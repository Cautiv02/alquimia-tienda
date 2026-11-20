<?php
/**
 * admin/clientes.php — listado de clientes (incluye los importados de la tienda anterior) y ficha con sus pedidos.
 *  ?q=texto  busca por nombre, correo o teléfono · ?o=reciente|gasto|pedidos|nombre · ?p=página · ?csv=1 descarga lo filtrado
 *  ?id=N     ficha del cliente con todos sus pedidos
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();

// ---------- Ficha de un cliente ----------
if (!empty($_GET['id'])) {
    $cid = (int) $_GET['id'];
    $c = $pdo->prepare("SELECT * FROM customers WHERE id = :id"); $c->execute([':id' => $cid]); $c = $c->fetch();
    if (!$c) { admin_header('Cliente'); echo '<p>Cliente no encontrado. <a href="clientes.php">Volver</a></p>'; admin_footer(); exit; }
    $os = $pdo->prepare("SELECT o.id, o.order_number, o.total_amount, o.financial_status, o.fulfillment_status, o.created_at,
            (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.title) SEPARATOR '; ') FROM order_items oi WHERE oi.order_id = o.id) AS items
        FROM orders o WHERE o.customer_id = :id OR LOWER(o.email) = LOWER(:e) ORDER BY o.created_at DESC, o.id DESC");
    $os->execute([':id' => $cid, ':e' => (string) $c['email']]); $os = $os->fetchAll();
    $paid = array_filter($os, fn($o) => $o['financial_status'] === 'pagado');
    $spent = array_sum(array_map(fn($o) => (float) $o['total_amount'], $paid));
    $nm = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    $wa = preg_replace('/\D+/', '', (string) ($c['phone'] ?? ''));
    $finLabel = ['pagado' => 'pagado', 'pendiente' => 'pago pendiente', 'anulado' => 'cancelado', 'reembolsado' => 'reembolsado'];
    admin_header('Cliente');
    ?>
    <p><a href="clientes.php">← Clientes</a></p>
    <div class="box">
      <h2 style="margin:0 0 6px;font-family:var(--serif);"><?= h($nm ?: '(sin nombre)') ?></h2>
      <p style="margin:0;"><b>Correo:</b> <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a><br>
        <b>Teléfono:</b> <?= h($c['phone'] ?: '-') ?><?php if (strlen($wa) >= 8): ?> · <a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener">abrir WhatsApp</a><?php endif; ?><br>
        <b>Cliente desde:</b> <?= !empty($c['created_at']) ? h(date('d/m/Y', strtotime($c['created_at']))) : '-' ?><br>
        <b>Pedidos:</b> <?= count($os) ?> (<?= count($paid) ?> pagados) · <b>Total comprado:</b> <?= money($spent) ?></p>
    </div>
    <div class="box">
      <strong>Pedidos</strong>
      <table class="t" style="margin-top:10px;">
        <tr><th>Pedido</th><th>Productos</th><th>Total</th><th>Estado</th><th>Fecha</th></tr>
        <?php foreach ($os as $o): ?>
        <tr>
          <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><b><?= h($o['order_number']) ?></b></a></td>
          <td class="tag"><?= h(mb_substr((string) $o['items'], 0, 120)) ?></td>
          <td><?= money($o['total_amount']) ?></td>
          <td><span class="pill <?= h($o['financial_status']) ?>"><?= h($finLabel[$o['financial_status']] ?? $o['financial_status']) ?></span></td>
          <td class="tag"><?= h(date('d/m/Y', strtotime($o['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$os): ?><tr><td colspan="5" class="tag">Este cliente no tiene pedidos.</td></tr><?php endif; ?>
      </table>
    </div>
    <?php admin_footer(); exit;
}

// ---------- Listado ----------
$q = trim((string) ($_GET['q'] ?? ''));
$ord = (string) ($_GET['o'] ?? 'reciente');
$orders = ['reciente' => 'last_order DESC', 'gasto' => 'spent DESC', 'pedidos' => 'n_orders DESC, last_order DESC', 'nombre' => 'c.first_name ASC, c.last_name ASC'];
if (!isset($orders[$ord])) $ord = 'reciente';
$where = ''; $args = [];
if ($q !== '') {
    $where = " WHERE (c.email LIKE :q OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE :q OR c.phone LIKE :q)";
    $args[':q'] = '%' . $q . '%';
}
$total = $pdo->prepare("SELECT COUNT(*) FROM customers c" . $where); $total->execute($args); $total = (int) $total->fetchColumn();
$base = "SELECT c.id, c.email, c.first_name, c.last_name, c.phone, COUNT(o.id) AS n_orders,
           COALESCE(SUM(CASE WHEN o.financial_status = 'pagado' THEN o.total_amount END), 0) AS spent, MAX(o.created_at) AS last_order
         FROM customers c LEFT JOIN orders o ON o.customer_id = c.id" . $where . " GROUP BY c.id, c.email, c.first_name, c.last_name, c.phone ORDER BY " . $orders[$ord];

if (!empty($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="clientes.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nombre', 'Correo', 'Teléfono', 'Pedidos', 'Total pagado', 'Último pedido']);
    $st = $pdo->prepare($base); $st->execute($args);
    while ($r = $st->fetch()) fputcsv($out, [trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), $r['email'], $r['phone'], $r['n_orders'], (int) $r['spent'], $r['last_order']]);
    exit;
}

$per = 50; $pg = max(1, (int) ($_GET['p'] ?? 1)); $pages = max(1, (int) ceil($total / $per));
$st = $pdo->prepare($base . " LIMIT $per OFFSET " . (($pg - 1) * $per)); $st->execute($args); $rows = $st->fetchAll();
$link = fn(array $o) => 'clientes.php?' . http_build_query(array_merge(['q' => $q, 'o' => $ord], $o));

admin_header('Clientes');
?>
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;align-items:center;">
  <input name="q" value="<?= h($q) ?>" placeholder="Nombre, correo o teléfono" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;min-width:260px;">
  <select name="o" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;">
    <?php foreach (['reciente' => 'Compra más reciente', 'gasto' => 'Mayor gasto', 'pedidos' => 'Más pedidos', 'nombre' => 'Nombre A-Z'] as $k => $l): ?><option value="<?= $k ?>" <?= $ord === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <button class="btn-a" type="submit">Buscar</button>
  <a class="btn-a ghost" href="<?= h($link(['csv' => 1])) ?>">Descargar CSV</a>
</form>
<p class="tag"><?= number_format($total, 0, ',', '.') ?> clientes · página <?= $pg ?> de <?= $pages ?></p>
<table class="t">
  <tr><th>Cliente</th><th>Correo</th><th>Teléfono</th><th>Pedidos</th><th>Total pagado</th><th>Último pedido</th></tr>
  <?php foreach ($rows as $r): $nm = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); ?>
  <tr>
    <td><a href="clientes.php?id=<?= (int) $r['id'] ?>"><b><?= h($nm ?: '(sin nombre)') ?></b></a></td>
    <td class="tag"><?= h($r['email']) ?></td>
    <td class="tag"><?= h($r['phone'] ?: '-') ?></td>
    <td><?= (int) $r['n_orders'] ?></td>
    <td><?= money($r['spent']) ?></td>
    <td class="tag"><?= $r['last_order'] ? h(date('d/m/Y', strtotime($r['last_order']))) : '-' ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="tag">No hay clientes con esa búsqueda.</td></tr><?php endif; ?>
</table>
<p class="tag" style="margin-top:12px;">
  <?php if ($pg > 1): ?><a href="<?= h($link(['p' => $pg - 1])) ?>">← Anterior</a><?php endif; ?>
  <?php if ($pg < $pages): ?> <?php if ($pg > 1): ?>·<?php endif; ?> <a href="<?= h($link(['p' => $pg + 1])) ?>">Siguiente →</a><?php endif; ?></p>
<?php admin_footer();
