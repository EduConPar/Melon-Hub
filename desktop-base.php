<?php
/* No imprimir warnings/notices en la salida: en algunos XAMPP (Windows)
   display_errors viene On por defecto y un aviso de PHP se colaría dentro
   del HTML/JS, rompiendo el script (p.ej. "taskbarManager is not defined").
   Los errores se siguen registrando en el log del servidor. */
@ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Base ABSOLUTA del proyecto (con barra final).
   Este archivo se incluye desde stubs en /desktops/<user>-desktop.php.
   Si hacemos `header('Location: index.php')` desde aquí, el navegador
   lo interpreta como relativo al recurso pedido (el stub), no a la
   raíz del proyecto → acaba navegando a `/desktops/index.php` (404).
   Calculamos la base recortando `/desktops/` de `$_SERVER['SCRIPT_NAME']`:
     - XAMPP local: SCRIPT_NAME=/scrapbookOnline/desktops/x-desktop.php → `/scrapbookOnline/`.
     - Hostinger:   SCRIPT_NAME=/desktops/x-desktop.php → `/`.
   El reabrir el navegador después de cerrar la pestaña dejaba al
   usuario en una URL inexistente; con la base absoluta el redirect
   apunta siempre al index.php correcto. */
$_dbScript = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$_dbPos    = strpos($_dbScript, '/desktops/');
if ($_dbPos !== false) {
    $DESKTOP_BASE_URL = substr($_dbScript, 0, $_dbPos) . '/';
} else {
    $_dbDir = rtrim(str_replace('\\', '/', dirname($_dbScript)), '/');
    $DESKTOP_BASE_URL = ($_dbDir === '' ? '/' : $_dbDir . '/');
}

if (!isset($desktopLabel)) { header('Location: ' . $DESKTOP_BASE_URL . 'index.php'); exit; }

/* Móviles SIEMPRE entran por la landing (mobile-landing.php) — el
   escritorio Win98 no es accesible desde móvil. La landing decide si
   pintar el pitch de instalación o rebotar al home (cuando ya está
   dentro de la PWA). Override con ?desktop=1 o cookie force_desktop. */
require_once __DIR__ . '/assets/mobile-detect.php';
if (isMobileDevice()) { header('Location: ' . $DESKTOP_BASE_URL . 'mobile-landing.php'); exit; }

setLongSessionCookie();
header('Content-Type: text/html; charset=UTF-8');

session_start();
require_once __DIR__ . '/assets/config.php';
/* Si la sesión del servidor caducó, se rehace con el token recordado. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assets/remember-login.php';
restoreRememberedLogin($pdo);

$desktopUserKey = null;
foreach ($loginUsers as $key => $user) {
    if ($user['label'] === $desktopLabel) { $desktopUserKey = $key; break; }
}

if (!isset($_SESSION['user']) || $_SESSION['user'] !== $desktopUserKey) {
    header('Location: ' . $DESKTOP_BASE_URL . 'index.php');
    exit;
}

/* Timbre (assets/php/timbre.php): archivo estático del usuario que mira
   window.melonTimbre (más abajo) en vez de preguntar a cada API cada
   pocos segundos. Se crea ya si no existe, para que no dé 404 al mirarlo,
   y se lee lo que tiene ahora: es el punto de partida de la página. */
require_once __DIR__ . '/assets/php/timbre.php';
[$rtTimbre, $rtTimbreDatos] = (function () use ($pdo, $desktopUserKey) {
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $st->execute([$desktopUserKey]);
    $uid = (int)$st->fetchColumn();
    if ($uid <= 0) return ['', []];
    rt_asegurarUsuario($uid);
    $url = rt_urlUsuario($uid);
    $datos = json_decode((string)@file_get_contents(__DIR__ . '/' . $url), true);
    return [$url, is_array($datos) ? $datos : []];
})();

/* Enlace compartido que espera desde index.php?abrir=… (assets/links.php):
   lo abre melonOpenLink al cargar. */
require_once __DIR__ . '/assets/links.php';
$melonPendingLink = melonTakePendingLink();

// Tras validar sesión: averiguar la pareja del usuario (0 si no tiene).
require_once __DIR__ . '/db.php';
/** @var PDO $pdo */
$stmtP = $pdo->prepare("
    SELECT p.id FROM parejas p
    JOIN usuarios u1 ON p.usuario1_id = u1.id
    JOIN usuarios u2 ON p.usuario2_id = u2.id
    WHERE u1.username = ? OR u2.username = ?
");
$stmtP->execute([strtolower($desktopLabel), strtolower($desktopLabel)]);
$rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
$parejaId = $rowP ? (int)$rowP['id'] : 0;

$hasPlayer = true;

require_once __DIR__ . '/assets/themes/theme-helpers.php';
require_once __DIR__ . '/assets/php/active-interface.php';
/* Flag de build solo-dev (define MELON_DEV_BUILD). En main vale false →
   las funciones exclusivas de dev no se renderizan. */
require_once __DIR__ . '/assets/dev-build.php';
/* Sistemas con app de escritorio descargable (aviso del haro). */
require_once __DIR__ . '/assets/desktop/downloads.php';

/* INTERFAZ POR USUARIO:
   La interfaz activa se guarda en BD como SLUG directa
   (user_settings.active_interface_slug = '"kawaii"' como JSON string).
   SIEMPRE sincronizamos cookie ← BD; si no hay preferencia guardada,
   caemos al default 'win98'. Sin este reseteo, un user heredaría la
   cookie del user anterior. */
(function() use (&$pdo, $desktopUserKey) {
    /** @var PDO $pdo */
    $slug = 'win98';
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$desktopUserKey]);
    $uid = (int)($stmt->fetchColumn() ?: 0);
    if ($uid) {
        $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_interface_slug'");
        $stmt->execute([$uid]);
        $raw = (string)$stmt->fetchColumn();
        if ($raw !== '') {
            /* `value` es JSON (CHECK json_valid). Decodificamos. */
            $candidate = json_decode($raw, true);
            if (is_string($candidate) && $candidate !== ''
                && is_dir(__DIR__ . '/assets/interfaces/' . $candidate . '/')) {
                $slug = $candidate;
            }
        }
    }
    $currentCookie = $_COOKIE['activeInterface'] ?? '';
    if ($currentCookie !== $slug) {
        setcookie('activeInterface', $slug, [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);
        $_COOKIE['activeInterface'] = $slug;
    }
})();

/* FUENTE POR USUARIO/INTERFAZ (assets/php/fuentes.php): la de la
   interfaz activa pasa de la BD a la cookie melonFuente, que lee
   emitInterfaceCss() aquí y en las apps (iframes). La de serie = sin
   cookie. */
(function() use (&$pdo, $desktopUserKey) {
    require_once __DIR__ . '/assets/php/fuentes.php';
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$desktopUserKey]);
    $uid = (int)($stmt->fetchColumn() ?: 0);
    $iface = getActiveInterface();
    $slug = melonFuenteActiva($pdo, $uid, $iface);
    melonFuenteCookie($slug === melonFuenteDefecto($iface) ? '' : $slug);
})();

/* ICON PACK POR USUARIO/INTERFAZ:
   Igual que con la interfaz, sincronizamos el icon pack del user para
   la interfaz activa. El valor vive en BD (user_settings.icon_pack:<iface>)
   pero icon-pack.js client lee de localStorage. Sin este sync, shell y
   BD divergen — y cuando alguien visita TU perfil ve un pack distinto
   al que tú estás viendo. */
$_shellIconPack = 'Melon';
(function() use (&$pdo, $desktopUserKey, &$_shellIconPack) {
    /** @var PDO $pdo */
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$desktopUserKey]);
    $uid = (int)($stmt->fetchColumn() ?: 0);
    if (!$uid) return;
    $iface = $_COOKIE['activeInterface'] ?? 'win98';
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
    $stmt->execute([$uid, 'icon_pack:' . $iface]);
    $raw = (string)$stmt->fetchColumn();
    if ($raw === '') return;
    $pack = json_decode($raw, true);
    if (is_string($pack) && $pack !== '') $_shellIconPack = $pack;
})();

/* Resuelve qué tema usar para la interfaz activa del usuario. Lee la
   cookie themeFor_<interface> que setea apps/temas.php al activar un
   tema. Si no hay cookie, cae al tema global (retrocompat). */
$_activeInterface = getActiveInterface();
$_themeMeta       = getActiveThemeForInterface($desktopUserKey, $desktopLabel, $_activeInterface);
$activeTheme      = $_themeMeta['name'];
$activeThemeClass = $_themeMeta['className'];
$activeThemeCss   = $_themeMeta['cssRel'];
/* Self-heal: si el archivo CSS del tema activo no existe (típico tras
   un deploy de Hostinger que borra uploads/themes/), lo regeneramos
   junto con todos los demás temas del usuario desde la BD. Sin esto,
   el escritorio cargaría con el tema default tras cada push hasta que
   el usuario entrara a Temas y forzara la regeneración. */
if ($activeThemeCss !== '' && !file_exists(__DIR__ . '/' . $activeThemeCss)) {
    refreshAllUserThemesCss($desktopUserKey, $desktopLabel);
    if (!file_exists(__DIR__ . '/' . $activeThemeCss)) $activeThemeCss = '';
}
/* Mantenemos loadUserThemes para retrocompat con código que pueda
   leer $_userThemes más abajo. */
$_userThemes = loadUserThemes($desktopUserKey);

$wallpaper = getUserWallpaper($desktopLabel);
$startIcon = getUserStartIcon($desktopLabel);

/* Haro activo del usuario para el sistema de notificaciones — se emite
   como global JS más abajo. Si user_settings no tiene preferencia, cae
   al haro 'green' (base que todos los usuarios tienen). */
require_once __DIR__ . '/assets/personalize/haro-paths.php';
$haroUid       = userIdByKey($desktopUserKey);
/** @var PDO $pdo */
$haroSlug      = $haroUid ? activeHaroSlug($pdo, $haroUid) : 'green';
$haroAssets    = haroPaths($haroSlug);
$activeFsDelta = 0; /* default: sin escalado */
if ($activeTheme !== '') {
    $uid = userIdByKey($desktopUserKey);
    $tWp = ''; $tSi = '';
    if ($uid) {
        /* Filtramos por interface_name además de (user_id, name): la
           tabla themes tiene UNIQUE (user_id, interface_name, name),
           así que el mismo "Capi" puede existir para win98 y para
           kawaii. Sin el filtro, MySQL devolvía cualquiera de las dos
           y se perdía el fontDelta de la activa. */
        $arow = null;
        try {
            $st = themesPdo()->prepare("SELECT wallpaper, start_icon, colors FROM themes WHERE user_id = ? AND name = ? AND interface_name = ?");
            $st->execute([$uid, $activeTheme, $_activeInterface]);
            $arow = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $_) {
            /* BD legacy sin columna interface_name → caemos al query sin
               filtrar. Las migraciones idempotentes deberían haber
               creado la columna ya, pero por si acaso. */
            $st = themesPdo()->prepare("SELECT wallpaper, start_icon, colors FROM themes WHERE user_id = ? AND name = ?");
            $st->execute([$uid, $activeTheme]);
            $arow = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($arow) {
            $tWp = (string)$arow['wallpaper'];
            $tSi = (string)$arow['start_icon'];
            /* Delta de tamaño de fuente. Vive en themes.colors junto a
               la paleta — el cliente lo guarda ahí desde la app de
               Temas. Lo emitimos a JS para que font-scale.js lo aplique
               desde el primer paint. Clamp coincide con el del backend. */
            $themeColorsRaw = json_decode((string)$arow['colors'], true);
            if (is_array($themeColorsRaw) && isset($themeColorsRaw['fontDelta'])) {
                $activeFsDelta = (int)$themeColorsRaw['fontDelta'];
                if ($activeFsDelta < -6) $activeFsDelta = -6;
                if ($activeFsDelta >  10) $activeFsDelta = 10;
            }
        }
    }
    /* Si el tema tiene asset propio y existe → úsalo; si no, mantén el
       wallpaper/icono global del usuario calculado arriba (no se cae al
       default salvo que el propio global tampoco exista). */
    /* Si el path del tema apunta a un archivo borrado por el deploy,
       intentamos restaurarlo desde el blob en BD antes de validar. */
    if ($tWp !== '' && !file_exists(__DIR__ . '/' . $tWp)) {
        if (function_exists('restoreThemeAssetFromDb')) restoreThemeAssetFromDb($tWp, 'wallpaper');
    }
    if ($tSi !== '' && !file_exists(__DIR__ . '/' . $tSi)) {
        if (function_exists('restoreThemeAssetFromDb')) restoreThemeAssetFromDb($tSi, 'start_icon');
    }
    if ($tWp !== '' && file_exists(__DIR__ . '/' . $tWp)) $wallpaper = $tWp;
    if ($tSi !== '' && file_exists(__DIR__ . '/' . $tSi)) $startIcon = $tSi;
}

/* URL absoluta del proyecto. Los stubs viven en /desktops/<label>-desktop.php,
   así que dirname(dirname(SCRIPT_NAME)) sube un nivel hasta la raíz. La
   necesitamos porque las url() en inline style del <body> NO respetan
   <base href> de forma consistente (Chrome resuelve contra location.href
   → /desktops/assets/... → 404). Con URL absoluta el navegador no tiene
   que adivinar. */
$_projectBaseUrl = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';
$bodyStyles = [];
if ($wallpaper) $bodyStyles[] = "background-image:url('{$_projectBaseUrl}{$wallpaper}')";
if ($startIcon) $bodyStyles[] = "--start-icon-url:url('{$_projectBaseUrl}{$startIcon}')";
$wallpaperStyle = $bodyStyles ? implode(';', $bodyStyles) : '';

$_iconTheme = 'default';
function desktopIcon(string $name, string $emoji) {
    global $_iconTheme;
    foreach ([$_iconTheme, 'default'] as $dir) {
        foreach (['png', 'jpg', 'gif'] as $ext) {
            $rel  = "assets/img/icons/{$dir}/{$name}.{$ext}";
            if (file_exists(__DIR__ . '/' . $rel)) {
                return '<img src="' . $rel . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">';
            }
        }
    }
    return $emoji;
}

/* Helper para el title-bar de las ventanas. */
function appTitleIcon(string $pngName, string $emoji): string {
    $rel    = "assets/img/appIcons/{$pngName}.png";
    $melon  = "assets/img/appIcons/Melon/{$pngName}.png";
    if (file_exists(__DIR__ . '/' . $melon) || file_exists(__DIR__ . '/' . $rel)) {
        return '<img src="' . $rel . '" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;" alt="">';
    }
    return $emoji . ' ';
}

/* Helper: dado un path tipo `assets/img/appIcons/X.png`, verifica si
   existe (en raíz o en la carpeta `Melon`, donde ahora viven todos
   los iconos por defecto). Lo usan los echos de desktop-icons para
   decidir entre img o emoji fallback. */
function _appIconExists(string $rel): bool {
    if (file_exists(__DIR__ . '/' . $rel)) return true;
    $melon = preg_replace('#/appIcons/#', '/appIcons/Melon/', $rel, 1);
    return file_exists(__DIR__ . '/' . $melon);
}

/* Flag global: lo leen tanto <html data-tablet>, el <meta viewport> como
   el <link rel="stylesheet" href="tablet.css">. Calculado UNA vez aquí. */
$_isTablet = isTabletDevice();
?>
<!DOCTYPE html>
<html lang="es"<?php echo $_isTablet ? ' data-tablet="1"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <?php if ($_isTablet): ?>
    <!-- Tablet: viewport responsive al ancho real del dispositivo. La shell
         + ventanas se adaptan vía body.is-tablet + assets/css/tablet.css. -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php else: ?>
    <!-- PC: layout fijo a 1280 → el navegador lo escala para encajar
         en el ancho del dispositivo. Pinch-zoom queda habilitado para
         que el usuario amplíe los elementos pequeños de Win98 si los
         dedos no caben. -->
    <meta name="viewport" content="width=1280, user-scalable=yes">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($desktopLabel); ?> - Escritorio</title>
    <!-- Los stubs <label>-desktop.php viven en /desktops/ pero todas las
         rutas relativas (assets/, apps/, logout.php) apuntan a la raíz.
         Sin este <base> el browser resolvería contra /desktops/ y rompería
         CSS/JS/iframes. Debe ir ANTES del primer href/src del head. -->
    <base href="../">
    <link rel="icon" href="assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <!-- Sincroniza el icon pack del user desde BD → localStorage ANTES
         de que icon-pack.js lo lea. Sin esto, si el usuario cambió su
         pack en otra sesión/dispositivo, el shell local lo ignoraba.
         Tambien previene divergencia con perfil ajeno (que lee de BD). -->
    <script>
        (function() {
            try {
                var pack  = <?php echo json_encode($_shellIconPack); ?>;
                var iface = <?php echo json_encode($_activeInterface ?? 'win98'); ?>;
                if (pack && iface) {
                    localStorage.setItem('iconPack:' + iface, pack);
                    localStorage.setItem('iconPack', pack);
                }
            } catch (_) {}
        })();
    </script>
    <!-- Swap de packs de iconos (lee localStorage.iconPack). DEBE cargar
         antes de cualquier render que dependa de iconos. -->
    <script src="assets/js/icon-pack.js"></script>
    <!-- "🔗 Copiar enlace" (melonCopyLink) de los menús y del perfil. -->
    <script src="assets/js/melon-links.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/melon-links.js'); ?>"></script>
    <?php if ($_isTablet): ?>
    <!-- En tablet, los iconos del escritorio y mascota usan long-press
         para abrir su menú contextual (sustituto del click derecho). -->
    <script src="assets/js/longpress.js"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/reproductor.css?v=<?php echo filemtime(__DIR__ . '/assets/css/reproductor.css'); ?>">
    <link rel="stylesheet" href="assets/css/perfil.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/perfil.css'); ?>">
    <link rel="stylesheet" href="assets/css/themes.css">
    <!-- Marcos de perfil: apps/perfil.php va incluido aquí y su <link> a
         marcos.css solo está en su cabecera standalone. Sin esto el marco
         (data-marco, de melon-marcos.js) no se dibuja en el escritorio. -->
    <link rel="stylesheet" href="assets/css/marcos.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/marcos.css'); ?>">
    <?php if ($_isTablet): ?>
    <!-- Overrides para tablets (taskbar, ventanas, hit-targets). Va DESPUÉS
         de themes.css para que sus reglas ganen sin !important. -->
    <link rel="stylesheet" href="assets/css/tablet.css">
    <?php endif; ?>
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?php echo htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <!-- INTERFACE CSS: cargada al final para que pueda sobreescribir
         98.css + themes.css. Lee cookie `activeInterface` (default win98). -->
    <?php
        require_once __DIR__ . '/assets/php/active-interface.php';
        emitInterfaceCss('');
    ?>
    <!-- Delta de fuente del tema activo. Se setea ANTES de cargar
         interface-loader.js para que el font-scale.js que se inyecta
         desde ahí ya vea el valor correcto y aplique el escalado en
         el primer paint (sin flash de tamaño base). -->
    <script>window.__fontScaleDelta = <?php echo (int)$activeFsDelta; ?>;</script>
    <script src="assets/js/interface-loader.js?v=fs1"></script>
    <script>
    (function(){
        if (window.__win98DialogsLoaded) return;
        window.__win98DialogsLoaded = true;
        var Z = 999999;
        function build(opts){
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.25);z-index:' + (Z++) + ';display:flex;align-items:center;justify-content:center;';
            var win = document.createElement('div');
            win.className = 'window';
            win.style.cssText = 'min-width:300px;max-width:480px;z-index:' + (Z++) + ';';
            var bar = document.createElement('div'); bar.className = 'title-bar';
            var barText = document.createElement('div'); barText.className = 'title-bar-text';
            barText.textContent = opts.title || (opts.type === 'alert' ? 'Aviso' : opts.type === 'confirm' ? 'Confirmar' : 'Introduce un valor');
            var barCtl = document.createElement('div'); barCtl.className = 'title-bar-controls';
            var closeBtn = document.createElement('button'); closeBtn.setAttribute('aria-label','Close');
            barCtl.appendChild(closeBtn); bar.appendChild(barText); bar.appendChild(barCtl);
            var body = document.createElement('div'); body.className = 'window-body'; body.style.cssText = 'padding:12px 14px;';
            var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:12px;align-items:flex-start;';
            var icon = document.createElement('div');
            icon.textContent = opts.type === 'confirm' ? '❓' : opts.type === 'prompt' ? '✏️' : 'ℹ️';
            icon.style.cssText = 'font-size:24px;line-height:1;flex:0 0 28px;';
            var msg = document.createElement('div');
            msg.style.cssText = 'flex:1;font-size:11px;white-space:pre-wrap;line-height:1.4;';
            msg.textContent = opts.message || '';
            row.appendChild(icon); row.appendChild(msg); body.appendChild(row);
            var input = null;
            if (opts.type === 'prompt') {
                input = document.createElement('input'); input.type = 'text';
                input.value = (opts.defaultVal != null ? String(opts.defaultVal) : '');
                input.style.cssText = 'width:100%;margin-top:10px;box-sizing:border-box;';
                body.appendChild(input);
            }
            var btns = document.createElement('div');
            btns.style.cssText = 'display:flex;justify-content:flex-end;gap:6px;margin-top:14px;';
            var okBtn = document.createElement('button'); okBtn.className = 'default'; okBtn.textContent = opts.okLabel || 'Aceptar';
            var cancelBtn = null;
            if (opts.type !== 'alert') {
                cancelBtn = document.createElement('button'); cancelBtn.textContent = opts.cancelLabel || 'Cancelar';
                btns.appendChild(cancelBtn);
            }
            btns.appendChild(okBtn); body.appendChild(btns);
            win.appendChild(bar); win.appendChild(body);
            overlay.appendChild(win); document.body.appendChild(overlay);
            if (input) setTimeout(function(){ input.focus(); input.select(); }, 30);
            else       setTimeout(function(){ okBtn.focus(); }, 30);
            function close(){ document.removeEventListener('keydown', onKey, true); overlay.remove(); }
            function doOk(){ close(); if (opts.onOk) opts.onOk(input ? input.value : true); }
            function doCancel(){ close(); if (opts.onCancel) opts.onCancel(); }
            function onKey(e){
                if (e.key === 'Enter')      { e.preventDefault(); doOk(); }
                else if (e.key === 'Escape'){ e.preventDefault(); doCancel(); }
            }
            document.addEventListener('keydown', onKey, true);
            okBtn.addEventListener('click', doOk);
            if (cancelBtn) cancelBtn.addEventListener('click', doCancel);
            closeBtn.addEventListener('click', doCancel);
        }
        window.win98Alert = function(message, title, onOk){
            if (typeof title === 'function') { onOk = title; title = undefined; }
            build({ type:'alert', message:String(message), title:title, onOk:onOk });
        };
        window.win98Confirm = function(message, title, onOk, onCancel){
            if (typeof title === 'function') { onCancel = onOk; onOk = title; title = undefined; }
            build({ type:'confirm', message:String(message), title:title, onOk:onOk, onCancel:onCancel });
        };
        window.win98Prompt = function(message, defaultVal, onOk, onCancel, title){
            build({ type:'prompt', message:String(message), defaultVal:defaultVal, onOk:onOk, onCancel:onCancel, title:title });
        };
        window.alert = function(message){ window.win98Alert(message); };
    })();
    </script>
</head>

<body class="<?php
    $bodyClasses = [];
    if ($activeThemeClass) $bodyClasses[] = $activeThemeClass;
    if ($startIcon) $bodyClasses[] = 'has-start-icon';
    if ($_isTablet)  $bodyClasses[] = 'is-tablet';
    echo htmlspecialchars(implode(' ', $bodyClasses));
?>"<?php echo $wallpaperStyle ? " style=\"{$wallpaperStyle}\"" : ''; ?>>

<div id="page-enter"></div>

<?php if ($_isTablet): ?>
<script>
/* --vh-fix: arregla el 100vh "saltarín" de iOS Safari (la barra de URL
   aparece/desaparece y cambia innerHeight). tablet.css usa svh nativo
   donde puede; este sync es el fallback. */
(function() {
    function syncVh() {
        document.documentElement.style.setProperty('--vh-fix', (window.innerHeight * 0.01) + 'px');
    }
    syncVh();
    window.addEventListener('resize', syncVh, { passive: true });
    window.addEventListener('orientationchange', syncVh, { passive: true });
})();
/* Marca tablet en JS para que las apps en iframe puedan detectar a
   través de parent (cuando son same-origin). */
window.__IS_TABLET__ = true;
</script>
<?php endif; ?>

<script>
    window.DesktopParejaId = <?php echo (int)$parejaId; ?>;
/* Helper: añade `tablet=1` al iframe src cuando el shell está en modo
   tablet. Los apps en iframe heredan así la señal y pueden decidir su
   propio viewport/layout sin volver a olfatear el UA. */
window.appSrc = function(path) {
    if (!window.__IS_TABLET__) return path;
    var sep = path.indexOf('?') === -1 ? '?' : '&';
    return path + sep + 'tablet=1';
};
window.DesktopState = { icons: {}, folders: [], player: null, loaded: false, _readyCbs: [] };
window.DesktopState.whenReady = function(cb){
    if (this.loaded) cb();
    else this._readyCbs.push(cb);
};
</script>

<!-- DESKTOP CONTEXT MENU (right-click) -->
<ul id="desktop-ctx-menu" class="desk-ctx">
    <li data-action="new-folder"><img src="assets/img/appIcons/folderIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Nueva carpeta</li>
