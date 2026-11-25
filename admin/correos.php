<?php
/**
 * admin/correos.php — editar los textos de los correos que recibe el cliente (asunto y mensaje) y enviarte una prueba.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../settings-lib.php';
$pdo = get_pdo();
settings_ensure_table($pdo);
$defs = mail_template_defs();

$varHelp = [
    'nombre' => 'nombre del cliente', 'pedido' => 'número de pedido', 'total' => 'monto del pedido', 'productos' => 'lista de productos',
    'codigo' => 'código único del pedido', 'enlace_pedido' => 'enlace para ver el pedido', 'correo_contacto' => 'tu correo de contacto',
    'whatsapp' => 'tu WhatsApp', 'tienda' => 'nombre de la tienda', 'datos_transferencia' => 'datos bancarios + monto + referencia',
    'nota_transferencia' => 'nota de la transferencia (Ajustes)', 'boton_resena' => 'botón para dejar una reseña', 'producto' => 'nombre del producto (o productos) del pedido', 'porcentaje' => 'porcentaje de descuento', 'dias_vigencia' => 'días de vigencia del código', 'codigo_descuento' => 'código de descuento único', 'vence' => 'fecha de vencimiento del código', 'boton_confirmar' => 'botón «Confirmar mi pago» (subir comprobante)', 'monto_usdt' => 'monto en USDT', 'datos_binance' => 'tu ID de Binance Pay + monto + referencia', 'nota_binance' => 'instrucciones de Binance (Ajustes)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $save = [];
    foreach ($defs as $k => $d) {
        if (!empty($_POST["reset_$k"])) { $save["mail_{$k}_subject"] = $d['subject']; $save["mail_{$k}_body"] = $d['body']; continue; }
        $s = trim(mb_substr((string) ($_POST["subject_$k"] ?? ''), 0, 200));
        $b = trim(mb_substr(str_replace("\r", '', (string) ($_POST["body_$k"] ?? '')), 0, 6000));
        $save["mail_{$k}_subject"] = $s !== '' ? $s : $d['subject'];
        $save["mail_{$k}_body"]    = $b !== '' ? $b : $d['body'];
    }
    settings_save($pdo, $save);
    $msg = 'Textos guardados.'; $err = false;
    $test = (string) ($_POST['test'] ?? '');
    if ($test !== '' && isset($defs[$test])) {
        $sample = [
            'nombre' => 'María', 'pedido' => 'AD-20/11-0000', 'total' => '$14.990', 'codigo' => 'ABC123XYZ',
            'productos' => '<ul><li>1 × Producto de ejemplo</li></ul>', 'enlace_pedido' => '<a href="' . h(SITE_URL) . '">Ver mi pedido</a>',
            'correo_contacto' => NOTIFY_EMAIL, 'whatsapp' => defined('SUPPORT_WHATSAPP') ? SUPPORT_WHATSAPP : '', 'tienda' => SITE_NAME,
            'datos_transferencia' => '<div style="background:#f6f2e6;padding:12px 14px;border-radius:8px;">Banco de ejemplo · Cuenta 000000<br><br><b>Monto: $14.990</b><br><b>Referencia: AD-20/11-0000</b></div>',
            'boton_confirmar' => '<p><a href="' . h(SITE_URL) . '" style="background:#96691A;color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;">Confirmar mi pago</a></p>',
            'porcentaje' => '10', 'dias_vigencia' => '60', 'codigo_descuento' => 'GRACIAS-AB12CD', 'vence' => date('d/m/Y', strtotime('+60 days')),
            'producto' => 'Office 2021 LTSC 1PC',
            'nota_transferencia' => 'Envíanos el comprobante por WhatsApp.', 'monto_usdt' => '15.60',
            'datos_binance' => '<div style="background:#f6f2e6;padding:12px 14px;border-radius:8px;">Binance Pay ID: 123456789<br><br><b>Monto: 15.60 USDT</b></div>', 'nota_binance' => 'Envíanos el comprobante por WhatsApp.',
            'boton_resena' => '<p><a href="' . h(SITE_URL) . '" style="background:#b8892e;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;">Dejar mi reseña</a></p>',
        ];
        [$subj, $html] = (function () use ($test, $sample, $save, $defs) {
            $subj = $save["mail_{$test}_subject"]; $body = $save["mail_{$test}_body"];
            return mail_render_text($subj, $body, $sample);
        })();
        $ok = smtp_send(NOTIFY_EMAIL, '[PRUEBA] ' . $subj, $html);
        $msg .= $ok ? ' Te enviamos una prueba a ' . NOTIFY_EMAIL . '.' : ' No se pudo enviar la prueba (revisa Diagnóstico de correo).';
        $err = !$ok;
    }
    flash_set($msg, $err);
    header('Location: correos.php' . ($test !== '' ? '#t-' . urlencode($test) : '')); exit;
}

$in = 'padding:9px 12px;border:1px solid var(--line);border-radius:8px;width:100%;font-family:var(--sans);font-size:.92rem;';
admin_header('Correos al cliente');
flash_show();
?>
<p class="tag">Aquí editas lo que dice cada correo que recibe el cliente. Una línea en blanco separa párrafos. Las palabras entre llaves, como <code>{nombre}</code>, se reemplazan solas por los datos del pedido. Los correos que te llegan a ti (avisos internos) no cambian.</p>
<form method="post"><?= csrf_field() ?>
<?php foreach ($defs as $k => $d): ?>
  <div class="box" id="t-<?= h($k) ?>">
    <strong><?= h($d['label']) ?></strong>
    <label class="tag" style="display:block;margin-top:10px;">Asunto<br><input name="subject_<?= h($k) ?>" value="<?= h(setting("mail_{$k}_subject")) ?>" style="<?= $in ?>"></label>
    <label class="tag" style="display:block;margin-top:10px;">Mensaje<br><textarea name="body_<?= h($k) ?>" rows="10" style="<?= $in ?>"><?= h(setting("mail_{$k}_body")) ?></textarea></label>
    <p class="tag" style="margin:8px 0;">Puedes usar: <?php foreach ($d['vars'] as $v): ?><code title="<?= h($varHelp[$v] ?? '') ?>">{<?= h($v) ?>}</code> <?php endforeach; ?></p>
    <label class="tag"><input type="checkbox" name="reset_<?= h($k) ?>" value="1"> Volver al texto original al guardar</label>
    <p style="margin:10px 0 0;"><button class="btn-a ghost" type="submit" name="test" value="<?= h($k) ?>">Guardar y enviarme una prueba</button></p>
  </div>
<?php endforeach; ?>
<p><button class="btn-a" type="submit">Guardar todos los textos</button></p>
</form>
<details class="box"><summary><b>¿Qué significa cada variable?</b></summary>
  <ul class="tag"><?php foreach ($varHelp as $v => $t): ?><li><code>{<?= h($v) ?>}</code> — <?= h($t) ?></li><?php endforeach; ?></ul>
</details>
<?php admin_footer();
