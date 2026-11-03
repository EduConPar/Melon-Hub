<?php
/* ──────────────────────────────────────────────────────────────────────
   MÚSICA — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Reproductor YouTube embebido + listado de playlists del usuario.
   - GET /assets/music/api.php?action=get-playlists → playlists con tracks
   - YouTube IFrame API para reproducir por videoId
   - Mini-player sticky abajo (track actual + play/pause + prev/next)
   Sin editor de playlists, sin import Spotify/Tidal — esas son features
   complejas que viven en el reproductor de escritorio.
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

/* Helper para resolver el avatar de un usuario por label.
   Mira primero en uploads/profile-photos/ (foto subida en registro,
   gitignored para sobrevivir deploys) y cae al seed del repo en
   assets/img/. NO toca BD: este script no carga db.php y delegar en
   getUserImage() haría un require_once db.php lazy que, si falla la
   conexión, dispara die() y deja la app entera sin abrir. */
$resolveAvatar = function($label) {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$label);
    $root = dirname(__DIR__, 2);
    foreach (['webp','png','jpg','jpeg','gif'] as $ext) {
        if (file_exists($root . "/uploads/profile-photos/{$safe}.{$ext}")) {
            return "../../uploads/profile-photos/{$safe}.{$ext}";
        }
    }
    foreach (['png','jpg','jpeg','gif'] as $ext) {
        if (file_exists($root . "/assets/img/{$safe}.{$ext}")) {
            return "../../assets/img/{$safe}.{$ext}";
        }
    }
    return '';
};
$userImg = $resolveAvatar($userLabel);

/* Lista de TODOS los usuarios con sus avatares resueltos — la usa el
   frontend para pintar avatares de colaboradores y de "added by".
   Lo pasamos al JS como JSON inline para no tener que pedirlo por
   fetch en cada arranque. */
$usersForJs = [];
foreach ($loginUsers as $k => $u) {
    $usersForJs[] = [
        'key'   => $k,
        'label' => $u['label'],
        'img'   => $resolveAvatar($u['label']),
    ];
}

/* ── TEMA ACTIVO DEL USUARIO ──
   Replica el setup de mobile.php para mantener consistencia visual:
   misma paleta de colores en todas las pantallas móviles. */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes = loadUserThemes($userKey);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $userLabel);
    $activeThemeCss   = themeCssRelPath($activeTheme, $userLabel);
    /* refreshActiveThemeCss da una ruta relativa al root del proyecto;
       desde apps/ subimos un nivel para resolver el href. */
    if ($activeThemeCss !== '' && !file_exists(dirname(__DIR__, 2) . '/' . $activeThemeCss)) {
        $activeThemeCss = '';
    }
}

