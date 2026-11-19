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
<p class="tag" style="margin:0 0 18px;">Edita los bloques de productos que se ven al entrar al sitio. Los cambios se ven al recargar la tienda. <a href="../index.php" target="_blank">Ver portada ↗</a></p>

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

  <table class="t">
    <?php foreach ($items as $it): $p = $products[(int) $it['product_id']] ?? null; ?>
    <tr>
      <td><?= $p ? h(mb_substr($p['clean_title'], 0, 80)) : '<i class="tag">Producto no disponible (no se muestra)</i>' ?>
        <?php if ($it['sold_out']): ?><span class="pill anulado">agotado</span><?php endif; ?></td>
      <td style="white-space:nowrap;"><?= $p ? (($p['nvar'] > 1 ? 'Desde ' : '') . money($p['price'])) : '' ?></td>
      <td style="white-space:nowrap;text-align:right;">
        <?php mini('move_item', ['section_id' => $s['id'], 'item_id' => $it['id'], 'dir' => 'up'], '↑'); ?>
        <?php mini('move_item', ['section_id' => $s['id'], 'item_id' => $it['id'], 'dir' => 'down'], '↓'); ?>
        <?php mini('toggle_soldout', ['section_id' => $s['id'], 'item_id' => $it['id']], $it['sold_out'] ? 'Quitar agotado' : 'Marcar agotado'); ?>
        <?php mini('remove_item', ['section_id' => $s['id'], 'item_id' => $it['id']], 'Quitar', '¿Quitar este producto del bloque?'); ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="3" class="tag">Este bloque no tiene productos.</td></tr><?php endif; ?>
  </table>

  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_item"><input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">
    <select name="product_id" required style="padding:9px 10px;border:1px solid var(--line);border-radius:8px;max-width:100%;min-width:300px;">
      <option value="">Agregar producto…</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['product_id'] ?>"><?= h(mb_substr($p['clean_title'], 0, 90)) ?> — <?= h(money($p['price'])) ?></option><?php endforeach; ?>
    </select>
    <button class="btn-a" type="submit" style="padding:9px 14px;">+ Agregar</button>
  </form>
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
<?php admin_footer();
