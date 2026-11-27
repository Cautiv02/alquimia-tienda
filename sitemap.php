<?php
require_once __DIR__ . '/delivery-engine.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = rtrim(SITE_URL, '/');
$urls = [[$base . '/', '1.0'], [$base . '/catalogo.php', '0.9'], [$base . '/resenas.php', '0.6'], [$base . '/contacto.php', '0.5']];
foreach (['terminos', 'reembolso', 'envios', 'privacidad'] as $p) $urls[] = [$base . '/legal.php?p=' . $p, '0.3'];
foreach (['windows', 'office', 'antivirus', 'adobe', 'autodesk'] as $c) $urls[] = [$base . '/catalogo.php?cat=' . $c, '0.7'];
try {
    foreach (get_pdo()->query("SELECT id FROM products WHERE status = 'activo' ORDER BY id")->fetchAll() as $r) $urls[] = [$base . '/producto.php?id=' . (int) $r['id'], '0.8'];
} catch (Throwable $e) {}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) echo '<url><loc>' . htmlspecialchars($u[0], ENT_XML1) . '</loc><priority>' . $u[1] . '</priority></url>' . "\n";
echo '</urlset>';
