<?php
/* ──────────────────────────────────────────────────────────────────────
   STEAM (perfil nuevo, fase 2)
   ──────────────────────────────────────────────────────────────────────
   - Vincular: inicio de sesión oficial de Steam (OpenID 2.0,
     steam-login.php → steam-callback.php). Así se sabe que la cuenta es
     tuya; se guarda el SteamID64 en usuarios.steam_id y el icono de Steam
     del perfil pasa a enlazar a ese perfil.
   - Datos: Steam Web API con STEAM_API_KEY (.env; se pide gratis en
     steamcommunity.com/dev/apikey). Todo en la caché del servidor
     (app_cache): juegos y horas 6 h, logros del jugador 12 h, nombres /
     iconos / % global de cada logro 7 días.
   - Hace falta que el perfil de Steam y "Detalles del juego" sean
     públicos; si no, Steam devuelve la biblioteca vacía (`private`).
   STEAM_API_BASE / STEAM_OPENID_URL (.env) solo para las pruebas.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/cache-helpers.php';

function steam_key(): string { return trim((string)env('STEAM_API_KEY', '')); }
function steam_api_base(): string { return rtrim((string)env('STEAM_API_BASE', 'https://api.steampowered.com'), '/'); }
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
    });
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
    $hit = cacheGet($cacheKey);
    if ($hit !== null) { $d = json_decode($hit, true); return (is_array($d) && empty($d['_null'])) ? $d : null; }
    $key = steam_key();
    if ($key === '') return null;
    $url = steam_api_base() . $path . '?' . http_build_query(['key' => $key, 'format' => 'json'] + $args);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: MelonHub\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d)) { cacheSet($cacheKey, json_encode(['_null' => true]), 600); return null; }
    cacheSet($cacheKey, json_encode($d, JSON_UNESCAPED_UNICODE), $ttl);
    return $d;
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
        $out[(int)$g['appid']] = ['appid' => (int)$g['appid'], 'name' => (string)($g['name'] ?? ''), 'minutes' => (int)($g['playtime_forever'] ?? 0)];
    }
    return $out;
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
    $g = steam_match($owned, $title);
    if (!$g) return ['inSteam' => false];
    $ach = steam_achievements($sid, $g['appid']);
    $got = count(array_filter($ach, fn($a) => $a['achieved']));
    return ['inSteam' => true, 'appid' => $g['appid'], 'hours' => round($g['minutes'] / 60, 1),
            'achieved' => $got, 'total' => count($ach)];
}

/* Juego destacado: horas, logros (conseguidos / total) y los logros
   elegidos a mano (por apiname, en ese orden; solo los que tiene). */
function steam_featured(string $sid, ?array $owned, string $title, array $apinames): ?array {
    if ($owned === null) return null;
    $g = steam_match($owned, $title);
    if (!$g) return ['inSteam' => false];
    $ach = steam_achievements($sid, $g['appid']);
    $byApi = [];
    foreach ($ach as $a) $byApi[$a['apiname']] = $a;
    $items = [];
    foreach ($apinames as $api) {
        if (isset($byApi[$api]) && $byApi[$api]['achieved']) $items[] = $byApi[$api] + ['appid' => $g['appid'], 'game' => $g['name']];
    }
    return ['inSteam' => true, 'appid' => $g['appid'], 'name' => $g['name'], 'hours' => round($g['minutes'] / 60, 1),
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
    foreach ($favTitles as $t) { $g = steam_match($owned, $t); if ($g) $apps[$g['appid']] = $g; }
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

/* Logros elegidos a mano ([{appid, apiname}]), solo los que tiene. */
function steam_picked(string $sid, ?array $owned, array $items): array {
    $out = [];
    $byApp = [];
    foreach ($items as $it) {
        $app = (int)$it['appid'];
        if (!isset($byApp[$app])) $byApp[$app] = steam_achievements($sid, $app);
        foreach ($byApp[$app] as $a) {
            if ($a['apiname'] === $it['apiname'] && $a['achieved']) {
                $out[] = $a + ['appid' => $app, 'game' => (string)($owned[$app]['name'] ?? '')];
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
