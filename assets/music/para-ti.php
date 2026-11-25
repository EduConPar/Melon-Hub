<?php
/* ──────────────────────────────────────────────────────────────────────
   PARA TI — mixes personalizados y tendencias de Melon Hub
   ──────────────────────────────────────────────────────────────────────
   Lo usa la portada de Playlists del reproductor (renderHome).

   GET  ?action=inicio      → { daily[], mixes[], descubre[] }
          daily:    Daily Mix 1–6 (grupos de artistas por género: lo que
                    ya escuchas + artistas parecidos). Cada día.
          mixes:    Mix de [Artista] (3), Mix de [Género] (2) y Mix de
                    [Década] (1). Cada día.
          descubre: Descubrimiento semanal (artistas parecidos que no has
                    escuchado; cada lunes) y Radar de novedades (lo
                    publicado en las últimas 4 semanas por tus artistas;
                    cada viernes).
   GET  ?action=tendencias  → { canciones[], artistas[], gusta[] }
          De toda la gente, últimos 7 días. "gusta" mezcla álbumes mejor
          valorados en las reseñas, canciones que más gente tiene en su
          perfil y playlists más escuchadas. Caché de 1 h para todos.
   POST ?action=mix-ids     { id, tracks:[{title,artist,videoId,duration}] }
          Guarda los videoIds que el navegador ha resuelto (yt-search-batch)
          al abrir un mix, para que la próxima vez no haya que buscarlos.

   Mix = { id, tipo, nombre, sub, imagen, tracks:[{title,artist,videoId,duration}] }
   Las canciones nuevas (Last.fm/Deezer) llegan sin videoId: se buscan en
   YouTube al abrir el mix. Los videoIds se recuerdan para todos
   (app_cache 'ptyt_<md5>').

   De dónde sale: escuchas de music_plays (90 días); géneros, parecidos y
   canciones de Last.fm (LASTFM_API_KEY); fotos y novedades de Deezer.
   Todo lo de fuera va por app_cache (artistas 30 días, novedades 1 día) y
   el resultado de cada usuario se guarda para el día/semana: solo el
   primer "inicio" del día cuesta llamadas de fuera.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/cache-helpers.php';
require_once __DIR__ . '/lastfm-helpers.php';   /* LASTFM_API_KEY, LASTFM_TAG_STOPWORDS */

$u       = requireAuth();
$userKey = $u['key'];
$action  = $_GET['action'] ?? '';
$GLOBALS['pdo'] = $pdo;   /* cachePdo() reutiliza esta conexión */

/* Versión del formato: si cambia el algoritmo, sube y se rehace todo. */
const PT_VER = 'v1';
/* Last.fm y Deezer (en .env se pueden cambiar para las pruebas, como
   DISCORD_API_BASE). */
define('PT_LASTFM', rtrim(env('LASTFM_API_BASE', 'https://ws.audioscrobbler.com'), '/'));
define('PT_DEEZER', rtrim(env('DEEZER_API_BASE', 'https://api.deezer.com'), '/'));

function pt_uid(PDO $pdo, string $key): ?int {
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $st->execute([$key]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/* ── Texto ─────────────────────────────────────────────────────────── */
function pt_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $s));
}
/* Artista "limpio" de YouTube: sin " - Topic", VEVO, y solo el primero
   de "A, B" / "A & B" / "A feat. B". */
function pt_artista(string $a): string {
    $a = trim(preg_replace('/\s*-\s*topic$/i', '', $a));
    $a = trim(preg_replace('/\s*(vevo|official|oficial)$/i', '', $a));
    $p = preg_split('/\s*(?:,|&|\sx\s|\sfeat\.?\s|\sft\.?\s|\sfeaturing\s)\s*/i', $a);
    return trim($p[0] ?? $a);
}
/* Título sin "(Official Video)", "[Lyrics]", "- Topic"… para buscarlo
   fuera y para no repetir canciones. */
function pt_titulo(string $t): string {
    $t = preg_replace('/\s*[\(\[][^\)\]]*(official|oficial|video|audio|lyric|letra|visualizer|hd|4k)[^\)\]]*[\)\]]/i', '', $t);
    return trim($t);
}
function pt_trackKey(string $title, string $artist): string {
    return pt_norm(pt_titulo($title)) . '|' . pt_norm(pt_artista($artist));
}
function pt_slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', pt_norm($s)), '-'); }

