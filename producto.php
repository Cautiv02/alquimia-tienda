<?php
/**
 * producto.php?id=ID — ficha de producto con opciones (variantes) y formulario de compra.
 * Envía a checkout-create-preference.php.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/catalog-lib.php';
require_once __DIR__ . '/soldout-lib.php';

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
$vstock = variant_stock_map();
$firstOk = null; foreach ($variants as $i => $v) { if (!isset($vstock[(int) $v['id']]) || $vstock[(int) $v['id']] > 0) { $firstOk = $i; break; } }

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
$fac = product_facets((string) $p['title']);
$cleanTitle = cat_clean((string) $p['title']);

site_header($p['title'], 'Licencia original ' . $p['title'] . '. Pago con Mercado Pago, entrega por correo.');
?>
<div class="wrap page">
  <p style="font-size:.85rem;color:var(--ash);margin:0 0 18px;"><a href="catalogo.php" style="text-decoration:none;">← Catálogo</a></p>
  <div class="product-grid">
    <div class="pg-main">
    <div class="panel buybox">
      <h1 style="font-size:1.5rem;"><?= h($p['title']) ?></h1>
      <?php if ($rCount): ?><p class="rating-line"><?= stars_html($rAvg) ?> <b><?= h(number_format($rAvg, 1, ',', '')) ?></b> · <a href="#opiniones"><?= (int) $rCount ?> opinión<?= $rCount === 1 ? '' : 'es' ?></a></p><?php endif; ?>
      <?php if (!empty($_GET['added'])): ?><p class="added-note">✓ Agregado al carrito. <a href="carrito.php">Ver carrito</a></p><?php endif; ?>
      <?php if (!$variants): ?>
        <p class="lead">Este producto no está disponible por ahora. Escríbenos por WhatsApp.</p>
      <?php else: ?>
      <form method="post" action="carrito.php"><input type="hidden" name="action" value="add"><input type="hidden" name="product_ref" value="<?= (int) $id ?>">
        <div class="field">
          <label>Elige tu opción</label>
          <div class="opt-grid" role="radiogroup">
            <?php foreach ($variants as $i => $v):
              $label = ($v['title'] === 'Default Title' || trim((string) $v['title']) === '') ? 'Licencia' : $v['title'];
              $off = ($v['compare_at_price'] && $v['compare_at_price'] > $v['price']) ? (int) round((1 - $v['price'] / $v['compare_at_price']) * 100) : 0; ?>
              <label class="opt">
                <input type="radio" name="variant_id" value="<?= (int) $v['id'] ?>" <?= $i === $firstOk ? 'checked' : '' ?> <?= (isset($vstock[(int) $v['id']]) && $vstock[(int) $v['id']] <= 0) ? 'disabled' : '' ?> required
                  data-price="<?= (int) round((float) $v['price']) ?>"
                  data-old="<?= $v['compare_at_price'] ? (int) round((float) $v['compare_at_price']) : 0 ?>">
                <span class="opt-name"><?= h($label) ?><?php if ($off > 0): ?> <em>-<?= $off ?>%</em><?php endif; ?></span>
                <span class="opt-price"><?= (isset($vstock[(int) $v['id']]) && $vstock[(int) $v['id']] <= 0) ? 'Agotado' : money($v['price']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <p style="margin:0 0 18px;">
          <span class="price-big" id="price"></span><span class="price-old" id="old"></span>
        </p>

        <div class="field">
          <label for="qty">Cantidad</label>
          <input id="qty" type="number" name="quantity" value="1" min="1" max="10">
        </div>
        <?php if (is_soldout((int) $id) || $firstOk === null): ?>
        <button class="btn-block" type="button" disabled style="opacity:.55;cursor:not-allowed;">Agotado por ahora</button>
        <p style="font-size:.85rem;color:var(--ash);margin:10px 0 0;text-align:center;">Este producto está agotado temporalmente. <a href="https://wa.me/" onclick="this.href='catalogo.php';">Ver otros productos</a></p>
        <?php else: ?>
        <button class="btn-block" type="submit">Comprar ahora · <span id="total"></span></button>
        <button class="btn-block" type="submit" name="stay" value="1" style="background:transparent;color:var(--text);border:1px solid var(--line);margin-top:10px;">🛒 Agregar al carrito</button>
        <?php endif; ?>
        <p style="font-size:.78rem;color:var(--ash);margin:12px 0 0;text-align:center;">Tus datos y el medio de pago los eliges en el carrito. Recibirás tu clave por correo.</p>
      </form>
      <script>
        (function () {
          var qty = document.getElementById('qty');
          var price = document.getElementById('price'), old = document.getElementById('old'), total = document.getElementById('total');
          function fmt(n) { return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
          function upd() {
            var o = document.querySelector('input[name=variant_id]:checked'), p = +o.dataset.price, od = +o.dataset.old;
            var q = Math.max(1, Math.min(10, +qty.value || 1));
            price.textContent = fmt(p);
            old.textContent = od > p ? fmt(od) : '';
            total.textContent = fmt(p * q);
          }
          document.querySelectorAll('input[name=variant_id]').forEach(function (r) { r.addEventListener('change', upd); }); qty.addEventListener('input', upd); upd();
        })();
      </script>
      <?php endif; ?>
    </div>
      <?php if (trim($descHtml) !== ''): ?><div class="desc"><?= $descHtml ?></div><?php endif; ?>
    </div>

    <aside class="pg-side">
      <div class="product-img">
        <?php if ($img): ?><img src="<?= h($img) ?>" width="840" height="840" fetchpriority="high" alt="<?= h($p['title']) ?>"><?php else: ?><span style="color:var(--ash)">Sin imagen</span><?php endif; ?>
      </div>
      <?php $qf = []; foreach (preg_split('/\R/', (string) ($p['quick_facts'] ?? '')) as $ln) { if (strpos($ln, ':') !== false) { [$a1, $b1] = array_map('trim', explode(':', $ln, 2)); if ($a1 !== '' && $b1 !== '') $qf[] = [$a1, $b1]; } } ?>
      <ul class="quick-facts">
        <?php if ($qf): foreach ($qf as $row): ?><li><span><?= h($row[0]) ?></span><b><?= h($row[1]) ?></b></li><?php endforeach; else: ?>
        <li><span>Marca</span><b><?= h($fac['brand']) ?></b></li>
        <?php if ($fac['platforms']): ?><li><span>Compatible</span><b><?= h(implode(', ', $fac['platforms'])) ?></b></li><?php endif; ?>
        <?php if ($fac['duration'] !== ''): ?><li><span>Vigencia</span><b><?= h($fac['duration']) ?></b></li><?php endif; ?>
        <li><span>Entrega</span><b>Por correo</b></li>
        <li><span>Pago</span><b>Mercado Pago</b></li>
        <?php endif; ?>
      </ul>
    </aside>
  </div>

  <?php if ($revs): ?>
  <section id="opiniones" class="reviews-block">
    <h2>Opiniones de clientes</h2>
    <p class="rating-line"><?= stars_html($rAvg) ?> <b><?= h(number_format($rAvg, 1, ',', '')) ?></b> de 5 · <?= (int) $rCount ?> opinión<?= $rCount === 1 ? '' : 'es' ?></p>
    <div class="reviews-wrap"><button type="button" class="rv-arrow prev" aria-label="Anterior">‹</button><button type="button" class="rv-arrow next" aria-label="Siguiente">›</button><div class="reviews-grid">
      <?php foreach ($revs as $r): if (trim($r['content']) === '') continue; ?>
      <div class="review-card">
        <div class="rv-top"><?= stars_html((float) $r['rating']) ?><?= verified_badge() ?></div>
        <?php if (trim($r['content']) !== ''): ?><p><?= h($r['content']) ?></p><?php endif; ?>
        <span class="rv-by"><?= h($r['author']) ?> · <?= h(date('d/m/Y', strtotime($r['review_date']))) ?></span>
      </div>
      <?php endforeach; ?>
    </div></div>
    <p style="text-align:center;margin:6px 0 0;"><a href="resenas.php?p=<?= (int) $id ?>" style="color:var(--gold);font-weight:600;text-decoration:none;">Ver todas las opiniones de este producto →</a></p>
  </section>
  <?php reviews_scroll_js(); ?>
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['title'],
      'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => round($rAvg, 1), 'reviewCount' => (int) $rCount]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
  <?php endif; ?>
</div>
<?php site_footer();
