<?php
/* ──────────────────────────────────────────────────────────────────────
   JUEGOS — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Versión móvil de apps/juegos.php (decidido así, 7/11): portada →
   Wordelon, Buscaminas y Ajedrez. Mismos IDs del HTML del escritorio.
   Wordelon: copia de su JS con los paths cambiados (../../); casillas y
   teclado al ancho del móvil, el teclado abajo. Buscaminas y Ajedrez:
   los MISMOS assets/juegos/buscaminas.js y ajedrez.js que el PC (con
   JG.movil y JG.RAIZ = '../../') y el mismo CSS (assets/juegos/juegos.css)
   más sus ajustes de aquí para una columna. Servidor y API, los mismos.

   ⚠ Si tocas el JS del Wordelon del escritorio (apps/juegos.php), este
      bloque es una copia adaptada: mantenlo en sync.
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

/* Tema activo del usuario — mismo flow que el resto de apps móviles. */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes      = loadUserThemes($userKey);
$activeTheme      = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
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
    <script>
    /* --mh-vh: alto real de la pantalla (como el resto de apps móviles). */
    (function(){
        function setVh(){ document.documentElement.style.setProperty('--mh-vh', window.innerHeight + 'px'); }
        setVh();
        window.addEventListener('resize', setVh);
        window.addEventListener('orientationchange', setVh);
        window.addEventListener('pageshow', setVh);
        window.addEventListener('visibilitychange', setVh);
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($themeBgColor) ?>">
    <title>Juegos</title>
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
    <style>
        /* El cuerpo de la ventana: las vistas ocupan todo el alto y la
           barra "‹ Menú" se queda abajo. */
        .mh-window > .window-body { display: flex; flex-direction: column; min-height: 0; }
        .jg-views { flex: 1; min-height: 0; position: relative; }
        .jg-view { display: none; height: 100%; flex-direction: column; box-sizing: border-box; color: var(--text, #000); font-size: 12px; }
        .jg-view.on { display: flex; }

        /* ── Portada: lista de juegos (como la del PC) ──────────────── */
        #jg-home { padding: 8px; gap: 8px; }
        .jg-home-title { font-weight: bold; font-size: 14px; }
        .jg-list {
            flex: 1; overflow: auto; padding: 8px; display: flex; flex-direction: column; gap: 6px;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        .jg-card {
            display: flex; align-items: center; gap: 10px; width: 100%;
            padding: 10px 8px; box-sizing: border-box; cursor: pointer; color: var(--text, #000);
            background: var(--win-bg, silver);
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-1, #fff),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-2, #dfdfdf);
        }
        .jg-card:active {
            box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a), inset -1px -1px var(--bezel-light-1, #fff),
                        inset 2px 2px var(--bezel-dark-2, grey), inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        body.jg-kawaii .jg-card { box-shadow: none; border: 2px solid var(--border, #49388B); }
        body.jg-kawaii .jg-list { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        .jg-card-ic {
            width: 40px; height: 40px; flex-shrink: 0;
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 2px; padding: 2px; box-sizing: border-box;
        }
        .jg-card-ic i { display: block; }
        .jg-card-ic img { grid-column: 1 / -1; grid-row: 1 / -1; width: 36px; height: 36px; object-fit: contain; image-rendering: pixelated; }
        .jg-card-txt { flex: 1; min-width: 0; }
        .jg-card-name { font-weight: bold; font-size: 13px; }
        .jg-card-sub  { color: var(--text-muted, #444); margin-top: 2px; font-size: 11px; }
        .jg-card-state { color: var(--text-muted, #444); white-space: nowrap; font-size: 11px; flex-shrink: 0; }

        /* ── Wordelon ─────────────────────────────────────────────── */
        #jg-wordelon { padding: 6px 6px 8px; align-items: center; }
        /* Tres columnas: las de los lados iguales para que el título quede
           en el centro (como el PC). */
        .wd-top { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 6px; width: 100%; flex-shrink: 0; }
        .wd-top button { min-width: 0; min-height: 28px; padding: 0 8px; font-size: 11px; }
        .wd-top > button:first-child { justify-self: start; }
        .wd-top-right { justify-self: end; display: flex; gap: 4px; }
        .wd-head { text-align: center; }
        .wd-title { font-weight: bold; font-size: 14px; white-space: nowrap; }
        .wd-date  { color: var(--text-muted, #444); margin-top: 2px; font-size: 11px; white-space: nowrap; }
        .wd-help  { width: 28px; padding: 0 !important; }
        .wd-msg { min-height: 16px; margin: 6px 0 2px; text-align: center; font-weight: bold; flex-shrink: 0; }
        /* El tablero ocupa lo que sobra y se centra; las casillas, del
           tamaño que quepa (ancho del móvil y alto de la pantalla). */
        .wd-zona { flex: 1; min-height: 0; width: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: auto; }
        .wd-board { --wd-t: min(56px, calc((100vw - 60px) / 5), calc((var(--mh-vh, 100vh) - 330px) / 6.6)); display: grid; gap: 5px; margin: 4px 0 8px; }
        .wd-row { display: grid; grid-template-columns: repeat(5, var(--wd-t)); gap: 5px; }
        .wd-row.shake { animation: wd-shake .4s; }
        @keyframes wd-shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); } 40% { transform: translateX(5px); }
            60% { transform: translateX(-4px); } 80% { transform: translateX(4px); }
        }
        .wd-tile {
            width: var(--wd-t, 46px); height: var(--wd-t, 46px); box-sizing: border-box;
            display: flex; align-items: center; justify-content: center;
            font-size: calc(var(--wd-t, 46px) * .52); font-weight: bold;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        /* Los colores son fijos y no del tema: tienen que casar con los
           cuadrados 🟩🟨⬛ que publica el bot en Discord. */
        .wd-tile.full { animation: wd-pop .1s; }
        @keyframes wd-pop { 50% { transform: scale(1.08); } }
        .wd-tile.ok, .wd-tile.cerca, .wd-tile.no {
            color: #fff;
            box-shadow: inset -1px -1px rgba(0,0,0,.45), inset 1px 1px rgba(255,255,255,.45),
                        inset -2px -2px rgba(0,0,0,.25), inset 2px 2px rgba(255,255,255,.25);
        }
        .wd-tile.ok    { background: #3f9a3f; }
        .wd-tile.cerca { background: #c99a0e; }
        .wd-tile.no    { background: #6b6b6b; }
        .wd-tile.reveal { animation: wd-flip .35s ease both; }
        @keyframes wd-flip { 0% { transform: scaleY(1); } 50% { transform: scaleY(0); } 100% { transform: scaleY(1); } }

        /* Teclado: a todo lo ancho, abajo. */
        .wd-kb { display: flex; flex-direction: column; gap: 5px; width: 100%; flex-shrink: 0; }
        .wd-kb-row { display: flex; gap: 4px; justify-content: center; }
        .wd-key {
            flex: 1 1 0; min-width: 0 !important; height: 46px; padding: 0 !important;
            font-size: 14px; font-weight: bold;
        }
        .wd-key.wide { flex: 1.6 1 0; font-size: 11px; }
        /* 98.css pinta el texto de los botones con text-shadow (color
           transparente), así que el blanco de las teclas con pista va ahí. */
        .wd-key.ok, .wd-key.cerca, .wd-key.no { text-shadow: 0 0 #fff !important; color: #fff !important; }
        .wd-key.ok    { background: #3f9a3f !important; }
        .wd-key.cerca { background: #c99a0e !important; }
        .wd-key.no    { background: #6b6b6b !important; }

        .wd-end {
            display: none; margin: 0 0 8px; padding: 8px 12px; text-align: center; line-height: 1.6;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        .wd-end.on { display: block; }
        .wd-end b { font-size: 13px; }
        body.jg-kawaii .wd-end { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        .pt-ic { width: 14px; height: 14px; object-fit: contain; image-rendering: pixelated; vertical-align: middle; }

        /* ── Estadísticas de hoy: la imagen del bot a 1x, con las tarjetas
           que quepan por fila (3 en el móvil). ── */
        #jg-stats { padding: 6px 0 0; align-items: center; }
        #jg-stats .wd-top { padding: 0 6px; box-sizing: border-box; }
        .st-sum { margin: 8px 12px; text-align: center; font-weight: bold; }
        .st-scroll { flex: 1; overflow: auto; width: 100%; text-align: center; }
        .st-scroll img { display: block; margin: 0 auto; image-rendering: pixelated; }
        .st-empty { margin-top: 30px; text-align: center; color: var(--text-muted, #444); padding: 0 12px; }

        /* Ventanita "Cómo se juega". */
        .wd-modal-bg {
            display: none; position: fixed; inset: 0; z-index: 50; padding: 12px;
            align-items: center; justify-content: center; background: rgba(0,0,0,.35);
        }
        .wd-modal-bg.on { display: flex; }
        .wd-modal { width: 100%; max-width: 360px; color: var(--text, #000); }
        .wd-modal .window-body { line-height: 1.5; font-size: 12px; }
        .wd-modal .ex { display: flex; gap: 3px; margin: 6px 0 2px; }
        .wd-modal .ex .wd-tile { width: 28px; height: 28px; font-size: 15px; }
        .wd-modal-btns { display: flex; justify-content: flex-end; margin-top: 8px; }
        .wd-modal-btns button { min-height: 28px; }
    </style>
    <!-- Buscaminas, Ajedrez y lo que 98.css pinta fijo: el mismo CSS que el
         escritorio (assets/juegos/juegos.css); debajo, sus ajustes para el
         móvil (una columna). -->
    <link rel="stylesheet" href="../../assets/juegos/juegos.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/juegos/juegos.css') ?>">
    <link rel="stylesheet" href="../../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/css/marcos.css') ?>">
    <script src="../../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-marcos.js') ?>"></script>
    <style>
        /* ── Buscaminas y Ajedrez en el móvil (decidido así, 7/11): lo del
           PC en una columna; cada vista se desliza entera. ── */
        #jg-bm, #jg-bm-juego, #jg-ajz, #jg-ajz-crear, #jg-ajz-torneo, #jg-ajz-partida {
            padding: 6px 6px 10px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        #jg-bm > *, #jg-bm-juego > *, #jg-ajz > *, #jg-ajz-crear > *, #jg-ajz-torneo > *, #jg-ajz-partida > * { flex-shrink: 0; }
        .bm-menu, .az-menu { overflow: visible; flex: none; }
        .bm-dia-grid, .bm-fila2, .bm-inf-cols, .az-fila2, .az-crear-cols { grid-template-columns: 1fr; }
        .bm-dia-izq { overflow-x: auto; }
        .az-fila2 .az-scroll { max-height: none; }
        .az-crece { flex: none; min-height: 0; }
        /* Partida del Buscaminas: tablero arriba (todo el ancho), ranking debajo. */
        .bmj-cols { flex-direction: column; flex: none; align-items: stretch; }
        .bmj-main { justify-content: flex-start; flex: none; }
        #jg-bm-juego .bm-side { width: auto; max-width: none; }
        .bm-wrap { max-width: 100%; overflow: auto; }
        .bm-board { touch-action: manipulation; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }
        #jg-bm-juego .bm-info { margin: 0 0 6px; min-height: 0; }
        #jg-bm-juego .bm-info:empty { display: none; }
        .bm-modo { display: flex; justify-self: center; }
        .bm-modo button { min-width: 0; width: 40px; height: 30px; padding: 0; font-size: 16px; line-height: 1; }
        .bm-modo button.on { box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a), inset -1px -1px var(--bezel-light-1, #fff),
            inset 2px 2px var(--bezel-dark-2, grey), inset -2px -2px var(--bezel-light-2, #dfdfdf); background: var(--input-bg, #fff); }
        body.jg-kawaii .bm-modo button.on { background: var(--accent, #FF66B2) !important; }
        /* Ajedrez, partida: todo en la pantalla sin deslizar. El tablero
           (ajustaTablero mira el sitio de #azp-main) encoge si no cabe; lo
           que sobra de alto es para la caja de Jugadas / Chat. */
        #jg-ajz-partida { overflow-y: auto; }
        .az-movil { flex-direction: column; flex: 1; min-height: 0; gap: 5px; align-items: stretch; margin-top: 4px; }
        .az-movil > * { flex-shrink: 0; }
        .az-movil .az-main { flex: 0 1 auto; width: 100%; aspect-ratio: 1 / 1; min-height: 150px; align-items: center; }
        .az-movil .az-jug { padding-top: 3px; padding-bottom: 3px; }
        .az-pest { flex: 1 1 110px !important; min-height: 110px; display: flex; flex-direction: column; padding: 3px; }
        .az-pest-tabs { display: flex; gap: 2px; flex-shrink: 0; }
        .az-pest-tabs button { flex: 1; min-width: 0; min-height: 26px; font-size: 11px; }
        .az-pest-tabs button.on { font-weight: bold; box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a), inset -1px -1px var(--bezel-light-1, #fff),
            inset 2px 2px var(--bezel-dark-2, grey), inset -2px -2px var(--bezel-light-2, #dfdfdf); }
        /* Kawaii pinta los botones con !important: la pestaña elegida, igual. */
        body.jg-kawaii .az-pest-tabs button.on { background: var(--accent, #FF66B2) !important; color: var(--accent-text, #fff) !important; }
        .az-pest-panel { flex: 1; min-height: 0; margin-top: 3px; }
        .az-pest-panel[hidden] { display: none !important; }
        #azp-jugadas { overflow: auto; }
        #azp-chat { display: flex; flex-direction: column; }
        #azp-chat .az-chat-lista { flex: 1; min-height: 0; }
        .az-movil .az-estado { margin: 0; }
        .az-movil .az-btns { display: flex; gap: 6px; }
        .az-movil .az-btns button { flex: 1; min-height: 30px; }
        .az-tor-body { overflow: visible; flex: none; }
        .az-form input[type=number] { width: 64px; min-height: 26px; }
        .az-form select, .az-form input[type=text], .az-form input[type=datetime-local] { min-height: 28px; }
        .az-chat-txt { min-height: 28px; }
        /* Ventanas de confirmar y "Cómo se juega" a lo ancho. */
        .az-modal { max-width: 360px; }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?><?= (($_COOKIE['activeInterface'] ?? '') === 'kawaii') ? ' jg-kawaii' : '' ?>">
<div class="window mh-window">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Juegos</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="jg-close"></button>
        </div>
    </div>
    <div class="window-body">
    <div class="jg-views">

<!-- PORTADA (como la del PC) -->
<div class="jg-view on" id="jg-home">
    <div class="jg-home-title">Juegos</div>
    <div class="jg-list">
        <div class="jg-card" id="jg-open-wordelon" role="button" tabindex="0">
            <div class="jg-card-ic" aria-hidden="true">
                <i style="background:#3f9a3f"></i><i style="background:#c99a0e"></i>
                <i style="background:#6b6b6b"></i><i style="background:#3f9a3f"></i>
            </div>
            <div class="jg-card-txt">
                <div class="jg-card-name">Wordelon</div>
                <div class="jg-card-sub">Adivina la palabra del día en 6 intentos</div>
            </div>
            <div class="jg-card-state" id="jg-wordelon-state"></div>
        </div>
        <div class="jg-card" id="jg-open-bm" role="button" tabindex="0">
            <div class="jg-card-ic bm-card-ic" aria-hidden="true">
                <i class="bm-c t"></i><i class="bm-c a n1">1</i>
                <i class="bm-c a"></i><i class="bm-c t bm-flag"></i>
            </div>
            <div class="jg-card-txt">
                <div class="jg-card-name">Buscaminas</div>
                <div class="jg-card-sub">Tablero del día, libre e infinito</div>
            </div>
            <div class="jg-card-state" id="jg-bm-state"></div>
        </div>
        <div class="jg-card" id="jg-open-ajz" role="button" tabindex="0">
            <div class="jg-card-ic az-card-ic" id="az-card-ic" aria-hidden="true"></div>
            <div class="jg-card-txt">
                <div class="jg-card-name">Ajedrez</div>
                <div class="jg-card-sub">Reta a tus amigos, apuesta puntos y organiza torneos</div>
            </div>
            <div class="jg-card-state" id="jg-ajz-state"></div>
        </div>
    </div>
</div>

<!-- WORDELON -->
<div class="jg-view" id="jg-wordelon">
    <div class="wd-top">
        <button type="button" id="wd-back">&lt; Juegos</button>
        <div class="wd-head">
            <div class="wd-title" id="wd-title">Wordelon</div>
            <div class="wd-date" id="wd-date"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" id="wd-stats">Estadísticas</button>
            <button type="button" class="wd-help" id="wd-help" title="Cómo se juega" aria-label="Cómo se juega">?</button>
        </div>
    </div>
    <div class="wd-msg" id="wd-msg"></div>
    <div class="wd-zona">
        <div class="wd-board" id="wd-board"></div>
        <div class="wd-end" id="wd-end"></div>
    </div>
    <div class="wd-kb" id="wd-kb"></div>
</div>

<!-- ESTADÍSTICAS DE HOY -->
<div class="jg-view" id="jg-stats">
    <div class="wd-top">
        <button type="button" id="st-back">&lt; Wordelon</button>
        <div class="wd-head">
            <div class="wd-title" id="st-title">Wordelon</div>
            <div class="wd-date" id="st-date"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" id="st-reload">Actualizar</button>
        </div>
    </div>
    <div class="st-sum" id="st-sum"></div>
    <div class="st-scroll" id="st-scroll"></div>
</div>

<!-- BUSCAMINAS: menú (assets/juegos/buscaminas.js) -->
<div class="jg-view" id="jg-bm">
    <div class="wd-top">
        <button type="button" id="bm-back">&lt; Juegos</button>
        <div class="wd-head">
            <div class="wd-title">Buscaminas</div>
            <div class="wd-date" id="bm-date"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" class="wd-help" id="bm-help" title="Cómo se juega" aria-label="Cómo se juega">?</button>
        </div>
    </div>
    <div class="bm-menu">
        <!-- Cada sección es una ventanita con su barra de título (colores
             del tema) y un icono pixelado. -->
        <section class="bm-win">
            <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 13 13" width="13" height="13" shape-rendering="crispEdges" aria-hidden="true"><path d="M6 0h1v13H6zM0 6h13v1H0zM2 2h1v1H2zM10 2h1v1h-1zM2 10h1v1H2zM10 10h1v1h-1zM4 3h5v1h1v1h1v3h-1v1h-1v1H4V9H3V8H2V5h1V4h1z"/></svg><span>Tablero del día</span><span class="bm-win-bar-r" id="bm-dia-estado"></span></div>
            <div class="bm-win-body bm-dia-grid">
                <div class="bm-dia-izq"><div class="bm-board bm-prev" id="bm-dia-prev"></div></div>
                <div class="bm-dia-der">
                    <div class="bm-libre">
                        <select id="bm-dia-nivel">
                            <option value="1">Principiante (9×9, 10 minas)</option>
                            <option value="2">Intermedio (16×16, 40 minas)</option>
                            <option value="3">Experto (30×16, 99 minas)</option>
                        </select>
                        <button type="button" id="bm-dia-jugar">Jugar</button>
                    </div>
                    <div class="bm-campos" id="bm-dia-campos"></div>
                    <div class="bm-dia-info" id="bm-dia-info"></div>
                    <div class="bm-premios">
                        <div class="bm-premios-tit">Premios del día</div>
                        <div class="bm-premios-fila">
                            <span><i class="bm-med m1">1</i> +50</span>
                            <span><i class="bm-med m2">2</i> +30</span>
                            <span><i class="bm-med m3">3</i> +15</span>
                        </div>
                        <div class="bm-nota">Para los 3 más rápidos de cada tablero, al acabar el día.</div>
                    </div>
                    <div class="bm-nota">Solo puedes hacer uno al día y tienes un intento. El tiempo empieza al darle a Jugar.</div>
                </div>
            </div>
        </section>
        <div class="bm-fila2">
            <section class="bm-win">
                <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 15 9" width="15" height="9" shape-rendering="crispEdges" aria-hidden="true"><path d="M2 1h3v1H2zM10 1h3v1h-3zM1 2h1v5H1zM13 2h1v5h-1zM5 2h1v1H5zM9 2h1v1H9zM6 3h1v1H6zM8 3h1v1H8zM7 4h1v1H7zM6 5h1v1H6zM8 5h1v1H8zM5 6h1v1H5zM9 6h1v1H9zM2 7h3v1H2zM10 7h3v1h-3z"/></svg><span>Infinito</span></div>
                <div class="bm-win-body bm-inf-cols">
                    <div id="bm-inf-box"></div>
                    <div class="bm-inf-top">
                        <div class="bm-side-tit">Mejores récords</div>
                        <div class="bm-rank" id="bm-inf-top"></div>
                    </div>
                </div>
            </section>
            <section class="bm-win">
                <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 12 12" width="12" height="12" shape-rendering="crispEdges" aria-hidden="true"><path d="M3 1h4v1h1v1h1v1H8v1H7v1H3z"/><path d="M6 1h1v8H6zM4 9h5v1H4zM2 10h9v1H2z"/></svg><span>Libre</span></div>
                <div class="bm-win-body bm-libre-col">
                    <select id="bm-libre-nivel">
                        <option value="1">Principiante · 9×9</option>
                        <option value="2">Intermedio · 16×16</option>
                        <option value="3">Experto · 30×16</option>
                    </select>
                    <button type="button" id="bm-libre-jugar">Jugar</button>
                    <div class="bm-nota">Elige la dificultad y juega las veces que quieras. Sin puntos.</div>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- BUSCAMINAS: tablero -->
<div class="jg-view" id="jg-bm-juego">
    <div class="wd-top">
        <button type="button" id="bmj-back">&lt; Buscaminas</button>
        <div class="wd-head">
            <div class="wd-title" id="bmj-title">Buscaminas</div>
            <div class="wd-date" id="bmj-sub"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" id="bmj-nueva">Nueva</button>
        </div>
    </div>
    <div class="bmj-cols">
        <!-- El tablero y, debajo (en el móvil), el ranking. -->
        <div class="bmj-main" id="bmj-main">
            <div class="bm-bar">
                <div class="bm-cont" id="bmj-minas" title="Minas sin marcar">0</div>
                <!-- Móvil: sin clic derecho. ⛏ destapar / 🚩 bandera (además,
                     mantener pulsada una casilla pone bandera). -->
                <div class="bm-modo" role="group" aria-label="Modo">
                    <button type="button" data-m="0" class="on" aria-pressed="true" title="Destapar">⛏</button><button type="button" data-m="1" aria-pressed="false" title="Bandera">🚩</button>
                </div>
                <div class="bm-cont" id="bmj-tiempo" title="Tiempo">0:00</div>
            </div>
            <div class="bm-info" id="bmj-info"></div>
            <div class="bm-wrap"><div class="bm-board" id="bmj-board"></div></div>
            <div class="wd-end" id="bmj-end"></div>
        </div>
        <div class="bm-side" id="bmj-side">
            <div class="bm-side-tit" id="bmj-rank-tit">Ranking</div>
            <div class="bm-rank" id="bmj-rank"></div>
        </div>
    </div>
</div>

<!-- AJEDREZ: menú (assets/juegos/ajedrez.js) -->
<div class="jg-view" id="jg-ajz">
    <div class="wd-top">
        <button type="button" id="az-back">&lt; Juegos</button>
        <div class="wd-head">
            <div class="wd-title">Ajedrez</div>
            <div class="wd-date az-saldo" id="az-saldo"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" class="wd-help" id="az-help" title="Cómo se juega" aria-label="Cómo se juega">?</button>
        </div>
    </div>
    <div class="az-menu">
        <div class="az-fila2">
            <section class="bm-win">
                <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 12 12" width="12" height="12" shape-rendering="crispEdges" aria-hidden="true"><path d="M5 1h2v1h1v2H7v1h1v1H7v3h2v1h1v1H2v-1h1V9h2V6H4V5h1V4H4V2h1z"/></svg><span>Retar a un amigo</span></div>
                <div class="bm-win-body">
                    <div class="az-form">
                        <label for="az-amigo">Amigo</label>
                        <select id="az-amigo"></select>
                        <label for="az-min">Ritmo</label>
                        <div class="az-ritmo"><input type="number" id="az-min" min="1" max="180" value="5"> min +
                            <input type="number" id="az-inc" min="0" max="180" value="3"> s</div>
                        <label for="az-apuesta">Apuesta</label>
                        <div class="az-ritmo"><input type="number" id="az-apuesta" min="0" value="0"><span id="az-apuesta-pt"></span></div>
                        <div class="az-span bm-nota">Los dos ponéis lo mismo y quien gana se lo lleva todo. En tablas, cada uno recupera lo suyo. El reto caduca a los 5 minutos.</div>
                        <div class="az-span az-acc"><span class="az-err" id="az-retar-msg"></span><button type="button" id="az-retar-enlace" title="Un enlace para que acepte quien lo abra (amigo o no)">Retar con un enlace</button><button type="button" class="az-retar-btn" id="az-retar">Retar</button></div>
                    </div>
                </div>
            </section>
            <section class="bm-win">
                <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 13 13" width="13" height="13" shape-rendering="crispEdges" aria-hidden="true"><path d="M2 2h9v1H2zM2 10h9v1H2zM1 3h1v7H1zM11 3h1v7h-1zM6 4h1v3h2v1H6z"/></svg><span>Partidas y retos</span><span class="bm-win-bar-r" id="az-mios-n"></span></div>
                <div class="bm-win-body az-scroll"><div class="az-list" id="az-mios"></div></div>
            </section>
        </div>
        <section class="bm-win az-crece">
            <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 13 13" width="13" height="13" shape-rendering="crispEdges" aria-hidden="true"><path d="M3 1h7v1h2v3h-1v1h-1v1H9v1H8v1H7v1h2v2H4v-2h2V9H5V8H4V7H3V6H2V5H1V2h2zM2 2v2h1V2zM10 2v2h1V2z"/></svg><span>Torneos</span>
                <span class="bm-win-bar-r"><button type="button" id="az-crear-btn">Crear torneo</button></span></div>
            <div class="bm-win-body"><div class="az-list" id="az-torneos"></div></div>
        </section>
    </div>
</div>

<!-- AJEDREZ: crear torneo -->
<div class="jg-view" id="jg-ajz-crear">
    <div class="wd-top">
        <button type="button" id="azc-back">&lt; Ajedrez</button>
        <div class="wd-head"><div class="wd-title" id="azc-title">Crear torneo</div><div class="wd-date az-saldo" id="azc-saldo"></div></div>
        <div class="wd-top-right"></div>
    </div>
    <div class="az-menu">
        <div class="az-crear-cols">
            <section class="bm-win">
                <div class="bm-win-bar"><svg class="bm-ic" viewBox="0 0 13 13" width="13" height="13" shape-rendering="crispEdges" aria-hidden="true"><path d="M3 1h7v1h2v3h-1v1h-1v1H9v1H8v1H7v1h2v2H4v-2h2V9H5V8H4V7H3V6H2V5H1V2h2zM2 2v2h1V2zM10 2v2h1V2z"/></svg><span>Eliminatoria directa</span></div>
                <div class="bm-win-body">
                    <div class="az-form">
                        <label for="azc-nombre">Nombre</label>
                        <input type="text" id="azc-nombre" maxlength="60" placeholder="Copa de los melones">
                        <label for="azc-plazas">Jugadores</label>
                        <select id="azc-plazas"><option value="4">4</option><option value="8" selected>8</option><option value="16">16</option><option value="32">32</option></select>
                        <label for="azc-min">Ritmo</label>
                        <div class="az-ritmo"><input type="number" id="azc-min" min="1" max="180" value="5"> min +
                            <input type="number" id="azc-inc" min="0" max="180" value="3"> s</div>
                        <label for="azc-empieza">Empieza</label>
                        <input type="datetime-local" id="azc-empieza">
                        <label for="azc-entrada">Entrada</label>
                        <div class="az-ritmo"><input type="number" id="azc-entrada" min="0" value="0"><span class="azc-pt"></span> por jugador, para ti</div>
                        <label>Premios</label>
                        <div class="az-ritmo">
                            <i class="bm-med m1">1</i><input type="number" id="azc-p1" min="0" value="0">
                            <i class="bm-med m2">2</i><input type="number" id="azc-p2" min="0" value="0">
                            <i class="bm-med m3">3</i><input type="number" id="azc-p3" min="0" value="0">
                        </div>
                    </div>
                </div>
            </section>
            <section class="bm-win az-resumen">
                <div class="bm-win-bar"><span>Resumen</span></div>
                <div class="bm-win-body">
                    <div id="azc-resumen"></div>
                    <div class="az-acc"><span class="az-err" id="azc-msg"></span><button type="button" class="az-retar-btn" id="azc-crear">Crear torneo</button></div>
                </div>
            </section>
        </div>
        <section class="bm-win">
            <div class="bm-win-bar"><span>Cómo funciona</span></div>
            <div class="bm-win-body">
                <ul class="az-reglas">
                    <li>Los premios salen de tus puntos al crear el torneo y las entradas te llegan cuando empieza.</li>
                    <li>Si a la hora de inicio no está lleno, se cancela y se devuelve todo.</li>
                    <li>Puedes apuntarte tú también, sin pagar entrada.</li>
                    <li>Cada ronda empieza cuando acaba la anterior. Quien no se presenta a su partida en 2 minutos, la pierde.</li>
                    <li>Las tablas se repiten con los colores cambiados.</li>
                    <li>Al final se juegan a la vez la final y la partida por el 3.er puesto.</li>
                </ul>
            </div>
        </section>
    </div>
</div>

<!-- AJEDREZ: torneo -->
<div class="jg-view" id="jg-ajz-torneo">
    <div class="wd-top">
        <button type="button" id="azt-back">&lt; Ajedrez</button>
        <div class="wd-head"><div class="wd-title" id="azt-title">Torneo</div><div class="wd-date" id="azt-sub"></div></div>
        <div class="wd-top-right"></div>
    </div>
    <div class="az-tor-body" id="azt-body">
        <div id="azt-info" class="az-contenido"></div>
        <!-- Chat: fuera de azt-info (que se repinta entero) para no perder
             lo que se está escribiendo. Lo rellena ajedrez.js (chatAbrir). -->
        <section class="bm-win" id="azt-chat" hidden>
            <div class="bm-win-bar"><span>Chat</span></div>
            <div class="bm-win-body">
                <div class="az-chat-lista az-hundido"></div>
                <div class="az-chat-form"><input type="text" class="az-chat-txt" maxlength="300" placeholder="Escribe un mensaje…" aria-label="Mensaje"><button type="button" class="az-chat-env">Enviar</button></div>
                <div class="az-chat-nota"></div>
            </div>
        </section>
    </div>
</div>

<!-- AJEDREZ: partida -->
<div class="jg-view" id="jg-ajz-partida">
    <div class="wd-top">
        <button type="button" id="azp-back">&lt; Ajedrez</button>
        <div class="wd-head"><div class="wd-title" id="azp-title">Ajedrez</div><div class="wd-date" id="azp-sub"></div></div>
        <div class="wd-top-right"></div>
    </div>
    <!-- Móvil (decidido así, 7/11): sin deslizar. El rival arriba (en su
         lado del tablero), el tablero, tú debajo con tu reloj, y Jugadas
         y Chat en la misma caja con pestañas; estado y botones al final.
         Los mismos ids que el PC: ajedrez.js no sabe nada de esto. -->
    <div class="az-cols az-movil">
        <div class="az-jug az-relieve" id="azp-rival"></div>
        <div class="az-main" id="azp-main">
            <div class="az-board-wrap" id="azp-wrap"><div class="az-board" id="azp-board"></div></div>
        </div>
        <div class="az-jug az-relieve" id="azp-yo"></div>
        <section class="az-pest az-hundido">
            <div class="az-pest-tabs" role="tablist">
                <button type="button" role="tab" data-pest="jug" class="on" aria-selected="true">Jugadas</button><button type="button" role="tab" data-pest="chat" aria-selected="false">Chat</button>
            </div>
            <div class="az-pest-panel az-jugadas" id="azp-jugadas" data-pest="jug"></div>
            <div class="az-pest-panel" id="azp-chat" data-pest="chat" hidden>
                <div class="az-chat-lista"></div>
                <div class="az-chat-form"><input type="text" class="az-chat-txt" maxlength="300" placeholder="Escribe un mensaje…" aria-label="Mensaje"><button type="button" class="az-chat-env">Enviar</button></div>
                <div class="az-chat-nota"></div>
            </div>
        </section>
        <div class="az-estado" id="azp-estado"></div>
        <div id="azp-aviso"></div>
        <div class="az-btns" id="azp-btns">
            <button type="button" id="azp-tablas">Ofrecer tablas</button>
            <button type="button" id="azp-rendirse">Rendirse</button>
        </div>
        <div class="az-fin az-hundido" id="azp-fin"></div>
    </div>
</div>
<div class="wd-modal-bg" id="az-modal">
    <div class="window wd-modal">
        <div class="title-bar">
            <div class="title-bar-text">Cómo se juega</div>
            <div class="title-bar-controls"><button aria-label="Close" id="az-modal-x"></button></div>
        </div>
        <div class="window-body">
            Ajedrez de siempre, en tiempo real y con reloj. Mueve tocando la pieza y luego la casilla, o arrastrándola.
            <div style="margin-top:6px"><b>Retos</b>: a tus amigos, o con "Retar con un enlace" a quien quieras (lo acepta el primero que lo abra; caduca en 1 hora). Eliges el ritmo (minutos y segundos que se suman en cada jugada) y, si quieres, una apuesta de puntos de Autismo: los dos ponéis lo mismo y quien gana se lo lleva todo. En tablas, cada uno recupera lo suyo. Si alguno no entra en 2 minutos, se anula y se devuelve la apuesta.</div>
            <div style="margin-top:6px"><b>Torneos</b>: eliminatoria directa. Quien lo organiza pone la entrada (que es para él), los premios del 1.º, 2.º y 3.º (que salen de sus puntos) y la hora de inicio.</div>
            <div style="margin-top:6px">Si cierras la partida o te quedas sin conexión más de 60 segundos, la pierdes.</div>
            <div class="wd-modal-btns"><button type="button" id="az-modal-ok">Aceptar</button></div>
        </div>
    </div>
</div>

<!-- AJEDREZ: confirmaciones (no se usa confirm(): bloquea las pruebas) -->
<div class="wd-modal-bg" id="az-conf">
    <div class="window wd-modal az-modal">
        <div class="title-bar">
            <div class="title-bar-text" id="az-conf-tit">Ajedrez</div>
            <div class="title-bar-controls"><button aria-label="Close" id="az-conf-x"></button></div>
        </div>
        <div class="window-body">
            <div id="az-conf-txt"></div>
            <div class="wd-modal-btns" style="gap:6px"><button type="button" id="az-conf-no">Cancelar</button><button type="button" id="az-conf-si">Aceptar</button></div>
        </div>
    </div>
</div>

    </div><!-- /.jg-views -->
        <div class="mh-statusbar">
            <a href="#" id="jg-menu-link">‹ Menú</a>
        </div>
    </div><!-- /.window-body -->
</div>

<div class="wd-modal-bg" id="wd-modal">
    <div class="window wd-modal">
        <div class="title-bar">
            <div class="title-bar-text">Cómo se juega</div>
            <div class="title-bar-controls"><button aria-label="Close" id="wd-modal-x"></button></div>
        </div>
        <div class="window-body">
            Adivina la palabra del día en 6 intentos. Es la misma para todos y cambia a medianoche.
            Cada intento tiene que ser una palabra de 5 letras. La ñ cuenta como letra; las tildes no hace falta ponerlas.
            <div class="ex"><div class="wd-tile ok">G</div><div class="wd-tile">A</div><div class="wd-tile">T</div><div class="wd-tile">O</div><div class="wd-tile">S</div></div>
            Verde: la letra está en la palabra y en ese sitio.
            <div class="ex"><div class="wd-tile">P</div><div class="wd-tile cerca">L</div><div class="wd-tile">A</div><div class="wd-tile">Z</div><div class="wd-tile">A</div></div>
            Amarillo: está en la palabra, pero en otro sitio.
            <div class="ex"><div class="wd-tile">T</div><div class="wd-tile">E</div><div class="wd-tile">C</div><div class="wd-tile no">L</div><div class="wd-tile">A</div></div>
            Gris: no está en la palabra.
            <div style="margin-top:6px">Si aciertas ganas puntos de Autismo: 60 a la primera, 50, 40, 30, 20 y 10 a la sexta.</div>
            <div class="wd-modal-btns"><button type="button" id="wd-modal-ok">Aceptar</button></div>
        </div>
    </div>
</div>

<div class="wd-modal-bg" id="bm-modal">
    <div class="window wd-modal">
        <div class="title-bar">
            <div class="title-bar-text">Cómo se juega</div>
            <div class="title-bar-controls"><button aria-label="Close" id="bm-modal-x"></button></div>
        </div>
        <div class="window-body">
            Destapa todas las casillas sin mina. Cada número dice cuántas minas hay en las 8 casillas de alrededor.
            Toca para destapar. Para poner o quitar una bandera, mantén pulsada la casilla o cambia a 🚩 con el botón de arriba. Toca un número con todas sus banderas puestas para destapar las de alrededor.
            <div style="margin-top:6px"><b>Tablero del día</b>: hay tres (iguales para todos) y eliges uno al día, con un intento. Empiezan con una zona abierta y se pueden resolver sin adivinar. Despejarlos da 50, 100 y 200 puntos de Autismo; si pisas una mina, la parte de las minas que tuvieras bien marcadas con bandera. Al acabar el día, los 3 más rápidos de cada tablero se llevan +50, +30 y +15.</div>
            <div style="margin-top:6px"><b>Infinito</b>: rondas cada vez más difíciles y, al llegar a Experto, con menos tiempo. Al pasar una ronda eliges: seguir o retirarte con lo ganado. Si pierdes (mina o tiempo), lo pierdes todo.</div>
            <div style="margin-top:6px"><b>Libre</b>: eliges la dificultad; no da puntos.</div>
            <div class="wd-modal-btns"><button type="button" id="bm-modal-ok">Aceptar</button></div>
        </div>
    </div>
</div>

<script>
/* Volver al menú de la carcasa (como el resto de apps móviles). */
(function(){
    function goMenu(e){
        if (e) e.preventDefault();
        if (window.parent && window.parent !== window) {
            try { window.parent.postMessage({ type: 'shell:back' }, '*'); return; } catch (_) {}
        }
        try { history.back(); } catch (_) { location.href = '../../mobile.php'; }
    }
    document.getElementById('jg-close').addEventListener('click', goMenu);
    document.getElementById('jg-menu-link').addEventListener('click', goMenu);
})();

/* ── Wordelon: copia del JS de apps/juegos.php con los paths del móvil ── */
(function () {
    'use strict';
    var API = '../../assets/juegos/api.php';
    var PT  = '<img src="../../assets/img/appIcons/puntosAutismo.png" class="pt-ic" alt="">';
    var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var FILAS_KB = ['qwertyuiop', 'asdfghjklñ', '<zxcvbnm>'];

    var estado = null;     /* lo que manda el servidor (assets/juegos/wordelon.php) */
    var actual = '';       /* lo que se está escribiendo */
    var ocupado = false;   /* esperando al servidor */
    var msgTimer = null;

    var $ = function (id) { return document.getElementById(id); };

    function show(view) {
        Array.prototype.forEach.call(document.querySelectorAll('.jg-view'), function (v) { v.classList.toggle('on', v.id === view); });
    }

    function api(action, body) {
        var opt = { credentials: 'same-origin' };
        if (body) {
            opt.method = 'POST';
            opt.headers = { 'Content-Type': 'application/json' };
            opt.body = JSON.stringify(body);
        }
        return fetch(API + '?action=' + action, opt).then(function (r) { return r.json(); });
    }

    /* Igual que wordelon_norm del servidor: sin tildes, con la ñ. */
    function norm(ch) {
        ch = (ch || '').toLowerCase();
        if (ch === 'ñ') return 'ñ';
        return ch.normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function fecha(dia) {
        var p = dia.split('-');
        return (+p[2]) + ' de ' + MESES[+p[1] - 1] + ' de ' + p[0];
    }

    function msg(t, ms) {
        $('wd-msg').textContent = t || '';
        clearTimeout(msgTimer);
        if (t && ms) msgTimer = setTimeout(function () { $('wd-msg').textContent = ''; }, ms);
    }

    /* ── Pintado ── */
    function buildBoard() {
        var b = $('wd-board');
        b.innerHTML = '';
        for (var r = 0; r < estado.maximo; r++) {
            var row = document.createElement('div');
            row.className = 'wd-row';
            for (var c = 0; c < estado.largo; c++) {
                var t = document.createElement('div');
                t.className = 'wd-tile';
                row.appendChild(t);
            }
            b.appendChild(row);
        }
    }

    function buildKeyboard() {
        var kb = $('wd-kb');
        kb.innerHTML = '';
        FILAS_KB.forEach(function (fila) {
            var row = document.createElement('div');
            row.className = 'wd-kb-row';
            Array.from(fila).forEach(function (ch) {
                var k = document.createElement('button');
                k.type = 'button';
                k.className = 'wd-key';
                if (ch === '<')      { k.textContent = 'Borrar'; k.classList.add('wide'); k.dataset.k = 'borrar'; }
                else if (ch === '>') { k.textContent = 'Enviar'; k.classList.add('wide'); k.dataset.k = 'enviar'; }
                else                 { k.textContent = ch.toUpperCase(); k.dataset.k = ch; }
                k.addEventListener('click', function () { tecla(k.dataset.k); k.blur(); });
                row.appendChild(k);
            });
            kb.appendChild(row);
        });
    }

    function paintRow(r, palabra, pistas, animar) {
        var row = $('wd-board').children[r];
        if (!row) return;
        var letras = Array.from(palabra || '');
        for (var c = 0; c < estado.largo; c++) {
            var t = row.children[c];
            t.textContent = letras[c] ? letras[c].toUpperCase() : '';
            t.className = 'wd-tile' + (letras[c] ? ' full' : '');
            if (pistas) {
                (function (t, p, d) {
                    if (!animar) { t.classList.add(p); return; }
                    setTimeout(function () {
                        t.classList.add('reveal');
                        setTimeout(function () { t.classList.add(p); }, 170);
                    }, d);
                })(t, pistas[c], c * 220);
            }
        }
    }

    function paintKeys() {
        /* Cada letra se queda con su mejor pista: ok > cerca > no. */
        var rango = { no: 1, cerca: 2, ok: 3 }, mejor = {};
        estado.filas.forEach(function (f) {
            Array.from(f.palabra).forEach(function (ch, i) {
                var p = f.pistas[i];
                if (!mejor[ch] || rango[p] > rango[mejor[ch]]) mejor[ch] = p;
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('.wd-key'), function (k) {
            k.classList.remove('ok', 'cerca', 'no');
            if (mejor[k.dataset.k]) k.classList.add(mejor[k.dataset.k]);
        });
    }

    function paintEnd() {
        var e = $('wd-end');
        if (!estado.terminado) { e.classList.remove('on'); e.innerHTML = ''; return; }
        var n = estado.filas.length;
        e.innerHTML = estado.acertado
            ? '<b>¡Acertada en ' + n + '/' + estado.maximo + '!</b><br>+' + estado.puntos + ' ' + PT + ' puntos de Autismo'
            : '<b>Hoy no ha podido ser</b><br>La palabra era <b>' + estado.palabra + '</b>';
        e.innerHTML += '<br>Vuelve mañana para el siguiente Wordelon.';
        e.classList.add('on');
    }

    function paintAll() {
        $('wd-title').textContent = estado.nombre + ' #' + estado.numero;
        $('wd-date').textContent = fecha(estado.dia);
        buildBoard();
        estado.filas.forEach(function (f, i) { paintRow(i, f.palabra, f.pistas, false); });
        if (!estado.terminado) paintRow(estado.filas.length, actual, null, false);
        paintKeys();
        paintEnd();
        paintHomeState();
    }

    function paintHomeState() {
        var s = $('jg-wordelon-state');
        if (!estado) { s.textContent = ''; return; }
        if (!estado.terminado) s.textContent = estado.filas.length ? 'A medias' : 'Sin jugar hoy';
        else s.textContent = 'Hoy: ' + (estado.acertado ? estado.filas.length : 'X') + '/' + estado.maximo;
    }

    /* ── Juego ── */
    function tecla(k) {
        if (!estado || estado.terminado || ocupado) return;
        if (k === 'enviar') return enviar();
        if (k === 'borrar') actual = Array.from(actual).slice(0, -1).join('');
        else if (Array.from(actual).length < estado.largo) actual += k;
        paintRow(estado.filas.length, actual, null, false);
    }

    function shake() {
        var row = $('wd-board').children[estado.filas.length];
        if (!row) return;
        row.classList.remove('shake'); void row.offsetWidth; row.classList.add('shake');
    }

    function enviar() {
        if (Array.from(actual).length < estado.largo) { msg('Faltan letras', 1800); shake(); return; }
        ocupado = true;
        var r = estado.filas.length;
        api('wordelon-intento', { intento: actual, dia: estado.dia }).then(function (res) {
            ocupado = false;
            if (res.diaNuevo) {
                actual = ''; estado = res.estado; paintAll();
                msg('Ha empezado un Wordelon nuevo', 3000);
                return;
            }
            if (!res.ok) { msg(res.error || 'No se ha podido enviar', 2200); shake(); return; }
            actual = '';
            estado = res.estado;
            var f = estado.filas[r];
            paintRow(r, f.palabra, f.pistas, true);
            /* Teclado y resultado cuando acaba de girar la fila. */
            setTimeout(function () {
                paintKeys(); paintEnd(); paintHomeState();
                if (estado.terminado) msg(estado.acertado ? '¡Muy bien!' : '', 0);
            }, estado.largo * 220 + 250);
        }).catch(function () {
            ocupado = false;
            msg('Sin conexión, prueba otra vez', 2200);
        });
    }

    /* Teclado físico (tablets, Chromebook): igual que el PC. */
    document.addEventListener('keydown', function (e) {
        if (!$('jg-wordelon').classList.contains('on')) return;
        if ($('wd-modal').classList.contains('on')) {
            if (e.key === 'Escape' || e.key === 'Enter') { $('wd-modal').classList.remove('on'); e.preventDefault(); }
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key === 'Enter')     { tecla('enviar'); e.preventDefault(); return; }
        if (e.key === 'Backspace') { tecla('borrar'); e.preventDefault(); return; }
        if (e.key && Array.from(e.key).length === 1) {
            var ch = norm(e.key);
            if (/^[a-zñ]$/.test(ch)) { tecla(ch); e.preventDefault(); }
        }
    });

    function cargar() {
        return api('wordelon').then(function (res) {
            if (res && res.estado) { estado = res.estado; return true; }
            return false;
        }).catch(function () { return false; });
    }

    function abrirWordelon() {
        show('jg-wordelon');
        msg('Cargando…');
        cargar().then(function (ok) {
            if (!ok) { msg('No se ha podido cargar el Wordelon'); return; }
            msg('');
            paintAll();
        });
    }

    /* ── Estadísticas de hoy ── */
    function cargarStats() {
        var box = $('st-scroll');
        $('st-sum').textContent = 'Cargando…';
        box.innerHTML = '';
        api('wordelon-hoy').then(function (r) {
            if (!r || !r.ok) { $('st-sum').textContent = 'No se han podido cargar'; return; }
            $('st-title').textContent = 'Wordelon #' + r.numero;
            $('st-date').textContent = fecha(r.dia);
            $('st-sum').textContent = r.texto;
            if (!r.jugadores) {
                box.innerHTML = '<div class="st-empty">Todavía no ha terminado nadie la partida de hoy.</div>';
                return;
            }
            if (!r.imagen) {
                box.innerHTML = '<div class="st-empty">El servidor no puede dibujar las tarjetas.</div>';
                return;
            }
            var img = new Image();
            img.alt = 'Resultados de hoy';
            /* A su tamaño (no se escala): las letras pixel quedan nítidas. */
            img.onload = function () { img.style.width = img.naturalWidth + 'px'; };
            /* A 1x: tarjetas de 112 px + 8 de separación; tantas por fila
               como quepan (3 en un móvil normal). */
            var cols = Math.max(1, Math.min(5, Math.floor((box.clientWidth - 16 + 8) / 120)));
            img.src = API + '?action=wordelon-hoy-img&escala=1&cols=' + cols + '&_=' + Date.now();
            box.appendChild(img);
        }).catch(function () { $('st-sum').textContent = 'Sin conexión, prueba otra vez'; });
    }
    function abrirStats() { show('jg-stats'); cargarStats(); }

    $('wd-stats').addEventListener('click', abrirStats);
    $('st-back').addEventListener('click', function () { show('jg-wordelon'); });
    $('st-reload').addEventListener('click', cargarStats);

    $('jg-open-wordelon').addEventListener('click', abrirWordelon);
    $('jg-open-wordelon').addEventListener('keydown', function (e) { if (e.key === 'Enter') abrirWordelon(); });
    $('wd-back').addEventListener('click', function () { show('jg-home'); paintHomeState(); });
    $('wd-help').addEventListener('click', function () { $('wd-modal').classList.add('on'); });
    $('wd-modal-x').addEventListener('click', function () { $('wd-modal').classList.remove('on'); });
    $('wd-modal-ok').addEventListener('click', function () { $('wd-modal').classList.remove('on'); });

    buildKeyboard();
    cargar().then(paintHomeState);

    /* Lo común para los demás juegos cuando se pasen (como el PC). */
    window.JG = { api: api, show: show, fecha: fecha, PT: PT, API: API, $: $,
                  RAIZ: '../../', movil: true };
})();
</script>
<script src="../../assets/juegos/buscaminas.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/juegos/buscaminas.js') ?>"></script>
<?php
/* ?abrir=partida:N | torneo:N (aviso del ajedrez de la carcasa o enlace
   del anuncio de Discord), como apps/juegos.php. */
$_jgAbrir = (string)($_GET['abrir'] ?? '');
if (!preg_match('/^((partida|torneo):[0-9]{1,10}|reto:[a-z0-9]{10})$/', $_jgAbrir)) $_jgAbrir = '';
/* Archivo del timbre del usuario: el menú del ajedrez lo mira. */
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/assets/php/timbre.php';
$_jgSt = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
$_jgSt->execute([$userKey]);
$_jgUid = (int)$_jgSt->fetchColumn();
rt_asegurarUsuario($_jgUid);
?>
<script>window.JG_ABRIR = <?= json_encode($_jgAbrir) ?>; window.JG_TIMBRE = <?= json_encode($_jgUid > 0 ? rt_urlUsuario($_jgUid) : '') ?>;</script>
<script>
/* Pestañas Jugadas / Chat de la partida (solo en el móvil). Al volver al
   chat, abajo del todo (lo nuevo). */
(function () {
    var tabs = document.querySelectorAll('.az-pest-tabs [data-pest]');
    Array.prototype.forEach.call(tabs, function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(tabs, function (x) {
                x.classList.toggle('on', x === b);
                x.setAttribute('aria-selected', x === b ? 'true' : 'false');
            });
            Array.prototype.forEach.call(document.querySelectorAll('.az-pest-panel'), function (p) {
                p.hidden = p.dataset.pest !== b.dataset.pest;
            });
            var l = document.querySelector('#azp-chat .az-chat-lista');
            if (b.dataset.pest === 'chat' && l) l.scrollTop = l.scrollHeight;
            var j = document.getElementById('azp-jugadas');
            if (b.dataset.pest === 'jug' && j) j.scrollTop = j.scrollHeight;
        });
    });
})();
</script>
<!-- Copiar enlace (retos por enlace y torneos). -->
<script src="../../assets/js/melon-links.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-links.js') ?>"></script>
<script src="../../assets/juegos/ajedrez.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/juegos/ajedrez.js') ?>"></script>
</body>
</html>