/* Mezcla estable: el mismo día da el mismo orden. */
function pt_barajar(array $a, string $semilla): array {
    mt_srand(crc32($semilla));
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    mt_srand();
    return $a;
}
/* Intercala conocidas y nuevas (1 y 1) y quita repetidas. */
function pt_intercalar(array $conocidas, array $nuevas, int $max): array {
    $out = []; $vistos = [];
    $n = max(count($conocidas), count($nuevas));
    for ($i = 0; $i < $n && count($out) < $max; $i++) {
        foreach ([$conocidas[$i] ?? null, $nuevas[$i] ?? null] as $t) {
            if (!$t || count($out) >= $max) continue;
            $k = pt_trackKey($t['title'], $t['artist']);
            if (isset($vistos[$k])) continue;
            $vistos[$k] = 1;
            $out[] = $t;
        }
    }
    return $out;
}

/* ── Caché ─────────────────────────────────────────────────────────── */
function pt_cacheMany(array $keys): array {
    if (!$keys) return [];
    $pdo = cachePdo();
    cacheEnsureTable($pdo);
    $out = [];
    foreach (array_chunk(array_values(array_unique($keys)), 300) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT cache_key, cache_value FROM app_cache
                             WHERE cache_key IN ($in) AND (expires_at IS NULL OR expires_at > ?)");
        $st->execute(array_merge($chunk, [time()]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['cache_key']] = $r['cache_value'];
    }
    return $out;
}

/* Varias URLs JSON en paralelo, con caché por URL. $reqs: clave => url.
   Devuelve clave => array decodificado (o null si falló). */
function pt_json(array $reqs, int $ttl): array {
    $out = [];
    if (!$reqs) return $out;
    $ck = [];
    foreach ($reqs as $k => $url) $ck[$k] = 'pt_' . md5($url);
    $hit = pt_cacheMany(array_values($ck));
    $todo = [];
    foreach ($reqs as $k => $url) {
        if (isset($hit[$ck[$k]])) $out[$k] = json_decode($hit[$ck[$k]], true);
        else $todo[$k] = $url;
    }
    foreach (array_chunk($todo, 10, true) as $chunk) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($chunk as $k => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'MelonHub/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        $active = null;
        do {
            $st = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 1.0);
        } while ($active && $st == CURLM_OK);
        foreach ($hs as $k => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $d = ($code === 200 && $raw) ? json_decode($raw, true) : null;
            /* Last.fm y Deezer devuelven 200 con "error" si algo va mal:
               eso no se guarda (se reintenta otro día). */
            if (is_array($d) && !isset($d['error'])) {
                cacheSet($ck[$k], $raw, $ttl);
                $out[$k] = $d;
            } else {
                $out[$k] = null;
            }
        }
        curl_multi_close($mh);
    }
    return $out;
}

function pt_lfm(string $method, array $params): string {
    return PT_LASTFM . '/2.0/?method=' . $method
         . '&' . http_build_query($params)
         . '&api_key=' . rawurlencode(LASTFM_API_KEY) . '&format=json&autocorrect=1';
}
function pt_lista($v): array {
    if (!is_array($v)) return [];
    return isset($v['name']) ? [$v] : array_values($v);
}

const PT_MES = 30 * 86400;

