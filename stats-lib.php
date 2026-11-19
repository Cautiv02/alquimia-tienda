<?php
/**
 * stats-lib.php — estadísticas propias de visitas (sin servicios externos, sin guardar IP ni datos personales).
 * Guarda un identificador anónimo en una cookie (alq_v) y un evento por página vista en la tabla site_events.
 * Nunca debe romper una página: todo va dentro de try/catch.
 */
function stats_source(): string {
    $utm = isset($_GET['utm_source']) ? strtolower(trim((string) $_GET['utm_source'])) : '';
    if ($utm !== '') return mb_substr(preg_replace('/[^a-z0-9._ -]/', '', $utm), 0, 40) ?: 'campaña';
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return 'directo';
    $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
    if ($host === '') return 'directo';
    if ($host === strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) || strpos($host, 'mercadopago') !== false || strpos($host, 'mercadolibre') !== false) return 'interno';
    $map = ['google' => 'google', 'bing' => 'bing', 'instagram' => 'instagram', 'facebook' => 'facebook', 'fb.com' => 'facebook',
            'whatsapp' => 'whatsapp', 'youtube' => 'youtube', 'tiktok' => 'tiktok', 't.co' => 'twitter', 'twitter' => 'twitter',
            'duckduckgo' => 'duckduckgo', 'yahoo' => 'yahoo'];
    foreach ($map as $needle => $name) if (strpos($host, $needle) !== false) return $name;
    return mb_substr(preg_replace('/^www\./', '', $host), 0, 40);
}

function track_event(string $ev = 'view', ?int $productId = null): void {
    try {
        if (PHP_SAPI === 'cli' || !function_exists('get_pdo')) return;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|facebookexternalhit|lighthouse|uptime/i', $ua)) return;
        if (isset($_COOKIE['alq_admin'])) return; // no contar tus propias visitas desde el panel
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && $ev === 'view') return;

        $vid = (string) ($_COOKIE['alq_v'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $vid)) {
            $vid = bin2hex(random_bytes(8));
            if (!headers_sent()) setcookie('alq_v', $vid, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        }
        $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobi|android|iphone/i', $ua) ? 'móvil' : 'escritorio');
        $page = mb_substr(basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) ?: 'index.php', 0, 60);
        if ($productId === null && $page === 'producto.php') $productId = (int) ($_GET['id'] ?? 0) ?: null;

        $pdo = get_pdo();
        $ins = "INSERT INTO site_events (created_at, vid, ev, page, product_id, source, device) VALUES (NOW(), :v, :e, :p, :pid, :s, :d)";
        $args = [':v' => $vid, ':e' => $ev, ':p' => $page, ':pid' => $productId, ':s' => stats_source(), ':d' => $device];
        try {
            $pdo->prepare($ins)->execute($args);
        } catch (PDOException $e) {
            stats_ensure_table($pdo);
            $pdo->prepare($ins)->execute($args);
        }
    } catch (Throwable $e) { /* las estadísticas nunca deben afectar la tienda */ }
}

function stats_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        vid CHAR(16) NOT NULL,
        ev VARCHAR(20) NOT NULL,
        page VARCHAR(60) NOT NULL,
        product_id INT UNSIGNED NULL,
        source VARCHAR(40) NOT NULL DEFAULT 'directo',
        device VARCHAR(12) NOT NULL DEFAULT '',
        KEY (created_at), KEY (ev, created_at), KEY (vid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
