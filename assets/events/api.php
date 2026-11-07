<?php
/* ──────────────────────────────────────────────────────────────────
   EVENTS API — router para eventos de calendario

   Modelo: un usuario crea un evento (titulo, descripcion, fecha,
   duración, min/max participantes, visibility public|private) y opcional
   invita amigos. Cualquiera puede unirse a públicos no finalizados; los
   privados solo los ve y se une quien tenga invite o ya esté dentro.
   Si el cupo está lleno, el join entra en waitlist y sube auto cuando
   alguien sale.

   Cuando un usuario se une a un evento, se inserta una fila en
   `recordatorios` con un titulo prefijado "Evento: …" para que
   aparezca en la grid del calendario como recordatorio. Al salir,
   se elimina esa fila.

   Acciones:
     GET   ?action=list-events
     GET   ?action=get-event&id=N
     GET   ?action=get-pending-invites
     POST  ?action=create-event  { title, description, eventDate (Y-m-d H:i:s),
                                    durationMin, minParticipants, maxParticipants,
                                    visibility ('public'|'private'),
                                    invitees: [userKey, …] }
     POST  ?action=join-event    { eventId }
     POST  ?action=leave-event   { eventId }
     POST  ?action=invite-to-event { eventId, userKey }
     POST  ?action=respond-event-invite { inviteId, action: 'accept'|'decline' }
     POST  ?action=mark-invites-notified { inviteIds: [N,…] }
     POST  ?action=delete-event  { eventId }
   ────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../social-helpers.php';
require_once dirname(__DIR__) . '/php/esquema.php';   /* melon_esquema: tablas una vez por versión */
require_once dirname(__DIR__) . '/php/timbre.php';    /* rt_usuario: avisos al escritorio */

