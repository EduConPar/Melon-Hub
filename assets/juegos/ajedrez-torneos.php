<?php
/* ──────────────────────────────────────────────────────────────────────
   AJEDREZ: TORNEOS (decidido así)
   ──────────────────────────────────────────────────────────────────────
   Cualquiera puede organizar uno: nombre, jugadores (4, 8, 16 o 32),
   ritmo, fecha y hora de inicio, precio de entrada y premios del 1.º, 2.º
   y 3.º. Los premios salen del bolsillo del anfitrión al crearlo; las
   entradas son para él y le llegan cuando el torneo empieza (hasta
   entonces se guardan, por si hay que devolverlas). Se puede apuntar
   cualquiera; el anfitrión también, sin pagar entrada.
   Eliminatoria directa con cuadro al azar. Tablas = otra partida con los
   colores cambiados, hasta que alguien gane. Cada ronda empieza cuando
   ha acabado entera la anterior. La última ronda es la final y, a la vez,
   la partida por el 3.er puesto entre los que pierden en semifinales.
   A la hora de inicio (ajz_torTick, sin cron): lleno → empieza; si no,
   se cancela y se devuelve todo (entradas y premios). Antes de empezar
   uno puede borrarse (recupera la entrada) y el anfitrión cancelarlo.
   Discord: el bot lo anuncia en el MISMO canal que los eventos del
   calendario (EV_DISCORD_CHANNEL de assets/events/api.php) y edita el
   mensaje al apuntarse/borrarse gente, al empezar, al acabar (podio) y
   si se cancela.
   Cancelado: aviso del haro a cada apuntado (menos a quien lo cancela)
   con lo que se le ha devuelto (tabla ajz_cancelados, se entrega una vez
   en ajz-avisos) y deja de salir en la lista de torneos del menú.
   Tablas: ajz_torneos, ajz_inscritos (pagado = la entrada guardada),
   ajz_cruces (ronda, slot; la final es slot 0 de la última ronda y el
   3.er puesto el slot 1; juegos = partidas jugadas en ese cruce, para
   cambiar los colores).
   ────────────────────────────────────────────────────────────────────── */

const AJZ_TOR_PLAZAS  = [4, 8, 16, 32];
const AJZ_TOR_CANAL   = '1516472123651133450';   /* = EV_DISCORD_CHANNEL (eventos del calendario) */
const AJZ_TOR_MIN_ANTES = 300000;                /* hay que crearlo al menos 5 min antes */
const AJZ_TOR_MAX_DIAS  = 60;
const AJZ_TOR_PUNTOS_MAX = 1000000;

