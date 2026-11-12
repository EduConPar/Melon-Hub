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
     y 5, en orden). Tipos: media (Media favorita: juegos, películas,
     series, libros o música, uno por tipo; 3 elementos + mejoras
     media-<tipo>-4/5), shots, review, steam_rare, steam_ach (filas de 7
     logros + mejoras steam-rare|ach-fila-2/3, máximo 3 filas), game_feat (Juego
     destacado), playlist (Playlist destacada) y text (Texto libre).
     Reseña, Juego y Playlist destacada se compran en la tienda
     (PF2_PAID): sin comprar se guardan pero no salen. Los tipos viejos
     games / movies / series / books / music pasan a media al leerlos.
   - Capturas (profile_screenshots): imágenes de la Galería del usuario
     (su Google Drive). El navegador las hace públicas en Drive y aquí solo
     se guarda el enlace (drive_id + url), su pie de foto y el juego. Las
     primeras versiones guardaban la imagen subida (file/mime/data, en
     uploads/screenshots/ con copia en la BD); screenshot.php sigue
     sirviendo esas.
   - Medallas (bronce / plata / oro) y nivel con lo que ya se guarda:
     bronce 100 XP, plata +200, oro +300. El nivel sube cada vez más
     despacio: del 1 al 2 hacen falta 100 XP, del 2 al 3 otros 200, del
     3 al 4 otros 300… (pf2_nivel). Las medallas no dan puntos de autismo.
   ────────────────────────────────────────────────────────────────────── */

require_once dirname(__DIR__) . '/cache-helpers.php';   /* portadas de álbum en caché (find-album) */
require_once __DIR__ . '/steam.php';                     /* fase 2: horas y logros de Steam */
require_once __DIR__ . '/racha.php';                     /* racha diaria (fueguito) e invitaciones */

const PF2_BASE_SLOTS   = 2;
const PF2_SLOT_SLUGS   = ['expositor-hueco-3', 'expositor-hueco-4', 'expositor-hueco-5'];
const PF2_TYPES        = ['media', 'shots', 'review', 'steam_rare', 'steam_ach', 'game_feat', 'playlist', 'text'];
const PF2_FAV_CATS     = ['games', 'movies', 'series', 'books'];   /* catálogo de títulos (game-catalog) */
const PF2_MEDIA_CATS   = ['games', 'movies', 'series', 'books', 'music'];
const PF2_MEDIA_BASE   = 3;                                        /* + media-<tipo>-4 y -5 */
const PF2_MEDIA_MAX    = 5;
const PF2_ACH_ROW      = 7;                                        /* logros por fila */
const PF2_ACH_ROWS_MAX = 3;                                        /* 1 + steam-…-fila-2/3 (máximo 3 filas) */
const PF2_MAX_STEAM_ACH = PF2_ACH_ROW * PF2_ACH_ROWS_MAX;
/* Expositores que se compran en la tienda (tipo => slug). */
const PF2_PAID         = ['review' => 'expositor-resena', 'game_feat' => 'expositor-juego-destacado', 'playlist' => 'expositor-playlist'];
const PF2_MAX_FEAT_ACH = 8;
const PF2_MAX_TEXT     = 1000;
const PF2_PLAYLIST_TRACKS = 5;
const PF2_MAX_SHOTS    = 3;

/* Medallas: [clave, nombre, emoji, unidad, umbrales bronce/plata/oro].
   De dónde sale cada número: pf2_stats. "Veterano" va por años (365,
   730, 1095 días). Las no ganadas no se enseñan en el perfil. */
const PF2_MEDALS = [
    ['listen',  'Melómano',    '🎧', 'h escuchadas',           [100, 500, 1000]],
    ['reviews', 'Crítico',     '⭐', 'reseñas',                [10, 50, 100]],
    ['art',     'Artista',     '🎨', 'obras publicadas',       [5, 25, 100]],
    ['days',    'Veterano',    '📅', 'días en Melon Hub',      [365, 730, 1095]],
    ['games',   'Jugador',     '🎮', 'partidas ganadas',       [50, 250, 500]],
    ['spent',   'Derrochador', '💸', 'puntos de autismo gastados', [1000, 5000, 20000]],
];
const PF2_TIER_XP = [0, 100, 300, 600];   /* XP acumulada por nivel de medalla */
/* Dibujo de cada medalla (assets/img/Medallas/<base><nivel>.png, 64×64,
   un PNG por nivel: 1 bronce, 2 plata, 3 oro). Sin entrada aquí, el
   dibujo SVG + emoji de siempre. La de Tester es una sola imagen. */
