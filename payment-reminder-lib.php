<?php
/** payment-reminder-lib.php — recordatorio manual de pago pendiente (correo + WhatsApp) desde Pedidos → Pago pendiente. */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/settings-lib.php';
require_once __DIR__ . '/mail-templates-lib.php';

function pay_reminder_ensure(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_reminder_at'")->fetch()) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN payment_reminder_at DATETIME NULL, ADD COLUMN payment_reminder_count TINYINT NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { error_log('[recordatorio] ' . $e->getMessage()); }
}

function pay_reminder_mark(PDO $pdo, int $orderId): void {
    $pdo->prepare("UPDATE orders SET payment_reminder_at = NOW(), payment_reminder_count = LEAST(payment_reminder_count + 1, 99) WHERE id = :id")->execute([':id' => $orderId]);
}

/** Envía el recordatorio por correo a un pedido con pago pendiente. */
function pay_reminder_email(PDO $pdo, int $orderId): bool {
    pay_reminder_ensure($pdo);
    $st = $pdo->prepare("SELECT email FROM orders WHERE id = :id AND financial_status = 'pendiente'"); $st->execute([':id' => $orderId]);
    $to = (string) $st->fetchColumn();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    [$subject, $html] = mail_render('recordatorio_pago', mail_order_vars($pdo, $orderId));
    $ok = smtp_send($to, $subject, $html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : null);
    if ($ok) pay_reminder_mark($pdo, $orderId);
    return $ok;
}

/** Número en formato internacional para wa.me ('' si no sirve). Un celular chileno de 9 dígitos que empieza con 9 recibe el 56. */
function pay_reminder_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === '') return '';
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    if (strlen($d) === 9 && $d[0] === '9') $d = '56' . $d;
    return strlen($d) >= 8 ? $d : '';
}

/** Enlace de WhatsApp con el mensaje ya escrito ('' si el pedido no tiene teléfono válido). */
function pay_reminder_wa_url(PDO $pdo, int $orderId): string {
    $st = $pdo->prepare("SELECT phone FROM orders WHERE id = :id"); $st->execute([':id' => $orderId]);
    $ph = pay_reminder_phone((string) $st->fetchColumn());
    if ($ph === '') return '';
    return 'https://wa.me/' . $ph . '?text=' . rawurlencode(mail_plain('recordatorio_pago', mail_order_vars($pdo, $orderId)));
}
