<?php
/** resenas.php — todas las opiniones de clientes, con filtros por estrellas, producto y fecha. */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/catalog-lib.php';
$pdo = get_pdo();

$star = (int) ($_GET['e'] ?? 0); if ($star < 1 || $star > 5) $star = 0;
$prod = (int) ($_GET['p'] ?? 0);
$per  = (string) ($_GET['f'] ?? '');
$ord  = (string) ($_GET['o'] ?? 'recientes');
$page = max(1, (int) ($_GET['pag'] ?? 1));
$perPage = 18;

$where = ["r.published = 1", "CHAR_LENGTH(TRIM(r.content)) > 0"]; $args = [];
if ($star) { $where[] = 'r.rating = :e'; $args[':e'] = $star; }
if ($prod) { $where[] = 'r.product_id = :p'; $args[':p'] = $prod; }
$now = time();
if ($per === '30d')      { $where[] = 'r.review_date >= :d'; $args[':d'] = date('Y-m-d', $now - 30 * 86400); }
elseif ($per === '6m')   { $where[] = 'r.review_date >= :d'; $args[':d'] = date('Y-m-d', strtotime('-6 months', $now)); }
elseif (preg_match('/^20\d\d$/', $per)) { $where[] = 'YEAR(r.review_date) = :y'; $args[':y'] = (int) $per; }
else $per = '';
$orderSql = ['recientes' => 'r.review_date DESC', 'antiguas' => 'r.review_date ASC', 'mejores' => 'r.rating DESC, r.review_date DESC', 'peores' => 'r.rating ASC, r.review_date DESC'][$ord] ?? 'r.review_date DESC';
if (!isset(['recientes' => 1, 'antiguas' => 1, 'mejores' => 1, 'peores' => 1][$ord])) $ord = 'recientes';

$total = 0; $rows = []; $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]; $prods = []; $years = []; $ok = true;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM reviews r WHERE " . implode(' AND ', $where)); $st->execute($args); $total = (int) $st->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage)); $page = min($page, $pages);
    $st = $pdo->prepare("SELECT r.*, p.title AS ptitle FROM reviews r LEFT JOIN products p ON p.id = r.product_id WHERE " . implode(' AND ', $where) . " ORDER BY $orderSql LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $st->execute($args); $rows = $st->fetchAll();
    foreach ($pdo->query("SELECT rating, COUNT(*) n FROM reviews WHERE published = 1 GROUP BY rating")->fetchAll() as $d) $dist[(int) $d['rating']] = (int) $d['n'];
    $prods = $pdo->query("SELECT p.id, p.title, COUNT(*) n FROM reviews r JOIN products p ON p.id = r.product_id WHERE r.published = 1 AND CHAR_LENGTH(TRIM(r.content)) > 0 AND p.status = 'activo' GROUP BY p.id, p.title ORDER BY n DESC LIMIT 80")->fetchAll();
    $years = $pdo->query("SELECT DISTINCT YEAR(review_date) y FROM reviews WHERE published = 1 ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $ok = false; $pages = 1; }
$sum = reviews_store_summary(); $all = array_sum($dist);

function rv_url(array $over = []): string {
    $q = array_merge(['e' => $_GET['e'] ?? '', 'p' => $_GET['p'] ?? '', 'f' => $_GET['f'] ?? '', 'o' => $_GET['o'] ?? '', 'pag' => ''], $over);
    return 'resenas.php' . (($qs = http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== 0 && $v !== '0'))) ? '?' . $qs : '');
}

