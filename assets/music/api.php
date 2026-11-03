<?php
/* ──────────────────────────────────────────────────────────
   MUSIC API — router único de la app Música (SQL)
   ──────────────────────────────────────────────────────────
   Acciones (vía ?action= o $_POST['action']):

   GET   ?action=get-playlists
   GET   ?action=shared-playlist&id=N   (playlist de un enlace, solo lectura)
   GET   ?action=get-users
   GET   ?action=yt-title&id=VIDEOID
   GET   ?action=yt-duration&id=VIDEOID
   GET   ?action=spotify-track&url=...
   GET   ?action=find-album&title=...&artist=...
   GET   ?action=album-tracks&id=SPOTIFY_ALBUM_ID
   GET   ?action=tidal-track&url=...

   POST  ?action=tidal-playlist        { url }
   POST  ?action=save-playlist-item   { id, name, tracks[], sharedFrom? }
   POST  ?action=delete-playlist      { id }
   POST  ?action=leave-playlist       { id, sharedFrom }
   POST  ?action=invite-collaborator  { playlistId, toUser }
   POST  ?action=remove-collaborator  { playlistId, collaborator }
   POST  ?action=respond-invite       { inviteId, action: 'accept'|'reject'|'dismiss' }
   POST  ?action=add-track            { videoId, title, artist? }
   POST  ?action=yt-search-batch      { tracks[] }
   POST  ?action=import-playlist      { url }
   POST  ?action=save-now-playing     { videoId, title, artist, plName, position, duration, isPlaying }
   GET   ?action=search-albums&q=...&artist=...  (autocompletado de álbumes)
   POST  ?action=report-album         { videoId, albumName|albumKey, artist? }  (corrige el álbum)
   GET   ?action=get-now-playing

   ALMACENAMIENTO: 100% SQL.
     - playlists / playlist_tracks / playlist_collaborators
     - playlist_invites (incluye notifs collab-accepted/rejected/left/removed
       además de los invite de colaboración)
     - music_extras (tracks añadidos por add-track al pool global del usuario)

   El SSE notifications-stream.php se mantiene aparte; lee directamente de
   playlist_invites.
   ────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';

/* Para el polling de tv.php — fuerza socket nuevo en cada petición.
   Las TVs viejas (Tizen <2018, webOS 3) reusan sockets keep-alive
   cerrados por Apache (KeepAliveTimeout=5s) y todos los polls tras el
   primero fallan con status=0. Aplicar antes que requireAuth para que
   el header esté en cualquier respuesta del endpoint, incluyendo 401. */
if (($_GET['action'] ?? '') === 'get-now-playing') {
    header('Connection: close');
}

/* Bypass de requireAuth para el modo iframe de get-now-playing.
   requireAuth devuelve JSON 401 con Content-Type application/json. El
   iframe del polling de tv.php necesita HTML siempre (si no, dispara
   un error cross-origin al intentar leer contentDocument). Aquí
   devolvemos un HTML con {ok:false,error:"auth"} cuando no hay sesión. */
if (($_GET['action'] ?? '') === 'get-now-playing' && isset($_GET['frame'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessUser = $_SESSION['user'] ?? null;
    if (!$sessUser || !isset($loginUsers[$sessUser])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body><pre id="d">'
           . htmlspecialchars('{"ok":false,"error":"auth"}', ENT_QUOTES, 'UTF-8')
           . '</pre></body></html>';
        exit;
    }
}

$u       = requireAuth();
$userKey = $u['key'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* user_key (string) → usuarios.id (int). Cachea por petición. */
function uidByKey(PDO $pdo, string $userKey): ?int {
    static $cache = [];
    if (array_key_exists($userKey, $cache)) return $cache[$userKey];
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $cache[$userKey] = $id ? (int)$id : null;
}

/* ¿El playlist id parece BIGINT existente (en SQL) o es un id cliente
   "pl_xxx" generado en el navegador para una playlist nueva? */
function isNumericId($v): bool {
    return is_int($v) || (is_string($v) && ctype_digit($v));
}

/* Carga las playlists del usuario con sus tracks + colaboradores en una
   estructura compatible con el formato JSON antiguo. */
/* Crea la tabla de escuchas recientes (idempotente). */
function _ensureRecentTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS music_recent (
        user_id   INT NOT NULL,
        item_type VARCHAR(12)  NOT NULL,
        item_key  VARCHAR(160) NOT NULL,
        name      VARCHAR(255) NOT NULL DEFAULT '',
        artist    VARCHAR(255) NOT NULL DEFAULT '',
        image     VARCHAR(500) NOT NULL DEFAULT '',
        played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, item_type, item_key),
        INDEX idx_user_time (user_id, played_at)
    ) DEFAULT CHARSET=utf8mb4");
}

/* Añade la columna image_url a `playlists` si no existe (idempotente). */
function _ensurePlaylistImageCol(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM playlists LIKE 'image_url'")->fetch();
        if (!$has) $pdo->exec("ALTER TABLE playlists ADD COLUMN image_url VARCHAR(500) NULL");
    } catch (Throwable $e) { /* sin permiso o ya existe */ }
}

/* Devuelve un mapa videoId(original) => ['newVideoId','title','artist'] con
   las correcciones manuales (song_album_overrides) para los videoIds dados.
   Solo trae los campos; el caller decide cuáles aplicar (los vacíos = sin
   cambio). Best-effort: si la tabla no existe todavía → []. */
function _fetchSongOverrideMap(PDO $pdo, array $videoIds): array {
    $videoIds = array_values(array_unique(array_filter($videoIds)));
    if (!$videoIds) return [];
    try {
        $place = implode(',', array_fill(0, count($videoIds), '?'));
        $stmt = $pdo->prepare("SELECT video_id, new_video_id, song_title, song_artist
                               FROM song_album_overrides WHERE video_id IN ($place)");
        $stmt->execute($videoIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['video_id']] = [
                'newVideoId' => (string)($r['new_video_id'] ?? ''),
                'title'      => (string)($r['song_title']   ?? ''),
                'artist'     => (string)($r['song_artist']  ?? ''),
            ];
        }
        return $map;
    } catch (Throwable $e) { return []; }
}

/* Aplica las correcciones (título/artista/link de YouTube) a una lista de
   tracks (cada uno con 'videoId','title','artist'). Sustituye in-place y
   devuelve la lista. Usado al cargar/importar para que la canción se vea
   SIEMPRE con sus valores corregidos en cualquier playlist. */
function _applySongOverrides(PDO $pdo, array $tracks): array {
    if (!$tracks) return $tracks;
    $map = _fetchSongOverrideMap($pdo, array_column($tracks, 'videoId'));
    if (!$map) return $tracks;
    foreach ($tracks as &$t) {
        $vid = (string)($t['videoId'] ?? '');
        if ($vid === '' || !isset($map[$vid])) continue;
        $ov = $map[$vid];
        if ($ov['title']  !== '') $t['title']  = $ov['title'];
        if ($ov['artist'] !== '') $t['artist'] = $ov['artist'];
        if ($ov['newVideoId'] !== '' && strlen($ov['newVideoId']) === 11) $t['videoId'] = $ov['newVideoId'];
    }
    unset($t);
    return $tracks;
}

