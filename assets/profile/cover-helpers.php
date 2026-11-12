<?php
/* ──────────────────────────────────────────────────────────────────────
   COVER HELPERS — fotos de los items de las listas
   ──────────────────────────────────────────────────────────────────────
   Las fotos de pelis/series/libros/juegos son enlaces externos que el
   usuario pega a mano y con el tiempo se caen (la web borra la imagen o
   bloquea el hotlink). Aquí vive lo necesario para que eso no deje una
   reseña sin foto:

   1. COPIA LOCAL. cv_cacheRemote() descarga la imagen, la reduce
      (máx. 500×750) y la guarda en `cover_cache` (BD) + caché en disco
      (uploads/covers/). El item pasa a apuntar a
      assets/profile/cover.php?h=<sha1 de la URL original>. Igual que las
      fotos de perfil: el blob en BD sobrevive a los despliegues.

   2. SUSTITUTA. cv_findReplacement() busca otra foto cuando la de un
      item está caída, en este orden:
        a) la original pedida desde el servidor (si solo fallaba por
           hotlink, nos quedamos con una copia de la misma foto)
        b) la del mismo título en las listas de otros usuarios
        c) TMDB (pelis/series), Open Library / Google Books (libros),
           Steam (juegos), iTunes (música)

   3. AUTOMÁTICA. La foto es opcional: cv_autoImage() le busca una (pasos
      b y c) a los items que se guardan sin foto.

   Música NO se copia en local: sus fotos vienen de YouTube/Spotify/
   iTunes (CDNs estables) y también las pinta musica-mobile, que no tiene
   <base href> y no resolvería la ruta relativa. Solo se le busca
   sustituta cuando la foto está caída o no tiene.
   ────────────────────────────────────────────────────────────────────── */

const CV_MAX_W     = 500;               /* tamaño máximo de la copia */
const CV_MAX_H     = 750;
const CV_MAX_FETCH = 8 * 1024 * 1024;   /* más que esto no es una carátula */
const CV_MAX_STORE = 2 * 1024 * 1024;   /* tope del blob (GIFs animados van sin tocar) */
const CV_UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function cv_ensureTables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no en
       cada petición: ver .claude/notas/rendimiento.md. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'portadas', __FILE__, function (PDO $pdo) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS cover_cache (
                    hash       CHAR(40) PRIMARY KEY,      /* sha1 de la URL original */
                    source_url VARCHAR(2000) NOT NULL,
                    mime       VARCHAR(20) NOT NULL,
                    data       MEDIUMBLOB NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            /* Intentos de arreglo por (categoría, título, foto caída): evita
               repetir la búsqueda en cada visita cuando no hay sustituta y
               cuenta los errores pasajeros para dar el enlace por muerto si
               siguen pasando. */
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS cover_checks (
                    hash       CHAR(40) PRIMARY KEY,
                    fails      INT NOT NULL DEFAULT 0,
                    gave_up_at TIMESTAMP NULL DEFAULT NULL,
                    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) { /* silencio */ }
    });
}

/* ── Rutas ─────────────────────────────────────────────────────────── */

/* Relativa al root del proyecto, como getUserImage(). Las páginas sin
   <base href> la resuelven con MhCover.url() (assets/js/cover-fallback.js). */
function cv_localUrl(string $hash): string {
    return 'assets/profile/cover.php?h=' . $hash;
}

/* Hash de una copia local, o null si la URL no apunta a cover.php.
   Acepta prefijos ("../../", "/scrapbookOnline/", absoluta) por si llega
   ya resuelta desde el cliente. */
function cv_localHash(string $url): ?string {
    return preg_match('#(?:^|/)assets/profile/cover\.php\?h=([a-f0-9]{40})$#', $url, $m) ? $m[1] : null;
}

function cv_isRemote(string $url): bool {
    return (bool)preg_match('#^https?://#i', $url) && cv_localHash($url) === null;
}

function cv_diskDir(): string {
    return dirname(__DIR__, 2) . '/uploads/covers';
}

function cv_ext(string $mime): string {
    return ['image/webp' => 'webp', 'image/png' => 'png', 'image/gif' => 'gif'][$mime] ?? 'jpg';
}

/* ── Descarga ──────────────────────────────────────────────────────── */

/* IP pública a la que conectar para $host, o null si no resuelve o apunta
   a la red interna (anti-SSRF: las URLs las pega el usuario). */