/* Géneros (tags buenos) de cada artista: nombre => [tag, …]. */
function pt_generos(array $artistas): array {
    if (LASTFM_API_KEY === '' || !$artistas) return [];
    $reqs = [];
    foreach ($artistas as $a) $reqs[$a] = pt_lfm('artist.gettoptags', ['artist' => $a]);
    $out = [];
    foreach (pt_json($reqs, PT_MES) as $a => $d) {
        $tags = [];
        foreach (pt_lista($d['toptags']['tag'] ?? []) as $t) {
            $n = mb_strtolower(trim((string)($t['name'] ?? '')));
            if ($n === '' || in_array($n, LASTFM_TAG_STOPWORDS, true)) continue;
            if (preg_match('/^[0-9]{2,4}s?$/', $n) || pt_decada($n)) continue;
            if (mb_stripos($n, mb_strtolower($a)) !== false) continue;
            $tags[] = $n;
            if (count($tags) >= 3) break;
        }
        $out[$a] = $tags;
    }
    return $out;
}
/* Parecidos: nombre => [[name, match], …]. */
function pt_parecidos(array $artistas, int $limit = 15): array {
    if (LASTFM_API_KEY === '' || !$artistas) return [];
    $reqs = [];
    foreach ($artistas as $a) $reqs[$a] = pt_lfm('artist.getsimilar', ['artist' => $a, 'limit' => $limit]);
    $out = [];
    foreach (pt_json($reqs, PT_MES) as $a => $d) {
        $out[$a] = [];
        foreach (pt_lista($d['similarartists']['artist'] ?? []) as $s) {
            if (!empty($s['name'])) $out[$a][] = [(string)$s['name'], (float)($s['match'] ?? 0)];
        }
    }
    return $out;
}
/* Canciones más conocidas: nombre => [track, …]. */
function pt_topCanciones(array $artistas, int $limit = 5): array {
    if (LASTFM_API_KEY === '' || !$artistas) return [];
    $reqs = [];
    foreach ($artistas as $a) $reqs[$a] = pt_lfm('artist.gettoptracks', ['artist' => $a, 'limit' => 10]);
    $out = [];
    foreach (pt_json($reqs, PT_MES) as $a => $d) {
        $out[$a] = [];
        foreach (pt_lista($d['toptracks']['track'] ?? []) as $t) {
            if (empty($t['name'])) continue;
            $out[$a][] = ['title' => (string)$t['name'], 'artist' => (string)($t['artist']['name'] ?? $a), 'videoId' => null, 'duration' => 0];
            if (count($out[$a]) >= $limit) break;
        }
    }
    return $out;
}
function pt_tagCanciones(string $tag, int $limit = 50): array {
    if (LASTFM_API_KEY === '') return [];
    $d = pt_json(['t' => pt_lfm('tag.gettoptracks', ['tag' => $tag, 'limit' => $limit])], PT_MES)['t'] ?? null;
    $out = [];
    foreach (pt_lista($d['tracks']['track'] ?? []) as $t) {
        if (empty($t['name'])) continue;
        $out[] = ['title' => (string)$t['name'], 'artist' => (string)($t['artist']['name'] ?? ''), 'videoId' => null, 'duration' => (int)($t['duration'] ?? 0)];
    }
    return $out;
}
/* "80s", "2000s", "00s"… → etiqueta de la década ("80", "2000"). */
function pt_decada(string $tag): ?string {
    $tag = mb_strtolower(trim($tag));
    $m = ['60s'=>'60','70s'=>'70','80s'=>'80','90s'=>'90','00s'=>'2000','2000s'=>'2000',
          '10s'=>'2010','2010s'=>'2010','20s'=>'2020','2020s'=>'2020','1960s'=>'60','1970s'=>'70','1980s'=>'80','1990s'=>'90'];
    return $m[$tag] ?? null;
}
const PT_DECADA_TAG = ['60'=>'60s','70'=>'70s','80'=>'80s','90'=>'90s','2000'=>'00s','2010'=>'2010s','2020'=>'2020s'];

/* Foto (Deezer) e id de cada artista: nombre => ['img'=>…, 'id'=>…]. */
function pt_deezerArtistas(array $artistas): array {
    $reqs = [];
    foreach ($artistas as $a) $reqs[$a] = PT_DEEZER . '/search/artist?limit=1&q=' . rawurlencode($a);
    $out = [];
    foreach (pt_json($reqs, PT_MES) as $a => $d) {
        $x = $d['data'][0] ?? null;
        $out[$a] = $x ? ['img' => (string)($x['picture_xl'] ?? $x['picture_big'] ?? ''), 'id' => (int)($x['id'] ?? 0)] : ['img' => '', 'id' => 0];
    }
    return $out;
}

/* Pone los videoIds ya conocidos (de cualquiera) a las canciones. */
function pt_rellenarIds(array &$mixes): void {
    $keys = [];
    foreach ($mixes as $m) foreach ($m['tracks'] as $t) {
        if (empty($t['videoId'])) $keys[] = 'ptyt_' . md5(pt_trackKey($t['title'], $t['artist']));
    }
    $hit = pt_cacheMany($keys);
    if (!$hit) return;
    foreach ($mixes as &$m) foreach ($m['tracks'] as &$t) {
        if (!empty($t['videoId'])) continue;
        $v = $hit['ptyt_' . md5(pt_trackKey($t['title'], $t['artist']))] ?? null;
        if ($v) { $d = json_decode($v, true); $t['videoId'] = $d['v'] ?? null; $t['duration'] = $t['duration'] ?: (int)($d['d'] ?? 0); }
    }
}

/* Lista corta de artistas para el subtítulo: "A, B, C y más". */
function pt_sub(array $nombres): string {
    $nombres = array_values(array_unique(array_filter($nombres)));
    if (!$nombres) return '';
    $tres = array_slice($nombres, 0, 3);
    return implode(', ', $tres) . (count($nombres) > 3 ? ' y más' : '');
}
function pt_mix(string $id, string $tipo, string $nombre, string $sub, string $img, array $tracks): array {
    return ['id' => $id, 'tipo' => $tipo, 'nombre' => $nombre, 'sub' => $sub, 'imagen' => $img, 'tracks' => array_values($tracks)];
}

