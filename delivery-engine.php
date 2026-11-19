<?php
/**
 * delivery-engine.php
 * --------------------
 * Motor de entrega de licencias. Funciones reutilizables:
 *
 *   - get_pdo()                          conexión a la base
 *   - assign_keys_for_order_item($pdo, $orderItemId)   asigna N claves (según quantity) de forma atómica
 *   - deliver_order($pdo, $orderId)      entrega TODOS los ítems de un pedido, envía el correo, notifica stock bajo
 *
 * Este archivo no se visita directamente — se incluye (require) desde
 * mercadopago-webhook.php o desde un panel admin que confirme pagos manuales.
 *
 * SEGURIDAD ANTI-CONDICIÓN DE CARRERA:
 * Cada asignación de clave usa una transacción con "SELECT ... FOR UPDATE",
 * igual que en softlic-alquimia — así dos pedidos simultáneos nunca pueden
 * llevarse la misma clave, aunque lleguen al mismo instante.
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Configuración (credenciales, URLs, SMTP) vive en config.php, fuera del código.
require_once __DIR__ . '/config.php';

function get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

/**
 * Asigna de forma atómica tantas claves como indique la cantidad del ítem.
 * Ahora soporta claves de volumen: una misma clave puede entregarse varias
 * veces (a distintos pedidos, incluso distintos clientes) hasta agotar su
 * max_activations. Devuelve un array de claves asignadas (strings) o lanza
 * excepción si no hay stock.
 */
