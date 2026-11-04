<?php
/* ──────────────────────────────────────────────────────────────────────
   PERFIL NUEVO (estilo Steam) — fase 1
   ──────────────────────────────────────────────────────────────────────
   Lo usa assets/profile/api.php (get-profile / view-user devuelven `v2`,
   y las acciones de comentarios, expositores y capturas).

   - Comentarios del muro (profile_comments): sustituyen a los posts. Los
     posts que ya había (y sus comentarios) pasan a comentarios del muro
     del dueño la primera vez que se carga su perfil; se borran "en
     blando" (deleted_at) para que esa copia no los vuelva a crear.
   - Expositores (profile_showcases): cada usuario elige cuáles y en qué
     orden. Huecos: 2 de serie + los comprados en la tienda (hueco 3, 4
     y 5, en orden). Tipos: games, shots, review, music.
   - Capturas (profile_screenshots): subidas por el usuario. Se guardan en
     uploads/screenshots/ y una copia en la BD (como las fotos de perfil:
     un despliegue de Hostinger ya vació uploads/ alguna vez);
     screenshot.php las sirve y rehace el archivo si falta.
   - Medallas (bronce / plata / oro) y nivel con lo que ya se guarda:
     nivel = 1 + XP/100; bronce 100 XP, plata +200, oro +300.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/cache-helpers.php';   /* portadas de álbum en caché (find-album) */

const PF2_BASE_SLOTS   = 2;
const PF2_SLOT_SLUGS   = ['expositor-hueco-3', 'expositor-hueco-4', 'expositor-hueco-5'];
const PF2_TYPES        = ['games', 'shots', 'review', 'music'];
const PF2_MAX_GAMES    = 4;
const PF2_MAX_SHOTS    = 3;
const PF2_MAX_MUSIC    = 5;

/* Medallas: [clave, nombre, emoji, unidad, umbrales bronce/plata/oro]. */
const PF2_MEDALS = [
    ['listen',   'Melómano',        '🎧', 'h escuchadas',         [100, 500, 1000]],
    ['reviews',  'Crítico',         '⭐', 'reseñas',              [10, 50, 100]],
    ['art',      'Artista',         '🎨', 'obras publicadas',     [5, 25, 100]],
    ['together', 'Escuchar juntos', '🎤', 'sesiones',             [10, 50, 100]],
    ['days',     'Veterano',        '📅', 'días en Melon Hub',    [30, 180, 365]],
    ['chat',     'Charlatán',       '💬', 'mensajes',             [500, 2000, 10000]],
];
const PF2_TIER_XP = [0, 100, 300, 600];   /* XP acumulada por nivel de medalla */

