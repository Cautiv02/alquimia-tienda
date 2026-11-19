<?php
/**
 * catalogo.php — catálogo con filtros laterales (marca, categoría, compatibilidad, duración, ofertas) y orden.
 * Enlaces antiguos siguen funcionando: ?cat=windows|office|antivirus|adobe|autodesk  ?q=texto
 */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/catalog-lib.php';

$pdo = get_pdo();

// Productos activos con su resumen de precios
$all = $pdo->query("
    SELECT p.id, p.title, p.image_url, MIN(pv.price) AS min_price,
           MAX(CASE WHEN pv.compare_at_price > pv.price THEN pv.compare_at_price END) AS old_price,
           COUNT(pv.id) AS nvar,
           SUBSTRING_INDEX(GROUP_CONCAT(pv.id ORDER BY pv.price ASC, pv.id ASC), ',', 1) AS first_variant
    FROM products p JOIN product_variants pv ON pv.product_id = p.id
    WHERE p.status = 'activo'
    GROUP BY p.id, p.title, p.image_url
    ORDER BY p.title ASC")->fetchAll();
foreach ($all as &$r) { $r['clean'] = cat_clean($r['title']); $r['f'] = product_facets($r['title']); } unset($r);

// Enlaces antiguos del menú → filtros nuevos
$legacy = ['windows' => ['cat', 'Windows'], 'office' => ['cat', 'Office y productividad'], 'antivirus' => ['cat', 'Antivirus y seguridad'],
           'adobe' => ['marca', 'Adobe'], 'autodesk' => ['marca', 'Autodesk']];
$selMarca = array_values(array_filter((array) ($_GET['marca'] ?? []), 'is_string'));
$selCat   = array_values(array_filter((array) ($_GET['categoria'] ?? []), 'is_string'));
$selPlat  = array_values(array_filter((array) ($_GET['plataforma'] ?? []), 'is_string'));
$selDur   = array_values(array_filter((array) ($_GET['duracion'] ?? []), 'is_string'));
$soloOferta = !empty($_GET['oferta']);
$q    = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
$sort = in_array($_GET['orden'] ?? '', ['precio_asc', 'precio_desc', 'nombre'], true) ? $_GET['orden'] : 'nombre';
$legacyKey = strtolower(trim((string) ($_GET['cat'] ?? '')));
if (isset($legacy[$legacyKey]) && !$selMarca && !$selCat) {
    if ($legacy[$legacyKey][0] === 'cat') $selCat[] = $legacy[$legacyKey][1]; else $selMarca[] = $legacy[$legacyKey][1];
}

// Conteos por opción (sobre todo el catálogo activo)
$facets = ['marca' => [], 'categoria' => [], 'plataforma' => [], 'duracion' => []];
$nOferta = 0;
foreach ($all as $r) {
    $facets['marca'][$r['f']['brand']] = ($facets['marca'][$r['f']['brand']] ?? 0) + 1;
    $facets['categoria'][$r['f']['category']] = ($facets['categoria'][$r['f']['category']] ?? 0) + 1;
    foreach ($r['f']['platforms'] as $pl) $facets['plataforma'][$pl] = ($facets['plataforma'][$pl] ?? 0) + 1;
    if ($r['f']['duration'] !== '') $facets['duracion'][$r['f']['duration']] = ($facets['duracion'][$r['f']['duration']] ?? 0) + 1;
    if ($r['old_price'] && $r['old_price'] > $r['min_price']) $nOferta++;
}
foreach ($facets as $k => &$arr) { arsort($arr); } unset($arr);
if (isset($facets['marca']['Otros'])) { $o = $facets['marca']['Otros']; unset($facets['marca']['Otros']); $facets['marca']['Otros'] = $o; }

// Filtrado
$rows = array_values(array_filter($all, function ($r) use ($selMarca, $selCat, $selPlat, $selDur, $soloOferta, $q) {
    if ($selMarca && !in_array($r['f']['brand'], $selMarca, true)) return false;
    if ($selCat && !in_array($r['f']['category'], $selCat, true)) return false;
    if ($selPlat && !array_intersect($selPlat, $r['f']['platforms'])) return false;
    if ($selDur && !in_array($r['f']['duration'], $selDur, true)) return false;
    if ($soloOferta && !($r['old_price'] && $r['old_price'] > $r['min_price'])) return false;
    if ($q !== '' && mb_stripos($r['clean'] . ' ' . $r['f']['brand'], $q) === false) return false;
    return true;
}));
if ($sort === 'precio_asc')  usort($rows, fn($a, $b) => $a['min_price'] <=> $b['min_price']);
if ($sort === 'precio_desc') usort($rows, fn($a, $b) => $b['min_price'] <=> $a['min_price']);

$activeFilters = count($selMarca) + count($selCat) + count($selPlat) + count($selDur) + ($soloOferta ? 1 : 0);
$pageTitle = ($selCat && count($selCat) === 1 && !$selMarca) ? $selCat[0] : (($selMarca && count($selMarca) === 1 && !$selCat) ? $selMarca[0] : 'Catálogo');

function facet_group(string $title, string $name, array $opts, array $sel, bool $open = true, bool $search = false): void {
    if (!$opts) return; ?>
    <details class="fg" <?= $open || $sel ? 'open' : '' ?>>
      <summary><?= h($title) ?></summary>
      <?php if ($search): ?><input type="search" class="fg-search" placeholder="Buscar marca…" oninput="var v=this.value.toLowerCase();this.parentNode.querySelectorAll('.fo').forEach(function(l){l.style.display=l.textContent.toLowerCase().indexOf(v)>-1?'':'none'})"><?php endif; ?>
      <div class="fg-list">
        <?php foreach ($opts as $label => $n): ?>
          <label class="fo"><input type="checkbox" name="<?= h($name) ?>[]" value="<?= h($label) ?>" <?= in_array($label, $sel, true) ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= h($label) ?></span><em><?= (int) $n ?></em></label>
        <?php endforeach; ?>
      </div>
    </details>
<?php }

site_header($pageTitle, 'Licencias originales de software para Chile.');
?>
<style>
  .cat-layout { display: grid; grid-template-columns: 260px 1fr; gap: 28px; align-items: start; }
  .side { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); padding: 14px 16px; position: sticky; top: 14px; }
  .side-top { display: flex; justify-content: space-between; align-items: center; font-weight: 600; margin-bottom: 6px; }
  .side-top a { font-size: .8rem; font-weight: 500; color: var(--gold); text-decoration: none; }
  .fg { border-top: 1px solid var(--line); padding: 10px 0; }
  .fg summary { cursor: pointer; font-size: .78rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--text-dim); list-style: none; }
  .fg summary::-webkit-details-marker { display: none; }
  .fg-list { margin-top: 8px; max-height: 250px; overflow: auto; }
  .fg-search { width: 100%; margin-top: 8px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-family: var(--sans); font-size: .85rem; }
  .fo { display: flex; align-items: center; gap: 9px; padding: 5px 0; font-size: .88rem; cursor: pointer; }
  .fo span { flex: 1; }
  .fo em { font-style: normal; font-size: .78rem; color: var(--ash); }
  .fo input { accent-color: var(--gold); }
  .toggle { display: flex; align-items: center; gap: 10px; background: var(--surface-tint); border-radius: 10px; padding: 10px 12px; font-size: .88rem; font-weight: 500; cursor: pointer; margin-bottom: 8px; }
  .toggle span { flex: 1; } .toggle em { font-style: normal; color: var(--ash); font-size: .78rem; }
  .toggle input { accent-color: var(--gold); }
  .cat-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .cat-top select { padding: 9px 12px; border: 1px solid var(--line); border-radius: 8px; font-family: var(--sans); background: #fff; }
  .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 18px; }
  .pcard { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); overflow: hidden; display: flex; flex-direction: column; transition: border-color .15s, box-shadow .15s; }
  .pcard:hover { border-color: rgba(185,134,42,.55); box-shadow: 0 12px 30px rgba(28,26,20,.08); }
  .pcard a.pc-link { text-decoration: none; color: inherit; display: block; }
  .pc-body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 9px; flex: 1; }
  .brand-tag { align-self: flex-start; font-size: .68rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--teal); background: rgba(42,125,116,.1); padding: 3px 9px; border-radius: 6px; }
  .pc-title { font-size: .95rem; font-weight: 600; margin: 0; line-height: 1.3; min-height: 2.6em; color: var(--text); }
  .chips2 { display: flex; gap: 6px; flex-wrap: wrap; }
  .chips2 span { font-size: .72rem; border: 1px solid var(--line); border-radius: 20px; padding: 3px 10px; color: var(--text-dim); background: #fff; }
  .pc-price { margin-top: auto; display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
  .pc-price .from { font-size: .8rem; color: var(--ash); }
  .pc-price b { font-size: 1.3rem; }
  .pc-price s { font-size: .85rem; color: var(--ash); }
  .pc-btn { width: 100%; border: 0; border-radius: 9px; padding: 11px; font-weight: 600; font-size: .9rem; cursor: pointer; font-family: var(--sans); background: var(--gold); color: #FFFBF3; text-align: center; text-decoration: none; display: block; }
  .pc-btn.ghost { background: #fff; color: var(--text); border: 1px solid var(--line); }
  .pc-btn:hover { filter: brightness(1.05); }
  .side-toggle { display: none; }
  @media (max-width: 900px) {
    .cat-layout { grid-template-columns: 1fr; }
    .side { position: static; }
    .side-toggle { display: inline-block; margin-bottom: 12px; }
    .side.collapsed .side-body { display: none; }
  }
</style>
<div class="wrap page">
  <h1><?= h($pageTitle) ?></h1>
  <p class="lead"><?= count($rows) ?> producto<?= count($rows) === 1 ? '' : 's' ?><?= $q !== '' ? ' para “' . h($q) . '”' : '' ?></p>

  <form method="get" id="ff">
    <div class="cat-layout">
      <aside class="side <?= $activeFilters ? '' : 'collapsed' ?>" id="side">
        <div class="side-top"><span>Filtros<?= $activeFilters ? ' (' . $activeFilters . ')' : '' ?></span><?php if ($activeFilters || $q !== ''): ?><a href="catalogo.php">Limpiar</a><?php endif; ?></div>
        <div class="side-body">
          <?php if ($nOferta): ?><label class="toggle"><span>Solo en oferta</span><em><?= $nOferta ?></em><input type="checkbox" name="oferta" value="1" <?= $soloOferta ? 'checked' : '' ?> onchange="this.form.submit()"></label><?php endif; ?>
          <?php facet_group('Marca', 'marca', $facets['marca'], $selMarca, true, true); ?>
          <?php facet_group('Categorías', 'categoria', $facets['categoria'], $selCat); ?>
          <?php facet_group('Compatibilidad', 'plataforma', $facets['plataforma'], $selPlat, false); ?>
          <?php facet_group('Duración', 'duracion', $facets['duracion'], $selDur, false); ?>
          <noscript><button class="pc-btn" type="submit">Aplicar filtros</button></noscript>
          <p style="margin:12px 0 0;font-size:.85rem;">¿Eres empresa? <a href="https://api.whatsapp.com/send?phone=<?= h(SUPPORT_WHATSAPP) ?>&text=<?= rawurlencode('Hola, quiero cotizar licencias para mi empresa') ?>" style="color:var(--gold);font-weight:600;">Cotizar →</a></p>
        </div>
      </aside>

      <section>
        <button type="button" class="chip side-toggle" onclick="document.getElementById('side').classList.toggle('collapsed')">☰ Filtros</button>
        <div class="cat-top">
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar software, licencia, marca…" style="flex:1;min-width:220px;padding:11px 14px;border:1px solid var(--line);border-radius:8px;font-family:var(--sans);">
          <select name="orden" onchange="this.form.submit()" aria-label="Ordenar">
            <option value="nombre" <?= $sort === 'nombre' ? 'selected' : '' ?>>Ordenar: nombre</option>
            <option value="precio_asc" <?= $sort === 'precio_asc' ? 'selected' : '' ?>>Precio: menor a mayor</option>
            <option value="precio_desc" <?= $sort === 'precio_desc' ? 'selected' : '' ?>>Precio: mayor a menor</option>
          </select>
          <button class="btn-gold" type="submit" style="border:none;cursor:pointer;">Buscar</button>
        </div>

        <div class="cat-grid">
          <?php foreach ($rows as $o):
            $hasOld = $o['old_price'] && $o['old_price'] > $o['min_price']; ?>
          <div class="pcard">
            <a class="pc-link" href="producto.php?id=<?= (int) $o['id'] ?>">
              <div class="offer-image">
                <?php if ($hasOld): ?><span class="offer-badge disponible">Oferta</span><?php endif; ?>
                <?php if ($o['image_url']): ?><img src="<?= h($o['image_url']) ?>" alt="<?= h($o['clean']) ?>" loading="lazy"><?php else: ?><span class="placeholder-mark">sin imagen</span><?php endif; ?>
              </div>
            </a>
            <div class="pc-body">
              <span class="brand-tag"><?= h($o['f']['brand']) ?></span>
              <a class="pc-link" href="producto.php?id=<?= (int) $o['id'] ?>"><p class="pc-title"><?= h($o['clean']) ?></p></a>
              <?php if ($rv = (reviews_stats()[(int) $o['id']] ?? null)): ?><div class="rating-line small"><?= stars_html($rv['avg']) ?> <span>(<?= (int) $rv['n'] ?>)</span></div><?php endif; ?>
              <div class="chips2">
                <?php foreach ($o['f']['platforms'] as $pl): ?><span><?= h($pl) ?></span><?php endforeach; ?>
                <?php if ($o['f']['duration'] !== ''): ?><span><?= h($o['f']['duration']) ?></span><?php endif; ?>
              </div>
              <div class="pc-price">
                <?php if ((int) $o['nvar'] > 1): ?><span class="from">Desde</span><?php endif; ?>
                <b><?= h(money($o['min_price'])) ?></b>
                <?php if ($hasOld): ?><s><?= h(money($o['old_price'])) ?></s><?php endif; ?>
              </div>
              <?php if ((int) $o['nvar'] > 1): ?>
                <a class="pc-btn ghost" href="producto.php?id=<?= (int) $o['id'] ?>">Ver opciones</a>
              <?php else: ?>
                <button class="pc-btn" type="submit" formmethod="post" formaction="carrito.php" name="add" value="<?= (int) $o['first_variant'] ?>" onclick="addCart(this)">🛒 Agregar al carrito</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$rows): ?><p style="color:var(--ash);grid-column:1/-1;">No encontramos productos con esos filtros. <a href="catalogo.php">Limpiar filtros</a> o escríbenos por WhatsApp.</p><?php endif; ?>
        </div>
      </section>
    </div>
  </form>
</div>
<script>
  // "Agregar al carrito" dentro del formulario de filtros: se envía solo el producto elegido
  function addCart(btn) {
    var f = document.createElement('form'); f.method = 'post'; f.action = 'carrito.php';
    [['action', 'add'], ['variant_id', btn.value], ['quantity', '1']].forEach(function (p) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit();
    event.preventDefault(); return false;
  }
</script>
<?php site_footer();
