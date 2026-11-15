<?php
/* ──────────────────────────────────────────────────────────────────────
   MELONARCHIVE — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Visor del archivo de YouTube de @melondeaguaarchive adaptado a móvil.
   - Lista vertical de playlists (single column, cards grandes).
   - Tap una playlist → lista de sus vídeos.
   - Tap un vídeo → reproductor fullscreen con embed de YouTube.
   - Botón "‹ Atrás" en header + back-button del SO vía pushState/popstate.
   - Si está embebido en mobile.php shell, "‹ Menú" emite postMessage para
     volver al launcher en vez de navegar. Al abrir el reproductor pausa
     la música del shell para no solapar dos streams de YouTube.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__, 2) . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';
/* Solo-dev: la wiki (MelonWiki) solo se activa en dev o con el modo dev
   (assets/php/modo-dev.php). Si no → MelonArchive abre directo al archivo. */
require_once dirname(__DIR__, 2) . '/assets/php/modo-dev.php';
$WIKI = melon_funcionesDev();

if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../../index.php');
    exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

/* Tema activo del usuario — mismo flow que el resto de apps móviles. */
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
    <title>MelonArchive</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
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
    <link rel="stylesheet" href="../../assets/css/mobile-theme.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/mobile-theme.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
    <style>
        /* Refresh visual rules específicas de esta app — el grueso del
           Win98 lo aportan 98.css + tokens.css + mobile-theme.css. */

        /* Sub-header con botón Atrás + breadcrumb. Sticky al top del
           panel hundido. Solo visible cuando entras en una playlist. */
        .ma-subbar {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 8px;
            background: var(--win-bg, silver);
            border-bottom: 1px solid var(--bezel-dark-1, #808080);
        }
        .ma-back-btn {
            min-height: 26px; min-width: 60px;
            font-size: 11px; padding: 0 8px;
        }
        .ma-back-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .ma-breadcrumb {
            flex: 1; min-width: 0;
            font-size: 12px; font-weight: bold;
            color: var(--text, #000);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Panel principal — lista vertical de cards. Hundido Win98. */
        .ma-panel {
            flex: 1; min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px;
            background: var(--input-bg, #fff);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
        }

        /* Card de playlist o vídeo — thumb grande a la izquierda, texto
           a la derecha. Touch target generoso (~84px). */
        .ma-card {
            display: flex; align-items: center;
            gap: 10px; padding: 6px;
            background: var(--win-bg, silver);
            margin-bottom: 6px;
            cursor: pointer;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #808080),
                inset  1px  1px var(--bezel-light-1, #fff);
            user-select: none;
            min-height: 84px;
            transition: transform 0.08s ease;
        }
        .ma-card:active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
            transform: scale(0.98);
        }
        .ma-card-thumb {
            width: 120px; height: 68px;
            flex-shrink: 0;
            background: var(--input-bg, #fff);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
        }
        .ma-card-thumb img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .ma-card-thumb-ph { font-size: 30px; color: var(--text-faint, #888); }
        .ma-card-info {
            flex: 1; min-width: 0;
            display: flex; flex-direction: column;
            gap: 4px;
        }
        .ma-card-title {
            font-size: 13px; font-weight: bold;
            color: var(--text, #000);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ma-card:active .ma-card-title { color: var(--accent-text, #fff); }
        .ma-card-meta {
            font-size: 11px;
            color: var(--text-faint, #666);
            display: flex; align-items: center; gap: 4px;
        }
        .ma-card:active .ma-card-meta { color: var(--accent-text, #fff); }

        /* Status genérico para "Cargando…" / "Sin resultados". */
        .ma-status {
            font-size: 12px; text-align: center;
            color: var(--text-faint, #666);
            padding: 24px 12px;
        }

        /* Reproductor estilo HUD YouTube — vista in-place que sustituye
           a la lista de vídeos. Layout vertical:
             ┌─────────────────────────┐
             │ ‹ Atrás                 │  ← barra superior
             ├─────────────────────────┤
             │ [vídeo 16:9 full width] │  ← iframe sin banda negra
             ├─────────────────────────┤
             │ Título grande           │  ← info del vídeo
             │ Duración · canal        │
             ├─────────────────────────┤
             │ (sugerencias / vacío)   │
             └─────────────────────────┘
           Cuando el usuario pulsa el botón fullscreen del iframe,
           bloqueamos la orientación a landscape vía Screen Orientation API. */
        .ma-video-view {
            position: fixed; inset: 0;
            z-index: 200;
            background: var(--win-bg, silver);
            display: none;
            flex-direction: column;
        }
        .ma-video-view.visible { display: flex; }

        /* La ventana del reproductor ocupa toda la pantalla — mismo
           tamaño que mh-window y reusa su estética. */
        .ma-video-window {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
        }
        .ma-video-body {
            flex: 1; min-height: 0;
            padding: 0;
            display: flex; flex-direction: column;
            background: var(--win-bg, silver);
        }

        /* Botón "‹" en el title-bar — chiquito y discreto, encajado a
           la izquierda del título junto al área de controles. */
        .ma-video-back-bar {
            min-width: 22px; height: 18px;
            margin-right: 6px;
            padding: 0 6px;
            font-size: 13px; font-weight: bold;
            line-height: 1;
            color: var(--text, #000);
            background: var(--win-bg, silver);
            border: 0;
            cursor: pointer;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #808080),
                inset  1px  1px var(--bezel-light-1, #fff);
        }
        .ma-video-back-bar:active {
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
        }

        /* Wrap del iframe con aspect-ratio 16:9 → ocupa 100% del ancho
           y la altura se calcula automáticamente, sin bandas negras. */
        .ma-video-wrap {
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #000;
            flex-shrink: 0;
        }
        .ma-video-wrap iframe {
            width: 100%; height: 100%;
            border: 0; display: block;
        }

        /* Info debajo del vídeo — fondo Win98 silver. Cada bloque
           es una mini ventana Win98 completa con su title-bar y body,
           reproduciendo el lenguaje visual del shell. */
        .ma-video-info {
            flex: 1; min-height: 0;
            overflow-y: auto;
            padding: 8px;
            background: var(--win-bg, silver);
            -webkit-overflow-scrolling: touch;
            display: flex; flex-direction: column;
            gap: 8px;
        }

        /* Mini ventanas — reuso de .window de 98.css. Solo refino
           espacios y tipografía del body. */
        .ma-sub-window .window-body {
            margin: 6px;
            padding: 8px;
        }
        .ma-sub-window .title-bar-text {
            font-size: 11px;
        }

        /* Título del vídeo dentro de su window-body. */
        .ma-video-title {
            font-size: 14px; font-weight: bold;
            line-height: 1.35;
            margin: 0 0 6px;
            color: var(--text, #000);
            word-break: break-word;
        }
        .ma-video-title:last-child { margin-bottom: 0; }

        /* Meta line — chips de info separados por · punto medio. */
        .ma-video-meta {
            font-size: 11px;
            color: var(--text, #000);
            display: flex; flex-wrap: wrap;
            align-items: center; gap: 6px;
        }
        .ma-video-meta:empty { display: none; }

        /* Descripción colapsable. Mismo pane sunken con texto encima. */
        .ma-video-desc-box {
            cursor: pointer;
        }
        .ma-video-desc-box[data-overflow="0"] { cursor: default; }
        .ma-video-desc {
            font-size: 12px; line-height: 1.5;
            color: var(--text, #000);
            white-space: pre-wrap;
            word-break: break-word;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ma-video-desc-box.expanded .ma-video-desc {
            display: block;
            -webkit-line-clamp: none;
            line-clamp: none;
        }
        .ma-video-desc:empty::after {
            content: '(sin descripción)';
            color: var(--text-faint, #888);
            font-style: italic;
            font-size: 11px;
        }

        /* Toggle "Mostrar más" — botón Win98 clásico (raised) con
           pseudo-elemento de flecha. Cambia a "pressed" cuando expanded. */
        .ma-video-desc-toggle {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 8px;
            padding: 3px 10px;
            font-family: inherit;
            font-size: 11px;
            color: var(--text, #000);
            background: var(--win-bg, silver);
            border: 0;
            cursor: pointer;
            user-select: none;
            min-height: 22px;
            /* Bezel Win98 raised — el botón estándar del proyecto. */
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #808080),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, #404040),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        .ma-video-desc-toggle::before {
            display: inline-block;
            font-size: 10px;
            line-height: 1;
        }
        .ma-video-desc-box.expanded .ma-video-desc-toggle {
            /* Pressed/sunken state — invertimos los bezels. */
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-2, #404040),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        .ma-video-desc-box.expanded .ma-video-desc-toggle::before { content: '▴'; }
        .ma-video-desc-box:not(.expanded) .ma-video-desc-toggle::before { content: '▾'; }
        .ma-video-desc-box.expanded .ma-video-desc-toggle::after { content: 'Mostrar menos'; }
        .ma-video-desc-box:not(.expanded) .ma-video-desc-toggle::after { content: 'Mostrar más'; }
        .ma-video-desc-box[data-overflow="0"] .ma-video-desc-toggle { display: none; }
        .ma-video-desc-box.loading .ma-video-desc-toggle { display: none; }

        /* Skeleton loading — barras Win98 sin gradientes modernos. Tres
           rectángulos hundidos animados con opacity para sugerir carga
           sin romper la estética. */
        .ma-video-desc-box.loading .ma-video-desc {
            display: block;
            -webkit-line-clamp: none;
            line-clamp: none;
        }
        .ma-video-desc-box.loading .ma-video-desc::before {
            content: '';
            display: block;
            height: 11px;
            background: var(--bezel-dark-1, #808080);
            box-shadow:
                0 18px 0 var(--bezel-dark-1, #808080),
                0 36px 0 var(--bezel-dark-1, #808080);
            opacity: 0.35;
            animation: ma-pulse 1.1s ease-in-out infinite;
            width: 100%;
        }
        @keyframes ma-pulse {
            0%, 100% { opacity: 0.25; }
            50%      { opacity: 0.55; }
        }

        /* Durante fullscreen: el iframe se expande. Quitamos los inset
           shadows y el padding para que no asomen por los bordes. */
        .ma-video-wrap:fullscreen { aspect-ratio: auto; height: 100%; }
        <?php if ($WIKI): /* CSS de la wiki: solo en dev */ ?>
        /* ── WIKI (portal) — reusa .ma-card para tiles y filas ── */
        .ma-wiki-searchbar { display: flex; gap: 6px; padding: 8px; }
        .ma-wiki-searchbar input { flex: 1; box-sizing: border-box; font-size: 14px; padding: 7px; }
        .ma-wiki-article { padding: 12px 14px; color: var(--text, #000); }
        .ma-wiki-article h1 { font-size: 20px; margin: 0 0 4px; }
        .ma-wiki-article .meta { font-size: 11px; color: var(--text-muted, #777); border-bottom: 1px solid var(--border, #c0c0c0); padding-bottom: 8px; margin-bottom: 10px; }
        .ma-wiki-body { font-size: 14px; line-height: 1.6; }
        .ma-wiki-body h2 { font-size: 17px; margin: 12px 0 6px; }
        .ma-wiki-body h3 { font-size: 15px; margin: 10px 0 4px; }
        .ma-wiki-body h4 { font-size: 14px; margin: 8px 0 4px; }
        .ma-wiki-body p { margin: 0 0 10px; }
        .ma-wiki-body ul { margin: 0 0 10px 18px; }
        .ma-wiki-body a { color: var(--accent, #1db954); }
        .ma-wiki-editor { padding: 12px; display: flex; flex-direction: column; }
        .ma-wiki-editor label { font-size: 11px; font-weight: bold; margin: 8px 0 2px; color: var(--text, #000); }
        .ma-wiki-editor input[type=text], .ma-wiki-editor textarea { width: 100%; box-sizing: border-box; font-size: 14px; }
        .ma-wiki-editor textarea { resize: vertical; min-height: 200px; font-family: inherit; }
        .ma-wiki-hint { font-size: 10px; color: var(--text-muted, #777); margin-bottom: 4px; }
        .ma-wiki-actions { display: flex; gap: 8px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
        .ma-wiki-msg { font-size: 11px; color: var(--text-muted, #777); }
        .ma-wiki-cards { padding: 10px; }
        .ma-wiki-card { border: 1px solid var(--border, #c0c0c0); background: var(--win-body-bg, #fff); padding: 10px; margin-bottom: 10px; color: var(--text, #000); }
        .ma-wiki-card .head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .ma-wiki-card .title { font-weight: bold; font-size: 13px; }
        .ma-wiki-card .type { font-size: 10px; color: var(--text-muted, #777); margin-left: auto; }
        .ma-wiki-card .meta { font-size: 11px; color: var(--text-muted, #777); margin-top: 4px; }
        .ma-wiki-card .reason { font-size: 12px; margin-top: 6px; }
        .ma-wiki-card .pts { font-size: 12px; color: var(--accent, #1db954); font-weight: bold; margin-top: 4px; }
        .ma-wiki-modbody { font-size: 12px; line-height: 1.5; max-height: 160px; overflow: auto; border: 1px solid var(--border, #c0c0c0); padding: 6px 8px; margin: 8px 0; background: var(--win-bg, #fff); }
        .ma-wiki-modbody h2, .ma-wiki-modbody h3 { font-size: 14px; margin: 6px 0 3px; }
        .ma-wiki-reason-in { width: 100%; box-sizing: border-box; margin-bottom: 6px; font-size: 13px; }
        .ma-wiki-badge { display: inline-block; font-size: 10px; padding: 1px 7px; border-radius: 8px; background: var(--accent, #1db954); color: var(--accent-text, #fff); }
        .ma-wiki-badge.ok { background: #2e7d32; color: #fff; }
        .ma-wiki-badge.no { background: #b00020; color: #fff; }
        .ma-wiki-badge.pend { background: #9e6a00; color: #fff; }
        .ma-wiki-empty { padding: 24px; text-align: center; color: var(--text-muted, #777); font-size: 13px; }
        <?php endif; /* fin CSS wiki solo-dev */ ?>
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">

<div class="window mh-window">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">MelonArchive</div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize" disabled></button>
            <button aria-label="Close" onclick="/* Dentro de la carcasa (iframe): volver al menú; si no, cargaría otra mobile.php dentro con su propio reproductor (dos discos). */ if (window.parent !== window) { try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {} } else { window.location.href='../../mobile.php'; }"></button>
        </div>
    </div>
    <div class="window-body">

        <!-- Sub-header con botón Atrás + breadcrumb. -->
        <div class="ma-subbar">
            <button class="button ma-back-btn" id="ma-back" type="button" disabled>‹ Atrás</button>
            <span class="ma-breadcrumb" id="ma-breadcrumb">Playlists</span>
        </div>

        <!-- Panel principal: lista de cards. -->
        <div class="ma-panel" id="ma-panel">
            <div class="ma-status" id="ma-status">Cargando playlists…</div>
        </div>

        <!-- Status bar Win98 al pie con vuelta al menú. -->
        <div class="mh-statusbar">
            <a href="../../mobile.php">‹ Menú</a>
        </div>
    </div>
</div>

<!-- Reproductor in-place estilo YouTube móvil. Es una ventana Win98
     completa que sustituye la vista del listado. -->
<div class="ma-video-view" id="ma-video-view">
    <div class="window mh-window ma-video-window">
        <div class="title-bar">
            <button class="ma-video-back-bar" id="ma-video-back" type="button" aria-label="Atrás">‹</button>
            <div class="title-bar-text" id="ma-video-titlebar-text"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Reproductor</div>
            <div class="title-bar-controls">
                <button aria-label="Close" id="ma-video-close" type="button"></button>
            </div>
        </div>
        <div class="window-body ma-video-body">
            <div class="ma-video-wrap" id="ma-video-wrap">
                <iframe id="ma-video-iframe"
                        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                        allowfullscreen></iframe>
            </div>
            <div class="ma-video-info">
                <!-- Sub-ventana Win98: título + meta del vídeo. -->
                <div class="window ma-sub-window">
                    <div class="title-bar">
                        <div class="title-bar-text"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Vídeo</div>
                    </div>
                    <div class="window-body">
                        <div class="ma-video-title" id="ma-video-title">—</div>
                        <div class="ma-video-meta"  id="ma-video-meta"></div>
                    </div>
                </div>

                <!-- Sub-ventana Win98: descripción + toggle. -->
                <div class="window ma-sub-window">
                    <div class="title-bar">
                        <div class="title-bar-text"><img src="../../assets/img/appIcons/newsIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Descripción</div>
                    </div>
                    <div class="window-body ma-video-desc-box" id="ma-video-desc-box" data-overflow="0">
                        <div class="ma-video-desc" id="ma-video-desc"></div>
                        <span class="ma-video-desc-toggle"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ─── Detección de shell SPA ──────────────────────────────────────
   Si estamos embebidos dentro de mobile.php, el "‹ Menú" emite
   postMessage para volver al launcher sin recargar todo. Y antes de
   reproducir un vídeo del archivo, pausamos la música del shell. */
var EMBEDDED = (function(){ try { return window.parent !== window; } catch (_) { return false; } })();
var SHELL    = EMBEDDED ? window.parent.MuShell : null;
if (EMBEDDED) {
    document.addEventListener('click', function(e){
        var link = e.target && e.target.closest && e.target.closest('.mh-statusbar a[href*="../../mobile.php"]');
        if (!link) return;
        e.preventDefault();
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {}
    }, true);
}

/* ─── Estado ────────────────────────────────────────────────────── */
var API = '../../assets/yt-archive.php';
/* Flag solo-dev (lo emite PHP). En main es false → sin wiki, solo archivo. */
var WIKI_ENABLED = <?php echo $WIKI ? 'true' : 'false'; ?>;
var STATE = {
    view: 'playlists',          /* 'playlists' | 'videos' */
    currentPl: null,            /* { id, title } cuando estamos en videos */
    playlistsCache: null,       /* primera fetch de playlists */
};

var panelEl      = document.getElementById('ma-panel');
var statusEl     = document.getElementById('ma-status');
var breadcrumbEl = document.getElementById('ma-breadcrumb');
var backBtn      = document.getElementById('ma-back');
var videoView    = document.getElementById('ma-video-view');
var videoWrap    = document.getElementById('ma-video-wrap');
var videoIframe  = document.getElementById('ma-video-iframe');
var videoTitleEl = document.getElementById('ma-video-title');
var videoMetaEl  = document.getElementById('ma-video-meta');
var videoBackBtn = document.getElementById('ma-video-back');
var videoDescBox = document.getElementById('ma-video-desc-box');
var videoDescEl  = document.getElementById('ma-video-desc');

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function setStatus(msg) {
    panelEl.innerHTML = '<div class="ma-status">' + esc(msg) + '</div>';
}

/* ─── Playlists ────────────────────────────────────────────────── */
function loadPlaylists() {
    STATE.view = 'playlists';
    STATE.currentPl = null;
    breadcrumbEl.textContent = WIKI_ENABLED ? 'Archivo · Playlists' : 'Playlists';
    backBtn.disabled = !WIKI_ENABLED;   /* con wiki, playlists cuelga del Inicio */
    panelEl.scrollTop = 0;
    /* Si ya cacheamos las playlists en esta sesión, las re-pintamos
       sin volver a llamar al API (la lista no cambia entre clics). */
    if (STATE.playlistsCache) {
        renderPlaylists(STATE.playlistsCache);
        return;
    }
    setStatus('Cargando playlists…');
    fetch(API + '?action=playlists', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { setStatus(d.error); return; }
            STATE.playlistsCache = d.playlists || [];
            renderPlaylists(STATE.playlistsCache);
        })
        .catch(function(e){ setStatus('Error: ' + e.message); });
}

function renderPlaylists(list) {
    if (!list.length) { setStatus('No se encontraron playlists.'); return; }
    var html = '';
    list.forEach(function(pl, i){
        var thumbHtml = pl.thumb
            ? '<img src="' + esc(pl.thumb) + '" alt="" loading="lazy">'
            : '<span class="ma-card-thumb-ph"><img src="../../assets/img/appIcons/folderIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>';
        html +=
            '<div class="ma-card" data-act="open-pl" data-idx="' + i + '" role="button" tabindex="0">' +
                '<div class="ma-card-thumb">' + thumbHtml + '</div>' +
                '<div class="ma-card-info">' +
                    '<div class="ma-card-title">' + esc(pl.title || 'Sin título') + '</div>' +
                    '<div class="ma-card-meta">' + esc(pl.count || '') + '</div>' +
                '</div>' +
            '</div>';
    });
    panelEl.innerHTML = html;
}

/* ─── Vídeos de una playlist ───────────────────────────────────── */
function loadVideos(pl) {
    STATE.view = 'videos';
    STATE.currentPl = pl;
    breadcrumbEl.textContent = pl.title || 'Vídeos';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    setStatus('Cargando vídeos…');
    /* pushState para que el back-button del SO funcione (popstate vuelve
       a la lista de playlists). */
    try { history.pushState({ view: 'videos', plId: pl.id }, '', '#pl=' + encodeURIComponent(pl.id)); } catch (_) {}
    fetch(API + '?action=videos&list=' + encodeURIComponent(pl.id), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { setStatus(d.error); return; }
            renderVideos(d.videos || []);
        })
        .catch(function(e){ setStatus('Error: ' + e.message); });
}

function renderVideos(list) {
    if (!list.length) { setStatus('Esta playlist no tiene vídeos.'); return; }
    var html = '';
    list.forEach(function(v, i){
        var thumbHtml = v.thumb
            ? '<img src="' + esc(v.thumb) + '" alt="" loading="lazy">'
            : '<span class="ma-card-thumb-ph"><img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:32px;height:32px;object-fit:contain;image-rendering:pixelated;"></span>';
        var dur = v.duration ? '⏱ ' + esc(v.duration) : '';
        html +=
            '<div class="ma-card" data-act="play-v" data-idx="' + i + '" role="button" tabindex="0">' +
                '<div class="ma-card-thumb">' + thumbHtml + '</div>' +
                '<div class="ma-card-info">' +
                    '<div class="ma-card-title">' + esc(v.title || 'Sin título') + '</div>' +
                    '<div class="ma-card-meta">' + dur + '</div>' +
                '</div>' +
            '</div>';
    });
    panelEl.innerHTML = html;
    /* Guardamos la lista en un campo del DOM para resolver los taps sin
       depender de variables globales. */
    panelEl._videos = list;
}

/* ─── Reproductor in-place (vista YouTube-style) ───────────────── */
function playVideo(v) {
    /* Si hay música sonando en el shell, la pausamos para no solapar
       dos streams de YouTube simultáneos. */
    if (SHELL && typeof SHELL.getState === 'function') {
        try { var st = SHELL.getState(); if (st && st.playing && typeof SHELL.toggle === 'function') SHELL.toggle(); }
        catch (_) {}
    }
    STATE.view = 'player';
    videoTitleEl.textContent = v.title || 'Vídeo';
    videoMetaEl.textContent  = v.duration ? '⏱ ' + v.duration : '';
    /* Reset descripción mientras carga — shimmer skeleton. */
    videoDescEl.textContent = '';
    videoDescBox.classList.remove('expanded');
    videoDescBox.classList.add('loading');
    videoDescBox.dataset.overflow = '0';
    videoIframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(v.id) +
                      '?autoplay=1&rel=0&playsinline=1';
    videoView.classList.add('visible');
    /* pushState para que el back-button del SO cierre el reproductor
       primero antes de salir de la playlist. */
    try { history.pushState({ view: 'player', videoId: v.id }, '', '#v=' + encodeURIComponent(v.id)); } catch (_) {}
    /* Fetch async de descripción + autor + vistas. Si tarda o falla, el
       título/duración ya se ven inmediatamente. */
    fetchVideoInfo(v.id, v.duration);
}

/* Pide info extra del vídeo a yt-archive.php → describe + autor +
   vistas. Pinta debajo del título y decide si el bloque descripción
   necesita el toggle "Mostrar más" (solo si el texto supera el clamp
   de 3 líneas). */
function fetchVideoInfo(videoId, fallbackDuration) {
    fetch(API + '?action=video-info&id=' + encodeURIComponent(videoId), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            videoDescBox.classList.remove('loading');
            if (d.error) {
                videoDescEl.textContent = '';
                return;
            }
            /* Meta: vistas formateadas + autor. Caemos al fallback de
               duración del listado si el detalle no lo trae. */
            var metaParts = [];
            if (d.viewCount) {
                var n = parseInt(d.viewCount, 10);
                if (!isNaN(n)) metaParts.push('👁 ' + formatViews(n));
            }
            if (d.author) metaParts.push(d.author);
            if (fallbackDuration) metaParts.push('⏱ ' + fallbackDuration);
            videoMetaEl.textContent = metaParts.join(' · ');
            /* Descripción. textContent (no innerHTML) → escape automático. */
            videoDescEl.textContent = d.description || '';
            /* Decidir si necesita toggle "Mostrar más": comparamos
               scrollHeight con clientHeight tras un repaint. */
            requestAnimationFrame(function(){
                var overflow = videoDescEl.scrollHeight > videoDescEl.clientHeight + 1;
                videoDescBox.dataset.overflow = overflow ? '1' : '0';
            });
        })
        .catch(function(){
            videoDescBox.classList.remove('loading');
            videoDescEl.textContent = '';
        });
}

/* Formato compacto de vistas (1.2 M, 34 K, 567). */
function formatViews(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + ' M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + ' K';
    return String(n);
}

/* Tap en el bloque descripción → expande / colapsa. Solo cuando hay
   overflow real, si no el toggle queda oculto vía CSS. */
videoDescBox.addEventListener('click', function(){
    if (this.dataset.overflow === '0') return;
    this.classList.toggle('expanded');
});

function closePlayer() {
    if (!videoView.classList.contains('visible')) return;
    videoView.classList.remove('visible');
    videoIframe.src = '';
    /* Vuelve a la vista de videos en STATE. */
    STATE.view = 'videos';
    /* Si seguimos en fullscreen (raro pero posible), salimos. */
    if (document.fullscreenElement) {
        try { document.exitFullscreen(); } catch (_) {}
    }
}

/* Cuando el iframe entra en fullscreen (tap del botón fullscreen
   nativo de YouTube), bloqueamos la orientación a landscape. Al salir,
   la liberamos. iOS Safari ignora `screen.orientation.lock` pero no
   rompe nada — la API se llama y resuelve con NotSupportedError. */
document.addEventListener('fullscreenchange', function(){
    if (document.fullscreenElement) {
        if (screen.orientation && typeof screen.orientation.lock === 'function') {
            screen.orientation.lock('landscape').catch(function(){});
        }
    } else {
        if (screen.orientation && typeof screen.orientation.unlock === 'function') {
            try { screen.orientation.unlock(); } catch (_) {}
        }
    }
});

/* ─── Delegación de taps + back ────────────────────────────────── */
panelEl.addEventListener('click', function(e){
    /* Enlaces internos de la wiki ([[Página]]) dentro de un artículo. */
    var il = e.target && e.target.closest && e.target.closest('.wiki-ilink');
    if (il) { e.preventDefault(); openPage(il.dataset.slug); return; }
    var card = e.target && e.target.closest && e.target.closest('.ma-card');
    if (!card) return;
    var act = card.dataset.act;
    if (act === 'go')        { navGo(card.dataset.go); return; }
    if (act === 'wiki-open') { openPage(card.dataset.slug); return; }
    var idx = parseInt(card.dataset.idx, 10);
    if (act === 'open-pl') {
        var pl = (STATE.playlistsCache || [])[idx];
        if (pl) loadVideos(pl);
    } else if (act === 'play-v') {
        var v = (panelEl._videos || [])[idx];
        if (v) playVideo(v);
    }
});

backBtn.addEventListener('click', function(){
    /* En videos mantenemos la semántica con history (back del SO).
       El reproductor se cierra con su ✕. El resto de vistas navegan
       en-app hacia su pantalla anterior. */
    if (STATE.view === 'videos') {
        try { history.back(); } catch (_) { loadPlaylists(); }
        return;
    }
    if (WIKI_ENABLED) goBack();   /* sin wiki, playlists es la raíz */
});

/* Botones "‹" (title-bar) y "✕" (close del title-bar) cierran el
   reproductor con la misma semántica del back del SO: usan
   history.back() para mantener el historial consistente. */
function backFromPlayer() {
    if (location.hash.indexOf('#v=') === 0) {
        try { history.back(); return; } catch (_) {}
    }
    closePlayer();
}
videoBackBtn.addEventListener('click', backFromPlayer);
var videoCloseBtn = document.getElementById('ma-video-close');
if (videoCloseBtn) videoCloseBtn.addEventListener('click', backFromPlayer);

/* Back-button del SO / gesto de borde. */
window.addEventListener('popstate', function(){
    /* Reproductor abierto → ciérralo. */
    if (videoView.classList.contains('visible')) {
        closePlayer();
        return;
    }
    /* Vista videos → vuelve a playlists. */
    if (STATE.view === 'videos') {
        loadPlaylists();
    }
});

/* El shell padre puede pedirnos que paremos cuando se cierra la app. */
window.addEventListener('message', function(ev){
    var d = ev.data || {};
    if (d && d.type === 'archive-stop') closePlayer();
});

/* ════════════════════════════════════════════════════════════════
   WIKI / PORTAL — bienvenida + entradas + editor + moderación
═══════════════════════════════════════════════════════════════════ */
var WIKI_API = '../../assets/wiki/api.php';
var WIKI_ADMIN = false;
var wikiEdit = null;   /* {pageId, backSlug} mientras editas */

function wikiGet(action, params) {
    var qs = new URLSearchParams(Object.assign({ action: action }, params || {})).toString();
    return fetch(WIKI_API + '?' + qs, { credentials: 'same-origin' }).then(function(r){ return r.json(); });
}
function wikiPost(action, body) {
    return fetch(WIKI_API + '?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    }).then(function(r){ return r.json(); });
}
function wikiSlug(s) {
    s = (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 150) || 'pagina';
}
function fmtDate(s) {
    if (!s) return '';
    var d = new Date(s.replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
}
/* Markdown ligero seguro (escapa y aplica formato básico). */
function wikiRender(text) {
    var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
    var html = '', inP = false;
    function closeP(){ if (inP) { html += '</p>'; inP = false; } }
    function inline(s) {
        s = esc(s);
        s = s.replace(/\[\[([^\]]+)\]\]/g, function(_, t){ return '<a href="#" class="wiki-ilink" data-slug="' + esc(wikiSlug(t)) + '">' + esc(t) + '</a>'; });
        s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function(_, t, u){ return '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(t) + '</a>'; });
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
        s = s.replace(/_([^_]+)_/g, '<em>$1</em>');
        return s;
    }
    lines.forEach(function(ln){
        var t = ln.trim(), m;
        if (t === '') { closeP(); return; }
        if ((m = t.match(/^(#{1,3})\s+(.*)$/))) { closeP(); var lvl = m[1].length + 1; html += '<h' + lvl + '>' + inline(m[2]) + '</h' + lvl + '>'; return; }
        if ((m = t.match(/^[-*]\s+(.*)$/)))      { closeP(); html += '<ul><li>' + inline(m[1]) + '</li></ul>'; return; }
        if (!inP) { html += '<p>'; inP = true; } else { html += '<br>'; }
        html += inline(t);
    });
    closeP();
    return html;
}

/* ── Navegación ── */
function navGo(go) {
    if (go === 'archive')           loadPlaylists();
    else if (go === 'wiki-list')    openWikiList();
    else if (go === 'wiki-new')     openEditor(null);
    else if (go === 'wiki-requests')openMyRequests();
    else if (go === 'wiki-mod')     openModeration();
}
function goBack() {
    switch (STATE.view) {
        case 'wiki-page':    openWikiList(); break;
        case 'wiki-edit':    (wikiEdit && wikiEdit.pageId) ? openPage(wikiEdit.backSlug) : openWikiList(); break;
        case 'wiki-list':
        case 'wiki-requests':
        case 'wiki-mod':
        case 'playlists':    goHome(); break;
        default:             goHome();
    }
}

/* ── Inicio (portal) ── */
function maTile(go, icon, name, desc) {
    return '<div class="ma-card" data-act="go" data-go="' + go + '" role="button" tabindex="0">' +
        '<div class="ma-card-thumb"><span class="ma-card-thumb-ph">' + icon + '</span></div>' +
        '<div class="ma-card-info"><div class="ma-card-title">' + esc(name) + '</div>' +
        '<div class="ma-card-meta">' + esc(desc) + '</div></div></div>';
}
function goHome() {
    STATE.view = 'home';
    breadcrumbEl.textContent = 'MelonArchive';
    backBtn.disabled = true;
    panelEl.scrollTop = 0;
    var html =
        maTile('archive', '📼', 'Archivo de vídeos', 'Playlists y vídeos de YouTube') +
        maTile('wiki-list', '📖', 'Explorar la wiki', 'Navega las entradas') +
        maTile('wiki-new', '✎', 'Crear página', 'Propón una entrada nueva') +
        maTile('wiki-requests', '🗳', 'Mis solicitudes', 'Estado de tus envíos');
    if (WIKI_ADMIN) html += maTile('wiki-mod', '🛡', 'Moderación', 'Revisar solicitudes');
    panelEl.innerHTML = html;
}

/* ── Lista de páginas ── */
function openWikiList() {
    STATE.view = 'wiki-list';
    breadcrumbEl.textContent = 'Wiki · Explorar';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    panelEl.innerHTML =
        '<div class="ma-wiki-searchbar"><input type="search" id="ma-wiki-search" placeholder="Buscar páginas…" autocomplete="off"><button class="button" id="ma-wiki-new">＋</button></div>' +
        '<div id="ma-wiki-results"><div class="ma-wiki-empty">Cargando…</div></div>';
    var inp = document.getElementById('ma-wiki-search'), t = null;
    inp.addEventListener('input', function(){ clearTimeout(t); var q = this.value.trim(); t = setTimeout(function(){ renderWikiList(q); }, 300); });
    document.getElementById('ma-wiki-new').addEventListener('click', function(){ openEditor(null); });
    renderWikiList('');
}
function renderWikiList(q) {
    var box = document.getElementById('ma-wiki-results');
    if (!box) return;
    wikiGet('list-pages', q ? { q: q } : null).then(function(res){
        var pages = (res && res.pages) || [];
        if (!pages.length) { box.innerHTML = '<div class="ma-wiki-empty">' + (q ? 'Sin resultados.' : 'Aún no hay páginas. ¡Crea la primera!') + '</div>'; return; }
        box.innerHTML = pages.map(function(p){
            return '<div class="ma-card" data-act="wiki-open" data-slug="' + esc(p.slug) + '" role="button" tabindex="0">' +
                '<div class="ma-card-info"><div class="ma-card-title">' + esc(p.title) + '</div>' +
                '<div class="ma-card-meta">' + fmtDate(p.updatedAt) + '</div></div></div>';
        }).join('');
    });
}

/* ── Leer página ── */
function openPage(slug) {
    STATE.view = 'wiki-page';
    breadcrumbEl.textContent = 'Wiki';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    panelEl.innerHTML = '<div class="ma-wiki-empty">Cargando…</div>';
    wikiGet('get-page', { slug: slug }).then(function(res){
        var page = res && res.page;
        if (!page) {
            panelEl.innerHTML = '<div class="ma-wiki-empty">Esta página no existe todavía.' +
                '<div style="margin-top:10px"><button class="button" id="ma-wiki-create">Crear "' + esc(slug) + '"</button></div></div>';
            var b = document.getElementById('ma-wiki-create');
            if (b) b.onclick = function(){ openEditor(null, slug.replace(/-/g, ' ')); };
            return;
        }
        breadcrumbEl.textContent = 'Wiki · ' + page.title;
        panelEl.innerHTML =
            '<div class="ma-wiki-article"><h1>' + esc(page.title) + '</h1>' +
            '<div class="meta">Última edición: ' + fmtDate(page.updatedAt) + ' · ' + esc(page.updatedBy) + '</div>' +
            '<div class="ma-wiki-body">' + wikiRender(page.body) + '</div>' +
            '<div style="margin-top:14px"><button class="button" id="ma-wiki-edit-btn">✎ Editar</button></div></div>';
        document.getElementById('ma-wiki-edit-btn').onclick = function(){ openEditor(page.id, page.title, page.body, page.slug); };
    });
}

/* ── Editor ── */
function openEditor(pageId, title, body, slug) {
    STATE.view = 'wiki-edit';
    wikiEdit = { pageId: pageId || null, backSlug: slug || null };
    breadcrumbEl.textContent = pageId ? 'Editar página' : 'Crear página';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    panelEl.innerHTML =
        '<div class="ma-wiki-editor">' +
            '<label>Título</label><input type="text" id="ma-we-title" autocomplete="off" placeholder="Título de la página">' +
            '<label>Contenido</label>' +
            '<div class="ma-wiki-hint">Markdown ligero: ## Título, **negrita**, *cursiva*, [texto](url), [[Otra página]].</div>' +
            '<textarea id="ma-we-body" placeholder="Escribe el contenido…"></textarea>' +
            '<label>Comentario para moderadores (opcional)</label><input type="text" id="ma-we-summary" autocomplete="off" placeholder="Qué cambiaste y por qué">' +
            '<div class="ma-wiki-actions"><button class="button default" id="ma-we-submit">Enviar a revisión</button><button class="button" id="ma-we-cancel">Cancelar</button><span class="ma-wiki-msg" id="ma-we-status"></span></div>' +
        '</div>';
    document.getElementById('ma-we-title').value = title || '';
    document.getElementById('ma-we-body').value = body || '';
    document.getElementById('ma-we-cancel').onclick = function(){ (wikiEdit && wikiEdit.pageId) ? openPage(wikiEdit.backSlug) : openWikiList(); };
    document.getElementById('ma-we-submit').onclick = submitEditor;
}
function submitEditor() {
    var title = document.getElementById('ma-we-title').value.trim();
    var body  = document.getElementById('ma-we-body').value.trim();
    var summary = document.getElementById('ma-we-summary').value.trim();
    var st  = document.getElementById('ma-we-status');
    var btn = document.getElementById('ma-we-submit');
    if (!title) { st.textContent = 'Ponle un título.'; return; }
    if (!body)  { st.textContent = 'El contenido no puede estar vacío.'; return; }
    btn.disabled = true; st.textContent = 'Enviando…';
    var payload = { title: title, body: body, summary: summary };
    if (wikiEdit && wikiEdit.pageId) payload.pageId = wikiEdit.pageId;
    wikiPost('submit', payload).then(function(res){
        btn.disabled = false;
        if (!res || res.error) { st.textContent = (res && res.error) || 'Error al enviar.'; return; }
        alert('Tu ' + (wikiEdit && wikiEdit.pageId ? 'edición' : 'página') + ' se ha enviado a los moderadores. Te avisaremos cuando la revisen.');
        openMyRequests();
    });
}

/* ── Mis solicitudes ── */
function openMyRequests() {
    STATE.view = 'wiki-requests';
    breadcrumbEl.textContent = 'Wiki · Mis solicitudes';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    panelEl.innerHTML = '<div class="ma-wiki-empty">Cargando…</div>';
    wikiGet('my-requests').then(function(res){
        var reqs = (res && res.requests) || [];
        if (!reqs.length) { panelEl.innerHTML = '<div class="ma-wiki-empty">No has enviado ninguna solicitud todavía.</div>'; return; }
        panelEl.innerHTML = '<div class="ma-wiki-cards">' + reqs.map(function(r){
            var badge = r.status === 'accepted' ? '<span class="ma-wiki-badge ok">Aceptada</span>'
                      : r.status === 'declined' ? '<span class="ma-wiki-badge no">Denegada</span>'
                      : '<span class="ma-wiki-badge pend">Pendiente</span>';
            var extra = '';
            if (r.status === 'accepted' && r.pointsAwarded) extra += '<div class="pts">+' + r.pointsAwarded + ' autismo</div>';
            if (r.reason) extra += '<div class="reason"><b>Motivo:</b> ' + esc(r.reason) + '</div>';
            return '<div class="ma-wiki-card"><div class="head">' + badge + '<span class="title">' + esc(r.title) + '</span><span class="type">' + (r.isNew ? 'Nueva' : 'Edición') + '</span></div>' +
                '<div class="meta">Enviada: ' + fmtDate(r.createdAt) + (r.reviewedAt ? ' · Revisada: ' + fmtDate(r.reviewedAt) : '') + '</div>' + extra + '</div>';
        }).join('') + '</div>';
    });
}

/* ── Moderación (admin) ── */
function openModeration() {
    if (!WIKI_ADMIN) { goHome(); return; }
    STATE.view = 'wiki-mod';
    breadcrumbEl.textContent = 'Wiki · Moderación';
    backBtn.disabled = false;
    panelEl.scrollTop = 0;
    panelEl.innerHTML = '<div class="ma-wiki-empty">Cargando…</div>';
    wikiGet('pending').then(function(res){
        var reqs = (res && res.requests) || [];
        if (!reqs.length) { panelEl.innerHTML = '<div class="ma-wiki-empty">No hay solicitudes pendientes. ✓</div>'; return; }
        panelEl.innerHTML = '<div class="ma-wiki-cards" id="ma-wiki-modlist">' + reqs.map(function(r){
            return '<div class="ma-wiki-card" data-id="' + r.id + '"><div class="head"><span class="type">' + (r.isNew ? 'Nueva página' : 'Edición') + '</span><span class="title">' + esc(r.title) + '</span></div>' +
                '<div class="meta">Por ' + esc(r.author) + ' · ' + fmtDate(r.createdAt) + '</div>' +
                (r.summary ? '<div class="reason"><b>Comentario:</b> ' + esc(r.summary) + '</div>' : '') +
                '<div class="ma-wiki-modbody">' + wikiRender(r.body) + '</div>' +
                '<input type="text" class="ma-wiki-reason-in" placeholder="Motivo (se enviará al usuario)">' +
                '<div class="ma-wiki-actions"><button class="button default" data-wact="accept">Aceptar</button><button class="button" data-wact="decline">Denegar</button><span class="ma-wiki-msg" data-role="msg"></span></div>' +
            '</div>';
        }).join('') + '</div>';
        document.getElementById('ma-wiki-modlist').addEventListener('click', onModClick);
    });
}
function onModClick(e) {
    var btn = e.target && e.target.closest && e.target.closest('button[data-wact]');
    if (!btn) return;
    var card = btn.closest('.ma-wiki-card');
    var id = parseInt(card.dataset.id, 10);
    var decision = btn.dataset.wact;
    var reason = card.querySelector('.ma-wiki-reason-in').value.trim();
    var msg = card.querySelector('[data-role="msg"]');
    if (decision === 'decline' && !reason) { if (!confirm('Vas a denegar sin indicar motivo. ¿Continuar?')) return; }
    card.querySelectorAll('button').forEach(function(b){ b.disabled = true; });
    msg.textContent = 'Procesando…';
    wikiPost('review', { revisionId: id, decision: decision, reason: reason }).then(function(res){
        if (!res || res.error) { msg.textContent = (res && res.error) || 'Error.'; card.querySelectorAll('button').forEach(function(b){ b.disabled = false; }); return; }
        card.style.opacity = '0.5';
        msg.textContent = decision === 'accept' ? ('Aceptada' + (res.points ? ' (+' + res.points + ')' : '')) : 'Denegada';
        setTimeout(openModeration, 700);
    });
}

/* ─── Bootstrap ─────────────────────────────────────────────────── */
(function init(){
    /* En main (wiki desactivada) MelonArchive abre directo al archivo. */
    if (!WIKI_ENABLED) { loadPlaylists(); return; }
    goHome();
    wikiGet('am-admin').then(function(a){
        WIKI_ADMIN = !!(a && a.isAdmin);
        if (WIKI_ADMIN && STATE.view === 'home') goHome();   /* repinta con la tile de Moderación */
    }).catch(function(){});
})();
</script>

</body>
</html>
