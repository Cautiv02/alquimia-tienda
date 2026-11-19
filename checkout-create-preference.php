<?php
/**
 * checkout-create-preference.php
 * Recibe la compra desde producto.php (POST), crea el pedido "pendiente", genera la preferencia de pago
 * en Mercado Pago (Checkout Pro) y redirige al cliente a pagar.
 *
 * Flujo: producto.php → este archivo → Mercado Pago → mercadopago-webhook.php (aviso) →
 *        el cliente vuelve a pedido-resultado.php.
 * Credenciales y URLs: todo viene de config.php (no hay valores escritos aquí).
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/cart-lib.php';

function get_product_prefix(?string $productType): string {
    $map = ['windows' => 'WIN', 'office' => 'OFC', 'adobe' => 'ADB', 'autodesk' => 'ADSK', 'antivirus' => 'AV'];
    return $map[strtolower(trim((string) $productType))] ?? 'AD';
}

function generate_unique_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $part = function ($len) use ($chars) {
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return 'AD-' . $part(4) . '-' . $part(4);
}

/**
 * Correlativo de pedidos: contador propio y atómico (tabla order_seq), independiente del id interno.
 * Arranca en ORDER_SEQ_START (por defecto 8041 = último número ya usado; el siguiente pedido será 8042).
 * Si la tabla no se puede crear, se usa el id interno como antes (nunca bloquea una venta).
 */
function ensure_order_seq(PDO $pdo): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_seq (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, n INT UNSIGNED NOT NULL) ENGINE=InnoDB");
        $start = defined('ORDER_SEQ_START') ? (int) ORDER_SEQ_START : 8041;
        $pdo->exec("INSERT IGNORE INTO order_seq (id, n) VALUES (1, " . $start . ")");
        return true;
    } catch (Throwable $e) {
        error_log('[checkout] order_seq no disponible, se usa el id interno: ' . $e->getMessage());
        return false;
    }
}
function next_order_seq(PDO $pdo): int {
    $pdo->exec("UPDATE order_seq SET n = LAST_INSERT_ID(n + 1) WHERE id = 1");
    return (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
}

function checkout_error(string $msg, int $code = 400): void {
    http_response_code($code);
    site_header('No pudimos procesar tu compra');
    echo '<div class="wrap page"><div class="panel"><h1>No pudimos procesar tu compra</h1>';
    echo '<p class="lead">' . h($msg) . '</p>';
    echo '<p><a class="btn-gold" href="javascript:history.back()">Volver</a></p></div></div>';
    site_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: catalogo.php'); exit; }
if (!empty($_POST['website'])) { http_response_code(204); exit; } // campo trampa para bots

$fromCart  = !empty($_POST['cart']);
$email     = strtolower(trim((string) ($_POST['email'] ?? '')));
$name      = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 120));
$phone     = trim(mb_substr((string) ($_POST['phone'] ?? ''), 0, 40));

if ($fromCart) {
    $wanted = cart_get();
} else {
    $variantId = (int) ($_POST['variant_id'] ?? 0);
    $wanted = $variantId ? [$variantId => max(1, min(10, (int) ($_POST['quantity'] ?? 1)))] : [];
}
if (!$wanted || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    checkout_error('Revisa que tengas productos en el carrito y escribas un correo válido: ahí te enviaremos tus claves.');
}
if (empty($_POST['accept'])) {
    checkout_error('Debes aceptar las condiciones de compra para continuar.');
}

$pdo = get_pdo();

// Datos de los productos SIEMPRE desde la base (nunca del navegador)
$lines = []; $totalAmount = 0;
foreach ($wanted as $vid => $qty) {
    $stmt = $pdo->prepare("
        SELECT pv.id, pv.title AS variant_title, pv.price, p.id AS product_id, p.title AS product_title, p.product_type
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        WHERE pv.id = :id AND p.status = 'activo'
    ");
    $stmt->execute([':id' => $vid]);
    $variant = $stmt->fetch();
    if (!$variant) checkout_error('Uno de los productos ya no está disponible. Revisa tu carrito.', 404);
    $unitPrice = (int) round((float) $variant['price']);
    if ($unitPrice <= 0) checkout_error('Un producto no tiene un precio válido. Escríbenos por WhatsApp.', 422);
    $itemTitle = $variant['product_title'];
    if (trim((string) $variant['variant_title']) !== '' && $variant['variant_title'] !== 'Default Title') {
        $itemTitle .= ' — ' . $variant['variant_title'];
    }
    // Con entrega automática sí exigimos stock cargado; en modo manual no (envías tú)
    if (AUTO_DELIVERY) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(lk.max_activations - (SELECT COUNT(*) FROM key_redemptions kr WHERE kr.license_key_id = lk.id)), 0)
            FROM license_keys lk WHERE lk.variant_id = :v AND lk.status = 'disponible'");
        $st->execute([':v' => $vid]);
        if ((int) $st->fetchColumn() < $qty) checkout_error('Por ahora no tenemos stock suficiente de ' . $itemTitle . '. Escríbenos por WhatsApp y lo resolvemos.', 409);
    }
    $lines[] = ['v' => $variant, 'qty' => (int) $qty, 'unit' => $unitPrice, 'title' => $itemTitle];
    $totalAmount += $unitPrice * (int) $qty;
}

