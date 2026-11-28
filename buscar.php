<?php
/** buscar.php?q=texto — sugerencias del buscador del menú (JSON). */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/catalog-lib.php';
require_once __DIR__ . '/soldout-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$norm = function (string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
};
$q = $norm(mb_substr((string) ($_GET['q'] ?? ''), 0, 60));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$words = array_filter(explode(' ', $q));

try {
    $rows = get_pdo()->query("SELECT p.id, p.title, p.image_url, MIN(pv.price) AS price
        FROM products p JOIN product_variants pv ON pv.product_id = p.id
        WHERE p.status = 'activo' AND pv.price > 0 GROUP BY p.id, p.title, p.image_url")->fetchAll();
} catch (Throwable $e) { echo '[]'; exit; }

$out = [];
foreach ($rows as $r) {
    $clean = cat_clean((string) $r['title']);
    $hay = $norm($clean . ' ' . product_facets((string) $r['title'])['brand']);
    $ok = true;
    foreach ($words as $w) if (strpos($hay, $w) === false) { $ok = false; break; }
    if (!$ok) continue;
    $score = (strpos($norm($clean), $q) === 0 ? 0 : 1) * 1000 + mb_strlen($clean);
    $out[] = ['s' => $score, 'id' => (int) $r['id'], 't' => display_title($clean), 'p' => is_soldout((int) $r['id']) ? 'Agotado' : money($r['price']), 'i' => (string) $r['image_url']];
}
usort($out, fn($a, $b) => $a['s'] <=> $b['s']);
echo json_encode(array_map(function ($o) { unset($o['s']); return $o; }, array_slice($out, 0, 7)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
