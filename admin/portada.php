<?php
/**
 * admin/portada.php — editar los bloques de productos de la portada:
 * cambiar títulos, ordenar bloques y productos, agregar/quitar productos, marcar agotado, ocultar bloques.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../home-lib.php';
$pdo = get_pdo();
home_ensure_tables($pdo);

/** Renumera sort_order de una lista de ids y mueve uno arriba/abajo. */
function move_in_list(PDO $pdo, string $table, string $where, array $args, int $id, string $dir): void {
    $st = $pdo->prepare("SELECT id FROM $table WHERE $where ORDER BY sort_order, id");
    $st->execute($args);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search($id, $ids, true);
    if ($i === false) return;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $u = $pdo->prepare("UPDATE $table SET sort_order = :o WHERE id = :id");
    foreach ($ids as $k => $x) $u->execute([':o' => $k, ':id' => $x]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string) ($_POST['action'] ?? '');
    $sid = (int) ($_POST['section_id'] ?? 0);
    $iid = (int) ($_POST['item_id'] ?? 0);
    $msg = 'Listo.';
    if ($a === 'save_section') {
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 120));
        $label = trim(mb_substr((string) ($_POST['link_label'] ?? ''), 0, 120));
        $url   = trim(mb_substr((string) ($_POST['link_url'] ?? ''), 0, 255));
        if ($title === '') { flash_set('El título no puede quedar vacío.', true); header('Location: portada.php'); exit; }
        $pdo->prepare("UPDATE home_sections SET title = :t, link_label = :l, link_url = :u WHERE id = :id")
            ->execute([':t' => $title, ':l' => $label ?: 'Ver todo el catálogo', ':u' => $url ?: 'catalogo.php', ':id' => $sid]);
        $msg = 'Bloque guardado.';
    } elseif ($a === 'move_section') {
        move_in_list($pdo, 'home_sections', '1=1', [], $sid, (string) ($_POST['dir'] ?? ''));
    } elseif ($a === 'toggle_section') {
        $pdo->prepare("UPDATE home_sections SET active = 1 - active WHERE id = :id")->execute([':id' => $sid]);
    } elseif ($a === 'delete_section') {
        $pdo->prepare("DELETE FROM home_section_items WHERE section_id = :id")->execute([':id' => $sid]);
        $pdo->prepare("DELETE FROM home_sections WHERE id = :id")->execute([':id' => $sid]);
        $msg = 'Bloque eliminado.'; $sid = 0;
    } elseif ($a === 'add_section') {
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 120));
        if ($title !== '') {
            $n = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM home_sections")->fetchColumn();
            $pdo->prepare("INSERT INTO home_sections (slug, title, sort_order) VALUES ('', :t, :o)")->execute([':t' => $title, ':o' => $n]);
            $sid = (int) $pdo->lastInsertId(); $msg = 'Bloque creado. Agrega productos abajo.';
        }
    } elseif ($a === 'add_item') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        if ($pid > 0 && $sid > 0) {
            $n = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM home_section_items WHERE section_id = :s");
            $n->execute([':s' => $sid]);
            $pdo->prepare("INSERT INTO home_section_items (section_id, product_id, sort_order) VALUES (:s, :p, :o)")
                ->execute([':s' => $sid, ':p' => $pid, ':o' => (int) $n->fetchColumn()]);
            $msg = 'Producto agregado al final del bloque.';
        }
    } elseif ($a === 'remove_item') {
        $pdo->prepare("DELETE FROM home_section_items WHERE id = :id")->execute([':id' => $iid]);
        $msg = 'Producto quitado del bloque.';
    } elseif ($a === 'move_item') {
        move_in_list($pdo, 'home_section_items', 'section_id = :s', [':s' => $sid], $iid, (string) ($_POST['dir'] ?? ''));
    } elseif ($a === 'reorder_items') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['order'] ?? []))));
        $u = $pdo->prepare("UPDATE home_section_items SET sort_order = :o WHERE id = :id AND section_id = :s");
        foreach ($ids as $k => $x) $u->execute([':o' => $k, ':id' => $x, ':s' => $sid]);
        echo 'ok'; exit;
    } elseif ($a === 'set_badge') {
        $b = (string) ($_POST['badge'] ?? '');
        $soldOut = $b === 'agotado' ? 1 : 0;
        if (!$soldOut && !isset(home_badges()[$b])) $b = '';
        if ($soldOut) $b = '';
        $pdo->prepare("UPDATE home_section_items SET sold_out = :x, badge = :b WHERE id = :id")->execute([':x' => $soldOut, ':b' => $b, ':id' => $iid]);
        $msg = 'Etiqueta actualizada.';
    } elseif ($a === 'toggle_soldout') {
        $pdo->prepare("UPDATE home_section_items SET sold_out = 1 - sold_out WHERE id = :id")->execute([':id' => $iid]);
    } elseif ($a === 'reset') {
        $pdo->exec("DELETE FROM home_section_items");
        $pdo->exec("DELETE FROM home_sections");
        home_seed_defaults($pdo);
        $msg = 'Portada restablecida a la configuración inicial.'; $sid = 0;
    }
    flash_set($msg);
    header('Location: portada.php' . ($sid ? '#b' . $sid : ''));
    exit;
}

