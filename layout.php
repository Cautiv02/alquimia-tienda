<?php
/**
 * layout.php — cabecera y pie compartidos para las páginas nuevas
 * (producto, catálogo, resultado del pago). Usa site.css, que es el mismo CSS de index.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cart-lib.php';
require_once __DIR__ . '/settings-lib.php';
require_once __DIR__ . '/seo-lib.php';
require_once __DIR__ . '/stats-lib.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n): string { return '$' . number_format((float) $n, 0, ',', '.'); }
}

require_once __DIR__ . '/reviews-lib.php';

/** Ventana emergente de la portada (se configura en Ajustes). */
function site_popup(): void {
    $s = settings_all();
    if ($s['popup_enabled'] !== '1' || trim($s['popup_title']) === '') return;
    $cp = function_exists('coupon_config') ? coupon_config() : ['enabled' => false, 'code' => '', 'percent' => 0];
    $rep = function (string $t) use ($cp): string { return str_replace(['{codigo}', '{porcentaje}'], [$cp['code'], (string) $cp['percent']], $t); };
    $title = $rep($s['popup_title']); $text = $rep($s['popup_text']);
    $hasCode = $cp['enabled'] && strpos($s['popup_title'] . $s['popup_text'], '{codigo}') !== false;
    $key = 'alq_pop_' . substr(md5($title . $text), 0, 8);
    ?>
<div id="alq-pop" class="alq-pop" hidden role="dialog" aria-modal="true" aria-labelledby="alq-pop-t">
  <div class="alq-pop-box">
    <button type="button" class="alq-pop-x" aria-label="Cerrar">✕</button>
    <h2 id="alq-pop-t"><?= h($title) ?></h2>
    <?php if (trim($text) !== ''): ?><p><?= h($text) ?></p><?php endif; ?>
    <?php if ($hasCode): ?><button type="button" class="alq-pop-code" data-code="<?= h($cp['code']) ?>" title="Copiar código"><?= h($cp['code']) ?> <span>📋 Copiar</span></button><?php endif; ?>
    <a class="btn-gold alq-pop-go" href="<?= h($s['popup_button_url'] ?: 'catalogo.php') ?>"><?= h($s['popup_button_label'] ?: 'Ver productos') ?></a>
    <button type="button" class="alq-pop-no">No, gracias</button>
  </div>
</div>
<script>
(function () {
  var key = <?= json_encode($key) ?>, days = <?= (int) $s['popup_days'] ?>, delay = <?= (int) $s['popup_delay'] ?> * 1000;
  var el = document.getElementById('alq-pop');
  try { var t = localStorage.getItem(key); if (t && days > 0 && Date.now() - (+t) < days * 86400000) return; } catch (e) {}
  function close() { el.hidden = true; document.removeEventListener('keydown', onKey); try { localStorage.setItem(key, String(Date.now())); } catch (e) {} }
  function onKey(e) { if (e.key === 'Escape') close(); }
  setTimeout(function () {
    el.hidden = false; document.addEventListener('keydown', onKey);
    var go = el.querySelector('.alq-pop-go'); if (go) go.focus();
  }, delay);
  el.addEventListener('click', function (e) { if (e.target === el || e.target.closest('.alq-pop-x') || e.target.closest('.alq-pop-no') || e.target.closest('.alq-pop-go')) close(); });
  var cb = el.querySelector('.alq-pop-code');
  if (cb) cb.addEventListener('click', function () {
    var c = cb.dataset.code, sp = cb.querySelector('span'), done = function () { sp.textContent = '✓ Copiado'; };
    function fb() { var a = document.createElement('textarea'); a.value = c; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); done(); } catch (x) {} a.remove(); }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(c).then(done, fb); else fb();
  });
})();
</script>
<?php }

function site_banner(): void {
    $s = settings_all();
    if ($s['banner_enabled'] !== '1' || trim($s['banner_text']) === '') return;
    $id = substr(md5($s['banner_text'] . $s['banner_link_url']), 0, 8);
    $url = $s['banner_link_url']; ?>
<div class="site-banner" id="site-banner" data-id="<?= h($id) ?>" role="status">
  <span><?= h($s['banner_text']) ?><?php if ($url !== '' && trim($s['banner_link_label']) !== ''): ?> <a href="<?= h($url) ?>"><?= h($s['banner_link_label']) ?></a><?php endif; ?></span>
  <button type="button" aria-label="Cerrar aviso" onclick="(function(b){b.style.display='none';try{localStorage.setItem('bn_'+b.dataset.id,'1');}catch(e){}})(document.getElementById('site-banner'))">×</button>
</div>
<script>(function(){var b=document.getElementById('site-banner');try{if(b&&localStorage.getItem('bn_'+b.dataset.id)==='1')b.style.display='none';}catch(e){}})();</script>
<?php }

