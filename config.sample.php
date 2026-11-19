<?php
/**
 * config.php  (cópialo desde config.sample.php)
 * ------------------------------------------------
 * ÚNICO lugar con claves y URLs. Ningún otro archivo debe llevar contraseñas.
 * - Súbelo junto al resto de archivos del sitio, con el nombre config.php.
 * - Idealmente muévelo fuera de public_html y cambia la ruta en delivery-engine.php.
 * - NO lo pegues en chats ni lo subas a repositorios.
 */

// --- Base de datos (usa una clave NUEVA, distinta a la del correo) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'digitala_alquimiashop');
define('DB_USER', 'CAMBIAR_USUARIO_DB');
define('DB_PASS', 'CAMBIAR_CLAVE_DB');

// --- Sitio ---
// URL base del sitio: SE DETECTA SOLA a partir de dónde está esta carpeta, así que da igual si el sitio
// está en /CID, en otra carpeta o en la raíz del dominio. No hace falta tocarla al mover el sitio.
// (Opcional: fija el dominio para que no dependa de lo que envíe el navegador, ej. 'alquimia-digital.cl')
define('SITE_HOST', '');
function detect_site_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = SITE_HOST !== '' ? SITE_HOST : ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);

    $dir  = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
    $root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
    $root = rtrim($root, '/');
    if ($root !== '' && strpos($dir, $root) === 0) {
        $path = substr($dir, strlen($root));                      // carpeta de este archivo respecto a la raíz web
    } else {
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');   // plan B: según la URL pedida
        $path = preg_replace('#/admin$#', '', $path);
    }
    $path = ($path === '/' || $path === '.') ? '' : rtrim($path, '/');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}
define('SITE_URL', detect_site_url());

// Evita que el navegador o el servidor guarden en caché las páginas dinámicas
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
define('SITE_NAME', 'Alquimia Digital');
define('NOTIFY_EMAIL', 'contacto@alquimia-digital.cl'); // aquí te llega el aviso de cada pedido pagado
define('SUPPORT_WHATSAPP', '56953993785');

// --- Entrega ---
// false = al pagarse un pedido te avisamos (panel + correo) y tú envías las claves a mano.
// true  = el sistema asigna claves del stock y las deja en el portal del cliente.
define('AUTO_DELIVERY', false);
define('LOW_STOCK_THRESHOLD', 5);

// --- Mercado Pago ---
// Access Token de PRODUCCIÓN (empieza con APP_USR-). Usa uno REGENERADO, no el anterior.
define('MP_ACCESS_TOKEN', 'APP_USR-CAMBIAR');
// Clave secreta del webhook (Mercado Pago → Tus integraciones → Webhooks → "Clave secreta").
// Si la dejas vacía, el pago igual se valida contra la API de Mercado Pago, pero se omite la firma.
define('MP_WEBHOOK_SECRET', '');

// --- Correo saliente (SMTP de tu cPanel) ---
define('SMTP_HOST', 'mail.digital-alquimia.com');
define('SMTP_PORT', 465); // 465 = SSL, 587 = TLS
define('SMTP_USER', 'delivery@digital-alquimia.com');
define('SMTP_PASS', 'CAMBIAR_CLAVE_CORREO');
define('MAIL_FROM', 'delivery@digital-alquimia.com');
define('MAIL_FROM_NAME', 'Alquimia Digital');

// --- Panel admin (carpeta admin/ del sitio) ---
// Genera el hash en el Terminal de cPanel:  php -r "echo password_hash('TU_CLAVE_LARGA', PASSWORD_DEFAULT);"
define('ADMIN_USER', 'admin');
define('ADMIN_PASS_HASH', 'PEGA_AQUI_EL_HASH');
