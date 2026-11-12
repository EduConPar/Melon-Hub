<?php
/* ──────────────────────────────────────────────────────────────────────
   STEAM (perfil nuevo, fase 2)
   ──────────────────────────────────────────────────────────────────────
   - Vincular: inicio de sesión oficial de Steam (OpenID 2.0,
     steam-login.php → steam-callback.php). Así se sabe que la cuenta es
     tuya; se guarda el SteamID64 en usuarios.steam_id y el icono de Steam
     del perfil pasa a enlazar a ese perfil.
   - Desde el móvil (api.php steam-link-start): en la app de Android Steam
     se abre en el navegador del teléfono, que no tiene la sesión de Melon
     Hub. La vuelta lleva un código de un solo uso (steam_vinculos, 10 min)
     que dice de quién es, en vez de la sesión.
   - Datos: Steam Web API con STEAM_API_KEY (.env; se pide gratis en
     steamcommunity.com/dev/apikey). Todo en la caché del servidor
     (app_cache): juegos y horas 6 h, logros del jugador 12 h, nombres /
     iconos / % global de cada logro 7 días.
   - Hace falta que el perfil de Steam y "Detalles del juego" sean
     públicos; si no, Steam devuelve la biblioteca vacía (`private`).
   - Juegos que NO están en su biblioteca (devueltos, de la familia de
     Steam) pero de los que tiene logros (12/11, decidido así): la Web API
     no da esa lista, pero sí deja leer sus logros de cualquier juego si
     se sabe cuál es. Se sacan de: los jugados las 2 últimas semanas
     (GetRecentlyPlayedGames, incluye los de la familia), los juegos de su
     lista de Melon Hub (se buscan en la tienda de Steam) y lo que busque a
     mano al elegir logros (steam-search-games). Solo salen si tiene algún
     logro. Van con owned = false; las horas solo se saben si lo ha jugado
     hace poco (si no, null).
   STEAM_API_BASE / STEAM_STORE_BASE / STEAM_OPENID_URL (.env) solo para
   las pruebas.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/cache-helpers.php';

function steam_key(): string { return trim((string)env('STEAM_API_KEY', '')); }
function steam_api_base(): string { return rtrim((string)env('STEAM_API_BASE', 'https://api.steampowered.com'), '/'); }
function steam_store_base(): string { return rtrim((string)env('STEAM_STORE_BASE', 'https://store.steampowered.com'), '/'); }
function steam_openid_url(): string { return (string)env('STEAM_OPENID_URL', 'https://steamcommunity.com/openid/login'); }

function steam_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no en
       cada petición: ver .claude/notas/rendimiento.md. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'steam', __FILE__, function (PDO $pdo) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'steam_id'")->fetch()) {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN steam_id VARCHAR(20) NULL");
            }
        } catch (Throwable $e) { /* sin permisos: no hay Steam */ }
        $pdo->exec("CREATE TABLE IF NOT EXISTS steam_vinculos (
            token    CHAR(32)    NOT NULL PRIMARY KEY,
            user_key VARCHAR(50) NOT NULL,
            app      TINYINT(1)  NOT NULL DEFAULT 0,
            caduca   DATETIME    NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    });
}

/* URL del inicio de sesión de Steam que vuelve a steam-callback.php con
   `?<param>=<valor>` (state de la sesión o código del móvil). */
