<?php
/* ───────────────────────────────────────────────────────────
   PROFILE API — router único de la app Perfil (SQL)
   ───────────────────────────────────────────────────────────
   Acciones (vía ?action= o $_POST['action']):

   GET   ?action=get-profile
   GET   ?action=get-lists
   GET   ?action=get-profile-notifs
   GET   ?action=get-followers
   GET   ?action=get-item-collabs&category=...&itemId=...
   GET   ?action=get-unread-chats
   GET   ?action=get-messages&with=USERKEY
   GET   ?action=view-user&user=USERKEY
   GET   ?action=melon-reviews&period=year|recent|alltime&cat=...&type=album|song
   GET   ?action=resolve-music-item&url=...&itemType=song|album

   POST  ?action=add-post              { text }
   POST  ?action=delete-post           { id }
   POST  ?action=save-quote            { quote }
   POST  ?action=wall-comment          { user, text }        (perfil nuevo: perfil2.php)
   POST  ?action=delete-wall-comment   { id }
   POST  ?action=save-showcases        { showcases: [{type, config}] }
   GET   ?action=game-catalog&q=…[&cat=movies|series|books]
   GET   ?action=my-playlists          (Playlist destacada)
   GET   ?action=steam-game-match&title=…   (Juego destacado)
   GET   ?action=my-screenshots
   POST  ?action=gallery-screenshots   { items: [{ driveId, caption, game }] }
   POST  ?action=steam-disconnect      {}                    (Steam: steam.php)
   GET   ?action=steam-games
   GET   ?action=frames                                    (marcos: marcos.php)
   GET   ?action=my-frames
   POST  ?action=set-frame             { slug }            ('' = sin marco)
   POST  ?action=streak-claim          {}                  (racha: racha.php)
   GET   ?action=invites                                   (invitaciones: racha.php)
   GET   ?action=steam-game-achievements&appid=N
   POST  ?action=save-info             { bio, pronouns, birthday, country, steam, ... }
   POST  ?action=save-lists            { category, items[] }
   POST  ?action=toggle-follow         { targetUser }  (ahora solicitudes, ver amigos.php)
   GET   ?action=friends / friend-search&q=…
   GET   ?action=friend-avisos         → solicitudes pendientes y aceptadas sin avisar (haro)
   POST  ?action=friend-request|friend-accept|friend-reject|friend-cancel|friend-remove { user }
   POST  ?action=toggle-post-like      { targetUser, postId }
   POST  ?action=mark-notifs-read      {}
   POST  ?action=send-message          { to, text }
   POST  ?action=send-item-invite      { toUser, category, itemId }
   POST  ?action=respond-item-invite   { inviteId, action: 'accept'|'reject'|'dismiss' }
   POST  ?action=leave-collab          { action: 'leave'|'remove', category, itemId, collaboratorUser? }
   POST  ?action=notify-review         { category, itemTitle, mtype? }
   POST  ?action=play-music-item       { ... }
   POST  ?action=fix-cover             { category, title, image }
   GET   ?action=get-vapid-public-key
   POST  ?action=save-push-subscription   { endpoint, p256dh, auth }
   POST  ?action=delete-push-subscription { endpoint }

   ALMACENAMIENTO: 100% SQL.
     - profile / posts / post_likes / follows / notifications
     - list_items / list_item_collaborators / item_invites
     - chats / messages

   El SSE item-notifications-stream.php lee directamente de item_invites.
   ─────────────────────────────────────────────────────────── */
/* Lo que el escritorio pide solo cada pocos segundos (chats sin leer,
   presencia, notificaciones, heartbeat): si la BD está saturada, un solo
   reintento corto (melon_pdo en assets/config.php) en vez de ocupar un
   proceso PHP esperando; ya volverá a preguntar. */
if (in_array($_GET['action'] ?? $_POST['action'] ?? '', ['get-unread-chats', 'presence', 'get-profile-notifs', 'heartbeat', 'friend-avisos'], true)) {
    define('MELON_DB_RAPIDO', true);
}
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/php/esquema.php';   /* melon_esquema */
require_once dirname(__DIR__) . '/php/timbre.php';    /* rt_usuario: avisos al escritorio */
require_once __DIR__ . '/presencia.php';                /* pf_presencia, pf_presenciaArchivo */
melon_esquemaVigilar();

