<?php
/**
 * reviews-lib.php — reseñas de clientes: tabla, asociación a productos, promedios y estrellas.
 * Las reseñas se importan desde el panel (admin/resenas.php) o llegan por el enlace del correo posterior al envío (resena.php).
 * Nunca se inventan datos: los promedios salen solo de reseñas publicadas.
 */
function reviews_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NULL,
        product_title VARCHAR(255) NOT NULL DEFAULT '',
        author VARCHAR(120) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        review_date DATETIME NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'trustoo',
        published TINYINT(1) NOT NULL DEFAULT 1,
        featured TINYINT(1) NOT NULL DEFAULT 0,
        order_id INT UNSIGNED NULL,
        dedupe CHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_dedupe (dedupe),
        KEY (product_id, published), KEY (published, review_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function review_clean($t): string {
    return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $t)));
}

/** Palabras significativas de un título, para comparar nombres de producto que cambiaron de oferta (CyberDays, Oferta Flash…). */
function review_tokens(string $title): array {
    $t = mb_strtolower(review_clean($title));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    $t = preg_replace('/\d+\s*%/', ' ', $t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $stop = ['de','para','original','cuenta','suscripcion','oferta','flash','descuento','cyberdays','cyber','blackdays','navidad','exclusiva','barato','en','alquimia','digital','el','la','y','con','sin','reinstalable'];
    $out = [];
    foreach (explode(' ', $t) as $w) if ($w !== '' && !in_array($w, $stop, true)) $out[$w] = true;
    return array_keys($out);
}

/** Devuelve el id del producto activo más parecido al título de la reseña, o null si no hay uno suficientemente claro. */
function review_match_product(array $activeProducts, string $title, float $min = 0.6): ?int {
    $a = review_tokens($title);
    if (!$a) return null;
    $best = null; $bestScore = 0.0;
    foreach ($activeProducts as $p) {
        $b = review_tokens($p['title']);
        if (!$b) continue;
        $inter = count(array_intersect($a, $b)); $union = count(array_unique(array_merge($a, $b)));
        $score = $union ? $inter / $union : 0;
        if ($score > $bestScore) { $bestScore = $score; $best = (int) $p['id']; }
    }
    return $bestScore >= $min ? $best : null;
}

/** [product_id => ['n' => cantidad, 'avg' => promedio]] solo con reseñas publicadas. Silencioso si la tabla no existe. */
function reviews_stats(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        if (!function_exists('get_pdo')) return $cache;
        foreach (get_pdo()->query("SELECT product_id, COUNT(*) n, AVG(rating) a FROM reviews WHERE published = 1 AND product_id IS NOT NULL GROUP BY product_id")->fetchAll() as $r) {
            $cache[(int) $r['product_id']] = ['n' => (int) $r['n'], 'avg' => (float) $r['a']];
        }
    } catch (Throwable $e) { }
    return $cache;
}

function reviews_store_summary(): array {
    try {
        $r = get_pdo()->query("SELECT COUNT(*) n, AVG(rating) a FROM reviews WHERE published = 1")->fetch();
        return ['n' => (int) $r['n'], 'avg' => (float) $r['a']];
    } catch (Throwable $e) { return ['n' => 0, 'avg' => 0.0]; }
}

function stars_html(float $avg): string {
    $p = max(0, min(100, round($avg / 5 * 100)));
    return '<span class="stars" style="--p:' . $p . '%" aria-label="' . h(number_format($avg, 1, ',', '')) . ' de 5">★★★★★</span>';
}

function review_token(int $orderId): string {
    $secret = (defined('REVIEW_SECRET') ? REVIEW_SECRET : (defined('DB_PASS') ? DB_PASS : 'alq')) . '|reviews';
    return substr(hash_hmac('sha256', 'order:' . $orderId, $secret), 0, 20);
}
function review_link(int $orderId): string {
    return SITE_URL . '/resena.php?o=' . $orderId . '&k=' . review_token($orderId);
}