// Cliente: buscar o crear por correo
$st = $pdo->prepare("SELECT id FROM customers WHERE email = :e LIMIT 1");
$st->execute([':e' => $email]);
$customerId = $st->fetchColumn();
if (!$customerId) {
    $pdo->prepare("INSERT INTO customers (email, first_name, phone, created_at) VALUES (:e, :n, :p, NOW())")
        ->execute([':e' => $email, ':n' => $name ?: null, ':p' => $phone ?: null]);
    $customerId = $pdo->lastInsertId();
}

// Pedido en estado pendiente (el número lleva el id correlativo: PREFIJO-DD/MM-id)
$orderId = 0; $orderNumber = ''; $uniqueCode = '';
$useSeq = ensure_order_seq($pdo);
$pdo->beginTransaction();
try {
    for ($try = 0; $try < 5 && !$orderId; $try++) {
        $uniqueCode = generate_unique_code();
        try {
            $pdo->prepare("
                INSERT INTO orders (order_number, unique_code, customer_id, email, phone, financial_status,
                    fulfillment_status, total_amount, currency, payment_method, created_at)
                VALUES ('TEMP', :code, :cid, :email, :phone, 'pendiente', 'pendiente', :total, 'CLP', 'mercadopago', NOW())
            ")->execute([':code' => $uniqueCode, ':cid' => $customerId, ':email' => $email, ':phone' => $phone ?: null, ':total' => $totalAmount]);
            $orderId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // código repetido: reintenta
        }
    }
    if (!$orderId) throw new RuntimeException('No se pudo generar un código de pedido único');

    $correlative = $useSeq ? next_order_seq($pdo) : $orderId;
    $orderNumber = (count($lines) === 1 ? get_product_prefix($lines[0]['v']['product_type']) : 'AD') . '-' . date('d/m') . '-' . $correlative;
    $pdo->prepare("UPDATE orders SET order_number = :n WHERE id = :id")->execute([':n' => $orderNumber, ':id' => $orderId]);

    $insItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, variant_id, title, quantity, unit_price)
        VALUES (:oid, :pid, :vid, :title, :qty, :price)
    ");
    foreach ($lines as $l) {
        $insItem->execute([':oid' => $orderId, ':pid' => $l['v']['product_id'], ':vid' => $l['v']['id'], ':title' => $l['title'], ':qty' => $l['qty'], ':price' => $l['unit']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[checkout] crear pedido: ' . $e->getMessage());
    checkout_error('Tuvimos un problema creando tu pedido. Inténtalo de nuevo en unos minutos.', 500);
}

// Preferencia de pago en Mercado Pago
$resultBase = SITE_URL . '/pedido-resultado.php?codigo=' . urlencode($uniqueCode) . '&estado=';
$preference = [
    'items' => array_map(function ($l) {
        return ['id' => (string) $l['v']['id'], 'title' => mb_substr($l['title'], 0, 250), 'quantity' => $l['qty'], 'unit_price' => $l['unit'], 'currency_id' => 'CLP'];
    }, $lines),
    'payer'              => array_filter(['email' => $email, 'name' => $name ?: null]),
    'external_reference' => $orderNumber,
    'notification_url'   => SITE_URL . '/mercadopago-webhook.php',
    'back_urls'          => ['success' => $resultBase . 'ok', 'pending' => $resultBase . 'pendiente', 'failure' => $resultBase . 'fallido'],
    'auto_return'        => 'approved',
    'statement_descriptor' => 'ALQUIMIA DIGITAL',
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($preference, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . MP_ACCESS_TOKEN, 'X-Idempotency-Key: ' . bin2hex(random_bytes(12))],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$pref = is_string($response) ? json_decode($response, true) : null;
if (!in_array($httpCode, [200, 201], true) || empty($pref['init_point'])) {
    // El detalle técnico va al log y al panel, nunca a la pantalla del cliente
    error_log('[checkout] Mercado Pago rechazó la preferencia (HTTP ' . $httpCode . ') pedido ' . $orderNumber . ': ' . substr((string) $response, 0, 500));
    $pdo->prepare("UPDATE orders SET financial_status = 'anulado', updated_at = NOW() WHERE id = :id AND financial_status = 'pendiente'")->execute([':id' => $orderId]);
    safe_notify($pdo, 'alerta', "Mercado Pago rechazó crear el pago del pedido {$orderNumber} (HTTP {$httpCode}). Revisa el Access Token en config.php.", 'order', $orderId);
    checkout_error('No pudimos iniciar el pago con Mercado Pago. Inténtalo de nuevo en unos minutos o escríbenos por WhatsApp.', 502);
}

// init_point sirve tanto para pruebas como para producción: lo que cambia es el Access Token usado.
if ($fromCart) cart_save([]);
header('Location: ' . $pref['init_point']);
exit;