</ul>

<!-- ICON CONTEXT MENU (táctil: long-press sobre un icono) -->
<ul id="desktop-icon-ctx-menu" class="desk-ctx">
    <li data-action="move">📦 Mover</li>
</ul>

<!-- FOLDER WINDOW TEMPLATE -->
<div class="window win-folder-template" id="folder-window-template" data-no-auto-z style="display:none; position:fixed; flex-direction:column;">
    <div class="title-bar">
        <div class="title-bar-text"><?php echo appTitleIcon('folderIcon', '📁'); ?>Carpeta</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close"></button>
        </div>
    </div>
    <div class="window-body folder-content"></div>
</div>

<!-- MODAL "Nueva carpeta" -->
<div class="window" id="folder-create-modal" data-no-auto-z style="display:none; flex-direction:column; position:fixed;">
    <div class="title-bar">
        <div class="title-bar-text"><img src="assets/img/appIcons/folderIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Nueva carpeta</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="folder-create-x"></button>
        </div>
    </div>
    <div class="window-body" style="padding:14px;">
        <p style="margin-bottom:10px;font-size:11px;">Nombre de la carpeta:</p>
        <input id="folder-create-input" type="text" maxlength="40" style="width:100%;" value="Nueva carpeta">
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:14px;">
            <button id="folder-create-ok" class="default">Aceptar</button>
            <button id="folder-create-cancel">Cancelar</button>
        </div>
    </div>
</div>

<!-- MODAL cambiar contraseña -->
<div class="window win-modal-narrow" id="change-password-modal" data-no-auto-z style="display:none; flex-direction:column; position:fixed;">
    <div class="title-bar">
        <div class="title-bar-text">🔑 Cambiar contraseña</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="change-password-x"></button>
        </div>
    </div>
    <div class="window-body" style="padding:14px;">
        <div style="display:flex;flex-direction:column;gap:8px;">
            <label style="font-size:11px;">Contraseña actual:
                <input id="cp-current" type="password" autocomplete="current-password" style="width:100%;display:block;margin-top:3px;">
            </label>
            <label style="font-size:11px;">Nueva contraseña:
                <input id="cp-new" type="password" autocomplete="new-password" minlength="6" style="width:100%;display:block;margin-top:3px;">
            </label>
            <label style="font-size:11px;">Repetir nueva:
                <input id="cp-confirm" type="password" autocomplete="new-password" minlength="6" style="width:100%;display:block;margin-top:3px;">
            </label>
        </div>
        <p id="cp-status" style="margin:10px 0 0;font-size:11px;min-height:14px;"></p>
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:12px;">
            <button id="cp-ok" class="default">Aceptar</button>
            <button id="cp-cancel">Cancelar</button>
        </div>
    </div>
</div>

<!-- MODAL Changelog -->
<div class="window" id="changelog-modal" data-no-auto-z style="display:none;flex-direction:column;position:fixed;width:520px;height:560px;min-width:320px;min-height:240px;">
    <div class="title-bar">
        <div class="title-bar-text">Changelog</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="changelog-x"></button>
        </div>
    </div>
    <div class="window-body" style="padding:0;flex:1;min-height:0;display:flex;flex-direction:column;">
        <div id="changelog-body" style="flex:1;min-height:0;overflow-y:auto;padding:12px 14px;font-size:12px;line-height:1.5;">
            Cargando…
        </div>
    </div>
</div>

<!-- MODAL Reportes (bug/sugerencia → Discord) -->
<div class="window" id="reports-modal" data-no-auto-z style="display:none;flex-direction:column;position:fixed;width:420px;height:560px;min-width:320px;min-height:280px;">
    <div class="title-bar">
        <div class="title-bar-text">Reportes</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="reports-x"></button>
        </div>
    </div>
    <style>
        /* Botones tipo del modal Reportes — bien diferenciados. */
        .report-type-btn {
            transition: none;
            position: relative;
        }
        .report-type-btn.is-active {
            background: var(--accent, #000080) !important;
            color: var(--accent-text, #fff) !important;
            font-weight: bold;
            box-shadow:
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf),
                inset  2px  2px var(--bezel-dark-2, grey) !important;
        }
        .report-type-btn:not(.is-active) {
            opacity: 0.85;
        }
    </style>
    <div class="window-body" style="padding:14px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;flex:1;min-height:0;" data-report-type="bug">
        <div style="font-size:11px;margin-bottom:2px;">Tipo:</div>
        <div style="display:flex;gap:6px;">
            <button type="button" class="button report-type-btn is-active" data-type="bug" style="flex:1;font-size:12px;padding:6px 10px;min-height:32px;">🐛 Bug</button>
            <button type="button" class="button report-type-btn" data-type="suggestion" style="flex:1;font-size:12px;padding:6px 10px;min-height:32px;">💡 Sugerencia</button>
        </div>
        <label style="font-size:11px;display:block;">Título
            <input id="report-title" type="text" maxlength="200" style="width:100%;display:block;margin-top:4px;box-sizing:border-box;padding:4px 6px;">
        </label>
        <!-- Descripción ocupa el espacio extra del body cuando la
             ventana se hace más alta. Antes el body usaba margin-top
             auto en los botones para empujarlos al fondo, pero eso
             dejaba un hueco enorme entre el último input y los
             botones. Ahora es la textarea la que crece, los botones
             quedan justo debajo. -->
        <label style="font-size:11px;display:flex;flex-direction:column;flex:1;min-height:120px;">Descripción
            <textarea id="report-body" maxlength="1900" style="width:100%;display:block;margin-top:4px;resize:vertical;font-family:inherit;font-size:12px;box-sizing:border-box;padding:4px 6px;flex:1;min-height:80px;"></textarea>
        </label>
        <div style="font-size:11px;">Imágenes (opcional, máx 4)</div>
        <div style="display:flex;gap:6px;align-items:center;margin-top:4px;">
            <!-- Input file nativo OCULTO. El botón Win98 de abajo lo
                 dispara con .click() — así el control sigue el estilo
                 de la interfaz seleccionada en lugar del look nativo
                 del navegador. -->
            <input id="report-files" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;">
            <button type="button" class="button" id="report-files-browse">Examinar...</button>
            <span id="report-files-name" style="font-size:11px;color:var(--text-faint,#666);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Sin archivos</span>
        </div>
        <div id="report-files-list" style="font-size:10px;color:var(--text-faint,#666);min-height:12px;"></div>
        <p id="report-status" style="margin:6px 0 0;font-size:11px;min-height:14px;"></p>
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:6px;">
            <button id="report-ok" class="default">Enviar</button>
            <button id="report-cancel">Cancelar</button>
        </div>
    </div>
</div>

<!-- MODAL confirmar borrar carpeta -->
<div class="window" id="folder-delete-modal" data-no-auto-z style="display:none; flex-direction:column; position:fixed;">
    <div class="title-bar">
        <div class="title-bar-text">Confirmar eliminación de carpeta</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="folder-delete-x"></button>
        </div>
    </div>
    <div class="window-body w98-confirm-body">
        <div class="w98-confirm-row">
            <div class="w98-icon-question"></div>
            <div class="w98-confirm-text" id="folder-delete-text">¿Borrar la carpeta?</div>
        </div>
        <div class="w98-confirm-btns">
            <button id="folder-delete-ok" class="default">Sí</button>
            <button id="folder-delete-cancel">No</button>
        </div>
    </div>
</div>

<div id="desktop">
    <div class="desktop-icon" id="archive-icon">
        <div class="desktop-icon-img"><?php
            $_archiveIcon = 'assets/img/appIcons/melonArchiveIcon.png';
            echo _appIconExists($_archiveIcon)
                ? '<img src="' . $_archiveIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('archive', '📼');
        ?></div>
        <span>MelonArchive</span>
    </div>
    <div class="desktop-icon" id="calendar-icon">
        <div class="desktop-icon-img"><?php
            $_calendarIcon = 'assets/img/appIcons/calendarioIcon.png';
            echo _appIconExists($_calendarIcon)
                ? '<img src="' . $_calendarIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">'
                : desktopIcon('calendar', '📅');
        ?></div>
        <span>Calendario</span>
    </div>
    <div class="desktop-icon" id="profile-icon">
        <div class="desktop-icon-img"><?php
            $_profileIcon = 'assets/img/appIcons/profileIcon.png';
            echo _appIconExists($_profileIcon)
                ? '<img src="' . $_profileIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('profile', '👤');
        ?></div>
        <span>Perfil</span>
    </div>
    <div class="desktop-icon" id="temas-icon">
        <div class="desktop-icon-img"><?php
            $_temasIcon = 'assets/img/appIcons/temasIcon.png';
            echo _appIconExists($_temasIcon)
                ? '<img src="' . $_temasIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('temas', '🎨');
        ?></div>
        <span>Temas</span>
    </div>
    <div class="desktop-icon" id="companion-icon">
        <div class="desktop-icon-img"><?php
            $_companionIcon = 'assets/img/appIcons/companionIcon.png';
            echo _appIconExists($_companionIcon)
                ? '<img src="' . $_companionIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('companion', '💀');
        ?></div>
        <span>Companion</span>
    </div>
    <div class="desktop-icon" id="dnd-icon">
        <div class="desktop-icon-img"><?php
            $_dndIcon = 'assets/img/appIcons/dndIcon.png';
            echo _appIconExists($_dndIcon)
                ? '<img src="' . $_dndIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">'
                : desktopIcon('dnd', '⚔');
        ?></div>
        <span>Fichas D&amp;D</span>
    </div>
    <div class="desktop-icon" id="galeria-icon">
        <div class="desktop-icon-img"><?php
            $_galeriaIcon = 'assets/img/appIcons/galeriaIcon.png';
            echo _appIconExists($_galeriaIcon)
                ? '<img src="' . $_galeriaIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('galeria', '🖼');
        ?></div>
        <span>Galería</span>
    </div>
    <!-- ★ NUEVO: icono Dibujo -->
    <div class="desktop-icon" id="dibujo-icon">
        <div class="desktop-icon-img"><?php
            $_drawingIcon = 'assets/img/appIcons/drawingIcon.png';
            echo _appIconExists($_drawingIcon)
                ? '<img src="' . $_drawingIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">'
                : desktopIcon('dibujo', '✏️');
        ?></div>
        <span>Dibujo</span>
    </div>
    <div class="desktop-icon" id="tienda-icon">
        <div class="desktop-icon-img"><?php
            $_tiendaIcon = 'assets/img/appIcons/tiendaIcon.png';
            echo _appIconExists($_tiendaIcon)
                ? '<img src="' . $_tiendaIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('tienda', '🛒');
        ?></div>
        <span>Tienda</span>
    </div>
    <div class="desktop-icon" id="juegos-icon">
        <div class="desktop-icon-img"><?php
            $_juegosIcon = 'assets/img/appIcons/juegosIcon.png';
            echo _appIconExists($_juegosIcon)
                ? '<img src="' . $_juegosIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('juegos', '🎮');
        ?></div>
        <span>Juegos</span>
    </div>
    <?php if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD): ?>
    <!-- Icono de mascotas (SOLO DEV — gated por MELON_DEV_BUILD). -->
    <div class="desktop-icon" id="mascota-desktop-icon">
        <div class="desktop-icon-img"><?php
            $_mascotaIcon = 'assets/img/appIcons/mascotaIcon.png';
            echo _appIconExists($_mascotaIcon)
                ? '<img src="' . $_mascotaIcon . '" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt="">'
                : desktopIcon('mascota', '🐾');
        ?></div>
        <span>Mascota</span>
    </div>
    <?php endif; ?>
</div>

<!-- CALENDAR WINDOW -->
<div class="window" id="calendar-window" style="display:none; position:fixed; left:5vw; top:4vh; width:90vw; height:88vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="calendar-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('calendarioIcon', '📅'); ?>Calendario</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="calendar-close"></button>
        </div>
    </div>
    <iframe id="calendar-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- MASCOTA WINDOW -->
<div class="window win-mascota" id="mascota-window"
     style="display:none; position:fixed; left:15vw; top:8vh; z-index:550; flex-direction:column;">
    <div class="title-bar" id="mascota-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('mascotaIcon', '🐾'); ?>Mascota</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="mascota-close"></button>
        </div>
    </div>
    <iframe id="mascota-frame" src="" frameborder="0"
            style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- VENTANA "Wrapped" — Spotify Wrapped del usuario.
     Embed del slide deck en iframe full-screen. Se abre por dos vías:
       1. Notificación automática cada 22 de diciembre.
       2. Botón DEV (configurable). -->
<div class="window" id="wrapped-window"
     style="display:none; position:fixed; left:5vw; top:4vh; width:90vw; height:88vh; z-index:9500; flex-direction:column;">
    <div class="title-bar" id="wrapped-titlebar">
        <div class="title-bar-text">🎁 Wrapped</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="wrapped-close-window"></button>
        </div>
    </div>
    <iframe id="wrapped-frame" src="" frameborder="0"
            allow="web-share; clipboard-write"
            style="flex:1; width:100%; border:none; display:block; background:#000;"></iframe>
</div>

<!-- VENTANA "Alimentar" — picker de comidas. Se monta con el catálogo
     de assets/mascota/foods.php (no se duplica). Al click → POST feed
     con el slug → cierra ventana → muestra reacción. -->
<?php require_once __DIR__ . '/assets/mascota/foods.php'; ?>
<div class="window" id="alimentar-window"
     style="display:none; position:fixed; left:30vw; top:18vh; width:320px; z-index:560; flex-direction:column;">
    <div class="title-bar" id="alimentar-titlebar">
        <div class="title-bar-text">🍴 Alimentar</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="alimentar-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:8px;">
        <p style="margin:0 0 8px;font-size:11px;">¿Qué le das?</p>
        <div id="alimentar-grid"
             style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;">
            <?php foreach (MASCOTA_FOODS as $slug => $meta): ?>
            <button type="button" class="alimentar-btn" data-slug="<?= htmlspecialchars($slug) ?>"
                    title="<?= htmlspecialchars($meta['nombre']) ?>"
                    style="min-width:0;min-height:60px;padding:4px 2px;display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer;">
                <span style="font-size:24px;line-height:1;"><?= $meta['emoji'] ?></span>
                <span style="font-size:9px;line-height:1.1;text-align:center;color:transparent;text-shadow:0 0 var(--text,#000);"><?= htmlspecialchars($meta['nombre']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ARCHIVE WINDOW -->
<div class="window" id="archive-window">
    <div class="title-bar" id="archive-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('melonArchiveIcon', '📼'); ?>MelonArchive</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="archive-close"></button>
        </div>
    </div>
    <iframe id="archive-frame" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- TEMAS WINDOW -->
<div class="window" id="temas-window" style="display:none; position:fixed; left:10vw; top:6vh; width:80vw; height:80vh; z-index:550; flex-direction:column;">
    <div class="title-bar" id="temas-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('temasIcon', '🎨'); ?>Temas</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="temas-close"></button>
        </div>
    </div>
    <iframe id="temas-frame" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- COMPANION WINDOW -->
<div class="window" id="companion-window" style="display:none; position:fixed; left:10vw; top:6vh; width:80vw; height:80vh; z-index:550; flex-direction:column;">
    <div class="title-bar" id="companion-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('companionIcon', '💀'); ?>Helldivers Companion</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="companion-close"></button>
        </div>
    </div>
    <iframe id="companion-frame" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- DND WINDOW -->
<div class="window" id="dnd-window" style="display:none; position:fixed; left:5vw; top:4vh; width:90vw; height:88vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="dnd-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('dndIcon', '⚔'); ?>Fichas D&amp;D</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="dnd-close"></button>
        </div>
    </div>
    <iframe id="dnd-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- JUEGOS WINDOW (apps/juegos.php: de momento, Wordelon) -->
<div class="window" id="juegos-window" style="display:none; position:fixed; left:calc(50vw - 380px); top:6vh; width:760px; height:640px; max-height:90vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="juegos-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('juegosIcon', '🎮'); ?>Juegos</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="juegos-close"></button>
        </div>
    </div>
    <iframe id="juegos-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- GALERÍA WINDOW -->
<div class="window" id="galeria-window" style="display:none; position:fixed; left:5vw; top:4vh; width:90vw; height:88vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="galeria-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('galeriaIcon', '🖼'); ?>Galería</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="galeria-close"></button>
        </div>
    </div>
    <iframe id="galeria-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- ★ NUEVO: DIBUJO WINDOW -->
<div class="window" id="dibujo-window" style="display:none; position:fixed; left:5vw; top:4vh; width:90vw; height:88vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="dibujo-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('drawingIcon', '✏️'); ?>Dibujo</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="dibujo-close"></button>
        </div>
    </div>
    <iframe id="dibujo-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- KO-FI WINDOW (lo abre la app de Tienda vía postMessage; sin icono propio) -->
<div class="window win-kofi" id="kofi-window" style="display:none; position:fixed; left:32vw; top:8vh; z-index:560; flex-direction:column;">
    <div class="title-bar" id="kofi-titlebar">
        <div class="title-bar-text">☕ Donar (Ko-fi)</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="kofi-close"></button>
        </div>
    </div>
    <iframe id="kofi-iframe" src="" frameborder="0" referrerpolicy="no-referrer" style="flex:1; width:100%; border:none; display:block; background:#fff;"></iframe>
</div>

<!-- TIENDA WINDOW -->
<div class="window" id="tienda-window" style="display:none; position:fixed; left:8vw; top:6vh; width:84vw; height:84vh; z-index:500; flex-direction:column;">
    <div class="title-bar" id="tienda-titlebar">
        <div class="title-bar-text"><?php echo appTitleIcon('tiendaIcon', '🛒'); ?>Tienda</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="tienda-close"></button>
        </div>
    </div>
    <iframe id="tienda-iframe" src="" frameborder="0" style="flex:1; width:100%; border:none; display:block;"></iframe>
</div>

<!-- NOTIFICATION STACK -->
<div id="notif-container"></div>

<!-- START MENU -->
<div id="start-menu">
    <div id="start-menu-inner">
        <div id="start-sidebar">MelonOS 98</div>
        <div id="start-menu-items">
            <div class="menu-sep"></div>
            <a class="menu-item" href="#" id="menu-changelog">Changelog...</a>
            <a class="menu-item" href="#" id="menu-reports">Reportes...</a>
            <a class="menu-item" href="#" id="menu-change-password">Cambiar contraseña...</a>
            <a class="menu-item" href="#" id="menu-desktop-app" style="display:none">Descargar app de escritorio...</a>
            <a class="menu-item" href="logout.php">Cerrar sesión...</a>
            <div class="menu-sep"></div>
        </div>
    </div>
</div>

<!-- MASCOTA -->
 <div id="mascota-root"></div>

 <!-- MASCOTA MENU BTN — botón flotante cuadrado con icono ☰ (tres barras).
      Esquina inferior derecha encima del taskbar. Se mantiene OCULTO
      hasta que el engine dispara `spawn()` (al pulsar el icono de la app
      en el escritorio). -->
<button id="mascota-menu-btn" title="Mascota" aria-label="Menú mascota" style="display:none;">☰</button>

<!-- TASKBAR -->
<div id="taskbar">
    <button id="start-btn" class="button">
        <span class="win-logo">
            <span style="background:#ff2020"></span>
            <span style="background:#20c820"></span>
            <span style="background:#2020f0"></span>
            <span style="background:#f0c800"></span>
        </span>
        Inicio
    </button>
    <div class="taskbar-sep"></div>
    <div id="taskbar-tasks"></div>
    <button class="button" id="tray-player-btn" title="Reproductor">♪▶</button>
    <button class="button" id="tray-volume-btn" title="Volumen global">
        <!-- Icono altavoz: SVG outline, sin fondo. Las ondas las
             actualiza el JS según el nivel (0/bajo/medio/alto).
             Tamaño/centrado vienen del CSS (#tray-volume-btn svg). -->
        <svg id="tray-volume-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <path id="tray-vol-wave1" d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
            <path id="tray-vol-wave2" d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
        </svg>
    </button>
    <div id="system-tray">
        <span id="tray-clock">00:00</span>
    </div>
</div>

<!-- POPUP del volumen global. Layout: altavoz | slider | %.
     Slider horizontal (el vertical de CSS está roto en Chromium
     moderno y queda sin pintar). -->
<div class="window" id="tray-volume-popup"
     style="display:none;position:fixed;z-index:100000;width:200px;flex-direction:column;">
    <div class="title-bar" style="font-size:10px;">
        <div class="title-bar-text">Volumen</div>
    </div>
    <div class="window-body" style="padding:8px 10px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" style="flex-shrink:0;">
                <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
            </svg>
            <input type="range" id="tray-volume-range" min="0" max="100" step="1" value="100"
                   style="flex:1;min-width:0;">
            <span id="tray-volume-readout"
                  style="font-size:11px;font-variant-numeric:tabular-nums;min-width:3em;text-align:right;">100%</span>
        </div>
    </div>
</div>

<script src="assets/js/timbre.js?v=<?= @filemtime(__DIR__ . '/assets/js/timbre.js') ?>"></script>
<script>
/* Timbre del escritorio (assets/js/timbre.js): el archivo del usuario y lo
   que tenía al generar esta página. */
window.melonTimbre = window.melonCrearTimbre(<?= json_encode($rtTimbre) ?>, <?= json_encode((object)$rtTimbreDatos) ?>, 3000);
</script>
<?php include __DIR__ . '/apps/reproductor.php'; ?>
<?php include __DIR__ . '/apps/perfil.php'; ?>

<script>
/* =========================
   ANIMACION ENTRADA
========================= */
const pageEnter = document.getElementById('page-enter');
pageEnter.addEventListener('animationend', () => pageEnter.remove());

/* =========================
   RELOJ
========================= */
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('tray-clock').textContent = h + ':' + m;
}
updateClock();
setInterval(updateClock, 1000);

/* =========================
   VOLUMEN GLOBAL
   -------------------------
   Botón en taskbar (entre música y reloj) abre un slider vertical.
   El valor (0-100) se aplica como factor a TODO lo que suene en la
   página: <audio>, <video>, el YouTube player del reproductor, e
   iframes hijos same-origin. Se persiste en localStorage. La música
   del reproductor mantiene su propio volumen local — este factor
   multiplica por encima, así "100%" deja la música tal cual.
========================= */
(function(){
    var KEY = 'globalVolumeFactor';
    var btn   = document.getElementById('tray-volume-btn');
    var popup = document.getElementById('tray-volume-popup');
    var range = document.getElementById('tray-volume-range');
    var out   = document.getElementById('tray-volume-readout');
    if (!btn || !popup || !range || !out) return;

    function getStored() {
        try { var v = parseFloat(localStorage.getItem(KEY));
              return isFinite(v) ? Math.max(0, Math.min(1, v)) : 1; }
        catch (_) { return 1; }
    }
    function store(f) {
        try { localStorage.setItem(KEY, String(f)); } catch (_) {}
    }

    /* Aplica el factor a una única ventana (document + iframes
       same-origin recursivamente). Idempotente: si la ventana ya tiene
       un factor seteado, sólo lo reaplica. */
    function applyTo(win) {
        if (!win) return;
        try {
            win.__globalVolumeFactor = window.__globalVolumeFactor;
            var doc = win.document;
            if (doc) {
                var media = doc.querySelectorAll('audio, video');
                for (var i = 0; i < media.length; i++) {
                    var el = media[i];
                    /* Guardamos el volumen "natural" la primera vez
                       que tocamos cada media; así "factor=1" lo
                       restaura exacto y "factor=0.5" lo deja al 50%
                       de su valor original. */
                    if (el.dataset.origVol === undefined) {
                        el.dataset.origVol = String(el.volume);
                    }
                    el.volume = (parseFloat(el.dataset.origVol) || 1) * win.__globalVolumeFactor;
                }
                /* YouTube player del reproductor (si existe en este
                   contexto). setVolume acepta 0-100, así que
                   multiplicamos por 100. NO tocamos su _userVolume
                   interno — esto es un factor multiplicador encima. */
                if (typeof win.ytPlayer !== 'undefined' && win.ytPlayer && typeof win.ytPlayer.setVolume === 'function') {
                    var base = (typeof win._userYtVolume === 'number') ? win._userYtVolume : 100;
                    win.ytPlayer.setVolume(Math.round(base * win.__globalVolumeFactor));
                }
            }
            /* Recursa a iframes same-origin. */
            var frames = doc ? doc.querySelectorAll('iframe') : [];
            for (var j = 0; j < frames.length; j++) {
                try { applyTo(frames[j].contentWindow); } catch (_) {}
                try { frames[j].contentWindow.postMessage({ type: 'global-volume', factor: win.__globalVolumeFactor }, '*'); }
                catch (_) {}
            }
        } catch (_) { /* cross-origin: silenciamos */ }
    }

    function setFactor(f) {
        f = Math.max(0, Math.min(1, f));
        window.__globalVolumeFactor = f;
        store(f);
        var pct = Math.round(f * 100);
        if (range && parseInt(range.value, 10) !== pct) range.value = pct;
        if (out) out.textContent = pct + '%';
        /* Icono altavoz outline: muestra ondas según nivel. < 40%
           sólo la onda corta; ≥ 75% las dos; 0 ninguna (muted). */
        var w1 = document.getElementById('tray-vol-wave1');
        var w2 = document.getElementById('tray-vol-wave2');
        if (w1) w1.style.display = pct > 0  ? '' : 'none';
        if (w2) w2.style.display = pct >= 75 ? '' : 'none';
        applyTo(window);
    }
    /* API pública para que reproductor/cualquier app pueda consultar
       el factor cuando crea un nuevo audio/video. */
    window.getGlobalVolumeFactor = function() {
        return (typeof window.__globalVolumeFactor === 'number') ? window.__globalVolumeFactor : 1;
    };
    window.setGlobalVolumeFactor = setFactor;

    /* MutationObserver: media añadido dinámicamente (track nuevo del
       reproductor, video que aparece en una app) recibe el factor
       actual sin esperar al próximo slider tick. */
    var mo = new MutationObserver(function(){ applyTo(window); });
    mo.observe(document.documentElement, { childList: true, subtree: true });

    /* Reaplica periódicamente — pillas casos donde algo cambió volume
       sin pasar por nosotros (típico: reproductor cambia de track). */
    setInterval(function(){ applyTo(window); }, 2000);

    /* Toggle popup. Posicionado encima del botón. */
    function openPopup() {
        var r = btn.getBoundingClientRect();
        popup.style.display = 'flex';
        var pw = popup.offsetWidth, ph = popup.offsetHeight;
        var left = Math.round(r.left + r.width/2 - pw/2);
        var top  = Math.round(r.top - ph - 4);
        if (left + pw > window.innerWidth - 4) left = window.innerWidth - pw - 4;
        if (left < 4) left = 4;
        if (top < 4) top = 4;
        popup.style.left = left + 'px';
        popup.style.top  = top  + 'px';
    }
    function closePopup() { popup.style.display = 'none'; }
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        if (popup.style.display === 'none') openPopup(); else closePopup();
    });
    document.addEventListener('click', function(e){
        if (popup.style.display !== 'none' && !popup.contains(e.target) && e.target !== btn) closePopup();
    });
    range.addEventListener('input', function(){ setFactor(range.value / 100); });

    /* Init */
    setFactor(getStored());
})();

