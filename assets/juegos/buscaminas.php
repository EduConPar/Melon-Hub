<?php
/* ──────────────────────────────────────────────────────────────────────
   BUSCAMINAS (app Juegos; decidido así)
   ──────────────────────────────────────────────────────────────────────
   Tres modos:
     - Tablero del día: hay tres (Principiante 9×9/10, Intermedio
       16×16/40, Experto 30×16/99), los mismos para todos, pero cada
       persona ELIGE UNO al día (el primero que abre) y tiene UN intento. Día
       en hora de Madrid, como Wordelon. Despejarlo da BM_NIVELES[base]
       (50 / 100 / 200); si pisas una mina, la parte proporcional a las
       minas bien marcadas con bandera (5 de 10 → la mitad). Al acabar el
       día, los 3 más rápidos de cada tablero se llevan BM_PREMIOS (+50 /
       +30 / +15); se reparten la primera vez que alguien abre el
       Buscaminas otro día (bm_premiar), no hay cron.
     - Infinito: rondas BM_RONDAS; primero sube la dificultad y, ya en
       Experto, baja el tiempo. Al pasar una ronda se elige: continuar o
       retirarse con los puntos acumulados; si se pierde (mina o tiempo),
       se pierde todo lo acumulado. Récord = la ronda más alta superada
       (de siempre).
     - Libre: se juega solo en el navegador (apps/juegos.php), no da
       puntos y aquí no pasa nada.
   Los tableros con puntos los lleva el SERVIDOR: las minas no llegan al
   navegador hasta que la partida acaba, el tiempo lo mide el servidor y
   cada clic es una petición (bm_jugar). Todos empiezan con una zona ya
   abierta y se pueden despejar sin adivinar (buscaminas-gen.php). El
   tiempo de los del día empieza al abrir el tablero.
   Vista para el navegador: una letra por casilla — '.' tapada, 'F'
   bandera, '0'…'8' número; al perder: 'M' mina, 'X' la que explotó,
   'W' bandera mal puesta; al ganar, las minas salen como 'F'.
   ────────────────────────────────────────────────────────────────────── */

require_once __DIR__ . '/buscaminas-gen.php';

const BM_TZ = 'Europe/Madrid';
const BM_NIVELES = [
    1 => ['nombre' => 'Principiante', 'w' => 9,  'h' => 9,  'm' => 10, 'base' => 50],
    2 => ['nombre' => 'Intermedio',   'w' => 16, 'h' => 16, 'm' => 40, 'base' => 100],
    3 => ['nombre' => 'Experto',      'w' => 30, 'h' => 16, 'm' => 99, 'base' => 200],
];
const BM_PREMIOS = [50, 30, 15];
/* Ronda => [ancho, alto, minas, segundos, puntos]. De la 10 en adelante:
   Experto, 30 s menos cada ronda (mínimo 120) y 10 puntos (bm_ronda). */
const BM_RONDAS = [
    1 => [8, 8, 8, 90, 1],
    2 => [9, 9, 10, 90, 1],
    3 => [10, 10, 14, 120, 2],
    4 => [12, 12, 20, 150, 2],
    5 => [14, 14, 28, 180, 3],
    6 => [16, 16, 40, 240, 4],
    7 => [20, 16, 55, 300, 5],
    8 => [24, 16, 70, 360, 6],
    9 => [30, 16, 99, 420, 8],
];

function bm_ronda(int $r): array {
    if (isset(BM_RONDAS[$r])) [$w, $h, $m, $s, $p] = BM_RONDAS[$r];
    else { $w = 30; $h = 16; $m = 99; $s = max(120, 420 - 30 * ($r - 9)); $p = 10; }
    return ['w' => $w, 'h' => $h, 'm' => $m, 'seg' => $s, 'puntos' => $p];
}

function bm_hoy(): string {
    return (new DateTime('now', new DateTimeZone(BM_TZ)))->format('Y-m-d');
}
function bm_ms(): int { return (int)floor(microtime(true) * 1000); }

