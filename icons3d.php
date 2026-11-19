<?php
/** icons3d.php — íconos con volumen (SVG en línea, sin imágenes externas). icon3d('pago'|'entrega'|'activa'|'rayo'|'escudo'|'chat') */
function icon3d(string $name, int $size = 64): string {
    static $n = 0; $n++; $id = 'ic' . $n;
    $tiles = [
        'pago'    => ['#7aa2ff', '#2b57d6'], 'entrega' => ['#52d6c2', '#178a7c'], 'activa' => ['#ffc15e', '#e7761f'],
        'rayo'    => ['#ffd66b', '#e59a12'], 'escudo'  => ['#6fdc9a', '#1f9a58'], 'chat'    => ['#7fb4ff', '#2f6fd8'],
    ];
    [$c1, $c2] = $tiles[$name] ?? $tiles['pago'];
    $glyph = [
        'pago' => '<g transform="rotate(-8 32 32)"><rect x="13" y="19" width="38" height="26" rx="5" fill="#fff"/><rect x="13" y="25" width="38" height="6" fill="#2b57d6" opacity=".85"/><rect x="18" y="36" width="12" height="4" rx="2" fill="#b9c8f2"/><circle cx="43" cy="38" r="3" fill="#ffc15e"/></g>',
        'entrega' => '<rect x="12" y="20" width="40" height="26" rx="5" fill="#fff"/><path d="M13 23l19 14 19-14" fill="none" stroke="#178a7c" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/><circle cx="46" cy="44" r="8" fill="#ffc15e"/><path d="M42.5 44.2l2.6 2.6 4.6-5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>',
        'activa' => '<path d="M32 10c9 6 12 17 9 30H23c-3-13 0-24 9-30z" fill="#fff"/><circle cx="32" cy="28" r="5" fill="#7aa2ff"/><path d="M23 34l-7 9 8-2zM41 34l7 9-8-2z" fill="#fff" opacity=".9"/><path d="M27 42h10l-5 12z" fill="#ffe08a"/>',
        'rayo' => '<path d="M36 10L18 35h12l-4 19 20-27H34z" fill="#fff"/>',
        'escudo' => '<path d="M32 11l16 6v12c0 11-7 19-16 23-9-4-16-12-16-23V17z" fill="#fff"/><path d="M24 32l6 6 11-12" fill="none" stroke="#1f9a58" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>',
        'chat' => '<path d="M14 18h36a4 4 0 014 4v18a4 4 0 01-4 4H34l-10 9v-9H14a4 4 0 01-4-4V22a4 4 0 014-4z" fill="#fff"/><circle cx="22" cy="31" r="3" fill="#2f6fd8"/><circle cx="32" cy="31" r="3" fill="#2f6fd8"/><circle cx="42" cy="31" r="3" fill="#2f6fd8"/>',
    ][$name] ?? '';
    return '<svg class="ic3d" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<defs><linearGradient id="' . $id . 'g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient>'
        . '<linearGradient id="' . $id . 'h" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>'
        . '<rect x="4" y="4" width="56" height="56" rx="17" fill="url(#' . $id . 'g)"/>'
        . '<rect x="4" y="4" width="56" height="30" rx="17" fill="url(#' . $id . 'h)"/>'
        . '<g style="filter:drop-shadow(0 3px 2px rgba(0,0,0,.28))">' . $glyph . '</g></svg>';
}