$products = home_products($pdo);
uasort($products, function ($x, $y) { return strcasecmp($x['clean_title'], $y['clean_title']); });
$secs = $pdo->query("SELECT * FROM home_sections ORDER BY sort_order, id")->fetchAll();
$itemsQ = $pdo->prepare("SELECT * FROM home_section_items WHERE section_id = :s ORDER BY sort_order, id");

function mini(string $action, array $f, string $label, string $confirm = '', string $cls = 'btn-a ghost'): void {
    echo '<form method="post" style="display:inline;margin:0 3px 0 0;">' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($f as $k => $v) echo '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    echo '<button class="' . $cls . '" style="padding:5px 10px;font-size:.8rem;" type="submit"' . ($confirm ? ' onclick="return confirm(\'' . h($confirm) . '\')"' : '') . '>' . $label . '</button></form>';
}

admin_header('Portada');
flash_show();
?>
<h2 style="font-family:var(--serif);margin:0 0 6px;">Portada de la tienda</h2>
<p class="tag" style="margin:0 0 18px;">Arrastra ⋮⋮ para ordenar los productos de cada bloque, y escribe en el buscador para agregar más. Los cambios se ven al recargar la tienda. <a href="../index.php" target="_blank">Ver portada ↗</a></p>

<?php foreach ($secs as $idx => $s):
    $itemsQ->execute([':s' => $s['id']]); $items = $itemsQ->fetchAll(); ?>
