<?php
/* ──────────────────────────────────────────────────────────────────────
   IMAGEN DEL RESUMEN DE WORDELON PARA DISCORD (decidido así)
   ──────────────────────────────────────────────────────────────────────
   Una tarjeta por jugador que ha terminado, por orden de llegada, en
   filas de WIMG_POR_FILA, sobre fondo transparente. Cada tarjeta va con
   la INTERFAZ y el TEMA de su jugador:
     - interfaz: user_settings.active_interface → tienda_items.slug
       (win98 si no tiene ninguna puesta);
     - colores: su tema activo de esa interfaz (tabla themes), encima de
       los colores por defecto de la interfaz.
   Win98 = ventana con biseles y barra de título en degradado; Kawaii =
   marco grueso con la barra y el cuerpo como tarjetas sueltas (igual que
   assets/interfaces/kawaii/style.css). Dentro: foto de perfil redonda,
   nombre, resultado (3/6 o X/6) y la cuadrícula de 6 filas (las que no
   se usaron, vacías). Sin letras: no destripa la palabra.

   Se dibuja a 1x y se amplía x2 sin suavizar (las fuentes pixel quedan
   nítidas); la foto se pega después ya a 2x para que no se pixele.
   Fuentes TTF en assets/juegos/fuentes/ (GD no lee woff2): ms_sans_serif
   convertida de assets/css (98.css) y Pixelify Sans (OFL, la de Kawaii).
   Si el servidor no tiene GD con FreeType, wimg_render devuelve null y
   el bot manda el resumen en texto como antes.
   ────────────────────────────────────────────────────────────────────── */

const WIMG_POR_FILA = 5;
const WIMG_W        = 112;   /* ancho de una tarjeta a 1x */
const WIMG_H        = 236;   /* alto de una tarjeta a 1x */
const WIMG_GAP      = 8;     /* separación entre tarjetas a 1x */
const WIMG_ESCALA   = 2;
const WIMG_AVATAR   = 56;    /* diámetro de la foto a 1x */
const WIMG_CASILLA  = 14;
const WIMG_HUECO    = 2;
const WIMG_PISTAS   = ['ok' => '#3f9a3f', 'cerca' => '#c99a0e', 'no' => '#6b6b6b'];

/* Colores por defecto de cada interfaz (mismos que tokens.css /
   $THEME_DEFAULTS para Win98 y el :root de kawaii/style.css). */
const WIMG_BASE = [
    'win98' => [
        'winBg' => '#c0c0c0', 'winBodyBg' => '#c0c0c0', 'text' => '#000000', 'textMuted' => '#666666',
        'titlebarStart' => '#000080', 'titlebarEnd' => '#1084d0', 'titlebarText' => '#ffffff',
        'inputBg' => '#ffffff', 'border' => '#808080', 'accent' => '#000080', 'accentText' => '#ffffff',
        'bezelLight1' => '#ffffff', 'bezelLight2' => '#dfdfdf', 'bezelDark1' => '#0a0a0a', 'bezelDark2' => '#808080',
    ],
    'kawaii' => [
        'winBg' => '#E0D4E7', 'winBodyBg' => '#F0E8F3', 'text' => '#472CC7', 'textMuted' => '#6B5BA8',
        'titlebarStart' => '#F0E8F3', 'titlebarEnd' => '#F0E8F3', 'titlebarText' => '#472CC7',
        'inputBg' => '#FFFFFF', 'border' => '#49388B', 'accent' => '#FF66B2', 'accentText' => '#ffffff',
        'bezelLight1' => '#ffffff', 'bezelLight2' => '#dfdfdf', 'bezelDark1' => '#0a0a0a', 'bezelDark2' => '#49388B',
    ],
];
const WIMG_LEGACY = [
    'bg' => 'winBg', 'windowBg' => 'winBg', 'windowText' => 'text',
    'titleBarStart' => 'titlebarStart', 'titleBarEnd' => 'titlebarEnd', 'titleBarText' => 'titlebarText',
    'windowShadow' => 'bezelDark1',
];

function wimg_disponible(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagettftext') && function_exists('imagepng');
}

