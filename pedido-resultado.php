<?php
/**
 * pedido-resultado.php — a donde vuelve el cliente desde Mercado Pago.
 * ?codigo=AD-XXXX-XXXX&estado=ok|pendiente|fallido  (+ payment_id / collection_id que agrega MP)
 * Si el cliente vuelve con un payment_id, se consulta el pago a la API como respaldo del webhook.
 */
require_once __DIR__ . '/mp-lib.php';
require_once __DIR__ . '/layout.php';

$pdo = get_pdo();
$code   = strtoupper(trim((string) ($_GET['codigo'] ?? '')));
$estado = (string) ($_GET['estado'] ?? '');
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');

$order = null;
if ($code !== '') {
    $st = $pdo->prepare("SELECT * FROM orders WHERE unique_code = :c LIMIT 1");
    $st->execute([':c' => $code]);
    $order = $st->fetch();
}

// Respaldo: si el webhook aún no llegó, confirmamos el pago nosotros
if ($order && $order['financial_status'] === 'pendiente' && preg_match('/^\d{3,20}$/', $paymentId)) {
    try { process_mp_payment($pdo, $paymentId); } catch (Throwable $e) { error_log('[resultado] ' . $e->getMessage()); }
    $st->execute([':c' => $code]);
    $order = $st->fetch();
}

$paid = $order && in_array($order['financial_status'], ['pagado'], true);
$portal = $order ? 'portal.php?modo=codigo&codigo=' . urlencode($order['unique_code']) : 'portal.php';

site_header('Resultado de tu pedido');
?>
<div class="wrap page" style="max-width: 760px;">
  <div class="panel">
  <?php if (!$order): ?>
    <h1>No encontramos tu pedido</h1>
    <p class="lead">Si ya pagaste, escríbenos por WhatsApp con tu comprobante y lo revisamos de inmediato.</p>
  <?php elseif ($paid): ?>
    <h1>¡Pago recibido! 🎉</h1>
    <p class="lead">Gracias por tu compra. Pedido <b><?= h($order['order_number']) ?></b>.</p>
    <div class="alert ok">
      <?php if ($order['fulfillment_status'] === 'entregado'): ?>
        Tu pedido ya fue entregado. Puedes ver tu clave en el portal.
      <?php else: ?>
        Estamos preparando tu(s) clave(s) y te las enviaremos a <b><?= h($order['email']) ?></b> en breve. Revisa también tu carpeta de spam.
      <?php endif; ?>
    </div>
    <p>Guarda tu código único: <code style="font-family:var(--mono);background:var(--surface-tint);padding:4px 8px;border-radius:6px;"><?= h($order['unique_code']) ?></code></p>
    <p><a class="btn-gold" href="<?= h($portal) ?>">Ver mi pedido</a></p>
  <?php elseif ($estado === 'fallido' || $order['financial_status'] === 'anulado'): ?>
    <h1>El pago no se completó</h1>
    <p class="lead">No se realizó ningún cobro. Puedes intentarlo de nuevo con otro medio de pago.</p>
    <p><a class="btn-gold" href="catalogo.php">Volver al catálogo</a></p>
  <?php elseif ($order['payment_method'] === 'binance'): ?>
    <h1>Pedido recibido: falta tu pago con Binance Pay</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b> por <b><?= h(binance_usdt($order['total_amount'])) ?> USDT</b> (<?= h(money($order['total_amount'])) ?>).</p>
    <p>Envía el pago por Binance Pay a:</p>
    <div class="bank-box"><?php if (binance_qr_url() !== ''): ?><img src="<?= h(binance_qr_url()) ?>" alt="Código QR de Binance Pay" width="220" height="220" style="display:block;max-width:100%;height:auto;margin:0 auto 12px;background:#fff;padding:8px;border-radius:8px;"><?php endif; ?><?= nl2br(h(setting('binance_details'))) ?><br><b>Monto: <?= h(binance_usdt($order['total_amount'])) ?> USDT</b><br><b>Nota/Referencia: <?= h($order['order_number']) ?></b></div>
    <div class="alert"><?= nl2br(h(setting('binance_note'))) ?></div>
    <p>También te enviamos estos datos a <b><?= h($order['email']) ?></b>. Tu código único: <code><?= h($order['unique_code']) ?></code></p>
    <p><a class="btn-gold" href="https://api.whatsapp.com/send?phone=<?= h(SUPPORT_WHATSAPP) ?>&text=<?= rawurlencode('Hola, hice el pago con Binance Pay del pedido ' . $order['order_number']) ?>">Enviar comprobante por WhatsApp</a>
       &nbsp;<a href="<?= h($portal) ?>">Ver estado de mi pedido</a></p>
  <?php elseif ($order['payment_method'] === 'transferencia'): ?>
    <h1>Pedido recibido: falta tu transferencia</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b> por <b><?= h(money($order['total_amount'])) ?></b>.</p>
    <p>Transfiere a esta cuenta e indica tu número de pedido como referencia:</p>
    <div class="bank-box"><?= nl2br(h(setting('transfer_details'))) ?><br><b>Monto: <?= h(money($order['total_amount'])) ?></b><br><b>Referencia: <?= h($order['order_number']) ?></b></div>
    <div class="alert"><?= nl2br(h(setting('transfer_note'))) ?></div>
    <p>También te enviamos estos datos a <b><?= h($order['email']) ?></b>. Tu código único: <code><?= h($order['unique_code']) ?></code></p>
    <p><a class="btn-gold" href="https://api.whatsapp.com/send?phone=<?= h(SUPPORT_WHATSAPP) ?>&text=<?= rawurlencode('Hola, hice la transferencia del pedido ' . $order['order_number']) ?>">Enviar comprobante por WhatsApp</a>
       &nbsp;<a href="<?= h($portal) ?>">Ver estado de mi pedido</a></p>
  <?php else: ?>
    <h1>Estamos confirmando tu pago</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b>. Algunos medios de pago demoran unos minutos en confirmarse.</p>
    <div class="alert">Apenas se acredite te avisaremos por correo a <b><?= h($order['email']) ?></b>. Puedes revisar el estado cuando quieras con tu código <code><?= h($order['unique_code']) ?></code>.</div>
    <p><a class="btn-gold" href="<?= h($portal) ?>">Ver estado de mi pedido</a></p>
  <?php endif; ?>
  </div>
</div>
<?php site_footer();