function loadPlaylistsForUser(PDO $pdo, int $uid, string $userKey): array {
    _ensurePlaylistImageCol($pdo);
    /* Propias */
    $stmt = $pdo->prepare("SELECT p.id, p.name, COALESCE(p.image_url,'') AS image, ? AS owner
                           FROM playlists p
                           WHERE p.owner_id = ?
                           ORDER BY p.id ASC");
    $stmt->execute([$userKey, $uid]);
    $ownRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Compartidas (collab) */
    $stmt = $pdo->prepare("SELECT p.id, p.name, COALESCE(p.image_url,'') AS image, ou.user_key AS owner, ou.label AS sharedLabel
                           FROM playlist_collaborators c
                           JOIN playlists p ON c.playlist_id = p.id
                           JOIN usuarios ou ON p.owner_id    = ou.id
                           WHERE c.user_id = ?
                           ORDER BY p.id ASC");
    $stmt->execute([$uid]);
    $sharedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ownRows) && empty($sharedRows)) return [];

    /* Cargar todos los tracks de las playlists implicadas en un único query */
    $allIds = array_merge(
        array_column($ownRows, 'id'),
        array_column($sharedRows, 'id')
    );
    $tracksByPl = [];
    if (!empty($allIds)) {
        $place = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $pdo->prepare("SELECT playlist_id, video_id AS videoId, title, artist, duration, added_by AS addedBy
                               FROM playlist_tracks
                               WHERE playlist_id IN ($place)
                               ORDER BY playlist_id ASC, position ASC");
        $stmt->execute($allIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $pid = (int)$tr['playlist_id'];
            unset($tr['playlist_id']);
            $tr['duration'] = (int)$tr['duration'];
            $tracksByPl[$pid][] = $tr;
        }
    }

    /* Aplica las correcciones manuales (título/artista/link de YouTube)
       globalmente, en UN solo query para todos los videoIds implicados. */
    if (!empty($tracksByPl)) {
        $allVids = [];
        foreach ($tracksByPl as $list) foreach ($list as $tr) $allVids[] = $tr['videoId'];
        $ovMap = _fetchSongOverrideMap($pdo, $allVids);
        if ($ovMap) foreach ($tracksByPl as $pid => $list) {
            foreach ($list as $i => $tr) {
                $vid = (string)$tr['videoId'];
                if (!isset($ovMap[$vid])) continue;
                $ov = $ovMap[$vid];
                if ($ov['title']  !== '') $tracksByPl[$pid][$i]['title']  = $ov['title'];
                if ($ov['artist'] !== '') $tracksByPl[$pid][$i]['artist'] = $ov['artist'];
                if ($ov['newVideoId'] !== '' && strlen($ov['newVideoId']) === 11) $tracksByPl[$pid][$i]['videoId'] = $ov['newVideoId'];
            }
        }
    }

    /* Colaboradores por playlist (solo para las propias; las shared el
       cliente las trata como "no editables masivamente" por sharedFrom). */
    $collabsByPl = [];
    if (!empty($ownRows)) {
        $place = implode(',', array_fill(0, count($ownRows), '?'));
        $params = array_column($ownRows, 'id');
        $stmt = $pdo->prepare("SELECT c.playlist_id, u.user_key
                               FROM playlist_collaborators c
                               JOIN usuarios u ON c.user_id = u.id
                               WHERE c.playlist_id IN ($place)");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $collabsByPl[(int)$r['playlist_id']][] = $r['user_key'];
        }
    }

    $result = [];
    foreach ($ownRows as $r) {
        $pid = (int)$r['id'];
        $result[] = [
            'id'            => $pid,
            'name'          => $r['name'],
            'image'         => $r['image'] ?? '',
            'owner'         => $r['owner'],
            'collaborators' => $collabsByPl[$pid] ?? [],
            'tracks'        => $tracksByPl[$pid]  ?? [],
        ];
    }
    foreach ($sharedRows as $r) {
        $pid = (int)$r['id'];
        $result[] = [
            'id'           => $pid,
            'name'         => $r['name'],
            'image'        => $r['image'] ?? '',
            'owner'        => $r['owner'],
            'sharedFrom'   => $r['owner'],
            'sharedLabel'  => $r['sharedLabel'],
            'tracks'       => $tracksByPl[$pid] ?? [],
        ];
    }
    return $result;
}

/* Sustituye TODOS los tracks de una playlist. Asume autorización ya hecha. */
function replacePlaylistTracks(PDO $pdo, int $playlistId, array $tracks): void {
    $pdo->prepare("DELETE FROM playlist_tracks WHERE playlist_id = ?")->execute([$playlistId]);
    if (empty($tracks)) return;
    $stmt = $pdo->prepare("INSERT INTO playlist_tracks
        (playlist_id, position, video_id, title, artist, duration, added_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($tracks as $pos => $t) {
        $stmt->execute([
            $playlistId, $pos,
            $t['videoId'], $t['title'], $t['artist'], $t['duration'], $t['addedBy'],
        ]);
    }
}

/* Sanitiza el array de tracks que llega del cliente. */
function sanitizeTracks($raw): array {
    $tracks = [];
    if (!is_array($raw)) return $tracks;
    foreach ($raw as $t) {
        $vid = preg_replace('/[^A-Za-z0-9_-]/', '', $t['videoId'] ?? '');
        if (strlen($vid) !== 11) continue;
        $tracks[] = [
            'videoId'  => $vid,
            'title'    => substr(strip_tags($t['title']   ?? ''), 0, 200),
            'artist'   => substr(strip_tags($t['artist']  ?? ''), 0, 200),
            'duration' => max(0, intval($t['duration']    ?? 0)),
            'addedBy'  => substr(strip_tags($t['addedBy'] ?? ''), 0, 100),
        ];
    }
    return $tracks;
}

/* Inserta una notificación tipo collab-* (status). Si el playlist ya no
   existe la inserción falla por FK; lo ignoramos sin romper la respuesta. */
function pushCollabNotif(PDO $pdo, int $toUid, int $fromUid, int $playlistId, string $type): void {
    try {
        $pdo->prepare("INSERT INTO playlist_invites (to_user_id, from_user_id, playlist_id, type)
                       VALUES (?, ?, ?, ?)")
            ->execute([$toUid, $fromUid, $playlistId, $type]);
    } catch (PDOException $e) { /* FK perdida → playlist borrada; sin notif */ }
}

switch ($action) {

/* ─── Playlists ──────────────────────────────────────────── */

case 'get-playlists': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonResponse([]);
    jsonResponse(loadPlaylistsForUser($pdo, $uid, $userKey));
}

/* Registra una escucha reciente (canción / álbum / playlist). */
case 'record-recent': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $type = (string)($b['type'] ?? '');
    $key  = trim((string)($b['key'] ?? ''));
    if (!in_array($type, ['song', 'album', 'playlist'], true) || $key === '') {
        jsonResponse(['ok' => false]);
    }
    _ensureRecentTable($pdo);
    $name   = mb_substr(trim((string)($b['name']   ?? '')), 0, 255);
    $artist = mb_substr(trim((string)($b['artist'] ?? '')), 0, 255);
    $image  = mb_substr(trim((string)($b['image']  ?? '')), 0, 500);
    $pdo->prepare("INSERT INTO music_recent (user_id, item_type, item_key, name, artist, image)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE name=VALUES(name), artist=VALUES(artist),
                       image=VALUES(image), played_at=NOW()")
        ->execute([$uid, $type, mb_substr($key, 0, 160), $name, $artist, $image]);
    jsonResponse(['ok' => true]);
}

/* Devuelve las últimas escuchas recientes del usuario. */
case 'get-recent': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    _ensureRecentTable($pdo);
    $stmt = $pdo->prepare("SELECT item_type AS type, item_key AS `key`, name, artist, image
                           FROM music_recent WHERE user_id = ?
                           ORDER BY played_at DESC LIMIT 24");
    $stmt->execute([$uid]);
    jsonResponse(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'save-playlist-item': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    if (!isset($b['id'], $b['name'])) jsonError('Datos incompletos');

    _ensurePlaylistImageCol($pdo);
    $name       = substr(strip_tags($b['name']), 0, 100);
    $sharedFrom = isset($b['sharedFrom']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $b['sharedFrom']) : null;
    $tracks     = sanitizeTracks($b['tracks'] ?? []);
    /* Imagen opcional de la playlist. null = no enviada (no tocar);
       '' = quitar; URL http(s) = poner. */
    $image = array_key_exists('image', $b) ? trim((string)$b['image']) : null;
    if ($image !== null && $image !== '' && !preg_match('#^https?://#i', $image)) $image = '';
    if (is_string($image)) $image = mb_substr($image, 0, 500);

    /* Caso A: el cliente edita una playlist compartida (colaborador). */
    if ($sharedFrom) {
        if (!isNumericId($b['id'])) jsonError('ID inválido para playlist compartida');
        $playlistId = (int)$b['id'];
        $ownerUid   = uidByKey($pdo, $sharedFrom);
        if (!$ownerUid) jsonError('Propietario inválido');
        /* Verificar que el playlist pertenece al owner y yo soy collab. */
        $stmt = $pdo->prepare("SELECT 1 FROM playlists p
                               JOIN playlist_collaborators c ON c.playlist_id = p.id
                               WHERE p.id = ? AND p.owner_id = ? AND c.user_id = ?");
        $stmt->execute([$playlistId, $ownerUid, $uid]);
        if (!$stmt->fetch()) jsonError('Sin permiso para editar', 403);
        $pdo->beginTransaction();
        try { replacePlaylistTracks($pdo, $playlistId, $tracks); $pdo->commit(); }
        catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        jsonResponse(['ok' => true, 'id' => $playlistId]);
    }

    /* Caso B: playlist propia, ID numérico existente → UPDATE. */
    if (isNumericId($b['id'])) {
        $playlistId = (int)$b['id'];
        $stmt = $pdo->prepare("SELECT 1 FROM playlists WHERE id = ? AND owner_id = ?");
        $stmt->execute([$playlistId, $uid]);
        if ($stmt->fetch()) {
            $pdo->beginTransaction();
            try {
                if ($image !== null) {
                    $pdo->prepare("UPDATE playlists SET name = ?, image_url = ? WHERE id = ?")
                        ->execute([$name, ($image !== '' ? $image : null), $playlistId]);
                } else {
                    $pdo->prepare("UPDATE playlists SET name = ? WHERE id = ?")
                        ->execute([$name, $playlistId]);
                }
                replacePlaylistTracks($pdo, $playlistId, $tracks);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            jsonResponse(['ok' => true, 'id' => $playlistId]);
        }
        /* Si el cliente mandó un numérico pero no existe / no es suyo →
           tratamos como creación nueva: caemos al caso C. */
    }

    /* Caso C: playlist nueva (id "pl_xxx" del cliente o no encontrada). */
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO playlists (owner_id, name, image_url) VALUES (?, ?, ?)")
            ->execute([$uid, $name, (($image !== null && $image !== '') ? $image : null)]);
        $playlistId = (int)$pdo->lastInsertId();
        replacePlaylistTracks($pdo, $playlistId, $tracks);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    jsonResponse(['ok' => true, 'id' => $playlistId]);
}

/* Playlist abierta desde un enlace (enlace.php?playlist=N): la puede ver
   cualquiera con sesión que tenga el enlace, como las de Spotify sin
   publicar. Solo lectura; "Guardar en mis playlists" la copia con
   save-playlist-item (id nuevo). `mine` = es tuya o colaboras en ella. */
case 'shared-playlist': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    if (!isNumericId($_GET['id'] ?? null)) jsonError('ID inválido');
    $playlistId = (int)$_GET['id'];
    _ensurePlaylistImageCol($pdo);
    $stmt = $pdo->prepare("SELECT p.id, p.name, COALESCE(p.image_url,'') AS image, p.owner_id,
                                  ou.user_key AS ownerKey, ou.label AS ownerLabel
                           FROM playlists p JOIN usuarios ou ON p.owner_id = ou.id
                           WHERE p.id = ?");
    $stmt->execute([$playlistId]);
    $pl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pl) jsonError('Esta playlist ya no existe', 404);
    $mine = (int)$pl['owner_id'] === $uid;
    if (!$mine) {
        $st = $pdo->prepare("SELECT 1 FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?");
        $st->execute([$playlistId, $uid]);
        $mine = (bool)$st->fetch();
    }
    $stmt = $pdo->prepare("SELECT video_id AS videoId, title, artist, duration
                           FROM playlist_tracks WHERE playlist_id = ? ORDER BY position ASC");
    $stmt->execute([$playlistId]);
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tracks as &$t) $t['duration'] = (int)$t['duration'];
    unset($t);
    jsonResponse([
        'ok'         => true,
        'id'         => $playlistId,
        'name'       => $pl['name'],
        'image'      => $pl['image'],
        'ownerKey'   => $pl['ownerKey'],
        'ownerLabel' => $pl['ownerLabel'] ?: $pl['ownerKey'],
        'mine'       => $mine,
        'tracks'     => _applySongOverrides($pdo, $tracks),
    ]);
}

case 'delete-playlist': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b   = jsonBody();
    if (!isNumericId($b['id'] ?? null)) jsonError('ID inválido');
    $playlistId = (int)$b['id'];
    /* CASCADE borra tracks, collaborators e invites asociados. */
    $stmt = $pdo->prepare("DELETE FROM playlists WHERE id = ? AND owner_id = ?");
    $stmt->execute([$playlistId, $uid]);
    if (!$stmt->rowCount()) jsonError('Playlist no encontrada o no autorizada', 404);
    jsonResponse(['ok' => true]);
}

case 'leave-playlist': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b          = jsonBody();
    if (!isNumericId($b['id'] ?? null)) jsonError('Datos incompletos');
    $sharedFrom = preg_replace('/[^A-Za-z0-9_-]/', '', $b['sharedFrom'] ?? '');
    if (!$sharedFrom) jsonError('Datos incompletos');
    $ownerUid = uidByKey($pdo, $sharedFrom);
    if (!$ownerUid) jsonError('Propietario inválido');
    $playlistId = (int)$b['id'];

    /* Verificar la relación de colaboración antes de borrar (para que el
       owner sólo reciba notif si realmente había salido un colaborador). */
    $stmt = $pdo->prepare("SELECT p.name FROM playlists p
                           JOIN playlist_collaborators c ON c.playlist_id = p.id
                           WHERE p.id = ? AND p.owner_id = ? AND c.user_id = ?");
    $stmt->execute([$playlistId, $ownerUid, $uid]);
    $playlistName = $stmt->fetchColumn();
    if ($playlistName === false) jsonError('No eres colaborador', 404);

    $pdo->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?")
        ->execute([$playlistId, $uid]);
    pushCollabNotif($pdo, $ownerUid, $uid, $playlistId, 'collab-left');
    jsonResponse(['ok' => true]);
}

/* ─── Colaboraciones ─────────────────────────────────────── */

case 'invite-collaborator': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b          = jsonBody();
    if (!isNumericId($b['playlistId'] ?? null)) jsonError('Datos incompletos');
    $playlistId = (int)$b['playlistId'];
    $toUser     = preg_replace('/[^A-Za-z0-9_-]/', '', $b['toUser'] ?? '');
    if (!$toUser)             jsonError('Datos incompletos');
    if ($toUser === $userKey) jsonError('No puedes invitarte a ti mismo');
    $toUid = uidByKey($pdo, $toUser);
    if (!$toUid) jsonError('Usuario destino inválido');

    /* Restringir colaboraciones a seguidores mutuos. */
    require_once dirname(__DIR__) . '/social-helpers.php';
    if (!isMutualFollow($pdo, $uid, $toUid)) {
        jsonError('Solo puedes invitar a usuarios con seguimiento mutuo');
    }

    /* Validar que la playlist es del usuario actual */
    $stmt = $pdo->prepare("SELECT name FROM playlists WHERE id = ? AND owner_id = ?");
    $stmt->execute([$playlistId, $uid]);
    $plName = $stmt->fetchColumn();
    if ($plName === false) jsonError('Playlist no encontrada', 404);

    /* ¿Ya es colaborador? */
    $stmt = $pdo->prepare("SELECT 1 FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?");
    $stmt->execute([$playlistId, $toUid]);
    if ($stmt->fetch()) {
        global $loginUsers;
        jsonError(($loginUsers[$toUser]['label'] ?? $toUser) . ' ya es colaborador');
    }

    /* ¿Ya hay invite pendiente del mismo from→to para esta playlist? */
    $stmt = $pdo->prepare("SELECT 1 FROM playlist_invites
                           WHERE to_user_id = ? AND from_user_id = ? AND playlist_id = ? AND type = 'invite'");
    $stmt->execute([$toUid, $uid, $playlistId]);
    if ($stmt->fetch()) jsonError('Ya existe una invitación pendiente');

    $pdo->prepare("INSERT INTO playlist_invites (to_user_id, from_user_id, playlist_id, type)
                   VALUES (?, ?, ?, 'invite')")
        ->execute([$toUid, $uid, $playlistId]);
    /* Push notification al destinatario. */
    require_once dirname(__DIR__) . '/push/send-push.php';
    global $loginUsers;
    $fromLabel = $loginUsers[$userKey]['label'] ?? $userKey;
    sendPushToUser($pdo, (int)$toUid, buildInvitePushPayload(
        'playlist',
        '📋 ' . $fromLabel . ' te invita',
        'Colaborar en "' . $plName . '"',
    ));
    jsonResponse(['ok' => true]);
}

case 'remove-collaborator': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b            = jsonBody();
    if (!isNumericId($b['playlistId'] ?? null)) jsonError('Datos incompletos');
    $playlistId   = (int)$b['playlistId'];
    $collaborator = preg_replace('/[^A-Za-z0-9_-]/', '', $b['collaborator'] ?? '');
    if (!$collaborator) jsonError('Datos incompletos');
    $collabUid = uidByKey($pdo, $collaborator);
    if (!$collabUid) jsonError('Usuario inválido');

    /* La playlist tiene que ser mía */
    $stmt = $pdo->prepare("SELECT name FROM playlists WHERE id = ? AND owner_id = ?");
    $stmt->execute([$playlistId, $uid]);
    if ($stmt->fetchColumn() === false) jsonError('Playlist no encontrada', 404);

    $pdo->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?")
        ->execute([$playlistId, $collabUid]);
    pushCollabNotif($pdo, $collabUid, $uid, $playlistId, 'removed');
    jsonResponse(['ok' => true]);
}

case 'respond-invite': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b        = jsonBody();
    $inviteId = (int)($b['inviteId'] ?? 0);
    $act      = $b['action']   ?? '';
    if (!$inviteId || !in_array($act, ['accept','reject','dismiss'], true)) jsonError('Datos inválidos');

    /* La invitación tiene que ser para mí */
    $stmt = $pdo->prepare("SELECT from_user_id, playlist_id, type FROM playlist_invites
                           WHERE id = ? AND to_user_id = ?");
    $stmt->execute([$inviteId, $uid]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) jsonError('Invitación no encontrada', 404);

    /* Consumir (borrar) la fila pase lo que pase */
    $pdo->prepare("DELETE FROM playlist_invites WHERE id = ?")->execute([$inviteId]);

    /* Aceptar / rechazar sólo aplican al type 'invite' (colaboración). */
    if ($inv['type'] === 'invite' && $act === 'accept') {
        $pdo->prepare("INSERT IGNORE INTO playlist_collaborators (playlist_id, user_id) VALUES (?, ?)")
            ->execute([(int)$inv['playlist_id'], $uid]);
        pushCollabNotif($pdo, (int)$inv['from_user_id'], $uid, (int)$inv['playlist_id'], 'collab-accepted');
    } elseif ($inv['type'] === 'invite' && $act === 'reject') {
        pushCollabNotif($pdo, (int)$inv['from_user_id'], $uid, (int)$inv['playlist_id'], 'collab-rejected');
    }
    /* dismiss = solo cerrar la notif. No se notifica al otro. */

    jsonResponse(['ok' => true]);
}

/* ─── Usuarios ───────────────────────────────────────────── */

case 'get-users': {
    /* Solo devuelve usuarios con seguimiento mutuo respecto al actual.
       Usado por la UI de invitar colaboradores en playlists; si la lista
       sale filtrada, el usuario nunca ve invitables que iban a fallar. */
    require_once dirname(__DIR__) . '/social-helpers.php';
    global $loginUsers;
    $uid = uidByKey($pdo, $userKey);
    $mutualIds = $uid ? mutualFollowerIds($pdo, $uid) : [];
    /* Resolver user_keys de los IDs mutuos en una sola query. */
    $mutualKeys = [];
    if ($mutualIds) {
        $ph = implode(',', array_fill(0, count($mutualIds), '?'));
        $st = $pdo->prepare("SELECT user_key FROM usuarios WHERE id IN ($ph)");
        $st->execute($mutualIds);
        $mutualKeys = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    }
    $users = [];
    foreach ($loginUsers as $k => $u2) {
        if ($k === $userKey) continue;
        if (!isset($mutualKeys[$k])) continue;
        $users[] = ['key' => $k, 'label' => $u2['label']];
    }
    jsonResponse($users);
}

/* ─── Tracks extra (music_extras) ────────────────────────── */

case 'add-track': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    if (!isset($b['videoId'], $b['title'])) jsonError('Datos incompletos');
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $b['videoId']);
    if (strlen($videoId) !== 11) jsonError('ID de video inválido');
    $title  = substr(strip_tags($b['title']),  0, 200);
    $artist = substr(strip_tags($b['artist'] ?? ''), 0, 200);
    $pdo->prepare("INSERT INTO music_extras (user_id, video_id, title, artist) VALUES (?, ?, ?, ?)")
        ->execute([$uid, $videoId, $title, $artist]);
    /* Si esta canción tiene una corrección manual (título/artista/link),
       la devolvemos ya corregida para que entre así en la playlist. */
    $corrected = _applySongOverrides($pdo, [[
        'videoId' => $videoId, 'title' => $title, 'artist' => $artist,
    ]]);
    $t = $corrected[0] ?? ['videoId' => $videoId, 'title' => $title, 'artist' => $artist];
    jsonResponse(['ok' => true, 'track' => [
        'title' => $t['title'], 'artist' => $t['artist'], 'videoId' => $t['videoId'],
    ]]);
}

/* ─── YouTube helpers ────────────────────────────────────── */

/* Datos de una canción para abrirla desde un enlace (enlace.php?cancion=…):
   como se ve en Melon Hub, no el título crudo de YouTube ("Artista - Canción
   (Official Video)" y el canal como artista, con lo que find-album sacaba
   otro álbum). Por orden: la corrección guardada ("Corregir…", que además
   puede cambiar el vídeo), una playlist, las canciones añadidas, las
   escuchas recientes de cualquiera y, si no está en ningún sitio, YouTube. */
case 'song-info': {
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? '');
    if (strlen($videoId) !== 11) jsonError('ID inválido');
    $title = ''; $artist = ''; $duration = 0;
    $one = function (string $sql) use ($pdo, $videoId) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$videoId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    };
    $r = $one("SELECT title, artist, duration FROM playlist_tracks WHERE video_id = ? AND title <> '' ORDER BY playlist_id DESC LIMIT 1")
      ?: $one("SELECT title, artist, 0 AS duration FROM music_extras WHERE video_id = ? AND title <> '' LIMIT 1")
      ?: $one("SELECT name AS title, artist, 0 AS duration FROM music_recent WHERE item_type = 'song' AND item_key = ? AND name <> '' ORDER BY played_at DESC LIMIT 1");
    if ($r) { $title = (string)$r['title']; $artist = (string)$r['artist']; $duration = (int)$r['duration']; }
    $ov = _fetchSongOverrideMap($pdo, [$videoId])[$videoId] ?? null;
    if ($ov) {
        if ($ov['title']  !== '') $title  = $ov['title'];
        if ($ov['artist'] !== '') $artist = $ov['artist'];
        if (strlen($ov['newVideoId']) === 11) $videoId = $ov['newVideoId'];
    }
    if ($title === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=' . $videoId . '&format=json', false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        if (isset($data['title'])) { $title = (string)$data['title']; $artist = (string)($data['author_name'] ?? ''); }
    }
    jsonResponse(['ok' => true, 'videoId' => $videoId, 'title' => $title, 'artist' => $artist, 'duration' => $duration]);
}

case 'yt-title': {
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? '');
    if (strlen($videoId) !== 11) jsonError('ID inválido');
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=' . $videoId . '&format=json', false, $ctx);
    if (!$raw) jsonError('No se pudo obtener el título', 502);
    $data = json_decode($raw, true);
    if (!isset($data['title'])) jsonError('Respuesta inválida', 502);
    jsonResponse(['title' => $data['title'], 'author' => $data['author_name'] ?? '']);
}