$u       = requireAuth();
$userKey = $u['key'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* ── Helpers ─────────────────────────────────────────────────────── */
/* jsonResponse/jsonError/jsonBody ya están definidas en assets/auth.php
   (cargado arriba). No las redeclaramos aquí. La firma de jsonError de
   auth.php devuelve {error: msg}; nosotros también pasamos el message
   como primer arg → compat. */
function ev_uid(PDO $pdo, string $userKey): ?int {
    static $cache = [];
    if (array_key_exists($userKey, $cache)) return $cache[$userKey];
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $cache[$userKey] = $id ? (int)$id : null;
}
function ev_userKey(PDO $pdo, int $uid): ?string {
    static $cache = [];
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $stmt = $pdo->prepare("SELECT user_key FROM usuarios WHERE id = ?");
    $stmt->execute([$uid]);
    $k = $stmt->fetchColumn();
    return $cache[$uid] = $k ?: null;
}

/* ── Auto-migración de tablas ──────────────────────────────────────
   Tres tablas:
     events             — el evento en sí
     event_participants — usuarios unidos (joined) o en waitlist
                          (con FK al recordatorio que les puso en su
                           calendario, para borrarlo al salir)
     event_invites      — invitaciones pendientes/aceptadas/rechazadas
*/
try {
    melon_esquema($pdo, 'eventos', __FILE__, function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS events (
                id                  INT AUTO_INCREMENT PRIMARY KEY,
                creator_id          INT NOT NULL,
                title               VARCHAR(120) NOT NULL,
                description         TEXT,
                event_date          DATETIME NOT NULL,
                duration_min        INT NOT NULL DEFAULT 60,
                min_participants    INT NOT NULL DEFAULT 1,
                max_participants    INT NOT NULL DEFAULT 0,
                visibility          ENUM('public','private') NOT NULL DEFAULT 'public',
                image_url           VARCHAR(500) NULL,
                discord_message_id  VARCHAR(32) NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_date (event_date),
                INDEX idx_creator (creator_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        /* Idempotente: añade columnas en BDs viejas que ya tenían la tabla. */
        try {
            $has = $pdo->query("SHOW COLUMNS FROM events LIKE 'discord_message_id'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE events ADD COLUMN discord_message_id VARCHAR(32) NULL");
        } catch (Throwable $_) {}
        try {
            $has = $pdo->query("SHOW COLUMNS FROM events LIKE 'image_url'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE events ADD COLUMN image_url VARCHAR(500) NULL AFTER visibility");
        } catch (Throwable $_) {}
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_participants (
                event_id        INT NOT NULL,
                user_id         INT NOT NULL,
                status          ENUM('joined','waitlist') NOT NULL DEFAULT 'joined',
                recordatorio_id INT NULL,
                joined_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id, user_id),
                INDEX idx_user (user_id),
                INDEX idx_event_status (event_id, status, joined_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_invites (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                event_id     INT NOT NULL,
                inviter_id   INT NOT NULL,
                invitee_id   INT NOT NULL,
                status       ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                notified_at  TIMESTAMP NULL,
                UNIQUE KEY uq_event_invitee (event_id, invitee_id),
                INDEX idx_invitee_status (invitee_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }, true);
} catch (Throwable $e) { /* silencio */ }

/* ── Discord announcer ──────────────────────────────────────────────
   Cuando se crea un evento PÚBLICO, el bot lo anuncia en el canal de
   Discord configurado. El message_id se guarda en `events`, y al
   join/leave/promote/delete editamos (PATCH) el embed para mantener
   los participantes actualizados en vivo. */
const EV_DISCORD_CHANNEL = '1516472123651133450';
const EV_PUBLIC_BASE_URL = 'https://melonhub.es';

function ev_botToken(): string {
    return trim((string)env('DISCORD_BOT_TOKEN', ''));
}

function ev_buildEmbed(array $event, string $creatorLabel, int $joined, int $waitlist, ?string $avatarUrl = null): array {
    /* Fecha localizada compacta. event_date es DATETIME → usamos epoch
       en el embed (Discord lo renderiza con timezone del visor) +
       fallback con fecha plain por si el lector no lo resuelve. */
    $ts        = strtotime((string)$event['event_date']);
    $when      = $ts ? ('<t:' . $ts . ':F>') : (string)$event['event_date'];
    $whenShort = $ts ? ('<t:' . $ts . ':R>') : '';

    $maxStr = ((int)$event['max_participants']) > 0
        ? (string)(int)$event['max_participants']
        : '∞';
    $waitStr = $waitlist > 0 ? (' (+' . $waitlist . ' en espera)') : '';
    $partLine = $joined . ' / ' . $maxStr . $waitStr;

    $melonUrl  = EV_PUBLIC_BASE_URL . '/?openEvent=' . (int)$event['id'];

    $author = ['name' => $creatorLabel];
    /* URL pública del avatar → Discord la fetchea ONCE y la cachea.
       Mismo URL para POST y PATCH ⇒ avatar consistente en toda la
       vida del mensaje. Sin attachments multipart ⇒ no quedan
       archivos sueltos al editar. */
    if ($avatarUrl) $author['icon_url'] = $avatarUrl;

    /* Descripción: bullet con título grande arriba (Discord no acepta
       headings markdown en embeds — usamos negrita). El link "Unirse"
       va abajo como CTA visible al instante. */
    $desc = '';
    if (!empty($event['description'])) {
        $desc = mb_substr((string)$event['description'], 0, 1500);
    }
    $desc .= ($desc !== '' ? "\n\n" : '') . '**[→ Unirse en Melon Hub](' . $melonUrl . ')**';

    $embed = [
        'title'       => mb_substr((string)$event['title'], 0, 240),
        'url'         => $melonUrl,
        'description' => $desc,
        'color'       => 0xFF66B2,       /* rosa accent kawaii — coherente con Overdose */
        'author'      => $author,
        'fields'      => [
            ['name' => 'Fecha y hora', 'value' => $when . ($whenShort ? "\n" . $whenShort : ''), 'inline' => true],
            ['name' => 'Duración',     'value' => ((int)$event['duration_min']) . ' min',         'inline' => true],
            ['name' => 'Participantes','value' => $partLine,                                      'inline' => true],
            ['name' => 'Mínimo',       'value' => (string)(int)$event['min_participants'],         'inline' => true],
            ['name' => 'Máximo',       'value' => $maxStr,                                          'inline' => true],
            ['name' => 'Visibilidad',  'value' => 'Público',                                        'inline' => true],
        ],
        'footer'      => ['text' => 'Evento creado por ' . $creatorLabel . ' en Melon Hub'],
        'timestamp'   => gmdate('c', $ts ?: time()),
    ];
    /* Imagen del evento (banner grande del embed). Discord la fetchea
       y la cachea. Si el URL es inválido el embed igualmente se renderiza
       sin la imagen — no necesita validación previa aquí. */
    $imgUrl = trim((string)($event['image_url'] ?? ''));
    if ($imgUrl !== '' && preg_match('#^https?://#i', $imgUrl)) {
        $embed['image'] = ['url' => $imgUrl];
    }
    return $embed;
}

/* Lee creator label + URL pública del avatar (sin multipart). */
function ev_creatorInfo(PDO $pdo, int $creatorId): array {
    $stmt = $pdo->prepare("SELECT user_key, label FROM usuarios WHERE id = ?");
    $stmt->execute([$creatorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $label = $row['label'] ?? ($row['user_key'] ?? 'Usuario');
    $avatarUrl = null;
    if (function_exists('getUserImage') && $row) {
        $rel = getUserImage($row['label'] ?? '');
        if ($rel) {
            /* URL absoluta pública → Discord la fetchea + cachea. */
            $avatarUrl = EV_PUBLIC_BASE_URL . '/' . ltrim($rel, '/');
        }
    }
    return ['label' => $label, 'avatarUrl' => $avatarUrl];
}

/* Cuenta joined / waitlist actuales del evento. */
function ev_counts(PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM event_participants WHERE event_id = ? GROUP BY status");
    $stmt->execute([$eventId]);
    $j = 0; $w = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['status'] === 'joined')   $j = (int)$r['c'];
        if ($r['status'] === 'waitlist') $w = (int)$r['c'];
    }
    return ['joined' => $j, 'waitlist' => $w];
}

/* POST inicial del embed → devuelve message_id o null en error.
   Sin multipart: el avatar va como URL pública en author.icon_url —
   Discord la fetchea y la cachea. Misma URL se usa en los PATCH ⇒
   el avatar persiste consistente durante toda la vida del mensaje. */
function ev_discordPublish(PDO $pdo, array $event): ?string {
    if (($event['visibility'] ?? '') !== 'public') return null;
    $token = ev_botToken();
    if ($token === '') return null;

    $ci     = ev_creatorInfo($pdo, (int)$event['creator_id']);
    $counts = ev_counts($pdo, (int)$event['id']);
    $embed  = ev_buildEmbed($event, $ci['label'], $counts['joined'], $counts['waitlist'], $ci['avatarUrl']);

    /* @everyone al publicarlo (solo aquí: los PATCH no tocan `content`,
       así que al editar no vuelve a avisar a todos). El bot necesita el
       permiso "Mencionar @everyone" en el canal; sin él, sale el texto
       pero no avisa. */
    $payload = ['content' => '@everyone', 'embeds' => [$embed], 'allowed_mentions' => ['parse' => ['everyone']]];
    $url     = 'https://discord.com/api/v10/channels/' . rawurlencode(EV_DISCORD_CHANNEL) . '/messages';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . $token,
            'Content-Type: application/json',
            'User-Agent: MelonHub-Events (https://melonhub.es, 1.0)',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300) return null;
    $j = json_decode((string)$resp, true);
    if (!is_array($j) || !isset($j['id'])) return null;
    return (string)$j['id'];
}

/* PATCH del embed con participantes actuales. Idempotente. */
function ev_discordUpdate(PDO $pdo, int $eventId): void {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return;
    $msgId = (string)($event['discord_message_id'] ?? '');
    if ($msgId === '' || ($event['visibility'] ?? '') !== 'public') return;
    $token = ev_botToken();
    if ($token === '') return;

    $ci     = ev_creatorInfo($pdo, (int)$event['creator_id']);
    $counts = ev_counts($pdo, (int)$event['id']);
    /* Mismo avatarUrl que el POST → el embed conserva el icono del
       author en cada update. Discord cachea la imagen al primer fetch. */
    $embed  = ev_buildEmbed($event, $ci['label'], $counts['joined'], $counts['waitlist'], $ci['avatarUrl']);

    $url  = 'https://discord.com/api/v10/channels/' . rawurlencode(EV_DISCORD_CHANNEL)
          . '/messages/' . rawurlencode($msgId);
    /* `attachments: []` por si quedaron archivos sueltos de versiones
       anteriores que sí usaban multipart — los limpia. */
    $body = json_encode([
        'embeds'      => [$embed],
        'attachments' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . $token,
            'Content-Type: application/json',
            'User-Agent: MelonHub-Events (https://melonhub.es, 1.0)',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
}

/* DELETE del mensaje cuando el evento se borra. Silencioso. */
function ev_discordDelete(?string $msgId): void {
    if (!$msgId) return;
    $token = ev_botToken();
    if ($token === '') return;
    $url = 'https://discord.com/api/v10/channels/' . rawurlencode(EV_DISCORD_CHANNEL)
         . '/messages/' . rawurlencode($msgId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . $token,
            'User-Agent: MelonHub-Events (https://melonhub.es, 1.0)',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch);
}

/* ── Helper: inserta un recordatorio en el calendario del usuario
      para un evento al que se une (joined). Devuelve el id del
      recordatorio o null si falla. ───────────────────────────────── */
function ev_addCalendarReminder(PDO $pdo, int $uid, array $event): ?int {
    $title = 'Evento: ' . mb_substr($event['title'], 0, 100);
    $desc  = (string)($event['description'] ?? '');
    $date  = (string)$event['event_date'];
    /* La tabla recordatorios tiene pareja_id NOT NULL DEFAULT 0 → 0 = sin pareja. */
    try {
        $stmt = $pdo->prepare("INSERT INTO recordatorios (usuario_id, pareja_id, titulo, fecha, descripcion, periodicidad)
                               VALUES (?, 0, ?, ?, ?, 'ninguna')");
        $stmt->execute([$uid, $title, $date, $desc]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return null; }
}
function ev_removeCalendarReminder(PDO $pdo, ?int $rid, int $uid): void {
    if (!$rid) return;
    try {
        $stmt = $pdo->prepare("DELETE FROM recordatorios WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$rid, $uid]);
    } catch (Throwable $e) { /* silencio */ }
}

/* Promociona al primero de la waitlist a joined cuando alguien sale.
   Si lo promueve, también le crea su recordatorio en el calendario. */
function ev_promoteFromWaitlist(PDO $pdo, int $eventId): void {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return;
    $cap = (int)$event['max_participants'];
    if ($cap <= 0) return; /* sin cupo no hay waitlist */

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'joined'");
    $stmt->execute([$eventId]);
    $joined = (int)$stmt->fetchColumn();
    if ($joined >= $cap) return;

    $stmt = $pdo->prepare("SELECT user_id FROM event_participants
                           WHERE event_id = ? AND status = 'waitlist'
                           ORDER BY joined_at ASC LIMIT 1");
    $stmt->execute([$eventId]);
    $nextUid = $stmt->fetchColumn();
    if (!$nextUid) return;
    $nextUid = (int)$nextUid;

    /* Promover + insertar recordatorio. */
    $rid = ev_addCalendarReminder($pdo, $nextUid, $event);
    $stmt = $pdo->prepare("UPDATE event_participants
                           SET status = 'joined', recordatorio_id = ?
                           WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$rid, $eventId, $nextUid]);
    /* Mantén el embed sincronizado. */
    ev_discordUpdate($pdo, $eventId);
}

/* Comprueba si el usuario PUEDE ver un evento concreto. */
function ev_canSee(PDO $pdo, int $uid, array $event): bool {
    if ($event['visibility'] === 'public') return true;
    if ((int)$event['creator_id'] === $uid) return true;
    /* Privado: ver si es invitado o ya unido. */
    $stmt = $pdo->prepare("SELECT 1 FROM event_participants WHERE event_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$event['id'], $uid]);
    if ($stmt->fetchColumn()) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM event_invites WHERE event_id = ? AND invitee_id = ? LIMIT 1");
    $stmt->execute([$event['id'], $uid]);
    return (bool)$stmt->fetchColumn();
}

/* Hidrata un evento con counts y datos del usuario actual:
     myStatus    — 'joined' | 'waitlist' | 'invited' | null
     myInviteId  — int|null (si invitado pendiente)
     joinedCount — int
     waitlistCount — int
     creatorKey  — user_key del creador
     participants — array<{key,label,status}> de joined (limitado por privacidad) */
function ev_hydrate(PDO $pdo, int $uid, array $event): array {
    $eid = (int)$event['id'];
    /* Counts. */
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM event_participants WHERE event_id = ? GROUP BY status");
    $stmt->execute([$eid]);
    $counts = ['joined' => 0, 'waitlist' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['status']] = (int)$row['c'];
    }
    /* Mi status. */
    $stmt = $pdo->prepare("SELECT status FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $uid]);
    $myStatus = $stmt->fetchColumn() ?: null;
    $myInviteId = null;
    if (!$myStatus) {
        $stmt = $pdo->prepare("SELECT id FROM event_invites WHERE event_id = ? AND invitee_id = ? AND status = 'pending'");
        $stmt->execute([$eid, $uid]);
        $iid = $stmt->fetchColumn();
        if ($iid) { $myStatus = 'invited'; $myInviteId = (int)$iid; }
    }
    /* Lista participants joined (todos pueden verla si participan o si es público). */
    $stmt = $pdo->prepare("
        SELECT u.user_key AS `key`, u.label AS label, ep.status
          FROM event_participants ep
          JOIN usuarios u ON u.id = ep.user_id
         WHERE ep.event_id = ?
         ORDER BY ep.status DESC, ep.joined_at ASC
    ");
    $stmt->execute([$eid]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    /* Creator. */
    $creatorKey = ev_userKey($pdo, (int)$event['creator_id']) ?? '';
    /* Calcular si está finalizado. */
    $endTs = strtotime($event['event_date']) + ((int)$event['duration_min']) * 60;
    $isFinished = $endTs < time();
    return [
        'id'              => $eid,
        'title'           => (string)$event['title'],
        'description'     => (string)$event['description'],
        'eventDate'       => (string)$event['event_date'],
        'durationMin'     => (int)$event['duration_min'],
        'minParticipants' => (int)$event['min_participants'],
        'maxParticipants' => (int)$event['max_participants'],
        'visibility'      => (string)$event['visibility'],
        'imageUrl'        => (string)($event['image_url'] ?? ''),
        'creatorKey'      => $creatorKey,
        'isCreator'       => ((int)$event['creator_id'] === $uid),
        'isFinished'      => $isFinished,
        'joinedCount'     => $counts['joined'],
        'waitlistCount'   => $counts['waitlist'],
        'myStatus'        => $myStatus,
        'myInviteId'      => $myInviteId,
        'participants'    => $participants,
    ];
}

/* ────────────────────────────────────────────────────────────────── */

switch ($action) {

case 'list-events': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'events' => []]);
    /* Eventos no finalizados (event_date + duration > NOW) que el
       usuario puede ver. Ordenados por fecha ascendente. */
    $stmt = $pdo->prepare("
        SELECT * FROM events
         WHERE DATE_ADD(event_date, INTERVAL duration_min MINUTE) > NOW()
           AND (
                visibility = 'public'
             OR creator_id = ?
             OR id IN (SELECT event_id FROM event_participants WHERE user_id = ?)
             OR id IN (SELECT event_id FROM event_invites WHERE invitee_id = ?)
           )
         ORDER BY event_date ASC
    ");
    $stmt->execute([$uid, $uid, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) $out[] = ev_hydrate($pdo, $uid, $row);
    jsonResponse(['ok' => true, 'events' => $out]);
}

case 'get-event': {
    $uid = ev_uid($pdo, $userKey);
    $eid = (int)($_GET['id'] ?? 0);
    if (!$uid || !$eid) jsonError('Datos inválidos');
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);
    if (!ev_canSee($pdo, $uid, $event)) jsonError('Sin permiso', 403);
    jsonResponse(['ok' => true, 'event' => ev_hydrate($pdo, $uid, $event)]);
}

case 'create-event': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) jsonError('Título inválido');
    $desc        = trim((string)($b['description'] ?? ''));
    $eventDate   = trim((string)($b['eventDate'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?$/', $eventDate)) jsonError('Fecha inválida');
    if (strlen($eventDate) === 10) $eventDate .= ' 00:00:00';
    elseif (strlen($eventDate) === 16) $eventDate .= ':00';
    /* No permitir crear en el pasado. */
    if (strtotime($eventDate) < time() - 60) jsonError('La fecha del evento no puede estar en el pasado');
    $durationMin = max(15, min(60 * 24 * 7, (int)($b['durationMin'] ?? 60)));
    $minP        = max(1, min(10000, (int)($b['minParticipants'] ?? 1)));
    $maxP        = max(0, min(10000, (int)($b['maxParticipants'] ?? 0)));
    if ($maxP > 0 && $maxP < $minP) jsonError('Máximo no puede ser menor que mínimo');
    $visibility = (($b['visibility'] ?? '') === 'private') ? 'private' : 'public';
    /* URL de imagen opcional. Si se pasa, debe ser http/https.
       Trim → 500 chars max (cabe en VARCHAR(500)). Cualquier cosa que
       no sea URL válida se descarta silenciosamente para no bloquear
       el create. */
    $imageUrl = trim((string)($b['imageUrl'] ?? ''));
    if ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) $imageUrl = '';
    if (mb_strlen($imageUrl) > 500) $imageUrl = mb_substr($imageUrl, 0, 500);
    $invitees   = is_array($b['invitees'] ?? null) ? $b['invitees'] : [];

    $stmt = $pdo->prepare("INSERT INTO events
        (creator_id, title, description, event_date, duration_min, min_participants, max_participants, visibility, image_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $title, $desc, $eventDate, $durationMin, $minP, $maxP, $visibility, $imageUrl ?: null]);
    $eventId = (int)$pdo->lastInsertId();

    /* El creador se une automáticamente como 'joined'. */
    $event = [
        'id'           => $eventId,
        'title'        => $title,
        'description'  => $desc,
        'event_date'   => $eventDate,
        'duration_min' => $durationMin,
    ];
    $rid = ev_addCalendarReminder($pdo, $uid, $event);
    $pdo->prepare("INSERT INTO event_participants (event_id, user_id, status, recordatorio_id)
                   VALUES (?, ?, 'joined', ?)")
        ->execute([$eventId, $uid, $rid]);

    /* Anuncio Discord: solo eventos PÚBLICOS. Guardamos el message_id
       para PATCH posteriores con participantes actualizados. */
    if ($visibility === 'public') {
        $eventFull = array_merge($event, [
            'visibility'       => $visibility,
            'min_participants' => $minP,
            'max_participants' => $maxP,
            'creator_id'       => $uid,
            'image_url'        => $imageUrl ?: null,
        ]);
        $msgId = ev_discordPublish($pdo, $eventFull);
        if ($msgId) {
            $pdo->prepare("UPDATE events SET discord_message_id = ? WHERE id = ?")
                ->execute([$msgId, $eventId]);
        }
    }

    /* Invitaciones (solo a amigos mutuos para evitar spam). */
    if ($invitees) {
        $mutuals = array_flip(mutualFollowerIds($pdo, $uid));
        $insertInvite = $pdo->prepare("INSERT IGNORE INTO event_invites
                                       (event_id, inviter_id, invitee_id, status)
                                       VALUES (?, ?, ?, 'pending')");
        foreach ($invitees as $key) {
            $iid = ev_uid($pdo, (string)$key);
            if (!$iid || $iid === $uid) continue;
            if (!isset($mutuals[$iid])) continue;
            $insertInvite->execute([$eventId, $uid, $iid]);
            /* Timbre 'cal': su calendario abierto pide las invitaciones al verlo. */
            rt_usuario($iid, 'cal');
        }
    }

    jsonResponse(['ok' => true, 'eventId' => $eventId]);
}

case 'join-event': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    if (!$eid) jsonError('eventId requerido');

    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);

    /* Privado: solo invitados o ya dentro pueden unirse. */
    if (!ev_canSee($pdo, $uid, $event)) jsonError('Sin permiso', 403);

    /* No unirse si ya finalizó. */
    $endTs = strtotime($event['event_date']) + ((int)$event['duration_min']) * 60;
    if ($endTs < time()) jsonError('El evento ya finalizó');

    /* ¿Ya estás dentro? Idempotente. */
    $stmt = $pdo->prepare("SELECT status FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $uid]);
    $existing = $stmt->fetchColumn();
    if ($existing) jsonResponse(['ok' => true, 'status' => $existing]);

    /* ¿Hay sitio o waitlist? */
    $cap = (int)$event['max_participants'];
    $status = 'joined';
    if ($cap > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'joined'");
        $stmt->execute([$eid]);
        $joined = (int)$stmt->fetchColumn();
        if ($joined >= $cap) $status = 'waitlist';
    }

    $rid = ($status === 'joined') ? ev_addCalendarReminder($pdo, $uid, $event) : null;
    $stmt = $pdo->prepare("INSERT INTO event_participants (event_id, user_id, status, recordatorio_id)
                           VALUES (?, ?, ?, ?)");
    $stmt->execute([$eid, $uid, $status, $rid]);

    /* Si tenía invite pendiente, marcarla como accepted. */
    $pdo->prepare("UPDATE event_invites SET status = 'accepted'
                   WHERE event_id = ? AND invitee_id = ? AND status = 'pending'")
        ->execute([$eid, $uid]);

    ev_discordUpdate($pdo, $eid);

    jsonResponse(['ok' => true, 'status' => $status]);
}

case 'leave-event': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    if (!$eid) jsonError('eventId requerido');

    /* Obtener su recordatorio si existe y borrar la participación. */
    $stmt = $pdo->prepare("SELECT recordatorio_id, status FROM event_participants
                           WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(['ok' => true]); /* idempotente */

    ev_removeCalendarReminder($pdo, $row['recordatorio_id'] ? (int)$row['recordatorio_id'] : null, $uid);
    $pdo->prepare("DELETE FROM event_participants WHERE event_id = ? AND user_id = ?")
        ->execute([$eid, $uid]);

    /* Si era joined y libera plaza, promover al primero de waitlist. */
    if ($row['status'] === 'joined') ev_promoteFromWaitlist($pdo, $eid);
    /* Sincroniza embed Discord — cubre TODOS los caminos (promote o no,
       waitlist o joined). Cost: 1 PATCH; idempotente. */
    ev_discordUpdate($pdo, $eid);

    jsonResponse(['ok' => true]);
}

case 'invite-to-event': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    $invKey = (string)($b['userKey'] ?? '');
    if (!$eid || !$invKey) jsonError('Datos inválidos');

    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);

    /* Permiso para invitar: creador SIEMPRE. Si público, cualquier
       participante puede invitar. Si privado, solo el creador. */
    $isCreator = ((int)$event['creator_id'] === $uid);
    if (!$isCreator) {
        if ($event['visibility'] === 'private') jsonError('Solo el creador puede invitar a eventos privados', 403);
        $stmt = $pdo->prepare("SELECT 1 FROM event_participants WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eid, $uid]);
        if (!$stmt->fetchColumn()) jsonError('Debes estar unido al evento para invitar', 403);
    }

    $invUid = ev_uid($pdo, $invKey);
    if (!$invUid || $invUid === $uid) jsonError('Usuario inválido');
    /* Solo amigos mutuos del invitador. */
    $mutuals = array_flip(mutualFollowerIds($pdo, $uid));
    if (!isset($mutuals[$invUid])) jsonError('Solo puedes invitar a amigos mutuos', 403);

    /* No invitar a quien ya esté unido. */
    $stmt = $pdo->prepare("SELECT 1 FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $invUid]);
    if ($stmt->fetchColumn()) jsonResponse(['ok' => true, 'already' => true]);

    /* INSERT IGNORE re-crea invite si la había cancelada. */
    $stmt = $pdo->prepare("INSERT INTO event_invites (event_id, inviter_id, invitee_id, status)
                           VALUES (?, ?, ?, 'pending')
                           ON DUPLICATE KEY UPDATE status = 'pending', inviter_id = VALUES(inviter_id), notified_at = NULL");
    $stmt->execute([$eid, $uid, $invUid]);
    rt_usuario((int)$invUid, 'cal');

    jsonResponse(['ok' => true]);
}

case 'respond-event-invite': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $iid = (int)($b['inviteId'] ?? 0);
    $act = (string)($b['action'] ?? '');
    if (!$iid || !in_array($act, ['accept','decline'], true)) jsonError('Datos inválidos');

    $stmt = $pdo->prepare("SELECT * FROM event_invites WHERE id = ? AND invitee_id = ?");
    $stmt->execute([$iid, $uid]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv || $inv['status'] !== 'pending') jsonError('Invitación no válida', 404);

    if ($act === 'decline') {
        $pdo->prepare("UPDATE event_invites SET status = 'declined' WHERE id = ?")->execute([$iid]);
        jsonResponse(['ok' => true, 'status' => 'declined']);
    }

    /* Accept → reusar lógica de join. */
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([(int)$inv['event_id']]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);
    $endTs = strtotime($event['event_date']) + ((int)$event['duration_min']) * 60;
    if ($endTs < time()) jsonError('El evento ya finalizó');

    /* Si ya estaba dentro de algún modo: idempotente. */
    $stmt = $pdo->prepare("SELECT status FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$event['id'], $uid]);
    $existing = $stmt->fetchColumn();
    if (!$existing) {
        $cap = (int)$event['max_participants'];
        $status = 'joined';
        if ($cap > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'joined'");
            $stmt->execute([$event['id']]);
            $joined = (int)$stmt->fetchColumn();
            if ($joined >= $cap) $status = 'waitlist';
        }
        $rid = ($status === 'joined') ? ev_addCalendarReminder($pdo, $uid, $event) : null;
        $pdo->prepare("INSERT INTO event_participants (event_id, user_id, status, recordatorio_id)
                       VALUES (?, ?, ?, ?)")
            ->execute([$event['id'], $uid, $status, $rid]);
        $existing = $status;
    }
    $pdo->prepare("UPDATE event_invites SET status = 'accepted' WHERE id = ?")->execute([$iid]);
    ev_discordUpdate($pdo, (int)$event['id']);
    jsonResponse(['ok' => true, 'status' => $existing]);
}

case 'get-pending-invites': {
    /* Devuelve invites pending del usuario actual con datos para
       mostrar el haro. NO marca como notified — el cliente debe
       llamar a mark-invites-notified tras renderizar. */
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'invites' => []]);
    $stmt = $pdo->prepare("
        SELECT ei.id, ei.event_id, ei.notified_at IS NOT NULL AS notified,
               UNIX_TIMESTAMP(ei.created_at) AS sentAt,
               e.title AS eventTitle, e.event_date AS eventDate,
               u.user_key AS inviterKey, u.label AS inviterLabel
          FROM event_invites ei
          JOIN events e   ON e.id = ei.event_id
          JOIN usuarios u ON u.id = ei.inviter_id
         WHERE ei.invitee_id = ? AND ei.status = 'pending'
         ORDER BY ei.created_at DESC
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jsonResponse(['ok' => true, 'invites' => $rows]);
}

case 'mark-invites-notified': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $ids = is_array($b['inviteIds'] ?? null) ? $b['inviteIds'] : [];
    $ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));
    if (!$ids) jsonResponse(['ok' => true]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE event_invites SET notified_at = NOW()
                           WHERE invitee_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$uid], $ids));
    jsonResponse(['ok' => true]);
}

case 'delete-event': {
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    if (!$eid) jsonError('eventId requerido');
    /* Lee título + creator + msg para poder limpiar TODO. */
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);
    if ((int)$event['creator_id'] !== $uid) jsonError('Solo el creador puede eliminar el evento', 403);

    /* 1) Borra el mensaje del bot en Discord (si lo había). */
    ev_discordDelete($event['discord_message_id'] ?? null);

    /* 2) Borra el recordatorio del calendario de TODOS los participantes.
       Dos pasadas para máxima robustez:
         a) Por recordatorio_id guardado en event_participants (rápido,
            cubre el caso normal).
         b) Por título "Evento: <titulo>" como safety-net — limpia
            cualquier recordatorio huérfano si en algún punto se perdió
            el recordatorio_id (race condition, fila vieja, etc.). */
    $title = 'Evento: ' . mb_substr((string)$event['title'], 0, 100);
    $stmt = $pdo->prepare("SELECT user_id, recordatorio_id FROM event_participants WHERE event_id = ?");
    $stmt->execute([$eid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $puid = (int)$p['user_id'];
        /* a) Por id */
        ev_removeCalendarReminder($pdo, $p['recordatorio_id'] ? (int)$p['recordatorio_id'] : null, $puid);
        /* b) Fallback por título — borra cualquier otro recordatorio
              de este evento que se haya quedado huérfano. */
        try {
            $del = $pdo->prepare("DELETE FROM recordatorios
                                  WHERE usuario_id = ?
                                    AND (pareja_id = 0 OR pareja_id IS NULL)
                                    AND titulo = ?
                                    AND fecha = ?");
            $del->execute([$puid, $title, (string)$event['event_date']]);
        } catch (Throwable $_) {}
    }
    /* 3) Borra rows del evento en orden FK-safe. */
    $pdo->prepare("DELETE FROM event_participants WHERE event_id = ?")->execute([$eid]);
    $pdo->prepare("DELETE FROM event_invites      WHERE event_id = ?")->execute([$eid]);
    $pdo->prepare("DELETE FROM events             WHERE id       = ?")->execute([$eid]);
    jsonResponse(['ok' => true]);
}

case 'list-mutual-friends': {
    /* Lista amigos mutuos para el form de invitación. */
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'friends' => []]);
    $ids = mutualFollowerIds($pdo, $uid);
    if (!$ids) jsonResponse(['ok' => true, 'friends' => []]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT user_key AS `key`, label AS label FROM usuarios WHERE id IN ($placeholders) ORDER BY label");
    $stmt->execute($ids);
    jsonResponse(['ok' => true, 'friends' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

case 'update-event': {
    /* Edición del evento por el creador. Solo se actualizan los
       campos enviados; el resto se preserva. Si la fecha cambia, se
       refrescan los recordatorios de TODOS los participantes. */
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    if (!$eid) jsonError('eventId requerido');

    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);
    if ((int)$event['creator_id'] !== $uid) jsonError('Solo el creador puede editar', 403);

    /* Cogemos valores entrantes o caemos al actual. */
    $title = trim((string)($b['title'] ?? $event['title']));
    if ($title === '' || mb_strlen($title) > 120) jsonError('Título inválido');
    $desc = trim((string)($b['description'] ?? $event['description']));
    $eventDate = trim((string)($b['eventDate'] ?? $event['event_date']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?$/', $eventDate)) jsonError('Fecha inválida');
    if (strlen($eventDate) === 10) $eventDate .= ' 00:00:00';
    elseif (strlen($eventDate) === 16) $eventDate .= ':00';
    $durationMin = max(15, min(60 * 24 * 7, (int)($b['durationMin'] ?? $event['duration_min'])));
    $minP = max(1, min(10000, (int)($b['minParticipants'] ?? $event['min_participants'])));
    $maxP = max(0, min(10000, (int)($b['maxParticipants'] ?? $event['max_participants'])));
    if ($maxP > 0 && $maxP < $minP) jsonError('Máximo no puede ser menor que mínimo');
    $visibility = (($b['visibility'] ?? $event['visibility']) === 'private') ? 'private' : 'public';
    /* imageUrl: cae al actual si no se envía. Vacío explícito = quitar. */
    $imageUrl = array_key_exists('imageUrl', $b) ? trim((string)$b['imageUrl']) : (string)$event['image_url'];
    if ($imageUrl !== '' && !preg_match('#^https?://#i', $imageUrl)) $imageUrl = '';
    if (mb_strlen($imageUrl) > 500) $imageUrl = mb_substr($imageUrl, 0, 500);

    $pdo->prepare("UPDATE events
                   SET title=?, description=?, event_date=?, duration_min=?,
                       min_participants=?, max_participants=?, visibility=?, image_url=?
                   WHERE id=?")
        ->execute([$title, $desc, $eventDate, $durationMin, $minP, $maxP, $visibility, $imageUrl ?: null, $eid]);

    /* Refresca recordatorios de todos los participantes si la fecha o
       el título cambiaron. */
    if ($eventDate !== $event['event_date']
        || $title    !== $event['title']
        || $desc     !== $event['description']) {
        $newTitle = 'Evento: ' . mb_substr($title, 0, 100);
        $stmt = $pdo->prepare("SELECT recordatorio_id FROM event_participants
                               WHERE event_id = ? AND recordatorio_id IS NOT NULL");
        $stmt->execute([$eid]);
        $u = $pdo->prepare("UPDATE recordatorios SET titulo=?, fecha=?, descripcion=? WHERE id=?");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            try { $u->execute([$newTitle, $eventDate, $desc, (int)$rid]); } catch (Throwable $_) {}
        }
    }

    /* Lógica Discord: visibility transitions */
    $oldMsgId = $event['discord_message_id'] ?? null;
    if ($event['visibility'] === 'public' && $visibility === 'private') {
        /* public → private: borra el mensaje y limpia el id. */
        ev_discordDelete($oldMsgId);
        $pdo->prepare("UPDATE events SET discord_message_id = NULL WHERE id = ?")->execute([$eid]);
    } elseif ($event['visibility'] === 'private' && $visibility === 'public') {
        /* private → public: publica un nuevo mensaje. */
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$eid]);
        $newEvent = $stmt->fetch(PDO::FETCH_ASSOC);
        $msgId = ev_discordPublish($pdo, $newEvent);
        if ($msgId) $pdo->prepare("UPDATE events SET discord_message_id = ? WHERE id = ?")
                       ->execute([$msgId, $eid]);
    } else {
        /* Mismo tipo de visibility: si era public, PATCH el embed. */
        if ($visibility === 'public') ev_discordUpdate($pdo, $eid);
    }
    jsonResponse(['ok' => true]);
}

case 'kick-from-event': {
    /* El creador expulsa a un participante (joined o waitlist). */
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    $kickKey = (string)($b['userKey'] ?? '');
    if (!$eid || $kickKey === '') jsonError('Datos inválidos');
    $stmt = $pdo->prepare("SELECT creator_id FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $creatorId = (int)($stmt->fetchColumn() ?: 0);
    if (!$creatorId) jsonError('Evento no encontrado', 404);
    if ($creatorId !== $uid) jsonError('Solo el creador puede expulsar', 403);
    $kickUid = ev_uid($pdo, $kickKey);
    if (!$kickUid) jsonError('Usuario inválido');
    if ($kickUid === $uid) jsonError('No puedes expulsarte a ti mismo');

    $stmt = $pdo->prepare("SELECT recordatorio_id, status FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $kickUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(['ok' => true]);

    ev_removeCalendarReminder($pdo, $row['recordatorio_id'] ? (int)$row['recordatorio_id'] : null, $kickUid);
    $pdo->prepare("DELETE FROM event_participants WHERE event_id = ? AND user_id = ?")
        ->execute([$eid, $kickUid]);
    /* Si era joined libera plaza → promover el primero de waitlist. */
    if ($row['status'] === 'joined') ev_promoteFromWaitlist($pdo, $eid);
    ev_discordUpdate($pdo, $eid);
    jsonResponse(['ok' => true]);
}

case 'set-participant-status': {
    /* El creador mueve a un participante entre joined ↔ waitlist.
       NO dispara auto-promoción de waitlist — el creador controla. */
    $uid = ev_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $eid = (int)($b['eventId'] ?? 0);
    $targetKey = (string)($b['userKey'] ?? '');
    $newStatus = (string)($b['status'] ?? '');
    if (!$eid || $targetKey === '' || !in_array($newStatus, ['joined', 'waitlist'], true)) {
        jsonError('Datos inválidos');
    }
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) jsonError('Evento no encontrado', 404);
    if ((int)$event['creator_id'] !== $uid) jsonError('Solo el creador puede mover participantes', 403);
    $targetUid = ev_uid($pdo, $targetKey);
    if (!$targetUid) jsonError('Usuario inválido');

    $stmt = $pdo->prepare("SELECT recordatorio_id, status FROM event_participants WHERE event_id = ? AND user_id = ?");
    $stmt->execute([$eid, $targetUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('El usuario no participa', 404);
    if ($row['status'] === $newStatus) jsonResponse(['ok' => true]); /* no-op */

    if ($newStatus === 'joined') {
        /* Promoción: añadimos recordatorio si no lo tiene. */
        $rid = $row['recordatorio_id']
            ? (int)$row['recordatorio_id']
            : ev_addCalendarReminder($pdo, $targetUid, $event);
        $pdo->prepare("UPDATE event_participants
                       SET status='joined', recordatorio_id=?
                       WHERE event_id=? AND user_id=?")
            ->execute([$rid, $eid, $targetUid]);
    } else {
        /* Democión: quitamos recordatorio. */
        ev_removeCalendarReminder($pdo, $row['recordatorio_id'] ? (int)$row['recordatorio_id'] : null, $targetUid);
        $pdo->prepare("UPDATE event_participants
                       SET status='waitlist', recordatorio_id=NULL
                       WHERE event_id=? AND user_id=?")
            ->execute([$eid, $targetUid]);
    }
    ev_discordUpdate($pdo, $eid);
    jsonResponse(['ok' => true]);
}

default:
    jsonError('Acción desconocida: ' . $action, 400);
}
