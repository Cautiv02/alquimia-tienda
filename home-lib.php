<?php
/**
 * home-lib.php — bloques de productos de la portada.
 * Se editan desde el panel (admin/portada.php) y se guardan en las tablas home_sections / home_section_items.
 * Mientras esas tablas no existan o estén vacías, la portada usa la configuración por defecto de abajo
 * (los productos se buscan por nombre).
 */

/** Configuración inicial. Casilla: [expresión del nombre, precio esperado o null, agotado]. */
function home_default_sections(): array {
    return [
        ['id' => 'ofertas', 'title' => 'Los más vendidos', 'link' => ['catalogo.php', 'Ver todo el catálogo'], 'slots' => [
            ['/Office 365.*Cuenta Original.*5 Dispositivos/iu', 4990], ['/Office 2024 Profesional Plus LTSC/iu', 7990],
            ['/Acrobat Pro 2026/iu', 12990], ['/^Windows 11 Professional/iu', 4990],
            ['/^Autodesk (Suscripci|todas)/iu', 42990], ['/Office 2021 Profesional Plus LTSC/iu', 6990],
            ['/Duolingo/iu', 29990], ['/Spotify Premium.*12/iu', 27950],
            ['/Creative Cloud.*1 A(Ñ|N)O/iu', 24990], ['/^Windows 10 Professional/iu', 4990],
            ['/^Project - Visio 2021/iu', 9990], ['/Perplexity/iu', 89990],
        ]],
        ['id' => 'flash', 'title' => 'Ofertas flash', 'link' => ['catalogo.php', 'Ver todas las ofertas'], 'slots' => [
            ['/Gemini/iu'], ['/Visio 2024 PRO/iu'], ['/Project 2024 Professional/iu'], ['/^Pack Office \+ Mcafee/iu'],
            ['/Perplexity/iu'], ['/Spotify Premium.*12/iu'], ['/Creative Cloud.*1 A(Ñ|N)O/iu'], ['/Chat ?GPT Plus/iu', null, true],
        ]],
        ['id' => 'office', 'title' => 'Office más vendidos', 'link' => ['catalogo.php?cat=office', 'Ver todos los Office disponibles'], 'slots' => [
            ['/^Office 2024 Profesional Plus - Windows/iu'], ['/Office 2021 Profesional Plus LTSC/iu'],
            ['/^Office 365 - Cuenta Original - 5 Dispositivos/iu'], ['/Visio 2024 STD/iu'], ['/Project 2024 Professional/iu'],
            ['/^Office 365 - 5 Dispositivos \+ OneDrive - Suscripción 1 Año/iu'],
        ]],
        ['id' => 'windows', 'title' => 'Windows más vendidos', 'link' => ['catalogo.php?cat=windows', 'Ver todos los Windows'], 'slots' => [
            ['/^Windows 10 Professional\s*$/iu'], ['/^Windows 11 Professional/iu'], ['/^Windows 10 Home/iu'],
            ['/^Windows 11 Home/iu'], ['/^Windows 7 Professional/iu'], ['/^Windows 8\.1 Professional/iu'],
        ]],
        ['id' => 'suscripciones', 'title' => 'Suscripciones más vendidas', 'link' => ['catalogo.php', 'Ver todas las suscripciones'], 'slots' => [
            ['/Creative Cloud 2026/iu'], ['/AUTOCAD 2.?022\/2027 para .?Windows/iu'], ['/Spotify Premium Barato/iu'],
        ]],
        ['id' => 'promos', 'title' => 'Promociones y packs', 'link' => ['catalogo.php', 'Ver todas las promociones'], 'slots' => [
            ['/^Pack Windows 10 Pro/iu'], ['/^Pack Windows 11 Pro/iu'], ['/^Project - Visio 2021 PRO\s*$/iu'],
            ['/^Autodesk Suscripción Original 2026/iu'], ['/^Pack Office \+ Mcafee/iu'], ['/^Windows 11 Pro \+ Office 2021-2024/iu'],
        ]],
        ['id' => 'oficina', 'title' => 'Productividad y oficina', 'link' => ['catalogo.php?cat=office', 'Ver más'], 'slots' => [
            ['/AUTOCAD 2.?022\/2027 para .?Windows/iu'], ['/Acrobat PRO 2024/iu', null, true], ['/AUTOCAD 2.?022\/2027 para .?MAC/iu'],
            ['/^Pack Office \+ Mcafee/iu'], ['/Acrobat STD DC 2025 para MAC/iu'], ['/CIVIL 3D/iu'],
        ]],
        ['id' => 'macos', 'title' => 'Solo para macOS', 'link' => ['catalogo.php', 'Ver todo para Mac'], 'slots' => [
            ['/Office 2016 Home & Business para Mac/iu'], ['/Office 2019 Home & Business para Mac/iu'],
            ['/Office 2021 Hogar & Empresas para Mac/iu'], ['/Office 2024 Hogar & Empresas para Mac/iu'],
            ['/AUTOCAD 2.?022\/2027 para .?MAC/iu'], ['/Acrobat STD DC 2025 para MAC/iu'],
        ]],
        ['id' => 'packs', 'title' => 'Packs', 'link' => ['catalogo.php', 'Ver todos los packs'], 'slots' => [
            ['/^Pack Windows 10 Pro/iu'], ['/^Pack Windows 11 Pro/iu'], ['/^Project - Visio 2021 PRO\s*$/iu'],
        ]],
    ];
}

