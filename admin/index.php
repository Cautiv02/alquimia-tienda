<?php
/**
 * admin/index.php — lista de pedidos. Por defecto muestra "Por enviar" = pagados sin entregar.
 * Acciones por fila y en bloque: marcar como enviado, cancelar.
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();

$f = (string) ($_GET['f'] ?? 'porenviar');
$q = trim((string) ($_GET['q'] ?? ''));
require_once __DIR__ . '/../payment-reminder-lib.php';
pay_reminder_ensure($pdo);

// Recordatorio por WhatsApp: registra el envío y abre el chat con el mensaje escrito
if (!empty($_GET['wa'])) {
    $wid = (int) $_GET['wa'];
    $url = pay_reminder_wa_url($pdo, $wid);
    if ($url === '') { flash_set('Este pedido no tiene un teléfono válido para WhatsApp.', true); header('Location: index.php?f=pendientes'); exit; }
    pay_reminder_mark($pdo, $wid);
    header('Location: ' . $url); exit;
}

// ---------- Acciones (fila o selección múltiple) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = ''; $ids = [];
    $silent = !empty($_POST['silent']);
    if (($_POST['bulk'] ?? '') === 'antiguos') { // todos los pagados sin enviar creados antes de hoy, sin email
        $n = $pdo->exec("UPDATE orders SET fulfillment_status='entregado', updated_at=NOW() WHERE financial_status='pagado' AND fulfillment_status<>'entregado' AND created_at < CURDATE()");
        flash_set((int) $n . ' pedido(s) antiguos marcados como enviados (sin email al cliente).');
        header('Location: index.php?f=' . urlencode($f) . '&q=' . urlencode($q)); exit;
    }
    if (!empty($_POST['single']) && preg_match('/^(entregado|cancelar|recordar):(\d+)$/', (string) $_POST['single'], $m)) {
        $action = $m[1]; $ids = [(int) $m[2]];
    } elseif (in_array($_POST['bulk'] ?? '', ['entregado', 'entregado_sin', 'cancelar', 'recordar'], true)) {
        $action = (string) $_POST['bulk'];
        if ($action === 'entregado_sin') { $action = 'entregado'; $silent = true; }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    }

    if (!$ids) {
        flash_set('Selecciona al menos un pedido.', true);
    } elseif ($action === 'recordar') {
        $okc = 0; foreach ($ids as $rid) { if (pay_reminder_email($pdo, $rid)) $okc++; }
        flash_set("Recordatorio de pago enviado por correo a $okc de " . count($ids) . " pedido(s)." . (count($ids) > $okc ? ' Los demás no estaban pendientes de pago o no tienen un correo válido.' : ''), $okc === 0);
    } else {
        $done = 0;
        if ($action === 'entregado') {
            $st = $pdo->prepare("UPDATE orders SET fulfillment_status = 'entregado', updated_at = NOW() WHERE id = :id AND financial_status = 'pagado' AND fulfillment_status <> 'entregado'");
        } else {
            $st = $pdo->prepare("UPDATE orders SET financial_status = 'anulado', updated_at = NOW() WHERE id = :id AND fulfillment_status <> 'entregado' AND financial_status <> 'anulado'");
        }
        $mails = 0;
        foreach ($ids as $id) {
            $st->execute([':id' => $id]);
            if ($st->rowCount()) { $done++; if ($action === 'entregado' && !$silent && notify_shipped($pdo, $id)) $mails++; }
        }
        $skipped = count($ids) - $done;
        $verb = $action === 'entregado' ? 'marcado(s) como enviado(s)' : 'cancelado(s)';
        flash_set($done . ' pedido(s) ' . $verb . ($action === 'entregado' ? ($silent ? ' (sin email al cliente)' : " ($mails email(s) de confirmación enviados)") : '') . ($skipped ? " ($skipped omitido(s): no correspondía cambiar su estado)" : '') . '.', $done === 0);
    }
    header('Location: index.php?f=' . urlencode($f) . '&q=' . urlencode($q));
    exit;
}

// ---------- Listado ----------
$where = []; $args = [];
if ($f === 'porenviar')      $where[] = "o.financial_status = 'pagado' AND o.fulfillment_status <> 'entregado'";
elseif ($f === 'entregados') $where[] = "o.fulfillment_status = 'entregado'";
elseif ($f === 'pendientes') $where[] = "o.financial_status = 'pendiente'";
elseif ($f === 'pagados')    $where[] = "o.financial_status = 'pagado'";
elseif ($f === 'cancelados') $where[] = "o.financial_status IN ('anulado','reembolsado')";
if ($q !== '') {
    $where[] = "(o.order_number LIKE :q OR o.email LIKE :q OR o.unique_code LIKE :q OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE :q)";
    $args[':q'] = '%' . $q . '%';
}
$sql = "SELECT o.id, o.order_number, o.email, o.phone, o.payment_reminder_at, o.payment_reminder_count, c.first_name, c.last_name, o.total_amount, o.financial_status, o.fulfillment_status, o.created_at,
               (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.title) SEPARATOR '; ') FROM order_items oi WHERE oi.order_id = o.id) AS items
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY o.created_at DESC, o.id DESC LIMIT 100 OFFSET " . (max(0, (int) ($_GET['p'] ?? 1) - 1) * 100);
$cq = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON c.id = o.customer_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
$cq->execute($args); $totalRows = (int) $cq->fetchColumn();
$pg = max(1, (int) ($_GET['p'] ?? 1)); $pages = max(1, (int) ceil($totalRows / 100));
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$cnt = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE financial_status = 'pagado' AND fulfillment_status <> 'entregado'")->fetchColumn();

$notes = [];
try {
    $notes = $pdo->query("SELECT type, message, created_at FROM admin_notifications ORDER BY id DESC LIMIT 8")->fetchAll();
} catch (Throwable $e) { /* si la tabla difiere, el listado de pedidos sigue funcionando */ }

