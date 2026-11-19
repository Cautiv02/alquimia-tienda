<?php
/**
 * admin/diagnostico.php — revisa desde el propio servidor que todo esté bien conectado:
 * base de datos, Mercado Pago (tipo de credencial y creación de un pago de prueba), correo y últimos errores.
 * Nunca muestra claves; los registros se enmascaran.
 */
require_once __DIR__ . '/_auth.php';
$pdo = null; $dbErr = '';
try { $pdo = get_pdo(); } catch (Throwable $e) { $dbErr = $e->getMessage(); }

function mask_secrets(string $s): string {
    $s = preg_replace('/(APP_USR|TEST)-[A-Za-z0-9\-_]{10,}/', '$1-********', $s);
    $s = preg_replace('/(password|pass|pwd)=([^&\s]+)/i', '$1=********', $s);
    return $s;
}
function tail_file(string $path, int $lines = 15): string {
    if (!is_readable($path)) return '(no existe o no se puede leer: ' . basename($path) . ')';
    $data = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$data) return '(vacío)';
    return mask_secrets(implode("\n", array_slice($data, -$lines)));
}
function mp_post_json(string $path, array $body): array {
    $ch = curl_init('https://api.mercadopago.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . MP_ACCESS_TOKEN, 'X-Idempotency-Key: ' . bin2hex(random_bytes(12))],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'data' => is_array($j) ? $j : null, 'raw' => (string) $raw, 'curl_error' => $err];
}

$mailMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mail') {
    csrf_check();
    $ok = smtp_send(NOTIFY_EMAIL, 'Prueba de correo - ' . SITE_NAME, '<p>Si ves este mensaje, el correo saliente funciona.</p>');
    $mailMsg = $ok ? 'Enviado a ' . NOTIFY_EMAIL . '. Revisa tu bandeja (y spam).' : 'FALLÓ el envío. Revisa SMTP_HOST, SMTP_USER y SMTP_PASS en config.php (detalle en error_log).';
}

// --- Mercado Pago ---
$me = mp_get('/users/me');
$meData = $me['data'] ?? [];
$tokenKind = strpos(MP_ACCESS_TOKEN, 'TEST-') === 0 ? 'TEST- (credencial de PRUEBA)' : (strpos(MP_ACCESS_TOKEN, 'APP_USR-') === 0 ? 'APP_USR- (producción o usuario de prueba)' : 'formato desconocido');
$nick = (string) ($meData['nickname'] ?? '');
$looksTestUser = (stripos($nick, 'TEST') === 0) || (isset($meData['tags']) && is_array($meData['tags']) && in_array('test_user', $meData['tags'], true));

$pref = mp_post_json('/checkout/preferences', [
    'items' => [['title' => 'Prueba de diagnóstico (no se cobra)', 'quantity' => 1, 'unit_price' => 1000, 'currency_id' => 'CLP']],
    'external_reference' => 'DIAG-' . time(),
]);

admin_header('Diagnóstico');
?>
<h2 style="font-family:var(--serif);margin:0 0 16px;">Diagnóstico del sistema</h2>

<div class="box">
  <strong>Sitio</strong>
  <p class="tag" style="margin:8px 0 0;">URL detectada: <code><?= h(SITE_URL) ?></code> · PHP <?= h(PHP_VERSION) ?> · Entrega automática: <?= AUTO_DELIVERY ? 'SÍ' : 'NO (manual)' ?></p>
  <p class="tag" style="margin:4px 0 0;">Base de datos:
    <?php if ($pdo): $n = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(); ?>
      <b style="color:var(--teal);">conectada</b> (<?= $n ?> productos)
    <?php else: ?><b style="color:#a33;">ERROR</b> <?= h(mask_secrets($dbErr)) ?><?php endif; ?></p>
</div>

<div class="box">
  <strong>Mercado Pago</strong>
  <p class="tag" style="margin:8px 0 0;">Tipo de credencial: <code><?= h($tokenKind) ?></code></p>
  <?php if ($me['code'] === 200): ?>
    <p style="margin:8px 0 0;">Credencial <b style="color:var(--teal);">válida</b>. País/sitio: <b><?= h($meData['site_id'] ?? '?') ?></b> · Cuenta: <b><?= h($nick ?: '?') ?></b></p>
    <?php if ($looksTestUser): ?>
      <p class="flash err" style="margin:10px 0 0;">⚠ Esta cuenta parece de <b>PRUEBA</b> (usuario de test). No sirve para cobrar a clientes reales. Necesitas el Access Token de <b>producción</b> de tu cuenta real (Tus integraciones → tu aplicación → Credenciales de producción).</p>
    <?php else: ?>
      <p class="tag" style="margin:6px 0 0;">No parece cuenta de prueba (cuenta real).</p>
    <?php endif; ?>
    <?php if (($meData['site_id'] ?? '') !== 'MLC'): ?>
      <p class="flash err" style="margin:10px 0 0;">⚠ El país de la cuenta no es Chile (MLC). Para cobrar en CLP necesitas una cuenta de Mercado Pago Chile.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="flash err" style="margin:10px 0 0;">La credencial fue <b>rechazada</b> por Mercado Pago (HTTP <?= (int) $me['code'] ?>). Mensaje: <?= h(mask_secrets((string) ($meData['message'] ?? $me['raw']))) ?>.<br>Revisa <code>MP_ACCESS_TOKEN</code> en config.php: debe ser el Access Token (no la Public Key).</p>
  <?php endif; ?>

  <p style="margin:14px 0 0;"><b>Crear un pago de prueba</b> (no cobra nada):
    <?php if (in_array($pref['code'], [200, 201], true) && !empty($pref['data']['init_point'])): ?>
      <b style="color:var(--teal);">OK</b> — Mercado Pago aceptó crear la preferencia. Si el cliente no avanza, el problema está en el paso siguiente (la pantalla de Mercado Pago o la cuenta), no en la tienda.
    <?php else: ?>
      <b style="color:#a33;">FALLÓ</b> (HTTP <?= (int) $pref['code'] ?>)<?= $pref['curl_error'] ? ' · ' . h($pref['curl_error']) : '' ?>.<br>
      <span class="tag">Respuesta de Mercado Pago: <?= h(mask_secrets(mb_substr($pref['raw'], 0, 500))) ?></span>
    <?php endif; ?></p>
</div>

<div class="box">
  <strong>Correo saliente</strong>
  <form method="post" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="mail">
    <button class="btn-a" type="submit">Enviar correo de prueba a <?= h(NOTIFY_EMAIL) ?></button></form>
  <?php if ($mailMsg): ?><p class="flash<?= strpos($mailMsg, 'FALL') === 0 ? ' err' : '' ?>" style="margin:12px 0 0;"><?= h($mailMsg) ?></p><?php endif; ?>
</div>

<div class="box">
  <strong>Últimos errores (error_log)</strong>
  <pre style="white-space:pre-wrap;font-size:.78rem;background:var(--surface-tint);padding:12px;border-radius:8px;overflow:auto;margin:10px 0 0;"><?= h(tail_file(__DIR__ . '/../error_log')) ?></pre>
  <strong style="display:block;margin-top:16px;">Últimos avisos de Mercado Pago (webhook.log)</strong>
  <pre style="white-space:pre-wrap;font-size:.78rem;background:var(--surface-tint);padding:12px;border-radius:8px;overflow:auto;margin:10px 0 0;"><?= h(tail_file(__DIR__ . '/../webhook.log')) ?></pre>
</div>
<?php admin_footer();