$u       = requireAuth();
$userKey = $u['key'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

/* Amigos estilo Steam (solicitudes): assets/profile/amigos.php. */
require_once __DIR__ . '/amigos.php';
amigos_ensure($pdo);

/* Tablas y columnas de la API del perfil: una vez por versión de este
   archivo (melon_esquema, assets/php/esquema.php), no en cada petición.
   Eran ~13 sentencias (CREATE, SHOW COLUMNS y ALTER que fallan porque la
   columna ya existe) en cada llamada, y el escritorio llama a esta API
   ~20 veces por minuto (chats sin leer, presencia, notificaciones). */
melon_esquema($pdo, 'perfil', __FILE__, function (PDO $pdo) {
    /* Auto-migración: tabla user_presence — un row por usuario, last_at
       actualizado vía heartbeat cada 30s desde la shell. Online = last_at
       en los últimos 60s. `state` = 'online' | 'away' — el cliente lo
       manda en cada heartbeat según inactividad / pantalla bloqueada. */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_presence (
                user_id INT PRIMARY KEY,
                last_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                state   VARCHAR(10) NOT NULL DEFAULT 'online',
                INDEX idx_last_at (last_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        /* ALTER idempotente para BDs antiguas que tenían la tabla sin
           la columna `state`. */
        try {
            $hasState = $pdo->query("SHOW COLUMNS FROM user_presence LIKE 'state'")->fetch();
            if (!$hasState) {
                $pdo->exec("ALTER TABLE user_presence ADD COLUMN state VARCHAR(10) NOT NULL DEFAULT 'online' AFTER last_at");
            }
        } catch (Throwable $_) {}
    } catch (Throwable $e) { /* silencio */ }

    /* Auto-migración: tabla chat_mutes — silenciado de notificaciones de un
       chat. mute_until = timestamp futuro (o muy lejano para indefinido). */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_mutes (
                user_id      INT NOT NULL,
                with_user_id INT NOT NULL,
                mute_until   TIMESTAMP NOT NULL,
                PRIMARY KEY (user_id, with_user_id),
                INDEX idx_until (mute_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) { /* silencio */ }

    /* Auto-migración: tabla chat_nicknames — apodo que MI usuario asignó al
       otro lado del chat. Reemplaza el label visible (label original oculto). */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_nicknames (
                user_id      INT NOT NULL,
                with_user_id INT NOT NULL,
                nickname     VARCHAR(60) NOT NULL,
                PRIMARY KEY (user_id, with_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) { /* silencio */ }

    /* Auto-migración: columnas edited_at + deleted_at en messages para
       soportar editar (con indicador "(editado)") y borrado soft. Se
       intentan ALTER por separado — si ya existen lanza SQLSTATE 42S21
       que ignoramos. */
    try { $pdo->exec("ALTER TABLE messages ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL"); }
    catch (Throwable $e) { /* ya existe */ }
    try { $pdo->exec("ALTER TABLE messages ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL"); }
    catch (Throwable $e) { /* ya existe */ }
    /* runtime del item: minutos (pelis) o nº de capítulos (series/libros).
       Se pone AL CREAR el item (autorrellenable). La moda entre los items
       del mismo título (varios usuarios) es la que se muestra en reseñas. */
    try { $pdo->exec("ALTER TABLE list_items ADD COLUMN runtime INT NULL DEFAULT NULL"); }
    catch (Throwable $e) { /* ya existe */ }
    /* Cumpleaños del perfil (fecha completa). La edad sale de aquí; la
       columna `age` (edad escrita a mano) solo queda para quien aún no ha
       puesto el cumpleaños. Los amigos mutuos lo ven como recordatorio
       anual en el calendario (assets/couple/api.php, cumplesDeAmigos). */
    try { $pdo->exec("ALTER TABLE profile ADD COLUMN birthday DATE NULL DEFAULT NULL"); }
    catch (Throwable $e) { /* ya existe */ }
    /* Capítulos de series — COMPARTIDOS por título (series_key = título en
       minúsculas). Cualquier usuario añade/quita capítulos. La duración va
       en minutos. El estado (visto + reseña) es POR USUARIO. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series_episodes (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            series_key  VARCHAR(200) NOT NULL,
            position    INT NOT NULL DEFAULT 0,
            title       VARCHAR(255) NOT NULL,
            image       VARCHAR(2000) NULL,
            duration    INT NULL,            /* minutos */
            created_by  INT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_series (series_key, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Temporadas: cada capítulo pertenece a una temporada (entero, por defecto
       1). Compartidas por título igual que los capítulos. */
    try { $pdo->exec("ALTER TABLE series_episodes ADD COLUMN season INT NOT NULL DEFAULT 1"); }
    catch (Throwable $e) { /* ya existe */ }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series_episode_state (
            episode_id  INT NOT NULL,
            user_id     INT NOT NULL,
            watched     TINYINT NOT NULL DEFAULT 0,
            stars       DECIMAL(2,1) NULL,
            comment     TEXT NULL,
            reviewed_at TIMESTAMP NULL,
            PRIMARY KEY (episode_id, user_id),
            INDEX idx_ep (episode_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Libros: enlace a un PDF COMPARTIDO por título (book_key = título en
       minúsculas). Cualquier usuario lo pone y todos los que tengan ese libro
       lo abren con el botón "Leer". */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_pdfs (
            book_key   VARCHAR(200) PRIMARY KEY,
            url        VARCHAR(2000) NOT NULL,
            set_by     INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
});

/* ── user_key → usuarios.id (cacheado por petición) ──────── */
function pf_uid(PDO $pdo, string $userKey): ?int {
    static $cache = [];
    if (array_key_exists($userKey, $cache)) return $cache[$userKey];
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $cache[$userKey] = $id ? (int)$id : null;
}

/* Plantillas para mantener compat con el contrato JSON original */
function pf_emptyProfile(): array {
    return ['quote'=>'','posts'=>[],'bio'=>'','pronouns'=>'','age'=>'','birthday'=>'','country'=>'',
            'steam'=>'','discord'=>'','twitter'=>'','instagram'=>'','following'=>[]];
}
function pf_emptyLists(): array { return ['movies'=>[],'series'=>[],'books'=>[],'games'=>[],'music'=>[]]; }

/* Lee la fila de profile o devuelve plantilla vacía. */
function pf_loadProfileRow(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT quote, bio, pronouns, age, birthday, country,
                                   steam, discord, twitter, instagram
                           FROM profile WHERE user_id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];
    /* Normaliza a string (los varchar pueden venir NULL) */
    foreach ($row as $k => $v) if ($v === null) $row[$k] = '';
    return $row;
}

/* Carga los posts del usuario con sus likes (lista de user_keys). */
function pf_loadPostsWithLikes(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT id, text, image_url AS imageUrl, UNIX_TIMESTAMP(created_at) AS createdAt
                           FROM posts WHERE user_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$uid]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$posts) return [];
    $ids = array_column($posts, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT pl.post_id, u.user_key
                           FROM post_likes pl JOIN usuarios u ON pl.user_id = u.id
                           WHERE pl.post_id IN ($place)");
    $stmt->execute($ids);
    $likesByPost = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $likesByPost[(int)$r['post_id']][] = $r['user_key'];
    }
    /* Comentarios por post: autor (key + label) + texto + timestamp */
    $stmt = $pdo->prepare(
        "SELECT c.id, c.post_id, c.text,
                UNIX_TIMESTAMP(c.created_at) AS createdAt,
                u.user_key AS authorKey, u.label AS authorLabel
         FROM post_comments c
         JOIN usuarios u ON c.user_id = u.id
         WHERE c.post_id IN ($place)
         ORDER BY c.created_at ASC, c.id ASC"
    );
    $stmt->execute($ids);
    $commentsByPost = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $commentsByPost[(int)$r['post_id']][] = [
            'id'          => (int)$r['id'],
            'text'        => $r['text'],
            'createdAt'   => (int)$r['createdAt'],
            'authorKey'   => $r['authorKey'],
            'authorLabel' => $r['authorLabel'],
        ];
    }

    foreach ($posts as &$p) {
        $p['id']        = (int)$p['id'];
        $p['createdAt'] = (int)$p['createdAt'];
        $p['likes']     = $likesByPost[(int)$p['id']] ?? [];
        $p['comments']  = $commentsByPost[(int)$p['id']] ?? [];
    }
    return $posts;
}

/* Carga la lista de user_keys a los que el usuario sigue. */
function pf_loadFollowing(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT u.user_key FROM follows f
                           JOIN usuarios u ON f.followee_id = u.id
                           WHERE f.follower_id = ? ORDER BY f.created_at ASC");
    $stmt->execute([$uid]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/* Devuelve el perfil completo del usuario en el formato JSON antiguo. */
function pf_buildFullProfile(PDO $pdo, int $uid): array {
    $p = array_merge(pf_emptyProfile(), pf_loadProfileRow($pdo, $uid));
    $p['posts']     = pf_loadPostsWithLikes($pdo, $uid);
    $p['following'] = pf_loadFollowing($pdo, $uid);
    /* age viene como int en BD, el frontend lo trataba como string */
    $p['age'] = ($p['age'] === '' || $p['age'] === null) ? '' : (string)$p['age'];
    /* Con cumpleaños, la edad se calcula (la escrita a mano ya no se
       edita). Fechas raras de filas viejas ('0000-00-00') = sin cumpleaños. */
    $bd = pf_birthdayOrNull((string)$p['birthday']);
    $p['birthday'] = $bd ?? '';
    if ($bd !== null) {
        $p['age'] = (string)(new DateTimeImmutable($bd))->diff(new DateTimeImmutable('today'))->y;
    }
    return $p;
}

/* 'AAAA-MM-DD' válida, desde 1900 y no en el futuro; si no, null. */
function pf_birthdayOrNull(string $s): ?string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    if ((int)$m[1] < 1900 || $s > date('Y-m-d')) return null;
    return $s;
}

/* Devuelve un item de lista (sin id, sin owner) en formato API.
   Los campos opcionales solo se incluyen si tienen valor. */
function pf_rowToItem(array $r, ?string $sharedFromUserKey, array $collabs): array {
    $item = [
        'id'    => (int)$r['id'],
        'title' => $r['title'],
        'image' => $r['image'] ?? '',
    ];
    if ($r['category'] === 'music') {
        $item['type']     = $r['music_type'] ?: 'song';
        $item['artist']   = $r['artist']     ?? '';
        $item['featured'] = (bool)($r['featured'] ?? 0);
        foreach (['yt_id'=>'ytId','spotify_id'=>'spotifyId',
                  'yt_playlist_id'=>'ytPlaylistId','spotify_album_id'=>'spotifyAlbumId'] as $sc => $jc) {
            if (!empty($r[$sc])) $item[$jc] = $r[$sc];
        }
    } else {
        $item['status'] = $r['status'] ?: 'pending';
        /* completed_at: marca el momento en que el item pasó a estado
           "completed". Solo se incluye si el status es completed Y
           tiene un timestamp guardado. El cliente lo usa para mostrar
           "Completado el [fecha]". */
        if ($item['status'] === 'completed' && !empty($r['completed_at_ts'])) {
            $item['completedAt'] = (int)$r['completed_at_ts'];
        }
    }
    /* Review POR USUARIO (tabla list_item_reviews). pf_loadAllLists
       inyecta my_review_* en la fila por LEFT JOIN, así pf_rowToItem
       resuelve la review del usuario actual sin necesidad de otra query.
       Si no hay LEFT JOIN (caller legacy), nos lleva al fallback de las
       columnas viejas list_items.review_* (review del owner, para no
       romper antiguos consumidores). */
    if (array_key_exists('my_review_stars', $r)) {
        if ($r['my_review_stars'] !== null) {
            $item['review'] = [
                'stars'   => (float)$r['my_review_stars'],
                'comment' => (string)($r['my_review_comment'] ?? ''),
            ];
            if (!empty($r['my_reviewed_at_ts'])) {
                $item['review']['reviewedAt'] = (int)$r['my_reviewed_at_ts'];
            }
        }
    } elseif ($r['review_stars'] !== null) {
        $item['review'] = [
            'stars'   => (float)$r['review_stars'],
            'comment' => (string)($r['review_comment'] ?? ''),
        ];
        if (!empty($r['reviewed_at_ts'])) {
            $item['review']['reviewedAt'] = (int)$r['reviewed_at_ts'];
        }
    }
    if (isset($r['runtime']) && $r['runtime'] !== null) $item['runtime'] = (int)$r['runtime'];
    if (!empty($collabs)) $item['collaborators'] = $collabs;
    if ($sharedFromUserKey)  $item['sharedFrom']    = $sharedFromUserKey;
    return $item;
}

/* Carga TODAS las listas del usuario (propias + las compartidas con él
   por otros). Devuelve la misma estructura que el JSON antiguo.
   Las reviews salen de list_item_reviews y son POR USUARIO: cada uno ve
   y modifica su propia review. Antes vivían en list_items.review_* y
   eran "compartidas" — al reseñar un item colaborativo se sobrescribía
   la review del otro. */
function pf_loadAllLists(PDO $pdo, int $uid): array {
    $lists = pf_emptyLists();
    /* Propias */
    $stmt = $pdo->prepare("SELECT i.*,
                                  UNIX_TIMESTAMP(i.reviewed_at)  AS reviewed_at_ts,
                                  UNIX_TIMESTAMP(i.completed_at) AS completed_at_ts,
                                  u.user_key AS shared_from_key,
                                  myr.stars AS my_review_stars,
                                  myr.comment AS my_review_comment,                                  UNIX_TIMESTAMP(myr.reviewed_at) AS my_reviewed_at_ts
                           FROM list_items i
                           LEFT JOIN usuarios u ON i.shared_from = u.id
                           LEFT JOIN list_item_reviews myr
                                ON myr.item_id = i.id AND myr.user_id = ?
                           WHERE i.owner_id = ?
                           ORDER BY i.id ASC");
    $stmt->execute([$uid, $uid]);
    $ownRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Las que comparten conmigo: estoy como colaborador → la entrada
       aparece en mi lista con sharedFrom apuntando al owner. */
    $stmt = $pdo->prepare("SELECT i.*,
                                  UNIX_TIMESTAMP(i.reviewed_at)  AS reviewed_at_ts,
                                  UNIX_TIMESTAMP(i.completed_at) AS completed_at_ts,
                                  ou.user_key AS owner_key,
                                  myr.stars AS my_review_stars,
                                  myr.comment AS my_review_comment,                                  UNIX_TIMESTAMP(myr.reviewed_at) AS my_reviewed_at_ts
                           FROM list_item_collaborators c
                           JOIN list_items i ON c.item_id = i.id
                           JOIN usuarios ou ON i.owner_id = ou.id
                           LEFT JOIN list_item_reviews myr
                                ON myr.item_id = i.id AND myr.user_id = ?
                           WHERE c.user_id = ?
                           ORDER BY i.id ASC");
    $stmt->execute([$uid, $uid]);
    $sharedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Cargar colaboradores de TODOS los items (propios + compartidos)
       en una sola query bulk. Antes sólo se cargaban los de los
       propios → en los compartidos el invitado veía únicamente el
       avatar del host, no el resto de invitados.
       Excluimos al usuario actual (c.user_id <> ?) para no duplicar
       su propio avatar al lado del host en items compartidos. */
    $allIds = array_merge(
        array_column($ownRows, 'id'),
        array_column($sharedRows, 'id')
    );
    $collabsByItem = [];
    if (!empty($allIds)) {
        $place = implode(',', array_fill(0, count($allIds), '?'));
        $sql = "SELECT c.item_id, u.user_key
                FROM list_item_collaborators c
                JOIN usuarios u ON c.user_id = u.id
                WHERE c.item_id IN ($place) AND c.user_id <> ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($allIds, [$uid]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $collabsByItem[(int)$r['item_id']][] = $r['user_key'];
        }
    }

    foreach ($ownRows as $r) {
        $cat = $r['category'];
        $lists[$cat][] = pf_rowToItem($r, null, $collabsByItem[(int)$r['id']] ?? []);
    }
    foreach ($sharedRows as $r) {
        $cat = $r['category'];
        /* En items compartidos, `sharedFrom` es el host (owner) y
           `collaborators` son los OTROS invitados (la query ya filtró
           al usuario actual, así que su propio avatar no se duplica). */
        $lists[$cat][] = pf_rowToItem($r, $r['owner_key'], $collabsByItem[(int)$r['id']] ?? []);
    }
    return $lists;
}

/* Inserta una notificación en `notifications`. fromUser puede ser null.
   Timbre 'notif': su escritorio pide get-profile-notifs al verlo
   (assets/php/timbre.php; antes lo pedía cada 30 s). */
function pf_addNotif(PDO $pdo, int $toUid, string $type, ?int $fromUid, array $extra = []): void {
    if ($fromUid !== null && $fromUid === $toUid) return; /* no auto-notifs */
    $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, payload, is_read)
                   VALUES (?, ?, ?, ?, 0)")
        ->execute([$toUid, $type, $fromUid, json_encode($extra ?: new stdClass(), JSON_UNESCAPED_UNICODE)]);
    rt_usuario($toUid, 'notif');
}

/* Borra notifs que matcheen un tipo + payload parcial. Útil para deshacer
   un follow/like cuando el usuario revierte la acción. */
function pf_deleteNotifsMatching(PDO $pdo, int $toUid, string $type, ?int $fromUid, array $payloadEq = []): void {
    $sql = "SELECT id, payload FROM notifications WHERE user_id = ? AND type = ?";
    $params = [$toUid, $type];
    if ($fromUid !== null) { $sql .= " AND from_user_id = ?"; $params[] = $fromUid; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $toDelete = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (empty($payloadEq)) { $toDelete[] = $r['id']; continue; }
        $p = json_decode($r['payload'] ?: '{}', true) ?: [];
        $match = true;
        foreach ($payloadEq as $k => $v) {
            if (($p[$k] ?? null) != $v) { $match = false; break; }
        }
        if ($match) $toDelete[] = $r['id'];
    }
    if (!empty($toDelete)) {
        $place = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM notifications WHERE id IN ($place)")->execute($toDelete);
        rt_usuario($toUid, 'notif');
    }
}

/* Inserta un item_invites de tipo "status" (item-accepted, collab-left,
   collab-removed, item-rejected) para que le llegue al cliente (timbre
   'items': su escritorio pide item-invites al verlo). */
function pf_pushItemStatus(PDO $pdo, int $toUid, int $fromUid, ?int $itemId, string $type, array $itemMeta): void {
    if ($toUid === $fromUid) return;
    rt_usuario($toUid, 'items');
    $pdo->prepare("INSERT INTO item_invites
        (to_user_id, from_user_id, type, item_id, category, item_title, item_image, item_music_type, item_artist)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $toUid, $fromUid, $type, $itemId,
            $itemMeta['category'],
            $itemMeta['title']  ?? '',
            $itemMeta['image']  ?? null,
            $itemMeta['music_type'] ?? null,
            $itemMeta['artist'] ?? null,
        ]);
}

/* Notifica a TODOS los participantes de un item compartido (host +
   colaboradores) menos al editor, para que refresquen sus listas en vivo
   cuando alguien edita el item. Colapsa notifs 'item-edited' pendientes
   del mismo item/destinatario para no acumular. */
function pf_pushItemEdited(PDO $pdo, int $itemId, int $ownerUid, int $editorUid, array $meta): void {
    $recips = [];
    if ($ownerUid !== $editorUid) $recips[$ownerUid] = true;
    $stmt = $pdo->prepare("SELECT user_id FROM list_item_collaborators WHERE item_id = ?");
    $stmt->execute([$itemId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int)$cid;
        if ($cid !== $editorUid) $recips[$cid] = true;
    }
    if (!$recips) return;
    $del = $pdo->prepare("DELETE FROM item_invites WHERE to_user_id = ? AND item_id = ? AND type = 'item-edited'");
    $ins = $pdo->prepare("INSERT INTO item_invites
        (to_user_id, from_user_id, type, item_id, category, item_title, item_image, item_music_type, item_artist)
        VALUES (?, ?, 'item-edited', ?, ?, ?, ?, ?, ?)");
    foreach (array_keys($recips) as $rid) {
        rt_usuario($rid, 'items');
        $del->execute([$rid, $itemId]);
        $ins->execute([
            $rid, $editorUid, $itemId,
            $meta['category'],
            $meta['title']  ?? '',
            $meta['image']  ?? null,
            $meta['music_type'] ?? null,
            $meta['artist'] ?? null,
        ]);
    }
}

/* Resuelve/crea el chat entre dos usuarios. Canoniza pair_id por orden. */
/* Mensaje editado o borrado: timbre 'chat' del otro del chat, para que
   lo vea cambiar si lo tiene abierto. */
function pf_avisarChat(PDO $pdo, int $mid, int $yo): void {
    $st = $pdo->prepare("SELECT c.user_a, c.user_b FROM messages m JOIN chats c ON c.id = m.chat_id WHERE m.id = ?");
    $st->execute([$mid]);
    $c = $st->fetch(PDO::FETCH_NUM);
    if ($c) rt_usuario((int)$c[0] === $yo ? (int)$c[1] : (int)$c[0], 'chat');
}

function pf_chatId(PDO $pdo, int $a, int $b): int {
    if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
    $stmt = $pdo->prepare("SELECT id FROM chats WHERE user_a = ? AND user_b = ?");
    $stmt->execute([$a, $b]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO chats (user_a, user_b) VALUES (?, ?)")->execute([$a, $b]);
    return (int)$pdo->lastInsertId();
}

/* ¿El usuario tiene activa la preferencia `key_name` en user_settings?
   Es la fuente de verdad de mute_profile / mute_social / mute_messages
   (boolean JSON). Usado para filtrar push antes de enviarlos. */
function pf_userHasMuteFlag(PDO $pdo, int $uid, string $key): bool {
    try {
        $st = $pdo->prepare("SELECT value FROM user_settings
                             WHERE user_id = ? AND key_name = ? LIMIT 1");
        $st->execute([$uid, $key]);
        $v = $st->fetchColumn();
        return $v === 'true';   /* JSON de boolean true → string literal 'true' */
    } catch (Throwable $_) { return false; }
}

/* Envía Web Push best-effort a todas las subscripciones del destinatario.
   Errores 410 (Gone) / 404 limpian la sub muerta de la BD. Cualquier otro
   fallo se silencia — el mensaje ya está persistido. */
function pf_tryWebPush(PDO $pdo, int $toUid, string $fromKey, string $text): void {
    global $loginUsers;
    $libPath = dirname(__DIR__) . '/push/webpush.php';
    if (!file_exists($libPath)) return;     /* no instalado todavía */
    $keysPath = dirname(__DIR__) . '/push/vapid-keys.php';
    if (!file_exists($keysPath)) return;    /* corre generate-vapid.php primero */

    /* Modo "no molestar": el destinatario desactivó la categoría
       Mensajes en sus prefs → no enviamos push (la conversación
       seguirá visible al abrir la app). */
    if (pf_userHasMuteFlag($pdo, $toUid, 'mute_messages')) return;

    /* Respeta el mute: si el destinatario ha silenciado el chat con el
       remitente y mute_until aún es futuro, no enviamos push. El mensaje
       igual queda en BD y aparece la próxima vez que abran la app. */
    $fromUid = pf_uid($pdo, $fromKey);
    if ($fromUid) {
        $st = $pdo->prepare("SELECT 1 FROM chat_mutes
                             WHERE user_id = ? AND with_user_id = ?
                               AND mute_until > NOW()");
        $st->execute([$toUid, $fromUid]);
        if ($st->fetchColumn()) return;
    }

    $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$toUid]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs) return;

    require_once $libPath;
    /* Helper pushNotifBaseUrl() para construir URLs portables (localhost
       y producción usan bases distintas). Vive en send-push.php. */
    require_once dirname(__DIR__) . '/push/send-push.php';
    try { $wp = new WebPush(); } catch (Throwable $e) { return; }

    $fromLabel = $loginUsers[$fromKey]['label'] ?? $fromKey;
    /* Avatar del remitente como icono de la notificación. Si no hay
       avatar resoluble cae al notification-icon.png del SW (la sandía). */
    $fromAvatarRel = getUserImage($fromLabel);
    $base = pushNotifBaseUrl();
    $iconUrl = $fromAvatarRel !== '' ? ($base . $fromAvatarRel) : null;
    $payload = json_encode([
        'type'  => 'chat',
        'title' => '💬 ' . $fromLabel,
        'body'  => mb_substr($text, 0, 120),
        'from'  => $fromKey,
        'icon'  => $iconUrl,
        /* Deep-link: el shell parsea #chat= y carga perfil-mobile con el
           mismo hash → handleChatHash() abre el modal de chat.
           `?pwa=1` hace que la URL haga match con el start_url del
           manifest (./mobile.php?pwa=1) — sin esto Chrome a veces no
           reconoce la URL como "perteneciente" a la PWA instalada y
           openWindow() abre una pestaña del navegador en vez del
           contenedor standalone. La base es dinámica (localhost vs prod). */
        'url'   => $base . 'mobile.php?pwa=1#chat=' . $fromKey,
    ], JSON_UNESCAPED_UNICODE);

    foreach ($subs as $s) {
        try {
            list($code, $_resp) = $wp->send([
                'endpoint' => $s['endpoint'],
                'p256dh'   => $s['p256dh'],
                'auth'     => $s['auth'],
            ], $payload, 60);
            /* 201/202/204 = OK; 404/410 = sub muerta. */
            if ($code === 404 || $code === 410) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$s['id']]);
            }
        } catch (Throwable $e) { /* silencio */ }
    }
}

switch ($action) {

/* ─── Perfil básico ─────────────────────────────────────── */

case 'get-profile': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(pf_emptyProfile());
    require_once __DIR__ . '/perfil2.php';
    jsonResponse(pf_buildFullProfile($pdo, $uid) + ['v2' => pf2_profile($pdo, $uid, $uid)]);
}

case 'add-post': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $body = jsonBody();
    $text = mb_substr(trim($body['text'] ?? ''), 0, 1000);
    $img  = trim($body['image_url'] ?? '');
    /* image_url opcional, debe ser http(s) y ≤ 2000 chars si está presente */
    if ($img !== '') {
        if (mb_strlen($img) > 2000 || !preg_match('#^https?://[^\s<>"\']+$#i', $img)) {
            jsonError('URL de imagen inválida');
        }
    }
    if ($text === '' && $img === '') jsonError('El post no puede estar vacío');
    $pdo->prepare("INSERT INTO posts (user_id, text, image_url) VALUES (?, ?, ?)")
        ->execute([$uid, $text, $img !== '' ? $img : null]);
    $id = (int)$pdo->lastInsertId();
    jsonResponse(['ok' => true, 'post' => [
        'id'        => $id,
        'text'      => $text,
        'imageUrl'  => $img !== '' ? $img : null,
        'createdAt' => time(),
        'likes'     => [],
        'comments'  => [],
    ]]);
}

case 'add-comment': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $body = jsonBody();
    $postId = (int)($body['postId'] ?? 0);
    $text = mb_substr(trim($body['text'] ?? ''), 0, 500);
    if (!$postId || $text === '') jsonError('Datos incompletos');
    /* El post tiene que existir */
    $stmt = $pdo->prepare("SELECT 1 FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    if (!$stmt->fetchColumn()) jsonError('Post no encontrado', 404);
    $pdo->prepare("INSERT INTO post_comments (post_id, user_id, text) VALUES (?, ?, ?)")
        ->execute([$postId, $uid, $text]);
    $id = (int)$pdo->lastInsertId();
    global $loginUsers;
    jsonResponse(['ok' => true, 'comment' => [
        'id'          => $id,
        'text'        => $text,
        'createdAt'   => time(),
        'authorKey'   => $userKey,
        'authorLabel' => $loginUsers[$userKey]['label'] ?? $userKey,
    ]]);
}

case 'delete-comment': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $cid = (int)(jsonBody()['id'] ?? 0);
    if (!$cid) jsonError('ID inválido');
    /* Permitir borrar si soy el autor del comentario O el dueño del post */
    $stmt = $pdo->prepare(
        "SELECT c.user_id AS commentUser, p.user_id AS postUser
         FROM post_comments c JOIN posts p ON c.post_id = p.id
         WHERE c.id = ?"
    );
    $stmt->execute([$cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('Comentario no encontrado', 404);
    if ((int)$row['commentUser'] !== $uid && (int)$row['postUser'] !== $uid) {
        jsonError('Sin permiso', 403);
    }
    $pdo->prepare("DELETE FROM post_comments WHERE id = ?")->execute([$cid]);
    jsonResponse(['ok' => true]);
}

case 'delete-post': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $id  = (int)(jsonBody()['id'] ?? 0);
    if (!$id) jsonError('ID inválido');
    $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
    jsonResponse(['ok' => true]);
}

case 'get-discord-webhook': {
    $stmt = $pdo->prepare("SELECT discord_webhook FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    jsonResponse(['ok' => true, 'webhook' => (string)$stmt->fetchColumn() ?: '']);
}

case 'save-discord-webhook': {
    $url = trim(jsonBody()['webhook'] ?? '');
    /* Vacío = borrar; si no, validar pattern de Discord */
    if ($url !== '') {
        $re = '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_-]+$#';
        if (!preg_match($re, $url) || mb_strlen($url) > 500) {
            jsonError('URL de webhook no válida');
        }
    }
    $pdo->prepare("UPDATE usuarios SET discord_webhook = ? WHERE user_key = ?")
        ->execute([$url !== '' ? $url : null, $userKey]);
    jsonResponse(['ok' => true]);
}

case 'discord-publish': {
    global $loginUsers;
    $body  = jsonBody();
    $img   = trim($body['image_url'] ?? '');
    $cap   = mb_substr(trim($body['caption'] ?? ''), 0, 1900);
    if (!preg_match('#^https?://[^\s<>"\']+$#i', $img) || mb_strlen($img) > 2000) {
        jsonError('URL de imagen no válida');
    }
    /* Publicación via BOT TOKEN al canal MELON_HUB_CHANNEL_ID.
       Antes usábamos un webhook (DISCORD_WEBHOOK_URL) — más simple pero
       requería crearlo a mano. Ahora reusamos el bot que ya está en el
       servidor para dar puntos por reacciones: con
         - DISCORD_BOT_TOKEN
         - MELON_HUB_CHANNEL_ID
       el bot postea directamente en ese canal. Si falta cualquiera de
       las dos, devolvemos `code:'discordNotConfigured'` para que el
       cliente muestre un texto neutral en vez de un error alarmante. */
    $channelId = trim(env('MELON_HUB_CHANNEL_ID', ''));
    $botToken  = trim(env('DISCORD_BOT_TOKEN', ''));
    if ($channelId === '' || $botToken === '') {
        $missing = [];
        if ($channelId === '') $missing[] = 'MELON_HUB_CHANNEL_ID';
        if ($botToken  === '') $missing[] = 'DISCORD_BOT_TOKEN';
        jsonResponse([
            'error' => 'Publicar en Discord está desactivado por el administrador (falta ' . implode(' + ', $missing) . ' en .env).',
            'code'  => 'discordNotConfigured'
        ], 503);
    }
    $stmt = $pdo->prepare("SELECT discord_user_id, label FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('Usuario no encontrado', 500);
    if (empty($row['discord_user_id'])) {
        /* code:'needsDiscordLink' permite al cliente reconocer el caso
           y guiar al usuario al flow de OAuth en vez de mostrar error
           genérico. */
        jsonResponse(['error' => 'Vincula tu cuenta de Discord para recibir los puntos por reacciones', 'code' => 'needsDiscordLink'], 400);
    }
    $label   = $row['label'] ?: $userKey;

    /* Avatar del usuario: lo enviamos ADJUNTO en el propio request multipart
       (no como URL pública). En el embed lo referenciamos con
       `attachment://avatar.<ext>`. Esto permite que Discord muestre el
       avatar SIN necesidad de que sea alcanzable desde Internet — lee los
       bytes directamente del payload. */
    $avatarFsPath = null;
    $avatarMime   = '';
    $avatarExt    = '';
    if (function_exists('getUserImage')) {
        $rel = getUserImage($label);
        if ($rel) {
            /* __DIR__ aquí = .../assets/profile. Subir 2 niveles → raíz del proyecto. */
            $candidate = dirname(__DIR__, 2) . '/' . $rel;
            if (is_file($candidate)) {
                $avatarFsPath = $candidate;
                $avatarExt    = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                $mimeMap      = [
                    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',  'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                ];
                $avatarMime = $mimeMap[$avatarExt] ?? 'image/png';
            }
        }
    }

    /* Embed: author = icono + nombre. description = caption (opcional).
       image = la imagen pública en Drive. */
    $author = ['name' => mb_substr($label, 0, 256)];
    if ($avatarFsPath) $author['icon_url'] = 'attachment://avatar.' . $avatarExt;
    $embed = [
        'author'    => $author,
        'image'     => ['url' => $img],
        /* `timestamp` ISO-8601 en UTC. Discord lo renderea en el footer
           autoformateado y localizado para cada viewer (ej. "Today at
           19:30" en castellano, "5/30/26 7:30 PM" en otros idiomas). */
        'timestamp' => gmdate('c'),
    ];
    /* description = caption + nota call-to-action de reacciones + enlace a
       la Melon Hub debajo. La nota le dice al lector explícitamente con
       qué emoji se otorgan puntos al autor, así desaparece el "¿esto
       cómo funciona?". El nombre del autor lo cogemos del `label`. */
    $hubUrl = 'https://melonhub.es';
    $desc  = ($cap !== '') ? ($cap . "\n\n") : '';
    $desc .= '*(Reacciona con ❤️ para dar puntos a ' . $label . ' en la Melon Hub)*' . "\n\n";
    $desc .= '[**Click para entrar a la Melon Hub**](' . $hubUrl . ')';
    $embed['description'] = $desc;

    $payload = [
        'embeds' => [$embed],
        /* Evita pings accidentales con @everyone/@here desde el caption */
        'allowed_mentions' => ['parse' => []],
    ];

    /* Endpoint del bot: POST a /channels/{id}/messages. La respuesta YA
       incluye el message_id (no hace falta ?wait=true como con webhooks). */
    $publishUrl  = 'https://discord.com/api/v10/channels/' . rawurlencode($channelId) . '/messages';
    $authHeader  = 'Authorization: Bot ' . $botToken;
    /* Identificador "User-Agent" requerido por Discord para apps API.
       Sin esto la API a veces rechaza con 400 BAD REQUEST. */
    $uaHeader    = 'User-Agent: MelonHub (https://github.com, 1.0)';

    $ch = curl_init($publishUrl);
    if ($avatarFsPath) {
        /* multipart/form-data: payload_json + files[0] (el avatar).
           NO añadimos Content-Type aquí — cURL lo construye con el boundary
           correcto cuando POSTFIELDS es un array. */
        $postFields = [
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'files[0]'     => new CURLFile($avatarFsPath, $avatarMime, 'avatar.' . $avatarExt),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => [$authHeader, $uaHeader],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
    } else {
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [$authHeader, $uaHeader, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        /* Discord responde JSON con detalle del error */
        $detail = '';
        $j = json_decode((string)$resp, true);
        if (is_array($j) && isset($j['message'])) $detail = ' — ' . $j['message'];
        jsonError('Discord rechazó la publicación (HTTP ' . $code . ')' . $detail, 502);
    }

    /* Extrae message_id + channel_id de la respuesta y los mapea al user
       para que el bot pueda premiar reacciones de corazón. */
    $msg               = json_decode((string)$resp, true);
    $messageId         = is_array($msg) ? (string)($msg['id']         ?? '') : '';
    $resolvedChannelId = is_array($msg) ? (string)($msg['channel_id'] ?? '') : '';
    if ($messageId !== '') {
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
        $st->execute([$userKey]);
        $authorUserId = (int)$st->fetchColumn();
        if ($authorUserId > 0) {
            $pdo->prepare('INSERT INTO webhook_posts (message_id, user_id, kind, channel_id) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)')
                ->execute([$messageId, $authorUserId, 'discord', $resolvedChannelId]);
        }
        /* Añadir ❤️ por defecto al mensaje con el bot token. Si el bot no
           tiene permiso en el canal, falla silenciosamente — la fila de
           webhook_posts ya está guardada, así que las reacciones manuales
           seguirán contando. */
        $botToken = env('DISCORD_BOT_TOKEN', '');
        if ($botToken !== '' && $resolvedChannelId !== '') {
            $emoji = rawurlencode('❤️');
            $ch2 = curl_init("https://discord.com/api/v10/channels/$resolvedChannelId/messages/$messageId/reactions/$emoji/@me");
            curl_setopt_array($ch2, [
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_HTTPHEADER     => ['Authorization: Bot ' . $botToken, 'Content-Length: 0'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);
            curl_exec($ch2);
            curl_close($ch2);
        }
    }

    jsonResponse(['ok' => true, 'message_id' => $messageId]);
}

case 'save-quote': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $quote = mb_substr(trim(jsonBody()['quote'] ?? ''), 0, 500);
    $pdo->prepare("INSERT INTO profile (user_id, quote) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE quote = VALUES(quote)")
        ->execute([$uid, $quote]);
    jsonResponse(['ok' => true]);
}

case 'save-info': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    if (!$b) jsonError('Datos inválidos');
    $bio       = mb_substr(trim($b['bio']       ?? ''), 0, 200);
    $pronouns  = mb_substr(trim($b['pronouns']  ?? ''), 0, 30);
    $ageStr    = mb_substr(preg_replace('/[^0-9]/', '', $b['age'] ?? ''), 0, 3);
    $age       = $ageStr === '' ? null : (int)$ageStr;
    $country   = mb_substr(trim($b['country']   ?? ''), 0, 50);
    $steam     = mb_substr(trim($b['steam']     ?? ''), 0, 200);
    $discord   = mb_substr(trim($b['discord']   ?? ''), 0, 100);
    $twitter   = mb_substr(trim($b['twitter']   ?? ''), 0, 100);
    $instagram = mb_substr(trim($b['instagram'] ?? ''), 0, 100);
    $cols = ['bio' => $bio, 'pronouns' => $pronouns, 'country' => $country,
             'steam' => $steam, 'discord' => $discord, 'twitter' => $twitter,
             'instagram' => $instagram];
    /* Los editores nuevos ya no mandan `age` (sale del cumpleaños): sin
       la clave no se toca, para no borrar la edad de quien aún no ha
       puesto el cumpleaños. Las páginas viejas en caché sí la mandan. */
    if (array_key_exists('age', $b)) $cols['age'] = $age;
    if (array_key_exists('birthday', $b)) {
        $bdIn = trim((string)$b['birthday']);
        $bd   = pf_birthdayOrNull($bdIn);
        if ($bdIn !== '' && $bd === null) jsonError('La fecha de cumpleaños no es válida');
        $cols['birthday'] = $bd;
        /* Con cumpleaños la edad escrita a mano sobra. */
        if ($bd !== null) $cols['age'] = null;
    }
    $names = array_keys($cols);
    $pdo->prepare("INSERT INTO profile (user_id, " . implode(', ', $names) . ")
        VALUES (?" . str_repeat(', ?', count($names)) . ")
        ON DUPLICATE KEY UPDATE " .
        implode(', ', array_map(fn($c) => "$c=VALUES($c)", $names)))
        ->execute(array_merge([$uid], array_values($cols)));
    jsonResponse(['ok' => true]);
}

/* ─── Listas ─────────────────────────────────────────────── */

case 'get-lists': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(pf_emptyLists());
    jsonResponse(pf_loadAllLists($pdo, $uid));
}

/* Autocomplete: devuelve titles distintos de items ya creados en la
   categoría dada que matcheen el prefijo q. Excluye los que el usuario
   ya tiene (own + shared con él) para evitar sugerir duplicados.
   Cap a 8 sugerencias para que el dropdown no se vuelva absurdo. */
case 'search-titles': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'suggestions' => []]);
    $category = $_GET['category'] ?? '';
    $q        = trim((string)($_GET['q'] ?? ''));
    if (!in_array($category, ['movies','series','books','games','music'], true) || $q === '') {
        jsonResponse(['ok' => true, 'suggestions' => []]);
    }
    /* Titles que el usuario ya tiene en esta categoría — para excluirlos. */
    $stmt = $pdo->prepare("
        SELECT LOWER(title) AS t FROM list_items WHERE owner_id = ? AND category = ?
        UNION
        SELECT LOWER(i.title) AS t FROM list_item_collaborators c
        JOIN list_items i ON c.item_id = i.id
        WHERE c.user_id = ? AND i.category = ?
    ");
    $stmt->execute([$uid, $category, $uid, $category]);
    $excluded = array_flip(array_map(function($r){ return $r['t']; }, $stmt->fetchAll(PDO::FETCH_ASSOC)));

    /* Búsqueda case-insensitive con MATCHES por prefijo Y por contains.
       Priorizamos prefijo (title LIKE 'q%') sobre contains (title LIKE '%q%'). */
    $like      = $q . '%';
    $likeAny   = '%' . $q . '%';
    /* Distinct por title (puede haber varios users con el mismo título). */
    $stmt = $pdo->prepare("
        SELECT title, MAX(image) AS image, GROUP_CONCAT(runtime) AS runtimes,
               CASE WHEN LOWER(title) LIKE ? THEN 1 ELSE 2 END AS rank_p
        FROM list_items
        WHERE category = ? AND LOWER(title) LIKE ?
        GROUP BY LOWER(title)
        ORDER BY rank_p ASC, title ASC
        LIMIT 30
    ");
    $stmt->execute([mb_strtolower($like), $category, mb_strtolower($likeAny)]);
    $suggestions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (count($suggestions) >= 8) break;
        if (isset($excluded[mb_strtolower($row['title'])])) continue;
        /* runtime sugerido = la moda de los valores de ese título. */
        $rt = null;
        if (!empty($row['runtimes'])) {
            $vals = array_filter(array_map('intval', explode(',', $row['runtimes'])), fn($v) => $v > 0);
            if ($vals) { $freq = array_count_values($vals); arsort($freq); $rt = (int)array_key_first($freq); }
        }
        $sug = ['title' => $row['title'], 'image' => $row['image'] ?? ''];
        if ($rt !== null) $sug['runtime'] = $rt;
        $suggestions[] = $sug;
    }
    jsonResponse(['ok' => true, 'suggestions' => $suggestions]);
}

case 'save-lists': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);

    $b        = jsonBody();
    $category = $b['category'] ?? '';
    $items    = $b['items']    ?? null;
    if (!in_array($category, ['movies','series','books','games','music'], true) || !is_array($items)) jsonError('Datos inválidos');
    $isMusic = ($category === 'music');

    /* Cargar el estado actual de la categoría: items propios + ids
       compartidos conmigo (solo para reconocerlos y NO tocarlos). */
    $stmt = $pdo->prepare("SELECT id FROM list_items WHERE owner_id = ? AND category = ?");
    $stmt->execute([$uid, $category]);
    $myOwnIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("SELECT i.id, i.owner_id FROM list_item_collaborators c
                           JOIN list_items i ON c.item_id = i.id
                           WHERE c.user_id = ? AND i.category = ?");
    $stmt->execute([$uid, $category]);
    $sharedWithMe = []; /* item_id => owner_id */
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sharedWithMe[(int)$r['id']] = (int)$r['owner_id'];
    }

    /* Snapshot de los items propios CON collaborators para detectar bajas.
       También trae status y completed_at_ts previos para preservar la
       fecha de completado entre saves (sin esto cada save resetearía el
       timestamp aunque el item siguiera en completed). */
    $oldItems = [];
    if (!empty($myOwnIds)) {
        $place = implode(',', array_fill(0, count($myOwnIds), '?'));
        $stmt = $pdo->prepare("SELECT id, title, status, UNIX_TIMESTAMP(completed_at) AS completed_at_ts
                               FROM list_items WHERE id IN ($place)");
        $stmt->execute($myOwnIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oldItems[(int)$r['id']] = [
                'title'        => $r['title'],
                'status'       => $r['status'],
                'completed_at' => $r['completed_at_ts'] !== null ? (int)$r['completed_at_ts'] : null,
                'collabs'      => [],
            ];
        }
        $stmt = $pdo->prepare("SELECT c.item_id, c.user_id FROM list_item_collaborators c
                               WHERE c.item_id IN ($place)");
        $stmt->execute($myOwnIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oldItems[(int)$r['item_id']]['collabs'][] = (int)$r['user_id'];
        }
    }

    /* Misma snapshot para los compartidos conmigo — necesito el status
       previo para preservar/asignar completed_at en el path de UPDATE
       de compartido (los compartidos solo permiten editar status, no
       todo el item). */
    $oldShared = []; /* item_id => ['status'=>, 'completed_at'=>] */
    if (!empty($sharedWithMe)) {
        $sharedIds = array_keys($sharedWithMe);
        $place2 = implode(',', array_fill(0, count($sharedIds), '?'));
        $stmt = $pdo->prepare("SELECT id, status, UNIX_TIMESTAMP(completed_at) AS completed_at_ts
                               FROM list_items WHERE id IN ($place2)");
        $stmt->execute($sharedIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oldShared[(int)$r['id']] = [
                'status'       => $r['status'],
                'completed_at' => $r['completed_at_ts'] !== null ? (int)$r['completed_at_ts'] : null,
            ];
        }
    }

    /* Fotos (ver cover-helpers.php). Va FUERA de la transacción: son
       descargas y búsquedas de red y no queremos la BD bloqueada mientras
       tanto. Con topes (3 descargas, 3 búsquedas, 8 s) para que guardar no
       se eternice; lo que quede lo hace covers-repair.php o, cuando una
       foto falla al verse, fix-cover.
         - Copia local de las fotos nuevas o cambiadas, para que no se
           caigan si la web de origen las borra. Música no se copia.
         - Foto automática para los items sin foto (es opcional). */
    require_once __DIR__ . '/cover-helpers.php';
    cv_ensureTables($pdo);
    $coverDeadline = microtime(true) + 8;
    if (!$isMusic) {
        $oldImages = [];   /* item_id => image guardada */
        $knownIds  = array_merge($myOwnIds, array_keys($sharedWithMe));
        if (!empty($knownIds)) {
            $place = implode(',', array_fill(0, count($knownIds), '?'));
            $stmt = $pdo->prepare("SELECT id, image FROM list_items WHERE id IN ($place)");
            $stmt->execute($knownIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $oldImages[(int)$r['id']] = (string)$r['image'];
        }
        $remote = [];
        foreach ($items as $k => $item) {
            $img = is_array($item) ? trim((string)($item['image'] ?? '')) : '';
            if (cv_isRemote($img)) $remote[$k] = $img;
        }
        $cached    = cv_cachedUrls($pdo, array_values(array_unique($remote)));
        $downloads = 0;
        foreach ($remote as $k => $img) {
            if (isset($cached[$img])) { $items[$k]['image'] = $cached[$img]; continue; }
            $rawId = $items[$k]['id'] ?? null;
            $oldImg = (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) ? ($oldImages[(int)$rawId] ?? null) : null;
            /* Sin cambios respecto a lo guardado → la copia la hará el repaso. */
            if ($img === $oldImg || $downloads >= 3 || microtime(true) > $coverDeadline) continue;
            $downloads++;
            $local = cv_cacheRemote($pdo, $img, $cvStatus, 5);
            if ($local) { $items[$k]['image'] = $local; $cached[$img] = $local; }
        }
    }
    /* Sin foto → automática. Primero los items nuevos (los que el usuario
       acaba de añadir); los que ya estaban sin foto, si queda margen. */
    $noImageNew = []; $noImageOld = [];
    foreach ($items as $k => $item) {
        if (!is_array($item) || trim((string)($item['title'] ?? '')) === '' || trim((string)($item['image'] ?? '')) !== '') continue;
        $rawId = $item['id'] ?? null;
        if (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) $noImageOld[] = $k;
        else $noImageNew[] = $k;
    }
    $searches = 0;
    foreach (array_merge($noImageNew, $noImageOld) as $k) {
        if ($searches >= 3 || microtime(true) > $coverDeadline) break;
        $rawId = $items[$k]['id'] ?? null;
        $hints = ['excludeId' => (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) ? (int)$rawId : 0];
        if ($isMusic) $hints += ['artist' => (string)($items[$k]['artist'] ?? ''), 'type' => (string)($items[$k]['type'] ?? '')];
        $auto  = cv_autoImage($pdo, $category, (string)$items[$k]['title'], $hints, $didSearch);
        if ($didSearch) $searches++;
        if ($auto !== null) $items[$k]['image'] = $auto;
    }

    /* Iterar los items entrantes y separar:
        - update de propios (id numérico que coincide con mi propiedad)
        - update de compartido conmigo (id en sharedWithMe → solo permitido
          editar review/status/featured; tracks de listas se ignoran)
        - nuevos (id no numérico o desconocido → INSERT) */
    $touchedOwnIds   = [];
    $touchedShared   = []; /* item_id => owner_id, para devolver al cliente */
    $insertedIds     = []; /* mapping: client_id (string) → new int id */

    $pdo->beginTransaction();
    try {
        $upsertCols = [
            'title','image','status','music_type','artist','featured',
            'yt_id','spotify_id','yt_playlist_id','spotify_album_id',
            'review_stars','review_comment','reviewed_at',
        ];

        $fnSanitize = function(array $item) use ($isMusic) {
            $clean = [
                'title' => mb_substr(trim($item['title'] ?? ''), 0, 200),
                'image' => mb_substr(trim($item['image'] ?? ''), 0, 2000),
                'status'        => null,
                'music_type'    => null,
                'artist'        => null,
                'featured'      => 0,
                'yt_id'         => null,
                'spotify_id'    => null,
                'yt_playlist_id'=> null,
                'spotify_album_id' => null,
                'review_stars'   => null,
                'review_comment' => null,
                'reviewed_at'    => null,
                /* runtime del item: minutos (pelis) / capítulos (series, libros).
                   Se pone al crear el item; música no lo usa. */
                'runtime' => (isset($item['runtime']) && is_numeric($item['runtime']) && (int)$item['runtime'] > 0)
                    ? min(100000, (int)$item['runtime']) : null,
            ];
            if ($isMusic) {
                $clean['music_type'] = in_array($item['type'] ?? '', ['song','album'], true) ? $item['type'] : 'song';
                $clean['artist']     = mb_substr(trim($item['artist'] ?? ''), 0, 200);
                $clean['featured']   = !empty($item['featured']) ? 1 : 0;
                foreach (['ytId'=>'yt_id','spotifyId'=>'spotify_id','ytPlaylistId'=>'yt_playlist_id','spotifyAlbumId'=>'spotify_album_id'] as $jc => $sc) {
                    if (!empty($item[$jc])) $clean[$sc] = preg_replace('/[^A-Za-z0-9_-]/', '', $item[$jc]);
                }
            } else {
                $clean['status'] = in_array($item['status'] ?? '', ['pending','in-progress','completed'], true)
                                    ? $item['status'] : 'pending';
            }
            if (isset($item['review']) && is_array($item['review'])) {
                $stars = isset($item['review']['stars']) ? round((float)$item['review']['stars'] * 2) / 2 : 0;
                $clean['review_stars']   = max(0, min(5, $stars));
                $clean['review_comment'] = mb_substr(trim($item['review']['comment'] ?? ''), 0, 1000);
                /* reviewed_at: usa el enviado por el cliente si lo trae, o
                   conserva el que ya existía si solo cambió el comentario, o
                   asigna ahora si es la primera reseña. Se decide a nivel
                   de query (UPSERT) → aquí solo marcamos "review presente". */
                $clean['reviewed_at'] = isset($item['review']['reviewedAt']) && is_numeric($item['review']['reviewedAt'])
                    ? (int)$item['review']['reviewedAt']
                    : time();
            }
            return $clean;
        };

        /* Helper: decide qué valor escribir en completed_at según la
           transición de status. Devuelve:
             - null         → status no es completed: limpiamos timestamp
             - int (unix)   → escribir este timestamp
           Reglas:
             - status NUEVO=completed && status VIEJO!=completed  → time() (ahora)
             - status NUEVO=completed && status VIEJO=completed   → preservar el viejo
             - status NUEVO!=completed                            → NULL */
        $fnCompletedAt = function(?string $newStatus, ?string $oldStatus, ?int $oldTs): ?int {
            if ($newStatus !== 'completed') return null;
            if ($oldStatus === 'completed' && $oldTs !== null) return $oldTs;
            return time();
        };

        /* Persiste la review del usuario actual ($uid) sobre $itemId.
           - Si $clean trae stars>0: UPSERT en list_item_reviews.
           - Si stars=0 o no se mandó: borra la fila (el usuario "quitó"
             su reseña).
           Cada usuario tiene su propia fila — un colaborador NO pisa la
           review del owner. */
        $fnPersistReview = function(int $itemId, array $clean) use ($pdo, $uid) {
            $stars = $clean['review_stars'];
            $hasReview = $stars !== null && (float)$stars > 0;
            if (!$hasReview) {
                $pdo->prepare("DELETE FROM list_item_reviews WHERE item_id = ? AND user_id = ?")
                    ->execute([$itemId, $uid]);
                return;
            }
            $reviewedAt = $clean['reviewed_at'] !== null ? (int)$clean['reviewed_at'] : time();
            $pdo->prepare("INSERT INTO list_item_reviews (item_id, user_id, stars, comment, reviewed_at)
                           VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
                           ON DUPLICATE KEY UPDATE
                             stars = VALUES(stars),
                             comment = VALUES(comment),
                             reviewed_at = VALUES(reviewed_at)")
                ->execute([$itemId, $uid, $stars, $clean['review_comment'], $reviewedAt]);
        };

        foreach ($items as $item) {
            $title = trim($item['title'] ?? '');
            if ($title === '') continue;
            $rawId = $item['id'] ?? null;
            $isNumeric = is_int($rawId) || (is_string($rawId) && ctype_digit($rawId));
            $clean = $fnSanitize($item);

            if ($isNumeric && in_array((int)$rawId, $myOwnIds, true)) {
                /* UPDATE de item propio. Las columnas review_stars, review_comment
                   y reviewed_at de list_items NO se tocan — la review vive ahora
                   en list_item_reviews por usuario (ver $fnPersistReview). */
                $id = (int)$rawId;
                $touchedOwnIds[] = $id;
                $oldStatus = $oldItems[$id]['status']       ?? null;
                $oldCompTs = $oldItems[$id]['completed_at'] ?? null;
                $newCompletedAt = !$isMusic
                    ? $fnCompletedAt($clean['status'], $oldStatus, $oldCompTs)
                    : null;
                $sql = "UPDATE list_items SET
                            title = ?, image = ?, status = ?, music_type = ?, artist = ?,
                            featured = ?, yt_id = ?, spotify_id = ?, yt_playlist_id = ?,
                            spotify_album_id = ?, runtime = ?,
                            completed_at = " . ($newCompletedAt === null ? "NULL" : "FROM_UNIXTIME(?)") . "
                        WHERE id = ? AND owner_id = ?";
                $params = [
                    $clean['title'], $clean['image'], $clean['status'], $clean['music_type'], $clean['artist'],
                    $clean['featured'], $clean['yt_id'], $clean['spotify_id'], $clean['yt_playlist_id'],
                    $clean['spotify_album_id'], $clean['runtime'],
                ];
                if ($newCompletedAt !== null) $params[] = $newCompletedAt;
                $params[] = $id; $params[] = $uid;
                $pdo->prepare($sql)->execute($params);
                $fnPersistReview($id, $clean);
                /* Si el item propio tiene colaboradores, avisarles del cambio
                   para que refresquen (título/imagen/duración/estado). */
                if (!empty($oldItems[$id]['collabs'])) {
                    pf_pushItemEdited($pdo, $id, $uid, $uid, [
                        'category' => $category, 'title' => $clean['title'], 'image' => $clean['image'],
                        'music_type' => $clean['music_type'], 'artist' => $clean['artist'],
                    ]);
                }
            } elseif ($isNumeric && isset($sharedWithMe[(int)$rawId])) {
                /* UPDATE de item compartido: el dueño es otro, pero ahora
                   cualquier colaborador puede editar los datos compartidos
                   del item (título/imagen/duración/estado/datos de música).
                   La fila es única → el cambio lo ven el host y el resto de
                   colaboradores. Mi review sigue siendo por-usuario
                   ($fnPersistReview). */
                $id = (int)$rawId;
                $touchedShared[$id] = $sharedWithMe[$id];
                $oldStatus = $oldShared[$id]['status']       ?? null;
                $oldCompTs = $oldShared[$id]['completed_at'] ?? null;
                $newCompletedAt = !$isMusic
                    ? $fnCompletedAt($clean['status'], $oldStatus, $oldCompTs)
                    : null;
                $sql = "UPDATE list_items SET
                            title = ?, image = ?, status = ?, music_type = ?, artist = ?,
                            featured = ?, yt_id = ?, spotify_id = ?, yt_playlist_id = ?,
                            spotify_album_id = ?, runtime = ?,
                            completed_at = " . ($newCompletedAt === null ? "NULL" : "FROM_UNIXTIME(?)") . "
                        WHERE id = ?";
                $params = [
                    $clean['title'], $clean['image'], $clean['status'], $clean['music_type'], $clean['artist'],
                    $clean['featured'], $clean['yt_id'], $clean['spotify_id'], $clean['yt_playlist_id'],
                    $clean['spotify_album_id'], $clean['runtime'],
                ];
                if ($newCompletedAt !== null) $params[] = $newCompletedAt;
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                $fnPersistReview($id, $clean);
                /* Avisar al host y a los demás colaboradores del cambio. */
                pf_pushItemEdited($pdo, $id, $sharedWithMe[$id], $uid, [
                    'category' => $category, 'title' => $clean['title'], 'image' => $clean['image'],
                    'music_type' => $clean['music_type'], 'artist' => $clean['artist'],
                ]);
            } else {
                /* INSERT (item nuevo) — si entra ya marcado como completed,
                   completed_at se setea ahora. La review (si la trae) va a
                   list_item_reviews por usuario, no a las columnas viejas. */
                $newCompletedAt = !$isMusic
                    ? $fnCompletedAt($clean['status'], null, null)
                    : null;
                $sql = "INSERT INTO list_items
                        (owner_id, category, title, image, status, music_type, artist, featured,
                         yt_id, spotify_id, yt_playlist_id, spotify_album_id, runtime, completed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " .
                        ($newCompletedAt === null ? "NULL" : "FROM_UNIXTIME(?)") . ")";
                $params = [
                    $uid, $category, $clean['title'], $clean['image'], $clean['status'],
                    $clean['music_type'], $clean['artist'], $clean['featured'],
                    $clean['yt_id'], $clean['spotify_id'], $clean['yt_playlist_id'], $clean['spotify_album_id'],
                    $clean['runtime'],
                ];
                if ($newCompletedAt !== null) $params[] = $newCompletedAt;
                $pdo->prepare($sql)->execute($params);
                $newId = (int)$pdo->lastInsertId();
                $touchedOwnIds[] = $newId;
                if ($rawId !== null) $insertedIds[(string)$rawId] = $newId;
                $fnPersistReview($newId, $clean);
            }
        }

        /* Detectar items propios eliminados (estaban en oldItems y no en touchedOwnIds).
           Si tenían colaboradores, propagamos notif collab-removed antes del DELETE
           para incluir el item_id en la notif. */
        $deletedOwnIds = array_values(array_diff($myOwnIds, $touchedOwnIds));
        foreach ($deletedOwnIds as $itemId) {
            $meta = $oldItems[$itemId] ?? null;
            if (!$meta) continue;
            foreach ($meta['collabs'] as $cUid) {
                pf_pushItemStatus($pdo, $cUid, $uid, $itemId, 'collab-removed', [
                    'category' => $category,
                    'title'    => $meta['title'],
                ]);
            }
        }
        if (!empty($deletedOwnIds)) {
            $place = implode(',', array_fill(0, count($deletedOwnIds), '?'));
            $pdo->prepare("DELETE FROM list_items WHERE id IN ($place) AND owner_id = ?")
                ->execute(array_merge($deletedOwnIds, [$uid]));
        }

        /* Detectar items compartidos eliminados (estaban en sharedWithMe y no
           en touchedShared). Eso significa "abandonar la colaboración". */
        $leftShared = array_diff(array_keys($sharedWithMe), array_keys($touchedShared));
        foreach ($leftShared as $itemId) {
            $ownerUid = $sharedWithMe[$itemId];
            $stmt = $pdo->prepare("SELECT title FROM list_items WHERE id = ?");
            $stmt->execute([$itemId]);
            $title = $stmt->fetchColumn() ?: '';
            $pdo->prepare("DELETE FROM list_item_collaborators WHERE item_id = ? AND user_id = ?")
                ->execute([$itemId, $uid]);
            pf_pushItemStatus($pdo, $ownerUid, $uid, $itemId, 'collab-left', [
                'category' => $category, 'title' => $title,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

    /* Devolver el snapshot canónico (incluye IDs nuevos asignados al INSERT) */
    jsonResponse([
        'ok'    => true,
        'items' => pf_loadAllLists($pdo, $uid)[$category] ?? [],
    ]);
}

case 'get-item-collabs': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $category = $_GET['category'] ?? '';
    $itemId   = (int)($_GET['itemId'] ?? 0);
    if (!in_array($category, ['movies','series','books','games','music'], true) || !$itemId) jsonError('Datos inválidos');

    $stmt = $pdo->prepare("SELECT i.owner_id, ou.user_key AS owner_key
                           FROM list_items i JOIN usuarios ou ON i.owner_id = ou.id
                           WHERE i.id = ? AND i.category = ?");
    $stmt->execute([$itemId, $category]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('Item no encontrado', 404);

    $stmt = $pdo->prepare("SELECT u.user_key FROM list_item_collaborators c
                           JOIN usuarios u ON c.user_id = u.id
                           WHERE c.item_id = ?");
    $stmt->execute([$itemId]);
    jsonResponse([
        'ownerKey'      => $row['owner_key'],
        'collaborators' => $stmt->fetchAll(PDO::FETCH_COLUMN),
    ]);
}

case 'send-item-invite': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b        = jsonBody();
    $toUser   = preg_replace('/[^A-Za-z0-9_-]/', '', $b['toUser'] ?? '');
    $category = $b['category'] ?? '';
    $itemId   = (int)($b['itemId'] ?? 0);
    if (!$toUser || !$itemId || !in_array($category, ['movies','series','books','games','music'], true)) jsonError('Datos inválidos');
    if (!isset($loginUsers[$toUser])) jsonError('Usuario destino inválido');
    if ($toUser === $userKey)          jsonError('No puedes invitarte a ti mismo');
    $toUid = pf_uid($pdo, $toUser);
    if (!$toUid) jsonError('Usuario destino inválido');

    /* Restringir colaboraciones a seguidores mutuos. */
    require_once dirname(__DIR__) . '/social-helpers.php';
    if (!isMutualFollow($pdo, $uid, $toUid)) {
        jsonError('Solo puedes invitar a usuarios con seguimiento mutuo');
    }

    $stmt = $pdo->prepare("SELECT title, image, music_type, artist
                           FROM list_items
                           WHERE id = ? AND owner_id = ? AND category = ?");
    $stmt->execute([$itemId, $uid, $category]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) jsonError('Elemento no encontrado', 404);

    /* ¿Ya es colaborador? */
    $stmt = $pdo->prepare("SELECT 1 FROM list_item_collaborators WHERE item_id = ? AND user_id = ?");
    $stmt->execute([$itemId, $toUid]);
    if ($stmt->fetch()) jsonError(($loginUsers[$toUser]['label'] ?? $toUser) . ' ya es colaborador');

    /* ¿Ya hay invite pendiente de mí hacia este usuario para este item? */
    $stmt = $pdo->prepare("SELECT 1 FROM item_invites
                           WHERE to_user_id = ? AND from_user_id = ? AND item_id = ? AND type = 'invite'");
    $stmt->execute([$toUid, $uid, $itemId]);
    if ($stmt->fetch()) jsonError('Ya existe una invitación pendiente');

    $pdo->prepare("INSERT INTO item_invites
        (to_user_id, from_user_id, type, item_id, category, item_title, item_image, item_music_type, item_artist)
        VALUES (?, ?, 'invite', ?, ?, ?, ?, ?, ?)")
        ->execute([
            $toUid, $uid, $itemId, $category,
            $item['title'], $item['image'],
            $category === 'music' ? ($item['music_type'] ?: 'song') : null,
            $category === 'music' ? ($item['artist'] ?? '') : null,
        ]);
    rt_usuario($toUid, 'items');
    /* Push notification al destinatario. */
    require_once dirname(__DIR__) . '/push/send-push.php';
    $fromLabel = $loginUsers[$userKey]['label'] ?? $userKey;
    sendPushToUser($pdo, (int)$toUid, buildInvitePushPayload(
        'item',
        '➕ ' . $fromLabel . ' te invita',
        'Colaborar en "' . ($item['title'] ?? '') . '"',
    ));
    jsonResponse(['ok' => true]);
}

case 'respond-item-invite': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b        = jsonBody();
    $inviteId = (int)($b['inviteId'] ?? 0);
    $act      = $b['action']   ?? '';
    if (!$inviteId || !in_array($act, ['accept','reject','dismiss'], true)) jsonError('Datos inválidos');

    $stmt = $pdo->prepare("SELECT id, from_user_id, type, item_id, category, item_title, item_image, item_music_type, item_artist
                           FROM item_invites
                           WHERE id = ? AND to_user_id = ?");
    $stmt->execute([$inviteId, $uid]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) jsonError('Invitación no encontrada', 404);

    /* Consumir la fila siempre */
    $pdo->prepare("DELETE FROM item_invites WHERE id = ?")->execute([$inviteId]);

    /* Solo procesar accept/reject si era un invite real (no un status) */
    if ($inv['type'] === 'invite' && $act === 'accept' && $inv['item_id']) {
        /* Validar que el item sigue existiendo */
        $stmt = $pdo->prepare("SELECT 1 FROM list_items WHERE id = ?");
        $stmt->execute([(int)$inv['item_id']]);
        if ($stmt->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO list_item_collaborators (item_id, user_id) VALUES (?, ?)")
                ->execute([(int)$inv['item_id'], $uid]);
            pf_pushItemStatus($pdo, (int)$inv['from_user_id'], $uid, (int)$inv['item_id'], 'item-accepted', [
                'category'   => $inv['category'],
                'title'      => $inv['item_title'],
                'image'      => $inv['item_image'],
                'music_type' => $inv['item_music_type'],
                'artist'     => $inv['item_artist'],
            ]);
        }
    } elseif ($inv['type'] === 'invite' && $act === 'reject') {
        pf_pushItemStatus($pdo, (int)$inv['from_user_id'], $uid, $inv['item_id'] ? (int)$inv['item_id'] : null, 'item-rejected', [
            'category'   => $inv['category'],
            'title'      => $inv['item_title'],
            'image'      => $inv['item_image'],
            'music_type' => $inv['item_music_type'],
            'artist'     => $inv['item_artist'],
        ]);
    }
    /* dismiss: nada extra */
    jsonResponse(['ok' => true]);
}

case 'leave-collab': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b        = jsonBody();
    $act      = $b['action']   ?? '';
    $category = $b['category'] ?? '';
    $itemId   = (int)($b['itemId'] ?? 0);
    if (!in_array($act, ['leave','remove'], true) || !in_array($category, ['movies','series','books','games','music'], true) || !$itemId) {
        jsonError('Datos inválidos');
    }

    if ($act === 'leave') {
        /* Yo soy collab → me salgo y aviso al owner */
        $stmt = $pdo->prepare("SELECT i.id, i.title, i.owner_id
                               FROM list_item_collaborators c
                               JOIN list_items i ON c.item_id = i.id
                               WHERE c.user_id = ? AND i.id = ? AND i.category = ?");
        $stmt->execute([$uid, $itemId, $category]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('No eres colaborador de este elemento');

        $pdo->prepare("DELETE FROM list_item_collaborators WHERE item_id = ? AND user_id = ?")
            ->execute([$itemId, $uid]);
        pf_pushItemStatus($pdo, (int)$row['owner_id'], $uid, $itemId, 'collab-left', [
            'category' => $category, 'title' => $row['title'],
        ]);
    } else {
        /* Yo soy owner → quito a un colaborador y le aviso */
        $collab = preg_replace('/[^A-Za-z0-9_-]/', '', $b['collaboratorUser'] ?? '');
        if (!$collab || !isset($loginUsers[$collab])) jsonError('Usuario colaborador inválido');
        $collabUid = pf_uid($pdo, $collab);
        if (!$collabUid) jsonError('Usuario colaborador inválido');

        $stmt = $pdo->prepare("SELECT i.title FROM list_items i
                               JOIN list_item_collaborators c ON c.item_id = i.id
                               WHERE i.id = ? AND i.owner_id = ? AND i.category = ? AND c.user_id = ?");
        $stmt->execute([$itemId, $uid, $category, $collabUid]);
        $title = $stmt->fetchColumn();
        if ($title === false) jsonError('El usuario no es colaborador');

        $pdo->prepare("DELETE FROM list_item_collaborators WHERE item_id = ? AND user_id = ?")
            ->execute([$itemId, $collabUid]);
        pf_pushItemStatus($pdo, $collabUid, $uid, $itemId, 'collab-removed', [
            'category' => $category, 'title' => $title,
        ]);
    }
    jsonResponse(['ok' => true]);
}

/* ─── Seguimiento / likes / notifs ───────────────────────── */

case 'toggle-follow': {
    /* Ya no hay "seguir": ahora son solicitudes de amistad (amigos.php).
       Se mantiene para páginas viejas (el móvil): sin relación → envía
       solicitud; enviada → la cancela; recibida → la acepta; amigos →
       elimina la amistad. `following` = amigos o solicitud enviada. */
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $target = preg_replace('/[^a-z0-9_]/i', '', jsonBody()['targetUser'] ?? '');
    if (!$target || !isset($loginUsers[$target])) jsonError('Usuario no encontrado');
    if ($target === $userKey)                     jsonError('No puedes agregarte a ti');
    $tid = pf_uid($pdo, $target);
    if (!$tid) jsonError('Usuario no encontrado');
    $e = amigos_estado($pdo, $uid, $tid);
    if ($e === 'ninguna')      $e = amigos_enviar($pdo, $uid, $tid);
    elseif ($e === 'enviada')  $e = amigos_cancelar($pdo, $uid, $tid);
    elseif ($e === 'recibida') $e = amigos_aceptar($pdo, $uid, $tid);
    else                       $e = amigos_eliminar($pdo, $uid, $tid);
    jsonResponse([
        'ok' => true, 'following' => in_array($e, ['amigos', 'enviada'], true), 'amistad' => $e,
        'list' => pf_loadFollowing($pdo, $uid),
    ]);
}

/* ─── Amigos (amigos.php) ─── */
case 'friends': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true] + amigos_lista($pdo, $uid));
}

/* Haro del escritorio (pollAmigos en desktop-base.php): solicitudes
   pendientes y amistades aceptadas sin avisar (amigos_avisos). */
case 'friend-avisos': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true] + amigos_avisos($pdo, $uid));
}

case 'friend-search': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true, 'resultados' => amigos_buscar($pdo, $uid, (string)($_GET['q'] ?? ''))]);
}

case 'friend-request':
case 'friend-accept':
case 'friend-reject':
case 'friend-cancel':
case 'friend-remove': {
    global $loginUsers;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $target = preg_replace('/[^a-z0-9_]/i', '', jsonBody()['user'] ?? '');
    $tid = $target !== '' ? pf_uid($pdo, $target) : 0;
    if (!$tid) jsonError('Usuario no encontrado');
    try {
        if ($action === 'friend-request')     $e = amigos_enviar($pdo, $uid, $tid);
        elseif ($action === 'friend-accept')  $e = amigos_aceptar($pdo, $uid, $tid);
        elseif ($action === 'friend-reject')  $e = amigos_rechazar($pdo, $uid, $tid);
        elseif ($action === 'friend-cancel')  $e = amigos_cancelar($pdo, $uid, $tid);
        else                                  $e = amigos_eliminar($pdo, $uid, $tid);
    } catch (InvalidArgumentException $ex) {
        jsonResponse(['ok' => false, 'error' => $ex->getMessage()]);
    }
    jsonResponse(['ok' => true, 'amistad' => $e, 'list' => pf_loadFollowing($pdo, $uid)] + amigos_lista($pdo, $uid));
}

case 'toggle-post-like': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b      = jsonBody();
    $target = preg_replace('/[^a-z0-9_]/i', '', $b['targetUser'] ?? '');
    $postId = (int)($b['postId'] ?? 0);
    if (!$target || !isset($loginUsers[$target])) jsonError('Usuario no encontrado');
    if (!$postId)                                  jsonError('Post inválido');

    $stmt = $pdo->prepare("SELECT p.id, p.text, p.user_id FROM posts p
                           JOIN usuarios u ON p.user_id = u.id
                           WHERE p.id = ? AND u.user_key = ?");
    $stmt->execute([$postId, $target]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) jsonError('Post no encontrado', 404);

    $stmt = $pdo->prepare("SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$postId, $uid]);
    $alreadyLiked = (bool)$stmt->fetch();

    if ($alreadyLiked) {
        $pdo->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?")
            ->execute([$postId, $uid]);
        pf_deleteNotifsMatching($pdo, (int)$post['user_id'], 'like', $uid, ['postId' => $postId]);
        $liked = false;
    } else {
        $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)")
            ->execute([$postId, $uid]);
        pf_addNotif($pdo, (int)$post['user_id'], 'like', $uid, [
            'postId'   => $postId,
            'postText' => mb_substr($post['text'], 0, 80),
        ]);
        $liked = true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ?");
    $stmt->execute([$postId]);
    jsonResponse(['ok' => true, 'liked' => $liked, 'count' => (int)$stmt->fetchColumn()]);
}

case 'get-profile-notifs': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'notifs' => [], 'unread' => 0]);
    $stmt = $pdo->prepare("SELECT n.id, n.type, fu.user_key AS fromUser, n.payload,
                                  n.is_read AS isRead, UNIX_TIMESTAMP(n.created_at) AS createdAt
                           FROM notifications n
                           LEFT JOIN usuarios fu ON n.from_user_id = fu.id
                           WHERE n.user_id = ?
                           ORDER BY n.created_at DESC, n.id DESC
                           LIMIT 100");
    $stmt->execute([$uid]);
    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $list   = [];
    $unread = 0;
    foreach ($rows as $r) {
        $payload = $r['payload'] ? (json_decode($r['payload'], true) ?: []) : [];
        $entry = array_merge([
            'id'        => (int)$r['id'],
            'type'      => $r['type'],
            'fromUser'  => $r['fromUser'],
            'createdAt' => (int)$r['createdAt'],
            'read'      => (bool)$r['isRead'],
        ], $payload);
        if (!$entry['read']) $unread++;
        $list[] = $entry;
    }
    jsonResponse(['ok' => true, 'notifs' => $list, 'unread' => $unread]);
}

case 'heartbeat': {
    /* Ping de presencia — la shell desktop/móvil lo manda cada 30s.
       state es opcional: 'away' cuando el cliente detectó inactividad
       o pantalla bloqueada; cualquier otro valor cae a 'online'. */
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $stateRaw = $_GET['state'] ?? ($_POST['state'] ?? '');
    $state = ($stateRaw === 'away') ? 'away' : 'online';
    try {
        $pdo->prepare("
            INSERT INTO user_presence (user_id, state) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE last_at = NOW(), state = VALUES(state)
        ")->execute([$uid, $state]);
    } catch (Throwable $_) {
        /* Fallback para BDs cuya migración de `state` aún no corrió. */
        $pdo->prepare("
            INSERT INTO user_presence (user_id) VALUES (?)
            ON DUPLICATE KEY UPDATE last_at = NOW()
        ")->execute([$uid]);
    }
    /* El archivo de la presencia que leen los perfiles (como mucho cada
       10 s, entre todos). */
    pf_presenciaArchivo($pdo);
    jsonResponse(['ok' => true]);
}

case 'presence': {
    /* La cuenta está en assets/profile/presencia.php (la misma que se deja
       en el archivo estático que leen los perfiles); esto queda para
       cuando el archivo no responde y para quien aún no lo lee. */
    jsonResponse(pf_presencia($pdo));
}

case 'notif-settings': {
    /* GET → devuelve los 3 flags del usuario actual.
       POST → guarda los flags enviados.
       Storage: tabla `user_settings` (key_name, value JSON string). */
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $KEYS = ['mute_profile', 'mute_social', 'mute_messages'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = jsonBody();
        foreach ($KEYS as $k) {
            $v = !empty($body[$k]) ? 'true' : 'false';
            try {
                $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE value = VALUES(value)")
                    ->execute([$uid, $k, json_encode($v === 'true', JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $_) { /* tabla ausente → ignorar */ }
        }
        /* "No molestar" sale en la presencia: el archivo, al momento. */
        pf_presenciaArchivo($pdo, true);
        jsonResponse(['ok' => true]);
    }
    $out = ['mute_profile' => false, 'mute_social' => false, 'mute_messages' => false];
    try {
        $place = implode(',', array_fill(0, count($KEYS), '?'));
        $stmt = $pdo->prepare("SELECT key_name, value FROM user_settings
                               WHERE user_id = ? AND key_name IN ($place)");
        $stmt->execute(array_merge([$uid], $KEYS));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['key_name']] = (json_decode($r['value'], true) === true);
        }
    } catch (Throwable $_) {}
    jsonResponse(array_merge(['ok' => true], $out));
}

case 'mark-notifs-read': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $st = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $st->execute([$uid]);
    /* Sus otros escritorios / el móvil quitan el contador. */
    if ($st->rowCount() > 0) rt_usuario($uid, 'notif');
    jsonResponse(['ok' => true]);
}

/* Invitaciones y avisos de listas (item_invites) del usuario: lo mismo
   que empujaba item-notifications-stream.php, que ocupaba un proceso PHP
   y una conexión todo el rato por escritorio. Ahora el escritorio lo pide
   cuando cambia 'items' en su archivo del timbre (assets/php/timbre.php). */
case 'item-invites': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $st = $pdo->prepare(
        "SELECT i.id, i.type, i.category, i.item_id AS itemId,
                i.item_title AS itemTitle, i.item_image AS itemImage,
                i.item_music_type AS itemMusicType, i.item_artist AS itemArtist,
                fu.user_key AS fromUser, fu.label AS fromLabel,
                UNIX_TIMESTAMP(i.created_at) AS sentAt
         FROM item_invites i
         JOIN usuarios fu ON i.from_user_id = fu.id
         WHERE i.to_user_id = ?
         ORDER BY i.created_at ASC");
    $st->execute([$uid]);
    $invites = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($invites as &$row) {
        $row['id']     = (int)$row['id'];
        $row['itemId'] = $row['itemId'] !== null ? (int)$row['itemId'] : null;
        $row['sentAt'] = (int)$row['sentAt'];
    }
    unset($row);
    jsonResponse(['ok' => true, 'invites' => $invites]);
}

case 'get-followers': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'followers' => []]);
    $stmt = $pdo->prepare("SELECT u.user_key FROM follows f
                           JOIN usuarios u ON f.follower_id = u.id
                           WHERE f.followee_id = ? ORDER BY f.created_at ASC");
    $stmt->execute([$uid]);
    jsonResponse(['ok' => true, 'followers' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

case 'notify-review': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b        = jsonBody();
    $category = preg_replace('/[^a-z]/i', '', $b['category']  ?? '');
    $title    = mb_substr(trim($b['itemTitle'] ?? ''), 0, 120);
    $mtype    = preg_replace('/[^a-z]/i', '', $b['mtype']     ?? '');
    if (!$category || !$title) jsonError('Faltan datos');
    if (!in_array($category, ['movies','series','books','games','music'], true)) jsonError('Categoría inválida');
    if ($mtype !== '' && !in_array($mtype, ['album','song'], true)) $mtype = '';

    /* Notificar a todos mis followers (los que me siguen) */
    $stmt = $pdo->prepare("SELECT follower_id FROM follows WHERE followee_id = ?");
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fuid) {
        /* Dedup: borra una notif previa de review del mismo título */
        pf_deleteNotifsMatching($pdo, (int)$fuid, 'review', $uid, [
            'category' => $category, 'itemTitle' => $title,
        ]);
        pf_addNotif($pdo, (int)$fuid, 'review', $uid, [
            'category' => $category, 'itemTitle' => $title, 'mtype' => $mtype,
        ]);
    }
    jsonResponse(['ok' => true]);
}

/* ─── Chat ──────────────────────────────────────────────── */

case 'get-unread-chats': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'counts' => []]);
    /* Por cada chat en el que participo: contar mensajes de la contraparte
       más nuevos que mi last_seen. Devuelve {otherUserKey: unreadCount}. */
    $stmt = $pdo->prepare("
        SELECT
            CASE WHEN c.user_a = :uid THEN bu.user_key ELSE au.user_key END AS other,
            (SELECT COUNT(*) FROM messages m
              WHERE m.chat_id = c.id
                AND m.from_user_id != :uid
                AND m.sent_at > COALESCE(
                    CASE WHEN c.user_a = :uid THEN c.last_seen_a ELSE c.last_seen_b END,
                    '1970-01-01'
                )) AS unread
        FROM chats c
        JOIN usuarios au ON c.user_a = au.id
        JOIN usuarios bu ON c.user_b = bu.id
        WHERE c.user_a = :uid OR c.user_b = :uid");
    $stmt->execute(['uid' => $uid]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['unread'] > 0) $counts[$r['other']] = (int)$r['unread'];
    }
    jsonResponse(['ok' => true, 'counts' => $counts]);
}

case 'get-messages': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $with = preg_replace('/[^a-z0-9_]/i', '', $_GET['with'] ?? '');
    if (!$with || !isset($loginUsers[$with])) jsonError('Usuario inválido');
    if ($with === $userKey)                    jsonError('Mismo usuario');
    $withUid = pf_uid($pdo, $with);
    if (!$withUid) jsonError('Usuario inválido');

    $chatId = pf_chatId($pdo, $uid, $withUid);

    /* Actualizar mi last_seen */
    $col = ($uid < $withUid) ? 'last_seen_a' : 'last_seen_b';
    /* ¿Había mensajes suyos sin leer? Solo entonces se avisa por el
       timbre: al otro (ve el "leído") y a mí (mis otros escritorios y el
       móvil quitan el contador). Avisar siempre sería un bucle: cada
       aviso hace que el otro pida sus mensajes, que avisaría de vuelta. */
    $st = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM messages m JOIN chats c ON c.id = m.chat_id
                          WHERE m.chat_id = ? AND m.from_user_id = ? AND m.sent_at > COALESCE(c.$col, '1970-01-01'))");
    $st->execute([$chatId, $withUid]);
    $habiaSinLeer = (bool)$st->fetchColumn();
    $pdo->prepare("UPDATE chats SET $col = NOW() WHERE id = ?")->execute([$chatId]);
    if ($habiaSinLeer) { rt_usuario($withUid, 'chat'); rt_usuario($uid, 'chat'); }

    /* last_seen del OTRO usuario en este chat → necesario para read receipts. */
    $otherCol = ($uid < $withUid) ? 'last_seen_b' : 'last_seen_a';
    $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP($otherCol) AS otherSeen FROM chats WHERE id = ?");
    $stmt->execute([$chatId]);
    $otherSeen = (int)($stmt->fetchColumn() ?: 0);

    /* Presencia del otro usuario → para "delivered" (recipient online
       después de que enviara el mensaje). */
    $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(last_at) FROM user_presence WHERE user_id = ?");
    $stmt->execute([$withUid]);
    $otherLastActive = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT m.id, fu.user_key AS `from`,
                                  CASE WHEN m.deleted_at IS NOT NULL THEN '' ELSE m.text END AS text,
                                  UNIX_TIMESTAMP(m.sent_at)   AS sentAt,
                                  UNIX_TIMESTAMP(m.edited_at) AS editedAt,
                                  (m.deleted_at IS NOT NULL)  AS isDeleted
                           FROM messages m
                           JOIN usuarios fu ON m.from_user_id = fu.id
                           WHERE m.chat_id = ?
                           ORDER BY m.sent_at ASC, m.id ASC");
    $stmt->execute([$chatId]);
    $msgs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $m['id']      = (int)$m['id'];
        $m['sentAt']  = (int)$m['sentAt'];
        $m['edited']  = $m['editedAt'] !== null && $m['editedAt'] !== '';
        $m['editedAt'] = $m['edited'] ? (int)$m['editedAt'] : 0;
        $m['deleted'] = (bool)(int)$m['isDeleted'];
        unset($m['isDeleted']);
        /* read/delivered solo aplican a MIS mensajes; el cliente
           ignora estos campos en los del otro lado. */
        if ($m['from'] === $userKey) {
            $m['read']      = ($otherSeen > 0 && $otherSeen >= $m['sentAt']);
            $m['delivered'] = ($otherLastActive > 0 && $otherLastActive >= $m['sentAt']);
        }
        $msgs[] = $m;
    }
    jsonResponse(['ok' => true, 'messages' => $msgs]);
}

/* ── edit-message ──
   Reemplaza el texto de un mensaje del usuario actual y marca edited_at.
   Solo el AUTOR puede editar. Mensajes ya borrados no se pueden editar. */
case 'edit-message': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b   = jsonBody();
    $mid = (int)($b['messageId'] ?? 0);
    $txt = mb_substr(trim($b['text'] ?? ''), 0, 2000);
    if ($mid <= 0)  jsonError('messageId inválido');
    if ($txt === '') jsonError('Texto vacío');
    /* Verifica autoría + que no esté borrado. */
    $st = $pdo->prepare("SELECT from_user_id, deleted_at FROM messages WHERE id = ?");
    $st->execute([$mid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('Mensaje no encontrado', 404);
    if ((int)$row['from_user_id'] !== $uid) jsonError('No autorizado', 403);
    if ($row['deleted_at']) jsonError('No se puede editar un mensaje eliminado', 400);
    $pdo->prepare("UPDATE messages SET text = ?, edited_at = NOW() WHERE id = ?")
        ->execute([$txt, $mid]);
    pf_avisarChat($pdo, $mid, $uid);
    jsonResponse(['ok' => true, 'id' => $mid, 'text' => $txt, 'editedAt' => time()]);
}

/* ── delete-message ──
   Soft-delete: deleted_at = NOW(). El texto se sigue mostrando como
   "" en el cliente con un placeholder "(mensaje eliminado)". Solo el
   autor puede borrar. */
case 'delete-message': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b   = jsonBody();
    $mid = (int)($b['messageId'] ?? 0);
    if ($mid <= 0) jsonError('messageId inválido');
    $st = $pdo->prepare("SELECT from_user_id FROM messages WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$mid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('Mensaje no encontrado o ya eliminado', 404);
    if ((int)$row['from_user_id'] !== $uid) jsonError('No autorizado', 403);
    $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE id = ?")
        ->execute([$mid]);
    pf_avisarChat($pdo, $mid, $uid);
    jsonResponse(['ok' => true, 'id' => $mid]);
}

/* ── get-recent-chats ──
   Un endpoint todo-en-uno para el listado de la app de chat móvil:
   por cada amigo mutual devuelve el último mensaje, contador de no
   leídos, online status, mute hasta cuándo, y nickname (si lo asigné).
   El frontend usa esto en lugar de get-unread-chats + N llamadas. */
case 'get-recent-chats': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonResponse(['ok' => true, 'chats' => []]);

    /* Amigos mutuos = follows en ambas direcciones. */
    $stmt = $pdo->prepare("
        SELECT u.id, u.user_key
          FROM follows f1
          JOIN follows f2 ON f1.followee_id = f2.follower_id AND f1.follower_id = f2.followee_id
          JOIN usuarios u ON u.id = f1.followee_id
         WHERE f1.follower_id = ?
    ");
    $stmt->execute([$uid]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$friends) jsonResponse(['ok' => true, 'chats' => []]);

    /* Una pasada: último mensaje + unread por chat. */
    $out = [];
    foreach ($friends as $f) {
        $withUid = (int)$f['id'];
        $withKey = $f['user_key'];

        $chatId = pf_chatId($pdo, $uid, $withUid);
        $col       = ($uid < $withUid) ? 'last_seen_a' : 'last_seen_b';

        /* Último mensaje. Si está borrado, devolvemos un placeholder en
           lugar del texto original — WhatsApp-style. */
        $st = $pdo->prepare("SELECT m.text, UNIX_TIMESTAMP(m.sent_at) AS sentAt,
                                    fu.user_key AS `from`,
                                    (m.deleted_at IS NOT NULL) AS isDeleted
                             FROM messages m
                             JOIN usuarios fu ON m.from_user_id = fu.id
                             WHERE m.chat_id = ?
                             ORDER BY m.sent_at DESC, m.id DESC LIMIT 1");
        $st->execute([$chatId]);
        $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($last && (int)$last['isDeleted']) {
            $last['text'] = '🗑 Mensaje eliminado';
        }

        /* Unread = mensajes del otro lado posteriores a mi last_seen. */
        $st = $pdo->prepare("SELECT COUNT(*) FROM messages m
                             JOIN chats c ON c.id = m.chat_id
                             WHERE m.chat_id = ?
                               AND m.from_user_id = ?
                               AND m.sent_at > COALESCE(c.$col, '1970-01-01')");
        $st->execute([$chatId, $withUid]);
        $unread = (int)$st->fetchColumn();

        /* Online: presencia en los últimos 60s. lastAt: última vez que
           el otro lado pingueó heartbeat (para mostrar "Última vez HH:MM"
           cuando está offline).
           Para usuarios en state='away' usamos un umbral más amplio
           (3 min) porque el navegador móvil bloqueado throttlea los
           setInterval y un ping puede llegar tarde — sin esto se verían
           como offline en vez de away mientras suena el reproductor. */
        $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(last_at) AS lastAt, state
                             FROM user_presence WHERE user_id = ?");
        $st->execute([$withUid]);
        $rowP = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $otherLastAt = (int)($rowP['lastAt'] ?? 0);
        $otherState  = (string)($rowP['state'] ?? 'online');
        $now = time();
        $awayFresh   = ($otherLastAt > 0 && $otherLastAt > ($now - 180));
        $onlineFresh = ($otherLastAt > 0 && $otherLastAt > ($now - 60));
        $isAway      = ($otherState === 'away' && $awayFresh);
        $online      = ($isAway || $onlineFresh);

        /* DND: mute_messages activo en user_settings → indicador y label
           "No molestar" en la cabecera del chat. */
        $isDnd = false;
        try {
            $st = $pdo->prepare("SELECT 1 FROM user_settings
                                 WHERE user_id = ? AND key_name = 'mute_messages' AND value = 'true' LIMIT 1");
            $st->execute([$withUid]);
            $isDnd = (bool)$st->fetchColumn();
        } catch (Throwable $_) {}

        /* Mute. */
        $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(mute_until) FROM chat_mutes
                             WHERE user_id = ? AND with_user_id = ?
                               AND mute_until > NOW()");
        $st->execute([$uid, $withUid]);
        $mutedUntil = $st->fetchColumn();
        $mutedUntil = $mutedUntil ? (int)$mutedUntil : 0;

        /* Nickname. */
        $st = $pdo->prepare("SELECT nickname FROM chat_nicknames
                             WHERE user_id = ? AND with_user_id = ?");
        $st->execute([$uid, $withUid]);
        $nick = $st->fetchColumn() ?: '';

        $out[] = [
            'userKey'    => $withKey,
            'lastText'   => $last ? mb_substr($last['text'], 0, 80) : '',
            'lastAt'     => $last ? (int)$last['sentAt'] : 0,
            'lastFromMe' => $last ? ($last['from'] === $userKey) : false,
            'unread'     => $unread,
            'online'     => $online,
            'away'       => $isAway,
            'dnd'        => $isDnd,
            /* Última vez visto del otro (presencia) — para "Última vez
               HH:MM" en la cabecera del chat cuando NO está online. */
            'lastSeenAt' => $otherLastAt,
            'mutedUntil' => $mutedUntil,
            'nickname'   => $nick,
        ];
    }
    /* Sort: chats con actividad más reciente primero. Sin mensajes (lastAt=0)
       van al final. */
    usort($out, function($a, $b) {
        if ($a['lastAt'] === $b['lastAt']) return 0;
        if ($a['lastAt'] === 0) return 1;
        if ($b['lastAt'] === 0) return -1;
        return $b['lastAt'] - $a['lastAt'];
    });
    jsonResponse(['ok' => true, 'chats' => $out]);
}

/* ── set-chat-mute ──
   Silencia (o desactiva el silencio de) un chat. duration es uno de:
   'off' | '1h' | '8h' | '24h' | '1w' | 'forever'. */
case 'set-chat-mute': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $with = preg_replace('/[^a-z0-9_]/i', '', $b['with'] ?? '');
    if (!$with || !isset($loginUsers[$with])) jsonError('Usuario inválido');
    $withUid = pf_uid($pdo, $with);
    if (!$withUid) jsonError('Usuario inválido');
    $dur = (string)($b['duration'] ?? 'off');
    $durationMap = [
        '1h'      => '1 HOUR',
        '8h'      => '8 HOUR',
        '24h'     => '24 HOUR',
        '1w'      => '7 DAY',
        /* "forever" → mute_until = max razonable (~100 años). */
        'forever' => '36500 DAY',
    ];
    if ($dur === 'off') {
        $pdo->prepare("DELETE FROM chat_mutes WHERE user_id = ? AND with_user_id = ?")
            ->execute([$uid, $withUid]);
        jsonResponse(['ok' => true, 'mutedUntil' => 0]);
    }
    if (!isset($durationMap[$dur])) jsonError('Duración inválida');
    $interval = $durationMap[$dur];
    $pdo->prepare("INSERT INTO chat_mutes (user_id, with_user_id, mute_until)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $interval))
                   ON DUPLICATE KEY UPDATE mute_until = DATE_ADD(NOW(), INTERVAL $interval)")
        ->execute([$uid, $withUid]);
    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(mute_until) FROM chat_mutes
                         WHERE user_id = ? AND with_user_id = ?");
    $st->execute([$uid, $withUid]);
    jsonResponse(['ok' => true, 'mutedUntil' => (int)$st->fetchColumn()]);
}

/* ── set-chat-nickname ──
   Asigna o limpia el apodo de un chat. nickname vacío = limpiar. */
case 'set-chat-nickname': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $with = preg_replace('/[^a-z0-9_]/i', '', $b['with'] ?? '');
    if (!$with || !isset($loginUsers[$with])) jsonError('Usuario inválido');
    $withUid = pf_uid($pdo, $with);
    if (!$withUid) jsonError('Usuario inválido');
    $nick = mb_substr(trim($b['nickname'] ?? ''), 0, 60);
    if ($nick === '') {
        $pdo->prepare("DELETE FROM chat_nicknames WHERE user_id = ? AND with_user_id = ?")
            ->execute([$uid, $withUid]);
        jsonResponse(['ok' => true, 'nickname' => '']);
    }
    $pdo->prepare("INSERT INTO chat_nicknames (user_id, with_user_id, nickname)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE nickname = VALUES(nickname)")
        ->execute([$uid, $withUid, $nick]);
    jsonResponse(['ok' => true, 'nickname' => $nick]);
}

case 'send-message': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b    = jsonBody();
    $to   = preg_replace('/[^a-z0-9_]/i', '', $b['to'] ?? '');
    $text = mb_substr(trim($b['text'] ?? ''), 0, 2000);
    if (!$to || !isset($loginUsers[$to])) jsonError('Destinatario inválido');
    if ($to === $userKey)                  jsonError('No puedes hablarte a ti mismo');
    if ($text === '')                      jsonError('Mensaje vacío');
    $toUid = pf_uid($pdo, $to);
    if (!$toUid) jsonError('Destinatario inválido');

    /* Solo se puede chatear si hay seguimiento mutuo */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows
                           WHERE (follower_id = ? AND followee_id = ?)
                              OR (follower_id = ? AND followee_id = ?)");
    $stmt->execute([$uid, $toUid, $toUid, $uid]);
    if ((int)$stmt->fetchColumn() < 2) jsonError('Necesitas seguirse mutuamente para hablar', 403);

    $chatId = pf_chatId($pdo, $uid, $toUid);
    $pdo->prepare("INSERT INTO messages (chat_id, from_user_id, text) VALUES (?, ?, ?)")
        ->execute([$chatId, $uid, $text]);
    $msgId = (int)$pdo->lastInsertId();
    /* Timbre 'chat': su escritorio pide el contador (y el chat, si lo
       tiene abierto) al verlo; antes lo pedía cada 4 s y cada 1,5 s. */
    rt_usuario($toUid, 'chat');
    /* Mi last_seen también se actualiza al mandar */
    $col = ($uid < $toUid) ? 'last_seen_a' : 'last_seen_b';
    $pdo->prepare("UPDATE chats SET $col = NOW() WHERE id = ?")->execute([$chatId]);

    /* ── Trigger Web Push al destinatario ──
       Best-effort: si falla, el mensaje ya está guardado. Errores 410
       (Gone) limpian la subscripción muerta. */
    pf_tryWebPush($pdo, $toUid, $userKey, $text);

    jsonResponse(['ok' => true, 'message' => [
        'id'     => $msgId,
        'from'   => $userKey,
        'text'   => $text,
        'sentAt' => time(),
    ]]);
}

case 'save-push-subscription': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b  = jsonBody();
    $ep = trim($b['endpoint'] ?? '');
    $p2 = trim($b['p256dh']   ?? '');
    $au = trim($b['auth']     ?? '');
    if (!$ep || !$p2 || !$au) jsonError('Subscription incompleta');
    if (!preg_match('#^https?://[^\s<>"\']+$#i', $ep) || mb_strlen($ep) > 500) jsonError('Endpoint inválido');
    /* UPSERT por endpoint — si el browser regenera la sub, sobrescribe. */
    $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
                   VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth=VALUES(auth)")
        ->execute([$uid, $ep, $p2, $au]);
    jsonResponse(['ok' => true]);
}

case 'delete-push-subscription': {
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $ep = trim(jsonBody()['endpoint'] ?? '');
    if (!$ep) jsonError('Endpoint vacío');
    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?")
        ->execute([$ep, $uid]);
    jsonResponse(['ok' => true]);
}

case 'get-vapid-public-key': {
    require_once dirname(__DIR__) . '/push/webpush.php';
    try {
        $wp = new WebPush();
        jsonResponse(['ok' => true, 'publicKey' => $wp->getPublicKeyB64()]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => 'VAPID no configurado']);
    }
}



/* ─── Ver perfil ajeno ──────────────────────────────────── */

case 'view-user': {
    global $loginUsers;
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $target = preg_replace('/[^a-z0-9_]/i', '', $_GET['user'] ?? '');
    if (!$target || !isset($loginUsers[$target])) jsonError('Usuario no encontrado', 404);
    $tid = pf_uid($pdo, $target);
    if (!$tid) jsonError('Usuario no encontrado', 404);

    $profile = pf_buildFullProfile($pdo, $tid);
    unset($profile['following']);
    $lists = pf_loadAllLists($pdo, $tid);

    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?");
    $stmt->execute([$uid, $tid]);
    $isFollowing = (bool)$stmt->fetch();

    require_once __DIR__ . '/perfil2.php';
    jsonResponse([
        'userKey'     => $target,
        'label'       => $loginUsers[$target]['label'],
        'profile'     => $profile,
        'lists'       => $lists,
        'isFollowing' => $isFollowing,
        'amistad'     => amigos_estado($pdo, $uid, $tid),
        'v2'          => pf2_profile($pdo, $tid, $uid),
    ]);
}

/* ─── Perfil nuevo (perfil2.php): muro, expositores, capturas ───── */

/* Comentario en el muro de alguien (cualquiera con cuenta; el dueño
   también escribe en el suyo). */
case 'wall-comment': {
    require_once __DIR__ . '/perfil2.php';
    pf2_ensure($pdo);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $b = jsonBody();
    $target = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['user'] ?? ''));
    $tid = $target !== '' ? pf_uid($pdo, $target) : null;
    if (!$tid) jsonError('Usuario no encontrado', 404);
    $text = mb_substr(trim((string)($b['text'] ?? '')), 0, 500);
    if ($text === '') jsonError('El comentario está vacío');
    $pdo->prepare("INSERT INTO profile_comments (profile_user_id, author_id, text) VALUES (?, ?, ?)")
        ->execute([$tid, $uid, $text]);
    jsonResponse(['ok' => true, 'comments' => pf2_comments($pdo, $tid, $uid)]);
}

/* Borrar: el dueño del muro (cualquiera) o quien lo escribió. Borrado en
   blando para que el paso de posts a comentarios no lo vuelva a crear. */
case 'delete-wall-comment': {
    require_once __DIR__ . '/perfil2.php';
    pf2_ensure($pdo);
    $uid = pf_uid($pdo, $userKey);
    $id  = (int)(jsonBody()['id'] ?? 0);
    $st = $pdo->prepare("SELECT profile_user_id, author_id FROM profile_comments WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jsonError('Comentario no encontrado', 404);
    if ((int)$c['profile_user_id'] !== $uid && (int)$c['author_id'] !== $uid) jsonError('No puedes borrar este comentario', 403);
    $pdo->prepare("UPDATE profile_comments SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true, 'comments' => pf2_comments($pdo, (int)$c['profile_user_id'], $uid)]);
}

/* Racha diaria (racha.php): pulsar el fueguito del perfil propio. */
case 'streak-claim': {
    require_once __DIR__ . '/racha.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $r = racha_pulsar($pdo, $uid);
    if (!$r['ok']) jsonError($r['error'], 409);
    jsonResponse($r);
}

/* Invitaciones (racha.php): las mías, para la ventana "Invitar". */
case 'invites': {
    require_once __DIR__ . '/racha.php';
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true] + invita_lista($pdo, $uid));
}

/* Guarda los expositores propios (tipo + configuración, en orden). */
/* Marcos de perfil (assets/profile/marcos.php): "frames" lo pide
   melon-marcos.js en cada página que pinta fotos; "my-frames" y
   "set-frame" son de Personalizar. */
case 'frames': {
    require_once __DIR__ . '/marcos.php';
    jsonResponse(['ok' => true] + marcos_todos($pdo));
}
case 'my-frames': {
    require_once __DIR__ . '/marcos.php';
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true] + marcos_propios($pdo, $uid));
}
case 'set-frame': {
    require_once __DIR__ . '/marcos.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $slug = (string)(jsonBody()['slug'] ?? '');
    if ($slug !== '' && !preg_match('/^[a-z0-9_-]{1,60}$/', $slug)) jsonError('Marco no válido');
    if (!marcos_poner($pdo, $uid, $slug)) jsonError('No tienes ese marco', 403);
    jsonResponse(['ok' => true, 'active' => marcos_activo($pdo, $uid)]);
}

case 'save-showcases': {
    require_once __DIR__ . '/perfil2.php';
    pf2_ensure($pdo);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    $r = pf2_saveShowcases($pdo, $uid, (array)(jsonBody()['showcases'] ?? []));
    if (!$r['ok']) jsonError($r['error']);
    jsonResponse(['ok' => true, 'showcases' => pf2_showcases($pdo, $uid), 'slots' => pf2_slots($pdo, $uid), 'perks' => pf2_perks($pdo, $uid)]);
}

/* Juegos registrados en las listas/reseñas de todos (para "Juegos
   favoritos"), con búsqueda por título. Con &cat=movies|series|books, lo
   mismo para películas, series y libros favoritos. */
case 'game-catalog': {
    require_once __DIR__ . '/perfil2.php';
    $cat = (string)($_GET['cat'] ?? 'games');
    jsonResponse(['ok' => true, 'games' => pf2_gameCatalog($pdo, (string)($_GET['q'] ?? ''), 30, $cat)]);
}

/* Mis playlists (propias y en las que colaboro), para "Playlist destacada". */
case 'my-playlists': {
    require_once __DIR__ . '/perfil2.php';
    jsonResponse(['ok' => true, 'playlists' => pf2_myPlaylists($pdo, pf_uid($pdo, $userKey))]);
}

/* Juego destacado: el juego de mi Steam que corresponde a un título del
   catálogo (para elegir sus logros). */
case 'steam-game-match': {
    require_once __DIR__ . '/perfil2.php';
    $uid = pf_uid($pdo, $userKey);
    $sid = steam_idOf($pdo, $uid);
    if ($sid === '' || steam_key() === '') jsonResponse(['ok' => true, 'steam' => null]);
    jsonResponse(['ok' => true, 'steam' => steam_featured($sid, steam_owned($sid), (string)($_GET['title'] ?? ''), [])]);
}

/* Mis capturas (para elegir las del expositor). */
case 'my-screenshots': {
    require_once __DIR__ . '/perfil2.php';
    pf2_ensure($pdo);
    $uid = pf_uid($pdo, $userKey);
    $st = $pdo->prepare("SELECT id, caption, game_title, drive_id, url FROM profile_screenshots WHERE user_id = ? ORDER BY created_at DESC, id DESC");
    $st->execute([$uid]);
    jsonResponse(['ok' => true, 'shots' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'url' => pf2_shotUrl($r), 'driveId' => (string)($r['drive_id'] ?? ''),
        'caption' => $r['caption'], 'game' => $r['game_title'],
    ], $st->fetchAll(PDO::FETCH_ASSOC))]);
}