site_header('Opiniones de clientes', 'Lee las opiniones de quienes ya compraron en ' . SITE_NAME . '.');
?>
<style>
  .rv-head { display: grid; grid-template-columns: 220px 1fr; gap: 28px; align-items: center; margin-bottom: 22px; }
  .rv-score { text-align: center; } .rv-score .big { font-size: 3rem; font-weight: 700; line-height: 1; } .rv-score .stars { font-size: 1.3rem; margin: 6px 0; }
  .rv-bars a { display: grid; grid-template-columns: 54px 1fr 54px; gap: 10px; align-items: center; text-decoration: none; color: var(--text-dim); font-size: .85rem; padding: 2px 0; }
  .rv-bars a.on { color: var(--text); font-weight: 700; } .rv-bars .bar { height: 8px; background: #e7e0cd; border-radius: 6px; overflow: hidden; } .rv-bars .bar i { display: block; height: 100%; background: #d9a21b; }
  .rv-filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; align-items: center; }
  .rv-filters select { padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: #fff; font-family: var(--sans); font-size: .9rem; max-width: 100%; }
  .rv-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px; align-items: start; }
  .rv-prodline { font-size: .78rem; color: var(--ash); }
  .rv-pager { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin: 28px 0 8px; }
  .rv-pager a, .rv-pager span { padding: 8px 14px; border: 1px solid var(--line); border-radius: 8px; text-decoration: none; color: var(--text); background: #fff; font-size: .9rem; } .rv-pager span.cur { background: var(--gold); border-color: var(--gold); color: #fff; }
  @media (max-width: 700px) { .rv-head { grid-template-columns: 1fr; } }
</style>
<div class="wrap page">
  <h1>Opiniones de nuestros clientes</h1>
  <p class="lead">Experiencias reales de quienes ya compraron con nosotros.</p>

  <?php if ($all): ?>
  <div class="rv-head">
    <div class="rv-score"><div class="big"><?= h(number_format($sum['avg'], 1, ',', '')) ?></div><?= stars_html($sum['avg']) ?><div class="tag"><?= (int) $all ?> calificaciones</div></div>
    <div class="rv-bars">
      <?php foreach ([5, 4, 3, 2, 1] as $s): $pc = $all ? round($dist[$s] / $all * 100) : 0; ?>
      <a href="<?= h(rv_url(['e' => $star === $s ? '' : $s])) ?>" class="<?= $star === $s ? 'on' : '' ?>"><span><?= $s ?> ★</span><span class="bar"><i style="width:<?= $pc ?>%"></i></span><span><?= (int) $dist[$s] ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>

  <form class="rv-filters" method="get">
    <select name="e" onchange="this.form.submit()"><option value="">Todas las estrellas</option>
      <?php foreach ([5, 4, 3, 2, 1] as $s): ?><option value="<?= $s ?>" <?= $star === $s ? 'selected' : '' ?>><?= str_repeat('★', $s) ?> (<?= $s ?>)</option><?php endforeach; ?></select>
    <select name="p" onchange="this.form.submit()"><option value="">Todos los productos</option>
      <?php foreach ($prods as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $prod === (int) $p['id'] ? 'selected' : '' ?>><?= h(mb_substr(cat_clean((string) $p['title']), 0, 60)) ?> (<?= (int) $p['n'] ?>)</option><?php endforeach; ?></select>
    <select name="f" onchange="this.form.submit()"><option value="">Cualquier fecha</option>
      <option value="30d" <?= $per === '30d' ? 'selected' : '' ?>>Últimos 30 días</option>
      <option value="6m" <?= $per === '6m' ? 'selected' : '' ?>>Últimos 6 meses</option>
      <?php foreach ($years as $y): ?><option value="<?= (int) $y ?>" <?= $per === (string) $y ? 'selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select>
    <select name="o" onchange="this.form.submit()">
      <?php foreach (['recientes' => 'Más recientes', 'antiguas' => 'Más antiguas', 'mejores' => 'Mejor calificadas', 'peores' => 'Peor calificadas'] as $k => $l): ?><option value="<?= $k ?>" <?= $ord === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <?php if ($star || $prod || $per): ?><a href="resenas.php" class="tag">Limpiar filtros</a><?php endif; ?>
  </form>

  <p class="tag" style="margin:0 0 14px;"><?= (int) $total ?> opinion<?= $total === 1 ? '' : 'es' ?> con comentario</p>
  <div class="rv-list">
    <?php foreach ($rows as $r): ?>
    <div class="review-card">
      <div class="rv-top"><?= stars_html((float) $r['rating']) ?><?= verified_badge() ?></div>
      <p><?= h($r['content']) ?></p>
      <?php if ($r['ptitle']): ?><a class="rv-prodline" href="producto.php?id=<?= (int) $r['product_id'] ?>"><?= h(mb_substr(cat_clean((string) $r['ptitle']), 0, 70)) ?></a><?php endif; ?>
      <span class="rv-by"><?= h($r['author']) ?> · <?= h(date('d/m/Y', strtotime($r['review_date']))) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><p class="lead">No hay opiniones con esos filtros.</p><?php endif; ?>

  <?php if ($pages > 1): ?>
  <div class="rv-pager">
    <?php if ($page > 1): ?><a href="<?= h(rv_url(['pag' => $page - 1])) ?>">‹ Anterior</a><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?><?= $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . h(rv_url(['pag' => $i])) . '">' . $i . '</a>' ?><?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= h(rv_url(['pag' => $page + 1])) ?>">Siguiente ›</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php else: ?><p class="lead"><?= $ok ? 'Aún no hay opiniones publicadas.' : 'No se pudieron cargar las opiniones.' ?></p><?php endif; ?>
</div>
<?php reviews_scroll_js(); site_footer();