/* Interfaz y colores de un jugador. */
function wimg_look(PDO $pdo, int $uid): array {
    $iface = 'win98';
    try {
        $st = $pdo->prepare("SELECT i.slug FROM user_settings s JOIN tienda_items i ON i.id = s.value
                              WHERE s.user_id = ? AND s.key_name = 'active_interface'");
        $st->execute([$uid]);
        $slug = (string)$st->fetchColumn();
        if (isset(WIMG_BASE[$slug])) $iface = $slug;
    } catch (Throwable $e) { /* sin tablas: Win98 */ }
    $c = WIMG_BASE[$iface];
    try {
        $st = $pdo->prepare("SELECT colors FROM themes WHERE user_id = ? AND interface_name = ? AND is_active = 1 LIMIT 1");
        $st->execute([$uid, $iface]);
        $tema = json_decode((string)$st->fetchColumn(), true);
        if (is_array($tema)) {
            foreach ($tema as $k => $v) {
                $k = WIMG_LEGACY[$k] ?? $k;
                if (isset($c[$k]) && is_string($v) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) $c[$k] = $v;
            }
        }
    } catch (Throwable $e) { /* sin tema: colores de la interfaz */ }
    return ['iface' => $iface, 'c' => $c];
}

function wimg_rgb(string $hex): array {
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}
function wimg_col($im, string $hex, int $alpha = 0): int {
    [$r, $g, $b] = wimg_rgb($hex);
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}
/* Mezcla un color con blanco (t > 0) o negro (t < 0), para los cantos
   de las casillas de colores. */
function wimg_tinte(string $hex, float $t): string {
    [$r, $g, $b] = wimg_rgb($hex);
    $f = fn($v) => (int)round($t >= 0 ? $v + (255 - $v) * $t : $v * (1 + $t));
    return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
}

function wimg_fuente(string $iface, bool $negrita): string {
    $d = __DIR__ . '/fuentes/';
    if ($iface === 'kawaii') return $d . 'PixelifySans.ttf';
    return $d . ($negrita ? 'ms_sans_serif_bold.ttf' : 'ms_sans_serif.ttf');
}
/* Tamaño en puntos de cada fuente para que salga a su tamaño de píxel
   (GD trabaja a 96 ppp). */
function wimg_pt(string $iface): float {
    return $iface === 'kawaii' ? 8.0 : 8.25;
}

function wimg_ancho(string $txt, string $font, float $pt): int {
    $b = imagettfbbox($pt, 0, $font, $txt);
    return $b ? (int)abs($b[2] - $b[0]) : 0;
}
/* Recorta con "…" hasta que quepa en $max píxeles. */
function wimg_cabe(string $txt, string $font, float $pt, int $max): string {
    if (wimg_ancho($txt, $font, $pt) <= $max) return $txt;
    while (mb_strlen($txt) > 1 && wimg_ancho($txt . '…', $font, $pt) > $max) $txt = mb_substr($txt, 0, -1);
    return $txt . '…';
}
/* Texto sin suavizado (color negativo en GD), centrado en $cx si se pide. */
function wimg_texto($im, string $txt, float $pt, string $font, string $hex, int $x, int $base, bool $centrar = false): void {
    if ($centrar) $x = (int)round($x - wimg_ancho($txt, $font, $pt) / 2);
    imagettftext($im, $pt, 0, $x, $base, -wimg_col($im, $hex), $font, $txt);
}

function wimg_rect($im, int $x, int $y, int $w, int $h, string $hex): void {
    imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, wimg_col($im, $hex));
}
/* Marco de 1 px: claro arriba-izquierda, oscuro abajo-derecha. */
function wimg_bisel($im, int $x, int $y, int $w, int $h, string $claro, string $oscuro): void {
    $cl = wimg_col($im, $claro); $os = wimg_col($im, $oscuro);
    imageline($im, $x, $y, $x + $w - 2, $y, $cl);
    imageline($im, $x, $y, $x, $y + $h - 2, $cl);
    imageline($im, $x, $y + $h - 1, $x + $w - 1, $y + $h - 1, $os);
    imageline($im, $x + $w - 1, $y, $x + $w - 1, $y + $h - 1, $os);
}
function wimg_borde($im, int $x, int $y, int $w, int $h, string $hex, int $grosor): void {
    $c = wimg_col($im, $hex);
    for ($i = 0; $i < $grosor; $i++) imagerectangle($im, $x + $i, $y + $i, $x + $w - 1 - $i, $y + $h - 1 - $i, $c);
}