/* =========================
   TEMA EN VIVO (hot-swap)
========================= */
window.applyThemeToDocument = function(doc, className, cssHref) {
    if (!doc || !doc.body) return;
    var body = doc.body;
    /* Conserva solo clases estructurales; capi/angie ya no se usan. */
    var keep = (body.className || '').split(/\s+/).filter(function(c) {
        return c === 'has-start-icon';
    });
    if (className) keep.push(className);
    body.className = keep.join(' ');

    var existing = doc.getElementById('active-theme-link');
    if (cssHref) {
        var bust = (cssHref.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
        if (existing) {
            existing.href = cssHref + bust;
        } else {
            var link = doc.createElement('link');
            link.rel  = 'stylesheet';
            link.id   = 'active-theme-link';
            link.href = cssHref + bust;
            doc.head.appendChild(link);
        }
    } else if (existing) {
        existing.remove();
    }
};

window.addEventListener('message', function(e) {
    if (!e.data) return;
    if (e.data.type === 'theme-activated') {
        var className = e.data.className || '';
        var basePath  = e.data.cssBasePath || '';
        applyThemeToDocument(document, className, basePath);
        ['calendar-iframe', 'archive-frame', 'temas-frame', 'companion-frame', 'dnd-iframe', 'galeria-iframe', 'juegos-iframe'].forEach(function(id) {
            var fr = document.getElementById(id);
            if (!fr || !fr.contentDocument || !fr.contentDocument.body) return;
            var childHref = basePath ? '../' + basePath : '';
            applyThemeToDocument(fr.contentDocument, className, childHref);
        });
    } else if (e.data.type === 'font-scale-delta' && typeof e.data.delta === 'number') {
        /* La app de Temas envía este mensaje en cada cambio del
           slider de tamaño de fuente. Lo aplicamos al shell + a
           todos los iframes hijos para preview en vivo y al activar
           el tema. */
        if (typeof window.setFontScaleDelta === 'function') {
            window.setFontScaleDelta(e.data.delta);
        }
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try { iframes[i].contentWindow.postMessage({ type: 'font-scale-delta', delta: e.data.delta }, '*'); }
            catch (_) {}
        }
    } else if (e.data.type === 'theme-color-preview') {
        /* Live preview de un color del editor de Temas — aplica el
           CSS var al body del shell Y a todos los iframes hijos (apps
           abiertas) para que vean el cambio en tiempo real, sin
           necesidad de guardar. La variable inline gana al theme CSS
           (specificity inline > class) y al CSS de la interfaz. */
        var cssVar = e.data.cssVar || '';
        var value  = e.data.value  || '';
        if (cssVar.charAt(0) === '-' && /^#[0-9a-f]{6}$/i.test(value)) {
            /* !important para vencer a interfaces como kawaii que
               declaran las vars con !important en sus :root. */
            try { document.body.style.setProperty(cssVar, value, 'important'); } catch (_) {}
            /* Propaga a iframes de apps abiertas. */
            ['calendar-iframe', 'archive-frame', 'temas-frame', 'companion-frame', 'dnd-iframe', 'galeria-iframe', 'juegos-iframe'].forEach(function(id) {
                var fr = document.getElementById(id);
                if (!fr || !fr.contentDocument || !fr.contentDocument.body) return;
                try { fr.contentDocument.body.style.setProperty(cssVar, value, 'important'); } catch (_) {}
            });
        }
    } else if (e.data.type === 'wallpaper-changed') {
        var wp = e.data.wallpaper || '';
        if (wp) {
            /* Mismo motivo que en start-icon-changed: resolver contra
               document.baseURI (respeta <base href="../">) — si dejamos
               la URL relativa, el navegador la resuelve contra
               location.href que apunta a /desktops/ y da 404. */
            var wpAbs = new URL(wp, document.baseURI).href;
            document.body.style.backgroundImage = "url('" + wpAbs + "?t=" + Date.now() + "')";
        } else {
            document.body.style.backgroundImage = '';
        }
    } else if (e.data.type === 'haro-changed') {
        if (typeof window.applyHaroSlug === 'function') {
            window.applyHaroSlug(e.data.slug || 'green');
        }
    } else if (e.data.type === 'start-icon-changed') {
        var si = e.data.icon || '';
        if (si) {
            /* Resolver contra document.baseURI (respeta <base href="../">)
               y NO contra location.href, que apunta a /desktops/ y rompería
               el src de la imagen. */
            var abs = new URL(si, document.baseURI).href;
            document.body.style.setProperty('--start-icon-url', "url('" + abs + "?t=" + Date.now() + "')");
            document.body.classList.add('has-start-icon');
        } else {
            document.body.style.removeProperty('--start-icon-url');
            document.body.classList.remove('has-start-icon');
        }
    } else if (e.data.type === 'profile-photo-changed') {
        var newPath = e.data.photo || '';
        if (!newPath) return;
        var m = newPath.match(/\/([^/]+)\.[^/]+$/);
        if (!m) return;
        var basename = m[1].toLowerCase();
        function refreshImgs(doc) {
            if (!doc) return;
            var imgs = doc.querySelectorAll('img');
            for (var i = 0; i < imgs.length; i++) {
                var src = imgs[i].getAttribute('src') || '';
                var sm = src.match(/\/([^/?]+)\.[a-zA-Z0-9]+(?:\?|$)/);
                if (sm && sm[1].toLowerCase() === basename) {
                    imgs[i].src = newPath + '?t=' + Date.now();
                }
            }
        }
        refreshImgs(document);
        ['calendar-iframe', 'archive-frame', 'temas-frame', 'companion-frame', 'dnd-iframe', 'galeria-iframe', 'juegos-iframe'].forEach(function(id) {
            var fr = document.getElementById(id);
            if (fr && fr.contentDocument) refreshImgs(fr.contentDocument);
        });
    } else if (e.data.type === 'open-profile') {
        if (typeof window.openProfileAtUser === 'function') {
            window.openProfileAtUser(e.data.userKey);
        }
    }
});

/* =========================
   START MENU
========================= */
const startBtn = document.getElementById('start-btn');
const startMenu = document.getElementById('start-menu');

startBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    const visible = startMenu.style.display === 'block';
    startMenu.style.display = visible ? 'none' : 'block';
    startBtn.classList.toggle('active', !visible);
});

document.addEventListener('click', function() {
    startMenu.style.display = 'none';
    startBtn.classList.remove('active');
});

/* =========================
   CAMBIAR CONTRASEÑA
   ========================
   Abre un modal Win98 con 3 inputs (actual / nueva / repetir).
   Valida en cliente (mismas reglas que en backend) y hace POST al
   endpoint assets/auth/change-password.php. En éxito muestra un toast
   verde y cierra; en error pinta el motivo en #cp-status. */
(function(){
    var link = document.getElementById('menu-change-password');
    if (!link) return;
    var modal   = document.getElementById('change-password-modal');
    var current = document.getElementById('cp-current');
    var newPwd  = document.getElementById('cp-new');
    var confirm = document.getElementById('cp-confirm');
    var status  = document.getElementById('cp-status');
    var okBtn   = document.getElementById('cp-ok');

    function reset(){
        current.value = ''; newPwd.value = ''; confirm.value = '';
        status.textContent = ''; status.style.color = '';
        okBtn.disabled = false;
    }
    function open(e){
        if (e) e.preventDefault();
        reset();
        modal.style.display = 'flex';
        if (window.centerModal) window.centerModal(modal);
        if (window.windowZ) windowZ.bringToFront('change-password-modal');
        setTimeout(function(){ current.focus(); }, 30);
    }
    function close(){ modal.style.display = 'none'; reset(); }

    link.addEventListener('click', open);
    document.getElementById('change-password-x').addEventListener('click', close);
    document.getElementById('cp-cancel').addEventListener('click', close);

    async function submit(){
        var c = current.value, n = newPwd.value, r = confirm.value;
        if (!c || !n || !r){ status.style.color = 'var(--error-text,#c00)'; status.textContent = 'Rellena todos los campos.'; return; }
        if (n.length < 6){ status.style.color = 'var(--error-text,#c00)'; status.textContent = 'La nueva debe tener al menos 6 caracteres.'; return; }
        if (n !== r){ status.style.color = 'var(--error-text,#c00)'; status.textContent = 'La nueva contraseña y la repetición no coinciden.'; return; }
        if (c === n){ status.style.color = 'var(--error-text,#c00)'; status.textContent = 'La nueva tiene que ser distinta de la actual.'; return; }

        okBtn.disabled = true;
        status.style.color = 'var(--text-muted,#666)';
        status.textContent = 'Guardando…';
        try {
            var resp = await fetch('assets/auth/change-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current: c, new: n })
            });
            var data = await resp.json().catch(function(){ return { error: 'Respuesta inválida' }; });
            if (!resp.ok || data.error){
                status.style.color = 'var(--error-text,#c00)';
                status.textContent = '✗ ' + (data.error || ('HTTP ' + resp.status));
                okBtn.disabled = false;
                return;
            }
            status.style.color = 'var(--accent-deep,#080)';
            status.textContent = '✔ Contraseña actualizada.';
            setTimeout(close, 900);
        } catch (err) {
            status.style.color = 'var(--error-text,#c00)';
            status.textContent = '✗ Error de red: ' + err.message;
            okBtn.disabled = false;
        }
    }
    okBtn.addEventListener('click', submit);
    /* Enter en cualquiera de los inputs envía. */
    [current, newPwd, confirm].forEach(function(el){
        el.addEventListener('keydown', function(ev){
            if (ev.key === 'Enter'){ ev.preventDefault(); submit(); }
            else if (ev.key === 'Escape'){ ev.preventDefault(); close(); }
        });
    });
})();

/* =========================
   CHANGELOG
   ─────────────────────────
   Modal que renderiza changelog.md (raíz del proyecto). Mini parser
   Markdown inline cubriendo headers, listas, bold, italic, code y links
   — suficiente para una changelog. Sin libs externas para no añadir peso. */
(function(){
    var link    = document.getElementById('menu-changelog');
    if (!link) return;
    var modal   = document.getElementById('changelog-modal');
    var bodyEl  = document.getElementById('changelog-body');
    var loaded  = false;

    function escHtml(s){
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    /* Renderiza Markdown muy básico → HTML. Maneja:
        # h1 / ## h2 / ### h3
        - lista     |   * lista     |   N. lista numerada
        **bold**    |   *italic*    |   `code`     |   [text](url)
        ```block code```
        Líneas en blanco → separador de párrafo. */
    function mdToHtml(md){
        var lines = String(md).split(/\r?\n/);
        var out = [], i = 0, inUl = false, inOl = false, inCode = false;
        function flushList(){
            if (inUl) { out.push('</ul>'); inUl = false; }
            if (inOl) { out.push('</ol>'); inOl = false; }
        }
        function inline(s){
            /* Code inline primero (no procesar markdown dentro). */
            s = s.replace(/`([^`]+)`/g, function(_, c){ return '<code>' + escHtml(c) + '</code>'; });
            /* Escape el resto. */
            s = escHtml(s);
            /* Re-aplicar los <code> que ya estaban escapados. */
            s = s.replace(/&lt;code&gt;([\s\S]*?)&lt;\/code&gt;/g, '<code>$1</code>');
            /* Bold y italic. */
            s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/(^|[^*])\*([^*\s][^*]*?)\*/g, '$1<em>$2</em>');
            /* Links [text](url). */
            s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(_, t, u){
                return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + t + '</a>';
            });
            return s;
        }
        while (i < lines.length) {
            var line = lines[i];
            /* Code block fence. */
            if (/^```/.test(line)) {
                flushList();
                if (!inCode) {
                    inCode = true;
                    out.push('<pre><code>');
                } else {
                    inCode = false;
                    out.push('</code></pre>');
                }
                i++; continue;
            }
            if (inCode) { out.push(escHtml(line)); i++; continue; }
            /* Horizontal rule (--- o *** o ___) — divisor entre versiones. */
            if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
                flushList();
                out.push('<hr>');
                i++; continue;
            }
            /* Headers. */
            var h = line.match(/^(#{1,3})\s+(.*)$/);
            if (h) {
                flushList();
                var lvl = h[1].length;
                out.push('<h' + lvl + '>' + inline(h[2]) + '</h' + lvl + '>');
                i++; continue;
            }
            /* Lista no ordenada. */
            var ul = line.match(/^\s*[-*]\s+(.*)$/);
            if (ul) {
                if (inOl) { out.push('</ol>'); inOl = false; }
                if (!inUl) { out.push('<ul>'); inUl = true; }
                out.push('<li>' + inline(ul[1]) + '</li>');
                i++; continue;
            }
            /* Lista numerada. */
            var ol = line.match(/^\s*\d+\.\s+(.*)$/);
            if (ol) {
                if (inUl) { out.push('</ul>'); inUl = false; }
                if (!inOl) { out.push('<ol>'); inOl = true; }
                out.push('<li>' + inline(ol[1]) + '</li>');
                i++; continue;
            }
            /* Línea en blanco. */
            if (!line.trim()) {
                flushList();
                i++; continue;
            }
            /* Párrafo normal. */
            flushList();
            out.push('<p>' + inline(line) + '</p>');
            i++;
        }
        flushList();
        if (inCode) out.push('</code></pre>');
        return out.join('\n');
    }

    function open(e){
        if (e) e.preventDefault();
        modal.style.display = 'flex';
        if (window.centerModal) window.centerModal(modal);
        if (window.windowZ) windowZ.bringToFront('changelog-modal');
        if (loaded) return;
        loaded = true;
        bodyEl.textContent = 'Cargando…';
        fetch('changelog.md?ts=' + Date.now())
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function(md){ bodyEl.innerHTML = mdToHtml(md); })
            .catch(function(err){
                bodyEl.innerHTML = '<p style="color:var(--error-text,#c00);">Error al cargar el changelog: ' + escHtml(err.message) + '</p>';
            });
    }
    function close(){ modal.style.display = 'none'; }
    link.addEventListener('click', open);
    document.getElementById('changelog-x').addEventListener('click', close);
})();

/* Estilos básicos para el body del changelog en desktop. */
(function(){
    var style = document.createElement('style');
    style.textContent =
        /* H1 = título de versión. Bezel hundido Win98 con fondo accent
           para que cada versión se vea como una "cabecera de sección"
           inequívoca. Padding generoso. */
        '#changelog-body h1{font-size:17px;margin:0 0 14px;padding:10px 12px;background:var(--accent,#000080);color:var(--accent-text,#fff);' +
            'box-shadow:inset 1px 1px var(--bezel-dark-1,#0a0a0a),inset -1px -1px var(--bezel-light-1,#fff),inset 2px 2px var(--bezel-dark-2,grey),inset -2px -2px var(--bezel-light-2,#dfdfdf);}' +
        '#changelog-body h2{font-size:14px;margin:14px 0 8px;border-bottom:1px solid var(--border,#808080);padding-bottom:3px;}' +
        '#changelog-body h3{font-size:12px;margin:10px 0 4px;color:var(--text-muted,#444);}' +
        '#changelog-body p{margin:0 0 8px;}' +
        '#changelog-body ul, #changelog-body ol{margin:0 0 8px;padding-left:20px;}' +
        '#changelog-body li{margin:0 0 3px;}' +
        '#changelog-body code{font-family:"Lucida Console",Consolas,monospace;background:rgba(0,0,0,0.08);padding:1px 4px;font-size:11px;}' +
        '#changelog-body pre{background:rgba(0,0,0,0.08);padding:8px;overflow-x:auto;}' +
        '#changelog-body pre code{background:transparent;padding:0;}' +
        '#changelog-body a{color:var(--accent,#000080);}' +
        /* HR = divisor doble Win98 entre versiones (línea oscura + línea
           clara debajo) con margen amplio para airear. */
        '#changelog-body hr{border:0;border-top:2px solid var(--bezel-dark-1,#0a0a0a);border-bottom:2px solid var(--bezel-light-1,#fff);margin:32px 0;}';
    document.head.appendChild(style);
})();

/* =========================
   REPORTES (BUG / SUGERENCIA → DISCORD)
========================= */
(function(){
    var link    = document.getElementById('menu-reports');
    if (!link) return;
    var modal   = document.getElementById('reports-modal');
    var body    = modal.querySelector('[data-report-type]');
    var typeBtns= modal.querySelectorAll('.report-type-btn');
    var titleEl = document.getElementById('report-title');
    var bodyEl  = document.getElementById('report-body');
    var filesEl = document.getElementById('report-files');
    var listEl  = document.getElementById('report-files-list');
    var status  = document.getElementById('report-status');
    var okBtn   = document.getElementById('report-ok');

    function setStat(msg, color){ status.style.color = color || ''; status.textContent = msg || ''; }
    function setType(t){
        body.dataset.reportType = t;
        typeBtns.forEach(function(b){
            b.classList.toggle('is-active', b.dataset.type === t);
        });
    }
    function reset(){
        titleEl.value = ''; bodyEl.value = ''; filesEl.value = '';
        listEl.textContent = '';
        if (typeof updateFileNameSummary === 'function') updateFileNameSummary(0);
        else if (fileName) fileName.textContent = 'Sin archivos';
        setType('bug');
        setStat(''); okBtn.disabled = false;
    }
    function open(e){
        if (e) e.preventDefault();
        reset();
        modal.style.display = 'flex';
        if (window.centerModal) window.centerModal(modal);
        if (window.windowZ) windowZ.bringToFront('reports-modal');
        setTimeout(function(){ titleEl.focus(); }, 30);
    }
    function close(){ modal.style.display = 'none'; reset(); }
    link.addEventListener('click', open);
    document.getElementById('reports-x').addEventListener('click', close);
    document.getElementById('report-cancel').addEventListener('click', close);

    typeBtns.forEach(function(b){
        b.addEventListener('click', function(){ setType(b.dataset.type); });
    });

    /* Browse button dispara el input file oculto. El span junto al
       botón muestra el resumen del archivo (1 nombre o "N archivos"). */
    var browseBtn = document.getElementById('report-files-browse');
    var fileName  = document.getElementById('report-files-name');
    if (browseBtn) browseBtn.addEventListener('click', function(){ filesEl.click(); });
    function updateFileNameSummary(n){
        if (!fileName) return;
        if (!n) { fileName.textContent = 'Sin archivos'; return; }
        if (n === 1) { fileName.textContent = filesEl.files[0].name; return; }
        fileName.textContent = n + ' archivos seleccionados';
    }
    filesEl.addEventListener('change', function(){
        var n = filesEl.files ? filesEl.files.length : 0;
        if (!n) { listEl.textContent = ''; updateFileNameSummary(0); return; }
        if (n > 4) {
            setStat('Máximo 4 imágenes.', 'var(--error-text,#c00)');
            filesEl.value = ''; listEl.textContent = ''; updateFileNameSummary(0); return;
        }
        var names = [];
        for (var i = 0; i < n; i++) names.push(filesEl.files[i].name);
        listEl.textContent = names.join(', ');
        updateFileNameSummary(n);
    });

    function submit(){
        var type = body.dataset.reportType || 'bug';
        var t = (titleEl.value || '').trim();
        var b = (bodyEl.value  || '').trim();
        if (!t) { setStat('Pon un título.', 'var(--error-text,#c00)'); return; }
        if (!b) { setStat('Escribe la descripción.', 'var(--error-text,#c00)'); return; }
        okBtn.disabled = true;
        setStat('Enviando…', 'var(--text-muted,#666)');
        var fd = new FormData();
        fd.append('type',  type);
        fd.append('title', t);
        fd.append('body',  b);
        if (filesEl.files) {
            for (var i = 0; i < filesEl.files.length && i < 4; i++) {
                fd.append('files[]', filesEl.files[i]);
            }
        }
        fetch('assets/profile/api.php?action=submit-report', {
            method: 'POST', credentials: 'same-origin', body: fd
        })
        .then(function(r){ return r.json().catch(function(){ return {}; }); })
        .then(function(d){
            if (d && d.ok) {
                setStat('✔ Enviado.', '');
                setTimeout(close, 900);
            } else {
                setStat((d && d.error) || 'Error al enviar.', 'var(--error-text,#c00)');
                okBtn.disabled = false;
            }
        })
        .catch(function(){
            setStat('Error de red.', 'var(--error-text,#c00)');
            okBtn.disabled = false;
        });
    }
    okBtn.addEventListener('click', submit);
})();

/* =========================
   ANIMACION SALIDA
========================= */
document.querySelectorAll('a[href="logout.php"]').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.href;
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:#000;opacity:0;transition:opacity 0.4s ease;z-index:9999;pointer-events:none;';
        document.body.appendChild(overlay);
        setTimeout(() => { overlay.style.opacity = '1'; }, 10);
        setTimeout(() => { window.location.href = href; }, 450);
    });
});

/* =========================
   TASKBAR TASK MANAGER
========================= */
window.windowZ = (function() {
    var topZ = 600;
    function bringToFront(id) {
        var win = (typeof id === 'string') ? document.getElementById(id) : id;
        if (!win) return;
        topZ++;
        win.style.zIndex = topZ;
    }
    function isFrontmost(id) {
        var win = document.getElementById(id);
        if (!win) return false;
        var myZ = parseInt(win.style.zIndex || getComputedStyle(win).zIndex || '0', 10) || 0;
        var maxZ = 0;
        document.querySelectorAll('.window').forEach(function(w){
            if (w === win) return;
            if (w.hasAttribute('data-no-auto-z')) return;
            if (w.style.display === 'none' || getComputedStyle(w).display === 'none') return;
            var z = parseInt(w.style.zIndex || getComputedStyle(w).zIndex || '0', 10) || 0;
            if (z > maxZ) maxZ = z;
        });
        return myZ >= maxZ;
    }

    function isVisible(el) {
        if (!el || !el.classList || !el.classList.contains('window')) return false;
        if (el.classList.contains('hidden')) return false;
        var s = el.style.display;
        if (s === 'none') return false;
        if (!s && getComputedStyle(el).display === 'none') return false;
        return true;
    }
    var lastSeen = new WeakMap();
    function check(el) {
        if (!el || !el.classList || !el.classList.contains('window')) return;
        if (el.hasAttribute('data-no-auto-z')) return;
        var nowVis = isVisible(el);
        var prev   = lastSeen.get(el) === true;
        if (nowVis && !prev) bringToFront(el);
        lastSeen.set(el, nowVis);
    }
    new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (m.type === 'attributes') {
                check(m.target);
            } else if (m.type === 'childList') {
                m.addedNodes.forEach(function(n) {
                    if (n.nodeType !== 1) return;
                    check(n);
                    if (n.querySelectorAll) n.querySelectorAll('.window').forEach(check);
                });
            }
        });
    }).observe(document.body, {
        attributes: true,
        attributeFilter: ['style', 'class'],
        childList: true,
        subtree: true,
    });

    return { bringToFront: bringToFront, isFrontmost: isFrontmost };
})();

