<?php
/* ──────────────────────────────────────────────
   WRAPPED API — tracking de reproducciones + stats
   ──────────────────────────────────────────────
   Endpoints (?action=):
     POST log    — registra una reproducción
                   body: { videoId, title, artist, playlistId, durationS }
     GET  stats  — devuelve top songs/artists/playlists del año actual
   ────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/themes/theme-helpers.php';
require_once __DIR__ . '/spotify-helpers.php';
require_once __DIR__ . '/lastfm-helpers.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$userKey = $_SESSION['user'] ?? '';
if (!$userKey) { http_response_code(401); echo json_encode(['error'=>'No autenticado']); exit; }
$userId = userIdByKey($userKey);
if (!$userId) { http_response_code(500); echo json_encode(['error'=>'user_id no resuelto']); exit; }

/* ── Auto-migración (idempotente) ───────────────────────────────── */
require_once dirname(__DIR__) . '/php/esquema.php';   /* melon_esquema: tablas una vez por versión */
try {
    melon_esquema($pdo, 'wrapped', __FILE__, function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `music_plays` (
                `id`          BIGINT NOT NULL AUTO_INCREMENT,
                `user_id`     INT NOT NULL,
                `video_id`    VARCHAR(11) NOT NULL,
                `title`       VARCHAR(200) NOT NULL,
                `artist`      VARCHAR(200) NOT NULL DEFAULT '',
                `playlist_id` BIGINT NULL,
                `duration_s`  INT NOT NULL DEFAULT 0,
                `played_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_user_year` (`user_id`, `played_at`),
                INDEX `idx_user_video` (`user_id`, `video_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        /* Tabla de "eventos de álbum" — distinta a music_plays porque
           agrupa por TÍTULO de álbum + artista (no por video_id como las
           canciones sueltas). action_type='play' al darle a reproducir;
           'import' al añadir el álbum a una playlist propia. Cada acción
           cuenta como un "punto" en el ranking de álbumes más escuchados. */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `music_album_actions` (
                `id`               BIGINT NOT NULL AUTO_INCREMENT,
                `user_id`          INT NOT NULL,
                `album_title`      VARCHAR(200) NOT NULL,
                `artist`           VARCHAR(200) NOT NULL DEFAULT '',
                `action_type`      VARCHAR(20) NOT NULL DEFAULT 'play',
                `yt_playlist_id`   VARCHAR(40) NULL,
                `spotify_album_id` VARCHAR(40) NULL,
                `cover_url`        VARCHAR(500) NULL,
                `played_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_user_year` (`user_id`, `played_at`),
                INDEX `idx_user_album` (`user_id`, `album_title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }, true);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Schema: '.$e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'log':       actionLog();      break;
        case 'log-album': actionLogAlbum(); break;
        case 'stats':     actionStats();    break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción desconocida']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/** Registra una reproducción. Anti-spam: ignora si la misma canción
 *  se registró en los últimos 30 segundos (suele ser por re-render
 *  del player tras pausa/resume). */
function actionLog(): void {
    global $pdo, $userId;
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $videoId   = mb_substr(trim((string)($body['videoId'] ?? '')), 0, 11);
    $title     = mb_substr(trim((string)($body['title']    ?? '')), 0, 200);
    $artist    = mb_substr(trim((string)($body['artist']   ?? '')), 0, 200);
    $playlistId= isset($body['playlistId']) && $body['playlistId'] !== null
                   ? (int)$body['playlistId']
                   : null;
    $durationS = max(0, (int)($body['durationS'] ?? 0));

    if ($videoId === '' || $title === '') {
        echo json_encode(['ok'=>false, 'error'=>'videoId+title requeridos']);
        return;
    }

    /* Dedupe 30s — evita doble-log al saltar/repausar la misma. */
    $stmt = $pdo->prepare("
        SELECT 1 FROM music_plays
        WHERE user_id=? AND video_id=?
          AND played_at >= (NOW() - INTERVAL 30 SECOND)
        LIMIT 1
    ");
    $stmt->execute([$userId, $videoId]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['ok'=>true, 'dedupe'=>true]);
        return;
    }

    $pdo->prepare("
        INSERT INTO music_plays (user_id, video_id, title, artist, playlist_id, duration_s)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$userId, $videoId, $title, $artist, $playlistId, $durationS]);

    echo json_encode(['ok'=>true]);
}

/** Log de acción sobre un ÁLBUM (play / import a playlist).
 *  Llamada desde perfil.php cuando el usuario abre un álbum desde su
 *  melon archive. Anti-dedupe 60s (más laxo que songs porque los
 *  álbumes se relisten más espaciados). */
function actionLogAlbum(): void {
    global $pdo, $userId;
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $album  = mb_substr(trim((string)($body['albumTitle'] ?? '')), 0, 200);
    $artist = mb_substr(trim((string)($body['artist']     ?? '')), 0, 200);
    $type   = in_array($body['actionType'] ?? 'play', ['play','import'], true)
                ? $body['actionType'] : 'play';
    $ytPL   = mb_substr(trim((string)($body['ytPlaylistId']  ?? '')), 0, 40) ?: null;
    $spAlb  = mb_substr(trim((string)($body['spotifyAlbumId'] ?? '')), 0, 40) ?: null;
    $cover  = mb_substr(trim((string)($body['coverUrl'] ?? '')), 0, 500) ?: null;

    if ($album === '') {
        echo json_encode(['ok'=>false, 'error'=>'albumTitle requerido']);
        return;
    }

    /* Dedupe: misma combinación user+album+action en 60s ignora. */
    $stmt = $pdo->prepare("
        SELECT 1 FROM music_album_actions
        WHERE user_id=? AND album_title=? AND action_type=?
          AND played_at >= (NOW() - INTERVAL 60 SECOND)
        LIMIT 1
    ");
    $stmt->execute([$userId, $album, $type]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['ok'=>true, 'dedupe'=>true]);
        return;
    }

    $pdo->prepare("
        INSERT INTO music_album_actions
            (user_id, album_title, artist, action_type, yt_playlist_id, spotify_album_id, cover_url)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$userId, $album, $artist, $type, $ytPL, $spAlb, $cover]);

    echo json_encode(['ok'=>true]);
}

