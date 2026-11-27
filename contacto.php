<?php
/** contacto.php — Quiénes somos y contacto: identidad del negocio, cómo funciona la compra y canales de soporte. */
require_once __DIR__ . '/delivery-engine.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/seo-lib.php';
$b = biz_info();
seo_set(['canonical' => SITE_URL . '/contacto.php']);
seo_ld_add(['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Quiénes somos y contacto', 'url' => SITE_URL . '/contacto.php']);
site_header('Quiénes somos y contacto', 'Quiénes somos, datos de la empresa, formas de pago, entrega de licencias digitales y canales de soporte de ' . SITE_NAME . '.');
$wa = 'https://api.whatsapp.com/send?phone=' . h($b['wa']);
?>
<style>.legal { max-width: 820px; margin: 0 auto; } .legal h2 { font-family: var(--sans); font-size: 1.1rem; font-weight: 600; margin: 28px 0 10px; } .legal p, .legal li { color: var(--text-dim); font-size: .95rem; line-height: 1.7; } .legal ul { padding-left: 20px; }</style>
<div class="wrap page"><div class="panel legal">
  <h1>Quiénes somos y contacto</h1>
  <p><?= h(SITE_NAME) ?> es una tienda online de licencias digitales de software (Windows, Office, antivirus, Adobe, Autodesk y otros) que atiende a clientes en Chile y Latinoamérica desde 2018. Todos nuestros productos son digitales: no enviamos nada físico.</p>

  <h2>Datos de la empresa</h2>
  <ul>
    <li><b>Nombre:</b> <?= h($b['name']) ?></li>
    <?php if ($b['rut'] !== ''): ?><li><b>RUT:</b> <?= h($b['rut']) ?></li><?php endif; ?>
    <?php if ($b['address'] !== ''): ?><li><b>Dirección:</b> <?= h($b['address']) ?></li><?php endif; ?>
    <li><b>Correo:</b> <a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></li>
    <li><b>WhatsApp:</b> <a href="<?= $wa ?>" rel="noopener" target="_blank">+<?= h($b['wa']) ?></a></li>
    <?php if ($b['hours'] !== ''): ?><li><b>Atención:</b> <?= h($b['hours']) ?></li><?php endif; ?>
  </ul>

  <h2>Cómo funciona la compra</h2>
  <ul>
    <li>Eliges el producto y pagas con Mercado Pago (tarjetas y otros medios), transferencia bancaria o Binance Pay (USDT).</li>
    <li>Con Mercado Pago, recibes tu licencia en tu correo una vez confirmado el pago. Con transferencia o Binance Pay, la enviamos apenas verificamos tu pago.</li>
    <li>Puedes consultar el estado de tu pedido en <a href="portal.php">Consultar mi pedido</a> con tu número de pedido y correo.</li>
    <li>Si algo falla con tu licencia, te ayudamos por WhatsApp o correo. Revisa nuestras políticas de <a href="legal.php?p=reembolso">reembolso y cambios</a>, <a href="legal.php?p=envios">envío</a>, <a href="legal.php?p=terminos">términos</a> y <a href="legal.php?p=privacidad">privacidad</a>.</li>
  </ul>

  <h2>Opiniones de clientes</h2>
  <p>Puedes leer las opiniones de quienes ya compraron en <a href="resenas.php">nuestra página de opiniones</a>.</p>

  <h2>Escríbenos</h2>
  <p><a class="btn-gold" href="<?= $wa ?>" rel="noopener" target="_blank">Hablar por WhatsApp</a> &nbsp; <a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></p>
</div></div>
<?php site_footer(); ?>
