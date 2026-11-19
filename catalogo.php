<?php
/**
 * catalogo.php — listado de productos activos con búsqueda y categorías.
 * ?cat=windows|office|antivirus|adobe|autodesk   ?q=texto
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';

$pdo = get_pdo();

// Categorías de la tienda → condición SQL sobre título, vendor y tipo de producto
$categories = [
    'windows'   => ['Windows',   "(p.title LIKE '%windows%')"],
    'office'    => ['Office',    "(p.title LIKE '%office%' OR p.title LIKE '%365%' OR p.title LIKE '%project%' OR p.title LIKE '%visio%')"],
    'antivirus' => ['Antivirus', "(p.product_type LIKE '%antivirus%' OR p.vendor IN ('McAfee','Avast','ESET','Norton','Kaspersky','Panda','Malwarebytes','AVG') OR p.title LIKE '%antivirus%')"],
    'adobe'     => ['Adobe',     "(p.vendor = 'Adobe' OR p.title LIKE '%adobe%' OR p.title LIKE '%photoshop%' OR p.title LIKE '%acrobat%' OR p.title LIKE '%creative cloud%')"],
    'autodesk'  => ['Autodesk',  "(p.vendor = 'Autodesk' OR p.title LIKE '%autodesk%' OR p.title LIKE '%autocad%')"],
];

$cat = strtolower(trim((string) ($_GET['cat'] ?? '')));
$q   = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
if (!isset($categories[$cat])) $cat = '';

$where = ["p.status = 'activo'", "EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id)"];
$args = [];
if ($cat !== '') $where[] = $categories[$cat][1];
if ($q !== '') { $where[] = '(p.title LIKE :q OR p.vendor LIKE :q)'; $args[':q'] = '%' . $q . '%'; }

$sql = "
    SELECT p.id, p.title, p.image_url,
           MIN(pv.price) AS min_price,
           MAX(CASE WHEN pv.compare_at_price > pv.price THEN pv.compare_at_price END) AS old_price,
           COUNT(pv.id) AS nvar
    FROM products p
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY p.id, p.title, p.image_url
    ORDER BY p.title ASC
    LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$pageTitle = $cat ? $categories[$cat][0] : 'Catálogo';
site_header($pageTitle, 'Licencias originales de software para Chile.');
?>
<div class="wrap page">
  <h1><?= h($pageTitle) ?></h1>
  <p class="lead"><?= count($rows) ?> producto<?= count($rows) === 1 ? '' : 's' ?><?= $q !== '' ? ' para “' . h($q) . '”' : '' ?></p>

  <form method="get" class="filters">
    <?php if ($cat): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar software, licencia, marca...">
    <button class="btn-gold" type="submit" style="border:none;cursor:pointer;">Buscar</button>
  </form>
  <div class="filters" style="margin-top:-12px;">
    <a class="chip <?= $cat === '' ? 'on' : '' ?>" href="catalogo.php">Todo</a>
    <?php foreach ($categories as $k => $c): ?>
      <a class="chip <?= $cat === $k ? 'on' : '' ?>" href="catalogo.php?cat=<?= h($k) ?>"><?= h($c[0]) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="offer-grid" style="grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));">
    <?php foreach ($rows as $o):
      $hasOld = $o['old_price'] && $o['old_price'] > $o['min_price']; ?>
      <a class="offer-card" href="producto.php?id=<?= (int) $o['id'] ?>" style="text-decoration:none;">
        <div class="offer-image">
          <?php if ($hasOld): ?><span class="offer-badge disponible">Oferta</span><?php endif; ?>
          <?php if ($o['image_url']): ?>
            <img src="<?= h($o['image_url']) ?>" alt="<?= h($o['title']) ?>" loading="lazy">
          <?php else: ?><span class="placeholder-mark">sin imagen</span><?php endif; ?>
        </div>
        <div class="offer-body">
          <p class="offer-title"><?= h($o['title']) ?></p>
          <div class="offer-price-row">
            <span class="offer-price"><?= (int) $o['nvar'] > 1 ? 'Desde ' : '' ?><?= money($o['min_price']) ?></span>
            <?php if ($hasOld): ?><span class="offer-price-old"><?= money($o['old_price']) ?></span><?php endif; ?>
          </div>
          <span class="offer-buy">Ver opciones</span>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <p style="color:var(--ash);grid-column:1/-1;">No encontramos productos con ese filtro. Prueba otra búsqueda o escríbenos por WhatsApp.</p>
    <?php endif; ?>
  </div>
</div>
<?php site_footer();