function home_clean_title($t): string {
    return preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $t);
}

/** Productos activos con su precio mínimo, indexados por id. */
function home_products(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT p.id AS product_id, p.title AS product_title, p.image_url, MIN(pv.price) AS price, COUNT(pv.id) AS nvar,
               (SELECT v2.compare_at_price FROM product_variants v2 WHERE v2.product_id = p.id ORDER BY v2.price ASC, v2.id ASC LIMIT 1) AS compare_at_price
        FROM products p
        JOIN product_variants pv ON pv.product_id = p.id
        WHERE p.status = 'activo'
        GROUP BY p.id, p.title, p.image_url
        ORDER BY p.id ASC
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) { $r['clean_title'] = home_clean_title($r['product_title']); $out[(int) $r['product_id']] = $r; }
    return $out;
}

/** Aplica la configuración por defecto sobre los productos reales. */
function home_resolve_defaults(array $products): array {
    $sections = [];
    foreach (home_default_sections() as $sec) {
        $items = []; $used = [];
        foreach ($sec['slots'] as $slot) {
            $rx = $slot[0]; $expected = $slot[1] ?? null; $soldOut = !empty($slot[2]);
            $first = null; $exact = null;
            foreach ($products as $row) {
                if (isset($used[$row['product_id']]) || !preg_match($rx, $row['clean_title'])) continue;
                if ($first === null) $first = $row;
                if ($expected !== null && $exact === null && (int) round((float) $row['price']) === $expected) $exact = $row;
            }
            $pick = $exact ?: $first;
            if ($pick) { $pick['sold_out'] = $soldOut; $items[] = $pick; $used[$pick['product_id']] = true; }
        }
        $sections[] = ['id' => $sec['id'], 'title' => $sec['title'], 'link' => $sec['link'], 'items' => $items];
    }
    return $sections;
}

function home_tables_exist(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM home_sections LIMIT 1"); $pdo->query("SELECT 1 FROM home_section_items LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

/** Crea las tablas (solo desde el panel) y, si están vacías, las llena con la configuración por defecto. */
function home_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS home_sections (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(40) NOT NULL DEFAULT '',
        title VARCHAR(120) NOT NULL,
        link_url VARCHAR(255) NOT NULL DEFAULT 'catalogo.php',
        link_label VARCHAR(120) NOT NULL DEFAULT 'Ver todo el catálogo',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS home_section_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        section_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        sold_out TINYINT(1) NOT NULL DEFAULT 0,
        KEY (section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->query("SELECT badge FROM home_section_items LIMIT 1"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE home_section_items ADD COLUMN badge VARCHAR(20) NOT NULL DEFAULT ''"); }
    if ((int) $pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn() === 0) home_seed_defaults($pdo);
}

/** Etiquetas disponibles para las tarjetas de la portada (clave => texto). */
function home_badges(): array {
    return ['oferta' => 'Oferta', 'popular' => 'Popular', 'hot' => '🔥 Hot', 'ultimas' => 'Últimas unidades'];
}

function home_seed_defaults(PDO $pdo): void {
    $secs = home_resolve_defaults(home_products($pdo));
    $insS = $pdo->prepare("INSERT INTO home_sections (slug, title, link_url, link_label, sort_order, active) VALUES (:s, :t, :u, :l, :o, 1)");
    $insI = $pdo->prepare("INSERT INTO home_section_items (section_id, product_id, sort_order, sold_out) VALUES (:s, :p, :o, :x)");
    foreach ($secs as $i => $sec) {
        $insS->execute([':s' => $sec['id'], ':t' => $sec['title'], ':u' => $sec['link'][0], ':l' => $sec['link'][1], ':o' => $i]);
        $sid = (int) $pdo->lastInsertId();
        foreach ($sec['items'] as $j => $it) $insI->execute([':s' => $sid, ':p' => $it['product_id'], ':o' => $j, ':x' => !empty($it['sold_out']) ? 1 : 0]);
    }
}

/** Bloques guardados en la base (solo activos y con productos vigentes), o null si aún no se usa el panel. */
function home_load(PDO $pdo): ?array {
    if (!home_tables_exist($pdo)) return null;
    $secs = $pdo->query("SELECT * FROM home_sections WHERE active = 1 ORDER BY sort_order, id")->fetchAll();
    if (!$secs) return ((int) $pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn() === 0) ? null : [];
    $products = home_products($pdo);
    $out = [];
    try { $pdo->query("SELECT badge FROM home_section_items LIMIT 1"); $cols = 'product_id, sold_out, badge'; }
    catch (Throwable $e) { $cols = "product_id, sold_out, '' AS badge"; }
    $q = $pdo->prepare("SELECT $cols FROM home_section_items WHERE section_id = :s ORDER BY sort_order, id");
    foreach ($secs as $s) {
        $q->execute([':s' => $s['id']]);
        $items = [];
        foreach ($q->fetchAll() as $it) {
            $p = $products[(int) $it['product_id']] ?? null;
            if ($p) { $p['sold_out'] = (bool) $it['sold_out']; $p['badge'] = isset(home_badges()[$it['badge']]) ? $it['badge'] : ''; $items[] = $p; }
        }
        $out[] = ['id' => $s['slug'] ?: 's' . $s['id'], 'title' => $s['title'], 'link' => [$s['link_url'], $s['link_label']], 'items' => $items];
    }
    return $out;
}