$finLabel = ['pagado' => 'pagado', 'pendiente' => 'pago pendiente', 'anulado' => 'cancelado', 'reembolsado' => 'reembolsado'];
$fulLabel = ['pendiente' => 'por enviar', 'entregado' => 'enviado', 'parcial' => 'envío parcial'];

admin_header('Pedidos');
flash_show();
?>
<?php if (($rpn = admin_review_pending()) > 0): ?><div class="box" style="border-color:#b8892e;">⭐ <b><?= $rpn ?></b> pedido(s) entregados hace más de 3 días esperan que decidas si les pides una reseña. <a href="pedir-resenas.php">Revisar ahora</a></div><?php endif; ?>
<div class="box" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
  <div><span style="font-size:1.8rem;font-weight:600;"><?= $cnt ?></span> <span class="tag">pedidos pagados por enviar</span></div>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
    <input type="hidden" name="f" value="<?= h($f) ?>">
    <input name="q" value="<?= h($q) ?>" placeholder="N° pedido, nombre, correo o código" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;min-width:240px;">
    <button class="btn-a" type="submit">Buscar</button>
  </form>
</div>

<p>
  <?php foreach (['porenviar' => 'Por enviar', 'pagados' => 'Pagados', 'entregados' => 'Enviados', 'pendientes' => 'Pago pendiente', 'cancelados' => 'Cancelados', 'todos' => 'Todos'] as $k => $l): ?>
    <a class="btn-a <?= $f === $k ? '' : 'ghost' ?>" style="margin:0 6px 6px 0;" href="?f=<?= h($k) ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</p>