/* Una tarjeta a 1x. Devuelve [imagen, x de la foto, y de la foto]. */
function wimg_tarjeta(array $look, string $nombre, string $resultado, array $filas, int $maximo): array {
    $W = WIMG_W; $H = WIMG_H; $c = $look['c']; $kw = $look['iface'] === 'kawaii';
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $pt = wimg_pt($look['iface']);

    if ($kw) {
        /* Marco grueso + barra y cuerpo como tarjetas con su borde. */
        wimg_rect($im, 0, 0, $W, $H, $c['winBg']);
        wimg_borde($im, 0, 0, $W, $H, $c['border'], 2);
        wimg_rect($im, 6, 6, $W - 12, 18, $c['winBodyBg']);
        wimg_borde($im, 6, 6, $W - 12, 18, $c['border'], 2);
        wimg_texto($im, 'Wordelon', $pt, wimg_fuente('kawaii', true), $c['titlebarText'], 12, 19);
        wimg_rect($im, 6, 28, $W - 12, $H - 34, $c['winBodyBg']);
        wimg_borde($im, 6, 28, $W - 12, $H - 34, $c['border'], 2);
        $top = 38;
    } else {
        /* Ventana Win98: bisel doble, barra con degradado. */
        wimg_rect($im, 0, 0, $W, $H, $c['winBg']);
        wimg_bisel($im, 0, 0, $W, $H, $c['bezelLight2'], $c['bezelDark1']);
        wimg_bisel($im, 1, 1, $W - 2, $H - 2, $c['bezelLight1'], $c['bezelDark2']);
        [$r1, $g1, $b1] = wimg_rgb($c['titlebarStart']);
        [$r2, $g2, $b2] = wimg_rgb($c['titlebarEnd']);
        $bw = $W - 6;
        for ($i = 0; $i < $bw; $i++) {
            $t = $bw > 1 ? $i / ($bw - 1) : 0;
            $col = imagecolorallocate($im, (int)($r1 + ($r2 - $r1) * $t), (int)($g1 + ($g2 - $g1) * $t), (int)($b1 + ($b2 - $b1) * $t));
            imageline($im, 3 + $i, 3, 3 + $i, 20, $col);
        }
        wimg_texto($im, 'Wordelon', $pt, wimg_fuente('win98', true), $c['titlebarText'], 6, 16);
        $top = 32;
    }

    /* Foto: aquí solo se reserva el sitio; se pega luego a 2x. */
    $ax = (int)(($W - WIMG_AVATAR) / 2);
    $ay = $top;

    $fn = wimg_fuente($look['iface'], true);
    $ff = wimg_fuente($look['iface'], false);
    $y = $top + WIMG_AVATAR + 14;
    wimg_texto($im, wimg_cabe($nombre, $fn, $pt, $W - 16), $pt, $fn, $c['text'], (int)($W / 2), $y, true);
    wimg_texto($im, $resultado, $pt, $ff, $c['textMuted'], (int)($W / 2), $y + 13, true);

    /* Cuadrícula. */
    $gw = 5 * WIMG_CASILLA + 4 * WIMG_HUECO;
    $gx = (int)(($W - $gw) / 2);
    $gy = $y + 21;
    for ($r = 0; $r < $maximo; $r++) {
        for ($k = 0; $k < 5; $k++) {
            $x = $gx + $k * (WIMG_CASILLA + WIMG_HUECO);
            $yy = $gy + $r * (WIMG_CASILLA + WIMG_HUECO);
            $p = $filas[$r][$k] ?? null;
            if ($p) {
                $hex = WIMG_PISTAS[$p];
                wimg_rect($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, $hex);
                wimg_bisel($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, wimg_tinte($hex, .35), wimg_tinte($hex, -.35));
            } elseif ($kw) {
                wimg_rect($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, $c['inputBg']);
                wimg_borde($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, $c['border'], 1);
            } else {
                wimg_rect($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, $c['inputBg']);
                wimg_bisel($im, $x, $yy, WIMG_CASILLA, WIMG_CASILLA, $c['bezelDark2'], $c['bezelLight1']);
                wimg_bisel($im, $x + 1, $yy + 1, WIMG_CASILLA - 2, WIMG_CASILLA - 2, $c['bezelDark1'], $c['bezelLight2']);
            }
        }
    }
    return [$im, $ax, $ay];
}

/* Foto redonda a 2x con su aro. Si no tiene foto: círculo del color de
   acento con la inicial. */
