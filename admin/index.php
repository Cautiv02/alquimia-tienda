<?php
/**
 * admin/index.php — lista de pedidos. Por defecto muestra "Por enviar" = pagados sin entregar.
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();

$f = (string) ($_GET['f'] ?? 'porenviar');
$q = trim((string) ($_GET['q'] ?? ''));

$where = []; $args = [];
if ($f === 'porenviar')      $where[] = "o.financial_status = 'pagado' AND o.fulfillment_status <> 'entregado'";
elseif ($f === 'entregados') $where[] = "o.fulfillment_status = 'entregado'";
elseif ($f === 'pendientes') $where[] = "o.financial_status = 'pendiente'";
elseif ($f === 'pagados')    $where[] = "o.financial_status = 'pagado'";
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
  <?php foreach (['porenviar' => 'Por enviar', 'pagados' => 'Pagados', 'entregados' => 'Entregados', 'pendientes' => 'Pago pendiente', 'todos' => 'Todos'] as $k => $l): ?>
    <a class="btn-a <?= $f === $k ? '' : 'ghost' ?>" style="margin:0 6px 6px 0;" href="?f=<?= h($k) ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</p>

<table class="t">
  <tr><th>Pedido</th><th>Cliente</th><th>Productos</th><th>Total</th><th>Estado</th><th>Fecha</th></tr>
  <?php foreach ($rows as $o): ?>
    <tr>
      <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
      <td><?= h($o['email']) ?></td>
      <td class="tag"><?= h(mb_substr((string) $o['items'], 0, 110)) ?></td>
      <td><?= money($o['total_amount']) ?></td>
      <td><span class="pill <?= h($o['financial_status']) ?>"><?= h($o['financial_status']) ?></span>
          <span class="pill <?= h($o['fulfillment_status']) ?>"><?= h($o['fulfillment_status']) ?></span></td>
      <td class="tag"><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="tag">No hay pedidos en esta vista.</td></tr><?php endif; ?>
</table>

<?php if ($notes): ?>
<div class="box" style="margin-top:24px;">
  <strong>Últimos avisos</strong>
  <?php foreach ($notes as $n): ?>
    <p class="tag" style="margin:8px 0 0;"><?= h(date('d/m H:i', strtotime($n['created_at']))) ?> · <?= h($n['message']) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer();
