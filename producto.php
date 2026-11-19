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

        <label style="display:flex;gap:9px;align-items:flex-start;font-size:.85rem;color:var(--text-dim);margin:6px 0 18px;cursor:pointer;">
          <input type="checkbox" name="accept" value="1" required style="margin-top:3px;accent-color:var(--gold);">
          <span>Confirmo que revisé el producto y que, una vez entregada la clave, la compra no tiene reembolso.</span>
        </label>

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
</div>
<?php site_footer();