/* Capturas elegidas de la Galería (Google Drive): el navegador ya las ha
   hecho públicas; aquí se guardan el enlace, el pie y el juego. */
case 'gallery-screenshots': {
    require_once __DIR__ . '/perfil2.php';
    pf2_ensure($pdo);
    $uid = pf_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 500);
    jsonResponse(['ok' => true, 'shots' => pf2_saveGalleryShots($pdo, $uid, (array)(jsonBody()['items'] ?? []))]);
}

/* ─── Steam (perfil nuevo, fase 2: steam.php) ─────────────────────
   Vincular: steam-login.php (inicio de sesión de Steam). */
case 'steam-disconnect': {
    require_once __DIR__ . '/perfil2.php';
    $uid = pf_uid($pdo, $userKey);
    $sid = steam_idOf($pdo, $uid);
    $pdo->prepare("UPDATE usuarios SET steam_id = NULL WHERE id = ?")->execute([$uid]);
    /* El icono de Steam que puso el vincular, fuera (uno escrito a mano se queda). */
    if ($sid !== '') {
        $pdo->prepare("UPDATE profile SET steam = '' WHERE user_id = ? AND steam = ?")
            ->execute([$uid, 'https://steamcommunity.com/profiles/' . $sid]);
    }
    jsonResponse(['ok' => true, 'steam' => steam_state($pdo, $uid)]);
}

