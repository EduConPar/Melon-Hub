<?php
/* ════════════════════════════════════════════════════════════════
   LISTEN-TOGETHER API — sesiones de escucha conjunta.

   Modelo:
   - HOST: usuario que tiene el reproductor con la cola "real". Su
     player postea el estado actual (videoId, currentTime, isPlaying)
     a `update-state`.
   - GUEST: usuario invitado. Su player polls cada 2s y aplica el
     estado del host (loadVideoById si cambia, seekTo si drift > 3s,
     play/pause para coincidir).

   Endpoints (todos requieren sesión PHP, $_SESSION['user']):
     create        POST   crea/reutiliza la sesión actual del usuario
                          como host; cierra otras sesiones abiertas
                          donde participaba.
     update-state  POST   host postea {videoId, title, artist,
                          currentTimeS, isPlaying, durationS}.
     poll          GET    guest lee estado actual + actualiza last_seen.
                          host también lo usa para ver participantes.
     invite        POST   {toUser} → crea invite pendiente +
                          notificación visible para el destinatario.
     invites       GET    lista invites pendientes para el usuario.
     accept        POST   {inviteId} → guest se une como participante.
     decline       POST   {inviteId} → cierra el invite.
     leave         POST   guest sale O host cierra la sesión entera.
     current       GET    devuelve la sesión activa del usuario (como
                          host o guest) o null.
     request-join  POST   {host} → "Escuchar conmigo" (botón de la presencia
                          de Discord de la app de escritorio): pide unirse a
                          lo que escucha otro usuario (amigo o no).
     join-requests GET    peticiones pendientes para mí como anfitrión: mi
                          app las atiende sola (abre la sesión e invita, y
                          la app de quien lo pidió acepta sola).
     answer-join   POST   {requestId} → petición atendida.

   No es WebSocket — usa polling cada 2s. Tradeoff: ~2s de lag, pero
   simple, sin infra extra.
═════════════════════════════════════════════════════════════════════ */
/* Los sondeos (invitaciones cada 5 s, invitado cada 2 s): si la BD está
   saturada, un solo reintento corto (melon_pdo en assets/config.php) en
   vez de ocupar un proceso PHP esperando; ya volverá a preguntar. */
if (in_array($_GET['action'] ?? '', ['invites', 'poll'], true)) define('MELON_DB_RAPIDO', true);
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
require_once dirname(__DIR__) . '/php/timbre.php';   /* rt_usuario: avisos al escritorio */

session_start();
$userKey = $_SESSION['user'] ?? '';
if (!$userKey) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No session']);
    exit;
}
$userId = userIdByKey($userKey);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unknown user']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ── Auto-migración de tablas ──────────────────────────────────── */
/* Una vez por versión de este archivo (melon_esquema, assets/php/
   esquema.php), no en cada petición: el escritorio llama a esta API cada
   5 s (invitaciones) o 2 s (escuchando de invitado). */