/* ── Lo que escucha el usuario ────────────────────────────────────── */
function pt_escuchas(PDO $pdo, int $uid): array {
    $st = $pdo->prepare("SELECT video_id, MAX(title) AS title, MAX(artist) AS artist,
                                SUM(GREATEST(duration_s, 30)) AS secs
                         FROM music_plays
                         WHERE user_id = ? AND played_at > NOW() - INTERVAL 90 DAY AND artist <> ''
                         GROUP BY video_id ORDER BY secs DESC LIMIT 400");
    $st->execute([$uid]);
    $canciones = []; $artistas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = pt_artista((string)$r['artist']);
        if ($a === '') continue;
        $ka = pt_norm($a);
        $t = ['title' => pt_titulo((string)$r['title']), 'artist' => $a, 'videoId' => (string)$r['video_id'], 'duration' => 0, 'ka' => $ka, 'secs' => (int)$r['secs']];
        $canciones[] = $t;
        if (!isset($artistas[$ka])) $artistas[$ka] = ['nombre' => $a, 'secs' => 0];
        $artistas[$ka]['secs'] += (int)$r['secs'];
    }
    uasort($artistas, fn($x, $y) => $y['secs'] <=> $x['secs']);
    return [$canciones, $artistas];
}
function pt_suyas(array $canciones, array $kas, int $max): array {
    $out = [];
    foreach ($canciones as $c) {
        if (!in_array($c['ka'], $kas, true)) continue;
        $out[] = ['title' => $c['title'], 'artist' => $c['artist'], 'videoId' => $c['videoId'], 'duration' => 0];
        if (count($out) >= $max) break;
    }
    return $out;
}

