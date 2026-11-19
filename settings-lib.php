<?php
/**
 * settings-lib.php — ajustes editables desde el panel (admin/ajustes.php): aviso superior y pago por transferencia.
 * Se guardan en la tabla site_settings. Si la tabla no existe todavía, se usan los valores por defecto.
 */
function settings_defaults(): array {
    $wa = defined('SUPPORT_WHATSAPP') ? SUPPORT_WHATSAPP : '';
    return [
        'banner_enabled'    => '1',
        'banner_text'       => 'Renovamos nuestra tienda. Si ya eres cliente, tu pedido y tu soporte siguen igual.',
        'banner_link_label' => 'Escríbenos por WhatsApp',
        'banner_link_url'   => 'https://api.whatsapp.com/send?phone=' . $wa,
        'transfer_enabled'  => '0',
        'transfer_details'  => '',
        'transfer_note'     => 'Una vez hecha la transferencia, envíanos el comprobante por WhatsApp o por correo indicando tu número de pedido. Despachamos tu clave apenas confirmemos el pago.',
    ];
}

function settings_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = settings_defaults();
    try {
        if (function_exists('get_pdo')) {
            foreach (get_pdo()->query("SELECT k, v FROM site_settings")->fetchAll() as $r) {
                if (array_key_exists($r['k'], $cache)) $cache[$r['k']] = (string) $r['v'];
            }
        }
    } catch (Throwable $e) { /* sin tabla o sin base: valores por defecto */ }
    return $cache;
}

function setting(string $k): string { $a = settings_all(); return (string) ($a[$k] ?? ''); }

/** La transferencia solo se ofrece si está activada y hay datos bancarios cargados. */
function transfer_enabled(): bool {
    return setting('transfer_enabled') === '1' && trim(setting('transfer_details')) !== '';
}

function settings_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        k VARCHAR(60) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function settings_save(PDO $pdo, array $values): void {
    settings_ensure_table($pdo);
    $st = $pdo->prepare("INSERT INTO site_settings (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach ($values as $k => $v) $st->execute([':k' => $k, ':v' => $v]);
}
