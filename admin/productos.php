<?php
/**
 * admin/productos.php — lista de productos: buscar, ver precio y stock, crear uno nuevo, editar.
 */
require_once __DIR__ . '/_auth.php';
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear') {
    csrf_check();
    $title = trim(mb_substr((string) ($_POST['title'] ?? ''), 0, 250));
    $price = (int) preg_replace('/\D/', '', (string) ($_POST['price'] ?? ''));
    if ($title === '' || $price <= 0) {
        flash_set('Escribe un nombre y un precio válido.', true);
    } else {
        try {
            $handle = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title))), '-') . '-' . substr((string) time(), -5);
            $localId = (int) (time() . random_int(10, 99)); // id propio por si la columna de Shopify no admite vacío
            $pid = 0;
            foreach ([null, $localId] as $sid) {
                try {
                    $pdo->prepare("INSERT INTO products (shopify_product_id, title, handle, description_html, vendor, product_type, status, image_url, created_at)
                                   VALUES (:sid, :t, :h, '', 'Alquimia Digital', :pt, 'borrador', NULL, NOW())")
                        ->execute([':sid' => $sid, ':t' => $title, ':h' => $handle, ':pt' => trim((string) ($_POST['product_type'] ?? ''))]);
                    $pid = (int) $pdo->lastInsertId(); break;
                } catch (PDOException $e) { $last = $e; }
            }
            if (!$pid) throw $last;
            foreach ([null, $localId + 1] as $vsid) {
                try {
                    $pdo->prepare("INSERT INTO product_variants (product_id, shopify_variant_id, title, sku, price, compare_at_price, created_at)
                                   VALUES (:p, :s, 'Default Title', NULL, :pr, NULL, NOW())")->execute([':p' => $pid, ':s' => $vsid, ':pr' => $price]);
                    break;
                } catch (PDOException $e) { $lastv = $e; if ($vsid !== null) throw $lastv; }
            }
            flash_set('Producto creado como borrador. Agrega imagen y descripción y luego cámbialo a "activo".');
            header('Location: producto.php?id=' . $pid); exit;
        } catch (Throwable $e) {
            error_log('[admin/productos] crear: ' . $e->getMessage());
            flash_set('No se pudo crear el producto: ' . $e->getMessage(), true);
        }
    }
    header('Location: productos.php'); exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$f = (string) ($_GET['f'] ?? 'activo');
$where = []; $args = [];
if ($f === 'activo') $where[] = "p.status = 'activo'";
elseif ($f === 'otros') $where[] = "p.status <> 'activo'";
if ($q !== '') { $where[] = 'p.title LIKE :q'; $args[':q'] = '%' . $q . '%'; }
$sql = "SELECT p.id, p.title, p.status, p.image_url, p.product_type,
            COUNT(pv.id) AS nvar, MIN(pv.price) AS pmin, MAX(pv.price) AS pmax,
            (SELECT COALESCE(SUM(lk.max_activations - (SELECT COUNT(*) FROM key_redemptions kr WHERE kr.license_key_id = lk.id)), 0)
               FROM license_keys lk JOIN product_variants v2 ON v2.id = lk.variant_id
              WHERE v2.product_id = p.id AND lk.status = 'disponible') AS stock
        FROM products p LEFT JOIN product_variants pv ON pv.product_id = p.id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " GROUP BY p.id ORDER BY p.title ASC LIMIT 300";
$st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll();

function adm_img(?string $u): string { return !$u ? '' : (preg_match('#^(https?:)?//#', $u) ? $u : '../' . ltrim($u, '/')); }

admin_header('Productos');
flash_show();
?>
<div class="box" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">
    <input type="hidden" name="f" value="<?= h($f) ?>">
    <input name="q" value="<?= h($q) ?>" placeholder="Buscar producto" style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;min-width:240px;">
    <button class="btn-a" type="submit">Buscar</button>
  </form>
  <div>
    <?php foreach (['activo' => 'Activos', 'otros' => 'Borradores / archivados', 'todos' => 'Todos'] as $k => $l): ?>
      <a class="btn-a <?= $f === $k ? '' : 'ghost' ?>" style="margin-left:6px;" href="?f=<?= h($k) ?>&q=<?= h(urlencode($q)) ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<table class="t">
  <tr><th style="width:56px;"></th><th>Producto</th><th>Estado</th><th>Precio</th><th>Opciones</th><th>Stock de claves</th><th></th></tr>
  <?php foreach ($rows as $p): $t = trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $p['title'])); ?>
  <tr>
    <td><?php if ($p['image_url']): ?><img src="<?= h(adm_img($p['image_url'])) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;"><?php endif; ?></td>
    <td><a href="producto.php?id=<?= (int) $p['id'] ?>"><b><?= h(mb_substr($t, 0, 90)) ?></b></a></td>
    <td><span class="pill <?= $p['status'] === 'activo' ? 'pagado' : 'pendiente' ?>"><?= h($p['status']) ?></span></td>
    <td><?= $p['pmin'] === null ? '—' : ($p['pmin'] == $p['pmax'] ? money($p['pmin']) : money($p['pmin']) . ' – ' . money($p['pmax'])) ?></td>
    <td><?= (int) $p['nvar'] ?></td>
    <td><?= (int) $p['stock'] ?></td>
    <td><a class="btn-a ghost" style="padding:6px 12px;font-size:.8rem;" href="producto.php?id=<?= (int) $p['id'] ?>">Editar</a></td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="tag">No hay productos en esta vista.</td></tr><?php endif; ?>
</table>

<div class="box" style="margin-top:22px;">
  <strong>Nuevo producto</strong>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="crear">
    <input name="title" placeholder="Nombre (ej. Duolingo Super 12 meses)" required style="padding:9px 10px;border:1px solid var(--line);border-radius:8px;min-width:300px;">
    <input name="price" placeholder="Precio en CLP" required inputmode="numeric" style="padding:9px 10px;border:1px solid var(--line);border-radius:8px;width:140px;">
    <input name="product_type" placeholder="Categoría (opcional)" style="padding:9px 10px;border:1px solid var(--line);border-radius:8px;min-width:200px;">
    <button class="btn-a" type="submit">Crear como borrador</button>
  </form>
</div>
<?php admin_footer();