/* ── Daily Mix + Mix de artista / género / década ─────────────────── */
function pt_diarios(array $canciones, array $artistas, string $semilla): array {
    $top = array_slice($artistas, 0, 15, true);
    $nombres = array_column($top, 'nombre');
    $generos = pt_generos($nombres);
    $conocidos = array_keys($artistas);

    /* Grupos por género principal (sin Last.fm: un grupo por artista). */
    $grupos = [];
    foreach ($top as $ka => $a) {
        $g = $generos[$a['nombre']][0] ?? ('artista:' . $ka);
        if (!isset($grupos[$g])) $grupos[$g] = ['secs' => 0, 'kas' => [], 'nombres' => []];
        $grupos[$g]['secs'] += $a['secs'];
        $grupos[$g]['kas'][] = $ka;
        $grupos[$g]['nombres'][] = $a['nombre'];
    }
    uasort($grupos, fn($x, $y) => $y['secs'] <=> $x['secs']);
    $grupos = array_slice($grupos, 0, 6, true);

    /* Parecidos de los 3 primeros de cada grupo y de los 3 artistas top. */
    $semillas = [];
    foreach ($grupos as $g) foreach (array_slice($g['nombres'], 0, 3) as $n) $semillas[$n] = 1;
    foreach (array_slice($nombres, 0, 3) as $n) $semillas[$n] = 1;
    $parecidos = pt_parecidos(array_keys($semillas));
    $nuevosDe = function (array $semillasN, int $cuantos) use ($parecidos, $conocidos) {
        $out = [];
        foreach ($semillasN as $n) foreach (array_slice($parecidos[$n] ?? [], 0, 8) as $p) {
            if (in_array(pt_norm($p[0]), $conocidos, true) || in_array($p[0], $out, true)) continue;
            $out[] = $p[0];
        }
        return array_slice($out, 0, $cuantos);
    };
    $plan = [];
    $gi = 0;
    foreach ($grupos as $g) $plan['daily' . (++$gi)] = $nuevosDe(array_slice($g['nombres'], 0, 3), 4);
    foreach (array_slice($nombres, 0, 3) as $n) $plan['art:' . $n] = $nuevosDe([$n], 6);
    $todos = [];
    foreach ($plan as $l) foreach ($l as $n) $todos[$n] = 1;
    $tops = pt_topCanciones(array_keys($todos), 3);
    $fotos = pt_deezerArtistas(array_slice($nombres, 0, 8));

    $daily = []; $gi = 0;
    foreach ($grupos as $g) {
        $gi++;
        $suyas = pt_barajar(pt_suyas($canciones, $g['kas'], 13), $semilla . 'd' . $gi);
        $nuevas = [];
        foreach ($plan['daily' . $gi] as $n) foreach ($tops[$n] ?? [] as $t) $nuevas[] = $t;
        $tracks = pt_intercalar($suyas, pt_barajar($nuevas, $semilla . 'n' . $gi), 25);
        if (count($tracks) < 5) continue;
        $img = $fotos[$g['nombres'][0]]['img'] ?? '';
        $daily[] = pt_mix('daily-' . (count($daily) + 1), 'daily', 'Daily Mix ' . (count($daily) + 1),
            pt_sub(array_merge($g['nombres'], $plan['daily' . $gi])), $img, $tracks);
    }

    $mixes = [];
    /* Mix de [Artista]: sus canciones + las de sus parecidos. */
    foreach (array_slice($top, 0, 3, true) as $ka => $a) {
        $n = $a['nombre'];
        $suyas = pt_suyas($canciones, [$ka], 8);
        $nuevas = [];
        foreach ($plan['art:' . $n] ?? [] as $p) foreach ($tops[$p] ?? [] as $t) $nuevas[] = $t;
        $tracks = pt_intercalar($suyas, pt_barajar($nuevas, $semilla . 'a' . $ka), 25);
        if (count($tracks) < 5) continue;
        $mixes[] = pt_mix('artista-' . pt_slug($n), 'artista', 'Mix de ' . $n,
            pt_sub(array_merge([$n], $plan['art:' . $n] ?? [])), $fotos[$n]['img'] ?? '', $tracks);
    }
    /* Mix de [Género]: los 2 géneros que más escuchas. */
    $porGenero = [];
    foreach ($top as $ka => $a) foreach (array_slice($generos[$a['nombre']] ?? [], 0, 1) as $g) {
        if (!isset($porGenero[$g])) $porGenero[$g] = ['secs' => 0, 'kas' => [], 'nombres' => []];
        $porGenero[$g]['secs'] += $a['secs']; $porGenero[$g]['kas'][] = $ka; $porGenero[$g]['nombres'][] = $a['nombre'];
    }
    uasort($porGenero, fn($x, $y) => $y['secs'] <=> $x['secs']);
    foreach (array_slice($porGenero, 0, 2, true) as $g => $info) {
        $suyas = pt_barajar(pt_suyas($canciones, $info['kas'], 8), $semilla . 'g' . $g);
        $deTag = pt_barajar(pt_tagCanciones($g), $semilla . 'gt' . $g);
        $tracks = pt_intercalar($suyas, array_slice($deTag, 0, 20), 25);
        if (count($tracks) < 5) continue;
        $mixes[] = pt_mix('genero-' . pt_slug($g), 'genero', 'Mix de ' . mb_convert_case($g, MB_CASE_TITLE, 'UTF-8'),
            pt_sub(array_merge($info['nombres'], array_column(array_slice($deTag, 0, 6), 'artist'))), $fotos[$info['nombres'][0]]['img'] ?? '', $tracks);
    }
    /* Mix de [Década]: la década que más sale en las etiquetas de tus
       canciones más escuchadas. */
    if (LASTFM_API_KEY !== '') {
        $reqs = [];
        foreach (array_slice($canciones, 0, 20) as $i => $c) $reqs[$i] = pt_lfm('track.gettoptags', ['artist' => $c['artist'], 'track' => $c['title']]);
        $cuenta = []; $deDecada = [];
        foreach (pt_json($reqs, PT_MES) as $i => $d) {
            foreach (array_slice(pt_lista($d['toptags']['tag'] ?? []), 0, 10) as $t) {
                $dec = pt_decada((string)($t['name'] ?? ''));
                if (!$dec) continue;
                $cuenta[$dec] = ($cuenta[$dec] ?? 0) + 1;
                $deDecada[$dec][] = $canciones[$i];
                break;
            }
        }
        arsort($cuenta);
        $dec = key($cuenta);
        if ($dec && $cuenta[$dec] >= 3) {
            $suyas = array_map(fn($c) => ['title' => $c['title'], 'artist' => $c['artist'], 'videoId' => $c['videoId'], 'duration' => 0], $deDecada[$dec]);
            $deTag = pt_barajar(pt_tagCanciones(PT_DECADA_TAG[$dec]), $semilla . 'dec');
            $tracks = pt_intercalar($suyas, array_slice($deTag, 0, 20), 25);
            $primero = $deDecada[$dec][0]['artist'];
            $img = pt_deezerArtistas([$primero])[$primero]['img'] ?? '';
            if (count($tracks) >= 5) {
                $mixes[] = pt_mix('decada-' . $dec, 'decada', 'Mix de los ' . $dec,
                    pt_sub(array_merge(array_column($suyas, 'artist'), array_column(array_slice($deTag, 0, 6), 'artist'))), $img, $tracks);
            }
        }
    }
    return [$daily, $mixes];
}