/* Mi biblioteca de Steam (para elegir logros), de más a menos jugado. */
case 'steam-games': {
    require_once __DIR__ . '/perfil2.php';
    $uid = pf_uid($pdo, $userKey);
    $sid = steam_idOf($pdo, $uid);
    if ($sid === '') jsonError('No tienes Steam vinculado');
    $owned = steam_owned($sid);
    if ($owned === null) jsonError('Tu perfil de Steam (o "Detalles del juego") es privado');
    usort($owned, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    jsonResponse(['ok' => true, 'games' => array_map(fn($g) => [
        'appid' => $g['appid'], 'name' => $g['name'], 'hours' => round($g['minutes'] / 60, 1),
    ], array_values($owned))]);
}

/* Logros que tengo de un juego (los desbloqueados). */
case 'steam-game-achievements': {
    require_once __DIR__ . '/perfil2.php';
    $uid = pf_uid($pdo, $userKey);
    $sid = steam_idOf($pdo, $uid);
    if ($sid === '') jsonError('No tienes Steam vinculado');
    $app = (int)($_GET['appid'] ?? 0);
    $all = steam_achievements($sid, $app);
    jsonResponse(['ok' => true, 'total' => count($all),
        'achievements' => array_values(array_filter($all, fn($a) => $a['achieved']))]);
}


/* ─── Capítulos de series (compartidos por título) ─────────────── */

case 'series-episodes': {
    $uid = pf_uid($pdo, $userKey);
    $key = mb_strtolower(trim((string)($_GET['seriesTitle'] ?? '')));
    if ($key === '') jsonResponse(['ok' => true, 'episodes' => []]);
    $st = $pdo->prepare("
        SELECT e.id, e.position, e.season, e.title, e.image, e.duration,
               s.watched AS myWatched, s.stars AS myStars, s.comment AS myComment,
               (SELECT AVG(stars) FROM series_episode_state WHERE episode_id = e.id AND stars IS NOT NULL) AS avgStars,
               (SELECT COUNT(stars) FROM series_episode_state WHERE episode_id = e.id AND stars IS NOT NULL) AS reviewCount
        FROM series_episodes e
        LEFT JOIN series_episode_state s ON s.episode_id = e.id AND s.user_id = ?
        WHERE e.series_key = ?
        ORDER BY e.season ASC, e.position ASC, e.id ASC
    ");
    $st->execute([$uid, $key]);
    $eps = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eps[] = [
            'id'          => (int)$r['id'],
            'season'      => (int)($r['season'] ?? 1),
            'title'       => $r['title'],
            'image'       => $r['image'] ?? '',
            'duration'    => $r['duration'] !== null ? (int)$r['duration'] : null,
            'watched'     => (int)($r['myWatched'] ?? 0) === 1,
            'myStars'     => $r['myStars'] !== null ? (float)$r['myStars'] : null,
            'myComment'   => (string)($r['myComment'] ?? ''),
            'avgStars'    => $r['avgStars'] !== null ? round((float)$r['avgStars'], 2) : null,
            'reviewCount' => (int)($r['reviewCount'] ?? 0),
        ];
    }
    jsonResponse(['ok' => true, 'episodes' => $eps]);
}

case 'series-episode-reviews': {
    /* Todas las reseñas (estrellas + comentario) de un capítulo concreto. */
    $epId = (int)($_GET['episodeId'] ?? 0);
    if (!$epId) jsonResponse(['ok' => true, 'reviews' => []]);
    $st = $pdo->prepare("
        SELECT u.user_key AS userKey, u.label AS userLabel,
               s.stars AS stars, s.comment AS comment, UNIX_TIMESTAMP(s.reviewed_at) AS reviewedAt
        FROM series_episode_state s
        JOIN usuarios u ON u.id = s.user_id
        WHERE s.episode_id = ? AND s.stars IS NOT NULL
        ORDER BY s.reviewed_at DESC, s.user_id ASC
    ");
    $st->execute([$epId]);
    $reviews = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $userLabel = $r['userLabel'];
        $reviews[] = [
            'user'       => $r['userKey'],
            'userLabel'  => $userLabel,
            'userImg'    => function_exists('getUserImage') ? getUserImage($userLabel) : '',
            'stars'      => (float)$r['stars'],
            'comment'    => (string)($r['comment'] ?? ''),
            'reviewedAt' => (int)($r['reviewedAt'] ?? 0),
        ];
    }
    jsonResponse(['ok' => true, 'reviews' => $reviews]);
}

case 'save-series-episode': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    $b = jsonBody();
    $key   = mb_strtolower(trim((string)($b['seriesTitle'] ?? '')));
    $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 255);
    if ($key === '' || $title === '') jsonError('Faltan datos');
    $image = mb_substr(trim((string)($b['image'] ?? '')), 0, 2000);
    $dur   = (isset($b['duration']) && is_numeric($b['duration']) && (int)$b['duration'] > 0) ? min(100000, (int)$b['duration']) : null;
    $season = (isset($b['season']) && is_numeric($b['season']) && (int)$b['season'] > 0) ? (int)$b['season'] : 1;
    $epId  = (isset($b['id']) && is_numeric($b['id'])) ? (int)$b['id'] : 0;
    if ($epId > 0) {
        $pdo->prepare("UPDATE series_episodes SET title=?, image=?, duration=?, season=? WHERE id=? AND series_key=?")
            ->execute([$title, $image, $dur, $season, $epId, $key]);
    } else {
        $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM series_episodes WHERE series_key=?");
        $posStmt->execute([$key]);
        $pos = (int)$posStmt->fetchColumn();
        $pdo->prepare("INSERT INTO series_episodes (series_key, position, season, title, image, duration, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$key, $pos, $season, $title, $image, $dur, $uid]);
        $epId = (int)$pdo->lastInsertId();
    }
    jsonResponse(['ok' => true, 'id' => $epId]);
}

