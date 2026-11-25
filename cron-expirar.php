<?php
/** cron-expirar.php?k=CLAVE — para la tarea programada de cPanel: cancela los pedidos Binance vencidos. */
require_once __DIR__ . '/order-expiry-lib.php';
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals(expiry_cron_key(), (string) ($_GET['k'] ?? ''))) { http_response_code(403); echo 'no'; exit; }
echo 'ok ' . expire_binance_orders(get_pdo());
