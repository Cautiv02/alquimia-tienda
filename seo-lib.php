<?php
/**
 * seo-lib.php — SEO técnico: canonical, Open Graph, noindex y datos estructurados (JSON-LD).
 * Las páginas llaman a seo_set([...]) ANTES de site_header(). Compatible con PHP 7.4.
 */
function seo_set(array $o): void { $GLOBALS['__seo'] = array_merge($GLOBALS['__seo'] ?? [], $o); }
function seo_get(string $k, $d = null) { return $GLOBALS['__seo'][$k] ?? $d; }
function seo_ld_add(array $block): void { $GLOBALS['__seo']['ld'][] = $block; }

/** Dominio canónico (SITE_HOST si está fijado; si no, el detectado). */
function seo_base(): string { return rtrim(SITE_URL, '/'); }
function seo_abs(string $u): string {
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    return seo_base() . '/' . ltrim($u, '/');
}

/** Datos de la empresa (editables en Ajustes → Datos de la empresa). */
function biz_info(): array {
    static $c = null;
    if ($c !== null) return $c;
    try { require_once __DIR__ . '/settings-lib.php'; $s = settings_all(get_pdo()); } catch (Throwable $e) { $s = settings_defaults(); }
    $c = [
        'name'    => trim((string) ($s['biz_legal_name'] ?? '')) ?: SITE_NAME,
        'rut'     => trim((string) ($s['biz_rut'] ?? '')),
        'address' => trim((string) ($s['biz_address'] ?? '')),
        'hours'   => trim((string) ($s['biz_hours'] ?? '')),
        'email'   => NOTIFY_EMAIL,
        'wa'      => SUPPORT_WHATSAPP,
    ];
    return $c;
}

function seo_json(array $a): string { return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

function seo_head(string $title, string $description): void {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $canon = seo_get('canonical');
    if (!$canon) { $canon = seo_base() . '/' . ltrim(basename($path), '/'); if (substr($canon, -10) === '/index.php') $canon = seo_base() . '/'; }
    $canon = seo_abs((string) $canon);
    $image = seo_abs((string) (seo_get('image') ?: 'images/logo.jpg'));
    $type  = (string) seo_get('type', 'website');
    $fullTitle = $title . ' | ' . SITE_NAME;
    $desc = trim(preg_replace('/\s+/', ' ', strip_tags($description)));
    if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 297) . '…';
    $b = biz_info();
    if (seo_get('noindex')) echo '<meta name="robots" content="noindex, follow">' . "\n";
    else echo '<meta name="robots" content="index, follow, max-image-preview:large">' . "\n";
    echo '<link rel="canonical" href="' . h($canon) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . h(SITE_NAME) . '">' . "\n";
    echo '<meta property="og:locale" content="es_CL">' . "\n";
    echo '<meta property="og:type" content="' . h($type) . '">' . "\n";
    echo '<meta property="og:title" content="' . h($fullTitle) . '">' . "\n";
    if ($desc !== '') echo '<meta property="og:description" content="' . h($desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . h($canon) . '">' . "\n";
    echo '<meta property="og:image" content="' . h($image) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    if (!seo_get('noindex')) {
        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $b['name'], 'alternateName' => SITE_NAME,
            'url' => seo_base() . '/', 'logo' => seo_abs('images/logo.jpg'), 'email' => $b['email'],
            'contactPoint' => [['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $b['email'],
                'telephone' => '+' . $b['wa'], 'areaServed' => ['CL', 'VE'], 'availableLanguage' => 'Spanish']],
            'sameAs' => ['https://www.facebook.com/DigitalAlquimiaLatam', 'https://www.instagram.com/digital.alquimia/']];
        if ($b['address'] !== '') $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $b['address'], 'addressCountry' => 'CL'];
        if ($b['rut'] !== '') $org['taxID'] = $b['rut'];
        echo '<script type="application/ld+json">' . seo_json($org) . '</script>' . "\n";
        if ($path === '/' || basename($path) === 'index.php' || $path === '') {
            echo '<script type="application/ld+json">' . seo_json(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => SITE_NAME,
                'url' => seo_base() . '/', 'potentialAction' => ['@type' => 'SearchAction',
                'target' => seo_base() . '/catalogo.php?q={search_term_string}', 'query-input' => 'required name=search_term_string']]) . '</script>' . "\n";
        }
        foreach ((array) seo_get('ld', []) as $blk) echo '<script type="application/ld+json">' . seo_json($blk) . '</script>' . "\n";
    }
}
