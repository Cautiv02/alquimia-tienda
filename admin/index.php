<?php
/**
 * admin/index.php — lista de pedidos. Por defecto muestra "Por enviar" = pagados sin entregar.
 * Acciones por fila y en bloque: marcar como enviado, cancelar.
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();

$f = (string) ($_GET['f'] ?? 'porenviar');
$q = trim((string) ($_GET['q'] ?? ''));

// ---------- Acciones (fila o selección múltiple) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = ''; $ids = [];
    if (!empty($_POST['single']) && preg_match('/^(entregado|cancelar):(\d+)$/', (string) $_POST['single'], $m)) {
        $action = $m[1]; $ids = [(int) $m[2]];
    } elseif (in_array($_POST['bulk'] ?? '', ['entregado', 'cancelar'], true)) {
        $action = (string) $_POST['bulk'];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    }

    if (!$ids) {
        flash_set('Selecciona al menos un pedido.', true);
    } else {
        $done = 0;
        if ($action === 'entregado') {
            $st = $pdo->prepare("UPDATE orders SET fulfillment_status = 'entregado', updated_at = NOW() WHERE id = :id AND financial_status = 'pagado' AND fulfillment_status <> 'entregado'");
        } else {
            $st = $pdo->prepare("UPDATE orders SET financial_status = 'anulado', updated_at = NOW() WHERE id = :id AND fulfillment_status <> 'entregado' AND financial_status <> 'anulado'");
        }
        foreach ($ids as $id) { $st->execute([':id' => $id]); $done += $st->rowCount(); }
        $skipped = count($ids) - $done;
        $verb = $action === 'entregado' ? 'marcado(s) como enviado(s)' : 'cancelado(s)';
        flash_set($done . ' pedido(s) ' . $verb . ($skipped ? " ($skipped omitido(s): no correspondía cambiar su estado)" : '') . '.', $done === 0);
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
elseif ($f === 'cancelados') $where[] = "o.financial_status = 'anulado'";
if ($q !== '') {
    $where[] = '(o.order_number LIKE :q OR o.email LIKE :q OR o.unique_code LIKE :q)';
    $args[':q'] = '%' . $q . '%';
}
$sql = "SELECT o.id, o.order_number, o.email, o.total_amount, o.financial_status, o.fulfillment_status, o.created_at,
               (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.title) SEPARATOR '; ') FROM order_items oi WHERE oi.order_id = o.id) AS items
        FROM orders o" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY o.id DESC LIMIT 150";
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
<div class="box" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
  <div><span style="font-size:1.8rem;font-weight:600;"><?= $cnt ?></span> <span class="tag">pedidos pagados por enviar</span></div>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
    <input type="hidden" name="f" value="<?= h($f) ?>">
    <input name="q" value="<?= h($q) ?>" placeholder="N° pedido, correo o código" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;min-width:240px;">
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
    <button class="btn-a" type="submit" name="bulk" value="entregado" onclick="return alguno() && confirm('¿Marcar los pedidos seleccionados como ENVIADOS? (solo se cambian los que ya están pagados)')">✔ Marcar como enviados</button>
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
        <td><?= h($o['email']) ?></td>
        <td class="tag"><?= h(mb_substr((string) $o['items'], 0, 110)) ?></td>
        <td><?= money($o['total_amount']) ?></td>
        <td>
          <span class="pill <?= h($o['financial_status']) ?>"><?= h($finLabel[$o['financial_status']] ?? $o['financial_status']) ?></span>
          <span class="pill <?= h($o['fulfillment_status']) ?>"><?= h($fulLabel[$o['fulfillment_status']] ?? $o['fulfillment_status']) ?></span>
        </td>
        <td class="tag"><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
        <td style="white-space:nowrap;">
          <?php if ($canSend): ?>
            <button class="btn-a" style="padding:6px 12px;font-size:.8rem;" name="single" value="entregado:<?= (int) $o['id'] ?>" onclick="return confirm('¿Ya enviaste la clave del pedido <?= h($o['order_number']) ?>?')">✔ Enviado</button>
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

<?php if ($notes): ?>
<div class="box" style="margin-top:24px;">
  <strong>Últimos avisos</strong>
  <?php foreach ($notes as $n): ?>
    <p class="tag" style="margin:8px 0 0;"><?= h(date('d/m H:i', strtotime($n['created_at']))) ?> · <?= h($n['message']) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer();
