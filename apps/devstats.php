<?php
/**
 * apps/devstats.php
 * Estadísticas de Melon Hub, solo para quien tiene la tag dev
 * (usuarios.es_dev, assets/php/dev-acceso.php). Está en main y en dev.
 *
 * Lee la BD del propio sitio. Si en el .env están las credenciales de
 * la otra (STATS_OTRA_DB_HOST / _NAME / _USER / _PASS y, opcional,
 * STATS_OTRA_NOMBRE), sale un selector para ver main o dev desde aquí;
 * si apuntan a la misma BD, no (main y dev compartirían los datos).
 *
 * Todo se pinta en el servidor: una carga de página = un puñado de
 * consultas, y nada se pide solo cada pocos segundos.
 */

@ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/../assets/config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../assets/themes/theme-helpers.php';
require_once __DIR__ . '/../assets/dev-build.php';
require_once __DIR__ . '/../assets/php/dev-acceso.php';
require_once __DIR__ . '/../assets/stats/comun.php';

if (empty($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../index.php'); exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];
if (melon_esDev($pdo, $userKey) !== true) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Esta página es solo para devs.';
    exit;
}

/* ── Fuentes de datos: esta BD y, si está configurada, la otra ───────── */
$esteNombre = (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD) ? 'Dev' : 'Main';
$fuentes = ['esta' => ['nombre' => $esteNombre, 'pdo' => $pdo]];
$otraHost = (string)env('STATS_OTRA_DB_HOST', '');
$otraName = (string)env('STATS_OTRA_DB_NAME', '');
if ($otraHost !== '' && $otraName !== '' && !($otraName === DB_NAME && $otraHost === DB_HOST)) {
    $fuentes['otra'] = [
        'nombre' => (string)env('STATS_OTRA_NOMBRE', $esteNombre === 'Dev' ? 'Main' : 'Dev'),
        'pdo'    => null,
    ];
}
$fuenteSel = (isset($_GET['bd']) && isset($fuentes[$_GET['bd']])) ? $_GET['bd'] : 'esta';
$errorFuente = '';
if ($fuenteSel === 'otra') {
    try {
        $fuentes['otra']['pdo'] = new PDO(
            'mysql:host=' . $otraHost . ';dbname=' . $otraName . ';charset=utf8mb4',
            (string)env('STATS_OTRA_DB_USER', ''), (string)env('STATS_OTRA_DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        error_log('[devstats] otra BD: ' . $e->getMessage());
        $errorFuente = 'No se puede conectar con la BD de ' . $fuentes['otra']['nombre'] . '. Se muestra la de ' . $esteNombre . '.';
        $fuenteSel = 'esta';
    }
}
$db = $fuentes[$fuenteSel]['pdo'];
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
try { melon_devEsquema($db); } catch (Throwable $_) {}
try { melon_usoEsquema($db); } catch (Throwable $_) {}

/* ── Dar o quitar la tag dev (POST, con token de la sesión) ──────────── */
if (empty($_SESSION['devstats_csrf'])) $_SESSION['devstats_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['devstats_csrf'];
$aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'tag-dev') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $aviso = 'La página había caducado: vuelve a intentarlo.';
    } else {
        $uid = (int)($_POST['uid'] ?? 0);
        $val = ((string)($_POST['valor'] ?? '') === '1') ? 1 : 0;
        try {
            /* Que no se quede nadie con la tag (nadie podría volver a
               entrar en dev ni aquí para darla). */
            $quedan = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE es_dev = 1")->fetchColumn();
            $loTiene = (int)$db->query("SELECT es_dev FROM usuarios WHERE id = " . $uid)->fetchColumn();
            if (!$val && $loTiene && $quedan <= 1) {
                $aviso = 'No se puede quitar la tag al último dev.';
            } else {
                $db->prepare("UPDATE usuarios SET es_dev = ? WHERE id = ?")->execute([$val, $uid]);
                $aviso = $val ? 'Tag dev dada.' : 'Tag dev quitada (tarda hasta 5 min en echarle de dev).';
            }
        } catch (Throwable $e) {
            error_log('[devstats] tag: ' . $e->getMessage());
            $aviso = 'No se ha podido guardar.';
        }
    }
    $_SESSION['devstats_aviso'] = $aviso;
    header('Location: devstats.php?' . http_build_query(['bd' => $fuenteSel, 'dias' => (int)($_GET['dias'] ?? 30)]) . '#usuarios');
    exit;
}
if (!empty($_SESSION['devstats_aviso'])) { $aviso = $_SESSION['devstats_aviso']; unset($_SESSION['devstats_aviso']); }

/* ── Rango ───────────────────────────────────────────────────────────── */
$RANGOS = [1 => 'Hoy', 7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '1 año'];
$dias = (int)($_GET['dias'] ?? 30);
if (!isset($RANGOS[$dias])) $dias = 30;
$desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));   /* incluido */
$desdeMs = strtotime($desde) * 1000;

