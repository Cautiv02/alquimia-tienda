<?php
/**
 * mercadopago-webhook.php
 * URL que recibe los avisos de Mercado Pago:  SITE_URL/mercadopago-webhook.php
 * (la tienda la envía en cada pago como notification_url; no necesitas configurarla a mano,
 *  pero si la configuras en "Webhooks", marca solo el evento "Pagos").
 *
 * Mercado Pago puede avisar de varias formas (JSON {type,data:{id}}, o ?topic=payment&id=..., o ?data.id=..),
 * así que se aceptan todas. Los datos del aviso NO se usan para decidir nada: se consulta el pago real.
 */
require_once __DIR__ . '/mp-lib.php';

function log_webhook(string $msg): void {
    @file_put_contents(__DIR__ . '/webhook.log', '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

$raw     = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = [];

$type = (string) ($payload['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$action = (string) ($payload['action'] ?? '');
// PHP convierte "data.id" de la URL en "data_id"
$paymentId = (string) ($_GET['data_id'] ?? $payload['data']['id'] ?? $_GET['id'] ?? '');

// Otros eventos (merchant_order, etc.): se confirman sin procesar
if ($type !== 'payment' && strpos($action, 'payment') !== 0) {
    http_response_code(200);
    echo 'ignored';
    exit;
}
if (!preg_match('/^\d{3,20}$/', $paymentId)) {
    http_response_code(400);
    log_webhook('Aviso sin id de pago válido: ' . substr($raw, 0, 300));
    exit;
}

// Firma: solo si configuraste MP_WEBHOOK_SECRET
if (MP_WEBHOOK_SECRET !== '') {
    $sigOk = verify_mp_signature($_SERVER['HTTP_X_SIGNATURE'] ?? '', $_SERVER['HTTP_X_REQUEST_ID'] ?? '', $paymentId);
    if (!$sigOk) {
        http_response_code(401);
        log_webhook("Firma inválida para el pago $paymentId");
        exit;
    }
}

try {
    $result = process_mp_payment(get_pdo(), $paymentId);
} catch (Throwable $e) {
    log_webhook("ERROR pago $paymentId: " . $e->getMessage());
    http_response_code(500); // Mercado Pago reintenta más tarde
    exit('error');
}

log_webhook("Pago $paymentId → $result");

// Errores temporales de la API de MP: pedir reintento. Todo lo demás: 200 para que no reintente.
if (preg_match('/^api_error_(0|5\d\d)$/', $result)) {
    http_response_code(502);
    exit($result);
}
http_response_code(200);
echo $result;
