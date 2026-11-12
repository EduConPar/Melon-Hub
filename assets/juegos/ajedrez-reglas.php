<?php
/* ──────────────────────────────────────────────────────────────────────
   AJEDREZ: las reglas (sin base de datos)
   ──────────────────────────────────────────────────────────────────────
   Lo usa assets/juegos/ajedrez.php: el servidor es el que decide qué
   jugadas valen (el navegador solo enseña las que le manda), así nadie
   puede hacer trampas con una apuesta de por medio.
   Tablero = cadena de 64 letras en el orden del FEN: casilla 0 = a8,
   7 = h8, 56 = a1, 63 = h1. Blancas en mayúscula (PNBRQK), negras en
   minúscula y '.' vacía. Estado = ['b' tablero, 't' 'w'|'b' turno,
   'c' enroques ("KQkq" o ''), 'ep' casilla al paso o -1, 'm' medias
   jugadas para la regla de 50, 'n' número de jugada].
   Jugada = "e2e4" / "e7e8q" (UCI), que es lo que va y viene del
   navegador; para la lista de jugadas se guarda también la notación de
   siempre (SAN: "Cf3", "exd5", "O-O", "e8=D+"), con las letras en
   español (R rey, D dama, T torre, A alfil, C caballo).
   ────────────────────────────────────────────────────────────────────── */

const AJZ_INICIAL = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
const AJZ_SAN_LETRA = ['K' => 'R', 'Q' => 'D', 'R' => 'T', 'B' => 'A', 'N' => 'C'];

function ajz_sqNombre(int $s): string { return chr(97 + ($s & 7)) . (8 - ($s >> 3)); }
function ajz_sq(string $n): int {
    if (!preg_match('/^([a-h])([1-8])$/', $n, $m)) return -1;
    return (8 - (int)$m[2]) * 8 + (ord($m[1]) - 97);
}
function ajz_blanca(string $p): bool { return $p !== '.' && ctype_upper($p); }

function ajz_fen(string $fen): array {
    $p = explode(' ', trim($fen)) + ['', 'w', '-', '-', '0', '1'];
    $b = '';
    foreach (explode('/', $p[0]) as $fila) {
        foreach (str_split($fila) as $c) $b .= ctype_digit($c) ? str_repeat('.', (int)$c) : $c;
    }
    return ['b' => $b, 't' => $p[1] === 'b' ? 'b' : 'w', 'c' => $p[2] === '-' ? '' : $p[2],
            'ep' => $p[3] === '-' ? -1 : ajz_sq($p[3]), 'm' => (int)$p[4], 'n' => max(1, (int)$p[5])];
}
function ajz_fenTexto(array $e): string {
    $filas = [];
    for ($r = 0; $r < 8; $r++) {
        $f = ''; $vac = 0;
        for ($c = 0; $c < 8; $c++) {
            $p = $e['b'][$r * 8 + $c];
            if ($p === '.') { $vac++; continue; }
            if ($vac) { $f .= $vac; $vac = 0; }
            $f .= $p;
        }
        $filas[] = $f . ($vac ? $vac : '');
    }
    return implode('/', $filas) . ' ' . $e['t'] . ' ' . ($e['c'] === '' ? '-' : $e['c']) . ' '
         . ($e['ep'] < 0 ? '-' : ajz_sqNombre($e['ep'])) . ' ' . $e['m'] . ' ' . $e['n'];
}
/* La posición para la triple repetición: tablero, turno, enroques y al paso. */
function ajz_clave(array $e): string {
    return $e['b'] . $e['t'] . $e['c'] . $e['ep'];
}

const AJZ_CABALLO = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
const AJZ_REY     = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
const AJZ_TORRE   = [[-1, 0], [1, 0], [0, -1], [0, 1]];
const AJZ_ALFIL   = [[-1, -1], [-1, 1], [1, -1], [1, 1]];

