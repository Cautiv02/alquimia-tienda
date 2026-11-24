<?php
/**
 * confirmar-pago.php?codigo=AD-XXXX-XXXX — el cliente que pagó por transferencia o Binance Pay adjunta su comprobante
 * (captura/PDF) y/o llena los datos del pago. Se guarda en payment_proofs y se avisa al admin.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';

$pdo = get_pdo();
$code = strtoupper(trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? '')));
$order = null;
if ($code !== '') { $st = $pdo->prepare("SELECT * FROM orders WHERE unique_code = :c LIMIT 1"); $st->execute([':c' => $code]); $order = $st->fetch(); }

$done = false; $err = '';
if ($order && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['website'])) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_proofs (
        id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, file_name VARCHAR(80) NULL, file_mime VARCHAR(40) NULL,
        sender VARCHAR(150) NULL, amount VARCHAR(40) NULL, reference VARCHAR(150) NULL, note TEXT NULL,
        created_at DATETIME NOT NULL, INDEX (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM payment_proofs WHERE order_id = :o"); $cnt->execute([':o' => $order['id']]);
    $sender = trim(mb_substr((string) ($_POST['sender'] ?? ''), 0, 150));
    $amount = trim(mb_substr((string) ($_POST['amount'] ?? ''), 0, 40));
    $ref    = trim(mb_substr((string) ($_POST['reference'] ?? ''), 0, 150));
    $note   = trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 1000));
    $fileName = null; $mime = null;
    if ((int) $cnt->fetchColumn() >= 6) { $err = 'Ya recibimos varios comprobantes de este pedido. Escríbenos por WhatsApp si necesitas ayuda.'; }
    elseif (!empty($_FILES['proof']['tmp_name']) && is_uploaded_file($_FILES['proof']['tmp_name'])) {
        $f = $_FILES['proof'];
        $head = (string) @file_get_contents($f['tmp_name'], false, null, 0, 5);
        $info = @getimagesize($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? ($head === '%PDF-' ? 'pdf' : '');
        if (!$ext || $f['size'] > 6 * 1024 * 1024) { $err = 'Adjunta una imagen (JPG, PNG, WEBP) o un PDF de hasta 6 MB.'; }
        else {
            $dir = __DIR__ . '/comprobantes';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
            $fileName = 'c' . (int) $order['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fileName)) { $err = 'No pudimos guardar el archivo. Inténtalo de nuevo o envíalo por WhatsApp.'; $fileName = null; }
            else $mime = $ext;
        }
    }
    if ($err === '' && $fileName === null && $sender === '' && $ref === '') { $err = 'Adjunta la captura del pago o escribe al menos el nombre/usuario de quien pagó o el ID de la transacción.'; }
    if ($err === '') {
        $pdo->prepare("INSERT INTO payment_proofs (order_id, file_name, file_mime, sender, amount, reference, note, created_at) VALUES (:o,:f,:m,:s,:a,:r,:n,NOW())")
            ->execute([':o' => $order['id'], ':f' => $fileName, ':m' => $mime, ':s' => $sender ?: null, ':a' => $amount ?: null, ':r' => $ref ?: null, ':n' => $note ?: null]);
        $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        safe_notify($pdo, 'venta', "Comprobante recibido del pedido #{$order['order_number']} ({$order['email']}).", 'order', (int) $order['id']);
        $a = "<p><strong>El cliente envió su comprobante de pago.</strong></p><p>Pedido: <b>" . $e($order['order_number']) . "</b> (" . $e($order['payment_method'] ?: '-') . ")<br>Correo: " . $e($order['email']) . "<br>"
           . "Pagó: " . $e($sender ?: '-') . " · Monto: " . $e($amount ?: '-') . " · ID transacción: " . $e($ref ?: '-') . "<br>Nota: " . $e($note ?: '-') . "<br>Archivo: " . ($fileName ? 'sí (míralo en el pedido)' : 'no') . "</p>"
           . "<p><a href=\"" . $e(SITE_URL . '/admin/pedido.php?id=' . (int) $order['id']) . "\">Abrir el pedido y revisar el comprobante</a></p>";
        try { notify_admins("Comprobante de pago - pedido {$order['order_number']}", $a, $order['email']); } catch (Throwable $e2) {}
        $done = true;
    }
}

site_header('Confirmar pago');
?>
<div class="wrap page" style="max-width: 640px;">
  <div class="panel">
  <?php if (!$order): ?>
    <h1>No encontramos tu pedido</h1>
    <p class="lead">Revisa el enlace de tu correo o escríbenos por WhatsApp con tu comprobante.</p>
  <?php elseif ($order['financial_status'] === 'pagado'): ?>
    <h1>Tu pago ya está confirmado ✅</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b>. Estamos preparando tu entrega.</p>
    <p><a class="btn-gold" href="portal.php?modo=codigo&codigo=<?= urlencode($order['unique_code']) ?>">Ver estado de mi pedido</a></p>
  <?php elseif ($done): ?>
    <h1>¡Comprobante recibido! 🎉</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b>. Revisaremos tu pago y te enviaremos las claves a <b><?= h($order['email']) ?></b>.</p>
    <p><a class="btn-gold" href="portal.php?modo=codigo&codigo=<?= urlencode($order['unique_code']) ?>">Ver estado de mi pedido</a></p>
  <?php else: ?>
    <h1>Confirmar mi pago</h1>
    <p class="lead">Pedido <b><?= h($order['order_number']) ?></b> por <b><?= h(money($order['total_amount'])) ?></b><?= $order['payment_method'] === 'binance' ? ' (' . h(binance_usdt($order['total_amount'])) . ' USDT)' : '' ?>.</p>
    <p>Adjunta la captura de tu pago o llena los datos. Con una de las dos basta.</p>
    <?php if ($err): ?><div class="alert" style="background:#fdeaea;color:#8a1f1f;"><?= h($err) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="buybox" style="max-width:none;">
      <input type="hidden" name="codigo" value="<?= h($order['unique_code']) ?>">
      <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label for="proof">Captura o comprobante (imagen o PDF, máx. 6 MB)</label><input id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
      <p style="margin:6px 0 10px;color:var(--ash);font-size:.85rem;text-align:center;">— o completa los datos —</p>
      <div class="field"><label for="sender"><?= $order['payment_method'] === 'binance' ? 'Tu correo electrónico o ID de Binance' : 'Nombre de quien pagó' ?></label><input id="sender" name="sender" maxlength="150" value="<?= h($_POST['sender'] ?? '') ?>"></div>
      <div class="field"><label for="amount">Monto enviado</label><input id="amount" name="amount" maxlength="40" placeholder="<?= $order['payment_method'] === 'binance' ? h(binance_usdt($order['total_amount']) . ' USDT') : h(money($order['total_amount'])) ?>" value="<?= h($_POST['amount'] ?? '') ?>"></div>
      <div class="field"><label for="reference"><?= $order['payment_method'] === 'binance' ? 'ID de la transacción (Order ID de Binance)' : 'N° de operación / comprobante' ?></label><input id="reference" name="reference" maxlength="150" value="<?= h($_POST['reference'] ?? '') ?>"></div>
      <div class="field"><label for="note">Comentario (opcional)</label><textarea id="note" name="note" rows="3" maxlength="1000"><?= h($_POST['note'] ?? '') ?></textarea></div>
      <button class="btn-block" type="submit">Enviar comprobante</button>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php site_footer();
