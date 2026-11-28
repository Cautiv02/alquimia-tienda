<?php
/**
 * feed.php (también /feed.xml) — feed de productos para Google Merchant Center.
 * Un artículo por producto, con el precio y la disponibilidad que ve el cliente en la ficha
 * (precio más bajo entre las opciones con stock). Así el feed coincide con la tienda.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/catalog-lib.php';
require_once __DIR__ . '/soldout-lib.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');
$pdo = get_pdo();
$base = rtrim(SITE_URL, '/');
$x = function ($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
// Google no admite texto promocional en los títulos (oferta, descuento, barato…): lo quitamos solo en el feed
function feed_title(string $t): string {
    $t = display_title($t);
    $t = preg_replace('/\b(\d+\s*%\s*)?(descuento|oferta|ofertas|barato|barata|flash|cyber\s?days?|cyberdays|black\s?days?|blackfriday|navidad|exclusiva|promo(ci[oó]n)?|liquidaci[oó]n)\b/iu', '', $t);
    $t = preg_replace('/(\s*[-–—|]\s*){2,}/u', ' - ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t, " \t-–—|");
}
// Descripción neutra: quita frases de garantía/originalidad/políticas y enlaces (deben coincidir con lo que dicen las políticas), y separa frases pegadas
function feed_description(string $html, string $title): string {
    $t = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/li|/h[1-6])[^>]*>~i', ". ", $html)), ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\.(?=[A-ZÁÉÍÓÚÑ])/u', '. ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    $keep = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', $t) as $sn) {
        $sn = trim($sn, " .");
        if ($sn === '' || mb_strlen($sn) < 12) continue;
        if (preg_match('/original|garant|pol[ií]tica|devoluci|reembols|reemplaz|https?:|www\.|@|ver pol|descuento|oferta|barato|lega(l|les)\b|100\s?%/iu', $sn)) continue;
        $keep[] = $sn;
        if (mb_strlen(implode('. ', $keep)) > 500) break;
    }
    $body = implode('. ', $keep);
    $intro = $title . '. Producto digital: recibes la clave de activación por correo electrónico, sin envío físico. Revisa la compatibilidad en la ficha del producto antes de comprar.';
    return mb_substr($intro . ($body !== '' ? ' ' . $body . '.' : ''), 0, 4900);
}
$sold = soldout_ids();
$vstock = variant_stock_map();
$prods = $pdo->query("SELECT id, title, handle, description_html, vendor, product_type, image_url FROM products WHERE status = 'activo' ORDER BY id")->fetchAll();
$vq = $pdo->prepare("SELECT id, title, sku, price, compare_at_price FROM product_variants WHERE product_id = :p ORDER BY price ASC, id ASC");
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "\n";
echo '<title>' . $x(SITE_NAME) . '</title><link>' . $x($base . '/') . '</link><description>Licencias de software para Chile</description>' . "\n";
foreach ($prods as $p) {
    $vq->execute([':p' => $p['id']]); $vars = $vq->fetchAll();
    if (!$vars) continue;
    $pick = null;
    foreach ($vars as $v) { if (!isset($vstock[(int) $v['id']]) || $vstock[(int) $v['id']] > 0) { $pick = $v; break; } }
    $avail = ($pick && empty($sold[(int) $p['id']])) ? 'in_stock' : 'out_of_stock';
    if (!$pick) $pick = $vars[0];
    $price = (int) round((float) $pick['price']);
    if ($price <= 0) continue;
    $desc = feed_description((string) $p['description_html'], feed_title((string) $p['title']) ?: (string) $p['title']);
    $img = (string) $p['image_url'];
    if ($img !== '' && !preg_match('#^https?://#i', $img)) $img = $base . '/' . ltrim($img, '/');
    echo '<item>';
    echo '<g:id>' . (int) $p['id'] . '</g:id>';
    echo '<g:title>' . $x(mb_substr(feed_title((string) $p['title']) ?: (string) $p['title'], 0, 150)) . '</g:title>';
    echo '<g:description>' . $x($desc) . '</g:description>';
    echo '<g:link>' . $x($base . '/producto.php?id=' . (int) $p['id']) . '</g:link>';
    if ($img !== '') echo '<g:image_link>' . $x($img) . '</g:image_link>';
    echo '<g:availability>' . $avail . '</g:availability>';
    echo '<g:price>' . $price . ' CLP</g:price>';
    echo '<g:condition>new</g:condition>';
    echo '<g:brand>' . $x((function ($p) { $b = product_facets((string) $p['title'])['brand']; if ($b === 'Otros' || $b === '') { $v = trim((string) $p['vendor']); $b = (strlen($v) > 2 && strcasecmp($v, 'Alquimia Digital') !== 0) ? $v : SITE_NAME; } return $b; })($p)) . '</g:brand>';
    echo '<g:identifier_exists>no</g:identifier_exists>';
    echo '<g:product_type>' . $x($p['product_type'] ?: 'Software') . '</g:product_type>';
    echo '<g:google_product_category>313</g:google_product_category>';
    echo '</item>' . "\n";
}
echo '</channel></rss>';