window.taskbarManager = (function() {
    var tasksEl  = document.getElementById('taskbar-tasks');
    var registry = {};

    function register(id, label, icon, displayMode) {
        if (registry[id]) { restore(id); return; }
        var win = document.getElementById(id);
        if (!win) return;
        var btn = document.createElement('button');
        btn.className = 'button taskbar-task-btn';
        btn.title = label;
        /* `icon` puede ser un emoji o HTML (<img>). Para soportar ambos
           usamos innerHTML y escapamos solo el label (controlado por
           callers internos, pero por defensa). */
        function _esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        btn.innerHTML = (icon ? icon + ' ' : '') + _esc(label);
        btn.addEventListener('click', function() { toggle(id); });
        tasksEl.appendChild(btn);
        registry[id] = { btn: btn, displayMode: displayMode || 'block' };
        win.style.display = displayMode || 'block';
        windowZ.bringToFront(id);
    }

    function unregister(id) {
        if (!registry[id]) return;
        var win = document.getElementById(id);
        if (win) win.style.display = 'none';
        registry[id].btn.remove();
        delete registry[id];
    }

    function minimize(id) {
        var entry = registry[id];
        if (!entry) return;
        var win = document.getElementById(id);
        if (!win || win.style.display === 'none') return;
        entry.displayMode = win.style.display;
        win.style.display = 'none';
        win.classList.remove('win-maximized');
        entry.btn.classList.add('taskbar-task-minimized');
    }

    function restore(id) {
        var entry = registry[id];
        if (!entry) return;
        var win = document.getElementById(id);
        if (!win) return;
        win.style.display = entry.displayMode || 'block';
        entry.btn.classList.remove('taskbar-task-minimized');
        windowZ.bringToFront(id);
    }

    function toggle(id) {
        var entry = registry[id];
        if (!entry) return;
        var win = document.getElementById(id);
        if (!win) return;
        if (win.style.display === 'none') {
            restore(id);
        } else if (!windowZ.isFrontmost(id)) {
            windowZ.bringToFront(id);
        } else {
            minimize(id);
        }
    }

    function isRegistered(id) { return !!registry[id]; }
    function getButton(id)    { return registry[id] ? registry[id].btn : null; }

    return { register: register, unregister: unregister, minimize: minimize, restore: restore, toggle: toggle, isRegistered: isRegistered, getButton: getButton };
})();

/* Haro activo del usuario — emitido por PHP. El sistema de
   notificaciones lo usa como fuente de verdad para sus URLs y se puede
   reasignar en runtime vía window.applyHaroSlug(slug) sin recargar. */
window.ACTIVE_HARO = <?= json_encode($haroAssets, JSON_UNESCAPED_SLASHES) ?>;

/* =========================
   SISTEMA UNIFICADO DE NOTIFICACIONES
========================= */
window.notifSystem = (function() {
    var container = document.getElementById('notif-container');
    var MAX_VISIBLE  = 3;
    var shownIds     = {};
    var dismissedIds = {};
    var pendingQueue = [];

    /* Animación del Haro: 29 frames @ 10 fps = 2900 ms.
       - HARO_REVEAL_MS: la tarjeta hace pop mientras el Haro aún está
         dando los últimos frames de su rodada, no esperando al final
         exacto. Da sensación de que la entrega es continua.
       - HARO_DURATION_MS: al cumplirse, swap del gif por el PNG estático
         del último frame (extraído con ffmpeg). El "freeze" vía canvas
         no funciona — drawImage(gif) captura el primer frame en casi
         todos los browsers. Por eso usamos un PNG estático.

       Las URLs vienen de window.ACTIVE_HARO (lo emite PHP según el haro
       activo del usuario). Helpers para leerlas dinámicamente — así un
       cambio de haro en personalización surte efecto al instante. */
    function haroGifUrl()   { return (window.ACTIVE_HARO && window.ACTIVE_HARO.gif)   || 'assets/vids/greenHaro.gif'; }
    function haroLastUrl()  { return (window.ACTIVE_HARO && window.ACTIVE_HARO.last)  || 'assets/vids/greenHaro-last.png'; }
    function haroAudioUrl() { return (window.ACTIVE_HARO && window.ACTIVE_HARO.audio) || 'assets/sound/haro.mp3'; }
    var HARO_REVEAL_MS   = 2200;
    var HARO_DURATION_MS = 2900;

    /* Precarga el PNG estático una vez para que el swap del gif al
       último frame sea instantáneo (sin flash). */
    (function preloadLastFrame() {
        var p = new Image();
        p.src = haroLastUrl();
    })();

    /* Audio que se reproduce al irse el Haro. Preload=auto para que el
       primer play no espere a la red. Lo reusamos en cada dismiss
       reseteando currentTime.

       Warm-up: muchos browsers bloquean Audio.play() si no hay un
       "gesture" reciente del usuario; un dismiss disparado desde un
       setTimeout o desde un animationend NO cuenta como gesto. Para
       desbloquear, hacemos un play+pause silencioso en el primer
       click/keydown que llegue a la página. A partir de ahí, la
       política de autoplay considera el elemento "iniciado por usuario"
       y nos deja reproducirlo más tarde sin gesto activo. */
    var haroAudio = new Audio(haroAudioUrl());
    haroAudio.preload = 'auto';
    (function primeOnFirstGesture() {
        var primed = false;
        function prime() {
            if (primed) return;
            primed = true;
            try {
                haroAudio.muted = true;
                var p = haroAudio.play();
                if (p && typeof p.then === 'function') {
                    p.then(function() {
                        haroAudio.pause();
                        haroAudio.currentTime = 0;
                        haroAudio.muted = false;
                    }).catch(function() { haroAudio.muted = false; });
                } else {
                    haroAudio.pause();
                    haroAudio.currentTime = 0;
                    haroAudio.muted = false;
                }
            } catch (_) { haroAudio.muted = false; }
            window.removeEventListener('click',   prime, true);
            window.removeEventListener('keydown', prime, true);
            window.removeEventListener('touchstart', prime, true);
        }
        window.addEventListener('click',      prime, true);
        window.addEventListener('keydown',    prime, true);
        window.addEventListener('touchstart', prime, true);
    })();

    /* El Haro vive a nivel del contenedor, no de un slot. Así sigue
       en pantalla aunque todas las notificaciones de su "tanda" se
       cierren — solo se va cuando el contenedor de slots se vacía.
       Una nueva notif tras el dismiss del Haro arranca otra tanda. */
    var activeHaro   = null;
    var haroPending  = false;

    /* Reproduce el sonido del Haro. Lo dispara cualquier "aparición" de
       notificación: tanto la revelación en lote tras el giro del Haro
       como cualquier notif que entre después (sin Haro).
       COOLDOWN: si llegan varias notifs en un lapso corto (típico cuando
       llegan 5 likes seguidos, etc.) el sonido NO se repite. Solo suena
       una vez por ráfaga. */
    var HARO_SOUND_COOLDOWN_MS = 3000;
    var lastHaroSoundAt = 0;
    function playHaroSound() {
        var now = Date.now();
        if (now - lastHaroSoundAt < HARO_SOUND_COOLDOWN_MS) return;
        lastHaroSoundAt = now;
        try {
            haroAudio.currentTime = 0;
            var pPlay = haroAudio.play();
            if (pPlay && typeof pPlay.then === 'function') {
                pPlay.catch(function(err) {
                    console.warn('[notifSystem] haroAudio play bloqueado:', err);
                });
            }
        } catch (e) {
            console.warn('[notifSystem] haroAudio threw:', e);
        }
    }

    function createHaroEl() {
        if (activeHaro) return activeHaro;
        var h = document.createElement('img');
        h.className = 'notif-haro';
        h.alt = '';
        h.src = haroGifUrl() + '?t=' + Date.now();
        container.appendChild(h);
        activeHaro  = h;
        haroPending = true;

        /* REVEAL_MS: revela todos los slots pendientes mientras la
           bola sigue dando sus últimos frames. Suena el audio en el
           mismo instante en que las tarjetas aparecen. */
        setTimeout(function() {
            if (activeHaro !== h) return;
            haroPending = false;
            var pending = container.querySelectorAll('.notif-slot.pending');
            for (var i = 0; i < pending.length; i++) {
                var p = pending[i];
                p.classList.remove('pending');
                p.classList.add('revealed');
                if (p._startDismiss) {
                    var fn = p._startDismiss;
                    p._startDismiss = null;
                    fn();
                }
            }
            if (pending.length) playHaroSound();
        }, HARO_REVEAL_MS);

        /* DURATION_MS: swap del gif por el PNG estático. */
        setTimeout(function() {
            if (activeHaro !== h) return;
            h.src = haroLastUrl();
            h.classList.add('landed');
        }, HARO_DURATION_MS);

        return h;
    }

    function dismissHaroEl() {
        if (!activeHaro) return;
        var h = activeHaro;
        activeHaro  = null;
        haroPending = false;
        h.classList.add('exiting');
        h.addEventListener('animationend', function() {
            if (h.parentNode) h.remove();
        }, { once: true });
    }

    function relTime(sentAt) {
        if (!sentAt) return '';
        var diff = Math.floor(Date.now() / 1000) - sentAt;
        if (diff < 60)   return 'ahora mismo';
        if (diff < 3600) return 'hace ' + Math.floor(diff / 60) + ' min';
        return 'hace ' + Math.floor(diff / 3600) + 'h';
    }

    function updateTimes() {
        container.querySelectorAll('.notif-time[data-sent]').forEach(function(el) {
            el.textContent = relTime(parseInt(el.dataset.sent, 10));
        });
    }

    function removeSlot(slot) {
        dismissedIds[slot.dataset.id] = true;
        delete shownIds[slot.dataset.id];
        slot.classList.add('notif-card-exiting');
        slot.addEventListener('animationend', function() {
            slot.remove();
            flushQueue();
            /* El Haro solo se va cuando no quedan slots NI cola
               pendiente. Si flushQueue creó nuevos slots, hay con qué
               seguir y el Haro sigue donde está. */
            if (!container.querySelector('.notif-slot') && pendingQueue.length === 0) {
                dismissHaroEl();
            }
        }, { once: true });
    }

    function flushQueue() {
        while (pendingQueue.length && Object.keys(shownIds).length < MAX_VISIBLE) {
            createCard(pendingQueue.shift());
        }
    }

    /* App de escritorio: lo que avisa el haro sale también como
       notificación del sistema cuando no se está mirando Melon Hub
       (electron/desktop-extras.js). Una vez por aviso, aunque espere en
       la cola. */
    var nativeIds = {};
    function nativeNotify(opts) {
        var md = window.melonDesktop;
        if (!md || !md.notify || nativeIds[opts.id] || document.hasFocus()) return;
        nativeIds[opts.id] = true;
        var plain = function(h) {
            var d = document.createElement('div');
            d.innerHTML = String(h || '');
            return (d.textContent || '').replace(/\s+/g, ' ').trim();
        };
        md.notify({ title: plain(opts.title) || 'Melon Hub', body: plain(opts.message) });
    }

    function createCard(opts) {
        if (!opts || !opts.id) return;
        if (shownIds[opts.id] || dismissedIds[opts.id]) return;
        nativeNotify(opts);
        if (Object.keys(shownIds).length >= MAX_VISIBLE) {
            if (!pendingQueue.some(function(q) { return q.id === opts.id; })) pendingQueue.push(opts);
            return;
        }

        var isAction = opts.type === 'action';

        /* Política del Haro (a nivel del contenedor, no del slot):
           - Si no hay Haro activo, esta notif inaugura una nueva tanda
             y lo crea. Las que lleguen mientras la bola gire empezarán
             pending y se revelarán todas juntas en HARO_REVEAL_MS.
           - El Haro persiste mientras quede AL MENOS un slot; cuando
             el último se va, dismissHaroEl() lo retira. */
        if (!activeHaro) createHaroEl();
        var startsPending = haroPending;

        var slot = document.createElement('div');
        slot.className = 'notif-slot';
        if (startsPending) slot.classList.add('pending');
        slot.dataset.id = opts.id;

        var card = document.createElement('div');
        card.className = 'window notif-card ' + (isAction ? 'notif-card-action' : 'notif-card-info');

        var tb = document.createElement('div'); tb.className = 'title-bar';
        var tbText = document.createElement('div'); tbText.className = 'title-bar-text';
        /* innerHTML para permitir <img> en el título (icons de papelera, etc.). */
        tbText.innerHTML = opts.title || 'Notificación';
        tb.appendChild(tbText);
        card.appendChild(tb);

        var body = document.createElement('div'); body.className = 'window-body';
        body.style.padding = '5px 8px 6px';

        if (isAction && opts.senderImage) {
            var topRow = document.createElement('div');
            topRow.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:4px;';
            var avWrap = document.createElement('div'); avWrap.className = 'collab-avatar-wrap';
            /* opts.senderKey (user_key de quien lo manda): su marco de perfil. */
            if (opts.senderKey) avWrap.setAttribute('data-marco-user', opts.senderKey);
            var avImg = document.createElement('img'); avImg.className = 'collab-avatar-img';
            avImg.src = opts.senderImage;
            avWrap.appendChild(avImg); topRow.appendChild(avWrap);
            var msgSpan = document.createElement('span'); msgSpan.style.cssText = 'font-size:11px;';
            msgSpan.textContent = opts.message || '';
            topRow.appendChild(msgSpan); body.appendChild(topRow);
        } else {
            var msgEl = document.createElement('p'); msgEl.style.cssText = 'margin:0 0 3px;font-size:11px;';
            msgEl.textContent = opts.message || ''; body.appendChild(msgEl);
        }

        var timeEl = document.createElement('span'); timeEl.className = 'notif-time';
        if (opts.sentAt) timeEl.dataset.sent = opts.sentAt;
        timeEl.textContent = relTime(opts.sentAt || 0);
        body.appendChild(timeEl);

        if (isAction) {
            var row = document.createElement('div'); row.className = 'field-row';
            row.style.cssText = 'justify-content:flex-end;gap:4px;margin-top:5px;';
            /* rejectLabel / acceptLabel: texto propio de los botones
               (p. ej. el aviso de la app de escritorio: "Ahora no" / "Descargar"). */
            var rejectBtn = document.createElement('button'); rejectBtn.className = 'button'; rejectBtn.textContent = opts.rejectLabel || 'Rechazar';
            rejectBtn.addEventListener('click', function() { if (typeof opts.onReject === 'function') opts.onReject(); removeSlot(slot); });
            var acceptBtn = document.createElement('button'); acceptBtn.className = 'button'; acceptBtn.textContent = opts.acceptLabel || 'Aceptar';
            acceptBtn.addEventListener('click', function() { if (typeof opts.onAccept === 'function') opts.onAccept(); removeSlot(slot); });
            row.appendChild(rejectBtn); row.appendChild(acceptBtn); body.appendChild(row);
        }

        card.appendChild(body);
        slot.appendChild(card);
        /* Insertamos al principio de la lista de slots (la más nueva
           arriba). El <img> del Haro vive aparte como hijo del
           contenedor (position:absolute, no afecta al flex layout). */
        var firstSlot = container.querySelector('.notif-slot');
        if (firstSlot) {
            container.insertBefore(slot, firstSlot);
        } else {
            container.insertBefore(slot, container.firstChild);
        }
        shownIds[opts.id] = slot;

        /* Si esta tarjeta entra visible YA (no espera a la revelación
           del Haro), suena el audio en su aparición. Las pending suenan
           todas juntas en el setTimeout REVEAL_MS de createHaroEl. */
        if (!startsPending) playHaroSound();

        /* Auto-dismiss DESHABILITADO. Pedido expresamente: las notifs
           del Haro NO se cierran solas con el tiempo. Solo desaparecen
           cuando el usuario hace click sobre la tarjeta (o usa los
           botones Aceptar/Rechazar en las de acción). Aunque el caller
           pase `autoDismissAfter`, lo ignoramos. */

        /* Click en la tarjeta no-action → cerrarla manualmente.
           Para las action (con botones Aceptar/Rechazar) NO añadimos
           este handler: el usuario debe usar los botones explícitos
           para no aceptar/rechazar por accidente con un click. */
        if (!isAction) {
            slot.style.cursor = 'pointer';
            slot.addEventListener('click', function(ev) {
                /* Si el caller añadió un onClick, lo ejecutamos antes
                   de cerrar. */
                if (typeof opts.onClick === 'function') {
                    try { opts.onClick(ev); } catch (_) {}
                }
                /* El click ES el descarte definitivo — no hay auto-dismiss.
                   Avisamos al caller para que lo persista en servidor; si
                   no, `dismissedIds` solo vive en memoria y la notif vuelve
                   a aparecer en cada carga mientras su fila siga en BD.
                   `onAutoDismiss` se acepta como alias legacy: quedó
                   huérfano al deshabilitarse el auto-dismiss. */
                var onDismiss = opts.onDismiss || opts.onAutoDismiss;
                if (typeof onDismiss === 'function') {
                    try { onDismiss(); } catch (_) {}
                }
                removeSlot(slot);
            });
        }

        /* Los setTimeout de revelación y freeze viven en createHaroEl
           — están ligados al Haro, no a un slot concreto. */
    }

    setInterval(updateTimes, 30000);

    /* Cambio de haro en runtime (lo dispara apps/temas.php al activar
       uno en personalización). Actualiza window.ACTIVE_HARO, reinstancia
       el <Audio> con la URL nueva y precarga el PNG del nuevo. */
    window.applyHaroSlug = function(slug) {
        slug = String(slug || 'green').toLowerCase().replace(/[^a-z0-9_-]/g, '');
        if (!slug) slug = 'green';
        var specificAudio = 'assets/sound/' + slug + 'Haro.mp3';
        var fallbackAudio = 'assets/sound/haro.mp3';
        /* Asignamos primero todo lo "no auditivo" — el audio se fija
           sincronamente al fallback y se intenta upgradear de forma
           async si existe la versión específica. Si lo asignáramos
           directamente al específico, cualquier `playHaroSound` que
           ocurriera ANTES de la confirmación del 404 fallaría. */
        window.ACTIVE_HARO = {
            slug:  slug,
            gif:   'assets/vids/' + slug + 'Haro.gif',
            last:  'assets/vids/' + slug + 'Haro-last.png',
            audio: fallbackAudio
        };
        try { haroAudio = new Audio(fallbackAudio); haroAudio.preload = 'auto'; } catch (_) {}
        /* HEAD probe: si {slug}Haro.mp3 SÍ existe en el servidor, hacemos
           upgrade silencioso al audio específico. Si no existe (404 o
           bloqueo CORS), nos quedamos con `haro.mp3` ya cargado. */
        try {
            fetch(specificAudio, { method: 'HEAD' }).then(function (r) {
                if (!r.ok) return;
                if (window.ACTIVE_HARO) window.ACTIVE_HARO.audio = specificAudio;
                try { haroAudio = new Audio(specificAudio); haroAudio.preload = 'auto'; } catch (_) {}
            }).catch(function () { /* sin red / CORS → fallback */ });
        } catch (_) {}
        try { (new Image()).src = haroLastUrl(); } catch (_) {}
        /* Si hay un Haro vivo en pantalla, swap inmediato a su nuevo gif. */
        if (activeHaro) {
            try { activeHaro.src = haroGifUrl() + '?t=' + Date.now(); } catch (_) {}
        }
    };

    return {
        show:        createCard,
        dismiss:     function(id) { var s = shownIds[id]; if (s) removeSlot(s); else dismissedIds[id] = true; },
        isShown:     function(id) { return !!shownIds[id]; },
        isDismissed: function(id) { return !!dismissedIds[id]; }
    };
})();

