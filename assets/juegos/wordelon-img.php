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
   assets/interfaces/kawaii/style.css). Dentro: foto de perfil cuadrada
   con el MARCO de perfil que lleve puesto (marcos.php), nombre,
   resultado (3/6 o X/6) y la cuadrícula de 6 filas (las que no se
   usaron, vacías). Sin letras: no destripa la palabra.

   Se dibuja a 1x y se amplía x2 sin suavizar (las fuentes pixel quedan
   nítidas); la foto y el marco se pegan después ya a la escala final
   para que no se pixelen.
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

/* ── Marcos de perfil (assets/profile/marcos.php) ─────────────────────
   Lo que sobresale cada marco por arriba / derecha / abajo / izquierda,
   en fracción del lado de la foto: COPIA de --mk-t/r/b/l de
   assets/css/marcos.css (si se añade un marco con otras medidas, hay que
   ponerlo también aquí). Las banderas son un anillo del 8 % hecho a mano
   (en la web es CSS); el fantasma, que en la web da vueltas animado, sale
   quieto en la esquina de arriba a la izquierda (donde empieza). */
const WIMG_MK_SERIE = [.14, .14, .14, .14];
const WIMG_MK = [
    'marco-paint'     => [.1333, .1083, .2167, .2833],
    'marco-polaroid'  => [.1867, .1733, .3467, .1467],
    'marco-98'        => [.2133, .04, .04, .04],
    'marco-overdose'  => [.28, .0533, .0533, .0533],
    'marco-gatito'    => [.23, .17, .07, .17],
    'marco-fantasma'  => [.27, .24, .27, .24],
    'marco-bi'        => [.08, .08, .08, .08],
    'marco-gay'       => [.08, .08, .08, .08],
    'marco-trans'     => [.08, .08, .08, .08],
    'marco-nobinario' => [.08, .08, .08, .08],
    'marco-lesbiana'  => [.08, .08, .08, .08],
];
/* Franjas de las banderas (de arriba abajo): [color, hasta qué fracción]. */
const WIMG_BANDERAS = [
    'marco-bi'        => [['#d60270', .40], ['#9b4f96', .60], ['#0038a8', 1]],
    'marco-gay'       => [['#e40303', 1 / 6], ['#ff8c00', 2 / 6], ['#ffed00', 3 / 6], ['#008026', 4 / 6], ['#24408e', 5 / 6], ['#732982', 1]],
    'marco-trans'     => [['#5bcefa', .2], ['#f5a9b8', .4], ['#ffffff', .6], ['#f5a9b8', .8], ['#5bcefa', 1]],
    'marco-nobinario' => [['#fcf434', .25], ['#ffffff', .5], ['#9c59d1', .75], ['#2c2c2c', 1]],
    'marco-lesbiana'  => [['#d52d00', .2], ['#ff9a56', .4], ['#ffffff', .6], ['#d362a4', .8], ['#a30262', 1]],
];

/* Marco puesto del jugador: ['slug' => …, 'png' => ruta o null] o null. */
function wimg_marco(PDO $pdo, int $uid): ?array {
    try {
        require_once dirname(__DIR__) . '/profile/marcos.php';
        $slug = marcos_activo($pdo, $uid);
        if (!$slug) return null;
        $def = marcos_defs()[$slug] ?? null;
        if (!$def) return null;
        $png = null;
        if ($def['tipo'] === 'imagen') {
            $png = dirname(__DIR__, 2) . '/' . ltrim((string)$def['src'], '/');
            if (!is_file($png)) return null;
        } elseif (!isset(WIMG_BANDERAS[$slug]) && $slug !== 'marco-fantasma') {
            return null;   /* marco CSS nuevo que aquí no se sabe dibujar */
        }
        return ['slug' => $slug, 'png' => $png];
    } catch (Throwable $e) { return null; }
}
function wimg_mk(?array $marco): array {
    if (!$marco) return [0, 0, 0, 0];
    return WIMG_MK[$marco['slug']] ?? WIMG_MK_SERIE;
}

/* Una tarjeta a 1x. $mt / $mb = sitio que se deja encima y debajo de la
   foto para los marcos (el mayor de los de la imagen, para que todas las
   tarjetas midan lo mismo). Devuelve [imagen, x de la foto, y de la foto]. */
