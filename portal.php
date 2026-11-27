<?php
/**
 * portal.php
 * -----------
 * Portal de clientes: consulta el pedido por N° de pedido + código único
 * (sin necesidad de cuenta). Muestra el estado, los ítems comprados y las
 * claves ya entregadas, listas para copiar.
 */

require_once __DIR__ . '/delivery-engine.php';

$pdo = get_pdo();

$mode        = $_GET['modo'] ?? 'email';
$email       = trim(strtolower($_GET['email'] ?? ''));
$orderNumber = trim($_GET['pedido'] ?? '');
$uniqueCode  = trim(strtoupper($_GET['codigo'] ?? ''));
$order = null;
$items = [];
$notFound = false;
$searched = false;
$revealError = '';

// Manejo del "revelar clave" — llega por POST desde el formulario de confirmación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reveal_redemption_id'])) {
    $redemptionId = (int) $_POST['reveal_redemption_id'];
    $confirmed = isset($_POST['confirm_product']) && isset($_POST['confirm_install']) && isset($_POST['confirm_norefund']);

    if ($confirmed) {
        $stmtReveal = $pdo->prepare("UPDATE key_redemptions SET revealed_at = NOW() WHERE id = :id AND revealed_at IS NULL");
        $stmtReveal->execute([':id' => $redemptionId]);
    } else {
        $revealError = 'Debes marcar las 3 confirmaciones para ver tu clave.';
    }

    // Volvemos a cargar la misma búsqueda para redibujar la página con la clave ya revelada
    $mode = $_POST['modo'] ?? 'email';
    $email = trim(strtolower($_POST['email'] ?? ''));
    $orderNumber = trim($_POST['pedido'] ?? '');
    $uniqueCode = trim(strtoupper($_POST['codigo'] ?? ''));
}

if ($mode === 'codigo' && $uniqueCode) {
    $searched = true;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE unique_code = :code");
    $stmt->execute([':code' => $uniqueCode]);
    $order = $stmt->fetch();
} elseif ($mode === 'email' && $email && $orderNumber) {
    $searched = true;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE LOWER(email) = :email AND order_number = :num");
    $stmt->execute([':email' => $email, ':num' => $orderNumber]);
    $order = $stmt->fetch();
}