/* ── Tablas (idempotente, una vez por petición) ───────────────────── */
function pf2_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_comments (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            profile_user_id  INT NOT NULL,
            author_id        INT NOT NULL,
            text             TEXT NOT NULL,
            image_url        VARCHAR(2000) NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at       TIMESTAMP NULL,
            from_post_id     INT NULL,
            from_pcomment_id INT NULL,
            UNIQUE KEY uq_from_post (from_post_id),
            UNIQUE KEY uq_from_pcomment (from_pcomment_id),
            INDEX idx_wall (profile_user_id, deleted_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_showcases (
            user_id   INT NOT NULL,
            position  TINYINT NOT NULL,
            type      VARCHAR(20) NOT NULL,
            config    TEXT NULL,
            PRIMARY KEY (user_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_screenshots (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            file        VARCHAR(80) NOT NULL,
            mime        VARCHAR(30) NOT NULL,
            data        MEDIUMBLOB NULL,
            caption     VARCHAR(200) NOT NULL DEFAULT '',
            game_title  VARCHAR(200) NOT NULL DEFAULT '',
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* sin permisos de CREATE: el resto degrada */ }
    pf2_ensureStoreItems($pdo);
}

/* Los tres huecos de la tienda (pestaña "Perfil"). Se crean si faltan;
   si ya existen no se tocan (el precio se puede cambiar en la BD). */
function pf2_ensureStoreItems(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $items = [
        ['expositor-hueco-3', 'Hueco de expositor 3', 'Un tercer expositor a la vez en tu perfil.', 500, 1],
        ['expositor-hueco-4', 'Hueco de expositor 4', 'Un cuarto expositor a la vez en tu perfil (antes, el hueco 3).', 1000, 2],
        ['expositor-hueco-5', 'Hueco de expositor 5', 'El quinto y último expositor a la vez (antes, el hueco 4).', 2000, 3],
    ];
    try {
        $chk = $pdo->prepare("SELECT 1 FROM tienda_items WHERE slug = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO tienda_items (nombre, slug, descripcion, precio, icono, activo, orden, categoria)
                              VALUES (?, ?, ?, ?, '', 1, ?, 'perfil')");
        foreach ($items as $it) {
            $chk->execute([$it[0]]);
            if ($chk->fetchColumn()) continue;
            $ins->execute([$it[1], $it[0], $it[2], $it[3], $it[4]]);
        }
    } catch (Throwable $e) { /* la tienda no existe en este entorno */ }
}

/* ── Posts → comentarios del muro (por usuario, idempotente) ──────── */
function pf2_migratePosts(PDO $pdo, int $uid): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO profile_comments
                (profile_user_id, author_id, text, image_url, created_at, from_post_id)
            SELECT p.user_id, p.user_id, COALESCE(p.text, ''), p.image_url, p.created_at, p.id
              FROM posts p WHERE p.user_id = ?")->execute([$uid]);
        $pdo->prepare("INSERT IGNORE INTO profile_comments
                (profile_user_id, author_id, text, created_at, from_pcomment_id)
            SELECT p.user_id, c.user_id, c.text, c.created_at, c.id
              FROM post_comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id = ?")->execute([$uid]);
    } catch (Throwable $e) { /* sin posts en este entorno */ }
}

/* ── Huecos ──────────────────────────────────────────────────────── */
function pf2_slots(PDO $pdo, int $uid): int {
    try {
        $ph = implode(',', array_fill(0, count(PF2_SLOT_SLUGS), '?'));
        $st = $pdo->prepare("SELECT COUNT(DISTINCT i.slug) FROM tienda_compras c
                             JOIN tienda_items i ON i.id = c.item_id
                             WHERE c.user_id = ? AND i.slug IN ($ph)");
        $st->execute(array_merge([$uid], PF2_SLOT_SLUGS));
        return PF2_BASE_SLOTS + (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return PF2_BASE_SLOTS;
    }
}

/* ── Utilidades ──────────────────────────────────────────────────── */
function pf2_userRow(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("SELECT id, user_key, username, label FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function pf2_label(array $u): string { return (string)($u['label'] ?: $u['username'] ?: $u['user_key']); }
function pf2_avatar(array $u): string {
    return function_exists('getUserImage') ? (string)getUserImage(pf2_label($u)) : '';
}
function pf2_one(PDO $pdo, string $sql, array $args) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ── Comentarios del muro ────────────────────────────────────────── */
function pf2_comments(PDO $pdo, int $profileUid, int $viewerUid): array {
    try {
        $st = $pdo->prepare("SELECT c.id, c.author_id, c.text, c.image_url, UNIX_TIMESTAMP(c.created_at) AS ts,
                                    u.user_key, u.username, u.label
                               FROM profile_comments c JOIN usuarios u ON u.id = c.author_id
                              WHERE c.profile_user_id = ? AND c.deleted_at IS NULL
                              ORDER BY c.created_at DESC, c.id DESC LIMIT 100");
        $st->execute([$profileUid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'        => (int)$r['id'],
                'authorKey' => $r['user_key'],
                'author'    => pf2_label($r),
                'avatar'    => pf2_avatar($r),
                'text'      => (string)$r['text'],
                'imageUrl'  => $r['image_url'] ?: null,
                'ts'        => (int)$r['ts'],
                /* Borra el dueño del muro (cualquiera) o quien lo escribió. */
                'canDelete' => $viewerUid === $profileUid || $viewerUid === (int)$r['author_id'],
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* ── Medallas, nivel y actividad ─────────────────────────────────── */
function pf2_stats(PDO $pdo, int $uid): array {
    $secs = (int)pf2_one($pdo, "SELECT COALESCE(SUM(IF(duration_s > 0, duration_s, 180)), 0) FROM music_plays WHERE user_id = ?", [$uid]);
    $vals = [
        'listen'   => intdiv($secs, 3600),
        'reviews'  => (int)pf2_one($pdo, "SELECT COUNT(*) FROM list_item_reviews WHERE user_id = ?", [$uid]),
        'art'      => (int)pf2_one($pdo, "SELECT COUNT(*) FROM webhook_posts WHERE user_id = ? AND kind = 'discord'", [$uid]),
        'together' => (int)pf2_one($pdo, "SELECT (SELECT COUNT(*) FROM listening_participants WHERE user_id = ?)
                                               + (SELECT COUNT(*) FROM listening_sessions WHERE host_user_id = ?)", [$uid, $uid]),
        'days'     => (int)pf2_one($pdo, "SELECT GREATEST(0, DATEDIFF(NOW(), created_at)) FROM usuarios WHERE id = ?", [$uid]),
        'chat'     => (int)pf2_one($pdo, "SELECT COUNT(*) FROM messages WHERE from_user_id = ?", [$uid]),
    ];
    $medals = [];
    $xp = 0;
    foreach (PF2_MEDALS as [$key, $name, $emoji, $unit, $th]) {
        $v = $vals[$key];
        $tier = 0;
        foreach ($th as $i => $t) if ($v >= $t) $tier = $i + 1;
        $xp += PF2_TIER_XP[$tier];
        $next = $tier < 3 ? $th[$tier] : null;
        $prev = $tier > 0 ? $th[$tier - 1] : 0;
        $medals[] = [
            'key'      => $key,
            'name'     => $name,
            'emoji'    => $emoji,
            'unit'     => $unit,
            'value'    => $v,
            'tier'     => $tier,                 /* 0 = aún sin medalla */
            'next'     => $next,                 /* siguiente umbral (null = oro) */
            'progress' => $next === null ? 100 : (int)floor(100 * max(0, $v - $prev) / max(1, $next - $prev)),
        ];
    }
    return [
        'level'   => 1 + intdiv($xp, 100),
        'xp'      => $xp,
        'xpInto'  => $xp % 100,
        'medals'  => $medals,
        'counts'  => [
            'reviews' => $vals['reviews'],
            'shots'   => (int)pf2_one($pdo, "SELECT COUNT(*) FROM profile_screenshots WHERE user_id = ?", [$uid]),
            'hours'   => $vals['listen'],
            'days'    => $vals['days'],
        ],
    ];
}

/* ── Amigos (seguimiento mutuo) ──────────────────────────────────── */
function pf2_friends(PDO $pdo, int $uid): array {
    require_once dirname(__DIR__) . '/social-helpers.php';
    $ids = mutualFollowerIds($pdo, $uid);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, user_key, username, label FROM usuarios WHERE id IN ($ph) ORDER BY label");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['key' => $r['user_key'], 'label' => pf2_label($r), 'image' => pf2_avatar($r)];
    }
    return $out;
}

/* ── Catálogo de juegos (los registrados en las reseñas/listas) ────── */
function pf2_gameCatalog(PDO $pdo, string $q, int $limit = 30): array {
    $q = trim($q);
    $args = [];
    $where = "category = 'games' AND title <> ''";
    if ($q !== '') { $where .= " AND title LIKE ?"; $args[] = '%' . $q . '%'; }
    try {
        /* Un juego por título (sin distinguir mayúsculas); la imagen, la
           primera que haya. */
        $st = $pdo->prepare("SELECT MIN(title) AS title, MAX(NULLIF(image, '')) AS image, COUNT(*) AS n
                               FROM list_items WHERE $where
                              GROUP BY LOWER(title) ORDER BY n DESC, title LIMIT " . (int)$limit);
        $st->execute($args);
        return array_map(fn($r) => ['title' => $r['title'], 'image' => (string)$r['image']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/* Imagen de un juego por título: la del dueño o, si no tiene, cualquiera. */
function pf2_gameImage(PDO $pdo, int $uid, string $title): string {
    $img = pf2_one($pdo, "SELECT image FROM list_items WHERE owner_id = ? AND category = 'games' AND LOWER(title) = LOWER(?) AND image <> '' LIMIT 1", [$uid, $title]);
    if (!$img) $img = pf2_one($pdo, "SELECT image FROM list_items WHERE category = 'games' AND LOWER(title) = LOWER(?) AND image <> '' LIMIT 1", [$title]);
    return (string)($img ?: '');
}

/* Reseña del dueño de un item de su lista por categoría y título. */
function pf2_ownReview(PDO $pdo, int $uid, string $cat, string $title): ?array {
    try {
        $st = $pdo->prepare("SELECT i.id, i.title, i.image, i.artist, r.stars, r.comment
                               FROM list_items i
                               JOIN list_item_reviews r ON r.item_id = i.id AND r.user_id = ?
                              WHERE i.owner_id = ? AND i.category = ? AND LOWER(i.title) = LOWER(?) LIMIT 1");
        $st->execute([$uid, $uid, $cat, $title]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ── Expositores ─────────────────────────────────────────────────── */
function pf2_cleanConfig(string $type, $cfg): array {
    $cfg = is_array($cfg) ? $cfg : [];
    switch ($type) {
        case 'games':
            $t = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 200), (array)($cfg['titles'] ?? [])), 'strlen'));
            return ['titles' => array_slice(array_values(array_unique($t)), 0, PF2_MAX_GAMES)];
        case 'shots':
            return ['ids' => array_slice(array_values(array_unique(array_map('intval', (array)($cfg['ids'] ?? [])))), 0, PF2_MAX_SHOTS)];
        case 'review':
            $cat = in_array($cfg['category'] ?? '', ['movies', 'series', 'books', 'games', 'music'], true) ? $cfg['category'] : '';
            return ['category' => $cat, 'title' => mb_substr(trim((string)($cfg['title'] ?? '')), 0, 200)];
        case 'music':
            return ['ids' => array_slice(array_values(array_unique(array_map('intval', (array)($cfg['ids'] ?? [])))), 0, PF2_MAX_MUSIC)];
    }
    return [];
}

function pf2_rawShowcases(PDO $pdo, int $uid): array {
    try {
        $st = $pdo->prepare("SELECT type, config FROM profile_showcases WHERE user_id = ? ORDER BY position");
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array($r['type'], PF2_TYPES, true)) continue;
            $out[] = ['type' => $r['type'], 'config' => pf2_cleanConfig($r['type'], json_decode((string)$r['config'], true))];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function pf2_saveShowcases(PDO $pdo, int $uid, array $list): array {
    $slots = pf2_slots($pdo, $uid);
    $clean = [];
    foreach ($list as $s) {
        $type = (string)($s['type'] ?? '');
        if (!in_array($type, PF2_TYPES, true)) continue;
        $clean[] = ['type' => $type, 'config' => pf2_cleanConfig($type, $s['config'] ?? [])];
    }
    if (count($clean) > $slots) {
        return ['ok' => false, 'error' => 'Solo tienes ' . $slots . ' huecos de expositor.'];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM profile_showcases WHERE user_id = ?")->execute([$uid]);
        $ins = $pdo->prepare("INSERT INTO profile_showcases (user_id, position, type, config) VALUES (?, ?, ?, ?)");
        foreach ($clean as $i => $s) $ins->execute([$uid, $i, $s['type'], json_encode($s['config'], JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'No se pudieron guardar los expositores.'];
    }
    return ['ok' => true];
}

/* Expositores con sus datos ya listos para pintar. */
function pf2_showcases(PDO $pdo, int $uid): array {
    $out = [];
    foreach (pf2_rawShowcases($pdo, $uid) as $s) {
        $cfg = $s['config'];
        $data = [];
        switch ($s['type']) {
            case 'games':
                foreach ($cfg['titles'] as $t) {
                    $rev = pf2_ownReview($pdo, $uid, 'games', $t);
                    $data[] = [
                        'title' => $t,
                        'image' => pf2_gameImage($pdo, $uid, $t),
                        'stars' => $rev ? (float)$rev['stars'] : null,
                    ];
                }
                break;
            case 'shots':
                if ($cfg['ids']) {
                    $ph = implode(',', array_fill(0, count($cfg['ids']), '?'));
                    $st = $pdo->prepare("SELECT id, caption, game_title FROM profile_screenshots WHERE user_id = ? AND id IN ($ph)");
                    $st->execute(array_merge([$uid], $cfg['ids']));
                    $byId = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = $r;
                    foreach ($cfg['ids'] as $id) {
                        if (!isset($byId[$id])) continue;
                        $data[] = ['id' => $id, 'url' => 'assets/profile/screenshot.php?id=' . $id,
                                   'caption' => $byId[$id]['caption'], 'game' => $byId[$id]['game_title']];
                    }
                }
                break;
            case 'review':
                if ($cfg['category'] !== '' && $cfg['title'] !== '') {
                    $rev = pf2_ownReview($pdo, $uid, $cfg['category'], $cfg['title']);
                    if ($rev) $data = [
                        'category' => $cfg['category'],
                        'title'    => $rev['title'],
                        'artist'   => (string)($rev['artist'] ?? ''),
                        'image'    => (string)$rev['image'],
                        'stars'    => (float)$rev['stars'],
                        'comment'  => (string)$rev['comment'],
                    ];
                }
                break;
            case 'music':
                if ($cfg['ids']) {
                    $ph = implode(',', array_fill(0, count($cfg['ids']), '?'));
                    $st = $pdo->prepare("SELECT * FROM list_items WHERE owner_id = ? AND category = 'music' AND id IN ($ph)");
                    $st->execute(array_merge([$uid], $cfg['ids']));
                    $byId = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = $r;
                    foreach ($cfg['ids'] as $id) {
                        if (!isset($byId[$id])) continue;
                        $r = $byId[$id];
                        $data[] = [
                            'id'       => $id,
                            'title'    => $r['title'],
                            'artist'   => (string)($r['artist'] ?? ''),
                            'type'     => $r['music_type'] ?: 'song',
                            'ytId'     => (string)($r['yt_id'] ?? ''),
                            'ytPlaylistId'   => (string)($r['yt_playlist_id'] ?? ''),
                            'spotifyAlbumId' => (string)($r['spotify_album_id'] ?? ''),
                            /* Portada del ÁLBUM: la guardada si no es una
                               miniatura de YouTube; si no, la que find-album
                               dejó en caché. Sin ninguna, el navegador la
                               busca (find-album). */
                            'cover'    => pf2_albumCover($r),
                        ];
                    }
                }
                break;
        }
        $out[] = ['type' => $s['type'], 'config' => $cfg, 'data' => $data];
    }
    return $out;
}

function pf2_albumCover(array $item): string {
    $img = (string)($item['image'] ?? '');
    if ($img !== '' && !preg_match('#(ytimg\.com|youtube\.com|img\.youtube)#i', $img)) return $img;
    $vid = (string)($item['yt_id'] ?? '');
    if ($vid !== '') {
        $c = json_decode((string)cacheGet('album_track_v1_' . $vid), true);
        if (is_array($c) && !empty($c['albumImage'])) return (string)$c['albumImage'];
    }
    return '';
}

/* ── Todo junto (lo que añaden get-profile y view-user) ─────────────── */
function pf2_profile(PDO $pdo, int $uid, int $viewerUid): array {
    pf2_ensure($pdo);
    pf2_migratePosts($pdo, $uid);
    return [
        'slots'     => pf2_slots($pdo, $uid),
        'maxSlots'  => PF2_BASE_SLOTS + count(PF2_SLOT_SLUGS),
        'showcases' => pf2_showcases($pdo, $uid),
        'comments'  => pf2_comments($pdo, $uid, $viewerUid),
        'stats'     => pf2_stats($pdo, $uid),
        'friends'   => pf2_friends($pdo, $uid),
    ];
}
