<?php
/* ──────────────────────────────────────────────────────────────────────
   API de la TV (ver assets/music/tv-lib.php)
   ──────────────────────────────────────────────────────────────────────
   Desde el móvil (JSON, con sesión):
     POST ?action=pair  { token } | { code }   empareja la TV que enseña
                                              ese QR (token) o ese código
     POST ?action=cmd   { c, … }               orden para la TV:
          load  { queue, idx, pos, play, shuffle, pl }
          play | pause | next | prev | stop
          seek  { pos }      shuffle { on }
   Desde la TV (GET en un <iframe>, contesta HTML con <pre id="d">):
     GET  ?action=estado&frame=1&d=<json>      cómo va: { on, track, idx,
          pl, playing, pos, dur, shuffle, n, ev }. ev = ha cambiado algo (no es
          el latido): también se apunta como "escuchando" del perfil.
   ────────────────────────────────────────────────────────────────────── */

$action = $_GET['action'] ?? '';
$frame  = isset($_GET['frame']);

/* La TV lee las respuestas con un iframe: siempre HTML (también el 401). */
function tv_responder(array $datos, int $status = 200): void {
    global $frame;
    if (!$frame) jsonResponse($datos, $status);
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><pre id="d">'
       . htmlspecialchars(json_encode($datos, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
       . '</pre></body></html>';
    exit;
}

require_once dirname(__DIR__) . '/auth.php';
if ($frame) {
    /* requireAuth contestaría JSON. Las TVs viejas reusan sockets que
       Apache ya ha cerrado: socket nuevo en cada petición. */
    header('Connection: close');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $k = $_SESSION['user'] ?? null;
    /* El hosting borra las sesiones mucho antes de 30 días: la TV entra
       con su sesión recordada (remember-login.php), como el escritorio. */
    if (!$k && !empty($_COOKIE['melon_remember'])) {
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__) . '/remember-login.php';
        $k = restoreRememberedLogin($pdo);
    }
    if (!$k || !isset($loginUsers[$k])) tv_responder(['ok' => false, 'error' => 'auth'], 401);
    $u = ['key' => $k, 'label' => $loginUsers[$k]['label']];
} else {
    $u = requireAuth();
}
/* La sesión ya no hace falta: que no bloquee otras peticiones. */
session_write_close();

require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/tv-lib.php';

$st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
$st->execute([$u['key']]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) tv_responder(['ok' => false, 'error' => 'Usuario no encontrado'], 500);

/* Textos de las canciones, recortados. */
function tv_cancion($t): ?array {
    if (!is_array($t)) return null;
    $vid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($t['videoId'] ?? ''));
    if (strlen($vid) !== 11) return null;
    return [
        'videoId'  => $vid,
        'title'    => mb_substr(trim((string)($t['title'] ?? '')), 0, 200),
        'artist'   => mb_substr(trim((string)($t['artist'] ?? '')), 0, 200),
        'duration' => max(0, (float)($t['duration'] ?? 0)),
    ];
}