function ajz_torEnsure(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_torneos (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            host_id     INT NOT NULL,
            nombre      VARCHAR(60) NOT NULL,
            plazas      TINYINT NOT NULL,
            entrada     INT NOT NULL DEFAULT 0,
            premio1     INT NOT NULL DEFAULT 0,
            premio2     INT NOT NULL DEFAULT 0,
            premio3     INT NOT NULL DEFAULT 0,
            base_ms     INT NOT NULL,
            inc_ms      INT NOT NULL DEFAULT 0,
            empieza     BIGINT NOT NULL,
            estado      VARCHAR(11) NOT NULL DEFAULT 'inscripcion',
            motivo      VARCHAR(12) NULL,
            ronda       TINYINT NOT NULL DEFAULT 0,
            primero     INT NULL,
            segundo     INT NULL,
            tercero     INT NULL,
            discord_msg VARCHAR(32) NULL,
            creado      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_estado (estado, empieza)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_inscritos (
            torneo_id INT NOT NULL,
            user_id   INT NOT NULL,
            pagado    INT NOT NULL DEFAULT 0,
            orden     BIGINT NOT NULL,
            PRIMARY KEY (torneo_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_cancelados (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            user_id   INT NOT NULL,
            torneo_id INT NOT NULL,
            devuelto  INT NOT NULL DEFAULT 0,
            creado    BIGINT NOT NULL,
            entregado TINYINT NOT NULL DEFAULT 0,
            INDEX idx_user (user_id, entregado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_cruces (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            torneo_id  INT NOT NULL,
            ronda      TINYINT NOT NULL,
            slot       TINYINT NOT NULL,
            a_id       INT NOT NULL,
            b_id       INT NOT NULL,
            ganador_id INT NULL,
            partida_id INT NULL,
            juegos     TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_cruce (torneo_id, ronda, slot),
            INDEX idx_partida (partida_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE */ }
}

function ajz_torRondas(int $plazas): int { return (int)round(log($plazas, 2)); }
/* Nombre de la ronda contando desde el final. */
function ajz_torNombreRonda(int $ronda, int $total, int $slot = 0): string {
    $faltan = $total - $ronda;
    if ($faltan === 0) return $slot === 1 ? '3.er puesto' : 'Final';
    return ['Semifinales', 'Cuartos', 'Octavos', 'Dieciseisavos'][$faltan - 1] ?? ('Ronda ' . $ronda);
}

function ajz_torCargar(PDO $pdo, int $id, bool $bloquear = false): array {
    $st = $pdo->prepare("SELECT * FROM ajz_torneos WHERE id = ?" . ($bloquear ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new InvalidArgumentException('No existe ese torneo');
    foreach (['id', 'host_id', 'plazas', 'entrada', 'premio1', 'premio2', 'premio3', 'base_ms', 'inc_ms', 'empieza', 'ronda'] as $k) $t[$k] = (int)$t[$k];
    return $t;
}
function ajz_torInscritos(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT user_id, pagado, orden FROM ajz_inscritos WHERE torneo_id = ? ORDER BY orden, user_id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* "2026-11-20T18:30" en hora de Madrid → milisegundos. */
function ajz_torHora(string $txt): int {
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $txt, new DateTimeZone('Europe/Madrid'));
    if (!$d) throw new InvalidArgumentException('Pon la fecha y la hora de inicio');
    return $d->getTimestamp() * 1000;
}

function ajz_torCrear(PDO $pdo, int $uid, array $d): array {
    $nombre = trim(preg_replace('/\s+/u', ' ', (string)($d['nombre'] ?? '')));
    if ($nombre === '' || mb_strlen($nombre) > 60) throw new InvalidArgumentException('El nombre tiene que tener entre 1 y 60 letras');
    $plazas = (int)($d['plazas'] ?? 0);
    if (!in_array($plazas, AJZ_TOR_PLAZAS, true)) throw new InvalidArgumentException('Jugadores: 4, 8, 16 o 32');
    [$base, $inc] = ajz_ritmo($d['min'] ?? 0, $d['inc'] ?? 0);
    $n = [];
    foreach (['entrada', 'premio1', 'premio2', 'premio3'] as $k) {
        $n[$k] = (int)($d[$k] ?? 0);
        if ($n[$k] < 0 || $n[$k] > AJZ_TOR_PUNTOS_MAX) throw new InvalidArgumentException('Cantidad de puntos no válida');
    }
    $empieza = ajz_torHora((string)($d['empieza'] ?? ''));
    $ahora = bm_ms();
    if ($empieza < $ahora + AJZ_TOR_MIN_ANTES) throw new InvalidArgumentException('Tiene que empezar dentro de 5 minutos como pronto');
    if ($empieza > $ahora + AJZ_TOR_MAX_DIAS * 86400000) throw new InvalidArgumentException('Como mucho, dentro de ' . AJZ_TOR_MAX_DIAS . ' días');
    $premios = $n['premio1'] + $n['premio2'] + $n['premio3'];
    $id = ajz_tx($pdo, function () use ($pdo, $uid, $nombre, $plazas, $base, $inc, $n, $empieza, $premios) {
        if (!ajz_cobrar($pdo, $uid, $premios)) throw new InvalidArgumentException('No tienes puntos suficientes para los premios');
        $pdo->prepare("INSERT INTO ajz_torneos (host_id, nombre, plazas, entrada, premio1, premio2, premio3, base_ms, inc_ms, empieza)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $nombre, $plazas, $n['entrada'], $n['premio1'], $n['premio2'], $n['premio3'], $base, $inc, $empieza]);
        return (int)$pdo->lastInsertId();
    });
    ajz_torDiscordLuego($pdo, $id);
    return ['torneo' => $id];
}

function ajz_torApuntarse(PDO $pdo, int $uid, int $id): void {
    ajz_tx($pdo, function () use ($pdo, $uid, $id) {
        $t = ajz_torCargar($pdo, $id, true);
        if ($t['estado'] !== 'inscripcion' || bm_ms() >= $t['empieza']) throw new InvalidArgumentException('Ya no se puede apuntar nadie');
        $ins = ajz_torInscritos($pdo, $id);
        if (in_array($uid, array_map('intval', array_column($ins, 'user_id')), true)) return;
        if (count($ins) >= $t['plazas']) throw new InvalidArgumentException('Está lleno');
        $pago = $uid === $t['host_id'] ? 0 : $t['entrada'];
        if (!ajz_cobrar($pdo, $uid, $pago)) throw new InvalidArgumentException('No tienes puntos suficientes para la entrada');
        $pdo->prepare("INSERT INTO ajz_inscritos (torneo_id, user_id, pagado, orden) VALUES (?,?,?,?)")->execute([$id, $uid, $pago, bm_ms()]);
    });
    ajz_torDiscordLuego($pdo, $id);
}

function ajz_torSalir(PDO $pdo, int $uid, int $id): void {
    ajz_tx($pdo, function () use ($pdo, $uid, $id) {
        $t = ajz_torCargar($pdo, $id, true);
        if ($t['estado'] !== 'inscripcion') throw new InvalidArgumentException('El torneo ya ha empezado');
        $st = $pdo->prepare("SELECT pagado FROM ajz_inscritos WHERE torneo_id = ? AND user_id = ?");
        $st->execute([$id, $uid]);
        $pagado = $st->fetchColumn();
        if ($pagado === false) return;
        $pdo->prepare("DELETE FROM ajz_inscritos WHERE torneo_id = ? AND user_id = ?")->execute([$id, $uid]);
        ajz_pagar($pdo, $uid, (int)$pagado);
    });
    ajz_torDiscordLuego($pdo, $id);
}

/* Cancelado (por el anfitrión o porque no se llenó): se devuelve todo. */
function ajz_torCancelarDentro(PDO $pdo, array $t, string $motivo): void {
    $aviso = $pdo->prepare("INSERT INTO ajz_cancelados (user_id, torneo_id, devuelto, creado) VALUES (?,?,?,?)");
    foreach (ajz_torInscritos($pdo, $t['id']) as $i) {
        ajz_pagar($pdo, (int)$i['user_id'], (int)$i['pagado']);
        /* Aviso a cada apuntado; quien lo cancela a mano ya lo sabe. */
        if (!($motivo === 'anfitrion' && (int)$i['user_id'] === $t['host_id'])) {
            $aviso->execute([(int)$i['user_id'], $t['id'], (int)$i['pagado'], bm_ms()]);
        }
    }
    $pdo->prepare("UPDATE ajz_inscritos SET pagado = 0 WHERE torneo_id = ?")->execute([$t['id']]);
    ajz_pagar($pdo, $t['host_id'], $t['premio1'] + $t['premio2'] + $t['premio3']);
    $pdo->prepare("UPDATE ajz_torneos SET estado = 'cancelado', motivo = ? WHERE id = ?")->execute([$motivo, $t['id']]);
}
function ajz_torCancelar(PDO $pdo, int $uid, int $id): void {
    ajz_tx($pdo, function () use ($pdo, $uid, $id) {
        $t = ajz_torCargar($pdo, $id, true);
        if ($t['host_id'] !== $uid) throw new InvalidArgumentException('Solo puede cancelarlo quien lo organiza');
        if ($t['estado'] !== 'inscripcion') throw new InvalidArgumentException('El torneo ya ha empezado');
        ajz_torCancelarDentro($pdo, $t, 'anfitrion');
    });
    ajz_torDiscordLuego($pdo, $id);
}

/* Cruce nuevo con su primera partida (colores al azar; luego se van
   cambiando, ver ajz_torPartidaCruce). */
function ajz_torNuevoCruce(PDO $pdo, array $t, int $ronda, int $slot, int $a, int $b): void {
    if (random_int(0, 1)) [$a, $b] = [$b, $a];
    $pdo->prepare("INSERT INTO ajz_cruces (torneo_id, ronda, slot, a_id, b_id) VALUES (?,?,?,?,?)")->execute([$t['id'], $ronda, $slot, $a, $b]);
    ajz_torPartidaCruce($pdo, $t, (int)$pdo->lastInsertId());
}
function ajz_torPartidaCruce(PDO $pdo, array $t, int $cruceId): void {
    $st = $pdo->prepare("SELECT * FROM ajz_cruces WHERE id = ?");
    $st->execute([$cruceId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    $par = (int)$c['juegos'] % 2 === 0;
    $pid = ajz_crearPartida($pdo, $par ? (int)$c['a_id'] : (int)$c['b_id'], $par ? (int)$c['b_id'] : (int)$c['a_id'],
                            $t['base_ms'], $t['inc_ms'], 0, $t['id']);
    $pdo->prepare("UPDATE ajz_cruces SET partida_id = ?, juegos = juegos + 1 WHERE id = ?")->execute([$pid, $cruceId]);
}

/* A su hora: si está lleno empieza (las entradas pasan al anfitrión y se
   sortea el cuadro); si no, se cancela. */
function ajz_torArrancar(PDO $pdo, int $id): void {
    $cambio = ajz_tx($pdo, function () use ($pdo, $id) {
        $t = ajz_torCargar($pdo, $id, true);
        if ($t['estado'] !== 'inscripcion' || bm_ms() < $t['empieza']) return false;
        $ins = ajz_torInscritos($pdo, $id);
        if (count($ins) < $t['plazas']) { ajz_torCancelarDentro($pdo, $t, 'vacio'); return true; }
        ajz_pagar($pdo, $t['host_id'], array_sum(array_map('intval', array_column($ins, 'pagado'))));
        $pdo->prepare("UPDATE ajz_inscritos SET pagado = 0 WHERE torneo_id = ?")->execute([$id]);
        $jug = array_map('intval', array_column($ins, 'user_id'));
        shuffle($jug);
        for ($k = 0; $k < count($jug); $k += 2) ajz_torNuevoCruce($pdo, $t, 1, intdiv($k, 2), $jug[$k], $jug[$k + 1]);
        $pdo->prepare("UPDATE ajz_torneos SET estado = 'jugando', ronda = 1 WHERE id = ?")->execute([$id]);
        return true;
    });
    if ($cambio) ajz_torDiscordLuego($pdo, $id);
}

function ajz_torTick(PDO $pdo): void {
    $st = $pdo->prepare("SELECT id FROM ajz_torneos WHERE estado = 'inscripcion' AND empieza <= ?");
    $st->execute([bm_ms()]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { ajz_torArrancar($pdo, (int)$id); }
        catch (Throwable $e) { error_log('[ajedrez] torneo ' . $id . ': ' . $e->getMessage()); }
    }
}

/* De dos jugadores, el que se apuntó antes (si no se presenta ninguno). */
function ajz_torPrimero(PDO $pdo, int $torneo, int $a, int $b): int {
    $st = $pdo->prepare("SELECT user_id FROM ajz_inscritos WHERE torneo_id = ? AND user_id IN (?, ?) ORDER BY orden, user_id LIMIT 1");
    $st->execute([$torneo, $a, $b]);
    return (int)($st->fetchColumn() ?: $a);
}

/* Ha acabado una partida del torneo (dentro de la transacción de la
   partida, ajz_conPartida). */
function ajz_torPartidaAcabada(PDO $pdo, array $p): void {
    $t = ajz_torCargar($pdo, (int)$p['torneo_id'], true);
    $st = $pdo->prepare("SELECT * FROM ajz_cruces WHERE partida_id = ? FOR UPDATE");
    $st->execute([$p['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['ganador_id'] !== null) return;
    if ($p['resultado'] === '1/2') { ajz_torPartidaCruce($pdo, $t, (int)$c['id']); return; }
    $gana = $p['resultado'] === '1-0' ? $p['blanco_id'] : $p['negro_id'];
    $pdo->prepare("UPDATE ajz_cruces SET ganador_id = ? WHERE id = ?")->execute([$gana, $c['id']]);
    ajz_torAvanzar($pdo, $t);
}

function ajz_torAvanzar(PDO $pdo, array $t): void {
    if ($t['estado'] !== 'jugando') return;
    $total = ajz_torRondas($t['plazas']);
    $st = $pdo->prepare("SELECT * FROM ajz_cruces WHERE torneo_id = ? AND ronda = ? ORDER BY slot");
    $st->execute([$t['id'], $t['ronda']]);
    $cruces = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cruces as $c) if ($c['ganador_id'] === null) return;
    $gan = []; $per = [];
    foreach ($cruces as $c) {
        $g = (int)$c['ganador_id'];
        $gan[] = $g;
        $per[] = $g === (int)$c['a_id'] ? (int)$c['b_id'] : (int)$c['a_id'];
    }
    if ($t['ronda'] === $total) {
        /* Final (slot 0) y 3.er puesto (slot 1): podio y premios. */
        [$p1, $p2, $p3] = [$gan[0], $per[0], $gan[1] ?? null];
        ajz_pagar($pdo, $p1, $t['premio1']);
        ajz_pagar($pdo, $p2, $t['premio2']);
        if ($p3) ajz_pagar($pdo, $p3, $t['premio3']);
        $pdo->prepare("UPDATE ajz_torneos SET estado = 'terminado', primero = ?, segundo = ?, tercero = ? WHERE id = ?")
            ->execute([$p1, $p2, $p3, $t['id']]);
        ajz_torDiscordLuego($pdo, $t['id']);
        return;
    }
    $sig = $t['ronda'] + 1;
    if ($sig === $total) {
        ajz_torNuevoCruce($pdo, $t, $sig, 0, $gan[0], $gan[1]);
        ajz_torNuevoCruce($pdo, $t, $sig, 1, $per[0], $per[1]);
    } else {
        for ($k = 0; $k < count($gan); $k += 2) ajz_torNuevoCruce($pdo, $t, $sig, intdiv($k, 2), $gan[$k], $gan[$k + 1]);
    }
    $pdo->prepare("UPDATE ajz_torneos SET ronda = ? WHERE id = ?")->execute([$sig, $t['id']]);
    ajz_torDiscordLuego($pdo, $t['id']);
}

/* Para la ventana de la partida: torneo y ronda. */
function ajz_torInfoPartida(PDO $pdo, array $p): array {
    $st = $pdo->prepare("SELECT c.ronda, c.slot, t.nombre, t.plazas FROM ajz_cruces c JOIN ajz_torneos t ON t.id = c.torneo_id WHERE c.partida_id = ?");
    $st->execute([$p['id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['id' => $p['torneo_id'], 'nombre' => '', 'ronda' => ''];
    return ['id' => $p['torneo_id'], 'nombre' => $r['nombre'],
            'ronda' => ajz_torNombreRonda((int)$r['ronda'], ajz_torRondas((int)$r['plazas']), (int)$r['slot'])];
}

function ajz_torResumen(array $t, int $apuntados, array $us): array {
    return [
        'id'        => $t['id'],
        'nombre'    => $t['nombre'],
        'host'      => $us[$t['host_id']] ?? null,
        'plazas'    => $t['plazas'],
        'apuntados' => $apuntados,
        'entrada'   => $t['entrada'],
        'premios'   => [$t['premio1'], $t['premio2'], $t['premio3']],
        'ritmo'     => ajz_ritmoTexto($t['base_ms'], $t['inc_ms']),
        'empieza'   => $t['empieza'],
        'estado'    => $t['estado'],
        'motivo'    => $t['motivo'],
    ];
}

/* Lista del menú: los abiertos y en juego, y los terminados de los
   últimos 3 días. Los cancelados no salen (sí por su enlace). */
function ajz_torLista(PDO $pdo, int $uid): array {
    $st = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM ajz_inscritos i WHERE i.torneo_id = t.id) AS apuntados,
                                (SELECT COUNT(*) FROM ajz_inscritos i WHERE i.torneo_id = t.id AND i.user_id = ?) AS yo
                           FROM ajz_torneos t
                          WHERE t.estado IN ('inscripcion','jugando') OR (t.estado = 'terminado' AND t.empieza > ?)
                          ORDER BY FIELD(t.estado, 'jugando', 'inscripcion', 'terminado'), t.empieza");
    $st->execute([$uid, bm_ms() - 3 * 86400000]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $us = bm_usuarios($pdo, array_column($rows, 'host_id'));
    $out = [];
    foreach ($rows as $r) {
        $apuntados = (int)$r['apuntados']; $yo = (int)$r['yo'] > 0;
        foreach (['id', 'host_id', 'plazas', 'entrada', 'premio1', 'premio2', 'premio3', 'base_ms', 'inc_ms', 'empieza', 'ronda'] as $k) $r[$k] = (int)$r[$k];
        $out[] = ajz_torResumen($r, $apuntados, $us) + ['apuntado' => $yo];
    }
    return $out;
}

/* Ventana de un torneo: datos, apuntados, cuadro y mi partida. */
function ajz_torVer(PDO $pdo, int $uid, int $id): array {
    ajz_tick($pdo);
    $t = ajz_torCargar($pdo, $id);
    $ins = ajz_torInscritos($pdo, $id);
    $st = $pdo->prepare("SELECT c.*, p.estado AS p_estado, p.resultado AS p_resultado, p.blanco_id AS p_blanco
                           FROM ajz_cruces c LEFT JOIN ajz_partidas p ON p.id = c.partida_id
                          WHERE c.torneo_id = ? ORDER BY c.ronda, c.slot");
    $st->execute([$id]);
    $cruces = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_merge([$t['host_id'], $uid], array_column($ins, 'user_id'));
    $us = bm_usuarios($pdo, $ids);
    $total = ajz_torRondas($t['plazas']);
    $cuadro = []; $miPartida = null;
    foreach ($cruces as $c) {
        $a = (int)$c['a_id']; $b = (int)$c['b_id'];
        $activa = in_array($c['p_estado'], ['esperando', 'jugando'], true);
        if ($activa && ($a === $uid || $b === $uid)) $miPartida = (int)$c['partida_id'];
        $cuadro[] = [
            'ronda'   => (int)$c['ronda'],
            'slot'    => (int)$c['slot'],
            'nombre'  => ajz_torNombreRonda((int)$c['ronda'], $total, (int)$c['slot']),
            'a'       => $us[$a] ?? null,
            'b'       => $us[$b] ?? null,
            'ganador' => $c['ganador_id'] === null ? null : ((int)$c['ganador_id'] === $a ? 'a' : 'b'),
            'juegos'  => (int)$c['juegos'],
            'estado'  => $c['ganador_id'] !== null ? 'hecho' : ($c['p_estado'] ?? 'esperando'),
        ];
    }
    $apuntados = array_map(fn($i) => $us[(int)$i['user_id']] ?? null, $ins);
    $podio = null;
    if ($t['estado'] === 'terminado') {
        $pod = bm_usuarios($pdo, array_filter([(int)$t['primero'], (int)$t['segundo'], (int)$t['tercero']]));
        $podio = [$pod[(int)$t['primero']] ?? null, $pod[(int)$t['segundo']] ?? null, $pod[(int)$t['tercero']] ?? null];
    }
    return ajz_torResumen($t, count($ins), $us) + [
        'rondas'    => $total,
        'ronda'     => $t['ronda'],
        'rondaNombre' => $t['ronda'] ? ajz_torNombreRonda($t['ronda'], $total) : '',
        'lista'     => array_values(array_filter($apuntados)),
        'apuntado'  => in_array($uid, array_map('intval', array_column($ins, 'user_id')), true),
        'soyHost'   => $t['host_id'] === $uid,
        'cuadro'    => $cuadro,
        'miPartida' => $miPartida,
        'podio'     => $podio,
        'ahora'     => bm_ms(),
        'yoKey'     => $us[$uid]['key'] ?? null,
    ];
}

/* ── Discord: mismo canal que los eventos del calendario ── */

function ajz_torWeb(): string {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return in_array($h, ['melonhub.es', 'melonhubdev.melonhub.es'], true) ? 'https://' . $h : 'https://melonhub.es';
}

/* Una vez por torneo y petición, cuando la respuesta ya ha salido (y la
   transacción está confirmada). */
function ajz_torDiscordLuego(PDO $pdo, int $id): void {
    static $pend = [];
    if (isset($pend[$id])) return;
    $pend[$id] = true;
    if (trim((string)env('DISCORD_BOT_TOKEN', '')) === '') return;
    wordelon_despues(function () use ($pdo, $id) {
        try { ajz_torDiscord($pdo, $id); }
        catch (Throwable $e) { error_log('[ajedrez] discord torneo ' . $id . ': ' . $e->getMessage()); }
    });
}

function ajz_torEmbed(PDO $pdo, array $t): array {
    $ins = ajz_torInscritos($pdo, $t['id']);
    $us = bm_usuarios($pdo, [$t['host_id']]);
    $host = $us[$t['host_id']] ?? ['nombre' => 'Alguien', 'foto' => null];
    $web = ajz_torWeb();
    $url = $web . '/index.php?abrir=torneo:' . $t['id'];
    $ts = intdiv($t['empieza'], 1000);
    $total = ajz_torRondas($t['plazas']);
    $pt = function (int $n): string { return $n . ' ' . ($n === 1 ? 'punto' : 'puntos'); };
    switch ($t['estado']) {
        case 'inscripcion': $desc = '**[→ Apuntarse en Melon Hub](' . $url . ')**'; break;
        case 'jugando':     $desc = 'En juego · ' . ajz_torNombreRonda($t['ronda'], $total) . "\n**[→ Ver el cuadro en Melon Hub](" . $url . ')**'; break;
        case 'terminado':   $desc = 'Terminado' . "\n**[→ Ver el cuadro en Melon Hub](" . $url . ')**'; break;
        default:            $desc = '❌ **Torneo cancelado** ' . ($t['motivo'] === 'vacio' ? 'porque no se llenó a tiempo.' : 'por quien lo organizaba.')
                                  . "\nSe han devuelto las entradas y los premios.";
    }
    $fields = [
        ['name' => 'Fecha y hora', 'value' => '<t:' . $ts . ':F>' . "\n" . '<t:' . $ts . ':R>', 'inline' => true],
        ['name' => 'Ritmo',        'value' => ajz_ritmoTexto($t['base_ms'], $t['inc_ms']) . ' (min + s)', 'inline' => true],
        ['name' => 'Jugadores',    'value' => count($ins) . ' / ' . $t['plazas'], 'inline' => true],
        ['name' => 'Entrada',      'value' => $t['entrada'] > 0 ? $pt($t['entrada']) : 'Gratis', 'inline' => true],
        ['name' => 'Premios',      'value' => '1.º ' . $pt($t['premio1']) . "\n2.º " . $pt($t['premio2']) . "\n3.º " . $pt($t['premio3']), 'inline' => true],
    ];
    if ($t['estado'] === 'terminado') {
        $pod = bm_usuarios($pdo, array_filter([(int)$t['primero'], (int)$t['segundo'], (int)$t['tercero']]));
        $nom = fn($i) => isset($pod[(int)$i]) ? $pod[(int)$i]['nombre'] : '—';
        $fields[] = ['name' => 'Podio', 'value' => '🥇 ' . $nom($t['primero']) . "\n🥈 " . $nom($t['segundo']) . "\n🥉 " . $nom($t['tercero']), 'inline' => true];
    }
    $author = ['name' => $host['nombre']];
    if (!empty($host['foto'])) $author['icon_url'] = $web . '/' . ltrim($host['foto'], '/');
    /* Cancelado: se nota de un vistazo (título, gris, sin enlace de apuntarse). */
    $cancelado = $t['estado'] === 'cancelado';
    return [
        'title'       => mb_substr(($cancelado ? '[CANCELADO] ' : '') . 'Torneo de ajedrez: ' . $t['nombre'], 0, 240),
        'url'         => $url,
        'description' => $desc,
        'color'       => $cancelado ? 0x808080 : 0xFF66B2,   /* rosa = el mismo que los eventos */
        'author'      => $author,
        'fields'      => $fields,
        'footer'      => ['text' => 'Torneo creado por ' . $host['nombre'] . ' en Melon Hub'],
        'timestamp'   => gmdate('c', $ts),
    ];
}

/* Publica el anuncio la primera vez y luego lo edita. Si alguien lo
   borró en Discord (PATCH → 404), se queda sin mensaje. */
function ajz_torDiscord(PDO $pdo, int $id): array {
    $t = ajz_torCargar($pdo, $id);
    $payload = ['embeds' => [ajz_torEmbed($pdo, $t)], 'allowed_mentions' => ['parse' => []]];
    $msg = (string)($t['discord_msg'] ?? '');
    if ($msg !== '') {
        [$code] = wordelon_discord('PATCH', '/channels/' . AJZ_TOR_CANAL . '/messages/' . rawurlencode($msg), $payload);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code];
    }
    if ($t['estado'] === 'cancelado') return ['ok' => true];
    [$code, $j] = wordelon_discord('POST', '/channels/' . AJZ_TOR_CANAL . '/messages', $payload);
    if ($code >= 200 && $code < 300 && isset($j['id'])) {
        $pdo->prepare("UPDATE ajz_torneos SET discord_msg = ? WHERE id = ? AND discord_msg IS NULL")->execute([(string)$j['id'], $id]);
    }
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code];
}