/* ¿Ataca algo del color $blancas la casilla $s? */
function ajz_atacada(string $b, int $s, bool $blancas): bool {
    $r = $s >> 3; $c = $s & 7;
    $en = function (int $rr, int $cc) use ($b): string {
        return ($rr < 0 || $rr > 7 || $cc < 0 || $cc > 7) ? '' : $b[$rr * 8 + $cc];
    };
    /* Peones: el blanco ataca hacia arriba (fila menor), así que el que
       ataca $s está una fila por debajo. */
    $pr = $blancas ? $r + 1 : $r - 1;
    $peon = $blancas ? 'P' : 'p';
    if ($en($pr, $c - 1) === $peon || $en($pr, $c + 1) === $peon) return true;
    $cab = $blancas ? 'N' : 'n';
    foreach (AJZ_CABALLO as [$dr, $dc]) if ($en($r + $dr, $c + $dc) === $cab) return true;
    $rey = $blancas ? 'K' : 'k';
    foreach (AJZ_REY as [$dr, $dc]) if ($en($r + $dr, $c + $dc) === $rey) return true;
    foreach ([[AJZ_TORRE, 'R'], [AJZ_ALFIL, 'B']] as [$dirs, $tipo]) {
        $t = $blancas ? $tipo : strtolower($tipo);
        $q = $blancas ? 'Q' : 'q';
        foreach ($dirs as [$dr, $dc]) {
            for ($k = 1; $k < 8; $k++) {
                $p = $en($r + $dr * $k, $c + $dc * $k);
                if ($p === '') break;
                if ($p === '.') continue;
                if ($p === $t || $p === $q) return true;
                break;
            }
        }
    }
    return false;
}

function ajz_enJaque(array $e, ?bool $blancas = null): bool {
    $blancas = $blancas ?? ($e['t'] === 'w');
    $k = strpos($e['b'], $blancas ? 'K' : 'k');
    return $k !== false && ajz_atacada($e['b'], $k, !$blancas);
}