function wimg_tarjeta(array $look, string $nombre, string $resultado, array $filas, int $maximo, int $mt, int $mb): array {
    $W = WIMG_W; $H = WIMG_H + $mt + $mb; $c = $look['c']; $kw = $look['iface'] === 'kawaii';
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

    /* Foto (y su marco): aquí solo se reserva el sitio; se pegan luego ya
       a la escala final. */
    $ax = (int)(($W - WIMG_AVATAR) / 2);
    $ay = $top + $mt;

    $fn = wimg_fuente($look['iface'], true);
    $ff = wimg_fuente($look['iface'], false);
    $y = $ay + WIMG_AVATAR + $mb + 14;
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

/* Foto CUADRADA a la escala final, con un borde fino (del color de borde
   de la interfaz). Si no tiene foto: cuadrado del color de acento con la
   inicial. */
function wimg_avatar(array $look, string $label, ?string $ruta, int $S = WIMG_ESCALA): GdImage {
    $D = WIMG_AVATAR * $S; $c = $look['c'];
    $out = imagecreatetruecolor($D, $D);
    imagealphablending($out, true);
    $src = null;
    if ($ruta && is_file($ruta)) $src = @imagecreatefromstring((string)file_get_contents($ruta));
    if ($src) {
        $w = imagesx($src); $h = imagesy($src); $l = min($w, $h);
        imagecopyresampled($out, $src, 0, 0, (int)(($w - $l) / 2), (int)(($h - $l) / 2), $D, $D, $l, $l);
        imagedestroy($src);
    } else {
        imagefilledrectangle($out, 0, 0, $D - 1, $D - 1, wimg_col($out, $c['accent']));
        $ini = mb_strtoupper(mb_substr($label, 0, 1)) ?: '?';
        $f = wimg_fuente($look['iface'], true);
        $pt = 13 * $S;
        $b = imagettfbbox($pt, 0, $f, $ini);
        $tw = abs($b[2] - $b[0]); $th = abs($b[7] - $b[1]);
        imagettftext($out, $pt, 0, (int)(($D - $tw) / 2 - $b[0]), (int)(($D + $th) / 2), -wimg_col($out, $c['accentText']), $f, $ini);
    }
    wimg_borde($out, 0, 0, $D, $D, $look['iface'] === 'kawaii' ? $c['border'] : $c['bezelDark1'], $S);
    return $out;
}

/* Dibuja el marco encima de la foto, que está en ($x, $y) y mide $D
   (todo ya a la escala final). */
function wimg_pintaMarco(GdImage $img, array $marco, int $x, int $y, int $D, int $S): void {
    [$t, $r, $b, $l] = wimg_mk($marco);
    imagealphablending($img, true);
    if ($marco['png']) {
        $src = @imagecreatefrompng($marco['png']);
        if (!$src) return;
        imagealphablending($src, true);
        imagecopyresampled($img, $src, (int)round($x - $l * $D), (int)round($y - $t * $D),
            0, 0, (int)round($D * (1 + $l + $r)), (int)round($D * (1 + $t + $b)), imagesx($src), imagesy($src));
        imagedestroy($src);
        return;
    }
    if (isset(WIMG_BANDERAS[$marco['slug']])) {
        /* Anillo del 8 % con las franjas de arriba abajo de todo el marco
           y un borde fino oscuro por fuera (como el ::before de la web). */
        $g = (int)round(.08 * $D);
        $bx = $x - $g - $S; $by = $y - $g - $S; $B = $D + 2 * ($g + $S);
        $franjas = WIMG_BANDERAS[$marco['slug']];
        for ($yy = 0; $yy < $B; $yy++) {
            $f = ($yy + .5) / $B;
            foreach ($franjas as [$hex, $hasta]) { if ($f <= $hasta) break; }
            $col = wimg_col($img, $hex);
            $dentro = $yy >= $g + $S && $yy < $B - $g - $S;
            if ($dentro) {
                imagefilledrectangle($img, $bx, $by + $yy, $bx + $g + $S - 1, $by + $yy, $col);
                imagefilledrectangle($img, $bx + $B - $g - $S, $by + $yy, $bx + $B - 1, $by + $yy, $col);
            } else {
                imagefilledrectangle($img, $bx, $by + $yy, $bx + $B - 1, $by + $yy, $col);
            }
        }
        $borde = imagecolorallocatealpha($img, 0, 0, 0, 83);   /* rgba(0,0,0,.35) */
        for ($i = 0; $i < $S; $i++) imagerectangle($img, $bx + $i, $by + $i, $bx + $B - 1 - $i, $by + $B - 1 - $i, $borde);
        return;
    }
    if ($marco['slug'] === 'marco-fantasma') wimg_fantasma($img, $x, (int)round($y - .06 * $D), $D);
}

/* El fantasma del marco (mismo dibujo que el SVG de marcos.css, viewBox
   40×46), centrado en ($cx, $cy), de .36 × .414 del lado de la foto. Se
   dibuja a 4x y se reduce para que los bordes salgan suaves. */
function wimg_fantasma(GdImage $img, int $cx, int $cy, int $D): void {
    $w = (int)round(.36 * $D); $h = (int)round(.414 * $D);
    $k = 4; $W = $w * $k; $H = $h * $k;
    $g = imagecreatetruecolor($W, $H);
    imagealphablending($g, false);
    imagefill($g, 0, 0, imagecolorallocatealpha($g, 0, 0, 0, 127));
    imagealphablending($g, true);
    $sx = $W / 40; $sy = $H / 46;
    $P = fn($px, $py) => [$px * $sx, $py * $sy];
    /* Contorno: cúpula + lados + bajo ondulado (curvas cuadráticas). */
    $pts = [];
    for ($i = 0; $i <= 24; $i++) {                       /* cúpula de (6,22) a (34,22) */
        $a = M_PI - M_PI * $i / 24;
        $pts[] = $P(20 + 14 * cos($a), 22 - 19 * sin($a));
    }
    $pts[] = $P(34, 38);
    $q = function ($p0, $c, $p1) use (&$pts, $P) {
        for ($i = 1; $i <= 6; $i++) {
            $t = $i / 6;
            $pts[] = $P((1 - $t) ** 2 * $p0[0] + 2 * (1 - $t) * $t * $c[0] + $t * $t * $p1[0],
                        (1 - $t) ** 2 * $p0[1] + 2 * (1 - $t) * $t * $c[1] + $t * $t * $p1[1]);
        }
    };
    $q([34, 38], [31, 42], [28, 38]);
    $q([28, 38], [25, 34], [22, 38]);
    $q([22, 38], [19, 42], [16, 38]);
    $q([16, 38], [13, 34], [10, 38]);
    $q([10, 38], [7, 42], [6, 38]);
    $flat = [];
    foreach ($pts as [$px, $py]) { $flat[] = (int)round($px); $flat[] = (int)round($py); }
    $linea = max(1, (int)round(1.6 * $sx));
    imagesetthickness($g, $linea);
    imagefilledpolygon($g, $flat, wimg_col($g, '#f7f7f4'));
    imagepolygon($g, $flat, wimg_col($g, '#2b2b2b'));
    imagesetthickness($g, 1);
    $ojo = wimg_col($g, '#1a1a1a');
    imagefilledellipse($g, (int)(15 * $sx), (int)(19 * $sy), (int)(5.2 * $sx), (int)(7.2 * $sy), $ojo);
    imagefilledellipse($g, (int)(25 * $sx), (int)(19 * $sy), (int)(5.2 * $sx), (int)(7.2 * $sy), $ojo);
    imagefilledellipse($g, (int)(20 * $sx), (int)(27 * $sy), (int)(3.2 * $sx), (int)(4.2 * $sy), $ojo);
    imagealphablending($img, true);
    imagecopyresampled($img, $g, $cx - intdiv($w, 2), $cy - intdiv($h, 2), 0, 0, $w, $h, $W, $H);
    imagedestroy($g);
}

/* PNG con todas las tarjetas, o null si no se puede dibujar. $porFila:
   5 en Discord; la pantalla de Estadísticas de la app pide las que
   quepan en la ventana. $escala: 2 en Discord; 1 en la app (más
   pequeñas, y la letra pixel sigue nítida a su tamaño).
   $jugadores: [{uid, label, ruta (foto o null), resultado, filas (pistas)}]. */
function wimg_render(PDO $pdo, array $jugadores, int $maximo, int $porFila = WIMG_POR_FILA, int $escala = WIMG_ESCALA): ?string {
    if (!wimg_disponible() || !$jugadores) return null;
    $porFila = max(1, min($porFila, WIMG_POR_FILA));
    $jugadores = array_values($jugadores);
    $n = count($jugadores);
    $cols = min($n, $porFila);
    $rows = (int)ceil($n / $porFila);
    $S = max(1, min($escala, WIMG_ESCALA));

    /* Look y marco de cada uno; sitio para el marco que más sobresale. */
    $mt = 0; $mb = 0;
    foreach ($jugadores as $i => $j) {
        $jugadores[$i]['look']  = wimg_look($pdo, (int)$j['uid']);
        $jugadores[$i]['marco'] = wimg_marco($pdo, (int)$j['uid']);
        [$t, , $b] = wimg_mk($jugadores[$i]['marco']);
        $mt = max($mt, (int)ceil($t * WIMG_AVATAR));
        $mb = max($mb, (int)ceil($b * WIMG_AVATAR));
    }
    $CH = WIMG_H + $mt + $mb;

    $W = ($cols * WIMG_W + ($cols - 1) * WIMG_GAP) * $S;
    $H = ($rows * $CH + ($rows - 1) * WIMG_GAP) * $S;
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

    foreach ($jugadores as $i => $j) {
        $look = $j['look'];
        [$card, $ax, $ay] = wimg_tarjeta($look, (string)$j['label'], (string)$j['resultado'], $j['filas'], $maximo, $mt, $mb);
        $ox = ($i % $porFila) * (WIMG_W + WIMG_GAP) * $S;
        $oy = intdiv($i, $porFila) * ($CH + WIMG_GAP) * $S;
        imagealphablending($img, false);
        imagecopyresized($img, $card, $ox, $oy, 0, 0, WIMG_W * $S, $CH * $S, WIMG_W, $CH);
        imagedestroy($card);
        $av = wimg_avatar($look, (string)$j['label'], $j['ruta'] ?? null, $S);
        imagealphablending($img, true);
        $px = $ox + $ax * $S; $py = $oy + $ay * $S;
        imagecopy($img, $av, $px, $py, 0, 0, imagesx($av), imagesy($av));
        imagedestroy($av);
        if ($j['marco']) wimg_pintaMarco($img, $j['marco'], $px, $py, WIMG_AVATAR * $S, $S);
    }
    ob_start();
    imagepng($img, null, 9);
    imagedestroy($img);
    return (string)ob_get_clean();
}
