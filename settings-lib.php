<?php
/**
 * settings-lib.php — ajustes editables desde el panel (admin/ajustes.php): aviso superior y pago por transferencia.
 * Se guardan en la tabla site_settings. Si la tabla no existe todavía, se usan los valores por defecto.
 */
require_once __DIR__ . '/mail-templates-lib.php';
function settings_defaults(): array {
    $wa = defined('SUPPORT_WHATSAPP') ? SUPPORT_WHATSAPP : '';
    return mail_template_setting_defaults() + [
        'banner_enabled'    => '1',
        'banner_text'       => 'Renovamos nuestra tienda. Si ya eres cliente, tu pedido y tu soporte siguen igual.',
        'banner_link_label' => 'Escríbenos por WhatsApp',
        'banner_link_url'   => 'https://api.whatsapp.com/send?phone=' . $wa,
        'notify_extra'      => 'christiandj@live.com',
        'coupon_enabled'    => '0',
        'coupon_code'       => 'BIENVENIDA',
        'coupon_percent'    => '10',
        'popup_enabled'     => '0',
        'popup_title'       => '🎁 {porcentaje}% de descuento en tu primer pedido',
        'popup_text'        => 'Usa el código {codigo} en el carrito antes de pagar. Válido solo en tu primera compra.',
        'popup_button_label'=> 'Ver productos',
        'popup_button_url'  => 'catalogo.php',
        'popup_delay'       => '4',
        'popup_days'        => '7',
        'review_reward_enabled' => '1',
        'review_reward_percent' => '10',
        'review_reward_days'    => '60',
        'review_remind_since'   => '',
        'binance_enabled'   => '0',
        'binance_details'   => '',
        'binance_rate'      => '',
        'binance_hours'     => '4',
        'expiry_last_run'   => '',
        'binance_qr'        => '',
        'binance_note'      => 'Paga desde la app de Binance (Pay → Enviar) usando el ID de arriba y escribe tu número de pedido en la nota. Luego envíanos el comprobante por WhatsApp o correo. Despachamos tu clave apenas confirmemos el pago.',
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

/** Binance Pay manual: activado, con datos y con tasa CLP por 1 USDT. */
function binance_enabled(): bool {
    return setting('binance_enabled') === '1' && trim(setting('binance_details')) !== '' && binance_rate() > 0;
}
/** Dirección completa de la imagen del QR de Binance Pay ('' si no hay). */
function binance_qr_url(): string { $p = trim(setting('binance_qr')); return $p === '' ? '' : (defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '') . ltrim($p, '/'); }
function binance_rate(): float { return (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', setting('binance_rate'))); }
/** Monto en USDT para un total en CLP, redondeado al entero más cercano (mínimo 1). */
function binance_usdt($clp): string { $r = binance_rate(); return $r > 0 ? (string) max(1, (int) round((float) $clp / $r)) : '0'; }
/** Referencia corta del pedido: solo el correlativo (AD-24/11-8066 → 8066). */
function order_short_ref(string $orderNumber): string { return preg_match('/(\d+)$/', $orderNumber, $m) ? $m[1] : $orderNumber; }

/** Si orders.payment_method es un ENUM antiguo, lo pasa a texto para admitir 'binance' (y futuros medios). */
function orders_ensure_payment_method(PDO $pdo): void {
    try {
        $c = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_method'")->fetch();
        if ($c && stripos((string) $c['Type'], 'enum') === 0) {
            $null = strtoupper((string) $c['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
            $def  = $c['Default'] !== null ? "DEFAULT " . $pdo->quote((string) $c['Default']) : ($null === 'NULL' ? 'DEFAULT NULL' : "DEFAULT 'mercadopago'");
            $pdo->exec("ALTER TABLE orders MODIFY payment_method VARCHAR(30) $null $def");
        }
    } catch (Throwable $e) { error_log('[checkout] payment_method: ' . $e->getMessage()); }
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