case 'yt-duration': {
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? '');
    if (strlen($videoId) !== 11) jsonError('ID inválido');
    $ctx = stream_context_create(['http' => [
        'timeout' => 12, 'ignore_errors' => true,
        'header' => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ]),
    ]]);
    $html = @file_get_contents('https://www.youtube.com/watch?v=' . $videoId, false, $ctx);
    if (!$html) jsonError('No se pudo acceder', 502);
    $duration = 0;
    if (preg_match('/"lengthSeconds"\s*:\s*"(\d+)"/', $html, $m)) $duration = (int)$m[1];
    if (!$duration) jsonError('No se pudo obtener duración', 502);
    jsonResponse(['duration' => $duration]);
}

case 'yt-search-batch': {
    set_time_limit(600);
    $b = jsonBody();
    if (!isset($b['tracks']) || !is_array($b['tracks'])) jsonError('Datos inválidos');

    /* Preparo metadata por índice para preservar el mapeo cuando hagamos
       los fetches en paralelo. */
    $items = [];
    foreach ($b['tracks'] as $t) {
        $title  = substr(strip_tags($t['title']  ?? ''), 0, 200);
        $artist = substr(strip_tags($t['artist'] ?? ''), 0, 200);
        if (!$title) continue;
        $items[] = [
            'query'    => trim($title . ' ' . $artist . ' audio'),
            'title'    => $title,
            'artist'   => $artist,
            'duration' => max(0, intval($t['duration'] ?? 0)),
            'videoId'  => null,
        ];
    }
    if (empty($items)) jsonResponse(['tracks' => []]);

    /* ── Helper genérico de fetch paralelo con concurrencia limitada.
       Aceptamos gzip (ENCODING) para reducir tamaño de transferencia. */
    $multiFetch = function(array $urls, int $concurrent = 10) {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
        $out  = [];
        $keys = array_keys($urls);
        foreach (array_chunk($keys, $concurrent, true) as $chunkKeys) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunkKeys as $k) {
                $ch = curl_init($urls[$k]);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_ENCODING       => '',   /* acepta gzip → menos bytes en el cable */
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$k] = $ch;
            }
            $active = null;
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh, 1.0);
            } while ($active && $status == CURLM_OK);
            foreach ($handles as $k => $ch) {
                $out[$k] = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
            /* Pausa corta entre chunks → suaviza el rate hacia YouTube
               sin perder los beneficios del paralelismo. */
            if (count($chunkKeys) >= $concurrent) usleep(200000);
        }
        return $out;
    };

    require_once __DIR__ . '/spotify-helpers.php'; /* ytExtract*, ytPickBest* */

    /* ── Fase 1: TODAS las búsquedas en paralelo (chunks de 10).
       Para cada track extraemos los candidatos del HTML de results y
       elegimos el mejor por score combinado (título + artista + canal
       + duración + posición), no el primero. Las trap-words
       (karaoke/cover/instrumental/etc.) penalizan fuerte para evitar
       falsos positivos típicos en imports de CSV de Spotify. */
    $searchUrls = [];
    foreach ($items as $i => $it) {
        $searchUrls[$i] = 'https://www.youtube.com/results?search_query=' . urlencode($it['query']);
    }
    foreach ($multiFetch($searchUrls, 10) as $i => $html) {
        $best = ytPickBestCandidate(ytExtractCandidates($html, 7), $items[$i]);
        if (!$best) continue;
        $items[$i]['videoId'] = $best['videoId'];
        if (!empty($best['duration'])) {
            /* Si la duración vino en el HTML de results, evita el watch
               fetch de Fase 2. */
            $items[$i]['duration'] = $best['duration'];
        }
    }

    /* ── Fase 2: completar duración para los que aún no la tienen
       (la sacamos del HTML de results si pudimos; este watch fetch es
       el fallback). */
    $watchUrls = [];
    foreach ($items as $i => $it) {
        if ($it['videoId'] && !$it['duration']) {
            $watchUrls[$i] = 'https://www.youtube.com/watch?v=' . $it['videoId'];
        }
    }
    if ($watchUrls) {
        foreach ($multiFetch($watchUrls, 10) as $i => $html) {
            if ($html && preg_match('/"lengthSeconds"\s*:\s*"(\d+)"/', $html, $d)) {
                $items[$i]['duration'] = (int)$d[1];
            }
        }
    }

    /* Construye respuesta final solo con los que tienen videoId resuelto. */
    $results = [];
    foreach ($items as $it) {
        if (!$it['videoId']) continue;
        $results[] = [
            'videoId'  => $it['videoId'],
            'title'    => $it['title'],
            'artist'   => $it['artist'],
            'duration' => $it['duration'],
        ];
    }
    /* Aplica las correcciones manuales (título/artista/link) a los tracks
       resueltos: al importar de Spotify/Tidal/etc. la canción entra ya con
       sus valores corregidos. */
    $results = _applySongOverrides($pdo, $results);
    jsonResponse(['tracks' => $results]);
}

/* ─── Spotify ─────────────────────────────────────────────── */

case 'spotify-track': {
    require_once __DIR__ . '/spotify-helpers.php';
    $url = trim($_GET['url'] ?? '');
    if (!$url) jsonError('URL requerida');
    $trackId = null;
    if (preg_match('/spotify\.com\/(?:[a-z][a-z0-9-]*\/)?track\/([A-Za-z0-9]+)/', $url, $m)) $trackId = $m[1];
    elseif (preg_match('/spotify:track:([A-Za-z0-9]+)/', $url, $m))                        $trackId = $m[1];
    if (!$trackId) jsonError('URL de track de Spotify inválida');
    $token = getSpotifyToken();
    if (!$token) jsonError('No se pudo autenticar con Spotify', 502);
    $ctx = stream_context_create(['http' => [
        'timeout' => 10, 'ignore_errors' => true,
        'header'  => 'Authorization: Bearer ' . $token,
    ]]);
    $raw = @file_get_contents('https://api.spotify.com/v1/tracks/' . $trackId, false, $ctx);
    if (!$raw) jsonError('No se pudo obtener el track de Spotify', 502);
    $track = json_decode($raw, true);
    if (!isset($track['name'])) jsonError('Track no encontrado en Spotify', 404);
    $title    = $track['name'];
    $artist   = $track['artists'][0]['name'] ?? '';
    $duration = isset($track['duration_ms']) ? (int)round($track['duration_ms'] / 1000) : 0;
    /* Pasamos los metadatos al helper: dentro escoge el mejor match por
       título + canal + duración, no el primer videoId que devuelva
       YouTube (que solía ser un cover o lyrics video). */
    $videoId  = searchYouTubeVideoId(
        $title . ' ' . $artist . ' audio',
        ['title' => $title, 'artist' => $artist, 'duration' => $duration]
    );
    if (!$videoId) jsonError('No se encontró el vídeo en YouTube', 502);
    $t = _applySongOverrides($pdo, [[
        'videoId' => $videoId, 'title' => $title, 'artist' => $artist, 'duration' => $duration,
    ]])[0];
    jsonResponse(['title' => $t['title'], 'artist' => $t['artist'], 'duration' => $t['duration'], 'videoId' => $t['videoId']]);
}

/* Busca el álbum al que pertenece una canción usando Spotify Search.
   Patrón: el cliente llama con title + artist (los datos que tiene del
   track de YouTube) y devolvemos lo que Spotify reporta como mejor match.

   Estrategia:
     1. Cache hit por md5(title:artist) → return.
     2. Spotify search type=track con "track:<title> artist:<artist>"
        (más preciso que query libre — Spotify ignora ruidos como
        "[Official Video]" o "feat. X").
     3. Si no hay match, búsqueda libre (sin filtros) como fallback.
     4. Si nada, devolvemos { notFound: true } y cacheamos también (7d)
        para no martillear el endpoint con queries que no resuelven.

   Singles: en Spotify a veces el "álbum" es la misma canción. Lo
   marcamos con isSingle=true para que el cliente decida si lo muestra
   ("Single" en vez del nombre del álbum). */
