<?php
/**
 * carrito.php — carrito de compra. Acciones (POST): add, set, remove, clear. Luego checkout con Mercado Pago.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/cart-lib.php';
require_once __DIR__ . '/coupon-lib.php';
require_once __DIR__ . '/soldout-lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart = cart_get();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $vid = (int) ($_POST['variant_id'] ?? 0);
        $qty = max(1, min(CART_MAX_QTY, (int) ($_POST['quantity'] ?? 1)));
        if ($vid > 0) {
            $pidAdd = 0;
            try { $pq = get_pdo()->prepare("SELECT product_id FROM product_variants WHERE id = :v"); $pq->execute([':v' => $vid]); $pidAdd = (int) $pq->fetchColumn(); } catch (Throwable $e) {}
            $vsm = variant_stock_map();
            if (isset($vsm[$vid]) && $vsm[$vid] <= 0) { header('Location: producto.php?id=' . $pidAdd); exit; }
            if ($pidAdd && is_soldout($pidAdd)) { header('Location: ' . ($pidAdd ? 'producto.php?id=' . $pidAdd : 'catalogo.php')); exit; }
            $cart[$vid] = min(CART_MAX_QTY, ($cart[$vid] ?? 0) + $qty);
            try { track_event('cart_add', $pidAdd ?: null); } catch (Throwable $e) {}
        }
    } elseif ($action === 'set') {
        foreach ((array) ($_POST['qty'] ?? []) as $vid => $qty) {
            $vid = (int) $vid; $qty = (int) $qty;
            if ($qty <= 0) unset($cart[$vid]); elseif (isset($cart[$vid])) $cart[$vid] = min(CART_MAX_QTY, $qty);
        }
    } elseif ($action === 'remove') {
        unset($cart[(int) ($_POST['variant_id'] ?? 0)]);
    } elseif ($action === 'clear') {
        $cart = [];
    }
    cart_save($cart);
    if ($action === 'add' && !empty($_POST['stay']) && (int) ($_POST['product_ref'] ?? 0) > 0) { header('Location: producto.php?id=' . (int) $_POST['product_ref'] . '&added=1'); exit; }
    header('Location: carrito.php');
    exit;
}

$pdo = get_pdo();
$cart = cart_get();
$lines = []; $total = 0; $clean = [];
foreach ($cart as $vid => $qty) {
    $st = $pdo->prepare("SELECT pv.id, pv.title AS vt, pv.price, p.id AS pid, p.title AS pt, p.image_url
        FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = :id AND p.status = 'activo'");
    $st->execute([':id' => $vid]);
    $r = $st->fetch();
    if (!$r || (int) round((float) $r['price']) <= 0) continue; // producto retirado: se omite
    $unit = (int) round((float) $r['price']);
    $title = $r['pt'] . ((trim((string) $r['vt']) !== '' && $r['vt'] !== 'Default Title') ? ' — ' . $r['vt'] : '');
    $lines[] = ['vid' => $vid, 'qty' => $qty, 'unit' => $unit, 'title' => $title, 'pid' => $r['pid'], 'img' => $r['image_url'], 'sold' => is_soldout((int) $r['pid']) || (isset(variant_stock_map()[(int) $vid]) && variant_stock_map()[(int) $vid] < (int) $qty)];
    $total += $unit * $qty; $clean[$vid] = $qty;
}
$soldLines = []; foreach ($lines as $l) if (!empty($l['sold'])) $soldLines[] = $l['title'];
if ($clean !== $cart) cart_save($clean); // quita productos que ya no existen

site_header('Carrito', 'Tu carrito de compra');
?>
<style>
  .cart-grid { display:grid; grid-template-columns: 1.4fr .8fr; gap: 28px; align-items:start; }
  .cart-line { display:flex; gap:16px; align-items:center; padding:16px 0; border-bottom:1px solid var(--line); }
  .cart-line:last-child { border-bottom:0; }
  .cart-thumb { width:72px; height:72px; border-radius:10px; background:var(--surface-tint); overflow:hidden; flex:none; }
  .cart-thumb img { width:100%; height:100%; object-fit:cover; }
  .cart-info { flex:1; min-width:0; }
  .cart-info a { color:var(--text); text-decoration:none; font-weight:600; }
  .cart-qty { width:64px; padding:8px; border:1px solid var(--line); border-radius:8px; font-size:.95rem; }
  .link-btn { background:none; border:none; color:var(--ash); cursor:pointer; font-size:.82rem; text-decoration:underline; padding:0; font-family:var(--sans); }
  @media (max-width: 800px) { .cart-grid { grid-template-columns: 1fr; } }
</style>
<div class="wrap page">
  <h1>Tu carrito</h1>
  <?php if (!$lines): ?>
    <div class="panel"><p class="lead">Tu carrito está vacío.</p><p><a class="btn-gold" href="catalogo.php">Ver el catálogo</a></p></div>
  <?php else: ?>
  <div class="cart-grid">
    <div class="panel">
      <form method="post" id="cartform"><input type="hidden" name="action" value="set">
        <?php foreach ($lines as $l): ?>
        <div class="cart-line">
          <div class="cart-thumb"><?php if ($l['img']): ?><img src="<?= h($l['img']) ?>" alt=""><?php endif; ?></div>
          <div class="cart-info">
            <a href="producto.php?id=<?= (int) $l['pid'] ?>"><?= h($l['title']) ?></a>
            <div style="color:var(--ash);font-size:.88rem;margin-top:4px;"><?= money($l['unit']) ?> c/u</div>
            <button class="link-btn" type="submit" form="rm<?= (int) $l['vid'] ?>">Quitar</button>
          </div>
          <input class="cart-qty" type="number" min="1" max="<?= CART_MAX_QTY ?>" name="qty[<?= (int) $l['vid'] ?>]" value="<?= (int) $l['qty'] ?>" onchange="this.form.submit()">
          <div style="width:96px;text-align:right;font-weight:600;"><?= money($l['unit'] * $l['qty']) ?></div>
        </div>
        <?php endforeach; ?>
      </form>
      <?php foreach ($lines as $l): ?>
        <form method="post" id="rm<?= (int) $l['vid'] ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="variant_id" value="<?= (int) $l['vid'] ?>"></form>
      <?php endforeach; ?>
      <p style="margin:14px 0 0;"><a href="catalogo.php" style="font-size:.9rem;">← Seguir comprando</a>
        &nbsp;·&nbsp;<form method="post" style="display:inline;"><input type="hidden" name="action" value="clear"><button class="link-btn" type="submit">Vaciar carrito</button></form></p>
    </div>

    <div class="panel">
      <h2 style="font-family:var(--serif);margin:0 0 14px;font-size:1.2rem;">Finalizar compra</h2>
      <p style="margin:0 0 18px;display:flex;justify-content:space-between;"><span>Total</span><span class="price-big"><?= money($total) ?></span></p>
      <form method="post" action="checkout-create-preference.php">
        <input type="hidden" name="cart" value="1">
        <div class="field"><label for="name">Nombre</label><input id="name" type="text" name="name" autocomplete="name" required></div>
        <div class="field"><label for="email">Correo (aquí te enviamos tus claves)</label><input id="email" type="email" name="email" autocomplete="email" required></div>
        <div class="field"><label for="phone">WhatsApp (opcional)</label><input id="phone" type="tel" name="phone" autocomplete="tel" placeholder="+56 9 ..."></div>
        <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <?php $cp = coupon_config(); if ($cp['enabled']): ?>
        <div class="field"><label for="coupon">¿Tienes un código de descuento? (opcional)</label>
          <input id="coupon" type="text" name="coupon" autocomplete="off" style="text-transform:uppercase;" maxlength="30">
          <p id="cdisc" style="display:none;margin:6px 0 0;font-size:.85rem;color:#1b5e33;"></p>
          <p style="margin:4px 0 0;font-size:.76rem;color:var(--ash);">Válido solo en tu primer pedido; lo verificamos con tu correo.</p>
        </div>
        <script>
        (function () {
          var code = <?= json_encode($cp['code']) ?>, pct = <?= (int) $cp['percent'] ?>, total = <?= (int) $total ?>;
          var i = document.getElementById('coupon'), d = document.getElementById('cdisc');
          function fmt(n) { return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
          i.addEventListener('input', function () {
            var ok = i.value.replace(/\s+/g, '').toUpperCase() === code;
            d.style.display = ok ? 'block' : 'none';
            if (ok) d.textContent = '✓ ' + pct + '% de descuento: pagarías aprox. ' + fmt(total * (100 - pct) / 100);
          });
        })();
        </script>
        <?php endif; ?>
        <?php if (transfer_enabled()): ?>
        <div class="pay-choice" role="radiogroup" aria-label="Medio de pago">
          <label><input type="radio" name="payment_method" value="mercadopago" checked> Mercado Pago (tarjeta, débito, saldo)</label>
          <label><input type="radio" name="payment_method" value="transferencia"> Transferencia bancaria</label>
        </div>
        <?php endif; ?>
        <?php if ($soldLines): ?><p class="added-note" style="background:#fdeaea;color:#8a1f1f;">Hay productos agotados en tu carrito (<?= h(implode(', ', $soldLines)) ?>). Quítalos para continuar.</p>
        <button class="btn-block" type="button" disabled style="opacity:.55;cursor:not-allowed;">No disponible</button>
        <?php else: ?>
        <button class="btn-block" type="submit"><?= transfer_enabled() ? 'Continuar' : 'Pagar con Mercado Pago' ?> · <?= money($total) ?></button>
        <p style="font-size:.78rem;color:var(--ash);margin:12px 0 0;text-align:center;">Pago seguro con Mercado Pago. Recibirás tus claves por correo.</p>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php site_footer();
