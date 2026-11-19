<?php
/**
 * producto.php?id=ID — ficha de producto con opciones (variantes) y formulario de compra.
 * Envía a checkout-create-preference.php.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';

$pdo = get_pdo();
$id = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM products WHERE id = :id AND status = 'activo'");
$st->execute([':id' => $id]);
$p = $st->fetch();

if (!$p) {
    http_response_code(404);
    site_header('Producto no encontrado');
    echo '<div class="wrap page"><div class="panel"><h1>Producto no encontrado</h1><p class="lead">Es posible que ya no esté disponible.</p><p><a class="btn-gold" href="catalogo.php">Ver el catálogo</a></p></div></div>';
    site_footer();
    exit;
}

$vs = $pdo->prepare("SELECT id, title, price, compare_at_price FROM product_variants WHERE product_id = :id ORDER BY price ASC, id ASC");
$vs->execute([':id' => $id]);
$variants = $vs->fetchAll();

// Descripción de Shopify: dejamos solo etiquetas básicas
$descHtml = strip_tags((string) $p['description_html'], '<p><br><ul><ol><li><strong><b><em><i><h2><h3><h4>');

$img = $p['image_url'] ?: null;
$revs = []; $rAvg = 0.0;
try {
    $rs = $pdo->prepare("SELECT author, rating, content, review_date FROM reviews WHERE product_id = :id AND published = 1 ORDER BY featured DESC, review_date DESC LIMIT 200");
    $rs->execute([':id' => $id]);
    $revs = $rs->fetchAll();
    if ($revs) $rAvg = array_sum(array_column($revs, 'rating')) / count($revs);
    $rst = reviews_stats()[$id] ?? null;
    if ($rst) $rAvg = $rst['avg'];
} catch (Throwable $e) { }
$rCount = (reviews_stats()[$id]['n'] ?? count($revs));
$err = (string) ($_GET['error'] ?? '');

site_header($p['title'], 'Licencia original ' . $p['title'] . '. Pago con Mercado Pago, entrega por correo.');
?>
<div class="wrap page">
  <p style="font-size:.85rem;color:var(--ash);margin:0 0 18px;"><a href="catalogo.php" style="text-decoration:none;">← Catálogo</a></p>
  <div class="product-grid">
    <div>
      <div class="product-img">
        <?php if ($img): ?><img src="<?= h($img) ?>" alt="<?= h($p['title']) ?>"><?php else: ?><span style="color:var(--ash)">Sin imagen</span><?php endif; ?>
      </div>
      <?php if (trim($descHtml) !== ''): ?><div class="desc"><?= $descHtml ?></div><?php endif; ?>
    </div>

    <div class="panel">
      <h1 style="font-size:1.5rem;"><?= h($p['title']) ?></h1>
      <?php if ($rCount): ?><p class="rating-line"><?= stars_html($rAvg) ?> <b><?= h(number_format($rAvg, 1, ',', '')) ?></b> · <a href="#opiniones"><?= (int) $rCount ?> opinión<?= $rCount === 1 ? '' : 'es' ?></a></p><?php endif; ?>
      <?php if (!$variants): ?>
        <p class="lead">Este producto no está disponible por ahora. Escríbenos por WhatsApp.</p>
      <?php else: ?>
      <form method="post" action="checkout-create-preference.php">
        <div class="field">
          <label for="variant">Opción</label>
          <select id="variant" name="variant_id" required>
            <?php foreach ($variants as $v):
              $label = ($v['title'] === 'Default Title' || trim((string) $v['title']) === '') ? 'Licencia' : $v['title']; ?>
              <option value="<?= (int) $v['id'] ?>"
                data-price="<?= (int) round((float) $v['price']) ?>"
                data-old="<?= $v['compare_at_price'] ? (int) round((float) $v['compare_at_price']) : 0 ?>">
                <?= h($label) ?> — <?= money($v['price']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <p style="margin:0 0 18px;">
          <span class="price-big" id="price"></span><span class="price-old" id="old"></span>
        </p>

        <div class="field">
          <label for="qty">Cantidad</label>
          <input id="qty" type="number" name="quantity" value="1" min="1" max="10">
        </div>
        <div class="field">
          <label for="name">Nombre</label>
          <input id="name" type="text" name="name" autocomplete="name" required>
        </div>
        <div class="field">
          <label for="email">Correo (aquí te enviamos tus claves)</label>
          <input id="email" type="email" name="email" autocomplete="email" required>
        </div>
        <div class="field">
          <label for="phone">WhatsApp (opcional)</label>
          <input id="phone" type="tel" name="phone" autocomplete="tel" placeholder="+56 9 ...">
        </div>
        <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

        <?php if (transfer_enabled()): ?>
        <div class="pay-choice" role="radiogroup" aria-label="Medio de pago">
          <label><input type="radio" name="payment_method" value="mercadopago" checked> Mercado Pago (tarjeta, débito, saldo)</label>
          <label><input type="radio" name="payment_method" value="transferencia"> Transferencia bancaria</label>
        </div>
        <?php endif; ?>
        <button class="btn-block" type="submit">Comprar ahora · <span id="total"></span></button>
        <button class="btn-block" type="submit" formaction="carrito.php" formnovalidate name="action" value="add" style="background:transparent;color:var(--text);border:1px solid var(--line);margin-top:10px;">🛒 Agregar al carrito</button>
        <p style="font-size:.78rem;color:var(--ash);margin:12px 0 0;text-align:center;">Pago seguro con Mercado Pago. Recibirás tu clave por correo.</p>
      </form>
      <script>
        (function () {
          var sel = document.getElementById('variant'), qty = document.getElementById('qty');
          var price = document.getElementById('price'), old = document.getElementById('old'), total = document.getElementById('total');
          function fmt(n) { return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
          function upd() {
            var o = sel.options[sel.selectedIndex], p = +o.dataset.price, od = +o.dataset.old;
            var q = Math.max(1, Math.min(10, +qty.value || 1));
            price.textContent = fmt(p);
            old.textContent = od > p ? fmt(od) : '';
            total.textContent = fmt(p * q);
          }
          sel.addEventListener('change', upd); qty.addEventListener('input', upd); upd();
        })();
      </script>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($revs): ?>
  <section id="opiniones" class="reviews-block">
    <h2>Opiniones de clientes</h2>
    <p class="rating-line"><?= stars_html($rAvg) ?> <b><?= h(number_format($rAvg, 1, ',', '')) ?></b> de 5 · <?= (int) $rCount ?> opinión<?= $rCount === 1 ? '' : 'es' ?></p>
    <div class="reviews-wrap"><button type="button" class="rv-arrow prev" aria-label="Anterior">‹</button><button type="button" class="rv-arrow next" aria-label="Siguiente">›</button><div class="reviews-grid">
      <?php foreach ($revs as $r): if (trim($r['content']) === '') continue; ?>
      <div class="review-card">
        <?= stars_html((float) $r['rating']) ?>
        <?php if (trim($r['content']) !== ''): ?><p><?= h($r['content']) ?></p><?php endif; ?>
        <span class="rv-by"><?= h($r['author']) ?> · <?= h(date('d/m/Y', strtotime($r['review_date']))) ?></span>
      </div>
      <?php endforeach; ?>
    </div></div>
  </section>
  <?php reviews_scroll_js(); ?>
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['title'],
      'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => round($rAvg, 1), 'reviewCount' => (int) $rCount]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
  <?php endif; ?>
</div>
<?php site_footer();