/* =========================
   AVISO: APP DE ESCRITORIO
   El haro avisa al entrar de que existe la app de escritorio (electron/)
   si hay instalable para el sistema del usuario. "Descargar" baja el de
   su sistema (assets/desktop/download.php → GitHub Releases).
   No sale dentro de la propia app, en tablets, ni si ya se contestó:
   "Ahora no" lo calla 14 días y "Descargar", para siempre.
   Para quien dijo "Ahora no" y luego la quiere, la misma descarga está
   en el menú Inicio ("Descargar app de escritorio..."), que sale siempre
   que el aviso podría salir, aunque esté callado.
========================= */
(function() {
    var systems = <?= json_encode(desktopDownloadSystems()) ?>;
    var KEY = 'melonhub.desktopAppPromo';
    if (window.melonDesktop || document.body.classList.contains('is-tablet')) return;

    var platform = ((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '') + ' ' + navigator.userAgent;
    var os = /Win/i.test(platform) ? 'windows'
           : (/Linux/i.test(platform) && !/Android|CrOS/i.test(platform)) ? 'linux'
           : '';
    if (!os || systems.indexOf(os) === -1) return;

    var DAY = 86400000;
    function silenceFor(ms) {
        try { localStorage.setItem(KEY, String(Date.now() + ms)); } catch (_) {}
    }
    function download() {
        /* Ya la tiene: el aviso del haro no vuelve a salir. */
        silenceFor(100 * 365 * DAY);
        /* Iframe oculto: la descarga empieza sin sacar al usuario
           del escritorio (si faltara el archivo, no navega a un 404). */
        var dl = document.createElement('iframe');
        dl.style.display = 'none';
        dl.src = 'assets/desktop/download.php?os=' + os;
        document.body.appendChild(dl);
        setTimeout(function() { dl.remove(); }, 60000);
    }

    var menuItem = document.getElementById('menu-desktop-app');
    if (menuItem) {
        menuItem.style.display = '';
        menuItem.addEventListener('click', function(e) {
            e.preventDefault();
            download();
        });
    }

    var until = 0;
    try { until = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (_) {}
    if (Date.now() < until) return;

    /* Después de las notificaciones del arranque (recordatorios a los 5 s). */
    setTimeout(function() {
        if (!window.notifSystem) return;
        window.notifSystem.show({
            id:      'desktop-app-promo',
            type:    'action',
            title:   '<img src="assets/img/appIcons/downloadIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">App de escritorio',
            message: 'Melon Hub ya tiene app de escritorio para ' + (os === 'windows' ? 'Windows' : 'Linux') + ', con su propia ventana. ¿Quieres descargarla?',
            rejectLabel: 'Ahora no',
            acceptLabel: 'Descargar',
            onReject: function() { silenceFor(14 * DAY); },
            onAccept: download
        });
    }, 8000);
})();

/* =========================
   APP DE ESCRITORIO: CONTADOR Y ARRASTRAR IMÁGENES
   - Los mensajes del chat sin leer salen en el icono de la bandeja
     (get-unread-chats, cada 20 s y al volver a la ventana).
   - Una imagen de Melon Hub (galería, momentos, chat…) se puede arrastrar
     fuera de la app como archivo: al pulsarla la app la va descargando y
     al arrastrarla entrega el archivo (electron/desktop-extras.js). Vale
     para esta página y para las apps abiertas en ventanas (iframes de la
     misma web). No se toca lo que ya se arrastra dentro de Melon Hub
     (elementos draggable, como las filas de las playlists).
========================= */
(function() {
    var md = window.melonDesktop;
    if (!md) return;
    if (md.setBadge) {
        var pollUnread = function() {
            fetch('assets/profile/api.php?action=get-unread-chats', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    var n = 0;
                    Object.keys((d && d.counts) || {}).forEach(function(k) { n += parseInt(d.counts[k], 10) || 0; });
                    md.setBadge(n);
                })
                .catch(function() {});
        };
        pollUnread();
        /* Cuando el timbre dice que hay algo nuevo en los chats (si no
           responde, cada 20 s como antes). */
        window.melonTimbre.on('chat', pollUnread, 20000);
        window.addEventListener('focus', pollUnread);
    }
    if (md.startDrag) {
        var draggableImg = function(t) {
            var img = t && t.closest ? t.closest('img') : null;
            if (!img || !img.currentSrc || img.getAttribute('draggable') === 'false') return null;
            if (img.closest('[draggable="true"]')) return null;
            if (/\/appIcons\//.test(img.currentSrc)) return null;   /* iconos de la interfaz */
            if ((img.naturalWidth || 0) < 64 && (img.naturalHeight || 0) < 64) return null;
            return img;
        };
        var hook = function(doc) {
            if (!doc || doc.__melonDrag) return;
            doc.__melonDrag = true;
            doc.addEventListener('pointerdown', function(e) {
                var img = draggableImg(e.target);
                if (img) md.prepareDrag(img.currentSrc);
            }, true);
            doc.addEventListener('dragstart', function(e) {
                var img = draggableImg(e.target);
                if (!img) return;
                e.preventDefault();
                md.startDrag(img.currentSrc);
            }, true);
        };
        var hookFrame = function(f) {
            var tryHook = function() { try { hook(f.contentDocument); } catch (_) { /* otra web: no */ } };
            tryHook();
            f.addEventListener('load', function() { try { f.contentDocument.__melonDrag = false; } catch (_) {} tryHook(); });
        };
        hook(document);
        document.querySelectorAll('iframe').forEach(hookFrame);
        new MutationObserver(function(muts) {
            muts.forEach(function(m) {
                m.addedNodes.forEach(function(n) {
                    if (n.tagName === 'IFRAME') hookFrame(n);
                    else if (n.querySelectorAll) n.querySelectorAll('iframe').forEach(hookFrame);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();

/* =========================
   AVISO: ACTUALIZACIÓN DE LA APP DE ESCRITORIO
   Dentro de la app, la parte nativa (electron/updater.js) avisa de que hay
   versión nueva y el haro ofrece instalarla: al pulsar "Actualizar" se
   descarga, se instala y la app se reabre sola. Versiones de la app sin
   actualizador no traen onUpdate y aquí no pasa nada.
========================= */
(function() {
    var md = window.melonDesktop;
    if (!md || typeof md.onUpdate !== 'function') return;
    var ICON = '<img src="assets/img/appIcons/downloadIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">';
    var offered = {}, installing = false;
    md.onUpdate(function(s) {
        if (!s || !window.notifSystem) return;
        if (s.status === 'available' && !offered[s.version]) {
            offered[s.version] = true;
            window.notifSystem.show({
                id:      'desktop-update-' + s.version,
                type:    'action',
                title:   ICON + 'Actualización',
                message: 'Hay una versión nueva de la app de escritorio (' + s.version + '). Se instala sola y la app se reinicia.',
                rejectLabel: 'Ahora no',
                acceptLabel: 'Actualizar',
                onAccept: function() { md.installUpdate(); }
            });
        } else if (s.status === 'downloading' && !installing) {
            installing = true;
            window.notifSystem.show({
                id:      'desktop-update-progress',
                type:    'info',
                title:   ICON + 'Actualizando',
                message: 'Descargando la versión ' + s.version + '… La app se reiniciará sola al terminar.'
            });
        } else if (s.status === 'error') {
            installing = false;
            window.notifSystem.show({
                id:      'desktop-update-error-' + Date.now(),
                type:    'action',
                title:   ICON + 'No se pudo actualizar',
                message: s.error || 'Error desconocido.',
                rejectLabel: 'Cerrar',
                acceptLabel: 'Reintentar',
                onAccept: function() { md.installUpdate(); }
            });
        }
    });
})();

/* =========================
   ARCHIVE
========================= */
(function() {
    var archFrame  = document.getElementById('archive-frame');
    var archLoaded = false;

    document.getElementById('archive-icon').addEventListener('dblclick', function() {
        if (!archLoaded) { archFrame.src = appSrc('apps/melonarchive.php'); archLoaded = true; }
        if (taskbarManager.isRegistered('archive-window')) {
            taskbarManager.restore('archive-window');
        } else {
            taskbarManager.register('archive-window', 'MelonArchive', '<img src="assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    });

    document.getElementById('archive-close').addEventListener('click', function() {
        taskbarManager.unregister('archive-window');
        try { archFrame.contentWindow.postMessage({ type: 'archive-stop' }, '*'); } catch(e) {}
    });
})();

/* =========================
   CALENDARIO
========================= */
(function() {
    var calIframe = document.getElementById('calendar-iframe');
    var calLoaded = false;

    document.getElementById('calendar-icon').addEventListener('dblclick', function() {
        if (!calLoaded) { calIframe.src = appSrc('apps/calendario.php'); calLoaded = true; }
        if (taskbarManager.isRegistered('calendar-window')) {
            taskbarManager.restore('calendar-window');
        } else {
            taskbarManager.register('calendar-window', 'Calendario', '<img src="assets/img/appIcons/calendarioIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    });

    document.getElementById('calendar-close').addEventListener('click', function() {
        taskbarManager.unregister('calendar-window');
    });

    /* Deep link Discord: si pendingOpenEvent está en sessionStorage,
       lo movemos a calOpenEvent (que el iframe leerá) y simulamos
       dblclick para abrir el calendario. El módulo de eventos en
       calendario.php hará openWindow + openDetail al detectar la flag. */
    (function() {
        try {
            var pe = sessionStorage.getItem('pendingOpenEvent');
            if (!pe) return;
            sessionStorage.removeItem('pendingOpenEvent');
            sessionStorage.setItem('calOpenEvent', pe);
            var calIcon = document.getElementById('calendar-icon');
            if (calIcon) calIcon.dispatchEvent(new Event('dblclick'));
        } catch(_){}
    })();
})();

/* =========================
   TEMAS
========================= */
(function() {
    var temasFrame  = document.getElementById('temas-frame');
    var temasLoaded = false;
    function openTemas() {
        if (!temasLoaded) { temasFrame.src = appSrc('apps/temas.php'); temasLoaded = true; }
        if (taskbarManager.isRegistered('temas-window')) {
            taskbarManager.restore('temas-window');
        } else {
            taskbarManager.register('temas-window', 'Temas', '<img src="assets/img/appIcons/temasIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    }
    document.getElementById('temas-icon').addEventListener('dblclick', openTemas);
    document.getElementById('temas-close').addEventListener('click', function() {
        taskbarManager.unregister('temas-window');
    });
    /* Auto-open al cargar: si la flag 'temas-restore-tab' está presente
       (la pone temas.php antes de un reload por cambio de iconos),
       reabrimos la app de Temas. temas.php leerá la flag a su vez y
       activará el tab correspondiente. */
    try {
        if (sessionStorage.getItem('temas-restore-tab')) openTemas();
    } catch (_) {}
})();

/* =========================
   COMPANION
========================= */
(function() {
    var companionFrame  = document.getElementById('companion-frame');
    var companionLoaded = false;
    document.getElementById('companion-icon').addEventListener('dblclick', function() {
        if (!companionLoaded) { companionFrame.src = 'https://helldiverscompanion.com'; companionLoaded = true; }
        if (taskbarManager.isRegistered('companion-window')) {
            taskbarManager.restore('companion-window');
        } else {
            taskbarManager.register('companion-window', 'Companion', '<img src="assets/img/appIcons/companionIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    });
    document.getElementById('companion-close').addEventListener('click', function() {
        taskbarManager.unregister('companion-window');
    });
})();

/* =========================
   D&D FICHAS
========================= */
(function() {
    var dndIframe = document.getElementById('dnd-iframe');
    var dndLoaded = false;

    document.getElementById('dnd-icon').addEventListener('dblclick', function() {
        if (!dndLoaded) { dndIframe.src = appSrc('apps/dnd.php'); dndLoaded = true; }
        if (taskbarManager.isRegistered('dnd-window')) {
            taskbarManager.restore('dnd-window');
        } else {
            taskbarManager.register('dnd-window', 'Fichas D&D', '<img src="assets/img/appIcons/dndIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    });

    document.getElementById('dnd-close').addEventListener('click', function() {
        taskbarManager.unregister('dnd-window');
    });
})();

/* =========================
   JUEGOS (Wordelon, Buscaminas, Ajedrez)
========================= */
(function() {
    var juegosIframe = document.getElementById('juegos-iframe');
    var juegosLoaded = false;
    var JUEGOS_IC = '<img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';

    /* abrir = "partida:N" | "torneo:N" (ajedrez): entra directo ahí. */
    function openJuegos(abrir) {
        if (!juegosLoaded) {
            juegosIframe.src = appSrc('apps/juegos.php' + (abrir ? '?abrir=' + encodeURIComponent(abrir) : ''));
            juegosLoaded = true;
        } else if (abrir) {
            try { juegosIframe.contentWindow.postMessage({ type: 'juegos-abrir', abrir: abrir }, location.origin); } catch (e) {}
        }
        if (taskbarManager.isRegistered('juegos-window')) {
            taskbarManager.restore('juegos-window');
        } else {
            taskbarManager.register('juegos-window', 'Juegos', JUEGOS_IC, 'flex');
        }
    }
    window.melonOpenJuegos = openJuegos;
    document.getElementById('juegos-icon').addEventListener('dblclick', function() { openJuegos(); });

    document.getElementById('juegos-close').addEventListener('click', function() {
        taskbarManager.unregister('juegos-window');
    });

    /* Ajedrez: aviso del haro con los retos que te llegan (Aceptar /
       Rechazar), las partidas que te esperan (reto aceptado, ronda de
       torneo: "Jugar") y los torneos cancelados en los que estabas. Si
       Juegos ya está enseñando el ajedrez, la partida entra sola y no
       hace falta avisar.
       No se llama a la API cada 10 s desde cada escritorio abierto (con
       mucha gente era carga fija para el servidor): se pide ajz-avisos
       cuando el timbre (window.melonTimbre) dice que ha cambiado su
       "aviso". También a la hora en que empieza su próximo torneo
       (prox): esa llamada es la que lo arranca (ajz_tick) aunque nadie
       tenga abierto el ajedrez. Si el timbre no responde, como antes:
       la API cada 10 s. */
    function ajzViendo() {
        var win = document.getElementById('juegos-window');
        if (!juegosLoaded || !win || win.style.display === 'none') return null;
        try { return juegosIframe.contentWindow.ajzViendo ? juegosIframe.contentWindow.ajzViendo() : null; } catch (e) { return null; }
    }
    var AJZ_TIT = '<img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Ajedrez';
    function ajzPost(action, body) {
        return fetch('assets/juegos/api.php?action=' + action, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function(r) { return r.json(); });
    }
    function ajzError(msg) {
        window.notifSystem.show({ id: 'ajz-err-' + Date.now(), type: 'info', title: AJZ_TIT, message: msg });
    }
    var ajzPlazo = null, ajzPidiendo = false, ajzOtra = false;
    /* A la hora de su próximo torneo, con un poco de margen al azar para
       que no lleguen todos los escritorios a la vez. */
    function ajzProgramar(d) {
        if (ajzPlazo) { clearTimeout(ajzPlazo); ajzPlazo = null; }
        var falta = d.prox && d.ahora ? d.prox - d.ahora : -1;
        if (falta > 0 && falta < 6 * 3600000) ajzPlazo = setTimeout(pollAjedrez, falta + 300 + Math.random() * 1500);
    }
    function pollAjedrez() {
        if (!window.notifSystem) return;
        /* Nunca dos a la vez; si se pide mientras va, se repite al acabar. */
        if (ajzPidiendo) { ajzOtra = true; return; }
        ajzPidiendo = true;
        fetch('assets/juegos/api.php?action=ajz-avisos', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.ok) return;
                ajzProgramar(d);
                (d.retos || []).forEach(function(x) {
                    var u = x.de || {};
                    window.notifSystem.show({
                        id:          'ajz-reto-' + x.id,
                        type:        'action',
                        title:       AJZ_TIT,
                        message:     (u.nombre || 'Alguien') + ' te reta a una partida (' + x.ritmo + (x.apuesta > 0 ? ', apuesta ' + x.apuesta + ' puntos' : '') + ')',
                        senderImage: u.foto || '',
                        senderKey:   u.key,
                        sentAt:      x.ts,
                        rejectLabel: 'Rechazar',
                        acceptLabel: 'Aceptar',
                        onReject:    function() { ajzPost('ajz-reto-responder', { id: x.id, aceptar: false }).catch(function() {}); },
                        onAccept:    function() {
                            ajzPost('ajz-reto-responder', { id: x.id, aceptar: true }).then(function(r) {
                                if (r && r.ok && r.partida) openJuegos('partida:' + r.partida);
                                else ajzError((r && r.error) || 'No se ha podido aceptar el reto');
                            }).catch(function() { ajzError('Sin conexión'); });
                        },
                    });
                });
                /* Torneo cancelado en el que estabas apuntado. */
                (d.cancelados || []).forEach(function(c) {
                    window.notifSystem.show({
                        id:      'ajz-cancelado-' + c.id,
                        type:    'info',
                        title:   AJZ_TIT,
                        message: 'Se ha cancelado el torneo «' + c.torneo + '» ' +
                                 (c.motivo === 'vacio' ? 'porque no se llenó a tiempo.' : 'porque lo ha cancelado quien lo organizaba.') +
                                 (c.devuelto > 0 ? ' Se te han devuelto los ' + c.devuelto + ' puntos de la entrada.' : ''),
                        sentAt:  c.ts,
                    });
                });
                var v = ajzViendo();
                (d.partidas || []).forEach(function(p) {
                    if (v && (v.vista === 'menu' || v.partida === p.id || (v.vista === 'torneo' && p.torneoId && v.torneo === p.torneoId))) return;
                    var u = p.rival || {};
                    window.notifSystem.show({
                        id:          'ajz-partida-' + p.id,
                        type:        'action',
                        title:       AJZ_TIT,
                        message:     p.torneo ? 'Empieza tu partida del torneo ' + p.torneo + ' contra ' + (u.nombre || '?')
                                              : (u.nombre || 'Tu rival') + ' ha aceptado tu reto: ¡a jugar!',
                        senderImage: u.foto || '',
                        senderKey:   u.key,
                        sentAt:      p.ts,
                        rejectLabel: 'Cerrar',
                        acceptLabel: 'Jugar',
                        onAccept:    function() { openJuegos('partida:' + p.id); },
                    });
                });
            })
            .catch(function() {})
            .then(function() {
                ajzPidiendo = false;
                if (ajzOtra) { ajzOtra = false; pollAjedrez(); }
            });
    }
    setTimeout(pollAjedrez, 4000);
    window.melonTimbre.on('aviso', pollAjedrez, 10000);
})();

/* =========================
   GALERÍA
========================= */
(function() {
    var galIframe = document.getElementById('galeria-iframe');
    var galLoaded = false;

    document.getElementById('galeria-icon').addEventListener('dblclick', function() {
        if (!galLoaded) { galIframe.src = appSrc('apps/galeria.php'); galLoaded = true; }
        if (taskbarManager.isRegistered('galeria-window')) {
            taskbarManager.restore('galeria-window');
        } else {
            taskbarManager.register('galeria-window', 'Galería', '<img src="assets/img/appIcons/galeriaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    });

    document.getElementById('galeria-close').addEventListener('click', function() {
        taskbarManager.unregister('galeria-window');
        galIframe.src = '';
        galLoaded = false;
    });
})();

/* =========================
   DIBUJO (Excalidraw colaborativo)
========================= */
(function() {
    var dibujoIframe = document.getElementById('dibujo-iframe');
    var dibujoLoaded = false;

    function openDibujoWindow() {
        if (!dibujoLoaded) {
            dibujoIframe.src = 'apps/dibujo.php';
            dibujoLoaded = true;
        }
        if (taskbarManager.isRegistered('dibujo-window')) {
            taskbarManager.restore('dibujo-window');
        } else {
            taskbarManager.register('dibujo-window', 'Dibujo', '<img src="assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    }

    document.getElementById('dibujo-icon').addEventListener('dblclick', openDibujoWindow);

    /* El X del título de la ventana NO cierra directo: pregunta primero
       al iframe si tiene cambios sin guardar. Dibujo responde con
       'close-allowed' o 'close-cancelled'. Si el iframe no responde en
       2 s asumimos que está OK cerrarlo (timeout de seguridad). */
    document.getElementById('dibujo-close').addEventListener('click', function() {
        if (!dibujoLoaded || !dibujoIframe.contentWindow) {
            taskbarManager.unregister('dibujo-window');
            return;
        }
        var done = false;
        var onReply = function(ev) {
            if (!ev.data || (ev.data.type !== 'close-allowed' && ev.data.type !== 'close-cancelled')) return;
            if (done) return;
            done = true;
            window.removeEventListener('message', onReply);
            if (ev.data.type === 'close-allowed') {
                taskbarManager.unregister('dibujo-window');
            }
        };
        window.addEventListener('message', onReply);
        try {
            dibujoIframe.contentWindow.postMessage({ type: 'request-close' }, location.origin);
        } catch (e) {
            done = true;
            window.removeEventListener('message', onReply);
            taskbarManager.unregister('dibujo-window');
        }
        setTimeout(function() {
            if (done) return;
            done = true;
            window.removeEventListener('message', onReply);
            taskbarManager.unregister('dibujo-window');
        }, 2000);
    });

    /* Puente Galería → Dibujo: la galería postMessage'a aquí cuando el
       usuario clica un WIP. Abrimos Dibujo si hace falta y reenviamos
       el blob al iframe en cuanto haya cargado. */
    window.addEventListener('message', function(ev) {
        var msg = ev.data;
        if (!msg || msg.type !== 'open-in-dibujo' || !msg.blob) return;
        var alreadyLoaded = dibujoLoaded;
        openDibujoWindow();
        var send = function() {
            try {
                dibujoIframe.contentWindow.postMessage({
                    type: 'load-file',
                    blob: msg.blob,
                    fileName: msg.fileName,
                    driveFileId: msg.driveFileId
                }, location.origin);
            } catch (e) {}
        };
        if (alreadyLoaded) {
            send();
        } else {
            /* Esperamos a que el iframe acabe de cargar antes de
               enviar (si no, el postMessage llega a un window sin
               listener todavía). */
            dibujoIframe.addEventListener('load', send, { once: true });
        }
    });
})();

/* =========================
   TIENDA
   Iframe con la app de tienda (apps/tienda.php). Internamente tiene una
   pestaña "Donaciones" que carga Ko-fi cuando se pulsa.
========================= */
(function() {
    var tiendaIframe = document.getElementById('tienda-iframe');
    var tiendaLoaded = false;

    /* giftId: abre ese regalo (aviso del haro "Abrir"); con la tienda sin
       cargar va en la URL (?regalo=), si ya estaba, por postMessage. */
    function openTienda(giftId) {
        if (!tiendaLoaded) {
            tiendaIframe.src = appSrc('apps/tienda.php' + (giftId ? '?regalo=' + giftId : ''));
            tiendaLoaded = true;
        } else {
            /* Iframe ya estaba cargado: pedimos al iframe que refresque su
               estado (balance, items, compras) para que el wallet de la
               sidebar no se quede congelado tras minutos cerrada. */
            try { tiendaIframe.contentWindow.postMessage({ type: 'tienda-refresh' }, '*'); } catch (e) {}
            if (giftId) { try { tiendaIframe.contentWindow.postMessage({ type: 'tienda-open-gift', id: giftId }, '*'); } catch (e) {} }
        }
        if (taskbarManager.isRegistered('tienda-window')) {
            taskbarManager.restore('tienda-window');
        } else {
            taskbarManager.register('tienda-window', 'Tienda', '<img src="assets/img/appIcons/tiendaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    }
    window.melonOpenTienda = openTienda;
    document.getElementById('tienda-icon').addEventListener('dblclick', function() { openTienda(); });

    /* Regalos de la tienda: aviso del haro por cada regalo pendiente de
       abrir (al entrar y cuando el timbre dice que ha llegado uno; si no
       responde, cada minuto como antes); "Abrir" lleva a la tienda con él. */
    function pollGifts() {
        if (!window.notifSystem) return;
        fetch('assets/tienda/api.php?action=gifts', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                ((d && d.gifts) || []).forEach(function(g) {
                    window.notifSystem.show({
                        id:          'tienda-gift-' + g.id,
                        type:        'action',
                        title:       '<img src="assets/img/appIcons/tiendaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Regalo',
                        message:     g.from.label + ' te ha enviado un regalo',
                        senderImage: (g.from.image || '').replace(/^\.\.\//, ''),
                        senderKey:   g.from.key,
                        sentAt:      g.ts,
                        rejectLabel: 'Cerrar',
                        acceptLabel: 'Abrir',
                        onAccept:    function() { openTienda(g.id); },
                    });
                });
            })
            .catch(function() {});
    }
    setTimeout(pollGifts, 6000);
    window.melonTimbre.on('regalo', pollGifts, 60000);

    /* Amigos (assets/profile/amigos.php, decidido así): además de la
       campana, el haro avisa de cada solicitud de amistad pendiente
       (Rechazar / Aceptar) y de cuando te aceptan una (una vez; se cierra
       al tocarla). Al entrar y cuando el timbre ('amigos') dice que ha
       cambiado algo; si no responde, cada minuto. Si la solicitud ya no
       está (aceptada desde la campana o el móvil, cancelada), su tarjeta
       se quita. */
    var AMIGOS_TIT = '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Amigos';
    var amigosTarjetas = {};   /* user_key → id de su tarjeta de solicitud */
    function amigosPost(action, user) {
        return fetch('assets/profile/api.php?action=' + action, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ user: user })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d && !d.ok && d.error) window.notifSystem.show({ id: 'amigos-err-' + Date.now(), type: 'info', title: AMIGOS_TIT, message: d.error });
            /* La página de Amigos del Perfil, si está abierta, al día. */
            if (typeof window.profileAmigosRecargar === 'function') window.profileAmigosRecargar();
            return d;
        }).catch(function() {});
    }
    function pollAmigos() {
        if (!window.notifSystem) return;
        fetch('assets/profile/api.php?action=friend-avisos', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.ok) return;
                var siguen = {};
                (d.solicitudes || []).forEach(function(x) {
                    var id = 'amigo-sol-' + x.key + '-' + x.ts;
                    siguen[x.key] = id;
                    amigosTarjetas[x.key] = id;
                    window.notifSystem.show({
                        id:          id,
                        type:        'action',
                        title:       AMIGOS_TIT,
                        message:     x.nombre + ' quiere ser tu amigo',
                        senderImage: x.foto || '',
                        senderKey:   x.key,
                        sentAt:      x.ts,
                        rejectLabel: 'Rechazar',
                        acceptLabel: 'Aceptar',
                        onReject:    function() { amigosPost('friend-reject', x.key); },
                        onAccept:    function() { amigosPost('friend-accept', x.key); },
                    });
                });
                Object.keys(amigosTarjetas).forEach(function(k) {
                    if (siguen[k] === amigosTarjetas[k]) return;
                    if (window.notifSystem.isShown(amigosTarjetas[k])) window.notifSystem.dismiss(amigosTarjetas[k]);
                    delete amigosTarjetas[k];
                });
                (d.aceptadas || []).forEach(function(x) {
                    window.notifSystem.show({
                        id:          'amigo-ok-' + x.id,
                        type:        'info',
                        title:       AMIGOS_TIT,
                        message:     x.nombre + ' ha aceptado tu solicitud de amistad',
                        sentAt:      x.ts,
                    });
                });
            })
            .catch(function() {});
    }
    setTimeout(pollAmigos, 5000);
    window.melonTimbre.on('amigos', pollAmigos, 60000);

    document.getElementById('tienda-close').addEventListener('click', function() {
        taskbarManager.unregister('tienda-window');
    });
})();

/* =========================
   KO-FI WINDOW (la abre la app de Tienda vía postMessage)
   Sin icono propio: solo se muestra cuando llega un mensaje
   {type:'open-kofi'} desde el iframe de la Tienda.
========================= */
(function() {
    var kofiIframe = document.getElementById('kofi-iframe');
    var kofiCurrent = null;     /* URL actualmente cargada en el iframe */
    var titleText = document.querySelector('#kofi-titlebar .title-bar-text');
    var DEFAULT_KOFI_URL = 'https://ko-fi.com/melonhub/?hidefeed=true&widget=true&embed=true&preview=true';

    function openKofi(opts) {
        opts = opts || {};
        var url = opts.url || DEFAULT_KOFI_URL;
        var title = opts.title || 'Donar (Ko-fi)';
        /* Solo recarga el iframe si la URL es DISTINTA — abrir-cerrar-abrir
           la misma URL no resetea el progreso del usuario en el checkout. */
        if (url !== kofiCurrent) {
            kofiIframe.src = url;
            kofiCurrent = url;
        }
        if (titleText) titleText.textContent = '☕ ' + title;
        if (taskbarManager.isRegistered('kofi-window')) {
            taskbarManager.restore('kofi-window');
        } else {
            taskbarManager.register('kofi-window', title, '☕', 'flex');
        }
    }

    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'open-kofi') openKofi(e.data);
    });

    document.getElementById('kofi-close').addEventListener('click', function() {
        taskbarManager.unregister('kofi-window');
    });
})();

/* ──── Player right-click context menu ──── */
(function() {
    var playerMain = document.getElementById('player-main');
    if (!playerMain) return;
    /* RETIRADO: el menú contextual del reproductor lo gestiona ahora
       reproductor.php (window.openTrackCtxMenu / showTrackCtxMenu), que
       cubre toda la ventana e incluye "Corregir álbum". Tener este menú
       AQUÍ además provocaba (1) dos menús a la vez al click derecho en la
       carátula —el listener de la carátula mostraba el suyo y el evento
       burbujeaba hasta #player-main mostrando este—, y (2) su picker
       enviaba {playlistId, tracks} sin id/name → 400 en save-playlist-item.
       Se desactiva por completo. */
    return;

    var ctxMenu = document.createElement('div');
    ctxMenu.className = 'window';
    ctxMenu.setAttribute('data-no-auto-z', '');
    ctxMenu.style.cssText = 'display:none;position:fixed;z-index:9999;padding:2px 0;min-width:160px;';
    document.body.appendChild(ctxMenu);

    var picker = document.createElement('div');
    picker.className = 'window';
    picker.setAttribute('data-no-auto-z', '');
    picker.style.cssText = 'display:none;position:fixed;z-index:10000;min-width:190px;';
    picker.innerHTML =
        '<div class="title-bar" style="cursor:default;">' +
            '<div class="title-bar-text">Añadir a playlist</div>' +
            '<div class="title-bar-controls"><button aria-label="Close" id="player-ctx-picker-close"></button></div>' +
        '</div>' +
        '<div class="window-body" style="padding:4px 0;" id="player-ctx-picker-list"></div>';
    document.body.appendChild(picker);

    document.getElementById('player-ctx-picker-close').addEventListener('click', function() {
        picker.style.display = 'none';
    });

    function closeAll() {
        ctxMenu.style.display = 'none';
        picker.style.display  = 'none';
    }

    function showPicker(ax, ay, track) {
        var listEl = document.getElementById('player-ctx-picker-list');
        listEl.innerHTML = '';
        fetch('assets/music/api.php?action=get-playlists')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var playlists = Array.isArray(data) ? data : [];
                var targets = playlists.filter(function(pl) { return pl.id !== currentPlaylistId; });
                if (!targets.length) {
                    listEl.innerHTML = '<div style="padding:4px 8px;font-size:11px;">Sin otras playlists</div>';
                } else {
                    targets.forEach(function(pl) {
                        var item = document.createElement('div');
                        item.className = 'pl-menu-item';
                        item.textContent = pl.name;
                        item.addEventListener('click', function() {
                            picker.style.display = 'none';
                            if (!track) return;
                            var already = (pl.tracks || []).some(function(t) { return t.videoId && t.videoId === track.videoId; });
                            if (already) { alert('Esta canción ya está en "' + pl.name + '"'); return; }
                            var newTrack = { title: track.title, artist: track.artist, videoId: track.videoId, duration: track.duration };
                            pl.tracks = (pl.tracks || []).concat([newTrack]);
                            fetch('assets/music/api.php?action=save-playlist-item', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ playlistId: pl.id, tracks: pl.tracks })
                            });
                        });
                        listEl.appendChild(item);
                    });
                }
                var px = Math.min(ax, window.innerWidth  - 200);
                var py = Math.min(ay, window.innerHeight - picker.offsetHeight - 10);
                picker.style.left    = px + 'px';
                picker.style.top     = py + 'px';
                picker.style.display = 'block';
            })
            .catch(function() {
                listEl.innerHTML = '<div style="padding:4px 8px;font-size:11px;">Error al cargar</div>';
                picker.style.display = 'block';
            });
    }

    playerMain.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        e.stopPropagation();   /* evita que el handler de #music-player también dispare → 2 menús superpuestos */
        closeAll();
        var track = (typeof playlist !== 'undefined' && typeof currentTrack !== 'undefined')
            ? playlist[currentTrack] : null;
        if (!track) return;

        ctxMenu.innerHTML = '';
        var item = document.createElement('div');
        item.className = 'pl-menu-item';
        item.textContent = '➕ Añadir a otra playlist';
        item.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            showPicker(parseInt(ctxMenu.style.left) + ctxMenu.offsetWidth, parseInt(ctxMenu.style.top), track);
        });
        ctxMenu.appendChild(item);

        var listItem = document.createElement('div');
        listItem.className = 'pl-menu-item';
        listItem.textContent = '🎵 Añadir a mi perfil';
        listItem.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            if (typeof window.profileAddTrackAndReview === 'function') window.profileAddTrackAndReview(track);
        });
        ctxMenu.appendChild(listItem);

        /* Item Listen Together — añadido aquí también para que aparezca
           al click derecho en el reproductor (este handler captura
           antes de que llegue al #music-player listener). */
        var ltItem = document.createElement('div');
        ltItem.className = 'pl-menu-item';
        var ltRole = (typeof LT_ROLE !== 'undefined') ? LT_ROLE : null;
        if (ltRole === 'host')       ltItem.textContent = '🎧 Invitar a más usuarios…';
        else if (ltRole === 'guest') ltItem.textContent = '🎧 Ver sesión actual';
        else                          ltItem.textContent = '🎧 Escuchar juntos…';
        ltItem.addEventListener('click', function() {
            ctxMenu.style.display = 'none';
            if (typeof window.ltOpenModal === 'function') window.ltOpenModal();
        });
        ctxMenu.appendChild(ltItem);

        if (ltRole) {
            var ltLeaveItem = document.createElement('div');
            ltLeaveItem.className = 'pl-menu-item';
            ltLeaveItem.textContent = '✕ Salir de la sesión';
            ltLeaveItem.addEventListener('click', function() {
                ctxMenu.style.display = 'none';
                if (typeof window.ltLeave === 'function') window.ltLeave();
            });
            ctxMenu.appendChild(ltLeaveItem);
        }

        var mx = Math.min(e.clientX, window.innerWidth  - 170);
        var my = Math.min(e.clientY, window.innerHeight - 60);
        ctxMenu.style.left    = mx + 'px';
        ctxMenu.style.top     = my + 'px';
        ctxMenu.style.display = 'block';
    });

    document.addEventListener('mousedown', function(e) {
        if (!ctxMenu.contains(e.target) && !picker.contains(e.target)) closeAll();
    });
})();

/* ──── Generic drag + resize for all windows ──── */
(function() {
    var MIN_W = 180, MIN_H = 100;
    var active = null;

    var blocker = document.createElement('div');
    blocker.style.cssText = 'position:fixed;inset:0;z-index:99998;display:none;';
    document.body.appendChild(blocker);

    function fixPos(el) {
        var r = el.getBoundingClientRect();
        el.style.position  = 'fixed';
        el.style.left      = r.left + 'px';
        el.style.top       = r.top  + 'px';
        el.style.right     = 'auto';
        el.style.bottom    = 'auto';
        el.style.transform = 'none';
    }

    /* Helper: coordenadas (x,y) tanto para eventos de ratón como táctiles.
       Para `touchend` los datos vienen en `changedTouches`, no en `touches`. */
    function ptXY(e) {
        if (e.touches && e.touches.length)               return { x: e.touches[0].clientX,        y: e.touches[0].clientY        };
        if (e.changedTouches && e.changedTouches.length) return { x: e.changedTouches[0].clientX, y: e.changedTouches[0].clientY };
        return { x: e.clientX, y: e.clientY };
    }

    function onPointerMove(e) {
        if (!active) return;
        var el = active.el;
        var p = ptXY(e);
        var PAD = 40;
        var VW = window.innerWidth, VH = window.innerHeight;

        if (active.mode === 'drag') {
            var newL = p.x - active.ox;
            var newT = p.y - active.oy;
            var w = el.offsetWidth, h = el.offsetHeight;
            newL = Math.max(PAD - w, Math.min(VW - PAD, newL));
            newT = Math.max(0,       Math.min(VH - PAD, newT));
            el.style.left = newL + 'px';
            el.style.top  = newT + 'px';
        } else {
            var dx = p.x - active.sx, dy = p.y - active.sy;
            var d = active.dir;
            var MAX_W = VW - PAD * 2, MAX_H = VH - PAD * 2;
            if (d.indexOf('e') !== -1) el.style.width  = Math.min(MAX_W, Math.max(MIN_W, active.sw + dx)) + 'px';
            if (d.indexOf('s') !== -1) el.style.height = Math.min(MAX_H, Math.max(MIN_H, active.sh + dy)) + 'px';
            if (d.indexOf('w') !== -1) {
                var nw = Math.min(MAX_W, Math.max(MIN_W, active.sw - dx));
                el.style.width = nw + 'px';
                el.style.left  = (active.sl + active.sw - nw) + 'px';
            }
            if (d.indexOf('n') !== -1) {
                var nh = Math.min(MAX_H, Math.max(MIN_H, active.sh - dy));
                el.style.height = nh + 'px';
                el.style.top    = (active.st + active.sh - nh) + 'px';
            }
        }
        /* Evita scroll/zoom del navegador mientras se arrastra/resize en táctil. */
        if (e.cancelable) e.preventDefault();
    }

    function onPointerUp() {
        if (active) { blocker.style.display = 'none'; active = null; }
    }

    document.addEventListener('mousemove', onPointerMove);
    document.addEventListener('mouseup',   onPointerUp);
    /* `passive:false` en touchmove → necesario para poder llamar a
       preventDefault() y bloquear el scroll mientras se arrastra. */
    document.addEventListener('touchmove', onPointerMove, { passive: false });
    document.addEventListener('touchend',  onPointerUp);
    document.addEventListener('touchcancel', onPointerUp);

    function setup(id, dragOnly) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('win-draggable');
        if (!dragOnly) el.classList.add('win-managed');

        var titleBar = el.querySelector('.title-bar');
        if (titleBar) {
            function startDrag(e) {
                /* En mouse, ignora botones secundarios; en táctil no aplica. */
                if (e.type === 'mousedown' && e.button !== 0) return;
                if (e.target.closest('.title-bar-controls')) return;
                e.preventDefault();
                fixPos(el);
                var r = el.getBoundingClientRect();
                var p = ptXY(e);
                blocker.style.display = 'block';
                active = { el: el, mode: 'drag', ox: p.x - r.left, oy: p.y - r.top };
            }
            titleBar.addEventListener('mousedown', startDrag);
            titleBar.addEventListener('touchstart', startDrag, { passive: false });
        }

        if (dragOnly) return;

        ['n','s','e','w','ne','nw','se','sw'].forEach(function(d) {
            var h = document.createElement('div');
            h.className = 'win-handle win-handle-' + d;
            function startResize(e) {
                if (e.type === 'mousedown' && e.button !== 0) return;
                e.preventDefault();
                e.stopPropagation();
                fixPos(el);
                var r = el.getBoundingClientRect();
                var p = ptXY(e);
                blocker.style.display = 'block';
                active = { el: el, mode: 'resize', dir: d,
                           sx: p.x, sy: p.y,
                           sw: r.width,  sh: r.height,
                           sl: r.left,   st: r.top };
            }
            h.addEventListener('mousedown', startResize);
            h.addEventListener('touchstart', startResize, { passive: false });
            el.appendChild(h);
        });
    }

    window.WindowManager = { setup: setup };

    /* Helper global: centra un elemento `position:fixed` en el viewport.
       Tiene que llamarse DESPUÉS de hacer display:flex (o lo que sea
       distinto de none) para que offsetWidth/Height ya esté calculado.
       Limita al borde superior/izquierdo a 8px mínimo para que en
       pantallas pequeñas no quede pegado fuera de vista. */
    window.centerModal = function(el){
        if (!el) return;
        var w = el.offsetWidth  || 320;
        var h = el.offsetHeight || 200;
        el.style.left = Math.max(8, (window.innerWidth  - w) / 2) + 'px';
        el.style.top  = Math.max(8, (window.innerHeight - h) / 2) + 'px';
        el.style.right  = 'auto';
        el.style.bottom = 'auto';
    };

    /* ★ dibujo-window, tienda-window, kofi-window añadidos aquí */
    [
        'calendar-window','archive-window','temas-window','dnd-window',
        'galeria-window','companion-window','dibujo-window',
        'tienda-window','kofi-window','mascota-window','juegos-window',
        'playlist-editor','create-playlist-dialog','profile-window',
        'add-track-dialog','import-playlist-dialog',
        'collab-dialog','spotify-import-dialog','tidal-import-dialog','confirm-dialog',
        'profile-add-dialog','profile-review-prompt','profile-review-window',
        'profile-review-view','profile-invite-dialog','profile-info-edit-dialog',
        'music-add-dialog','profile-notifs-window','profile-melon-details-window',
        'profile-chat-window'
    ].forEach(function(id) { setup(id, false); });
    setup('music-player', true);
    /* Modales pequeños — arrastrables por su title-bar pero sin resize. */
    setup('change-password-modal', true);
    /* Reports y Changelog → resizable (segundo arg false). El usuario
       puede agrandar para leer cómodamente entradas largas o pegar más
       contexto en un reporte. */
    setup('reports-modal',         false);
    setup('changelog-modal',       false);
    setup('folder-create-modal',   true);
    setup('folder-delete-modal',   true);
})();

/* ──── Window minimize / maximize ──── */
(function() {
    /* ★ dibujo-window, tienda-window, kofi-window añadidos aquí */
    var ids = [
        'calendar-window', 'archive-window', 'temas-window', 'dnd-window',
        'galeria-window', 'create-playlist-dialog', 'profile-window',
        'companion-window', 'dibujo-window',
        'tienda-window', 'kofi-window', 'mascota-window',
        'wrapped-window', 'juegos-window'
    ];
    ids.forEach(function(id) {
        var win = document.getElementById(id);
        if (!win) return;
        var minBtn = win.querySelector('[aria-label="Minimize"]');
        var maxBtn = win.querySelector('[aria-label="Maximize"]');

        if (minBtn) {
            minBtn.addEventListener('click', function() { taskbarManager.minimize(id); });
        }
        if (maxBtn) {
            maxBtn.addEventListener('click', function() {
                if (win.classList.contains('win-maximized')) {
                    win.classList.remove('win-maximized');
                    maxBtn.setAttribute('aria-label', 'Maximize');
                } else {
                    win.classList.add('win-maximized');
                    maxBtn.setAttribute('aria-label', 'Restore');
                }
            });
        }
    });
})();

/* =========================================================
   ICONOS DEL ESCRITORIO: long-press para mover
========================================================= */
(function(){
    var HOLD_MS = 350;
    var MOVE_TOLERANCE = 6;
    var GRID_W = 96;
    var GRID_H = 96;

    window.DesktopState = window.DesktopState || { icons: {}, folders: [], player: null, loaded: false, _readyCbs: [] };
    if (!window.DesktopState.whenReady) {
        window.DesktopState.whenReady = function(cb){
            if (this.loaded) cb();
            else this._readyCbs.push(cb);
        };
    }

    function snap(v, step){ return Math.round(v / step) * step; }
    function snapPos(x, y){ return { left: snap(x, GRID_W), top: snap(y, GRID_H) }; }

    /* ¿Está la celda (left, top) — coordenadas YA snapeadas — ocupada
       por algún OTRO icono del escritorio? `excludeId` evita que un
       icono se "auto-bloquee" su propia posición durante el drag.
       Comparamos las posiciones de cada icono snapeadas a la cuadrícula
       por si alguno está un píxel descolocado por animaciones previas. */
    function isCellOccupied(left, top, excludeId){
        var icons = document.querySelectorAll('.desktop-icon');
        for (var i = 0; i < icons.length; i++){
            var ic = icons[i];
            if (ic.id === excludeId) continue;
            /* Solo iconos que tienen position absoluta/fija y left/top
               numéricos. Ignora los que vivan en un folder window. */
            var l = parseFloat(ic.style.left);
            var t = parseFloat(ic.style.top);
            if (isNaN(l) || isNaN(t)) continue;
            if (snap(l, GRID_W) === left && snap(t, GRID_H) === top) return true;
        }
        return false;
    }

    /* Busca la celda LIBRE más cercana a (targetLeft, targetTop) en
       distancia Manhattan/Chebyshev sobre la cuadrícula. La búsqueda
       avanza en anillos concéntricos: ring=1 cubre las 8 celdas
       contiguas, ring=2 las 16 siguientes, etc. Dentro de cada anillo
       elegimos la celda con menor distancia EUCLÍDEA al objetivo, así
       que el resultado se "siente" como la celda más cercana real.
       Solo consideramos celdas DENTRO del escritorio. */
    function findNearestEmptyCell(targetLeft, targetTop, excludeId, deskW, deskH){
        if (!isCellOccupied(targetLeft, targetTop, excludeId)) {
            return { left: targetLeft, top: targetTop };
        }
        var maxX = Math.max(0, deskW - GRID_W);
        var maxY = Math.max(0, deskH - GRID_H);
        var maxRing = Math.max(
            Math.ceil(deskW / GRID_W),
            Math.ceil(deskH / GRID_H)
        );
        for (var ring = 1; ring <= maxRing; ring++){
            var best = null, bestDist = Infinity;
            for (var dy = -ring; dy <= ring; dy++){
                for (var dx = -ring; dx <= ring; dx++){
                    /* Solo el borde del anillo — los interiores ya
                       se probaron en iteraciones previas. */
                    if (Math.abs(dx) !== ring && Math.abs(dy) !== ring) continue;
                    var cl = targetLeft + dx * GRID_W;
                    var ct = targetTop  + dy * GRID_H;
                    if (cl < 0 || ct < 0)        continue;
                    if (cl > maxX || ct > maxY)  continue;
                    if (isCellOccupied(cl, ct, excludeId)) continue;
                    var d = dx * dx + dy * dy;
                    if (d < bestDist){ bestDist = d; best = { left: cl, top: ct }; }
                }
            }
            if (best) return best;
        }
        /* Escritorio totalmente ocupado — fallback al destino original
           (se solapará pero al menos no perdemos el icono). */
        return { left: targetLeft, top: targetTop };
    }

    function loadPositions(){ return window.DesktopState.icons || {}; }
    var _saveTimers = {};
    function savePosition(id, pos){
        window.DesktopState.icons[id] = pos;
        clearTimeout(_saveTimers[id]);
        _saveTimers[id] = setTimeout(function(){
            fetch('assets/desktop/api.php?action=save-icon', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, left: pos.left, top: pos.top })
            }).catch(function(){});
        }, 120);
    }

    function fetchState(cb){
        function done(){
            window.DesktopState.loaded = true;
            var queue = window.DesktopState._readyCbs || [];
            window.DesktopState._readyCbs = [];
            queue.forEach(function(fn){ try { fn(); } catch(e){} });
            cb();
        }
        fetch('assets/desktop/api.php?action=get-all')
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(d && d.ok){
                    window.DesktopState.icons   = d.icons   || {};
                    window.DesktopState.folders = d.folders || [];
                    window.DesktopState.player  = d.player  || null;
                }
                done();
            })
            .catch(done);
    }

    function init(){
        var icons = document.querySelectorAll('.desktop-icon');
        if(!icons.length) return;
        var desk  = document.getElementById('desktop');
        var saved = loadPositions();

        var natural = {};
        icons.forEach(function(icon){
            var r = icon.getBoundingClientRect();
            var dr = desk.getBoundingClientRect();
            natural[icon.id] = { left: r.left - dr.left, top: r.top - dr.top };
        });

        icons.forEach(function(icon){
            var raw = saved[icon.id] || natural[icon.id];
            var pos = snapPos(raw.left, raw.top);
            icon.style.position = 'absolute';
            icon.style.margin   = '0';
            icon.style.left = pos.left + 'px';
            icon.style.top  = pos.top  + 'px';
            attachDrag(icon, desk);
        });
        if(window.DesktopFolders) DesktopFolders.init(desk);
    }

    window.DesktopIcons = {
        attachDrag: function(icon){ var desk = document.getElementById('desktop'); attachDrag(icon, desk); },
        snapPos: snapPos,
        isCellOccupied: isCellOccupied,
        findNearestEmptyCell: findNearestEmptyCell,
        savePosition: savePosition,
        loadPositions: loadPositions,
        GRID_W: GRID_W,
        GRID_H: GRID_H
    };

    /* ─── Menú contextual táctil para iconos ─────────────────────────────
       En tablet, mantener pulsado un icono ya no entra directamente en
       modo arrastrar — abre este menú con la opción "📦 Mover". Tras
       pulsar Mover, el icono queda en estado .move-pending (anim wobble)
       y la siguiente pulsación sobre él arranca el drag sin esperar los
       350 ms del long-press. Cualquier toque fuera cancela el estado. */
    var _ctxMenuEl = null;
    var _ctxIcon   = null;

    function ensureIconCtxMenu(){
        if (_ctxMenuEl) return _ctxMenuEl;
        _ctxMenuEl = document.getElementById('desktop-icon-ctx-menu');
        if (!_ctxMenuEl) return null;
        _ctxMenuEl.addEventListener('click', function(e){
            var li = e.target.closest('li[data-action]');
            if (!li) return;
            if (li.dataset.action === 'move' && _ctxIcon) {
                _ctxIcon.classList.add('move-pending');
            }
            hideIconCtxMenu();
        });
        /* Listener global (captura) para cerrar el menú y/o cancelar el
           modo mover cuando el usuario toca fuera. Vive aquí para no
           duplicarlo por cada icono. */
        document.addEventListener('touchstart', function(e){
            var menuShown        = _ctxMenuEl.classList.contains('show');
            var tappedMenu       = !!e.target.closest('#desktop-icon-ctx-menu');
            var tappedPendingIco = !!e.target.closest('.desktop-icon.move-pending');
            if (menuShown && !tappedMenu) hideIconCtxMenu();
            if (!tappedPendingIco && !tappedMenu) {
                document.querySelectorAll('.desktop-icon.move-pending').forEach(function(i){
                    i.classList.remove('move-pending');
                });
            }
        }, true);
        return _ctxMenuEl;
    }

    function showIconCtxMenu(icon){
        var menu = ensureIconCtxMenu();
        if (!menu) return;
        _ctxIcon = icon;
        /* Lo posicionamos JUNTO al icono, no bajo el dedo del usuario.
           Si lo metiéramos donde está el dedo, al levantarlo el touchend
           dispararía click sintético sobre la opción "Mover" sin haberla
           tocado intencionalmente. */
        menu.classList.add('show');
        menu.style.left = '0px';
        menu.style.top  = '0px';
        var w = menu.offsetWidth, h = menu.offsetHeight;
        var VW = window.innerWidth, VH = window.innerHeight;
        var r = icon.getBoundingClientRect();
        /* Por defecto: a la derecha del icono, alineado por arriba. */
        var px = r.right + 8;
        var py = r.top;
        if (px + w > VW - 4) px = r.left - w - 8;   /* no cabe → izquierda */
        if (px < 4)           px = Math.max(4, Math.min(r.left, VW - w - 4));
        if (py + h > VH - 4)  py = VH - h - 4;
        if (py < 4)           py = 4;
        menu.style.left = px + 'px';
        menu.style.top  = py + 'px';
    }

    function hideIconCtxMenu(){
        if (_ctxMenuEl) _ctxMenuEl.classList.remove('show');
        _ctxIcon = null;
    }

    function attachDrag(icon, desk){
        var holdTimer = null;
        var dragging  = false;
        var armed     = false;
        var startX = 0, startY = 0;
        var originX = 0, originY = 0;
        var moved   = false;
        var wasInBody = false;

        function pointer(e){
            if(e.touches && e.touches[0]) return e.touches[0];
            if(e.changedTouches && e.changedTouches[0]) return e.changedTouches[0];
            return e;
        }

        function cleanupListeners(){
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            document.removeEventListener('touchcancel', onUp);
        }

        function onDown(e){
            if(e.type === 'mousedown' && e.button !== 0) return;
            var isTouch = (e.type === 'touchstart');
            var p = pointer(e);
            startX = p.clientX; startY = p.clientY;
            originX = parseFloat(icon.style.left) || 0;
            originY = parseFloat(icon.style.top)  || 0;
            moved = false;

            /* Táctil con el icono en "modo mover" (ya se confirmó desde el
               menú): saltamos el long-press y armamos el drag al instante. */
            if (isTouch && icon.classList.contains('move-pending')) {
                icon.classList.remove('move-pending');
                armed = true;
                icon.classList.add('dragging');
            } else {
                icon.classList.add('long-pressing');
                holdTimer = setTimeout(function(){
                    icon.classList.remove('long-pressing');
                    if (isTouch) {
                        /* En táctil → mostramos menú "Mover" en vez de
                           empezar a arrastrar de golpe. */
                        showIconCtxMenu(icon);
                    } else {
                        armed = true;
                        icon.classList.add('dragging');
                    }
                }, HOLD_MS);
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend',  onUp);
            document.addEventListener('touchcancel', onUp);
        }

        function onMove(e){
            var p = pointer(e);
            var dx = p.clientX - startX;
            var dy = p.clientY - startY;
            if(!armed){
                if(Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE){
                    clearTimeout(holdTimer);
                    icon.classList.remove('long-pressing');
                    cleanupListeners();
                }
                return;
            }
            if(e.cancelable) e.preventDefault();
            if(!dragging && !wasInBody){
                var br = icon.getBoundingClientRect();
                document.body.appendChild(icon);
                icon.style.position = 'fixed';
                icon.style.left = br.left + 'px';
                icon.style.top  = br.top  + 'px';
                wasInBody = true;
            }
            dragging = true; moved = true;
            var w = icon.offsetWidth, h = icon.offsetHeight;
            var dw = desk.clientWidth, dh = desk.clientHeight;
            var nx = Math.max(0, Math.min(dw - w, originX + dx));
            var ny = Math.max(0, Math.min(dh - h, originY + dy));
            icon.style.left = nx + 'px';
            icon.style.top  = ny + 'px';
            if(window.DesktopFolders){
                var rect = icon.getBoundingClientRect();
                DesktopFolders.updateHover(icon.id, rect.left + rect.width/2, rect.top + rect.height/2);
            }
        }

        function onUp(){
            clearTimeout(holdTimer);
            icon.classList.remove('long-pressing');
            icon.classList.remove('dragging');
            if(dragging){
                /* Drag completado → salimos del modo "move-pending" si lo
                   teníamos puesto (entró por flujo táctil del menú). */
                icon.classList.remove('move-pending');
                var droppedInFolder = false;
                if(window.DesktopFolders){
                    var rect = icon.getBoundingClientRect();
                    droppedInFolder = DesktopFolders.tryDrop(icon.id, rect.left + rect.width/2, rect.top + rect.height/2);
                    DesktopFolders.clearHover();
                }
                if(wasInBody){
                    desk.appendChild(icon);
                    icon.style.position = 'absolute';
                    wasInBody = false;
                }
                if(!droppedInFolder){
                    var finalPos = snapPos(parseFloat(icon.style.left)||0, parseFloat(icon.style.top)||0);
                    /* Si la celda destino ya está ocupada, buscamos la
                       celda LIBRE más cercana (anillos concéntricos
                       sobre la cuadrícula). Así el icono no rebota a
                       origen ni se solapa — siempre acaba en la celda
                       más próxima posible al punto donde lo soltaste. */
                    finalPos = findNearestEmptyCell(
                        finalPos.left, finalPos.top, icon.id,
                        desk.clientWidth, desk.clientHeight
                    );
                    icon.classList.add('snapping');
                    icon.style.left = finalPos.left + 'px';
                    icon.style.top  = finalPos.top  + 'px';
                    setTimeout(function(){ icon.classList.remove('snapping'); }, 200);
                    savePosition(icon.id, finalPos);
                }
                if(moved){
                    var swallow = function(ev){ ev.stopPropagation(); ev.preventDefault();
                        document.removeEventListener('click', swallow, true); };
                    document.addEventListener('click', swallow, true);
                }
            }
            dragging = false; armed = false;
            cleanupListeners();
        }

        icon.addEventListener('mousedown',  onDown);
        icon.addEventListener('touchstart', onDown, { passive: true });
    }

    function bootstrap(){
        fetchState(function(){ requestAnimationFrame(init); });
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();

/* ── Presencia: heartbeat cada 30s al servidor para marcar al usuario
   actual como online. assets/profile/api.php?action=heartbeat upserta
   user_presence.last_at = NOW(). Otros clientes consultan ?action=presence
   para pintar el indicador.

   ESTADO 'AWAY': tras `AWAY_AFTER_MS` sin actividad del usuario
   (mouse / teclado / scroll / touch) marcamos el state como 'away' y lo
   enviamos en el siguiente heartbeat. Al detectar cualquier input
   vuelve a 'online' y se envía un heartbeat inmediato para que el
   indicador amarillo desaparezca rápido.
   También: si la pestaña pasa a oculta (cambia de ventana, bloqueo de
   pantalla, etc.), pasamos a 'away' sin esperar al timer. */
(function presenceHeartbeat(){
    var AWAY_AFTER_MS = 5 * 60 * 1000;   /* 5 minutos de inactividad */
    var currentState  = 'online';
    var lastActivity  = Date.now();
    var awayTimer     = null;

    function ping(){
        fetch('assets/profile/api.php?action=heartbeat&state=' + encodeURIComponent(currentState), {
            method: 'POST', credentials: 'same-origin'
        }).catch(function(){});
    }
    function setState(next){
        if (next === currentState) return;
        currentState = next;
        ping();   /* heartbeat inmediato para que se vea sin esperar 30s */
    }
    function scheduleAwayCheck(){
        if (awayTimer) clearTimeout(awayTimer);
        awayTimer = setTimeout(function(){
            if (Date.now() - lastActivity >= AWAY_AFTER_MS) setState('away');
        }, AWAY_AFTER_MS);
    }
    function onActivity(){
        lastActivity = Date.now();
        if (currentState !== 'online') setState('online');
        scheduleAwayCheck();
    }
    ['mousemove','mousedown','keydown','touchstart','scroll','wheel'].forEach(function(ev){
        window.addEventListener(ev, onActivity, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function(){
        if (document.hidden) setState('away');
        else                 onActivity();
    });
    ping();
    scheduleAwayCheck();
    setInterval(ping, 30000);
})();

/* ── Recordatorios próximos: poll cada 5 min al endpoint que devuelve
   los que caen a 7/2/1 días y no han sido notificados. Cada match
   dispara notifSystem.show() ─────────────────────────────────────── */
(function remindersPoll(){
    function whenLabel(t){
        if (t === 1) return 'mañana';
        if (t === 2) return 'en 2 días';
        if (t === 7) return 'en 1 semana';
        return 'en ' + t + ' días';
    }
    function poll(){
        fetch('assets/couple/api.php?action=upcoming-reminders', { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok || !Array.isArray(d.reminders) || !window.notifSystem) return;
                d.reminders.forEach(function(rm){
                    window.notifSystem.show({
                        id:    'reminder-' + rm.id + '-' + rm.threshold,
                        type:  'info',
                        title: '<img src="assets/img/appIcons/bellIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Recordatorio',
                        message: rm.titulo + ' ' + whenLabel(rm.threshold),
                        autoDismissAfter: 8000,
                    });
                });
            })
            .catch(function(){});
    }
    /* Primer fetch a los 5s del arranque (deja a notifSystem inicializarse). */
    setTimeout(poll, 5000);
    setInterval(poll, 5 * 60 * 1000);
})();

/* =========================================================
   DOUBLE-TAP → DBLCLICK (táctil)
   ─────────────────────────────────────────────────────────
   En táctil, los navegadores no siempre sintetizan dblclick
   con dos pulsaciones rápidas. Lo emulamos manualmente para
   .desktop-icon (incluye iconos del escritorio y carpetas):
   dos taps sobre el mismo elemento en < 400 ms → dispatch
   sintético de dblclick. attachDrag() ya bloquea esto si
   hubo arrastre real (la clase .dragging se mantiene).
========================================================= */
(function(){
    var GAP = 400;
    var last = { id: null, t: 0 };
    document.addEventListener('touchend', function(e){
        if (!e.changedTouches || e.changedTouches.length !== 1) return;
        var icon = e.target.closest('.desktop-icon');
        if (!icon || !icon.id) return;
        if (icon.classList.contains('dragging'))     return;   /* drag activo */
        if (icon.classList.contains('move-pending')) return;   /* esperando mover */
        /* Si el menú "Mover" está abierto, este touchend cierra el
           long-press, no es un primer tap del doble. */
        var menu = document.getElementById('desktop-icon-ctx-menu');
        if (menu && menu.classList.contains('show')) return;
        var now = (new Date()).getTime();
        if (last.id === icon.id && now - last.t < GAP) {
            last = { id: null, t: 0 };
            icon.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));
        } else {
            last = { id: icon.id, t: now };
        }
    });
})();

/* =========================================================
   CARPETAS DEL ESCRITORIO
========================================================= */
(function(){
    var folders = {};
    var openFolderWindows = {};
    var hoverFolderEl = null;

    function load(){
        folders = {};
        var arr = (window.DesktopState && window.DesktopState.folders) || [];
        arr.forEach(function(f){
            folders[f.id] = {
                id: f.id,
                name: f.name,
                pos: { left: (f.pos && f.pos.left)|0, top: (f.pos && f.pos.top)|0 },
                children: Array.isArray(f.children) ? f.children.slice() : [],
            };
        });
    }
    var _saveTimers = {};
    function saveFolder(id){
        var f = folders[id];
        if(!f){
            clearTimeout(_saveTimers[id]);
            fetch('assets/desktop/api.php?action=delete-folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).catch(function(){});
            return;
        }
        clearTimeout(_saveTimers[id]);
        _saveTimers[id] = setTimeout(function(){
            fetch('assets/desktop/api.php?action=save-folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: f.id, name: f.name, pos: f.pos, children: f.children || [] })
            }).catch(function(){});
        }, 150);
    }
    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function init(desk){
        load();
        Object.keys(folders).forEach(function(id){ renderFolderIcon(folders[id], desk); });
        var hiddenChildren = new Set();
        Object.values(folders).forEach(function(f){ (f.children||[]).forEach(function(cid){ hiddenChildren.add(cid); }); });
        hiddenChildren.forEach(function(cid){ var el = document.getElementById(cid); if(el) el.style.display = 'none'; });
    }

    function renderFolderIcon(folder, desk){
        if(!desk) desk = document.getElementById('desktop');
        var div = document.createElement('div');
        div.className = 'desktop-icon';
        div.id = folder.id;
        div.dataset.folder = '1';
        div.innerHTML = '<div class="desktop-icon-img"><img src="assets/img/appIcons/folderIcon.png" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;" alt=""></div><span>' + esc(folder.name) + '</span>';
        div.style.position = 'absolute';
        div.style.margin = '0';
        var savedIconPos = window.DesktopIcons ? window.DesktopIcons.loadPositions()[folder.id] : null;
        var basePos = savedIconPos || folder.pos;
        var pos = window.DesktopIcons ? window.DesktopIcons.snapPos(basePos.left, basePos.top) : basePos;
        div.style.left = pos.left + 'px';
        div.style.top  = pos.top  + 'px';
        div.addEventListener('dblclick', function(){ openFolderWindow(folder.id); });
        div.addEventListener('contextmenu', function(e){ e.preventDefault(); e.stopPropagation(); deleteFolder(folder.id); });
        desk.appendChild(div);
        if(window.DesktopIcons) window.DesktopIcons.attachDrag(div);
    }

    function createFolder(x, y){
        showCreateModal(function(name){
            if(!name) return;
            name = name.trim().slice(0, 40);
            if(!name) return;
            var id = 'fld-' + Date.now().toString(36) + Math.random().toString(36).slice(2,5);
            var pos = window.DesktopIcons ? window.DesktopIcons.snapPos(x, y) : { left: x, top: y };
            /* Si la celda de creación ya está ocupada (p.ej. clicaste muy
               cerca de un icono existente), reubicamos la carpeta al
               hueco libre más cercano antes de persistir. */
            if (window.DesktopIcons && window.DesktopIcons.findNearestEmptyCell) {
                var desk = document.getElementById('desktop');
                pos = window.DesktopIcons.findNearestEmptyCell(
                    pos.left, pos.top, id,
                    desk.clientWidth, desk.clientHeight
                );
            }
            folders[id] = { id: id, name: name, pos: pos, children: [] };
            saveFolder(id);
            renderFolderIcon(folders[id]);
            if(window.DesktopIcons) window.DesktopIcons.savePosition(id, pos);
        });
    }

    function createFolderInside(parentId){
        if(!folders[parentId]) return;
        showCreateModal(function(name){
            if(!name) return;
            name = name.trim().slice(0, 40);
            if(!name) return;
            var id = 'fld-' + Date.now().toString(36) + Math.random().toString(36).slice(2,5);
            var pos = window.DesktopIcons ? window.DesktopIcons.snapPos(0, 0) : { left: 0, top: 0 };
            folders[id] = { id: id, name: name, pos: pos, children: [] };
            folders[parentId].children.push(id);
            saveFolder(id); saveFolder(parentId);
            renderFolderIcon(folders[id]);
            var iconEl = document.getElementById(id);
            if(iconEl) iconEl.style.display = 'none';
            if(openFolderWindows[parentId]) renderFolderContent(parentId);
        });
    }

    function deleteFolder(id){
        var f = folders[id]; if(!f) return;
        showDeleteModal(f.name, function(){
            (f.children||[]).forEach(function(cid){ var el = document.getElementById(cid); if(el) el.style.display = ''; });
            var parentsToRefresh = [];
            Object.keys(folders).forEach(function(fid){
                if(fid === id) return;
                var pf = folders[fid];
                var idx = (pf.children||[]).indexOf(id);
                if(idx !== -1){ pf.children.splice(idx, 1); parentsToRefresh.push(fid); }
            });
            delete folders[id];
            saveFolder(id);
            parentsToRefresh.forEach(saveFolder);
            var el = document.getElementById(id); if(el) el.remove();
            if(openFolderWindows[id]) closeFolderWindowById(id);
            parentsToRefresh.forEach(function(pid){ if(openFolderWindows[pid]) renderFolderContent(pid); });
        });
    }

    function showCreateModal(onAccept){
        var modal = document.getElementById('folder-create-modal');
        var input = document.getElementById('folder-create-input');
        input.value = 'Nueva carpeta';
        modal.style.display = 'flex';
        if(window.centerModal) window.centerModal(modal);
        if(window.windowZ) windowZ.bringToFront('folder-create-modal');
        setTimeout(function(){ input.focus(); input.select(); }, 30);
        function ok(){ cleanup(); onAccept(input.value); }
        function cancel(){ cleanup(); }
        function keyHandler(ev){ if(ev.key === 'Enter'){ ev.preventDefault(); ok(); } else if(ev.key === 'Escape'){ ev.preventDefault(); cancel(); } }
        function cleanup(){
            modal.style.display = 'none';
            document.getElementById('folder-create-ok').onclick = null;
            document.getElementById('folder-create-cancel').onclick = null;
            document.getElementById('folder-create-x').onclick = null;
            input.removeEventListener('keydown', keyHandler);
        }
        document.getElementById('folder-create-ok').onclick = ok;
        document.getElementById('folder-create-cancel').onclick = cancel;
        document.getElementById('folder-create-x').onclick = cancel;
        input.addEventListener('keydown', keyHandler);
    }

    function showDeleteModal(folderName, onAccept){
        var modal = document.getElementById('folder-delete-modal');
        document.getElementById('folder-delete-text').innerHTML =
            '¿Borrar la carpeta <strong>' + esc(folderName) + '</strong>?<br><small>Los iconos volverán al escritorio.</small>';
        modal.style.display = 'flex';
        if(window.centerModal) window.centerModal(modal);
        if(window.windowZ) windowZ.bringToFront('folder-delete-modal');
        function ok(){ cleanup(); onAccept(); }
        function cancel(){ cleanup(); }
        function cleanup(){
            modal.style.display = 'none';
            document.getElementById('folder-delete-ok').onclick = null;
            document.getElementById('folder-delete-cancel').onclick = null;
            document.getElementById('folder-delete-x').onclick = null;
        }
        document.getElementById('folder-delete-ok').onclick = ok;
        document.getElementById('folder-delete-cancel').onclick = cancel;
        document.getElementById('folder-delete-x').onclick = cancel;
    }

    function findFolderAt(iconId, x, y){
        var els = document.querySelectorAll('.desktop-icon[data-folder]');
        for(var i=0; i<els.length; i++){
            if(els[i].id === iconId) continue;
            var r = els[i].getBoundingClientRect();
            if(x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return els[i];
        }
        return null;
    }

    function getOpenFolderWindowAt(x, y){
        for(var fid in openFolderWindows){
            var fw = openFolderWindows[fid].el;
            if(!fw || fw.style.display === 'none') continue;
            var r = fw.getBoundingClientRect();
            if(x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return fid;
        }
        return null;
    }

    function updateHover(iconId, x, y){
        var fEl = findFolderAt(iconId, x, y);
        if(fEl && folders[iconId] && isDescendant(fEl.id, iconId)) fEl = null;
        if(hoverFolderEl && hoverFolderEl !== fEl) hoverFolderEl.classList.remove('folder-receive');
        if(fEl) fEl.classList.add('folder-receive');
        hoverFolderEl = fEl;
        Object.keys(openFolderWindows).forEach(function(fid){ openFolderWindows[fid].el.classList.remove('folder-window-receive'); });
        var overFid = getOpenFolderWindowAt(x, y);
        if(overFid && folders[overFid] && overFid !== iconId &&
           !(folders[iconId] && isDescendant(overFid, iconId)) &&
           (folders[overFid].children||[]).indexOf(iconId) === -1){
            openFolderWindows[overFid].el.classList.add('folder-window-receive');
        }
    }

    function clearHover(){
        if(hoverFolderEl) hoverFolderEl.classList.remove('folder-receive');
        hoverFolderEl = null;
        Object.keys(openFolderWindows).forEach(function(fid){ openFolderWindows[fid].el.classList.remove('folder-window-receive'); });
    }

    function isDescendant(descId, ancestorId){
        if(descId === ancestorId) return true;
        var f = folders[ancestorId]; if(!f) return false;
        var children = f.children || [];
        for(var i=0; i<children.length; i++){ if(isDescendant(descId, children[i])) return true; }
        return false;
    }

    function unlinkAndCollect(iconId){
        var touched = [];
        Object.keys(folders).forEach(function(fid){
            var f = folders[fid];
            var idx = (f.children||[]).indexOf(iconId);
            if(idx !== -1){ f.children.splice(idx, 1); touched.push(fid); }
        });
        return touched;
    }

    function tryDrop(iconId, x, y){
        var fEl = findFolderAt(iconId, x, y);
        if(fEl){
            var folderId = fEl.id;
            if(folderId === iconId) return false;
            if(folders[iconId] && isDescendant(folderId, iconId)) return false;
            if(folders[folderId] && folders[folderId].children.indexOf(iconId) === -1){
                var touched = unlinkAndCollect(iconId);
                folders[folderId].children.push(iconId);
                touched.push(folderId);
                touched.forEach(saveFolder);
            }
            var icon = document.getElementById(iconId); if(icon) icon.style.display = 'none';
            if(openFolderWindows[folderId]) renderFolderContent(folderId);
            return true;
        }
        var overFid = getOpenFolderWindowAt(x, y);
        if(overFid && folders[overFid]){
            if(overFid === iconId) return false;
            if(folders[iconId] && isDescendant(overFid, iconId)) return false;
            var f = folders[overFid];
            if(f.children.indexOf(iconId) === -1){
                var touched2 = unlinkAndCollect(iconId);
                f.children.push(iconId);
                touched2.push(overFid);
                touched2.forEach(saveFolder);
            }
            var ic = document.getElementById(iconId); if(ic) ic.style.display = 'none';
            renderFolderContent(overFid);
            return true;
        }
        return false;
    }

    function attachFolderItemDrag(item, folderId, iconId){
        item.addEventListener('mousedown', function(e){
            if(e.button !== 0) return;
            var startX = e.clientX, startY = e.clientY;
            var ghost = null;
            function makeGhost(){
                ghost = item.cloneNode(true);
                ghost.style.position = 'fixed'; ghost.style.pointerEvents = 'none';
                ghost.style.opacity = '0.78'; ghost.style.zIndex = '99999';
                ghost.style.transform = 'scale(1.05)'; ghost.style.boxShadow = '0 4px 14px rgba(0,0,0,0.4)';
                document.body.appendChild(ghost); moveGhost(startX, startY);
            }
            function moveGhost(x, y){ if(!ghost) return; ghost.style.left = (x - 40) + 'px'; ghost.style.top = (y - 24) + 'px'; }
            function highlightTargets(x, y){
                Object.keys(openFolderWindows).forEach(function(fid){ openFolderWindows[fid].el.classList.remove('folder-window-receive'); });
                if(hoverFolderEl) hoverFolderEl.classList.remove('folder-receive');
                hoverFolderEl = null;
                var overFid = getOpenFolderWindowAt(x, y);
                if(overFid && overFid !== folderId){ openFolderWindows[overFid].el.classList.add('folder-window-receive'); return; }
                var fEl = findFolderAt(iconId, x, y);
                if(fEl && fEl.id !== folderId){ fEl.classList.add('folder-receive'); hoverFolderEl = fEl; }
            }
            function clearTargets(){
                Object.keys(openFolderWindows).forEach(function(fid){ openFolderWindows[fid].el.classList.remove('folder-window-receive'); });
                if(hoverFolderEl) hoverFolderEl.classList.remove('folder-receive');
                hoverFolderEl = null;
            }
            function onMove(ev){
                var dx = ev.clientX - startX, dy = ev.clientY - startY;
                if(!ghost && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) makeGhost();
                if(ghost){ ev.preventDefault(); moveGhost(ev.clientX, ev.clientY); highlightTargets(ev.clientX, ev.clientY); }
            }
            function onUp(ev){
                document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp);
                if(!ghost) return;
                ghost.remove(); clearTargets();
                var overFid = getOpenFolderWindowAt(ev.clientX, ev.clientY);
                if(overFid && overFid !== folderId){ moveIconBetweenFolders(folderId, overFid, iconId); return; }
                if(overFid === folderId) return;
                var fEl = findFolderAt(iconId, ev.clientX, ev.clientY);
                if(fEl && fEl.id !== folderId && folders[fEl.id]){ moveIconBetweenFolders(folderId, fEl.id, iconId); return; }
                removeFromFolderAt(folderId, iconId, ev.clientX, ev.clientY);
            }
            document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
        });
    }

    function moveIconBetweenFolders(fromId, toId, iconId){
        var from = folders[fromId], to = folders[toId];
        if(!from || !to || fromId === toId) return;
        from.children = (from.children||[]).filter(function(c){ return c !== iconId; });
        if((to.children = to.children || []).indexOf(iconId) === -1) to.children.push(iconId);
        saveFolder(fromId); saveFolder(toId);
        if(openFolderWindows[fromId]) renderFolderContent(fromId);
        if(openFolderWindows[toId])   renderFolderContent(toId);
    }

    function removeFromFolderAt(folderId, iconId, screenX, screenY){
        var f = folders[folderId]; if(!f) return;
        f.children = (f.children||[]).filter(function(c){ return c !== iconId; });
        saveFolder(folderId);
        var el = document.getElementById(iconId);
        if(el && window.DesktopIcons){
            el.style.display = '';
            var desk = document.getElementById('desktop');
            var dr = desk.getBoundingClientRect();
            var pos = window.DesktopIcons.snapPos(screenX - dr.left, screenY - dr.top);
            var w = el.offsetWidth || 76, h = el.offsetHeight || 76;
            pos.left = Math.max(0, Math.min(desk.clientWidth - w, pos.left));
            pos.top  = Math.max(0, Math.min(desk.clientHeight - h, pos.top));
            /* Si la celda destino ya tiene otro icono, buscamos la libre
               más cercana — mismo comportamiento que el drop manual del
               drag-en-escritorio (sin esto, sacar de carpeta apilaba
               iconos en la misma cuadrícula). */
            if (window.DesktopIcons.findNearestEmptyCell) {
                pos = window.DesktopIcons.findNearestEmptyCell(
                    pos.left, pos.top, iconId,
                    desk.clientWidth, desk.clientHeight
                );
            }
            el.style.left = pos.left + 'px'; el.style.top = pos.top + 'px';
            window.DesktopIcons.savePosition(iconId, pos);
        } else if(el) { el.style.display = ''; }
        if(openFolderWindows[folderId]) renderFolderContent(folderId);
    }

    function openFolderWindow(id){
        var f = folders[id]; if(!f) return;
        if(openFolderWindows[id]){
            var existing = openFolderWindows[id].el;
            if(window.taskbarManager && taskbarManager.isRegistered(openFolderWindows[id].wid)){
                taskbarManager.restore(openFolderWindows[id].wid);
            } else { existing.style.display = 'flex'; }
            if(window.windowZ) windowZ.bringToFront(existing);
            return;
        }
        var tpl = document.getElementById('folder-window-template'); if(!tpl) return;
        var w = tpl.cloneNode(true);
        var wid = 'folder-window-' + id;
        w.id = wid;
        var count = Object.keys(openFolderWindows).length;
        var baseL = window.innerWidth  * 0.18, baseT = window.innerHeight * 0.14;
        w.style.left = (baseL + count * 28) + 'px'; w.style.top = (baseT + count * 28) + 'px';
        w.style.zIndex = ''; w.style.display = 'flex';
        var titleEl = w.querySelector('.title-bar-text');
        if(titleEl) {
            /* Render PNG folderIcon + nombre escapado (textContent solo
               admite texto, así que innerHTML con escape para evitar XSS
               si f.name viniese con < > etc). */
            titleEl.innerHTML = '<img src="assets/img/appIcons/folderIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">' + esc(f.name);
        }
        var closeBtn = w.querySelector('[aria-label="Close"]');
        var minBtn   = w.querySelector('[aria-label="Minimize"]');
        var maxBtn   = w.querySelector('[aria-label="Maximize"]');
        if(closeBtn) closeBtn.onclick = function(){ closeFolderWindowById(id); };
        if(minBtn)   minBtn.onclick   = function(){ if(window.taskbarManager) taskbarManager.minimize(wid); };
        if(maxBtn)   maxBtn.onclick   = function(){
            if(w.classList.contains('win-maximized')){ w.classList.remove('win-maximized'); maxBtn.setAttribute('aria-label', 'Maximize'); }
            else { w.classList.add('win-maximized'); maxBtn.setAttribute('aria-label', 'Restore'); }
        };
        document.body.appendChild(w);
        openFolderWindows[id] = { el: w, wid: wid };
        var bodyEl = w.querySelector('.folder-content');
        if(bodyEl){
            bodyEl.addEventListener('contextmenu', function(e){
                if(e.target.closest('.folder-item')) return;
                e.preventDefault(); e.stopPropagation();
                showCtxMenu(e.clientX, e.clientY, id);
            });
        }
        if(window.WindowManager) window.WindowManager.setup(wid, false);
        if(window.taskbarManager) taskbarManager.register(wid, f.name, '<img src="assets/img/appIcons/folderIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        if(window.windowZ) windowZ.bringToFront(w);
        renderFolderContent(id);
    }

    function closeFolderWindowById(id){
        var entry = openFolderWindows[id]; if(!entry) return;
        if(window.taskbarManager && taskbarManager.isRegistered(entry.wid)) taskbarManager.unregister(entry.wid);
        entry.el.remove();
        delete openFolderWindows[id];
    }

    function renderFolderContent(id){
        var f = folders[id]; if(!f) return;
        var entry = openFolderWindows[id]; if(!entry) return;
        var box = entry.el.querySelector('.folder-content'); if(!box) return;
        box.innerHTML = '';
        if(!f.children || !f.children.length){
            var e = document.createElement('div'); e.className = 'folder-empty';
            e.textContent = 'Carpeta vacía. Arrastra iconos aquí desde el escritorio.';
            box.appendChild(e); return;
        }
        f.children.forEach(function(cid){
            var orig = document.getElementById(cid); if(!orig) return;
            var imgHtml = (orig.querySelector('.desktop-icon-img') || {}).innerHTML || '';
            var label   = (orig.querySelector('span') || {}).textContent || cid;
            var item = document.createElement('div');
            item.className = 'folder-item'; item.dataset.iconId = cid;
            item.innerHTML = '<div class="fi-img">' + imgHtml + '</div><div class="fi-label">' + esc(label) + '</div>';
            item.addEventListener('dblclick', function(){ var ev = new MouseEvent('dblclick', { bubbles: true, cancelable: true }); orig.dispatchEvent(ev); });
            if(folders[cid]){ item.addEventListener('contextmenu', function(e){ e.preventDefault(); e.stopPropagation(); deleteFolder(cid); }); }
            attachFolderItemDrag(item, id, cid);
            box.appendChild(item);
        });
    }

    var lastCtxX = 0, lastCtxY = 0;
    var ctxTargetFolderId = null;
    function showCtxMenu(x, y, targetFolderId){
        var menu = document.getElementById('desktop-ctx-menu');
        lastCtxX = x; lastCtxY = y; ctxTargetFolderId = targetFolderId || null;
        menu.style.left = x + 'px'; menu.style.top = y + 'px';
        menu.classList.add('show');
    }
    function hideCtxMenu(){ document.getElementById('desktop-ctx-menu').classList.remove('show'); }

    function bindMenu(){
        var desk = document.getElementById('desktop'); if(!desk) return;
        desk.addEventListener('contextmenu', function(e){
            if(e.target.closest('.desktop-icon')) return;
            e.preventDefault(); showCtxMenu(e.clientX, e.clientY);
        });
        /* Long-press en zona vacía del escritorio ≈ click derecho.
           600 ms para no chocar con los 350 ms del drag de iconos. */
        var lpTimer = null, lpX = 0, lpY = 0;
        desk.addEventListener('touchstart', function(e){
            if (e.target.closest('.desktop-icon')) return;
            if (!e.touches || e.touches.length !== 1) return;
            lpX = e.touches[0].clientX;
            lpY = e.touches[0].clientY;
            lpTimer = setTimeout(function(){
                lpTimer = null;
                showCtxMenu(lpX, lpY);
            }, 600);
        }, { passive: true });
        desk.addEventListener('touchmove', function(e){
            if (!lpTimer || !e.touches[0]) return;
            if (Math.abs(e.touches[0].clientX - lpX) > 10 ||
                Math.abs(e.touches[0].clientY - lpY) > 10) {
                clearTimeout(lpTimer); lpTimer = null;
            }
        }, { passive: true });
        desk.addEventListener('touchend',    function(){ if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } });
        desk.addEventListener('touchcancel', function(){ if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } });
        document.addEventListener('click', function(){ hideCtxMenu(); });
        document.addEventListener('contextmenu', function(e){ if(!e.target.closest('#desktop')) hideCtxMenu(); });
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') hideCtxMenu(); });
        var menu = document.getElementById('desktop-ctx-menu');
        menu.addEventListener('click', function(e){
            var li = e.target.closest('li[data-action]'); if(!li) return;
            var target = ctxTargetFolderId; hideCtxMenu();
            if(li.dataset.action === 'new-folder'){
                if(target) createFolderInside(target);
                else       createFolder(lastCtxX, lastCtxY);
            }
        });
        var fc = document.getElementById('folder-close');
        if(fc) fc.addEventListener('click', closeFolderWindow);
    }

    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', bindMenu); }
    else { bindMenu(); }

    window.DesktopFolders = {
        init: init, updateHover: updateHover, clearHover: clearHover,
        tryDrop: tryDrop, openFolder: openFolderWindow, closeFolder: closeFolderWindowById
    };
})();

/* =========================
   MASCOTA — botón ⋯ y ventana
========================= */
(function () {
    var mascotaFrame  = document.getElementById('mascota-frame');
    var mascotaLoaded = false;
    var menuBtn       = document.getElementById('mascota-menu-btn');

    /* ─── DRAG del botón flotante ────────────────────────────────────
       El botón arranca arriba-derecha (CSS). Si el usuario lo arrastra,
       cambiamos left/top inline y persistimos en localStorage. Para no
       confundir drag con click: solo entramos en modo drag tras
       desplazarse > DRAG_THRESHOLD px. Si no hubo drag, el click se
       deja pasar normal y abre el menú. */
    (function setupDrag(){
        if (!menuBtn) return;
        var DRAG_THRESHOLD = 4;
        var STORAGE_KEY    = 'mascota-menu-btn-pos';
        var startX = 0, startY = 0, baseLeft = 0, baseTop = 0;
        var dragging = false, dragged = false, pointerId = null;

        /* Restaurar última posición guardada (si existe). */
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                var p = JSON.parse(saved);
                if (typeof p.left === 'number' && typeof p.top === 'number') {
                    /* Clamp al viewport por si la pantalla ahora es más pequeña. */
                    var w = window.innerWidth, h = window.innerHeight;
                    menuBtn.style.left  = Math.max(0, Math.min(w - 36, p.left)) + 'px';
                    menuBtn.style.top   = Math.max(0, Math.min(h - 36, p.top))  + 'px';
                    menuBtn.style.right = 'auto';
                }
            }
        } catch (_) {}

        function ptXY(e){
            if (e.touches && e.touches[0])         return { x: e.touches[0].clientX,        y: e.touches[0].clientY        };
            if (e.changedTouches && e.changedTouches[0]) return { x: e.changedTouches[0].clientX, y: e.changedTouches[0].clientY };
            return { x: e.clientX, y: e.clientY };
        }

        function onDown(e){
            if (e.type === 'mousedown' && e.button !== 0) return;
            var p = ptXY(e);
            startX = p.x; startY = p.y;
            var r = menuBtn.getBoundingClientRect();
            baseLeft = r.left; baseTop = r.top;
            dragging = true; dragged = false;
            if (e.type === 'pointerdown' && menuBtn.setPointerCapture) {
                try { pointerId = e.pointerId; menuBtn.setPointerCapture(pointerId); } catch(_){}
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup',   onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend',  onUp);
            document.addEventListener('touchcancel', onUp);
        }
        function onMove(e){
            if (!dragging) return;
            var p = ptXY(e);
            var dx = p.x - startX, dy = p.y - startY;
            if (!dragged) {
                if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) return;
                dragged = true;
                menuBtn.classList.add('dragging');
                /* Una vez en drag, convertimos a coordenadas left/top y
                   eliminamos right para que el botón se ancore a la
                   esquina superior izquierda. */
                menuBtn.style.right = 'auto';
                menuBtn.style.left  = baseLeft + 'px';
                menuBtn.style.top   = baseTop  + 'px';
            }
            if (e.cancelable) e.preventDefault();
            var nx = baseLeft + dx, ny = baseTop + dy;
            /* Clamp al viewport. */
            nx = Math.max(0, Math.min(window.innerWidth  - menuBtn.offsetWidth,  nx));
            ny = Math.max(0, Math.min(window.innerHeight - menuBtn.offsetHeight, ny));
            menuBtn.style.left = nx + 'px';
            menuBtn.style.top  = ny + 'px';
        }
        function onUp(e){
            if (!dragging) return;
            dragging = false;
            menuBtn.classList.remove('dragging');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup',   onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend',  onUp);
            document.removeEventListener('touchcancel', onUp);
            if (dragged) {
                /* Guardar nueva posición. */
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify({
                        left: parseFloat(menuBtn.style.left) || 0,
                        top:  parseFloat(menuBtn.style.top)  || 0,
                    }));
                } catch(_){}
                /* Si fue un drag real, "comernos" el siguiente click
                   sintético para que no abra el menú al soltar. */
                var swallow = function(ev){
                    ev.stopPropagation(); ev.preventDefault();
                    menuBtn.removeEventListener('click', swallow, true);
                };
                menuBtn.addEventListener('click', swallow, true);
            }
        }
        menuBtn.addEventListener('mousedown',  onDown);
        menuBtn.addEventListener('touchstart', onDown, { passive: false });
    })();

    /* Abre la ventana de gestión */
    function openMascotaWindow() {
        if (!mascotaLoaded) {
            mascotaFrame.src = appSrc('apps/mascota.php');
            mascotaLoaded = true;
        }
        if (taskbarManager.isRegistered('mascota-window')) {
            taskbarManager.restore('mascota-window');
        } else {
            taskbarManager.register('mascota-window', 'Mascota', '<img src="assets/img/appIcons/mascotaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
        }
    }

    /** Invalida el iframe de la ventana de gestión para que la
     *  próxima apertura cargue datos frescos. Si la ventana está
     *  abierta ahora mismo, recarga al vuelo. Útil tras delete,
     *  hatch dev, break-egg — cualquier cambio que invalide el SSR. */
    function refreshMascotaWindow() {
        mascotaLoaded = false;
        var win = document.getElementById('mascota-window');
        if (win && win.style.display && win.style.display !== 'none') {
            mascotaFrame.src = appSrc('apps/mascota.php?_=' + Date.now());
            mascotaLoaded = true;
        }
    }
    /* Exponer global para que la pueda llamar el propio iframe (tras
       acciones internas como hatch dev) y el resto del desktop. */
    window.refreshMascotaWindow = refreshMascotaWindow;
    /* Expuesto para la sección solo-dev (icono de mascotas del escritorio). */
    window.openMascotaWindow = openMascotaWindow;

    document.getElementById('mascota-close').addEventListener('click', function () {
        taskbarManager.unregister('mascota-window');
    });

    /* ─── Picker "Alimentar" ────────────────────────────────────── */
    /* Hacer arrastrable la ventana del picker (solo drag, sin
       redimensionar — la ventana tiene tamaño fijo por su grid). */
    if (window.WindowManager) window.WindowManager.setup('alimentar-window', true);

    function openAlimentarWindow() {
        if (taskbarManager.isRegistered('alimentar-window')) {
            taskbarManager.restore('alimentar-window');
        } else {
            taskbarManager.register('alimentar-window', 'Alimentar', '🍴', 'flex');
        }
    }
    document.getElementById('alimentar-close').addEventListener('click', function () {
        taskbarManager.unregister('alimentar-window');
    });

    /* ─── Wrapped (Spotify Wrapped clone) ──────────────────────── */
    /* Setup completo: drag por la title-bar + resize por las esquinas.
       El card resumen y la imagen final escalan automáticamente con el
       tamaño del iframe (ver updateWcScale en wrapped.php). */
    if (window.WindowManager) window.WindowManager.setup('wrapped-window', false);

    var wrappedFrame   = document.getElementById('wrapped-frame');
    var wrappedCloseEl = document.getElementById('wrapped-close-window');

    /** Abre la ventana del Wrapped cargando el iframe.
     *  @param {boolean} dev - si true, carga con ?dev=1 (todas las plays). */
    function openWrappedWindow(dev) {
        wrappedFrame.src = appSrc('apps/wrapped.php?_=' + Date.now() + (dev ? '&dev=1' : ''));
        if (taskbarManager.isRegistered('wrapped-window')) {
            taskbarManager.restore('wrapped-window');
        } else {
            taskbarManager.register('wrapped-window', 'Wrapped', '🎁', 'flex');
        }
    }
    function closeWrappedWindow() {
        taskbarManager.unregister('wrapped-window');
        wrappedFrame.src = '';
    }
    /* Expuesto para la sección solo-dev (banner "Abrir Melon Wrapped"). */
    window.openWrappedWindow = openWrappedWindow;

    wrappedCloseEl.addEventListener('click', closeWrappedWindow);
    /* El iframe del wrapped puede pedir cerrarse vía postMessage. */
    window.addEventListener('message', function (ev) {
        if (ev && ev.data && ev.data.type === 'wrapped-close') {
            closeWrappedWindow();
        }
    });

    /* Notificación del 22 de diciembre — solo se muestra una vez por
       año (flag en localStorage). Click → abre wrapped (NO dev). */
    (function autoNotifyWrapped() {
        var now = new Date();
        var month = now.getMonth(); /* 0-11 */
        var day   = now.getDate();
        var year  = now.getFullYear();
        if (month !== 11 || day !== 22) return; /* Solo 22 de diciembre */
        var key = 'wrapped-notified-' + year;
        try { if (localStorage.getItem(key)) return; } catch (_) {}
        if (!window.notifSystem) return;
        setTimeout(function () {
            window.notifSystem.show({
                id:      'wrapped-' + year,
                type:    'info',
                title:   'Tu Wrapped ' + year + ' está aquí',
                message: 'Repasa tu año en música. Tap para abrir.',
                autoDismissAfter: 0,
                onClick: function () {
                    openWrappedWindow(false);
                    try { localStorage.setItem(key, '1'); } catch (_) {}
                },
            });
        }, 2000);
    })();

    /* Click sobre cualquier botón de alimento → POST feed con su slug. */
    document.querySelectorAll('.alimentar-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug = btn.dataset.slug;
            fetch('assets/mascota/api.php?action=feed', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ alimento: slug }),
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) {
                        if (window.notifSystem) {
                            window.notifSystem.show({
                                id: 'feed-err-' + Date.now(),
                                type: 'error',
                                title: 'Error',
                                message: (d && d.error) || 'No se pudo alimentar.',
                                autoDismissAfter: 3500,
                            });
                        }
                        return;
                    }
                    /* Sync stats locales del engine + animación de comer. */
                    if (window.MascotaEngine && window.MascotaEngine.getState) {
                        var st = window.MascotaEngine.getState();
                        if (st && st.mascota) {
                            st.mascota.hambre    = d.hambre;
                            st.mascota.felicidad = d.felicidad;
                        }
                        if (window.MascotaEngine.feed) window.MascotaEngine.feed();
                    }
                    /* Burbuja de reacción según gusto. */
                    var msgs = {
                        love:    [d.emoji + ' ¡Adoro ' + d.nombre + '! 😍', '¡Mi favorito! ❤'],
                        like:    ['¡Mmm, gracias! 😋',     '¡' + d.nombre + ' está rica!'],
                        neutral: ['Gracias.',              '...mh, vale.'],
                        dislike: ['Esto no me gusta 🙁',   '¿Otra vez ' + d.nombre + '?'],
                        hate:    ['¡Puaj! 🤢 odio ' + d.nombre, '¡Eso es asqueroso!'],
                    };
                    var arr = msgs[d.reaccion] || msgs.neutral;
                    var msg = arr[Math.floor(Math.random() * arr.length)];
                    if (window.MascotaEngine && window.MascotaEngine.showBubble) {
                        window.MascotaEngine.showBubble(msg, 3000);
                    }
                    /* Cerrar picker tras alimentar. */
                    taskbarManager.unregister('alimentar-window');
                    /* Refrescar ventana de gestión si está abierta. */
                    if (window.refreshMascotaWindow) window.refreshMascotaWindow();
                })
                .catch(function (e) { console.error('feed:', e); });
        });
    });

    /* Menú contextual del botón ⋯ */
    menuBtn.addEventListener('click', function (e) {
        e.stopPropagation();

        /* Si ya existe, cerrarlo */
        var existing = document.getElementById('mascota-ctx-inline');
        if (existing) { existing.remove(); return; }

        var menu = document.createElement('ul');
        menu.id        = 'mascota-ctx-inline';
        /* OJO: NO añadimos `desk-ctx` para que use SOLO los estilos de
           #mascota-ctx-inline (Win98 + tokens del tema). La clase
           `show` tampoco hace falta — el display es por id. */

        /* Las opciones del menú cambian según el estado actual:
           - HUEVO  → "Ver mascota" + "Dar calor" + eliminar
           - MASCOTA → "Ver mascota" + Alimentar/Jugar/Estado + eliminar
           Esto evita acciones inválidas (alimentar un huevo etc). */
        var isEgg = window.MascotaEngine && window.MascotaEngine.isEgg && window.MascotaEngine.isEgg();
        var mascotaImg = '<img src="assets/img/appIcons/mascotaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        var items;
        if (isEgg) {
            items = [
                { icon: mascotaImg, label: 'Ver huevo',     action: 'open' },
                { icon: '🔥',        label: 'Dar calor',     action: 'warm' },
                { sep: true },
                { icon: '🐣',        label: 'Eclosionar (DEV)', action: 'force-hatch' },
                { icon: '<img src="assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">', label: 'Eliminar (testeo)', action: 'delete', danger: true },
            ];
        } else {
            items = [
                { icon: mascotaImg, label: 'Ver mascota',   action: 'open'   },
                { icon: '🍕',        label: 'Alimentar',      action: 'feed'   },
                { icon: '⚽',        label: 'Jugar',           action: 'play'   },
                { icon: '💬',        label: '¿Cómo estás?',   action: 'status' },
                { sep: true },
                { icon: '<img src="assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">', label: 'Eliminar (testeo)', action: 'delete', danger: true },
            ];
        }

        items.forEach(function (item) {
            var li = document.createElement('li');
            if (item.sep) {
                li.className = 'sep';
            } else {
                /* innerHTML porque algunos `item.icon` son <img> inline
                   (PNG appIcon) en vez de un emoji string. El label es
                   data nuestra (no input usuario) → seguro. */
                li.innerHTML = item.icon + ' ' + item.label;
                if (item.danger) li.className = 'danger';
                li.addEventListener('click', function () {
                    menu.remove();
                    handleMascotaMenuAction(item.action);
                });
            }
            menu.appendChild(li);
        });

        /* Posicionar el menú cerca del botón flotante (puede haberse
           movido por el usuario). Ajustamos top/left tras montar — si
           se saldría por la derecha lo flipeamos a la izquierda del
           botón; si se saldría por abajo, hacia arriba. */
        document.body.appendChild(menu);
        var btnRect = menuBtn.getBoundingClientRect();
        var menuRect = menu.getBoundingClientRect();
        var top  = btnRect.bottom + 4;
        var left = btnRect.left;
        if (top + menuRect.height > window.innerHeight) {
            top = btnRect.top - menuRect.height - 4;
        }
        if (left + menuRect.width > window.innerWidth) {
            left = btnRect.right - menuRect.width;
        }
        menu.style.top  = Math.max(4, top) + 'px';
        menu.style.left = Math.max(4, left) + 'px';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';

        /* Cerrar al hacer click fuera */
        setTimeout(function () {
            document.addEventListener('click', function closeMascotaMenu() {
                var m = document.getElementById('mascota-ctx-inline');
                if (m) m.remove();
                document.removeEventListener('click', closeMascotaMenu);
            });
        }, 0);
    });

    function handleMascotaMenuAction(action) {
        switch (action) {
            case 'open':
                openMascotaWindow();
                break;

            case 'feed':
                /* Abrir ventana picker — la elección concreta del
                   alimento se delega al usuario. El handler de los
                   botones llama a la API y notifica al engine. */
                openAlimentarWindow();
                break;

            case 'play':
                if (typeof window.MascotaEngine !== 'undefined') {
                    window.MascotaEngine.play();
                } else {
                    openMascotaWindow();
                }
                break;

            case 'warm':
                /* Acción exclusiva del estado HUEVO. */
                if (typeof window.MascotaEngine !== 'undefined' && window.MascotaEngine.warm) {
                    window.MascotaEngine.warm();
                } else {
                    openMascotaWindow();
                }
                break;

            case 'force-hatch':
                /* DEV: dispara el prompt de nombre + eclosión inmediata. */
                if (typeof window.MascotaEngine !== 'undefined'
                    && window.MascotaEngine.forceHatch) {
                    window.MascotaEngine.forceHatch();
                }
                break;

            case 'status':
                if (typeof window.MascotaEngine !== 'undefined') {
                    var s = window.MascotaEngine.getState();
                    if (s && s.mascota) {
                        var h = s.mascota.hambre;
                        var f = s.mascota.felicidad;
                        var msg;
                        if (!s.mascota.viva)         msg = '💀 Ha muerto... ábrela para revivirla.';
                        else if (h > 70 && f > 70)   msg = '😄 ¡Está genial!';
                        else if (h < 25)              msg = '😢 Tiene mucha hambre...';
                        else if (f < 30)              msg = '😔 Se siente sola.';
                        else                          msg = '🙂 Bien (🍖' + h + ' ♥' + f + ')';
                        window.notifSystem.show({
                            id:    'mascota-status-' + Date.now(),
                            type:  'info',
                            title: '<img src="assets/img/appIcons/mascotaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Tu mascota',
                            message: msg,
                            autoDismissAfter: 4000,
                        });
                    } else {
                        openMascotaWindow();
                    }
                } else {
                    openMascotaWindow();
                }
                break;

            case 'delete':
                /* TESTEO: borra mascota + memoria con confirmación.
                   Tras éxito, despawnea (limpia el DOM, oculta el
                   botón, para los loops). Próximo doble-click en el
                   icono creará un huevo nuevo. */
                var ok = window.win98Confirm
                    ? null  // wait for callback
                    : window.confirm('¿Eliminar la mascota PARA SIEMPRE?\nSe borrarán todos sus datos. Solo para testeo.');
                var doDelete = function () {
                    fetch('assets/mascota/api.php?action=delete', { method: 'POST' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d && d.ok) {
                                if (window.MascotaEngine && window.MascotaEngine.despawn) {
                                    window.MascotaEngine.despawn();
                                }
                                /* La iframe de la ventana de gestión
                                   tenía SSR con la mascota anterior →
                                   invalidar para que la próxima
                                   apertura (o el reload inmediato si
                                   está abierta) traiga el estado nuevo
                                   (huevo recién creado). */
                                refreshMascotaWindow();
                                if (window.notifSystem) {
                                    window.notifSystem.show({
                                        id: 'mascota-deleted-' + Date.now(),
                                        type: 'info',
                                        title: '<img src="assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Mascota eliminada',
                                        message: 'Toda su data ha sido borrada. Doble-click en el icono para crear una nueva.',
                                        autoDismissAfter: 4000,
                                    });
                                }
                            } else {
                                alert('Error al eliminar: ' + (d && d.error || 'desconocido'));
                            }
                        })
                        .catch(function (e) { alert('Error de red: ' + e.message); });
                };
                if (window.win98Confirm) {
                    window.win98Confirm(
                        '¿Eliminar la mascota PARA SIEMPRE?\nSe borrarán todos sus datos.\n(Esta opción es solo para testeo.)',
                        'Eliminar mascota',
                        doDelete
                    );
                } else if (ok) {
                    doDelete();
                }
                break;
        }
    }

    /* Exponer para que el engine pueda llamar a openMascotaWindow */
    window.openMascotaWindow = openMascotaWindow;
})();

/* =========================
   MASCOTA — engine (carga dinámica)
   ─────────────────────────────────────────────
   Antes el engine se inicializaba Y spawneaba la mascota en cada carga
   del escritorio. Ahora solo se INICIALIZA (config + listo) — el
   `spawn()` se dispara al hacer doble-click en el icono "Mascota" del
   escritorio. Esto convierte la mascota en una "app" más en lugar de
   un widget global. El botón flotante ☰ aparece junto con el spawn.
========================= */
(function () {
    /* Icono Mascota retirado del escritorio. El engine sigue cargándose
       para que las interacciones existentes (alimentar, click-on-spawn,
       etc.) sigan funcionando si algo más invoca MascotaEngine.spawn(). */

    var script  = document.createElement('script');
    script.src  = 'assets/mascota/engine.js';
    script.onload = function () {
        if (typeof window.MascotaEngine === 'undefined') return;
        /* init() es ligero — solo guarda config. Lo llamamos ya, sin
           esperar a DesktopState. */
        window.MascotaEngine.init({
            userId:   <?php echo (int)userIdByKey($desktopUserKey); ?>,
            parejaId: <?php echo (int)$parejaId; ?>,
            label:    <?php echo json_encode($desktopLabel); ?>
        });
    };
    script.onerror = function () {
        console.error('[Mascota] No se pudo cargar engine.js');
    };
    document.head.appendChild(script);
})();

<?php if ($_isTablet): ?>
/* Long-press → contextmenu en escritorio + mascota. Como el contextmenu
   sintético burbujea, alcanza todos los handlers registrados (iconos,
   carpetas, area-vacía-de-desktop, mascota inline-ctx). */
(function () {
    if (!window.longPressMenu) return;
    var desk    = document.getElementById('desktop');
    var mascota = document.getElementById('mascota-window');
    if (desk)    longPressMenu.attach(desk);
    if (mascota) longPressMenu.attach(mascota);
})();
<?php endif; ?>
</script>

<script>
/* ── Enlaces compartidos (assets/links.php) ──────────────────────────
   melonOpenLink('<tipo>:<valor>') abre lo que dice el enlace. Llega:
   - al entrar desde enlace.php → index.php?abrir=… (enlace pendiente en
     la sesión, abajo);
   - de la app de escritorio (melonhub://… o un enlace pulsado dentro),
     con melonDesktop.onOpenLink, sin recargar la página. */
(function () {
    /* La música de un enlace gana a "la última canción" que el
       reproductor pone al arrancar (ver onReady en reproductor.php). */
    function whenPlayer(fn) {
        window.__melonLinkPlayed = true;
        if (window.__melonPlayerReady) fn();
        else window.__melonLinkPlay = fn;
    }
    function showPlayer() {
        if (window.taskbarManager && taskbarManager.isRegistered('music-player')) {
            try { taskbarManager.restore('music-player'); } catch (_) {}
        }
    }
    window.melonOpenLink = function (link) {
        var m = /^([a-z]+):(.+)$/.exec(String(link || ''));
        if (!m) return;
        var tipo = m[1], valor = m[2];
        if (tipo === 'perfil') {
            if (valor === currentUserKey) {
                var ic = document.getElementById('profile-icon');
                if (ic) ic.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
            } else if (typeof window.openProfileAtUser === 'function') {
                window.openProfileAtUser(valor);
            }
        } else if (tipo === 'cancion') {
            /* Título y artista como se ven en Melon Hub (song-info), no
               los de YouTube: con esos find-album sacaba otro álbum. */
            fetch('assets/music/api.php?action=song-info&id=' + encodeURIComponent(valor))
                .then(function (r) { return r.json(); })
                .catch(function () { return {}; })
                .then(function (d) {
                    d = d || {};
                    whenPlayer(function () {
                        melonPlaySong({ videoId: d.videoId || valor, title: d.title || '', artist: d.artist || '', duration: d.duration || 0 });
                        showPlayer();
                    });
                });
        } else if (tipo === 'album') {
            if (typeof openAlbumViewer === 'function') openAlbumViewer(valor);
        } else if (tipo === 'playlist') {
            if (typeof openSharedPlaylistViewer === 'function') openSharedPlaylistViewer(valor);
        } else if (tipo === 'escuchar') {
            if (typeof window.melonJoinListen === 'function') window.melonJoinListen(valor);
        } else if (tipo === 'torneo' || tipo === 'reto') {
            /* Torneo de ajedrez (anuncio en Discord, "Copiar enlace") o reto
               por enlace: Juegos lo abre (ajedrez.js, abrirDesde). */
            if (typeof window.melonOpenJuegos === 'function') window.melonOpenJuegos(tipo + ':' + valor);
        }
    };

    if (window.melonDesktop && typeof window.melonDesktop.onOpenLink === 'function') {
        window.melonDesktop.onOpenLink(window.melonOpenLink);
    }

    var pending = <?= json_encode($melonPendingLink) ?>;
    if (pending) {
        var run = function () { setTimeout(function () { window.melonOpenLink(pending); }, 300); };
        if (document.readyState === 'complete') run();
        else window.addEventListener('load', run);
    }
})();
</script>

<?php
/* Sección exclusiva de DEV (banner Wrapped + marca de agua "Dev Build").
   Solo se incluye si MELON_DEV_BUILD es true (en main es false → nada). */
if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD) {
    include __DIR__ . '/assets/dev/dev-desktop.php';
}
?>

</body>
</html>