case 'find-album': {
    require_once __DIR__ . '/spotify-helpers.php';
    $title   = trim((string)($_GET['title']  ?? ''));
    $artist  = trim((string)($_GET['artist'] ?? ''));
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['videoId'] ?? ''));
    if (strlen($videoId) !== 11) $videoId = '';
    if ($title === '') jsonError('title requerido');

    /* ── Override manual (reporte de álbum incorrecto) ──
       Si alguien corrigió el álbum de este videoId vía `report-album`,
       ese álbum GANA SIEMPRE sobre la cascada automática. Es global (por
       videoId) y persistente, así la canción muestra el álbum correcto en
       cualquier playlist y para todos los usuarios. */
    if ($videoId !== '') {
        try {
            /* Buscamos por el videoId ORIGINAL o por el nuevo (new_video_id):
               si la canción ya se cargó con el link corregido, su videoId
               actual es el nuevo y aun así debe encontrar su override. */
            $ovStmt = $pdo->prepare("SELECT source, album_id, album_key, album_name, album_artist, album_image, album_url
                                       FROM song_album_overrides
                                      WHERE video_id = ? OR new_video_id = ?
                                      LIMIT 1");
            $ovStmt->execute([$videoId, $videoId]);
            $ov = $ovStmt->fetch(PDO::FETCH_ASSOC);
            if ($ov) {
                $ovKey = (string)($ov['album_key'] ?? '');
                $ovImg = (string)($ov['album_image'] ?? '');
                $ovOk  = (strpos($ovKey, 'itunes:') === 0 || strpos($ovKey, 'deezer:') === 0);

                /* Overrides legacy (creados en la era Spotify) llevan
                   album_key = spotify:* o imagen vacía. El cliente RECHAZA
                   las keys spotify:* → el reproductor se quedaba con la
                   miniatura de YouTube (síntoma típico en artistas
                   corregidos muchas veces, p.ej. Lemon Demon). Aquí los
                   re-resolvemos en vivo contra iTunes/Deezer y refrescamos
                   la fila para que la corrección vuelva a ser válida. */
                if (!$ovOk || $ovImg === '') {
                    require_once __DIR__ . '/itunes-helpers.php';
                    require_once __DIR__ . '/deezer-helpers.php';
                    $fix = null;
                    /* Si la key sigue siendo válida pero solo falta imagen,
                       sácala de la metadata del álbum. */
                    if ($ovOk) {
                        [$src, $aid] = explode(':', $ovKey, 2);
                        $meta = $src === 'itunes' ? itunesGetAlbumTracks($aid) : deezerGetAlbumTracks($aid);
                        if ($meta && !empty($meta['image'])) {
                            $fix = ['source' => $src, 'albumId' => $aid,
                                    'name' => (string)($ov['album_name'] ?: ($meta['name'] ?? '')),
                                    'artist' => (string)($ov['album_artist'] ?: ($meta['artist'] ?? '')),
                                    'image' => (string)$meta['image']];
                        }
                    }
                    /* Key inservible (spotify:* / vacía) → re-resolver por
                       nombre de álbum + artista guardados. */
                    if (!$fix && (string)$ov['album_name'] !== '') {
                        $fix = _resolveAlbumByName((string)$ov['album_name'], (string)$ov['album_artist']);
                    }
                    if ($fix) {
                        $newKey = $fix['source'] . ':' . $fix['albumId'];
                        try {
                            $pdo->prepare("UPDATE song_album_overrides
                                SET source = ?, album_id = ?, album_key = ?, album_name = ?, album_artist = ?, album_image = ?
                                WHERE video_id = ?")
                                ->execute([$fix['source'], $fix['albumId'], $newKey, $fix['name'], $fix['artist'], $fix['image'], $videoId]);
                        } catch (Throwable $e) {}
                        $ov['source'] = $fix['source']; $ov['album_key'] = $newKey;
                        $ov['album_name'] = $fix['name']; $ov['album_artist'] = $fix['artist'];
                        $ovImg = $fix['image']; $ovKey = $newKey; $ovOk = true;
                    }
                }

                /* Solo devolvemos el override si quedó con una key válida
                   (itunes:/deezer:). Si no se pudo arreglar, caemos a la
                   cascada automática en vez de servir un payload roto. */
                if ($ovOk) {
                    jsonResponse([
                        'notFound'       => false,
                        'source'         => $ov['source'],
                        'albumKey'       => $ovKey,
                        'spotifyAlbumId' => '',
                        'albumName'      => $ov['album_name'],
                        'albumArtist'    => $ov['album_artist'],
                        'matchArtist'    => $ov['album_artist'],
                        'albumImage'     => $ovImg,
                        'albumUrl'       => (string)($ov['album_url'] ?? ''),
                        'isSingle'       => false,
                        'matchTitle'     => '',
                        'isOverride'     => true,
                    ]);
                }
            }
        } catch (Throwable $e) { /* tabla aún no existe → sin override */ }
    }

    /* ── Cache key NORMALIZADA agresiva ──
       Antes era `md5(lower(title) . '|' . lower(artist))` — variantes
       triviales del mismo track ("Song (Official Audio)" vs "Song",
       "Song feat. X" vs "Song", "Song - Remastered 2011" vs "Song")
       hashaban distinto y disparaban requests duplicadas a Spotify
       para el MISMO resultado. Strip ese ruido aquí dispara más hits
       sin tocar el ranking del backend (que sigue usando el title
       original para scoring). */
    $normalizeForKey = function (string $s): string {
        $s = mb_strtolower($s);
        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) $s = $tr->transliterate($s);
        }
        /* Strip "(Official ...)", "[HD]", "(Audio)", "(Lyric Video)",
           "(Live)", "(Remastered YYYY)", "(Single Version)", etc. */
        $s = preg_replace('/[\[\(](?:official|music|video|audio|hd|hq|lyric[s]?(?:\s+video)?|mv|m\/v|live(?:\s+performance)?|visualizer|extended|remaster(?:ed)?(?:\s+\d+)?|single(?:\s+version)?|radio(?:\s+edit)?|explicit|clean|stereo|mono)[\]\)\s\-\d]*/i', ' ', $s);
        /* Strip "feat. X" / "ft. X" hasta el siguiente paréntesis o final. */
        $s = preg_replace('/\s*(?:feat\.?|ft\.?|featuring)\s+[^\(\[\-]+/i', ' ', $s);
        /* Strip puntuación + collapse whitespace. */
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    };

    /* ── Lookup por VIDEO ID primero (#5) ──
       Si el cliente nos da el videoId del track de YouTube, buscamos
       primero un hit cacheado para ese videoId. Esto sobrevive a
       cambios del título (re-uploads, ediciones del playlist, etc.)
       y comparte cache entre usuarios distintos. Si hit → return
       inmediato sin tocar Spotify. */
    if ($videoId !== '') {
        $vidKey = 'album_track_v1_' . $videoId;
        $cachedVid = cacheGet($vidKey);
        if ($cachedVid !== null) {
            $decoded = json_decode($cachedVid, true);
            if (is_array($decoded)
                && empty($decoded['notFound'])
                && !empty($decoded['spotifyAlbumId'])
                && strpos((string)$decoded['spotifyAlbumId'], 'synthetic:') !== 0) {
                jsonResponse($decoded);
            }
        }
    }

    /* v5 — bumpeamos para invalidar los notFound:true cacheados con la
       key v4 (que no normalizaba el título — entradas tipo "Song
       (Official Audio)" no se reusaban para "Song"). */
    $titleN  = $normalizeForKey($title);
    $artistN = $normalizeForKey($artist);
    $cacheKey = 'album_lookup_v5_' . md5($titleN . '|' . $artistN);
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded)
            && empty($decoded['notFound'])
            && empty($decoded['isSynthetic'])
            && !empty($decoded['spotifyAlbumId'])
            && strpos((string)$decoded['spotifyAlbumId'], 'synthetic:') !== 0) {
            /* Solo aceptamos cache hit si tiene álbum REAL — un
               notFound o sintético legacy se reprocesa con la cascada. */
            /* Replica al cache por videoId para futuras consultas. */
            if ($videoId !== '') {
                cacheSet('album_track_v1_' . $videoId, $cached, 7 * 24 * 3600);
            }
            jsonResponse($decoded);
        }
    }

    /* ── Fallback al cache v4 ──
       El bump v4→v5 invalidó TODOS los hits previos. Si el cliente
       enviaba un track ya resuelto con v4, ahora cae aquí miss → fetch
       a Spotify. Cuando Spotify está rate-limited, eso devuelve 503 y
       no se ven los álbumes que SÍ se vieron antes. Para evitar la
       degradación: leemos el cache v4 (que sigue ahí 7 días), y si
       tiene un HIT REAL (no notFound, no synthetic) lo aceptamos y lo
       promovemos a v5 + por videoId. Los notFound de v4 NO se aceptan
       (la normalización de v5 puede convertirlos en hit). */
    $cacheKeyV4 = 'album_lookup_v4_' . md5(mb_strtolower($title) . '|' . mb_strtolower($artist));
    $cachedV4 = cacheGet($cacheKeyV4);
    if ($cachedV4 !== null) {
        $decodedV4 = json_decode($cachedV4, true);
        if (is_array($decodedV4)
            && empty($decodedV4['notFound'])
            && empty($decodedV4['isSynthetic'])
            && !empty($decodedV4['spotifyAlbumId'])
            && strpos((string)$decodedV4['spotifyAlbumId'], 'synthetic:') !== 0) {
            cacheSet($cacheKey, $cachedV4, 7 * 24 * 3600);
            if ($videoId !== '') {
                cacheSet('album_track_v1_' . $videoId, $cachedV4, 7 * 24 * 3600);
            }
            jsonResponse($decodedV4);
        }
    }

    /* ══════════════════════════════════════════════════════════════
       CASCADA DE FUENTES (iTunes → Deezer → Spotify)
       ──────────────────────────────────────────────────────────────
       Spotify queda como ÚLTIMO recurso para reducir al máximo nuestra
       presión sobre su API (causa de bans persistentes). iTunes y
       Deezer son endpoints públicos sin auth ni rate-limit estricto.

       Cada hit se cachea bajo el mismo cacheKey/videoId, así que la
       siguiente consulta se sirve del cache directamente — el orden de
       la cascada solo aplica al PRIMER lookup de cada track. */
    require_once __DIR__ . '/itunes-helpers.php';
    require_once __DIR__ . '/deezer-helpers.php';

    $persistAndReturn = function(array $result) use ($cacheKey, $videoId) {
        $payload = json_encode($result, JSON_UNESCAPED_UNICODE);
        cacheSet($cacheKey, $payload, 30 * 24 * 3600); /* 30 días */
        if ($videoId !== '') {
            cacheSet('album_track_v1_' . $videoId, $payload, 30 * 24 * 3600);
        }
        jsonResponse($result);
    };

    /* PRE-LIMPIEZA aplicada a iTunes/Deezer (no solo a Spotify como
       antes). Sin esto, títulos típicos de YouTube tipo "(Official
       Audio)", "[HD]", "feat. X", "- 2012 Remaster", caían fuera de
       los matches por similitud y la cascada terminaba en Spotify
       (que está banneado → 503).
       Artist con `;` (múltiples artistas separados) → tomamos solo
       el primero: las APIs buscan mejor con un único nombre y los
       restantes están casi siempre como "feat." dentro del título. */
    $cleanTitleCascade = preg_replace(
        '/[\[\(](?:official|music|video|audio|hd|hq|lyrics?|mv|m\/v|live|live performance|visualizer|extended|remaster(ed)?(\s+\d+)?)[\]\)\s\-]*/i',
        ' ', $title
    );
    $cleanTitleCascade = preg_replace('/\s*(?:feat\.?|ft\.?)\s+[^\(\[\-]+/i', ' ', (string)$cleanTitleCascade);
    /* Quita guiones flotantes que quedan tras quitar "- 2012 Remaster". */
    $cleanTitleCascade = preg_replace('/\s*-\s*$/', '', (string)$cleanTitleCascade);
    $cleanTitleCascade = trim(preg_replace('/\s{2,}/', ' ', (string)$cleanTitleCascade));
    if ($cleanTitleCascade === '') $cleanTitleCascade = $title;   /* fallback */
    $primaryArtist = trim((string)preg_replace('/[;,&].*$/', '', $artist));
    if ($primaryArtist === '') $primaryArtist = $artist;

    /* iTunes — cobertura altísima en repertorio occidental. */
    $itunes = itunesSearchAlbumForTrack($cleanTitleCascade, $primaryArtist);
    if ($itunes !== null) $persistAndReturn($itunes);

    /* Deezer — cubre los gaps de iTunes (electrónica, francés, etc.). */
    $deezer = deezerSearchAlbumForTrack($cleanTitleCascade, $primaryArtist);
    if ($deezer !== null) $persistAndReturn($deezer);

    /* ── Spotify ELIMINADO ──
       Antes este case caía a Spotify cuando iTunes/Deezer no encontraban
       el track. Spotify Web API está banneada permanentemente para esta
       cuenta — todo lo que hacíamos era pegarle a una API que no
       respondía y devolver 503 al cliente. Lo quitamos. Si ninguna
       fuente publica encuentra el álbum, cacheamos notFound (TTL corto:
       3h, por si el álbum aparece en iTunes/Deezer más tarde) y
       devolvemos 200 con el flag — el cliente trata notFound como
       "no se encontró" sin error, no como un fallo de red. */
    $notFound = ['notFound' => true];
    cacheSet($cacheKey, json_encode($notFound), 3 * 3600);
    if ($videoId !== '') {
        cacheSet('album_track_v1_' . $videoId, json_encode($notFound), 3 * 3600);
    }
    jsonResponse($notFound);
}

/* Lista las canciones de un álbum de Spotify dado su ID. NO resuelve
   videoIds de YouTube — eso lo hace el cliente con yt-search-batch (ya
   existente), así esta llamada es rápida y barata. Cache 7 días: los
   álbumes no cambian de tracklist.

   Devuelve: { name, artist, image, tracks: [{title, artist, duration}] } */