const PF2_MEDAL_SPRITES = [
    'listen'  => 'MedallaMusica',
    'reviews' => 'MedallaResenas',
    'art'     => 'MedallaArte',
    'days'    => 'MedallaTiempo',
    'games'   => 'MedallaJuegos',
    'spent'   => 'MedallaPuntos',
];
const PF2_TESTER_SPRITE = 'assets/img/Medallas/MedallaTester.png';
/* Medalla única "Tester" (🖥️, color de acento): la lista se lleva a mano.
   Nombres de usuario de iniciar sesión, en minúsculas (usuarios.username).
   No da XP ni puntos y a quien no está en la lista no le sale. */
const PF2_TESTERS = [
];

/* ── Tablas (idempotente, una vez por petición) ───────────────────── */
function pf2_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no en
       cada petición: ver .claude/notas/rendimiento.md. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'perfil-v2', __FILE__, function (PDO $pdo) {
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
                file        VARCHAR(80) NULL,
                mime        VARCHAR(30) NULL,
                data        MEDIUMBLOB NULL,
                drive_id    VARCHAR(80) NULL,
                url         VARCHAR(500) NULL,
                caption     VARCHAR(200) NOT NULL DEFAULT '',
                game_title  VARCHAR(200) NOT NULL DEFAULT '',
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { /* sin permisos de CREATE: el resto degrada */ }
        /* Tablas de la primera versión (capturas subidas): columnas de Drive. */
        try {
            if (!$pdo->query("SHOW COLUMNS FROM profile_screenshots LIKE 'drive_id'")->fetch()) {
                $pdo->exec("ALTER TABLE profile_screenshots
                              MODIFY file VARCHAR(80) NULL, MODIFY mime VARCHAR(30) NULL,
                              ADD COLUMN drive_id VARCHAR(80) NULL, ADD COLUMN url VARCHAR(500) NULL");
            }
        } catch (Throwable $e) { /* ya estaba */ }
    });
    /* Fecha de alta (medalla Veterano y "En Melon Hub N días"). La tabla
       usuarios no tenía created_at y la consulta fallaba en silencio (0
       días). Se añade y a las cuentas de antes se les pone lo más viejo
       que se sabe de ellas (primera canción, mensaje, reseña, compra…);
       solo toca filas sin fecha, así que repetirlo no cambia nada. Las
       cuentas nuevas la llevan sola (DEFAULT CURRENT_TIMESTAMP). */
    melon_esquema($pdo, 'usuarios-alta', __FILE__, function (PDO $pdo) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT NULL");
        $fuentes = [
            "SELECT user_id u, MIN(played_at) f FROM music_plays GROUP BY user_id",
            "SELECT from_user_id u, MIN(sent_at) f FROM messages GROUP BY from_user_id",
            "SELECT user_id u, MIN(reviewed_at) f FROM list_item_reviews GROUP BY user_id",
            "SELECT user_id u, MIN(created_at) f FROM posts GROUP BY user_id",
            "SELECT follower_id u, MIN(created_at) f FROM follows GROUP BY follower_id",
            "SELECT user_id u, MIN(created_at) f FROM notifications GROUP BY user_id",
            "SELECT user_id u, MIN(creado_en) f FROM tienda_compras GROUP BY user_id",
            "SELECT author_id u, MIN(created_at) f FROM profile_comments GROUP BY author_id",
            "SELECT user_id u, MIN(created_at) f FROM profile_screenshots GROUP BY user_id",
            "SELECT user_id u, MIN(dia) f FROM wordelon_partidas GROUP BY user_id",
            "SELECT user_id u, MIN(dia) f FROM bm_partidas WHERE dia IS NOT NULL GROUP BY user_id",
            "SELECT user_id u, MIN(updated_at) f FROM user_settings GROUP BY user_id",
        ];
        $primero = [];
        foreach ($fuentes as $sql) {
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $u = (int)$r['u']; $f = (string)$r['f'];
                    if ($u > 0 && $f !== '' && $f > '2000' && (!isset($primero[$u]) || $f < $primero[$u])) $primero[$u] = $f;
                }
            } catch (Throwable $e) { /* esa tabla o columna no está en este entorno */ }
        }
        /* La mejor pista: desktops/<usuario>-desktop.php se escribe solo al
           registrarse (register-user.php) y el deploy no lo toca (está en
           .gitignore), así que su fecha es la del alta. Las de capi/angie
           van en git (fecha del deploy, más nueva): por eso se coge la más
           vieja de todas las pistas. */
        $raiz = dirname(__DIR__, 2) . '/desktops/';
        foreach ($pdo->query("SELECT id, username, label FROM usuarios WHERE created_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (array_unique([strtolower((string)$r['username']), strtolower((string)$r['label'])]) as $nom) {
                $t = $nom !== '' ? @filemtime($raiz . $nom . '-desktop.php') : false;
                if (!$t) continue;
                $f = date('Y-m-d H:i:s', $t);
                $u = (int)$r['id'];
                if (!isset($primero[$u]) || $f < $primero[$u]) $primero[$u] = $f;
            }
        }
        $st = $pdo->prepare("UPDATE usuarios SET created_at = ? WHERE id = ? AND created_at IS NULL");
        foreach ($primero as $u => $f) $st->execute([$f, $u]);
        $pdo->exec("UPDATE usuarios SET created_at = NOW() WHERE created_at IS NULL");
        $pdo->exec("ALTER TABLE usuarios MODIFY created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
    });
    /* Obras publicadas en Discord (medalla Artista): api.php
       (publish-discord) las apunta aquí, pero la tabla no la creaba nadie. */
    melon_esquema($pdo, 'webhook-posts', __FILE__, function (PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_posts (
            message_id VARCHAR(40) PRIMARY KEY,
            user_id    INT NOT NULL,
            kind       VARCHAR(20) NOT NULL DEFAULT 'discord',
            channel_id VARCHAR(40) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    });
    /* El catálogo de la tienda, siempre (tiene su propia huella). */
    pf2_ensureStoreItems($pdo);
}