/* ── Descubrimiento semanal ───────────────────────────────────────── */
function pt_descubrimiento(array $artistas, string $semilla): ?array {
    if (LASTFM_API_KEY === '') return null;
    $semillas = array_column(array_slice($artistas, 0, 10, true), 'nombre');
    $parecidos = pt_parecidos($semillas, 20);
    $puntos = [];
    foreach ($parecidos as $lista) foreach ($lista as [$n, $m]) {
        if (isset($artistas[pt_norm($n)])) continue;   /* ya lo escuchas */
        $puntos[$n] = ($puntos[$n] ?? 0) + $m;
    }
    arsort($puntos);
    $elegidos = array_slice(array_keys($puntos), 0, 15);
    if (count($elegidos) < 3) return null;
    $tops = pt_topCanciones($elegidos, 2);
    $tracks = [];
    foreach ($elegidos as $n) foreach ($tops[$n] ?? [] as $t) $tracks[] = $t;
    $tracks = array_slice(pt_barajar($tracks, $semilla), 0, 30);
    if (count($tracks) < 5) return null;
    $img = pt_deezerArtistas([$elegidos[0]])[$elegidos[0]]['img'] ?? '';
    return pt_mix('descubrimiento', 'descubrimiento', 'Descubrimiento semanal',
        'Música nueva para ti. Se renueva cada lunes.', $img, $tracks);
}