function cv_publicIp(string $host): ?string {
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    $h = trim($host, '[]');
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        return filter_var($h, FILTER_VALIDATE_IP, $flags) ? $h : null;
    }
    $ips = @gethostbynamel($h);
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) return null;
    }
    return $ips[0];
}

/* Descarga una URL externa siguiendo las redirecciones a mano: cada salto
   se valida contra la red interna y se fija a la IP comprobada, para que
   un DNS que cambie entre medias no cuele una IP privada.
   status:
     'ok'    → 200 con cuerpo
     'dead'  → el enlace no sirve: no resuelve, 4xx, certificado roto,
               demasiado grande, "removed.png" de imgur…
     'error' → fallo que puede ser pasajero (timeout, conexión, 5xx, 429)
   'detail' explica el motivo para el diagnóstico de covers-repair.php. */
function cv_fetch(string $url, int $timeout = 6): array {
    for ($hop = 0; $hop <= 4; $hop++) {
        $p      = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host   = $p['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return ['status' => 'dead', 'detail' => 'URL no válida'];
        /* Imgur redirige las imágenes borradas a su "removed.png". */
        if (preg_match('#^/removed\.(?:png|jpe?g)$#i', $p['path'] ?? '')) return ['status' => 'dead', 'detail' => 'borrada en imgur'];
        $ip = cv_publicIp($host);
        if ($ip === null) return ['status' => 'dead', 'detail' => 'el dominio no existe o es de red interna'];
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        $body = ''; $tooBig = false;
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => CV_UA,
            /* Referer = la propia web de la imagen: salta la mayoría de
               protecciones anti-hotlink, que solo aceptan su dominio. */
            CURLOPT_HTTPHEADER     => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer: ' . $scheme . '://' . $host . '/',
            ],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk;
                if (strlen($body) > CV_MAX_FETCH) { $tooBig = true; return 0; }
                return strlen($chunk);
            },
        ];
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ip];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if ($tooBig) return ['status' => 'dead', 'detail' => 'demasiado grande'];
        /* 51/60: certificado inválido o caducado — el navegador tampoco la carga. */
        if ($errno === 51 || $errno === 60) return ['status' => 'dead', 'detail' => 'certificado inválido'];
        if ($errno) return ['status' => 'error', 'detail' => $errno === 28 ? 'no responde (timeout)' : 'error de conexión (curl ' . $errno . ')'];
        if ($code >= 300 && $code < 400 && $next !== '') { $url = $next; continue; }
        if ($code === 429 || $code >= 500) return ['status' => 'error', 'detail' => 'HTTP ' . $code];
        if ($code !== 200 || $body === '') return ['status' => 'dead', 'detail' => 'HTTP ' . $code];
        return ['status' => 'ok', 'body' => $body, 'detail' => 'HTTP 200'];
    }
    return ['status' => 'dead', 'detail' => 'demasiadas redirecciones'];
}

/* ── Imagen ────────────────────────────────────────────────────────── */

/* [ancho, alto, mime] si $bytes es una imagen utilizable, o null. Por
   debajo de 16 px es un píxel de seguimiento o un "no image". */
function cv_imageInfo(string $bytes): ?array {
    $info = @getimagesizefromstring($bytes);
    if (!$info) return null;
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;
    if ($info[0] < 16 || $info[1] < 16) return null;
    return [$info[0], $info[1], $mime];
}

/* Reduce la imagen a CV_MAX_W×CV_MAX_H como mucho. Devuelve
   ['mime' => ..., 'data' => ...] o null si no es una imagen. */