case 'delete-series-episode': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b = jsonBody();
    $epId = (int)($b['id'] ?? 0);
    if (!$epId) jsonError('Falta id');
    $pdo->prepare("DELETE FROM series_episodes WHERE id=?")->execute([$epId]);
    $pdo->prepare("DELETE FROM series_episode_state WHERE episode_id=?")->execute([$epId]);
    jsonResponse(['ok' => true]);
}

case 'delete-series-season': {
    /* Borra una temporada entera: sus capítulos y el estado de éstos. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b = jsonBody();
    $key = mb_strtolower(trim((string)($b['seriesTitle'] ?? '')));
    $season = (int)($b['season'] ?? 0);
    if ($key === '' || $season <= 0) jsonError('Faltan datos');
    /* Borrar primero el estado de los capítulos de la temporada. */
    $ids = $pdo->prepare("SELECT id FROM series_episodes WHERE series_key=? AND season=?");
    $ids->execute([$key, $season]);
    $epIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if ($epIds) {
        $place = implode(',', array_fill(0, count($epIds), '?'));
        $pdo->prepare("DELETE FROM series_episode_state WHERE episode_id IN ($place)")->execute($epIds);
    }
    $pdo->prepare("DELETE FROM series_episodes WHERE series_key=? AND season=?")->execute([$key, $season]);
    jsonResponse(['ok' => true]);
}