<div class="box" id="b<?= (int) $s['id'] ?>" style="<?= $s['active'] ? '' : 'opacity:.6;' ?>">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_section"><input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">
    <label class="tag">Título<br><input name="title" value="<?= h($s['title']) ?>" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;min-width:220px;"></label>
    <label class="tag">Texto del enlace<br><input name="link_label" value="<?= h($s['link_label']) ?>" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;min-width:200px;"></label>
    <label class="tag">Enlace<br><input name="link_url" value="<?= h($s['link_url']) ?>" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;min-width:180px;"></label>
    <button class="btn-a" type="submit" style="padding:9px 14px;">Guardar</button>
  </form>
  <div style="margin-bottom:12px;">
    <?php mini('move_section', ['section_id' => $s['id'], 'dir' => 'up'], '↑ Subir bloque'); ?>
    <?php mini('move_section', ['section_id' => $s['id'], 'dir' => 'down'], '↓ Bajar bloque'); ?>
    <?php mini('toggle_section', ['section_id' => $s['id']], $s['active'] ? 'Ocultar bloque' : 'Mostrar bloque'); ?>
    <?php mini('delete_section', ['section_id' => $s['id']], 'Eliminar bloque', '¿Eliminar este bloque completo?'); ?>
    <?php if (!$s['active']): ?><span class="tag">(oculto en la portada)</span><?php endif; ?>
  </div>

  <table class="t sortable" data-section="<?= (int) $s['id'] ?>">
    <?php foreach ($items as $it): $p = $products[(int) $it['product_id']] ?? null; ?>
    <tr draggable="true" data-id="<?= (int) $it['id'] ?>">
      <td class="grip" title="Arrastra para ordenar">⋮⋮</td>
      <td><?= $p ? h(mb_substr($p['clean_title'], 0, 80)) : '<i class="tag">Producto no disponible (no se muestra)</i>' ?>
        <?php if ($it['sold_out']): ?><span class="pill anulado">agotado</span><?php elseif (!empty($it['badge'])): ?><span class="pill"><?= h(home_badges()[$it['badge']] ?? $it['badge']) ?></span><?php endif; ?></td>
      <td style="white-space:nowrap;"><?= $p ? (($p['nvar'] > 1 ? 'Desde ' : '') . money($p['price'])) : '' ?></td>
      <td style="white-space:nowrap;text-align:right;">
        <?php mini('move_item', ['section_id' => $s['id'], 'item_id' => $it['id'], 'dir' => 'up'], '↑'); ?>
        <?php mini('move_item', ['section_id' => $s['id'], 'item_id' => $it['id'], 'dir' => 'down'], '↓'); ?>
        <form method="post" style="display:inline;margin:0 3px 0 0;"><?= csrf_field() ?><input type="hidden" name="action" value="set_badge"><input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
          <select name="badge" onchange="this.form.submit()" style="padding:5px 8px;border:1px solid var(--line);border-radius:8px;font-size:.8rem;">
            <?php $cur = $it['sold_out'] ? 'agotado' : (string) ($it['badge'] ?? ''); ?>
            <option value="">Etiqueta: automática</option>
            <?php foreach (home_badges() as $k => $l): ?><option value="<?= h($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
            <option value="agotado" <?= $cur === 'agotado' ? 'selected' : '' ?>>Agotado</option>
          </select></form>
        <?php mini('remove_item', ['section_id' => $s['id'], 'item_id' => $it['id']], 'Quitar', '¿Quitar este producto del bloque?'); ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="4" class="tag">Este bloque no tiene productos.</td></tr><?php endif; ?>
  </table>

  <div class="picker" data-section="<?= (int) $s['id'] ?>" data-has="<?= h(implode(',', array_map(fn($i) => (int) $i['product_id'], $items))) ?>">
    <input type="search" placeholder="🔍 Escribe para buscar un producto y agregarlo al final del bloque…" autocomplete="off">
    <ul class="pk-list" hidden></ul>
  </div>
</div>
<?php endforeach; ?>

<div class="box">
  <strong>Nuevo bloque</strong>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_section">
    <input name="title" placeholder="Título del bloque" required style="padding:9px 10px;border:1px solid var(--line);border-radius:8px;min-width:260px;">
    <button class="btn-a" type="submit">Crear bloque</button>
  </form>
  <p style="margin:18px 0 0;"><?php mini('reset', [], 'Restablecer la portada a la configuración inicial', 'Se perderán todos tus cambios en la portada. ¿Continuar?'); ?></p>
