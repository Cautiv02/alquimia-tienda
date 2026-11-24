<?php
/**
 * mail-templates-lib.php — textos editables de los correos que recibe el cliente (admin/correos.php).
 * Cada correo tiene asunto y mensaje; el mensaje admite variables como {nombre} o {pedido}.
 * Líneas en blanco separan párrafos. Si un texto está vacío o no se ha editado, se usa el original.
 */
function mail_template_defs(): array {
    return [
        'pago' => [
            'label' => 'Pago recibido (pedido pagado con Mercado Pago o confirmado)',
            'vars'  => ['nombre', 'pedido', 'total', 'productos', 'codigo', 'enlace_pedido', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Recibimos tu pago - Pedido {pedido}',
            'body' => "Hola {nombre},\n\n¡Gracias por tu compra! Recibimos tu pago correctamente y estamos preparando tu(s) clave(s). Te las enviaremos a este correo en breve.\n\nTu pedido {pedido}:\n\n{productos}\n\nPuedes revisar el estado cuando quieras aquí: {enlace_pedido}\nTu código único: {codigo}\n\nSi necesitas ayuda escríbenos a {correo_contacto} o por WhatsApp.\n\n— {tienda}",
        ],
        'transferencia' => [
            'label' => 'Datos para transferir (pedido por transferencia bancaria)',
            'vars'  => ['nombre', 'pedido', 'total', 'productos', 'datos_transferencia', 'nota_transferencia', 'enlace_pedido', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Datos para transferir - Pedido {pedido}',
            'body' => "Hola {nombre},\n\nRecibimos tu pedido {pedido} por {total}. Para completarlo, realiza una transferencia con estos datos:\n\n{datos_transferencia}\n\n{productos}\n\n{nota_transferencia}\n\nEstado de tu pedido: {enlace_pedido}\nSi tienes dudas escríbenos a {correo_contacto}.\n\n— {tienda}",
        ],
        'binance' => [
            'label' => 'Datos para pagar con Binance Pay',
            'vars'  => ['nombre', 'pedido', 'total', 'monto_usdt', 'productos', 'datos_binance', 'nota_binance', 'enlace_pedido', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Datos para pagar con Binance Pay - Pedido {pedido}',
            'body' => "Hola {nombre},\n\nRecibimos tu pedido {pedido} por {total} ({monto_usdt} USDT). Para completarlo, envía el pago por Binance Pay con estos datos:\n\n{datos_binance}\n\n{productos}\n\n{nota_binance}\n\nEstado de tu pedido: {enlace_pedido}\nSi tienes dudas escríbenos a {correo_contacto}.\n\n— {tienda}",
        ],
        'enviado' => [
            'label' => 'Pedido enviado (cuando marcas "entregado")',
            'vars'  => ['nombre', 'pedido', 'total', 'productos', 'boton_resena', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Tu pedido {pedido} fue enviado - {tienda}',
            'body' => "Hola {nombre},\n\nYa te enviamos las claves/datos de tu licencia. Revisa tu correo (y la carpeta de spam) y tu WhatsApp.\n\nSi necesitas ayuda con la activación, escríbenos al WhatsApp +{whatsapp} o responde a este correo.\n\n{boton_resena}\n\nGracias por confiar en {tienda}.",
        ],
        'cancelado' => [
            'label' => 'Pedido cancelado (sin reembolso)',
            'vars'  => ['nombre', 'pedido', 'total', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Tu pedido {pedido} fue cancelado - {tienda}',
            'body' => "Hola {nombre},\n\nTu pedido {pedido} fue cancelado.\n\nSi tienes dudas, escríbenos al WhatsApp +{whatsapp} o responde a este correo.\n\n{tienda}",
        ],
        'cancelado_pendiente' => [
            'label' => 'Pedido cancelado, reembolso en trámite',
            'vars'  => ['nombre', 'pedido', 'total', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Tu pedido {pedido} fue cancelado - {tienda}',
            'body' => "Hola {nombre},\n\nTu pedido {pedido} fue cancelado. Estamos gestionando la devolución de {total} y te avisaremos cuando esté realizada.\n\nSi tienes dudas, escríbenos al WhatsApp +{whatsapp} o responde a este correo.\n\n{tienda}",
        ],
        'reembolsado' => [
            'label' => 'Reembolso realizado',
            'vars'  => ['nombre', 'pedido', 'total', 'correo_contacto', 'whatsapp', 'tienda'],
            'subject' => 'Reembolso de tu pedido {pedido} - {tienda}',
            'body' => "Hola {nombre},\n\nTu pedido {pedido} fue cancelado y ya procesamos el reembolso de {total}.\n\nSegún tu banco o tarjeta, el dinero puede tardar algunos días hábiles en reflejarse. Si pagaste por transferencia, ya te devolvimos el monto a tu cuenta.\n\nSi tienes dudas, escríbenos al WhatsApp +{whatsapp} o responde a este correo.\n\n{tienda}",
        ],
    ];
}

/** Valores por defecto en formato de ajustes: mail_<clave>_subject / mail_<clave>_body */
function mail_template_setting_defaults(): array {
    $o = [];
    foreach (mail_template_defs() as $k => $d) { $o["mail_{$k}_subject"] = $d['subject']; $o["mail_{$k}_body"] = $d['body']; }
    return $o;
}

/** Variables que contienen HTML ya preparado (no se escapan). */
function mail_raw_vars(): array { return ['productos', 'enlace_pedido', 'datos_transferencia', 'nota_transferencia', 'datos_binance', 'nota_binance', 'boton_resena']; }

/**
 * Genera [asunto, html] de un correo. $vars: valores en texto plano (se escapan) y, para las variables "raw", HTML.
 */
function mail_render(string $key, array $vars): array {
    $defs = mail_template_defs();
    $d = $defs[$key];
    $subj = trim(setting("mail_{$key}_subject")) !== '' ? setting("mail_{$key}_subject") : $d['subject'];
    $body = trim(setting("mail_{$key}_body")) !== '' ? setting("mail_{$key}_body") : $d['body'];
    return mail_render_text($subj, $body, $vars);
}

/** Convierte asunto y mensaje (con variables) en [asunto, html]. */
function mail_render_text(string $subj, string $body, array $vars): array {
    $raw = mail_raw_vars();
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $sub = function (string $text, bool $html) use ($vars, $raw, $esc) {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, $raw, $esc, $html) {
            $n = $m[1];
            if (!array_key_exists($n, $vars)) return $m[0];
            if (in_array($n, $raw, true)) return $html ? (string) $vars[$n] : strip_tags((string) $vars[$n]);
            return $html ? $esc($vars[$n]) : (string) $vars[$n];
        }, $text);
    };
    $subject = $sub($subj, false);
    $paras = preg_split('/\R{2,}/', trim(str_replace("\r", '', $body)));
    $out = '';
    foreach ($paras as $p) {
        $p = trim($p); if ($p === '') continue;
        // un párrafo que es solo una variable HTML (lista, botón) va sin <p>
        if (preg_match('/^\{([a-z_]+)\}$/', $p, $mm) && in_array($mm[1], ['productos', 'datos_transferencia', 'datos_binance', 'boton_resena'], true)) { $out .= $sub($p, true); continue; }
        $out .= '<p>' . nl2br($sub(htmlspecialchars_decode_tokens($p), true)) . '</p>';
    }
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#222;line-height:1.5">' . $out . '</div>';
    return [$subject, $html];
}

/** Escapa el texto del párrafo pero conserva las llaves de las variables para sustituirlas después. */
function htmlspecialchars_decode_tokens(string $p): string {
    return preg_replace_callback('/\{[a-z_]+\}|[^{]+|\{/', function ($m) {
        return preg_match('/^\{[a-z_]+\}$/', $m[0]) ? $m[0] : htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
    }, $p);
}

/** Variables comunes de un pedido para usar en las plantillas. */
function mail_order_vars(PDO $pdo, int $orderId): array {
    $st = $pdo->prepare("SELECT o.*, c.first_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = :id");
    $st->execute([':id' => $orderId]); $o = $st->fetch();
    if (!$o) return [];
    $it = $pdo->prepare("SELECT title, quantity FROM order_items WHERE order_id = :id ORDER BY id"); $it->execute([':id' => $orderId]);
    $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $li = ''; foreach ($it->fetchAll() as $i) $li .= '<li>' . (int) $i['quantity'] . ' × ' . $e($i['title']) . '</li>';
    $portal = SITE_URL . '/portal.php?modo=codigo&codigo=' . urlencode((string) $o['unique_code']);
    $total = '$' . number_format((float) $o['total_amount'], 0, ',', '.');
    return [
        'nombre' => trim((string) $o['first_name']) !== '' ? trim((string) $o['first_name']) : 'cliente',
        'pedido' => $o['order_number'], 'total' => $total, 'codigo' => $o['unique_code'],
        'productos' => '<ul>' . $li . '</ul>',
        'enlace_pedido' => '<a href="' . $e($portal) . '">Ver mi pedido</a>',
        'correo_contacto' => NOTIFY_EMAIL, 'whatsapp' => defined('SUPPORT_WHATSAPP') ? SUPPORT_WHATSAPP : '', 'tienda' => SITE_NAME,
        'datos_transferencia' => '<div style="background:#f6f2e6;padding:12px 14px;border-radius:8px;">' . nl2br($e(setting('transfer_details'))) . '<br><br><b>Monto: ' . $e($total) . '</b><br><b>Referencia: ' . $e($o['order_number']) . '</b></div>',
        'nota_transferencia' => nl2br($e(setting('transfer_note'))),
        'monto_usdt' => binance_usdt($o['total_amount']),
        'datos_binance' => '<div style="background:#f6f2e6;padding:12px 14px;border-radius:8px;">' . nl2br($e(setting('binance_details'))) . '<br><br><b>Monto: ' . $e(binance_usdt($o['total_amount'])) . ' USDT</b><br><b>Nota/Referencia: ' . $e($o['order_number']) . '</b></div>',
        'nota_binance' => nl2br($e(setting('binance_note'))),
        'boton_resena' => '',
    ];
}