/* Los huecos de la tienda (pestaña "Perfil", expositor-hueco-3/4/5)
   están en assets/tienda/catalogo.json con el resto de objetos. */
function pf2_ensureStoreItems(PDO $pdo): void {
    require_once dirname(__DIR__) . '/tienda/catalogo.php';
    tiendaSyncCatalogo($pdo);
    tiendaEnsureGastos($pdo);   /* lo gastado, para la medalla Derrochador */
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
/* Slugs comprados de la pestaña Perfil (huecos, expositores de pago y
   mejoras). Se recuerda por petición; `$fresh` lo vuelve a leer (tras
   guardar no hace falta: guardar no compra nada). */
function pf2_ownedSlugs(PDO $pdo, int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    try {
        $st = $pdo->prepare("SELECT DISTINCT i.slug FROM tienda_compras c
                             JOIN tienda_items i ON i.id = c.item_id
                             WHERE c.user_id = ? AND i.slug IS NOT NULL");
        $st->execute([$uid]);
        return $cache[$uid] = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        return [];
    }
}

function pf2_slots(PDO $pdo, int $uid): int {
    $own = pf2_ownedSlugs($pdo, $uid);
    $n = 0;
    foreach (PF2_SLOT_SLUGS as $sl) if (isset($own[$sl])) $n++;
    return PF2_BASE_SLOTS + $n;
}

/* Lo comprado que cambia los expositores (`v2.perks`):
     unlocked  — tipo de pago => comprado;
     media     — tipo => cuántos elementos caben (3, 4 o 5);
     steamRows — steam_rare / steam_ach => filas de 7 logros (1 a 3). */
function pf2_perks(PDO $pdo, int $uid): array {
    $own = pf2_ownedSlugs($pdo, $uid);
    $unlocked = [];
    foreach (PF2_PAID as $type => $slug) $unlocked[$type] = isset($own[$slug]);
    $media = [];
    foreach (PF2_MEDIA_CATS as $cat) {
        $media[$cat] = PF2_MEDIA_BASE + (isset($own["media-$cat-4"]) ? 1 : 0) + (isset($own["media-$cat-5"]) ? 1 : 0);
    }
    $rows = [];
    foreach (['steam_rare' => 'rare', 'steam_ach' => 'ach'] as $type => $k) {
        $r = 1;
        for ($i = 2; $i <= PF2_ACH_ROWS_MAX; $i++) if (isset($own["steam-$k-fila-$i"])) $r++;
        $rows[$type] = $r;
    }
    return ['unlocked' => $unlocked, 'media' => $media, 'steamRows' => $rows];
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
/* Nivel con la XP total: del 1 al 2 cuestan 100, del 2 al 3 otros 200,
   del N al N+1 otros N×100. Devuelve [nivel, XP dentro de ese nivel]. */
function pf2_nivel(int $xp): array {
    $nivel = 1;
    while ($xp >= 100 * $nivel) { $xp -= 100 * $nivel; $nivel++; }
    return [$nivel, $xp];
}

function pf2_stats(PDO $pdo, int $uid): array {
    $secs = (int)pf2_one($pdo, "SELECT COALESCE(SUM(IF(duration_s > 0, duration_s, 180)), 0) FROM music_plays WHERE user_id = ?", [$uid]);
    /* Cada parte va por separado (pf2_one da 0 si falla): si un juego o
       una tabla no está, el resto se sigue contando. */
    $n = fn(string $sql, array $a) => (int)pf2_one($pdo, $sql, $a);
    $vals = [
        /* Lo escuchado de verdad (el reproductor manda los segundos). */
        'listen'  => intdiv($secs, 3600),
        /* Reseñas con estrellas que siguen en una lista (no las de cosas borradas). */
        'reviews' => $n("SELECT COUNT(*) FROM list_item_reviews r JOIN list_items i ON i.id = r.item_id WHERE r.user_id = ?", [$uid]),
        'art'     => $n("SELECT COUNT(*) FROM webhook_posts WHERE user_id = ? AND kind = 'discord'", [$uid]),
        'days'    => $n("SELECT GREATEST(0, DATEDIFF(NOW(), created_at)) FROM usuarios WHERE id = ?", [$uid]),
        /* Ganadas: Wordelon acertado, Buscaminas ganada (diaria o ronda
           del infinito) y Ajedrez ganado (retos y torneos). */
        'games'   => $n("SELECT COUNT(*) FROM wordelon_partidas WHERE user_id = ? AND acertado = 1", [$uid])
                   + $n("SELECT COUNT(*) FROM bm_partidas WHERE user_id = ? AND estado = 'ganada'", [$uid])
                   + $n("SELECT COUNT(*) FROM ajz_partidas WHERE estado = 'terminada'
                           AND ((blanco_id = ? AND resultado = '1-0') OR (negro_id = ? AND resultado = '0-1'))", [$uid, $uid]),
        /* Gastados: compras de la tienda (tienda_gastos, una fila por
           compra), regalos enviados que no se devolvieron y apuestas de
           ajedrez perdidas. */
        'spent'   => $n("SELECT COALESCE(SUM(precio), 0) FROM tienda_gastos WHERE user_id = ?", [$uid])
                   + $n("SELECT COALESCE(SUM(precio), 0) FROM tienda_regalos WHERE from_user_id = ? AND estado NOT IN ('rechazado', 'devuelto')", [$uid])
                   + $n("SELECT COALESCE(SUM(apuesta), 0) FROM ajz_partidas WHERE estado = 'terminada' AND apuesta > 0
                           AND ((blanco_id = ? AND resultado = '0-1') OR (negro_id = ? AND resultado = '1-0'))", [$uid, $uid]),
    ];
    $medals = [];
    $xp = 0;
    foreach (PF2_MEDALS as [$key, $name, $emoji, $unit, $th]) {
        $v = $vals[$key];
        $tier = 0;
        foreach ($th as $i => $t) if ($v >= $t) $tier = $i + 1;
        $xp += PF2_TIER_XP[$tier];
        $next = $tier < count($th) ? $th[$tier] : null;
        $prev = $tier > 0 ? $th[$tier - 1] : 0;
        $medals[] = [
            'key'      => $key,
            'name'     => $name,
            'emoji'    => $emoji,
            'unit'     => $unit,
            'value'    => $v,
            'tier'     => $tier,                 /* 0 = aún sin medalla */
            'next'     => $next,                 /* siguiente umbral (null = el último nivel) */
            'progress' => $next === null ? 100 : (int)floor(100 * max(0, $v - $prev) / max(1, $next - $prev)),
            'sprite'   => isset(PF2_MEDAL_SPRITES[$key]) && $tier > 0
                ? 'assets/img/Medallas/' . PF2_MEDAL_SPRITES[$key] . $tier . '.png' : null,
        ];
    }
    $user = strtolower((string)pf2_one($pdo, "SELECT username FROM usuarios WHERE id = ?", [$uid]));
    if ($user !== '' && in_array($user, array_map('strtolower', PF2_TESTERS), true)) {
        $medals[] = ['key' => 'tester', 'name' => 'Tester', 'emoji' => '🖥️', 'unit' => '', 'value' => 0,
                     'tier' => 1, 'next' => null, 'progress' => 100, 'unique' => true,
                     'desc' => 'Ayudó a probar Melon Hub', 'sprite' => PF2_TESTER_SPRITE];
    }
    [$level, $into] = pf2_nivel($xp);
    return [
        'level'   => $level,
        'xp'      => $xp,
        'xpInto'  => $into,              /* XP dentro del nivel actual */
        'xpNeed'  => 100 * $level,       /* XP que hace falta para el siguiente */
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
    /* Última conexión (para "Última conexión: hace X"); el en línea y lo
       que escucha lo pone el navegador con la presencia de cada 20 s. */
    $vistos = [];
    try {
        $sp = $pdo->prepare("SELECT user_id, UNIX_TIMESTAMP(last_at) FROM user_presence WHERE user_id IN ($ph)");
        $sp->execute($ids);
        $vistos = $sp->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { /* sin tabla de presencia */ }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['key' => $r['user_key'], 'label' => pf2_label($r), 'image' => pf2_avatar($r),
                  'level' => (int)pf2_stats($pdo, (int)$r['id'])['level'],
                  'lastSeen' => (int)($vistos[$r['id']] ?? 0)];
    }
    /* Como en Steam: de mayor a menor nivel (a igualdad, por nombre). */
    usort($out, fn($a, $b) => [$b['level'], mb_strtolower($a['label'])] <=> [$a['level'], mb_strtolower($b['label'])]);
    return $out;
}

/* ── Catálogo de juegos (los registrados en las reseñas/listas) ──────
   También el de películas, series y libros (sus "favoritos"). */
function pf2_gameCatalog(PDO $pdo, string $q, int $limit = 30, string $cat = 'games'): array {
    if (!in_array($cat, PF2_FAV_CATS, true)) return [];
    $q = trim($q);
    $args = [$cat];
    $where = "category = ? AND title <> ''";
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

/* Imagen de un juego (o película, serie, libro) por título: la del dueño
   o, si no tiene, cualquiera. */
function pf2_gameImage(PDO $pdo, int $uid, string $title, string $cat = 'games'): string {
    $img = pf2_one($pdo, "SELECT image FROM list_items WHERE owner_id = ? AND category = ? AND LOWER(title) = LOWER(?) AND image <> '' LIMIT 1", [$uid, $cat, $title]);
    if (!$img) $img = pf2_one($pdo, "SELECT image FROM list_items WHERE category = ? AND LOWER(title) = LOWER(?) AND image <> '' LIMIT 1", [$cat, $title]);
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
        case 'media':
            /* Se guardan hasta 5 aunque solo quepan 3 (sin las mejoras salen
               los primeros; al comprarlas vuelven los demás). */
            $cat = in_array($cfg['cat'] ?? '', PF2_MEDIA_CATS, true) ? $cfg['cat'] : 'games';
            if ($cat === 'music') {
                return ['cat' => $cat, 'ids' => array_slice(array_values(array_unique(array_map('intval', (array)($cfg['ids'] ?? [])))), 0, PF2_MEDIA_MAX)];
            }
            $t = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 200), (array)($cfg['titles'] ?? [])), 'strlen'));
            return ['cat' => $cat, 'titles' => array_slice(array_values(array_unique($t)), 0, PF2_MEDIA_MAX)];
        case 'game_feat':
            $ach = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), (array)($cfg['ach'] ?? [])), 'strlen')));
            return ['title' => mb_substr(trim((string)($cfg['title'] ?? '')), 0, 200), 'ach' => array_slice($ach, 0, PF2_MAX_FEAT_ACH)];
        case 'playlist':
            return ['id' => max(0, (int)($cfg['id'] ?? 0))];
        case 'text':
            $t = str_replace(["\r\n", "\r"], "\n", (string)($cfg['text'] ?? ''));
            return ['text' => mb_substr(trim($t), 0, PF2_MAX_TEXT)];
        case 'shots':
            return ['ids' => array_slice(array_values(array_unique(array_map('intval', (array)($cfg['ids'] ?? [])))), 0, PF2_MAX_SHOTS)];
        case 'review':
            $cat = in_array($cfg['category'] ?? '', ['movies', 'series', 'books', 'games', 'music'], true) ? $cfg['category'] : '';
            return ['category' => $cat, 'title' => mb_substr(trim((string)($cfg['title'] ?? '')), 0, 200)];
        case 'steam_rare':
            return [];
        case 'steam_ach':
            $items = [];
            foreach ((array)($cfg['items'] ?? []) as $it) {
                $app = (int)($it['appid'] ?? 0);
                $api = mb_substr(trim((string)($it['apiname'] ?? '')), 0, 120);
                /* game: nombre del juego (los que no están en su biblioteca no
                   lo traen de Steam; steam_picked). */
                $game = mb_substr(trim((string)($it['game'] ?? '')), 0, 200);
                if ($app > 0 && $api !== '') $items[$app . '|' . $api] = ['appid' => $app, 'apiname' => $api, 'game' => $game];
            }
            return ['items' => array_slice(array_values($items), 0, PF2_MAX_STEAM_ACH)];
    }
    return [];
}