case 'album-tracks': {
    require_once __DIR__ . '/spotify-helpers.php';
    require_once __DIR__ . '/itunes-helpers.php';
    require_once __DIR__ . '/deezer-helpers.php';

    /* Acepta dos formas:
         ?key=itunes:1440834378  / ?key=deezer:6975097  / ?key=spotify:abc
         ?id=abc                 (legacy, asume Spotify)
       Esto permite que el viewer del álbum se sirva desde iTunes o
       Deezer sin tocar nunca a Spotify para los hits que vinieron de
       esas fuentes — exactamente lo que reduce nuestra presión a la
       API de Spotify al mínimo. */
    $key   = (string)($_GET['key'] ?? '');
    $idRaw = (string)($_GET['id']  ?? '');
    $source = ''; $albumId = '';
    if ($key !== '' && str_contains($key, ':')) {
        [$src, $rest] = explode(':', $key, 2);
        $source = strtolower(preg_replace('/[^a-z]/i', '', $src));
        $albumId = $rest;
    } elseif ($idRaw !== '') {
        /* Legacy: cliente viejo manda ?id=spotify_id. */
        $source = 'spotify';
        $albumId = $idRaw;
    }
    if ($albumId === '' || !in_array($source, ['spotify', 'itunes', 'deezer'], true)) {
        jsonError('key o id requeridos');
    }
    /* Sanitizamos según fuente — Spotify es alfanumérico, iTunes/Deezer
       son numéricos. */
    if ($source === 'spotify') $albumId = preg_replace('/[^A-Za-z0-9]/', '', $albumId);
    else                       $albumId = preg_replace('/[^0-9]/', '', $albumId);
    if ($albumId === '') jsonError('id inválido');

    $cacheKey = 'album_tracks_' . $source . '_' . $albumId;
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        $decoded = json_decode($cached, true);
        /* Solo servimos la caché si tiene PISTAS. Antes se cacheaban (30
           días) álbumes con tracklist VACÍA — p.ej. los que la tienda US de
           iTunes no listaba (japoneses como Masayoshi Takanaka). Esos
           álbumes que ya habías abierto se quedaban vacíos para siempre.
           Ignorando las cachés vacías se re-resuelven (ahora probando
           JP/GB/DE) y se vuelven a cachear con sus pistas reales. */
        if (is_array($decoded) && !empty($decoded['tracks'])) { jsonResponse($decoded); }
    }

    /* iTunes / Deezer: sin auth ni rate-limit estricto, fetch directo. */
    if ($source === 'itunes' || $source === 'deezer') {
        $r = ($source === 'itunes') ? itunesGetAlbumTracks($albumId) : deezerGetAlbumTracks($albumId);
        if ($r && !empty($r['tracks'])) {
            cacheSet($cacheKey, json_encode($r, JSON_UNESCAPED_UNICODE), 30 * 24 * 3600);
            jsonResponse($r);
        }
        /* El álbum existe en su fuente pero la búsqueda de pistas vino
           VACÍA (no disponible en esa store/región) o falló. Algunos
           álbumes salen en la búsqueda/página de artista pero al abrirlos
           no tenían pistas → ventana vacía. Reintentamos buscando el MISMO
           álbum por nombre+artista en iTunes/Deezer (la otra fuente suele
           tenerlo). Usamos el nombre del propio álbum si lo tenemos, o los
           hints que mande el cliente. */
        $fbName   = trim((string)(($r['name']   ?? '') ?: ($_GET['name']   ?? '')));
        $fbArtist = trim((string)(($r['artist'] ?? '') ?: ($_GET['artist'] ?? '')));
        if ($fbName !== '') {
            try {
                $fb = _albumTracksFallbackByName($fbName, $fbArtist, $cacheKey);
                if ($fb !== null && !empty($fb['tracks'])) jsonResponse($fb);
            } catch (Throwable $e) { /* cae al cierre de abajo */ }
        }
        /* Si teníamos el álbum (aunque sin pistas) lo devolvemos para que
           al menos se vea su nombre/portada; si no, error. NO lo cacheamos:
           una tracklist vacía no debe persistir (si mañana sí está
           disponible, queremos reintentar). */
        if ($r) {
            jsonResponse($r);
        }
        jsonError('No se pudo leer el álbum (' . $source . ')', 502);
    }

    /* ── Spotify ELIMINADO ──
       Antes intentábamos resolver el álbum directamente contra Spotify
       cuando la key era `spotify:*`. Ahora Spotify está fuera del
       proyecto: si el cliente nos da name + artist como hints,
       resolvemos el mismo álbum en iTunes/Deezer. Si no, devolvemos
       404 con un mensaje claro para que el cliente le pida al usuario
       que re-añada el álbum (los nuevos vendrán con itunes:/deezer:
       keys nativas). */
    $hintName   = trim((string)($_GET['name']   ?? ''));
    $hintArtist = trim((string)($_GET['artist'] ?? ''));
    if ($hintName !== '') {
        try {
            $fallback = _albumTracksFallbackByName($hintName, $hintArtist, $cacheKey);
            if ($fallback !== null) jsonResponse($fallback);
        } catch (Throwable $e) {
            /* No matar la respuesta con 500: caemos al jsonError de abajo. */
        }
    }
    jsonError('Este álbum era de Spotify y ya no se puede cargar. Vuelve a añadirlo desde una canción para que se enlace con iTunes/Deezer.', 404);
}

/* ─── Tidal ───────────────────────────────────────────────── */

case 'tidal-track': {
    require_once __DIR__ . '/spotify-helpers.php';   /* searchYouTubeVideo */
    require_once __DIR__ . '/tidal-helpers.php';
    $url = trim($_GET['url'] ?? ($_POST['url'] ?? ''));
    if (!$url) jsonError('URL requerida');
    $trackId = parseTidalTrackId($url);
    if (!$trackId) jsonError('URL de track de Tidal inválida');
    if (!TIDAL_CLIENT_ID || !TIDAL_CLIENT_SECRET) jsonError('Falta configurar las credenciales de Tidal (.env)', 503);
    $meta = tidalGetTrack($trackId);
    if (!$meta) jsonError('No se pudo obtener el track de Tidal', 502);
    /* Mismo helper que spotify-track: pasamos title/artist/duration
       para que escoja el mejor candidato (no el primero). */
    $video = searchYouTubeVideo(
        $meta['title'] . ' ' . $meta['artist'] . ' audio',
        ['title' => $meta['title'], 'artist' => $meta['artist'], 'duration' => (int)($meta['duration'] ?? 0)]
    );
    if (!$video) jsonError('No se encontró el vídeo en YouTube', 502);
    $t = _applySongOverrides($pdo, [[
        'videoId'  => $video['videoId'],
        'title'    => $meta['title'],
        'artist'   => $meta['artist'],
        'duration' => $video['duration'] ?: $meta['duration'],
    ]])[0];
    jsonResponse([
        'title'    => $t['title'],
        'artist'   => $t['artist'],
        'duration' => $t['duration'],
        'videoId'  => $t['videoId'],
    ]);
}

case 'tidal-playlist': {
    require_once __DIR__ . '/tidal-helpers.php';
    set_time_limit(120);
    $b   = jsonBody();
    $url = trim($b['url'] ?? ($_GET['url'] ?? ''));
    if (!$url) jsonError('URL requerida');
    $plId = parseTidalPlaylistId($url);
    if (!$plId) jsonError('URL de playlist de Tidal inválida');
    if (!TIDAL_CLIENT_ID || !TIDAL_CLIENT_SECRET) jsonError('Falta configurar las credenciales de Tidal (.env)', 503);
    $pl = tidalGetPlaylist($plId);
    if ($pl === null) jsonError('No se pudo obtener la playlist de Tidal (¿es pública?)', 502);
    if (empty($pl['tracks'])) jsonError('La playlist no tiene canciones o no es accesible', 404);
    /* Devuelve nombre + tracks SIN resolver (title/artist/duration).
       El cliente los resuelve a YouTube vía yt-search-batch (como el CSV). */
    jsonResponse(['name' => $pl['name'], 'tracks' => $pl['tracks']]);
}

/* ─── Import de playlist de YouTube ──────────────────────── */

case 'import-playlist': {
    $b   = jsonBody();
    $url = trim($b['url'] ?? '');
    $playlistId = '';
    if (preg_match('/[?&]list=([A-Za-z0-9_-]+)/', $url, $m)) {
        $playlistId = preg_replace('/[^A-Za-z0-9_-]/', '', $m[1]);
    } elseif (preg_match('/^[A-Za-z0-9_-]{10,}$/', $url)) {
        $playlistId = $url;
    }
    if (!$playlistId) jsonError('URL de playlist inválida');

    /* ── Helper HTTP unificado: cURL con file_get_contents como
          fallback. Algunos hosts (Hostinger entre ellos) tienen
          allow_url_fopen restringido o problemas de SSL con streams
          → cURL es más fiable. */
    $httpRequest = function($url, $method = 'GET', $body = null, $headers = []) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_ENCODING, '');  /* aceptar gzip */
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            /* No llamamos curl_close — deprecado en PHP 8.5. El handle
               se cierra automáticamente al salir de scope. */
            return ['code' => $code, 'body' => $res];
        }
        /* Fallback file_get_contents */
        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'timeout'         => 25,
                'ignore_errors'   => true,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'header'          => implode("\r\n", $headers),
                'content'         => $body,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $mm)) {
            $code = (int)$mm[1];
        }
        return ['code' => $code, 'body' => $res];
    };

    /* ──────────────────────────────────────────────────────────────
       APPROACH A — InnerTube API (el JSON interno de YouTube).
       Es lo que usa la propia web y herramientas como yt-dlp.
       Mucho más fiable que parsear HTML porque devuelve datos
       estructurados y sin marcadores que cambien con cada update
       del front. Soporta paginación vía `continuations`.

       INNERTUBE_API_KEY: clave pública del cliente WEB de YouTube.
       Está hardcoded en la propia web y es la misma para todos.
       Sin esta key la API devuelve 403. */
    $INNERTUBE_KEY     = 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8';
    $INNERTUBE_VERSION = '2.20240814.01.00';
    $innerTubeBrowse = function($browseId, $continuation = null) use ($httpRequest, $INNERTUBE_KEY, $INNERTUBE_VERSION) {
        $payload = [
            'context' => [
                'client' => [
                    'clientName'       => 'WEB',
                    'clientVersion'    => $INNERTUBE_VERSION,
                    'hl'               => 'en',
                    'gl'               => 'US',
                    'platform'         => 'DESKTOP',
                    'clientFormFactor' => 'UNKNOWN_FORM_FACTOR',
                ],
                'user'    => ['lockedSafetyMode' => false],
                'request' => ['useSsl' => true],
            ],
        ];
        if ($continuation) $payload['continuation'] = $continuation;
        else               $payload['browseId']     = $browseId;
        $r = $httpRequest(
            'https://www.youtube.com/youtubei/v1/browse?key=' . $INNERTUBE_KEY . '&prettyPrint=false',
            'POST',
            json_encode($payload),
            [
                'Content-Type: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language: en-US,en;q=0.9',
                'Accept: */*',
                'Origin: https://www.youtube.com',
                'Referer: https://www.youtube.com/',
                'X-YouTube-Client-Name: 1',
                'X-YouTube-Client-Version: ' . $INNERTUBE_VERSION,
            ]
        );
        return ['code' => $r['code'], 'data' => $r['body'] ? json_decode($r['body'], true) : null];
    };

    /* ¿Este nodo es un ítem de vídeo? YouTube usa DOS formatos: el
       clásico `playlistVideoRenderer` y el nuevo `lockupViewModel`
       (migración de 2024, hoy el habitual en web). Soportar solo el
       primero hacía que A devolviera 0 vídeos y se cayera al RSS, que
       tope en 15 canciones. */
    $isVideoNode = function($el) {
        if (!is_array($el)) return false;
        if (isset($el['playlistVideoRenderer'])) return true;
        if (isset($el['lockupViewModel'])
            && (($el['lockupViewModel']['contentType'] ?? '') === 'LOCKUP_CONTENT_TYPE_VIDEO')) return true;
        return false;
    };
    /* Primer `continuationCommand.token` dentro de un nodo (búsqueda
       recursiva acotada al elemento). */
    $findToken = function($el) use (&$findToken) {
        if (!is_array($el)) return null;
        if (isset($el['continuationCommand']['token'])) return $el['continuationCommand']['token'];
        foreach ($el as $v) {
            $t = $findToken($v);
            if ($t) return $t;
        }
        return null;
    };

    /* Walker recursivo: recolecta TODOS los vídeos (ambos formatos) y el
       token de continuación CORRECTO para paginar. Clave: el token válido
       es el que está en el MISMO array que los vídeos (el "load more" de
       la rejilla). YouTube incluye otros tokens (panel lateral, secciones)
       en arrays SIN vídeos que no paginan la lista; coger "el último"
       token de todo el árbol devolvía 0 en la página 2 y cortaba el
       import en la primera página. */
    $collectVideos = function($data) use (&$collectVideos, $isVideoNode, $findToken) {
        $out    = ['videos' => [], 'continuation' => null];
        $isList = function($a) { return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1)); };
        $walk = function($node) use (&$walk, &$out, $isVideoNode, $findToken, $isList) {
            if (!is_array($node)) return;
            if (isset($node['playlistVideoRenderer'])) {
                $out['videos'][] = $node['playlistVideoRenderer'];
            }
            if (isset($node['lockupViewModel'])
                && (($node['lockupViewModel']['contentType'] ?? '') === 'LOCKUP_CONTENT_TYPE_VIDEO')) {
                $out['videos'][] = $node['lockupViewModel'];
            }
            if ($isList($node)) {
                $hasVideos = false; $listToken = null;
                foreach ($node as $el) {
                    if ($isVideoNode($el)) { $hasVideos = true; continue; }
                    $t = $findToken($el);
                    if ($t) $listToken = $t;
                }
                if ($hasVideos && $listToken) $out['continuation'] = $listToken;
            }
            foreach ($node as $v) if (is_array($v)) $walk($v);
        };
        $walk($data);
        return $out;
    };

    $videos = [];
    $diag   = [];   /* códigos HTTP de cada intento, para el mensaje
                       de error si todos fallan. */

    /* ── A: InnerTube browse — La browseId de una playlist es "VL"+id. */
    $browseRes = $innerTubeBrowse('VL' . $playlistId);
    $diag[]    = 'innertube:' . ($browseRes['code'] ?: '?');
    if (is_array($browseRes['data'])) {
        $col = $collectVideos($browseRes['data']);
        $videos = $col['videos'];
        $cont = $col['continuation'];
        $hops = 0;
        while ($cont && $hops < 50) {
            $next = $innerTubeBrowse(null, $cont);
            if (!is_array($next['data'])) break;
            $col2 = $collectVideos($next['data']);
            if (!$col2['videos']) break;
            $videos = array_merge($videos, $col2['videos']);
            $cont = $col2['continuation'];
            $hops++;
        }
    }

    /* ── B: scraping del HTML clásico. */
    if (empty($videos)) {
        $r = $httpRequest(
            'https://www.youtube.com/playlist?list=' . $playlistId,
            'GET',
            null,
            [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language: en-US,en;q=0.9',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Cookie: CONSENT=YES+cb; SOCS=CAESEwgDEgk0ODE3Nzk3MjQaAmVuIAEaBgiA_LyaBg; PREF=hl=en&gl=US',
            ]
        );
        $diag[] = 'html:' . ($r['code'] ?: '?');
        $html = $r['body'] ?? '';
        if ($html) {
            $markers = ['var ytInitialData = ', 'window["ytInitialData"] = ', 'ytInitialData = '];
            foreach ($markers as $marker) {
                $pos = strpos($html, $marker);
                if ($pos === false) continue;
                $pos += strlen($marker);
                $endCandidates = [';</script>', ";\n", ';var ', ';window['];
                $endPos = false;
                foreach ($endCandidates as $end) {
                    $cand = strpos($html, $end, $pos);
                    if ($cand !== false && ($endPos === false || $cand < $endPos)) $endPos = $cand;
                }
                if ($endPos === false) continue;
                $data = json_decode(substr($html, $pos, $endPos - $pos), true);
                if (!$data) continue;
                $col = $collectVideos($data);
                if (!empty($col['videos'])) {
                    $videos = $col['videos'];
                    break;
                }
            }
        }
    }

    /* ── C: feed RSS público (último recurso — solo 15 tracks máximo,
          pero útil para verificar que el servidor PUEDE alcanzar
          YouTube). Si los anteriores devolvieron 0 vídeos pero ninguno
          dio error de red, esto demuestra que el problema es de
          parseo, no de conectividad. */
    if (empty($videos)) {
        $r = $httpRequest(
            'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $playlistId,
            'GET',
            null,
            ['User-Agent: Mozilla/5.0', 'Accept: application/atom+xml,application/xml']
        );
        $diag[] = 'rss:' . ($r['code'] ?: '?');
        if (!empty($r['body']) && preg_match_all('#<yt:videoId>([A-Za-z0-9_-]{11})</yt:videoId>.*?<title>([^<]+)</title>.*?<name>([^<]+)</name>#s', $r['body'], $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                $videos[] = [
                    'videoId' => $row[1],
                    'title'   => ['simpleText' => $row[2]],
                    'shortBylineText' => ['simpleText' => $row[3]],
                ];
            }
        }
    }

    if (empty($videos)) {
        $msg = 'No se pudo leer la playlist [' . implode(' ', $diag) . ']. ';
        if (in_array('innertube:403', $diag) || in_array('html:403', $diag)) {
            $msg .= 'YouTube bloqueó la petición (IP del servidor restringida).';
        } elseif (in_array('innertube:0', $diag) && in_array('html:0', $diag)) {
            $msg .= 'El servidor no puede alcanzar youtube.com (firewall outbound).';
        } else {
            $msg .= '¿Está pública la playlist?';
        }
        jsonError($msg, 502);
    }

    $tracks = [];
    foreach ($videos as $v) {
        if (isset($v['contentId']) || isset($v['metadata']['lockupMetadataViewModel'])) {
            /* ── Formato nuevo: lockupViewModel. */
            $vidRaw = $v['contentId'] ?? '';
            $title  = $v['metadata']['lockupMetadataViewModel']['title']['content'] ?? '';
            /* Artista = primera "parte" de la primera fila de metadata
               (el nombre del canal). */
            $artist = '';
            $rows = $v['metadata']['lockupMetadataViewModel']['metadata']['contentMetadataViewModel']['metadataRows'] ?? [];
            foreach ($rows as $row) {
                foreach (($row['metadataParts'] ?? []) as $mp) {
                    if (!empty($mp['text']['content'])) { $artist = $mp['text']['content']; break 2; }
                }
            }
            /* Duración: el badge del thumbnail con formato m:ss / h:mm:ss. */
            $findDur = function($node) use (&$findDur) {
                if (!is_array($node)) return '';
                if (isset($node['text']) && is_string($node['text'])
                    && preg_match('/^\d+(:\d{2})+$/', $node['text'])) return $node['text'];
                foreach ($node as $c) {
                    $r = $findDur($c);
                    if ($r !== '') return $r;
                }
                return '';
            };
            $lenText = $findDur($v['contentImage'] ?? []);
        } else {
            /* ── Formato clásico: playlistVideoRenderer / feed RSS. */
            $vidRaw  = $v['videoId'] ?? '';
            $title   = $v['title']['runs'][0]['text']
                    ?? $v['title']['simpleText']
                    ?? '';
            $artist  = $v['shortBylineText']['runs'][0]['text']
                    ?? $v['shortBylineText']['simpleText']
                    ?? '';
            $lenText = $v['lengthText']['simpleText']
                    ?? $v['lengthText']['runs'][0]['text']
                    ?? '';
        }
        $vid = preg_replace('/[^A-Za-z0-9_-]/', '', $vidRaw);
        if (strlen($vid) !== 11) continue;
        $artist = trim(preg_replace('/\s*-\s*topic$/i', '', $artist));
        $duration = 0;
        if ($lenText) {
            $parts = explode(':', $lenText);
            if (count($parts) === 2)      $duration = (int)$parts[0] * 60 + (int)$parts[1];
            elseif (count($parts) === 3)  $duration = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        $tracks[] = [
            'videoId'  => $vid,
            'title'    => substr(strip_tags($title),  0, 200),
            'artist'   => substr(strip_tags($artist), 0, 200),
            'duration' => $duration,
        ];
    }
    if (empty($tracks)) jsonError('No se encontraron canciones válidas en la playlist', 404);
    /* Correcciones manuales: la playlist de YouTube importada entra con los
       títulos/artistas/links corregidos por la comunidad. */
    $tracks = _applySongOverrides($pdo, $tracks);
    jsonResponse(['tracks' => $tracks]);
}

