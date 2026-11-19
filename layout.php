<?php
/**
 * layout.php — cabecera y pie compartidos para las páginas nuevas
 * (producto, catálogo, resultado del pago). Usa site.css, que es el mismo CSS de index.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cart-lib.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n): string { return '$' . number_format((float) $n, 0, ',', '.'); }
}

function site_header(string $title, string $description = ''): void { ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> | <?= h(SITE_NAME) ?></title>
<?php if ($description): ?><meta name="description" content="<?= h($description) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="site.css?v=<?= (int) @filemtime(__DIR__ . '/site.css') ?>">
<style>
  .page { padding: 40px 0 20px; }
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
  .product-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 32px; align-items: start; }
  .product-img { background: var(--surface-tint); border-radius: var(--radius-card); overflow: hidden; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; }
  .product-img img { width: 100%; height: 100%; object-fit: cover; }
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
  @media (max-width: 800px) { .product-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<header>
  <div class="wrap nav-row">
    <div class="logo"><a href="index.php"><img src="LOGo21.png" alt="<?= h(SITE_NAME) ?>"></a></div>
    <nav class="links">
      <a href="catalogo.php?cat=windows">Windows</a>
      <a href="catalogo.php?cat=office">Office</a>
      <a href="catalogo.php?cat=antivirus">Antivirus</a>
      <a href="catalogo.php?cat=adobe">Adobe</a>
      <a href="catalogo.php?cat=autodesk">Autodesk</a>
      <a href="catalogo.php">Catálogo</a>
    </nav>
    <div class="nav-actions">
      <a href="carrito.php" class="cart-link" aria-label="Carrito">🛒 Carrito<?php $cc = cart_count(); if ($cc): ?> <span class="cart-badge"><?= (int) $cc ?></span><?php endif; ?></a>
      <a class="btn-gold" href="portal.php">Consultar pedido</a>
    </div>
  </div>
</header>
<?php }

function site_footer(): void { ?>
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
        <a href="legal.php?p=terminos">Términos del servicio</a>
        <a href="legal.php?p=reembolso">Política de reembolso</a>
        <a href="legal.php?p=envios">Política de envío</a>
        <a href="legal.php?p=privacidad">Política de privacidad</a>
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

    <div class="footer-bottom2">
      <span>© <?= date('Y') ?> <?= h(SITE_NAME) ?> CL</span>
      <span>Santiago, Chile</span>
    </div>
  </div>
</footer>
</body>
</html>
<?php }