/* Jugadas sin mirar si dejan al propio rey en jaque: [desde, hasta, corona]. */
function ajz_pseudo(array $e): array {
    $b = $e['b']; $w = $e['t'] === 'w';
    $out = [];
    $mio = function (string $p) use ($w): bool { return $p !== '.' && ajz_blanca($p) === $w; };
    $suyo = function (string $p) use ($w): bool { return $p !== '.' && ajz_blanca($p) !== $w; };
    for ($s = 0; $s < 64; $s++) {
        $p = $b[$s];
        if (!$mio($p)) continue;
        $r = $s >> 3; $c = $s & 7;
        $tipo = strtoupper($p);
        if ($tipo === 'P') {
            $dir = $w ? -1 : 1;
            $ini = $w ? 6 : 1;
            $ult = $w ? 0 : 7;
            $add = function (int $a) use (&$out, $s, $ult) {
                if (($a >> 3) === $ult) { foreach (['q', 'r', 'b', 'n'] as $pr) $out[] = [$s, $a, $pr]; }
                else $out[] = [$s, $a, ''];
            };
            $r1 = $r + $dir;
            if ($r1 >= 0 && $r1 <= 7 && $b[$r1 * 8 + $c] === '.') {
                $add($r1 * 8 + $c);
                $r2 = $r + 2 * $dir;
                if ($r === $ini && $b[$r2 * 8 + $c] === '.') $out[] = [$s, $r2 * 8 + $c, ''];
            }
            foreach ([-1, 1] as $dc) {
                $cc = $c + $dc;
                if ($cc < 0 || $cc > 7 || $r1 < 0 || $r1 > 7) continue;
                $a = $r1 * 8 + $cc;
                if ($suyo($b[$a]) || $a === $e['ep']) $add($a);
            }
            continue;
        }
        if ($tipo === 'N' || $tipo === 'K') {
            foreach ($tipo === 'N' ? AJZ_CABALLO : AJZ_REY as [$dr, $dc]) {
                $rr = $r + $dr; $cc = $c + $dc;
                if ($rr < 0 || $rr > 7 || $cc < 0 || $cc > 7) continue;
                $a = $rr * 8 + $cc;
                if (!$mio($b[$a])) $out[] = [$s, $a, ''];
            }
            continue;
        }
        $dirs = $tipo === 'R' ? AJZ_TORRE : ($tipo === 'B' ? AJZ_ALFIL : array_merge(AJZ_TORRE, AJZ_ALFIL));
        foreach ($dirs as [$dr, $dc]) {
            for ($k = 1; $k < 8; $k++) {
                $rr = $r + $dr * $k; $cc = $c + $dc * $k;
                if ($rr < 0 || $rr > 7 || $cc < 0 || $cc > 7) break;
                $a = $rr * 8 + $cc;
                if ($mio($b[$a])) break;
                $out[] = [$s, $a, ''];
                if ($b[$a] !== '.') break;
            }
        }
    }
    /* Enroques: rey y torre en su sitio, casillas de en medio vacías y el
       rey sin pasar por casillas atacadas (ni estar en jaque). */
    $enr = [
        'K' => [60, 62, 63, [61, 62],     [60, 61, 62]],
        'Q' => [60, 58, 56, [59, 58, 57], [60, 59, 58]],
        'k' => [4, 6, 7,    [5, 6],       [4, 5, 6]],
        'q' => [4, 2, 0,    [3, 2, 1],    [4, 3, 2]],
    ];
    foreach ($enr as $letra => [$rey, $dest, $torre, $vacias, $seguras]) {
        if (strpos($e['c'], $letra) === false || ctype_upper($letra) !== $w) continue;
        if ($b[$rey] !== ($w ? 'K' : 'k') || $b[$torre] !== ($w ? 'R' : 'r')) continue;
        foreach ($vacias as $v) if ($b[$v] !== '.') continue 2;
        foreach ($seguras as $v) if (ajz_atacada($b, $v, !$w)) continue 2;
        $out[] = [$rey, $dest, ''];
    }
    return $out;
}

function ajz_aplicar(array $e, array $j): array {
    [$de, $a, $cor] = $j;
    $b = $e['b']; $w = $e['t'] === 'w';
    $p = $b[$de]; $tipo = strtoupper($p);
    $captura = $b[$a] !== '.';
    $b[$a] = $cor !== '' ? ($w ? strtoupper($cor) : $cor) : $p;
    $b[$de] = '.';
    /* Al paso: el peón come en diagonal a una casilla vacía. */
    if ($tipo === 'P' && $a === $e['ep'] && ($de & 7) !== ($a & 7)) {
        $b[($de >> 3) * 8 + ($a & 7)] = '.';
        $captura = true;
    }
    /* Enroque: la torre salta al otro lado del rey. */
    if ($tipo === 'K' && abs(($a & 7) - ($de & 7)) === 2) {
        $torres = [62 => [63, 61], 58 => [56, 59], 6 => [7, 5], 2 => [0, 3]];
        [$tde, $ta] = $torres[$a];
        $b[$ta] = $b[$tde]; $b[$tde] = '.';
    }
    $c = $e['c'];
    if ($tipo === 'K') $c = str_replace($w ? ['K', 'Q'] : ['k', 'q'], '', $c);
    foreach ([63 => 'K', 56 => 'Q', 7 => 'k', 0 => 'q'] as $esq => $letra) {
        if ($de === $esq || $a === $esq) $c = str_replace($letra, '', $c);
    }
    return [
        'b'  => $b,
        't'  => $w ? 'b' : 'w',
        'c'  => $c,
        'ep' => ($tipo === 'P' && abs($a - $de) === 16) ? intdiv($a + $de, 2) : -1,
        'm'  => ($tipo === 'P' || $captura) ? 0 : $e['m'] + 1,
        'n'  => $e['n'] + ($w ? 0 : 1),
    ];
}

