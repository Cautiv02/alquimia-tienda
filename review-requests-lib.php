<?php
/**
 * review-requests-lib.php — solicitudes de reseña manuales con descuento de agradecimiento.
 *  - Tú decides a quién pedirla (admin/pedir-resenas.php o el botón en el pedido); el sistema solo te recuerda a los 3 días.
 *  - Cuando el cliente deja su reseña y tú la apruebas, envías el código de descuento (único, de un solo uso, ligado a su correo).
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/settings-lib.php';
require_once __DIR__ . '/mail-templates-lib.php';

function reward_enabled(): bool { return setting('review_reward_enabled') === '1' && reward_percent() > 0; }
function reward_percent(): int { return max(0, min(90, (int) setting('review_reward_percent'))); }
function reward_days(): int { return max(1, min(365, (int) setting('review_reward_days') ?: 60)); }

function review_requests_ensure(PDO $pdo): void {
    foreach (["ALTER TABLE orders ADD COLUMN review_requested_at DATETIME NULL", "ALTER TABLE orders ADD COLUMN review_skip TINYINT NOT NULL DEFAULT 0"] as $q) {
        try { $pdo->exec($q); } catch (Throwable $e) { /* ya existe */ }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS review_coupons (
        id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL, percent TINYINT NOT NULL,
        order_id INT NULL, review_id INT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX (order_id), INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Pedidos entregados hace 3 días o más (y hasta 45), sin solicitud, sin omitir y sin reseña. Nunca lanza excepción. */
function review_pending_orders(PDO $pdo, bool $count = false) {
    try {
        $since = trim(setting('review_remind_since'));
        if ($since === '') { settings_save($pdo, ['review_remind_since' => date('Y-m-d')]); $since = date('Y-m-d'); }
        $sql = "FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                WHERE o.fulfillment_status = 'entregado' AND o.financial_status = 'pagado' AND o.review_skip = 0 AND o.review_requested_at IS NULL
                  AND o.updated_at <= DATE_SUB(NOW(), INTERVAL 3 DAY) AND o.updated_at >= DATE_SUB(NOW(), INTERVAL 45 DAY) AND o.updated_at >= :since
                  AND o.email LIKE '%@%' AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_id = o.id)";
        if ($count) { $st = $pdo->prepare("SELECT COUNT(*) $sql"); $st->execute([':since' => $since]); return (int) $st->fetchColumn(); }
        $st = $pdo->prepare("SELECT o.id, o.order_number, o.email, o.total_amount, o.updated_at, c.first_name $sql ORDER BY o.updated_at ASC LIMIT 200");
        $st->execute([':since' => $since]);
        return $st->fetchAll();
    } catch (Throwable $e) { return $count ? 0 : []; }
}

/** Envía la solicitud de reseña al cliente de ese pedido. */
function review_request_send(PDO $pdo, int $orderId): bool {
    review_requests_ensure($pdo);
    require_once __DIR__ . '/reviews-lib.php';
    $vars = mail_order_vars($pdo, $orderId);
    if (!$vars) return false;
    $st = $pdo->prepare("SELECT email FROM orders WHERE id = :id"); $st->execute([':id' => $orderId]);
    $to = (string) $st->fetchColumn();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $vars['boton_resena'] = '<p style="margin:20px 0;"><a href="' . htmlspecialchars(review_link($orderId), ENT_QUOTES, 'UTF-8') . '" style="background:#b8892e;color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:bold;">Dejar mi opinión</a></p>';
    $vars['porcentaje'] = (string) reward_percent();
    $vars['dias_vigencia'] = (string) reward_days();
    [$subject, $html] = mail_render('resena_solicitud', $vars);
    $ok = smtp_send($to, $subject, $html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : null);
    if ($ok) $pdo->prepare("UPDATE orders SET review_requested_at = NOW() WHERE id = :id")->execute([':id' => $orderId]);
    return $ok;
}

/** Crea un código único de descuento para el cliente del pedido y se lo envía. Devuelve [ok, mensaje]. */
function review_reward_send(PDO $pdo, int $orderId, ?int $reviewId = null): array {
    review_requests_ensure($pdo);
    if (!reward_enabled()) return [false, 'El descuento por reseña está desactivado en Ajustes.'];
    $ex = $pdo->prepare("SELECT code FROM review_coupons WHERE order_id = :o LIMIT 1"); $ex->execute([':o' => $orderId]);
    if ($c = $ex->fetchColumn()) return [false, 'A este pedido ya se le envió el código ' . $c . '.'];
    $st = $pdo->prepare("SELECT email FROM orders WHERE id = :id"); $st->execute([':id' => $orderId]);
    $email = strtolower((string) $st->fetchColumn());
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'El pedido no tiene un correo válido.'];
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
    $code = 'GRACIAS-' . $code;
    $days = reward_days();
    $pdo->prepare("INSERT INTO review_coupons (code, email, percent, order_id, review_id, created_at, expires_at) VALUES (:c,:e,:p,:o,:r,NOW(),DATE_ADD(NOW(), INTERVAL :d DAY))")
        ->execute([':c' => $code, ':e' => $email, ':p' => reward_percent(), ':o' => $orderId, ':r' => $reviewId, ':d' => $days]);
    $vars = mail_order_vars($pdo, $orderId);
    $vars['porcentaje'] = (string) reward_percent(); $vars['dias_vigencia'] = (string) $days; $vars['codigo_descuento'] = $code;
    $vars['vence'] = date('d/m/Y', strtotime("+$days days"));
    [$subject, $html] = mail_render('resena_descuento', $vars);
    $ok = smtp_send($email, $subject, $html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : null);
    return [$ok, $ok ? "Código $code enviado a $email." : "Se creó el código $code pero no se pudo enviar el correo a $email. Cópialo y envíaselo tú."];
}
