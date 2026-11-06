<?php
/* ──────────────────────────────────────────────────────────────────────
   AJEDREZ (app Juegos; decidido así)
   ──────────────────────────────────────────────────────────────────────
   Partidas en tiempo real con reloj (minutos + incremento por jugada, los
   que se quiera). Dos formas de jugar:
     - Retos: solo a amigos (mutuos). Quien reta elige ritmo y apuesta;
       los dos ponen lo mismo (al retar y al aceptar) y el ganador se lleva
       las dos partes. Tablas = cada uno recupera lo suyo. El reto caduca a
       los 5 min (AJZ_RETO_MS) y se devuelve lo apostado. Aviso del haro
       (desktop-base.php, ajz-avisos) y en Juegos > Ajedrez.
     - Torneos (assets/juegos/ajedrez-torneos.php).
   Colores al azar. La partida empieza "esperando" a que los dos la abran;
   el reloj de las blancas corre cuando están los dos. Si en 2 min
   (AJZ_PRESENTARSE_MS) falta alguien: en un reto se anula y se devuelve
   la apuesta; en un torneo pierde quien no está (si no está ninguno,
   pasa el que se apuntó antes). Ya empezada, quien lleva 60 s sin dar
   señales (AJZ_AUSENTE_MS: la ventana de la partida llama cada segundo)
   pierde aunque le quede reloj.
   Todo lo decide el servidor (reglas en ajedrez-reglas.php, tiempo del
   servidor, jugadas en UCI). No hay cron: lo que caduca (retos, relojes,
   ausencias, torneos que empiezan) se mira en cada petición del ajedrez y
   en el aviso del haro, que llama cada 10 s desde todos los escritorios
   abiertos (ajz_tick).
   Partida en `ajz_partidas` (fen, jugadas UCI y SAN separadas por
   espacios, claves de posición para la triple repetición), retos en
   `ajz_retos`.
   ────────────────────────────────────────────────────────────────────── */

require_once __DIR__ . '/ajedrez-reglas.php';
require_once __DIR__ . '/buscaminas.php';            /* bm_usuarios, bm_ms */
require_once dirname(__DIR__) . '/social-helpers.php'; /* isMutualFollow, mutualFollowerIds */
require_once __DIR__ . '/ajedrez-torneos.php';

const AJZ_RETO_MS        = 300000;
const AJZ_PRESENTARSE_MS = 120000;
const AJZ_AUSENTE_MS     = 60000;
const AJZ_MIN_MAX        = 180;   /* minutos de reloj, como mucho */
const AJZ_INC_MAX        = 180;   /* segundos de incremento, como mucho */
const AJZ_APUESTA_MAX    = 1000000;