function cv_processImage(string $bytes): ?array {
    $info = cv_imageInfo($bytes);
    if (!$info) return null;
    [$w, $h, $mime] = $info;
    $original = strlen($bytes) <= CV_MAX_STORE ? ['mime' => $mime, 'data' => $bytes] : null;

    /* Sin tocar: GIFs animados (perderían la animación), imágenes que ya
       son pequeñas, o si no hay GD. */
    $animated = $mime === 'image/gif' && substr_count($bytes, "\x21\xF9\x04") > 1;
    $small    = $w <= CV_MAX_W && $h <= CV_MAX_H && strlen($bytes) <= 200 * 1024;
    if ($animated || $small || !function_exists('imagecreatefromstring')) return $original;

    $src = @imagecreatefromstring($bytes);
    if (!$src) return $original;
    $scale = min(1, CV_MAX_W / $w, CV_MAX_H / $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    if (function_exists('imagewebp')) {
        imagewebp($dst, null, 82);
        $outMime = 'image/webp';
    } elseif ($mime === 'image/png' || $mime === 'image/gif') {
        imagepng($dst, null, 8);          /* conserva la transparencia */
        $outMime = 'image/png';
    } else {
        imagejpeg($dst, null, 85);
        $outMime = 'image/jpeg';
    }
    $out = ob_get_clean();
    if (!is_string($out) || $out === '') return $original;
    return ['mime' => $outMime, 'data' => $out];
}

/* ── Copia local ───────────────────────────────────────────────────── */

function cv_writeDisk(string $hash, string $mime, string $data): void {
    $dir = cv_diskDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    @file_put_contents($dir . '/' . $hash . '.' . cv_ext($mime), $data);
}

function cv_store(PDO $pdo, string $sourceUrl, array $img): string {
    $hash = sha1($sourceUrl);
    $src  = mb_substr($sourceUrl, 0, 2000);
    $data = $img['data'];
    $stmt = $pdo->prepare("INSERT INTO cover_cache (hash, source_url, mime, data) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data)");
    $stmt->bindValue(1, $hash);
    $stmt->bindValue(2, $src);
    $stmt->bindValue(3, $img['mime']);
    $stmt->bindParam(4, $data, PDO::PARAM_LOB);
    $stmt->execute();
    cv_writeDisk($hash, $img['mime'], $data);
    return cv_localUrl($hash);
}

/* Copias locales ya existentes para una lista de URLs externas:
   [url => ruta local]. Una sola query para todo el lote. */
function cv_cachedUrls(PDO $pdo, array $urls): array {
    $byHash = [];
    foreach ($urls as $u) $byHash[sha1($u)] = $u;
    if (!$byHash) return [];
    $place = implode(',', array_fill(0, count($byHash), '?'));
    $stmt = $pdo->prepare("SELECT hash FROM cover_cache WHERE hash IN ($place)");
    $stmt->execute(array_keys($byHash));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $h) $out[$byHash[$h]] = cv_localUrl($h);
    return $out;
}

/* Copia local de una URL externa. Devuelve la ruta local o null, y deja
   en $status 'ok' | 'dead' | 'error' y en $detail el motivo (ver
   cv_fetch). Si ya estaba copiada no la vuelve a descargar. */
function cv_cacheRemote(PDO $pdo, string $url, ?string &$status = null, int $timeout = 6, ?string &$detail = null): ?string {
    $status = 'ok';
    $detail = 'ya copiada';
    $cached = cv_cachedUrls($pdo, [$url]);
    if (isset($cached[$url])) return $cached[$url];

    $res    = cv_fetch($url, $timeout);
    $status = $res['status'];
    $detail = $res['detail'];
    if ($status !== 'ok') return null;
    $img = cv_processImage($res['body']);
    if (!$img) {   /* no es una imagen (p. ej. la página HTML de un anti-hotlink) */
        $status = 'dead';
        $detail = 'no es una imagen';
        return null;
    }
    return cv_store($pdo, $url, $img);
}

/* ── Búsqueda de sustitutas ────────────────────────────────────────── */

