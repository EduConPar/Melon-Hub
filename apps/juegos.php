<?php
/**
 * apps/juegos.php
 * App "Juegos" del escritorio (iframe #juegos-iframe en desktop-base.php).
 * Portada con la lista de juegos y, de momento, uno: Wordelon
 * (servidor en assets/juegos/wordelon.php, API en assets/juegos/api.php).
 */

@ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/../assets/config.php';
require_once __DIR__ . '/../assets/mobile-detect.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../assets/themes/theme-helpers.php';

$_juegosIsTablet = isTabletDevice();

if (empty($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    http_response_code(403); echo 'No autorizado'; exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

/* Tema activo (mismo bloque que el resto de apps en iframe). */
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php if ($_juegosIsTablet): ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php else: ?>
    <meta name="viewport" content="width=1024, user-scalable=yes">
    <?php endif; ?>
    <title>Juegos</title>
    <!-- Mismo orden que calendario.php / tienda.php: el CSS de la interfaz
         DESPUÉS de tokens.css, para que sus variables (las de Kawaii) no
         las pisen los valores de Win98. -->
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <!-- Botones y demás piezas de 98.css con los colores del tema (como en
         calendario.php y tienda.php). -->
    <link rel="stylesheet" href="../assets/css/themes.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/css/themes.css') ?>">
    <!-- Fotos con el marco de perfil de cada uno (rankings del Buscaminas). -->
    <link rel="stylesheet" href="../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/css/marcos.css') ?>">
    <script src="../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/melon-marcos.js') ?>"></script>
    <?php if ($themeCssRel): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?= htmlspecialchars($themeCssRel) ?>">
    <?php endif; ?>
    <style>
        html, body {
            margin: 0; padding: 0; height: 100%; overflow: hidden;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            font-size: 11px;
            font-family: 'Pixelated MS Sans Serif', 'ms_sans_serif', Tahoma, sans-serif;
        }
        .jg-view { display: none; height: 100%; flex-direction: column; box-sizing: border-box; }
        .jg-view.on { display: flex; }

        /* ── Portada: lista de juegos ─────────────────────────────── */
        #jg-home { padding: 12px; gap: 10px; }
        .jg-home-title { font-weight: bold; font-size: 13px; }
        .jg-list {
            flex: 1; overflow: auto; padding: 8px;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        .jg-card {
            display: flex; align-items: center; gap: 10px; width: 100%;
            padding: 8px; box-sizing: border-box; cursor: pointer;
            background: var(--win-bg, silver);
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-1, #fff),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-2, #dfdfdf);
        }
        .jg-card:active {
            box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a), inset -1px -1px var(--bezel-light-1, #fff),
                        inset 2px 2px var(--bezel-dark-2, grey), inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        .jg-card-ic {
            width: 40px; height: 40px; flex-shrink: 0;
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 2px; padding: 2px; box-sizing: border-box;
        }
        .jg-card-ic i { display: block; }
        .jg-card-name { font-weight: bold; font-size: 12px; }
        .jg-card-sub  { color: var(--text-muted, #444); margin-top: 2px; }
        .jg-card-state { margin-left: auto; color: var(--text-muted, #444); white-space: nowrap; }

        /* ── Wordelon ─────────────────────────────────────────────── */
        #jg-wordelon { padding: 8px 12px 12px; align-items: center; }
        /* Tres columnas: las de los lados iguales (1fr) para que el título
           quede en el centro de la ventana aunque "< Juegos" sea más ancho
           que "?" (con flex se iba a la derecha). */
        .wd-top { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 8px; width: 100%; }
        .wd-top button { min-width: 0; }
        .wd-top > button:first-child { justify-self: start; }
        .wd-top-right { justify-self: end; display: flex; gap: 4px; }
        .wd-head { text-align: center; }
        .wd-title { font-weight: bold; font-size: 13px; }
        .wd-date  { color: var(--text-muted, #444); margin-top: 2px; }
        .wd-help  { width: 23px; padding: 0; }

        .wd-msg {
            min-height: 16px; margin: 8px 0 4px; text-align: center; font-weight: bold;
        }
        .wd-board { display: grid; gap: 5px; margin: 4px 0 10px; }
        .wd-row { display: grid; grid-template-columns: repeat(5, 46px); gap: 5px; }
        .wd-row.shake { animation: wd-shake .4s; }
        @keyframes wd-shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); } 40% { transform: translateX(5px); }
            60% { transform: translateX(-4px); } 80% { transform: translateX(4px); }
        }
        .wd-tile {
            width: 46px; height: 46px; box-sizing: border-box;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: bold;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        /* Las letras ya puestas (en relieve) y las pistas de colores. Los
           colores son fijos y no del tema: tienen que casar con los
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

        .wd-kb { display: flex; flex-direction: column; gap: 4px; align-items: center; }
        .wd-kb-row { display: flex; gap: 4px; }
        .wd-key {
            min-width: 30px; width: 30px; height: 36px; padding: 0;
            font-size: 12px; font-weight: bold;
        }
        .wd-key.wide { width: 58px; min-width: 58px; font-size: 11px; }
        /* 98.css pinta el texto de los botones con text-shadow (color
           transparente), así que el blanco de las teclas con pista va ahí. */
        .wd-key.ok, .wd-key.cerca, .wd-key.no { text-shadow: 0 0 #fff !important; }
        .wd-key.ok    { background: #3f9a3f !important; }
        .wd-key.cerca { background: #c99a0e !important; }
        .wd-key.no    { background: #6b6b6b !important; }

        .wd-end {
            display: none; margin-top: 10px; padding: 8px 12px; text-align: center; line-height: 1.6;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        .wd-end.on { display: block; }
        .wd-end b { font-size: 12px; }
        .pt-ic { width: 14px; height: 14px; object-fit: contain; image-rendering: pixelated; vertical-align: middle; }

        /* ── Estadísticas de hoy: la misma imagen que publica el bot
           (assets/juegos/wordelon-img.php), cada tarjeta con la interfaz
           y el tema de su jugador, a 1x (tarjetas de 112 px) y con las
           que quepan por fila. */
        /* Sin margen a los lados en la zona de la imagen: con la barra de
           scroll (16 px) quedan 476 px para los 464 de la imagen. */
        #jg-stats { padding: 8px 0 0; align-items: center; }
        #jg-stats .wd-top { padding: 0 12px; box-sizing: border-box; }
        .st-sum { margin: 8px 12px; text-align: center; font-weight: bold; }
        .st-scroll { flex: 1; overflow: auto; width: 100%; text-align: center; }
        .st-scroll img { display: block; margin: 0 auto; image-rendering: pixelated; }
        .st-empty { margin-top: 30px; text-align: center; color: var(--text-muted, #444); }

        /* ── Colores del tema en lo que 98.css pinta fijo: el título de
           los recuadros (fondo plateado) y los desplegables (blanco). Con
           un tema oscuro no se leían. ── */
        fieldset legend { background: var(--win-bg, silver); color: var(--text, #000); }
        select { background-color: var(--input-bg, #fff); color: var(--input-text, #000); }
        select option { background-color: var(--input-bg, #fff); color: var(--input-text, #000); }
        select:disabled { color: var(--text-muted, grey); }

        /* ── Buscaminas (assets/juegos/buscaminas.js) ────────────────── */
        #jg-bm, #jg-bm-juego { padding: 8px 12px 12px; align-items: center; }
        #jg-bm-stats { padding: 8px 0 0; align-items: center; }
        #jg-bm-stats .wd-top { padding: 0 12px; box-sizing: border-box; }
        .bm-menu { width: 100%; overflow: auto; margin-top: 8px; display: flex; flex-direction: column; gap: 8px; }
        .bm-menu fieldset { padding: 8px 10px; }
        .bm-row { display: grid; grid-template-columns: 1fr auto auto; gap: 8px; align-items: center; padding: 3px 0; }
        .bm-row-name b { font-size: 12px; }
        .bm-row-state { color: var(--text-muted, #444); text-align: right; white-space: nowrap; }
        .bm-row button, .bm-libre button { min-width: 80px; }
        .bm-nota { color: var(--text-muted, #444); margin-top: 6px; }
        .bm-libre { display: flex; gap: 8px; align-items: center; }
        /* Alto automático: 98.css lo fija a 21 px y en Kawaii (letra más
           grande) se cortaba el texto. */
        .bm-libre select { flex: 1; height: auto; min-height: 21px; }
        .bm-inf-line { margin-bottom: 6px; line-height: 1.5; }
        /* Tablero del día: vista previa (tapada, o como lo dejó). */
        .bm-dia-info { margin: 8px 0 6px; text-align: center; font-weight: bold; }
        .bm-prev-wrap { display: flex; justify-content: center; }
        .bm-prev { pointer-events: none; }
        /* Infinito en el menú: tú a la izquierda, los 5 mejores a la derecha. */
        .bm-inf-cols { display: grid; grid-template-columns: 1fr 260px; gap: 12px; align-items: start; }
        .bm-yo { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
        .bm-yo .bm-av { width: 48px; height: 48px; }
        /* Rankings: puesto, foto con marco, nombre y valor. */
        .bm-side-tit { font-weight: bold; margin-bottom: 4px; }
        .bm-rank { display: flex; flex-direction: column; gap: 2px; }
        .bm-rk { display: grid; grid-template-columns: 22px 30px 1fr auto; align-items: center; gap: 6px; padding: 5px 6px; }
        .bm-rk .bm-rk-n { text-align: right; color: var(--text-muted, #444); }
        .bm-rk .bm-rk-nom { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bm-rk .bm-rk-v { font-weight: bold; white-space: nowrap; }
        .bm-rk.yo { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .bm-rk.yo .bm-rk-n { color: inherit; }
        .bm-rk-sep { text-align: center; color: var(--text-muted, #444); line-height: 1; }
        .bm-rank-vacio { color: var(--text-muted, #444); padding: 4px 6px; }
        .bm-av {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; background: var(--accent, navy); color: var(--accent-text, #fff); font-weight: bold;
        }
        .bm-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
        /* Al jugar: tablero a la izquierda (centrado) y ranking a la derecha. */
        .bmj-cols { display: flex; gap: 10px; width: 100%; flex: 1; min-height: 0; margin-top: 6px; }
        .bmj-main { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .bm-side {
            width: 200px; flex-shrink: 0; overflow: auto; padding: 8px; box-sizing: border-box;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        body.jg-kawaii .bm-side { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        .bm-btns { display: flex; gap: 6px; justify-content: center; margin-top: 6px; flex-wrap: wrap; }

        .bm-bar { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; width: 100%; margin: 0 0 6px; gap: 8px; }
        .bm-cont {
            justify-self: start; min-width: 54px; padding: 3px 6px; text-align: center;
            font-weight: bold; font-size: 14px; font-variant-numeric: tabular-nums;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        #bmj-tiempo { justify-self: end; }
        .bm-cont.urge { color: #c00000; }
        .bm-info { text-align: center; font-weight: bold; }
        .bm-wrap { flex: 0 0 auto; max-width: 100%; overflow: auto; }
        .bm-board {
            display: grid; padding: 3px; user-select: none; -webkit-user-select: none;
            background: var(--bezel-dark-2, grey);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -3px -3px var(--bezel-light-2, #dfdfdf), inset 3px 3px var(--bezel-dark-1, #0a0a0a);
        }
        /* Casillas: tapada en relieve; destapada plana con la rejilla fina. */
        .bm-c {
            width: var(--cs, 20px); height: var(--cs, 20px); box-sizing: border-box;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: calc(var(--cs, 20px) * .68); font-style: normal; line-height: 1;
            background-position: center; background-repeat: no-repeat; background-size: 70%;
        }
        .bm-c.t {
            background-color: var(--btn-bg, silver); cursor: pointer;
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-1, #fff),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-2, #dfdfdf);
        }
        .bm-c.t.p { box-shadow: inset 1px 1px var(--bezel-dark-2, grey); }
        .bm-c.a { background-color: var(--win-bg, silver); box-shadow: inset 1px 1px var(--bezel-dark-2, grey); }
        .bm-c.x { background-color: #e03030; }
        /* Colores de los números (los de siempre del género). */
        .bm-c.n1 { color: #0000ff; } .bm-c.n2 { color: #007b00; } .bm-c.n3 { color: #ff0000; } .bm-c.n4 { color: #00007b; }
        .bm-c.n5 { color: #7b0000; } .bm-c.n6 { color: #007b7b; } .bm-c.n7 { color: #000; }    .bm-c.n8 { color: #7b7b7b; }
        /* Tema oscuro (fondo de ventana oscuro, lo detecta buscaminas.js):
           los mismos colores de números, más claros, y la mina en blanco. */
        body.jg-oscuro .bm-c.n1 { color: #7aa8ff; } body.jg-oscuro .bm-c.n2 { color: #5fd35f; }
        body.jg-oscuro .bm-c.n3 { color: #ff6b6b; } body.jg-oscuro .bm-c.n4 { color: #b0b0ff; }
        body.jg-oscuro .bm-c.n5 { color: #ff9f7a; } body.jg-oscuro .bm-c.n6 { color: #5fdede; }
        body.jg-oscuro .bm-c.n7 { color: #fff; }    body.jg-oscuro .bm-c.n8 { color: #c8c8c8; }
        body.jg-oscuro .bm-c.a.bm-mina:not(.x) { filter: invert(1); }
        .bm-flag { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' shape-rendering='crispEdges'%3E%3Cpath fill='%23e00000' d='M3 1h4v1h1v1h1v1H8v1H7v1H3z'/%3E%3Cpath fill='%23000' d='M6 1h1v8H6zM4 9h5v1H4zM2 10h9v1H2z'/%3E%3C/svg%3E"); }
        .bm-mina, .bm-mal { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 13 13' shape-rendering='crispEdges'%3E%3Cpath fill='%23000' d='M6 0h1v13H6zM0 6h13v1H0zM2 2h1v1H2zM10 2h1v1h-1zM2 10h1v1H2zM10 10h1v1h-1zM4 3h5v1h1v1h1v3h-1v1h-1v1H4V9H3V8H2V5h1V4h1z'/%3E%3Cpath fill='%23fff' d='M4 4h2v2H4z'/%3E%3C/svg%3E"); }
        .bm-mal { position: relative; }
        .bm-mal::after { content: ''; position: absolute; inset: 15%; background: linear-gradient(45deg, transparent 44%, #e00000 44% 56%, transparent 56%), linear-gradient(-45deg, transparent 44%, #e00000 44% 56%, transparent 56%); }
        /* Kawaii: casillas con borde morado en vez de relieve. */
        body.jg-kawaii .bm-c.t { background-color: var(--win-body-bg, #F0E8F3); box-shadow: inset 0 0 0 1px var(--border, #49388B); }
        body.jg-kawaii .bm-c.t.p { background-color: var(--win-bg, #E0D4E7); }
        body.jg-kawaii .bm-c.a { background-color: var(--input-bg, #fff); box-shadow: inset 0 0 0 1px var(--win-bg, #E0D4E7); }
        body.jg-kawaii .bm-board { background: var(--border, #49388B); box-shadow: none; padding: 2px; }
        body.jg-kawaii .bm-cont { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        /* Icono de la portada. */
        .bm-card-ic { --cs: 18px; }
        .bm-card-ic .bm-c { width: 18px; height: 18px; font-size: 12px; }

        /* Ventanita Win98 de "Cómo se juega". */
        .wd-modal-bg {
            display: none; position: fixed; inset: 0; z-index: 50;
            align-items: center; justify-content: center; background: rgba(0,0,0,.25);
        }
        .wd-modal-bg.on { display: flex; }
        .wd-modal { width: 340px; }
        .wd-modal .window-body { line-height: 1.5; }
        .wd-modal .ex { display: flex; gap: 3px; margin: 6px 0 2px; }
        .wd-modal .ex .wd-tile { width: 26px; height: 26px; font-size: 14px; }
        .wd-modal-btns { display: flex; justify-content: flex-end; margin-top: 8px; }
    </style>
</head>
<body class="<?= htmlspecialchars($themeClass) ?><?= (($_COOKIE['activeInterface'] ?? '') === 'kawaii') ? ' jg-kawaii' : '' ?>">

<!-- PORTADA -->
<div class="jg-view on" id="jg-home">
    <div class="jg-home-title">Juegos</div>
    <div class="jg-list">
        <div class="jg-card" id="jg-open-wordelon" role="button" tabindex="0">
            <div class="jg-card-ic" aria-hidden="true">
                <i style="background:#3f9a3f"></i><i style="background:#c99a0e"></i>
                <i style="background:#6b6b6b"></i><i style="background:#3f9a3f"></i>
            </div>
            <div>
                <div class="jg-card-name">Wordelon</div>
                <div class="jg-card-sub">Adivina la palabra del día en 6 intentos</div>
            </div>
            <div class="jg-card-state" id="jg-wordelon-state"></div>
        </div>
        <div class="jg-card" id="jg-open-bm" role="button" tabindex="0" style="margin-top:6px">
            <div class="jg-card-ic bm-card-ic" aria-hidden="true">
                <i class="bm-c t"></i><i class="bm-c a n1">1</i>
                <i class="bm-c a"></i><i class="bm-c t bm-flag"></i>
            </div>
            <div>
                <div class="jg-card-name">Buscaminas</div>
                <div class="jg-card-sub">Tablero del día, libre e infinito</div>
            </div>
            <div class="jg-card-state" id="jg-bm-state"></div>
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
    <div class="wd-board" id="wd-board"></div>
    <div class="wd-kb" id="wd-kb"></div>
    <div class="wd-end" id="wd-end"></div>
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
            <button type="button" id="bm-stats">Estadísticas</button>
            <button type="button" class="wd-help" id="bm-help" title="Cómo se juega" aria-label="Cómo se juega">?</button>
        </div>
    </div>
    <div class="bm-menu">
        <fieldset>
            <legend>Tablero del día</legend>
            <div class="bm-libre">
                <select id="bm-dia-nivel">
                    <option value="1">Principiante (9×9, 10 minas) · 50 puntos</option>
                    <option value="2">Intermedio (16×16, 40 minas) · 100 puntos</option>
                    <option value="3">Experto (30×16, 99 minas) · 200 puntos</option>
                </select>
                <button type="button" id="bm-dia-jugar">Jugar</button>
            </div>
            <div class="bm-dia-info" id="bm-dia-info"></div>
            <div class="bm-prev-wrap"><div class="bm-board bm-prev" id="bm-dia-prev"></div></div>
            <div class="bm-nota">Solo puedes hacer uno al día y tienes un intento. El tiempo empieza al darle a Jugar.</div>
        </fieldset>
        <fieldset>
            <legend>Infinito</legend>
            <div class="bm-inf-cols">
                <div id="bm-inf-box"></div>
                <div class="bm-inf-top">
                    <div class="bm-side-tit">Mejores récords</div>
                    <div class="bm-rank" id="bm-inf-top"></div>
                </div>
            </div>
        </fieldset>
        <fieldset>
            <legend>Libre</legend>
            <div class="bm-libre">
                <select id="bm-libre-nivel">
                    <option value="1">Principiante (9×9, 10 minas)</option>
                    <option value="2">Intermedio (16×16, 40 minas)</option>
                    <option value="3">Experto (30×16, 99 minas)</option>
                </select>
                <button type="button" id="bm-libre-jugar">Jugar</button>
            </div>
            <div class="bm-nota">Sin puntos.</div>
        </fieldset>
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
        <!-- El tablero, siempre centrado en su zona (a la izquierda del ranking). -->
        <div class="bmj-main" id="bmj-main">
            <div class="bm-bar">
                <div class="bm-cont" id="bmj-minas" title="Minas sin marcar">0</div>
                <div class="bm-info" id="bmj-info"></div>
                <div class="bm-cont" id="bmj-tiempo" title="Tiempo">0:00</div>
            </div>
            <div class="bm-wrap"><div class="bm-board" id="bmj-board"></div></div>
            <div class="wd-end" id="bmj-end"></div>
        </div>
        <div class="bm-side" id="bmj-side">
            <div class="bm-side-tit" id="bmj-rank-tit">Ranking</div>
            <div class="bm-rank" id="bmj-rank"></div>
        </div>
    </div>
</div>

<!-- BUSCAMINAS: estadísticas de hoy -->
<div class="jg-view" id="jg-bm-stats">
    <div class="wd-top">
        <button type="button" id="bms-back">&lt; Buscaminas</button>
        <div class="wd-head">
            <div class="wd-title">Buscaminas</div>
            <div class="wd-date" id="bms-date"></div>
        </div>
        <div class="wd-top-right">
            <button type="button" id="bms-reload">Actualizar</button>
        </div>
    </div>
    <div class="st-sum" id="bms-sum"></div>
    <div class="st-scroll" id="bms-scroll"></div>
</div>

<div class="wd-modal-bg" id="bm-modal">
    <div class="window wd-modal">
        <div class="title-bar">
            <div class="title-bar-text">Cómo se juega</div>
            <div class="title-bar-controls"><button aria-label="Close" id="bm-modal-x"></button></div>
        </div>
        <div class="window-body">
            Destapa todas las casillas sin mina. Cada número dice cuántas minas hay en las 8 casillas de alrededor.
            Clic: destapar. Clic derecho: poner o quitar bandera. Clic en un número con todas sus banderas puestas: destapa las de alrededor.
            <div style="margin-top:6px"><b>Tablero del día</b>: hay tres (iguales para todos) y eliges uno al día, con un intento. Empiezan con una zona abierta y se pueden resolver sin adivinar. Despejarlos da 50, 100 y 200 puntos de Autismo; si pisas una mina, la parte de las minas que tuvieras bien marcadas con bandera. Al acabar el día, los 3 más rápidos de cada tablero se llevan +50, +30 y +15.</div>
            <div style="margin-top:6px"><b>Infinito</b>: rondas cada vez más difíciles y, al llegar a Experto, con menos tiempo. Al pasar una ronda eliges: seguir o retirarte con lo ganado. Si pierdes (mina o tiempo), lo pierdes todo.</div>
            <div style="margin-top:6px"><b>Libre</b>: eliges la dificultad; no da puntos.</div>
            <div class="wd-modal-btns"><button type="button" id="bm-modal-ok">Aceptar</button></div>
        </div>
    </div>
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

<script>
(function () {
    'use strict';
    var API = '../assets/juegos/api.php';
    var PT  = '<img src="../assets/img/appIcons/puntosAutismo.png" class="pt-ic" alt="">';
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
            /* A 1x: tarjetas de 112 px + 8 de separación; tantas por
               fila como quepan (4 en la ventana de 500). */
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

    /* Lo común para los demás juegos (assets/juegos/buscaminas.js). */
    window.JG = { api: api, show: show, fecha: fecha, PT: PT, API: API, $: $ };
})();
</script>
<script src="../assets/juegos/buscaminas.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/juegos/buscaminas.js') ?>"></script>
</body>
</html>
