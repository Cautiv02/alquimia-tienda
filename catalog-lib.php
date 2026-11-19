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