function ajz_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_partidas (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            blanco_id    INT NOT NULL,
            negro_id     INT NOT NULL,
            base_ms      INT NOT NULL,
            inc_ms       INT NOT NULL DEFAULT 0,
            apuesta      INT NOT NULL DEFAULT 0,
            torneo_id    INT NULL,
            fen          VARCHAR(100) NOT NULL,
            jugadas      MEDIUMTEXT NOT NULL,
            sans         MEDIUMTEXT NOT NULL,
            claves       MEDIUMTEXT NOT NULL,
            estado       VARCHAR(10) NOT NULL DEFAULT 'esperando',
            resultado    VARCHAR(3) NULL,
            motivo       VARCHAR(16) NULL,
            t_blanco     INT NOT NULL,
            t_negro      INT NOT NULL,
            turno_desde  BIGINT NOT NULL DEFAULT 0,
            creada       BIGINT NOT NULL,
            empezada     BIGINT NULL,
            fin          BIGINT NULL,
            visto_blanco BIGINT NOT NULL DEFAULT 0,
            visto_negro  BIGINT NOT NULL DEFAULT 0,
            tablas_de    INT NULL,
            INDEX idx_estado (estado),
            INDEX idx_blanco (blanco_id, estado),
            INDEX idx_negro (negro_id, estado),
            INDEX idx_torneo (torneo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_retos (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            de_id      INT NOT NULL,
            a_id       INT NOT NULL,
            base_ms    INT NOT NULL,
            inc_ms     INT NOT NULL DEFAULT 0,
            apuesta    INT NOT NULL DEFAULT 0,
            estado     VARCHAR(10) NOT NULL DEFAULT 'pendiente',
            partida_id INT NULL,
            creado     BIGINT NOT NULL,
            resuelto   BIGINT NULL,
            INDEX idx_a (a_id, estado),
            INDEX idx_de (de_id, estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE */ }
    ajz_torEnsure($pdo);
}

/* ── Utilidades ── */

/* Transacción; si ya hay una abierta (p. ej. un torneo que avanza al
   acabar una partida), se hace dentro de esa. */
function ajz_tx(PDO $pdo, callable $fn) {
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
/* Quita puntos solo si los tiene (false si no llega). */
function ajz_cobrar(PDO $pdo, int $uid, int $n): bool {
    if ($n <= 0) return true;
    $st = $pdo->prepare("UPDATE usuarios SET autismo = autismo - ? WHERE id = ? AND autismo >= ?");
    $st->execute([$n, $uid, $n]);
    return $st->rowCount() === 1;
}
function ajz_pagar(PDO $pdo, int $uid, int $n): void {
    if ($n > 0) $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$n, $uid]);
}
function ajz_saldo(PDO $pdo, int $uid): int {
    $st = $pdo->prepare("SELECT autismo FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}
function ajz_uidDeKey(PDO $pdo, string $key): int {
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $st->execute([$key]);
    return (int)$st->fetchColumn();
}
/* Ritmo de reloj pedido → [base_ms, inc_ms] (lanza si no vale). */
function ajz_ritmo($min, $inc): array {
    $min = (int)$min; $inc = (int)$inc;
    if ($min < 1 || $min > AJZ_MIN_MAX) throw new InvalidArgumentException('Los minutos tienen que ir de 1 a ' . AJZ_MIN_MAX);
    if ($inc < 0 || $inc > AJZ_INC_MAX) throw new InvalidArgumentException('El incremento tiene que ir de 0 a ' . AJZ_INC_MAX . ' segundos');
    return [$min * 60000, $inc * 1000];
}
function ajz_ritmoTexto(int $baseMs, int $incMs): string {
    return intdiv($baseMs, 60000) . '+' . intdiv($incMs, 1000);
}

/* ── Partidas ── */

function ajz_crearPartida(PDO $pdo, int $blanco, int $negro, int $baseMs, int $incMs, int $apuesta = 0, ?int $torneo = null): int {
    $e = ajz_fen(AJZ_INICIAL);
    $pdo->prepare("INSERT INTO ajz_partidas (blanco_id, negro_id, base_ms, inc_ms, apuesta, torneo_id, fen, jugadas, sans, claves,
                                             t_blanco, t_negro, creada)
                   VALUES (?,?,?,?,?,?,?,'','',?,?,?,?)")
        ->execute([$blanco, $negro, $baseMs, $incMs, $apuesta, $torneo, AJZ_INICIAL, ajz_clave($e), $baseMs, $baseMs, bm_ms()]);
    return (int)$pdo->lastInsertId();
}

/* Abre la partida bloqueada, deja que $fn la cambie y la guarda. Si con
   eso ha acabado y es de un torneo, el torneo sigue (ajz_torPartidaAcabada). */
function ajz_conPartida(PDO $pdo, int $id, callable $fn) {
    return ajz_tx($pdo, function () use ($pdo, $id, $fn) {
        $st = $pdo->prepare("SELECT * FROM ajz_partidas WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new InvalidArgumentException('No existe esa partida');
        foreach (['id', 'blanco_id', 'negro_id', 'base_ms', 'inc_ms', 'apuesta', 't_blanco', 't_negro', 'turno_desde',
                  'creada', 'visto_blanco', 'visto_negro'] as $k) $p[$k] = (int)$p[$k];
        $p['torneo_id'] = $p['torneo_id'] !== null ? (int)$p['torneo_id'] : null;
        $p['tablas_de'] = $p['tablas_de'] !== null ? (int)$p['tablas_de'] : null;
        $antes = $p['estado'];
        $r = $fn($p);
        $pdo->prepare("UPDATE ajz_partidas SET fen = ?, jugadas = ?, sans = ?, claves = ?, estado = ?, resultado = ?, motivo = ?,
                              t_blanco = ?, t_negro = ?, turno_desde = ?, empezada = ?, fin = ?, visto_blanco = ?, visto_negro = ?,
                              tablas_de = ? WHERE id = ?")
            ->execute([$p['fen'], $p['jugadas'], $p['sans'], $p['claves'], $p['estado'], $p['resultado'], $p['motivo'],
                       $p['t_blanco'], $p['t_negro'], $p['turno_desde'], $p['empezada'], $p['fin'], $p['visto_blanco'],
                       $p['visto_negro'], $p['tablas_de'], $p['id']]);
        if ($antes !== 'terminada' && $p['estado'] === 'terminada' && $p['torneo_id']) ajz_torPartidaAcabada($pdo, $p);
        return $r;
    });
}

/* Reloj de cada uno ahora mismo y de quién corre. */
function ajz_relojes(array $p, int $ahora): array {
    $w = $p['t_blanco']; $b = $p['t_negro']; $corre = null;
    if ($p['estado'] === 'jugando') {
        $corre = ajz_fen($p['fen'])['t'];
        $gasto = max(0, $ahora - $p['turno_desde']);
        if ($corre === 'w') $w -= $gasto; else $b -= $gasto;
    }
    return ['w' => max(0, $w), 'b' => max(0, $b), 'corre' => $corre];
}

/* Acaba la partida y reparte la apuesta. $res: '1-0', '0-1' o '1/2'. */
function ajz_terminar(PDO $pdo, array &$p, string $res, string $motivo, int $ahora): void {
    if ($p['estado'] === 'jugando') {
        $r = ajz_relojes($p, $ahora);
        $p['t_blanco'] = $r['w']; $p['t_negro'] = $r['b'];
    }
    $p['estado'] = 'terminada';
    $p['resultado'] = $res;
    $p['motivo'] = $motivo;
    $p['fin'] = $ahora;
    $p['tablas_de'] = null;
    $a = $p['apuesta'];
    if ($a > 0) {
        if ($res === '1-0')      ajz_pagar($pdo, $p['blanco_id'], 2 * $a);
        elseif ($res === '0-1')  ajz_pagar($pdo, $p['negro_id'], 2 * $a);
        else { ajz_pagar($pdo, $p['blanco_id'], $a); ajz_pagar($pdo, $p['negro_id'], $a); }
    }
}

/* Lo que caduca con el tiempo: presentarse, el reloj y las ausencias. */
function ajz_revisar(PDO $pdo, array &$p, int $ahora): void {
    if ($p['estado'] === 'esperando') {
        $w = $p['visto_blanco'] > 0; $b = $p['visto_negro'] > 0;
        if ($w && $b) {
            $p['estado'] = 'jugando';
            $p['empezada'] = $ahora;
            $p['turno_desde'] = $ahora;
            return;
        }
        if ($ahora < $p['creada'] + AJZ_PRESENTARSE_MS) return;
        if (!$p['torneo_id']) {
            /* Reto: nadie gana, se devuelve lo apostado. */
            $p['estado'] = 'anulada';
            $p['motivo'] = 'no-presentado';
            $p['fin'] = $ahora;
            ajz_pagar($pdo, $p['blanco_id'], $p['apuesta']);
            ajz_pagar($pdo, $p['negro_id'], $p['apuesta']);
            return;
        }
        if ($w || $b) { ajz_terminar($pdo, $p, $w ? '1-0' : '0-1', 'no-presentado', $ahora); return; }
        $primero = ajz_torPrimero($pdo, $p['torneo_id'], $p['blanco_id'], $p['negro_id']);
        ajz_terminar($pdo, $p, $primero === $p['blanco_id'] ? '1-0' : '0-1', 'nadie', $ahora);
        return;
    }
    if ($p['estado'] !== 'jugando') return;
    $r = ajz_relojes($p, $ahora);
    $mueve = $r['corre'];
    if ($r[$mueve] <= 0) {
        /* Sin tiempo: pierde, salvo que el otro no pueda dar mate. */
        $e = ajz_fen($p['fen']);
        $otroPuede = ajz_puedeMatar($e['b'], $mueve !== 'w');
        if ($mueve === 'w') $p['t_blanco'] = 0; else $p['t_negro'] = 0;
        $p['turno_desde'] = $ahora;
        if (!$otroPuede) ajz_terminar($pdo, $p, '1/2', 'tiempo-material', $ahora);
        else ajz_terminar($pdo, $p, $mueve === 'w' ? '0-1' : '1-0', 'tiempo', $ahora);
        return;
    }
    $limite = $ahora - AJZ_AUSENTE_MS;
    $fueraW = $p['visto_blanco'] < $limite; $fueraB = $p['visto_negro'] < $limite;
    if ($fueraW || $fueraB) {
        /* Si se han ido los dos, pierde el que se fue antes. */
        $pierdeW = $fueraW && (!$fueraB || $p['visto_blanco'] <= $p['visto_negro']);
        ajz_terminar($pdo, $p, $pierdeW ? '0-1' : '1-0', 'abandono', $ahora);
    }
}

function ajz_color(array $p, int $uid): ?string {
    if ($p['blanco_id'] === $uid) return 'w';
    if ($p['negro_id'] === $uid) return 'b';
    return null;
}
/* La ventana de la partida da señales de vida al pedirla. */
function ajz_senal(array &$p, string $color, int $ahora): void {
    if (!in_array($p['estado'], ['esperando', 'jugando'], true)) return;
    if ($color === 'w') $p['visto_blanco'] = $ahora; else $p['visto_negro'] = $ahora;
}

function ajz_vista(PDO $pdo, array $p, int $uid, int $ahora): array {
    $yo = ajz_color($p, $uid);
    $e = ajz_fen($p['fen']);
    $us = bm_usuarios($pdo, [$p['blanco_id'], $p['negro_id']]);
    $jug = $p['jugadas'] === '' ? [] : explode(' ', $p['jugadas']);
    $out = [
        'id'        => $p['id'],
        'yo'        => $yo,
        'blanco'    => $us[$p['blanco_id']] ?? null,
        'negro'     => $us[$p['negro_id']] ?? null,
        'fen'       => $p['fen'],
        'turno'     => $e['t'],
        'n'         => count($jug),
        'ultima'    => $jug ? end($jug) : null,
        'sans'      => $p['sans'] === '' ? [] : explode(' ', $p['sans']),
        'jaque'     => ajz_enJaque($e) ? ajz_sqNombre((int)strpos($e['b'], $e['t'] === 'w' ? 'K' : 'k')) : null,
        'estado'    => $p['estado'],
        'resultado' => $p['resultado'],
        'motivo'    => $p['motivo'],
        'reloj'     => ajz_relojes($p, $ahora),
        'ritmo'     => ajz_ritmoTexto($p['base_ms'], $p['inc_ms']),
        'apuesta'   => $p['apuesta'],
        'tablas'    => $p['tablas_de'] === null ? null : ($p['tablas_de'] === $uid ? 'yo' : 'rival'),
        'legales'   => [],
        'torneo'    => $p['torneo_id'] ? ajz_torInfoPartida($pdo, $p) : null,
    ];
    if ($p['estado'] === 'jugando' && $yo === $e['t']) $out['legales'] = array_map('ajz_uci', ajz_legales($e));
    if ($p['estado'] === 'esperando') {
        $out['esperando'] = ['quedan' => max(0, $p['creada'] + AJZ_PRESENTARSE_MS - $ahora),
                             'w' => $p['visto_blanco'] > 0, 'b' => $p['visto_negro'] > 0];
    }
    /* Rival sin dar señales unos segundos: "pierde en N s" si no vuelve. */
    if ($p['estado'] === 'jugando' && $yo) {
        $suyo = $yo === 'w' ? $p['visto_negro'] : $p['visto_blanco'];
        if ($ahora - $suyo > 5000) $out['rivalFuera'] = max(0, $suyo + AJZ_AUSENTE_MS - $ahora);
    }
    /* Lo que me llevo de la apuesta (lo que gano por encima de lo puesto). */
    if ($p['estado'] === 'terminada' && $yo && $p['apuesta'] > 0) {
        $gano = ($p['resultado'] === '1-0' && $yo === 'w') || ($p['resultado'] === '0-1' && $yo === 'b');
        $out['puntos'] = $p['resultado'] === '1/2' ? 0 : ($gano ? $p['apuesta'] : -$p['apuesta']);
    }
    return $out;
}

/* GET de la partida (cada segundo desde la ventana): señal + revisar. */
function ajz_partidaVer(PDO $pdo, int $uid, int $id): array {
    return ajz_conPartida($pdo, $id, function (array &$p) use ($pdo, $uid) {
        $yo = ajz_color($p, $uid);
        if (!$yo) throw new InvalidArgumentException('No juegas esta partida');
        $ahora = bm_ms();
        ajz_senal($p, $yo, $ahora);
        ajz_revisar($pdo, $p, $ahora);
        return $p;
    });
}

function ajz_mover(PDO $pdo, int $uid, int $id, string $uci, int $n): array {
    return ajz_conPartida($pdo, $id, function (array &$p) use ($pdo, $uid, $uci, $n) {
        $yo = ajz_color($p, $uid);
        if (!$yo) throw new InvalidArgumentException('No juegas esta partida');
        $ahora = bm_ms();
        ajz_senal($p, $yo, $ahora);
        ajz_revisar($pdo, $p, $ahora);
        if ($p['estado'] !== 'jugando') return $p;
        $jug = $p['jugadas'] === '' ? [] : explode(' ', $p['jugadas']);
        $e = ajz_fen($p['fen']);
        /* $n = jugadas que tenía la ventana: si no cuadra, no se mueve
           (doble clic, o una jugada vieja que llega tarde). */
        if ($e['t'] !== $yo || $n !== count($jug)) return $p;
        $legales = ajz_legales($e);
        $j = null;
        foreach ($legales as $l) if (ajz_uci($l) === $uci) { $j = $l; break; }
        if (!$j) throw new InvalidArgumentException('Esa jugada no vale');

        $san = ajz_san($e, $j, $legales);
        $gasto = max(0, $ahora - $p['turno_desde']);
        if ($yo === 'w') $p['t_blanco'] = $p['t_blanco'] - $gasto + $p['inc_ms'];
        else             $p['t_negro']  = $p['t_negro']  - $gasto + $p['inc_ms'];
        $p['turno_desde'] = $ahora;
        $e2 = ajz_aplicar($e, $j);
        $p['fen'] = ajz_fenTexto($e2);
        $jug[] = $uci;
        $p['jugadas'] = implode(' ', $jug);
        $p['sans'] = trim($p['sans'] . ' ' . $san);
        $clave = ajz_clave($e2);
        $p['claves'] .= '|' . $clave;
        /* Una oferta de tablas del rival se rechaza jugando. */
        if ($p['tablas_de'] !== null && $p['tablas_de'] !== $uid) $p['tablas_de'] = null;

        if (!ajz_legales($e2)) {
            if (ajz_enJaque($e2)) ajz_terminar($pdo, $p, $yo === 'w' ? '1-0' : '0-1', 'mate', $ahora);
            else ajz_terminar($pdo, $p, '1/2', 'ahogado', $ahora);
        } elseif (ajz_materialInsuficiente($e2['b'])) {
            ajz_terminar($pdo, $p, '1/2', 'material', $ahora);
        } elseif (substr_count($p['claves'], $clave) >= 3) {
            ajz_terminar($pdo, $p, '1/2', 'repeticion', $ahora);
        } elseif ($e2['m'] >= 100) {
            ajz_terminar($pdo, $p, '1/2', '50', $ahora);
        }
        return $p;
    });
}

function ajz_rendirse(PDO $pdo, int $uid, int $id): array {
    return ajz_conPartida($pdo, $id, function (array &$p) use ($pdo, $uid) {
        $yo = ajz_color($p, $uid);
        if (!$yo) throw new InvalidArgumentException('No juegas esta partida');
        $ahora = bm_ms();
        ajz_senal($p, $yo, $ahora);
        ajz_revisar($pdo, $p, $ahora);
        if (in_array($p['estado'], ['esperando', 'jugando'], true)) {
            ajz_terminar($pdo, $p, $yo === 'w' ? '0-1' : '1-0', 'rendicion', $ahora);
        }
        return $p;
    });
}

/* $tipo: ofrecer | aceptar | rechazar. */
function ajz_tablas(PDO $pdo, int $uid, int $id, string $tipo): array {
    return ajz_conPartida($pdo, $id, function (array &$p) use ($pdo, $uid, $tipo) {
        $yo = ajz_color($p, $uid);
        if (!$yo) throw new InvalidArgumentException('No juegas esta partida');
        $ahora = bm_ms();
        ajz_senal($p, $yo, $ahora);
        ajz_revisar($pdo, $p, $ahora);
        if ($p['estado'] !== 'jugando') return $p;
        $delRival = $p['tablas_de'] !== null && $p['tablas_de'] !== $uid;
        if ($tipo === 'ofrecer' && $p['tablas_de'] === null) $p['tablas_de'] = $uid;
        elseif ($tipo === 'aceptar' && $delRival) ajz_terminar($pdo, $p, '1/2', 'acuerdo', $ahora);
        elseif ($tipo === 'rechazar' && $delRival) $p['tablas_de'] = null;
        return $p;
    });
}

/* ── Retos ── */

function ajz_caducarRetos(PDO $pdo, int $ahora): void {
    $st = $pdo->prepare("SELECT id, de_id, apuesta FROM ajz_retos WHERE estado = 'pendiente' AND creado < ?");
    $st->execute([$ahora - AJZ_RETO_MS]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        ajz_tx($pdo, function () use ($pdo, $r, $ahora) {
            $u = $pdo->prepare("UPDATE ajz_retos SET estado = 'caducado', resuelto = ? WHERE id = ? AND estado = 'pendiente'");
            $u->execute([$ahora, $r['id']]);
            if ($u->rowCount() === 1) ajz_pagar($pdo, (int)$r['de_id'], (int)$r['apuesta']);
        });
    }
}

function ajz_retar(PDO $pdo, int $uid, string $amigoKey, $min, $inc, $apuesta): array {
    $aid = ajz_uidDeKey($pdo, $amigoKey);
    if ($aid <= 0 || $aid === $uid) throw new InvalidArgumentException('Elige un amigo');
    if (!isMutualFollow($pdo, $uid, $aid)) throw new InvalidArgumentException('Solo puedes retar a tus amigos');
    [$base, $incMs] = ajz_ritmo($min, $inc);
    $apuesta = (int)$apuesta;
    if ($apuesta < 0 || $apuesta > AJZ_APUESTA_MAX) throw new InvalidArgumentException('Apuesta no válida');
    $ahora = bm_ms();
    ajz_caducarRetos($pdo, $ahora);
    return ajz_tx($pdo, function () use ($pdo, $uid, $aid, $base, $incMs, $apuesta, $ahora) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ajz_retos WHERE de_id = ? AND a_id = ? AND estado = 'pendiente'");
        $st->execute([$uid, $aid]);
        if ((int)$st->fetchColumn() > 0) throw new InvalidArgumentException('Ya tienes un reto pendiente con esa persona');
        if (!ajz_cobrar($pdo, $uid, $apuesta)) throw new InvalidArgumentException('No tienes puntos suficientes para esa apuesta');
        $pdo->prepare("INSERT INTO ajz_retos (de_id, a_id, base_ms, inc_ms, apuesta, creado) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $aid, $base, $incMs, $apuesta, $ahora]);
        return ['reto' => (int)$pdo->lastInsertId()];
    });
}

/* Aceptar (pone su parte de la apuesta y crea la partida) o rechazar. */
function ajz_responder(PDO $pdo, int $uid, int $id, bool $aceptar): array {
    $ahora = bm_ms();
    ajz_caducarRetos($pdo, $ahora);
    return ajz_tx($pdo, function () use ($pdo, $uid, $id, $aceptar, $ahora) {
        $st = $pdo->prepare("SELECT * FROM ajz_retos WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['a_id'] !== $uid) throw new InvalidArgumentException('No existe ese reto');
        if ($r['estado'] !== 'pendiente') {
            throw new InvalidArgumentException($r['estado'] === 'caducado' ? 'El reto ha caducado' : 'El reto ya no está pendiente');
        }
        if (!$aceptar) {
            $pdo->prepare("UPDATE ajz_retos SET estado = 'rechazado', resuelto = ? WHERE id = ?")->execute([$ahora, $id]);
            ajz_pagar($pdo, (int)$r['de_id'], (int)$r['apuesta']);
            return ['partida' => null];
        }
        if (!ajz_cobrar($pdo, $uid, (int)$r['apuesta'])) throw new InvalidArgumentException('No tienes puntos suficientes para esa apuesta');
        $jug = [(int)$r['de_id'], $uid];
        if (random_int(0, 1)) $jug = array_reverse($jug);
        $pid = ajz_crearPartida($pdo, $jug[0], $jug[1], (int)$r['base_ms'], (int)$r['inc_ms'], (int)$r['apuesta']);
        $pdo->prepare("UPDATE ajz_retos SET estado = 'aceptado', partida_id = ?, resuelto = ? WHERE id = ?")->execute([$pid, $ahora, $id]);
        return ['partida' => $pid];
    });
}

function ajz_cancelarReto(PDO $pdo, int $uid, int $id): void {
    ajz_tx($pdo, function () use ($pdo, $uid, $id) {
        $st = $pdo->prepare("SELECT * FROM ajz_retos WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['de_id'] !== $uid || $r['estado'] !== 'pendiente') return;
        $pdo->prepare("UPDATE ajz_retos SET estado = 'cancelado', resuelto = ? WHERE id = ?")->execute([bm_ms(), $id]);
        ajz_pagar($pdo, $uid, (int)$r['apuesta']);
    });
}

function ajz_amigos(PDO $pdo, int $uid): array {
    $us = bm_usuarios($pdo, mutualFollowerIds($pdo, $uid));
    $out = array_values($us);
    usort($out, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
    return $out;
}

/* ── Lo que caduca, de todos (sin cron) ── */
function ajz_tick(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $ahora = bm_ms();
    ajz_caducarRetos($pdo, $ahora);
    $rows = $pdo->query("SELECT id, estado, fen, creada, visto_blanco, visto_negro, turno_desde, t_blanco, t_negro
                           FROM ajz_partidas WHERE estado IN ('esperando','jugando')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $toca = false;
        if ($r['estado'] === 'esperando') $toca = $ahora >= (int)$r['creada'] + AJZ_PRESENTARSE_MS;
        else {
            $mueveW = ajz_fen($r['fen'])['t'] === 'w';
            $queda = ($mueveW ? (int)$r['t_blanco'] : (int)$r['t_negro']) - ($ahora - (int)$r['turno_desde']);
            $toca = $queda <= 0 || min((int)$r['visto_blanco'], (int)$r['visto_negro']) < $ahora - AJZ_AUSENTE_MS;
        }
        if (!$toca) continue;
        try {
            ajz_conPartida($pdo, (int)$r['id'], function (array &$p) use ($pdo) { ajz_revisar($pdo, $p, bm_ms()); });
        } catch (Throwable $e) { error_log('[ajedrez] tick ' . $r['id'] . ': ' . $e->getMessage()); }
    }
    ajz_torTick($pdo);
}

/* Menú del ajedrez: saldo, retos, mis partidas y torneos. */
function ajz_estado(PDO $pdo, int $uid): array {
    ajz_tick($pdo);
    $ahora = bm_ms();
    $st = $pdo->prepare("SELECT * FROM ajz_retos WHERE (a_id = ? AND estado = 'pendiente')
                            OR (de_id = ? AND (estado = 'pendiente' OR (estado IN ('rechazado','caducado') AND resuelto > ?)))
                          ORDER BY creado DESC");
    $st->execute([$uid, $uid, $ahora - 120000]);
    $retos = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM ajz_partidas WHERE (blanco_id = ? OR negro_id = ?) AND estado IN ('esperando','jugando') ORDER BY id DESC");
    $st->execute([$uid, $uid]);
    $partidas = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($retos as $r) { $ids[] = (int)$r['de_id']; $ids[] = (int)$r['a_id']; }
    foreach ($partidas as $p) { $ids[] = (int)$p['blanco_id']; $ids[] = (int)$p['negro_id']; }
    $us = bm_usuarios($pdo, $ids);
    $rec = []; $env = [];
    foreach ($retos as $r) {
        $x = ['id' => (int)$r['id'], 'ritmo' => ajz_ritmoTexto((int)$r['base_ms'], (int)$r['inc_ms']), 'apuesta' => (int)$r['apuesta'],
              'estado' => $r['estado'], 'quedan' => max(0, (int)$r['creado'] + AJZ_RETO_MS - $ahora)];
        if ((int)$r['a_id'] === $uid) $rec[] = $x + ['de' => $us[(int)$r['de_id']] ?? null];
        else $env[] = $x + ['a' => $us[(int)$r['a_id']] ?? null];
    }
    $mias = [];
    foreach ($partidas as $p) {
        $w = (int)$p['blanco_id'] === $uid;
        $mias[] = [
            'id'      => (int)$p['id'],
            'rival'   => $us[$w ? (int)$p['negro_id'] : (int)$p['blanco_id']] ?? null,
            'ritmo'   => ajz_ritmoTexto((int)$p['base_ms'], (int)$p['inc_ms']),
            'apuesta' => (int)$p['apuesta'],
            'estado'  => $p['estado'],
            'torneo'  => $p['torneo_id'] ? (int)$p['torneo_id'] : null,
            /* Sin abrir todavía (para entrar sola desde el menú). */
            'nueva'   => $p['estado'] === 'esperando' && (int)($w ? $p['visto_blanco'] : $p['visto_negro']) === 0,
        ];
    }
    return ['autismo' => ajz_saldo($pdo, $uid), 'recibidos' => $rec, 'enviados' => $env, 'partidas' => $mias,
            'torneos' => ajz_torLista($pdo, $uid)];
}

/* Aviso del haro (desktop-base.php, cada 10 s): retos recibidos y
   partidas que esperan a que las abra (reto aceptado o ronda de torneo). */
function ajz_avisos(PDO $pdo, int $uid): array {
    ajz_tick($pdo);
    $ahora = bm_ms();
    $st = $pdo->prepare("SELECT * FROM ajz_retos WHERE a_id = ? AND estado = 'pendiente' ORDER BY creado");
    $st->execute([$uid]);
    $retos = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT p.*, t.nombre AS torneo_nombre FROM ajz_partidas p LEFT JOIN ajz_torneos t ON t.id = p.torneo_id
                          WHERE p.estado = 'esperando' AND ((p.blanco_id = ? AND p.visto_blanco = 0) OR (p.negro_id = ? AND p.visto_negro = 0))");
    $st->execute([$uid, $uid]);
    $parts = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($retos, 'de_id');
    foreach ($parts as $p) $ids[] = (int)$p['blanco_id'] === $uid ? (int)$p['negro_id'] : (int)$p['blanco_id'];
    $us = bm_usuarios($pdo, $ids);
    $out = ['retos' => [], 'partidas' => []];
    foreach ($retos as $r) {
        $out['retos'][] = ['id' => (int)$r['id'], 'de' => $us[(int)$r['de_id']] ?? null, 'apuesta' => (int)$r['apuesta'],
                           'ritmo' => ajz_ritmoTexto((int)$r['base_ms'], (int)$r['inc_ms']), 'ts' => intdiv((int)$r['creado'], 1000)];
    }
    foreach ($parts as $p) {
        $rival = (int)$p['blanco_id'] === $uid ? (int)$p['negro_id'] : (int)$p['blanco_id'];
        $out['partidas'][] = ['id' => (int)$p['id'], 'rival' => $us[$rival] ?? null, 'torneo' => $p['torneo_nombre'], 'torneoId' => $p['torneo_id'] ? (int)$p['torneo_id'] : null,
                              'ts' => intdiv((int)$p['creada'], 1000), 'quedan' => max(0, (int)$p['creada'] + AJZ_PRESENTARSE_MS - $ahora)];
    }
    return $out;
}