function bm_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bm_tableros (
            id     INT AUTO_INCREMENT PRIMARY KEY,
            w      TINYINT NOT NULL,
            h      TINYINT NOT NULL,
            minas  VARCHAR(600) NOT NULL,
            inicio SMALLINT NOT NULL,
            logico TINYINT NOT NULL DEFAULT 1,
            creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bm_dias (
            dia        DATE NOT NULL,
            nivel      TINYINT NOT NULL,
            tablero_id INT NOT NULL,
            premiado   TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (dia, nivel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bm_partidas (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            modo       VARCHAR(4) NOT NULL,
            dia        DATE NULL,
            nivel      TINYINT NULL,
            ronda      INT NULL,
            tablero_id INT NOT NULL,
            abiertas   VARCHAR(600) NOT NULL,
            banderas   VARCHAR(600) NOT NULL,
            estado     VARCHAR(8) NOT NULL DEFAULT 'jugando',
            explotada  SMALLINT NULL,
            inicio_ms  BIGINT NOT NULL,
            fin_ms     BIGINT NULL,
            ms         INT NULL,
            puntos     INT NOT NULL DEFAULT 0,
            bonus      INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_dia (user_id, dia, nivel),
            INDEX idx_dia (dia, nivel, estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bm_infinito (
            user_id    INT PRIMARY KEY,
            estado     VARCHAR(9) NOT NULL DEFAULT 'ninguna',
            ronda      INT NOT NULL DEFAULT 0,
            partida_id INT NULL,
            pendientes INT NOT NULL DEFAULT 0,
            mejor      INT NOT NULL DEFAULT 0,
            mejor_en   TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE */ }
}

function bm_sumarPuntos(PDO $pdo, int $uid, int $p): void {
    if ($p > 0) $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$p, $uid]);
}

function bm_nuevoTablero(PDO $pdo, int $w, int $h, int $m): int {
    $g = bm_generar($w, $h, $m);
    $pdo->prepare("INSERT INTO bm_tableros (w, h, minas, inicio, logico) VALUES (?,?,?,?,?)")
        ->execute([$w, $h, $g['minas'], $g['inicio'], $g['logico'] ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}
function bm_tablero(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM bm_tableros WHERE id = ?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new RuntimeException('Tablero no encontrado');
    $t['w'] = (int)$t['w']; $t['h'] = (int)$t['h']; $t['inicio'] = (int)$t['inicio'];
    $t['m'] = substr_count($t['minas'], '1');
    return $t;
}

/* Tablero del día de ese nivel (lo genera la primera vez; si dos llegan a
   la vez, gana el primero y el otro tablero queda suelto, sin más). */
function bm_tableroDia(PDO $pdo, string $dia, int $nivel): int {
    $st = $pdo->prepare("SELECT tablero_id FROM bm_dias WHERE dia = ? AND nivel = ?");
    $st->execute([$dia, $nivel]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $n = BM_NIVELES[$nivel];
    $tid = bm_nuevoTablero($pdo, $n['w'], $n['h'], $n['m']);
    $pdo->prepare("INSERT IGNORE INTO bm_dias (dia, nivel, tablero_id) VALUES (?,?,?)")->execute([$dia, $nivel, $tid]);
    $st->execute([$dia, $nivel]);
    return (int)$st->fetchColumn();
}

/* Partida nueva sobre un tablero, con la zona de inicio ya abierta. */
function bm_crearPartida(PDO $pdo, int $uid, string $modo, int $tid, ?string $dia, ?int $nivel, ?int $ronda): int {
    $t = bm_tablero($pdo, $tid);
    $n = $t['w'] * $t['h'];
    $num = bm_numeros($t['minas'], $t['w'], $t['h']);
    $ab = str_repeat('0', $n);
    bm_abrir($num, $ab, $t['inicio'], $t['w'], $t['h']);
    $pdo->prepare("INSERT INTO bm_partidas (user_id, modo, dia, nivel, ronda, tablero_id, abiertas, banderas, inicio_ms)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$uid, $modo, $dia, $nivel, $ronda, $tid, $ab, str_repeat('0', $n), bm_ms()]);
    return (int)$pdo->lastInsertId();
}

function bm_partida(PDO $pdo, int $id, bool $bloquear = false): ?array {
    $st = $pdo->prepare("SELECT * FROM bm_partidas WHERE id = ?" . ($bloquear ? " FOR UPDATE" : ""));
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
}

/* Lo que ve el navegador de una partida. */
function bm_vista(PDO $pdo, array $p): array {
    $t = bm_tablero($pdo, (int)$p['tablero_id']);
    $w = $t['w']; $h = $t['h']; $n = $w * $h;
    $num = bm_numeros($t['minas'], $w, $h);
    $v = '';
    $fin = $p['estado'] !== 'jugando';
    for ($i = 0; $i < $n; $i++) {
        $mina = $t['minas'][$i] === '1'; $flag = $p['banderas'][$i] === '1';
        if ($p['abiertas'][$i] === '1') { $v .= (string)$num[$i]; continue; }
        if ($fin && $p['estado'] === 'perdida') {
            if ($p['explotada'] !== null && (int)$p['explotada'] === $i) $v .= 'X';
            elseif ($mina) $v .= $flag ? 'F' : 'M';
            elseif ($flag) $v .= 'W';
            else $v .= '.';
            continue;
        }
        if ($fin && $p['estado'] === 'ganada' && $mina) { $v .= 'F'; continue; }
        $v .= $flag ? 'F' : '.';
    }
    $out = [
        'id'      => (int)$p['id'],
        'modo'    => $p['modo'],
        'w'       => $w, 'h' => $h, 'minas' => $t['m'],
        'vista'   => $v,
        'banderas'=> substr_count($p['banderas'], '1'),
        'estado'  => $p['estado'],
        'ms'      => $p['ms'] !== null ? (int)$p['ms'] : bm_ms() - (int)$p['inicio_ms'],
        'puntos'  => (int)$p['puntos'],
    ];
    if ($p['modo'] === 'dia') {
        $out['nivel'] = (int)$p['nivel'];
        $out['nombre'] = BM_NIVELES[(int)$p['nivel']]['nombre'];
        $out['base'] = BM_NIVELES[(int)$p['nivel']]['base'];
        $out['dia'] = $p['dia'];
    } else {
        $r = bm_ronda((int)$p['ronda']);
        $out['ronda'] = (int)$p['ronda'];
        $out['limite_ms'] = $r['seg'] * 1000;
        $out['restante_ms'] = max(0, (int)$p['inicio_ms'] + $r['seg'] * 1000 - bm_ms());
        $out['puntosRonda'] = $r['puntos'];
    }
    return $out;
}

/* ── Tableros del día ─────────────────────────────────────────────── */

/* Cómo van los 3 de hoy para este usuario. */
function bm_dia(PDO $pdo, int $uid): array {
    bm_premiar($pdo);
    $hoy = bm_hoy();
    $st = $pdo->prepare("SELECT * FROM bm_partidas WHERE user_id = ? AND dia = ? AND modo = 'dia'");
    $st->execute([$uid, $hoy]);
    $mias = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $mias[(int)$p['nivel']] = $p;
    $out = [];
    foreach (BM_NIVELES as $nv => $n) {
        $p = $mias[$nv] ?? null;
        $out[] = [
            'nivel'  => $nv, 'nombre' => $n['nombre'], 'w' => $n['w'], 'h' => $n['h'], 'm' => $n['m'], 'base' => $n['base'],
            'estado' => $p ? $p['estado'] : 'nueva',
            'id'     => $p ? (int)$p['id'] : null,
            'ms'     => $p && $p['ms'] !== null ? (int)$p['ms'] : null,
            'puntos' => $p ? (int)$p['puntos'] : 0,
            'puesto' => $p && $p['estado'] === 'ganada' ? bm_puesto($pdo, $hoy, $nv, (int)$p['ms'], (int)$p['fin_ms']) : null,
        ];
    }
    /* El que ha elegido hoy (solo se puede uno) y cómo lo dejó, para la
       vista previa del menú. */
    $elegido = null; $partida = null;
    foreach ($mias as $nv => $p) { $elegido = $nv; $partida = bm_vista($pdo, $p); break; }
    return ['dia' => $hoy, 'tableros' => $out, 'premios' => BM_PREMIOS, 'elegido' => $elegido, 'partida' => $partida];
}

/* Puesto entre los que han despejado ese tablero (por tiempo; a igualdad,
   quien acabó antes). */
function bm_puesto(PDO $pdo, string $dia, int $nivel, int $ms, int $fin): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bm_partidas WHERE dia = ? AND nivel = ? AND modo = 'dia' AND estado = 'ganada'
                           AND (ms < ? OR (ms = ? AND fin_ms < ?))");
    $st->execute([$dia, $nivel, $ms, $ms, $fin]);
    return (int)$st->fetchColumn() + 1;
}

/* Abre (o retoma) el tablero del día de ese nivel. */
function bm_abrirDia(PDO $pdo, int $uid, int $nivel): array {
    if (!isset(BM_NIVELES[$nivel])) throw new InvalidArgumentException('Nivel no válido');
    $hoy = bm_hoy();
    $tid = bm_tableroDia($pdo, $hoy, $nivel);
    $st = $pdo->prepare("SELECT id FROM bm_partidas WHERE user_id = ? AND dia = ? AND nivel = ? AND modo = 'dia'");
    $st->execute([$uid, $hoy, $nivel]);
    $id = (int)$st->fetchColumn();
    if (!$id) {
        /* Uno al día: si ya eligió otra dificultad, no puede abrir esta. */
        $ot = $pdo->prepare("SELECT nivel FROM bm_partidas WHERE user_id = ? AND dia = ? AND modo = 'dia' LIMIT 1");
        $ot->execute([$uid, $hoy]);
        $otro = $ot->fetchColumn();
        if ($otro !== false) throw new InvalidArgumentException('Hoy ya has elegido el ' . BM_NIVELES[(int)$otro]['nombre']);
        try { $id = bm_crearPartida($pdo, $uid, 'dia', $tid, $hoy, $nivel, null); }
        catch (PDOException $e) {   /* doble clic: ya la creó la otra petición */
            $st->execute([$uid, $hoy, $nivel]);
            $id = (int)$st->fetchColumn();
            if (!$id) throw $e;
        }
    }
    return bm_vista($pdo, bm_partida($pdo, $id));
}

/* Premios por puesto de los días que ya acabaron y no se han repartido. */
function bm_premiar(PDO $pdo): void {
    $st = $pdo->prepare("SELECT dia, nivel FROM bm_dias WHERE premiado = 0 AND dia < ?");
    $st->execute([bm_hoy()]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $l = $pdo->prepare("SELECT premiado FROM bm_dias WHERE dia = ? AND nivel = ? FOR UPDATE");
            $l->execute([$d['dia'], $d['nivel']]);
            if ((int)$l->fetchColumn() === 1) { $pdo->commit(); continue; }
            $g = $pdo->prepare("SELECT id, user_id FROM bm_partidas WHERE dia = ? AND nivel = ? AND modo = 'dia' AND estado = 'ganada'
                                 ORDER BY ms ASC, fin_ms ASC LIMIT " . count(BM_PREMIOS));
            $g->execute([$d['dia'], $d['nivel']]);
            foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $k => $p) {
                $pdo->prepare("UPDATE bm_partidas SET bonus = ? WHERE id = ?")->execute([BM_PREMIOS[$k], $p['id']]);
                bm_sumarPuntos($pdo, (int)$p['user_id'], BM_PREMIOS[$k]);
            }
            $pdo->prepare("UPDATE bm_dias SET premiado = 1 WHERE dia = ? AND nivel = ?")->execute([$d['dia'], $d['nivel']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[buscaminas] premios: ' . $e->getMessage());
        }
    }
}

/* ── Jugar (del día o infinito) ───────────────────────────────────── */

/* Un clic: tipo abrir | acorde (sobre un número con sus banderas puestas,
   abre las vecinas) | bandera (pone o quita). Devuelve la vista y lo que
   ha pasado ('ganados' = puntos sumados ahora al saldo). */
function bm_jugar(PDO $pdo, int $uid, int $id, string $tipo, int $celda): array {
    $pdo->beginTransaction();
    try {
        $p = bm_partida($pdo, $id, true);
        if (!$p || (int)$p['user_id'] !== $uid) throw new InvalidArgumentException('Partida no encontrada');
        $ganados = 0;
        if ($p['estado'] === 'jugando' && $p['modo'] === 'inf' && bm_infCaducada($p)) {
            bm_infPerder($pdo, $uid, $p, null);
            $p = bm_partida($pdo, $id);
        }
        if ($p['estado'] !== 'jugando') {
            $pdo->commit();
            return ['partida' => bm_vista($pdo, $p), 'ganados' => 0];
        }
        $t = bm_tablero($pdo, (int)$p['tablero_id']);
        $w = $t['w']; $h = $t['h']; $n = $w * $h;
        if ($celda < 0 || $celda >= $n) throw new InvalidArgumentException('Casilla no válida');
        $num = bm_numeros($t['minas'], $w, $h);
        $ab = $p['abiertas']; $fl = $p['banderas'];
        $boom = null;

        if ($tipo === 'bandera') {
            if ($ab[$celda] !== '1') $fl[$celda] = $fl[$celda] === '1' ? '0' : '1';
        } else {
            $abrir = [];
            if ($tipo === 'abrir') {
                if ($ab[$celda] !== '1' && $fl[$celda] !== '1') $abrir[] = $celda;
            } elseif ($tipo === 'acorde') {
                if ($ab[$celda] === '1' && $num[$celda] > 0) {
                    $vec = bm_vecinas($celda, $w, $h);
                    $f = 0;
                    foreach ($vec as $v) if ($fl[$v] === '1') $f++;
                    if ($f === $num[$celda]) foreach ($vec as $v) if ($ab[$v] !== '1' && $fl[$v] !== '1') $abrir[] = $v;
                }
            }
            foreach ($abrir as $c) {
                if ($t['minas'][$c] === '1') { $boom = $c; break; }
                bm_abrir($num, $ab, $c, $w, $h);
            }
            /* Lo que se abre deja de tener bandera. */
            for ($i = 0; $i < $n; $i++) if ($ab[$i] === '1') $fl[$i] = '0';
        }
        $pdo->prepare("UPDATE bm_partidas SET abiertas = ?, banderas = ? WHERE id = ?")->execute([$ab, $fl, $id]);
        $p['abiertas'] = $ab; $p['banderas'] = $fl;

        $fin = bm_ms();
        if ($boom !== null) {
            if ($p['modo'] === 'dia') {
                /* Parte proporcional a las minas bien marcadas. */
                $bien = 0;
                for ($i = 0; $i < $n; $i++) if ($fl[$i] === '1' && $t['minas'][$i] === '1') $bien++;
                $base = BM_NIVELES[(int)$p['nivel']]['base'];
                $ganados = (int)floor($base * $bien / max(1, $t['m']));
                $pdo->prepare("UPDATE bm_partidas SET estado = 'perdida', explotada = ?, fin_ms = ?, ms = ?, puntos = ? WHERE id = ?")
                    ->execute([$boom, $fin, $fin - (int)$p['inicio_ms'], $ganados, $id]);
                bm_sumarPuntos($pdo, $uid, $ganados);
            } else {
                bm_infPerder($pdo, $uid, $p, $boom);
            }
        } elseif (substr_count($ab, '1') === $n - $t['m']) {
            $ms = $fin - (int)$p['inicio_ms'];
            if ($p['modo'] === 'dia') {
                $ganados = BM_NIVELES[(int)$p['nivel']]['base'];
                $pdo->prepare("UPDATE bm_partidas SET estado = 'ganada', fin_ms = ?, ms = ?, puntos = ? WHERE id = ?")
                    ->execute([$fin, $ms, $ganados, $id]);
                bm_sumarPuntos($pdo, $uid, $ganados);
            } else {
                /* Ronda superada: suma a lo acumulado (no al saldo) y espera
                   a que decida si sigue o se retira. */
                $r = bm_ronda((int)$p['ronda']);
                $pdo->prepare("UPDATE bm_partidas SET estado = 'ganada', fin_ms = ?, ms = ?, puntos = ? WHERE id = ?")
                    ->execute([$fin, $ms, $r['puntos'], $id]);
                $pdo->prepare("UPDATE bm_infinito SET estado = 'superada', pendientes = pendientes + ?,
                                      mejor = GREATEST(mejor, ?), mejor_en = IF(? > mejor, NOW(), mejor_en)
                                WHERE user_id = ?")
                    ->execute([$r['puntos'], (int)$p['ronda'], (int)$p['ronda'], $uid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $res = ['partida' => bm_vista($pdo, bm_partida($pdo, $id)), 'ganados' => $ganados];
    if ($res['partida']['modo'] === 'inf') $res['infinito'] = bm_infEstado($pdo, $uid);
    return $res;
}

/* ── Infinito ─────────────────────────────────────────────────────── */

function bm_infCaducada(array $p): bool {
    return bm_ms() > (int)$p['inicio_ms'] + bm_ronda((int)$p['ronda'])['seg'] * 1000;
}

/* Se acabó: mina ($boom) o tiempo (null). Se pierde lo acumulado. */
function bm_infPerder(PDO $pdo, int $uid, array $p, ?int $boom): void {
    $fin = bm_ms();
    $pdo->prepare("UPDATE bm_partidas SET estado = 'perdida', explotada = ?, fin_ms = ?, ms = ? WHERE id = ?")
        ->execute([$boom, $fin, $fin - (int)$p['inicio_ms'], (int)$p['id']]);
    $pdo->prepare("UPDATE bm_infinito SET estado = 'perdida', pendientes = 0 WHERE user_id = ?")->execute([$uid]);
}

function bm_infFila(PDO $pdo, int $uid, bool $bloquear = false): array {
    $pdo->prepare("INSERT IGNORE INTO bm_infinito (user_id) VALUES (?)")->execute([$uid]);
    $st = $pdo->prepare("SELECT * FROM bm_infinito WHERE user_id = ?" . ($bloquear ? " FOR UPDATE" : ""));
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/* Estado del infinito: ninguna (sin partida), jugando, superada (espera
   decisión), perdida (la última se perdió; se puede empezar otra). */
function bm_infEstado(PDO $pdo, int $uid): array {
    $f = bm_infFila($pdo, $uid);
    /* Si se le acabó el tiempo con la ventana cerrada, se cierra aquí. */
    if ($f['estado'] === 'jugando' && $f['partida_id']) {
        $p = bm_partida($pdo, (int)$f['partida_id']);
        if ($p && $p['estado'] === 'jugando' && bm_infCaducada($p)) {
            bm_infPerder($pdo, $uid, $p, null);
            $f = bm_infFila($pdo, $uid);
        }
    }
    $out = [
        'estado'     => $f['estado'],
        'ronda'      => (int)$f['ronda'],
        'pendientes' => (int)$f['pendientes'],
        'mejor'      => (int)$f['mejor'],
        'partida'    => $f['partida_id'] ? bm_vista($pdo, bm_partida($pdo, (int)$f['partida_id'])) : null,
    ];
    $sig = bm_ronda((int)$f['ronda'] + 1);
    $out['siguiente'] = ['ronda' => (int)$f['ronda'] + 1] + $sig;
    return $out;
}

/* Empezar desde la ronda 1 (si no hay una en marcha) o pasar a la
   siguiente tras superar una. */
function bm_infAvanzar(PDO $pdo, int $uid, bool $continuar): array {
    $pdo->beginTransaction();
    try {
        $f = bm_infFila($pdo, $uid, true);
        if ($continuar) {
            if ($f['estado'] !== 'superada') throw new InvalidArgumentException('No hay ninguna ronda superada');
            $ronda = (int)$f['ronda'] + 1;
            $pend = (int)$f['pendientes'];
        } else {
            if (in_array($f['estado'], ['jugando', 'superada'], true)) throw new InvalidArgumentException('Ya tienes una partida en marcha');
            $ronda = 1;
            $pend = 0;
        }
        $r = bm_ronda($ronda);
        $tid = bm_nuevoTablero($pdo, $r['w'], $r['h'], $r['m']);
        $pid = bm_crearPartida($pdo, $uid, 'inf', $tid, null, null, $ronda);
        $pdo->prepare("UPDATE bm_infinito SET estado = 'jugando', ronda = ?, partida_id = ?, pendientes = ? WHERE user_id = ?")
            ->execute([$ronda, $pid, $pend, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return bm_infEstado($pdo, $uid);
}

/* Retirarse tras superar una ronda: lo acumulado pasa al saldo. */
function bm_infRetirarse(PDO $pdo, int $uid): array {
    $pdo->beginTransaction();
    try {
        $f = bm_infFila($pdo, $uid, true);
        if ($f['estado'] !== 'superada') throw new InvalidArgumentException('Solo puedes retirarte al pasar una ronda');
        $ganados = (int)$f['pendientes'];
        bm_sumarPuntos($pdo, $uid, $ganados);
        $pdo->prepare("UPDATE bm_infinito SET estado = 'retirada', pendientes = 0 WHERE user_id = ?")->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['infinito' => bm_infEstado($pdo, $uid), 'ganados' => $ganados];
}

/* ── Rankings (menú y panel de la derecha al jugar) ───────────────── */

/* Datos públicos de unos usuarios: user_key (para el marco con
   data-marco-user), nombre y ruta de la foto (relativa a la raíz). */
function bm_usuarios(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, label, user_key FROM usuarios WHERE id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $label = (string)($u['label'] ?: $u['user_key']);
        $foto = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        $out[(int)$u['id']] = ['key' => (string)$u['user_key'], 'nombre' => $label, 'foto' => $foto !== '' ? $foto : null];
    }
    return $out;
}

/* Récords del infinito de todos (ronda más alta superada), de mayor a
   menor; a igualdad, quien llegó antes. El navegador mete ahí la partida
   en marcha del que juega para ver a quién va adelantando. */
function bm_rankingInf(PDO $pdo, int $uid): array {
    $rows = $pdo->query("SELECT user_id, mejor FROM bm_infinito WHERE mejor > 0 ORDER BY mejor DESC, mejor_en ASC")->fetchAll(PDO::FETCH_ASSOC);
    $us = bm_usuarios($pdo, array_merge(array_column($rows, 'user_id'), [$uid]));
    $lista = [];
    foreach ($rows as $r) {
        if (!isset($us[(int)$r['user_id']])) continue;
        $lista[] = $us[(int)$r['user_id']] + ['valor' => (int)$r['mejor'], 'yo' => (int)$r['user_id'] === $uid];
    }
    return ['lista' => $lista, 'yo' => $us[$uid] ?? null];
}

/* Los 3 mejores tiempos de hoy en un tablero del día. */
function bm_rankingDia(PDO $pdo, int $uid, int $nivel): array {
    $st = $pdo->prepare("SELECT user_id, ms FROM bm_partidas WHERE dia = ? AND nivel = ? AND modo = 'dia' AND estado = 'ganada'
                          ORDER BY ms ASC, fin_ms ASC LIMIT 3");
    $st->execute([bm_hoy(), $nivel]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $us = bm_usuarios($pdo, array_column($rows, 'user_id'));
    $lista = [];
    foreach ($rows as $r) {
        if (!isset($us[(int)$r['user_id']])) continue;
        $lista[] = $us[(int)$r['user_id']] + ['valor' => (int)$r['ms'], 'yo' => (int)$r['user_id'] === $uid];
    }
    return ['lista' => $lista];
}

/* ── Estadísticas (tarjetas como Wordelon, wordelon-img.php) ──────── */

function bm_tiempo(int $ms): string {
    $s = intdiv($ms, 1000);
    return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . '.' . intdiv($ms % 1000, 100);
}

/* Jugadores de las estadísticas de hoy: los que han terminado algún
   tablero del día (por orden de llegada) y luego los que tienen récord
   en el infinito. Cada uno con sus 3 resultados y su récord. */
function bm_jugadoresHoy(PDO $pdo): array {
    $hoy = bm_hoy();
    $st = $pdo->prepare("SELECT p.user_id, p.nivel, p.estado, p.ms, p.fin_ms, p.puntos, p.banderas, p.tablero_id
                           FROM bm_partidas p WHERE p.dia = ? AND p.modo = 'dia' AND p.estado <> 'jugando'
                          ORDER BY p.fin_ms ASC");
    $st->execute([$hoy]);
    $por = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int)$r['user_id'];
        $por[$u] = $por[$u] ?? [];
        $res = ['estado' => $r['estado']];
        if ($r['estado'] === 'ganada') {
            $res['ms'] = (int)$r['ms'];
            $res['puesto'] = bm_puesto($pdo, $hoy, (int)$r['nivel'], (int)$r['ms'], (int)$r['fin_ms']);
        } else {
            $res['pct'] = (int)round(100 * (int)$r['puntos'] / BM_NIVELES[(int)$r['nivel']]['base']);
        }
        $por[$u][(int)$r['nivel']] = $res;
    }
    $rec = [];
    foreach ($pdo->query("SELECT user_id, mejor FROM bm_infinito WHERE mejor > 0 ORDER BY mejor DESC, mejor_en ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rec[(int)$r['user_id']] = (int)$r['mejor'];
        if (!isset($por[(int)$r['user_id']])) $por[(int)$r['user_id']] = [];
    }
    if (!$por) return [];
    $ids = array_keys($por);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, label, user_key FROM usuarios WHERE id IN ($in)");
    $st->execute($ids);
    $us = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $us[(int)$u['id']] = $u;
    $out = [];
    foreach ($por as $uid => $niveles) {
        if (!isset($us[$uid])) continue;
        $label = (string)($us[$uid]['label'] ?: $us[$uid]['user_key']);
        $foto = function_exists('getUserImage') ? (string)getUserImage($label) : '';
        $out[] = [
            'uid' => $uid, 'label' => $label,
            'ruta' => $foto !== '' ? dirname(__DIR__, 2) . '/' . $foto : null,
            'resultado' => '',
            'niveles' => $niveles,
            'record' => $rec[$uid] ?? 0,
        ];
    }
    return $out;
}

/* Tarjeta del Buscaminas: una línea por tablero del día (tamaño a la
   izquierda; tiempo y puesto, % si perdió o "-" si no lo ha jugado a la
   derecha) y el récord del infinito. */
function bm_cfgImagen(): array {
    $lineas = count(BM_NIVELES) + 1;
    return [
        'titulo' => 'Buscaminas',
        'alto'   => $lineas * 13 + 4,
        'pinta'  => function (GdImage $im, array $look, array $j, int $y) {
            $c = $look['c']; $pt = wimg_pt($look['iface']);
            $ff = wimg_fuente($look['iface'], false); $fn = wimg_fuente($look['iface'], true);
            $x0 = 12; $x1 = WIMG_W - 12;
            $y -= 10;   /* sin línea de resultado debajo del nombre */
            $fila = function (string $izq, string $der, string $col) use ($im, $pt, $ff, $fn, $c, $x0, $x1, &$y) {
                $y += 13;
                wimg_texto($im, $izq, $pt, $ff, $c['textMuted'], $x0, $y);
                wimg_texto($im, $der, $pt, $fn, $col, $x1 - wimg_ancho($der, $fn, $pt), $y);
            };
            foreach (BM_NIVELES as $nv => $n) {
                $r = $j['niveles'][$nv] ?? null;
                if (!$r) $fila($n['w'] . '×' . $n['h'], '-', $c['textMuted']);
                elseif ($r['estado'] === 'ganada') $fila($n['w'] . '×' . $n['h'], bm_tiempo($r['ms']) . ' ' . $r['puesto'] . '.º', $c['text']);
                else $fila($n['w'] . '×' . $n['h'], 'Mina ' . $r['pct'] . '%', '#c00000');
            }
            $fila('Infinito', $j['record'] ? 'ronda ' . $j['record'] : '-', $j['record'] ? $c['text'] : $c['textMuted']);
        },
    ];
}