require_once dirname(__DIR__) . '/php/esquema.php';
melon_esquema($pdo, 'escuchar-juntos', __FILE__, function (PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS listening_sessions (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            host_user_id    INT NOT NULL,
            video_id        VARCHAR(20)  NULL,
            track_title     VARCHAR(255) NULL,
            track_artist    VARCHAR(255) NULL,
            cover_url       VARCHAR(500) NULL,
            current_time_s  INT  NOT NULL DEFAULT 0,
            duration_s      INT  NOT NULL DEFAULT 0,
            is_playing      TINYINT NOT NULL DEFAULT 0,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at       TIMESTAMP NULL,
            INDEX idx_host (host_user_id, closed_at),
            INDEX idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS listening_participants (
            session_id    INT NOT NULL,
            user_id       INT NOT NULL,
            joined_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            left_at       TIMESTAMP NULL,
            PRIMARY KEY (session_id, user_id),
            INDEX idx_user (user_id, left_at),
            INDEX idx_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS listening_invites (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            session_id    INT NOT NULL,
            from_user_id  INT NOT NULL,
            to_user_id    INT NOT NULL,
            status        ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at  TIMESTAMP NULL,
            INDEX idx_to (to_user_id, status),
            INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS listening_join_requests (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            host_user_id  INT NOT NULL,
            from_user_id  INT NOT NULL,
            status        ENUM('pending','done','expired') DEFAULT 'pending',
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_host (host_user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
});

/* ── Helpers ────────────────────────────────────────────────────── */
function getActiveSessionAsHost(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare("
        SELECT * FROM listening_sessions
        WHERE host_user_id = ? AND closed_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function getActiveSessionAsGuest(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare("
        SELECT s.*
        FROM listening_sessions s
        JOIN listening_participants p ON p.session_id = s.id
        WHERE p.user_id = ?
          AND p.left_at IS NULL
          AND s.closed_at IS NULL
        ORDER BY s.id DESC LIMIT 1
    ");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function countActiveParticipants(PDO $pdo, int $sessionId): int {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM listening_participants
        WHERE session_id = ? AND left_at IS NULL
          AND last_seen_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ");
    $st->execute([$sessionId]);
    return (int)$st->fetchColumn();
}
function getHostLabel(PDO $pdo, int $hostId): string {
    $st = $pdo->prepare("SELECT label FROM usuarios WHERE id = ?");
    $st->execute([$hostId]);
    return (string)($st->fetchColumn() ?: '?');
}
function bodyJson(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ── ACTIONS ───────────────────────────────────────────────────── */

if ($action === 'current') {
    /* Devuelve la sesión activa del usuario (como host o guest). */
    $host = getActiveSessionAsHost($pdo, $userId);
    if ($host) {
        echo json_encode([
            'ok' => true,
            'role' => 'host',
            'session' => $host,
            'participants' => countActiveParticipants($pdo, (int)$host['id']),
        ]);
        exit;
    }
    $guest = getActiveSessionAsGuest($pdo, $userId);
    if ($guest) {
        echo json_encode([
            'ok' => true,
            'role' => 'guest',
            'session' => $guest,
            'host_label' => getHostLabel($pdo, (int)$guest['host_user_id']),
            'participants' => countActiveParticipants($pdo, (int)$guest['id']),
        ]);
        exit;
    }
    echo json_encode(['ok' => true, 'role' => null, 'session' => null]);
    exit;
}

if ($action === 'create') {
    /* Si ya tengo sesión host abierta, la reutilizamos en vez de
       cerrar/crear de nuevo. Esto evita el bug donde clicks repetidos
       en "Invitar" cerraban la sesión justo recién creada y los
       invites quedaban huérfanos (sesión cerrada → la query de
       invites los filtraba). */
    $existing = getActiveSessionAsHost($pdo, $userId);
    if ($existing) {
        /* Aseguramos que el host también esté como participante (para
           que el wrapped cuente sus minutos junto a los invitados). Si
           había salido, reabrir su fila. */
        $pdo->prepare("
            INSERT INTO listening_participants (session_id, user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                joined_at = NOW(),
                last_seen_at = NOW(),
                left_at = NULL
        ")->execute([(int)$existing['id'], $userId]);
        echo json_encode(['ok' => true, 'session_id' => (int)$existing['id'], 'reused' => true]);
        exit;
    }
    /* Salimos de cualquier guest-spot. */
    $pdo->prepare("UPDATE listening_participants SET left_at = NOW() WHERE user_id = ? AND left_at IS NULL")
        ->execute([$userId]);
    /* Creamos nueva sesión host. */
    $pdo->prepare("INSERT INTO listening_sessions (host_user_id) VALUES (?)")
        ->execute([$userId]);
    $sid = (int)$pdo->lastInsertId();
    /* El host también se registra como participante para que sus
       minutos cuenten en el wrapped junto a cada invitado. */
    $pdo->prepare("INSERT INTO listening_participants (session_id, user_id) VALUES (?, ?)")
        ->execute([$sid, $userId]);
    echo json_encode(['ok' => true, 'session_id' => $sid]);
    exit;
}

if ($action === 'update-state') {
    $j = bodyJson();
    $host = getActiveSessionAsHost($pdo, $userId);
    if (!$host) {
        echo json_encode(['ok' => false, 'error' => 'No active host session']);
        exit;
    }
    $pdo->prepare("
        UPDATE listening_sessions
           SET video_id = ?, track_title = ?, track_artist = ?, cover_url = ?,
               current_time_s = ?, duration_s = ?, is_playing = ?
         WHERE id = ?
    ")->execute([
        (string)($j['videoId']     ?? ''),
        (string)($j['title']       ?? ''),
        (string)($j['artist']      ?? ''),
        (string)($j['coverUrl']    ?? ''),
        (int)   ($j['currentTimeS'] ?? 0),
        (int)   ($j['durationS']    ?? 0),
        !empty($j['isPlaying']) ? 1 : 0,
        (int)$host['id'],
    ]);
    /* Lo que suena, en el archivo estático de la sesión (rt_urlSesion):
       los invitados lo leen cada 2 s sin PHP ni BD y solo llaman a `poll`
       cada 10 s (antes, cada 2 s). Con la hora de la BD, la misma que
       devuelve `poll` (server_time), para que calculen bien la posición. */
    $stS = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(updated_at) AS updated_at_epoch FROM listening_sessions WHERE id = ?");
    $stS->execute([(int)$host['id']]);
    if ($filaS = $stS->fetch(PDO::FETCH_ASSOC)) rt_escribir(rt_urlSesion((int)$host['id']), ['session' => $filaS]);
    /* Refresca last_seen_at del host (igual que hace el poll del guest).
       Necesario para que la query de buddies del wrapped use un cierre
       preciso cuando la sesión se abandona sin "leave" explícito. Si
       la fila no existe (sesiones antiguas creadas antes de la mejora),
       la creamos. */
    $pdo->prepare("
        INSERT INTO listening_participants (session_id, user_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_seen_at = NOW(), left_at = NULL
    ")->execute([(int)$host['id'], $userId]);
    /* Devolvemos lista de participantes activos para que el host detecte
       cuando alguien nuevo se une y muestre notificación. EXCLUIMOS al
       propio host — desde que se inserta en listening_participants para
       que sus minutos cuenten en el wrapped, también aparecía aquí y
       el frontend mostraba "X (uno mismo) se unió". */
    $stp = $pdo->prepare("
        SELECT u.label, u.user_key
          FROM listening_participants p
          JOIN usuarios u ON u.id = p.user_id
         WHERE p.session_id = ?
           AND p.user_id <> ?
           AND p.left_at IS NULL
           AND p.last_seen_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
         ORDER BY p.joined_at DESC
    ");
    $stp->execute([(int)$host['id'], (int)$userId]);
    $list = $stp->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'                => true,
        'participants'      => count($list),
        'participants_list' => $list,
    ]);
    exit;
}

if ($action === 'poll') {
    /* Guest poll: lee la sesión del host del que es participante. */
    $guest = getActiveSessionAsGuest($pdo, $userId);
    if (!$guest) {
        echo json_encode(['ok' => true, 'session' => null]);
        exit;
    }
    /* Refresh last_seen_at del guest. */
    $pdo->prepare("UPDATE listening_participants SET last_seen_at = NOW() WHERE session_id = ? AND user_id = ?")
        ->execute([(int)$guest['id'], $userId]);
    /* Re-fetch para asegurar datos al día. Incluimos updated_at y "ahora"
       como epoch calculados POR LA BD (mismo reloj/zona horaria) para que
       el guest pueda estimar la posición real del host = current_time_s +
       (server_time - updated_at_epoch). Sin esto se sincroniza contra un
       valor obsoleto y aparecían micro-saltos. */
    $st = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(updated_at) AS updated_at_epoch, UNIX_TIMESTAMP() AS server_now
                           FROM listening_sessions WHERE id = ? AND closed_at IS NULL");
    $st->execute([(int)$guest['id']]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        /* La sesión se cerró por el host. */
        echo json_encode(['ok' => true, 'session' => null, 'closed' => true]);
        exit;
    }
    echo json_encode([
        'ok'           => true,
        'session'      => $cur,
        'host_label'   => getHostLabel($pdo, (int)$cur['host_user_id']),
        'participants' => countActiveParticipants($pdo, (int)$cur['id']),
        'server_time'  => (int)$cur['server_now'],
        'rt'           => rt_urlSesion((int)$cur['id']),
    ]);
    exit;
}

if ($action === 'invite') {
    $j = bodyJson();
    $toKey = trim((string)($j['toUser'] ?? $_POST['toUser'] ?? ''));
    if (!$toKey) {
        echo json_encode(['ok' => false, 'error' => 'Falta toUser']);
        exit;
    }
    $toId = userIdByKey($toKey);
    if (!$toId || $toId === $userId) {
        echo json_encode(['ok' => false, 'error' => 'Usuario inválido']);
        exit;
    }
    /* Defense in depth: rechazar si no es seguimiento mutuo. Excepción:
       quien entró por el enlace de "Escuchar conmigo" (request-join de
       los últimos 5 min): la app del anfitrión le invita sola aunque no
       sean amigos. */
    require_once dirname(__DIR__) . '/social-helpers.php';
    if (!isMutualFollow($pdo, (int)$userId, (int)$toId)) {
        $st = $pdo->prepare("SELECT 1 FROM listening_join_requests
                              WHERE host_user_id = ? AND from_user_id = ?
                                AND status IN ('pending', 'done')
                                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1");
        $st->execute([$userId, $toId]);
        if (!$st->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Solo puedes invitar a usuarios con seguimiento mutuo']);
            exit;
        }
    }
    /* Asegura que tengamos sesión host abierta. */
    $host = getActiveSessionAsHost($pdo, $userId);
    if (!$host) {
        echo json_encode(['ok' => false, 'error' => 'No tienes sesión activa']);
        exit;
    }
    /* Cancelamos invites previos pendientes del mismo host al mismo dest. */
    $pdo->prepare("
        UPDATE listening_invites
           SET status = 'expired', responded_at = NOW()
         WHERE from_user_id = ? AND to_user_id = ? AND status = 'pending'
    ")->execute([$userId, $toId]);
    $pdo->prepare("
        INSERT INTO listening_invites (session_id, from_user_id, to_user_id)
        VALUES (?, ?, ?)
    ")->execute([(int)$host['id'], $userId, $toId]);
    $newInviteId = (int)$pdo->lastInsertId();
    /* Timbre 'lt' (assets/php/timbre.php): su escritorio pide las
       invitaciones al verlo (antes, cada 5 s). */
    rt_usuario((int)$toId, 'lt');

    /* Push notification al destinatario. */
    require_once dirname(__DIR__) . '/push/send-push.php';
    $fromLabel = '';
    try {
        $st = $pdo->prepare("SELECT label FROM usuarios WHERE id = ?");
        $st->execute([(int)$userId]);
        $fromLabel = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    sendPushToUser($pdo, (int)$toId, buildInvitePushPayload(
        'listen',
        '🎧 ' . ($fromLabel ?: 'Alguien') . ' te invita',
        'Escuchar juntos en directo',
    ));

    echo json_encode(['ok' => true, 'invite_id' => $newInviteId]);
    exit;
}

if ($action === 'invites') {
    /* Lista invites pendientes para el usuario actual.
       Ventana ampliada a 15 min y SIN filtro de session.closed_at —
       si la sesión se cerró pero el invite es reciente, lo mostramos
       igual; el accept reabrirá la sesión si hace falta. */
    $st = $pdo->prepare("
        SELECT i.id, i.session_id, i.from_user_id, u.user_key AS from_user_key, u.label AS from_label,
               s.track_title, s.track_artist, s.video_id, s.cover_url
          FROM listening_invites i
          JOIN usuarios u ON u.id = i.from_user_id
          JOIN listening_sessions s ON s.id = i.session_id
         WHERE i.to_user_id = ?
           AND i.status = 'pending'
           AND i.created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY i.id DESC
    ");
    $st->execute([$userId]);
    echo json_encode(['ok' => true, 'invites' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'accept') {
    $j = bodyJson();
    $inviteId = (int)($j['inviteId'] ?? $_POST['inviteId'] ?? 0);
    if (!$inviteId) {
        echo json_encode(['ok' => false, 'error' => 'Falta inviteId']);
        exit;
    }
    $st = $pdo->prepare("
        SELECT * FROM listening_invites WHERE id = ? AND to_user_id = ? AND status = 'pending'
    ");
    $st->execute([$inviteId, $userId]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        echo json_encode(['ok' => false, 'error' => 'Invite inválido o expirado']);
        exit;
    }
    /* Sale de cualquier sesión previa como guest. */
    $pdo->prepare("UPDATE listening_participants SET left_at = NOW() WHERE user_id = ? AND left_at IS NULL")
        ->execute([$userId]);
    /* Cerra cualquier sesión propia como host (no puedes ser host y guest a la vez). */
    $pdo->prepare("UPDATE listening_sessions SET closed_at = NOW() WHERE host_user_id = ? AND closed_at IS NULL")
        ->execute([$userId]);
    /* REABRIR la sesión si estaba cerrada — el invite es reciente,
       así que asumimos que el host quiere reanudar al aceptar. */
    $pdo->prepare("UPDATE listening_sessions SET closed_at = NULL WHERE id = ?")
        ->execute([(int)$inv['session_id']]);
    /* Inserta como participante (o reactivar). */
    $pdo->prepare("
        INSERT INTO listening_participants (session_id, user_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW(), last_seen_at = NOW()
    ")->execute([(int)$inv['session_id'], $userId]);
    /* Marca invite como aceptado. */
    $pdo->prepare("UPDATE listening_invites SET status = 'accepted', responded_at = NOW() WHERE id = ?")
        ->execute([$inviteId]);
    $hostLabel = getHostLabel($pdo, (int)$inv['from_user_id']);
    echo json_encode([
        'ok' => true,
        'session_id' => (int)$inv['session_id'],
        'host_label' => $hostLabel,
    ]);
    exit;
}

if ($action === 'decline') {
    $j = bodyJson();
    $inviteId = (int)($j['inviteId'] ?? $_POST['inviteId'] ?? 0);
    $pdo->prepare("
        UPDATE listening_invites SET status = 'declined', responded_at = NOW()
         WHERE id = ? AND to_user_id = ? AND status = 'pending'
    ")->execute([$inviteId, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'leave') {
    /* Si es host: cierra la sesión entera. */
    $host = getActiveSessionAsHost($pdo, $userId);
    if ($host) {
        $pdo->prepare("UPDATE listening_sessions SET closed_at = NOW() WHERE id = ?")->execute([(int)$host['id']]);
        /* Los invitados lo ven en el archivo de la sesión. */
        rt_escribir(rt_urlSesion((int)$host['id']), ['closed' => true]);
        $pdo->prepare("UPDATE listening_participants SET left_at = NOW() WHERE session_id = ? AND left_at IS NULL")
            ->execute([(int)$host['id']]);
        echo json_encode(['ok' => true, 'role' => 'host', 'closed' => true]);
        exit;
    }
    /* Si es guest: solo sale. */
    $pdo->prepare("UPDATE listening_participants SET left_at = NOW() WHERE user_id = ? AND left_at IS NULL")
        ->execute([$userId]);
    echo json_encode(['ok' => true, 'role' => 'guest', 'closed' => false]);
    exit;
}

if ($action === 'request-join') {
    $j = bodyJson();
    $hostKey = trim((string)($j['host'] ?? ''));
    $hostId = $hostKey !== '' ? userIdByKey($hostKey) : 0;
    if (!$hostId || $hostId === $userId) {
        echo json_encode(['ok' => false, 'error' => 'Usuario inválido']);
        exit;
    }
    /* Con el enlace de "Escuchar conmigo" se une cualquiera con cuenta,
       sea o no amigo del anfitrión (antes solo seguimiento mutuo). */
    $pdo->prepare("UPDATE listening_join_requests SET status = 'expired'
                    WHERE host_user_id = ? AND from_user_id = ? AND status = 'pending'")
        ->execute([$hostId, $userId]);
    $pdo->prepare("INSERT INTO listening_join_requests (host_user_id, from_user_id) VALUES (?, ?)")
        ->execute([$hostId, $userId]);
    /* Timbre 'lt': la app del anfitrión atiende la petición al verlo
       (antes preguntaba cada 5 s). */
    rt_usuario((int)$hostId, 'lt');
    echo json_encode(['ok' => true, 'host_label' => getHostLabel($pdo, (int)$hostId)]);
    exit;
}

if ($action === 'join-requests') {
    $st = $pdo->prepare("
        SELECT r.id, u.user_key AS from_user_key, u.label AS from_label
          FROM listening_join_requests r
          JOIN usuarios u ON u.id = r.from_user_id
         WHERE r.host_user_id = ? AND r.status = 'pending'
           AND r.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY r.id
    ");
    $st->execute([$userId]);
    echo json_encode(['ok' => true, 'requests' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'answer-join') {
    $j = bodyJson();
    $pdo->prepare("UPDATE listening_join_requests SET status = 'done' WHERE id = ? AND host_user_id = ?")
        ->execute([(int)($j['requestId'] ?? 0), $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'users') {
    /* Lista usuarios a los que se puede invitar a escuchar juntos:
       solo aquellos con seguimiento mutuo respecto al usuario actual. */
    require_once dirname(__DIR__) . '/social-helpers.php';
    $mutualIds = mutualFollowerIds($pdo, (int)$userId);
    if (!$mutualIds) {
        echo json_encode(['ok' => true, 'users' => []]);
        exit;
    }
    $ph = implode(',', array_fill(0, count($mutualIds), '?'));
    $st = $pdo->prepare("
        SELECT u.user_key, u.label
          FROM usuarios u
         WHERE u.id IN ($ph)
         ORDER BY u.label
    ");
    $st->execute($mutualIds);
    echo json_encode(['ok' => true, 'users' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => "Action desconocida: {$action}"]);