case 'reorder-series-episodes': {
    /* Reordena los capítulos de una serie. Recibe el orden completo de ids. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b = jsonBody();
    $key = mb_strtolower(trim((string)($b['seriesTitle'] ?? '')));
    $order = isset($b['order']) && is_array($b['order']) ? $b['order'] : [];
    if ($key === '' || !$order) jsonError('Faltan datos');
    $up = $pdo->prepare("UPDATE series_episodes SET position=? WHERE id=? AND series_key=?");
    $pos = 1;
    foreach ($order as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $up->execute([$pos, $id, $key]);
        $pos++;
    }
    jsonResponse(['ok' => true]);
}

case 'series-episode-state': {
    /* Setea visto y/o reseña del usuario para un capítulo (merge parcial). */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    $b = jsonBody();
    $epId = (int)($b['episodeId'] ?? 0);
    if (!$epId) jsonError('Falta episodeId');
    $cur = $pdo->prepare("SELECT watched, stars, comment FROM series_episode_state WHERE episode_id=? AND user_id=?");
    $cur->execute([$epId, $uid]);
    $row = $cur->fetch(PDO::FETCH_ASSOC) ?: ['watched' => 0, 'stars' => null, 'comment' => null];
    $watched = array_key_exists('watched', $b) ? (!empty($b['watched']) ? 1 : 0) : (int)$row['watched'];
    $stars   = $row['stars'] !== null ? (float)$row['stars'] : null;
    $comment = $row['comment'];
    if (array_key_exists('stars', $b)) {
        $s = (float)$b['stars'];
        $stars   = ($s > 0) ? round($s * 2) / 2 : null;
        $comment = ($stars !== null) ? mb_substr(trim((string)($b['comment'] ?? $comment ?? '')), 0, 1000) : null;
    } elseif (array_key_exists('comment', $b)) {
        $comment = mb_substr(trim((string)$b['comment']), 0, 1000);
    }
    $pdo->prepare("INSERT INTO series_episode_state (episode_id, user_id, watched, stars, comment, reviewed_at)
                   VALUES (?,?,?,?,?, " . ($stars !== null ? "NOW()" : "NULL") . ")
                   ON DUPLICATE KEY UPDATE watched=VALUES(watched), stars=VALUES(stars), comment=VALUES(comment), reviewed_at=VALUES(reviewed_at)")
        ->execute([$epId, $uid, $watched, $stars, $comment]);
    jsonResponse(['ok' => true]);
}

case 'complete-series-episodes': {
    /* Marca TODOS los capítulos de la serie como vistos para el usuario. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    $b = jsonBody();
    $key = mb_strtolower(trim((string)($b['seriesTitle'] ?? '')));
    if ($key === '') jsonError('Falta seriesTitle');
    $ids = $pdo->prepare("SELECT id FROM series_episodes WHERE series_key=?");
    $ids->execute([$key]);
    $up = $pdo->prepare("INSERT INTO series_episode_state (episode_id, user_id, watched) VALUES (?,?,1)
                         ON DUPLICATE KEY UPDATE watched=1");
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $eid) $up->execute([(int)$eid, $uid]);
    jsonResponse(['ok' => true]);
}

case 'book-pdf': {
    /* Devuelve el PDF (compartido por título) de un libro. */
    $key = mb_strtolower(trim((string)($_GET['bookTitle'] ?? '')));
    if ($key === '') jsonResponse(['ok' => true, 'url' => '']);
    $st = $pdo->prepare("SELECT url FROM book_pdfs WHERE book_key = ?");
    $st->execute([$key]);
    jsonResponse(['ok' => true, 'url' => (string)($st->fetchColumn() ?: '')]);
}

case 'save-book-pdf': {
    /* Guarda/actualiza (o borra si url vacía) el PDF compartido de un libro.
       Solo se aceptan URLs http(s) para evitar esquemas peligrosos. */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $uid = pf_uid($pdo, $userKey);
    $b = jsonBody();
    $key = mb_strtolower(trim((string)($b['bookTitle'] ?? '')));
    if ($key === '') jsonError('Falta bookTitle');
    $url = mb_substr(trim((string)($b['url'] ?? '')), 0, 2000);
    if ($url === '') {
        $pdo->prepare("DELETE FROM book_pdfs WHERE book_key = ?")->execute([$key]);
        jsonResponse(['ok' => true, 'url' => '']);
    }
    if (!preg_match('#^https?://#i', $url)) jsonError('La URL debe empezar por http:// o https://');
    $pdo->prepare("INSERT INTO book_pdfs (book_key, url, set_by) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE url = VALUES(url), set_by = VALUES(set_by)")
        ->execute([$key, $url, $uid]);
    jsonResponse(['ok' => true, 'url' => $url]);
}

/* ─── Medias de la comunidad para música (reproductor) ───
   Devuelve la media (estrellas) y nº de reseñas de cada canción/álbum
   reseñado, indexado por título normalizado (+artista). El reproductor
   lo usa para pintar la nota a la derecha de cada resultado. */
case 'music-ratings': {
    /* Normalización idéntica a la del JS del reproductor (_n):
       minúsculas → todo lo no [a-z0-9] a espacio → trim. */
    $norm = function($s) {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return trim((string)$s);
    };
    $stmt = $pdo->query("
        SELECT i.title, i.artist, i.music_type AS mtype, r.stars
        FROM list_item_reviews r
        JOIN list_items i ON r.item_id = i.id
        WHERE i.category = 'music' AND r.stars > 0
    ");
    /* Acumuladores: por (título+artista) y por título solo (fallback /
       para casar canciones de un álbum cuando el artista del track no
       coincide exactamente con el de la reseña). */
    $acc = ['songs' => [], 'albums' => [], 'songsTitle' => [], 'albumsTitle' => []];
    $bump = function(&$map, $k, $stars) {
        if (!isset($map[$k])) $map[$k] = ['sum' => 0.0, 'n' => 0];
        $map[$k]['sum'] += $stars; $map[$k]['n']++;
    };
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nt = $norm($r['title']);
        if ($nt === '') continue;
        $na = $norm($r['artist'] ?? '');
        $stars = (float)$r['stars'];
        $isAlbum = (($r['mtype'] ?? '') === 'album');
        $tk = $isAlbum ? 'albums' : 'songs';
        $ttk = $isAlbum ? 'albumsTitle' : 'songsTitle';
        $bump($acc[$tk], $nt . "\x1f" . $na, $stars);
        $bump($acc[$ttk], $nt, $stars);
    }
    $finish = function($map) {
        $out = [];
        foreach ($map as $k => $v) {
            $out[$k] = ['avg' => round($v['sum'] / max(1, $v['n']), 2), 'count' => $v['n']];
        }
        return $out;
    };
    jsonResponse([
        'ok'          => true,
        'songs'       => $finish($acc['songs']),
        'albums'      => $finish($acc['albums']),
        'songsTitle'  => $finish($acc['songsTitle']),
        'albumsTitle' => $finish($acc['albumsTitle']),
    ]);
}

/* ─── Reseñas globales (MelonArchive) ───────────────────── */

case 'melon-reviews': {
    global $loginUsers;
    $period = $_GET['period'] ?? 'alltime';
    $cat    = $_GET['cat']    ?? 'movies';
    $type   = $_GET['type']   ?? '';
    if (!in_array($cat, ['movies','series','books','games','music'], true)) jsonError('Categoría inválida');
    if (!in_array($period, ['year','recent','alltime'], true))     jsonError('Período inválido');
    if ($type !== '' && !in_array($type, ['album','song'], true))  jsonError('Tipo inválido');
    $yearAgo = time() - 365 * 24 * 60 * 60;

    /* Las reviews viven ahora en list_item_reviews por usuario. Cada
       review se atribuye al user_id que la escribió (no al owner del
       item). Un item colaborativo con dos reseñas distintas aparece
       agrupado por título pero con dos entradas en 'reviews'. */
    $sql = "SELECT i.id AS itemId, i.title, i.image, i.artist, i.music_type AS mtype, i.yt_id AS ytId,
                   i.spotify_id AS spotifyId, i.yt_playlist_id AS ytPlaylistId,
                   i.spotify_album_id AS spotifyAlbumId,
                   r.stars AS stars, r.comment AS comment, i.runtime AS runtime,
                   UNIX_TIMESTAMP(COALESCE(r.reviewed_at, i.created_at)) AS reviewedAt,
                   u.user_key AS userKey, u.label AS userLabel
            FROM list_item_reviews r
            JOIN list_items i ON r.item_id = i.id
            JOIN usuarios u ON r.user_id = u.id
            WHERE i.category = ? AND r.stars > 0";
    $params = [$cat];
    if ($cat === 'music' && $type !== '') { $sql .= " AND i.music_type = ?"; $params[] = $type; }
    if ($period === 'year') { $sql .= " AND COALESCE(r.reviewed_at, i.created_at) >= FROM_UNIXTIME(?)"; $params[] = $yearAgo; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $title = trim((string)$r['title']);
        if ($title === '') continue;
        $stars = (float)$r['stars'];
        $rAt   = (int)$r['reviewedAt'];
        $key   = mb_strtolower($title);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'title'          => $title,
                'image'          => $r['image']  ?? '',
                'artist'         => $r['artist'] ?? '',
                'mtype'          => $r['mtype']  ?? '',
                'ytId'           => $r['ytId']           ?? '',
                'spotifyId'      => $r['spotifyId']      ?? '',
                'ytPlaylistId'   => $r['ytPlaylistId']   ?? '',
                'spotifyAlbumId' => $r['spotifyAlbumId'] ?? '',
                'totalStars'     => 0.0, 'totalReviews' => 0, 'latestAt' => 0, 'reviews' => [],
                'runtimeByItem'  => [],   /* itemId => runtime (1 voto por item) */
            ];
        }
        foreach (['image','artist','mtype','ytId','spotifyId','ytPlaylistId','spotifyAlbumId'] as $f) {
            if (empty($groups[$key][$f]) && !empty($r[$f])) $groups[$key][$f] = $r[$f];
        }
        if ($r['runtime'] !== null && (int)$r['runtime'] > 0) $groups[$key]['runtimeByItem'][(int)$r['itemId']] = (int)$r['runtime'];
        $groups[$key]['totalStars'] += $stars;
        $groups[$key]['totalReviews']++;
        if ($rAt > $groups[$key]['latestAt']) $groups[$key]['latestAt'] = $rAt;
        $userLabel = $r['userLabel'];
        $groups[$key]['reviews'][] = [
            'user'       => $r['userKey'],
            'userLabel'  => $userLabel,
            'userImg'    => function_exists('getUserImage') ? getUserImage($userLabel) : '',
            'stars'      => $stars,
            'comment'    => (string)($r['comment'] ?? ''),
            'reviewedAt' => $rAt,
        ];
    }

    /* Series: nº de capítulos + nº de temporadas + duración total
       (compartidos) por título. */
    $epMap = [];
    if ($cat === 'series') {
        foreach ($pdo->query("SELECT series_key, COUNT(*) AS cnt, COUNT(DISTINCT season) AS seasons, SUM(duration) AS dur FROM series_episodes GROUP BY series_key")->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $epMap[$er['series_key']] = ['cnt' => (int)$er['cnt'], 'seasons' => (int)$er['seasons'], 'dur' => (int)$er['dur']];
        }
    }
    /* Libros: PDF (compartido por título) → clicar el título lo abre. */
    $pdfMap = [];
    if ($cat === 'books') {
        foreach ($pdo->query("SELECT book_key, url FROM book_pdfs")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $pdfMap[$pr['book_key']] = (string)$pr['url'];
        }
    }

    $items = [];
    foreach ($groups as $g) {
        usort($g['reviews'], fn($a, $b) => $b['reviewedAt'] - $a['reviewedAt']);
        $e = [
            'title' => $g['title'], 'image' => $g['image'], 'artist' => $g['artist'], 'mtype' => $g['mtype'],
            'avg'   => round($g['totalStars'] / $g['totalReviews'], 2),
            'count' => $g['totalReviews'], 'latestAt' => $g['latestAt'], 'reviews' => $g['reviews'],
        ];
        if ($cat === 'series') {
            $lk = mb_strtolower($g['title']);
            if (isset($epMap[$lk]) && $epMap[$lk]['cnt'] > 0) {
                $e['episodeCount']    = $epMap[$lk]['cnt'];
                $e['episodeDuration'] = $epMap[$lk]['dur'];
                $e['seasonCount']     = $epMap[$lk]['seasons'];
            }
        }
        if ($cat === 'books') {
            $lk = mb_strtolower($g['title']);
            if (!empty($pdfMap[$lk])) $e['pdfUrl'] = $pdfMap[$lk];
        }
        foreach (['ytId','spotifyId','ytPlaylistId','spotifyAlbumId'] as $f) if (!empty($g[$f])) $e[$f] = $g[$f];
        if (!empty($g['mtype'])) $e['type'] = $g['mtype'];
        /* Consenso de runtime = la moda (valor con más coincidencias entre
           los items del mismo título). Empate → el mayor (determinista). */
        if (!empty($g['runtimeByItem'])) {
            $freq = [];
            foreach ($g['runtimeByItem'] as $rt) $freq[$rt] = ($freq[$rt] ?? 0) + 1;
            $bestVal = null; $bestCnt = -1;
            foreach ($freq as $val => $cnt) {
                if ($cnt > $bestCnt || ($cnt === $bestCnt && (int)$val > (int)$bestVal)) { $bestCnt = $cnt; $bestVal = (int)$val; }
            }
            $e['runtime'] = $bestVal;
        }
        $items[] = $e;
    }
    if ($period === 'recent') {
        usort($items, fn($a, $b) => $b['latestAt'] - $a['latestAt']);
    } else {
        usort($items, function($a, $b) {
            if ($b['avg'] !== $a['avg']) return ($b['avg'] > $a['avg']) ? 1 : -1;
            return $b['count'] - $a['count'];
        });
    }
    jsonResponse(['ok' => true, 'items' => $items]);
}