/** Stats para Spotify Wrapped del usuario.
 *  Param `year` opcional (default año actual). Para testeo dev se
 *  pasa `all=1` para incluir todo lo registrado sin filtro de año. */
function actionStats(): void {
    global $pdo, $userId;
    $year = (int)($_GET['year'] ?? date('Y'));
    $all  = !empty($_GET['all']);

    $where  = "user_id = ?";
    $params = [$userId];
    if (!$all) {
        $where  .= " AND YEAR(played_at) = ?";
        $params[] = $year;
    }

    /* Mes con MÁS tiempo escuchado — agrupa por YEAR+MONTH y suma
       duration (mismo fallback que el total: plays sin duración cuentan
       como 180s). Devuelve el "top mes" con su número (1-12) y los
       minutos acumulados. Si no hay plays, queda null. */
    $stmt = $pdo->prepare("
        SELECT YEAR(played_at)  AS y,
               MONTH(played_at) AS m,
               SUM(IF(duration_s > 0, duration_s, 180)) AS secs,
               COUNT(*) AS plays
        FROM music_plays WHERE $where
        GROUP BY YEAR(played_at), MONTH(played_at)
        ORDER BY secs DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $topMonthRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $monthShort = ['Ene','Feb','Mar','Abr','May','Jun',
                   'Jul','Ago','Sep','Oct','Nov','Dic'];
    $topMonth = null;
    if ($topMonthRow) {
        $mIdx = ((int)$topMonthRow['m']) - 1;
        $topMonth = [
            'month_num' => (int)$topMonthRow['m'],
            'year'      => (int)$topMonthRow['y'],
            'name'      => $monthNames[$mIdx] ?? '?',
            'minutes'   => (int)round(((int)$topMonthRow['secs']) / 60),
            'plays'     => (int)$topMonthRow['plays'],
        ];
    }

    /* Breakdown por MES (1-12) para la gráfica de barras. Si el wrapped
       filtra por año, los meses son del año actual; si es DEV (`all=1`)
       agregamos todos los años por número de mes (Ene de 2025 + Ene de
       2026, etc.). Devolvemos siempre los 12 meses con 0 si no hay
       datos — el cliente dibuja 12 barras fijas. */
    $stmt = $pdo->prepare("
        SELECT MONTH(played_at) AS m,
               SUM(IF(duration_s > 0, duration_s, 180)) AS secs
        FROM music_plays WHERE $where
        GROUP BY MONTH(played_at)
        ORDER BY m
    ");
    $stmt->execute($params);
    $byMonth = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byMonth[(int)$r['m']] = (int)$r['secs'];
    }
    $monthsBreakdown = [];
    for ($mi = 1; $mi <= 12; $mi++) {
        $monthsBreakdown[] = [
            'm'       => $mi,
            'short'   => $monthShort[$mi - 1],
            'minutes' => (int)round(($byMonth[$mi] ?? 0) / 60),
        ];
    }

    /* Total minutos. Cada play sin duration (caso típico de plays
       de álbum desde perfil, donde el endpoint play-music-item no
       devuelve duration en sus tracks) cuenta como 180s (3 min)
       — duración media estimada de una canción. Plays CON duración
       suman su valor real. */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS plays,
               SUM(IF(duration_s > 0, duration_s, 180)) AS secs
        FROM music_plays WHERE $where
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['plays'=>0,'secs'=>0];
    $totalPlays = (int)$totals['plays'];
    $totalSecs  = (int)$totals['secs'];
    $totalMin = (int)round($totalSecs / 60);

    /* Canciones "considerables" — top 20 con al menos 3 plays. El
       wrapped las usa como pool para las primeras slides (welcome,
       minutos, mes, gráfica) → "una canción que has escuchado un
       número considerable de veces". */
    $stmt = $pdo->prepare("
        SELECT video_id, MAX(title) AS title, MAX(artist) AS artist, COUNT(*) AS plays
        FROM music_plays WHERE $where AND artist <> ''
        GROUP BY video_id
        HAVING plays >= 3
        ORDER BY plays DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $considerableSongs = array_map(fn($r) => [
        'video_id' => $r['video_id'],
        'title'    => $r['title'],
        'artist'   => $r['artist'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    /* Si el usuario no tiene ninguna con >= 3 plays, usamos top sin
       filtro como fallback. */
    if (empty($considerableSongs)) {
        $stmt = $pdo->prepare("
            SELECT video_id, MAX(title) AS title, MAX(artist) AS artist
            FROM music_plays WHERE $where AND artist <> ''
            GROUP BY video_id
            ORDER BY COUNT(*) DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $considerableSongs = array_map(fn($r) => [
            'video_id' => $r['video_id'],
            'title'    => $r['title'],
            'artist'   => $r['artist'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* Top canciones (5) — agrupadas por video_id. */
    $stmt = $pdo->prepare("
        SELECT video_id, MAX(title) AS title, MAX(artist) AS artist, COUNT(*) AS plays
        FROM music_plays WHERE $where
        GROUP BY video_id
        ORDER BY plays DESC, MAX(played_at) DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($songs as &$s) {
        $s['plays']     = (int)$s['plays'];
        $s['cover_url'] = "https://img.youtube.com/vi/{$s['video_id']}/mqdefault.jpg";
    }
    unset($s);

    /* Top artistas (5) — saltamos artist vacío.
       Si artist contiene ';' son N artistas distintos (e.g. "Bad Bunny;J Balvin"
       para una colaboración). Cada play cuenta 1 vez para CADA artista
       de la lista, no como un super-artista combinado.
       Estrategia: fetch raw groups, expandir por ';' en PHP, re-agregar. */
    $stmt = $pdo->prepare("
        SELECT artist, COUNT(*) AS plays
        FROM music_plays WHERE $where AND artist <> ''
        GROUP BY artist
    ");
    $stmt->execute($params);
    $rawArtists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $artistCounts = [];   /* artistName(trim) → plays acumulados */
    foreach ($rawArtists as $r) {
        $artistStr = (string)$r['artist'];
        $plays     = (int)$r['plays'];
        $parts = strpos($artistStr, ';') !== false
            ? array_filter(array_map('trim', explode(';', $artistStr)), 'strlen')
            : [trim($artistStr)];
        foreach ($parts as $a) {
            if ($a === '') continue;
            $artistCounts[$a] = ($artistCounts[$a] ?? 0) + $plays;
        }
    }
    arsort($artistCounts);
    $artists = [];
    foreach (array_slice($artistCounts, 0, 5, true) as $name => $plays) {
        $artists[] = ['artist' => $name, 'plays' => (int)$plays];
    }
    /* Pool de hasta 5 canciones por artista — match FLEXIBLE: cuando
       buscamos "Bad Bunny" debemos incluir filas donde artist sea solo
       "Bad Bunny" Y filas donde forme parte de una lista colaborativa
       ("Bad Bunny;X", "Y;Bad Bunny", "Y;Bad Bunny;Z").
       CONCAT envuelve el campo en ';...;' y el patrón busca ';artist;'
       como subcadena. REPLACE elimina espacios alrededor de ';' para
       normalizar artistas con espacio tras la separación. */
    $matchClause = "CONCAT(';', REPLACE(REPLACE(artist, '; ', ';'), ' ;', ';'), ';') LIKE CONCAT('%;', ?, ';%')";
    $stmtTopSongsByArtist = $pdo->prepare("
        SELECT video_id, MAX(title) AS title, MAX(artist) AS artist, COUNT(*) AS plays
        FROM music_plays
        WHERE user_id = ? AND $matchClause" . ($all ? "" : " AND YEAR(played_at) = ?") . "
        GROUP BY video_id
        ORDER BY plays DESC
        LIMIT 5
    ");
    foreach ($artists as &$a) {
        $a['plays'] = (int)$a['plays'];
        /* Primero recogemos las top_songs del usuario para ESTE artista
           — sus títulos sirven para verificar que la imagen de Spotify
           corresponde al artista correcto (no a un homónimo). */
        $bind = $all ? [$userId, $a['artist']] : [$userId, $a['artist'], $year];
        $stmtTopSongsByArtist->execute($bind);
        $rows = $stmtTopSongsByArtist->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $a['top_songs'] = array_map(fn($r) => [
            'video_id' => $r['video_id'],
            'title'    => $r['title'],
            'artist'   => $r['artist'],
        ], $rows);
        $a['top_video_id'] = $rows[0]['video_id'] ?? null;
        $a['top_title']    = $rows[0]['title']    ?? '';
        /* Imagen del artista verificada con los títulos que el usuario
           ha escuchado: filtra match exacto de nombre y, si quedan
           varios candidatos, elige el que más canciones del usuario
           tenga en su top-tracks de Spotify. */
        $verifyTitles = array_column($a['top_songs'], 'title');
        $a['image_url'] = getSpotifyArtistImage($a['artist'], $verifyTitles);
    }
    unset($a);

    /* Top GÉNEROS — primario: Last.fm tags POR CANCIÓN (top 50
       canciones más escuchadas). Fallback: Spotify géneros por
       artista (top 20 artistas). Ambos cachean 7 días. */
    $genreCounts = [];
    /* genre slug → array de canciones que contribuyeron a ese género.
       Lo usamos al final para adjuntar candidatos al pool de cada
       slide de género — así el slide del género suena UNA canción que
       efectivamente tiene ese tag. */
    $genreSongs = [];

    /* PRIMARIO: Last.fm por canción. */
    if (LASTFM_API_KEY !== '') {
        $stmtTopSongs = $pdo->prepare("
            SELECT video_id, MAX(title) AS title, MAX(artist) AS artist, COUNT(*) AS plays
            FROM music_plays WHERE $where AND artist <> ''
            GROUP BY video_id
            ORDER BY plays DESC
            LIMIT 50
        ");
        $stmtTopSongs->execute($params);
        foreach ($stmtTopSongs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tags = getLastFmTrackGenres((string)$row['artist'], (string)$row['title'], 5);
            if (empty($tags)) continue;
            $weight = (int)$row['plays'];
            $songRef = [
                'video_id' => $row['video_id'],
                'title'    => $row['title'],
                'artist'   => $row['artist'],
                'plays'    => $weight,
            ];
            foreach ($tags as $g) {
                $genreCounts[$g] = ($genreCounts[$g] ?? 0) + $weight;
                $genreSongs[$g][] = $songRef;
            }
        }
    }

    /* FALLBACK: Spotify por artista. También trackeamos canciones del
       artista que aportan a cada género (todas las del top de ese
       artista, no solo una). */
    if (empty($genreCounts)) {
        $stmtAllArtists = $pdo->prepare("
            SELECT artist, COUNT(*) AS plays
            FROM music_plays WHERE $where AND artist <> ''
            GROUP BY artist
            ORDER BY plays DESC
            LIMIT 20
        ");
        $stmtAllArtists->execute($params);
        $stmtSongsOfArtist = $pdo->prepare("
            SELECT video_id, MAX(title) AS title, COUNT(*) AS plays
            FROM music_plays WHERE $where AND artist = ?
            GROUP BY video_id
            ORDER BY plays DESC
            LIMIT 3
        ");
        foreach ($stmtAllArtists->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sp = getSpotifyArtistData($row['artist']);
            if (!$sp || empty($sp['genres'])) continue;
            $weight = (int)$row['plays'];
            /* Top canciones del artista para mapear a sus géneros. */
            $bind = array_merge($params, [$row['artist']]);
            $stmtSongsOfArtist->execute($bind);
            $artistSongs = array_map(fn($r) => [
                'video_id' => $r['video_id'],
                'title'    => $r['title'],
                'artist'   => $row['artist'],
                'plays'    => (int)$r['plays'],
            ], $stmtSongsOfArtist->fetchAll(PDO::FETCH_ASSOC));
            foreach ($sp['genres'] as $g) {
                $g = trim((string)$g);
                if ($g === '') continue;
                $genreCounts[$g] = ($genreCounts[$g] ?? 0) + $weight;
                foreach ($artistSongs as $sf) {
                    $genreSongs[$g][] = $sf;
                }
            }
        }
    }

    arsort($genreCounts);
    $genres = [];
    foreach (array_slice($genreCounts, 0, 5, true) as $name => $score) {
        $pretty = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        /* Top canciones del género ordenadas por plays DESC, dedupe por
           video_id. El cliente elige una random (de las top 5 con más
           plays para evitar tracks marginales). */
        $songsOfGenre = $genreSongs[$name] ?? [];
        usort($songsOfGenre, fn($a, $b) => $b['plays'] - $a['plays']);
        $seen = [];
        $unique = [];
        foreach ($songsOfGenre as $sg) {
            if (isset($seen[$sg['video_id']])) continue;
            $seen[$sg['video_id']] = true;
            $unique[] = [
                'video_id' => $sg['video_id'],
                'title'    => $sg['title'],
                'artist'   => $sg['artist'] ?? '',
            ];
            if (count($unique) >= 5) break;
        }
        $genres[] = [
            'name'      => $pretty,
            'plays'     => $score,
            'top_songs' => $unique,
        ];
    }

    /* Top álbumes — desde music_album_actions (eventos play + import).
       Agrupa por título del álbum (case-insensitive vía LOWER en SQL). */
    $whereAlb = "user_id = ?";
    $paramsAlb = [$userId];
    if (!$all) {
        $whereAlb  .= " AND YEAR(played_at) = ?";
        $paramsAlb[] = $year;
    }
    $stmt = $pdo->prepare("
        SELECT
            MAX(album_title) AS title,
            MAX(artist)      AS artist,
            MAX(cover_url)   AS cover_url,
            COUNT(*)         AS plays,
            SUM(CASE WHEN action_type='import' THEN 1 ELSE 0 END) AS imports
        FROM music_album_actions WHERE $whereAlb
        GROUP BY LOWER(album_title)
        ORDER BY plays DESC, MAX(played_at) DESC
        LIMIT 5
    ");
    $stmt->execute($paramsAlb);
    $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
    /* Query case-insensitive + trim — normaliza diferencias de
       capitalización/espacios entre el artist del álbum (escrito a
       mano en el review) y el artist del reproductor (meta YouTube). */
    $stmtTopSongsByArtistLoose = $pdo->prepare("
        SELECT video_id, MAX(title) AS title, MAX(artist) AS artist, COUNT(*) AS plays
        FROM music_plays
        WHERE user_id = ?
          AND LOWER(TRIM(artist)) = LOWER(TRIM(?))" . ($all ? "" : " AND YEAR(played_at) = ?") . "
        GROUP BY video_id
        ORDER BY plays DESC
        LIMIT 5
    ");
    foreach ($albums as &$al) {
        $al['plays']   = (int)$al['plays'];
        $al['imports'] = (int)$al['imports'];
        $al['top_songs']    = [];
        $al['top_video_id'] = null;
        $al['top_title']    = '';
        if (!empty($al['artist'])) {
            $bind = $all ? [$userId, $al['artist']] : [$userId, $al['artist'], $year];
            $stmtTopSongsByArtistLoose->execute($bind);
            $rows = $stmtTopSongsByArtistLoose->fetchAll(PDO::FETCH_ASSOC) ?: [];
            /* Cada canción lleva su artist REAL (el de music_plays, no
               el del álbum). Antes el fallback usaba canciones del
               usuario por otros artistas pero las etiquetaba como del
               artista del álbum → labels incorrectos. Ahora el cliente
               ve la asociación real. */
            $al['top_songs'] = array_map(fn($r) => [
                'video_id' => $r['video_id'],
                'title'    => $r['title'],
                'artist'   => $r['artist'],
            ], $rows);
        }
        /* SIN fallback genérico: si el artista del álbum no tiene
           tracks en music_plays, dejamos top_songs vacío y la slide no
           cambia la música — sigue sonando la del slide anterior. Es
           mejor que poner canciones de otros artistas etiquetadas
           incorrectamente. */
        $al['top_video_id'] = $al['top_songs'][0]['video_id'] ?? null;
        $al['top_title']    = $al['top_songs'][0]['title']    ?? '';
    }
    unset($al);

    /* ── LISTEN-TOGETHER BUDDIES ────────────────────────────────
       Aproximación: para cada usuario distinto del actual que haya
       compartido al menos una sesión conmigo, sumamos los segundos
       de OVERLAP (joined_at .. min(left_at, last_seen_at)).
       Considera las sesiones donde:
         - YO he sido host y el otro participante, O
         - El otro ha sido host y yo participante, O
         - Ambos hemos sido participantes.
       Resultado: lista ordenada por segundos totales escuchados juntos. */
    $buddies = [];
    try {
        $sqlBuddies = $all
            ? "
                SELECT u.user_key, u.label,
                       SUM(GREATEST(0,
                           TIMESTAMPDIFF(SECOND,
                               GREATEST(myP.joined_at, otherP.joined_at),
                               LEAST(
                                   COALESCE(myP.left_at,    myP.last_seen_at),
                                   COALESCE(otherP.left_at, otherP.last_seen_at)
                               )
                           )
                       )) AS secs_together
                  FROM listening_participants myP
                  JOIN listening_participants otherP
                    ON otherP.session_id = myP.session_id
                   AND otherP.user_id   != myP.user_id
                  JOIN usuarios u ON u.id = otherP.user_id
                 WHERE myP.user_id = ?
                 GROUP BY otherP.user_id
                HAVING secs_together > 0
                 ORDER BY secs_together DESC
                 LIMIT 10
            "
            : "
                SELECT u.user_key, u.label,
                       SUM(GREATEST(0,
                           TIMESTAMPDIFF(SECOND,
                               GREATEST(myP.joined_at, otherP.joined_at),
                               LEAST(
                                   COALESCE(myP.left_at,    myP.last_seen_at),
                                   COALESCE(otherP.left_at, otherP.last_seen_at)
                               )
                           )
                       )) AS secs_together
                  FROM listening_participants myP
                  JOIN listening_participants otherP
                    ON otherP.session_id = myP.session_id
                   AND otherP.user_id   != myP.user_id
                  JOIN usuarios u ON u.id = otherP.user_id
                 WHERE myP.user_id = ?
                   AND YEAR(myP.joined_at) = ?
                 GROUP BY otherP.user_id
                HAVING secs_together > 0
                 ORDER BY secs_together DESC
                 LIMIT 10
            ";
        $stB = $pdo->prepare($sqlBuddies);
        $stB->execute($all ? [$userId] : [$userId, $year]);
        foreach ($stB->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $secs = (int)$row['secs_together'];
            /* Resolución de la imagen del usuario por su label. Devuelve
               ruta relativa al root (assets/img/<label>.<ext>) o '' si no
               existe. El frontend está en /apps/ así que prepende '../'. */
            $imgRel = function_exists('getUserImage') ? getUserImage($row['label']) : '';
            $buddies[] = [
                'user_key' => $row['user_key'],
                'label'    => $row['label'],
                'minutes'  => (int)round($secs / 60),
                'secs'     => $secs,
                'image_url'=> $imgRel ? ('../' . $imgRel) : '',
            ];
        }
    } catch (Throwable $e) { /* tabla puede no existir todavía */ }

    echo json_encode([
        'ok'         => true,
        'year'       => $year,
        'all'        => $all,
        'total_plays'=> $totalPlays,
        'total_secs' => $totalSecs,
        'total_min'  => $totalMin,
        'top_month'  => $topMonth,
        'months_breakdown' => $monthsBreakdown,
        'considerable_songs' => $considerableSongs,
        'songs'      => $songs,
        'artists'    => $artists,
        'albums'     => $albums,
        'genres'     => $genres,
        'buddies'    => $buddies,
    ]);
}