function site_mobile_menu(): void { ?>
<button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false" aria-controls="mobile-panel">☰</button>
<?php }

function site_mobile_panel(): void { ?>
<nav class="mobile-panel" id="mobile-panel" hidden>
  <a href="catalogo.php?cat=windows">Windows</a>
  <a href="catalogo.php?cat=office">Office</a>
  <a href="catalogo.php?cat=antivirus">Antivirus</a>
  <a href="catalogo.php?cat=adobe">Adobe</a>
  <a href="catalogo.php?cat=autodesk">Autodesk</a>
  <a href="catalogo.php">Catálogo completo</a>
  <a href="resenas.php">Opiniones</a>
  <a href="portal.php" class="mp-order">Consultar pedido</a>
</nav>
<script>
(function () {
  var b = document.querySelector('.menu-toggle'), p = document.getElementById('mobile-panel'); if (!b || !p) return;
  b.addEventListener('click', function () { var open = p.hidden; p.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); b.textContent = open ? '✕' : '☰'; });
})();
</script>
<?php }

function site_search_box(): void { ?>
<form class="nav-search" action="catalogo.php" method="get" role="search" autocomplete="off">
  <input type="search" name="q" placeholder="Buscar licencias…" aria-label="Buscar" maxlength="60">
  <ul class="ns-list" hidden></ul>
</form>
<script>
(function () {
  var f = document.querySelector('.nav-search'); if (!f) return;
  var inp = f.querySelector('input'), ul = f.querySelector('.ns-list'), t, idx = -1, items = [];
  function close() { ul.hidden = true; idx = -1; }
  function mark() { [].forEach.call(ul.children, function (li, i) { li.classList.toggle('on', i === idx); }); }
  function render(data, q) {
    ul.innerHTML = ''; items = data;
    data.forEach(function (d) {
      var li = document.createElement('li'), a = document.createElement('a');
      a.href = 'producto.php?id=' + d.id;
      var s = document.createElement('span'); s.className = 'ns-t'; s.textContent = d.t; a.appendChild(s);
      var p = document.createElement('b'); p.textContent = d.p; a.appendChild(p);
      li.appendChild(a); ul.appendChild(li);
    });
    var li = document.createElement('li'), a = document.createElement('a');
    a.className = 'ns-all'; a.href = 'catalogo.php?q=' + encodeURIComponent(q);
    a.textContent = data.length ? 'Ver todos los resultados' : 'Sin resultados. Ver el catálogo completo';
    li.appendChild(a); ul.appendChild(li); ul.hidden = false; idx = -1;
  }
  inp.addEventListener('input', function () {
    clearTimeout(t); var q = inp.value.trim();
    if (q.length < 2) { close(); return; }
    t = setTimeout(function () {
      fetch('buscar.php?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); })
        .then(function (d) { if (inp.value.trim() === q) render(d, q); }).catch(function () {});
    }, 150);
  });
  inp.addEventListener('keydown', function (e) {
    var n = ul.children.length;
    if (e.key === 'ArrowDown' && !ul.hidden) { e.preventDefault(); idx = (idx + 1) % n; mark(); }
    else if (e.key === 'ArrowUp' && !ul.hidden) { e.preventDefault(); idx = (idx - 1 + n) % n; mark(); }
    else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); ul.children[idx].querySelector('a').click(); }
    else if (e.key === 'Escape') close();
  });
  document.addEventListener('click', function (e) { if (!f.contains(e.target)) close(); });
  inp.addEventListener('focus', function () { if (ul.children.length) ul.hidden = false; });
})();
</script>
<?php }

