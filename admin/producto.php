<?php
/**
 * admin/producto.php?id=ID — editar un producto: datos, imagen, precios por opción y carga de claves (stock).
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();
$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM products WHERE id = :id"); $st->execute([':id' => $id]);
$p = $st->fetch();
if (!$p) { admin_header('Producto'); echo '<p>Producto no encontrado. <a href="productos.php">Volver</a></p>'; admin_footer(); exit; }

function money_in($v): ?int { $n = preg_replace('/\D/', '', (string) $v); return $n === '' ? null : (int) $n; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string) ($_POST['action'] ?? '');
    $msg = 'Guardado.'; $err = false;

    if ($a === 'datos') {
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 250));
        $status = in_array($_POST['status'] ?? '', ['activo', 'borrador', 'archivado', (string) $p['status']], true) ? $_POST['status'] : $p['status'];
        $imageUrl = $p['image_url'];
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['image'];
            $info = @getimagesize($f['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
            if (!$ext || $f['size'] > 4 * 1024 * 1024) { $err = true; $msg = 'La imagen debe ser JPG, PNG o WEBP de hasta 4 MB.'; }
            else {
                $dir = __DIR__ . '/../images/productos';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $name = 'p' . $id . '-' . time() . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $imageUrl = 'images/productos/' . $name; }
                else { $err = true; $msg = 'No se pudo guardar la imagen (revisa permisos de images/productos).'; }
            }
        } elseif (!empty($_POST['image_url_text'])) {
            $u = trim((string) $_POST['image_url_text']);
            if (preg_match('#^(https://|images/)#', $u)) $imageUrl = mb_substr($u, 0, 500);
        }
        if (!$err) {
            if ($title === '') { $err = true; $msg = 'El nombre no puede quedar vacío.'; }
            else {
                $pdo->prepare("UPDATE products SET title = :t, status = :s, product_type = :pt, description_html = :d, image_url = :i WHERE id = :id")
                    ->execute([':t' => $title, ':s' => $status, ':pt' => trim(mb_substr((string) ($_POST['product_type'] ?? ''), 0, 120)),
                               ':d' => (string) ($_POST['description_html'] ?? ''), ':i' => $imageUrl, ':id' => $id]);
            }
        }
    } elseif ($a === 'variantes') {
        $u = $pdo->prepare("UPDATE product_variants SET title = :t, price = :p, compare_at_price = :c WHERE id = :v AND product_id = :pid");
        foreach ((array) ($_POST['v'] ?? []) as $vid => $row) {
            $price = money_in($row['price'] ?? '');
            if (!$price) continue;
            $u->execute([':t' => trim(mb_substr((string) ($row['title'] ?? 'Default Title'), 0, 200)) ?: 'Default Title',
                         ':p' => $price, ':c' => money_in($row['compare'] ?? ''), ':v' => (int) $vid, ':pid' => $id]);
        }
        $msg = 'Precios guardados.';
    } elseif ($a === 'nueva_variante') {
        $price = money_in($_POST['price'] ?? '');
        $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 200));
        if (!$price || $title === '') { $err = true; $msg = 'Escribe el nombre de la opción y su precio.'; }
        else {
            try {
                $pdo->prepare("INSERT INTO product_variants (product_id, shopify_variant_id, title, sku, price, compare_at_price, created_at) VALUES (:p, NULL, :t, NULL, :pr, :c, NOW())")
                    ->execute([':p' => $id, ':t' => $title, ':pr' => $price, ':c' => money_in($_POST['compare'] ?? '')]);
            } catch (PDOException $e) {
                $pdo->prepare("INSERT INTO product_variants (product_id, shopify_variant_id, title, sku, price, compare_at_price, created_at) VALUES (:p, :s, :t, NULL, :pr, :c, NOW())")
                    ->execute([':p' => $id, ':s' => (int) (time() . random_int(10, 99)), ':t' => $title, ':pr' => $price, ':c' => money_in($_POST['compare'] ?? '')]);
            }
            $msg = 'Opción agregada.';
        }
    } elseif ($a === 'claves') {
        $vid = (int) ($_POST['variant_id'] ?? 0);
        $max = max(1, (int) ($_POST['max_activations'] ?? 1));
        $ok = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE id = :v AND product_id = :p");
        $ok->execute([':v' => $vid, ':p' => $id]);
        $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['keys'] ?? ''))))));
        if (!$ok->fetchColumn() || !$keys) { $err = true; $msg = 'Elige una opción y pega al menos una clave.'; }
        else {
            $ins = $pdo->prepare("INSERT INTO license_keys (variant_id, key_value, max_activations, status, created_at) VALUES (:v, :k, :m, 'disponible', NOW())");
            foreach ($keys as $k) $ins->execute([':v' => $vid, ':k' => mb_substr($k, 0, 500), ':m' => $max]);
            $msg = count($keys) . ' clave(s) cargada(s).';
        }
    }
    flash_set($msg, $err);
    header('Location: producto.php?id=' . $id); exit;
}

$vs = $pdo->prepare("SELECT pv.*, (SELECT COALESCE(SUM(lk.max_activations - (SELECT COUNT(*) FROM key_redemptions kr WHERE kr.license_key_id = lk.id)), 0)
      FROM license_keys lk WHERE lk.variant_id = pv.id AND lk.status = 'disponible') AS stock
      FROM product_variants pv WHERE pv.product_id = :id ORDER BY pv.price ASC, pv.id ASC");
$vs->execute([':id' => $id]); $variants = $vs->fetchAll();
$imgSrc = $p['image_url'] ? (preg_match('#^(https?:)?//#', $p['image_url']) ? $p['image_url'] : '../' . ltrim($p['image_url'], '/')) : '';
$clean = trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $p['title']));
$in = 'padding:9px 12px;border:1px solid var(--line);border-radius:8px;width:100%;font-family:var(--sans);font-size:.92rem;';

admin_header('Editar producto');
flash_show();
?>
<p class="tag"><a href="productos.php">← Productos</a> · <a href="../producto.php?id=<?= $id ?>" target="_blank">Ver en la tienda ↗</a></p>
<h2 style="font-family:var(--serif);margin:0 0 16px;"><?= h($clean) ?></h2>

<form method="post" enctype="multipart/form-data" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="datos">
  <strong>Datos del producto</strong>
  <div style="display:grid;grid-template-columns:1fr 180px;gap:12px;margin-top:12px;">
    <label class="tag">Nombre<br><input name="title" value="<?= h($clean) ?>" style="<?= $in ?>" required></label>
    <label class="tag">Estado<br><select name="status" style="<?= $in ?>">
      <?php foreach (array_unique(['activo', 'borrador', 'archivado', (string) $p['status']]) as $s): ?><option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
    </select></label>
  </div>
  <label class="tag" style="display:block;margin-top:12px;">Categoría<br><input name="product_type" value="<?= h($p['product_type']) ?>" style="<?= $in ?>"></label>
  <div style="display:flex;gap:16px;align-items:flex-start;margin-top:12px;flex-wrap:wrap;">
    <?php if ($imgSrc): ?><img src="<?= h($imgSrc) ?>" alt="" style="width:120px;height:90px;object-fit:cover;border-radius:8px;"><?php endif; ?>
    <div style="flex:1;min-width:240px;">
      <label class="tag">Cambiar imagen (JPG, PNG o WEBP, máx. 4 MB)<br><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
      <label class="tag" style="display:block;margin-top:8px;">…o dirección de una imagen<br><input name="image_url_text" value="" placeholder="<?= h($p['image_url']) ?>" style="<?= $in ?>"></label>
    </div>
  </div>
  <label class="tag" style="display:block;margin-top:12px;">Descripción (acepta HTML básico: p, br, ul, li, strong)<br>
    <textarea name="description_html" rows="7" style="<?= $in ?>font-family:var(--mono);"><?= h($p['description_html']) ?></textarea></label>
  <p style="margin:14px 0 0;"><button class="btn-a" type="submit">Guardar datos</button></p>
</form>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="variantes">
  <strong>Precios y opciones</strong>
  <table class="t" style="margin-top:12px;">
    <tr><th>Opción</th><th>Precio (CLP)</th><th>Precio anterior (tachado)</th><th>Stock de claves</th></tr>
    <?php foreach ($variants as $v): ?>
    <tr>
      <td><input name="v[<?= (int) $v['id'] ?>][title]" value="<?= h($v['title']) ?>" style="<?= $in ?>"></td>
      <td><input name="v[<?= (int) $v['id'] ?>][price]" value="<?= (int) round((float) $v['price']) ?>" inputmode="numeric" style="<?= $in ?>width:130px;"></td>
      <td><input name="v[<?= (int) $v['id'] ?>][compare]" value="<?= $v['compare_at_price'] ? (int) round((float) $v['compare_at_price']) : '' ?>" inputmode="numeric" style="<?= $in ?>width:130px;"></td>
      <td><?= (int) $v['stock'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin:14px 0 0;"><button class="btn-a" type="submit">Guardar precios</button>
    <span class="tag">"Default Title" significa que el producto tiene una sola opción.</span></p>
</form>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="nueva_variante">
  <strong>Agregar otra opción</strong>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
    <input name="title" placeholder="Nombre (ej. 2 dispositivos)" required style="<?= $in ?>max-width:260px;">
    <input name="price" placeholder="Precio" required inputmode="numeric" style="<?= $in ?>width:130px;">
    <input name="compare" placeholder="Precio anterior" inputmode="numeric" style="<?= $in ?>width:140px;">
    <button class="btn-a" type="submit">Agregar</button>
  </div>
</form>

<form method="post" class="box"><?= csrf_field() ?><input type="hidden" name="action" value="claves">
  <strong>Cargar claves (stock)</strong>
  <p class="tag" style="margin:6px 0 10px;">Opcional. Hoy entregas a mano, así que la tienda vende aunque no haya claves cargadas. Sirve para llevar el control o para volver a la entrega automática.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <select name="variant_id" style="<?= $in ?>max-width:280px;">
      <?php foreach ($variants as $v): ?><option value="<?= (int) $v['id'] ?>"><?= h($v['title']) ?></option><?php endforeach; ?>
    </select>
    <label class="tag">Usos por clave <input name="max_activations" value="1" inputmode="numeric" style="<?= $in ?>width:70px;"></label>
  </div>
  <textarea name="keys" rows="5" placeholder="Una clave por línea" style="<?= $in ?>margin-top:10px;font-family:var(--mono);"></textarea>
  <p style="margin:12px 0 0;"><button class="btn-a" type="submit">Cargar claves</button></p>
</form>
<?php admin_footer();
