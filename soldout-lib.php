<?php
/** Productos agotados: no se pueden comprar. Lee products.sold_out y los marcados "Agotado" en la portada. */
function soldout_ensure_column(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE products ADD COLUMN sold_out TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ya existe */ }
}
/** @return array<int,true> ids de producto agotados (nunca lanza excepción) */
function soldout_ids(?PDO $pdo = null): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try { $pdo = $pdo ?: get_pdo(); } catch (Throwable $e) { return $cache; }
    try { foreach ($pdo->query("SELECT id FROM products WHERE sold_out = 1")->fetchAll(PDO::FETCH_COLUMN) as $i) $cache[(int) $i] = true; } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT DISTINCT product_id FROM home_section_items WHERE sold_out = 1")->fetchAll(PDO::FETCH_COLUMN) as $i) $cache[(int) $i] = true; } catch (Throwable $e) {}
    return $cache;
}
function is_soldout(int $productId): bool { return isset(soldout_ids()[$productId]); }
/** Fija o quita el estado agotado de un producto (también en sus lugares de la portada). */
function soldout_set(PDO $pdo, int $productId, bool $on): void {
    soldout_ensure_column($pdo);
    $pdo->prepare("UPDATE products SET sold_out = :x WHERE id = :id")->execute([':x' => $on ? 1 : 0, ':id' => $productId]);
    try { $pdo->prepare("UPDATE home_section_items SET sold_out = :x WHERE product_id = :id")->execute([':x' => $on ? 1 : 0, ':id' => $productId]); } catch (Throwable $e) {}
}

/** Stock manual por opción (product_variants.stock_manual; NULL = sin límite). @return array<int,int> */
function variant_stock_map(): array {
    static $m = null; if ($m !== null) return $m; $m = [];
    try { foreach (get_pdo()->query("SELECT id, stock_manual FROM product_variants WHERE stock_manual IS NOT NULL")->fetchAll() as $r) $m[(int) $r['id']] = (int) $r['stock_manual']; } catch (Throwable $e) {}
    return $m;
}
function variant_stock_ensure(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE product_variants ADD COLUMN stock_manual INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
}