switch ($action) {

case 'pair': {
    $b = jsonBody();
    tv_esquema($pdo);
    $token = preg_replace('/[^a-z0-9]/', '', (string)($b['token'] ?? ''));
    $code  = preg_replace('/[^0-9]/', '', (string)($b['code'] ?? ''));
    /* Un código de 6 cifras se adivina probando: como mucho 10 fallos
       cada 10 minutos por usuario. */
    $fallos = __DIR__ . '/../../uploads/esquema/.tv-fallos-' . $uid;
    $n = (int)@file_get_contents($fallos);
    if ($n >= 10 && @filemtime($fallos) > time() - 600) tv_responder(['ok' => false, 'error' => 'Demasiados intentos. Espera unos minutos.'], 429);
    if (strlen($token) === 24) {
        $q = $pdo->prepare("SELECT token FROM tv_pairs WHERE token = ? AND user_key IS NULL
                            AND created_at > NOW() - INTERVAL " . TV_PAR_MINUTOS . " MINUTE");
        $q->execute([$token]);
    } elseif (strlen($code) === 6) {
        $q = $pdo->prepare("SELECT token FROM tv_pairs WHERE code = ? AND user_key IS NULL
                            AND created_at > NOW() - INTERVAL " . TV_PAR_MINUTOS . " MINUTE");
        $q->execute([$code]);
    } else {
        tv_responder(['ok' => false, 'error' => 'El código tiene 6 cifras'], 400);
    }
    $token = (string)$q->fetchColumn();
    if ($token === '') {
        @file_put_contents($fallos, (string)($n >= 10 ? 1 : $n + 1));
        tv_responder(['ok' => false, 'error' => 'Código no válido o caducado'], 404);
    }
    $pdo->prepare("UPDATE tv_pairs SET user_key = ? WHERE token = ?")->execute([$u['key'], $token]);
    /* La TV lo ve en su archivo y entra (tv.php?emparejar=1). */
    tv_escribir(tv_urlPar($token), ['ok' => true]);
    tv_responder(['ok' => true]);
}

case 'cmd': {
    $b = jsonBody();
    $c = (string)($b['c'] ?? '');
    $orden = ['c' => $c];
    switch ($c) {
        case 'load':
            $cola = [];
            foreach (array_slice(is_array($b['queue'] ?? null) ? $b['queue'] : [], 0, 1000) as $t) {
                $t = tv_cancion($t);
                if ($t) $cola[] = $t;
            }
            if (!$cola) tv_responder(['ok' => false, 'error' => 'Cola vacía'], 400);
            $qv = rt_version();
            tv_escribir(tv_urlCola($uid), ['qv' => $qv, 'queue' => $cola]);
            $orden += [
                'qv'      => $qv,
                'idx'     => max(0, min(count($cola) - 1, (int)($b['idx'] ?? 0))),
                'pos'     => max(0, (float)($b['pos'] ?? 0)),
                'play'    => !empty($b['play']),
                'shuffle' => !empty($b['shuffle']),
                'pl'      => mb_substr(trim((string)($b['pl'] ?? '')), 0, 100),
            ];
            break;
        case 'seek':
            $orden['pos'] = max(0, (float)($b['pos'] ?? 0));
            break;
        case 'shuffle':
            $orden['on'] = !empty($b['on']);
            break;
        case 'play': case 'pause': case 'next': case 'prev': case 'stop':
            break;
        default:
            tv_responder(['ok' => false, 'error' => 'Orden desconocida'], 400);
    }
    /* Se apuntan las últimas 20 (la TV mira cada ~0,75 s: si llegan
       varias seguidas, las hace todas en orden). Con cerrojo para que dos
       órdenes a la vez no se pisen el número. */
    $dir = dirname(__DIR__, 2) . '/rt/tv';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $cerrojo = @fopen($dir . '/.cerrojo', 'c');
    if ($cerrojo) flock($cerrojo, LOCK_EX);
    try {
        $d = tv_leer(tv_urlOrdenes($uid));
        $cmds = is_array($d['cmds'] ?? null) ? $d['cmds'] : [];
        $ultima = $cmds ? (int)end($cmds)['n'] : 0;
        $orden = ['n' => $ultima + 1] + $orden;
        $cmds[] = $orden;
        tv_escribir(tv_urlOrdenes($uid), ['cmds' => array_slice($cmds, -20)]);
    } finally {
        if ($cerrojo) { flock($cerrojo, LOCK_UN); fclose($cerrojo); }
    }
    tv_responder(['ok' => true, 'n' => $orden['n']]);
}

case 'estado': {
    $d = json_decode((string)($_GET['d'] ?? ''), true);
    if (!is_array($d)) tv_responder(['ok' => false, 'error' => 'Datos inválidos'], 400);
    $on    = !empty($d['on']);
    $track = $on ? tv_cancion($d['track'] ?? null) : null;
    $estado = [
        'on'      => $on && $track ? 1 : 0,
        'track'   => $track,
        'idx'     => (int)($d['idx'] ?? -1),
        'pl'      => mb_substr(trim((string)($d['pl'] ?? '')), 0, 100),
        'playing' => $track && !empty($d['playing']),
        'pos'     => max(0, (float)($d['pos'] ?? 0)),
        'dur'     => max(0, (float)($d['dur'] ?? 0)),
        'shuffle' => !empty($d['shuffle']),
        'n'       => (int)($d['n'] ?? 0),
        't'       => tv_ahora(),
    ];
    $antes = tv_leer(tv_urlEstado($uid));
    tv_escribir(tv_urlEstado($uid), $estado);

    /* Lo que suena en la TV es lo que "escucha" el usuario (perfil,
       presencia): como save-now-playing de api.php, solo al cambiar algo.
       Esperando (sin canción) solo cuenta si acaba de dejar de sonar: si
       no, pisaría lo que suena en el móvil o el PC. */
    if (!empty($d['ev']) && ($track || !empty($antes['on']))) {
        try {
            melon_esquema($pdo, 'tv-sonando', __FILE__, function (PDO $pdo) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS now_playing (
                        user_id    INT PRIMARY KEY,
                        track_json TEXT NOT NULL,
                        is_playing TINYINT(1) DEFAULT 0,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) DEFAULT CHARSET=utf8mb4
                ");
            }, true);
            $np = $track ? $track + ['plName' => $estado['pl'], 'position' => $estado['pos']] : null;
            if ($np) {
                $pdo->prepare("INSERT INTO now_playing (user_id, track_json, is_playing) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE track_json = VALUES(track_json), is_playing = VALUES(is_playing)")
                    ->execute([$uid, json_encode($np, JSON_UNESCAPED_UNICODE), $estado['playing'] ? 1 : 0]);
            } else {
                $pdo->prepare("UPDATE now_playing SET is_playing = 0 WHERE user_id = ?")->execute([$uid]);
            }
            require_once dirname(__DIR__) . '/profile/presencia.php';
            pf_presenciaArchivo($pdo, true);
        } catch (Throwable $e) {
            error_log('[tv] escuchando: ' . $e->getMessage());
        }
    }
    tv_responder(['ok' => true]);
}

default:
    tv_responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