/* ── Radar de novedades ───────────────────────────────────────────── */
function pt_radar(array $artistas): ?array {
    $nombres = array_column(array_slice($artistas, 0, 15, true), 'nombre');
    if (!$nombres) return null;
    $ids = pt_deezerArtistas($nombres);
    $reqs = [];
    foreach ($ids as $n => $x) if ($x['id']) $reqs[$n] = PT_DEEZER . '/artist/' . $x['id'] . '/albums?limit=10';
    $limite = date('Y-m-d', strtotime('-28 days'));
    $nuevos = [];
    foreach (pt_json($reqs, 86400) as $n => $d) {
        foreach ($d['data'] ?? [] as $al) {
            $f = (string)($al['release_date'] ?? '');
            if ($f >= $limite && $f <= date('Y-m-d')) $nuevos[] = ['id' => (int)$al['id'], 'fecha' => $f, 'img' => (string)($al['cover_xl'] ?? $al['cover_big'] ?? ''), 'artist' => $n];
        }
    }
    if (!$nuevos) return null;
    usort($nuevos, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    $nuevos = array_slice($nuevos, 0, 10);
    $reqs = [];
    foreach ($nuevos as $i => $al) $reqs[$i] = PT_DEEZER . '/album/' . $al['id'] . '/tracks?limit=50';
    $tracks = [];
    foreach (pt_json($reqs, 7 * 86400) as $i => $d) {
        foreach (array_slice($d['data'] ?? [], 0, 6) as $t) {
            $tracks[] = ['title' => (string)$t['title'], 'artist' => (string)($t['artist']['name'] ?? $nuevos[$i]['artist']), 'videoId' => null, 'duration' => (int)($t['duration'] ?? 0)];
        }
    }
    $tracks = array_slice($tracks, 0, 30);
    if (!$tracks) return null;
    return pt_mix('radar', 'radar', 'Radar de novedades',
        'Lo nuevo de tus artistas. Se renueva cada viernes.', $nuevos[0]['img'], $tracks);
}

/* Claves de caché del usuario: día, lunes de la semana y último viernes. */
function pt_claves(int $uid): array {
    $lunes   = date('Ymd', strtotime('monday this week'));
    $viernes = date('Ymd', strtotime(date('N') >= 5 ? 'friday this week' : 'friday last week'));
    return [
        'dia'    => 'ptdia_' . PT_VER . '_' . $uid . '_' . date('Ymd'),
        'descub' => 'ptdes_' . PT_VER . '_' . $uid . '_' . $lunes,
        'radar'  => 'ptrad_' . PT_VER . '_' . $uid . '_' . $viernes,
    ];
}

try {
    $uid = pt_uid($pdo, $userKey);
    if (!$uid) jsonError('Usuario no encontrado', 401);

    switch ($action) {

    case 'inicio': {
        $k = pt_claves($uid);
        $c = cacheGet($k['dia']);
        if ($c !== null) { header('Content-Type: application/json; charset=utf-8'); echo $c; exit; }
        set_time_limit(90);
        /* Cerrar la sesión: no bloquear el resto de peticiones del
           escritorio mientras se preparan los mixes. */
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        [$canciones, $artistas] = pt_escuchas($pdo, $uid);
        $semilla = $uid . date('Ymd');
        [$daily, $mixes] = $artistas ? pt_diarios($canciones, $artistas, $semilla) : [[], []];
        $descubre = [];
        foreach (['descub' => fn() => pt_descubrimiento($artistas, $uid . $k['descub']), 'radar' => fn() => pt_radar($artistas)] as $cual => $fn) {
            $v = cacheGet($k[$cual]);
            if ($v === null) {
                $m = $artistas ? $fn() : null;
                $v = json_encode($m);
                cacheSet($k[$cual], $v, 8 * 86400);
            }
            $m = json_decode($v, true);
            if ($m) $descubre[] = $m;
        }
        $todo = array_merge($daily, $mixes, $descubre);
        pt_rellenarIds($todo);
        $nd = count($daily); $nm = count($mixes);
        $res = ['ok' => true, 'daily' => array_slice($todo, 0, $nd), 'mixes' => array_slice($todo, $nd, $nm),
                'descubre' => array_slice($todo, $nd + $nm), 'sinEscuchas' => !$artistas];
        $json = json_encode($res, JSON_UNESCAPED_UNICODE);
        /* Sin escuchas no se guarda: en cuanto escuche algo, que salga. */
        if ($artistas) cacheSet($k['dia'], $json, 2 * 86400);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    case 'mix-ids': {
        $b = jsonBody();
        $id = (string)($b['id'] ?? '');
        $tracks = is_array($b['tracks'] ?? null) ? $b['tracks'] : [];
        if ($id === '' || !$tracks) jsonError('Datos inválidos');
        /* Para todos: título+artista → videoId. */
        $conocidos = [];
        foreach (array_slice($tracks, 0, 60) as $t) {
            $v = (string)($t['videoId'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) continue;
            $tk = pt_trackKey((string)($t['title'] ?? ''), (string)($t['artist'] ?? ''));
            $conocidos[$tk] = ['v' => $v, 'd' => (int)($t['duration'] ?? 0)];
            cacheSet('ptyt_' . md5($tk), json_encode($conocidos[$tk]), 60 * 86400);
        }
        /* Y en lo guardado del usuario (el día y, si es semanal, la semana). */
        $k = pt_claves($uid);
        $poner = function (array &$m) use ($conocidos) {
            foreach ($m['tracks'] as &$t) {
                if (!empty($t['videoId'])) continue;
                $c = $conocidos[pt_trackKey($t['title'], $t['artist'])] ?? null;
                if ($c) { $t['videoId'] = $c['v']; $t['duration'] = $t['duration'] ?: $c['d']; }
            }
        };
        $dia = cacheGet($k['dia']);
        if ($dia !== null) {
            $d = json_decode($dia, true);
            foreach (['daily', 'mixes', 'descubre'] as $sec) foreach ($d[$sec] as &$m) if ($m['id'] === $id) $poner($m);
            unset($m);
            cacheSet($k['dia'], json_encode($d, JSON_UNESCAPED_UNICODE), 2 * 86400);
        }
        $sem = ['descubrimiento' => 'descub', 'radar' => 'radar'][$id] ?? null;
        if ($sem && ($v = cacheGet($k[$sem])) !== null) {
            $m = json_decode($v, true);
            if ($m) { $poner($m); cacheSet($k[$sem], json_encode($m, JSON_UNESCAPED_UNICODE), 8 * 86400); }
        }
        jsonResponse(['ok' => true]);
    }

    case 'tendencias': {
        $c = cacheGet('pttend_' . PT_VER);
        if ($c !== null) { header('Content-Type: application/json; charset=utf-8'); echo $c; exit; }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        /* Canciones más escuchadas esta semana: primero las que escucha
           más gente distinta, luego por escuchas. */
        $canciones = [];
        foreach ($pdo->query("SELECT video_id, MAX(title) AS title, MAX(artist) AS artist,
                                     COUNT(*) AS n, COUNT(DISTINCT user_id) AS u
                              FROM music_plays WHERE played_at > NOW() - INTERVAL 7 DAY
                              GROUP BY video_id ORDER BY u DESC, n DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $canciones[] = ['videoId' => (string)$r['video_id'], 'title' => pt_titulo((string)$r['title']),
                            'artist' => pt_artista((string)$r['artist']), 'escuchas' => (int)$r['n'], 'oyentes' => (int)$r['u']];
        }
        /* Artistas del momento. */
        $acc = [];
        foreach ($pdo->query("SELECT artist, user_id, COUNT(*) AS n FROM music_plays
                              WHERE played_at > NOW() - INTERVAL 7 DAY AND artist <> ''
                              GROUP BY artist, user_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = pt_artista((string)$r['artist']); $ka = pt_norm($a);
            if ($ka === '') continue;
            if (!isset($acc[$ka])) $acc[$ka] = ['nombre' => $a, 'n' => 0, 'u' => []];
            $acc[$ka]['n'] += (int)$r['n']; $acc[$ka]['u'][$r['user_id']] = 1;
        }
        uasort($acc, fn($x, $y) => [count($y['u']), $y['n']] <=> [count($x['u']), $x['n']]);
        $acc = array_slice($acc, 0, 10, true);
        $fotos = pt_deezerArtistas(array_column($acc, 'nombre'));
        $artistas = [];
        foreach ($acc as $a) $artistas[] = ['nombre' => $a['nombre'], 'imagen' => $fotos[$a['nombre']]['img'] ?? '', 'escuchas' => $a['n'], 'oyentes' => count($a['u'])];

        /* Lo que más gusta: álbumes mejor valorados, canciones que más
           gente tiene en su perfil y playlists más escuchadas, mezclados. */
        $albumes = [];
        foreach ($pdo->query("SELECT MAX(i.title) AS title, MAX(i.artist) AS artist, MAX(i.image) AS image,
                                     MAX(i.spotify_album_id) AS albumKey, AVG(r.stars) AS media, COUNT(*) AS n
                              FROM list_item_reviews r JOIN list_items i ON r.item_id = i.id
                              WHERE i.category = 'music' AND i.music_type = 'album' AND r.stars > 0
                              GROUP BY LOWER(i.title), LOWER(COALESCE(i.artist,''))
                              ORDER BY media DESC, n DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $albumes[] = ['tipo' => 'album', 'nombre' => (string)$r['title'], 'artist' => (string)$r['artist'],
                          'imagen' => (string)$r['image'], 'albumKey' => (string)($r['albumKey'] ?? ''),
                          'sub' => '★ ' . number_format((float)$r['media'], 1, ',', '') . ' · ' . (int)$r['n'] . ((int)$r['n'] === 1 ? ' reseña' : ' reseñas')];
        }
        $perfil = [];
        foreach ($pdo->query("SELECT i.yt_id, MAX(i.title) AS title, MAX(i.artist) AS artist, COUNT(DISTINCT i.owner_id) AS n
                              FROM list_items i
                              WHERE i.category = 'music' AND i.music_type = 'song' AND i.yt_id IS NOT NULL AND i.yt_id <> ''
                              GROUP BY i.yt_id ORDER BY n DESC, MAX(i.created_at) DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perfil[] = ['tipo' => 'cancion', 'nombre' => (string)$r['title'], 'artist' => (string)$r['artist'],
                         'videoId' => (string)$r['yt_id'], 'imagen' => 'https://i.ytimg.com/vi/' . $r['yt_id'] . '/hqdefault.jpg',
                         'sub' => 'En ' . (int)$r['n'] . ((int)$r['n'] === 1 ? ' perfil' : ' perfiles')];
        }
        $listas = [];
        try {
            foreach ($pdo->query("SELECT p.id, p.name, COALESCE(p.image_url,'') AS image, u.label,
                                         COUNT(*) AS n, COUNT(DISTINCT m.user_id) AS oy,
                                         (SELECT t.video_id FROM playlist_tracks t WHERE t.playlist_id = p.id ORDER BY t.position LIMIT 1) AS v
                                  FROM music_plays m JOIN playlists p ON p.id = m.playlist_id JOIN usuarios u ON u.id = p.owner_id
                                  WHERE m.played_at > NOW() - INTERVAL 30 DAY
                                  GROUP BY p.id ORDER BY oy DESC, n DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $listas[] = ['tipo' => 'playlist', 'id' => (int)$r['id'], 'nombre' => (string)$r['name'],
                             'imagen' => $r['image'] !== '' ? (string)$r['image'] : ($r['v'] ? 'https://i.ytimg.com/vi/' . $r['v'] . '/hqdefault.jpg' : ''),
                             'sub' => 'De ' . $r['label'] . ' · ' . (int)$r['n'] . ' escuchas'];
            }
        } catch (Throwable $e) { /* sin columna image_url aún: sin playlists */ }
        $gusta = [];
        for ($i = 0; $i < 6; $i++) foreach ([$albumes, $perfil, $listas] as $l) if (isset($l[$i])) $gusta[] = $l[$i];

        $json = json_encode(['ok' => true, 'canciones' => $canciones, 'artistas' => $artistas, 'gusta' => $gusta], JSON_UNESCAPED_UNICODE);
        cacheSet('pttend_' . PT_VER, $json, 3600);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    default:
        jsonError('Acción desconocida');
    }
} catch (Throwable $e) {
    error_log('[para-ti] ' . $e->getMessage());
    jsonError('Error preparando la música para ti', 500);
}
