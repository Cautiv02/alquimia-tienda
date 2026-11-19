<?php
/**
 * cart-lib.php — carrito basado en una cookie (no usa sesión PHP, así no choca con el panel admin).
 * Guarda solo {variant_id: cantidad}. Los precios SIEMPRE se leen de la base de datos.
 */
const CART_COOKIE = 'alq_cart';
const CART_MAX_LINES = 20;
const CART_MAX_QTY = 10;

function cart_get(): array {
    $raw = $_COOKIE[CART_COOKIE] ?? '';
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    $out = [];
    if (is_array($data)) {
        foreach ($data as $vid => $qty) {
            $vid = (int) $vid; $qty = (int) $qty;
            if ($vid > 0 && $qty > 0) $out[$vid] = min(CART_MAX_QTY, $qty);
            if (count($out) >= CART_MAX_LINES) break;
        }
    }
    return $out;
}
function cart_save(array $cart): void {
    $_COOKIE[CART_COOKIE] = $cart ? json_encode($cart) : '';
    if (headers_sent()) return;
    if ($cart) setcookie(CART_COOKIE, json_encode($cart), ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    else setcookie(CART_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}
function cart_count(): int { return array_sum(cart_get()); }
