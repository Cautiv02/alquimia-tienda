<?php
/** resena.php — el cliente deja su opinión desde el enlace del correo. Queda sin publicar hasta que la apruebes en el panel. */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
$pdo = get_pdo();
$oid = (int) ($_GET['o'] ?? $_POST['o'] ?? 0);
$k = (string) ($_GET['k'] ?? $_POST['k'] ?? '');
$ok = $oid > 0 && hash_equals(review_token($oid), $k);

$order = null; $items = []; $done = false; $msg = '';
if ($ok) {
    $st = $pdo->prepare("SELECT o.id, o.fulfillment_status, c.first_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = :id");
    $st->execute([':id' => $oid]);
    $order = $st->fetch();
    if ($order && $order['fulfillment_status'] === 'entregado') {
        reviews_ensure_table($pdo);
        $it = $pdo->prepare("SELECT DISTINCT oi.product_id, p.title FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :id");
        $it->execute([':id' => $oid]);
        $items = $it->fetchAll();
    } else $order = null;
}

if ($order && $items && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $text = mb_substr(review_clean($_POST['content'] ?? ''), 0, 1500);
    $author = mb_substr(review_clean($_POST['author'] ?? '') ?: (string) ($order['first_name'] ?: 'Cliente'), 0, 120);
    $valid = array_column($items, 'product_id');
    if (in_array($pid, array_map('intval', $valid), true) && $rating >= 1 && $rating <= 5) {
        $pdo->prepare("INSERT IGNORE INTO reviews (product_id, product_title, author, rating, content, review_date, source, published, featured, order_id, dedupe, created_at)
                       VALUES (:p, '', :a, :r, :c, NOW(), 'cliente', 0, 0, :o, :d, NOW())")
            ->execute([':p' => $pid, ':a' => $author, ':r' => $rating, ':c' => $text, ':o' => $oid, ':d' => sha1('order|' . $oid . '|' . $pid)]);
        $done = true;
    } else $msg = 'Elige una calificación de 1 a 5 estrellas.';
}

require_once __DIR__ . '/seo-lib.php'; seo_set(['noindex' => true]);
site_header('Deja tu opinión', 'Cuéntanos cómo te fue con tu compra.');
?>
<div class="wrap page"><div class="panel" style="max-width:640px;margin:auto;">
<?php if (!$order): ?>
  <h1>Enlace no válido</h1><p class="lead">Este enlace no es válido o el pedido aún no fue enviado.</p>
<?php elseif ($done): ?>
  <h1>¡Gracias por tu opinión!</h1><p class="lead">La revisaremos y la publicaremos pronto.<?php if (function_exists('setting') && setting('review_reward_enabled') === '1' && (int) setting('review_reward_percent') > 0): ?> Cuando esté aprobada te enviaremos por correo un código de <b><?= (int) setting('review_reward_percent') ?>% de descuento</b> para tu próxima compra.<?php endif; ?></p><p><a class="btn-gold" href="catalogo.php">Ver el catálogo</a></p>
<?php else: ?>
  <h1>Deja tu opinión</h1>
  <?php if ($msg): ?><p style="color:#b00020;"><?= h($msg) ?></p><?php endif; ?>
  <form method="post" class="rev-form"><input type="hidden" name="o" value="<?= (int) $oid ?>"><input type="hidden" name="k" value="<?= h($k) ?>">
    <?php if (count($items) > 1): ?>
      <p><label>Producto<br><select name="product_id" style="padding:10px;width:100%;"><?php foreach ($items as $i): ?><option value="<?= (int) $i['product_id'] ?>"><?= h(review_clean($i['title'])) ?></option><?php endforeach; ?></select></label></p>
    <?php else: ?><input type="hidden" name="product_id" value="<?= (int) $items[0]['product_id'] ?>"><p class="lead"><?= h(review_clean($items[0]['title'])) ?></p><?php endif; ?>
    <div class="star-pick"><?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" id="s<?= $s ?>" name="rating" value="<?= $s ?>"><label for="s<?= $s ?>" title="<?= $s ?>">★</label><?php endfor; ?></div>
    <p><label>Tu nombre<br><input name="author" value="<?= h((string) $order['first_name']) ?>" maxlength="120" style="padding:10px;width:100%;border:1px solid var(--line);border-radius:8px;"></label></p>
    <p><label>Tu opinión<br><textarea name="content" maxlength="1500"></textarea></label></p>
    <button class="btn-block" type="submit">Enviar opinión</button>
  </form>
<?php endif; ?>
</div></div>
<?php site_footer();