/* Minúsculas, sin tildes ni signos. */
function cv_norm(string $s): string {
    $s = mb_strtolower($s);
    if (class_exists('Transliterator')) {
        $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($tr) $s = $tr->transliterate($s);
    }
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/* Parecido entre dos títulos ya normalizados, 0-100. Que uno empiece por
   el otro ("abbey road" / "abbey road remastered") cuenta como parecido,
   pero por debajo de una coincidencia exacta. */
function cv_similar(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 100.0;
    similar_text($a, $b, $pct);
    if (str_starts_with($a, $b . ' ') || str_starts_with($b, $a . ' ')) $pct = max($pct, 75.0);
    return $pct;
}

/* El resultado que más se parezca al título buscado en cualquiera de sus
   nombres. Por debajo de $min no vale: mejor sin foto que con la de otra
   obra. A igualdad gana el primero (las APIs ya ordenan por relevancia). */
function cv_pickBest(array $results, string $title, callable $names, float $min = 70.0): ?array {
    $q = cv_norm($title);
    $best = null; $bestScore = 0.0;
    foreach ($results as $r) {
        if (!is_array($r)) continue;
        foreach ($names($r) as $n) {
            $score = cv_similar($q, cv_norm((string)$n));
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
    }
    return $bestScore >= $min ? $best : null;
}

/* GET JSON a una API pública conocida (sin anti-SSRF: la URL la montamos
   nosotros). */
function cv_getJson(string $url, array $headers = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 7,
        CURLOPT_USERAGENT      => 'MelonHub/1.0',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($raw) || $code !== 200) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/* Separa un año final "(2021)" o "[2021]" del título. */
function cv_splitYear(string $title): array {
    if (preg_match('/^(.*?)\s*[\(\[](\d{4})[\)\]]\s*$/u', $title, $m) && trim($m[1]) !== '') {
        return [trim($m[1]), (int)$m[2]];
    }
    return [$title, null];
}

/* Póster de TMDB para una peli ('movie') o serie ('tv'). */
function cv_searchTmdb(string $kind, string $title): array {
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if ($key === '') return [];
    [$q, $year] = cv_splitYear($title);
    $params  = ['query' => $q, 'language' => 'es-ES', 'include_adult' => 'false'];
    $headers = [];
    /* Vale la "API Key" (v3) o el "API Read Access Token" (v4, un JWT). */
    if (str_starts_with($key, 'eyJ')) $headers[] = 'Authorization: Bearer ' . $key;
    else $params['api_key'] = $key;

    $search = function (array $params) use ($kind, $headers) {
        $data = cv_getJson('https://api.themoviedb.org/3/search/' . $kind . '?' . http_build_query($params), $headers);
        return array_values(array_filter($data['results'] ?? [], fn($r) => !empty($r['poster_path'])));
    };
    $results = [];
    if ($year) $results = $search($params + [($kind === 'movie' ? 'year' : 'first_air_date_year') => $year]);
    if (!$results) $results = $search($params);

    $best = cv_pickBest($results, $q, fn($r) => [
        $r['title'] ?? $r['name'] ?? '',
        $r['original_title'] ?? $r['original_name'] ?? '',
    ]);
    return $best ? ['https://image.tmdb.org/t/p/w500' . $best['poster_path']] : [];
}

/* Open Library agrupa por obra y la titula en su idioma original ("The
   Name of the Wind"). Con lang=es cada obra trae además la edición en
   español que mejor encaja, con su título y su portada: comparamos con
   las dos y nos quedamos con la portada de la que coincida. */
function cv_searchOpenLibrary(string $title): array {
    $data = cv_getJson('https://openlibrary.org/search.json?' . http_build_query([
        'q' => $title, 'lang' => 'es', 'limit' => 5,
        /* Sin "key" Open Library devuelve las ediciones vacías. */
        'fields' => 'key,title,cover_i,editions,editions.title,editions.cover_i',
    ]));
    $entries = [];
    foreach ($data['docs'] ?? [] as $d) {
        $ed = $d['editions']['docs'][0] ?? null;
        if (!empty($ed['cover_i'])) $entries[] = ['name' => $ed['title'] ?? '', 'cover' => $ed['cover_i']];
        if (!empty($d['cover_i']))  $entries[] = ['name' => $d['title'] ?? '', 'cover' => $d['cover_i']];
    }
    $best = cv_pickBest($entries, $title, fn($e) => [$e['name']]);
    return $best ? ['https://covers.openlibrary.org/b/id/' . (int)$best['cover'] . '-L.jpg'] : [];
}

/* Segunda opción para libros: portadas más pequeñas, pero más catálogo
   en español. */
function cv_searchGoogleBooks(string $title): array {
    $data = cv_getJson('https://www.googleapis.com/books/v1/volumes?' . http_build_query([
        'q' => 'intitle:' . $title, 'maxResults' => 5, 'printType' => 'books',
    ]));
    $vols = array_values(array_filter($data['items'] ?? [], fn($v) => !empty($v['volumeInfo']['imageLinks']['thumbnail'])));
    $best = cv_pickBest($vols, $title, fn($v) => [$v['volumeInfo']['title'] ?? '']);
    if (!$best) return [];
    return [str_replace(['http://', '&edge=curl'], ['https://', ''], $best['volumeInfo']['imageLinks']['thumbnail'])];
}

function cv_searchSteam(string $title): array {
    $data = cv_getJson('https://store.steampowered.com/api/storesearch/?' . http_build_query([
        'term' => $title, 'l' => 'spanish', 'cc' => 'ES',
    ]));
    $best = cv_pickBest($data['items'] ?? [], $title, fn($i) => [$i['name'] ?? '']);
    if (!$best || empty($best['id'])) return [];
    $base = 'https://cdn.cloudflare.steamstatic.com/steam/apps/' . (int)$best['id'];
    /* Cápsula vertical (tipo póster) si el juego la tiene; si no, la
       cabecera horizontal y, como último recurso, la miniatura. */
    return array_values(array_filter([
        $base . '/library_600x900.jpg',
        $base . '/header.jpg',
        (string)($best['tiny_image'] ?? ''),
    ]));
}

function cv_searchMusic(string $title, string $artist, string $type): array {
    if ($type === 'album') {
        $data = cv_getJson('https://itunes.apple.com/search?' . http_build_query([
            'term' => trim($title . ' ' . $artist), 'entity' => 'album', 'limit' => 10,
        ]));
        $best = cv_pickBest($data['results'] ?? [], $title, fn($r) => [$r['collectionName'] ?? '']);
        $img  = (string)($best['artworkUrl100'] ?? '');
        return $img !== '' ? [str_replace('100x100', '600x600', $img)] : [];
    }
    require_once dirname(__DIR__) . '/music/itunes-helpers.php';
    $album = itunesSearchAlbumForTrack($title, $artist);
    return !empty($album['albumImage']) ? [$album['albumImage']] : [];
}

/* Candidatas externas por categoría. Es un generador: cada API solo se
   consulta si las anteriores no dieron una foto que funcione. */
function cv_externalCandidates(string $category, string $title, string $artist, string $musicType): Generator {
    switch ($category) {
        case 'movies': yield from cv_searchTmdb('movie', $title); break;
        case 'series': yield from cv_searchTmdb('tv', $title); break;
        case 'books':
            yield from cv_searchOpenLibrary($title);
            yield from cv_searchGoogleBooks($title);
            break;
        case 'games':  yield from cv_searchSteam($title); break;
        case 'music':  yield from cv_searchMusic($title, $artist, $musicType); break;
    }
}

/* Busca una foto que funcione para un item cuya foto ($failing) no carga.
   Devuelve ['status' => ..., 'url' => ?string]:
     'ok'        → la original sí carga desde el servidor (fallaba por
                   hotlink); url = su copia local
     'replaced'  → url = otra foto (copia local; externa en música)
     'transient' → la original dio un error que puede ser pasajero. url,
                   si no es null, es una sustituta para ENSEÑAR ahora sin
                   guardarla: si la original vuelve, se queda la original
     'none'      → no hay sustituta
   $failing vacío = el item no tiene foto (cv_autoImage).
   $skipOriginal: dar la original por muerta sin volver a pedirla.
   $hints: ['artist' => …, 'type' => song|album] de música, para items
   que aún no están en BD (si no, se leen de list_items), y 'excludeId':
   el propio item, para que al quitarle la foto no se le devuelva la
   misma que tenía guardada. */
function cv_findReplacement(PDO $pdo, string $category, string $title, string $failing, bool $skipOriginal = false, array $hints = []): array {
    $deadline = microtime(true) + 20;   /* margen dentro del max_execution_time */
    $isMusic  = ($category === 'music');
    $titleKey = mb_strtolower(trim($title));
    $originalTransient = false;
    $found = function (?string $url) use (&$originalTransient): array {
        if ($originalTransient) return ['status' => 'transient', 'url' => $url];
        return ['status' => $url !== null ? 'replaced' : 'none', 'url' => $url];
    };

    /* a) La original, pedida desde el servidor. */
    if (!$skipOriginal && cv_isRemote($failing)) {
        if ($isMusic) {
            $res    = cv_fetch($failing);
            $status = ($res['status'] === 'ok' && !cv_imageInfo($res['body'])) ? 'dead' : $res['status'];
            /* Carga desde aquí pero no en el navegador, y música no se
               copia en local: no hay nada que hacer. */
            if ($status === 'ok') return ['status' => 'none', 'url' => null];
        } else {
            $local = cv_cacheRemote($pdo, $failing, $status);
            if ($local) return ['status' => 'ok', 'url' => $local];
        }
        if ($status === 'error') $originalTransient = true;
    }
    /* Una copia local que no carga pero sigue en BD es un fallo puntual
       del servidor, no una foto caída. */
    $localHash = cv_localHash($failing);
    if ($localHash !== null) {
        $st = $pdo->prepare("SELECT 1 FROM cover_cache WHERE hash = ?");
        $st->execute([$localHash]);
        if ($st->fetchColumn()) return ['status' => 'transient', 'url' => null];
    }

    /* b) La misma obra en las listas de otros usuarios. */
    $st = $pdo->prepare("SELECT DISTINCT image FROM list_items
                         WHERE category = ? AND LOWER(title) = ? AND image <> '' AND image <> ? AND id <> ?
                         LIMIT 5");
    $st->execute([$category, $titleKey, $failing, (int)($hints['excludeId'] ?? 0)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $img) {
        if (microtime(true) > $deadline) break;
        $h = cv_localHash($img);
        if ($h !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM cover_cache WHERE hash = ?");
            $chk->execute([$h]);
            if ($chk->fetchColumn()) return $found(cv_localUrl($h));
            continue;
        }
        if (!cv_isRemote($img)) continue;
        if ($isMusic) {
            $res = cv_fetch($img);
            if ($res['status'] === 'ok' && cv_imageInfo($res['body'])) return $found($img);
            continue;
        }
        $local = cv_cacheRemote($pdo, $img);
        if ($local) return $found($local);
    }

    /* c) Webs de datos. */
    $artist    = trim((string)($hints['artist'] ?? ''));
    $musicType = (string)($hints['type'] ?? '');
    if ($isMusic && $artist === '' && $musicType === '') {
        $st = $pdo->prepare("SELECT artist, music_type FROM list_items
                             WHERE category = 'music' AND LOWER(title) = ? LIMIT 1");
        $st->execute([$titleKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $artist    = (string)($row['artist'] ?? '');
        $musicType = (string)($row['music_type'] ?? '');
    }
    foreach (cv_externalCandidates($category, trim($title), $artist, $musicType) as $url) {
        if (microtime(true) > $deadline) break;
        /* Música: CDN de Apple, no hace falta comprobarla ni copiarla. */
        if ($isMusic) return $found($url);
        $local = cv_cacheRemote($pdo, $url);
        if ($local) return $found($local);
    }
    return $found(null);
}

/* Clave de cover_checks para (categoría, título, foto). Incluye si TMDB
   está configurado: si se configura después de un intento fallido, la
   clave cambia y se vuelve a buscar en vez de esperar. */
function cv_checkKey(string $category, string $titleKey, string $failing): string {
    $tmdb = defined('TMDB_API_KEY') && TMDB_API_KEY !== '' ? 'tmdb' : '';
    return sha1($category . '|' . $titleKey . '|' . $failing . '|' . $tmdb);
}

/* Foto automática para un item guardado sin foto. Devuelve la URL (copia
   local; externa en música) o null. Si no hay ninguna lo apunta en
   cover_checks y no lo vuelve a buscar en 3 días (si no, cada guardado
   de la lista repetiría las búsquedas). $searched dice si ha buscado de
   verdad o ha salido por ese registro. */
function cv_autoImage(PDO $pdo, string $category, string $title, array $hints = [], ?bool &$searched = null): ?string {
    $searched = false;
    $titleKey = mb_strtolower(trim($title));
    if ($titleKey === '') return null;
    $key = cv_checkKey($category, $titleKey, '');
    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(gave_up_at) FROM cover_checks WHERE hash = ?");
    $st->execute([$key]);
    $gaveUp = (int)$st->fetchColumn();
    if ($gaveUp && time() - $gaveUp < 3 * 86400) return null;

    $searched = true;
    $res = cv_findReplacement($pdo, $category, $title, '', false, $hints);
    if ($res['url'] !== null) {
        $pdo->prepare("DELETE FROM cover_checks WHERE hash = ?")->execute([$key]);
        return $res['url'];
    }
    $pdo->prepare("INSERT INTO cover_checks (hash, gave_up_at) VALUES (?, NOW())
                   ON DUPLICATE KEY UPDATE gave_up_at = NOW()")
        ->execute([$key]);
    return null;
}

/* Estado de la clave de TMDB para el diagnóstico de covers-repair.php:
   'sin clave' | 'ok' | 'clave rechazada (HTTP 401)' | 'sin respuesta'… */
function cv_tmdbStatus(): string {
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if ($key === '') return 'sin clave (TMDB_API_KEY vacía en el .env de este servidor)';
    $params = ['query' => 'Oppenheimer'];
    $headers = [];
    if (str_starts_with($key, 'eyJ')) $headers[] = 'Authorization: Bearer ' . $key;
    else $params['api_key'] = $key;
    $ch = curl_init('https://api.themoviedb.org/3/search/movie?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 7, CURLOPT_HTTPHEADER => $headers]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code === 200) return 'ok';
    if ($code === 0)   return 'sin respuesta (¿el servidor no puede salir a api.themoviedb.org?)';
    return 'clave rechazada (HTTP ' . $code . ')';
}