/* ─── TV LINK (código de emparejamiento) ──────────────────
   El móvil llama get-tv-code → recibe un código de 6 dígitos.
   La TV (sin sesión) lo introduce en tv.php → tv.php valida con
   la tabla `tv_link_codes` y crea sesión persistente. */
case 'get-tv-code': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    /* Auto-crea tabla idempotente. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tv_link_codes (
            code VARCHAR(6) PRIMARY KEY,
            user_key VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at)
        ) DEFAULT CHARSET=utf8mb4
    ");
    /* Limpia códigos caducados (>5 min). */
    $pdo->exec("DELETE FROM tv_link_codes WHERE created_at < NOW() - INTERVAL 5 MINUTE");
    /* Genera código único — random_int es seguro criptográficamente. */
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code = sprintf('%06d', random_int(0, 999999));
        $stmt = $pdo->prepare("SELECT 1 FROM tv_link_codes WHERE code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) break;
    }
    $pdo->prepare("INSERT INTO tv_link_codes (code, user_key) VALUES (?, ?)")
        ->execute([$code, $userKey]);
    jsonResponse(['code' => $code, 'expiresIn' => 300]);
}

/* ─── NOW PLAYING (TV companion view) ──────────────────────
   El shell del móvil publica su estado de reproducción aquí y
   tv.php hace polling cada segundo para mostrarlo en la TV.
   Tabla auto-creada al primer save. */
case 'save-now-playing': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    if (!$b) jsonError('Datos inválidos');

    /* Crea la tabla si no existe — idempotente. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS now_playing (
            user_id    INT PRIMARY KEY,
            track_json TEXT NOT NULL,
            is_playing TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4
    ");

    /* Sanitiza y guarda solo los campos que la TV necesita. */
    $track = [
        'videoId'  => isset($b['videoId']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $b['videoId']) : '',
        'title'    => mb_substr(trim($b['title']  ?? ''), 0, 200),
        'artist'   => mb_substr(trim($b['artist'] ?? ''), 0, 200),
        'plName'   => mb_substr(trim($b['plName'] ?? ''), 0, 100),
        'position' => isset($b['position']) ? max(0, (float)$b['position']) : 0,
        'duration' => isset($b['duration']) ? max(0, (float)$b['duration']) : 0,
    ];
    $playing = !empty($b['isPlaying']) ? 1 : 0;

    $pdo->prepare("INSERT INTO now_playing (user_id, track_json, is_playing)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE track_json=VALUES(track_json), is_playing=VALUES(is_playing)")
        ->execute([$uid, json_encode($track, JSON_UNESCAPED_UNICODE), $playing]);
    jsonResponse(['ok' => true]);
}

/* ─── Letras (LRCLIB) ───────────────────────────────────── */
/* ─── Debug logger del módulo lyric-video del fullscreen player ───
   POSTea {msg} → escribe línea con timestamp en logs/lyrics-debug.log
   Solo para diagnosticar bugs de render/sync de letras. */
case 'client-log': {
    $b = jsonBody();
    $msg = is_string($b['msg'] ?? null) ? substr($b['msg'], 0, 2000) : '';
    if ($msg === '') jsonResponse(['ok' => true]);
    $logDir  = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/lyrics-debug.log';
    /* Rotate al sobrepasar 512KB (evita que crezca eternamente). */
    if (file_exists($logFile) && filesize($logFile) > 512 * 1024) {
        @rename($logFile, $logFile . '.1');
    }
    $stamp = date('Y-m-d H:i:s.') . substr(microtime(false), 2, 3);
    $line  = "[$stamp] {$userKey}: {$msg}\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    jsonResponse(['ok' => true]);
}

case 'get-lyrics': {
    require_once __DIR__ . '/lyrics-helpers.php';
    $title    = trim($_GET['title']    ?? '');
    $artist   = trim($_GET['artist']   ?? '');
    $duration = max(0, (int)($_GET['duration'] ?? 0));
    if ($title === '') jsonError('Falta title', 400);
    $r = getLyrics($title, $artist, $duration);
    if (!$r) {
        jsonResponse(['ok' => true, 'found' => false]);
    }
    jsonResponse([
        'ok'       => true,
        'found'    => true,
        'plain'    => $r['plain'],
        'synced'   => $r['synced'],
        'matched'  => [
            'title'  => $r['matched_title'],
            'artist' => $r['matched_artist'],
        ],
    ]);
}

case 'get-now-playing': {
    /* Connection: close se setea al inicio del script (antes de
       requireAuth) para que aplique también a 401. */
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    /* Si la tabla no existe (nunca se llamó save), devolvemos vacío. */
    try {
        $stmt = $pdo->prepare("SELECT track_json, is_playing, UNIX_TIMESTAMP(updated_at) AS ts
                               FROM now_playing WHERE user_id = ?");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $row = null; }
    /* `age` = segundos transcurridos en el servidor desde el último
       save-now-playing. Permite que la TV compute la posición REAL del
       móvil compensando la latencia de red móvil→servidor. Sin esto,
       el polling de la TV usa una snapshot que puede estar varios
       segundos atrasada → desincronización progresiva. */
    $payload = $row
        ? [
            'ok'        => true,
            'track'     => json_decode($row['track_json'], true),
            'isPlaying' => !!$row['is_playing'],
            'updatedAt' => (int)$row['ts'],
            'age'       => max(0, time() - (int)$row['ts']),
            'serverNow' => time(),
            'user'      => $u['label'],
        ]
        : ['ok' => true, 'track' => null, 'isPlaying' => false];

    /* Modo iframe — para Smart TVs en las que ni XHR ni <script src>
       funcionan tras el primer poll. El padre lee el JSON desde
       iframe.contentDocument tras iframe.onload. Sin script dentro del
       iframe (no corren en navegaciones dinámicas en algunos browsers). */
    if (isset($_GET['frame'])) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body><pre id="d">' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
        exit;
    }

    jsonResponse($payload);
}

/* ─── Autocompletado de álbumes ─────────────────────────────────────
   Mientras el usuario escribe el nombre del álbum correcto, devolvemos
   candidatos (iTunes + Deezer) para que elija con un clic. */
case 'search-albums': {
    require_once __DIR__ . '/itunes-helpers.php';
    require_once __DIR__ . '/deezer-helpers.php';
    $q      = trim((string)($_GET['q']      ?? ''));
    $artist = trim((string)($_GET['artist'] ?? ''));
    if (mb_strlen($q) < 2) jsonResponse(['ok' => true, 'results' => []]);
    jsonResponse(['ok' => true, 'results' => _searchAlbumsByName($q, $artist, 40)]);
}

/* ─── Búsqueda de CANCIONES en YouTube ──────────────────────────────
   Devuelve una lista de resultados (videoId + título + canal + duración)
   para una query libre. Reutiliza el parser de candidatos de búsqueda. */
case 'yt-search': {
    require_once __DIR__ . '/spotify-helpers.php';
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) jsonResponse(['ok' => true, 'results' => []]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 10, 'ignore_errors' => true,
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\nAccept-Language: en-US,en;q=0.9\r\n",
    ]]);
    $html = @file_get_contents('https://www.youtube.com/results?search_query=' . urlencode($q), false, $ctx);
    $out  = [];
    if ($html) {
        foreach (ytExtractCandidates($html, 12) as $c) {
            if (empty($c['videoId'])) continue;
            $artist = isset($c['channel']) ? trim((string)preg_replace('/\s*-\s*topic$/i', '', $c['channel'])) : '';
            $out[] = [
                'videoId'  => $c['videoId'],
                'title'    => (string)($c['title'] ?? ''),
                'artist'   => $artist,
                'duration' => (int)($c['duration'] ?? 0),
            ];
        }
    }
    jsonResponse(['ok' => true, 'results' => $out]);
}

/* ─── Búsqueda de ARTISTAS (iTunes + Deezer) ────────────────────────
   Devuelve {source, artistId, name, image}. */
case 'search-artists': {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) jsonResponse(['ok' => true, 'results' => []]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub/1.0\r\n",
    ]]);
    $seen = []; $out = [];
    /* iTunes — entity=musicArtist NO trae imagen del artista. La sacamos
       después de la carátula de su primer álbum (en paralelo) para que
       ningún artista quede sin imagen y no se descarte luego. */
    $itunesIdx = [];   /* artistId => índice en $out */
    $raw = @file_get_contents('https://itunes.apple.com/search?term=' . rawurlencode($q) . '&entity=musicArtist&limit=10', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) foreach (($d['results'] ?? []) as $r) {
            $id = (string)($r['artistId'] ?? '');
            $nm = (string)($r['artistName'] ?? '');
            if ($id === '' || $nm === '') continue;
            $k = 'itunes:' . $id; if (isset($seen[$k])) continue; $seen[$k] = 1;
            $out[] = ['source' => 'itunes', 'artistId' => $id, 'name' => $nm, 'image' => '', 'imageBig' => ''];
            $itunesIdx[$id] = count($out) - 1;
        }
    }
    /* Enriquecimiento de imágenes iTunes: carátula del primer álbum de
       cada artista, en paralelo con curl_multi. */
    if ($itunesIdx && function_exists('curl_multi_init')) {
        $mh = curl_multi_init(); $hs = [];
        foreach ($itunesIdx as $aid => $_i) {
            $ch = curl_init('https://itunes.apple.com/lookup?id=' . $aid . '&entity=album&limit=1');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['User-Agent: MelonHub/1.0'],
            ]);
            curl_multi_add_handle($mh, $ch); $hs[$aid] = $ch;
        }
        $active = null;
        do { $st = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 1.0); } while ($active && $st == CURLM_OK);
        foreach ($hs as $aid => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            if (!$body) continue;
            $dd = json_decode($body, true);
            if (!is_array($dd)) continue;
            foreach (($dd['results'] ?? []) as $rr) {
                if (($rr['wrapperType'] ?? '') !== 'collection') continue;
                $art = (string)($rr['artworkUrl100'] ?? '');
                if ($art) {
                    $out[$itunesIdx[$aid]]['image']    = str_replace('100x100', '300x300', $art);
                    $out[$itunesIdx[$aid]]['imageBig'] = str_replace('100x100', '1000x1000', $art);
                }
                break;
            }
        }
        curl_multi_close($mh);
    }
    /* Deezer — trae imagen del artista. */
    $raw = @file_get_contents('https://api.deezer.com/search/artist?q=' . rawurlencode($q) . '&limit=12', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) foreach (($d['data'] ?? []) as $r) {
            $id = (string)($r['id'] ?? '');
            $nm = (string)($r['name'] ?? '');
            if ($id === '' || $nm === '') continue;
            $k = 'deezer:' . $id; if (isset($seen[$k])) continue; $seen[$k] = 1;
            $out[] = [
                'source'   => 'deezer',
                'artistId' => $id,
                'name'     => $nm,
                'image'    => (string)($r['picture_medium'] ?? ($r['picture'] ?? '')),
                'imageBig' => (string)($r['picture_xl'] ?? ($r['picture_big'] ?? ($r['picture_medium'] ?? ''))),
                /* Deezer no da "oyentes mensuales"; usamos nº de fans como
                   aproximación (no hay banner del artista en la API). */
                'fans'     => (int)($r['nb_fan'] ?? 0),
            ];
        }
    }
    jsonResponse(['ok' => true, 'results' => $out]);
}