/* Foto caída de un item de lista: assets/js/cover-fallback.js avisa
   cuando una <img> no carga y aquí buscamos otra (cv_findReplacement).
   Si aparece, se guarda en TODOS los items con ese título y esa misma
   foto caída → se arregla una vez para todo el mundo. */
case 'fix-cover': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    require_once __DIR__ . '/cover-helpers.php';
    $b        = jsonBody();
    $category = (string)($b['category'] ?? '');
    $title    = trim((string)($b['title'] ?? ''));
    $failing  = trim((string)($b['image'] ?? ''));
    if (!in_array($category, ['movies','series','books','games','music'], true) || $title === '' || $failing === '') {
        jsonError('Datos inválidos');
    }
    /* El cliente puede mandar la copia local ya resuelta ("../../assets/…"
       o absoluta): la llevamos a la forma con la que se guarda en BD. */
    $h = cv_localHash($failing);
    if ($h !== null) $failing = cv_localUrl($h);
    $titleKey = mb_strtolower($title);
    cv_ensureTables($pdo);
    @set_time_limit(60);

    $checkKey = cv_checkKey($category, $titleKey, $failing);
    $stmt = $pdo->prepare("SELECT fails, UNIX_TIMESTAMP(gave_up_at) AS gaveUp, UNIX_TIMESTAMP(checked_at) AS checkedAt
                           FROM cover_checks WHERE hash = ?");
    $stmt->execute([$checkKey]);
    $check = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['fails' => 0, 'gaveUp' => null, 'checkedAt' => null];
    /* Ya buscamos hace poco y no había nada: no repetir en cada visita. */
    if ($check['gaveUp'] && time() - (int)$check['gaveUp'] < 12 * 3600) {
        jsonResponse(['ok' => true, 'image' => null]);
    }
    /* Tras 3 errores pasajeros (separados al menos 10 min) damos la
       original por muerta. */
    $res = cv_findReplacement($pdo, $category, $title, $failing, (int)$check['fails'] >= 3);

    if ($res['status'] === 'transient') {
        if (!$check['checkedAt'] || time() - (int)$check['checkedAt'] >= 600) {
            $pdo->prepare("INSERT INTO cover_checks (hash, fails) VALUES (?, 1)
                           ON DUPLICATE KEY UPDATE fails = fails + 1")
                ->execute([$checkKey]);
        }
        /* Puede traer una sustituta para enseñar ya, sin guardarla. */
        jsonResponse(['ok' => true, 'image' => $res['url']]);
    }
    if ($res['status'] === 'none') {
        $pdo->prepare("INSERT INTO cover_checks (hash, gave_up_at) VALUES (?, NOW())
                       ON DUPLICATE KEY UPDATE gave_up_at = NOW()")
            ->execute([$checkKey]);
        jsonResponse(['ok' => true, 'image' => null]);
    }
    $pdo->prepare("UPDATE list_items SET image = ? WHERE category = ? AND LOWER(title) = ? AND image = ?")
        ->execute([$res['url'], $category, $titleKey, $failing]);
    $pdo->prepare("DELETE FROM cover_checks WHERE hash = ?")->execute([$checkKey]);
    jsonResponse(['ok' => true, 'image' => $res['url']]);
}

/* ─── Música: resolución / playback (sin almacenamiento) ── */

case 'resolve-music-item': {
    require_once dirname(__DIR__) . '/music/spotify-helpers.php';
    $url      = trim($_GET['url']      ?? '');
    $itemType = trim($_GET['itemType'] ?? 'song');
    if (!$url)                                  jsonError('URL requerida');
    if (!in_array($itemType, ['song','album'], true)) jsonError('Tipo invalido');

    $parseYtV  = fn($u) => (preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})/', $u, $m) || preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $u, $m) || preg_match('/\/embed\/([A-Za-z0-9_-]{11})/', $u, $m) || preg_match('/\/shorts\/([A-Za-z0-9_-]{11})/', $u, $m) || preg_match('/^([A-Za-z0-9_-]{11})$/', trim($u), $m)) ? $m[1] : null;
    $parseYtP  = fn($u) => preg_match('/[?&]list=([A-Za-z0-9_-]+)/', $u, $m) ? $m[1] : null;
    $parseSpot = fn($u, $type) => (preg_match('/spotify\.com\/(?:[a-z][a-z0-9-]*\/)?' . $type . '\/([A-Za-z0-9]+)/', $u, $m) || preg_match('/spotify:' . $type . ':([A-Za-z0-9]+)/', $u, $m)) ? $m[1] : null;
    $ctx8 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);

    $result = null;
    if ($itemType === 'song') {
        $trackId = $parseSpot($url, 'track');
        if ($trackId && ($token = getSpotifyToken())) {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => 'Authorization: Bearer ' . $token]]);
            $raw = @file_get_contents('https://api.spotify.com/v1/tracks/' . $trackId, false, $ctx);
            if ($raw) {
                $t = json_decode($raw, true);
                if (isset($t['name'])) {
                    $imgs = $t['album']['images'] ?? [];
                    $result = ['title' => $t['name'], 'artist' => $t['artists'][0]['name'] ?? '',
                        'image' => count($imgs) > 1 ? $imgs[1]['url'] : ($imgs[0]['url'] ?? ''), 'spotifyId' => $trackId];
                }
            }
        }
        if (!$result && ($vid = $parseYtV($url))) {
            $raw = @file_get_contents('https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/watch?v=' . $vid) . '&format=json', false, $ctx8);
            if ($raw) {
                $d = json_decode($raw, true);
                if (isset($d['title'])) $result = ['title' => $d['title'], 'artist' => '', 'image' => 'https://img.youtube.com/vi/' . $vid . '/mqdefault.jpg', 'ytId' => $vid];
            }
        }
    } else {
        if (($albumId = $parseSpot($url, 'album')) && ($token = getSpotifyToken())) {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => 'Authorization: Bearer ' . $token]]);
            $raw = @file_get_contents('https://api.spotify.com/v1/albums/' . $albumId, false, $ctx);
            if ($raw) {
                $a = json_decode($raw, true);
                if (isset($a['name'])) {
                    $imgs = $a['images'] ?? [];
                    $result = ['title' => $a['name'], 'artist' => $a['artists'][0]['name'] ?? '',
                        'image' => count($imgs) > 1 ? $imgs[1]['url'] : ($imgs[0]['url'] ?? ''), 'spotifyAlbumId' => $albumId];
                }
            }
        }
        if (!$result && ($plId = $parseSpot($url, 'playlist')) && ($token = getSpotifyToken())) {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => 'Authorization: Bearer ' . $token]]);
            $raw = @file_get_contents('https://api.spotify.com/v1/playlists/' . $plId . '?fields=name,images,owner', false, $ctx);
            if ($raw) {
                $p = json_decode($raw, true);
                if (isset($p['name'])) $result = ['title' => $p['name'], 'artist' => $p['owner']['display_name'] ?? '', 'image' => $p['images'][0]['url'] ?? '', 'spotifyAlbumId' => $plId];
            }
        }
        if (!$result && ($plId = $parseYtP($url))) {
            $raw = @file_get_contents('https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/playlist?list=' . $plId) . '&format=json', false, $ctx8);
            if ($raw) {
                $d = json_decode($raw, true);
                if (isset($d['title'])) $result = ['title' => $d['title'], 'artist' => '', 'image' => $d['thumbnail_url'] ?? '', 'ytPlaylistId' => $plId];
            }
        }
    }
    if (!$result) jsonError('No se pudo obtener informacion del enlace', 404);
    jsonResponse($result);
}