/* ── Consultas: si una tabla no existe en esta BD, null ("sin datos") ── */
function q(PDO $db, string $sql, array $p = []): ?array {
    try { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {
        /* Falta la tabla o la columna (el ajedrez no está en main, etc.):
           es lo esperado, no se llena el log en cada carga. */
        if (!($e instanceof PDOException) || !in_array((string)$e->getCode(), ['42S02', '42S22'], true)) error_log('[devstats] ' . $e->getMessage());
        return null;
    }
}
function q1(PDO $db, string $sql, array $p = []) {
    $r = q($db, $sql, $p);
    return ($r && $r[0]) ? reset($r[0]) : null;
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function num($n): string { return $n === null ? '—' : number_format((float)$n, 0, ',', '.'); }
function dur($s): string {
    if ($s === null) return '—';
    $s = (int)$s;
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return floor($s / 60) . ' min';
    $h = floor($s / 3600); $m = floor(($s % 3600) / 60);
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}
function hace(?string $f): string {
    if (!$f) return 'nunca';
    $d = time() - strtotime($f);
    if ($d < 120) return 'ahora';
    if ($d < 3600) return 'hace ' . floor($d / 60) . ' min';
    if ($d < 86400) return 'hace ' . floor($d / 3600) . ' h';
    return 'hace ' . floor($d / 86400) . ' d';
}
/* Serie diaria [fecha => valor] con los días vacíos a 0. */
function serie(?array $filas, string $desde, int $dias): array {
    $out = [];
    for ($i = 0; $i < $dias; $i++) $out[date('Y-m-d', strtotime($desde . ' +' . $i . ' days'))] = 0;
    foreach ($filas ?? [] as $r) if (isset($out[$r['d']])) $out[$r['d']] = (int)$r['n'];
    return $out;
}

/* Resumen */
$totalUsuarios = q1($db, "SELECT COUNT(*) FROM usuarios");
$nuevos        = q1($db, "SELECT COUNT(*) FROM usuarios WHERE created_at >= ?", [$desde]);
$enLinea       = q1($db, "SELECT COUNT(*) FROM user_presence WHERE last_at >= NOW() - INTERVAL 2 MINUTE");
$act1          = q1($db, "SELECT COUNT(*) FROM user_presence WHERE last_at >= CURDATE()");
$act7          = q1($db, "SELECT COUNT(*) FROM user_presence WHERE last_at >= NOW() - INTERVAL 7 DAY");
$act30         = q1($db, "SELECT COUNT(*) FROM user_presence WHERE last_at >= NOW() - INTERVAL 30 DAY");
$dormidos      = ($totalUsuarios !== null && $act30 !== null) ? $totalUsuarios - $act30 : null;
$serieAltas    = serie(q($db, "SELECT DATE(created_at) d, COUNT(*) n FROM usuarios WHERE created_at >= ? GROUP BY DATE(created_at)", [$desde]), $desde, $dias);
$serieActivos  = serie(q($db, "SELECT dia d, COUNT(DISTINCT user_id) n FROM uso_horas WHERE dia >= ? GROUP BY dia", [$desde]), $desde, $dias);
$primerDiaUso  = q1($db, "SELECT MIN(dia) FROM uso_horas");

/* Usuarios */
$usuarios = q($db, "
    SELECT u.id, u.label, u.created_at, u.es_dev, p.last_at,
           s.current AS racha, s.best AS racha_max,
           (SELECT COUNT(*) FROM music_plays m WHERE m.user_id = u.id AND m.played_at >= ?) AS escuchas,
           (SELECT COALESCE(SUM(a.segundos),0) FROM uso_apps a WHERE a.user_id = u.id AND a.dia >= ?) AS tiempo
    FROM usuarios u
    LEFT JOIN user_presence p ON p.user_id = u.id
    LEFT JOIN user_streaks  s ON s.user_id = u.id
    ORDER BY u.id", [$desde, $desde]);
if ($usuarios === null) {
    /* Sin alguna de las tablas de al lado: al menos la lista. */
    $usuarios = q($db, "SELECT id, label, created_at, es_dev, NULL last_at, NULL racha, NULL racha_max, NULL escuchas, NULL tiempo FROM usuarios ORDER BY id") ?? [];
}

/* Apps */
$apps = q($db, "SELECT app, SUM(segundos) s, SUM(aperturas) n, COUNT(DISTINCT user_id) u
                FROM uso_apps WHERE dia >= ? GROUP BY app ORDER BY s DESC", [$desde]) ?? [];
$tiempoApps = 0; foreach ($apps as $a) if ($a['app'] !== '_inicio') $tiempoApps += (int)$a['s'];
$plataformas = q($db, "SELECT plataforma, SUM(segundos) s, COUNT(DISTINCT user_id) u FROM uso_apps WHERE dia >= ? GROUP BY plataforma ORDER BY s DESC", [$desde]) ?? [];
$calor = array_fill(0, 7, array_fill(0, 24, 0));
$calorMax = 0;
foreach (q($db, "SELECT WEEKDAY(dia) wd, hora, SUM(segundos) s FROM uso_horas WHERE dia >= ? GROUP BY wd, hora", [$desde]) ?? [] as $r) {
    $calor[(int)$r['wd']][(int)$r['hora']] = (int)$r['s'];
    $calorMax = max($calorMax, (int)$r['s']);
}

/* Música */
$escuchas      = q1($db, "SELECT COUNT(*) FROM music_plays WHERE played_at >= ?", [$desde]);
$horasMusica   = q1($db, "SELECT COALESCE(SUM(duration_s),0) FROM music_plays WHERE played_at >= ?", [$desde]);
$oyentes       = q1($db, "SELECT COUNT(DISTINCT user_id) FROM music_plays WHERE played_at >= ?", [$desde]);
$serieEscuchas = serie(q($db, "SELECT DATE(played_at) d, COUNT(*) n FROM music_plays WHERE played_at >= ? GROUP BY DATE(played_at)", [$desde]), $desde, $dias);
$topCanciones  = q($db, "SELECT title, artist, COUNT(*) n, COUNT(DISTINCT user_id) u FROM music_plays WHERE played_at >= ?
                         GROUP BY video_id, title, artist ORDER BY n DESC LIMIT 15", [$desde]) ?? [];
$topArtistas   = q($db, "SELECT artist, COUNT(*) n, COUNT(DISTINCT user_id) u FROM music_plays WHERE played_at >= ? AND artist <> ''
                         GROUP BY artist ORDER BY n DESC LIMIT 15", [$desde]) ?? [];
$topOyentes    = q($db, "SELECT u.label, COUNT(*) n, COALESCE(SUM(m.duration_s),0) s FROM music_plays m JOIN usuarios u ON u.id = m.user_id
                         WHERE m.played_at >= ? GROUP BY m.user_id, u.label ORDER BY n DESC LIMIT 10", [$desde]) ?? [];

/* Juegos */
$wd = q($db, "SELECT COUNT(*) jugadas, SUM(terminado) terminadas, SUM(acertado) acertadas,
                     AVG(CASE WHEN acertado = 1 THEN JSON_LENGTH(intentos) END) media_intentos,
                     COUNT(DISTINCT user_id) jugadores
              FROM wordelon_partidas WHERE dia >= ?", [$desde]);
$wd = $wd[0] ?? null;
$bm = q($db, "SELECT modo, COUNT(*) n, SUM(estado = 'ganada') g, SUM(estado = 'perdida') p, COUNT(DISTINCT user_id) u,
                     MIN(CASE WHEN estado = 'ganada' THEN ms END) mejor
              FROM bm_partidas WHERE inicio_ms >= ? GROUP BY modo", [$desdeMs]);
$ajz = q($db, "SELECT COUNT(*) n, SUM(estado = 'terminada') t, SUM(torneo_id IS NOT NULL) tor FROM ajz_partidas WHERE creada >= ?", [$desdeMs]);
$ajz = $ajz[0] ?? null;
$ajzJugadores = q1($db, "SELECT COUNT(DISTINCT j) FROM (SELECT blanco_id j FROM ajz_partidas WHERE creada >= ?
                         UNION SELECT negro_id FROM ajz_partidas WHERE creada >= ?) x", [$desdeMs, $desdeMs]);
/* Cada juego por su lado y se suman: con un UNION, la falta de una
   tabla (el ajedrez en main) dejaba la gráfica entera vacía. */
$serieJuegos = serie([], $desde, $dias);
foreach ([
    ["SELECT dia d, COUNT(*) n FROM wordelon_partidas WHERE dia >= ? GROUP BY dia", $desde],
    ["SELECT DATE(FROM_UNIXTIME(inicio_ms/1000)) d, COUNT(*) n FROM bm_partidas WHERE inicio_ms >= ? GROUP BY d", $desdeMs],
    ["SELECT DATE(FROM_UNIXTIME(creada/1000)) d, COUNT(*) n FROM ajz_partidas WHERE creada >= ? GROUP BY d", $desdeMs],
] as [$sql, $p]) {
    foreach (serie(q($db, $sql, [$p]), $desde, $dias) as $d => $n) $serieJuegos[$d] += $n;
}
$MODOS_BM = ['dia' => 'Del día', 'inf' => 'Infinito'];

/* Tienda y social */
$gasto       = q1($db, "SELECT COALESCE(SUM(precio),0) FROM tienda_gastos WHERE creado_en >= ?", [$desde]);
$compras     = q1($db, "SELECT COUNT(*) FROM tienda_gastos WHERE creado_en >= ?", [$desde]);
$compradores = q1($db, "SELECT COUNT(DISTINCT user_id) FROM tienda_gastos WHERE creado_en >= ?", [$desde]);
$topItems    = q($db, "SELECT COALESCE(i.slug, CONCAT('#', g.item_id)) item, COUNT(*) n, SUM(g.precio) p
                       FROM tienda_gastos g LEFT JOIN tienda_items i ON i.id = g.item_id
                       WHERE g.creado_en >= ? GROUP BY g.item_id, i.slug ORDER BY n DESC LIMIT 10", [$desde]) ?? [];
$regalos     = q1($db, "SELECT COUNT(*) FROM tienda_regalos WHERE creado_en >= ?", [$desde]);
$amistades   = q1($db, "SELECT COUNT(*) FROM follows a JOIN follows b ON b.follower_id = a.followee_id AND b.followee_id = a.follower_id WHERE a.follower_id < a.followee_id");
$solicitudes = q1($db, "SELECT COUNT(*) FROM friend_requests");
$comentarios = q1($db, "SELECT COUNT(*) FROM profile_comments WHERE created_at >= ? AND deleted_at IS NULL", [$desde]);
$eventos     = q1($db, "SELECT COUNT(*) FROM events WHERE created_at >= ?", [$desde]);
$conMovil    = q1($db, "SELECT COUNT(DISTINCT user_id) FROM device_tokens");
$conSteam    = q1($db, "SELECT COUNT(*) FROM usuarios WHERE steam_id IS NOT NULL AND steam_id <> ''");

/* ── Tema del usuario (mismo bloque que el resto de apps) ────────────── */
refreshActiveThemeCss($userKey, $userLabel);
$_ut         = loadUserThemes($userKey);
$activeTheme = !empty($_ut['active']) ? sanitizeThemeName($_ut['active']) : '';
$themeCssRel = '';
$themeClass  = '';
if ($activeTheme !== '' && isset(((array)$_ut['themes'])[$activeTheme])) {
    $themeClass  = themeCssClassName($activeTheme, $userLabel);
    $themeCssRel = '../' . themeCssRelPath($activeTheme, $userLabel);
    if (!file_exists(__DIR__ . '/' . $themeCssRel)) $themeCssRel = '';
}

/* Barras de una serie diaria (divs: se pintan con --accent). */
function barras(array $serie, string $unidad = ''): string {
    $max = max(1, max($serie ?: [0]));
    $n = count($serie);
    $h = '<div class="ds-barras" style="--n:' . $n . '">';
    foreach ($serie as $d => $v) {
        $t = date('d/m', strtotime($d)) . ': ' . num($v) . ($unidad ? ' ' . $unidad : '');
        $h .= '<div class="ds-barra" data-tip="' . e($t) . '"><i style="height:' . round($v / $max * 100, 1) . '%"></i></div>';
    }
    $claves = array_keys($serie);
    $h .= '</div><div class="ds-eje"><span>' . e(date('d/m', strtotime($claves[0]))) . '</span><span>máx. ' . num($max) . '</span><span>'
        . e(date('d/m', strtotime(end($claves)))) . '</span></div>';
    return $h;
}
function cifra(string $titulo, $valor, string $sub = ''): string {
    return '<div class="ds-cifra"><div class="ds-cifra-t">' . e($titulo) . '</div><div class="ds-cifra-v">' . $valor
        . '</div>' . ($sub !== '' ? '<div class="ds-cifra-s">' . e($sub) . '</div>' : '') . '</div>';
}
function urlCon(array $cambios): string {
    return 'devstats.php?' . http_build_query(array_merge(['bd' => $GLOBALS['fuenteSel'], 'dias' => $GLOBALS['dias']], $cambios));
}
$DIAS_SEM = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$NOMBRE_PLAT = ['web' => 'Navegador (PC)', 'electron' => 'App de escritorio', 'movil' => 'Móvil (navegador/PWA)', 'app-movil' => 'App de Android'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estadísticas · Melon Hub</title>
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../assets/css/themes.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/css/themes.css') ?>">
    <?php if ($themeCssRel): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?= e($themeCssRel) ?>">
    <?php endif; ?>
    <style>
        /* Todo con las variables del tema: 98.css pinta fijo (plateado y
           blanco) ventanas, pestañas, tablas, legend y select, con
           selectores fuertes; aquí van con al menos esa especificidad. */
        html, body {
            margin: 0; min-height: 100%;
            background: var(--desktop-bg, teal); color: var(--text, #000);
            font-size: 11px;
            font-family: 'Pixelated MS Sans Serif', 'ms_sans_serif', Tahoma, sans-serif;
        }
        /* La ventana ocupa toda la pantalla (de alto, como mínimo: si el
           contenido es más largo, la página baja con la barra normal). */
        body { padding: 0; box-sizing: border-box; }
        .ds-win.window {
            margin: 0; min-height: 100vh; box-sizing: border-box;
            display: flex; flex-direction: column;
            background: var(--win-bg); color: var(--text);
            box-shadow: inset -1px -1px var(--bezel-dark-1), inset 1px 1px var(--bezel-light-2),
                        inset -2px -2px var(--bezel-dark-2), inset 2px 2px var(--bezel-light-1);
        }
        .ds-win .title-bar { background: linear-gradient(90deg, var(--titlebar-start), var(--titlebar-end)); }
        .ds-win .title-bar-text { color: var(--titlebar-text); }
        .ds-win > .window-body { flex: 1; display: flex; flex-direction: column; }
        .ds-win .window[role=tabpanel] { flex: 1; }

        /* Valor de una barra al pasar el ratón, al momento (el title del
           navegador tarda ~1 s). Mismo aspecto que los avisos del perfil. */
        #ds-tip { position: fixed; z-index: 50; pointer-events: none; display: none; white-space: nowrap;
            background: var(--warning-bg, #fffbe6); color: var(--warning-text, #444);
            border: 1px solid var(--bezel-dark-2); padding: 2px 6px; }
        .ds-win button { background: var(--btn-bg); text-shadow: 0 0 var(--btn-text);
            box-shadow: inset -1px -1px var(--bezel-dark-1), inset 1px 1px var(--bezel-light-1),
                        inset -2px -2px var(--bezel-dark-2), inset 2px 2px var(--bezel-light-2); }
        .ds-win button:not(:disabled):active { box-shadow: inset 1px 1px var(--bezel-dark-1), inset -1px -1px var(--bezel-light-1),
                        inset 2px 2px var(--bezel-dark-2), inset -2px -2px var(--bezel-light-2); }
        .ds-win button.ds-on { box-shadow: inset 1px 1px var(--bezel-dark-1), inset -1px -1px var(--bezel-light-1),
                        inset 2px 2px var(--bezel-dark-2), inset -2px -2px var(--bezel-light-2); font-weight: bold; }
        .ds-win input[type=search] { background: var(--input-bg); color: var(--input-text);
            box-shadow: inset -1px -1px var(--bezel-light-1), inset 1px 1px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2), inset 2px 2px var(--bezel-dark-1); }
        .ds-win fieldset legend { background: var(--win-bg); color: var(--text); }
        .ds-win a { color: var(--link-text, var(--accent)); }

        .ds-top { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin-bottom: 10px; }
        .ds-grupo { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
        .ds-grupo > span { margin-right: 2px; color: var(--text-muted); }
        .ds-grupo button { min-width: 0; padding: 0 8px; }
        .ds-aviso { padding: 4px 8px; margin-bottom: 8px; background: var(--warning-bg); color: var(--warning-text);
            box-shadow: inset 1px 1px var(--bezel-dark-2), inset -1px -1px var(--bezel-light-1); }

        .ds-win menu[role=tablist] { flex-wrap: wrap; }
        .ds-win menu[role=tablist] > li {
            background: var(--win-bg); color: var(--text);
            box-shadow: inset -1px 0 var(--bezel-dark-1), inset 1px 1px var(--bezel-light-2),
                        inset -2px 0 var(--bezel-dark-2), inset 2px 2px var(--bezel-light-1);
        }
        .ds-win menu[role=tablist] > li[aria-selected=true] { background-color: var(--win-bg); }
        .ds-win menu[role=tablist] > li > a { color: var(--text); cursor: pointer; }
        .ds-win menu[role=tablist] > li > a:focus { outline: 1px dotted var(--text); }
        .ds-win .window[role=tabpanel] {
            background: var(--win-bg); color: var(--text);
            box-shadow: inset -1px -1px var(--bezel-dark-1), inset 1px 1px var(--bezel-light-2),
                        inset -2px -2px var(--bezel-dark-2), inset 2px 2px var(--bezel-light-1);
        }
        .ds-panel { display: none; padding: 12px; }
        .ds-panel.on { display: block; }
        .ds-panel h3 { font-size: 12px; margin: 14px 0 6px; }
        .ds-panel h3:first-child { margin-top: 0; }
        .ds-nota { color: var(--text-muted); margin: 4px 0 8px; }

        .ds-cifras { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
        .ds-cifra { padding: 8px; background: var(--input-bg); color: var(--input-text);
            box-shadow: inset -1px -1px var(--bezel-light-1), inset 1px 1px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2), inset 2px 2px var(--bezel-dark-1); }
        .ds-cifra-t { color: var(--text-muted); }
        .ds-cifra-v { font-size: 20px; font-weight: bold; margin-top: 2px; }
        .ds-cifra-s { color: var(--text-muted); margin-top: 2px; }

        .ds-caja { padding: 8px; background: var(--input-bg); color: var(--input-text);
            box-shadow: inset -1px -1px var(--bezel-light-1), inset 1px 1px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2), inset 2px 2px var(--bezel-dark-1); }
        .ds-barras { display: grid; grid-template-columns: repeat(var(--n), 1fr); align-items: end; gap: 1px; height: 120px; }
        .ds-barra { height: 100%; display: flex; align-items: flex-end; }
        .ds-barra i { display: block; width: 100%; min-height: 1px; background: var(--accent); }
        .ds-barra:hover i { background: var(--accent-deep, var(--accent)); }
        .ds-eje { display: flex; justify-content: space-between; color: var(--text-muted); margin-top: 3px; }

        .ds-dos { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 760px) { .ds-dos { grid-template-columns: 1fr; } }

        .ds-tabla-env { overflow: auto; max-height: 520px; background: var(--input-bg);
            box-shadow: inset -1px -1px var(--bezel-light-1), inset 1px 1px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2), inset 2px 2px var(--bezel-dark-1); }
        .ds-win table.ds-t { width: 100%; background: var(--input-bg); color: var(--input-text); }
        .ds-win table.ds-t > thead > tr > th {
            background: var(--btn-bg); color: var(--btn-text); cursor: pointer; user-select: none;
            box-shadow: inset -1px -1px var(--bezel-dark-1), inset 1px 1px var(--bezel-light-1),
                        inset -2px -2px var(--bezel-dark-2), inset 2px 2px var(--bezel-light-2);
            padding: 2px 6px; height: auto;
        }
        .ds-win table.ds-t > tbody > tr > td { padding: 2px 6px; height: auto; }
        .ds-win table.ds-t > tbody > tr:nth-child(even) { background: color-mix(in srgb, var(--input-text) 5%, var(--input-bg)); }
        .ds-num { text-align: right; font-variant-numeric: tabular-nums; }
        .ds-pct { display: inline-block; width: 70px; height: 8px; vertical-align: middle; margin-right: 6px;
            background: color-mix(in srgb, var(--input-text) 12%, var(--input-bg)); }
        .ds-pct i { display: block; height: 100%; background: var(--accent); }
        .ds-tag { display: inline-block; padding: 0 4px; background: var(--accent); color: var(--accent-text); }
        .ds-tag-btn { min-height: 18px !important; padding: 0 6px !important; min-width: 0 !important; }

        /* 98.css: table con fondo blanco y thead plateado con bisel. */
        .ds-win table.ds-calor { border-collapse: separate; border-spacing: 2px; background: transparent; }
        .ds-win table.ds-calor td, .ds-win table.ds-calor th { padding: 0; font-weight: normal; color: var(--input-text); height: auto; }
        .ds-win table.ds-calor th { padding-right: 4px; text-align: right; }
        .ds-win table.ds-calor td { width: 22px; height: 16px; }
        .ds-win table.ds-calor > thead > tr > th { background: none; box-shadow: none; text-align: center; padding: 0; position: static; }
    </style>
</head>
<body class="<?= e($themeClass) ?>">
<div class="window ds-win">
    <div class="title-bar">
        <div class="title-bar-text">Estadísticas de Melon Hub — <?= e($fuentes[$fuenteSel]['nombre']) ?></div>
    </div>
    <div class="window-body">
        <?php if ($errorFuente): ?><div class="ds-aviso"><?= e($errorFuente) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="ds-aviso"><?= e($aviso) ?></div><?php endif; ?>

        <div class="ds-top">
            <?php if (count($fuentes) > 1): ?>
            <div class="ds-grupo"><span>Datos de:</span>
                <?php foreach ($fuentes as $k => $f): ?>
                <button type="button" class="<?= $k === $fuenteSel ? 'ds-on' : '' ?>" data-ir="<?= e(urlCon(['bd' => $k])) ?>"><?= e($f['nombre']) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="ds-grupo"><span>Periodo:</span>
                <?php foreach ($RANGOS as $d => $t): ?>
                <button type="button" class="<?= $d === $dias ? 'ds-on' : '' ?>" data-ir="<?= e(urlCon(['dias' => $d])) ?>"><?= e($t) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <menu role="tablist">
            <li role="tab" aria-selected="true" data-tab="resumen"><a>Resumen</a></li>
            <li role="tab" aria-selected="false" data-tab="usuarios"><a>Usuarios</a></li>
            <li role="tab" aria-selected="false" data-tab="apps"><a>Apps</a></li>
            <li role="tab" aria-selected="false" data-tab="musica"><a>Música</a></li>
            <li role="tab" aria-selected="false" data-tab="juegos"><a>Juegos</a></li>
            <li role="tab" aria-selected="false" data-tab="social"><a>Tienda y social</a></li>
        </menu>
        <div class="window" role="tabpanel">

        <!-- ── Resumen ─────────────────────────────────────────────── -->
        <section class="ds-panel on" id="p-resumen">
            <div class="ds-cifras">
                <?= cifra('Usuarios', num($totalUsuarios)) ?>
                <?= cifra('Cuentas nuevas', num($nuevos), $RANGOS[$dias]) ?>
                <?= cifra('En línea ahora', num($enLinea), 'últimos 2 min') ?>
                <?= cifra('Activos hoy', num($act1)) ?>
                <?= cifra('Activos 7 días', num($act7)) ?>
                <?= cifra('Activos 30 días', num($act30)) ?>
                <?= cifra('Dormidos', num($dormidos), 'más de 30 días sin entrar') ?>
                <?= cifra('Tiempo en apps', e(dur($tiempoApps)), $RANGOS[$dias]) ?>
            </div>
            <div class="ds-dos">
                <div>
                    <h3>Cuentas creadas por día</h3>
                    <div class="ds-caja"><?= barras($serieAltas, 'cuentas') ?></div>
                </div>
                <div>
                    <h3>Usuarios activos por día</h3>
                    <div class="ds-caja"><?= barras($serieActivos, 'usuarios') ?></div>
                    <p class="ds-nota"><?= $primerDiaUso ? 'Se mide desde el ' . e(date('d/m/Y', strtotime($primerDiaUso))) . '.' : 'Aún no hay datos: se empieza a medir al subir esta versión.' ?></p>
                </div>
            </div>
        </section>

        <!-- ── Usuarios ────────────────────────────────────────────── -->
        <section class="ds-panel" id="p-usuarios">
            <div class="ds-top">
                <input type="search" id="ds-buscar" placeholder="Buscar usuario…" style="width:200px">
                <span class="ds-nota" style="margin:0"><?= num(count($usuarios)) ?> usuarios · escuchas y tiempo: <?= e($RANGOS[$dias]) ?> · clic en una columna para ordenar</span>
            </div>
            <div class="ds-tabla-env">
            <table class="ds-t" id="ds-tabla-usuarios">
                <thead><tr>
                    <th data-tipo="n">ID</th><th>Usuario</th><th data-tipo="n">Alta</th><th data-tipo="n">Última vez</th>
                    <th data-tipo="n" class="ds-num">Racha</th><th data-tipo="n" class="ds-num">Mejor racha</th>
                    <th data-tipo="n" class="ds-num">Escuchas</th><th data-tipo="n" class="ds-num">Tiempo en apps</th><th>Dev</th>
                </tr></thead>
                <tbody>
                <?php foreach ($usuarios as $u): $esYo = ($fuenteSel === 'esta' && strcasecmp($u['label'], $userLabel) === 0); ?>
                <tr data-buscar="<?= e(mb_strtolower($u['label'])) ?>">
                    <td class="ds-num" data-v="<?= (int)$u['id'] ?>"><?= (int)$u['id'] ?></td>
                    <td><?= e($u['label']) ?></td>
                    <td data-v="<?= $u['created_at'] ? strtotime($u['created_at']) : 0 ?>"><?= $u['created_at'] ? e(date('d/m/Y', strtotime($u['created_at']))) : '—' ?></td>
                    <td data-v="<?= $u['last_at'] ? strtotime($u['last_at']) : 0 ?>" title="<?= e($u['last_at'] ?? '') ?>"><?= e(hace($u['last_at'])) ?></td>
                    <td class="ds-num" data-v="<?= (int)$u['racha'] ?>"><?= num($u['racha']) ?></td>
                    <td class="ds-num" data-v="<?= (int)$u['racha_max'] ?>"><?= num($u['racha_max']) ?></td>
                    <td class="ds-num" data-v="<?= (int)$u['escuchas'] ?>"><?= num($u['escuchas']) ?></td>
                    <td class="ds-num" data-v="<?= (int)$u['tiempo'] ?>"><?= e(dur($u['tiempo'])) ?></td>
                    <td data-v="<?= (int)$u['es_dev'] ?>">
                        <form method="post" action="<?= e(urlCon([])) ?>" style="display:inline-flex;gap:4px;align-items:center;margin:0">
                            <input type="hidden" name="accion" value="tag-dev">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="valor" value="<?= $u['es_dev'] ? '0' : '1' ?>">
                            <?php if ($u['es_dev']): ?><span class="ds-tag">dev</span><?php endif; ?>
                            <?php if (!$esYo): ?>
                            <button type="submit" class="ds-tag-btn"><?= $u['es_dev'] ? 'Quitar' : 'Dar' ?></button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="ds-nota">La tag dev deja entrar en la Melon Hub de dev y en esta página. Se da y se quita en la BD que estás viendo (<?= e($fuentes[$fuenteSel]['nombre']) ?>).</p>
        </section>

        <!-- ── Apps ────────────────────────────────────────────────── -->
        <section class="ds-panel" id="p-apps">
            <?php if (!$apps): ?>
            <p class="ds-nota">Aún no hay datos de uso en este periodo. El tiempo en cada app se empieza a medir al subir esta versión (escritorio y móvil).</p>
            <?php else: ?>
            <h3>Tiempo por app</h3>
            <div class="ds-tabla-env">
            <table class="ds-t ds-ordenable">
                <thead><tr><th>App</th><th data-tipo="n" class="ds-num">Tiempo</th><th data-tipo="n">% del tiempo en apps</th>
                    <th data-tipo="n" class="ds-num">Aperturas</th><th data-tipo="n" class="ds-num">Usuarios</th><th data-tipo="n" class="ds-num">Media por usuario</th></tr></thead>
                <tbody>
                <?php foreach ($apps as $a): $pct = ($a['app'] !== '_inicio' && $tiempoApps) ? $a['s'] / $tiempoApps * 100 : null; ?>
                <tr>
                    <td><?= e(melon_usoNombreApp($a['app'])) ?></td>
                    <td class="ds-num" data-v="<?= (int)$a['s'] ?>"><?= e(dur($a['s'])) ?></td>
                    <td data-v="<?= $pct === null ? -1 : round($pct, 2) ?>"><?php if ($pct !== null): ?><span class="ds-pct"><i style="width:<?= round($pct, 1) ?>%"></i></span><?= number_format($pct, 1, ',', '.') ?> %<?php else: ?>—<?php endif; ?></td>
                    <td class="ds-num" data-v="<?= (int)$a['n'] ?>"><?= num($a['n']) ?></td>
                    <td class="ds-num" data-v="<?= (int)$a['u'] ?>"><?= num($a['u']) ?></td>
                    <td class="ds-num" data-v="<?= $a['u'] ? (int)($a['s'] / $a['u']) : 0 ?>"><?= e(dur($a['u'] ? $a['s'] / $a['u'] : 0)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="ds-nota">Cuenta el tiempo con la app delante, la pestaña visible y actividad en los últimos 5 min. "Escritorio / menú" es el tiempo sin ninguna app delante.</p>

            <div class="ds-dos">
                <div>
                    <h3>Por plataforma</h3>
                    <div class="ds-tabla-env">
                    <table class="ds-t">
                        <thead><tr><th>Plataforma</th><th class="ds-num">Tiempo</th><th class="ds-num">Usuarios</th></tr></thead>
                        <tbody>
                        <?php foreach ($plataformas as $p): ?>
                        <tr><td><?= e($NOMBRE_PLAT[$p['plataforma']] ?? $p['plataforma']) ?></td><td class="ds-num"><?= e(dur($p['s'])) ?></td><td class="ds-num"><?= num($p['u']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div>
                    <h3>Horas punta</h3>
                    <div class="ds-caja" style="overflow:auto">
                    <table class="ds-calor">
                        <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h % 3 === 0 ? $h : '' ?></th><?php endfor; ?></tr></thead>
                        <tbody>
                        <?php foreach ($calor as $diaSem => $fila): ?>
                        <tr><th><?= $DIAS_SEM[$diaSem] ?></th>
                            <?php foreach ($fila as $h => $s): $p = $calorMax ? round($s / $calorMax * 100) : 0; ?>
                            <td title="<?= e($DIAS_SEM[$diaSem] . ' ' . $h . ':00 — ' . dur($s)) ?>" style="background:color-mix(in srgb, var(--accent) <?= $p ?>%, color-mix(in srgb, var(--input-text) 8%, var(--input-bg)))"></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Música ──────────────────────────────────────────────── -->
        <section class="ds-panel" id="p-musica">
            <div class="ds-cifras">
                <?= cifra('Escuchas', num($escuchas), $RANGOS[$dias]) ?>
                <?= cifra('Tiempo escuchado', e(dur($horasMusica))) ?>
                <?= cifra('Oyentes', num($oyentes)) ?>
                <?= cifra('Media por oyente', $oyentes ? num($escuchas / $oyentes) : '—', 'escuchas') ?>
            </div>
            <h3>Escuchas por día</h3>
            <div class="ds-caja"><?= barras($serieEscuchas, 'escuchas') ?></div>
            <div class="ds-dos">
                <div>
                    <h3>Canciones más escuchadas</h3>
                    <div class="ds-tabla-env"><table class="ds-t">
                        <thead><tr><th>Canción</th><th class="ds-num">Escuchas</th><th class="ds-num">Oyentes</th></tr></thead>
                        <tbody><?php foreach ($topCanciones as $c): ?>
                        <tr><td><?= e($c['title']) ?><?= $c['artist'] !== '' ? ' <span style="color:var(--text-muted)">— ' . e($c['artist']) . '</span>' : '' ?></td><td class="ds-num"><?= num($c['n']) ?></td><td class="ds-num"><?= num($c['u']) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </div>
                <div>
                    <h3>Artistas más escuchados</h3>
                    <div class="ds-tabla-env"><table class="ds-t">
                        <thead><tr><th>Artista</th><th class="ds-num">Escuchas</th><th class="ds-num">Oyentes</th></tr></thead>
                        <tbody><?php foreach ($topArtistas as $a): ?>
                        <tr><td><?= e($a['artist']) ?></td><td class="ds-num"><?= num($a['n']) ?></td><td class="ds-num"><?= num($a['u']) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                    <h3>Quién más escucha</h3>
                    <div class="ds-tabla-env"><table class="ds-t">
                        <thead><tr><th>Usuario</th><th class="ds-num">Escuchas</th><th class="ds-num">Tiempo</th></tr></thead>
                        <tbody><?php foreach ($topOyentes as $o): ?>
                        <tr><td><?= e($o['label']) ?></td><td class="ds-num"><?= num($o['n']) ?></td><td class="ds-num"><?= e(dur($o['s'])) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </div>
            </div>
        </section>

        <!-- ── Juegos ──────────────────────────────────────────────── -->
        <section class="ds-panel" id="p-juegos">
            <h3>Partidas por día (todos los juegos)</h3>
            <div class="ds-caja"><?= barras($serieJuegos, 'partidas') ?></div>
            <h3>Wordelon</h3>
            <?php if ($wd === null): ?><p class="ds-nota">Sin datos en esta BD.</p><?php else: ?>
            <div class="ds-cifras">
                <?= cifra('Partidas', num($wd['jugadas'])) ?>
                <?= cifra('Jugadores', num($wd['jugadores'])) ?>
                <?= cifra('Acertadas', $wd['terminadas'] ? number_format($wd['acertadas'] / $wd['terminadas'] * 100, 0, ',', '.') . ' %' : '—', 'de las terminadas') ?>
                <?= cifra('Intentos de media', $wd['media_intentos'] !== null ? number_format((float)$wd['media_intentos'], 2, ',', '.') : '—', 'al acertar') ?>
            </div>
            <?php endif; ?>
            <h3>Buscaminas</h3>
            <?php if (!$bm): ?><p class="ds-nota"><?= $bm === null ? 'Sin datos en esta BD.' : 'Ninguna partida en este periodo.' ?></p><?php else: ?>
            <div class="ds-tabla-env"><table class="ds-t">
                <thead><tr><th>Modo</th><th class="ds-num">Partidas</th><th class="ds-num">Ganadas</th><th class="ds-num">Perdidas</th><th class="ds-num">Jugadores</th><th class="ds-num">Mejor tiempo</th></tr></thead>
                <tbody><?php foreach ($bm as $r): ?>
                <tr><td><?= e($MODOS_BM[$r['modo']] ?? $r['modo']) ?></td><td class="ds-num"><?= num($r['n']) ?></td><td class="ds-num"><?= num($r['g']) ?></td><td class="ds-num"><?= num($r['p']) ?></td><td class="ds-num"><?= num($r['u']) ?></td>
                    <td class="ds-num"><?= $r['mejor'] !== null ? e(number_format($r['mejor'] / 1000, 1, ',', '.') . ' s') : '—' ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
            <h3>Ajedrez</h3>
            <?php if ($ajz === null): ?><p class="ds-nota">Sin datos en esta BD (el ajedrez solo está en dev).</p><?php else: ?>
            <div class="ds-cifras">
                <?= cifra('Partidas', num($ajz['n'])) ?>
                <?= cifra('Terminadas', num($ajz['t'])) ?>
                <?= cifra('De torneo', num($ajz['tor'])) ?>
                <?= cifra('Jugadores', num($ajzJugadores)) ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Tienda y social ─────────────────────────────────────── -->
        <section class="ds-panel" id="p-social">
            <h3>Tienda (<?= e($RANGOS[$dias]) ?>)</h3>
            <div class="ds-cifras">
                <?= cifra('Puntos gastados', num($gasto)) ?>
                <?= cifra('Compras', num($compras)) ?>
                <?= cifra('Compradores', num($compradores)) ?>
                <?= cifra('Regalos enviados', num($regalos)) ?>
            </div>
            <?php if ($topItems): ?>
            <h3>Lo más comprado</h3>
            <div class="ds-tabla-env"><table class="ds-t">
                <thead><tr><th>Artículo</th><th class="ds-num">Compras</th><th class="ds-num">Puntos</th></tr></thead>
                <tbody><?php foreach ($topItems as $i): ?>
                <tr><td><?= e($i['item']) ?></td><td class="ds-num"><?= num($i['n']) ?></td><td class="ds-num"><?= num($i['p']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
            <h3>Social</h3>
            <div class="ds-cifras">
                <?= cifra('Amistades', num($amistades), 'en total') ?>
                <?= cifra('Solicitudes pendientes', num($solicitudes)) ?>
                <?= cifra('Comentarios en perfiles', num($comentarios), $RANGOS[$dias]) ?>
                <?= cifra('Eventos creados', num($eventos), $RANGOS[$dias]) ?>
            </div>
            <h3>Plataforma</h3>
            <div class="ds-cifras">
                <?= cifra('Con el móvil vinculado', num($conMovil), 'PWA o app de Android') ?>
                <?= cifra('Con Steam vinculado', num($conSteam)) ?>
            </div>
        </section>

        </div>
    </div>
</div>

<script>
(function () {
    /* Botones de fuente y periodo: llevan la pestaña abierta en el #. */
    document.querySelectorAll('[data-ir]').forEach(function (b) {
        b.addEventListener('click', function () { location.href = b.getAttribute('data-ir') + location.hash; });
    });
    /* Pestañas (la abierta va en el #, para recargar sin perderla). */
    var tabs = document.querySelectorAll('menu[role=tablist] > li');
    function abrir(id) {
        if (!document.getElementById('p-' + id)) id = 'resumen';
        tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.tab === id ? 'true' : 'false'); });
        document.querySelectorAll('.ds-panel').forEach(function (p) { p.classList.toggle('on', p.id === 'p-' + id); });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { history.replaceState(null, '', '#' + t.dataset.tab); abrir(t.dataset.tab); });
    });
    abrir(location.hash.slice(1));
    /* Un enlace o "atrás" que solo cambia el # no recarga la página. */
    window.addEventListener('hashchange', function () { abrir(location.hash.slice(1)); });

    /* Ordenar tablas por columna (clic en la cabecera). */
    document.querySelectorAll('#ds-tabla-usuarios, table.ds-ordenable').forEach(function (tabla) {
        tabla.querySelectorAll('thead th').forEach(function (th, i) {
            var asc = false;
            th.addEventListener('click', function () {
                asc = !asc;
                var cuerpo = tabla.tBodies[0];
                var filas = Array.prototype.slice.call(cuerpo.rows);
                var num = th.dataset.tipo === 'n';
                filas.sort(function (a, b) {
                    var ca = a.cells[i], cb = b.cells[i];
                    var va = ca.dataset.v !== undefined ? ca.dataset.v : ca.textContent.trim();
                    var vb = cb.dataset.v !== undefined ? cb.dataset.v : cb.textContent.trim();
                    var r = num ? (parseFloat(va) - parseFloat(vb)) : String(va).localeCompare(String(vb), 'es');
                    return asc ? r : -r;
                });
                filas.forEach(function (f) { cuerpo.appendChild(f); });
            });
        });
    });

    /* Valor de la barra bajo el ratón: sale al entrar y sigue al ratón,
       por encima (o por debajo si no cabe) y sin salirse por los lados. */
    var tip = document.createElement('div');
    tip.id = 'ds-tip';
    document.body.appendChild(tip);
    function moverTip(ev) {
        var r = tip.getBoundingClientRect();
        var x = Math.max(4, Math.min(ev.clientX - r.width / 2, window.innerWidth - r.width - 4));
        var y = ev.clientY - r.height - 10;
        if (y < 4) y = ev.clientY + 18;
        tip.style.left = x + 'px'; tip.style.top = y + 'px';
    }
    document.querySelectorAll('.ds-barra[data-tip]').forEach(function (b) {
        b.addEventListener('mouseenter', function (ev) { tip.textContent = b.dataset.tip; tip.style.display = 'block'; moverTip(ev); });
        b.addEventListener('mousemove', moverTip);
        b.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
    });

    /* Buscar en la lista de usuarios. */
    var buscar = document.getElementById('ds-buscar');
    if (buscar) buscar.addEventListener('input', function () {
        var t = buscar.value.trim().toLowerCase();
        document.querySelectorAll('#ds-tabla-usuarios tbody tr').forEach(function (f) {
            f.style.display = (!t || f.dataset.buscar.indexOf(t) !== -1) ? '' : 'none';
        });
    });
})();
</script>
</body>
</html>