/* ─── Álbumes de un ARTISTA ─────────────────────────────────────────
   Dado {source, artistId} devuelve sus álbumes {albumKey, name, image,
   year} para la página de artista. */
case 'artist-albums': {
    $source   = strtolower(preg_replace('/[^a-z]/i', '', (string)($_GET['source'] ?? '')));
    $artistId = preg_replace('/[^0-9]/', '', (string)($_GET['artistId'] ?? ''));
    if ($artistId === '' || !in_array($source, ['itunes', 'deezer'], true)) {
        jsonError('source/artistId inválidos');
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub/1.0\r\n",
    ]]);
    $albums = []; $seen = [];
    if ($source === 'itunes') {
        $raw = @file_get_contents('https://itunes.apple.com/lookup?id=' . $artistId . '&entity=album&limit=100', false, $ctx);
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) foreach (($d['results'] ?? []) as $r) {
                if (($r['wrapperType'] ?? '') !== 'collection') continue;
                $id = (string)($r['collectionId'] ?? '');
                if ($id === '' || isset($seen[$id])) continue; $seen[$id] = 1;
                $img = (string)($r['artworkUrl100'] ?? '');
                if ($img) $img = str_replace('100x100', '300x300', $img);
                /* iTunes no da record_type → lo inferimos del nº de pistas:
                   1-2 = single, 3-6 = EP, resto = álbum. */
                $tc = (int)($r['trackCount'] ?? 0);
                $type = $tc <= 2 ? 'single' : ($tc <= 6 ? 'ep' : 'album');
                $albums[] = [
                    'albumKey' => 'itunes:' . $id,
                    'name'     => (string)($r['collectionName'] ?? ''),
                    'image'    => $img,
                    'year'     => substr((string)($r['releaseDate'] ?? ''), 0, 4),
                    'type'     => $type,
                ];
            }
        }
    } else { /* deezer */
        $raw = @file_get_contents('https://api.deezer.com/artist/' . $artistId . '/albums?limit=100', false, $ctx);
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) foreach (($d['data'] ?? []) as $r) {
                $id = (string)($r['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue; $seen[$id] = 1;
                $rt = strtolower((string)($r['record_type'] ?? 'album'));
                $type = in_array($rt, ['single', 'ep'], true) ? $rt : 'album';
                $albums[] = [
                    'albumKey' => 'deezer:' . $id,
                    'name'     => (string)($r['title'] ?? ''),
                    'image'    => (string)($r['cover_medium'] ?? ($r['cover_big'] ?? '')),
                    'year'     => substr((string)($r['release_date'] ?? ''), 0, 4),
                    'type'     => $type,
                ];
            }
        }
    }
    jsonResponse(['ok' => true, 'albums' => $albums]);
}

/* ─── Top tracks de un ARTISTA (Deezer, por nombre) ─────────────────
   Para la sección "Popular" de la ventana de artista. Devuelve
   {title, artist, duration, image}. Se reproducen resolviendo a YouTube
   en el cliente al hacer click. */
case 'artist-top': {
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') jsonResponse(['ok' => true, 'tracks' => []]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub/1.0\r\n",
    ]]);
    $artistId = '';
    $raw = @file_get_contents('https://api.deezer.com/search/artist?q=' . rawurlencode($name) . '&limit=1', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d) && !empty($d['data'][0]['id'])) $artistId = (string)$d['data'][0]['id'];
    }
    $tracks = [];
    if ($artistId !== '') {
        $raw = @file_get_contents('https://api.deezer.com/artist/' . $artistId . '/top?limit=5', false, $ctx);
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) foreach (($d['data'] ?? []) as $t) {
                $tracks[] = [
                    'title'    => (string)($t['title'] ?? ''),
                    'artist'   => (string)($t['artist']['name'] ?? $name),
                    'duration' => (int)($t['duration'] ?? 0),
                    'image'    => (string)($t['album']['cover_medium'] ?? ($t['album']['cover'] ?? '')),
                    /* `rank` de Deezer = índice de popularidad (no son
                       reproducciones reales de Spotify, pero sirve de proxy
                       para la columna de "reproducciones"). */
                    'rank'     => (int)($t['rank'] ?? 0),
                ];
            }
        }
    }
    /* Aplica las correcciones manuales (overrides) por título+artista:
       si el usuario corrigió el álbum de una de estas canciones, usamos
       su carátula corregida en lugar de la de Deezer. */
    if ($tracks) {
        try {
            $norm = function($s){ $s = mb_strtolower((string)$s); $s = preg_replace('/[^a-z0-9]+/u', ' ', $s); return trim((string)preg_replace('/\s+/', ' ', $s)); };
            /* Traemos los overrides con imagen. Para los antiguos (sin
               song_title, creados antes de guardar el título), puenteamos
               con el nombre/artista que quedó en `music_recent` para ese
               videoId — así una corrección hecha en el reproductor también
               se refleja en las populares del artista. */
            $rows = [];
            try {
                $rows = $pdo->query(
                    "SELECT o.song_title, o.song_artist, o.album_image,
                            (SELECT r.name   FROM music_recent r  WHERE r.item_type='song' AND r.item_key=o.video_id LIMIT 1) AS recent_name,
                            (SELECT r2.artist FROM music_recent r2 WHERE r2.item_type='song' AND r2.item_key=o.video_id LIMIT 1) AS recent_artist
                       FROM song_album_overrides o
                      WHERE o.album_image <> ''"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                /* music_recent puede no existir → consulta simple. */
                $rows = $pdo->query("SELECT song_title, song_artist, album_image FROM song_album_overrides WHERE album_image <> ''")->fetchAll(PDO::FETCH_ASSOC);
            }
            $ovs = [];
            foreach ($rows as $ov) {
                $title  = ($ov['song_title']  ?? '') !== '' ? $ov['song_title']  : ($ov['recent_name']   ?? '');
                $artistOv = ($ov['song_artist'] ?? '') !== '' ? $ov['song_artist'] : ($ov['recent_artist'] ?? '');
                if (trim((string)$title) === '') continue;
                $ovs[] = ['t' => $norm($title), 'a' => $norm($artistOv), 'img' => $ov['album_image']];
            }
            if ($ovs) foreach ($tracks as &$t) {
                $tt = $norm($t['title']); $ta = $norm($t['artist']);
                if ($tt === '') continue;
                foreach ($ovs as $ov) {
                    if ($ov['t'] === '') continue;
                    /* Título de Deezer (limpio) vs el guardado (ruidoso,
                       de YouTube): aceptamos si uno contiene al otro.
                       El artista debe coincidir si ambos lo tienen. */
                    $titleHit  = ($ov['t'] === $tt) || str_contains($ov['t'], $tt) || str_contains($tt, $ov['t']);
                    $artistHit = ($ov['a'] === '' || $ta === '' || $ov['a'] === $ta || str_contains($ov['a'], $ta) || str_contains($ta, $ov['a']));
                    if ($titleHit && $artistHit) { $t['image'] = $ov['img']; break; }
                }
            }
            unset($t);
        } catch (Throwable $e) { /* tabla puede no existir */ }
    }
    jsonResponse(['ok' => true, 'tracks' => $tracks]);
}

/* ─── Corregir una canción ──────────────────────────────────────────
   El usuario corrige los datos de un track: álbum, título, artista y/o
   el link de YouTube (videoId). Todo se guarda por videoId (original) en
   `song_album_overrides`. A partir de entonces:
     - `find-album` devuelve SIEMPRE el álbum corregido para esa canción.
     - al cargar/importar playlists se sustituyen título/artista/videoId.
   Es global (todos los usuarios) y persistente. El álbum es OPCIONAL:
   se puede corregir solo el título, el artista o el link. */