/* Tipo + config limpios; los viejos (games, movies, series, books,
   music) pasan a Media favorita de ese tipo. null = tipo desconocido. */
function pf2_normShowcase(string $type, $cfg): ?array {
    $cfg = is_array($cfg) ? $cfg : [];
    if (in_array($type, PF2_MEDIA_CATS, true)) { $cfg['cat'] = $type; $type = 'media'; }
    if (!in_array($type, PF2_TYPES, true)) return null;
    return ['type' => $type, 'config' => pf2_cleanConfig($type, $cfg)];
}

/* Una Media favorita por tipo: si se repite, vale la primera. */
function pf2_dedupeMedia(array $list): array {
    $seen = [];
    $out = [];
    foreach ($list as $s) {
        if ($s['type'] === 'media') {
            if (isset($seen[$s['config']['cat']])) continue;
            $seen[$s['config']['cat']] = true;
        }
        $out[] = $s;
    }
    return $out;
}

function pf2_rawShowcases(PDO $pdo, int $uid): array {
    try {
        $st = $pdo->prepare("SELECT type, config FROM profile_showcases WHERE user_id = ? ORDER BY position");
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = pf2_normShowcase((string)$r['type'], json_decode((string)$r['config'], true));
            if ($n) $out[] = $n;
        }
        return pf2_dedupeMedia($out);
    } catch (Throwable $e) { return []; }
}