/* desktopBg del tema para el meta theme-color. */
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
    <!-- PWA GUARD: fuera de standalone → fuera de la app. -->
    <script>
    (function(){
        /* "MelonHubApp" en el User-Agent: la app de Android. */
        var sa = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
              || window.navigator.standalone === true
              || /MelonHubApp/.test(navigator.userAgent);
        if (!sa) window.location.replace('../../mobile-landing.php');
    })();
    /* --mh-vh sincronizado con window.innerHeight (sobrevive al bfcache). */
    (function(){
        function setVh(){
            document.documentElement.style.setProperty('--mh-vh', window.innerHeight + 'px');
        }
        setVh();
        window.addEventListener('resize', setVh);
        window.addEventListener('orientationchange', setVh);
        window.addEventListener('pageshow', setVh);
        window.addEventListener('visibilitychange', setVh);
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($themeBgColor) ?>">
    <title>Música — Scrapbook Melon</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <!-- Mismo stack que mobile.php para Win98 + tema del usuario -->
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../../assets/js/icon-pack.js"></script>
    <script src="../../assets/js/melon-links.js"></script>
    <?php require_once dirname(__DIR__, 2) . "/assets/php/active-interface.php"; emitInterfaceCss("../../"); ?>
    <script src="../../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="../../<?= htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../../assets/css/mobile-theme.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/mobile-theme.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
    <style>
        /* Tweaks específicos de Música — todo lo común (window, userbar,
           panel, statusbar) viene de mobile-theme.css. */

        /* Reservamos espacio abajo para el mini-player sticky. */
        .mu-list { padding-bottom: 88px; }

        /* Cada playlist es un bloque con head colapsable + tracks. */
        .mu-playlist + .mu-playlist {
            border-top: 1px dotted var(--text-faint, #aaa);
        }
        .mu-pl-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            cursor: pointer;
            background: var(--btn-bg, transparent);
            color: var(--text, #000);
            min-height: 44px;
        }
        .mu-pl-head:active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .mu-pl-info { flex: 1; min-width: 0; }
        .mu-pl-name {
            font-size: 14px;
            font-weight: bold;
            color: inherit;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mu-pl-meta {
            font-size: 11px;
            color: var(--text-faint, #666);
            margin-top: 1px;
        }
        /* Avatares de colaboradores: posición lateral (donde estaba el ⋮),
           apilados horizontalmente con leve overlap estilo Slack/GitHub
           para que ocupen poco ancho aunque haya hasta 4. */
        .mu-pl-collabs {
            display: flex;
            flex-direction: row-reverse;  /* el primero queda encima del resto */
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
            padding: 0 8px 0 10px;
        }
        .mu-pl-collab-av {
            width: 32px; height: 32px;
            object-fit: cover;
            image-rendering: pixelated;
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            margin-left: -10px;            /* overlap apilado */
            flex-shrink: 0;
            /* Marco de foto estilo Win98 (perfil.css .profile-avatar-frame). */
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .mu-pl-collab-av:last-child { margin-left: 0; }
        .mu-pl-collab-more {
            background: var(--btn-bg, silver);
            color: var(--text, #000);
        }
        /* Long-press en la cabecera abre el menú de opciones.
           user-select: none → evita que el navegador inicie la selección
           de texto al mantener el dedo pulsado. */
        .mu-pl-head {
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        /* Feedback visual mientras se está aguantando el long-press
           (clase que se añade en touchstart y se quita en touchend). */
        .mu-pl-head.long-pressing,
        .mu-track.long-pressing {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            transition: background 0.15s ease;
        }
        .mu-pl-head.long-pressing .mu-pl-meta,
        .mu-pl-head.long-pressing .mu-pl-arrow,
        .mu-track.long-pressing .mu-track-num,
        .mu-track.long-pressing .mu-track-artist,
        .mu-track.long-pressing .mu-track-dur {
            color: var(--accent-text, #fff);
        }
        /* Evita que la selección de texto se inicie al mantener pulsado. */
        .mu-track {
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        /* "Añadido por" en cada track — avatar circular junto a la duración. */
        .mu-track-addedby {
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .mu-track-addedby-av {
            width: 22px; height: 22px;
            object-fit: cover;
            image-rendering: pixelated;
            background: var(--btn-bg, silver);
            color: var(--text, #000);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
            margin: 0 2px;             /* aire al shadow exterior */
            /* Marco de foto Win98. */
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .mu-pl-head:active .mu-pl-meta { color: var(--accent-text, #fff); }
        .mu-pl-arrow {
            font-size: 14px;
            color: var(--text-faint, #444);
            transition: transform 0.2s;
            flex-shrink: 0;
        }
        .mu-pl-head:active .mu-pl-arrow { color: var(--accent-text, #fff); }
        .mu-playlist.open .mu-pl-arrow { transform: rotate(90deg); }

        /* Botón de play en el header de cada playlist. Inicia la
           reproducción desde el primer track al pulsarlo. Si la
           playlist está vacía queda en disabled. */
        .mu-pl-play {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
            padding: 0;
            margin: 0 4px 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text, #000);
            font-family: inherit;
        }
        .mu-pl-play svg {
            width: 16px;
            height: 16px;
            display: block;
        }
        .mu-pl-play:disabled {
            color: var(--text-faint, #888);
            cursor: not-allowed;
        }
        .mu-pl-head:active .mu-pl-play { color: var(--accent-text, #fff); }

        /* Lista de tracks dentro de una playlist abierta. */
        .mu-tracks {
            display: none;
            background: var(--inset-bg, rgba(0,0,0,0.05));
        }
        .mu-playlist.open .mu-tracks { display: block; }
        .mu-track {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            cursor: pointer;
            border-top: 1px dotted var(--text-faint, #aaa);
            color: var(--text, #000);
        }
        .mu-track:active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .mu-track.playing {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .mu-track-num {
            color: var(--text-faint, #888);
            font-size: 11px;
            width: 18px;
            text-align: right;
            flex-shrink: 0;
        }
        .mu-track:active .mu-track-num,
        .mu-track.playing .mu-track-num { color: var(--accent-text, #fff); }

        /* Thumbnail YouTube 16:9 escalado a cuadrado con object-fit: cover
           (recorta el centro, no añade bandas). Marco de foto Win98. */
        .mu-track-thumb {
            width: 40px;
            height: 40px;
            object-fit: cover;
            flex-shrink: 0;
            margin-left: 2px;          /* compensa el shadow exterior */
            background: var(--inset-bg, #ddd);
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .mu-track-thumb-ph {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--accent, #000080);
        }

        .mu-track-info { flex: 1; min-width: 0; }
        .mu-track-title {
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: inherit;
        }
        .mu-track-artist {
            color: var(--text-faint, #666);
            font-size: 11px;
            margin-top: 1px;
        }
        .mu-track:active .mu-track-artist,
        .mu-track.playing .mu-track-artist { color: var(--accent-text, #fff); }
        /* Nombre del álbum del track — separado del artista por un
           bullet sutil. Vacío hasta que find-album resuelve; tap abre
           el viewer del álbum.
           El color debe ser DISTINTO del --accent (background del row
           cuando .playing) para no confundirse visualmente con el
           highlight de "canción sonando". Usamos un azul tipo link
           neutro. Cuando .playing, override a --accent-text (blanco
           sobre el fondo accent). */
        .mu-track-album {
            display: inline;
            color: var(--mu-album-link, #6a9bd1);
            font-size: 11px;
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
            text-decoration-thickness: 1px;
            text-underline-offset: 2px;
        }
        .mu-track-album:empty { display: none; }
        .mu-track-album:before { content: " · "; opacity: 0.5; }
        .mu-track:active .mu-track-album,
        .mu-track.playing .mu-track-album { color: var(--accent-text, #fff); }
        /* Mientras se resuelve el long-press del album, da feedback. */
        .mu-track-album.long-pressing { background: var(--accent, #1db954); color: var(--accent-text, #fff); }

        /* Título del fullscreen: SIEMPRE pulsable (resuelve el álbum
           on-demand si hace falta). Cursor pointer da el feedback. */
        .mu-full-title { cursor: pointer; }
        /* Cuando el álbum YA está cacheado, además subrayado punteado
           para que se VEA que es link. Sin la clase queda neutro pero
           sigue siendo clickable. */
        .mu-player-title.is-album-clickable,
        .mu-full-title.is-album-clickable {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
            text-decoration-thickness: 1px;
            text-underline-offset: 2px;
        }

        /* ─ Album viewer ─ ventana ENTERA fullscreen (no modal).
           Cubre todo el viewport. El look se reaproxima al desktop:
           body con padding 8px + gap 6px, lista de tracks en panel
           hundido con fondo input-bg + bezel inset, header con cover
           80×80 + título 13px bold + artista en text-muted. */
        #mu-album-fullview {
            position: fixed; inset: 0;
            z-index: 73;
            background: var(--win-bg, silver);
            display: none;
            flex-direction: column;
            box-sizing: border-box;
            padding-top:    env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
        }
        #mu-album-fullview.is-open { display: flex; }
        .ma-titlebar { flex-shrink: 0; }
        /* Body interno con padding 8px + gap 6px (mismo que desktop). */
        .mu-album-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            padding: 8px;
            gap: 6px;
            box-sizing: border-box;
        }
        /* Header: cover + texto a la derecha, mismo layout que el
           #album-viewer-header del desktop. */
        .mu-album-header {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            flex-shrink: 0;
        }
        .mu-album-cover {
            width: 80px; height: 80px;
            object-fit: cover;
            background: var(--surface-deep, var(--inset-bg, #000));
            border: 1px solid var(--border, #808080);
            flex-shrink: 0;
            cursor: pointer;
        }
        .mu-album-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 2px;
        }
        .mu-album-name {
            font-size: 13px;
            color: var(--text, #000);
            font-weight: bold;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mu-album-artist {
            font-size: 11px;
            color: var(--text-muted, var(--text-faint, #666));
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* Botones de acción: una fila después del header, antes del
           panel de tracks. En desktop estos viven en el click-derecho
           del cover; en móvil los dejamos visibles como botones porque
           el long-press tiene otro propósito. */
        .mu-album-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .mu-album-actions .button {
            flex: 1 1 0;
            min-width: 0;
            font-size: 11px;
            min-height: 26px;
        }
        /* Panel de tracks: idéntico al #album-viewer-tracks del desktop
           — input-bg + border + sombra inset Win98. */
        .mu-album-tracks {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            background: var(--input-bg, #fff);
            border: 1px solid var(--border, #808080);
            box-shadow: inset 1px 1px var(--bezel-dark-1, #808080);
            -webkit-overflow-scrolling: touch;
        }
        /* Filas amplias optimizadas para touch: padding generoso, tipo
           14px, numeración y duración legibles. Mismo look base que el
           desktop pero con hit-targets cómodos para el pulgar. */
        .mu-album-track {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 12px;
            min-height: 48px;
            box-sizing: border-box;
            border-bottom: 1px solid var(--border, #c0c0c0);
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            color: var(--text, #000);
        }
        .mu-album-track:last-child { border-bottom: none; }
        .mu-album-track:active,
        .mu-album-track.long-pressing {
            background: color-mix(in srgb, var(--accent, #1db954) 22%, transparent);
        }
        .mu-album-track.is-playing {
            background: color-mix(in srgb, var(--accent, #1db954) 22%, transparent);
        }
        .mu-album-track.is-playing .mu-album-track-title {
            font-weight: bold;
            color: var(--accent-deep, var(--accent, #1db954));
        }
        .mu-album-track-num {
            width: 26px;
            text-align: right;
            font-size: 13px;
            color: var(--text-muted, var(--text-faint, #888));
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }
        .mu-album-track-title {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 14px;
            line-height: 1.25;
        }
        .mu-album-track-dur {
            width: 48px;
            text-align: right;
            color: var(--text-muted, var(--text-faint, #888));
            font-size: 12px;
            flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }
        /* Footer: botón Play álbum (principal) + botón Volver al lado.
           El play queda primero por affordance; volver es defensivo. */
        .mu-album-footer {
            flex-shrink: 0;
            display: flex;
            gap: 6px;
            padding: 0 0 max(0px, env(safe-area-inset-bottom)) 0;
        }
        .mu-album-footer .button { min-height: 30px; font-size: 12px; }
        .mu-album-footer .button.play-album {
            flex: 1;
        }
        .mu-album-footer .button.back-btn {
            flex: 0 0 auto;
            min-width: 86px;
        }
        .mu-track-dur {
            color: var(--text-faint, #888);
            font-size: 11px;
            font-variant-numeric: tabular-nums;
            flex-shrink: 0;
        }

        /* ════ Búsqueda global + vista de artista (overlays fullscreen,
           mismo patrón que #mu-album-fullview). search < artist < album. ════ */
        /* Los overlays solo posicionan; la ventana interior (.mh-window)
           aporta el mismo chrome que la pantalla de playlists (borde +
           title-bar + window-body + panel hundido). El fondo del overlay
           usa el del escritorio del tema para que, si el viewport supera
           los 480px, los laterales casen con la pantalla de playlists. */
        #mu-search-view, #mu-artist-view {
            position: fixed; inset: 0;
            background: var(--desktop-bg, var(--win-bg, silver));
            display: none; box-sizing: border-box;
            padding-top: env(safe-area-inset-top);
        }
        #mu-search-view  { z-index: 71; }
        #mu-artist-view  { z-index: 72; }
        #mu-search-view.is-open, #mu-artist-view.is-open { display: block; }

        /* Búsqueda — el searchbar es la toolbar sobre el panel; el panel
           hundido (.mh-panel) provee scroll, padding y borde interior. */
        .mu-sr-searchbar { flex-shrink: 0; }
        .mu-sr-searchbar input { width: 100%; box-sizing: border-box; font-size: 15px; padding: 8px; }
        .mu-sr-results.mh-panel { min-height: 0; }
        .mu-sr-group-title {
            font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted, var(--text-faint, #777));
            margin: 12px 2px 4px; font-weight: bold;
        }
        .mu-sr-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 6px; min-height: 52px; box-sizing: border-box;
            border-bottom: 1px solid var(--border, #c0c0c0);
            cursor: pointer; user-select: none; -webkit-user-select: none;
            color: var(--text, #000);
        }
        .mu-sr-row:active { background: color-mix(in srgb, var(--accent,#1db954) 22%, transparent); }
        .mu-sr-thumb {
            width: 44px; height: 44px; object-fit: cover; flex-shrink: 0;
            background: var(--inset-bg, #000); border: 1px solid var(--border, #808080);
        }
        .mu-sr-thumb.is-artist { border-radius: 50%; }
        .mu-sr-info { flex: 1; min-width: 0; }
        .mu-sr-name { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mu-sr-sub { font-size: 11px; color: var(--text-muted, var(--text-faint, #888)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mu-sr-msg { padding: 16px; text-align: center; color: var(--text-faint, #888); font-size: 12px; }
        /* Nota media de la comunidad (reseñas) en resultados/listas de música. */
        .mu-sr-rating {
            flex: 0 0 auto; margin-left: 8px;
            padding: 1px 7px; font-size: 12px; font-weight: bold; white-space: nowrap;
            color: var(--star-color, #e8b923);
            background: var(--inset-bg, rgba(0,0,0,0.18)); border-radius: 3px;
            align-self: center;
        }
        .mu-sr-time { flex: 0 0 auto; margin-left: 8px; font-size: 11px; color: var(--text-muted, var(--text-faint, #888)); white-space: nowrap; }
        .mu-aw-top-row .mu-sr-rating, .mu-album-track .mu-sr-rating { margin-left: 0; margin-right: 8px; }
        .mu-album-artist .mu-sr-rating { margin-left: 6px; padding: 0 6px; font-size: 11px; vertical-align: middle; }

        /* Vista de artista */
        .mu-aw-body { flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .mu-aw-banner {
            position: relative; height: 200px;
            background-size: cover; background-position: center;
            background-color: var(--inset-bg, #222);
            display: flex; flex-direction: column; justify-content: flex-end;
        }
        .mu-aw-banner::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,.1), rgba(0,0,0,.75));
        }
        .mu-aw-banner-name { position: relative; z-index: 1; color: #fff; font-size: 26px; font-weight: bold; padding: 0 12px 2px; text-shadow: 0 2px 6px rgba(0,0,0,.6); }
        .mu-aw-listeners { position: relative; z-index: 1; color: rgba(255,255,255,.85); font-size: 12px; padding: 0 12px 12px; text-shadow: 0 1px 4px rgba(0,0,0,.6); }
        .mu-aw-section-title { font-size: 13px; font-weight: bold; color: var(--text, #000); margin: 14px 10px 6px; }
        .mu-aw-top-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; cursor: pointer; user-select: none; color: var(--text, #000); }
        .mu-aw-top-row:active { background: color-mix(in srgb, var(--accent,#1db954) 22%, transparent); }
        .mu-aw-top-num { width: 18px; text-align: right; color: var(--text-faint,#888); font-size: 12px; flex-shrink: 0; font-variant-numeric: tabular-nums; }
        .mu-aw-top-thumb { width: 42px; height: 42px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--border,#808080); background: var(--inset-bg,#000); }
        .mu-aw-top-main { flex: 1; min-width: 0; }
        .mu-aw-top-title { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mu-aw-top-sub { font-size: 11px; color: var(--text-faint,#888); }
        .mu-aw-top-dur { font-size: 12px; color: var(--text-faint,#888); flex-shrink: 0; font-variant-numeric: tabular-nums; }
        /* Discografía como LISTA de lanzamientos (filas tipo móvil):
           carátula a la izquierda + nombre en negrita + "Tipo • Año".
           Mucho más legible en móvil que el grid de carátulas. */
        #mu-aw-albums { padding-bottom: 8px; }
        .mu-aw-rel-list { display: flex; flex-direction: column; padding: 4px 10px; }
        .mu-aw-rel {
            display: flex; align-items: center; gap: 12px;
            padding: 8px 4px; cursor: pointer; user-select: none;
            -webkit-user-select: none; color: var(--text, #000);
        }
        .mu-aw-rel:active { background: color-mix(in srgb, var(--accent,#1db954) 22%, transparent); }
        .mu-aw-rel-cover {
            width: 64px; height: 64px; object-fit: cover; flex-shrink: 0;
            border: 1px solid var(--border,#808080); background: var(--inset-bg,#000);
        }
        .mu-aw-rel-main { flex: 1; min-width: 0; }
        .mu-aw-rel-name {
            font-size: 15px; font-weight: bold; color: var(--text,#000);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .mu-aw-rel-sub {
            font-size: 12px; color: var(--text-faint,#888); margin-top: 2px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Botón "Ver discografía" centrado, tipo pastilla. */
        .mu-aw-disco-btn-wrap { display: flex; justify-content: center; padding: 8px 10px 18px; }
        .mu-aw-disco-btn { font-size: 12px; padding: 7px 18px; min-height: 34px; border-radius: 18px; }

        /* ════ Página de discografía completa (overlay sobre el artista) ════
           Mismo chrome que playlists: el overlay solo posiciona; la
           ventana interior (.mh-window) aporta borde + window-body +
           panel hundido (.mh-panel). */
        #mu-disco-view {
            position: fixed; inset: 0;
            background: var(--desktop-bg, var(--win-bg, silver));
            display: none; box-sizing: border-box;
            z-index: 73;
            padding-top: env(safe-area-inset-top);
        }
        #mu-disco-view.is-open { display: block; }
        .mu-disco-chips {
            display: flex; gap: 6px; flex-shrink: 0;
            overflow-x: auto; -webkit-overflow-scrolling: touch;
        }
        .mu-disco-chip { font-size: 11px; padding: 6px 12px; min-height: 30px; white-space: nowrap; border-radius: 15px; flex-shrink: 0; }
        .mu-disco-chip.is-active { font-weight: bold; }
        .mu-disco-body.mh-panel { min-height: 0; }
        .mu-disco-group-title { font-size: 17px; font-weight: bold; color: var(--text,#000); margin: 14px 12px 4px; }

        /* Editor de reseña — fila de estrellas grandes (tap para puntuar,
           tap en la mitad izquierda = media estrella). */
        #re-stars {
            font-size: 32px;
            letter-spacing: 6px;
            color: var(--accent, #000080);
            user-select: none;
            -webkit-user-select: none;
            line-height: 1;
        }
        #re-stars span {
            display: inline-block;
            position: relative;
            width: 1.1em;
            cursor: pointer;
        }

        /* ── Mini reproductor sticky abajo ── */
        .mu-player {
            position: fixed;
            left: 0; right: 0;
            /* Bottom = statusbar_top exacto, sin gap ni overlap.
               statusbar_height (22px) + window-body padding-bottom
               (max 8px o env safe-area). */
            bottom: calc(22px + max(8px, env(safe-area-inset-bottom, 0px)));
            background: var(--btn-bg, silver);
            border-top: 2px solid var(--accent, #000080);
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow:
                inset 0 1px var(--bezel-light-1, #fff),
                0 -4px 12px rgba(0,0,0,0.4);
            z-index: 50;
            transform: translateY(110%);
            transition: transform 0.25s ease;
            color: var(--text, #000);
        }
        .mu-player.visible { transform: translateY(0); }
        .mu-player-thumb {
            width: 40px;
            height: 40px;
            margin-left: 2px;          /* compensa el shadow exterior */
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            overflow: hidden;
            /* Marco de foto Win98 (idéntico al del escritorio). */
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .mu-player-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .mu-player-info { flex: 1; min-width: 0; }
        .mu-player-title {
            font-family: 'VT323', monospace;
            font-size: 18px;
            line-height: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text, #000);
            letter-spacing: 0.5px;
        }
        .mu-player-artist {
            font-family: 'VT323', monospace;
            font-size: 14px;
            line-height: 1;
            margin-top: 2px;
            color: var(--text-faint, #666);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            letter-spacing: 0.5px;
        }
        .mu-player-controls {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        /* Botones de control reusan .button Win98 — solo damos forma circular
           a los aux con clase .mu-btn. */
        .mu-btn {
            min-width: 32px;
            min-height: 32px;
            padding: 0;
            font-size: 14px;
        }
        .mu-btn.primary {
            min-width: 40px;
            min-height: 40px;
            font-size: 16px;
        }
        /* Visualmente sugerir que el mini-player es tappable. */
        .mu-player-info, .mu-player-thumb {
            cursor: pointer;
            -webkit-user-select: none;
            user-select: none;
        }

        /* ════════════════════════════════════════════════════════════
           NOW PLAYING — vista fullscreen con vinilo girando
           Estética Win98 con la paleta del tema del usuario. El splash
           del thumbnail blureado sigue presente pero atenuado, para
           que pinte sobre el fondo sin tapar la silver del tema.
           ════════════════════════════════════════════════════════════ */
        .mu-full {
            /* ── Solo el efecto LCD/glow del texto vive aquí ──
               Los FONDOS los heredamos del tema activo (var(--win-bg),
               var(--input-bg), etc.) como cualquier otra app. La
               "vibe LCD" sale del glow del acento sobre el chrome
               Win98 normal + scanlines globales sutiles. */
            --lcd:      var(--accent, #00ff88);
            --lcd-soft: color-mix(in srgb, var(--accent, #00ff88) 65%, transparent);
            --lcd-dim:  color-mix(in srgb, var(--accent, #00ff88) 35%, transparent);
            --lcd-veil: color-mix(in srgb, var(--accent, #00ff88) 5%, transparent);

            position: fixed;
            inset: 0;
            z-index: 300;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            overflow: hidden;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .mu-full.visible { transform: translateY(0); }
        /* Scanlines globales sobre toda la ventana. mix-blend-mode da
           ese tinte CRT auténtico sin tapar el texto. */
        .mu-full::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                to bottom,
                var(--lcd-veil) 0,
                var(--lcd-veil) 1px,
                transparent 1px,
                transparent 3px
            );
            mix-blend-mode: screen;
            pointer-events: none;
            z-index: 999;
        }
        /* Splash desactivado: el fondo del fullscreen usa solo el tema
           del usuario (var(--win-bg) en .mu-full). El elemento se queda
           en el DOM por si en el futuro quieres reactivarlo. */
        .mu-full-splash { display: none; }
        /* La ventana Win98 ocupa todo el viewport y vive por encima del
           splash. Body translúcido para que la paleta cale a través. */
        .mu-full-window {
            position: relative;
            z-index: 2;
            width: 100%;
            height: 100%;
            margin: 0;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            background: transparent;
            box-shadow: none;
            padding: 3px;
        }
        .mu-full-window > .title-bar { flex-shrink: 0; }
        .mu-full-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            margin: 0;
            padding: 10px 12px;
            background: transparent;
            overflow: hidden;
            gap: 10px;
        }

        /* Display sunken: el vinilo vive dentro de un panel hundido
           Win98 (como la pantalla de un reproductor). Le da volumen y
           "marco" sin tener que tocar el propio disco. */
        .mu-full-display {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            min-height: 0;
            background: var(--input-bg, #000);
            box-shadow:
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -2px -2px var(--bezel-light-2, #dfdfdf),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a);
            /* Brillo radial sutil que sugiere "pantalla de tubo". */
            background-image:
                radial-gradient(ellipse at center, rgba(0,0,0,0) 40%, rgba(0,0,0,0.35) 100%);
            position: relative;
            overflow: hidden;
        }
        /* Scanlines retro encima del display. */
        .mu-full-display::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                repeating-linear-gradient(
                    to bottom,
                    rgba(255,255,255,0.025) 0,
                    rgba(255,255,255,0.025) 1px,
                    transparent 1px,
                    transparent 3px
                );
            pointer-events: none;
        }
        .mu-vinyl-wrap {
            width: min(72vw, 300px);
            max-height: 100%;
            aspect-ratio: 1;
            position: relative;
            filter: drop-shadow(0 10px 18px rgba(0,0,0,0.65));
        }
        .mu-vinyl {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background:
                repeating-radial-gradient(circle at center,
                    rgba(255,255,255,0.04) 0,
                    rgba(255,255,255,0.04) 1px,
                    transparent 1px,
                    transparent 5px),
                radial-gradient(circle at center,
                    #1f1f1f 0%,
                    #0a0a0a 70%,
                    #050505 100%);
            animation: mu-spin 6s linear infinite;
        }
        .mu-vinyl.paused { animation-play-state: paused; }
        @keyframes mu-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        .mu-vinyl::after {
            content: '';
            position: absolute;
            inset: 6px;
            border-radius: 50%;
            box-shadow:
                inset 12px -20px 30px rgba(255,255,255,0.04),
                inset -6px 8px 18px rgba(0,0,0,0.6);
            pointer-events: none;
        }
        /* Etiqueta central — círculo con el thumbnail (mqdefault, 16:9
           sin letterbox). 36% del diámetro: tamaño clásico de etiqueta
           de vinilo, el cover del thumbnail rellena sin bandas porque
           la fuente ya viene 16:9. */
        .mu-vinyl-label {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 36%;
            height: 36%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background-color: var(--accent, #000080);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            box-shadow:
                0 0 0 2px rgba(0,0,0,0.5),
                inset 0 0 0 1px rgba(255,255,255,0.12);
            overflow: hidden;
        }
        .mu-vinyl-label.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: var(--accent-text, #fff);
        }
        .mu-vinyl-hole {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 3.2%;
            height: 3.2%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: #050505;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.18);
            z-index: 2;
        }

        /* Info — display LCD/holograma sobre fondo negro. Misma estética
           que antes (glow + scanlines + blink) pero el color de luz lo
           dicta `var(--accent)` del tema activo en vez del verde fijo.
           Las dos custom properties internas (--lcd y --lcd-soft) se
           recalculan con color-mix si el tema cambia. */
        .mu-full-info {
            --lcd:      var(--accent, #00ff88);
            --lcd-soft: color-mix(in srgb, var(--accent, #00ff88) 65%, transparent);
            --lcd-veil: color-mix(in srgb, var(--accent, #00ff88) 5%, transparent);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: var(--input-bg, #fff);
            color: var(--lcd);
            box-shadow:
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -2px -2px var(--bezel-light-2, #dfdfdf),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a);
            box-sizing: border-box;
            flex-shrink: 0;
            min-height: 50px;
            position: relative;
            overflow: hidden;
        }
        /* Scanlines retro tintadas con el acento del tema. */
        .mu-full-info::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                to bottom,
                var(--lcd-veil) 0,
                var(--lcd-veil) 1px,
                transparent 1px,
                transparent 3px
            );
            pointer-events: none;
        }
        .mu-full-info-marker {
            font-family: 'VT323', monospace;
            font-size: 24px;
            color: var(--lcd);
            text-shadow: 0 0 8px var(--lcd-soft);
            line-height: 1;
            flex-shrink: 0;
            animation: mu-blink 1s steps(2) infinite;
        }
        @keyframes mu-blink {
            from { opacity: 1; }
            to   { opacity: 0.25; }
        }
        .mu-full-info-text {
            flex: 1;
            min-width: 0;
            text-align: left;
        }
        /* Wrappers con altura fija + clipping horizontal. La altura es
           1 línea exacta (line-height × font-size). El texto vive en
           un <span> hijo inline-block que puede desbordar y se anima
           horizontalmente si excede el ancho del wrap. */
        .mu-full-title-wrap {
            font-size: clamp(18px, 5.5vw, 24px);
            line-height: 1.15;
            height: 1.15em;
            overflow: hidden;
            white-space: nowrap;
            margin: 0 0 2px;
        }
        .mu-full-artist-wrap {
            font-size: clamp(13px, 3.8vw, 16px);
            line-height: 1.3;
            height: 1.3em;
            overflow: hidden;
            white-space: nowrap;
        }
        /* Track: contenedor que se anima horizontalmente. En reposo es
           inline-block con un único hijo (el span del texto). Cuando
           el JS detecta overflow añade .marquee y entonces pasa a
           inline-flex con un gap entre las dos copias del texto. */
        .mu-full-title-track,
        .mu-full-artist-track {
            display: inline-block;
            will-change: transform;
        }
        .mu-full-title,
        .mu-full-artist {
            display: inline-block;
            font-family: 'VT323', monospace;
            letter-spacing: 1px;
            text-shadow: 0 0 6px var(--lcd-soft);
            line-height: inherit;
        }
        .mu-full-title  { color: var(--lcd); }
        .mu-full-artist { color: var(--lcd-soft); }

        /* Marquee continuo tipo ticker: el track recorre una distancia
           igual a (ancho del texto + gap), llevando el clon a la
           posición donde estaba el original → loop sin "vuelta atrás",
           hay un espacio en blanco entre cada pasada del título. */
        .mu-full-title-wrap.marquee .mu-full-title-track,
        .mu-full-artist-wrap.marquee .mu-full-artist-track {
            display: inline-flex;
            gap: 3em;
            animation: mu-marquee var(--mu-marquee-duration, 12s) linear infinite;
        }
        @keyframes mu-marquee {
            from { transform: translateX(0); }
            to   { transform: translateX(calc(-1 * var(--mu-marquee-cycle, 100%))); }
        }

        /* Progress bar tipo cassette deck: barra hundida con relleno
           accent + tiempos VT323 a los lados. */
        .mu-full-progress-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 4px;
            flex-shrink: 0;
        }
        .mu-full-time {
            font-family: 'VT323', monospace;
            font-size: 14px;
            color: var(--text, #000);
            font-variant-numeric: tabular-nums;
            min-width: 38px;
            text-align: center;
        }
        .mu-full-progress {
            flex: 1;
            height: 14px;
            background: var(--input-bg, #fff);
            box-shadow:
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -2px -2px var(--bezel-light-2, #dfdfdf),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a);
            position: relative;
            overflow: hidden;
            padding: 2px;
            box-sizing: border-box;
            cursor: pointer;
            touch-action: none;   /* permite arrastrar para buscar sin scrollear */
        }
        .mu-full-progress-fill {
            height: 100%;
            width: 0;
            background:
                linear-gradient(
                    90deg,
                    var(--accent, #000080) 0%,
                    var(--accent-deep, var(--accent, #1084d0)) 100%
                );
            transition: width 0.25s linear;
        }

        /* Controles LCD: dark + glow accent.
           98.css mete colores hardcoded en .button, hay que override-arlos
           con !important. El text-shadow `0 0 #222` lo borra con un glow
           del color del tema. */
        .mu-full-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            padding: 4px 0 2px;
        }
        /* Botones Win98 normales del tema — sin overrides de fondo.
           Solo controlamos tamaño y centrado del icono SVG/CSS. */
        .mu-full-btn {
            min-width: 60px;
            min-height: 52px;
            padding: 0 12px;
            font-size: 20px;
            font-family: inherit;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text, #000);
        }
        .mu-full-btn.primary {
            min-width: 88px;
            min-height: 60px;
            font-size: 26px;
        }

        /* ── Iconos play/pausa dibujados con CSS ──
           Evita la lotería de renderizado de ⏸/▶ como emoji con fondo
           en algunos dispositivos. Forma siempre centrada y un solo
           color (currentColor) que sigue al `color` del botón. */
        .mu-icon-play,
        .mu-icon-pause {
            display: inline-block;
            color: inherit;
            line-height: 0;
        }
        .mu-icon-pause { display: none; }
        .is-playing > .mu-icon-play   { display: none; }
        .is-playing > .mu-icon-pause  { display: inline-flex; }

        /* Triángulo play: CSS borders. */
        #mu-toggle .mu-icon-play {
            width: 0; height: 0;
            border-style: solid;
            border-width: 6px 0 6px 10px;
            border-color: transparent transparent transparent currentColor;
            margin-left: 2px;            /* compensación óptica del triángulo */
        }
        #mu-full-toggle .mu-icon-play {
            width: 0; height: 0;
            border-style: solid;
            border-width: 11px 0 11px 18px;
            border-color: transparent transparent transparent currentColor;
            margin-left: 4px;            /* compensación óptica */
        }

        /* Dos barras de pausa: pseudo-elementos. */
        .mu-icon-pause {
            align-items: center;
            justify-content: center;
        }
        .mu-icon-pause::before,
        .mu-icon-pause::after {
            content: '';
            display: block;
            background: currentColor;
        }
        #mu-toggle .mu-icon-pause::before,
        #mu-toggle .mu-icon-pause::after {
            width: 3px;
            height: 11px;
            margin: 0 2px;
        }
        #mu-full-toggle .mu-icon-pause::before,
        #mu-full-toggle .mu-icon-pause::after {
            width: 6px;
            height: 22px;
            margin: 0 3px;
        }

        /* Tiempo del progreso con glow LCD (sigue siendo "pantalla"). */
        .mu-full-time {
            color: var(--lcd) !important;
            text-shadow: 0 0 5px var(--lcd-soft);
        }
        /* Fila de acciones secundarias: shuffle + añadir. Icon-only. */
        .mu-full-extras {
            display: flex;
            justify-content: center;
            align-items: stretch;
            gap: 8px;
            flex-shrink: 0;
            padding: 2px 0;
        }
        .mu-full-extra {
            flex: 0 0 auto;
            width: 56px;
            min-height: 36px;
            padding: 0;
            font-family: inherit;
            color: var(--text, #000);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .mu-full-extra svg {
            width: 22px;
            height: 22px;
            display: block;
        }
        /* Estado "pulsado" del shuffle — bezel invertido como botón Win98
           apretado, para marcar que el modo aleatorio está activo. */
        .mu-full-extra.is-on {
            box-shadow:
                inset -1px -1px var(--bezel-light-2, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-1, #dfdfdf),
                inset  2px  2px var(--bezel-dark-2, grey) !important;
            color: var(--accent, #000080);
        }

        /* Footer: chevron-down ancho completo que minimiza el fullscreen. */
        .mu-full-footer {
            flex-shrink: 0;
            display: flex;
            margin: 0 1px 1px;
            padding-bottom: env(safe-area-inset-bottom);
        }
        .mu-full-footer-btn {
            flex: 1;
            min-height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text, #000);
            font-family: inherit;
        }
        .mu-full-footer-btn svg {
            width: 22px;
            height: 22px;
            display: block;
        }

        /* ── MODALES WIN98 reutilizables ──
           Overlay + ventana centrada con title-bar + window-body. Pensado
           como bottom-sheet en móvil pero usando estructura Win98. */
        /* ════════════════════════════════════════════════════════════
           LOCK SCREEN — modo reposo del reproductor.
           Estructura: overlay full-viewport con vinilo centrado,
           scanlines LCD y hint deslizable. El opening "mezcla" el
           reproductor con el negro vía transición de opacity sobre
           todo el overlay (su contenido aparece al mismo tiempo que
           el fondo negro tapa el fullscreen subyacente).
           ════════════════════════════════════════════════════════════ */
        /* ── Lock screen MINIMALISTA ── (optimizado para batería)
           Fondo negro puro, sin filtros, sin animaciones, sin
           sombras, sin transitions. Solo el texto del swipe. */
        .mu-lock {
            position: fixed;
            inset: 0;
            z-index: 350;
            display: none;
            align-items: center;
            justify-content: center;
            background: #000;
            color: #999;
            -webkit-tap-highlight-color: transparent;
            contain: strict;
        }
        .mu-lock.visible { display: flex; }

        /* Tier 1 #1 — content-visibility: skip de render del resto de
           la UI mientras el lock cubre todo. El browser puede saltar
           layout/paint/composite del subtree. Solo aplica a partes
           que SI O SI están tapadas por el lock. */
        body.lock-active #mu-list,
        body.lock-active #mu-player,
        body.lock-active #mu-widget,
        body.lock-active #mu-full {
            content-visibility: hidden;
        }

        /* Ahorro batería: animaciones del fullscreen subyacente pausadas. */
        .mu-full.behind-lock,
        .mu-full.behind-lock *,
        .mu-full.behind-lock *::before,
        .mu-full.behind-lock *::after {
            animation-play-state: paused !important;
        }

        /* Hint del swipe: AMOLED-friendly.
           - color: gris medio #999 → en AMOLED consume ~50% menos por
             píxel iluminado que blanco puro. Sigue legible sobre fondo
             negro.
           - font-size 12px → menos píxeles encendidos en total. */
        .mu-lock-hint {
            position: absolute;
            left: 0; right: 0;
            bottom: max(28px, env(safe-area-inset-bottom) + 28px);
            text-align: center;
            font-family: 'VT323', monospace;
            font-size: 12px;
            letter-spacing: 1.5px;
            color: #999;
            pointer-events: none;
            white-space: nowrap;
        }

        .mu-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            /* z-index 400 → por encima del fullscreen (z 300) para que
               cualquier modal abierto desde el reproductor se vea sobre
               él en lugar de quedar tapado. */
            z-index: 400;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        /* Icono SVG opcional en el title-bar del modal — hereda el color
           del titlebar-text y se alinea verticalmente con el texto. */
        .mu-modal .title-bar-text {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .mu-modal .title-bar-text svg {
            width: 14px;
            height: 14px;
            display: block;
            color: var(--titlebar-text, #fff);
        }
        .mu-modal-backdrop[hidden] { display: none !important; }
        .mu-modal {
            width: 100%;
            max-width: 460px;
            max-height: 90vh;
            margin-bottom: max(8px, env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
        }
        .mu-modal > .title-bar { flex-shrink: 0; }
        .mu-modal > .window-body {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 10px 12px;
            font-size: 12px;
            background: var(--win-body-bg, var(--win-bg, silver));
            color: var(--text, #000);
        }
        .mu-modal .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--bezel-dark-2, grey);
        }
        .mu-modal .modal-actions .button { min-height: 28px; min-width: 70px; }
        .mu-modal label {
            display: block;
            font-size: 11px;
            color: var(--text, #000);
            margin: 8px 0 3px;
        }
        .mu-modal input[type="text"],
        .mu-modal input[type="url"],
        .mu-modal input[type="search"] {
            width: 100%;
            box-sizing: border-box;
            font-size: 16px;       /* evita auto-zoom iOS */
            font-family: inherit;
        }
        .mu-modal .modal-msg {
            margin: 0 0 8px;
            font-size: 12px;
            line-height: 1.4;
        }
        .mu-modal .modal-err {
            color: var(--error-text, #c00);
            background: var(--warning-bg, #ffeeee);
            border: 1px solid var(--error-text, #c00);
            padding: 4px 6px;
            font-size: 11px;
            margin: 6px 0;
        }
        .mu-modal .modal-loading {
            text-align: center;
            padding: 16px;
            color: var(--text-faint, #666);
            font-size: 12px;
        }
        /* Lista de items dentro de modal (para tracks, usuarios, etc.) */
        .mu-modal-list {
            border: 2px inset var(--bezel-dark-2, grey);
            background: var(--input-bg, #fff);
            padding: 2px;
            margin-top: 4px;
            max-height: 40vh;
            overflow-y: auto;
        }
        .mu-modal-list-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            font-size: 12px;
            border-bottom: 1px dotted var(--text-faint, #aaa);
            color: var(--input-text, var(--text, #000));
        }
        .mu-modal-list-item:last-child { border-bottom: none; }
        .mu-modal-list-item:active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .mu-modal-list-item .item-icon {
            width: 24px; height: 24px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            object-fit: cover;
            image-rendering: pixelated;
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            font-size: 13px;
            font-weight: bold;
        }
        .mu-modal-list-item .item-main {
            flex: 1; min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mu-modal-list-item .item-action {
            flex-shrink: 0;
            font-size: 11px;
            min-height: 24px;
        }

        /* Botón "+ Nueva playlist" en la cabecera de la lista. */
        .mu-newpl-bar {
            display: flex;
            gap: 6px;
            padding: 4px 6px;
            background: var(--btn-bg, silver);
            border-bottom: 1px solid var(--bezel-dark-2, grey);
            flex-shrink: 0;
        }
        .mu-newpl-bar .button {
            flex: 1;
            min-height: 26px;
            font-size: 11px;
        }

        /* Barra del filtro de canciones de las playlists. */
        .mu-filter-bar {
            padding: 4px 6px;
            background: var(--btn-bg, silver);
            border-bottom: 1px solid var(--bezel-dark-2, grey);
            flex-shrink: 0;
        }
        .mu-filter-bar input {
            width: 100%;
            box-sizing: border-box;
            min-height: 26px;
            font-size: 12px;
        }
        .mu-filter-empty {
            padding: 14px 10px;
            text-align: center;
            font-size: 12px;
            color: var(--text-faint, #666);
        }

        /* iframe del reproductor YouTube — solo necesitamos el audio. */
        #yt-host {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
        }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">

<div class="window mh-window" id="musicaWindow">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Música - <?= htmlspecialchars($userLabel) ?></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize" disabled></button>
            <button aria-label="Close" onclick="window.location.href='../../mobile.php';"></button>
        </div>
    </div>
    <div class="window-body">

        <!-- Header: icono música + título + contador de playlists -->
        <div class="mh-userbar">
            <div class="mh-userbar-avatar">
                <?php if ($userImg): ?>
                    <img src="<?= htmlspecialchars($userImg) ?>" alt="">
                <?php else: ?>
                    <span><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>
                <?php endif; ?>
            </div>
            <div class="mh-userbar-text">
                <div class="mh-userbar-greeting">Tu música</div>
                <div class="mh-userbar-name"><?= htmlspecialchars($userLabel) ?></div>
            </div>
            <div>
                <div class="mh-userbar-clock" id="mu-pl-count">—</div>
                <div class="mh-userbar-date">playlists</div>
            </div>
        </div>

        <!-- Botonera arriba para acciones globales -->
        <div class="mu-newpl-bar">
            <button class="button" id="mu-btn-new-pl" type="button">+ Nueva playlist</button>
            <button class="button" id="mu-btn-import" type="button">⤓ Importar</button>
            <button class="button" id="mu-btn-search" type="button">Buscar</button>
        </div>

        <!-- Filtro: busca entre las canciones de las playlists. -->
        <div class="mu-filter-bar">
            <input type="text" id="mu-pl-filter" autocomplete="off"
                   placeholder="Filtrar canciones de las playlists…">
        </div>

        <!-- Lista de playlists colapsables -->
        <div class="mh-panel mu-list" id="mu-list">
            <div class="mh-empty"><span class="mh-empty-icon">⏳</span>Cargando…</div>
        </div>

        <!-- Status bar Win98 al pie -->
        <div class="mh-statusbar">
            <a href="../../mobile.php">‹ Menú</a>
        </div>

    </div>
</div>

<!-- Mini reproductor sticky -->
<div class="mu-player" id="mu-player">
    <div class="mu-player-thumb" id="mu-thumb"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></div>
    <div class="mu-player-info">
        <div class="mu-player-title" id="mu-now-title">—</div>
        <div class="mu-player-artist" id="mu-now-artist">—</div>
    </div>
    <div class="mu-player-controls">
        <button class="button mu-btn" id="mu-prev" type="button" aria-label="Anterior">⏮</button>
        <button class="button default mu-btn primary" id="mu-toggle" type="button" aria-label="Play/Pausa">
            <span class="mu-icon-play"  aria-hidden="true"></span>
            <span class="mu-icon-pause" aria-hidden="true"></span>
        </button>
        <button class="button mu-btn" id="mu-next" type="button" aria-label="Siguiente">⏭</button>
    </div>
</div>

<!-- ─── NOW PLAYING fullscreen ────────────────────────────────────
     Ventana Win98 a pantalla completa con vinilo en panel hundido,
     progress bar tipo cassette deck y status bar al pie. -->
<div class="mu-full" id="mu-full" aria-hidden="true">
    <div class="mu-full-splash" id="mu-full-splash"></div>
    <div class="window mu-full-window">
        <div class="title-bar">
            <div class="title-bar-text"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Melon Player — <span id="mu-full-tb-pl">Reproduciendo</span></div>
            <div class="title-bar-controls">
                <button aria-label="Close" id="mu-full-close" type="button"></button>
            </div>
        </div>
        <div class="window-body mu-full-body">

            <!-- Display sunken con el vinilo -->
            <div class="mu-full-display">
                <div class="mu-vinyl-wrap">
                    <div class="mu-vinyl" id="mu-vinyl">
                        <div class="mu-vinyl-label empty" id="mu-vinyl-label"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></div>
                        <div class="mu-vinyl-hole"></div>
                    </div>
                </div>
            </div>

            <!-- Panel de info con LCD verde "tipo CRT".
                 Estructura para marquee continuo tipo ticker:
                 wrap (clipping fijo) → track (animable) → span (texto).
                 Si hay overflow, el JS clona el span dentro del track
                 y la animación scrollea el track una distancia
                 (texto + gap), creando un loop visual sin "vuelta atrás". -->
            <div class="mu-full-info">
                <div class="mu-full-info-marker"><img src="../../assets/img/appIcons/songIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></div>
                <div class="mu-full-info-text">
                    <div class="mu-full-title-wrap" id="mu-full-title-wrap">
                        <span class="mu-full-title-track" id="mu-full-title-track">
                            <span class="mu-full-title" id="mu-full-title">—</span>
                        </span>
                    </div>
                    <div class="mu-full-artist-wrap" id="mu-full-artist-wrap">
                        <span class="mu-full-artist-track" id="mu-full-artist-track">
                            <span class="mu-full-artist" id="mu-full-artist">—</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Progress bar tipo deck con tiempo a los lados -->
            <div class="mu-full-progress-row">
                <span class="mu-full-time" id="mu-full-time-cur">0:00</span>
                <div class="mu-full-progress" id="mu-full-progress">
                    <div class="mu-full-progress-fill" id="mu-full-progress-fill"></div>
                </div>
                <span class="mu-full-time" id="mu-full-time-tot">0:00</span>
            </div>

            <!-- Controles grandes -->
            <div class="mu-full-controls">
                <button class="button mu-full-btn" id="mu-full-prev" type="button" aria-label="Anterior">⏮</button>
                <button class="button default mu-full-btn primary" id="mu-full-toggle" type="button" aria-label="Play/Pausa">
                    <span class="mu-icon-play"  aria-hidden="true"></span>
                    <span class="mu-icon-pause" aria-hidden="true"></span>
                </button>
                <button class="button mu-full-btn" id="mu-full-next" type="button" aria-label="Siguiente">⏭</button>
            </div>
            <!-- Acciones secundarias: shuffle + añadir canción a otra playlist.
                 SVG inline con stroke=currentColor → monocromo, sin pastilla
                 de emoji, hereda el color del tema. -->
            <div class="mu-full-extras">
                <button class="button mu-full-extra" id="mu-full-shuffle" type="button" aria-label="Aleatorio" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="16 3 21 3 21 8"/>
                        <line x1="4" y1="20" x2="21" y2="3"/>
                        <polyline points="21 16 21 21 16 21"/>
                        <line x1="15" y1="15" x2="21" y2="21"/>
                        <line x1="4" y1="4" x2="9" y2="9"/>
                    </svg>
                </button>
                <button class="button mu-full-extra" id="mu-full-add" type="button" aria-label="Añadir a playlist">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="12" y1="5"  x2="12" y2="19"/>
                        <line x1="5"  y1="12" x2="19" y2="12"/>
                    </svg>
                </button>
                <button class="button mu-full-extra" id="mu-full-lock" type="button" aria-label="Bloquear pantalla">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="11" width="16" height="10" rx="1.5"/>
                        <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                    </svg>
                </button>
            </div>
        </div>
        <!-- Footer: chevron-down que minimiza el fullscreen y vuelve a la
             vista de playlists. NO navega fuera del reproductor. -->
        <div class="mu-full-footer">
            <button class="button mu-full-footer-btn" id="mu-full-menu" type="button" aria-label="Cerrar reproductor">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- ─── LOCK SCREEN ────────────────────────────────────────────────
     MISMA distribución vertical que el .mu-full: title-bar arriba,
     body con display/info/progress/controls/extras, footer abajo.
     Diferencia: los huecos del title-bar, controls, extras y footer
     son DIVS VACÍOS — reservan el espacio pero no muestran botones.
     Los colores se sustituyen por la paleta dark/LCD. -->
<!-- Lock screen MINIMALISTA: solo fondo negro + texto del swipe.
     Quitamos vinyl, info LCD, progress bar, filtro CRT (scanlines +
     viñeta) y todas las animaciones (rotación vinyl, mu-blink, mu-
     marquee). Objetivo: máximo ahorro de batería — el browser entra
     en idle mode (sin repaint, sin compositor activo) hasta que el
     usuario hace swipe. La música del YouTube iframe sigue sonando
     porque vive en su propio contexto. -->
<div class="mu-lock" id="mu-lock" aria-hidden="true">
    <div class="mu-lock-hint" id="mu-lock-hint">↑  Desliza para desbloquear</div>
</div>

<!-- Host de modales (se rellena dinámicamente desde JS) -->
<div id="mu-modal-host"></div>

<!-- Iframe oculto donde vive el player de YouTube -->
<div id="yt-host"><div id="yt-iframe"></div></div>


<script>
/* ─── Modo embebido ──────────────────────────────────────────────
   Cuando esta página vive dentro del shell SPA (mobile.php),
   delegamos toda la reproducción al motor del padre — el iframe de
   YouTube y el mini-player del shell controlan el audio, aquí solo
   gestionamos la UI de playlists. Standalone (acceso directo) sigue
   funcionando con su propio motor por compatibilidad. */
var EMBEDDED = (function(){ try { return window.parent !== window; } catch (_) { return false; } })();
var SHELL    = EMBEDDED ? window.parent.MuShell : null;

/* Ocultamos el mini-player local + el iframe YT local cuando estamos
   embebidos: el shell ya muestra su propio mini-player y mantiene la
   reproducción. */
if (EMBEDDED) {
    var _localPlayer = document.getElementById('mu-player');
    if (_localPlayer) _localPlayer.style.display = 'none';
    var _localYt = document.getElementById('yt-host');
    if (_localYt) _localYt.style.display = 'none';
    /* La vista grande del reproductor y la pantalla de bloqueo viven
       en musica-mobile, pero como no tenemos YT_PLAYER local quedan
       inservibles. Las ocultamos por completo. */
    ['mu-full', 'mu-lock'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    /* Y la status-bar del fullscreen "‹ Menú" envía postMessage en
       lugar de navegar para volver al launcher. */
    document.addEventListener('click', function(e){
        var menuLink = e.target && e.target.closest && e.target.closest('.mh-statusbar a[href*="../../mobile.php"]');
        if (!menuLink) return;
        e.preventDefault();
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {}
    }, true);

    /* Nos suscribimos al shell para recibir 'mushell:track' cada vez
       que cambia el track activo (next/prev/auto-advance) → reaplicamos
       el highlight visual en la lista. */
    try { window.parent.postMessage({ type: 'mushell:subscribe' }, '*'); } catch (_) {}
    window.addEventListener('message', function(ev){
        var d = ev.data || {};
        if (d.type !== 'mushell:track' || !d.track || !d.track.videoId) return;
        findAndHighlight(d.track.videoId, d.plName);
    });
}

/* Localiza el par (pi, ti) que mejor describe el track actual del shell.
   Prefiere la playlist cuyo nombre coincide con plName si viene; cae al
   primer match por videoId si no. */
function findAndHighlight(videoId, plName) {
    if (!videoId) return;
    function search(filterByName){
        for (var pi = 0; pi < PLAYLISTS.length; pi++) {
            var pl = PLAYLISTS[pi];
            if (filterByName && plName && pl.name !== plName) continue;
            var tracks = (pl && pl.tracks) || [];
            for (var ti = 0; ti < tracks.length; ti++) {
                if (tracks[ti] && tracks[ti].videoId === videoId) {
                    CUR_PL_IDX = pi; CUR_IDX = ti; QUEUE = tracks.slice();
                    applyPlayingHighlight(pi, ti);
                    return true;
                }
            }
        }
        return false;
    }
    if (plName && search(true)) return;
    search(false);
}

/* ─── Estado ────────────────────────────────────────────────────── */
var API_MUSIC = '../../assets/music/api.php';
var ME_KEY    = <?= json_encode($userKey) ?>;
var ME_LABEL  = <?= json_encode($userLabel) ?>;
var PLAYLISTS = [];        /* respuesta del API */
var MU_FILTER = '';        /* texto del filtro de canciones de las playlists */
var USERS     = <?= json_encode($usersForJs, JSON_UNESCAPED_SLASHES) ?>;
                            /* todos los usuarios con sus avatares resueltos
                               (server-side, no fetch) para pintar collabs y
                               "added by" al instante. */
var CUR_PL_IDX = -1;       /* índice de la playlist actualmente reproduciéndose */
var QUEUE     = [];        /* tracks de la playlist en reproducción */
var CUR_IDX   = -1;        /* índice del track actual en QUEUE */
var YT_PLAYER = null;      /* instancia YT.Player (no llamarla YT — choca con window.YT) */
var YT_READY  = false;
var SHUFFLE_ON = false;    /* modo aleatorio: next/prev eligen track al azar */
/* Música del perfil — la cargamos en paralelo a las playlists para
   poder mostrar la estrella de reseña en cualquier track cuyo videoId
   ya tengas reseñado en tu lista de música. Solo items PROPIOS. */
var MY_PROFILE_MUSIC   = [];
var MY_REVIEWS_BY_YTID = {}; /* ytId → { musicId, title, stars, comment, reviewedAt } */

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function fmtDur(sec) {
    sec = parseInt(sec, 10) || 0;
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
}
/* Duración total formateada como "1h 23m" o "23m" o "" si la playlist
   no tiene durations cargadas. */
function fmtDurTotal(tracks) {
    var total = 0, has = false;
    (tracks || []).forEach(function(t){ if (t.duration) { total += +t.duration; has = true; } });
    if (!has) return '';
    var h = Math.floor(total / 3600);
    var m = Math.floor((total % 3600) / 60);
    return (h > 0 ? h + 'h ' : '') + m + 'm';
}

/* Lookup de avatar de usuario por LABEL (que es lo que viene en
   track.addedBy y pl.sharedLabel). Devuelve la url del avatar o null. */
function userAvatarByLabel(label) {
    if (!label) return null;
    for (var i = 0; i < USERS.length; i++) {
        if (USERS[i].label === label) return USERS[i].img || null;
    }
    return null;
}
function userAvatarByKey(key) {
    if (!key) return null;
    for (var i = 0; i < USERS.length; i++) {
        if (USERS[i].key === key) return USERS[i].img || null;
    }
    return null;
}
function userLabelByKey(key) {
    if (!key) return '';
    for (var i = 0; i < USERS.length; i++) {
        if (USERS[i].key === key) return USERS[i].label;
    }
    return key;
}

/* ─── Carga playlists ───────────────────────────────────────────── */
function loadPlaylists() {
    /* Antes de recargar, capturamos qué playlists estaban abiertas
       (clase .open) por su `pl.id`. Tras renderPlaylists() las
       restauramos — sin esto, añadir una canción o importar una
       playlist provocaba que TODAS se colapsaran y el usuario tenía
       que volver a abrirlas (= "salir y volver a entrar"). */
    var openIds = [];
    document.querySelectorAll('.mu-playlist.open').forEach(function(el){
        var idx = parseInt(el.dataset.plIdx, 10);
        if (PLAYLISTS && PLAYLISTS[idx] && PLAYLISTS[idx].id) {
            openIds.push(String(PLAYLISTS[idx].id));
        }
    });
    return fetch(API_MUSIC + '?action=get-playlists', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            PLAYLISTS = Array.isArray(d) ? d : [];
            document.getElementById('mu-pl-count').textContent =
                PLAYLISTS.length + ' playlist' + (PLAYLISTS.length === 1 ? '' : 's');
            renderPlaylists();
            /* Re-aplica la clase .open a las playlists que ya lo estaban
               antes del repaint (matching por pl.id, no por índice — el
               orden puede cambiar al añadir una nueva playlist). */
            if (openIds.length) {
                var openSet = {};
                openIds.forEach(function(id){ openSet[id] = true; });
                PLAYLISTS.forEach(function(pl, i){
                    if (openSet[String(pl.id)]) {
                        var el = document.querySelector('.mu-playlist[data-pl-idx="' + i + '"]');
                        if (el) el.classList.add('open');
                    }
                });
            }
            /* Embebida → restaurar highlight desde el estado del shell.
               Localizamos el track actual buscando su videoId en las
               PLAYLISTS recién cargadas. */
            restoreHighlightFromShell();
        })
        .catch(function(){
            document.getElementById('mu-list').innerHTML =
                '<div class="mh-empty"><span class="mh-empty-icon">⚠️</span>Error cargando playlists</div>';
        });
}

/* Cuando estamos embebidos pedimos el track actual al shell y
   buscamos su par (pl-idx, tr-idx) en PLAYLISTS para resaltar.
   Si el shell no está, o no hay track sonando, no hace nada. */
function restoreHighlightFromShell() {
    if (!SHELL || typeof SHELL.getState !== 'function') return;
    var st = null;
    try { st = SHELL.getState(); } catch (_) {}
    if (!st || !st.track || !st.track.videoId) return;
    findAndHighlight(st.track.videoId, st.plName);
}

function renderPlaylists() {
    var listEl = document.getElementById('mu-list');
    if (!PLAYLISTS.length) {
        listEl.innerHTML = '<div class="mh-empty"><span class="mh-empty-icon"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>No tienes playlists todavía</div>';
        return;
    }
    /* Filtro: oculta canciones que no coinciden por título/artista. El
       índice `ti` se mantiene REAL (data-tr-idx) → al darle play se sigue
       encolando la playlist completa. Las playlists sin coincidencias se
       ocultan; las que sí tienen, se muestran ya abiertas. */
    var q = (MU_FILTER || '').trim().toLowerCase();
    var filtering = q.length > 0;
    var anyShown = false;
    var html = '';
    PLAYLISTS.forEach(function(pl, i){
        var tracks = pl.tracks || [];
        /* Pre-filtra los índices de tracks que coinciden. */
        var matchIdx = [];
        tracks.forEach(function(tr, ti){
            if (!filtering) { matchIdx.push(ti); return; }
            var hay = ((tr.title || '') + ' ' + (tr.artist || '')).toLowerCase();
            if (hay.indexOf(q) !== -1) matchIdx.push(ti);
        });
        if (filtering && !matchIdx.length) return;   /* playlist sin matches */
        anyShown = true;
        var collabs = pl.collaborators || [];
        var isOwn = !pl.sharedFrom;
        /* Meta: nº canciones · duración total · "compartido por X" */
        var metaParts = [tracks.length + ' canción' + (tracks.length === 1 ? '' : 'es')];
        var dur = fmtDurTotal(tracks);
        if (dur) metaParts.push(dur);
        if (pl.sharedLabel) metaParts.push('compartido por ' + esc(pl.sharedLabel));
        var meta = metaParts.join(' · ');

        /* Avatares de colaboradores. Cuando es mi playlist con collabs:
           muestro los avatares de los demás. Cuando es compartida conmigo:
           muestro el avatar del owner + el resto de collabs (sin yo). */
        var avatarsHtml = '';
        var avatarsToShow = [];
        if (isOwn) {
            collabs.forEach(function(ck){
                if (ck !== ME_KEY) avatarsToShow.push(ck);
            });
        } else {
            /* owner del compartido — pl.sharedFrom es su user_key */
            if (pl.sharedFrom) avatarsToShow.push(pl.sharedFrom);
            collabs.forEach(function(ck){
                if (ck !== ME_KEY && ck !== pl.sharedFrom) avatarsToShow.push(ck);
            });
        }
        if (avatarsToShow.length) {
            avatarsHtml = '<div class="mu-pl-collabs">';
            avatarsToShow.slice(0, 4).forEach(function(ck){
                var img = userAvatarByKey(ck);
                var lbl = userLabelByKey(ck);
                avatarsHtml += img
                    ? '<img class="mu-pl-collab-av" src="' + esc(img) + '" alt="' + esc(lbl) + '" title="' + esc(lbl) + '">'
                    : '<div class="mu-pl-collab-av mu-pl-collab-av-ph" title="' + esc(lbl) + '">' + esc(lbl.substring(0, 1)) + '</div>';
            });
            if (avatarsToShow.length > 4) {
                avatarsHtml += '<div class="mu-pl-collab-av mu-pl-collab-more">+' + (avatarsToShow.length - 4) + '</div>';
            }
            avatarsHtml += '</div>';
        }

        var canPlay   = (pl.tracks || []).length > 0;
        var playBtnHtml = '<button class="button mu-pl-play" type="button"' +
                            ' data-pl-idx="' + i + '"' +
                            (canPlay ? '' : ' disabled') +
                            ' aria-label="Reproducir playlist">' +
                            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                                '<polygon points="6 4 20 12 6 20"/>' +
                            '</svg>' +
                          '</button>';
        html += '<div class="mu-playlist' + (filtering ? ' open' : '') + '" data-pl-idx="' + i + '">' +
                  '<div class="mu-pl-head">' +
                    '<div class="mu-pl-info">' +
                      '<div class="mu-pl-name">' + esc(pl.name) + '</div>' +
                      '<div class="mu-pl-meta">' + meta + '</div>' +
                    '</div>' +
                    avatarsHtml +
                    playBtnHtml +
                    '<div class="mu-pl-arrow">›</div>' +
                  '</div>' +
                  '<div class="mu-tracks">';

        /* Si la playlist tiene collabs, mostramos "added by" en cada track. */
        var showAddedBy = avatarsToShow.length > 0 || isOwn === false;
        matchIdx.forEach(function(ti){
            var tr = tracks[ti];
            var thumbUrl = tr.videoId ? 'https://i.ytimg.com/vi/' + esc(tr.videoId) + '/mqdefault.jpg' : '';
            var addedByHtml = '';
            if (showAddedBy && tr.addedBy) {
                var addImg = userAvatarByLabel(tr.addedBy);
                addedByHtml = '<div class="mu-track-addedby" title="Añadido por ' + esc(tr.addedBy) + '">' +
                              (addImg
                                ? '<img class="mu-track-addedby-av" src="' + esc(addImg) + '" alt="">'
                                : '<span class="mu-track-addedby-av mu-track-addedby-av-ph">' + esc(tr.addedBy.substring(0,1)) + '</span>') +
                              '</div>';
            }
            /* Las estrellas de reseña NO se muestran en la app de música
               (la calificación vive en el perfil). Sigue el editor de
               reseña al añadir al perfil, pero los tracks no las pintan. */
            /* mu-track-title-clickable: zona del título que abre el
               album viewer. mu-track-album: aparece tras find-album
               (vacío hasta entonces; data-vid identifica el track
               para el resolver loop). */
            html += '<div class="mu-track" data-pl-idx="' + i + '" data-tr-idx="' + ti + '">' +
                      '<div class="mu-track-num">' + (ti + 1) + '</div>' +
                      (thumbUrl
                        ? '<img class="mu-track-thumb" src="' + thumbUrl + '" alt="" loading="lazy">'
                        : '<div class="mu-track-thumb mu-track-thumb-ph"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></div>') +
                      '<div class="mu-track-info">' +
                        '<div class="mu-track-title mu-track-title-clickable" data-vid="' + esc(tr.videoId || '') + '">' + esc(tr.title || tr.videoId) + '</div>' +
                        '<div class="mu-track-artist">' +
                          (tr.artist ? esc(tr.artist) : '') +
                          '<span class="mu-track-album" data-vid="' + esc(tr.videoId || '') + '"></span>' +
                        '</div>' +
                      '</div>' +
                      addedByHtml +
                      '<div class="mu-track-dur">' + (tr.duration ? fmtDur(tr.duration) : '—') + '</div>' +
                    '</div>';
        });
        html += '</div></div>';
    });
    if (filtering && !anyShown) {
        html = '<div class="mu-filter-empty">No hay canciones que coincidan con "' + esc(q) + '".</div>';
    }
    listEl.innerHTML = html;
}

/* ─── Long-press handler (delegado) ───────────────────────────────
   Mantener pulsado abre un menú contextual:
   - sobre `.mu-pl-head` → muOpenPlaylistMenu (editar, colaboradores…)
   - sobre `.mu-track`   → muOpenTrackMenu    (añadir a perfil, quitar…)
   La flag `_lpFired` bloquea el click sintético posterior para que no
   se haga toggle de la playlist ni se inicie la reproducción. */
var _lpTimer = null, _lpStartX = 0, _lpStartY = 0, _lpFired = false, _lpEl = null;
var _LP_MS = 500, _LP_TOLERANCE = 12;

function _lpStart(el, x, y, onLongPress) {
    _lpEl = el;
    _lpStartX = x;
    _lpStartY = y;
    _lpFired = false;
    el.classList.add('long-pressing');
    _lpTimer = setTimeout(function(){
        _lpTimer = null;
        _lpFired = true;
        el.classList.remove('long-pressing');
        try { navigator.vibrate && navigator.vibrate(40); } catch (_) {}
        onLongPress();
    }, _LP_MS);
}
function _lpCancel() {
    if (_lpTimer) { clearTimeout(_lpTimer); _lpTimer = null; }
    if (_lpEl)    { _lpEl.classList.remove('long-pressing'); _lpEl = null; }
}
/* Resuelve el elemento bajo el dedo/cursor y devuelve la callback que
   debe disparar el long-press. Centralizado para no duplicar la lógica
   entre touchstart y mousedown. */
function _lpResolve(target) {
    var headEl = target.closest('.mu-pl-head');
    if (headEl) {
        var pl = headEl.parentElement;
        var idx = parseInt(pl.dataset.plIdx, 10);
        return { el: headEl, cb: function(){ muOpenPlaylistMenu(idx); } };
    }
    /* Long-press sobre el nombre del álbum → menú con acciones del
       ÁLBUM (añadir el álbum entero a perfil / playlist). Va antes de
       `.mu-track` para que tenga prioridad. */
    var albumEl = target.closest('.mu-track-album');
    if (albumEl && albumEl.textContent.trim()) {
        var albumId   = albumEl.dataset.albumId || '';
        var albumName = albumEl.dataset.albumName || albumEl.textContent.trim();
        if (albumId) {
            return { el: albumEl, cb: function(){ muOpenAlbumMenu(albumId, albumName); } };
        }
    }
    var trackEl = target.closest('.mu-track');
    if (trackEl) {
        var pi = parseInt(trackEl.dataset.plIdx, 10);
        var ti = parseInt(trackEl.dataset.trIdx, 10);
        return { el: trackEl, cb: function(){ muOpenTrackMenu(pi, ti); } };
    }
    return null;
}

var listEl = document.getElementById('mu-list');
listEl.addEventListener('touchstart', function(e){
    if (!e.touches || e.touches.length !== 1) return;
    var r = _lpResolve(e.target);
    if (!r) return;
    _lpStart(r.el, e.touches[0].clientX, e.touches[0].clientY, r.cb);
}, { passive: true });
listEl.addEventListener('touchmove', function(e){
    if (!_lpTimer || !e.touches[0]) return;
    if (Math.abs(e.touches[0].clientX - _lpStartX) > _LP_TOLERANCE ||
        Math.abs(e.touches[0].clientY - _lpStartY) > _LP_TOLERANCE) {
        _lpCancel();
    }
}, { passive: true });
listEl.addEventListener('touchend',    _lpCancel);
listEl.addEventListener('touchcancel', _lpCancel);

/* Soporte mouse (testing en escritorio): mismo gesto con mousedown. */
listEl.addEventListener('mousedown', function(e){
    if (e.button !== 0) return;
    var r = _lpResolve(e.target);
    if (!r) return;
    _lpStart(r.el, e.clientX, e.clientY, r.cb);
});
listEl.addEventListener('mousemove', function(e){
    if (!_lpTimer) return;
    if (Math.abs(e.clientX - _lpStartX) > _LP_TOLERANCE ||
        Math.abs(e.clientY - _lpStartY) > _LP_TOLERANCE) {
        _lpCancel();
    }
});
listEl.addEventListener('mouseup',    _lpCancel);
listEl.addEventListener('mouseleave', _lpCancel);

/* ─── Click handlers (delegados) ───────────────────────────────── */
listEl.addEventListener('click', function(e){
    /* Si acabamos de disparar long-press, ignora el click sintético
       que viene con el touchend para no togglear la playlist. */
    if (_lpFired) { _lpFired = false; e.preventDefault(); e.stopPropagation(); return; }
    /* Botón ▶ del header — antes que el toggle del head para que el
       tap NO abra/cierre la playlist accidentalmente. */
    var playBtn = e.target.closest('.mu-pl-play');
    if (playBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (playBtn.disabled) return;
        var pi = parseInt(playBtn.dataset.plIdx, 10);
        if (PLAYLISTS[pi] && (PLAYLISTS[pi].tracks || []).length) {
            playFromPlaylist(pi, 0);     /* arranca desde el primer track */
        }
        return;
    }
    var headEl = e.target.closest('.mu-pl-head');
    if (headEl) {
        var pl = headEl.parentElement;
        pl.classList.toggle('open');
        return;
    }
    /* Tap en el título O en el nombre del álbum → abre album viewer
       (NO reproduce). Buscamos ambos antes que el genérico de track. */
    var titleEl = e.target.closest('.mu-track-title-clickable');
    var albumEl = e.target.closest('.mu-track-album');
    if (titleEl || albumEl) {
        e.preventDefault();
        e.stopPropagation();
        var trEl = (titleEl || albumEl).closest('.mu-track');
        if (!trEl) return;
        var _pi = parseInt(trEl.dataset.plIdx, 10);
        var _ti = parseInt(trEl.dataset.trIdx, 10);
        var _tr = (PLAYLISTS[_pi] && PLAYLISTS[_pi].tracks || [])[_ti];
        if (_tr) muOpenAlbumFromTrack(_tr);
        return;
    }
    var trackEl = e.target.closest('.mu-track');
    if (trackEl) {
        var pi = parseInt(trackEl.dataset.plIdx, 10);
        var ti = parseInt(trackEl.dataset.trIdx, 10);
        playFromPlaylist(pi, ti);
    }
});

function onYTState(e) {
    var btn = document.getElementById('mu-toggle');
    if (e.data === 1)      { btn.classList.add('is-playing');    setMediaPlaybackState('playing'); }
    else if (e.data === 2) { btn.classList.remove('is-playing'); setMediaPlaybackState('paused');  }
    else if (e.data === 0) nextTrack();
    /* Espeja el estado en el toggle grande del fullscreen + pausa
       la rotación del vinilo cuando no se está reproduciendo. */
    muFullSyncPlay(e.data === 1);
}

/* ─── NOW PLAYING fullscreen + LOCK ─────────────────────────────
   Timer único de progreso (500ms) que actualiza el progreso de
   ambos paneles (fullscreen + lock) — solo corre cuando al menos
   uno está visible para ahorrar batería. */
var MU_PROGRESS_T = null;

function muFullOpen() {
    if (CUR_IDX < 0) return;                     /* nada sonando: no abre */
    var el = document.getElementById('mu-full');
    el.classList.add('visible');
    el.setAttribute('aria-hidden', 'false');
    muFullRefresh();
    muProgressTimerSync();
}
function muFullClose() {
    var el = document.getElementById('mu-full');
    el.classList.remove('visible');
    el.setAttribute('aria-hidden', 'true');
    muProgressTimerSync();
}
function muProgressTimerSync() {
    /* Lock activo → STOP del timer aunque el fullscreen esté detrás.
       En el modo bloqueo no se muestra progress UI, así que es absurdo
       wake-upear cada 500ms para nada. Ahorro batería directo. */
    var lockVis = document.getElementById('mu-lock').classList.contains('visible');
    if (lockVis) { muStopProgressTimer(); return; }
    var fullVis = document.getElementById('mu-full').classList.contains('visible');
    if (fullVis) muStartProgressTimer();
    else         muStopProgressTimer();
}
function muStartProgressTimer() {
    if (MU_PROGRESS_T) return;
    MU_PROGRESS_T = setInterval(muTickProgress, 500);
    muTickProgress();
}
function muStopProgressTimer() {
    if (MU_PROGRESS_T) { clearInterval(MU_PROGRESS_T); MU_PROGRESS_T = null; }
}
function muFmt(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
}
var _muScrub = false;   /* true mientras el usuario arrastra la barra */
function muTickProgress() {
    if (!YT_READY || !YT_PLAYER) return;
    var cur = 0, tot = 0;
    try {
        cur = YT_PLAYER.getCurrentTime() || 0;
        tot = YT_PLAYER.getDuration()    || 0;
    } catch (_) {}
    var pct = tot > 0 ? Math.min(100, (cur / tot) * 100) : 0;
    var curStr = muFmt(cur), totStr = muFmt(tot);
    /* Fullscreen — durante el arrastre NO repintamos para no pisar la
       previsualización del dedo. */
    if (!_muScrub) {
        var f1 = document.getElementById('mu-full-progress-fill');
        var f2 = document.getElementById('mu-full-time-cur');
        var f3 = document.getElementById('mu-full-time-tot');
        if (f1) f1.style.width = pct + '%';
        if (f2) f2.textContent = curStr;
        if (f3) f3.textContent = totStr;
    }
    /* Lock screen */
    var l1 = document.getElementById('mu-lock-progress-fill');
    var l2 = document.getElementById('mu-lock-time-cur');
    var l3 = document.getElementById('mu-lock-time-tot');
    if (l1) l1.style.width = pct + '%';
    if (l2) l2.textContent = curStr;
    if (l3) l3.textContent = totStr;
}
/* Alias retro-compat: el resto del código aún llama Start/Stop. */
function muFullStartProgressTimer() { muStartProgressTimer(); }
function muFullStopProgressTimer()  { muStopProgressTimer();  }

/* ── Tap / arrastre sobre la barra de progreso → busca ese punto ── */
(function(){
    var bar = document.getElementById('mu-full-progress');
    if (!bar) return;
    function ratioFromX(clientX){
        var rect = bar.getBoundingClientRect();
        if (rect.width <= 0) return 0;
        var r = (clientX - rect.left) / rect.width;
        return Math.max(0, Math.min(1, r));
    }
    function preview(r){
        var fill = document.getElementById('mu-full-progress-fill');
        if (fill) fill.style.width = (r * 100) + '%';
        var tot = (YT_PLAYER && YT_PLAYER.getDuration) ? (YT_PLAYER.getDuration() || 0) : 0;
        var c1 = document.getElementById('mu-full-time-cur');
        if (c1 && tot > 0) c1.textContent = muFmt(r * tot);
    }
    function commit(r){
        if (!YT_PLAYER || !YT_PLAYER.getDuration) return;
        var tot = YT_PLAYER.getDuration() || 0;
        if (tot <= 0) return;
        try { YT_PLAYER.seekTo(r * tot, true); } catch (_) {}
    }
    bar.addEventListener('pointerdown', function(e){
        _muScrub = true;
        try { bar.setPointerCapture(e.pointerId); } catch (_) {}
        preview(ratioFromX(e.clientX));
        e.preventDefault();
    });
    bar.addEventListener('pointermove', function(e){
        if (!_muScrub) return;
        preview(ratioFromX(e.clientX));
    });
    function end(e){
        if (!_muScrub) return;
        _muScrub = false;
        try { bar.releasePointerCapture(e.pointerId); } catch (_) {}
        commit(ratioFromX(e.clientX));
    }
    bar.addEventListener('pointerup', end);
    bar.addEventListener('pointercancel', end);
})();
/* Marquee tipo ticker: si el texto no cabe en su wrap, duplica el
   span dentro del track y anima el track una distancia igual a
   (ancho del texto + gap). El clon llega exactamente a la posición
   inicial del original → loop continuo sin saltos ni vuelta atrás.
   Velocidad fija ~50 px/s → frases largas tardan más en cíclar. */
function muSetupMarquee(wrapId, trackId, textId) {
    var wrap  = document.getElementById(wrapId);
    var track = document.getElementById(trackId);
    var text  = document.getElementById(textId);
    if (!wrap || !track || !text) return;
    /* Reset: quita marquee, elimina cualquier clon previo, limpia props. */
    wrap.classList.remove('marquee');
    while (track.children.length > 1) track.removeChild(track.lastChild);
    track.style.removeProperty('--mu-marquee-cycle');
    track.style.removeProperty('--mu-marquee-duration');
    /* requestAnimationFrame: esperamos al layout antes de medir. */
    requestAnimationFrame(function(){
        var textW = text.offsetWidth;
        if (textW <= wrap.clientWidth + 4) return;  /* cabe — sin marquee */
        /* Clon del span con aria-hidden para lectores de pantalla. */
        var dup = text.cloneNode(true);
        dup.removeAttribute('id');
        dup.setAttribute('aria-hidden', 'true');
        track.appendChild(dup);
        /* Gap CSS = 3em → en píxeles. */
        var em  = parseFloat(getComputedStyle(text).fontSize) || 16;
        var gap = em * 3;
        var cycle = textW + gap;                    /* distancia a recorrer */
        var duration = Math.max(8, cycle / 50).toFixed(1) + 's';  /* ~50 px/s */
        track.style.setProperty('--mu-marquee-cycle', cycle + 'px');
        track.style.setProperty('--mu-marquee-duration', duration);
        wrap.classList.add('marquee');
    });
}

function muFullRefresh() {
    if (CUR_IDX < 0 || !QUEUE[CUR_IDX]) return;
    var tr = QUEUE[CUR_IDX];
    document.getElementById('mu-full-title').textContent  = tr.title  || tr.videoId || '—';
    document.getElementById('mu-full-artist').textContent = tr.artist || '';
    /* Sincroniza la clase is-album-clickable según el cache del track
       activo. Si el álbum se resuelve después (cuando termine la cola),
       _muPaintAlbumForVid también vuelve a llamar aquí. */
    if (typeof _muRefreshPlayerAlbumLink === 'function') _muRefreshPlayerAlbumLink();
    /* Re-evalúa marquee con el texto nuevo. */
    muSetupMarquee('mu-full-title-wrap',  'mu-full-title-track',  'mu-full-title');
    muSetupMarquee('mu-full-artist-wrap', 'mu-full-artist-track', 'mu-full-artist');
    /* mqdefault: 320×180 16:9, sin letterbox. */
    var thumbUrl = tr.videoId ? 'https://i.ytimg.com/vi/' + tr.videoId + '/mqdefault.jpg' : '';
    var label  = document.getElementById('mu-vinyl-label');
    var splash = document.getElementById('mu-full-splash');
    if (thumbUrl) {
        label.style.backgroundImage  = "url('" + thumbUrl + "')";
        splash.style.backgroundImage = "url('" + thumbUrl + "')";
        label.classList.remove('empty');
        label.textContent = '';
    } else {
        label.style.backgroundImage  = '';
        splash.style.backgroundImage = '';
        label.classList.add('empty');
        label.innerHTML = '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">';
    }
    /* Title-bar: nombre de la playlist activa. */
    var tbPl = document.getElementById('mu-full-tb-pl');
    if (tbPl) {
        var pl = (CUR_PL_IDX >= 0 && PLAYLISTS[CUR_PL_IDX]) ? PLAYLISTS[CUR_PL_IDX] : null;
        tbPl.textContent = pl ? pl.name : 'Reproduciendo';
    }
    /* Replica los mismos datos en el lock (vinilo + info + marquee). */
    muLockSync();
    /* Sincroniza play state. */
    var playing = !!(YT_PLAYER && YT_PLAYER.getPlayerState && YT_PLAYER.getPlayerState() === 1);
    muFullSyncPlay(playing);
}
function muFullSyncPlay(isPlaying) {
    var btn       = document.getElementById('mu-full-toggle');
    var vinyl     = document.getElementById('mu-vinyl');
    var lockVinyl = document.getElementById('mu-lock-vinyl');
    if (btn)       btn.classList.toggle('is-playing', !!isPlaying);
    if (vinyl)     vinyl.classList.toggle('paused', !isPlaying);
    if (lockVinyl) lockVinyl.classList.toggle('paused', !isPlaying);
}

/* Tap en el mini-player (excepto en los botones) → abre fullscreen. */
document.getElementById('mu-player').addEventListener('click', function(e){
    if (e.target.closest('button')) return;      /* clicks de control siguen su flujo */
    /* Click EXACTO en el título con álbum resuelto → abre viewer del
       álbum en lugar del fullscreen. */
    var titleEl = e.target.closest('.mu-player-title.is-album-clickable');
    if (titleEl && titleEl.dataset.albumKey) {
        e.stopPropagation();
        _muOpenAlbumViewer(titleEl.dataset.albumKey, titleEl.dataset.albumName || '');
        return;
    }
    muFullOpen();
});

/* Tap en el título del reproductor GRANDE → cierra el fullscreen y
   abre el viewer del álbum.
   El handler antes estaba atado al span `#mu-full-title`, pero ese
   span solo ocupa el ancho del texto (display:inline-block) — si el
   título era corto, tocar a su lado caía fuera y no disparaba nada.
   Ahora lo atamos al WRAP del título (zona completa de 1 línea con
   ancho del LCD). Si el usuario toca en la zona del LCD pero fuera
   del título, igual abrimos el álbum del track actual: el LCD entero
   funciona como "info de la canción" y tocar ahí es intuitivo. */
(function(){
    var ft = document.getElementById('mu-full-title-wrap');
    if (!ft) return;
    ft.style.cursor = 'pointer';
    ft.addEventListener('click', function(e){
        e.stopPropagation();
        if (CUR_IDX < 0 || !QUEUE[CUR_IDX]) return;
        var tr = QUEUE[CUR_IDX];
        muFullClose();
        muOpenAlbumFromTrack(tr);
    });
})();
document.getElementById('mu-full-close').addEventListener('click', muFullClose);
/* Chevron-down al pie → minimiza el fullscreen. Mismo comportamiento
   que la X del title-bar, pero accesible con el pulgar. */
document.getElementById('mu-full-menu').addEventListener('click', muFullClose);

/* ─── LOCK SCREEN ──────────────────────────────────────────────
   Click en el padlock → muLockOpen() añade .visible y la transición
   CSS funde el reproductor con el fondo negro. Para los pollers que
   gastan batería (progress timer). Swipe-up para desbloquear. */
/* Theme-color del SO mientras el lock está activo: negro puro para
   que la barra de estado/notch y la nav-bar se fundan con el modo
   reposo y los bordes superior/inferior del dispositivo se vean
   negros, dando sensación de pantalla apagada. */
var THEME_COLOR_DEFAULT = '<?= htmlspecialchars($themeBgColor) ?>';
function muSetThemeColor(color) {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', color);
}

/* Broadcast "estoy en modo idle" al shell padre (mobile.php) y a
   cualquier listener interno. mobile.php usa esto para pausar sus
   heartbeats / LT polls / etc. */
function muBroadcastIdle(on) {
    var msg = { type: 'melon:idle', on: !!on };
    try { window.dispatchEvent(new CustomEvent('melon:idle', { detail: msg })); } catch (_) {}
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(msg, '*');
        }
        if (window.top && window.top !== window && window.top !== window.parent) {
            window.top.postMessage(msg, '*');
        }
    } catch (_) {}
}

function muLockOpen() {
    if (CUR_IDX < 0) return;                 /* nada sonando: nada que bloquear */
    var lock = document.getElementById('mu-lock');
    var hint = document.getElementById('mu-lock-hint');
    var full = document.getElementById('mu-full');
    if (hint) { hint.style.transform = ''; }
    /* Versión minimalista: NO pintamos vinilo/título/progress. */
    lock.classList.add('visible');
    lock.setAttribute('aria-hidden', 'false');
    if (full) full.classList.add('behind-lock');
    /* Tier 1 #1: clase en body activa el content-visibility:hidden
       de la UI subyacente. */
    document.body.classList.add('lock-active');
    muSetThemeColor('#000000');
    /* YouTube: bajamos la calidad al mínimo para ahorrar descodificación. */
    try {
        if (YT_PLAYER && YT_PLAYER.setPlaybackQuality) {
            YT_PLAYER.setPlaybackQuality('tiny');
        }
    } catch (_) {}
    /* Tier 2 #6: pausa la cola de resolver álbumes. */
    _muAlbumQueuePaused = true;
    muRequestFullscreen();
    muProgressTimerSync();   /* para el timer al detectar lock visible */
    /* Tier 2 #5: avisa al shell que entre en idle mode. */
    muBroadcastIdle(true);
}
function muLockClose() {
    var lock = document.getElementById('mu-lock');
    var hint = document.getElementById('mu-lock-hint');
    var full = document.getElementById('mu-full');
    lock.classList.remove('visible');
    lock.setAttribute('aria-hidden', 'true');
    if (full) full.classList.remove('behind-lock');
    document.body.classList.remove('lock-active');
    muSetThemeColor(THEME_COLOR_DEFAULT);
    /* Restaurar calidad del player. */
    try {
        if (YT_PLAYER && YT_PLAYER.setPlaybackQuality) {
            YT_PLAYER.setPlaybackQuality('default');
        }
    } catch (_) {}
    /* Reanuda la cola de álbumes y dispara los pendientes. */
    _muAlbumQueuePaused = false;
    if (typeof _muAlbumNextSlot === 'function') _muAlbumNextSlot();
    muExitFullscreen();
    if (hint) hint.style.transform = '';
    muProgressTimerSync();
    muBroadcastIdle(false);
}
/* Helpers Fullscreen API con prefijos webkit por compatibilidad. */
function muRequestFullscreen() {
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
    if (fn) {
        try { fn.call(el).catch(function(){}); } catch (_) {}
    }
}
function muExitFullscreen() {
    var fn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if (fn && (document.fullscreenElement || document.webkitFullscreenElement)) {
        try { fn.call(document).catch(function(){}); } catch (_) {}
    }
}

/* Sincroniza el lock con el track actual: vinilo (label + paused),
   título, artista, marquee. El progreso lo refresca el timer común. */
/* Lock minimalista — sin HUD que sincronizar. muLockSync queda como
   no-op para no romper los call-sites (muFullRefresh la llama). */
function muLockSync() { /* intentionally empty */ }

document.getElementById('mu-full-lock').addEventListener('click', muLockOpen);

/* Swipe up para desbloquear. SOLO se mueve el hint (.mu-lock-hint);
   ni el fondo negro ni el vinilo se tocan. Threshold = mitad del
   viewport (~400px en móvil estándar). Al alcanzarlo se dispara el
   fade-out lento (1.2s) del lock screen entero. */
(function attachLockSwipe() {
    var lock = document.getElementById('mu-lock');
    var hint = document.getElementById('mu-lock-hint');
    if (!lock || !hint) return;
    var startY = null;
    var dragging = false;
    function getThreshold() { return window.innerHeight / 3; }   /* ~1/3 de la pantalla */

    lock.addEventListener('touchstart', function(e){
        if (!e.touches || e.touches.length !== 1) return;
        startY = e.touches[0].clientY;
        dragging = true;
        /* Sin transición durante el drag para que el hint siga al
           dedo sin retraso. */
        hint.style.transition = 'none';
    }, { passive: true });
    lock.addEventListener('touchmove', function(e){
        if (!dragging || startY === null || !e.touches[0]) return;
        var dy = startY - e.touches[0].clientY;
        if (dy > 0) {
            /* El hint nuevo ya está centrado con left:0 + right:0 +
               text-align:center, así que solo aplicamos translateY.
               Sin opacity fade (no la necesitamos: cuesta repaint y
               el feedback de movimiento ya es claro). */
            hint.style.transform = 'translateY(' + (-dy * 1.8) + 'px)';
        }
    }, { passive: true });
    function endDrag(e) {
        if (!dragging || startY === null) return;
        dragging = false;
        var t = e.changedTouches && e.changedTouches[0];
        var dy = t ? (startY - t.clientY) : 0;
        startY = null;
        hint.style.transition = '';   /* restaura snap-back fluido */
        if (dy >= getThreshold()) {
            muLockClose();
        } else {
            /* Snap back inmediato (sin transición CSS para no animar). */
            hint.style.transform = '';
        }
    }
    lock.addEventListener('touchend',    endDrag);
    lock.addEventListener('touchcancel', endDrag);

    /* Soporte mouse para testing en escritorio. */
    var msDown = false;
    lock.addEventListener('mousedown', function(e){
        if (e.button !== 0) return;
        startY = e.clientY;
        msDown = true;
        dragging = true;
        hint.style.transition = 'none';
    });
    lock.addEventListener('mousemove', function(e){
        if (!msDown || startY === null) return;
        var dy = startY - e.clientY;
        if (dy > 0) {
            hint.style.transform = 'translateX(-50%) translateY(' + (-dy * 1.8) + 'px)';
            var pct = Math.min(1, dy / getThreshold());
            hint.style.opacity = String(0.8 - pct * 0.6);
        }
    });
    function endMouseDrag(e) {
        if (!msDown) return;
        msDown = false;
        var dy = startY !== null ? (startY - e.clientY) : 0;
        startY = null;
        hint.style.transition = '';
        if (dy >= getThreshold()) muLockClose();
        else {
            hint.style.transform = '';
            hint.style.opacity = '';
        }
    }
    lock.addEventListener('mouseup',    endMouseDrag);
    lock.addEventListener('mouseleave', endMouseDrag);
})();
document.getElementById('mu-full-prev').addEventListener('click', prevTrack);
document.getElementById('mu-full-next').addEventListener('click', nextTrack);
document.getElementById('mu-full-toggle').addEventListener('click', function(){
    if (!YT_READY || !YT_PLAYER || CUR_IDX < 0) return;
    var st = YT_PLAYER.getPlayerState();
    if (st === 1) YT_PLAYER.pauseVideo();
    else          YT_PLAYER.playVideo();
});

/* Shuffle: toggle visual + de comportamiento. El estado lo lleva
   SHUFFLE_ON; next/prev miran esa flag para decidir secuencial o
   random. */
document.getElementById('mu-full-shuffle').addEventListener('click', function(){
    SHUFFLE_ON = !SHUFFLE_ON;
    this.classList.toggle('is-on', SHUFFLE_ON);
    this.setAttribute('aria-pressed', SHUFFLE_ON ? 'true' : 'false');
});

/* Añadir canción actual a otra playlist. Lista todas las playlists,
   chequea duplicados por videoId y pide confirmación si ya existe. */
document.getElementById('mu-full-add').addEventListener('click', function(){
    if (CUR_IDX < 0 || !QUEUE[CUR_IDX]) return;
    muOpenAddCurrentToPlaylist(QUEUE[CUR_IDX]);
});

/* ─── MENÚ DE LA CANCIÓN ACTUAL (long-press en el vinilo) ────────────
   Mantener pulsado el vinilo del reproductor grande abre el mismo menú
   de opciones que el long-press de una canción en la lista, incluida
   la opción "Corregir". Actúa sobre el track en reproducción. */
function muOpenNowPlayingMenu() {
    if (CUR_IDX < 0 || !QUEUE[CUR_IDX]) return;
    var tr = QUEUE[CUR_IDX];
    var items = [
        { act: 'fixAlbum', label: MU_FIX_ICON + 'Corregir' },
        { act: 'addPl',    label: '📋 Añadir' }
    ];
    if (tr.videoId) items.push({ act: 'link', label: '🔗 Copiar enlace' });
    var bodyHtml = '<p class="modal-msg" style="margin:0 0 6px;color:var(--text-faint, #666);">' +
                     esc(tr.title || tr.videoId || 'Canción') +
                     (tr.artist ? ' — ' + esc(tr.artist) : '') +
                   '</p><div class="mu-modal-list">';
    items.forEach(function(it){
        bodyHtml += '<div class="mu-modal-list-item" data-act="' + it.act + '">' +
                      '<div class="item-main">' + it.label + '</div>' +
                    '</div>';
    });
    bodyHtml += '</div><div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';

    var m = muOpenModal({ title: 'Opciones', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'fixAlbum') muReportWrongAlbum(tr);
            if (act === 'addPl')    muOpenAddCurrentToPlaylist(tr);
            if (act === 'link' && window.melonCopyLink) window.melonCopyLink('cancion', tr.videoId);
        });
    });
}

/* Gesto long-press sobre el vinilo del reproductor grande. Implementación
   local (no comparte estado con el long-press de la lista) para no
   interferir con sus clicks sintéticos. */
(function setupVinylLongPress(){
    var vinyl = document.getElementById('mu-vinyl');
    if (!vinyl) return;
    var timer = null, sx = 0, sy = 0;
    function start(x, y) {
        sx = x; sy = y;
        vinyl.classList.add('long-pressing');
        timer = setTimeout(function(){
            timer = null;
            vinyl.classList.remove('long-pressing');
            try { navigator.vibrate && navigator.vibrate(40); } catch (_) {}
            muOpenNowPlayingMenu();
        }, 500);
    }
    function cancel() {
        if (timer) { clearTimeout(timer); timer = null; }
        vinyl.classList.remove('long-pressing');
    }
    vinyl.addEventListener('touchstart', function(e){
        if (!e.touches || e.touches.length !== 1) return;
        start(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });
    vinyl.addEventListener('touchmove', function(e){
        if (!timer || !e.touches[0]) return;
        if (Math.abs(e.touches[0].clientX - sx) > 12 || Math.abs(e.touches[0].clientY - sy) > 12) cancel();
    }, { passive: true });
    vinyl.addEventListener('touchend',    cancel);
    vinyl.addEventListener('touchcancel', cancel);
    vinyl.addEventListener('mousedown', function(e){ if (e.button === 0) start(e.clientX, e.clientY); });
    vinyl.addEventListener('mousemove', function(e){
        if (!timer) return;
        if (Math.abs(e.clientX - sx) > 12 || Math.abs(e.clientY - sy) > 12) cancel();
    });
    vinyl.addEventListener('mouseup',    cancel);
    vinyl.addEventListener('mouseleave', cancel);
    /* Evita el menú contextual nativo del navegador al mantener pulsado. */
    vinyl.addEventListener('contextmenu', function(e){ e.preventDefault(); });
})();

function muOpenAddCurrentToPlaylist(tr) {
    /* Si aún no se cargaron las playlists (no se abrió la pantalla de
       playlists), las cargamos y reintentamos — antes salía "no tienes
       playlists" aunque sí las tuvieras, al añadir desde una canción/álbum
       que no está dentro de ninguna playlist. */
    if (!PLAYLISTS.length) {
        loadPlaylists().then(function(){
            if (!PLAYLISTS.length) { muAlert('No tienes playlists todavía'); return; }
            muOpenAddCurrentToPlaylist(tr);
        }).catch(function(){ muAlert('No se pudieron cargar las playlists'); });
        return;
    }
    var bodyHtml = '<p class="modal-msg" style="margin:0 0 6px;">' +
                     'Añadir "' + esc(tr.title || tr.videoId || 'canción') + '" a:' +
                   '</p>';
    bodyHtml += '<div class="mu-modal-list">';
    PLAYLISTS.forEach(function(pl, pi){
        var nTracks = (pl.tracks || []).length;
        var sharedTag = pl.sharedLabel
            ? '<span style="font-size:10px;color:var(--text-faint, #666);margin-left:6px;">de ' + esc(pl.sharedLabel) + '</span>'
            : '';
        bodyHtml += '<div class="mu-modal-list-item" data-pl-idx="' + pi + '">' +
                      '<div class="item-main">' +
                        '<div style="font-weight:bold;">' + esc(pl.name) + sharedTag + '</div>' +
                        '<div style="font-size:11px;color:var(--text-faint, #666);">' +
                            nTracks + ' canción' + (nTracks === 1 ? '' : 'es') +
                        '</div>' +
                      '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>';
    bodyHtml += '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cancelar</button></div>';

    /* Mismo SVG que el botón → coherencia visual entre trigger y modal. */
    var addIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">' +
                    '<line x1="12" y1="5"  x2="12" y2="19"/>' +
                    '<line x1="5"  y1="12" x2="19" y2="12"/>' +
                  '</svg>';
    var m = muOpenModal({ titleIcon: addIcon, title: 'Añadir a playlist', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var pi = parseInt(el.dataset.plIdx, 10);
            var pl = PLAYLISTS[pi];
            if (!pl) return;
            m.close();
            muAddCurrentToPlaylistConfirmed(pl, tr);
        });
    });
}

function muAddCurrentToPlaylistConfirmed(pl, tr) {
    var dup = tr.videoId && (pl.tracks || []).some(function(t){
        return t && t.videoId && t.videoId === tr.videoId;
    });
    var doAdd = function(){
        var newTracks = (pl.tracks || []).slice();
        /* Copia plana de tr + addedBy (no mutamos el objeto original
           que vive en QUEUE/PLAYLISTS). */
        var trCopy = {};
        for (var k in tr) if (tr.hasOwnProperty(k)) trCopy[k] = tr[k];
        trCopy.addedBy = ME_LABEL;
        newTracks.push(trCopy);
        var payload = { id: pl.id, name: pl.name, tracks: newTracks };
        if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
        apiPost('save-playlist-item', payload).then(function(res){
            if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al añadir'); return; }
            loadPlaylists();
            muAlert('"' + (tr.title || 'Canción') + '" añadida a "' + pl.name + '"', 'Añadida');
        });
    };
    if (dup) {
        muConfirm(
            '"' + (tr.title || 'Esta canción') + '" ya está en "' + pl.name + '". ¿Añadirla de nuevo?',
            doAdd
        );
    } else {
        doAdd();
    }
}

/* ─── Reproductor YouTube ──────────────────────────────────────── */
/* Carga la IFrame API una vez. El callback global onYouTubeIframeAPIReady
   es el contrato standard de la API. */
window.onYouTubeIframeAPIReady = function() {
    if (EMBEDDED) return;             /* shell maneja el player */
    YT_PLAYER = new window.YT.Player('yt-iframe', {
        height: '1', width: '1',
        playerVars: { playsinline: 1, autoplay: 0 },
        events: {
            'onReady':       function(){ YT_READY = true; },
            'onStateChange': onYTState,
            'onError':       function(){ nextTrack(); }
        }
    });
};
(function loadYT(){
    if (EMBEDDED) return;             /* shell ya tiene su propio iframe API */
    var s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(s);
})();

/* YT.PlayerState: -1 unstarted, 0 ended, 1 playing, 2 paused, 3 buffering, 5 cued */

/* ─── Media Session API ────────────────────────────────────────────
   Le dice al SO qué se está reproduciendo (título, artista, artwork)
   y cómo responder a los botones de control (play/pause/prev/next).
   - Android: aparece la track en la pantalla de bloqueo + en el panel
     de notificaciones, y los botones físicos (auriculares, bluetooth)
     funcionan. Combined con la PWA standalone, esto permite que el
     audio siga sonando aunque el móvil se bloquee.
   - iOS: aparece en el control center y en pantalla de bloqueo.
     Background audio en PWA es limitado pero MediaSession ayuda. */
function updateMediaSession(tr) {
    if (!('mediaSession' in navigator)) return;
    if (!tr) { navigator.mediaSession.metadata = null; return; }
    try {
        navigator.mediaSession.metadata = new MediaMetadata({
            title:  tr.title  || 'Track',
            artist: tr.artist || 'Melon Hub',
            album:  'Melon Hub',
            artwork: tr.videoId ? [
                { src: 'https://i.ytimg.com/vi/' + tr.videoId + '/mqdefault.jpg',  sizes: '320x180', type: 'image/jpeg' },
                { src: 'https://i.ytimg.com/vi/' + tr.videoId + '/hqdefault.jpg',  sizes: '480x360', type: 'image/jpeg' },
                { src: 'https://i.ytimg.com/vi/' + tr.videoId + '/maxresdefault.jpg', sizes: '1280x720', type: 'image/jpeg' }
            ] : []
        });
    } catch (_) { /* algunos navegadores antiguos */ }
}
function setMediaPlaybackState(state) {
    if (!('mediaSession' in navigator)) return;
    try { navigator.mediaSession.playbackState = state; } catch (_) {}
}
if ('mediaSession' in navigator) {
    /* Action handlers — el SO los invoca cuando el usuario toca los
       botones de la lock screen / notificación / auriculares. */
    try {
        navigator.mediaSession.setActionHandler('play',           function(){ if (YT_PLAYER) YT_PLAYER.playVideo();  });
        navigator.mediaSession.setActionHandler('pause',          function(){ if (YT_PLAYER) YT_PLAYER.pauseVideo(); });
        navigator.mediaSession.setActionHandler('previoustrack',  prevTrack);
        navigator.mediaSession.setActionHandler('nexttrack',      nextTrack);
        navigator.mediaSession.setActionHandler('stop',           function(){ if (YT_PLAYER) YT_PLAYER.stopVideo();  });
    } catch (_) {}
}

function playFromPlaylist(pi, ti) {
    var pl = PLAYLISTS[pi];
    if (!pl) return;
    QUEUE = (pl.tracks || []).slice();
    CUR_IDX = ti;
    CUR_PL_IDX = pi;
    /* Highlight visual del track activo — también cuando estamos
       embebidos en el shell (antes solo lo hacía playCurrent que no
       corre en modo embed). Selector con pl-idx + tr-idx para no
       pintar tracks homónimos de otras playlists. */
    applyPlayingHighlight(pi, ti);
    /* Embebida → delega al shell, pasando el nombre de la playlist
       para el title-bar del fullscreen. */
    if (SHELL) { SHELL.loadQueue(QUEUE, ti, pl.name); return; }
    playCurrent();
}

/* Pone .playing solo en el track del par (pi, ti). Limpia el resto. */
function applyPlayingHighlight(pi, ti) {
    document.querySelectorAll('.mu-track.playing').forEach(function(el){
        el.classList.remove('playing');
    });
    if (pi == null || ti == null || pi < 0 || ti < 0) return;
    var sel = document.querySelector(
        '.mu-track[data-pl-idx="' + pi + '"][data-tr-idx="' + ti + '"]'
    );
    if (sel) sel.classList.add('playing');
}

/* ════════════════════════════════════════════════════════════════
   WRAPPED tracking — replica el patrón del reproductor de escritorio
   ([apps/reproductor.php]) para que las escuchas DESDE EL MÓVIL
   también cuenten en las stats anuales. Se logea CADA cambio de
   track + flush en pagehide/visibilitychange.
   ════════════════════════════════════════════════════════════════ */
var _wrappedLastTrack      = null;
var _wrappedLastPlaylistId = null;
var WRAPPED_URL = '../../assets/music/wrapped-api.php?action=log';

function sendWrappedLog(track, listenedS, playlistId) {
    if (!track || !track.videoId || !track.title) return;
    listenedS = Math.max(0, Math.floor(listenedS || 0));
    /* < 3s no cuenta — evita inflar el conteo con saltos rápidos. */
    if (listenedS < 3) return;
    var body = JSON.stringify({
        videoId:    track.videoId,
        title:      track.title,
        artist:     track.artist || '',
        playlistId: playlistId,
        durationS:  listenedS,
    });
    try {
        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon(WRAPPED_URL, blob)) return;
        }
    } catch (_) {}
    try {
        fetch(WRAPPED_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true,
        }).catch(function(){});
    } catch (_) {}
}

(function setupWrappedFlush() {
    function flush() {
        if (!_wrappedLastTrack) return;
        var listened = 0;
        try {
            if (YT_PLAYER && typeof YT_PLAYER.getCurrentTime === 'function') {
                listened = YT_PLAYER.getCurrentTime() || 0;
            }
        } catch (_) {}
        sendWrappedLog(_wrappedLastTrack, listened, _wrappedLastPlaylistId);
    }
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
})();

function playCurrent() {
    if (CUR_IDX < 0 || CUR_IDX >= QUEUE.length) return;
    var tr = QUEUE[CUR_IDX];
    if (!tr || !tr.videoId) { nextTrack(); return; }

    /* WRAPPED tracking: antes de cargar el nuevo videoId, logea el
       track ANTERIOR con el tiempo real escuchado (getCurrentTime del
       momento del cambio). Cubre tanto skip como fin natural. */
    if (_wrappedLastTrack && _wrappedLastTrack.videoId !== tr.videoId) {
        var listened = 0;
        try {
            if (YT_PLAYER && typeof YT_PLAYER.getCurrentTime === 'function') {
                listened = YT_PLAYER.getCurrentTime() || 0;
            }
        } catch (_) {}
        sendWrappedLog(_wrappedLastTrack, listened, _wrappedLastPlaylistId);
    }
    _wrappedLastTrack      = tr;
    _wrappedLastPlaylistId = (PLAYLISTS[CUR_PL_IDX] || {}).id || null;

    /* Marca visualmente el track activo en la lista. Usa pl-idx + tr-idx
       para no pintar tracks homónimos de otras playlists. */
    applyPlayingHighlight(CUR_PL_IDX, CUR_IDX);
    /* Info en el mini-player. */
    document.getElementById('mu-now-title').textContent  = tr.title  || tr.videoId;
    document.getElementById('mu-now-artist').textContent = tr.artist || '';
    /* Si el álbum del track está cacheado, marca los títulos como
       clickables. Si no, _muPaintAlbumForVid lo hará cuando la cola
       termine de resolverlo. */
    if (typeof _muRefreshPlayerAlbumLink === 'function') _muRefreshPlayerAlbumLink();
    /* Si el viewer del álbum está abierto, sincroniza el highlight de
       la fila activa con el track que está sonando. */
    if (typeof _muHighlightAlbumPlayingRow === 'function') _muHighlightAlbumPlayingRow();
    document.getElementById('mu-thumb').innerHTML =
        '<img src="https://i.ytimg.com/vi/' + esc(tr.videoId) + '/mqdefault.jpg" alt="">';
    document.getElementById('mu-player').classList.add('visible');
    /* Refresca también la vista fullscreen si está abierta — esto
       actualiza el thumbnail del vinilo y el splash con cada cambio
       de track. */
    muFullRefresh();
    /* Registra metadata para la lock screen / auriculares / Bluetooth. */
    updateMediaSession(tr);
    /* Si el SDK aún no está, esperamos un tick. */
    if (!YT_READY || !YT_PLAYER) {
        var waitT = setInterval(function(){
            if (YT_READY && YT_PLAYER) { clearInterval(waitT); YT_PLAYER.loadVideoById(tr.videoId); }
        }, 150);
        return;
    }
    YT_PLAYER.loadVideoById(tr.videoId);
}

/* Devuelve un índice aleatorio de QUEUE distinto al actual. Se usa
   tanto para prev como next cuando SHUFFLE_ON: si solo hay un track
   se queda donde está. */
function muPickRandomIdx() {
    if (QUEUE.length <= 1) return CUR_IDX;
    var r;
    do { r = Math.floor(Math.random() * QUEUE.length); } while (r === CUR_IDX);
    return r;
}
function nextTrack() {
    if (CUR_IDX < 0) return;
    if (SHUFFLE_ON) { CUR_IDX = muPickRandomIdx(); }
    else {
        CUR_IDX++;
        if (CUR_IDX >= QUEUE.length) CUR_IDX = 0;     /* loop circular */
    }
    playCurrent();
}
function prevTrack() {
    if (CUR_IDX < 0) return;
    if (SHUFFLE_ON) { CUR_IDX = muPickRandomIdx(); }
    else {
        CUR_IDX--;
        if (CUR_IDX < 0) CUR_IDX = QUEUE.length - 1;  /* loop circular */
    }
    playCurrent();
}

document.getElementById('mu-toggle').addEventListener('click', function(){
    if (!YT_READY || !YT_PLAYER || CUR_IDX < 0) return;
    var st = YT_PLAYER.getPlayerState();
    if (st === 1) YT_PLAYER.pauseVideo();
    else          YT_PLAYER.playVideo();
});
document.getElementById('mu-next').addEventListener('click', nextTrack);
document.getElementById('mu-prev').addEventListener('click', prevTrack);

/* ════════════════════════════════════════════════════════════════════
   MODALES + EDICIÓN DE PLAYLISTS
   ════════════════════════════════════════════════════════════════════ */

/* ─── Helper modal genérico ────────────────────────────────────────
   Crea un overlay con una ventana Win98 dentro y devuelve la API para
   manipular su contenido y cerrarla. */
function muOpenModal(opts) {
    var host = document.getElementById('mu-modal-host');
    var bd = document.createElement('div');
    bd.className = 'mu-modal-backdrop';
    /* opts.titleIcon: HTML/SVG crudo que se inyecta antes del título.
       Se confía la entrada — no la llaman desde input de usuario. */
    var titleHtml = (opts.titleIcon ? opts.titleIcon : '') + esc(opts.title || '');
    bd.innerHTML =
        '<div class="window mu-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">' + titleHtml + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body"></div>' +
        '</div>';
    var modal = bd.querySelector('.mu-modal');
    var body = bd.querySelector('.window-body');
    var closeBtn = bd.querySelector('.title-bar-controls button');
    body.innerHTML = opts.body || '';
    host.appendChild(bd);

    var api = {
        el: bd,
        body: body,
        modal: modal,
        close: function(){ if (bd.parentNode) bd.parentNode.removeChild(bd); },
        setBody: function(html){ body.innerHTML = html; }
    };
    closeBtn.addEventListener('click', api.close);
    /* Click en backdrop (fuera de la ventana) cierra. */
    bd.addEventListener('click', function(e){
        if (e.target === bd) api.close();
    });
    if (opts.onOpen) opts.onOpen(api);
    return api;
}

/* Confirmación tipo win98 */
function muConfirm(message, onYes) {
    var m = muOpenModal({
        title: 'Confirmar',
        body:
            '<p class="modal-msg">' + esc(message) + '</p>' +
            '<div class="modal-actions">' +
                '<button class="button" type="button" data-act="no">Cancelar</button>' +
                '<button class="button default" type="button" data-act="yes">Sí</button>' +
            '</div>'
    });
    m.body.querySelector('[data-act="no"]').addEventListener('click', m.close);
    m.body.querySelector('[data-act="yes"]').addEventListener('click', function(){
        m.close();
        onYes && onYes();
    });
}

/* Aviso simple */
function muAlert(message, title) {
    var m = muOpenModal({
        title: title || 'Aviso',
        body:
            '<p class="modal-msg">' + esc(message) + '</p>' +
            '<div class="modal-actions">' +
                '<button class="button default" type="button" data-act="ok">OK</button>' +
            '</div>'
    });
    m.body.querySelector('[data-act="ok"]').addEventListener('click', m.close);
}

/* ─── API helpers ──────────────────────────────────────────────── */
function apiPost(action, body) {
    return fetch(API_MUSIC + '?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    }).then(function(r){ return r.json().then(function(d){ return { ok: r.ok, status: r.status, data: d }; }); });
}
function apiGet(action, params) {
    var qs = '';
    if (params) {
        var p = [];
        Object.keys(params).forEach(function(k){ p.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); });
        if (p.length) qs = '&' + p.join('&');
    }
    return fetch(API_MUSIC + '?action=' + action + qs, { credentials: 'same-origin' })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, status: r.status, data: d }; }); });
}

/* ─── NUEVA PLAYLIST ───────────────────────────────────────────── */
/* Filtro de canciones de las playlists: re-renderiza aplicando MU_FILTER. */
(function(){
    var fi = document.getElementById('mu-pl-filter');
    if (!fi) return;
    fi.addEventListener('input', function(){
        MU_FILTER = fi.value || '';
        renderPlaylists();
    });
})();

document.getElementById('mu-btn-new-pl').addEventListener('click', function(){
    var m = muOpenModal({
        title: '+ Nueva playlist',
        body:
            '<label for="np-name">Nombre</label>' +
            '<input type="text" id="np-name" maxlength="100" autofocus>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                '<button class="button default" data-act="ok" type="button">Crear</button>' +
            '</div>'
    });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelector('[data-act="ok"]').addEventListener('click', function(){
        var name = (m.body.querySelector('#np-name').value || '').trim();
        if (!name) return;
        var payload = { id: 'pl_' + Date.now(), name: name, tracks: [] };
        apiPost('save-playlist-item', payload).then(function(res){
            m.close();
            if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al crear'); return; }
            /* La nueva playlist no viene en la respuesta — recargamos. */
            loadPlaylists();
        });
    });
});

/* ─── MENÚ CONTEXTUAL DE PLAYLIST (⋮) ────────────────────────── */
function muOpenPlaylistMenu(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl) return;
    var isOwn = !pl.sharedFrom;
    var items = [
        { act: 'add',       label: '➕ Añadir canción' },
        { act: 'edit',      label: '<img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">️ Renombrar' }
    ];
    /* Enlace para compartir (cualquiera con sesión puede verla y copiarla). */
    if (/^\d+$/.test(String(pl.id))) items.push({ act: 'link', label: '🔗 Copiar enlace' });
    if (isOwn) {
        items.push({ act: 'collab', label: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Colaboradores' });
        items.push({ act: 'delete', label: '<img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Eliminar playlist', danger: true });
    } else {
        items.push({ act: 'leave',  label: '🚪 Abandonar (dejar de colaborar)', danger: true });
    }
    var bodyHtml = '<div class="mu-modal-list">';
    items.forEach(function(it){
        bodyHtml += '<div class="mu-modal-list-item" data-act="' + it.act + '">' +
                      '<div class="item-main" style="' + (it.danger ? 'color:var(--error-text, #c00);font-weight:bold;' : '') + '">' + it.label + '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>';
    bodyHtml += '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';

    var m = muOpenModal({ title: pl.name, body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'add')    muOpenAddTrack(idx);
            if (act === 'edit')   muOpenEditPlaylist(idx);
            if (act === 'collab') muOpenCollabs(idx);
            if (act === 'link' && window.melonCopyLink) window.melonCopyLink('playlist', String(pl.id));
            if (act === 'delete') muConfirm('¿Eliminar "' + pl.name + '"? Esta acción no se puede deshacer.', function(){ muDeletePlaylist(idx); });
            if (act === 'leave')  muConfirm('¿Dejar de colaborar en "' + pl.name + '"?', function(){ muLeavePlaylist(idx); });
        });
    });
}

/* ─── RENOMBRAR PLAYLIST ────────────────────────────────────────
   Solo cambia el nombre. La eliminación de tracks vive en el menú
   contextual del track (long-press sobre la canción). */
function muOpenEditPlaylist(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl) return;
    var bodyHtml =
        '<label for="ep-name">Nombre</label>' +
        '<input type="text" id="ep-name" maxlength="100" value="' + esc(pl.name) + '" autofocus>' +
        '<div class="modal-actions">' +
            '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
            '<button class="button default" data-act="save" type="button">Guardar</button>' +
        '</div>';
    var m = muOpenModal({ title: 'Renombrar playlist', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelector('[data-act="save"]').addEventListener('click', function(){
        var newName = (m.body.querySelector('#ep-name').value || '').trim();
        if (!newName) { muAlert('El nombre no puede estar vacío'); return; }
        if (newName === pl.name) { m.close(); return; }
        var payload = { id: pl.id, name: newName, tracks: pl.tracks || [] };
        if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
        apiPost('save-playlist-item', payload).then(function(res){
            m.close();
            if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al guardar'); return; }
            loadPlaylists();
        });
    });
}

/* Icono musicaIcon.png para la opción "Corregir" (lo reescribe
   icon-pack.js según el pack activo). */
var MU_FIX_ICON =
    '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" ' +
        'style="width:15px;height:15px;object-fit:contain;image-rendering:pixelated;vertical-align:-3px;margin-right:6px;">';

/* ─── MENÚ CONTEXTUAL DE TRACK (long-press) ────────────────────
   Opciones: añadir la canción a la lista de música del perfil del
   usuario, o quitarla de la playlist actual. */
function muOpenTrackMenu(pi, ti) {
    var pl = PLAYLISTS[pi];
    if (!pl) return;
    var tr = (pl.tracks || [])[ti];
    if (!tr) return;
    var items = [
        { act: 'addProfile', label: '➕ Añadir a mi perfil' },
        { act: 'addPl',      label: '📋 Añadir' },
        { act: 'fixAlbum',   label: MU_FIX_ICON + 'Corregir' },
        { act: 'link',       label: '🔗 Copiar enlace' },
        { act: 'remove',     label: '<img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Quitar de la playlist', danger: true }
    ];
    var bodyHtml = '<p class="modal-msg" style="margin:0 0 6px;color:var(--text-faint, #666);">' +
                     esc(tr.title || tr.videoId || 'Canción') +
                     (tr.artist ? ' — ' + esc(tr.artist) : '') +
                   '</p>';
    bodyHtml += '<div class="mu-modal-list">';
    items.forEach(function(it){
        bodyHtml += '<div class="mu-modal-list-item" data-act="' + it.act + '">' +
                      '<div class="item-main" style="' + (it.danger ? 'color:var(--error-text, #c00);font-weight:bold;' : '') + '">' + it.label + '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>';
    bodyHtml += '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';

    var m = muOpenModal({ title: 'Opciones', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'addProfile') addTrackToProfile(pi, ti);
            if (act === 'addPl')      muOpenAddCurrentToPlaylist(tr);
            if (act === 'fixAlbum')   muReportWrongAlbum(tr);
            if (act === 'link' && tr.videoId && window.melonCopyLink) window.melonCopyLink('cancion', tr.videoId);
            if (act === 'remove') {
                muConfirm('¿Quitar "' + (tr.title || 'esta canción') + '" de la playlist?', function(){
                    removeTrackFromPlaylist(pi, ti);
                });
            }
        });
    });
}

/* ─── CORREGIR CANCIÓN (móvil) ──────────────────────────────────────
   El usuario corrige los datos de una canción: título, artista, link de
   YouTube y/o álbum. Todo se guarda por videoId vía report-album (global
   y persistente) → la canción se ve corregida en cualquier playlist y al
   importarla de nuevo. El álbum se busca en vivo (iTunes/Deezer) y al
   elegir un resultado queda seleccionado; se envía con el resto al pulsar
   Guardar (ya no auto-envía). */
function muReportWrongAlbum(tr) {
    if (!tr || !tr.videoId) return;

    /* Álbum elegido (opcional). null = no se toca el álbum. */
    var chosenAlbum = null;

    var body =
        '<p class="modal-msg" style="font-size:10px;line-height:1.45;opacity:0.85;margin:0 0 8px;">' +
            'Corrige los datos de esta canción. Se guardan para siempre: al añadirla a otra playlist o importarla se usarán estos valores.' +
        '</p>' +
        '<label style="font-size:11px;font-weight:bold;display:block;margin:0 0 2px;">Título</label>' +
        '<input type="text" class="mu-ra-title" autocomplete="off" placeholder="Título de la canción" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
        '<label style="font-size:11px;font-weight:bold;display:block;margin:0 0 2px;">Artista</label>' +
        '<input type="text" class="mu-ra-artist" autocomplete="off" placeholder="Nombre del artista" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
        '<label style="font-size:11px;font-weight:bold;display:block;margin:0 0 2px;">Link de YouTube</label>' +
        '<input type="text" class="mu-ra-link" autocomplete="off" placeholder="Pega un enlace de YouTube (opcional)" style="width:100%;box-sizing:border-box;margin-bottom:8px;">' +
        '<label style="font-size:11px;font-weight:bold;display:block;margin:0 0 2px;">Álbum</label>' +
        '<input type="text" class="mu-ra-input" autocomplete="off" placeholder="Escribe el nombre del álbum (opcional)…" style="width:100%;box-sizing:border-box;">' +
        '<div class="mu-ra-chosen" style="display:none;align-items:center;gap:8px;margin-top:6px;padding:4px 6px;border:1px solid var(--bezel-dark-2,grey);"></div>' +
        '<div class="mu-ra-results" style="margin-top:6px;max-height:34vh;overflow-y:auto;"></div>' +
        '<div class="modal-actions">' +
            '<button class="button" type="button" data-act="cancel">Cancelar</button>' +
            '<button class="button" type="button" data-act="save">Guardar</button>' +
        '</div>';

    var m = muOpenModal({ title: 'Corregir canción', body: body });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    var titleIn   = m.body.querySelector('.mu-ra-title');
    var artistIn  = m.body.querySelector('.mu-ra-artist');
    var linkIn    = m.body.querySelector('.mu-ra-link');
    var input     = m.body.querySelector('.mu-ra-input');
    var resultsEl = m.body.querySelector('.mu-ra-results');
    var chosenEl  = m.body.querySelector('.mu-ra-chosen');
    titleIn.value  = tr.title  || '';
    artistIn.value = tr.artist || '';

    /* Pinta el álbum elegido y permite quitarlo. */
    function renderChosen() {
        if (!chosenAlbum) { chosenEl.style.display = 'none'; chosenEl.innerHTML = ''; return; }
        chosenEl.style.display = 'flex';
        chosenEl.innerHTML =
            '<img src="' + esc(chosenAlbum.image || '') + '" alt="" style="width:36px;height:36px;object-fit:cover;flex:0 0 36px;background:#222;" onerror="this.style.visibility=\'hidden\'">' +
            '<div style="min-width:0;flex:1;">' +
                '<div style="font-size:12px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(chosenAlbum.name || '') + '</div>' +
                '<div style="font-size:10px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(chosenAlbum.artist || '') + '</div>' +
            '</div>' +
            '<button class="button" type="button" data-act="rm-album" style="flex:0 0 auto;">Quitar</button>';
        chosenEl.querySelector('[data-act="rm-album"]').addEventListener('click', function(){ chosenAlbum = null; renderChosen(); });
    }

    function submit() {
        var payload = {
            videoId:   tr.videoId,
            title:     (titleIn.value  || '').trim(),
            artist:    (artistIn.value || '').trim(),
            videoLink: (linkIn.value   || '').trim()
        };
        if (chosenAlbum) {
            payload.albumKey    = chosenAlbum.albumKey || '';
            payload.albumName   = chosenAlbum.name   || '';
            payload.albumArtist = chosenAlbum.artist || '';
            payload.albumImage  = chosenAlbum.image  || '';
        }
        apiPost('report-album', payload).then(function(res){
            if (!res.ok || !res.data || !res.data.ok) {
                muAlert((res.data && res.data.error) || 'No se pudo guardar la corrección');
                return;
            }
            var d = res.data;
            var alb = d.album;
            /* Guarda el álbum corregido en cache local y repinta. */
            if (alb) {
                try { _muAlbumCacheSet(tr.videoId, alb); } catch (_) {}
                if (typeof _muPaintAlbumForVid === 'function') {
                    _muPaintAlbumForVid(tr.videoId, _muNormalizeAlbumPayload(alb));
                }
            }
            m.close();
            /* Recarga las playlists para reflejar título/artista/link
               corregidos (el backend los aplica al cargar). */
            if (typeof loadPlaylists === 'function') loadPlaylists();
            muAlert((alb && alb.albumName) ? ('Canción corregida · álbum: ' + alb.albumName) : 'Canción corregida', 'Hecho');
        });
    }
    m.body.querySelector('[data-act="save"]').addEventListener('click', submit);

    function renderResults(list) {
        resultsEl.innerHTML = '';
        (list || []).forEach(function(a){
            var row = document.createElement('div');
            row.className = 'mu-modal-list-item';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;';
            var safeImg  = esc(a.image || '');
            var safeName = esc(a.name || '');
            var safeArt  = esc(a.artist || '');
            row.innerHTML =
                '<img src="' + safeImg + '" alt="" style="width:36px;height:36px;object-fit:cover;flex:0 0 36px;background:#222;" ' +
                    'onerror="this.style.visibility=\'hidden\'">' +
                '<div style="min-width:0;flex:1;">' +
                    '<div style="font-size:12px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + safeName + '</div>' +
                    '<div style="font-size:10px;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + safeArt + '</div>' +
                '</div>';
            row.addEventListener('click', function(){
                /* Selecciona el álbum (no envía aún). */
                chosenAlbum = { albumKey: a.albumKey, name: a.name || '', artist: a.artist || '', image: a.image || '' };
                renderChosen();
                input.value = '';
                resultsEl.innerHTML = '';
                lastQ = null;
            });
            resultsEl.appendChild(row);
        });
    }

    var searchT = null, lastQ = null;
    function doSearch() {
        var q = (input.value || '').trim();
        if (q === lastQ) return;
        lastQ = q;
        if (q.length < 2) { resultsEl.innerHTML = ''; return; }
        apiGet('search-albums', { q: q, artist: (artistIn.value || '').trim() || tr.artist || '' }).then(function(res){
            if ((input.value || '').trim() !== q) return;   /* respuesta obsoleta */
            renderResults(res.ok && res.data ? res.data.results : []);
        });
    }
    input.addEventListener('input', function(){ clearTimeout(searchT); searchT = setTimeout(doSearch, 300); });
}

/* Quita la canción `ti` de la playlist `pi` y guarda con save-playlist-item.
   Si la playlist es compartida se preserva sharedFrom para que el server
   actualice la copia del owner correcta. */
function removeTrackFromPlaylist(pi, ti) {
    var pl = PLAYLISTS[pi];
    if (!pl) return;
    var newTracks = (pl.tracks || []).slice();
    if (ti < 0 || ti >= newTracks.length) return;
    newTracks.splice(ti, 1);
    var payload = { id: pl.id, name: pl.name, tracks: newTracks };
    if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
    apiPost('save-playlist-item', payload).then(function(res){
        if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al quitar'); return; }
        /* Si la canción quitada está sonando, paramos el visual highlight
           del track (los índices habrán cambiado). */
        loadPlaylists();
    });
}

/* Añade la canción al apartado "música" del perfil del usuario.
   El API de perfil hace UPSERT + DELETE de los items NO presentes en la
   petición — por eso primero hacemos GET para reenviarlos todos. */
var API_PROFILE = '../../assets/profile/api.php';
function profileApiGet(action) {
    return fetch(API_PROFILE + '?action=' + action, { credentials: 'same-origin' })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, status: r.status, data: d }; }); });
}
function profileApiPost(action, body) {
    return fetch(API_PROFILE + '?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    }).then(function(r){ return r.json().then(function(d){ return { ok: r.ok, status: r.status, data: d }; }); });
}

/* ── Medias de la comunidad (reseñas) para música ───────────────────
   Igual que en el reproductor de escritorio: carga una vez (cacheado) el
   mapa de medias de canciones/álbumes reseñados y pinta la nota junto a
   cada resultado / canción / pista de álbum. La nota exige que coincidan
   título Y artista. */
var _muRatings = null, _muRatingsPromise = null;
function _mrNorm(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }
function loadMusicRatings(force) {
    if (_muRatings && !force) return Promise.resolve(_muRatings);
    if (_muRatingsPromise && !force) return _muRatingsPromise;
    _muRatingsPromise = profileApiGet('music-ratings')
        .then(function(r){
            _muRatings = (r.ok && r.data && r.data.ok) ? r.data : { songs:{}, albums:{}, songsTitle:{}, albumsTitle:{} };
            return _muRatings;
        })
        .catch(function(){ _muRatings = { songs:{}, albums:{}, songsTitle:{}, albumsTitle:{} }; return _muRatings; });
    return _muRatingsPromise;
}
/* Devuelve {avg,count} o null. Exige título Y artista; solo cae a título
   solo si no se conoce el artista del resultado. */
function mrLookup(kind, title, artist) {
    if (!_muRatings) return null;
    var nt = _mrNorm(title); if (!nt) return null;
    var byTA = _muRatings[kind] || {};
    var na = _mrNorm(artist);
    if (na) return byTA[nt + '\x1f' + na] || null;
    var byT = _muRatings[kind === 'albums' ? 'albumsTitle' : 'songsTitle'] || {};
    return byT[nt] || null;
}
function mrBadge(rating) {
    if (!rating) return '';
    return '<span class="mu-sr-rating" title="' + rating.count + ' reseña' + (rating.count === 1 ? '' : 's') + '">★ ' + rating.avg.toFixed(1) + '</span>';
}
/* Reconstruye el lookup ytId → review desde MY_PROFILE_MUSIC. Lo llaman
   loadProfileMusic + saveProfileMusic tras cada cambio. */
function buildReviewsMap() {
    MY_REVIEWS_BY_YTID = {};
    MY_PROFILE_MUSIC.forEach(function(it){
        if (it && it.ytId && it.review && it.review.stars) {
            MY_REVIEWS_BY_YTID[it.ytId] = {
                musicId:    it.id,
                title:      it.title,
                stars:      it.review.stars,
                comment:    it.review.comment || '',
                reviewedAt: it.review.reviewedAt || null
            };
        }
    });
}
/* Carga la música del perfil al arrancar — corre en paralelo a las
   playlists y dispara un re-render si éstas ya están pintadas. */
function loadProfileMusic() {
    return profileApiGet('get-lists').then(function(res){
        if (!res.ok) return;
        var lists = res.data || {};
        MY_PROFILE_MUSIC = (Array.isArray(lists.music) ? lists.music : [])
            .filter(function(m){ return m && !m.sharedFrom; });
        buildReviewsMap();
        if (PLAYLISTS.length) renderPlaylists();
    });
}
/* Guarda la lista de música y refresca el cache local con la respuesta
   canónica del server (incluye los IDs nuevos asignados al INSERT). */
function saveProfileMusic(music, onSuccess) {
    return profileApiPost('save-lists', { category: 'music', items: music }).then(function(r){
        if (!r.ok) { muAlert((r.data && r.data.error) || 'Error guardando en tu perfil'); return; }
        var fresh = (r.data && Array.isArray(r.data.items)) ? r.data.items : music;
        MY_PROFILE_MUSIC = fresh.filter(function(m){ return m && !m.sharedFrom; });
        buildReviewsMap();
        if (PLAYLISTS.length) renderPlaylists();
        onSuccess && onSuccess(MY_PROFILE_MUSIC);
    });
}
/* Aplica una reseña a la canción del perfil con id `musicId`. Si
   newReview es null borra la reseña existente. */
function saveReviewToProfile(musicId, newReview, onSuccess) {
    profileApiGet('get-lists').then(function(res){
        if (!res.ok) { muAlert((res.data && res.data.error) || 'Error obteniendo el perfil'); return; }
        var lists = res.data || {};
        var music = (Array.isArray(lists.music) ? lists.music : [])
            .filter(function(m){ return m && !m.sharedFrom; });
        var idx = music.findIndex(function(m){ return m && m.id === musicId; });
        if (idx === -1) { muAlert('No se encontró la canción en tu perfil'); return; }
        if (newReview) music[idx].review = newReview;
        else           delete music[idx].review;
        saveProfileMusic(music, onSuccess);
    });
}

function addTrackToProfile(pi, ti) {
    var pl = PLAYLISTS[pi];
    if (!pl) return;
    var tr = (pl.tracks || [])[ti];
    if (!tr) return;
    profileApiGet('get-lists').then(function(res){
        if (!res.ok) { muAlert((res.data && res.data.error) || 'Error obteniendo el perfil'); return; }
        var lists = res.data || {};
        /* get-lists devuelve items propios + compartidos conmigo (con
           sharedFrom). Replico el patrón de perfil.php: opero solo sobre
           los propios — los compartidos viven en la tabla de collab y
           el server los respeta. */
        var music = (Array.isArray(lists.music) ? lists.music : [])
            .filter(function(m){ return m && !m.sharedFrom; });
        /* Evita duplicar la canción si ya está en el perfil (por ytId). */
        if (tr.videoId && music.some(function(m){ return m && m.ytId && m.ytId === tr.videoId; })) {
            muAlert('"' + (tr.title || tr.videoId) + '" ya está en tu perfil');
            return;
        }
        music.push({
            id:       'music_' + Date.now(),
            type:     'song',
            title:    tr.title  || tr.videoId || 'Canción',
            artist:   tr.artist || '',
            image:    tr.videoId ? ('https://i.ytimg.com/vi/' + tr.videoId + '/mqdefault.jpg') : '',
            featured: false,
            ytId:     tr.videoId || ''
        });
        saveProfileMusic(music, function(saved){
            /* Encuentra el item recién insertado (por ytId — único) para
               obtener el id numérico asignado por el server. */
            var savedItem = tr.videoId
                ? saved.find(function(m){ return m && m.ytId === tr.videoId; })
                : null;
            if (!savedItem) {
                muAlert('"' + (tr.title || 'Canción') + '" añadida a tu perfil', 'Añadido');
                return;
            }
            muReviewPrompt('"' + (tr.title || 'Canción') + '" añadida. ¿Quieres dejar una reseña?', function(){
                muOpenReviewEditor(savedItem.id, savedItem.title, savedItem.ytId);
            });
        });
    });
}

/* Prompt Sí/No para preguntar por la reseña tras añadir al perfil
   (replica el flujo del escritorio: showAddedMusicReviewPrompt). */
function muReviewPrompt(message, onYes) {
    var m = muOpenModal({
        title: '¿Añadir reseña?',
        body:
            '<p class="modal-msg">' + esc(message) + '</p>' +
            '<div class="modal-actions">' +
                '<button class="button" type="button" data-act="no">No, gracias</button>' +
                '<button class="button default" type="button" data-act="yes">★ Sí</button>' +
            '</div>'
    });
    m.body.querySelector('[data-act="no"]').addEventListener('click', m.close);
    m.body.querySelector('[data-act="yes"]').addEventListener('click', function(){
        m.close();
        onYes && onYes();
    });
}

/* Editor de reseña: estrellas táctiles (tap en mitad izquierda = media
   estrella), comentario, guardar / eliminar. Re-resuelve el item por
   musicId al guardar por si los IDs cambiaron entre tanto. */
function muOpenReviewEditor(musicId, title, ytId) {
    var current = ytId ? MY_REVIEWS_BY_YTID[ytId] : null;
    var sel = current ? current.stars : 0;
    var bodyHtml =
        '<p class="modal-msg" style="text-align:center;color:var(--text, #000);margin:0 0 4px;">' + esc(title || 'Canción') + '</p>' +
        '<div style="text-align:center;margin:10px 0 14px;">' +
            '<div id="re-stars"></div>' +
            '<div id="re-stars-num" style="font-size:14px;margin-top:6px;color:var(--text, #000);min-height:18px;font-weight:bold;"></div>' +
        '</div>' +
        '<label for="re-comment">Comentario (opcional)</label>' +
        '<textarea id="re-comment" rows="3" style="width:100%;box-sizing:border-box;resize:vertical;font-family:inherit;font-size:13px;" placeholder="Tu opinión..."></textarea>' +
        '<div class="modal-actions">' +
            (current ? '<button class="button" data-act="delete" type="button" style="margin-right:auto;color:var(--error-text, #c00);"><img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Eliminar</button>' : '') +
            '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
            '<button class="button default" data-act="save" type="button">Guardar</button>' +
        '</div>';
    var m = muOpenModal({ title: '★ Reseña', body: bodyHtml });
    var starsEl   = m.body.querySelector('#re-stars');
    var numEl     = m.body.querySelector('#re-stars-num');
    var commentEl = m.body.querySelector('#re-comment');
    if (current && current.comment) commentEl.value = current.comment;

    function paintStar(span, val, pos) {
        if (val >= pos)        { span.innerHTML = '★'; span.style.clipPath = ''; }
        else if (val >= pos - 0.5) { span.innerHTML = '★'; span.style.clipPath = 'inset(0 50% 0 0)'; }
        else                   { span.innerHTML = '☆'; span.style.clipPath = ''; }
    }
    function drawStars() {
        starsEl.innerHTML = '';
        for (var i = 1; i <= 5; i++) {
            var s = document.createElement('span');
            s.setAttribute('data-star', String(i));
            paintStar(s, sel, i);
            starsEl.appendChild(s);
        }
        numEl.textContent = sel > 0 ? String(sel) : '';
    }
    drawStars();
    /* Tap → setea estrellas. Half-star detection por posición relativa
       al span tocado (usamos el bounding rect para que funcione igual
       en touch synthesized clicks y en clicks normales). */
    starsEl.addEventListener('click', function(e){
        var target = e.target.closest('[data-star]');
        if (!target) return;
        var rect = target.getBoundingClientRect();
        var pos  = parseInt(target.getAttribute('data-star'), 10);
        var half = (e.clientX - rect.left) < rect.width / 2;
        sel = half ? pos - 0.5 : pos;
        drawStars();
    });

    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelector('[data-act="save"]').addEventListener('click', function(){
        if (!sel) { muAlert('Selecciona una puntuación'); return; }
        var review = {
            stars:      sel,
            comment:    (commentEl.value || '').trim(),
            reviewedAt: Math.floor(Date.now() / 1000)
        };
        saveReviewToProfile(musicId, review, m.close);
    });
    if (current) {
        m.body.querySelector('[data-act="delete"]').addEventListener('click', function(){
            muConfirm('¿Eliminar la reseña?', function(){
                saveReviewToProfile(musicId, null, m.close);
            });
        });
    }
}

/* ─── AÑADIR CANCIÓN ─────────────────────────────────────────── */
function detectMusicSource(url) {
    if (/youtu\.?be(?:\.com)?\//.test(url) || /^[A-Za-z0-9_-]{11}$/.test(url.trim())) return 'youtube';
    if (/spotify\.com|spotify:track:/.test(url)) return 'spotify';
    if (/tidal\.com/.test(url)) return 'tidal';
    return '';
}
function extractYouTubeId(url) {
    if (/^[A-Za-z0-9_-]{11}$/.test(url.trim())) return url.trim();
    var m = url.match(/(?:v=|youtu\.be\/|embed\/|shorts\/)([A-Za-z0-9_-]{11})/);
    return m ? m[1] : '';
}

function muOpenAddTrack(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl) return;
    var m = muOpenModal({
        title: 'Añadir canción a ' + (pl.name || ''),
        body:
            '<label for="at-url">URL de YouTube, Spotify o Tidal</label>' +
            '<input type="url" id="at-url" placeholder="https://..." autofocus>' +
            '<div id="at-preview"></div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                '<button class="button" data-act="fetch" type="button">Buscar</button>' +
                '<button class="button default" data-act="add" type="button" disabled>Añadir</button>' +
            '</div>'
    });
    var inputEl = m.body.querySelector('#at-url');
    var previewEl = m.body.querySelector('#at-preview');
    var addBtn = m.body.querySelector('[data-act="add"]');
    var fetchBtn = m.body.querySelector('[data-act="fetch"]');
    var track = null;

    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    fetchBtn.addEventListener('click', function(){
        var url = (inputEl.value || '').trim();
        if (!url) return;
        var src = detectMusicSource(url);
        previewEl.innerHTML = '<div class="modal-loading">Buscando…</div>';
        addBtn.disabled = true;
        track = null;

        function done(t){
            if (!t || !t.videoId) {
                previewEl.innerHTML = '<div class="modal-err">No se pudo obtener la canción</div>';
                return;
            }
            track = t;
            previewEl.innerHTML =
                '<div class="mu-modal-list" style="margin-top:8px;">' +
                  '<div class="mu-modal-list-item">' +
                    '<img class="item-icon" src="https://i.ytimg.com/vi/' + esc(t.videoId) + '/mqdefault.jpg" alt="">' +
                    '<div class="item-main"><strong>' + esc(t.title) + '</strong>' +
                        (t.artist ? '<br><span style="color:var(--text-faint, #666);font-size:11px;">' + esc(t.artist) + '</span>' : '') +
                    '</div>' +
                  '</div>' +
                '</div>';
            addBtn.disabled = false;
        }
        function fail(err){
            previewEl.innerHTML = '<div class="modal-err">' + esc(err || 'Error obteniendo metadata') + '</div>';
        }

        if (src === 'youtube') {
            var vid = extractYouTubeId(url);
            if (!vid) return fail('URL de YouTube no reconocida');
            Promise.all([
                apiGet('yt-title',    { id: vid }),
                apiGet('yt-duration', { id: vid })
            ]).then(function(rs){
                if (!rs[0].ok) return fail(rs[0].data.error || 'No se pudo obtener título');
                done({
                    videoId:  vid,
                    title:    rs[0].data.title || '',
                    artist:   rs[0].data.author || '',
                    duration: rs[1].ok ? (rs[1].data.duration || 0) : 0
                });
            });
        } else if (src === 'spotify') {
            apiGet('spotify-track', { url: url }).then(function(r){
                if (!r.ok) return fail(r.data.error || 'Error con Spotify');
                done(r.data);
            });
        } else if (src === 'tidal') {
            apiGet('tidal-track', { url: url }).then(function(r){
                if (!r.ok) return fail(r.data.error || 'Error con Tidal');
                done(r.data);
            });
        } else {
            fail('Origen no reconocido (YouTube/Spotify/Tidal)');
        }
    });

    addBtn.addEventListener('click', function(){
        if (!track) return;
        track.addedBy = ME_LABEL;
        var newTracks = (pl.tracks || []).slice();
        newTracks.push(track);
        var payload = { id: pl.id, name: pl.name, tracks: newTracks };
        if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
        apiPost('save-playlist-item', payload).then(function(res){
            m.close();
            if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al añadir'); return; }
            loadPlaylists();
        });
    });
}

/* ─── IMPORTAR PLAYLIST ───────────────────────────────────────────
   Primero un selector: Spotify (CSV de exportify.net) o Tidal/YouTube
   (URL). Cada opción abre su modal específico — Spotify no expone
   playlists públicas vía URL, así que su única vía es CSV. */
document.getElementById('mu-btn-import').addEventListener('click', function(){
    var items = [
        { act: 'spotify', icon: '🟢', label: 'Spotify',          sub: 'Sube un CSV de exportify.net' },
        { act: 'url',     icon: '🔗', label: 'Tidal / YouTube',  sub: 'Pega la URL de la playlist' }
    ];
    var bodyHtml = '<p class="modal-msg">¿De dónde quieres importar?</p>';
    bodyHtml += '<div class="mu-modal-list">';
    items.forEach(function(it){
        bodyHtml += '<div class="mu-modal-list-item" data-act="' + it.act + '" style="padding:10px 8px;">' +
                      '<div class="item-icon" style="background:transparent;color:inherit;font-size:18px;">' + it.icon + '</div>' +
                      '<div class="item-main">' +
                        '<div style="font-weight:bold;font-size:13px;">' + esc(it.label) + '</div>' +
                        '<div style="font-size:11px;color:var(--text-faint, #666);">' + esc(it.sub) + '</div>' +
                      '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>';
    bodyHtml += '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';

    var m = muOpenModal({ title: '⤓ Importar playlist', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'spotify') muOpenImportSpotify();
            if (act === 'url')     muOpenImportUrl();
        });
    });
});

/* Crea una playlist nueva con los tracks ya resueltos. Helper compartido
   entre los dos flujos de import. */
function muCreateImportedPlaylist(name, tracks, onDone) {
    tracks.forEach(function(t){ t.addedBy = ME_LABEL; });
    apiPost('save-playlist-item', { id: 'pl_' + Date.now(), name: name, tracks: tracks }).then(function(res){
        if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al guardar'); onDone && onDone(false); return; }
        loadPlaylists();
        onDone && onDone(true);
    });
}

/* Importar desde Tidal o YouTube por URL. */
function muOpenImportUrl() {
    var m = muOpenModal({
        title: 'Importar de Tidal / YouTube',
        body:
            '<p class="modal-msg">Pega la URL de una playlist de YouTube o Tidal.</p>' +
            '<label for="im-url">URL</label>' +
            '<input type="url" id="im-url" placeholder="https://..." autofocus>' +
            '<div id="im-status"></div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                '<button class="button default" data-act="ok" type="button">Importar</button>' +
            '</div>'
    });
    var inputEl  = m.body.querySelector('#im-url');
    var statusEl = m.body.querySelector('#im-status');
    var btnOk    = m.body.querySelector('[data-act="ok"]');
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);

    btnOk.addEventListener('click', function(){
        var url = (inputEl.value || '').trim();
        if (!url) return;
        btnOk.disabled = true;
        var src = /tidal\.com/.test(url) ? 'tidal' : 'youtube';
        statusEl.innerHTML = '<div class="modal-loading">Importando…</div>';

        function done(success) {
            if (success) m.close();
            else btnOk.disabled = false;
        }
        function resolveBatch(tracks, plName) {
            statusEl.innerHTML = '<div class="modal-loading">Buscando ' + tracks.length + ' canciones en YouTube…</div>';
            apiPost('yt-search-batch', { tracks: tracks }).then(function(r){
                if (!r.ok) { statusEl.innerHTML = '<div class="modal-err">' + esc(r.data.error || 'Error en búsqueda') + '</div>'; btnOk.disabled = false; return; }
                muCreateImportedPlaylist(plName, r.data.tracks || [], done);
            });
        }
        if (src === 'tidal') {
            apiPost('tidal-playlist', { url: url }).then(function(r){
                if (!r.ok) { statusEl.innerHTML = '<div class="modal-err">' + esc(r.data.error || 'Error con Tidal') + '</div>'; btnOk.disabled = false; return; }
                resolveBatch(r.data.tracks || [], r.data.name || 'Importada de Tidal');
            });
        } else {
            apiPost('import-playlist', { url: url }).then(function(r){
                if (!r.ok) { statusEl.innerHTML = '<div class="modal-err">' + esc(r.data.error || 'Error con YouTube') + '</div>'; btnOk.disabled = false; return; }
                /* import-playlist ya devuelve videoIds resueltos. */
                var tracks = (r.data.tracks || []).map(function(t){ t.addedBy = ME_LABEL; return t; });
                muCreateImportedPlaylist('Importada de YouTube', tracks, done);
            });
        }
    });
}

/* Importar desde Spotify vía CSV de exportify.net. Replica el flujo del
   escritorio: el CSV trae (title, artist) y resolvemos a videoIds de
   YouTube con yt-search-batch (por lotes de 10 con barra de progreso). */
function muOpenImportSpotify() {
    var m = muOpenModal({
        title: 'Importar de Spotify',
        body:
            '<p class="modal-msg">Exporta tu playlist desde <strong>exportify.net</strong> y selecciona el archivo CSV.</p>' +
            '<label for="im-sp-name">Nombre de la playlist</label>' +
            '<input type="text" id="im-sp-name" maxlength="100" placeholder="Importada de Spotify">' +
            '<label for="im-sp-file" style="margin-top:8px;">Archivo CSV</label>' +
            '<input type="file" id="im-sp-file" accept=".csv,text/csv">' +
            '<div id="im-sp-status" style="font-size:11px;color:var(--text-faint, #666);margin-top:6px;"></div>' +
            '<div id="im-sp-progress" style="display:none;margin-top:8px;">' +
                '<div style="background:var(--input-bg, #fff);border:1px inset var(--bezel-dark-2, grey);height:14px;position:relative;">' +
                    '<div id="im-sp-progress-fill" style="background:var(--accent, #000080);height:100%;width:0;transition:width 0.2s;"></div>' +
                '</div>' +
                '<div id="im-sp-progress-text" style="font-size:11px;margin-top:3px;color:var(--text, #000);"></div>' +
            '</div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                '<button class="button default" data-act="ok" type="button">Importar</button>' +
            '</div>'
    });
    var nameEl    = m.body.querySelector('#im-sp-name');
    var fileEl    = m.body.querySelector('#im-sp-file');
    var statusEl  = m.body.querySelector('#im-sp-status');
    var progEl    = m.body.querySelector('#im-sp-progress');
    var progFill  = m.body.querySelector('#im-sp-progress-fill');
    var progText  = m.body.querySelector('#im-sp-progress-text');
    var btnOk     = m.body.querySelector('[data-act="ok"]');
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);

    /* Parse RFC-4180-ish de la cabecera + filas de exportify (mismo
       algoritmo que reproductor.php). Solo necesitamos 'Track Name' y
       'Artist Name(s)'. */
    function parseExportifyCSV(raw) {
        function parseLine(line) {
            var cols = [], cur = '', inQ = false;
            for (var i = 0; i < line.length; i++) {
                var c = line[i];
                if (c === '"') { inQ = !inQ; }
                else if (c === ',' && !inQ) { cols.push(cur); cur = ''; }
                else { cur += c; }
            }
            cols.push(cur);
            return cols;
        }
        var lines  = raw.split('\n');
        if (!lines.length) return [];
        var header = parseLine(lines[0]);
        var tiIdx  = header.indexOf('Track Name');
        var arIdx  = header.indexOf('Artist Name(s)');
        if (tiIdx === -1) return [];
        var tracks = [];
        for (var i = 1; i < lines.length; i++) {
            if (!lines[i].trim()) continue;
            var cols   = parseLine(lines[i]);
            var title  = (cols[tiIdx] || '').trim();
            var artist = arIdx !== -1 ? (cols[arIdx] || '').trim() : '';
            if (title) tracks.push({ title: title, artist: artist, duration: 0 });
        }
        return tracks;
    }

    btnOk.addEventListener('click', function(){
        var file = fileEl.files && fileEl.files[0];
        if (!file) { statusEl.textContent = 'Selecciona un archivo CSV.'; return; }
        var plName = (nameEl.value || '').trim() || 'Importada de Spotify';
        btnOk.disabled = true;
        statusEl.textContent = 'Leyendo archivo…';
        var reader = new FileReader();
        reader.onload = function(e){
            var tracks = parseExportifyCSV(e.target.result);
            if (!tracks.length) {
                statusEl.textContent = 'No se encontraron canciones (¿es un CSV de exportify.net?).';
                btnOk.disabled = false;
                return;
            }
            statusEl.textContent = '';
            progEl.style.display = 'block';
            progFill.style.width = '0%';
            progText.textContent = '0 / ' + tracks.length;

            /* Resuelve en lotes de 10. Los lotes se concatenan en
               resolved[] y al terminar guardamos la playlist. */
            var BATCH = 10;
            var resolved = [];
            var idx = 0;
            function nextBatch() {
                if (idx >= tracks.length) {
                    progText.textContent = 'Guardando playlist…';
                    muCreateImportedPlaylist(plName, resolved, function(ok){
                        if (ok) m.close();
                        else btnOk.disabled = false;
                    });
                    return;
                }
                var slice = tracks.slice(idx, idx + BATCH);
                apiPost('yt-search-batch', { tracks: slice }).then(function(r){
                    if (!r.ok) {
                        statusEl.textContent = r.data && r.data.error ? r.data.error : 'Error en la búsqueda';
                        btnOk.disabled = false;
                        progEl.style.display = 'none';
                        return;
                    }
                    var found = r.data.tracks || [];
                    found.forEach(function(t){ if (t && t.videoId) resolved.push(t); });
                    idx += slice.length;
                    var pct = Math.round((idx / tracks.length) * 100);
                    progFill.style.width = pct + '%';
                    progText.textContent = idx + ' / ' + tracks.length + ' · ' + resolved.length + ' encontradas';
                    nextBatch();
                });
            }
            nextBatch();
        };
        reader.onerror = function(){
            statusEl.textContent = 'No se pudo leer el archivo';
            btnOk.disabled = false;
        };
        reader.readAsText(file);
    });
}

/* ─── COLABORADORES ─────────────────────────────────────────────── */
function muOpenCollabs(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl) return;
    var collabs = pl.collaborators || [];
    var others = USERS.filter(function(u){ return u.key !== ME_KEY; });

    var bodyHtml = '<p class="modal-msg">Gestiona quién puede editar "' + esc(pl.name) + '".</p>';
    bodyHtml += '<div class="mu-modal-list">';
    if (!others.length) bodyHtml += '<div class="modal-msg" style="padding:8px;">(no hay otros usuarios)</div>';
    others.forEach(function(u){
        var isCollab = collabs.indexOf(u.key) >= 0;
        var img = u.img;
        bodyHtml += '<div class="mu-modal-list-item">' +
                      (img
                        ? '<img class="item-icon" src="' + esc(img) + '" alt="">'
                        : '<div class="item-icon">' + esc(u.label.substring(0,1)) + '</div>') +
                      '<div class="item-main">' + esc(u.label) + '</div>' +
                      (isCollab
                        ? '<button class="button item-action" data-act="remove" data-key="' + esc(u.key) + '" type="button">Quitar</button>'
                        : '<button class="button item-action" data-act="invite" data-key="' + esc(u.key) + '" type="button">Invitar</button>') +
                    '</div>';
    });
    bodyHtml += '</div>';
    bodyHtml += '<div class="modal-actions"><button class="button" data-act="close" type="button">Cerrar</button></div>';

    var m = muOpenModal({ title: 'Colaboradores', body: bodyHtml });
    m.body.querySelector('[data-act="close"]').addEventListener('click', m.close);
    m.body.querySelectorAll('[data-act="invite"]').forEach(function(b){
        b.addEventListener('click', function(){
            apiPost('invite-collaborator', { playlistId: pl.id, toUser: b.dataset.key }).then(function(r){
                if (!r.ok) { muAlert((r.data && r.data.error) || 'Error al invitar'); return; }
                m.close();
                muAlert('Invitación enviada a ' + userLabelByKey(b.dataset.key));
            });
        });
    });
    m.body.querySelectorAll('[data-act="remove"]').forEach(function(b){
        b.addEventListener('click', function(){
            muConfirm('¿Quitar a ' + userLabelByKey(b.dataset.key) + ' de colaboradores?', function(){
                apiPost('remove-collaborator', { playlistId: pl.id, collaborator: b.dataset.key }).then(function(r){
                    if (!r.ok) { muAlert((r.data && r.data.error) || 'Error al quitar'); return; }
                    m.close();
                    loadPlaylists();
                });
            });
        });
    });
}

/* ─── ELIMINAR / ABANDONAR ─────────────────────────────────────── */
function muDeletePlaylist(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl) return;
    apiPost('delete-playlist', { id: pl.id }).then(function(r){
        if (!r.ok) { muAlert((r.data && r.data.error) || 'Error al eliminar'); return; }
        loadPlaylists();
    });
}
function muLeavePlaylist(idx) {
    var pl = PLAYLISTS[idx];
    if (!pl || !pl.sharedFrom) return;
    apiPost('leave-playlist', { id: pl.id, sharedFrom: pl.sharedFrom }).then(function(r){
        if (!r.ok) { muAlert((r.data && r.data.error) || 'Error al abandonar'); return; }
        loadPlaylists();
    });
}

/* ════════════════════════════════════════════════════════════════
   ÁLBUMES (paridad con desktop)
   ────────────────────────────────────────────────────────────────
   - Cache local por videoId.
   - Cola SERIAL con espaciado (300ms) para find-album: protege a
     Spotify del burst que provocaba Retry-After de 22h.
   - Resolver loop: tras render, resuelve el álbum de cada track
     visible y rellena `.mu-track-album` con su nombre.
   - Click en el título o en el nombre del álbum → abre album viewer.
   - Long-press sobre el nombre del álbum → menú con "Añadir álbum
     a mi perfil" / "Añadir álbum a otra playlist".
   - Viewer: cover, nombre, artista, lista de tracks. Click en un
     track del álbum lo reproduce en una QUEUE temporal.
   ════════════════════════════════════════════════════════════════ */

/* ── Cache local de álbumes por videoId. */
var MU_ALBUM_CACHE_KEY = 'mu:album-cache:v1';
var _muAlbumCache = null;
function _muLoadAlbumCache() {
    if (_muAlbumCache) return _muAlbumCache;
    try { _muAlbumCache = JSON.parse(localStorage.getItem(MU_ALBUM_CACHE_KEY) || '{}'); }
    catch (_) { _muAlbumCache = {}; }
    return _muAlbumCache;
}
function _muSaveAlbumCache() {
    try { localStorage.setItem(MU_ALBUM_CACHE_KEY, JSON.stringify(_muAlbumCache)); }
    catch (_) {}
}
function _muAlbumCacheGet(vid) { return vid ? _muLoadAlbumCache()[vid] : undefined; }
function _muAlbumCacheSet(vid, payload) {
    if (!vid) return;
    _muLoadAlbumCache()[vid] = payload;
    _muSaveAlbumCache();
}

/* ── Cola PARALELA con tope de concurrencia ──
   iTunes/Deezer responden rápido y sin rate-limit estricto; Spotify
   queda protegido por el mutex server-side (600 ms entre requests).
   Disparamos hasta MU_ALBUM_MAX_PARALLEL requests simultáneas al
   backend para que las playlists grandes terminen en pocos segundos. */
var MU_ALBUM_MAX_PARALLEL = 5;
var _muAlbumInFlight = 0;
var _muAlbumQueue    = [];
var _muAlbumQueuePaused = false; /* lock screen lo pone en true */
function _muAlbumNextSlot() {
    /* Tier 2 #6: si la cola está pausada (lock activo), no
       arrancamos nuevos jobs. Los pendientes esperan a que
       muLockClose() llame _muAlbumNextSlot() de nuevo. */
    if (_muAlbumQueuePaused) return;
    while (_muAlbumInFlight < MU_ALBUM_MAX_PARALLEL && _muAlbumQueue.length) {
        _muAlbumInFlight++;
        var job = _muAlbumQueue.shift();
        job().finally(function(){
            _muAlbumInFlight--;
            _muAlbumNextSlot();
        });
    }
}
function _muAlbumQueueRun(jobFn) {
    _muAlbumQueue.push(jobFn);
    _muAlbumNextSlot();
}

/* ── Normaliza el payload del backend: devuelve null para tracks sin
   álbum REAL (notFound / synthetic legacy / sin spotifyAlbumId). */
function _muNormalizeAlbumPayload(data) {
    if (!data || data.notFound) return null;
    /* Aceptamos albumKey con prefijo itunes:/deezer: ÚNICAMENTE.
       Las keys spotify:* y los legacy spotifyAlbumId quedan descartados
       (devolvemos null para que el caller dispare un find-album fresh
       contra iTunes/Deezer). Antes sintetizábamos 'spotify:' + id y se
       arrastraban viewers rotos. */
    var key = data.albumKey || '';
    if (!key) return null;
    if (key.indexOf('spotify:') === 0) return null;     /* IGNORA spotify */
    if (key.indexOf('synthetic:') === 0) return null;
    return {
        albumKey:       key,
        spotifyAlbumId: '',
        albumName:      data.albumName || '',
        albumImage:     data.albumImage || '',
        albumUrl:       data.albumUrl  || '',
        matchTitle:     data.matchTitle  || '',
        matchArtist:    data.matchArtist || ''
    };
}

/* ── Pinta el nombre del álbum en TODOS los spans .mu-track-album
   con el mismo data-vid. */
function _muPaintAlbumForVid(vid, norm) {
    var spans = document.querySelectorAll('.mu-track-album[data-vid="' + vid + '"]');
    for (var i = 0; i < spans.length; i++) {
        var sp = spans[i];
        if (!norm || !norm.albumName) {
            sp.textContent = '';
            sp.dataset.albumId   = '';
            sp.dataset.albumName = '';
        } else {
            sp.textContent       = norm.albumName;
            sp.dataset.albumId   = norm.albumKey || norm.spotifyAlbumId;
            sp.dataset.albumName = norm.albumName;
        }
    }
    /* Si el track activo (sonando ahora) coincide con este videoId,
       sincroniza también el título del player (mini + fullscreen) para
       que apunte al viewer de ESTE álbum. */
    if (typeof QUEUE !== 'undefined' && CUR_IDX >= 0 && QUEUE[CUR_IDX] && QUEUE[CUR_IDX].videoId === vid) {
        _muRefreshPlayerAlbumLink();
    }
}

/* ── Actualiza la clase + dataset de los dos títulos del player
   según el álbum cacheado del track activo. Llamado cuando cambia
   el track o cuando se resuelve un álbum nuevo del track sonando. */
function _muRefreshPlayerAlbumLink() {
    var miniTitle = document.getElementById('mu-now-title');
    var fullTitle = document.getElementById('mu-full-title');
    var cur = (typeof QUEUE !== 'undefined' && CUR_IDX >= 0) ? QUEUE[CUR_IDX] : null;
    var cached = cur ? _muAlbumCacheGet(cur.videoId) : null;
    var key  = cached ? (cached.albumKey || cached.spotifyAlbumId || '') : '';
    var name = cached ? (cached.albumName || '') : '';
    [miniTitle, fullTitle].forEach(function(el){
        if (!el) return;
        if (key) {
            el.classList.add('is-album-clickable');
            el.dataset.albumKey  = key;
            el.dataset.albumName = name;
            el.title = 'Ver álbum: ' + name;
        } else {
            el.classList.remove('is-album-clickable');
            el.dataset.albumKey  = '';
            el.dataset.albumName = '';
            el.title = '';
        }
    });
}

/* ── Resuelve el álbum de UN track: cache primero, fetch (en cola) si
   no hay cache, y pinta en TODOS los spans del DOM con ese videoId. */
function _muResolveAlbumForTrack(tr) {
    if (!tr || !tr.videoId) return;
    var vid = tr.videoId;
    var cached = _muAlbumCacheGet(vid);
    if (cached !== undefined) { _muPaintAlbumForVid(vid, cached); return; }
    var params = new URLSearchParams({
        title:   tr.title  || '',
        artist:  tr.artist || '',
        videoId: vid
    });
    _muAlbumQueueRun(function(){
        return fetch('../../assets/music/api.php?action=find-album&' + params.toString())
            .then(function(r){
                if (r.status === 503) return null;
                return r.ok ? r.json() : null;
            })
            .then(function(data){
                if (!data) return;
                var norm = _muNormalizeAlbumPayload(data);
                _muAlbumCacheSet(vid, norm || null);
                _muPaintAlbumForVid(vid, norm);
            })
            .catch(function(){});
    });
}

/* ── Bucle: tras cada render de playlists, resuelve los álbumes que
   aún no tienen cache. Throttled vía la cola serial. */
function _muResolveAllVisibleAlbums() {
    if (!PLAYLISTS || !PLAYLISTS.length) return;
    var seen = {};
    PLAYLISTS.forEach(function(pl){
        (pl.tracks || []).forEach(function(tr){
            if (!tr.videoId || seen[tr.videoId]) return;
            seen[tr.videoId] = true;
            _muResolveAlbumForTrack(tr);
        });
    });
}

/* Hook: piggyback en renderPlaylists si existe. */
(function hookRender(){
    if (typeof renderPlaylists !== 'function') return;
    var orig = renderPlaylists;
    renderPlaylists = function(){
        orig.apply(this, arguments);
        setTimeout(_muResolveAllVisibleAlbums, 0);
    };
})();

/* ── Album viewer: modal con cover + tracks. Cachea por albumId. */
var _muAlbumViewCache = {};
function muOpenAlbumFromTrack(tr) {
    /* Antes salíamos en silencio si no había videoId. Eso convertía un
       tap en el título en un no-op invisible para tracks sin videoId
       (importados de Spotify/Tidal sin match en YouTube). Ahora
       intentamos resolver con title+artist igual: el backend acepta
       fallback de búsqueda. */
    if (!tr) return;
    var cached = tr.videoId ? _muAlbumCacheGet(tr.videoId) : null;
    function openWithMeta(albumId, albumName) {
        if (!albumId) {
            muAlert('No se encontró un álbum para esta canción.');
            return;
        }
        _muOpenAlbumViewer(albumId, albumName);
    }
    if (cached && (cached.albumKey || cached.spotifyAlbumId)) {
        openWithMeta(cached.albumKey || cached.spotifyAlbumId, cached.albumName);
        return;
    }
    /* Sin cache (o cache vacía/marcada notFound): resolvemos AHORA
       (saltándose la cola para feedback inmediato al gesto del
       usuario). */
    var params = new URLSearchParams({
        title:   tr.title  || '',
        artist:  tr.artist || '',
        videoId: tr.videoId || ''
    });
    if (!params.get('title') && !params.get('artist') && !params.get('videoId')) {
        muAlert('No hay datos suficientes para buscar el álbum.');
        return;
    }
    fetch('../../assets/music/api.php?action=find-album&' + params.toString())
        .then(function(r){ return r.status === 503 ? null : (r.ok ? r.json() : null); })
        .then(function(data){
            var norm = _muNormalizeAlbumPayload(data);
            if (tr.videoId) _muAlbumCacheSet(tr.videoId, norm || null);
            if (tr.videoId) _muPaintAlbumForVid(tr.videoId, norm);
            if (!norm) {
                muAlert('No se encontró un álbum para esta canción.');
                return;
            }
            openWithMeta(norm.albumKey || norm.spotifyAlbumId, norm.albumName);
        })
        .catch(function(){ muAlert('Error de red al buscar el álbum.'); });
}

/* Estado del viewer fullscreen abierto (para el highlight de track
   activo y para que reproducir un track NO cierre la ventana). */
var _muAlbumFullCurrent = null; /* { albumId, album } o null */

/* El overlay del visor (se crea la primera vez y se reutiliza). Sirve
   de visor de álbum y de playlist compartida (muOpenSharedPlaylist):
   _muAlbumViewMode cambia título, acciones y "Reproducir". */
function _muAlbumFullview() {
    var fv = document.getElementById('mu-album-fullview');
    if (!fv) {
        /* Crea el overlay la primera vez. Reusable entre aperturas. */
        fv = document.createElement('div');
        fv.id = 'mu-album-fullview';
        fv.innerHTML =
            '<div class="window ma-titlebar">' +
                '<div class="title-bar">' +
                    '<div class="title-bar-text" id="mu-album-view-titlebar-text">' +
                        '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">' +
                        'Álbum' +
                    '</div>' +
                    '<div class="title-bar-controls">' +
                        '<button aria-label="Close" id="mu-album-view-close" type="button"></button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            /* Body interno que reproduce el layout del desktop:
               header + actions + tracks (panel hundido) + footer. */
            '<div class="mu-album-body">' +
                '<div class="mu-album-header">' +
                    '<img class="mu-album-cover" id="mu-album-view-cover" alt="">' +
                    '<div class="mu-album-info">' +
                        '<div class="mu-album-name" id="mu-album-view-name">Álbum</div>' +
                        '<div class="mu-album-artist" id="mu-album-view-artist"></div>' +
                    '</div>' +
                '</div>' +
                /* Acciones secundarias (las que en desktop viven en el
                   click-derecho del cover). En móvil las dejo visibles
                   porque el long-press tiene otra semántica. */
                '<div class="mu-album-actions">' +
                    '<button class="button" type="button" data-act="addProfile">+ Perfil</button>' +
                    '<button class="button" type="button" data-act="addPl">+ Playlist</button>' +
                    '<button class="button" type="button" data-act="savePl" style="display:none;">+ Guardar</button>' +
                    '<button class="button" type="button" data-act="link">🔗 Enlace</button>' +
                '</div>' +
                '<div class="mu-album-tracks" id="mu-album-view-tracks">' +
                    '<div class="album-viewer-msg" style="padding:14px;text-align:center;color:var(--text-muted,#888);">Cargando…</div>' +
                '</div>' +
                /* Footer: principal "Reproducir álbum" + secundario "Volver". */
                '<div class="mu-album-footer">' +
                    '<button class="button default play-album" type="button" data-act="playAlbum">' +
                        '<span style="font-family:\'Lucida Console\',monospace;font-size:9px;margin-right:3px;">▶</span> Reproducir álbum' +
                    '</button>' +
                    '<button class="button back-btn" type="button" data-act="back">‹ Volver</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(fv);

        document.getElementById('mu-album-view-close').addEventListener('click', _muCloseAlbumViewer);
        fv.querySelector('[data-act="back"]').addEventListener('click', _muCloseAlbumViewer);
        fv.querySelector('[data-act="playAlbum"]').addEventListener('click', function(){
            var cur = _muAlbumFullCurrent;
            if (cur && cur.shared) { _muPlaySharedFrom(0); return; }
            if (!cur || !cur.album || !cur.album.tracks || !cur.album.tracks.length) {
                muAlert('Aún cargando el álbum…');
                return;
            }
            /* Reproducir desde el principio, SIN cerrar el viewer. */
            _muPlayAlbumFrom(cur.album, 0);
        });
        fv.querySelector('[data-act="addProfile"]').addEventListener('click', function(){
            var cur = _muAlbumFullCurrent;
            if (!cur || !cur.album) { muAlert('Aún cargando el álbum…'); return; }
            muAddAlbumToProfile(cur.albumId, cur.album);
        });
        fv.querySelector('[data-act="link"]').addEventListener('click', function(){
            var cur = _muAlbumFullCurrent;
            if (!cur || !window.melonCopyLink) return;
            if (cur.shared) window.melonCopyLink('playlist', String(cur.playlistId));
            else if (_muAlbumKeyLinkable(cur.albumId)) window.melonCopyLink('album', cur.albumId);
        });
        fv.querySelector('[data-act="savePl"]').addEventListener('click', function(){
            var cur = _muAlbumFullCurrent;
            if (cur && cur.shared) _muSaveShared(this);
        });
        fv.querySelector('[data-act="addPl"]').addEventListener('click', function(){
            var cur = _muAlbumFullCurrent;
            if (!cur || !cur.album) { muAlert('Aún cargando el álbum…'); return; }
            muAddAlbumToPlaylist(cur.album);
        });
    }

    return fv;
}

function _muOpenAlbumViewer(albumId, albumName, albumArtist) {
    var fv = _muAlbumFullview();
    _muAlbumViewMode('album', albumId);
    /* Estado inicial: cabecera con lo que sabemos, lista en "Cargando…". */
    _muAlbumFullCurrent = { albumId: albumId, album: null };
    document.getElementById('mu-album-view-name').textContent = albumName || 'Álbum';
    document.getElementById('mu-album-view-artist').textContent = '';
    document.getElementById('mu-album-view-cover').src = '';
    document.getElementById('mu-album-view-tracks').innerHTML =
        '<div style="padding:14px;text-align:center;color:var(--text-faint,#888);">Cargando…</div>';
    fv.classList.add('is-open');

    function applyAlbum(album) {
        _muAlbumFullCurrent = { albumId: albumId, album: album };
        document.getElementById('mu-album-view-cover').src = album.image || '';
        document.getElementById('mu-album-view-name').textContent = album.name || albumName || 'Álbum';
        document.getElementById('mu-album-view-artist').textContent = album.artist || '';
        var tracksEl = document.getElementById('mu-album-view-tracks');
        tracksEl.innerHTML = '';
        if (!album.tracks || !album.tracks.length) {
            tracksEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-faint,#888);">Álbum sin canciones.</div>';
            return;
        }
        album.tracks.forEach(function(t, idx){
            var row = document.createElement('div');
            row.className = 'mu-album-track';
            row.dataset.idx = String(idx);
            row.dataset.title = t.title || '';
            row.dataset.artist = t.artist || album.artist || '';
            row.innerHTML =
                '<div class="mu-album-track-num">' + (idx + 1) + '</div>' +
                '<div class="mu-album-track-title">' + esc(t.title || '') +
                    (t.artist ? ' <span style="color:var(--text-faint,#888);">— ' + esc(t.artist) + '</span>' : '') +
                '</div>' +
                '<div class="mu-album-track-dur">' + (t.duration ? fmtDur(t.duration) : '—') + '</div>';
            tracksEl.appendChild(row);
        });
        _muHighlightAlbumPlayingRow();
        /* Notas de la comunidad: media del álbum (sus reseñas) en la
           cabecera y media de cada canción reseñada delante de su tiempo
           (las reseñas de canción van por separado, no en la del álbum). */
        loadMusicRatings().then(function(){
            tracksEl.querySelectorAll('.mu-album-track').forEach(function(row){
                if (row.querySelector('.mu-sr-rating')) return;
                var rt = mrLookup('songs', row.dataset.title, row.dataset.artist || album.artist);
                if (!rt) return;
                var durEl = row.querySelector('.mu-album-track-dur');
                if (durEl) durEl.insertAdjacentHTML('beforebegin', mrBadge(rt));
                else row.insertAdjacentHTML('beforeend', mrBadge(rt));
            });
            var own = mrLookup('albums', album.name || albumName, album.artist);
            var artEl = document.getElementById('mu-album-view-artist');
            if (own && artEl && !artEl.querySelector('.mu-sr-rating')) {
                artEl.insertAdjacentHTML('beforeend', ' ' + mrBadge(own));
            }
        });
    }

    if (_muAlbumViewCache[albumId]) {
        applyAlbum(_muAlbumViewCache[albumId]);
    } else {
        /* Hints para el fallback de album-tracks cuando es spotify:* y
           Spotify está banneado — el server intenta encontrar el mismo
           álbum por nombre + artista en iTunes/Deezer. Sin esto, los
           álbumes spotify: guardados antes del ban quedan "rotos". */
        var trackUrl = '../../assets/music/api.php?action=album-tracks&' + ((typeof albumId === 'string' && albumId.indexOf(':') !== -1) ? 'key=' : 'id=') + encodeURIComponent(albumId);
        if (albumName)   trackUrl += '&name='   + encodeURIComponent(albumName);
        if (albumArtist) trackUrl += '&artist=' + encodeURIComponent(albumArtist);
        fetch(trackUrl)
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(data){
                if (!data || data.error) {
                    document.getElementById('mu-album-view-tracks').innerHTML =
                        '<div style="padding:14px;text-align:center;color:var(--error-text,#c00);">No se pudo cargar el álbum.</div>';
                    return;
                }
                _muAlbumViewCache[albumId] = data;
                applyAlbum(data);
            })
            .catch(function(){
                document.getElementById('mu-album-view-tracks').innerHTML =
                    '<div style="padding:14px;text-align:center;color:var(--error-text,#c00);">Error de red.</div>';
            });
    }
}

/* Solo se comparten álbumes con clave real (itunes:/deezer:/spotify:). */
function _muAlbumKeyLinkable(k) {
    return typeof k === 'string' && /^[a-z]+:[A-Za-z0-9_-]+$/.test(k) && k.indexOf('synthetic:') !== 0;
}

function _muAlbumViewMode(mode, albumId) {
    var fv = _muAlbumFullview();
    var pl = mode === 'playlist';
    var t = document.getElementById('mu-album-view-titlebar-text');
    /* El texto va tras el icono (el título solo lleva icono + palabra). */
    if (t) {
        var img = t.querySelector('img');
        t.textContent = pl ? 'Playlist' : 'Álbum';
        if (img) t.insertBefore(img, t.firstChild);
    }
    fv.querySelector('[data-act="addProfile"]').style.display = pl ? 'none' : '';
    fv.querySelector('[data-act="addPl"]').style.display      = pl ? 'none' : '';
    var save = fv.querySelector('[data-act="savePl"]');
    save.style.display = 'none';
    save.disabled = false;
    save.textContent = '+ Guardar';
    fv.querySelector('[data-act="link"]').style.display = (pl || _muAlbumKeyLinkable(albumId)) ? '' : 'none';
    fv.querySelector('[data-act="playAlbum"]').lastChild.textContent = pl ? ' Reproducir' : ' Reproducir álbum';
}

/* Playlist de otra persona abierta desde un enlace (?playlist=N, lo pone
   mobile.php): solo lectura, se reproduce como cola suelta y "+ Guardar"
   la copia en mis playlists. Igual que openSharedPlaylistViewer en el
   reproductor del escritorio. */
function muOpenSharedPlaylist(playlistId) {
    var fv = _muAlbumFullview();
    _muAlbumViewMode('playlist');
    _muAlbumFullCurrent = { shared: true, playlistId: playlistId, tracks: [], pl: null };
    var tracksEl = document.getElementById('mu-album-view-tracks');
    document.getElementById('mu-album-view-name').textContent = 'Cargando…';
    document.getElementById('mu-album-view-artist').textContent = '';
    document.getElementById('mu-album-view-cover').src = '';
    tracksEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-faint,#888);">Cargando…</div>';
    fv.classList.add('is-open');
    fetch('../../assets/music/api.php?action=shared-playlist&id=' + encodeURIComponent(playlistId), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .catch(function(){ return null; })
        .then(function(pl){
            var cur = _muAlbumFullCurrent;
            if (!cur || !cur.shared || cur.playlistId !== playlistId) return;
            if (!pl || !pl.ok) {
                document.getElementById('mu-album-view-name').textContent = 'Playlist';
                tracksEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--error-text,#c00);"></div>';
                tracksEl.firstChild.textContent = (pl && pl.error) || 'No se pudo abrir la playlist.';
                return;
            }
            var tracks = (pl.tracks || []).filter(function(t){ return t && t.videoId; });
            cur.pl = pl;
            cur.tracks = tracks;
            document.getElementById('mu-album-view-name').textContent = pl.name || 'Playlist';
            document.getElementById('mu-album-view-artist').textContent = pl.mine ? '' : 'Playlist de ' + pl.ownerLabel;
            document.getElementById('mu-album-view-cover').src = pl.image || (tracks[0] ? 'https://i.ytimg.com/vi/' + tracks[0].videoId + '/mqdefault.jpg' : '');
            if (!pl.mine && tracks.length) fv.querySelector('[data-act="savePl"]').style.display = '';
            tracksEl.innerHTML = '';
            if (!tracks.length) {
                tracksEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-faint,#888);">Esta playlist está vacía.</div>';
                return;
            }
            tracks.forEach(function(t, idx){
                var row = document.createElement('div');
                row.className = 'mu-album-track';
                row.innerHTML =
                    '<div class="mu-album-track-num">' + (idx + 1) + '</div>' +
                    '<div class="mu-album-track-title">' + esc(t.title || '') +
                        (t.artist ? ' <span style="color:var(--text-faint,#888);">— ' + esc(t.artist) + '</span>' : '') +
                    '</div>' +
                    '<div class="mu-album-track-dur">' + (t.duration ? fmtDur(t.duration) : '—') + '</div>';
                row.addEventListener('click', function(){ _muPlaySharedFrom(idx); });
                tracksEl.appendChild(row);
            });
        });
}

function _muPlaySharedFrom(i) {
    var cur = _muAlbumFullCurrent;
    if (!cur || !cur.shared || !cur.tracks.length) return;
    QUEUE = cur.tracks.map(function(t){ return { videoId: t.videoId, title: t.title, artist: t.artist || '', duration: t.duration || 0 }; });
    CUR_IDX = Math.max(0, Math.min(i, QUEUE.length - 1));
    CUR_PL_IDX = -1;
    applyPlayingHighlight(null, null);
    var name = (cur.pl && cur.pl.name) || 'Playlist';
    if (SHELL) { SHELL.loadQueue(QUEUE, CUR_IDX, name); return; }
    playCurrent();
}

function _muSaveShared(btn) {
    var cur = _muAlbumFullCurrent;
    if (!cur || !cur.pl) return;
    btn.disabled = true;
    apiPost('save-playlist-item', {
        id: 'pl_' + Date.now(), name: cur.pl.name, image: cur.pl.image || '',
        tracks: cur.tracks.map(function(t){ return { videoId: t.videoId, title: t.title, artist: t.artist || '', duration: t.duration || 0, addedBy: '' }; })
    }).then(function(res){
        if (!res.ok) { btn.disabled = false; muAlert((res.data && res.data.error) || 'No se pudo guardar'); return; }
        btn.textContent = '✓ Guardada';
        loadPlaylists();
    });
}

function _muCloseAlbumViewer() {
    var fv = document.getElementById('mu-album-fullview');
    if (fv) fv.classList.remove('is-open');
    _muAlbumFullCurrent = null;
}

/* ════════════════ BÚSQUEDA GLOBAL (canciones / álbumes / artistas) ═══════
   Portado del escritorio. Overlay fullscreen con input + 3 secciones.
   Click: canción → reproduce; álbum → album viewer; artista → vista de
   artista. */
var _muSearchSeq = 0, _muSearchTimer = null;

function _muDedupe(arr, keyFn) {
    var seen = {}, out = [];
    (arr || []).forEach(function(x){ var k = keyFn(x); if (k && !seen[k]) { seen[k] = 1; out.push(x); } });
    return out;
}
function _muFmtNum(n) {
    n = parseInt(n, 10) || 0;
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(n);
}

function muOpenSearch() {
    var sv = document.getElementById('mu-search-view');
    if (!sv) {
        sv = document.createElement('div');
        sv.id = 'mu-search-view';
        sv.innerHTML =
            /* Mismo chrome que la pantalla de playlists: ventana con borde
               (.mh-window) → window-body → panel hundido (.mh-panel) con
               todos los items dentro + statusbar al pie. */
            '<div class="window mh-window">' +
                '<div class="title-bar">' +
                    '<div class="title-bar-text">' +
                        '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Buscar' +
                    '</div>' +
                    '<div class="title-bar-controls">' +
                        '<button aria-label="Minimize" type="button"></button>' +
                        '<button aria-label="Maximize" type="button"></button>' +
                        '<button aria-label="Close" id="mu-search-close" type="button"></button>' +
                    '</div>' +
                '</div>' +
                '<div class="window-body">' +
                    '<div class="mu-sr-searchbar"><input type="text" id="mu-search-input" autocomplete="off" placeholder="Canciones, álbumes, artistas…"></div>' +
                    '<div class="mh-panel mu-sr-results" id="mu-search-results"><div class="mu-sr-msg">Escribe para buscar.</div></div>' +
                    '<div class="mh-statusbar"><button type="button" data-act="back">‹ Volver</button></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(sv);
        document.getElementById('mu-search-close').addEventListener('click', muCloseSearch);
        sv.querySelector('[data-act="back"]').addEventListener('click', muCloseSearch);
        document.getElementById('mu-search-input').addEventListener('input', function(){
            if (_muSearchTimer) clearTimeout(_muSearchTimer);
            _muSearchTimer = setTimeout(_muRunSearch, 350);
        });
        document.getElementById('mu-search-results').addEventListener('click', function(e){
            var row = e.target.closest && e.target.closest('.mu-sr-row');
            if (!row) return;
            var type = row.dataset.type;
            if (type === 'song') {
                _muPlayAlbumFrom({ name: row.dataset.title || '', tracks: [{
                    title: row.dataset.title || '', artist: row.dataset.artist || '',
                    duration: parseInt(row.dataset.dur || '0', 10), videoId: row.dataset.vid
                }] }, 0);
            } else if (type === 'album') {
                _muOpenAlbumViewer(row.dataset.key, row.dataset.name, row.dataset.artist || '');
            } else if (type === 'artist') {
                muOpenArtistView(row.dataset.name);
            }
        });
    }
    sv.classList.add('is-open');
    setTimeout(function(){ var i = document.getElementById('mu-search-input'); if (i) i.focus(); }, 60);
}
function muCloseSearch() {
    var sv = document.getElementById('mu-search-view');
    if (sv) sv.classList.remove('is-open');
}
function _muRunSearch() {
    var inp = document.getElementById('mu-search-input');
    var resEl = document.getElementById('mu-search-results');
    if (!inp || !resEl) return;
    var q = (inp.value || '').trim();
    if (q.length < 2) { resEl.innerHTML = '<div class="mu-sr-msg">Escribe para buscar.</div>'; return; }
    var seq = ++_muSearchSeq;
    resEl.innerHTML = '<div class="mu-sr-msg">Buscando…</div>';
    Promise.all([
        apiGet('yt-search',      { q: q }).catch(function(){ return null; }),
        apiGet('search-albums',  { q: q }).catch(function(){ return null; }),
        apiGet('search-artists', { q: q }).catch(function(){ return null; })
    ]).then(function(rs){
        if (seq !== _muSearchSeq) return;
        var songs   = (rs[0] && rs[0].data && rs[0].data.results) || [];
        var albums  = (rs[1] && rs[1].data && rs[1].data.results) || [];
        var artists = (rs[2] && rs[2].data && rs[2].data.results) || [];
        artists = _muDedupe(artists.filter(function(a){ return a && a.image; }), function(a){ return (a.name || '').toLowerCase(); });
        albums  = _muDedupe(albums.filter(function(a){ return a && a.image; }),  function(a){ return (a.albumKey || '') || ((a.name || '') + '|' + (a.artist || '')).toLowerCase(); });
        songs   = _muDedupe(songs, function(s){ return s.videoId; });
        var html = '';
        if (artists.length) {
            html += '<div class="mu-sr-group-title">Artistas</div>';
            artists.slice(0, 6).forEach(function(a){
                html += '<div class="mu-sr-row" data-type="artist" data-name="' + esc(a.name || '') + '">' +
                    '<img class="mu-sr-thumb is-artist" src="' + esc(a.image || '') + '" alt="">' +
                    '<div class="mu-sr-info"><div class="mu-sr-name">' + esc(a.name || '') + '</div><div class="mu-sr-sub">Artista</div></div></div>';
            });
        }
        if (albums.length) {
            html += '<div class="mu-sr-group-title">Álbumes</div>';
            albums.slice(0, 8).forEach(function(a){
                html += '<div class="mu-sr-row" data-type="album" data-key="' + esc(a.albumKey || '') + '" data-name="' + esc(a.name || '') + '" data-artist="' + esc(a.artist || '') + '">' +
                    '<img class="mu-sr-thumb" src="' + esc(a.image || '') + '" alt="">' +
                    '<div class="mu-sr-info"><div class="mu-sr-name">' + esc(a.name || '') + '</div><div class="mu-sr-sub">' + esc(a.artist || '') + '</div></div></div>';
            });
        }
        if (songs.length) {
            html += '<div class="mu-sr-group-title">Canciones</div>';
            songs.slice(0, 12).forEach(function(s){
                var sdur = s.duration ? fmtDur(s.duration) : '';
                html += '<div class="mu-sr-row" data-type="song" data-vid="' + esc(s.videoId || '') + '" data-title="' + esc(s.title || '') + '" data-artist="' + esc(s.artist || '') + '" data-dur="' + (s.duration || 0) + '">' +
                    '<img class="mu-sr-thumb" src="https://i.ytimg.com/vi/' + esc(s.videoId || '') + '/default.jpg" alt="">' +
                    '<div class="mu-sr-info"><div class="mu-sr-name">' + esc(s.title || '') + '</div><div class="mu-sr-sub">' + esc(s.artist || '') + '</div></div>' +
                    (sdur ? '<span class="mu-sr-time">' + sdur + '</span>' : '') + '</div>';
            });
        }
        if (!html) html = '<div class="mu-sr-msg">Sin resultados.</div>';
        resEl.innerHTML = html;
        muDecorateSearchRatings(resEl);
    });
}
/* Pinta las notas de la comunidad en los resultados de búsqueda: canción
   (delante del tiempo) y álbum (al final, exigiendo artista). */
function muDecorateSearchRatings(container) {
    if (!container) return;
    loadMusicRatings().then(function(){
        container.querySelectorAll('.mu-sr-row[data-type="song"]').forEach(function(row){
            if (row.querySelector('.mu-sr-rating')) return;
            var r = mrLookup('songs', row.dataset.title, row.dataset.artist);
            if (!r) return;
            var timeEl = row.querySelector('.mu-sr-time');
            if (timeEl) timeEl.insertAdjacentHTML('beforebegin', mrBadge(r));
            else row.insertAdjacentHTML('beforeend', mrBadge(r));
        });
        container.querySelectorAll('.mu-sr-row[data-type="album"]').forEach(function(row){
            if (row.querySelector('.mu-sr-rating')) return;
            var r = mrLookup('albums', row.dataset.name, row.dataset.artist);
            if (r) row.insertAdjacentHTML('beforeend', mrBadge(r));
        });
    });
}

/* ════════════════ VISTA DE ARTISTA (estilo Spotify) ════════════════════
   Portada del escritorio: banner + oyentes, "Popular", y discografía con
   3 pestañas (Populares / Álbumes / Singles y EP). */
var _muAwAlbums = [], _muAwTab = 'popular', _muAwName = '', _muAwSeq = 0;

function muOpenArtistView(name) {
    if (!name) return;
    var av = document.getElementById('mu-artist-view');
    if (!av) {
        av = document.createElement('div');
        av.id = 'mu-artist-view';
        av.innerHTML =
            /* Mismo chrome que la pantalla de playlists: ventana con borde
               (.mh-window) → window-body → panel hundido (.mh-panel) con
               todo el contenido del artista dentro + statusbar al pie. */
            '<div class="window mh-window">' +
                '<div class="title-bar">' +
                    '<div class="title-bar-text" id="mu-aw-titlebar">Artista</div>' +
                    '<div class="title-bar-controls">' +
                        '<button aria-label="Minimize" type="button"></button>' +
                        '<button aria-label="Maximize" type="button"></button>' +
                        '<button aria-label="Close" id="mu-aw-close" type="button"></button>' +
                    '</div>' +
                '</div>' +
                '<div class="window-body">' +
                    '<div class="mh-panel mu-aw-body">' +
                        '<div class="mu-aw-banner" id="mu-aw-banner">' +
                            '<div class="mu-aw-banner-name" id="mu-aw-name">Artista</div>' +
                            '<div class="mu-aw-listeners" id="mu-aw-listeners"></div>' +
                        '</div>' +
                        '<div class="mu-aw-section-title">Popular</div>' +
                        '<div id="mu-aw-top"><div class="mu-sr-msg">Cargando…</div></div>' +
                        '<div class="mu-aw-section-title">Discografía</div>' +
                        '<div class="mu-aw-albums" id="mu-aw-albums"><div class="mu-sr-msg">Cargando…</div></div>' +
                    '</div>' +
                    '<div class="mh-statusbar"><button type="button" data-act="back">‹ Volver</button></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(av);
        document.getElementById('mu-aw-close').addEventListener('click', muCloseArtistView);
        av.querySelector('[data-act="back"]').addEventListener('click', muCloseArtistView);
        document.getElementById('mu-aw-top').addEventListener('click', function(e){
            var row = e.target.closest && e.target.closest('.mu-aw-top-row');
            if (!row) return;
            _muPlayAlbumFrom({ name: row.dataset.title || '', tracks: [{
                title: row.dataset.title || '', artist: row.dataset.artist || '',
                duration: parseInt(row.dataset.dur || '0', 10)
            }] }, 0);
        });
        document.getElementById('mu-aw-albums').addEventListener('click', function(e){
            if (e.target.closest && e.target.closest('#mu-aw-disco-open')) { muOpenDiscography(); return; }
            var row = e.target.closest && e.target.closest('.mu-aw-rel');
            if (!row) return;
            _muOpenAlbumViewer(row.dataset.key, row.dataset.name, _muAwName);
        });
    }
    _muAwName = name; _muAwTab = 'popular'; _muAwAlbums = [];
    document.getElementById('mu-aw-titlebar').textContent = name;
    document.getElementById('mu-aw-name').textContent = name;
    document.getElementById('mu-aw-listeners').textContent = '';
    document.getElementById('mu-aw-banner').style.backgroundImage = '';
    document.getElementById('mu-aw-top').innerHTML = '<div class="mu-sr-msg">Cargando…</div>';
    document.getElementById('mu-aw-albums').innerHTML = '<div class="mu-sr-msg">Cargando…</div>';
    av.classList.add('is-open');
    var seq = ++_muAwSeq;

    /* 1) Metadatos del artista: banner (foto), oyentes y source/artistId
       para luego pedir la discografía. */
    apiGet('search-artists', { q: name }).then(function(r){
        if (seq !== _muAwSeq) return;
        var list = (r.data && r.data.results) || [];
        var lower = name.toLowerCase();
        var withImg = list.filter(function(a){ return a && a.image; });
        var chosen = withImg.filter(function(a){ return (a.name || '').toLowerCase() === lower; })[0] || withImg[0] || list[0];
        if (!chosen) {
            document.getElementById('mu-aw-albums').innerHTML = '<div class="mu-sr-msg">Sin discografía.</div>';
            return;
        }
        /* Los oyentes (nº de fans) solo los da Deezer; si el elegido no los
           trae, los tomamos de la entrada de Deezer con el mismo nombre. */
        if (!chosen.fans) {
            var dz = list.filter(function(a){ return a.source === 'deezer' && (a.name || '').toLowerCase() === (chosen.name || '').toLowerCase() && a.fans; })[0];
            if (dz) chosen = { source: chosen.source, artistId: chosen.artistId, name: chosen.name,
                               image: chosen.image || dz.image, imageBig: chosen.imageBig || dz.imageBig, fans: dz.fans };
        }
        var img = chosen.imageBig || chosen.image || '';
        if (img) document.getElementById('mu-aw-banner').style.backgroundImage = 'url("' + img.replace(/"/g, '') + '")';
        if (chosen.fans) document.getElementById('mu-aw-listeners').textContent = _muFmtNum(chosen.fans) + ' oyentes';
        if (chosen.source && chosen.artistId) {
            apiGet('artist-albums', { source: chosen.source, artistId: chosen.artistId }).then(function(ra){
                if (seq !== _muAwSeq) return;
                var albums = (ra.data && ra.data.albums) || [];
                _muAwAlbums = _muDedupe(albums.filter(function(a){ return a && a.image; }), function(a){ return (a.name || '').toLowerCase() + '|' + (a.year || ''); });
                _muAwRenderAlbums();
            });
        } else {
            _muAwAlbums = []; _muAwRenderAlbums();
        }
    });

    /* 2) Canciones populares. */
    apiGet('artist-top', { name: name }).then(function(r){
        if (seq !== _muAwSeq) return;
        var tracks = (r.data && r.data.tracks) || [];
        var topEl = document.getElementById('mu-aw-top');
        if (!tracks.length) { topEl.innerHTML = '<div class="mu-sr-msg">Sin canciones populares.</div>'; return; }
        var html = '';
        tracks.forEach(function(t, i){
            html += '<div class="mu-aw-top-row" data-title="' + esc(t.title || '') + '" data-artist="' + esc(t.artist || name) + '" data-dur="' + (t.duration || 0) + '">' +
                '<div class="mu-aw-top-num">' + (i + 1) + '</div>' +
                '<img class="mu-aw-top-thumb" src="' + esc(t.image || '') + '" alt="">' +
                '<div class="mu-aw-top-main"><div class="mu-aw-top-title">' + esc(t.title || '') + '</div>' +
                    (t.rank ? '<div class="mu-aw-top-sub">' + _muFmtNum(t.rank) + ' reproducciones</div>' : '') +
                '</div>' +
                '<div class="mu-aw-top-dur">' + (t.duration ? fmtDur(t.duration) : '') + '</div></div>';
        });
        topEl.innerHTML = html;
        /* Nota media de la comunidad, delante del tiempo de cada canción. */
        loadMusicRatings().then(function(){
            topEl.querySelectorAll('.mu-aw-top-row').forEach(function(row){
                if (row.querySelector('.mu-sr-rating')) return;
                var r = mrLookup('songs', row.dataset.title, row.dataset.artist);
                if (!r) return;
                var durEl = row.querySelector('.mu-aw-top-dur');
                if (durEl) durEl.insertAdjacentHTML('beforebegin', mrBadge(r));
                else row.insertAdjacentHTML('beforeend', mrBadge(r));
            });
        });
    });
}
function muCloseArtistView() {
    var dv = document.getElementById('mu-disco-view');
    if (dv) dv.classList.remove('is-open');
    var av = document.getElementById('mu-artist-view');
    if (av) av.classList.remove('is-open');
}

/* Etiqueta legible del tipo de lanzamiento. */
function _muRelTypeLabel(t) {
    if (t === 'album') return 'Álbum';
    if (t === 'ep')    return 'EP';
    return 'Sencillo';   /* single y desconocidos */
}
/* Fila de lanzamiento: carátula + nombre + "Tipo • Año". */
function _muRelRowHtml(a) {
    var sub = _muRelTypeLabel(a.type) + (a.year ? ' • ' + esc(String(a.year)) : '');
    return '<div class="mu-aw-rel" data-key="' + esc(a.albumKey || '') + '" data-name="' + esc(a.name || '') + '">' +
        '<img class="mu-aw-rel-cover" src="' + esc(a.image || '') + '" alt="" loading="lazy">' +
        '<div class="mu-aw-rel-main">' +
            '<div class="mu-aw-rel-name">' + esc(a.name || '') + '</div>' +
            '<div class="mu-aw-rel-sub">' + sub + '</div>' +
        '</div>' +
    '</div>';
}
/* Ordena lanzamientos del más reciente al más antiguo (por año). */
function _muSortReleases(list) {
    return (list || []).slice().sort(function(a, b){
        return (parseInt(b.year, 10) || 0) - (parseInt(a.year, 10) || 0);
    });
}

/* Sección "Discografía" del artista: vista previa de los lanzamientos
   más recientes como lista + botón "Ver discografía" para abrir la
   página completa. */
function _muAwRenderAlbums() {
    var el = document.getElementById('mu-aw-albums');
    if (!el) return;
    var list = _muSortReleases(_muAwAlbums);
    if (!list.length) { el.innerHTML = '<div class="mu-sr-msg">Sin discografía.</div>'; return; }
    var preview = list.slice(0, 5);
    var html = '<div class="mu-aw-rel-list">';
    preview.forEach(function(a){ html += _muRelRowHtml(a); });
    html += '</div>';
    html += '<div class="mu-aw-disco-btn-wrap">' +
              '<button class="button mu-aw-disco-btn" id="mu-aw-disco-open" type="button">Ver discografía</button>' +
            '</div>';
    el.innerHTML = html;
    muDecorateAlbumRows(el, _muAwName);
}
/* Pinta la nota del álbum (sus reseñas) en filas .mu-aw-rel, exigiendo
   que el artista coincida con el de la página. */
function muDecorateAlbumRows(container, artistName) {
    if (!container) return;
    loadMusicRatings().then(function(){
        container.querySelectorAll('.mu-aw-rel').forEach(function(row){
            if (row.querySelector('.mu-sr-rating')) return;
            var r = mrLookup('albums', row.dataset.name, artistName);
            if (r) row.insertAdjacentHTML('beforeend', mrBadge(r));
        });
    });
}

/* ─── PÁGINA DE DISCOGRAFÍA COMPLETA ─────────────────────────────────
   Overlay sobre la vista de artista. Filtros por tipo (Todos / Álbumes
   / Sencillos y EP) y, en "Todos", destaca la "Última publicación". */
var _muDiscoFilter = 'all';
function muOpenDiscography() {
    var dv = document.getElementById('mu-disco-view');
    if (!dv) {
        dv = document.createElement('div');
        dv.id = 'mu-disco-view';
        /* Mismo chrome que la pantalla de playlists: ventana con borde
           (.mh-window) → window-body → chips + panel hundido (.mh-panel)
           con los lanzamientos dentro + statusbar al pie. */
        dv.innerHTML =
            '<div class="window mh-window">' +
                '<div class="title-bar">' +
                    '<div class="title-bar-text" id="mu-disco-titlebar">Publicaciones</div>' +
                    '<div class="title-bar-controls">' +
                        '<button aria-label="Minimize" type="button"></button>' +
                        '<button aria-label="Maximize" type="button"></button>' +
                        '<button aria-label="Close" id="mu-disco-close" type="button"></button>' +
                    '</div>' +
                '</div>' +
                '<div class="window-body">' +
                    '<div class="mu-disco-chips" id="mu-disco-chips">' +
                        '<button class="button mu-disco-chip is-active" data-f="all" type="button">Todos</button>' +
                        '<button class="button mu-disco-chip" data-f="album" type="button">Álbumes</button>' +
                        '<button class="button mu-disco-chip" data-f="single" type="button">Sencillos y EP</button>' +
                    '</div>' +
                    '<div class="mh-panel mu-disco-body" id="mu-disco-body"></div>' +
                    '<div class="mh-statusbar"><button type="button" data-act="back">‹ Volver</button></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(dv);
        document.getElementById('mu-disco-close').addEventListener('click', function(){ dv.classList.remove('is-open'); });
        dv.querySelector('[data-act="back"]').addEventListener('click', function(){ dv.classList.remove('is-open'); });
        document.getElementById('mu-disco-chips').addEventListener('click', function(e){
            var b = e.target.closest && e.target.closest('.mu-disco-chip');
            if (!b) return;
            _muDiscoFilter = b.dataset.f;
            dv.querySelectorAll('.mu-disco-chip').forEach(function(x){ x.classList.toggle('is-active', x === b); });
            _muRenderDiscography();
        });
        document.getElementById('mu-disco-body').addEventListener('click', function(e){
            var row = e.target.closest && e.target.closest('.mu-aw-rel');
            if (!row) return;
            _muOpenAlbumViewer(row.dataset.key, row.dataset.name, _muAwName);
        });
    }
    _muDiscoFilter = 'all';
    dv.querySelectorAll('.mu-disco-chip').forEach(function(x){ x.classList.toggle('is-active', x.dataset.f === 'all'); });
    document.getElementById('mu-disco-titlebar').textContent = _muAwName || 'Publicaciones';
    _muRenderDiscography();
    dv.classList.add('is-open');
}
function _muRenderDiscography() {
    var body = document.getElementById('mu-disco-body');
    if (!body) return;
    var list = _muSortReleases(_muAwAlbums);
    if (_muDiscoFilter === 'album')  list = list.filter(function(a){ return a.type === 'album'; });
    if (_muDiscoFilter === 'single') list = list.filter(function(a){ return a.type === 'single' || a.type === 'ep'; });
    if (!list.length) { body.innerHTML = '<div class="mu-sr-msg">Nada por aquí.</div>'; return; }
    var html = '';
    if (_muDiscoFilter === 'all') {
        /* Última publicación destacada + el resto debajo. */
        html += '<div class="mu-disco-group-title">Última publicación</div>';
        html += '<div class="mu-aw-rel-list">' + _muRelRowHtml(list[0]) + '</div>';
        var rest = list.slice(1);
        if (rest.length) {
            html += '<div class="mu-disco-group-title">Más publicaciones</div>';
            html += '<div class="mu-aw-rel-list">';
            rest.forEach(function(a){ html += _muRelRowHtml(a); });
            html += '</div>';
        }
    } else {
        html += '<div class="mu-aw-rel-list">';
        list.forEach(function(a){ html += _muRelRowHtml(a); });
        html += '</div>';
    }
    body.innerHTML = html;
    muDecorateAlbumRows(body, _muAwName);
}

/* Botón "Buscar" de la barra superior. */
(function(){
    var b = document.getElementById('mu-btn-search');
    if (b) b.addEventListener('click', muOpenSearch);
})();

/* Marca con .is-playing la fila que coincide con el track activo del
   reproductor (por título + artista). Llamado al pintar el álbum y
   cuando cambia el track (vía playCurrent). */
function _muHighlightAlbumPlayingRow() {
    var fv = document.getElementById('mu-album-fullview');
    if (!fv || !fv.classList.contains('is-open')) return;
    var cur = _muAlbumFullCurrent;
    if (!cur || !cur.album || !cur.album.tracks) return;
    var active = (typeof QUEUE !== 'undefined' && CUR_IDX >= 0) ? QUEUE[CUR_IDX] : null;
    var activeKey = active ? ((active.title || '').toLowerCase() + '|' + (active.artist || '').toLowerCase()) : '';
    var rows = fv.querySelectorAll('.mu-album-track');
    rows.forEach(function(row){
        var idx = parseInt(row.dataset.idx, 10);
        var t = cur.album.tracks[idx];
        if (!t) return;
        var k = (t.title || '').toLowerCase() + '|' + (t.artist || '').toLowerCase();
        row.classList.toggle('is-playing', !!activeKey && k === activeKey);
    });
}

/* ── Handlers del overlay del viewer: tap reproduce, long-press abre
   menú con "Añadir al perfil + reseñar". Listener delegado a document
   porque las filas se recrean a cada apertura. */
(function setupAlbumViewerGestures(){
    var lpTimer = null, lpRow = null, lpStartX = 0, lpStartY = 0, lpFired = false;
    function viewerOpen() {
        var fv = document.getElementById('mu-album-fullview');
        return fv && fv.classList.contains('is-open') ? fv : null;
    }
    function startLp(row, x, y) {
        lpRow = row; lpStartX = x; lpStartY = y; lpFired = false;
        row.classList.add('long-pressing');
        lpTimer = setTimeout(function(){
            lpTimer = null;
            lpFired = true;
            row.classList.remove('long-pressing');
            try { navigator.vibrate && navigator.vibrate(40); } catch (_) {}
            var idx = parseInt(row.dataset.idx, 10);
            var cur = _muAlbumFullCurrent;
            if (cur && cur.album && cur.album.tracks[idx]) {
                _muOpenAlbumTrackMenu(cur.album, idx);
            }
        }, 500);
    }
    function cancelLp() {
        if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
        if (lpRow) { lpRow.classList.remove('long-pressing'); lpRow = null; }
    }
    document.addEventListener('touchstart', function(e){
        var fv = viewerOpen();
        if (!fv) return;
        if (!e.touches || e.touches.length !== 1) return;
        var row = e.target.closest && e.target.closest('.mu-album-track');
        if (!row || !fv.contains(row)) return;
        startLp(row, e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });
    document.addEventListener('touchmove', function(e){
        if (!lpTimer || !e.touches[0]) return;
        if (Math.abs(e.touches[0].clientX - lpStartX) > 12 ||
            Math.abs(e.touches[0].clientY - lpStartY) > 12) cancelLp();
    }, { passive: true });
    document.addEventListener('touchend',    cancelLp);
    document.addEventListener('touchcancel', cancelLp);
    document.addEventListener('mousedown', function(e){
        if (e.button !== 0) return;
        var fv = viewerOpen();
        if (!fv) return;
        var row = e.target.closest && e.target.closest('.mu-album-track');
        if (!row || !fv.contains(row)) return;
        startLp(row, e.clientX, e.clientY);
    });
    document.addEventListener('mousemove', function(e){
        if (!lpTimer) return;
        if (Math.abs(e.clientX - lpStartX) > 12 || Math.abs(e.clientY - lpStartY) > 12) cancelLp();
    });
    document.addEventListener('mouseup', cancelLp);
    /* Click delegado: si NO fue long-press, reproduce el track SIN
       cerrar el viewer. */
    document.addEventListener('click', function(e){
        var fv = viewerOpen();
        if (!fv) return;
        var row = e.target.closest && e.target.closest('.mu-album-track');
        if (!row || !fv.contains(row)) return;
        if (lpFired) { lpFired = false; e.preventDefault(); e.stopPropagation(); return; }
        var idx = parseInt(row.dataset.idx, 10);
        var cur = _muAlbumFullCurrent;
        if (cur && cur.album) _muPlayAlbumFrom(cur.album, idx);
    });
})();

/* Menú contextual al long-press de una fila del viewer. */
function _muOpenAlbumTrackMenu(album, idx) {
    var t = album.tracks[idx];
    if (!t) return;
    var bodyHtml =
        '<p class="modal-msg" style="margin:0 0 6px;color:var(--text-faint,#666);">' +
            esc(t.title || 'Canción') + (t.artist ? ' — ' + esc(t.artist) : '') +
        '</p>' +
        '<div class="mu-modal-list">' +
            '<div class="mu-modal-list-item" data-act="play"><div class="item-main">▶ Reproducir</div></div>' +
            '<div class="mu-modal-list-item" data-act="profile"><div class="item-main">➕ Añadir al perfil y reseñar</div></div>' +
        '</div>' +
        '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';
    var m = muOpenModal({ title: 'Opciones', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'play')    _muPlayAlbumFrom(album, idx);
            if (act === 'profile') _muAddAlbumTrackToProfile(album, idx);
        });
    });
}

/* Añade UNA canción del álbum al perfil del usuario, resolviendo
   primero su videoId con yt-search-batch. Tras guardar, ofrece dejar
   una reseña reutilizando el flujo de muReviewPrompt + muOpenReviewEditor. */
function _muAddAlbumTrackToProfile(album, idx) {
    var t = album.tracks[idx];
    if (!t || !t.title) return;
    /* Necesitamos un videoId — los tracks de iTunes/Deezer/Spotify no lo
       traen. Resolvemos UNO solo con yt-search-batch antes de añadir. */
    apiPost('yt-search-batch', { tracks: [{ title: t.title, artist: t.artist || '', duration: t.duration || 0 }] })
        .then(function(r){
            if (!r.ok) { muAlert('Error buscando la canción en YouTube.'); return; }
            var found = (r.data && r.data.tracks) || [];
            var hit = found.find(function(x){
                return x && x.title && x.title.toLowerCase() === (t.title || '').toLowerCase();
            }) || found[0];
            if (!hit || !hit.videoId) {
                muAlert('No se encontró "' + (t.title || 'la canción') + '" en YouTube.');
                return;
            }
            profileApiGet('get-lists').then(function(res){
                if (!res.ok) { muAlert('Error obteniendo el perfil'); return; }
                var lists = res.data || {};
                var music = (Array.isArray(lists.music) ? lists.music : [])
                    .filter(function(m){ return m && !m.sharedFrom; });
                if (music.some(function(m){ return m && m.ytId && m.ytId === hit.videoId; })) {
                    muAlert('"' + (t.title || 'Esta canción') + '" ya está en tu perfil');
                    return;
                }
                music.push({
                    id:       'music_' + Date.now(),
                    type:     'song',
                    title:    t.title  || hit.title,
                    artist:   t.artist || hit.artist || '',
                    image:    'https://i.ytimg.com/vi/' + hit.videoId + '/mqdefault.jpg',
                    featured: false,
                    ytId:     hit.videoId
                });
                saveProfileMusic(music, function(saved){
                    var savedItem = saved.find(function(m){ return m && m.ytId === hit.videoId; });
                    if (!savedItem) {
                        muAlert('"' + (t.title || 'Canción') + '" añadida a tu perfil', 'Añadido');
                        return;
                    }
                    muReviewPrompt('"' + (t.title || 'Canción') + '" añadida. ¿Quieres dejar una reseña?', function(){
                        muOpenReviewEditor(savedItem.id, savedItem.title, savedItem.ytId);
                    });
                });
            });
        });
}

/* ── Menú contextual del álbum (long-press en .mu-track-album). */
function muOpenAlbumMenu(albumId, albumName, albumArtist) {
    var items = [
        { act: 'open',       label: '👁 Ver álbum' },
        { act: 'addProfile', label: '➕ Añadir a mi perfil' },
        { act: 'addPl',      label: '📋 Añadir a playlist' }
    ];
    var bodyHtml = '<p class="modal-msg" style="margin:0 0 6px;color:var(--text-faint,#666);">' +
                     esc(albumName || 'Álbum') +
                   '</p>' +
                   '<div class="mu-modal-list">';
    items.forEach(function(it){
        bodyHtml += '<div class="mu-modal-list-item" data-act="' + it.act + '">' +
                      '<div class="item-main">' + it.label + '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>' +
                '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cerrar</button></div>';
    var m = muOpenModal({ title: 'Álbum', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var act = el.dataset.act;
            m.close();
            if (act === 'open')       _muOpenAlbumViewer(albumId, albumName, albumArtist);
            if (act === 'addProfile') _muLoadAndAddAlbumToProfile(albumId, albumName);
            if (act === 'addPl')      _muLoadAndAddAlbumToPlaylist(albumId, albumName);
        });
    });
}
function _muLoadAndAddAlbumToProfile(albumId, albumName) {
    if (_muAlbumViewCache[albumId]) {
        muAddAlbumToProfile(albumId, _muAlbumViewCache[albumId]);
        return;
    }
    fetch('../../assets/music/api.php?action=album-tracks&' + ((typeof albumId === 'string' && albumId.indexOf(':') !== -1) ? 'key=' : 'id=') + encodeURIComponent(albumId))
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){
            if (!data || data.error) { muAlert('No se pudo cargar el álbum.'); return; }
            _muAlbumViewCache[albumId] = data;
            muAddAlbumToProfile(albumId, data);
        })
        .catch(function(){ muAlert('Error de red.'); });
}
function _muLoadAndAddAlbumToPlaylist(albumId, albumName) {
    if (_muAlbumViewCache[albumId]) {
        muAddAlbumToPlaylist(_muAlbumViewCache[albumId]);
        return;
    }
    fetch('../../assets/music/api.php?action=album-tracks&' + ((typeof albumId === 'string' && albumId.indexOf(':') !== -1) ? 'key=' : 'id=') + encodeURIComponent(albumId))
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){
            if (!data || data.error) { muAlert('No se pudo cargar el álbum.'); return; }
            _muAlbumViewCache[albumId] = data;
            muAddAlbumToPlaylist(data);
        })
        .catch(function(){ muAlert('Error de red.'); });
}

/* ── Reproducir un álbum desde el track `startIdx` */
function _muPlayAlbumFrom(album, startIdx) {
    /* Convierte los tracks del álbum a QUEUE format. Necesitamos
       videoIds — primero resolverlos en batch contra YouTube. */
    var tracks = (album.tracks || []).map(function(t){
        return { title: t.title || '', artist: t.artist || '', duration: t.duration || 0 };
    });
    if (!tracks.length) { muAlert('El álbum no tiene canciones.'); return; }
    /* Si todos ya tienen videoId (raro pero), play directo. */
    var needSearch = tracks.some(function(t){ return !t.videoId; });
    function startQueue(resolved) {
        QUEUE = resolved.filter(function(t){ return t.videoId; });
        if (!QUEUE.length) { muAlert('No se encontraron las canciones en YouTube.'); return; }
        CUR_IDX = Math.max(0, Math.min(startIdx || 0, QUEUE.length - 1));
        CUR_PL_IDX = -1;   /* no pertenece a ninguna playlist guardada */
        var plName = album.name || 'Álbum';
        if (SHELL && typeof SHELL.loadQueue === 'function') {
            SHELL.loadQueue(QUEUE, CUR_IDX, plName);
        } else if (typeof playCurrent === 'function') {
            playCurrent();
        }
    }
    if (!needSearch) { startQueue(tracks); return; }
    /* yt-search-batch para resolver videoIds. */
    apiPost('yt-search-batch', { tracks: tracks }).then(function(r){
        if (!r.ok) { muAlert('Error buscando en YouTube.'); return; }
        var found = (r.data && r.data.tracks) || [];
        /* yt-search-batch puede devolver menos items; los mapeamos por
           título+artista para no perder orden. */
        var byKey = {};
        found.forEach(function(t){
            byKey[((t.title||'') + '|' + (t.artist||'')).toLowerCase()] = t;
        });
        var resolved = tracks.map(function(t){
            var key = ((t.title||'') + '|' + (t.artist||'')).toLowerCase();
            var hit = byKey[key];
            return hit ? Object.assign({}, t, { videoId: hit.videoId, duration: hit.duration || t.duration }) : t;
        });
        startQueue(resolved);
    });
}

/* ── Añadir álbum al perfil (type: 'album'). */
function muAddAlbumToProfile(albumId, album) {
    profileApiGet('get-lists').then(function(res){
        if (!res.ok) { muAlert('Error obteniendo el perfil'); return; }
        var lists = res.data || {};
        var music = (Array.isArray(lists.music) ? lists.music : [])
            .filter(function(m){ return m && !m.sharedFrom; });
        /* Evita duplicar por spotifyAlbumId. */
        /* dedupe por albumKey nuevo (acepta itunes:/deezer:/spotify:);
           si el item legacy solo tiene spotifyAlbumId, lo comparamos
           contra el id desnudo extraído del key. */
        var bareId = (typeof albumId === 'string' && albumId.indexOf(':') !== -1)
            ? albumId.split(':')[1] : albumId;
        var dup = music.some(function(m){
            if (!m || m.type !== 'album') return false;
            if (m.albumKey && m.albumKey === albumId) return true;
            if (m.spotifyAlbumId && m.spotifyAlbumId === bareId && albumId.indexOf('spotify:') === 0) return true;
            return false;
        });
        if (dup) {
            muAlert('"' + (album.name || 'Álbum') + '" ya está en tu perfil');
            return;
        }
        /* Guardamos albumKey (con prefijo) como fuente moderna.
           spotifyAlbumId sigue solo cuando el origen era Spotify, para
           que el código legacy de perfil que mira ese campo no se rompa. */
        var spotifyId = (typeof albumId === 'string' && albumId.indexOf('spotify:') === 0)
            ? albumId.slice(8) : '';
        var newItem = {
            id:             'music_' + Date.now(),
            type:           'album',
            title:          album.name   || 'Álbum',
            artist:         album.artist || '',
            image:          album.image  || '',
            featured:       false,
            albumKey:       albumId,
            spotifyAlbumId: spotifyId
        };
        music.push(newItem);
        saveProfileMusic(music, function(){
            /* Tras guardar: ofrece reseña inmediata, igual que se hace
               para canciones. Si dice Sí abre muOpenReviewEditor con
               este álbum. ytId vacío — el editor está hecho para que un
               item sin ytId funcione (mira MY_REVIEWS_BY_YTID[''] que
               devuelve undefined, así que arranca limpio). */
            muReviewPrompt(
                '"' + (newItem.title) + '" añadido a tu perfil. ¿Quieres dejar una reseña?',
                function(){ muOpenReviewEditor(newItem.id, newItem.title, ''); }
            );
        });
    });
}

/* ── Añadir álbum a una playlist: picker idéntico al de track. */
function muAddAlbumToPlaylist(album) {
    /* Carga perezosa: si no se abrió la pantalla de playlists, PLAYLISTS
       está vacío aunque sí tengas; cargamos y reintentamos. */
    if (!PLAYLISTS.length) {
        loadPlaylists().then(function(){
            if (!PLAYLISTS.length) { muAlert('No tienes playlists todavía'); return; }
            muAddAlbumToPlaylist(album);
        }).catch(function(){ muAlert('No se pudieron cargar las playlists'); });
        return;
    }
    var bodyHtml = '<p class="modal-msg" style="margin:0 0 6px;">' +
                     'Añadir "' + esc(album.name || 'álbum') + '" a:' +
                   '</p>' +
                   '<div class="mu-modal-list">';
    PLAYLISTS.forEach(function(pl, pi){
        var nTracks = (pl.tracks || []).length;
        var sharedTag = pl.sharedLabel
            ? '<span style="font-size:10px;color:var(--text-faint,#666);margin-left:6px;">de ' + esc(pl.sharedLabel) + '</span>'
            : '';
        bodyHtml += '<div class="mu-modal-list-item" data-pl-idx="' + pi + '">' +
                      '<div class="item-main">' +
                        '<div style="font-weight:bold;">' + esc(pl.name) + sharedTag + '</div>' +
                        '<div style="font-size:11px;color:var(--text-faint,#666);">' +
                            nTracks + ' canción' + (nTracks === 1 ? '' : 'es') +
                        '</div>' +
                      '</div>' +
                    '</div>';
    });
    bodyHtml += '</div>' +
                '<div class="modal-actions"><button class="button" data-act="cancel" type="button">Cancelar</button></div>';
    var m = muOpenModal({ title: 'Añadir álbum a playlist', body: bodyHtml });
    m.body.querySelector('[data-act="cancel"]').addEventListener('click', m.close);
    m.body.querySelectorAll('.mu-modal-list-item').forEach(function(el){
        el.addEventListener('click', function(){
            var pi = parseInt(el.dataset.plIdx, 10);
            var pl = PLAYLISTS[pi];
            if (!pl) return;
            m.close();
            _muAddAlbumToPlaylistConfirmed(pl, album);
        });
    });
}
function _muAddAlbumToPlaylistConfirmed(pl, album) {
    /* Resolver videoIds de los tracks del álbum vía yt-search-batch
       (los tracks de Spotify no traen videoId). */
    var rawTracks = (album.tracks || []).map(function(t){
        return { title: t.title || '', artist: t.artist || '', duration: t.duration || 0 };
    });
    if (!rawTracks.length) { muAlert('El álbum no tiene canciones.'); return; }
    apiPost('yt-search-batch', { tracks: rawTracks }).then(function(r){
        if (!r.ok) { muAlert('Error buscando en YouTube.'); return; }
        var found = (r.data && r.data.tracks) || [];
        if (!found.length) { muAlert('No se encontró ninguna canción del álbum en YouTube.'); return; }
        /* Mergea omitiendo videoIds ya en la playlist. */
        var existing = {};
        (pl.tracks || []).forEach(function(t){ if (t.videoId) existing[t.videoId] = true; });
        var fresh = found.filter(function(t){ return t.videoId && !existing[t.videoId]; })
                         .map(function(t){
                             return { title: t.title, artist: t.artist, videoId: t.videoId,
                                      duration: t.duration || 0, addedBy: ME_LABEL };
                         });
        if (!fresh.length) {
            muAlert('Todas las canciones del álbum ya estaban en "' + pl.name + '".');
            return;
        }
        var merged = (pl.tracks || []).concat(fresh);
        var payload = { id: pl.id, name: pl.name, tracks: merged };
        if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
        apiPost('save-playlist-item', payload).then(function(res){
            if (!res.ok) { muAlert((res.data && res.data.error) || 'Error al guardar'); return; }
            loadPlaylists();
            muAlert('✔ ' + fresh.length + ' canción(es) de "' + (album.name || 'álbum') + '" añadidas a "' + pl.name + '"', 'Añadidas');
        });
    });
}

/* Tier 1 #2: pausa todo lo no-esencial cuando el documento queda
   invisible (cambias de app, minimizas la PWA). El audio sigue
   gracias a Media Session — solo paramos UI de la app. */
document.addEventListener('visibilitychange', function(){
    if (document.hidden) {
        if (typeof muStopProgressTimer === 'function') muStopProgressTimer();
        _muAlbumQueuePaused = true;
        muBroadcastIdle(true);
    } else {
        _muAlbumQueuePaused = false;
        if (typeof _muAlbumNextSlot === 'function') _muAlbumNextSlot();
        if (typeof muProgressTimerSync === 'function') muProgressTimerSync();
        muBroadcastIdle(false);
    }
});

/* ─── Bootstrap ─────────────────────────────────────────────────── */
loadPlaylists();
/* En paralelo: música del perfil para tener el mapa de reseñas listo
   y mostrar las estrellas en los tracks reseñados (re-renderiza solo
   si las playlists ya terminaron de cargar). */
loadProfileMusic();

/* Deep-link "abrir álbum del track" disparado por el shell al tocar el
   título del fullscreen.
   El shell nos avisa por DOS canales para que llegue seguro:
     1. Hash en la URL (#open-album=videoId=...) — útil cuando la app
        carga fresh; el bootstrap lo lee inmediatamente.
     2. postMessage `{type:'mu:open-album', tr:{...}}` — útil cuando
        la app ya estaba cargada (replace con solo cambio de hash a
        veces no dispara hashchange según navegador) y como red de
        seguridad.
   Guardamos `_lastOpenAlbumKey` para deduplicar (que ambos canales
   disparando no abra el viewer dos veces seguidas). */
(function(){
    var _lastOpenAlbumKey = '';
    function trKey(tr) {
        return (tr.videoId || '') + '|' + (tr.title || '') + '|' + (tr.artist || '');
    }
    function dispatchOpenAlbum(tr) {
        if (!tr || (!tr.videoId && !tr.title)) return;
        var k = trKey(tr);
        if (k === _lastOpenAlbumKey) return;  /* dedupe */
        _lastOpenAlbumKey = k;
        /* Reset el "último" tras 2s para que un segundo tap legítimo
           sobre el MISMO track funcione. */
        setTimeout(function(){ _lastOpenAlbumKey = ''; }, 2000);
        muOpenAlbumFromTrack(tr);
    }
    function handleOpenAlbumHash() {
        var m = /#open-album=(.+)$/.exec(location.hash);
        if (!m) return;
        try {
            var params = new URLSearchParams(m[1]);
            var tr = {
                videoId: params.get('videoId') || '',
                title:   params.get('title')   || '',
                artist:  params.get('artist')  || ''
            };
            /* Limpia el hash para que un reload no vuelva a dispararlo. */
            try { history.replaceState(null, '', location.pathname + location.search); } catch (_) {}
            dispatchOpenAlbum(tr);
        } catch (_) {}
    }
    handleOpenAlbumHash();
    window.addEventListener('hashchange', handleOpenAlbumHash);
    /* Enlaces compartidos (mobile.php abre musica-mobile.php?album=… o
       ?playlist=…): visor del álbum o de la playlist. */
    try {
        var q = new URLSearchParams(location.search);
        var qAlbum = q.get('album'), qPl = q.get('playlist');
        if (qAlbum && _muAlbumKeyLinkable(qAlbum)) _muOpenAlbumViewer(qAlbum);
        else if (qPl && /^\d+$/.test(qPl)) muOpenSharedPlaylist(qPl);
    } catch (_) {}
    /* Canal 2: postMessage del shell. */
    window.addEventListener('message', function(e){
        var d = (e && e.data) || {};
        if (d.type !== 'mu:open-album' || !d.tr) return;
        dispatchOpenAlbum(d.tr);
    });
})();

</script>

</body>
</html>
