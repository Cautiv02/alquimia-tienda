<?php
/**
 * admin/ajustes.php — aviso superior de la tienda y datos de pago por transferencia.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../settings-lib.php';
$pdo = get_pdo();
settings_ensure_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $url = trim(mb_substr((string) ($_POST['banner_link_url'] ?? ''), 0, 255));
    if ($url !== '' && !preg_match('#^(https?://|[A-Za-z0-9_./?=&%-]+$)#', $url)) $url = ''; // solo http(s) o rutas del sitio
    settings_save($pdo, [
        'banner_enabled'    => !empty($_POST['banner_enabled']) ? '1' : '0',
        'banner_text'       => trim(mb_substr((string) ($_POST['banner_text'] ?? ''), 0, 300)),
        'banner_link_label' => trim(mb_substr((string) ($_POST['banner_link_label'] ?? ''), 0, 80)),
        'banner_link_url'   => $url,
        'transfer_enabled'  => !empty($_POST['transfer_enabled']) ? '1' : '0',
        'transfer_details'  => trim(mb_substr((string) ($_POST['transfer_details'] ?? ''), 0, 1500)),
        'transfer_note'     => trim(mb_substr((string) ($_POST['transfer_note'] ?? ''), 0, 800)),
    ]);
    flash_set('Ajustes guardados.');
    header('Location: ajustes.php');
    exit;
}
$s = settings_all();
$in = 'padding:9px 12px;border:1px solid var(--line);border-radius:8px;width:100%;font-family:var(--sans);font-size:.92rem;';

admin_header('Ajustes');
flash_show();
?>
<h2 style="font-family:var(--serif);margin:0 0 16px;">Ajustes de la tienda</h2>
<form method="post"><?= csrf_field() ?>
  <div class="box">
    <strong>Aviso superior (franja arriba de todas las páginas)</strong>
    <p class="tag" style="margin:6px 0 12px;">Los clientes pueden cerrarlo; si cambias el texto, vuelve a aparecer para todos.</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="banner_enabled" value="1" <?= $s['banner_enabled'] === '1' ? 'checked' : '' ?>> Mostrar el aviso</label>
    <label class="tag">Texto<br><input name="banner_text" value="<?= h($s['banner_text']) ?>" style="<?= $in ?>" maxlength="300"></label>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
      <label class="tag" style="flex:1;min-width:200px;">Texto del enlace (opcional)<br><input name="banner_link_label" value="<?= h($s['banner_link_label']) ?>" style="<?= $in ?>"></label>
      <label class="tag" style="flex:2;min-width:240px;">Dirección del enlace<br><input name="banner_link_url" value="<?= h($s['banner_link_url']) ?>" style="<?= $in ?>"></label>
    </div>
  </div>

  <div class="box">
    <strong>Pago por transferencia bancaria</strong>
    <p class="tag" style="margin:6px 0 12px;">Si lo activas, el cliente puede elegir "Transferencia" al pagar. El pedido queda <b>pago pendiente</b> hasta que confirmes la transferencia en el panel (botón "Marcar como pagado").</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="transfer_enabled" value="1" <?= $s['transfer_enabled'] === '1' ? 'checked' : '' ?>> Ofrecer pago por transferencia</label>
    <label class="tag">Datos bancarios (se muestran al cliente y se envían por correo)<br>
      <textarea name="transfer_details" rows="7" style="<?= $in ?>font-family:var(--mono);" placeholder="Banco: ...&#10;Tipo de cuenta: ...&#10;N° de cuenta: ...&#10;Nombre: ...&#10;RUT: ...&#10;Correo: ..."><?= h($s['transfer_details']) ?></textarea></label>
    <label class="tag" style="display:block;margin-top:12px;">Instrucciones para el cliente<br>
      <textarea name="transfer_note" rows="3" style="<?= $in ?>"><?= h($s['transfer_note']) ?></textarea></label>
    <?php if ($s['transfer_enabled'] === '1' && trim($s['transfer_details']) === ''): ?>
      <p class="flash err" style="margin:12px 0 0;">La transferencia está activada pero faltan los datos bancarios: no se mostrará hasta que los completes.</p>
    <?php endif; ?>
  </div>
  <button class="btn-a" type="submit">Guardar ajustes</button>
</form>
<?php admin_footer();
