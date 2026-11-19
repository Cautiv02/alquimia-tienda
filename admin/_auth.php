<?php
/**
 * admin/_auth.php — protege el panel. Se incluye al inicio de cada página del admin.
 * Usuario y hash de clave: config.php (ADMIN_USER / ADMIN_PASS_HASH).
 */
require_once __DIR__ . '/../mp-lib.php';
require_once __DIR__ . '/../layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'path' => '/']);
    session_name('alq_admin');
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sesión expirada. Vuelve atrás, recarga la página e inténtalo de nuevo.');
    }
}

if (empty($_SESSION['admin_ok'])) {
    header('Location: login.php');
    exit;
}

function admin_header(string $title): void { ?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Admin · <?= h($title) ?></title>
<link rel="stylesheet" href="../site.css?v=<?= (int) @filemtime(__DIR__ . '/../site.css') ?>">
<style>
  body { background: var(--bg); }
  .adm { max-width: 1100px; margin: 0 auto; padding: 24px 20px 60px; }
  .adm-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
  .adm-top a { color: var(--text-dim); text-decoration:none; margin-right:16px; font-size:.92rem; }
  .adm-top a:hover { color: var(--gold); }
  table.t { width:100%; border-collapse: collapse; background: var(--surface); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
  table.t th, table.t td { text-align:left; padding:12px 14px; border-bottom:1px solid var(--line); font-size:.9rem; vertical-align:top; }
  table.t th { color: var(--ash); font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; background: var(--surface-tint); }
  .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.75rem; font-weight:600; color:#fff; background: var(--ash); }
  .pill.pagado, .pill.entregado { background: var(--teal); }
  .pill.pendiente { background: var(--gold); }
  .pill.anulado, .pill.reembolsado { background: #8a4b4b; }
  .box { background: var(--surface); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:20px; }
  .btn-a { background: var(--gold); color:#FFFBF3; border:none; padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer; font-family: var(--sans); font-size:.9rem; text-decoration:none; display:inline-block; }
  .btn-a.ghost { background: transparent; color: var(--text); border:1px solid var(--line); }
  .flash { background: var(--surface-tint); border:1px solid rgba(42,125,116,.5); border-radius:10px; padding:12px 16px; margin-bottom:18px; }
  .flash.err { border-color: rgba(185,134,42,.6); }
  .tag { font-size:.8rem; color: var(--ash); }
  code { font-family: var(--mono); }
</style></head><body>
<div class="adm">
  <div class="adm-top">
    <div><strong style="font-family:var(--serif);font-size:1.2rem;">Panel · <?= h(SITE_NAME) ?></strong></div>
    <div><a href="index.php">Pedidos</a><a href="productos.php">Productos</a><a href="portada.php">Portada</a><a href="ajustes.php">Ajustes</a><a href="diagnostico.php">Diagnóstico</a><a href="../index.php" target="_blank">Ver tienda</a><a href="logout.php">Salir</a></div>
  </div>
<?php }
function admin_footer(): void { echo '</div></body></html>'; }

function flash_set(string $msg, bool $err = false): void { $_SESSION['flash'] = [$msg, $err]; }
function flash_show(): void {
    if (!empty($_SESSION['flash'])) {
        [$m, $e] = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="flash' . ($e ? ' err' : '') . '">' . h($m) . '</div>';
    }
}
