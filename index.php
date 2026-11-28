<?php
require_once __DIR__ . '/cart-lib.php';
/**
 * index.php — Portada dinámica de Alquimia Digital
 * Usa la misma base visual de homepage.html, pero trae productos reales
 * desde la base de datos en vez de datos de ejemplo.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/config.php';
$pdo = get_pdo();

require_once __DIR__ . '/home-lib.php';
require_once __DIR__ . '/catalog-lib.php';
$allActive = home_products($pdo);
$homeSections = home_load($pdo);
if ($homeSections === null) $homeSections = home_resolve_defaults($allActive);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/icons3d.php';
require_once __DIR__ . '/coupon-lib.php';
require_once __DIR__ . '/soldout-lib.php';
track_event('view');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alquimia Digital — Licencias de software</title>
<meta name="description" content="Licencias de Windows, Office, antivirus, Adobe y Autodesk para Chile. Entrega digital por correo, pago con Mercado Pago, transferencia o Binance Pay. Soporte por WhatsApp.">
<?php require_once __DIR__ . '/seo-lib.php'; seo_set(['canonical' => SITE_URL . '/']); seo_head('Licencias de software', 'Licencias de Windows, Office, antivirus, Adobe y Autodesk para Chile. Entrega digital por correo.'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"></noscript>
<link rel="stylesheet" href="site.css?v=<?= (int) @filemtime(__DIR__ . '/site.css') ?>">
</head>
<body>
<?php site_banner(); ?>

<header>
  <div class="wrap nav-row">
    <div class="logo"><img src="images/logo.jpg" width="147" height="80" alt="Alquimia Digital"></div>
    <nav class="links">
      <a href="catalogo.php?cat=windows">Windows</a>
      <a href="catalogo.php?cat=office">Office</a>
      <a href="catalogo.php?cat=antivirus">Antivirus</a>
      <a href="catalogo.php?cat=adobe">Adobe</a>
      <a href="catalogo.php?cat=autodesk">Autodesk</a>
      <a href="catalogo.php">Catálogo</a>
    </nav>
    <?php site_search_box(); ?>
    <div class="nav-actions">
      
      <a href="carrito.php" style="color:var(--text);text-decoration:none;font-weight:600;font-size:.92rem;margin-right:14px;white-space:nowrap;">🛒 Carrito<?php $cc = cart_count(); if ($cc): ?> <span style="background:var(--gold);color:#FFFBF3;border-radius:20px;padding:1px 8px;font-size:.75rem;"><?= (int) $cc ?></span><?php endif; ?></a>
      <a class="btn-gold" href="portal.php">Consultar pedido</a>
    </div>
    <?php site_mobile_menu(); ?>
  </div>
  <?php site_mobile_panel(); ?>
</header>
<main id="contenido">

<section class="hero-banner">
  <img src="images/banner.jpg" srcset="images/banner-m.jpg 896w, images/banner.jpg 1792w" sizes="(max-width: 900px) 100vw, 1400px" width="1792" height="576" fetchpriority="high" alt="Alquimia Digital — Seguro, rápido y garantizado. Soporte técnico especializado, 8 años y más de 12.000 clientes, envíos digitales rápidos, de Chile para el mundo.">
</section>

<div class="brands">
  <div class="brands-track">
    <img src="images/marcas/office.png" width="260" height="82" loading="lazy" alt="Office">
    <img src="images/marcas/adobe.png" width="440" height="114" loading="lazy" alt="Adobe">
    <img src="images/marcas/autodesk.png" width="512" height="85" loading="lazy" alt="Autodesk">
    <img src="images/marcas/mcafee.png" width="260" height="66" loading="lazy" alt="McAfee">
    <img src="images/marcas/eset.png" width="200" height="79" loading="lazy" alt="ESET">
    <img src="images/marcas/kaspersky.png" width="200" height="39" loading="lazy" alt="Kaspersky">
    <img src="images/marcas/norton.png" width="460" height="104" loading="lazy" alt="Norton">
    <img src="images/marcas/avg.png" width="200" height="82" loading="lazy" alt="AVG">
    <img src="images/marcas/panda.png" width="229" height="64" loading="lazy" alt="Panda Security">
    <!-- duplicado para el loop continuo -->
    <img src="images/marcas/office.png" width="260" height="82" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/adobe.png" width="440" height="114" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/autodesk.png" width="512" height="85" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/mcafee.png" width="260" height="66" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/eset.png" width="200" height="79" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/kaspersky.png" width="200" height="39" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/norton.png" width="460" height="104" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/avg.png" width="200" height="82" loading="lazy" alt="" aria-hidden="true">
    <img src="images/marcas/panda.png" width="229" height="64" loading="lazy" alt="" aria-hidden="true">
  </div>
</div>

<?php foreach ($homeSections as $sec): if (empty($sec['items'])) continue; ?>
<section class="section home-block" id="<?= htmlspecialchars($sec["id"]) ?>">
  <div class="wrap">
    <div class="section-head">
      <h2 class="section-title"><?= htmlspecialchars($sec['title']) ?></h2>
    </div>
    <div class="car-wrap"><button type="button" class="rv-arrow prev" aria-label="Anterior">‹</button><button type="button" class="rv-arrow next" aria-label="Siguiente">›</button>
    <div class="offer-grid">
      <?php foreach ($sec['items'] as $o):
        $hasDiscount = old_prices_on() && $o['compare_at_price'] && $o['compare_at_price'] > $o['price'];
        $imgSrc = $o['image_url'] ?: null;
      ?>
      <a class="offer-card" href="producto.php?id=<?= (int) $o['product_id'] ?>" style="text-decoration:none;">
        <div class="offer-image">
          <?php $bd = (string) ($o['badge'] ?? ''); $bdl = home_badges();
          if (!empty($o['sold_out']) || is_soldout((int) $o['product_id'])): ?><span class="offer-badge agotado">Agotado</span>
          <?php elseif ($bd !== '' && isset($bdl[$bd])): ?><span class="offer-badge b-<?= htmlspecialchars($bd) ?>"><?= htmlspecialchars($bdl[$bd]) ?></span>
          <?php elseif ($hasDiscount): ?><span class="offer-badge disponible">Oferta</span><?php endif; ?>
          <?php if ($imgSrc): ?>
            <img src="<?= htmlspecialchars($imgSrc) ?>" width="600" height="600" loading="lazy" alt="<?= htmlspecialchars(display_title((string) $o['clean_title'])) ?>">
          <?php else: ?>
            <span class="placeholder-mark">foto del producto</span>
          <?php endif; ?>
        </div>
        <div class="offer-body">
          <p class="offer-title"><?= htmlspecialchars(display_title((string) $o['clean_title'])) ?></p>
          <div class="offer-price-row">
            <?php if ($hasDiscount): ?><span class="offer-price-old"><?= money($o['compare_at_price']) ?></span><?php endif; ?>
            <span class="offer-price"><?= ((int) $o['nvar'] > 1 ? 'Desde ' : '') . money($o['price']) ?> CLP</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    </div>
  </div>
</section>
<?php endforeach; ?>
<?php if (!$allActive): ?><div class="wrap"><p style="color:var(--ash);">Aún no hay productos cargados en el catálogo.</p></div><?php endif; ?>


<?php
$homeRevs = [];
try {
    $homeRevs = get_pdo()->query("SELECT author, rating, content, review_date FROM reviews WHERE published = 1 AND featured = 1 AND CHAR_LENGTH(content) >= 25 ORDER BY review_date DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) { }
if ($homeRevs): $rsum = reviews_store_summary(); ?>
<section class="section home-block">
  <div class="wrap">
    <h2 style="text-align:center;">Opiniones de nuestros clientes</h2>
    <p class="rating-line" style="justify-content:center;"><?= stars_html($rsum['avg']) ?> <b><?= h(number_format($rsum['avg'], 1, ',', '')) ?></b> de 5 · <?= (int) $rsum['n'] ?> reseñas de clientes</p>
    <div class="reviews-wrap"><button type="button" class="rv-arrow prev" aria-label="Anterior">‹</button><button type="button" class="rv-arrow next" aria-label="Siguiente">›</button><div class="reviews-grid">
      <?php foreach ($homeRevs as $r): ?>
      <div class="review-card"><div class="rv-top"><?= stars_html((float) $r['rating']) ?><?= verified_badge() ?></div><p><?= h($r['content']) ?></p><span class="rv-by"><?= h($r['author']) ?> · <?= h(date('d/m/Y', strtotime($r['review_date']))) ?></span></div>
      <?php endforeach; ?>
    </div></div>
    <p style="text-align:center;margin:6px 0 0;"><a href="resenas.php" style="color:var(--gold);font-weight:600;text-decoration:none;">Ver todas las reseñas →</a></p>
  </div>
</section>
<?php reviews_scroll_js(); endif; ?>

<section class="section on-paper how-block" id="como-funciona">
  <div class="wrap">
    <div class="section-head">
      <h2 class="section-title">De la compra a la activación, sin vueltas</h2>
    </div>
    <div class="steps-with-card no-card">
      <div class="steps">
        <div class="step-card s-pago">
          <div class="step-ic"><?= icon3d('pago', 60) ?></div>
          <div class="step-mark">01 — pago</div>
          <h3 class="step-title">Realiza tu compra</h3>
          <p class="step-body">Paga de forma segura con Mercado Pago. Confirmamos tu pago apenas se acredita.</p>
        </div>
        <div class="step-card s-entrega">
          <div class="step-ic"><?= icon3d('entrega', 60) ?></div>
          <div class="step-mark">02 — entrega</div>
          <h3 class="step-title">Recibe tu clave</h3>
          <p class="step-body">Te enviamos tu clave por correo, con las instrucciones para instalarla.</p>
        </div>
        <div class="step-card s-activa">
          <div class="step-ic"><?= icon3d('activa', 60) ?></div>
          <div class="step-mark">03 — activación</div>
          <h3 class="step-title">Instala y activa</h3>
          <p class="step-body">Sigue el tutorial de tu producto y actívalo. Si tienes algún problema, te ayudamos por WhatsApp o correo.</p>
        </div>
      </div>
    </div>

    <div class="trust-strip">
      <div class="trust-item"><?= icon3d('rayo', 40) ?><span><b>Entrega rápida</b> — tu clave llega por correo</span></div>
      <div class="trust-item"><?= icon3d('escudo', 40) ?><span><b>Pago seguro</b> — con Mercado Pago</span></div>
      <div class="trust-item"><?= icon3d('chat', 40) ?><span><b>Soporte real</b> — por WhatsApp y correo</span></div>
    </div>
  </div>
</section>

<script>
document.querySelectorAll('.car-wrap').forEach(function (w) {
  var g = w.querySelector('.offer-grid'), pv = w.querySelector('.prev'), nx = w.querySelector('.next');
  function upd() { var more = g.scrollWidth > g.clientWidth + 4; pv.style.display = nx.style.display = more ? '' : 'none'; pv.style.opacity = g.scrollLeft < 4 ? .35 : 1; nx.style.opacity = g.scrollLeft + g.clientWidth >= g.scrollWidth - 4 ? .35 : 1; }
  [pv, nx].forEach(function (b) { b.addEventListener('click', function () { g.scrollBy({ left: (b === nx ? 1 : -1) * g.clientWidth * 0.85, behavior: 'smooth' }); }); });
  g.addEventListener('scroll', upd, { passive: true }); window.addEventListener('resize', upd); upd();
  // Escritorio: la imagen de cada tarjeta se desplaza un poco siguiendo el cursor
  g.querySelectorAll('.offer-card').forEach(function (c) {
    c.addEventListener('mousemove', function (e) {
      if (!window.matchMedia('(hover: hover) and (min-width: 701px)').matches) return;
      var r = c.getBoundingClientRect();
      c.style.setProperty('--mx', (-(e.clientX - r.left - r.width / 2) / r.width * 14).toFixed(1) + 'px');
      c.style.setProperty('--my', (-(e.clientY - r.top - r.height / 2) / r.height * 14).toFixed(1) + 'px');
    });
    c.addEventListener('mouseleave', function () { c.style.removeProperty('--mx'); c.style.removeProperty('--my'); });
  });
  // Escritorio: avanza solo cada pocos segundos; se detiene al pasar el mouse o con «reducir movimiento»
  var desk = window.matchMedia('(min-width: 701px)'), calm = window.matchMedia('(prefers-reduced-motion: reduce)'), hover = false;
  w.addEventListener('mouseenter', function () { hover = true; }); w.addEventListener('mouseleave', function () { hover = false; });
  w.addEventListener('focusin', function () { hover = true; }); w.addEventListener('focusout', function () { hover = false; });
  setInterval(function () {
    if (!desk.matches || calm.matches || hover || document.hidden || g.scrollWidth <= g.clientWidth + 4) return;
    if (g.scrollLeft + g.clientWidth >= g.scrollWidth - 4) g.scrollTo({ left: 0, behavior: 'smooth' });
    else g.scrollBy({ left: g.clientWidth * 0.85, behavior: 'smooth' });
  }, 5000);
});
</script>

<?php site_popup(); ?>
<?php site_footer();
