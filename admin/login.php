<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'path' => '/']);
    session_name('alq_admin');
    session_start();
}
function hh($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if ($_SESSION['tries'] > 8) {
        $err = 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.';
    } else {
        $okUser = hash_equals(ADMIN_USER, (string) ($_POST['user'] ?? ''));
        $okPass = password_verify((string) ($_POST['pass'] ?? ''), ADMIN_PASS_HASH);
        if ($okUser && $okPass) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['tries'] = 0;
            header('Location: index.php');
            exit;
        }
        $err = 'Usuario o clave incorrectos.';
        usleep(600000);
    }
}
?><!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Acceso · <?= hh(SITE_NAME) ?></title>
<link rel="stylesheet" href="../site.css?v=<?= (int) @filemtime(__DIR__ . '/../site.css') ?>">
<style>
  .login { max-width: 380px; margin: 90px auto; padding: 0 20px; }
  .login h1 { font-family: var(--serif); font-size: 1.5rem; margin: 0 0 18px; }
  .login input { width:100%; padding:12px 14px; border:1px solid var(--line); border-radius:8px; font-size:.95rem; margin-bottom:14px; font-family: var(--sans); }
  .login button { width:100%; background: var(--gold); color:#FFFBF3; border:none; padding:13px; border-radius:8px; font-weight:600; cursor:pointer; font-size:.95rem; }
  .login .err { background: var(--surface-tint); border:1px solid rgba(185,134,42,.6); padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:.9rem; }
</style></head><body>
<div class="login">
  <h1>Panel <?= hh(SITE_NAME) ?></h1>
  <?php if ($err): ?><div class="err"><?= hh($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input name="user" placeholder="Usuario" autofocus required>
    <input type="password" name="pass" placeholder="Clave" required>
    <button type="submit">Entrar</button>
  </form>
</div>
</body></html>
