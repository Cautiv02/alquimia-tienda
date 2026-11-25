<?php
/**
 * order-expiry-lib.php — cancela solos los pedidos por Binance Pay sin pagar pasado el plazo (por defecto 4 horas).
 * Se ejecuta (a) de forma ligera cuando alguien abre la tienda o el panel (como máximo cada 5 min) y
 * (b) exacto con una tarea programada de cPanel que llame a cron-expirar.php.
 * No cancela pedidos cuyo cliente ya envió su comprobante: esos los revisas tú.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/settings-lib.php';
require_once __DIR__ . '/mail-templates-lib.php';

function binance_hours(): int { return max(1, min(72, (int) setting('binance_hours') ?: 4)); }

/** Clave secreta para el cron (derivada de la contraseña de la base; no hay que guardarla). */
function expiry_cron_key(): string { return substr(hash_hmac('sha256', 'expirar', (defined('DB_PASS') ? DB_PASS : 'alq') . '|cron'), 0, 24); }

/** Cancela los pedidos Binance vencidos. Devuelve cuántos canceló. Nunca lanza excepción. */
function expire_binance_orders(PDO $pdo): int {
    try {
        $hours = binance_hours();
        $proofJoin = '';
        try { $pdo->query("SELECT 1 FROM payment_proofs LIMIT 1"); $proofJoin = " AND NOT EXISTS (SELECT 1 FROM payment_proofs pp WHERE pp.order_id = o.id)"; } catch (Throwable $e) { /* sin tabla: nadie ha enviado comprobante */ }
        $rows = $pdo->query("SELECT o.id, o.order_number, o.email FROM orders o
            WHERE o.payment_method = 'binance' AND o.financial_status = 'pendiente'
              AND o.created_at <= DATE_SUB(NOW(), INTERVAL $hours HOUR)" . $proofJoin . " ORDER BY o.id LIMIT 50")->fetchAll();
        $n = 0;
        foreach ($rows as $o) {
            $up = $pdo->prepare("UPDATE orders SET financial_status = 'anulado', updated_at = NOW() WHERE id = :id AND financial_status = 'pendiente'");
            $up->execute([':id' => $o['id']]);
            if (!$up->rowCount()) continue;
            $n++;
            try { // devuelve las unidades al stock de las opciones con stock manual
                $it = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :id"); $it->execute([':id' => $o['id']]);
                $rs = $pdo->prepare("UPDATE product_variants SET stock_manual = stock_manual + :q WHERE id = :v AND stock_manual IS NOT NULL");
                foreach ($it->fetchAll() as $r) if ($r['variant_id']) $rs->execute([':q' => (int) $r['quantity'], ':v' => (int) $r['variant_id']]);
            } catch (Throwable $e) {}
            try {
                $vars = mail_order_vars($pdo, (int) $o['id']); $vars['horas'] = (string) $hours;
                [$subject, $html] = mail_render('cancelado_vencido', $vars);
                smtp_send((string) $o['email'], $subject, $html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : null);
            } catch (Throwable $e) { error_log('[expirar] correo: ' . $e->getMessage()); }
            safe_notify($pdo, 'alerta', "Pedido #{$o['order_number']} (Binance) cancelado automáticamente: no se recibió el pago en {$hours} h.", 'order', (int) $o['id']);
        }
        return $n;
    } catch (Throwable $e) { error_log('[expirar] ' . $e->getMessage()); return 0; }
}

/** Ejecuta la cancelación como máximo cada 5 minutos, al abrir cualquier página. Barato y a prueba de fallos. */
function expire_binance_orders_lazy(): void {
    static $ran = false; if ($ran) return; $ran = true;
    try {
        if (setting('binance_enabled') !== '1') return;
        $last = (int) setting('expiry_last_run');
        if ($last > time() - 300) return;
        $pdo = get_pdo();
        settings_save($pdo, ['expiry_last_run' => (string) time()]);
        expire_binance_orders($pdo);
    } catch (Throwable $e) { /* nunca rompe la página */ }
}