function steam_origen(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
}
function steam_loginUrl(string $param, string $valor): string {
    $origin = steam_origen();
    $return = $origin . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/steam-callback.php?' . $param . '=' . $valor;
    return steam_openid_url() . '?' . http_build_query([
        'openid.ns'         => 'http://specs.openid.net/auth/2.0',
        'openid.mode'       => 'checkid_setup',
        'openid.return_to'  => $return,
        'openid.realm'      => $origin . '/',
        'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ]);
}

/* Código de un solo uso para vincular desde el móvil ($app: la app de
   Android, que al acabar ofrece volver a ella). */
function steam_vinculoNuevo(PDO $pdo, string $userKey, bool $app): string {
    steam_ensure($pdo);
    $pdo->exec("DELETE FROM steam_vinculos WHERE caduca < NOW()");
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO steam_vinculos (token, user_key, app, caduca) VALUES (?, ?, ?, NOW() + INTERVAL 10 MINUTE)")
        ->execute([$token, $userKey, $app ? 1 : 0]);
    return $token;
}
/* Lo gasta: ['user_key', 'app'] o null si no existe o ha caducado. */
function steam_vinculoUsar(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    steam_ensure($pdo);
    $st = $pdo->prepare("SELECT user_key, app FROM steam_vinculos WHERE token = ? AND caduca >= NOW()");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM steam_vinculos WHERE token = ?")->execute([$token]);
    return $row ? ['user_key' => (string)$row['user_key'], 'app' => (bool)$row['app']] : null;
}

function steam_idOf(PDO $pdo, int $uid): string {
    steam_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT steam_id FROM usuarios WHERE id = ?");
        $st->execute([$uid]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

/* GET a la Web API (JSON) con caché. null si falla (también se guarda
   un rato, para no machacar a Steam con un perfil privado o caído). */
function steam_get(string $path, array $args, string $cacheKey, int $ttl): ?array {
    if (steam_key() === '') return null;
    return steam_getMany(['x' => [steam_apiUrl($path, $args), $cacheKey, $ttl]])['x'];
}
function steam_apiUrl(string $path, array $args): string {
    return steam_api_base() . $path . '?' . http_build_query(['key' => steam_key(), 'format' => 'json'] + $args);
}

/* Varias a la vez: [clave => [url, cacheKey, ttl]] → [clave => array|null].
   Lo que no está en caché se pide en paralelo (curl_multi): buscar en la
   tienda los juegos de una lista larga, uno detrás de otro, tardaría
   demasiado la primera vez. Sin curl, una detrás de otra. */
function steam_getMany(array $reqs): array {
    $out = []; $pend = [];
    foreach ($reqs as $k => [$url, $cacheKey, $ttl]) {
        $hit = cacheGet($cacheKey);
        if ($hit !== null) { $d = json_decode($hit, true); $out[$k] = (is_array($d) && empty($d['_null'])) ? $d : null; }
        else $pend[$k] = $reqs[$k];
    }
    $raws = [];
    if ($pend && function_exists('curl_multi_init')) {
        foreach (array_chunk($pend, 10, true) as $lote) {
            $mh = curl_multi_init(); $hs = [];
            foreach ($lote as $k => [$url]) {
                $h = curl_init($url);
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                                       CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'MelonHub']);
                curl_multi_add_handle($mh, $h); $hs[$k] = $h;
            }
            do { $st = curl_multi_exec($mh, $vivos); if ($vivos) curl_multi_select($mh, 1.0); } while ($vivos && $st === CURLM_OK);
            foreach ($hs as $k => $h) { $raws[$k] = curl_multi_getcontent($h); curl_multi_remove_handle($mh, $h); curl_close($h); }
            curl_multi_close($mh);
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub\r\n"]]);
        foreach ($pend as $k => [$url]) $raws[$k] = @file_get_contents($url, false, $ctx);
    }
    foreach ($pend as $k => [$url, $cacheKey, $ttl]) {
        $d = !empty($raws[$k]) ? json_decode($raws[$k], true) : null;
        if (!is_array($d)) { cacheSet($cacheKey, json_encode(['_null' => true]), 600); $out[$k] = null; continue; }
        cacheSet($cacheKey, json_encode($d, JSON_UNESCAPED_UNICODE), $ttl);
        $out[$k] = $d;
    }
    return $out;
}

/* Perfil público: nombre, avatar, enlace. */
function steam_summary(string $sid): ?array {
    $d = steam_get('/ISteamUser/GetPlayerSummaries/v2/', ['steamids' => $sid], 'steam_sum_' . $sid, 3600);
    $p = $d['response']['players'][0] ?? null;
    if (!$p) return null;
    return [
        'persona'    => (string)($p['personaname'] ?? ''),
        'avatar'     => (string)($p['avatarfull'] ?? $p['avatar'] ?? ''),
        'profileUrl' => (string)($p['profileurl'] ?? ('https://steamcommunity.com/profiles/' . $sid)),
        'public'     => (int)($p['communityvisibilitystate'] ?? 1) === 3,
    ];
}

/* Biblioteca: [appid => ['appid','name','minutes']], o null si es privada. */
function steam_owned(string $sid): ?array {
    $d = steam_get('/IPlayerService/GetOwnedGames/v1/',
        ['steamid' => $sid, 'include_appinfo' => 1, 'include_played_free_games' => 1], 'steam_owned_' . $sid, 6 * 3600);
    if (!$d || !isset($d['response']['games'])) return null;
    $out = [];
    foreach ($d['response']['games'] as $g) {
        $out[(int)$g['appid']] = ['appid' => (int)$g['appid'], 'name' => (string)($g['name'] ?? ''), 'minutes' => (int)($g['playtime_forever'] ?? 0), 'owned' => true];
    }
    return $out;
}

/* ── Juegos fuera de su biblioteca (ver cabecera) ── */

/* Jugados las 2 últimas semanas (también los de la familia de Steam). */
function steam_recent(string $sid): array {
    $d = steam_get('/IPlayerService/GetRecentlyPlayedGames/v1/', ['steamid' => $sid], 'steam_recent_' . $sid, 3600);
    $out = [];
    foreach (($d['response']['games'] ?? []) as $g) {
        $out[(int)$g['appid']] = ['appid' => (int)$g['appid'], 'name' => (string)($g['name'] ?? ''), 'minutes' => (int)($g['playtime_forever'] ?? 0)];
    }
    return $out;
}

/* Logros conseguidos de varios juegos: [appid => n] (0 si no lo ha jugado).
   Misma caché que steam_achievements. */
function steam_myAchCounts(string $sid, array $appids): array {
    if (steam_key() === '') return [];
    $reqs = [];
    foreach (array_unique(array_map('intval', $appids)) as $app) {
        if ($app > 0) $reqs[$app] = [steam_apiUrl('/ISteamUserStats/GetPlayerAchievements/v1/', ['steamid' => $sid, 'appid' => $app]), 'steam_ach_' . $sid . '_' . $app, 12 * 3600];
    }
    $out = [];
    foreach (steam_getMany($reqs) as $app => $d) {
        $out[$app] = count(array_filter($d['playerstats']['achievements'] ?? [], fn($a) => !empty($a['achieved'])));
    }
    return $out;
}

/* Buscar en la tienda de Steam: [término => [appid => ['appid','name']]]. */
function steam_storeSearch(array $terms): array {
    $reqs = [];
    foreach ($terms as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $reqs[$t] = [steam_store_base() . '/api/storesearch/?' . http_build_query(['term' => $t, 'l' => 'spanish', 'cc' => 'ES']),
                     'steam_store_' . md5(mb_strtolower($t)), 7 * 86400];
    }
    $out = [];
    foreach (steam_getMany($reqs) as $t => $d) {
        $out[$t] = [];
        foreach (($d['items'] ?? []) as $it) {
            if (($it['type'] ?? 'app') !== 'app' || empty($it['id'])) continue;
            $out[$t][(int)$it['id']] = ['appid' => (int)$it['id'], 'name' => (string)($it['name'] ?? '')];
        }
    }
    return $out;
}

/* Títulos ("a mano", de su lista) → juegos de Steam que no tiene pero de
   los que tiene logros: [título => juego con owned false] (los que no
   se encuentran no salen). Las horas, de los jugados hace poco. */
function steam_resolveExtra(string $sid, ?array $owned, array $titles): array {
    $owned = $owned ?? [];
    $buscar = [];
    foreach ($titles as $t) if (trim((string)$t) !== '' && !steam_match($owned, (string)$t)) $buscar[] = (string)$t;
    if (!$buscar) return [];
    $cand = [];
    foreach (steam_storeSearch($buscar) as $t => $items) {
        $g = steam_match($items, $t);
        if ($g && !isset($owned[$g['appid']])) $cand[$t] = $g;
    }
    if (!$cand) return [];
    $n = steam_myAchCounts($sid, array_column($cand, 'appid'));
    $recent = steam_recent($sid);
    $out = [];
    foreach ($cand as $t => $g) {
        if (empty($n[$g['appid']])) continue;
        $out[$t] = ['appid' => $g['appid'], 'name' => $g['name'], 'minutes' => $recent[$g['appid']]['minutes'] ?? null, 'owned' => false];
    }
    return $out;
}

/* Para elegir logros: los de la familia / devueltos que se conocen
   (jugados hace poco + los de su lista de juegos de Melon Hub). */
function steam_extraGames(PDO $pdo, int $uid, string $sid, ?array $owned): array {
    $owned = $owned ?? [];
    $out = [];
    $recent = array_diff_key(steam_recent($sid), $owned);
    if ($recent) {
        $n = steam_myAchCounts($sid, array_keys($recent));
        foreach ($recent as $app => $g) if (!empty($n[$app])) $out[$app] = $g + ['owned' => false];
    }
    try {
        $st = $pdo->prepare("SELECT DISTINCT title FROM list_items WHERE owner_id = ? AND category = 'games' AND title <> '' LIMIT 300");
        $st->execute([$uid]);
        $titles = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $titles = []; }
    foreach (steam_resolveExtra($sid, $owned, $titles) as $g) if (!isset($out[$g['appid']])) $out[$g['appid']] = $g;
    return $out;
}

/* Un juego de la lista: el de su biblioteca o, si no lo tiene, el de la
   tienda del que tenga logros. null si ninguno. */
function steam_resolve(string $sid, ?array $owned, string $title): ?array {
    if ($owned !== null && ($g = steam_match($owned, $title))) return $g;
    return steam_resolveExtra($sid, $owned, [$title])[$title] ?? null;
}

/* Horas de un juego para enseñar: null si no se saben (no lo tiene y no
   lo ha jugado hace poco). */
function steam_hours(array $g): ?float {
    return $g['minutes'] === null ? null : round($g['minutes'] / 60, 1);
}

/* Logros de un juego: nombre, descripción, icono, % global y, si se pasa
   el jugador, si lo tiene y cuándo. [] si el juego no tiene logros. */
function steam_achievements(string $sid, int $appid): array {
    $schema = steam_get('/ISteamUserStats/GetSchemaForGame/v2/', ['appid' => $appid, 'l' => 'spanish'], 'steam_schema_' . $appid, 7 * 86400);
    $list = $schema['game']['availableGameStats']['achievements'] ?? [];
    if (!$list) return [];
    $glob = steam_get('/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2/', ['gameid' => $appid], 'steam_global_' . $appid, 7 * 86400);
    $pct = [];
    foreach (($glob['achievementpercentages']['achievements'] ?? []) as $a) $pct[(string)$a['name']] = (float)$a['percent'];
    $mine = steam_get('/ISteamUserStats/GetPlayerAchievements/v1/', ['steamid' => $sid, 'appid' => $appid], 'steam_ach_' . $sid . '_' . $appid, 12 * 3600);
    $got = [];
    foreach (($mine['playerstats']['achievements'] ?? []) as $a) {
        if (!empty($a['achieved'])) $got[(string)$a['apiname']] = (int)($a['unlocktime'] ?? 0);
    }
    $out = [];
    foreach ($list as $a) {
        $api = (string)$a['name'];
        $out[] = [
            'apiname'  => $api,
            'name'     => (string)($a['displayName'] ?? $api),
            'desc'     => (string)($a['description'] ?? ''),
            'icon'     => (string)($a['icon'] ?? ''),
            'percent'  => isset($pct[$api]) ? round($pct[$api], 1) : null,
            'achieved' => isset($got[$api]),
            'unlock'   => $got[$api] ?? 0,
        ];
    }
    return $out;
}

/* Título "a mano" de la lista ↔ juego de Steam, en tres pasos (el primero
   que acierte):
     1. exacto, sin mayúsculas, ™ ® ni signos ("Hollow Knight");
     2. normalizado (steam_loose): romanos a números ("Hearts of Iron IV"
        = "Hearts of Iron 4"), sin tildes, "&" = "and", sin "The" delante
        ni coletillas de edición ("GOTY", "Definitive Edition"…);
     3. parecido: el que más se parezca, si es un 90 % o más (nombres de
        6+ letras, para no emparejar cosas cortas al azar) y con los mismos
        números (las secuelas: "Celeste 2" no es "Celeste").
   Nada de "empieza por": "Hollow Knight" no es "Hollow Knight: Silksong". */
function steam_norm(string $s): string {
    $s = mb_strtolower($s);
    $s = str_replace(['™', '®', '©'], '', $s);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
}
function steam_loose(string $s): string {
    $s = mb_strtolower(str_replace(['™', '®', '©'], '', $s));
    if (class_exists('Normalizer')) $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/\bgame of the year\b/u', ' ', $s);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    static $ROMAN = ['ii' => 2, 'iii' => 3, 'iv' => 4, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'xi' => 11,
                     'xii' => 12, 'xiii' => 13, 'xiv' => 14, 'xv' => 15, 'xvi' => 16, 'xvii' => 17, 'xviii' => 18, 'xix' => 19, 'xx' => 20];
    static $EDITION = ['edition', 'goty', 'definitive', 'remastered', 'deluxe', 'enhanced', 'ultimate'];
    $out = [];
    $n = count($words);
    foreach ($words as $i => $w) {
        if (in_array($w, $EDITION, true)) continue;
        if (isset($ROMAN[$w])) $w = (string)$ROMAN[$w];
        /* "V" y "X" sueltos solo al final ("GTA V"), no "X-COM". */
        elseif ($i === $n - 1 && $i > 0 && ($w === 'v' || $w === 'x')) $w = $w === 'v' ? '5' : '10';
        $out[] = $w;
    }
    if (($out[0] ?? '') === 'the' && count($out) > 1) array_shift($out);
    return implode('', $out);
}
function steam_match(array $owned, string $title): ?array {
    $n = steam_norm($title);
    if ($n === '') return null;
    foreach ($owned as $g) if (steam_norm($g['name']) === $n) return $g;
    $l = steam_loose($title);
    if ($l === '') return null;
    $best = null; $bestPct = 0.0;
    $nums = function ($x) { preg_match_all('/\d+/', $x, $m); return implode(',', $m[0]); };
    $lNums = $nums($l);
    foreach ($owned as $g) {
        $gl = steam_loose($g['name']);
        if ($gl === $l) return $g;
        if (mb_strlen($l) < 6 || mb_strlen($gl) < 6 || $nums($gl) !== $lNums) continue;
        similar_text($l, $gl, $pct);
        if ($pct > $bestPct) { $bestPct = $pct; $best = $g; }
    }
    return $bestPct >= 90 ? $best : null;
}

/* Datos de un juego favorito: horas y logros (conseguidos / total). */
function steam_gameStats(string $sid, ?array $owned, string $title): ?array {
    if ($owned === null) return null;
    $g = steam_resolve($sid, $owned, $title);
    if (!$g) return ['inSteam' => false];
    $ach = steam_achievements($sid, $g['appid']);
    $got = count(array_filter($ach, fn($a) => $a['achieved']));
    return ['inSteam' => true, 'owned' => $g['owned'], 'appid' => $g['appid'], 'hours' => steam_hours($g),
            'achieved' => $got, 'total' => count($ach)];
}

/* Juego destacado: horas, logros (conseguidos / total) y los logros
   elegidos a mano (por apiname, en ese orden; solo los que tiene). */
function steam_featured(string $sid, ?array $owned, string $title, array $apinames): ?array {
    if ($owned === null) return null;
    $g = steam_resolve($sid, $owned, $title);
    if (!$g) return ['inSteam' => false];
    $ach = steam_achievements($sid, $g['appid']);
    $byApi = [];
    foreach ($ach as $a) $byApi[$a['apiname']] = $a;
    $items = [];
    foreach ($apinames as $api) {
        if (isset($byApi[$api]) && $byApi[$api]['achieved']) $items[] = $byApi[$api] + ['appid' => $g['appid'], 'game' => $g['name']];
    }
    return ['inSteam' => true, 'owned' => $g['owned'], 'appid' => $g['appid'], 'name' => $g['name'], 'hours' => steam_hours($g),
            'achieved' => count(array_filter($ach, fn($a) => $a['achieved'])), 'total' => count($ach), 'items' => $items];
}

/* Resumen para debajo de los expositores de logros. */
function steam_totals(?array $owned): array {
    if ($owned === null) return ['games' => 0, 'hours' => 0];
    return ['games' => count($owned), 'hours' => (int)round(array_sum(array_column($owned, 'minutes')) / 60)];
}

/* Los 8 más raros entre los favoritos y los 10 más jugados. */
function steam_rarest(string $sid, ?array $owned, array $favTitles, int $n = 8): array {
    if ($owned === null) return [];
    $apps = [];
    foreach ($favTitles as $t) { $g = steam_resolve($sid, $owned, $t); if ($g) $apps[$g['appid']] = $g; }
    $top = $owned;
    usort($top, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    foreach (array_slice($top, 0, 10) as $g) if ($g['minutes'] > 0) $apps[$g['appid']] = $g;
    $all = [];
    foreach ($apps as $g) {
        foreach (steam_achievements($sid, $g['appid']) as $a) {
            if (!$a['achieved'] || $a['percent'] === null) continue;
            $all[] = $a + ['appid' => $g['appid'], 'game' => $g['name']];
        }
    }
    usort($all, fn($a, $b) => $a['percent'] <=> $b['percent']);
    return array_slice($all, 0, $n);
}

/* Logros elegidos a mano ([{appid, apiname, game}]), solo los que tiene.
   El nombre del juego viene guardado con el logro: si no lo tiene en la
   biblioteca, Steam no lo da. */
function steam_picked(string $sid, ?array $owned, array $items): array {
    $out = [];
    $byApp = [];
    foreach ($items as $it) {
        $app = (int)$it['appid'];
        if (!isset($byApp[$app])) $byApp[$app] = steam_achievements($sid, $app);
        foreach ($byApp[$app] as $a) {
            if ($a['apiname'] === $it['apiname'] && $a['achieved']) {
                $out[] = $a + ['appid' => $app, 'game' => (string)($owned[$app]['name'] ?? ($it['game'] ?? ''))];
            }
        }
    }
    return $out;
}

/* Estado de Steam para el perfil (`v2.steam`). */
function steam_state(PDO $pdo, int $uid): array {
    $sid = steam_idOf($pdo, $uid);
    $st = ['configured' => steam_key() !== '', 'linked' => $sid !== ''];
    if ($sid === '' || !$st['configured']) return $st;
    $sum = steam_summary($sid);
    $owned = steam_owned($sid);
    return $st + [
        'steamId'    => $sid,
        'persona'    => $sum['persona'] ?? '',
        'profileUrl' => $sum['profileUrl'] ?? ('https://steamcommunity.com/profiles/' . $sid),
        'private'    => $owned === null,
    ] + steam_totals($owned);
}
