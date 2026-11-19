<?php
/** suscribir.php — guarda el correo del formulario "Suscríbete para recibir ofertas" y vuelve a la página anterior. */
require_once __DIR__ . '/delivery-engine.php';

$back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
// Solo volvemos a páginas del propio sitio
$host = parse_url($back, PHP_URL_HOST);
if ($host && $host !== ($_SERVER['HTTP_HOST'] ?? '')) $back = 'index.php';
$back = preg_replace('/([?&])sub=[a-z]+&?/', '$1', $back);
$back = rtrim($back, '?&');
$sep = strpos($back, '?') === false ? '?' : '&';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['website'])) { header('Location: index.php'); exit; }
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { header('Location: ' . $back . $sep . 'sub=error#suscripcion'); exit; }
try {
    $pdo = get_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email, created_at) VALUES (:e, NOW())")->execute([':e' => $email]);
} catch (Throwable $e) {
    error_log('[suscribir] ' . $e->getMessage());
    header('Location: ' . $back . $sep . 'sub=error'); exit;
}
header('Location: ' . $back . $sep . 'sub=ok');
exit;