case 'report-album': {
    $uid = uidByKey($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $videoId   = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['videoId'] ?? ''));
    if (strlen($videoId) !== 11) jsonError('videoId inválido');
    $songTitle = mb_substr(trim((string)($b['title'] ?? '')), 0, 255);
    $artist    = mb_substr(trim((string)($b['artist'] ?? '')), 0, 255);

    /* Nuevo link de YouTube → extraemos el videoId. Acepta URL completa
       (watch?v=, youtu.be/, /shorts/, /embed/) o el id de 11 chars. */
    $newVideoId = '';
    $linkIn = trim((string)($b['newVideoId'] ?? $b['videoLink'] ?? ''));
    if ($linkIn !== '') {
        if (preg_match('~(?:v=|youtu\.be/|/shorts/|/embed/)([A-Za-z0-9_-]{11})~', $linkIn, $vm)) {
            $newVideoId = $vm[1];
        } elseif (preg_match('~^[A-Za-z0-9_-]{11}$~', $linkIn)) {
            $newVideoId = $linkIn;
        } else {
            jsonError('El link de YouTube no es válido.');
        }
        /* Si el id "nuevo" coincide con el original, no es un cambio. */
        if ($newVideoId === $videoId) $newVideoId = '';
    }

    require_once __DIR__ . '/itunes-helpers.php';
    require_once __DIR__ . '/deezer-helpers.php';

    /* Álbum (OPCIONAL). Dos modos:
         a) El usuario eligió una sugerencia → llega `albumKey` (itunes:ID /
            deezer:ID) + nombre/artista/imagen. Match directo.
         b) Texto libre → `albumName` y lo resolvemos por nombre.
       Si no se aporta ni albumKey ni albumName, NO se corrige el álbum. */
    $match = null;
    $albumKeyIn = trim((string)($b['albumKey'] ?? ''));
    $albumName  = trim((string)($b['albumName'] ?? ''));
    if ($albumKeyIn !== '' && preg_match('~^(itunes|deezer):(\d+)$~', $albumKeyIn, $mk)) {
        $src = $mk[1]; $aid = $mk[2];
        $nm  = trim((string)($b['albumName']   ?? ''));
        $art = trim((string)($b['albumArtist'] ?? ''));
        $img = trim((string)($b['albumImage']  ?? ''));
        if ($nm === '' || $img === '') {
            $meta = $src === 'itunes' ? itunesGetAlbumTracks($aid) : deezerGetAlbumTracks($aid);
            if ($meta) {
                if ($nm  === '') $nm  = (string)($meta['name']   ?? '');
                if ($art === '') $art = (string)($meta['artist'] ?? '');
                if ($img === '') $img = (string)($meta['image']  ?? '');
            }
        }
        if ($nm !== '') $match = ['source' => $src, 'albumId' => $aid, 'name' => $nm, 'artist' => $art, 'image' => $img];
    } elseif ($albumName !== '') {
        $match = _resolveAlbumByName($albumName, $artist);
        if (!$match) {
            jsonError('No se encontró un álbum con ese nombre. Escríbelo más exacto (puedes incluir el artista).', 404);
        }
    }

    /* Sin álbum: campos de álbum vacíos (corrección de solo metadatos). */
    $aSource = $match['source']  ?? '';
    $aId     = $match['albumId'] ?? '';
    $aKey    = $match ? ($match['source'] . ':' . $match['albumId']) : '';
    $aName   = $match['name']   ?? '';
    $aArtist = $match['artist'] ?? '';
    $aImage  = $match['image']  ?? '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS song_album_overrides (
        video_id     VARCHAR(11) PRIMARY KEY,
        source       VARCHAR(10)  NOT NULL,
        album_id     VARCHAR(40)  NOT NULL,
        album_key    VARCHAR(60)  NOT NULL,
        album_name   VARCHAR(255) NOT NULL DEFAULT '',
        album_artist VARCHAR(255) NOT NULL DEFAULT '',
        album_image  VARCHAR(500) NOT NULL DEFAULT '',
        album_url    VARCHAR(500) NOT NULL DEFAULT '',
        song_title   VARCHAR(255) NOT NULL DEFAULT '',
        song_artist  VARCHAR(255) NOT NULL DEFAULT '',
        new_video_id VARCHAR(11)  NOT NULL DEFAULT '',
        created_by   INT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    /* Columnas para tablas ya existentes (idempotente). */
    try { if (!$pdo->query("SHOW COLUMNS FROM song_album_overrides LIKE 'song_title'")->fetch())   $pdo->exec("ALTER TABLE song_album_overrides ADD COLUMN song_title VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    try { if (!$pdo->query("SHOW COLUMNS FROM song_album_overrides LIKE 'song_artist'")->fetch())  $pdo->exec("ALTER TABLE song_album_overrides ADD COLUMN song_artist VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    try { if (!$pdo->query("SHOW COLUMNS FROM song_album_overrides LIKE 'new_video_id'")->fetch()) $pdo->exec("ALTER TABLE song_album_overrides ADD COLUMN new_video_id VARCHAR(11) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    $pdo->prepare("INSERT INTO song_album_overrides
        (video_id, source, album_id, album_key, album_name, album_artist, album_image, album_url, song_title, song_artist, new_video_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE source=VALUES(source), album_id=VALUES(album_id), album_key=VALUES(album_key),
            album_name=VALUES(album_name), album_artist=VALUES(album_artist), album_image=VALUES(album_image),
            album_url=VALUES(album_url), song_title=VALUES(song_title), song_artist=VALUES(song_artist),
            new_video_id=VALUES(new_video_id), created_by=VALUES(created_by)")
        ->execute([$videoId, $aSource, $aId, $aKey,
                   $aName, $aArtist, $aImage, '', $songTitle, $artist, $newVideoId, $uid]);

    /* Refleja la corrección al instante en "escuchados recientemente"
       (global, por videoId): imagen del álbum + título/artista corregidos. */
    try {
        _ensureRecentTable($pdo);
        $sets = []; $vals = [];
        if ($aImage   !== '') { $sets[] = 'image = ?';  $vals[] = $aImage;   }
        if ($songTitle !== '') { $sets[] = 'name = ?';   $vals[] = $songTitle; }
        if ($artist    !== '') { $sets[] = 'artist = ?'; $vals[] = $artist;    }
        if ($sets) {
            $vals[] = $videoId;
            $pdo->prepare("UPDATE music_recent SET " . implode(', ', $sets) . " WHERE item_type = 'song' AND item_key = ?")
                ->execute($vals);
        }
    } catch (Throwable $e) { /* tabla puede no existir aún */ }

    jsonResponse(['ok' => true,
        'newVideoId' => $newVideoId,
        'title'      => $songTitle,
        'artist'     => $artist,
        'album'      => $match ? [
            'notFound'       => false,
            'source'         => $aSource,
            'albumKey'       => $aKey,
            'spotifyAlbumId' => '',
            'albumName'      => $aName,
            'albumArtist'    => $aArtist,
            'matchArtist'    => $aArtist,
            'albumImage'     => $aImage,
            'albumUrl'       => '',
            'isSingle'       => false,
            'matchTitle'     => '',
            'isOverride'     => true,
        ] : null,
    ]);
}

default:
    jsonError('Acción no válida: ' . $action, 400);
}

/* Busca un álbum por NOMBRE (+ artista opcional como pista) en iTunes y
   luego Deezer. Devuelve ['source','albumId','name','artist','image'] del
   mejor match, o null. Usado por `report-album` para resolver la
   corrección que escribe el usuario. */
function _resolveAlbumByName(string $name, string $artist): ?array {
    $name = trim($name);
    if ($name === '') return null;

    $norm = function (string $s): string {
        $s = mb_strtolower($s);
        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) { $r = $tr->transliterate($s); if ($r !== null) $s = $r; }
        }
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s));
    };
    $nName   = $norm($name);
    $nArtist = $norm($artist);
    if ($nName === '') return null;

    /* Puntúa un candidato: el nombre DEBE coincidir (exacto o substring);
       si coincide también el artista, sube. Sin coincidencia de nombre →
       descartado (-1). */
    $score = function (string $candName, string $candArtist) use ($norm, $nName, $nArtist): int {
        $cN = $norm($candName);
        if ($cN === '') return -1;
        $s = 0;
        if ($cN === $nName)                                          $s += 100;
        elseif (str_contains($cN, $nName) || str_contains($nName, $cN)) $s += 60;
        else return -1;
        if ($nArtist !== '') {
            $cA = $norm($candArtist);
            if ($cA === $nArtist)                                            $s += 40;
            elseif ($cA !== '' && (str_contains($cA, $nArtist) || str_contains($nArtist, $cA))) $s += 20;
        }
        return $s;
    };

    $term = $artist !== '' ? ($name . ' ' . $artist) : $name;
    $ctx  = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub/1.0\r\n",
    ]]);

    $bestScore = -1; $best = null;

    /* iTunes — entity=album. */
    $raw = @file_get_contents('https://itunes.apple.com/search?term=' . rawurlencode($term) . '&entity=album&limit=15', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) foreach (($d['results'] ?? []) as $r) {
            $sc = $score((string)($r['collectionName'] ?? ''), (string)($r['artistName'] ?? ''));
            if ($sc > $bestScore) {
                $img = (string)($r['artworkUrl100'] ?? '');
                if ($img) $img = str_replace('100x100', '600x600', $img);
                $best = [
                    'source'  => 'itunes',
                    'albumId' => (string)($r['collectionId'] ?? ''),
                    'name'    => (string)($r['collectionName'] ?? ''),
                    'artist'  => (string)($r['artistName'] ?? ''),
                    'image'   => $img,
                ];
                $bestScore = $sc;
            }
        }
    }

    /* Deezer — solo si iTunes no dio un match perfecto de nombre. */
    if ($bestScore < 100) {
        $raw = @file_get_contents('https://api.deezer.com/search/album?q=' . rawurlencode($term) . '&limit=15', false, $ctx);
        if ($raw) {
            $d = json_decode($raw, true);
            if (is_array($d)) foreach (($d['data'] ?? []) as $r) {
                $sc = $score((string)($r['title'] ?? ''), (string)($r['artist']['name'] ?? ''));
                if ($sc > $bestScore) {
                    $best = [
                        'source'  => 'deezer',
                        'albumId' => (string)($r['id'] ?? ''),
                        'name'    => (string)($r['title'] ?? ''),
                        'artist'  => (string)($r['artist']['name'] ?? ''),
                        'image'   => (string)($r['cover_xl'] ?? ($r['cover_big'] ?? '')),
                    ];
                    $bestScore = $sc;
                }
            }
        }
    }

    if (!$best || $best['albumId'] === '') return null;
    return $best;
}

/* Devuelve una LISTA de álbumes candidatos para autocompletado, buscando
   por nombre (+ artista como pista) en iTunes y Deezer. Cada item:
   ['source','albumId','albumKey','name','artist','image']. Ordenados por
   relevancia (coincidencia de nombre/artista) y deduplicados. */
function _searchAlbumsByName(string $name, string $artist, int $limit = 40): array {
    $name = trim($name);
    if ($name === '') return [];

    $norm = function (string $s): string {
        $s = mb_strtolower($s);
        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) { $r = $tr->transliterate($s); if ($r !== null) $s = $r; }
        }
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s));
    };
    $nName   = $norm($name);
    $nArtist = $norm($artist);
    if ($nName === '') return [];

    /* Score SUAVE — NO descarta nada (los que no casan salen al final).
       Prioridad:
         1º (SIEMPRE lo que más puntúa): que el TÍTULO del álbum coincida
            con lo que escribió el usuario (exacto > empieza por > contiene).
         2º: que el ARTISTA del álbum coincida con el artista actualmente
            asignado a la canción.
       Los rangos garantizan que cualquier coincidencia de título (mín.
       300) pese más que cualquier coincidencia de artista (máx. 100). */
    $score = function (string $candName, string $candArtist) use ($norm, $nName, $nArtist): int {
        $cN = $norm($candName); $s = 0;
        if ($cN === $nName)                                  $s += 1000;
        elseif ($cN !== '' && str_starts_with($cN, $nName))  $s += 600;
        elseif ($cN !== '' && (str_contains($cN, $nName) || str_contains($nName, $cN))) $s += 300;
        if ($nArtist !== '') {
            $cA = $norm($candArtist);
            if ($cA === $nArtist)                                                $s += 100;
            elseif ($cA !== '' && (str_contains($cA, $nArtist) || str_contains($nArtist, $cA))) $s += 40;
        }
        return $s;
    };

    /* Buscamos SOLO por título. Si metiéramos el artista en el término,
       las APIs filtrarían por él y ocultarían álbumes con el título
       correcto de otros artistas. El artista solo se usa para ordenar. */
    $term = $name;
    $ctx  = stream_context_create(['http' => [
        'timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub/1.0\r\n",
    ]]);

    $out = [];   /* albumKey => [item + _score] */
    $add = function(array $item, int $sc) use (&$out) {
        $k = $item['albumKey'];
        if (!isset($out[$k]) || $sc > $out[$k]['_score']) { $item['_score'] = $sc; $out[$k] = $item; }
    };

    /* iTunes. */
    $raw = @file_get_contents('https://itunes.apple.com/search?term=' . rawurlencode($term) . '&entity=album&limit=50', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) foreach (($d['results'] ?? []) as $r) {
            $id = (string)($r['collectionId'] ?? '');
            if ($id === '') continue;
            $img = (string)($r['artworkUrl100'] ?? '');
            if ($img) $img = str_replace('100x100', '300x300', $img);
            $add([
                'source'   => 'itunes',
                'albumId'  => $id,
                'albumKey' => 'itunes:' . $id,
                'name'     => (string)($r['collectionName'] ?? ''),
                'artist'   => (string)($r['artistName'] ?? ''),
                'image'    => $img,
            ], $score((string)($r['collectionName'] ?? ''), (string)($r['artistName'] ?? '')));
        }
    }

    /* Deezer. */
    $raw = @file_get_contents('https://api.deezer.com/search/album?q=' . rawurlencode($term) . '&limit=50', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) foreach (($d['data'] ?? []) as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id === '') continue;
            $add([
                'source'   => 'deezer',
                'albumId'  => $id,
                'albumKey' => 'deezer:' . $id,
                'name'     => (string)($r['title'] ?? ''),
                'artist'   => (string)($r['artist']['name'] ?? ''),
                'image'    => (string)($r['cover_medium'] ?? ($r['cover_big'] ?? '')),
            ], $score((string)($r['title'] ?? ''), (string)($r['artist']['name'] ?? '')));
        }
    }

    $items = array_values($out);
    usort($items, function($a, $b) { return $b['_score'] <=> $a['_score']; });
    $items = array_slice($items, 0, $limit);
    foreach ($items as &$it) unset($it['_score']);
    return $items;
}

/* Fallback de album-tracks cuando el albumKey original es spotify:* y
   Spotify está banneado. Busca el álbum por NOMBRE + artista en iTunes
   primero, luego Deezer; si encuentra, devuelve su tracklist usando la
   misma estructura que el case principal. Cachea el resultado bajo la
   key original (`album_tracks_spotify_<id>`) para que la próxima petición
   se sirva desde cache sin re-resolver. Devuelve null si nada matchea. */
function _albumTracksFallbackByName(string $name, string $artist, string $origCacheKey): ?array {
    require_once __DIR__ . '/cache-helpers.php';
    require_once __DIR__ . '/itunes-helpers.php';
    require_once __DIR__ . '/deezer-helpers.php';

    /* Normaliza para matching tolerante: lowercase, strip puntuación,
       diacritics y colapsa whitespace. Sin esto "Ultrakill: Chaos /
       Order" vs "Ultrakill Chaos Order" no matcheaban. */
    $norm = function(string $s): string {
        $s = mb_strtolower($s);
        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) { $r = $tr->transliterate($s); if ($r !== null) $s = $r; }
        }
        /* Mantiene unicode (japonés, etc.) pero quita puntuación ASCII. */
        $s = preg_replace('/[\(\)\[\]\{\}\:\;\,\.\?\!\-\_\/\\\\&\|]/u', ' ', (string)$s);
        $s = preg_replace('/\s+/u', ' ', (string)$s);
        return trim((string)$s);
    };
    /* Variantes del nombre: original, sin texto entre paréntesis, y
       palabras clave principales — para que "Ultrakill: Chaos / Order
       (Original Game Soundtrack)" matchee "Ultrakill: Chaos / Order". */
    $nameVariants = array_values(array_unique(array_filter([
        $norm($name),
        $norm((string)preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $name)),
    ])));
    /* Match tolerante: substring entre nombre normalizado y collectionName
       normalizado en CUALQUIER dirección. */
    $matches = function(string $candidate) use ($nameVariants, $norm): bool {
        $cN = $norm($candidate);
        if ($cN === '') return false;
        foreach ($nameVariants as $nv) {
            if ($nv === '') continue;
            if ($nv === $cN) return true;
            if (mb_strpos($cN, $nv) !== false) return true;
            if (mb_strpos($nv, $cN) !== false) return true;
        }
        return false;
    };

    /* Genera queries: name+artist, name solo (por si artist confunde), y
       variante sin paréntesis. Probamos cada una hasta encontrar match. */
    $queries = array_values(array_unique(array_filter([
        $artist !== '' ? ($name . ' ' . $artist) : $name,
        $name,
        (string)preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $name),
    ])));

    $ctx = stream_context_create(['http' => [
        'timeout' => 6, 'ignore_errors' => true,
        'header'  => "User-Agent: MelonHub/1.0\r\n",
    ]]);

    foreach ($queries as $q) {
        if (trim($q) === '') continue;
        /* iTunes search por álbum. entity=album devuelve collectionId. */
        $url = 'https://itunes.apple.com/search?term=' . rawurlencode($q)
             . '&entity=album&limit=10&country=US';
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : null;
        if (is_array($data) && !empty($data['results'])) {
            foreach ($data['results'] as $r) {
                $collId = (string)($r['collectionId'] ?? '');
                if ($collId === '' || !$matches((string)($r['collectionName'] ?? ''))) continue;
                $tracks = itunesGetAlbumTracks($collId);
                if ($tracks && !empty($tracks['tracks'])) {
                    cacheSet($origCacheKey, json_encode($tracks, JSON_UNESCAPED_UNICODE), 30 * 24 * 3600);
                    return $tracks;
                }
            }
        }
        /* Deezer search por álbum. */
        $dUrl = 'https://api.deezer.com/search/album?q=' . rawurlencode($q) . '&limit=10';
        $dRaw = @file_get_contents($dUrl, false, $ctx);
        $dData = $dRaw ? json_decode($dRaw, true) : null;
        if (is_array($dData) && !empty($dData['data'])) {
            foreach ($dData['data'] as $a) {
                $aid = (string)($a['id'] ?? '');
                if ($aid === '' || !$matches((string)($a['title'] ?? ''))) continue;
                $tracks = deezerGetAlbumTracks($aid);
                if ($tracks && !empty($tracks['tracks'])) {
                    cacheSet($origCacheKey, json_encode($tracks, JSON_UNESCAPED_UNICODE), 30 * 24 * 3600);
                    return $tracks;
                }
            }
        }
    }
    return null;
}
