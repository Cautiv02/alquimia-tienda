<?php
/**
 * catalog-lib.php — deduce marca, categoría, compatibilidad y duración de cada producto a partir de su nombre,
 * para los filtros del catálogo. No requiere cambiar la base de datos.
 */
function cat_clean($t): string {
    return trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $t));
}

function product_facets(string $title): array {
    $t = mb_strtolower(cat_clean($title));
    $has = function (string $re) use ($t): bool { return (bool) preg_match($re . 'u', $t); };

    // Marca
    if     ($has('/autodesk|autocad|civil 3d|revit/'))               $brand = 'Autodesk';
    elseif ($has('/adobe|acrobat|photoshop|illustrator|creative cloud/')) $brand = 'Adobe';
    elseif ($has('/mcafee/'))        $brand = 'McAfee';
    elseif ($has('/kaspersky/'))     $brand = 'Kaspersky';
    elseif ($has('/eset|nod32/'))    $brand = 'ESET';
    elseif ($has('/avast/'))         $brand = 'Avast';
    elseif ($has('/norton/'))        $brand = 'Norton';
    elseif ($has('/panda/'))         $brand = 'Panda';
    elseif ($has('/malwarebytes/'))  $brand = 'Malwarebytes';
    elseif ($has('/avg/'))           $brand = 'AVG';
    elseif ($has('/corel/'))         $brand = 'CorelDRAW';
    elseif ($has('/spotify/'))       $brand = 'Spotify';
    elseif ($has('/canva/'))         $brand = 'Canva';
    elseif ($has('/hbo/'))           $brand = 'HBO Max';
    elseif ($has('/prime video/'))   $brand = 'Prime Video';
    elseif ($has('/duolingo/'))      $brand = 'Duolingo';
    elseif ($has('/chat ?gpt/'))     $brand = 'ChatGPT';
    elseif ($has('/gemini/'))        $brand = 'Gemini';
    elseif ($has('/perplexity/'))    $brand = 'Perplexity';
    elseif ($has('/gift card/'))     $brand = 'Gift Card';
    elseif ($has('/windows|office|visio|project|365|microsoft/')) $brand = 'Microsoft';
    else $brand = 'Otros';

    // Categoría principal
    if     ($has('/^pack|pack /'))                                      $cat = 'Packs';
    elseif ($brand === 'Autodesk')                                      $cat = 'Ingeniería';
    elseif ($has('/^windows/'))                                         $cat = 'Windows';
    elseif (in_array($brand, ['McAfee','Kaspersky','ESET','Avast','Norton','Panda','Malwarebytes','AVG'], true)) $cat = 'Antivirus y seguridad';
    elseif ($has('/creative cloud|photoshop|illustrator|corel|canva/')) $cat = 'Diseño';
    elseif ($has('/office|365|visio|project|acrobat/'))                 $cat = 'Office y productividad';
    elseif (in_array($brand, ['Spotify','HBO Max','Prime Video','Duolingo','ChatGPT','Gemini','Perplexity','Gift Card'], true)) $cat = 'Suscripciones y streaming';
    else $cat = 'Otros';

    // Compatibilidad
    $plat = [];
    if ($has('/\bmac\b/')) $plat[] = 'Mac';
    elseif (in_array($cat, ['Windows','Office y productividad','Diseño','Ingeniería','Packs'], true) && $brand !== 'Canva') $plat[] = 'Windows';

    // Duración
    $dur = '';
    if     ($has('/permanente|vitalicia|lifetime/')) $dur = 'Permanente';
    elseif ($has('/(\d+)\s*a[ñn]os?/') && preg_match('/(\d+)\s*a[ñn]os?/u', $t, $m) && (int) $m[1] >= 2) $dur = 'Más de 1 año';
    elseif ($has('/a[ñn]o|12 meses|anual/'))          $dur = '1 año';
    elseif ($has('/(\d+)\s*mes/'))                    $dur = 'Menos de 1 año';

    return ['brand' => $brand, 'category' => $cat, 'platforms' => $plat, 'duration' => $dur];
}

/**
 * Quita de la descripción (heredada de Shopify) las frases de "originales/garantía/políticas" que podrían
 * contradecir los Términos y la Política de reembolso. Solo afecta lo que se muestra; la base de datos no se toca.
 */
function desc_clean(string $html): string {
    $h = $html;
    $h = preg_replace('/Todos nuestros productos (son originales y )?(cuentan con|tienen) garant[ií]a[^.<]*\.?/iu', '', $h);
    $h = preg_replace('/sin embargo tenemos una pol[ií]tica de devoluci[^.<]*\.?/iu', '', $h);
    $h = preg_replace('/Ver Pol[ií]ticas/iu', '', $h);
    $h = preg_replace('/\.(?=[A-ZÁÉÍÓÚÑ])/u', '. ', $h);
    $h = preg_replace('/<p>\s*(<br\s*\/?>)?\s*<\/p>/i', '', $h);
    return $h;
}

/** Tokens significativos de un handle de Shopify (sin palabras de oferta ni números sueltos). */
function handle_tokens(string $h): array {
    $stop = ['oferta','ofertas','descuento','blackdays','blackday','flash','cyber','cyberdays','copia','original','barato','navidad','exclusivo','exclusiva','para','de','en','con','y','el','la'];
    $h = preg_replace('/descuento[-_ ]?\d+/', '', strtolower($h)); // «descuento-60» no identifica al producto, pero «windows-11» sí
    $t = array_filter(preg_split('/[^a-z0-9]+/', $h), function ($w) use ($stop) { return $w !== '' && !in_array($w, $stop, true); });
    return array_values(array_unique($t));
}
/** Devuelve el id del producto activo cuyo handle se parece lo bastante al antiguo (>= 70 % de palabras en común), o 0. */
function product_handle_guess(PDO $pdo, string $handle): int {
    $a = handle_tokens($handle); if (count($a) < 2) return 0;
    $best = 0; $bestScore = 0.0;
    foreach ($pdo->query("SELECT id, handle FROM products WHERE status = 'activo' AND handle IS NOT NULL AND handle <> ''")->fetchAll() as $r) {
        $b = handle_tokens((string) $r['handle']); if (!$b) continue;
        $inter = count(array_intersect($a, $b)); $union = count(array_unique(array_merge($a, $b)));
        $score = $union ? $inter / $union : 0;
        if ($score > $bestScore) { $bestScore = $score; $best = (int) $r['id']; }
    }
    return $bestScore >= 0.7 ? $best : 0;
}

/**
 * Título para mostrar al público: sin la palabra «Original» (autenticidad que no podemos demostrar como revendedor independiente).
 * Solo cambia lo que se muestra; la base de datos y las búsquedas internas no se tocan.
 */
function display_title($t): string {
    $t = cat_clean($t);
    $t = preg_replace('/\b(cuenta|suscripci[oó]n|licencia|licencias)\s+original(es)?\b/iu', '$1', $t);
    $t = preg_replace('/\boriginal(es)?\b/iu', '', $t);
    $t = preg_replace('/(\s*[-–—|]\s*){2,}/u', ' - ', $t);
    $t = preg_replace('/\s*\/\s*(?=[-–—|]|$)/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t, " \t-–—|");
}
