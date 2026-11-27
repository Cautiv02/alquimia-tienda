<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = rtrim(SITE_URL, '/');
echo "User-agent: *\nAllow: /\n";
foreach (['admin/', 'carrito.php', 'portal.php', 'confirmar-pago.php', 'pedido-resultado.php', 'checkout-create-preference.php', 'cron-expirar.php', 'resena.php', 'comprobantes/', 'buscar.php'] as $d) echo "Disallow: /$d\n";
echo "\nSitemap: $base/sitemap.xml\n";