case 'play-music-item': {
    set_time_limit(60);
    require_once dirname(__DIR__) . '/music/spotify-helpers.php';
    $b = jsonBody();
    if (!$b) jsonError('Datos inválidos');
    $itemType       = $b['itemType']       ?? '';
    $title          = mb_substr(trim($b['title']  ?? ''), 0, 200);
    $artist         = mb_substr(trim($b['artist'] ?? ''), 0, 200);
    $ytId           = preg_replace('/[^A-Za-z0-9_-]/', '', $b['ytId']           ?? '');
    $ytPlaylistId   = preg_replace('/[^A-Za-z0-9_-]/', '', $b['ytPlaylistId']   ?? '');
    $spotifyAlbumId = preg_replace('/[^A-Za-z0-9]/',   '', $b['spotifyAlbumId'] ?? '');
    $spotifyId      = preg_replace('/[^A-Za-z0-9]/',   '', $b['spotifyId']      ?? '');
    if (!in_array($itemType, ['song','album'], true)) jsonError('Tipo inválido');

    $searchYTParallel = function(array $queries): array {
        if (empty($queries)) return [];
        $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'Accept-Language: en-US,en;q=0.9'];
        $mh = curl_multi_init(); $handles = [];
        foreach ($queries as $i => $q) {
            $ch = curl_init('https://www.youtube.com/results?search_query=' . urlencode($q));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $active = null;
        do { $status = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 1.0); } while ($active && $status == CURLM_OK);
        $results = [];
        foreach ($handles as $i => $ch) {
            $html = curl_multi_getcontent($ch);
            $vid = ($html && preg_match('/"videoId"\s*:\s*"([A-Za-z0-9_-]{11})"/', $html, $m)) ? $m[1] : null;
            $results[$i] = $vid;
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    };

    if ($itemType === 'song') {
        if ($ytId) jsonResponse(['tracks' => [['videoId' => $ytId, 'title' => $title, 'artist' => $artist]]]);
        $vids = $searchYTParallel([trim($title . ' ' . $artist . ' audio')]);
        if (empty($vids[0])) jsonError('No se encontró el vídeo en YouTube', 502);
        jsonResponse(['tracks' => [['videoId' => $vids[0], 'title' => $title, 'artist' => $artist]]]);
    }

    /* Album */
    if ($ytPlaylistId) {
        $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'Accept-Language: en-US,en;q=0.9'];
        $ch = curl_init('https://www.youtube.com/playlist?list=' . $ytPlaylistId);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
        $html = curl_exec($ch); curl_close($ch);
        if (!$html) jsonError('No se pudo cargar la playlist de YouTube', 502);
        $pairs = [];
        preg_match_all('/"videoId"\s*:\s*"([A-Za-z0-9_-]{11})"[^}]{0,400}?"title"\s*:\s*\{\s*"runs"\s*:\s*\[\s*\{\s*"text"\s*:\s*"([^"]+)"/', $html, $pairs);
        $videoIds = $pairs[1] ?? [];
        $titles   = $pairs[2] ?? [];
        $seen = []; $tracks = [];
        foreach ($videoIds as $i => $vid) {
            if (isset($seen[$vid])) continue;
            $seen[$vid] = true;
            $tracks[] = ['videoId' => $vid, 'title' => ($titles[$i] ?? '') ?: $title, 'artist' => $artist];
            if (count($tracks) >= 50) break;
        }
        if (empty($tracks)) {
            $m = [];
            preg_match_all('/"videoId"\s*:\s*"([A-Za-z0-9_-]{11})"/', $html, $m);
            foreach (($m[1] ?? []) as $vid) {
                if (isset($seen[$vid])) continue;
                $seen[$vid] = true;
                $tracks[] = ['videoId' => $vid, 'title' => $title, 'artist' => $artist];
                if (count($tracks) >= 50) break;
            }
        }
        if (!$tracks) jsonError('No se encontraron vídeos en la playlist', 502);
        jsonResponse(['tracks' => $tracks]);
    }

    if ($spotifyAlbumId) {
        $token = getSpotifyToken();
        if (!$token) jsonError('No se pudo conectar con Spotify', 502);
        $headers = ['Authorization: Bearer ' . $token];
        $spTracks = null;
        foreach (['albums/' . $spotifyAlbumId . '/tracks', 'playlists/' . $spotifyAlbumId . '/tracks'] as $ep) {
            $ch = curl_init('https://api.spotify.com/v1/' . $ep . '?limit=50');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
            $raw = curl_exec($ch); curl_close($ch);
            if (!$raw) continue;
            $data = json_decode($raw, true); if (!isset($data['items'])) continue;
            $items = $data['items'];
            if (!empty($items[0]['track'])) $items = array_values(array_filter(array_map(fn($i) => $i['track'] ?? null, $items)));
            if (!empty($items)) { $spTracks = $items; break; }
        }
        if (!$spTracks) jsonError('No se encontraron canciones en el álbum', 502);
        $queries = []; $meta = [];
        foreach ($spTracks as $t) {
            if (empty($t['name'])) continue;
            $tt = $t['name']; $ta = $t['artists'][0]['name'] ?? $artist;
            $queries[] = $tt . ' ' . $ta . ' audio';
            $meta[]    = ['title' => $tt, 'artist' => $ta];
        }
        if (!$queries) jsonError('Sin pistas válidas', 404);
        $vids = $searchYTParallel($queries);
        $tracks = [];
        foreach ($vids as $i => $v) {
            if (!$v) continue;
            $tracks[] = ['videoId' => $v, 'title' => $meta[$i]['title'], 'artist' => $meta[$i]['artist']];
        }
        if (!$tracks) jsonError('No se encontraron vídeos en YouTube', 502);
        jsonResponse(['tracks' => $tracks]);
    }

    jsonError('Sin datos de reproducción');
}

case 'tenor-search': {
    /* Proxy de búsqueda de GIFs en GIPHY (developers.giphy.com).
       El nombre del case se mantiene como `tenor-search` por
       compatibilidad con los clientes existentes (chat móvil y
       desktop) — antes soportaba Tenor pero esa API cerró el
       registro público; ahora SOLO usamos GIPHY.
       Para activar: registra una key gratis en developers.giphy.com
       → "Create an App" → API → copia la key a GIPHY_API_KEY en .env.
       Salida normalizada {id, preview, url}. */
    $giphyKey = trim(env('GIPHY_API_KEY', ''));
    if ($giphyKey === '') {
        jsonResponse([
            'error' => 'Búsqueda de GIFs desactivada. Añade GIPHY_API_KEY en .env. Solicita una key gratis en developers.giphy.com → "Create an App" → API.',
            'code'  => 'tenorNotConfigured'
        ], 503);
    }

    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));

    /* GIPHY v1: trending (sin query) o search. rating=pg-13 = G + PG +
       PG-13 (sin R). */
    $endpoint = $q === '' ? 'trending' : 'search';
    $params = http_build_query([
        'q'       => $q,
        'api_key' => $giphyKey,
        'limit'   => $limit,
        'rating'  => 'pg-13',
    ]);
    $url = "https://api.giphy.com/v1/gifs/$endpoint?$params";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || $resp === false) {
        jsonError('GIPHY no respondió (HTTP ' . $code . ')', 502);
    }
    $data = json_decode((string)$resp, true);

    /* GIPHY: data[].images.{fixed_height_small,original}.url.
       fixed_height_small = preview pequeño (alto fijo 100px) ideal
       para el grid del picker. original = el GIF a tamaño completo,
       que es lo que mandamos como mensaje. */
    $out = [];
    $results = is_array($data) && isset($data['data']) ? $data['data'] : [];
    foreach ($results as $r) {
        $tiny = $r['images']['fixed_height_small']['url']
            ?? $r['images']['fixed_height_downsampled']['url']
            ?? '';
        $full = $r['images']['original']['url']
            ?? $r['images']['downsized_large']['url']
            ?? $tiny;
        if ($tiny === '' || $full === '') continue;
        $out[] = [
            'id'      => (string)($r['id'] ?? ''),
            'preview' => $tiny,
            'url'     => $full,
        ];
    }
    jsonResponse(['gifs' => $out]);
}

case 'submit-report': {
    /* Reportes del usuario (bug / sugerencia) → enviados a Discord en
       el canal correspondiente vía bot.
       Multipart: type=bug|suggestion, title, body, opcional files[].
       Requiere DISCORD_BOT_TOKEN en .env. */
    global $loginUsers;
    $type  = strtolower(trim((string)($_POST['type']  ?? '')));
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body']  ?? ''));
    if (!in_array($type, ['bug','suggestion'], true)) jsonError('type inválido (bug|suggestion)');
    if ($title === '' || mb_strlen($title) > 200) jsonError('Título requerido (1-200 caracteres)');
    if ($body === '' || mb_strlen($body) > 1900)  jsonError('Texto requerido (1-1900 caracteres)');

    $botToken = trim(env('DISCORD_BOT_TOKEN', ''));
    if ($botToken === '') {
        jsonResponse([
            'error' => 'Discord no configurado (falta DISCORD_BOT_TOKEN en .env).',
            'code'  => 'discordNotConfigured'
        ], 503);
    }
    /* IDs de canales fijos hardcoded — pedidos explícitamente. */
    $channelId = $type === 'bug'
        ? '1508966914624589917'
        : '1508966824941850805';

    $userLabel = $loginUsers[$userKey]['label'] ?? $userKey;

    /* Avatar adjunto del autor, mismo patrón que discord-publish.
       Si no hay avatar resoluble, cae al icono por defecto. */
    $avatarFsPath = null; $avatarMime = ''; $avatarExt = '';
    if (function_exists('getUserImage')) {
        $rel = getUserImage($userLabel);
        if ($rel) {
            $cand = dirname(__DIR__, 2) . '/' . $rel;
            if (is_file($cand)) {
                $avatarFsPath = $cand;
                $avatarExt    = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                $mimeMap      = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                $avatarMime   = $mimeMap[$avatarExt] ?? 'image/png';
            }
        }
    }

    $color = $type === 'bug' ? 0xE74C3C : 0x3498DB;   /* rojo / azul */
    $kind  = $type === 'bug' ? 'Bug'  : 'Sugerencia';

    /* URL compartida entre TODOS los embeds del mensaje. Truco oficial
       de Discord: cuando N embeds del MISMO mensaje comparten el campo
       `url`, los agrupa en una galería visual (todas las imágenes
       dentro del mismo recuadro coloreado).
       Como NO seteamos `embed.title`, el `embed.url` no tiene a qué
       aplicarse visualmente — Discord lo usa solo para el grouping,
       no renderiza ningún link visible en el cuerpo del embed.
       Movemos el título del reporte a la primera línea de la
       description en negrita, sustituyéndolo. */
    $melonUrl = 'https://melonhub.es';

    $author = ['name' => $userLabel, 'url' => $melonUrl];
    if ($avatarFsPath) $author['icon_url'] = 'attachment://avatar.' . $avatarExt;
    /* Negrita Markdown para el título dentro del body. */
    $titleLine = '**' . str_replace('**', '\\*\\*', $title) . '**';
    $desc = $titleLine . "\n" . $body;
    $embed = [
        'author'      => $author,
        'url'         => $melonUrl,
        'description' => mb_substr($desc, 0, 4000),
        'color'       => $color,
        'footer'      => ['text' => $kind . ' reportado desde Melon Hub'],
        'timestamp'   => gmdate('c'),
    ];

    /* Adjuntar imágenes (máx 8 MB cada una, total <= 25 MB del límite
       Discord para bots no booster). */
    $userFiles = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($_FILES['files']['size'][$i] > 8 * 1024 * 1024)  continue;
            $tmp  = $_FILES['files']['tmp_name'][$i];
            $info = @getimagesize($tmp);
            if (!$info) continue;
            $typeToExt = [
                IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png',
                IMAGETYPE_GIF=>'gif',  IMAGETYPE_WEBP=>'webp',
            ];
            if (!isset($typeToExt[$info[2]])) continue;
            $ext  = $typeToExt[$info[2]];
            $mime = image_type_to_mime_type($info[2]);
            $userFiles[] = [
                'path' => $tmp,
                'mime' => $mime,
                'name' => 'imagen_' . ($i + 1) . '.' . $ext,
            ];
        }
    }

    /* Galería: primera imagen como `image` del embed principal; cada
       imagen adicional como embed-fantasma con SOLO `url` (mismo) +
       `image`. Discord agrupa todos los embeds en una sola galería
       visual dentro del recuadro coloreado del primer embed. */
    $embeds = [$embed];
    if (!empty($userFiles)) {
        $embeds[0]['image'] = ['url' => 'attachment://' . $userFiles[0]['name']];
        for ($i = 1; $i < count($userFiles); $i++) {
            $embeds[] = [
                'url'   => $melonUrl,
                'image' => ['url' => 'attachment://' . $userFiles[$i]['name']],
            ];
        }
    }

    $payload = [
        'embeds'           => $embeds,
        'allowed_mentions' => ['parse' => []],
    ];

    $url        = 'https://discord.com/api/v10/channels/' . rawurlencode($channelId) . '/messages';
    $authHeader = 'Authorization: Bot ' . $botToken;
    $uaHeader   = 'User-Agent: MelonHub (https://github.com, 1.0)';

    /* Multipart: payload_json + files[0..N] (avatar + adjuntos del user). */
    $postFields = [
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $fileIdx = 0;
    if ($avatarFsPath) {
        $postFields['files[' . $fileIdx . ']'] = new CURLFile($avatarFsPath, $avatarMime, 'avatar.' . $avatarExt);
        $fileIdx++;
    }
    foreach ($userFiles as $uf) {
        $postFields['files[' . $fileIdx . ']'] = new CURLFile($uf['path'], $uf['mime'], $uf['name']);
        $fileIdx++;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => [$authHeader, $uaHeader],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $detail = '';
        $j = json_decode((string)$resp, true);
        if (is_array($j) && isset($j['message'])) $detail = ' — ' . $j['message'];
        jsonError('Discord rechazó el reporte (HTTP ' . $code . ')' . $detail, 502);
    }

    /* Si es una sugerencia, añadimos reacciones ✅/❌ con el bot para
       que los usuarios voten directamente. Se hace tras crear el
       mensaje (se necesita su id de la respuesta).
       Emojis declarados con bytes UTF-8 explícitos (no como literales
       en el archivo) para evitar problemas de codificación si alguien
       guarda este archivo con un charset distinto:
         "\xE2\x9C\x85" = ✅ (U+2705)
         "\xE2\x9D\x8C" = ❌ (U+274C)
       Entre ambos PUT metemos un usleep de 350 ms para evitar el
       bucket rate-limit de reacciones de Discord (5 ops / 5s por canal,
       pero la API a veces es más estricta de facto).
       Falla silenciosa: si el bot no tiene "Add Reactions" o hay otro
       error, el reporte ya está publicado, no abortamos la respuesta. */
    if ($type === 'suggestion') {
        $msg       = json_decode((string)$resp, true);
        $messageId = is_array($msg) ? (string)($msg['id'] ?? '') : '';
        if ($messageId !== '') {
            $emojis = ["\xE2\x9C\x85", "\xE2\x9D\x8C"];   /* ✅ , ❌ */
            foreach ($emojis as $idx => $emoji) {
                if ($idx > 0) usleep(350 * 1000);   /* delay entre PUTs */
                $rUrl = 'https://discord.com/api/v10/channels/'
                      . rawurlencode($channelId) . '/messages/'
                      . rawurlencode($messageId) . '/reactions/'
                      . rawurlencode($emoji) . '/@me';
                $rch  = curl_init($rUrl);
                curl_setopt_array($rch, [
                    CURLOPT_CUSTOMREQUEST  => 'PUT',
                    CURLOPT_HTTPHEADER     => [$authHeader, $uaHeader, 'Content-Length: 0'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                ]);
                $rResp = curl_exec($rch);
                $rCode = curl_getinfo($rch, CURLINFO_HTTP_CODE);
                curl_close($rch);
                /* 429 = rate-limited: respetamos el retry_after de
                   Discord y reintentamos una vez. */
                if ($rCode === 429) {
                    $j = json_decode((string)$rResp, true);
                    $retry = is_array($j) && isset($j['retry_after'])
                           ? (float)$j['retry_after'] : 1.0;
                    usleep((int)(($retry + 0.1) * 1000000));
                    $rch2 = curl_init($rUrl);
                    curl_setopt_array($rch2, [
                        CURLOPT_CUSTOMREQUEST  => 'PUT',
                        CURLOPT_HTTPHEADER     => [$authHeader, $uaHeader, 'Content-Length: 0'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 8,
                    ]);
                    curl_exec($rch2);
                    curl_close($rch2);
                }
            }
        }
    }

    jsonResponse(['ok' => true]);
}

default:
    jsonError('Acción no válida: ' . $action, 400);
}