function ajz_legales(array $e): array {
    $w = $e['t'] === 'w';
    $out = [];
    foreach (ajz_pseudo($e) as $j) {
        if (!ajz_enJaque(ajz_aplicar($e, $j), $w)) $out[] = $j;
    }
    return $out;
}

function ajz_uci(array $j): string { return ajz_sqNombre($j[0]) . ajz_sqNombre($j[1]) . $j[2]; }

/* Notación de siempre de una jugada legal de $e ($legales = las de $e). */
function ajz_san(array $e, array $j, array $legales): string {
    [$de, $a, $cor] = $j;
    $p = $e['b'][$de]; $tipo = strtoupper($p);
    if ($tipo === 'K' && abs(($a & 7) - ($de & 7)) === 2) $san = ($a & 7) === 6 ? 'O-O' : 'O-O-O';
    else {
        $captura = $e['b'][$a] !== '.' || ($tipo === 'P' && $a === $e['ep'] && ($de & 7) !== ($a & 7));
        if ($tipo === 'P') {
            $san = ($captura ? chr(97 + ($de & 7)) . 'x' : '') . ajz_sqNombre($a);
            if ($cor !== '') $san .= '=' . AJZ_SAN_LETRA[strtoupper($cor)];
        } else {
            /* Si otra pieza igual puede ir a la misma casilla: columna, fila o las dos. */
            $otras = array_filter($legales, fn($o) => $o[1] === $a && $o[0] !== $de && $e['b'][$o[0]] === $p);
            $dis = '';
            if ($otras) {
                $mismaCol = array_filter($otras, fn($o) => ($o[0] & 7) === ($de & 7));
                $mismaFila = array_filter($otras, fn($o) => ($o[0] >> 3) === ($de >> 3));
                if (!$mismaCol) $dis = chr(97 + ($de & 7));
                elseif (!$mismaFila) $dis = (string)(8 - ($de >> 3));
                else $dis = ajz_sqNombre($de);
            }
            $san = AJZ_SAN_LETRA[$tipo] . $dis . ($captura ? 'x' : '') . ajz_sqNombre($a);
        }
    }
    $tras = ajz_aplicar($e, $j);
    if (ajz_enJaque($tras)) $san .= ajz_legales($tras) ? '+' : '#';
    return $san;
}

/* ¿Puede este color dar mate con lo que le queda? (para "tiempo" contra
   rey solo y para tablas por material). Con solo el rey, o rey y un
   caballo o un alfil, no. */
function ajz_puedeMatar(string $b, bool $blancas): bool {
    $mias = '';
    for ($s = 0; $s < 64; $s++) {
        $p = $b[$s];
        if ($p !== '.' && ajz_blanca($p) === $blancas && strtoupper($p) !== 'K') $mias .= strtoupper($p);
    }
    if ($mias === '') return false;
    if (strlen($mias) === 1 && ($mias === 'N' || $mias === 'B')) return false;
    return true;
}
/* Tablas por material: nadie puede dar mate (rey contra rey, rey y pieza
   menor contra rey, o solo alfiles todos en casillas del mismo color). */
function ajz_materialInsuficiente(string $b): bool {
    $resto = []; $colores = [];
    for ($s = 0; $s < 64; $s++) {
        $p = strtoupper($b[$s]);
        if ($p === '.' || $p === 'K') continue;
        $resto[] = $p;
        if ($p === 'B') $colores[(($s >> 3) + ($s & 7)) % 2] = true;
    }
    if (count($resto) === 0) return true;
    if (count($resto) === 1 && ($resto[0] === 'N' || $resto[0] === 'B')) return true;
    if (count(array_filter($resto, fn($x) => $x !== 'B')) === 0 && count($colores) === 1) return true;
    return false;
}
