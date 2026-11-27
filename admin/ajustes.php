<?php
/**
 * admin/ajustes.php — aviso superior de la tienda y datos de pago por transferencia.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../settings-lib.php';
require_once __DIR__ . '/../order-expiry-lib.php';
$pdo = get_pdo();
settings_ensure_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $url = trim(mb_substr((string) ($_POST['banner_link_url'] ?? ''), 0, 255));
    if ($url !== '' && !preg_match('#^(https?://|[A-Za-z0-9_./?=&%-]+$)#', $url)) $url = ''; // solo http(s) o rutas del sitio
    $qrErr = ''; $qr = (string) setting('binance_qr');
    if (!empty($_POST['binance_qr_remove'])) $qr = '';
    if (!empty($_FILES['binance_qr_file']['tmp_name']) && is_uploaded_file($_FILES['binance_qr_file']['tmp_name'])) {
        $qf = $_FILES['binance_qr_file']; $qi = @getimagesize($qf['tmp_name']);
        $qe = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$qi['mime'] ?? ''] ?? '';
        if (!$qe || $qf['size'] > 3 * 1024 * 1024) { $qrErr = 'El QR debe ser una imagen JPG, PNG o WEBP de hasta 3 MB. El resto de ajustes sí se guardó.'; }
        else {
            $qn = 'images/binance-qr-' . time() . '.' . $qe;
            if (move_uploaded_file($qf['tmp_name'], __DIR__ . '/../' . $qn)) $qr = $qn;
            else $qrErr = 'No se pudo guardar el QR (revisa permisos de la carpeta images). El resto de ajustes sí se guardó.';
        }
    }
    settings_save($pdo, [
        'binance_qr'        => $qr,
        'pt_warranty'       => trim(mb_substr((string) ($_POST['pt_warranty'] ?? ''), 0, 400)),
        'pt_delivery'       => trim(mb_substr((string) ($_POST['pt_delivery'] ?? ''), 0, 400)),
        'pt_payment'        => trim(mb_substr((string) ($_POST['pt_payment'] ?? ''), 0, 400)),
        'show_old_prices'   => !empty($_POST['show_old_prices']) ? '1' : '0',
        'biz_legal_name'    => trim(mb_substr((string) ($_POST['biz_legal_name'] ?? ''), 0, 120)),
        'biz_rut'           => trim(mb_substr((string) ($_POST['biz_rut'] ?? ''), 0, 20)),
        'biz_address'       => trim(mb_substr((string) ($_POST['biz_address'] ?? ''), 0, 200)),
        'biz_hours'         => trim(mb_substr((string) ($_POST['biz_hours'] ?? ''), 0, 160)),
        'banner_enabled'    => !empty($_POST['banner_enabled']) ? '1' : '0',
        'banner_text'       => trim(mb_substr((string) ($_POST['banner_text'] ?? ''), 0, 300)),
        'banner_link_label' => trim(mb_substr((string) ($_POST['banner_link_label'] ?? ''), 0, 80)),
        'banner_link_url'   => $url,
        'notify_extra'      => trim(mb_substr((string) ($_POST['notify_extra'] ?? ''), 0, 300)),
        'coupon_enabled'    => !empty($_POST['coupon_enabled']) ? '1' : '0',
        'coupon_code'       => strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['coupon_code'] ?? ''))),
        'coupon_percent'    => (string) max(1, min(90, (int) ($_POST['coupon_percent'] ?? 10))),
        'popup_enabled'     => !empty($_POST['popup_enabled']) ? '1' : '0',
        'popup_title'       => trim(mb_substr((string) ($_POST['popup_title'] ?? ''), 0, 120)),
        'popup_text'        => trim(mb_substr((string) ($_POST['popup_text'] ?? ''), 0, 300)),
        'popup_button_label'=> trim(mb_substr((string) ($_POST['popup_button_label'] ?? ''), 0, 40)),
        'popup_button_url'  => (function ($u) { $u = trim(mb_substr((string) $u, 0, 255)); return preg_match('#^(https?://|[A-Za-z0-9_./?=&%-]+$)#', $u) ? $u : 'catalogo.php'; })($_POST['popup_button_url'] ?? ''),
        'popup_delay'       => (string) max(0, min(60, (int) ($_POST['popup_delay'] ?? 4))),
        'popup_days'        => (string) max(0, min(90, (int) ($_POST['popup_days'] ?? 7))),
        'review_reward_enabled' => !empty($_POST['review_reward_enabled']) ? '1' : '0',
        'review_reward_percent' => (string) max(1, min(90, (int) ($_POST['review_reward_percent'] ?? 10))),
        'review_reward_days'    => (string) max(1, min(365, (int) ($_POST['review_reward_days'] ?? 60))),
        'binance_enabled'   => !empty($_POST['binance_enabled']) ? '1' : '0',
        'binance_details'   => trim(mb_substr((string) ($_POST['binance_details'] ?? ''), 0, 800)),
        'binance_rate'      => trim(mb_substr((string) ($_POST['binance_rate'] ?? ''), 0, 20)),
        'binance_hours'     => (string) max(1, min(72, (int) ($_POST['binance_hours'] ?? 4))),
        'binance_note'      => trim(mb_substr((string) ($_POST['binance_note'] ?? ''), 0, 800)),
        'transfer_enabled'  => !empty($_POST['transfer_enabled']) ? '1' : '0',
        'transfer_details'  => trim(mb_substr((string) ($_POST['transfer_details'] ?? ''), 0, 1500)),
        'transfer_note'     => trim(mb_substr((string) ($_POST['transfer_note'] ?? ''), 0, 800)),
    ]);
    if ($qrErr !== '') flash_set($qrErr, true); else flash_set('Ajustes guardados.');
    header('Location: ajustes.php');
    exit;
}
$s = settings_all();
$in = 'padding:9px 12px;border:1px solid var(--line);border-radius:8px;width:100%;font-family:var(--sans);font-size:.92rem;';

admin_header('Ajustes');
flash_show();
?>
<h2 style="font-family:var(--serif);margin:0 0 16px;">Ajustes de la tienda</h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
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
    <strong>Avisos de pedidos nuevos</strong>
    <p class="tag" style="margin:6px 0 12px;">Cada pedido nuevo (pagado, o por transferencia pendiente) te llega por correo a <b><?= h(NOTIFY_EMAIL) ?></b>. Aquí puedes agregar otros correos que también lo reciban, por ejemplo uno que tengas en el móvil. Sepáralos con coma.</p>
    <label class="tag">Correos extra<br><input name="notify_extra" value="<?= h($s['notify_extra']) ?>" style="<?= $in ?>" maxlength="300" placeholder="correo@ejemplo.com, otro@ejemplo.com"></label>
  </div>

  <div class="box">
    <strong>Ventana emergente en la portada</strong>
    <p class="tag" style="margin:6px 0 12px;">Aparece una sola vez al entrar a la portada. En los textos puedes usar <code>{codigo}</code> y <code>{porcentaje}</code>: se reemplazan por los del código de descuento de abajo.</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="popup_enabled" value="1" <?= $s['popup_enabled'] === '1' ? 'checked' : '' ?>> Mostrar la ventana emergente</label>
    <label class="tag">Título<br><input name="popup_title" value="<?= h($s['popup_title']) ?>" style="<?= $in ?>" maxlength="120"></label><br><br>
    <label class="tag">Texto<br><input name="popup_text" value="<?= h($s['popup_text']) ?>" style="<?= $in ?>" maxlength="300"></label><br><br>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <label class="tag">Texto del botón<br><input name="popup_button_label" value="<?= h($s['popup_button_label']) ?>" style="<?= $in ?>width:200px;" maxlength="40"></label>
      <label class="tag">Enlace del botón<br><input name="popup_button_url" value="<?= h($s['popup_button_url']) ?>" style="<?= $in ?>width:220px;"></label>
      <label class="tag">Aparece a los (segundos)<br><input type="number" name="popup_delay" value="<?= h($s['popup_delay']) ?>" min="0" max="60" style="<?= $in ?>width:110px;"></label>
      <label class="tag">No repetir durante (días)<br><input type="number" name="popup_days" value="<?= h($s['popup_days']) ?>" min="0" max="90" style="<?= $in ?>width:110px;"></label>
    </div>
    <?php if ($s['popup_enabled'] === '1' && $s['coupon_enabled'] !== '1' && (strpos($s['popup_title'] . $s['popup_text'], '{codigo}') !== false)): ?>
      <p class="flash err" style="margin:12px 0 0;">El texto usa el código de descuento, pero el código está desactivado más abajo: el cliente vería el texto sin código.</p>
    <?php endif; ?>
  </div>

  <div class="box">
    <strong>Código de descuento para el primer pedido</strong>
    <p class="tag" style="margin:6px 0 12px;">El cliente lo escribe en el carrito. Solo funciona si su correo no tiene compras pagadas anteriores (un pedido abandonado no cuenta). El descuento se aplica al total del pedido.</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="coupon_enabled" value="1" <?= $s['coupon_enabled'] === '1' ? 'checked' : '' ?>> Activar el código</label>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <label class="tag">Código<br><input name="coupon_code" value="<?= h($s['coupon_code']) ?>" maxlength="30" style="<?= $in ?>width:200px;text-transform:uppercase;"></label>
      <label class="tag">Descuento (%)<br><input type="number" name="coupon_percent" value="<?= h($s['coupon_percent']) ?>" min="1" max="90" style="<?= $in ?>width:110px;"></label>
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
  <div class="box">
    <strong>Textos de confianza en las fichas de producto (por defecto, para todos)</strong>
    <p class="tag" style="margin:6px 0 12px;">Se muestran en el cuadro «Garantía de funcionamiento / Cómo y cuándo se entrega / Pago y envío» de todas las fichas. Cada producto puede tener su propio texto en su página de edición; si lo dejas vacío usa estos.</p>
    <label class="tag" style="display:block;margin-bottom:10px;">Garantía de funcionamiento <textarea name="pt_warranty" rows="2" style="<?= $in ?>width:100%;"><?= h($s['pt_warranty']) ?></textarea></label>
    <label class="tag" style="display:block;margin-bottom:10px;">Cómo y cuándo se entrega <textarea name="pt_delivery" rows="2" style="<?= $in ?>width:100%;"><?= h($s['pt_delivery']) ?></textarea></label>
    <label class="tag" style="display:block;">Pago y envío automatizado <textarea name="pt_payment" rows="2" style="<?= $in ?>width:100%;"><?= h($s['pt_payment']) ?></textarea></label>
  </div>
  <div class="box">
    <strong>Precios tachados y porcentajes de descuento</strong>
    <p class="tag" style="margin:6px 0 12px;">Google puede considerar engañoso un precio tachado que no sea un precio de referencia real y comprobable. Apagado, la tienda muestra solo el precio de venta.</p>
    <label style="display:block;"><input type="checkbox" name="show_old_prices" value="1" <?= $s['show_old_prices'] === '1' ? 'checked' : '' ?>> Mostrar precios tachados y % de descuento</label>
  </div>
  <div class="box">
    <strong>Datos de la empresa (se muestran en «Quiénes somos y contacto» y se envían a Google)</strong>
    <p class="tag" style="margin:6px 0 12px;">Google exige identificar con claridad a quién le compra el cliente. Completa el RUT y la razón social tal como figuran en el SII; el RUT solo se publica si lo llenas.</p>
    <label class="tag" style="display:block;margin-bottom:10px;">Razón social / nombre comercial <input name="biz_legal_name" value="<?= h($s['biz_legal_name']) ?>" style="<?= $in ?>width:100%;"></label>
    <label class="tag" style="display:block;margin-bottom:10px;">RUT <input name="biz_rut" value="<?= h($s['biz_rut']) ?>" placeholder="12.345.678-9" style="<?= $in ?>width:200px;"></label>
    <label class="tag" style="display:block;margin-bottom:10px;">Dirección <input name="biz_address" value="<?= h($s['biz_address']) ?>" style="<?= $in ?>width:100%;"></label>
    <label class="tag" style="display:block;">Horario de atención <input name="biz_hours" value="<?= h($s['biz_hours']) ?>" style="<?= $in ?>width:100%;"></label>
  </div>
  <div class="box">
    <strong>Descuento por dejar una reseña</strong>
    <p class="tag" style="margin:6px 0 12px;">Tú eliges a quién pedirle la reseña (menú «Pedir reseñas» o desde el pedido). Cuando el cliente la deje y tú la apruebes, le envías con un clic un código único de descuento, válido solo con su correo y de un solo uso.</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="review_reward_enabled" value="1" <?= $s['review_reward_enabled'] === '1' ? 'checked' : '' ?>> Ofrecer descuento por reseña</label>
    <label class="tag">Porcentaje de descuento <input name="review_reward_percent" value="<?= h($s['review_reward_percent']) ?>" inputmode="numeric" style="<?= $in ?>width:80px;"> %</label>
    <label class="tag" style="margin-left:14px;">Vigencia del código <input name="review_reward_days" value="<?= h($s['review_reward_days']) ?>" inputmode="numeric" style="<?= $in ?>width:80px;"> días</label>
  </div>

  <div class="box">
    <strong>Pago con Binance Pay (manual, en USDT)</strong>
    <p class="tag" style="margin:6px 0 12px;">El cliente ve tu Binance Pay ID y el monto en USDT, paga desde su app y te envía el comprobante. El pedido queda <b>pago pendiente</b> hasta que lo marques como pagado en el panel.</p>
    <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="binance_enabled" value="1" <?= $s['binance_enabled'] === '1' ? 'checked' : '' ?>> Ofrecer pago con Binance Pay</label>
    <label class="tag">Tu Binance Pay ID / datos para recibir (se muestran al cliente y se envían por correo)<br>
      <textarea name="binance_details" rows="4" style="<?= $in ?>font-family:var(--mono);" placeholder="Binance Pay ID: 123456789&#10;Nombre: ...&#10;Red: Binance Pay (sin comisión)"><?= h($s['binance_details']) ?></textarea></label>
    <div class="tag" style="margin-top:12px;">Código QR de tu Binance Pay (opcional; se muestra al cliente y en el correo)<br>
      <?php if (trim($s['binance_qr']) !== ''): ?><img src="../<?= h($s['binance_qr']) ?>" alt="QR actual" style="width:140px;height:140px;object-fit:contain;border:1px solid var(--line);border-radius:8px;background:#fff;margin:6px 0;display:block;">
        <label><input type="checkbox" name="binance_qr_remove" value="1"> Quitar el QR actual</label><br><?php endif; ?>
      <input type="file" name="binance_qr_file" accept="image/jpeg,image/png,image/webp"> <span>JPG, PNG o WEBP, máx. 3 MB. En la app de Binance: Pay → Recibir → guardar imagen del QR.</span></div>
    <label class="tag" style="display:block;margin-top:12px;">Tasa: cuántos pesos chilenos (CLP) vale 1 USDT<br>
      <input name="binance_rate" value="<?= h($s['binance_rate']) ?>" inputmode="decimal" placeholder="ej. 960" style="<?= $in ?>max-width:160px;"></label>
    <label class="tag" style="display:block;margin-top:12px;">Horas para pagar antes de que el pedido se cancele solo <input name="binance_hours" value="<?= h($s['binance_hours']) ?>" inputmode="numeric" style="<?= $in ?>width:70px;"></label>
    <p class="tag" style="margin:6px 0 0;">Se cancela solo cuando alguien abre la tienda o el panel (revisa cada 5 min). Para que sea exacto aunque nadie entre, crea en cPanel → Tareas Cron una tarea cada 10 minutos con este comando:<br>
      <code style="word-break:break-all;">curl -s "<?= h(SITE_URL) ?>/cron-expirar.php?k=<?= h(expiry_cron_key()) ?>" &gt; /dev/null</code><br>Los pedidos cuyo cliente ya envió el comprobante no se cancelan solos.</p>
    <label class="tag" style="display:block;margin-top:12px;">Instrucciones para el cliente<br>
      <textarea name="binance_note" rows="3" style="<?= $in ?>"><?= h($s['binance_note']) ?></textarea></label>
    <?php if ($s['binance_enabled'] === '1' && (trim($s['binance_details']) === '' || binance_rate() <= 0)): ?>
      <p class="flash err" style="margin:12px 0 0;">Binance Pay está activado pero falta tu ID o la tasa: no se mostrará hasta que los completes.</p>
    <?php endif; ?>
  </div>
  <button class="btn-a" type="submit">Guardar ajustes</button>
</form>
<?php admin_footer();