function site_header(string $title, string $description = ''): void {
    track_event('view');
    try { require_once __DIR__ . '/order-expiry-lib.php'; expire_binance_orders_lazy(); } catch (Throwable $e) {} ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> | <?= h(SITE_NAME) ?></title>
<?php if ($description): ?><meta name="description" content="<?= h($description) ?>"><?php endif; ?>
<?php require_once __DIR__ . '/seo-lib.php'; seo_head($title, $description); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet"></noscript>
<link rel="stylesheet" href="site.css?v=<?= (int) @filemtime(__DIR__ . '/site.css') ?>">
<style>
  .page { padding-top: 40px; padding-bottom: 20px; }
  .page h1 { font-family: var(--serif); font-size: clamp(1.6rem, 3vw, 2.2rem); margin: 0 0 8px; }
  .page .lead { color: var(--text-dim); margin: 0 0 28px; }
  .panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); padding: 26px; }
  .field { margin-bottom: 16px; }
  .field label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 6px; }
  .field input, .field select, .field textarea {
    width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px;
    font-family: var(--sans); font-size: 0.95rem; background: #fff; color: var(--text);
  }
  .field input:focus, .field select:focus { outline: none; border-color: var(--gold); }
  .btn-block { width: 100%; background: var(--gold); color: #FFFBF3; border: none; padding: 14px 24px; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; font-family: var(--sans); }
  .btn-block:hover { background: var(--gold-bright); }
  .alert { background: var(--surface-tint); border: 1px solid var(--line); border-radius: 10px; padding: 16px 18px; margin-bottom: 24px; font-size: 0.92rem; }
  .alert.error { border-color: rgba(185,134,42,0.5); }
  .alert.ok { border-color: rgba(42,125,116,0.5); }
  .product-grid { display: grid; grid-template-columns: 420px minmax(0, 1fr); gap: 32px; align-items: start; }
  .pg-side { position: sticky; top: 16px; grid-column: 1; grid-row: 1; }
  .pg-main { grid-column: 2; grid-row: 1; }
  .product-img { background: #fff; border: 1px solid var(--line); border-radius: var(--radius-card); overflow: hidden; aspect-ratio: 1/1; display: flex; align-items: center; justify-content: center; }
  .product-img img { width: 100%; height: 100%; object-fit: cover; object-position: center; }
  .desc { color: var(--text-dim); font-size: 0.95rem; margin-top: 22px; }
  .desc p { margin: 0 0 12px; }
  .price-big { font-size: 1.6rem; font-weight: 600; }
  .price-old { color: var(--ash); text-decoration: line-through; margin-left: 10px; font-size: 1rem; }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; margin: 0 0 28px; }
  .filters input[type=text] { flex: 1; min-width: 220px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; font-family: var(--sans); font-size: 0.95rem; }
  .chip { display: inline-block; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--line); background: #fff; font-size: 0.85rem; text-decoration: none; color: var(--text-dim); }
  .chip.on, .chip:hover { background: var(--gold); border-color: var(--gold); color: #FFFBF3; }
  .cart-link { color: var(--text); text-decoration: none; font-weight: 600; font-size: .92rem; margin-right: 14px; white-space: nowrap; }
  .cart-badge { background: var(--gold); color: #FFFBF3; border-radius: 20px; padding: 1px 8px; font-size: .75rem; }
  .hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }
    .quick-facts { list-style: none; margin: 14px 0 0; padding: 0; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
  .quick-facts li { display: flex; justify-content: space-between; gap: 10px; padding: 10px 14px; font-size: .85rem; border-bottom: 1px solid var(--line); }
  .quick-facts li:last-child { border-bottom: 0; } .quick-facts span { color: var(--ash); } .quick-facts b { text-align: right; font-weight: 600; }
  .pt-title { font-family: var(--sans); font-weight: 700; font-size: 1.4rem; line-height: 1.25; letter-spacing: -.01em; margin: 0 0 6px; }
  .opt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; }
  .opt-grid.many { max-height: 236px; overflow-y: auto; padding: 2px 4px 2px 2px; }
  .opt { display: flex; flex-direction: column; justify-content: center; gap: 1px; min-height: 52px; border: 1.5px solid var(--line); border-radius: 10px; padding: 7px 10px; cursor: pointer; background: #fff; position: relative; }
  .field .opt { display: flex; margin: 0; font-size: inherit; font-weight: 400; } /* .field label lo hacía bloque: nombre y precio quedaban en una sola línea */
  .opt input { position: absolute; opacity: 0; pointer-events: none; }
  .opt-name { font-size: .82rem; font-weight: 600; line-height: 1.2; padding-right: 14px; } .opt-name em { font-style: normal; font-size: .68rem; color: #1b7a3a; background: #e4f4ea; border-radius: 6px; padding: 1px 5px; margin-left: 4px; }
  .opt-price { font-size: .9rem; font-weight: 600; color: var(--text); }
  .opt:has(input:checked) { border-color: var(--gold); background: var(--surface-tint); box-shadow: 0 0 0 2px rgba(184,137,46,.18); }
  .opt:has(input:checked)::after { content: '✓'; position: absolute; top: 5px; right: 8px; color: var(--gold); font-weight: 700; font-size: .8rem; }
  .opt:has(input:disabled) { opacity: .5; cursor: not-allowed; }
  .buy-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 14px 0 12px; }
  .buy-row .price-big { font-size: 1.55rem; font-weight: 700; }
  .buy-qty { display: flex; align-items: center; gap: 8px; font-size: .8rem; font-weight: 600; color: var(--text-dim); } .buy-qty input { width: 64px; padding: 8px 8px; border: 1px solid var(--line); border-radius: 8px; font-size: .95rem; text-align: center; }
  .buy-btns { display: grid; grid-template-columns: 1fr auto; gap: 8px; } .buy-btns .btn-block { padding: 12px 18px; font-size: .95rem; }
  .btn-alt { background: transparent; color: var(--text); border: 1px solid var(--line); border-radius: 8px; padding: 12px 16px; font-weight: 600; font-size: .9rem; cursor: pointer; font-family: var(--sans); white-space: nowrap; } .btn-alt:hover { border-color: var(--gold); color: var(--gold); }
  .buy-note { font-size: .74rem; color: var(--ash); margin: 10px 0 0; text-align: center; }
  .trust-card { margin-top: 18px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px; }
  .tc-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; } .tc-btns a { display: flex; align-items: center; justify-content: center; text-align: center; font-weight: 600; font-size: .9rem; text-decoration: none; border-radius: 12px; padding: 12px; } .tc-b1 { background: #eaf3f1; color: #1f6b62; border: 1px solid #cfe3df; } .tc-b2 { background: #e4f4ea; color: #1b5e33; border: 1px solid #c7e6d1; }
  .tc-guide { display: flex; justify-content: space-between; gap: 10px; margin: 10px 0; background: var(--surface-tint); border-radius: 12px; padding: 12px 14px; font-size: .88rem; color: var(--text-dim); text-decoration: none; } .tc-guide b { color: var(--text); } .tc-guide:hover { color: var(--gold); }
  .tc-list { border: 1px solid var(--line); border-radius: 12px; } .tc-list details { padding: 12px 16px; border-bottom: 1px solid var(--line); } .tc-list details:last-child { border-bottom: 0; }
  .tc-list summary { cursor: pointer; font-weight: 600; font-size: .92rem; list-style: none; display: flex; justify-content: space-between; gap: 10px; } .tc-list summary::-webkit-details-marker { display: none; } .tc-list summary i::after { content: '→'; color: var(--gold); font-style: normal; } .tc-list details[open] summary i::after { content: '↓'; }
  .tc-list p { margin: 10px 0 0; font-size: .88rem; color: var(--text-dim); line-height: 1.55; } .tc-list p a { color: var(--gold); font-weight: 600; text-decoration: none; }
  .tc-icons { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); } .tc-icons span { text-align: center; font-size: .75rem; color: var(--text-dim); line-height: 1.35; }
  @media (max-width: 520px) { .tc-btns { grid-template-columns: 1fr; } .tc-icons { grid-template-columns: repeat(2, 1fr); } }
  .crumbs { font-size: .85rem; color: var(--ash); margin: 0 0 18px; } .crumbs a { color: var(--ash); text-decoration: none; } .crumbs a:hover { color: var(--gold); } .crumbs span { margin: 0 4px; } .crumbs b { color: var(--text); font-weight: 600; }
  .trust-badges { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; } .trust-badges a, .trust-badges span { font-size: .78rem; color: var(--text-dim); text-decoration: none; background: var(--surface-tint); border: 1px solid var(--line); border-radius: 20px; padding: 5px 11px; } .trust-badges a:hover { border-color: var(--gold); color: var(--gold); }
  .sec-nav { display: flex; gap: 22px; flex-wrap: wrap; border-bottom: 1px solid var(--line); margin: 34px 0 0; padding-bottom: 12px; } .sec-nav a { font-weight: 600; font-size: .92rem; color: var(--text-dim); text-decoration: none; } .sec-nav a:hover { color: var(--gold); }
  .pg-sec { margin: 34px 0 0; } .pg-sec h2 { font-family: var(--serif); font-size: 1.35rem; margin: 0 0 16px; }
  .steps3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; } .steps3 > div { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px; } .steps3 b { display: inline-flex; width: 30px; height: 30px; align-items: center; justify-content: center; border-radius: 50%; background: var(--gold); color: #FFFBF3; font-size: .9rem; } .steps3 h3 { margin: 10px 0 6px; font-size: 1rem; } .steps3 p { margin: 0; color: var(--text-dim); font-size: .9rem; line-height: 1.55; }
  .faq details { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; } .faq summary { cursor: pointer; font-weight: 600; font-size: .95rem; list-style: none; display: flex; justify-content: space-between; gap: 12px; } .faq summary::-webkit-details-marker { display: none; } .faq summary::after { content: '+'; color: var(--gold); font-weight: 700; } .faq details[open] summary::after { content: '–'; } .faq p { margin: 10px 0 0; color: var(--text-dim); font-size: .92rem; line-height: 1.6; }
  .rel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; } .rel-card { display: flex; flex-direction: column; gap: 6px; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px; text-decoration: none; color: var(--text); } .rel-card:hover { border-color: var(--gold); } .rel-card img { width: 100%; height: auto; aspect-ratio: 1/1; object-fit: cover; border-radius: 10px; } .rel-card span { font-size: .88rem; line-height: 1.35; } .rel-card b { font-size: .9rem; }
  @media (max-width: 800px) { .steps3 { grid-template-columns: 1fr; } }
  .buybox { margin-bottom: 0; }
  .added-note { background: #e4f4ea; color: #1b5e33; border-radius: 10px; padding: 10px 14px; font-size: .9rem; margin: 0 0 14px; } .added-note a { color: inherit; font-weight: 600; }
  @media (max-width: 800px) { .product-grid { grid-template-columns: 1fr; } .pg-side, .pg-main { grid-column: auto; grid-row: auto; } .pg-side { order: -1; position: static; display: grid; grid-template-columns: 110px 1fr; gap: 14px; align-items: center; } .pg-side .quick-facts { margin: 0; } }
</style>
</head>
<body>
<?php site_banner(); ?>

<header>
  <div class="wrap nav-row">
    <div class="logo"><a href="./"><img src="images/logo.jpg" width="147" height="80" alt="<?= h(SITE_NAME) ?>"></a></div>
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
      <a href="carrito.php" class="cart-link" aria-label="Carrito">🛒 Carrito<?php $cc = cart_count(); if ($cc): ?> <span class="cart-badge"><?= (int) $cc ?></span><?php endif; ?></a>
      <a class="btn-gold" href="portal.php">Consultar pedido</a>
    </div>
    <?php site_mobile_menu(); ?>
  </div>
  <?php site_mobile_panel(); ?>
</header>
<main id="contenido">
<?php }

function site_footer(): void { ?>
</main>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid2">
      <div class="footer-pay">
        <a href="https://www.mercadopago.cl" rel="noopener" target="_blank" aria-label="Mercado Pago"><img class="mp-logo" src="images/mercadopago.png" alt="Mercado Pago" width="210" loading="lazy"></a>
        <p class="footer-desc2">Licencias originales de software para Chile, desde 2018.</p>
      </div>
      <div class="footer-col2">
        <h4>Info</h4>
        <a href="catalogo.php">Búsqueda</a>
        <a href="resenas.php">Opiniones</a>
        <a href="legal.php?p=terminos">Términos del servicio</a>
        <a href="legal.php?p=reembolso">Política de reembolso</a>
        <a href="legal.php?p=envios">Política de envío</a>
        <a href="legal.php?p=privacidad">Política de privacidad</a>
        <a href="contacto.php">Quiénes somos y contacto</a>
        <a href="portal.php">Consultar mi pedido</a>
      </div>
      <div class="footer-col2">
        <h4>Nuestras redes sociales</h4>
        <a class="em" href="https://www.facebook.com/DigitalAlquimiaLatam" rel="noopener" target="_blank">Facebook</a>
        <a class="em" href="https://www.instagram.com/digital.alquimia/" rel="noopener" target="_blank">Instagram</a>
        <a class="em" href="https://api.whatsapp.com/send?phone=<?= h(SUPPORT_WHATSAPP) ?>" rel="noopener" target="_blank">Whatsapp</a>
        <a class="em" href="https://api.whatsapp.com/send?phone=<?= h(SUPPORT_WHATSAPP) ?>" rel="noopener" target="_blank">+<?= h(preg_replace('/^(\d{2})(\d)(\d{3})(\d{4})$/', '$1 $2 $3 $4', (string) SUPPORT_WHATSAPP)) ?></a>
        <a class="plain" href="mailto:<?= h(NOTIFY_EMAIL) ?>"><?= h(NOTIFY_EMAIL) ?></a>
      </div>
    </div>

    <p style="font-size:.78rem;color:var(--ash);margin:18px 0 0;line-height:1.5;">Las marcas y logotipos mencionados pertenecen a sus respectivos propietarios y se muestran solo para indicar la compatibilidad de los productos que vendemos. <?= h(SITE_NAME) ?> es un revendedor independiente y no está afiliado a los fabricantes, salvo que se indique lo contrario.</p>
    <div class="footer-bottom2">
      <span>© <?= date('Y') ?> <?= h(SITE_NAME) ?> CL</span>
      <span>Santiago, Chile</span>
    </div>
  </div>
</footer>
</body>
</html>
<?php }
