<?php
/* ──────────────────────────────────────────────────────────────────────
   PLACEHOLDER MÓVIL — para apps que en el escritorio son parciales
   (Perfil, Música) y todavía no tienen entrada standalone para móvil.
   ──────────────────────────────────────────────────────────────────────
   En el escritorio, perfil.php y reproductor.php son `require`s desde
   desktop-base.php — esperan $desktopLabel, appTitleIcon() y el resto
   del contexto que monta el escritorio. No se pueden cargar tal cual
   como página standalone. Cuando estén adaptadas a móvil, basta con
   cambiar la URL del array $apps en mobile.php para que apunten a la
   nueva entrada.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__, 2) . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';

if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../../index.php');
    exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

$appName = isset($_GET['app']) ? trim((string)$_GET['app']) : 'Esta app';
$appName = mb_substr($appName, 0, 40);

/* ── TEMA DEL USUARIO (igual setup que mobile.php) ── */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes = loadUserThemes($userKey);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $userLabel);
    $activeThemeCss   = themeCssRelPath($activeTheme, $userLabel);
    if ($activeThemeCss !== '' && !file_exists(dirname(__DIR__, 2) . '/' . $activeThemeCss)) {
        $activeThemeCss = '';
    }
}
$themeBgColor = '#000000';
if ($activeTheme !== '' && isset($_userThemes['themes'][$activeTheme]['colors']['desktopBg'])) {
    $candidate = (string)$_userThemes['themes'][$activeTheme]['colors']['desktopBg'];
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $candidate)) {
        $themeBgColor = $candidate;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="../../assets/js/pwa-guard.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($themeBgColor) ?>">
    <title><?= htmlspecialchars($appName) ?> — Próximamente</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <!-- Mismo look Win98 + tema usuario que el resto del móvil -->
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__, 2) . "/assets/php/active-interface.php"; emitInterfaceCss("../../"); ?>
    <script src="../../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="../../<?= htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../../assets/css/mobile-theme.css">
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">

<div class="window mh-window" id="placeholderWindow">
    <div class="title-bar">
        <div class="title-bar-text">🚧 <?= htmlspecialchars($appName) ?></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize" disabled></button>
            <button aria-label="Close" onclick="/* Dentro de la carcasa (iframe): volver al menú; si no, cargaría otra mobile.php dentro con su propio reproductor (dos discos). */ if (window.parent !== window) { try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {} } else { window.location.href='../../mobile.php'; }"></button>
        </div>
    </div>
    <div class="window-body">

        <div class="mh-panel" style="display:flex;align-items:center;justify-content:center;">
            <div class="mh-empty">
                <span class="mh-empty-icon">🚧</span>
                <div style="font-size:14px;font-weight:bold;color:var(--text, #000);margin-bottom:8px;">
                    <?= htmlspecialchars($appName) ?>
                </div>
                <p style="font-size:12px;line-height:1.5;margin:0 0 14px;">
                    Esta app aún no tiene versión adaptada a móvil.<br>
                    Estamos trabajando en ello — vuelve pronto.
                </p>
            </div>
        </div>

        <div class="mh-statusbar">
            <a href="../../mobile.php">‹ Volver al menú</a>
        </div>

    </div>
</div>

<script>
/* Embebido en el shell SPA → "‹ Volver al menú" envía postMessage. */
(function(){
    var embedded = false;
    try { embedded = window.parent !== window; } catch (_) {}
    if (!embedded) return;
    document.addEventListener('click', function(e){
        var link = e.target && e.target.closest && e.target.closest('.mh-statusbar a[href*="../../mobile.php"]');
        if (!link) return;
        e.preventDefault();
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {}
    }, true);
})();
</script>

</body>
</html>