<form method="post" action="index.php?f=<?= h(urlencode($f)) ?>&q=<?= h(urlencode($q)) ?>" id="lista">
  <?= csrf_field() ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px;align-items:center;">
    <span class="tag">Con los seleccionados:</span>
    <?php if ($f === 'pendientes'): ?><button class="btn-a" type="submit" name="bulk" value="recordar" onclick="return alguno() && confirm('¿Enviar el recordatorio de pago por correo a los pedidos seleccionados?')">✉ Recordar pago (correo)</button><?php endif; ?>
    <button class="btn-a" type="submit" name="bulk" value="entregado" onclick="return alguno() && confirm('¿Marcar como ENVIADOS y avisar por email a cada cliente?')">✔ Enviados + avisar al cliente</button>
    <button class="btn-a ghost" type="submit" name="bulk" value="entregado_sin" onclick="return alguno() && confirm('¿Marcar como ENVIADOS SIN enviar email?')">✔ Enviados sin email</button>
    <button class="btn-a ghost" type="submit" name="bulk" value="cancelar" onclick="return alguno() && confirm('¿Cancelar los pedidos seleccionados? Si ya estaban cobrados, acuérdate de reembolsarlos en Mercado Pago.')">✕ Cancelar</button>
  </div>

  <table class="t">
    <tr>
      <th style="width:34px;"><input type="checkbox" id="todos" title="Seleccionar todos"></th>
      <th>Pedido</th><th>Cliente</th><th>Productos</th><th>Total</th><th>Estado</th><th>Fecha</th><th>Acciones</th>
    </tr>
    <?php foreach ($rows as $o):
      $canSend   = $o['financial_status'] === 'pagado' && $o['fulfillment_status'] !== 'entregado';
      $canCancel = $o['fulfillment_status'] !== 'entregado' && $o['financial_status'] !== 'anulado'; ?>
      <tr>
        <td><input type="checkbox" class="sel" name="ids[]" value="<?= (int) $o['id'] ?>"></td>
        <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
        <td>
          <?php $cn = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')); ?>
          <b><?= h($cn !== '' ? $cn : '(sin nombre)') ?></b><br>
          <span class="tag"><?= h($o['email']) ?></span>
          <button type="button" class="copy-mail" data-mail="<?= h($o['email']) ?>" title="Copiar correo" style="border:1px solid var(--line);background:#fff;border-radius:6px;padding:1px 7px;font-size:.75rem;cursor:pointer;margin-left:4px;">📋 Copiar</button>
          <a href="pedido.php?id=<?= (int) $o['id'] ?>&editar=1#edit-mail" title="Corregir el correo" style="border:1px solid var(--line);background:#fff;border-radius:6px;padding:1px 7px;font-size:.75rem;text-decoration:none;margin-left:2px;">✎ Editar</a>
        </td>
        <td class="tag"><?= h(mb_substr((string) $o['items'], 0, 110)) ?></td>
        <td><?= money($o['total_amount']) ?></td>
        <td>
          <span class="pill <?= h($o['financial_status']) ?>"><?= h($finLabel[$o['financial_status']] ?? $o['financial_status']) ?></span>
          <span class="pill <?= h($o['fulfillment_status']) ?>"><?= h($fulLabel[$o['fulfillment_status']] ?? $o['fulfillment_status']) ?></span>
        </td>
        <td class="tag"><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
        <td style="white-space:nowrap;">
          <?php if ($canSend): ?>
            <button class="btn-a" style="padding:6px 12px;font-size:.8rem;" name="single" value="entregado:<?= (int) $o['id'] ?>" onclick="return confirm('¿Ya enviaste la clave del pedido <?= h($o['order_number']) ?>? Se avisará por email al cliente.')">✔ Enviado</button>
          <?php endif; ?>
          <?php if ($o['financial_status'] === 'pendiente'): ?>
            <button class="btn-a" style="padding:6px 12px;font-size:.8rem;" name="single" value="recordar:<?= (int) $o['id'] ?>" onclick="return confirm('¿Enviar el recordatorio de pago por correo a <?= h($o['email']) ?>?')">✉ Recordar</button>
            <?php if (pay_reminder_phone((string) $o['phone']) !== ''): ?><a class="btn-a ghost" style="padding:6px 12px;font-size:.8rem;" href="index.php?wa=<?= (int) $o['id'] ?>" target="_blank" rel="noopener" title="Abre WhatsApp con el mensaje ya escrito">💬 WhatsApp</a>
            <?php else: ?><span class="tag" title="El pedido no tiene teléfono">sin tel.</span><?php endif; ?>
            <?php if (!empty($o['payment_reminder_at'])): ?><br><span class="tag">Recordado <?= (int) $o['payment_reminder_count'] ?>× · último <?= h(date('d/m H:i', strtotime($o['payment_reminder_at']))) ?></span><?php endif; ?>
          <?php endif; ?>
          <?php if ($canCancel): ?>
            <button class="btn-a ghost" style="padding:6px 12px;font-size:.8rem;" name="single" value="cancelar:<?= (int) $o['id'] ?>" onclick="return confirm('¿Cancelar el pedido <?= h($o['order_number']) ?>? Si ya estaba cobrado, reembólsalo en Mercado Pago.')">✕ Cancelar</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="tag">No hay pedidos en esta vista.</td></tr><?php endif; ?>
  </table>
</form>
<p class="tag" style="margin:12px 0;"><?= number_format($totalRows, 0, ',', '.') ?> pedidos en esta vista · página <?= $pg ?> de <?= $pages ?>
  <?php $pl = fn($n) => 'index.php?f=' . urlencode($f) . '&q=' . urlencode($q) . '&p=' . $n; ?>
  <?php if ($pg > 1): ?> · <a href="<?= h($pl($pg - 1)) ?>">← Más recientes</a><?php endif; ?>
  <?php if ($pg < $pages): ?> · <a href="<?= h($pl($pg + 1)) ?>">Anteriores →</a><?php endif; ?></p>
<script>
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.copy-mail'); if (!b) return; e.preventDefault();
    var t = b.dataset.mail, done = function () { var o = b.textContent; b.textContent = '✓ Copiado'; setTimeout(function () { b.textContent = o; }, 1400); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
    var a = document.createElement('textarea'); a.value = t; a.style.position = 'fixed'; a.style.opacity = '0'; document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); done(); } catch (x) {} a.remove();
  });
</script>
<script>
  (function () {
    var all = document.getElementById('todos');
    if (all) all.addEventListener('change', function () {
      document.querySelectorAll('.sel').forEach(function (c) { c.checked = all.checked; });
    });
    window.alguno = function () {
      if (document.querySelector('.sel:checked')) return true;
      alert('Selecciona al menos un pedido.'); return false;
    };
  })();
</script>

<?php if ($cnt > 0): ?>
<form method="post" style="margin-top:18px;"><?= csrf_field() ?>
  <button class="btn-a ghost" name="bulk" value="antiguos" onclick="return confirm('Se marcarán como ENVIADOS todos los pedidos pagados creados ANTES DE HOY, sin enviar ningún email. ¿Continuar?')">Marcar todos los pedidos antiguos (anteriores a hoy) como enviados, sin email</button>
</form>
<?php endif; ?>

<?php if ($notes): ?>
<div class="box" style="margin-top:24px;">
  <strong>Últimos avisos</strong>
  <?php foreach ($notes as $n): ?>
    <p class="tag" style="margin:8px 0 0;"><?= h(date('d/m H:i', strtotime($n['created_at']))) ?> · <?= h($n['message']) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer();