if ($searched) {
    if (!$order) {
        $notFound = true;
    } else {
        $stmtItems = $pdo->prepare("
            SELECT oi.id, oi.title, oi.quantity, oi.unit_price
            FROM order_items oi
            WHERE oi.order_id = :oid
        ");
        $stmtItems->execute([':oid' => $order['id']]);
        $itemRows = $stmtItems->fetchAll();

        foreach ($itemRows as $item) {
            $stmtKeys = $pdo->prepare("
                SELECT kr.id AS redemption_id, lk.key_value, kr.redeemed_at, kr.revealed_at
                FROM key_redemptions kr
                JOIN license_keys lk ON lk.id = kr.license_key_id
                WHERE kr.order_item_id = :oiid
                ORDER BY kr.redeemed_at ASC
            ");
            $stmtKeys->execute([':oiid' => $item['id']]);
            $item['keys'] = $stmtKeys->fetchAll();
            $items[] = $item;
        }
    }
}

function money($n) { return '$' . number_format((float)$n, 0, ',', '.'); }

$statusLabels = [
    'pendiente'  => ['label' => 'Pago pendiente', 'color' => 'var(--ash)'],
    'pagado'     => ['label' => 'Pago confirmado', 'color' => 'var(--teal)'],
    'reembolsado'=> ['label' => 'Reembolsado', 'color' => 'var(--ash)'],
    'anulado'    => ['label' => 'Anulado', 'color' => 'var(--ash)'],
];
$fulfillLabels = [
    'pendiente' => ['label' => 'Preparando entrega', 'color' => 'var(--gold)'],
    'entregado' => ['label' => 'Entregado', 'color' => 'var(--teal)'],
    'parcial'   => ['label' => 'Entrega parcial — contáctanos', 'color' => 'var(--gold)'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Consultar mi pedido | Alquimia Digital</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #FAF7F0; --surface: #FFFFFF; --surface-tint: #F1ECDD;
    --gold: #B9862A; --gold-bright: #D4A03E; --teal: #2A7D74;
    --ash: #7A7566; --line: rgba(28,26,20,0.10);
    --text: #211E17; --text-dim: #5C5749; --radius-card: 14px;
    --serif: 'Fraunces', Georgia, serif;
    --sans: 'IBM Plex Sans', -apple-system, sans-serif;
    --mono: 'IBM Plex Mono', Consolas, monospace;
    --dark: #3E5C76; --dark-2: #4C7093;
    --text-on-dark: #F2EFE6; --text-on-dark-dim: #C9D6E0;
    --line-on-dark: rgba(245,241,230,0.18);
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--sans); line-height: 1.5; }
  a { color: inherit; } img { max-width: 100%; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 0 28px; }

  header { position: sticky; top: 0; z-index: 50; background: #FFFFFF; border-bottom: 1px solid var(--line); }
  .nav-row { display: flex; align-items: center; justify-content: space-between; padding: 18px 0; max-width: 1180px; margin: 0 auto; padding-left: 28px; padding-right: 28px; }
  .logo { font-family: var(--serif); font-weight: 600; font-size: 1.2rem; text-decoration: none; color: var(--text); }
  nav.links { display: flex; gap: 26px; font-size: 0.9rem; }
  nav.links a { text-decoration: none; color: var(--text-dim); }
  nav.links a:hover { color: var(--gold); }

  .hero { padding: 52px 0 32px; text-align: center; }
  .hero h1 { font-family: var(--serif); font-size: clamp(1.7rem, 3vw, 2.3rem); margin: 0 0 10px; }
  .hero p { color: var(--text-dim); max-width: 46ch; margin: 0 auto; }

  .lookup-tabs { display: flex; gap: 8px; margin-top: 28px; }
  .tab-btn {
    background: var(--surface); border: 1px solid var(--line); border-radius: 20px;
    padding: 9px 18px; font-size: 0.85rem; font-family: var(--sans); cursor: pointer; color: var(--text-dim);
  }
  .tab-btn.active { background: var(--gold); border-color: var(--gold); color: #FFFBF3; font-weight: 600; }

  .lookup-form {
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card);
    padding: 28px; margin: 16px 0 32px; display: grid; grid-template-columns: 1fr 1fr auto; gap: 14px; align-items: end;
  }
  .lookup-form label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 6px; }
  .lookup-form input {
    width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px;
    font-family: var(--mono); font-size: 0.95rem; text-transform: uppercase;
  }
  .lookup-form input::placeholder { text-transform: none; font-family: var(--sans); color: var(--ash); }
  .btn-primary {
    background: var(--gold); color: #FFFBF3; border: none; padding: 13px 24px; border-radius: 8px;
    font-weight: 600; font-size: 0.95rem; cursor: pointer; font-family: var(--sans); white-space: nowrap;
  }
  .btn-primary:hover { background: var(--gold-bright); }

  .alert {
    background: var(--surface-tint); border: 1px solid var(--line); border-radius: 10px;
    padding: 16px 18px; margin-bottom: 24px; font-size: 0.92rem;
  }
  .alert.error { border-color: rgba(185,134,42,0.4); }

  .order-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); padding: 28px; margin-bottom: 24px; }
  .order-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--line); }
  .order-head h2 { font-family: var(--serif); font-size: 1.3rem; margin: 0 0 4px; }
  .order-head .meta { font-size: 0.85rem; color: var(--ash); }
  .status-badges { display: flex; gap: 10px; flex-wrap: wrap; }
  .badge { font-size: 0.78rem; font-weight: 600; padding: 6px 13px; border-radius: 20px; color: #fff; }

  .item-row { padding: 18px 0; border-bottom: 1px solid var(--line); }
  .item-row:last-child { border-bottom: none; }
  .item-title { font-family: var(--serif); font-size: 1.05rem; margin: 0 0 4px; }
  .item-price { font-size: 0.85rem; color: var(--ash); margin-bottom: 12px; }
  .key-box {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    background: var(--surface-tint); border: 1px dashed rgba(185,134,42,0.4); border-radius: 8px;
    padding: 12px 14px; margin-bottom: 8px;
  }
  .key-box code { font-family: var(--mono); font-size: 0.95rem; letter-spacing: 0.03em; color: var(--text); word-break: break-all; }
  .copy-btn {
    background: var(--surface); border: 1px solid var(--line); border-radius: 6px;
    padding: 6px 12px; font-size: 0.78rem; cursor: pointer; flex-shrink: 0; font-family: var(--sans);
  }
  .copy-btn:hover { border-color: var(--gold); }
  .key-box--hidden { justify-content: space-between; opacity: 0.7; }
  .hidden-label { font-size: 0.78rem; color: var(--ash); font-style: italic; }
  .reveal-box { margin-bottom: 14px; }
  .reveal-form {
    background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
    padding: 16px; margin-top: 6px;
  }
  .confirm-row {
    display: flex; align-items: flex-start; gap: 9px; font-size: 0.85rem; color: var(--text-dim);
    margin-bottom: 10px; cursor: pointer;
  }
  .confirm-row input { margin-top: 3px; accent-color: var(--gold); flex-shrink: 0; cursor: pointer; }
  .reveal-btn { width: 100%; margin-top: 6px; font-size: 0.9rem; padding: 11px; }

  .pending-note { font-size: 0.85rem; color: var(--ash); font-style: italic; }

  .help-row { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 24px; }
  .help-link {
    flex: 1; min-width: 180px; text-align: center; padding: 14px; border-radius: 8px;
    border: 1px solid var(--line); text-decoration: none; color: var(--text); font-size: 0.9rem;
    background: var(--surface);
  }
  .help-link:hover { border-color: var(--gold); }

  footer { background: var(--dark); color: var(--text-on-dark); padding: 40px 0; margin-top: 60px; text-align: center; font-size: 0.85rem; }
  footer a { color: var(--text-on-dark-dim); text-decoration: none; margin: 0 10px; }

  @media (max-width: 640px) {
    .lookup-form { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<header>
  <div class="nav-row">
    <a class="logo" href="index.php">Alquimia Digital</a>
    <nav class="links">
      <a href="index.php">Inicio</a>
      <a href="catalogo.php">Catálogo</a>
      <a href="portal.php">Mi pedido</a>
    </nav>
  </div>
</header>

<div class="wrap">
  <div class="hero">
    <h1>Consulta tu pedido</h1>
    <p>Elige cómo quieres buscar tu pedido.</p>
  </div>

  <div class="lookup-tabs">
    <button type="button" class="tab-btn <?= $mode === 'email' ? 'active' : '' ?>" onclick="showTab('email')">Correo + N° de pedido</button>
    <button type="button" class="tab-btn <?= $mode === 'codigo' ? 'active' : '' ?>" onclick="showTab('codigo')">Solo código único</button>
  </div>

  <form class="lookup-form" method="get" id="form-email" style="<?= $mode === 'codigo' ? 'display:none;' : '' ?>">
    <input type="hidden" name="modo" value="email">
    <div>
      <label for="email">Correo electrónico</label>
      <input type="email" id="email" name="email" placeholder="tu@correo.com" value="<?= htmlspecialchars($email) ?>" required>
    </div>
    <div>
      <label for="pedido">N° de pedido</label>
      <input type="text" id="pedido" name="pedido" placeholder="AD202609039848" value="<?= htmlspecialchars($orderNumber) ?>" required>
    </div>
    <button type="submit" class="btn-primary">Consultar</button>
  </form>

  <form class="lookup-form" method="get" id="form-codigo" style="<?= $mode === 'codigo' ? '' : 'display:none;' ?>">
    <input type="hidden" name="modo" value="codigo">
    <div style="grid-column: 1 / span 2;">
      <label for="codigo">Código único</label>
      <input type="text" id="codigo" name="codigo" placeholder="AD-XXXX-XXXX" value="<?= htmlspecialchars($uniqueCode) ?>" required>
    </div>
    <button type="submit" class="btn-primary">Consultar</button>
  </form>

  <script>
    function showTab(mode) {
      document.getElementById('form-email').style.display = mode === 'email' ? '' : 'none';
      document.getElementById('form-codigo').style.display = mode === 'codigo' ? '' : 'none';
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      event.target.classList.add('active');
    }
  </script>

  <?php if ($revealError): ?>
    <div class="alert error"><?= htmlspecialchars($revealError) ?></div>
  <?php endif; ?>

  <?php if ($notFound): ?>
    <div class="alert error">
      No encontramos ningún pedido con esos datos. Revisa que la información coincida exactamente con la de tu compra — si el problema sigue, escríbenos por WhatsApp.
    </div>
  <?php endif; ?>

  <?php if ($order): ?>
    <?php
      $fs = $statusLabels[$order['financial_status']] ?? ['label' => $order['financial_status'], 'color' => 'var(--ash)'];
      $ffs = $fulfillLabels[$order['fulfillment_status']] ?? ['label' => $order['fulfillment_status'], 'color' => 'var(--ash)'];
    ?>
    <div class="order-card">
      <div class="order-head">
        <div>
          <h2>Pedido #<?= htmlspecialchars($order['order_number']) ?></h2>
          <div class="meta"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?> · Total: <?= money($order['total_amount']) ?> <?= htmlspecialchars($order['currency']) ?></div>
        </div>
        <div class="status-badges">
          <span class="badge" style="background:<?= $fs['color'] ?>"><?= htmlspecialchars($fs['label']) ?></span>
          <span class="badge" style="background:<?= $ffs['color'] ?>"><?= htmlspecialchars($ffs['label']) ?></span>
        </div>
      </div>

      <?php foreach ($items as $item): ?>
        <div class="item-row">
          <p class="item-title"><?= htmlspecialchars($item['title']) ?></p>
          <p class="item-price">Cantidad: <?= (int)$item['quantity'] ?> · <?= money($item['unit_price']) ?> c/u</p>

          <?php if (!empty($item['keys'])): ?>
            <?php foreach ($item['keys'] as $k): ?>
              <?php if ($k['revealed_at']): ?>
                <div class="key-box">
                  <code><?= htmlspecialchars($k['key_value']) ?></code>
                  <button class="copy-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($k['key_value'], ENT_QUOTES) ?>'); this.textContent='¡Copiado!'; setTimeout(() => this.textContent='Copiar', 1500);">Copiar</button>
                </div>
              <?php else: ?>
                <div class="reveal-box">
                  <div class="key-box key-box--hidden">
                    <code>••••••-••••••-••••••-••••••</code>
                    <span class="hidden-label">Clave oculta</span>
                  </div>
                  <form method="post" class="reveal-form" onsubmit="return this.querySelectorAll('input[type=checkbox]:checked').length === 3;">
                    <input type="hidden" name="reveal_redemption_id" value="<?= (int) $k['redemption_id'] ?>">
                    <input type="hidden" name="modo" value="<?= htmlspecialchars($mode) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <input type="hidden" name="pedido" value="<?= htmlspecialchars($orderNumber) ?>">
                    <input type="hidden" name="codigo" value="<?= htmlspecialchars($uniqueCode) ?>">
                    <label class="confirm-row"><input type="checkbox" name="confirm_product" required> Confirmo que <strong><?= htmlspecialchars($item['title']) ?></strong> es el producto que compré y que verifiqué que es compatible con mi equipo</label>
                    <label class="confirm-row"><input type="checkbox" name="confirm_install" required> Voy a realizar la instalación siguiendo los tutoriales antes de reportar cualquier problema</label>
                    <label class="confirm-row"><input type="checkbox" name="confirm_norefund" required> Entiendo que <strong>una vez revelada la clave, el pedido no tiene reembolso</strong></label>
                    <button type="submit" class="btn-primary reveal-btn">🔒 Confirmar y mostrar mi clave</button>
                  </form>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="pending-note">
              <?= $order['financial_status'] === 'pagado' ? 'Recibimos tu pago. Te enviaremos tu clave por correo en breve.' : 'Tu clave te llegará por correo apenas se confirme el pago.' ?>
            </p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="help-row">
        <a class="help-link" href="https://api.whatsapp.com/send?phone=56953993785">💬 WhatsApp</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<footer>
  <div class="wrap">
    <p>© 2026 Alquimia Digital CL</p>
    <div>
      <a href="index.php">Inicio</a>
      <a href="catalogo.php">Catálogo</a>
      <a href="https://api.whatsapp.com/send?phone=56953993785">WhatsApp</a>
    </div>
  </div>
</footer>

</body>
</html>