function assign_keys_for_order_item(PDO $pdo, int $orderItemId): array {
    $stmtItem = $pdo->prepare("
        SELECT oi.variant_id, oi.quantity, oi.title, p.title AS product_title
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.id = :id
    ");
    $stmtItem->execute([':id' => $orderItemId]);
    $item = $stmtItem->fetch();

    if (!$item) throw new RuntimeException("order_item $orderItemId no encontrado");
    if (!$item['variant_id']) {
        // Ítem sin variante ligada (ej: importado del CSV sin match de SKU) — no se puede
        // entregar automáticamente, queda pendiente para revisión manual.
        return [];
    }

    $assignedKeys = [];
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $item['quantity']; $i++) {
            // SELECT ... FOR UPDATE bloquea la fila elegida hasta el COMMIT,
            // así ningún otro proceso puede tomar el último cupo de la misma
            // clave en paralelo. Ordenamos por claves con menos cupo restante
            // primero, para agotar una clave antes de empezar la siguiente.
            $stmtLock = $pdo->prepare("
                SELECT lk.id, lk.key_value, lk.max_activations,
                       (SELECT COUNT(*) FROM key_redemptions kr WHERE kr.license_key_id = lk.id) AS used
                FROM license_keys lk
                WHERE lk.variant_id = :vid AND lk.status = 'disponible'
                HAVING used < max_activations
                ORDER BY (max_activations - used) ASC, lk.id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtLock->execute([':vid' => $item['variant_id']]);
            $key = $stmtLock->fetch();

            if (!$key) {
                throw new RuntimeException("Sin stock de claves para variant_id {$item['variant_id']}");
            }

            $stmtRedeem = $pdo->prepare("
                INSERT INTO key_redemptions (license_key_id, order_item_id, redeemed_at)
                VALUES (:kid, :oiid, NOW())
            ");
            $stmtRedeem->execute([':kid' => $key['id'], ':oiid' => $orderItemId]);

            // Si con esta entrega se agotó el cupo, marca la clave como agotada
            $newUsedCount = $key['used'] + 1;
            if ($newUsedCount >= $key['max_activations']) {
                $stmtExhaust = $pdo->prepare("UPDATE license_keys SET status = 'agotada' WHERE id = :kid");
                $stmtExhaust->execute([':kid' => $key['id']]);
            }

            $assignedKeys[] = $key['key_value'];
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $assignedKeys;
}

/**
 * Entrega TODOS los ítems de un pedido: asigna claves, arma el correo,
 * lo envía, y marca el pedido como entregado. Si algún ítem se queda sin
 * stock, el pedido queda "parcial" y se genera notificación para ti.
 */
function deliver_order(PDO $pdo, int $orderId): void {
    $stmtOrder = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
    $stmtOrder->execute([':id' => $orderId]);
    $order = $stmtOrder->fetch();
    if (!$order) throw new RuntimeException("Pedido $orderId no encontrado");

    // Protección de idempotencia: si ya se entregó antes, no reprocesar.
    // Esto evita consumir cupo de más si el webhook de MercadoPago (u otro
    // disparador) llama a deliver_order() más de una vez para el mismo pedido.
    if ($order['fulfillment_status'] === 'entregado') {
        return;
    }

    $stmtItems = $pdo->prepare("SELECT id, title, quantity FROM order_items WHERE order_id = :oid");
    $stmtItems->execute([':oid' => $orderId]);
    $items = $stmtItems->fetchAll();

    $deliveredLines = [];
    $anyMissing = false;

    foreach ($items as $item) {
        try {
            $keys = assign_keys_for_order_item($pdo, $item['id']);
            if (empty($keys)) {
                $anyMissing = true;
                continue;
            }
            $deliveredLines[] = [
                'title'   => $item['title'],
                'keys'    => $keys,
                'devices' => extract_device_count($item['title']),
            ];
        } catch (RuntimeException $e) {
            // Sin stock para este ítem: se registra para revisión manual, no se corta el resto.
            $anyMissing = true;
            notify_admin($pdo, 'stock_bajo', "Sin stock al entregar pedido #{$order['order_number']}: " . $e->getMessage(), 'order', $orderId);
        }
    }

    $newStatus = $anyMissing ? 'parcial' : 'entregado';
    $stmtUpdate = $pdo->prepare("UPDATE orders SET fulfillment_status = :st, updated_at = NOW() WHERE id = :id");
    $stmtUpdate->execute([':st' => $newStatus, ':id' => $orderId]);

    if (!empty($deliveredLines)) {
        send_delivery_email($order, $deliveredLines);
    }

    check_low_stock($pdo, $items);

    notify_admin($pdo, 'venta', "Pedido #{$order['order_number']} entregado ({$order['email']})", 'order', $orderId);
}

/**
 * Detecta cuántos dispositivos permite una variante a partir de su título,
 * ej: "Office 2021 ... — 3 PC" -> 3. Si no encuentra un número, devuelve null
 * (no se agrega la aclaración en el correo).
 */
function extract_device_count(string $title): ?int {
    if (preg_match('/(\d+)\s*PC/i', $title, $m)) {
        return (int) $m[1];
    }
    return null;
}

function send_delivery_email(array $order, array $deliveredLines): void {
    $to = $order['email'];

    // Asunto con la misma nomenclatura que usabas para ubicar pedidos en Gmail,
    // ej: "ADSK-12/11-6566 - 1 AUTODESK AUTOCAD 2022 - 1 PC"
    $itemSummary = implode(' + ', array_map(fn($l) => $l['title'], $deliveredLines));
    $subject = "{$order['order_number']} - {$itemSummary}";

    $bodyHtml  = "<p>Hola,</p>";
    $bodyHtml .= "<p>¡Gracias por tu compra! Tu pedido ya fue procesado y tu(s) clave(s) están listas para ver.</p>";
    $bodyHtml .= "<p>Por seguridad, no enviamos la clave directamente por correo — la puedes ver y copiar en cualquier momento desde tu portal de pedidos:</p>";

    $portalUrl = SITE_URL . '/portal.php?modo=codigo&codigo=' . urlencode($order['unique_code']);
    $bodyHtml .= "<p style='text-align:center; margin: 24px 0;'>";
    $bodyHtml .= "<a href=\"" . htmlspecialchars($portalUrl) . "\" style=\"background:#B9862A; color:#FFFBF3; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:600;\">Ver mi clave</a>";
    $bodyHtml .= "</p>";

    $bodyHtml .= "<p>Tu pedido:</p><ul>";
    foreach ($deliveredLines as $line) {
        $bodyHtml .= "<li>" . htmlspecialchars($line['title']);
        if (!empty($line['devices']) && $line['devices'] > 1) {
            $bodyHtml .= " <em>(válida para " . $line['devices'] . " dispositivos)</em>";
        }
        $bodyHtml .= "</li>";
    }
    $bodyHtml .= "</ul>";

    $bodyHtml .= "<p>Si prefieres buscar tu pedido manualmente, puedes hacerlo con:</p>";
    $bodyHtml .= "<ul>";
    $bodyHtml .= "<li>Tu código único: <code>" . htmlspecialchars($order['unique_code']) . "</code></li>";
    $bodyHtml .= "<li>O tu correo + N° de pedido: <code>" . htmlspecialchars($order['order_number']) . "</code></li>";
    $bodyHtml .= "</ul>";

    $bodyHtml .= "<p>Si necesitas ayuda con la instalación, revisa nuestro panel de tutoriales o escríbenos por WhatsApp.</p>";
    $bodyHtml .= "<p>— Alquimia Digital</p>";

    $bodyText = strip_tags(str_replace(['<br>', '</p>'], "\n", $bodyHtml));

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();
    } catch (PHPMailerException $e) {
        // No relanzamos la excepción: si el correo falla, el pedido ya quedó
        // entregado en la base (la clave se asignó igual). Solo dejamos rastro.
        error_log('Error enviando correo de entrega: ' . $mail->ErrorInfo);
    }
}

function check_low_stock(PDO $pdo, array $items): void {
    foreach ($items as $item) {
        $stmtVariant = $pdo->prepare("
            SELECT ov.variant_id, pv.title AS variant_title, p.title AS product_title
            FROM order_items ov
            JOIN product_variants pv ON pv.id = ov.variant_id
            JOIN products p ON p.id = pv.product_id
            WHERE ov.id = :id
        ");
        $stmtVariant->execute([':id' => $item['id']]);
        $v = $stmtVariant->fetch();
        if (!$v) continue;

        $stmtCount = $pdo->prepare("
            SELECT COALESCE(SUM(
                lk.max_activations - (SELECT COUNT(*) FROM key_redemptions kr WHERE kr.license_key_id = lk.id)
            ), 0)
            FROM license_keys lk
            WHERE lk.variant_id = :vid AND lk.status = 'disponible'
        ");
        $stmtCount->execute([':vid' => $v['variant_id']]);
        $remaining = (int) $stmtCount->fetchColumn();

        if ($remaining <= LOW_STOCK_THRESHOLD) {
            notify_admin(
                $pdo,
                'stock_bajo',
                "Quedan $remaining claves de \"{$v['product_title']} — {$v['variant_title']}\"",
                'variant',
                $v['variant_id']
            );
        }
    }
}

function notify_admin(PDO $pdo, string $type, string $message, ?string $refType = null, ?int $refId = null): void {
    $stmt = $pdo->prepare("
        INSERT INTO admin_notifications (type, message, reference_type, reference_id, created_at)
        VALUES (:type, :message, :rtype, :rid, NOW())
    ");
    $stmt->execute([
        ':type' => $type,
        ':message' => $message,
        ':rtype' => $refType,
        ':rid' => $refId,
    ]);
}


// ---------------------------------------------------------------------
// MODO MANUAL: avisos de pedido pagado (sin entregar claves automáticamente)
// ---------------------------------------------------------------------

/** Envía un correo HTML por SMTP autenticado. Devuelve true/false y deja rastro en el error_log si falla. */
function smtp_send(string $to, string $subject, string $html, string $text = '', ?string $replyTo = null): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text !== '' ? $text : strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html));
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Error enviando correo a ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/** notify_admin() que nunca rompe el flujo de pago si la tabla rechaza el tipo o falla. */
function safe_notify(PDO $pdo, string $type, string $message, ?string $refType = null, ?int $refId = null): void {
    try {
        notify_admin($pdo, $type, $message, $refType, $refId);
    } catch (Throwable $e) {
        error_log('safe_notify falló: ' . $e->getMessage());
    }
}

/**
 * Se llama UNA vez cuando un pedido pasa a "pagado" y la entrega es manual:
 *  - deja un aviso en el panel,
 *  - te envía un correo con los datos del cliente y los productos,
 *  - le confirma al cliente que recibimos su pago (sin claves).
 */
function notify_paid_order(PDO $pdo, int $orderId): void {
    $st = $pdo->prepare("
        SELECT o.*, c.first_name, c.last_name, c.phone AS customer_phone
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.id = :id
    ");
    $st->execute([':id' => $orderId]);
    $order = $st->fetch();
    if (!$order) return;

    $it = $pdo->prepare("SELECT title, quantity, unit_price FROM order_items WHERE order_id = :id ORDER BY id");
    $it->execute([':id' => $orderId]);
    $items = $it->fetchAll();

    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $name  = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
    $phone = $order['phone'] ?: ($order['customer_phone'] ?? '');
    $total = '$' . number_format((float)$order['total_amount'], 0, ',', '.');

    safe_notify($pdo, 'venta', "Pedido #{$order['order_number']} PAGADO ({$order['email']}) por {$total}. Envía las claves manualmente.", 'order', $orderId);

    // 1) Aviso para ti
    $li = '';
    foreach ($items as $i) {
        $li .= '<li>' . (int)$i['quantity'] . ' × ' . $e($i['title']) . '</li>';
    }
    $adminUrl = SITE_URL . '/admin/pedido.php?id=' . $orderId;
    $html  = "<p><strong>Se pagó un pedido y está esperando el envío manual de claves.</strong></p>";
    $html .= "<p>Pedido: <b>" . $e($order['order_number']) . "</b><br>Total: <b>$total</b><br>";
    $html .= "Cliente: " . $e($name ?: '(sin nombre)') . "<br>Correo: " . $e($order['email']) . "<br>Teléfono: " . $e($phone ?: '-') . "</p>";
    $html .= "<p>Productos:</p><ul>$li</ul>";
    $html .= "<p><a href=\"" . $e($adminUrl) . "\">Abrir el pedido en el panel</a></p>";
    smtp_send(NOTIFY_EMAIL, "Nuevo pedido pagado {$order['order_number']} ({$total})", $html, '', $order['email']);

    // 2) Confirmación para el cliente (sin claves)
    $portalUrl = SITE_URL . '/portal.php?modo=codigo&codigo=' . urlencode($order['unique_code']);
    $c  = "<p>Hola" . ($name ? ' ' . $e($name) : '') . ",</p>";
    $c .= "<p>¡Gracias por tu compra! Recibimos tu pago correctamente y estamos preparando tu(s) clave(s). Te las enviaremos a este correo en breve.</p>";
    $c .= "<p>Tu pedido <b>" . $e($order['order_number']) . "</b>:</p><ul>$li</ul>";
    $c .= "<p>Puedes revisar el estado cuando quieras aquí: <a href=\"" . $e($portalUrl) . "\">Ver mi pedido</a><br>Tu código único: <code>" . $e($order['unique_code']) . "</code></p>";
    $c .= "<p>Si necesitas ayuda escríbenos a " . $e(NOTIFY_EMAIL) . " o por WhatsApp.</p><p>— " . $e(MAIL_FROM_NAME) . "</p>";
    smtp_send($order['email'], "Recibimos tu pago - Pedido {$order['order_number']}", $c, '', NOTIFY_EMAIL);
}
