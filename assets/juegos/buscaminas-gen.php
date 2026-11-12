<?php
/* ──────────────────────────────────────────────────────────────────────
   BUSCAMINAS: generador de tableros "sin adivinar"
   ──────────────────────────────────────────────────────────────────────
   Los tableros con puntos (los del día y el modo infinito) empiezan con
   una zona vacía ya abierta (la casilla de inicio y sus vecinas no tienen
   mina) y tienen que poder despejarse solo con lógica: se genera uno al
   azar y se le pasa un resolvedor; si se atasca, se prueba otro.
   Resolvedor (bm_resolver):
     - Reglas de una casilla: si un número ya tiene todas sus minas
       marcadas, el resto de vecinas son seguras; si le faltan tantas
       como vecinas tapadas, todas son mina.
     - Reglas de dos casillas: si las tapadas de A están dentro de las de
       B, las de B que no son de A tienen (minas de B − minas de A).
     - Cuenta total: con las minas que quedan = 0 o = tapadas.
   Casillas: índice i = fila * ancho + columna. Minas: cadena de '0'/'1'.
   ────────────────────────────────────────────────────────────────────── */

function bm_vecinas(int $i, int $w, int $h): array {
    static $cache = [];
    $k = "$i,$w,$h";
    if (isset($cache[$k])) return $cache[$k];
    $x = $i % $w; $y = intdiv($i, $w); $out = [];
    for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
        if (!$dx && !$dy) continue;
        $nx = $x + $dx; $ny = $y + $dy;
        if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h) $out[] = $ny * $w + $nx;
    }
    return $cache[$k] = $out;
}

/* Números de cada casilla (−1 = mina). */
function bm_numeros(string $minas, int $w, int $h): array {
    $n = $w * $h; $num = array_fill(0, $n, 0);
    for ($i = 0; $i < $n; $i++) {
        if ($minas[$i] === '1') { $num[$i] = -1; continue; }
        $c = 0;
        foreach (bm_vecinas($i, $w, $h) as $v) if ($minas[$v] === '1') $c++;
        $num[$i] = $c;
    }
    return $num;
}

/* Abre $i y, si es 0, su zona entera. $abiertas: cadena '0'/'1' (por ref). */
function bm_abrir(array $num, string &$abiertas, int $i, int $w, int $h): void {
    $pila = [$i];
    while ($pila) {
        $c = array_pop($pila);
        if ($abiertas[$c] === '1' || $num[$c] < 0) continue;
        $abiertas[$c] = '1';
        if ($num[$c] === 0) foreach (bm_vecinas($c, $w, $h) as $v) if ($abiertas[$v] !== '1') $pila[] = $v;
    }
}

/* ¿Se despeja entero solo con lógica desde $inicio? */
function bm_resolver(string $minas, int $w, int $h, int $inicio): bool {
    $n = $w * $h; $num = bm_numeros($minas, $w, $h);
    $total = substr_count($minas, '1');
    $ab = str_repeat('0', $n);
    bm_abrir($num, $ab, $inicio, $w, $h);
    $mina = [];   /* deducidas */
    $seguras = $n - $total;
    while (true) {
        if (substr_count($ab, '1') === $seguras) return true;
        $avance = false;
        /* Restricciones de la frontera: [tapadas no marcadas, minas que faltan]. */
        $rest = [];
        for ($i = 0; $i < $n; $i++) {
            if ($ab[$i] !== '1' || $num[$i] <= 0) continue;
            $tap = []; $f = 0;
            foreach (bm_vecinas($i, $w, $h) as $v) {
                if ($ab[$v] === '1') continue;
                if (isset($mina[$v])) $f++; else $tap[] = $v;
            }
            if (!$tap) continue;
            $falta = $num[$i] - $f;
            if ($falta === 0) {
                foreach ($tap as $v) bm_abrir($num, $ab, $v, $w, $h);
                $avance = true;
            } elseif ($falta === count($tap)) {
                foreach ($tap as $v) $mina[$v] = true;
                $avance = true;
            } else {
                $rest[] = [$tap, $falta];
            }
        }
        if ($avance) continue;
        /* Dos casillas: A ⊆ B. */
        $nr = count($rest);
        for ($a = 0; $a < $nr && !$avance; $a++) {
            [$ta, $fa] = $rest[$a];
            for ($b = 0; $b < $nr; $b++) {
                if ($a === $b) continue;
                [$tb, $fb] = $rest[$b];
                if (count($ta) >= count($tb)) continue;
                if (array_diff($ta, $tb)) continue;
                $resto = array_values(array_diff($tb, $ta));
                $m = $fb - $fa;
                if ($m === 0) { foreach ($resto as $v) bm_abrir($num, $ab, $v, $w, $h); $avance = true; break; }
                if ($m === count($resto)) { foreach ($resto as $v) $mina[$v] = true; $avance = true; break; }
            }
        }
        if ($avance) continue;
        /* Cuenta total. */
        $tapadas = [];
        for ($i = 0; $i < $n; $i++) if ($ab[$i] !== '1' && !isset($mina[$i])) $tapadas[] = $i;
        $quedan = $total - count($mina);
        if ($quedan === 0) { foreach ($tapadas as $v) bm_abrir($num, $ab, $v, $w, $h); continue; }
        if ($quedan === count($tapadas)) return true;
        return false;
    }
}

/* Tablero al azar con la zona de inicio libre de minas. Inicio lejos
   de los bordes para que la zona abierta sea grande. */
function bm_azar(int $w, int $h, int $m): array {
    $n = $w * $h;
    $inicio = random_int(1, $h - 2) * $w + random_int(1, $w - 2);
    $prohibidas = array_flip(array_merge([$inicio], bm_vecinas($inicio, $w, $h)));
    $libres = [];
    for ($i = 0; $i < $n; $i++) if (!isset($prohibidas[$i])) $libres[] = $i;
    shuffle($libres);
    $minas = str_repeat('0', $n);
    foreach (array_slice($libres, 0, $m) as $i) $minas[$i] = '1';
    return [$minas, $inicio];
}

/* Tablero sin adivinar. Prueba hasta $segundos; si no encuentra (muy
   raro), devuelve el último aunque toque adivinar algo ('logico' false). */
function bm_generar(int $w, int $h, int $m, float $segundos = 8.0): array {
    $t0 = microtime(true); $intentos = 0;
    do {
        $intentos++;
        [$minas, $inicio] = bm_azar($w, $h, $m);
        if (bm_resolver($minas, $w, $h, $inicio)) {
            return ['minas' => $minas, 'inicio' => $inicio, 'logico' => true, 'intentos' => $intentos];
        }
    } while (microtime(true) - $t0 < $segundos);
    return ['minas' => $minas, 'inicio' => $inicio, 'logico' => false, 'intentos' => $intentos];
}
