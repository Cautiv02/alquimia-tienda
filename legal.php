<?php
/**
 * legal.php?p=terminos|reembolso|envios|privacidad — páginas legales.
 * El texto vive en legal/<página>.txt (formato simple: "# Título", "## Subtítulo", párrafos separados por línea en blanco,
 * líneas que empiezan con "-" se muestran con viñeta). Se puede editar el .txt sin tocar código.
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';

$pages = ['terminos' => 'Términos del servicio', 'reembolso' => 'Política de reembolso', 'envios' => 'Política de envío', 'privacidad' => 'Política de privacidad'];
$slug = (string) ($_GET['p'] ?? '');
if (!isset($pages[$slug])) {
    http_response_code(404);
    site_header('Página no encontrada');
    echo '<div class="wrap page"><div class="panel"><h1>Página no encontrada</h1><p><a class="btn-gold" href="./">Ir al inicio</a></p></div></div>';
    site_footer();
    exit;
}

$text = (string) @file_get_contents(__DIR__ . '/legal/' . $slug . '.txt');
$text = str_replace(["\r\n", "\r"], "\n", $text);
$text = preg_replace('/^(#{1,2} .*)\n(?!\n)/m', "$1\n\n", $text);

// Datos de la empresa (Ajustes → Datos de la empresa) dentro de los textos legales: {identificacion}
require_once __DIR__ . '/seo-lib.php';
$_b = biz_info();
$_ident = $_b['name'] . ($_b['rut'] !== '' ? ', RUT ' . $_b['rut'] : '') . ($_b['address'] !== '' ? ', con domicilio en ' . $_b['address'] : '')
        . ', correo ' . $_b['email'] . ' y WhatsApp +' . $_b['wa'];
$text = str_replace('{identificacion}', $_ident, $text);

function legal_inline(string $s): string {
    $s = h($s);
    return preg_replace('~(https?://[^\s<]+)~', '<a href="$1" rel="noopener" target="_blank">$1</a>', $s);
}

$html = ''; $title = $pages[$slug];
foreach (preg_split("/\n{2,}|\n(?=## |# )/", trim($text)) as $block) {
    $block = trim($block);
    if ($block === '') continue;
    if (strpos($block, '## ') === 0) { $html .= '<h2>' . legal_inline(substr($block, 3)) . '</h2>'; continue; }
    if (strpos($block, '# ') === 0) { $title = substr($block, 2); continue; }
    if ($block[0] === '-') { $html .= '<p class="li">• ' . legal_inline(ltrim(substr($block, 1))) . '</p>'; continue; }
    $html .= '<p>' . nl2br(legal_inline($block), false) . '</p>';
}

site_header($title);
?>
<style>
  .legal { max-width: 820px; margin: 0 auto; }
  .legal h2 { font-family: var(--sans); font-size: 1.1rem; font-weight: 600; margin: 30px 0 10px; }
  .legal p { color: var(--text-dim); font-size: .95rem; line-height: 1.7; margin: 0 0 14px; }
  .legal p.li { padding-left: 14px; text-indent: -14px; }
  .legal a { color: var(--gold); word-break: break-word; }
</style>
<div class="wrap page">
  <div class="legal">
    <h1><?= h($title) ?></h1>
    <?= $html ?>
  </div>
</div>
<?php site_footer();
