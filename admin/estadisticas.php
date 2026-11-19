<?php
/**
 * admin/estadisticas.php — cómo va la tienda: visitas, embudo (visita → producto → carrito → pedido → pago), orígenes, productos más vistos.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../stats-lib.php';
$pdo = get_pdo();
stats_ensure_table($pdo);

$ranges = ['hoy' => ['Hoy', 0], 'ayer' => ['Ayer', 1], '7' => ['7 días', 6], '30' => ['30 días', 29]];
$r = (string) ($_GET['r'] ?? '7'); if (!isset($ranges[$r])) $r = '7';
$startDay = date('Y-m-d', strtotime('-' . $ranges[$r][1] . ' days'));
$from = $startDay . ' 00:00:00';
$to = $r === 'ayer' ? date('Y-m-d') . ' 00:00:00' : date('Y-m-d H:i:s', strtotime('+1 day'));
$args = [':f' => $from, ':t' => $to];

function one(PDO $pdo, string $sql, array $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function rows(PDO $pdo, string $sql, array $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); }
$W = "created_at >= :f AND created_at < :t";
$We = "e.created_at >= :f AND e.created_at < :t";
$Wo = "o.created_at >= :f AND o.created_at < :t";

$visitors  = (int) one($pdo, "SELECT COUNT(DISTINCT vid) FROM site_events WHERE ev='view' AND $W", $args);
$views     = (int) one($pdo, "SELECT COUNT(*) FROM site_events WHERE ev='view' AND $W", $args);
$vProduct  = (int) one($pdo, "SELECT COUNT(DISTINCT vid) FROM site_events WHERE ev='view' AND page='producto.php' AND $W", $args);
$vCart     = (int) one($pdo, "SELECT COUNT(DISTINCT vid) FROM site_events WHERE ev='cart_add' AND $W", $args);
$ordersAll = (int) one($pdo, "SELECT COUNT(*) FROM orders WHERE $W", $args);
$ordersPaid = rows($pdo, "SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) s FROM orders WHERE financial_status='pagado' AND $W", $args)[0];

$perDay = rows($pdo, "SELECT DATE(created_at) d, COUNT(DISTINCT vid) v, COUNT(*) pv FROM site_events WHERE ev='view' AND $W GROUP BY DATE(created_at) ORDER BY d", $args);
$byDay = []; foreach ($perDay as $x) $byDay[$x['d']] = $x;
$maxV = max(1, ...array_map(fn($x) => (int) $x['v'], $perDay ?: [['v' => 1]]));
$sources = rows($pdo, "SELECT source, COUNT(DISTINCT vid) v FROM site_events WHERE ev='view' AND source <> 'interno' AND $W GROUP BY source ORDER BY v DESC LIMIT 8", $args);
$devices = rows($pdo, "SELECT device, COUNT(DISTINCT vid) v FROM site_events WHERE ev='view' AND $W GROUP BY device ORDER BY v DESC", $args);
$topProducts = rows($pdo, "SELECT e.product_id, p.title, COUNT(*) vistas, COUNT(DISTINCT e.vid) personas
    FROM site_events e LEFT JOIN products p ON p.id = e.product_id WHERE e.ev='view' AND e.page='producto.php' AND e.product_id IS NOT NULL AND $We
    GROUP BY e.product_id, p.title ORDER BY vistas DESC LIMIT 10", $args);
$topCart = rows($pdo, "SELECT e.product_id, p.title, COUNT(*) n FROM site_events e LEFT JOIN products p ON p.id = e.product_id
    WHERE e.ev='cart_add' AND e.product_id IS NOT NULL AND $We GROUP BY e.product_id, p.title ORDER BY n DESC LIMIT 5", $args);
$abandoned = rows($pdo, "SELECT o.id, o.order_number, o.email, o.total_amount, o.created_at, o.payment_method
    FROM orders o WHERE o.financial_status='pendiente' AND $Wo ORDER BY o.id DESC LIMIT 12", $args);

function pct($a, $b) { return $b > 0 ? round($a * 100 / $b, 1) . '%' : '—'; }
function clean_t($t) { return trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $t)); }

admin_header('Estadísticas');
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
  <h2 style="font-family:var(--serif);margin:0;">Estadísticas de la tienda</h2>
  <div><?php foreach ($ranges as $k => $v): ?><a class="btn-a <?= $r === (string) $k ? '' : 'ghost' ?>" style="margin-left:6px;" href="?r=<?= h($k) ?>"><?= h($v[0]) ?></a><?php endforeach; ?></div>
</div>
<style>.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}.kpi{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:16px}.kpi b{display:block;font-size:1.7rem;font-weight:600}.kpi span{font-size:.78rem;color:var(--ash)}.bar{height:10px;background:var(--gold);border-radius:5px}.two{display:grid;grid-template-columns:1fr 1fr;gap:20px}@media(max-width:800px){.two{grid-template-columns:1fr}}</style>

<div class="kpis">
  <div class="kpi"><b><?= $visitors ?></b><span>personas distintas en la tienda</span></div>
  <div class="kpi"><b><?= $views ?></b><span>páginas vistas</span></div>
  <div class="kpi"><b><?= (int) $ordersPaid['n'] ?></b><span>pedidos pagados</span></div>
  <div class="kpi"><b><?= h(money($ordersPaid['s'])) ?></b><span>ventas pagadas</span></div>
  <div class="kpi"><b><?= pct((int) $ordersPaid['n'], $visitors) ?></b><span>visitas que terminaron comprando</span></div>
</div>

<div class="box">
  <strong>Del interés al pago</strong>
  <table class="t" style="margin-top:12px;">
    <tr><th>Etapa</th><th>Personas / pedidos</th><th>Del paso anterior</th></tr>
    <tr><td>Entraron a la tienda</td><td><?= $visitors ?></td><td>—</td></tr>
    <tr><td>Vieron un producto</td><td><?= $vProduct ?></td><td><?= pct($vProduct, $visitors) ?></td></tr>
    <tr><td>Agregaron al carrito</td><td><?= $vCart ?></td><td><?= pct($vCart, $vProduct) ?></td></tr>
    <tr><td>Llegaron al pago (pedido creado)</td><td><?= $ordersAll ?></td><td>—</td></tr>
    <tr><td>Pagaron</td><td><?= (int) $ordersPaid['n'] ?></td><td><?= pct((int) $ordersPaid['n'], $ordersAll) ?></td></tr>
  </table>
  <p class="tag" style="margin:10px 0 0;">Los pedidos incluyen compras directas y desde el carrito, y también tus pruebas. "Comprar ahora" no pasa por el carrito, por eso el carrito puede ser menor que los pedidos.</p>
</div>

<div class="box">
  <strong>Visitas por día</strong>
  <table class="t" style="margin-top:12px;">
    <tr><th style="width:110px;">Día</th><th>Personas</th><th style="width:90px;">Páginas</th></tr>
    <?php for ($d = strtotime($startDay); $d <= strtotime($r === 'ayer' ? $startDay : date('Y-m-d')); $d += 86400): $k = date('Y-m-d', $d); $x = $byDay[$k] ?? ['v' => 0, 'pv' => 0]; ?>
      <tr><td><?= h(date('d/m', $d)) ?></td><td><div style="display:flex;align-items:center;gap:8px;"><div class="bar" style="width:<?= (int) round(($x['v'] / $maxV) * 80) ?>%;min-width:2px;"></div><?= (int) $x['v'] ?></div></td><td><?= (int) $x['pv'] ?></td></tr>
    <?php endfor; ?>
  </table>
</div>

<div class="two">
  <div class="box"><strong>De dónde llegan</strong>
    <table class="t" style="margin-top:12px;"><?php foreach ($sources as $x): ?><tr><td><?= h($x['source']) ?></td><td><?= (int) $x['v'] ?></td></tr><?php endforeach; if (!$sources): ?><tr><td class="tag">Aún sin datos.</td></tr><?php endif; ?></table></div>
  <div class="box"><strong>Dispositivo</strong>
    <table class="t" style="margin-top:12px;"><?php foreach ($devices as $x): ?><tr><td><?= h($x['device']) ?></td><td><?= (int) $x['v'] ?></td></tr><?php endforeach; if (!$devices): ?><tr><td class="tag">Aún sin datos.</td></tr><?php endif; ?></table></div>
</div>

<div class="two">
  <div class="box"><strong>Productos más vistos</strong>
    <table class="t" style="margin-top:12px;"><tr><th>Producto</th><th>Vistas</th><th>Personas</th></tr>
    <?php foreach ($topProducts as $x): ?><tr><td><a href="producto.php?id=<?= (int) $x['product_id'] ?>"><?= h(mb_substr(clean_t($x['title']), 0, 60)) ?></a></td><td><?= (int) $x['vistas'] ?></td><td><?= (int) $x['personas'] ?></td></tr><?php endforeach; if (!$topProducts): ?><tr><td colspan="3" class="tag">Aún sin datos.</td></tr><?php endif; ?></table></div>
  <div class="box"><strong>Más agregados al carrito</strong>
    <table class="t" style="margin-top:12px;"><?php foreach ($topCart as $x): ?><tr><td><?= h(mb_substr(clean_t($x['title']), 0, 60)) ?></td><td><?= (int) $x['n'] ?></td></tr><?php endforeach; if (!$topCart): ?><tr><td class="tag">Aún sin datos.</td></tr><?php endif; ?></table></div>
</div>

<div class="box">
  <strong>Llegaron al pago y no pagaron</strong>
  <p class="tag" style="margin:6px 0 10px;">Pedidos de este período que siguen pendientes. Puedes escribirles por WhatsApp o correo; las transferencias aparecen aquí hasta que las marques como pagadas.</p>
  <table class="t"><tr><th>Pedido</th><th>Correo</th><th>Total</th><th>Medio</th><th>Fecha</th></tr>
  <?php foreach ($abandoned as $o): ?><tr>
    <td><a href="pedido.php?id=<?= (int) $o['id'] ?>"><?= h($o['order_number']) ?></a></td><td><?= h($o['email']) ?></td><td><?= h(money($o['total_amount'])) ?></td>
    <td><?= h($o['payment_method']) ?></td><td class="tag"><?= h(date('d/m H:i', strtotime($o['created_at']))) ?></td></tr>
  <?php endforeach; if (!$abandoned): ?><tr><td colspan="5" class="tag">Ninguno en este período.</td></tr><?php endif; ?></table>
</div>
<p class="tag">Las visitas se cuentan de forma anónima (sin guardar IP ni datos personales), no cuentan tus visitas desde el panel ni los robots de buscadores, y se miden desde que se instaló esta función.</p>
<?php admin_footer();