function pf2_saveShowcases(PDO $pdo, int $uid, array $list): array {
    $slots = pf2_slots($pdo, $uid);
    $clean = [];
    foreach ($list as $s) {
        $n = pf2_normShowcase((string)($s['type'] ?? ''), $s['config'] ?? []);
        if ($n) $clean[] = $n;
    }
    $clean = pf2_dedupeMedia($clean);
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
    $raw = pf2_rawShowcases($pdo, $uid);
    /* Steam (fase 2): solo si está vinculado y hay clave. */
    $sid = steam_key() !== '' ? steam_idOf($pdo, $uid) : '';
    $owned = $sid !== '' ? steam_owned($sid) : null;
    $perks = pf2_perks($pdo, $uid);
    $favTitles = [];
    foreach ($raw as $s) {
        if ($s['type'] === 'media' && $s['config']['cat'] === 'games') $favTitles = array_slice($s['config']['titles'], 0, $perks['media']['games']);
    }
    foreach ($raw as $s) {
        $cfg = $s['config'];
        $data = [];
        /* De pago sin comprar: se conserva lo elegido, pero sin datos (no
           sale; el dueño lo ve en Personalizar como bloqueado). */
        if (isset(PF2_PAID[$s['type']]) && !$perks['unlocked'][$s['type']]) {
            $out[] = ['type' => $s['type'], 'config' => $cfg, 'data' => [], 'locked' => true];
            continue;
        }
        /* Media favorita: se pinta como el tipo que tenga, con los
           elementos que quepan (3 + mejoras). */
        $kind = $s['type'];
        if ($kind === 'media') {
            $kind = $cfg['cat'];
            $lim = $perks['media'][$kind];
            if ($kind === 'music') $cfg['ids'] = array_slice($cfg['ids'], 0, $lim);
            else $cfg['titles'] = array_slice($cfg['titles'], 0, $lim);
        }
        switch ($kind) {
            case 'games':
                foreach ($cfg['titles'] as $t) {
                    $rev = pf2_ownReview($pdo, $uid, 'games', $t);
                    $data[] = [
                        'title' => $t,
                        'image' => pf2_gameImage($pdo, $uid, $t),
                        'stars' => $rev ? (float)$rev['stars'] : null,
                        /* null = sin Steam; inSteam false = no está en su biblioteca. */
                        'steam' => $sid !== '' ? steam_gameStats($sid, $owned, $t) : null,
                    ];
                }
                break;
            case 'movies':
            case 'series':
            case 'books':
                foreach ($cfg['titles'] as $t) {
                    $rev = pf2_ownReview($pdo, $uid, $kind, $t);
                    $data[] = [
                        'title' => $t,
                        'image' => pf2_gameImage($pdo, $uid, $t, $kind),
                        'stars' => $rev ? (float)$rev['stars'] : null,
                    ];
                }
                break;
            case 'game_feat':
                if ($cfg['title'] !== '') {
                    $rev = pf2_ownReview($pdo, $uid, 'games', $cfg['title']);
                    $data = [
                        'title'   => $cfg['title'],
                        'image'   => pf2_gameImage($pdo, $uid, $cfg['title']),
                        'stars'   => $rev ? (float)$rev['stars'] : null,
                        'comment' => $rev ? (string)$rev['comment'] : '',
                        /* null = sin Steam; inSteam false = no está en su biblioteca. */
                        'steam'   => $sid !== '' ? steam_featured($sid, $owned, $cfg['title'], $cfg['ach']) : null,
                    ];
                }
                break;
            case 'playlist':
                if ($cfg['id']) $data = pf2_playlist($pdo, $uid, $cfg['id']) ?? [];
                break;
            case 'text':
                if ($cfg['text'] !== '') $data = ['text' => $cfg['text']];
                break;
            case 'shots':
                if ($cfg['ids']) {
                    $ph = implode(',', array_fill(0, count($cfg['ids']), '?'));
                    $st = $pdo->prepare("SELECT id, caption, game_title, url FROM profile_screenshots WHERE user_id = ? AND id IN ($ph)");
                    $st->execute(array_merge([$uid], $cfg['ids']));
                    $byId = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = $r;
                    foreach ($cfg['ids'] as $id) {
                        if (!isset($byId[$id])) continue;
                        $data[] = ['id' => $id, 'url' => pf2_shotUrl($byId[$id]),
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
            case 'steam_rare':
                $data = $sid !== '' ? ['items' => steam_rarest($sid, $owned, $favTitles, PF2_ACH_ROW * $perks['steamRows']['steam_rare']),
                                       'totals' => steam_totals($owned)] : [];
                break;
            case 'steam_ach':
                /* Se guardan hasta 28; salen los que quepan en sus filas. */
                $data = $sid !== '' ? ['items' => array_slice(steam_picked($sid, $owned, $cfg['items']), 0, PF2_ACH_ROW * $perks['steamRows']['steam_ach']),
                                       'totals' => steam_totals($owned)] : [];
                break;
        }
        $out[] = ['type' => $s['type'], 'config' => $s['config'], 'data' => $data];
    }
    return $out;
}

/* Playlist destacada: una propia o en la que colabora (si deja de
   serlo, o la borran, el expositor sale vacío). Nombre, portada (la suya
   o la miniatura de la primera canción, como el visor de playlists),
   número de canciones y las primeras, con las correcciones de canciones
   (song_album_overrides) como en las playlists. */
function pf2_playlist(PDO $pdo, int $uid, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT p.id, p.name, p.owner_id, u.user_key, u.username, u.label
                               FROM playlists p JOIN usuarios u ON u.id = p.owner_id
                              WHERE p.id = ? AND (p.owner_id = ? OR EXISTS (
                                    SELECT 1 FROM playlist_collaborators c WHERE c.playlist_id = p.id AND c.user_id = ?))");
        $st->execute([$id, $uid, $uid]);
        $pl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pl) return null;
        $image = '';
        try { $image = (string)pf2_one($pdo, "SELECT image_url FROM playlists WHERE id = ?", [$id]); } catch (Throwable $e) { /* sin columna aún */ }
        $count = (int)pf2_one($pdo, "SELECT COUNT(*) FROM playlist_tracks WHERE playlist_id = ?", [$id]);
        $st = $pdo->prepare("SELECT video_id AS videoId, title, artist, duration FROM playlist_tracks
                              WHERE playlist_id = ? ORDER BY position ASC LIMIT " . PF2_PLAYLIST_TRACKS);
        $st->execute([$id]);
        $tracks = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($tracks) {
            try {
                $vids = array_column($tracks, 'videoId');
                $ph = implode(',', array_fill(0, count($vids), '?'));
                $o = $pdo->prepare("SELECT video_id, new_video_id, song_title, song_artist FROM song_album_overrides WHERE video_id IN ($ph)");
                $o->execute($vids);
                $ov = [];
                foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) $ov[(string)$r['video_id']] = $r;
                foreach ($tracks as &$t) {
                    $r = $ov[(string)$t['videoId']] ?? null;
                    if (!$r) continue;
                    if ((string)$r['song_title'] !== '')  $t['title']  = $r['song_title'];
                    if ((string)$r['song_artist'] !== '') $t['artist'] = $r['song_artist'];
                    if (strlen((string)$r['new_video_id']) === 11) $t['videoId'] = $r['new_video_id'];
                }
                unset($t);
            } catch (Throwable $e) { /* sin tabla de correcciones */ }
        }
        foreach ($tracks as &$t) $t['duration'] = (int)$t['duration'];
        unset($t);
        if ($image === '' && $tracks) $image = 'https://i.ytimg.com/vi/' . $tracks[0]['videoId'] . '/mqdefault.jpg';
        $own = (int)$pl['owner_id'] === $uid;
        return [
            'id'     => (int)$pl['id'],
            'name'   => (string)$pl['name'],
            'image'  => $image,
            'count'  => $count,
            /* De quién es, si no es suya (colabora en ella). */
            'owner'  => $own ? '' : pf2_label($pl),
            'tracks' => $tracks,
        ];
    } catch (Throwable $e) { return null; }
}

/* Playlists que puede elegir (propias y en las que colabora). */
function pf2_myPlaylists(PDO $pdo, int $uid): array {
    try {
        $st = $pdo->prepare("SELECT p.id FROM playlists p WHERE p.owner_id = ?
                              UNION SELECT c.playlist_id FROM playlist_collaborators c WHERE c.user_id = ?");
        $st->execute([$uid, $uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pl = pf2_playlist($pdo, $uid, (int)$id);
            if ($pl) $out[] = $pl;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* URL de una captura: la de Drive (Galería) o, si es de las subidas de
   la primera versión, screenshot.php. */
function pf2_shotUrl(array $r): string {
    return !empty($r['url']) ? (string)$r['url'] : 'assets/profile/screenshot.php?id=' . (int)$r['id'];
}

/* Registra (o actualiza el pie y el juego de) capturas de la Galería, en
   el orden recibido. Devuelve las capturas con su id. */
function pf2_saveGalleryShots(PDO $pdo, int $uid, array $items): array {
    $out = [];
    $sel = $pdo->prepare("SELECT id FROM profile_screenshots WHERE user_id = ? AND drive_id = ? LIMIT 1");
    $upd = $pdo->prepare("UPDATE profile_screenshots SET caption = ?, game_title = ? WHERE id = ?");
    $ins = $pdo->prepare("INSERT INTO profile_screenshots (user_id, drive_id, url, caption, game_title) VALUES (?, ?, ?, ?, ?)");
    foreach (array_slice($items, 0, 20) as $it) {
        $drive = (string)($it['driveId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $drive)) continue;
        $caption = mb_substr(trim((string)($it['caption'] ?? '')), 0, 200);
        $game    = mb_substr(trim((string)($it['game'] ?? '')), 0, 200);
        $url = 'https://drive.google.com/thumbnail?id=' . $drive . '&sz=w1920';
        $sel->execute([$uid, $drive]);
        $id = (int)$sel->fetchColumn();
        if ($id) $upd->execute([$caption, $game, $id]);
        else { $ins->execute([$uid, $drive, $url, $caption, $game]); $id = (int)$pdo->lastInsertId(); }
        $out[] = ['id' => $id, 'driveId' => $drive, 'url' => $url, 'caption' => $caption, 'game' => $game];
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
        'perks'     => pf2_perks($pdo, $uid),
        'comments'  => pf2_comments($pdo, $uid, $viewerUid),
        'stats'     => pf2_stats($pdo, $uid),
        'friends'   => pf2_friends($pdo, $uid),
        'steam'     => steam_state($pdo, $uid),
        'racha'     => racha_estado($pdo, $uid, $uid === $viewerUid),
    ];
}
