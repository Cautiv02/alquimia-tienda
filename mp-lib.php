<?php
/**
 * mp-lib.php — funciones compartidas de Mercado Pago.
 * Lo usan el webhook, la página de resultado y el panel admin.
 * NUNCA confía en datos que llegan por URL o POST: siempre consulta el pago a la API de MP.
 */
require_once __DIR__ . '/delivery-engine.php';

/** GET a la API de Mercado Pago. Devuelve ['code' => http, 'data' => array|null, 'raw' => string]. */
function mp_get(string $path): array {
    $ch = curl_init('https://api.mercadopago.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => (string) $raw];
}

/** Valida la cabecera x-signature de Mercado Pago (defensa extra; la verdad siempre viene de la API). */
function verify_mp_signature(string $xSignature, string $xRequestId, string $dataId): bool {
    if ($xSignature === '' || MP_WEBHOOK_SECRET === '') return false;
    $parts = [];
    foreach (explode(',', $xSignature) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) $parts[trim($kv[0])] = trim($kv[1]);
    }
    $ts = $parts['ts'] ?? null;
    $hash = $parts['v1'] ?? null;
    if (!$ts || !$hash) return false;
    $template = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $ts . ';';
    return hash_equals(hash_hmac('sha256', $template, MP_WEBHOOK_SECRET), $hash);
}

/**
 * Consulta el pago en Mercado Pago y, si está APROBADO y coincide con un pedido, lo registra una sola vez
 * y deja el pedido en "pagado". Luego:
 *   - AUTO_DELIVERY = true  → entrega claves del stock (deliver_order)
 *   - AUTO_DELIVERY = false → avisa a NOTIFY_EMAIL y al panel (notify_paid_order)
 * Es idempotente: llamarla varias veces para el mismo pago no duplica nada.
 *
 * Devuelve un código de resultado: processed | already_processed | not_approved:<estado> | order_not_found |
 *   amount_mismatch | no_reference | api_error_<http>
 */
function process_mp_payment(PDO $pdo, string $paymentId): string {
    $res = mp_get('/v1/payments/' . rawurlencode($paymentId));
    if ($res['code'] !== 200 || !$res['data']) return 'api_error_' . $res['code'];
    $p = $res['data'];

    $status = (string) ($p['status'] ?? '');
    if ($status !== 'approved') return 'not_approved:' . $status;

    $ref = (string) ($p['external_reference'] ?? '');
    if ($ref === '') return 'no_reference';
    $amount = (float) ($p['transaction_amount'] ?? 0);

    $orderId = null;
    $firstTime = false;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, financial_status, total_amount FROM orders WHERE order_number = :n FOR UPDATE");
        $st->execute([':n' => $ref]);
        $order = $st->fetch();
        if (!$order) { $pdo->rollBack(); return 'order_not_found'; }
        $orderId = (int) $order['id'];

        // El monto cobrado debe coincidir con el pedido (evita pagos manipulados)
        if (abs($amount - (float) $order['total_amount']) > 1 || ($p['currency_id'] ?? 'CLP') !== 'CLP') {
            $pdo->rollBack();
            safe_notify($pdo, 'alerta', "Pago MP {$paymentId} aprobado pero NO coincide con el pedido {$ref} (MP: {$amount} / pedido: {$order['total_amount']}). No se procesó.", 'order', $orderId);
            return 'amount_mismatch';
        }

        $ex = $pdo->prepare("SELECT id FROM payments WHERE gateway_ref = :r LIMIT 1 FOR UPDATE");
        $ex->execute([':r' => $paymentId]);
        if ($ex->fetch()) { $pdo->commit(); return 'already_processed'; }

        $ins = $pdo->prepare("
            INSERT INTO payments (order_id, gateway, gateway_ref, status, amount, raw_response, created_at)
            VALUES (:oid, 'mercadopago', :ref, 'aprobado', :amount, :raw, NOW())
        ");
        $ins->execute([':oid' => $orderId, ':ref' => $paymentId, ':amount' => $amount, ':raw' => $res['raw']]);

        if ($order['financial_status'] === 'pendiente' || $order['financial_status'] === 'anulado') {
            $up = $pdo->prepare("UPDATE orders SET financial_status = 'pagado', payment_method = 'mercadopago', updated_at = NOW() WHERE id = :id");
            $up->execute([':id' => $orderId]);
            $firstTime = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e; // el webhook responde 500 y Mercado Pago reintenta
    }

    if ($firstTime) {
        try {
            if (AUTO_DELIVERY) deliver_order($pdo, $orderId);
            else notify_paid_order($pdo, $orderId);
        } catch (Throwable $e) {
            error_log('Post-pago pedido ' . $orderId . ': ' . $e->getMessage());
            safe_notify($pdo, 'alerta', "Pedido {$ref} pagado, pero falló el aviso/entrega: " . $e->getMessage(), 'order', $orderId);
        }
    }
    return 'processed';
}
