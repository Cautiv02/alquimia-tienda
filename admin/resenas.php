<?php
/** admin/resenas.php — importar y moderar reseñas. */
require_once __DIR__ . '/_auth.php';
set_exception_handler(function ($e) { // muestra el error real en vez de un 500 en blanco
    echo '<pre style="padding:20px;white-space:pre-wrap;">Error en Reseñas: ' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars(basename($e->getFile()) . ':' . $e->getLine()) . '</pre>';
});
require_once __DIR__ . '/../reviews-lib.php';
$pdo = get_pdo();
reviews_ensure_table($pdo);

$active = $pdo->query("SELECT id, title FROM products WHERE status = 'activo' ORDER BY title")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string) ($_POST['a'] ?? '');
    if ($a === 'import') {
        $f = $_FILES['json'] ?? null;
        $data = ($f && $f['error'] === UPLOAD_ERR_OK) ? json_decode((string) file_get_contents($f['tmp_name']), true) : null;
        $list = is_array($data) ? ($data['reviews'] ?? $data) : null;
        if (!is_array($list)) { flash_set('No pude leer el archivo. Sube trustoo-reviews-cache.json.', true); header('Location: resenas.php'); exit; }
        $ins = $pdo->prepare("INSERT IGNORE INTO reviews (product_id, product_title, author, rating, content, review_date, source, published, featured, dedupe, created_at)
                              VALUES (:pid, :pt, :au, :ra, :co, :dt, 'trustoo', 1, :fe, :dd, NOW())");
        $new = 0; $unm = 0; $skip = 0;
        foreach ($list as $r) {
            if (!is_array($r) || empty($r['author']) || empty($r['rating'])) { $skip++; continue; }
            $author = mb_substr(review_clean($r['author']), 0, 120);
            $content = review_clean($r['content'] ?? '');
            $rating = max(1, min(5, (int) $r['rating']));
            $ts = strtotime((string) ($r['date'] ?? '')) ?: time();
            $title = review_clean($r['product'] ?? '');
            $pid = review_match_product($active, $title);
            $ins->execute([':pid' => $pid, ':pt' => mb_substr($title, 0, 255), ':au' => $author, ':ra' => $rating, ':co' => $content,
                ':dt' => date('Y-m-d H:i:s', $ts), ':fe' => !empty($r['featured']) ? 1 : 0, ':dd' => sha1($author . '|' . date('Y-m-d', $ts) . '|' . $content)]);
            if ($ins->rowCount()) { $new++; if (!$pid) $unm++; }
        }
        flash_set("Importadas $new reseña(s) nuevas" . ($unm ? " ($unm sin producto asociado: asígnalas abajo)" : '') . ($skip ? ", $skip omitidas" : '') . '. Las ya existentes no se duplican.');
        header('Location: resenas.php'); exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($a === 'toggle')   $pdo->prepare("UPDATE reviews SET published = 1 - published WHERE id = ?")->execute([$id]);
    if ($a === 'feature')  $pdo->prepare("UPDATE reviews SET featured = 1 - featured WHERE id = ?")->execute([$id]);
    if ($a === 'delete')   $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);
    if ($a === 'assign')   $pdo->prepare("UPDATE reviews SET product_id = ? WHERE id = ?")->execute([((int) ($_POST['product_id'] ?? 0)) ?: null, $id]);
    flash_set('Listo.');
    header('Location: resenas.php?' . http_build_query(['f' => $_POST['f'] ?? '']));
    exit;
}

$f = (string) ($_GET['f'] ?? '');
$w = ['' => '1', 'sinproducto' => 'r.product_id IS NULL', 'ocultas' => 'r.published = 0', 'nuevas' => "r.source = 'cliente'"][$f] ?? '1';
$rows = $pdo->query("SELECT r.*, p.title AS ptitle FROM reviews r LEFT JOIN products p ON p.id = r.product_id WHERE $w ORDER BY r.review_date DESC LIMIT 300")->fetchAll();
$tot = $pdo->query("SELECT COUNT(*) n, SUM(published) pub, AVG(CASE WHEN published=1 THEN rating END) a, SUM(product_id IS NULL) sp FROM reviews")->fetch();

admin_header('Reseñas');
flash_show();
?>
<div class="box">
  <strong><?= (int) $tot['n'] ?></strong> reseñas · <?= (int) $tot['pub'] ?> publicadas · promedio <b><?= $tot['a'] ? number_format((float) $tot['a'], 2, ',', '') : '—' ?></b> · <?= (int) $tot['sp'] ?> sin producto asociado
</div>

<div class="box">
  <strong>Importar desde Trustoo</strong>
  <p class="tag">Sube el archivo <code>trustoo-reviews-cache.json</code>. Se asocian solas al producto de la tienda más parecido y no se duplican si repites la importación.</p>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="import">
    <input type="file" name="json" accept=".json,application/json" required> <button class="btn-a">Importar</button>
  </form>
</div>

<p>
  <?php foreach (['' => 'Todas', 'sinproducto' => 'Sin producto', 'ocultas' => 'Ocultas', 'nuevas' => 'De clientes (nuevas)'] as $k => $l): ?>
    <a class="btn-a <?= $f === $k ? '' : 'ghost' ?>" style="margin:0 6px 6px 0;" href="?f=<?= h($k) ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</p>

<table class="t">
  <tr><th>Fecha</th><th>Cliente</th><th>★</th><th>Reseña</th><th>Producto</th><th>Acciones</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr style="<?= $r['published'] ? '' : 'opacity:.55' ?>">
    <td class="tag"><?= h(date('d/m/Y', strtotime($r['review_date']))) ?><br><?= h($r['source']) ?></td>
    <td><?= h($r['author']) ?></td>
    <td><?= (int) $r['rating'] ?></td>
    <td style="max-width:340px;"><?= h(mb_substr($r['content'], 0, 220)) ?></td>
    <td>
      <form method="post" style="display:flex;gap:4px;"><?= csrf_field() ?><input type="hidden" name="a" value="assign"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>">
        <select name="product_id" style="max-width:220px;" onchange="this.form.submit()">
          <option value="0">— sin producto —</option>
          <?php foreach ($active as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $r['product_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= h(mb_substr(review_clean($p['title']), 0, 60)) ?></option><?php endforeach; ?>
        </select>
      </form>
      <?php if (!$r['product_id'] && $r['product_title']): ?><span class="tag">Original: <?= h($r['product_title']) ?></span><?php endif; ?>
    </td>
    <td style="white-space:nowrap;">
      <?php foreach ([['toggle', $r['published'] ? 'Ocultar' : 'Publicar'], ['feature', $r['featured'] ? '★ Quitar de portada' : '☆ A portada'], ['delete', 'Borrar']] as [$act, $lab]): ?>
      <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>">
        <button class="btn-a ghost" style="padding:4px 10px;font-size:.78rem;" <?= $act === 'delete' ? "onclick=\"return confirm('¿Borrar esta reseña?')\"" : '' ?>><?= h($lab) ?></button></form>
      <?php endforeach; ?>
    </td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="tag">No hay reseñas en esta vista.</td></tr><?php endif; ?>
</table>
<?php admin_footer();
