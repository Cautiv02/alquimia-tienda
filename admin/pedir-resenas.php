<?php
/**
 * admin/pedir-resenas.php — pedidos entregados hace 3 días o más a los que aún no les has pedido reseña.
 * Tú eliges a quién se la pides (o la omites). Nada se envía solo.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../settings-lib.php';
require_once __DIR__ . '/../review-requests-lib.php';
$pdo = get_pdo();
review_requests_ensure($pdo);
require_once __DIR__ . '/../reviews-lib.php';
try { reviews_ensure_table($pdo); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string) ($_POST['a'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('Marca al menos un pedido.', true); }
    elseif ($a === 'solicitar') {
        $ok = 0; $bad = 0;
        foreach ($ids as $oid) { if (review_request_send($pdo, $oid)) $ok++; else $bad++; }
        flash_set("Solicitud enviada a $ok cliente(s)." . ($bad ? " $bad no se pudieron enviar." : ''), $bad > 0 && !$ok);
    } elseif ($a === 'omitir') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE orders SET review_skip = 1 WHERE id IN ($in)")->execute($ids);
        flash_set(count($ids) . ' pedido(s) omitidos: no volverán a aparecer aquí.');
    }
    header('Location: pedir-resenas.php'); exit;
}

$pending = review_pending_orders($pdo);
$sent = [];
try {
    $sent = $pdo->query("SELECT o.id, o.order_number, o.email, o.review_requested_at, c.first_name,
            (SELECT COUNT(*) FROM reviews r WHERE r.order_id = o.id) AS got,
            (SELECT code FROM review_coupons k WHERE k.order_id = o.id LIMIT 1) AS code
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.review_requested_at IS NOT NULL ORDER BY o.review_requested_at DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {}

admin_header('Pedir reseñas');
flash_show();
?>
<div class="box">
  <strong><?= count($pending) ?></strong> pedido(s) entregados hace 3 días o más, sin reseña ni solicitud.
  <p class="tag" style="margin:6px 0 0;">Marca los clientes a los que quieres pedirles su opinión. Se les envía un correo con el enlace<?= reward_enabled() ? ' y la oferta de ' . reward_percent() . '% de descuento al dejarla' : '' ?> (el texto se edita en «Correos»). Solo aparecen pedidos entregados desde que se activó este recordatorio.</p>
</div>

<form method="post"><?= csrf_field() ?>
<div class="box">
  <table class="t">
    <tr><th style="width:34px;"><input type="checkbox" onclick="document.querySelectorAll('.sel').forEach(function(c){c.checked=this.checked}.bind(this))"></th><th>Pedido</th><th>Cliente</th><th>Total</th><th>Entregado</th></tr>
    <?php foreach ($pending as $o): ?>
    <tr>
      <td><input type="checkbox" class="sel" name="ids[]" value="<?= (int) $o['id'] ?>"></td>
      <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
      <td><b><?= h(trim((string) $o['first_name']) ?: '(sin nombre)') ?></b><br><span class="tag"><?= h($o['email']) ?></span></td>
      <td><?= money($o['total_amount']) ?></td>
      <td class="tag"><?= h(date('d/m/Y', strtotime($o['updated_at']))) ?> (hace <?= max(3, (int) floor((time() - strtotime($o['updated_at'])) / 86400)) ?> días)</td>
    </tr>
    <?php endforeach; if (!$pending): ?><tr><td colspan="5" class="tag">No hay pedidos pendientes de pedir reseña. 🎉</td></tr><?php endif; ?>
  </table>
  <?php if ($pending): ?>
  <p style="margin:14px 0 0;display:flex;gap:10px;flex-wrap:wrap;">
    <button class="btn-a" name="a" value="solicitar" onclick="return confirm('¿Enviar la solicitud de reseña a los pedidos marcados?')">Pedir reseña a los marcados</button>
    <button class="btn-a ghost" name="a" value="omitir" onclick="return confirm('Estos pedidos no volverán a aparecer en esta lista. ¿Omitir?')">No pedir (omitir)</button>
  </p>
  <?php endif; ?>
</div>
</form>

<?php if ($sent): ?>
<div class="box">
  <strong>Solicitudes enviadas (últimas 30)</strong>
  <table class="t" style="margin-top:10px;">
    <tr><th>Pedido</th><th>Cliente</th><th>Enviada</th><th>Estado</th></tr>
    <?php foreach ($sent as $o): ?>
    <tr>
      <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
      <td><?= h(trim((string) $o['first_name']) ?: $o['email']) ?></td>
      <td class="tag"><?= h(date('d/m/Y', strtotime($o['review_requested_at']))) ?></td>
      <td class="tag"><?= $o['code'] ? '🎁 Descuento enviado (' . h($o['code']) . ')' : ((int) $o['got'] > 0 ? '✅ Dejó reseña — <a href="resenas.php?f=nuevas">aprobar y enviar descuento</a>' : 'Sin respuesta aún') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php admin_footer();
