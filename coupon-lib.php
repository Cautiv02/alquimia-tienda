<?php
/**
 * coupon-lib.php — código de descuento para el primer pedido (se configura en admin/ajustes.php).
 * Elegible: correo sin pedidos pagados ni transferencias pendientes. Un pedido abandonado en Mercado Pago no cuenta.
 */
function coupon_config(): array {
    $pct = (int) setting('coupon_percent');
    return [
        'enabled' => setting('coupon_enabled') === '1' && trim(setting('coupon_code')) !== '' && $pct >= 1 && $pct <= 90,
        'code'    => strtoupper(trim(setting('coupon_code'))),
        'percent' => max(1, min(90, $pct)),
    ];
}

function coupon_norm(string $c): string { return strtoupper(preg_replace('/\s+/', '', $c)); }

/** @return array{ok:bool,msg:string,code:string,percent:int} */
function coupon_check(PDO $pdo, string $input, string $email): array {
    $cfg = coupon_config();
    $bad = fn(string $m) => ['ok' => false, 'msg' => $m, 'code' => '', 'percent' => 0];
    if (!$cfg['enabled'] || coupon_norm($input) !== coupon_norm($cfg['code'])) {
        return $bad('El código de descuento no es válido. Revísalo o quítalo para continuar sin descuento.');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE LOWER(email) = :e AND (financial_status = 'pagado' OR (financial_status = 'pendiente' AND payment_method IN ('transferencia','binance')))");
    $st->execute([':e' => strtolower($email)]);
    if ((int) $st->fetchColumn() > 0) {
        return $bad('Este código es solo para el primer pedido y ya tienes compras con este correo. Quita el código para continuar sin descuento.');
    }
    return ['ok' => true, 'msg' => '', 'code' => $cfg['code'], 'percent' => $cfg['percent']];
}

/** Agrega las columnas del descuento a orders (una sola vez). No llamar dentro de una transacción. */
function coupon_ensure_columns(PDO $pdo): void {
    try { $pdo->query("SELECT discount_code, discount_amount FROM orders LIMIT 1"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE orders ADD COLUMN discount_code VARCHAR(40) NULL, ADD COLUMN discount_amount INT NOT NULL DEFAULT 0"); }
}
