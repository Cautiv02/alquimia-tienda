<?php
/**
 * admin/pedido.php?id=N — detalle del pedido y acciones:
 *  - Marcar como entregado (ya enviaste las claves por tu cuenta)
 *  - Consultar el pago en Mercado Pago (y procesarlo si está aprobado y aún no estaba registrado)
 *  - Marcar como pagado a mano (p. ej. transferencia confirmada)
 *  - Entregar con claves del stock (solo si cargaste claves en el sistema)
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();
$id = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT o.*, c.first_name, c.last_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = :id");
$st->execute([':id' => $id]);
$o = $st->fetch();
if (!$o) { http_response_code(404); admin_header('Pedido no encontrado'); echo '<p>Pedido no encontrado.</p>'; admin_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string) ($_POST['action'] ?? '');
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN refund_pending TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

    if ($a === 'entregado') {
        $pdo->prepare("UPDATE orders SET fulfillment_status = 'entregado', updated_at = NOW() WHERE id = :id AND financial_status = 'pagado'")->execute([':id' => $id]);
        $m = !empty($_POST['silent']) ? false : notify_shipped($pdo, $id);
        flash_set('Pedido marcado como entregado' . (!empty($_POST['silent']) ? ' (sin email al cliente).' : ($m ? ' y se envió el email de confirmación al cliente.' : '. No se pudo enviar el email de confirmación.')), false);
    } elseif ($a === 'solicitar_resena') {
        require_once __DIR__ . '/../review-requests-lib.php';
        flash_set(review_request_send($pdo, $id) ? 'Se envió la solicitud de reseña al cliente.' : 'No se pudo enviar la solicitud (revisa el correo del cliente o el envío de correos).', false);
    } elseif ($a === 'editar_correo') {
        $new = strtolower(trim((string) ($_POST['nuevo_correo'] ?? '')));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 190) {
            flash_set('Ese correo no es válido. Revísalo e inténtalo de nuevo.', true);
        } elseif ($new === strtolower((string) $o['email'])) {
            flash_set('El correo es el mismo que ya tenía el pedido.', true);
        } else {
            // El pedido pasa a un cliente con el correo nuevo (se busca o se crea); el cliente anterior no se modifica porque puede tener otros pedidos.
            $cs = $pdo->prepare("SELECT id FROM customers WHERE email = :e LIMIT 1"); $cs->execute([':e' => $new]);
            $cid = $cs->fetchColumn();
            if (!$cid) {
                $fn = trim((string) $o['first_name']);
                $pdo->prepare("INSERT INTO customers (email, first_name, phone, created_at) VALUES (:e, :n, :p, NOW())")
                    ->execute([':e' => $new, ':n' => $fn !== '' ? $fn : null, ':p' => $o['phone'] ?: null]);
                $cid = $pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE orders SET email = :e, customer_id = :c, updated_at = NOW() WHERE id = :id")->execute([':e' => $new, ':c' => $cid, ':id' => $id]);
            $msg = 'Correo actualizado de ' . $o['email'] . ' a ' . $new . '.';
            if (!empty($_POST['reenviar']) && $o['fulfillment_status'] === 'entregado') {
                $msg .= notify_shipped($pdo, $id) ? ' Se reenvió el aviso de envío al correo nuevo.' : ' No se pudo reenviar el aviso de envío.';
            }
            flash_set($msg);
        }
    } elseif ($a === 'renumerar') {
        $new = trim((string) ($_POST['nuevo_numero'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9/\\-]{3,40}$#', $new)) {
            flash_set('Número no válido. Usa letras, números, guion y barra (ej. AD-19/11-8041).', true);
        } else {
            $ex = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = :n AND id <> :id");
            $ex->execute([':n' => $new, ':id' => $id]);
            if ((int) $ex->fetchColumn() > 0) {
                flash_set('Ya existe otro pedido con ese número.', true);
            } else {
                $pdo->prepare("UPDATE orders SET order_number = :n, updated_at = NOW() WHERE id = :id")->execute([':n' => $new, ':id' => $id]);
                // si el nuevo correlativo es mayor que el contador, lo subimos para no repetir números
                if (preg_match('#-(\\d+)$#', $new, $m)) {
                    try { $pdo->prepare("UPDATE order_seq SET n = GREATEST(n, :v) WHERE id = 1")->execute([':v' => (int) $m[1]]); } catch (Throwable $e) { /* tabla aún no creada: no pasa nada */ }
                }
                flash_set('Número de pedido actualizado a ' . $new . '.');
            }
        }
    } elseif ($a === 'cancelar_pedido' || $a === 'reembolsar' || $a === 'reembolso_manual') {
        $wasPaid = $o['financial_status'] === 'pagado';
        $refund = (string) ($_POST['refund'] ?? 'none');
        $pr = $pdo->prepare("SELECT gateway_ref FROM payments WHERE order_id = :id AND gateway = 'mercadopago' AND status = 'aprobado' ORDER BY id DESC LIMIT 1");
        $pr->execute([':id' => $id]); $mpPay = $pr->fetchColumn();
        $err = false; $msg = '';
        $restock = function () use ($pdo, $id) {
            try {
                $it = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = :id"); $it->execute([':id' => $id]);
                $up = $pdo->prepare("UPDATE product_variants SET stock_manual = stock_manual + :q WHERE id = :v AND stock_manual IS NOT NULL");
                foreach ($it->fetchAll() as $r) if ($r['variant_id']) $up->execute([':q' => (int) $r['quantity'], ':v' => (int) $r['variant_id']]);
            } catch (Throwable $e) {}
        };
        $markRefunded = function () use ($pdo, $id) {
            try { $pdo->prepare("UPDATE orders SET financial_status = 'reembolsado', refund_pending = 0, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]); }
            catch (Throwable $e) { $pdo->prepare("UPDATE orders SET financial_status = 'anulado', refund_pending = 0, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]); } // el dinero ya se devolvió; si la base no admite 'reembolsado' queda como cancelado
            try { $pdo->prepare("UPDATE payments SET status = 'reembolsado' WHERE order_id = :id AND gateway = 'mercadopago' AND status = 'aprobado'")->execute([':id' => $id]); } catch (Throwable $e) {}
        };
        if ($a === 'cancelar_pedido') {
            if (in_array($o['financial_status'], ['anulado', 'reembolsado'], true)) { $err = true; $msg = 'Este pedido ya estaba cancelado.'; }
            elseif ($wasPaid && $refund === 'now') {
                if (!$mpPay) { $err = true; $msg = 'No hay un pago de Mercado Pago registrado en este pedido, así que no puedo reembolsarlo desde aquí. Elige "Reembolsar después" y devuelve el dinero por tu cuenta; luego márcalo como reembolsado.'; }
                else {
                    [$ok, $m] = mp_refund_payment((string) $mpPay);
                    if ($ok) { $markRefunded(); $restock(); $msg = 'Pedido cancelado y reembolsado. ' . $m; }
                    else { $err = true; $msg = $m . ' El pedido NO se canceló.'; }
                }
            } else {
                $pdo->prepare("UPDATE orders SET financial_status = 'anulado', refund_pending = :rp, updated_at = NOW() WHERE id = :id")->execute([':rp' => ($wasPaid && $refund === 'later') ? 1 : 0, ':id' => $id]);
                $restock();
                $msg = 'Pedido cancelado.' . (($wasPaid && $refund === 'later') ? ' Quedó con reembolso pendiente: puedes hacerlo cuando quieras desde esta misma página.' : '');
            }
        } elseif ($a === 'reembolsar') {
            if (empty($o['refund_pending'])) { $err = true; $msg = 'Este pedido no tiene un reembolso pendiente.'; }
            elseif (!$mpPay) { $err = true; $msg = 'No hay un pago de Mercado Pago registrado en este pedido.'; }
            else {
                [$ok, $m] = mp_refund_payment((string) $mpPay);
                if ($ok) { $markRefunded(); $msg = $m; } else { $err = true; $msg = $m; }
            }
        } else { // reembolso_manual
            if (empty($o['refund_pending'])) { $err = true; $msg = 'Este pedido no tiene un reembolso pendiente.'; }
            else { $markRefunded(); $msg = 'Pedido marcado como reembolsado (devolución hecha por tu cuenta).'; }
        }
        if (!$err && !empty($_POST['avisar'])) {
            $mode = ($a === 'cancelar_pedido') ? (($wasPaid && $refund === 'now') ? 'refunded' : (($wasPaid && $refund === 'later') ? 'pending' : 'none')) : 'refunded';
            $msg .= notify_cancelled($pdo, $id, $mode) ? ' Se avisó al cliente por correo.' : ' No se pudo enviar el correo al cliente.';
        }
        flash_set($msg, $err);
    } elseif ($a === 'cancelar') {
        $u = $pdo->prepare("UPDATE orders SET financial_status = 'anulado', updated_at = NOW() WHERE id = :id AND fulfillment_status <> 'entregado' AND financial_status <> 'anulado'");
        $u->execute([':id' => $id]);
        flash_set($u->rowCount() ? 'Pedido cancelado.' : 'Este pedido no se puede cancelar (ya está enviado o cancelado).', !$u->rowCount());
    } elseif ($a === 'deshacer_envio') {
        $pdo->prepare("UPDATE orders SET fulfillment_status = 'pendiente', updated_at = NOW() WHERE id = :id AND fulfillment_status = 'entregado'")->execute([':id' => $id]);
        flash_set('El pedido volvió a "por enviar".');
    } elseif ($a === 'reactivar') {
        $pdo->prepare("UPDATE orders SET financial_status = 'pendiente', updated_at = NOW() WHERE id = :id AND financial_status = 'anulado'")->execute([':id' => $id]);
        flash_set('El pedido se reactivó como "pago pendiente".');
    } elseif ($a === 'consultar_mp') {
        $r = mp_get('/v1/payments/search?external_reference=' . rawurlencode($o['order_number']) . '&sort=date_created&criteria=desc');
        $pid = $r['data']['results'][0]['id'] ?? null;
        if ($r['code'] !== 200) flash_set('No pude consultar Mercado Pago (HTTP ' . $r['code'] . '). Revisa el Access Token en config.php.', true);
        elseif (!$pid) flash_set('Mercado Pago no tiene pagos registrados para este pedido.', true);
        else {
            try { flash_set('Resultado: ' . process_mp_payment($pdo, (string) $pid) . ' (pago MP #' . $pid . ')'); }
            catch (Throwable $e) { flash_set('Error: ' . $e->getMessage(), true); }
        }
    } elseif ($a === 'pagado_manual') {
        $u = $pdo->prepare("UPDATE orders SET financial_status = 'pagado', updated_at = NOW() WHERE id = :id AND financial_status IN ('pendiente','anulado')");
        $u->execute([':id' => $id]);
        if ($u->rowCount()) {
            if (AUTO_DELIVERY) { try { deliver_order($pdo, $id); } catch (Throwable $e) { error_log($e->getMessage()); } }
            else { try { notify_paid_order($pdo, $id); } catch (Throwable $e) { error_log($e->getMessage()); } }
            flash_set('Pedido marcado como pagado y avisos enviados.');
        }
    } elseif ($a === 'entregar_stock') {
        try { deliver_order($pdo, $id); flash_set('Se asignaron las claves del stock y se envió el correo al cliente.'); }
        catch (Throwable $e) { flash_set('No se pudo entregar: ' . $e->getMessage(), true); }
    }
    header('Location: pedido.php?id=' . $id);
    exit;
}

$items = $pdo->prepare("SELECT id, title, quantity, unit_price FROM order_items WHERE order_id = :id ORDER BY id");
$items->execute([':id' => $id]);
$items = $items->fetchAll();

$pays = $pdo->prepare("SELECT gateway_ref, status, amount, created_at FROM payments WHERE order_id = :id ORDER BY id");
$pays->execute([':id' => $id]);
$pays = $pays->fetchAll();

$name = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
$wa = preg_replace('/\D+/', '', (string) $o['phone']);

admin_header('Pedido ' . $o['order_number']);
flash_show();
?>
<p><a href="index.php">← Pedidos</a></p>
<div class="box">
  <h2 style="margin:0 0 6px;font-family:var(--serif);"><?= h($o['order_number']) ?>
    <span class="pill <?= h($o['financial_status']) ?>"><?= h($o['financial_status']) ?></span>
    <span class="pill <?= h($o['fulfillment_status']) ?>"><?= h($o['fulfillment_status']) ?></span></h2>
  <p class="tag" style="margin:0 0 14px;"><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?> · código único <code><?= h($o['unique_code']) ?></code> · <?= h($o['payment_method']) ?></p>
  <p style="margin:0;"><b>Cliente:</b> <?php if (!empty($o['customer_id'])): ?><a href="clientes.php?id=<?= (int) $o['customer_id'] ?>"><?= h($name ?: '(sin nombre)') ?></a> <span class="tag">(ver todos sus pedidos)</span><?php else: ?><?= h($name ?: '(sin nombre)') ?><?php endif; ?><br>
     <b>Correo:</b> <a href="mailto:<?= h($o['email']) ?>"><?= h($o['email']) ?></a>
     <button type="button" class="btn-a ghost" style="padding:2px 10px;font-size:.78rem;margin-left:6px;" onclick="document.getElementById('edit-mail').style.display='block';this.style.display='none';document.getElementById('nuevo_correo').focus();">✎ Editar correo</button><br>
     <b>Teléfono:</b> <?= h($o['phone'] ?: '-') ?>
     <?php if (strlen($wa) >= 8): ?> · <a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener">abrir WhatsApp</a><?php endif; ?></p>
  <form method="post" id="edit-mail" style="display:<?= !empty($_GET['editar']) ? 'block' : 'none' ?>;margin-top:14px;padding-top:14px;border-top:1px solid var(--line);">
    <?= csrf_field() ?><input type="hidden" name="action" value="editar_correo">
    <label class="tag">Correo correcto del cliente<br>
      <input type="email" id="nuevo_correo" name="nuevo_correo" required maxlength="190" value="<?= h($o['email']) ?>" style="padding:9px 12px;border:1px solid var(--line);border-radius:8px;min-width:300px;"></label>
    <?php if ($o['fulfillment_status'] === 'entregado'): ?>
      <label class="tag" style="display:block;margin:10px 0;"><input type="checkbox" name="reenviar" value="1" checked> Reenviar el aviso de envío al correo nuevo</label>
    <?php endif; ?>
    <button class="btn-a" type="submit" style="margin-top:8px;">Guardar correo</button>
    <p class="tag" style="margin:8px 0 0;">Solo cambia el correo de este pedido. Si el cliente tiene otros pedidos, esos no se modifican.</p>
  </form>
</div>

<div class="box">
  <strong>Productos</strong>
  <table class="t" style="margin-top:10px;">
    <tr><th>Producto</th><th>Cant.</th><th>Precio</th></tr>
    <?php foreach ($items as $i): ?>
      <tr><td><?= h($i['title']) ?></td><td><?= (int) $i['quantity'] ?></td><td><?= money($i['unit_price']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!empty($o['discount_code'])): ?><tr><td colspan="2" style="text-align:right;">Descuento (<?= h($o['discount_code']) ?>)</td><td>−<?= money($o['discount_amount'] ?? 0) ?></td></tr><?php endif; ?>
    <tr><td colspan="2" style="text-align:right;"><b>Total</b></td><td><b><?= money($o['total_amount']) ?></b></td></tr>
  </table>
</div>

<div class="box">
  <strong>Acciones</strong>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;"><?= csrf_field() ?>
    <?php if ($o['financial_status'] === 'pagado' && $o['fulfillment_status'] !== 'entregado'): ?>
      <label class="tag" style="display:block;margin-bottom:8px;"><input type="checkbox" name="silent" value="1"> No avisar al cliente por email</label>
      <button class="btn-a" name="action" value="entregado" onclick="return confirm('¿Ya enviaste las claves al cliente?')">✔ Marcar como entregado (ya envié las claves)</button>
      <button class="btn-a ghost" name="action" value="entregar_stock" onclick="return confirm('Se asignarán claves del stock cargado y se enviará el correo con el enlace al cliente. ¿Continuar?')">Entregar con claves del stock</button>
    <?php endif; ?>
    <?php if ($o['fulfillment_status'] === 'entregado' && $o['financial_status'] === 'pagado'): ?>
      <button class="btn-a ghost" name="action" value="solicitar_resena" onclick="return confirm('¿Enviar al cliente la solicitud de reseña?')">⭐ Pedir reseña<?= !empty($o['review_requested_at']) ? ' (ya enviada el ' . h(date('d/m', strtotime($o['review_requested_at']))) . ')' : '' ?></button>
    <?php endif; ?>
    <?php if ($o['fulfillment_status'] === 'entregado'): ?>
      <button class="btn-a ghost" name="action" value="deshacer_envio" onclick="return confirm('¿Volver a marcar este pedido como por enviar?')">↩ Deshacer envío</button>
    <?php endif; ?>
    <?php if ($o['financial_status'] === 'anulado'): ?>
      <button class="btn-a ghost" name="action" value="reactivar">↩ Reactivar pedido</button>
    <?php endif; ?>
    <?php if ($o['financial_status'] !== 'pagado'): ?>
      <button class="btn-a ghost" name="action" value="consultar_mp">Consultar pago en Mercado Pago</button>
      <button class="btn-a ghost" name="action" value="pagado_manual" onclick="return confirm('¿Confirmas que recibiste el pago de este pedido?')">Marcar como pagado (manual)</button>
    <?php endif; ?>
  </form>
</div>

<?php
  $isPaid = $o['financial_status'] === 'pagado';
  $pr2 = $pdo->prepare("SELECT gateway_ref FROM payments WHERE order_id = :id AND gateway = 'mercadopago' AND status = 'aprobado' ORDER BY id DESC LIMIT 1");
  $pr2->execute([':id' => $id]); $hasMp = (bool) $pr2->fetchColumn();
  $canCancel = !in_array($o['financial_status'], ['anulado', 'reembolsado'], true);
?>
<?php if ($canCancel): ?>
<div class="box">
  <strong>Cancelar pedido</strong>
  <form method="post" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="cancelar_pedido">
    <?php if ($isPaid): ?>
      <p class="tag" style="margin:0 0 8px;">Este pedido está pagado (<?= money($o['total_amount']) ?>). ¿Qué hacemos con el dinero?</p>
      <label style="display:block;margin:6px 0;"><input type="radio" name="refund" value="now" <?= $hasMp ? 'checked' : 'disabled' ?>> Reembolsar ahora por Mercado Pago <?= $hasMp ? '' : '<span class="tag">(no hay pago de MP registrado)</span>' ?></label>
      <label style="display:block;margin:6px 0;"><input type="radio" name="refund" value="later" <?= $hasMp ? '' : 'checked' ?>> Cancelar y reembolsar después (queda pendiente)</label>
      <label style="display:block;margin:6px 0;"><input type="radio" name="refund" value="none"> Solo cancelar, sin reembolso</label>
    <?php else: ?>
      <p class="tag" style="margin:0 0 8px;">Este pedido aún no está pagado, no hay dinero que devolver.</p>
      <input type="hidden" name="refund" value="none">
    <?php endif; ?>
    <label class="tag" style="display:block;margin-top:10px;"><input type="checkbox" name="avisar" value="1" checked> Avisar al cliente por correo</label>
    <button class="btn-a ghost" type="submit" style="margin-top:8px;" onclick="return confirm('¿Cancelar el pedido <?= h($o['order_number']) ?>? Si elegiste reembolsar ahora, el dinero se devuelve al cliente de inmediato y no se puede deshacer.')">✕ Cancelar pedido</button>
    <?php if ($o['fulfillment_status'] === 'entregado'): ?><p class="tag" style="margin:8px 0 0;">Ojo: este pedido ya figura como entregado.</p><?php endif; ?>
  </form>
</div>
<?php elseif (!empty($o['refund_pending'])): ?>
<div class="box">
  <strong>Reembolso pendiente · <?= money($o['total_amount']) ?></strong>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;"><?= csrf_field() ?>
    <label class="tag" style="display:block;width:100%;"><input type="checkbox" name="avisar" value="1" checked> Avisar al cliente por correo</label>
    <?php if ($hasMp): ?><button class="btn-a" name="action" value="reembolsar" onclick="return confirm('Se devolverán <?= h(money($o['total_amount'])) ?> al cliente por Mercado Pago. No se puede deshacer. ¿Continuar?')">Reembolsar ahora por Mercado Pago</button><?php endif; ?>
    <button class="btn-a ghost" name="action" value="reembolso_manual" onclick="return confirm('¿Confirmas que ya devolviste el dinero por tu cuenta (transferencia, etc.)?')">Ya lo devolví yo (marcar reembolsado)</button>
  </form>
</div>
<?php endif; ?>

<div class="box">
  <strong>Número de pedido</strong>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:center;"><?= csrf_field() ?>
    <input type="hidden" name="action" value="renumerar">
    <input name="nuevo_numero" value="<?= h($o['order_number']) ?>" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;min-width:240px;font-family:var(--mono);">
    <button class="btn-a ghost" type="submit" onclick="return confirm('¿Cambiar el número de este pedido? Úsalo solo en pedidos de prueba: el número original sigue siendo la referencia del pago en Mercado Pago.')">Cambiar número</button>
  </form>
</div>

<?php
  try { $pf = $pdo->prepare("SELECT * FROM payment_proofs WHERE order_id = :id ORDER BY id DESC"); $pf->execute([':id' => $id]); $proofs = $pf->fetchAll(); } catch (Throwable $e) { $proofs = []; }
?>
<?php if ($proofs): ?>
<div class="box">
  <strong>Comprobantes enviados por el cliente (<?= count($proofs) ?>)</strong>
  <?php foreach ($proofs as $pr): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);">
      <p class="tag" style="margin:0 0 6px;"><?= h(date('d/m/Y H:i', strtotime($pr['created_at']))) ?>
        · Pagó: <b><?= h($pr['sender'] ?: '-') ?></b> · Monto: <b><?= h($pr['amount'] ?: '-') ?></b> · ID/operación: <b><?= h($pr['reference'] ?: '-') ?></b></p>
      <?php if ($pr['note']): ?><p class="tag" style="margin:0 0 6px;">Nota: <?= h($pr['note']) ?></p><?php endif; ?>
      <?php if ($pr['file_name']): ?>
        <?php if ($pr['file_mime'] === 'pdf'): ?><a href="comprobante.php?id=<?= (int) $pr['id'] ?>" target="_blank">Abrir comprobante (PDF)</a>
        <?php else: ?><a href="comprobante.php?id=<?= (int) $pr['id'] ?>" target="_blank"><img src="comprobante.php?id=<?= (int) $pr['id'] ?>" alt="Comprobante" style="max-width:280px;max-height:360px;border:1px solid var(--line);border-radius:8px;"></a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="box">
  <strong>Pagos registrados</strong>
  <?php foreach ($pays as $p): ?>
    <p class="tag" style="margin:8px 0 0;">MP #<?= h($p['gateway_ref']) ?> · <?= h($p['status']) ?> · <?= money($p['amount']) ?> · <?= h($p['created_at']) ?></p>
  <?php endforeach; ?>
  <?php if (!$pays): ?><p class="tag" style="margin:8px 0 0;">Sin pagos registrados.</p><?php endif; ?>
</div>
<?php admin_footer();