</div>
<form method="post" id="addf" style="display:none;"><?= csrf_field() ?><input type="hidden" name="action" value="add_item"><input type="hidden" name="section_id" id="addf-s"><input type="hidden" name="product_id" id="addf-p"></form>
<style>
  .grip { width: 22px; color: #9a9384; cursor: grab; user-select: none; font-size: 1.1rem; }
  tr.dragging { opacity: .35; } tr.over-top { box-shadow: 0 -3px 0 var(--gold) inset; } tr.over-bot { box-shadow: 0 3px 0 var(--gold) inset; }
  .picker { position: relative; margin-top: 12px; }
  .picker input { width: 100%; padding: 11px 14px; border: 1px solid var(--line); border-radius: 8px; font-size: .95rem; }
  .pk-list { position: absolute; z-index: 20; left: 0; right: 0; margin: 4px 0 0; padding: 4px; list-style: none; background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,.14); max-height: 340px; overflow: auto; }
  .pk-list li { display: flex; justify-content: space-between; gap: 12px; padding: 9px 12px; border-radius: 8px; cursor: pointer; font-size: .9rem; }
  .pk-list li:hover, .pk-list li.on { background: #f3eee2; } .pk-list li.has { opacity: .45; cursor: default; }
  .pk-list li b { white-space: nowrap; }
</style>
<script>
(function () {
  var PRODUCTS = <?= json_encode(array_values(array_map(function ($p) { return ['id' => (int) $p['product_id'], 't' => $p['clean_title'], 'p' => money($p['price'])]; }, $products)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  function norm(x) { return x.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
  PRODUCTS.forEach(function (p) { p.n = norm(p.t); });
  var token = document.querySelector('#addf input[name=csrf]').value;

  // --- buscador para agregar ---
  document.querySelectorAll('.picker').forEach(function (pk) {
    var inp = pk.querySelector('input'), ul = pk.querySelector('.pk-list'), has = pk.dataset.has.split(',').map(Number), idx = -1;
    function fill() {
      var ws = norm(inp.value).split(' ').filter(Boolean); ul.innerHTML = ''; idx = -1;
      if (!ws.length) { ul.hidden = true; return; }
      var res = PRODUCTS.filter(function (p) { return ws.every(function (w) { return p.n.indexOf(w) > -1; }); }).slice(0, 15);
      res.forEach(function (p) {
        var li = document.createElement('li'), a = document.createElement('span'), b = document.createElement('b');
        a.textContent = p.t; b.textContent = p.p; li.appendChild(a); li.appendChild(b); li.dataset.id = p.id;
        if (has.indexOf(p.id) > -1) { li.classList.add('has'); a.textContent += '  (ya está en este bloque)'; }
        li.addEventListener('click', function () { if (li.classList.contains('has')) return; document.getElementById('addf-s').value = pk.dataset.section; document.getElementById('addf-p').value = p.id; document.getElementById('addf').submit(); });
        ul.appendChild(li);
      });
      if (!res.length) { var e = document.createElement('li'); e.textContent = 'Sin resultados'; e.className = 'has'; ul.appendChild(e); }
      ul.hidden = false;
    }
    inp.addEventListener('input', fill);
    inp.addEventListener('keydown', function (e) {
      var n = ul.children.length; if (ul.hidden || !n) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); idx = (idx + 1) % n; } else if (e.key === 'ArrowUp') { e.preventDefault(); idx = (idx - 1 + n) % n; }
      else if (e.key === 'Enter') { e.preventDefault(); (ul.children[idx < 0 ? 0 : idx]).click(); return; } else if (e.key === 'Escape') { ul.hidden = true; return; } else return;
      [].forEach.call(ul.children, function (li, i) { li.classList.toggle('on', i === idx); });
    });
    document.addEventListener('click', function (e) { if (!pk.contains(e.target)) ul.hidden = true; });
  });

  // --- arrastrar para ordenar ---
  document.querySelectorAll('table.sortable').forEach(function (tb) {
    var drag = null;
    function clear() { tb.querySelectorAll('tr').forEach(function (r) { r.classList.remove('over-top', 'over-bot'); }); }
    tb.addEventListener('dragstart', function (e) { drag = e.target.closest('tr[data-id]'); if (!drag) return; drag.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', drag.dataset.id); } catch (x) {} });
    tb.addEventListener('dragover', function (e) {
      var r = e.target.closest('tr[data-id]'); if (!drag || !r || r === drag) return; e.preventDefault(); clear();
      var after = e.clientY > r.getBoundingClientRect().top + r.offsetHeight / 2; r.classList.add(after ? 'over-bot' : 'over-top');
      r.dataset.after = after ? '1' : '';
    });
    tb.addEventListener('drop', function (e) {
      var r = e.target.closest('tr[data-id]'); if (!drag || !r || r === drag) return; e.preventDefault();
      if (r.dataset.after) r.after(drag); else r.before(drag); save();
    });
    tb.addEventListener('dragend', function () { if (drag) drag.classList.remove('dragging'); clear(); drag = null; });
    function save() {
      var fd = new FormData(); fd.append('csrf', token); fd.append('action', 'reorder_items'); fd.append('section_id', tb.dataset.section);
      tb.querySelectorAll('tr[data-id]').forEach(function (r) { fd.append('order[]', r.dataset.id); });
      fetch('portada.php', { method: 'POST', body: fd }).then(function (r) { return r.text(); }).then(function (t) {
        var m = document.createElement('div'); m.textContent = t === 'ok' ? '✓ Orden guardado' : 'No se pudo guardar el orden'; m.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#1b5e33;color:#fff;padding:10px 16px;border-radius:8px;z-index:99;'; if (t !== 'ok') m.style.background = '#a02020';
        document.body.appendChild(m); setTimeout(function () { m.remove(); }, 1800);
      });
    }
  });
})();
</script>
<?php admin_footer();