function wimg_avatar(array $look, string $label, ?string $ruta): GdImage {
    $D = WIMG_AVATAR * WIMG_ESCALA; $c = $look['c'];
    $out = imagecreatetruecolor($D, $D);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));

    $src = null;
    if ($ruta && is_file($ruta)) $src = @imagecreatefromstring((string)file_get_contents($ruta));
    if ($src) {
        $w = imagesx($src); $h = imagesy($src); $l = min($w, $h);
        imagealphablending($out, true);
        imagecopyresampled($out, $src, 0, 0, (int)(($w - $l) / 2), (int)(($h - $l) / 2), $D, $D, $l, $l);
        imagedestroy($src);
    } else {
        imagealphablending($out, true);
        imagefilledrectangle($out, 0, 0, $D - 1, $D - 1, wimg_col($out, $c['accent']));
        $ini = mb_strtoupper(mb_substr($label, 0, 1)) ?: '?';
        $f = wimg_fuente($look['iface'], true);
        $pt = 26;
        $b = imagettfbbox($pt, 0, $f, $ini);
        $tw = abs($b[2] - $b[0]); $th = abs($b[7] - $b[1]);
        imagettftext($out, $pt, 0, (int)(($D - $tw) / 2 - $b[0]), (int)(($D + $th) / 2), -wimg_col($out, $c['accentText']), $f, $ini);
    }

    /* Recorte redondo con borde suave y aro de 2 px (1 px a 1x). */
    imagealphablending($out, false);
    $aro = wimg_rgb($look['iface'] === 'kawaii' ? $c['border'] : $c['bezelDark1']);
    $rad = $D / 2; $grosor = 2.0;
    for ($y = 0; $y < $D; $y++) {
        for ($x = 0; $x < $D; $x++) {
            $d = sqrt(($x + .5 - $rad) ** 2 + ($y + .5 - $rad) ** 2);
            $fuera = $d - ($rad - .5);
            if ($fuera >= 1) { imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0, 0, 0, 127)); continue; }
            $rgba = imagecolorat($out, $x, $y);
            $r = ($rgba >> 16) & 255; $g = ($rgba >> 8) & 255; $b = $rgba & 255;
            $mez = max(0.0, min(1.0, $d - ($rad - $grosor - .5)));   /* 0 dentro … 1 en el aro */
            $r = (int)($r + ($aro[0] - $r) * $mez); $g = (int)($g + ($aro[1] - $g) * $mez); $b = (int)($b + ($aro[2] - $b) * $mez);
            $a = $fuera > 0 ? (int)round(127 * $fuera) : 0;
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $a));
        }
    }
    return $out;
}

/* PNG con todas las tarjetas, o null si no se puede dibujar. $porFila:
   5 en Discord; la pantalla de Estadísticas de la app pide menos para
   que quepan en la ventana sin encogerse.
   $jugadores: [{uid, label, ruta (foto o null), resultado, filas (pistas)}]. */
function wimg_render(PDO $pdo, array $jugadores, int $maximo, int $porFila = WIMG_POR_FILA): ?string {
    if (!wimg_disponible() || !$jugadores) return null;
    $porFila = max(1, min($porFila, WIMG_POR_FILA));
    $n = count($jugadores);
    $cols = min($n, $porFila);
    $rows = (int)ceil($n / $porFila);
    $S = WIMG_ESCALA;
    $W = ($cols * WIMG_W + ($cols - 1) * WIMG_GAP) * $S;
    $H = ($rows * WIMG_H + ($rows - 1) * WIMG_GAP) * $S;
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

    foreach (array_values($jugadores) as $i => $j) {
        $look = wimg_look($pdo, (int)$j['uid']);
        [$card, $ax, $ay] = wimg_tarjeta($look, (string)$j['label'], (string)$j['resultado'], $j['filas'], $maximo);
        $ox = ($i % $porFila) * (WIMG_W + WIMG_GAP) * $S;
        $oy = intdiv($i, $porFila) * (WIMG_H + WIMG_GAP) * $S;
        imagealphablending($img, false);
        imagecopyresized($img, $card, $ox, $oy, 0, 0, WIMG_W * $S, WIMG_H * $S, WIMG_W, WIMG_H);
        imagedestroy($card);
        $av = wimg_avatar($look, (string)$j['label'], $j['ruta'] ?? null);
        imagealphablending($img, true);
        imagecopy($img, $av, $ox + $ax * $S, $oy + $ay * $S, 0, 0, imagesx($av), imagesy($av));
        imagedestroy($av);
    }
    ob_start();
    imagepng($img, null, 9);
    imagedestroy($img);
    return (string)ob_get_clean();
}
