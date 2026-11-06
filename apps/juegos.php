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
        /* themes.css usa el atajo `background`, que deja la imagen en
           "repetir": al pulsar (98.css pone la flecha de :active) salía una
           fila de flechas. La flecha, una y en la esquina. */
        select { background-color: var(--input-bg, #fff); color: var(--input-text, #000);
                 background-repeat: no-repeat; background-position: top 2px right 2px; }
        select option { background-color: var(--input-bg, #fff); color: var(--input-text, #000); }
        select:disabled { color: var(--text-muted, grey); }

        /* ── Buscaminas (assets/juegos/buscaminas.js) ────────────────── */
        #jg-bm, #jg-bm-juego { padding: 8px 12px 12px; align-items: center; }
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
        /* ── Menú del Buscaminas: secciones como ventanitas ── */
        .bm-win {
            display: flex; flex-direction: column; padding: 3px; background: var(--win-bg, silver);
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-2, #dfdfdf),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-1, #fff);
        }
        .bm-win-bar {
            display: flex; align-items: center; gap: 6px; padding: 3px 6px; font-weight: bold;
            background: linear-gradient(90deg, var(--titlebar-start, navy), var(--titlebar-end, #1084d0));
            color: var(--titlebar-text, #fff);
        }
        .bm-win-bar-r { margin-left: auto; font-weight: normal; }
        .bm-ic { fill: currentColor; flex-shrink: 0; }
        .bm-win-body { padding: 8px 10px; flex: 1; }
        body.jg-kawaii .bm-win { box-shadow: none; border: 2px solid var(--border, #49388B); padding: 4px; }
        body.jg-kawaii .bm-win-bar { background: var(--win-body-bg, #F0E8F3); border: 2px solid var(--border, #49388B); }
        body.jg-kawaii .bm-win-body { background: var(--win-body-bg, #F0E8F3); border: 2px solid var(--border, #49388B); margin-top: 4px; }
        /* Tablero del día: vista previa a la izquierda, datos a la derecha. */
        .bm-dia-grid { display: grid; grid-template-columns: auto 1fr; gap: 14px; align-items: start; }
        .bm-dia-izq { display: flex; justify-content: center; }
        .bm-dia-der { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .bm-campos { display: flex; gap: 4px; }
        .bm-campo {
            flex: 1; padding: 3px 6px; min-width: 0;
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey);
        }
        body.jg-kawaii .bm-campo { box-shadow: inset 0 0 0 1px var(--border, #49388B); }
        .bm-campo small { display: block; color: var(--text-muted, #444); }
        .bm-campo b { font-size: 13px; white-space: nowrap; }
        .bm-campo .pt-ic { margin-left: 3px; }
        .bm-premios-tit { font-weight: bold; margin-bottom: 3px; }
        .bm-premios-fila { display: flex; gap: 14px; font-weight: bold; }
        .bm-premios-fila span { display: inline-flex; align-items: center; gap: 4px; }
        /* Medallas (oro, plata, bronce) de los 3 primeros: premios y rankings. */
        .bm-med {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px; border-radius: 50%; font-size: 10px; font-weight: bold; font-style: normal; color: #2a2a2a;
            box-shadow: inset -1px -1px rgba(0,0,0,.35), inset 1px 1px rgba(255,255,255,.7);
        }
        .bm-med.m1 { background: #e8c547; } .bm-med.m2 { background: #c9ccd6; } .bm-med.m3 { background: #d08a4e; }
        /* Infinito y Libre, lado a lado. */
        .bm-fila2 { display: grid; grid-template-columns: 1fr 220px; gap: 8px; }
        .bm-libre-col { display: flex; flex-direction: column; gap: 8px; }
        .bm-libre-col select { height: auto; min-height: 21px; width: 100%; }
        .bm-contador {
            display: inline-flex; align-items: baseline; gap: 5px; padding: 2px 10px; margin-top: 2px;
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        body.jg-kawaii .bm-contador { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        .bm-contador b { font-size: 20px; }
        /* Tablero del día: vista previa (tapada, o como lo dejó). */
        .bm-dia-info { font-weight: bold; }
        .bm-prev-wrap { display: flex; justify-content: center; }
        .bm-prev { pointer-events: none; }
        /* Infinito en el menú: tú a la izquierda, los 5 mejores a la derecha. */
        .bm-inf-cols { display: grid; grid-template-columns: 1fr 250px; gap: 12px; align-items: start; }
        .bm-yo { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
        .bm-yo .bm-av { --av: 48px; }
        /* Rankings: puesto, foto con marco, nombre y valor. */
        .bm-side-tit { font-weight: bold; margin-bottom: 4px; }
        .bm-rank { display: flex; flex-direction: column; gap: 2px; }
        .bm-rk { display: grid; grid-template-columns: 22px auto 1fr auto; align-items: center; gap: 6px; padding: 5px 6px; }
        .bm-rk .bm-rk-n { text-align: right; color: var(--text-muted, #444); }
        .bm-rk .bm-rk-n.bm-med { justify-self: end; color: #2a2a2a; }
        .bm-rk .bm-rk-nom { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bm-rk .bm-rk-v { font-weight: bold; white-space: nowrap; }
        .bm-rk.yo { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .bm-rk.yo .bm-rk-n { color: inherit; }
        .bm-rk-sep { text-align: center; color: var(--text-muted, #444); line-height: 1; }
        .bm-rank-vacio { color: var(--text-muted, #444); padding: 4px 6px; }
        .bm-av {
            --av: 22px;
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: var(--av); height: var(--av); flex-shrink: 0;
            background: var(--accent, navy); color: var(--accent-text, #fff); font-weight: bold;
        }
        /* Sitio para el marco: lo que sobresale por cada lado (--mk-* de
           marcos.css, en fracción del lado de la foto), como la cabecera
           del perfil. Así no se sale de la fila ni del recuadro. */
        .bm-av[data-marco] {
            margin: calc(var(--mk-t) * var(--av)) calc(var(--mk-r) * var(--av))
                    calc(var(--mk-b) * var(--av)) calc(var(--mk-l) * var(--av));
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

        /* ── Ajedrez (assets/juegos/ajedrez.js) ─────────────────────────
           Todo con las variables del tema (también con tema oscuro y en
           Kawaii, que cambia relieves por bordes). Piezas blancas y negras
           fijas: son "contenido", como los números del Buscaminas. */
        #jg-ajz, #jg-ajz-crear, #jg-ajz-torneo, #jg-ajz-partida { padding: 8px 12px 12px; align-items: center; }
        .az-menu { width: 100%; margin-top: 8px; display: flex; flex-direction: column; gap: 8px; flex: 1; min-height: 0; }
        .az-fila2 { display: grid; grid-template-columns: 290px 1fr; gap: 8px; }
        .az-fila2 > .bm-win { min-height: 0; }
        /* Torneos: ocupa lo que queda y hace scroll por dentro. */
        .az-crece { flex: 1; min-height: 120px; }
        .az-crece .bm-win-body, .az-fila2 .az-scroll { flex: 1; min-height: 0; overflow: auto; }
        .az-fila2 .az-scroll { max-height: 180px; }
        .az-hundido {
            background: var(--input-bg, #fff); color: var(--input-text, #000);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -2px -2px var(--bezel-light-2, #dfdfdf), inset 2px 2px var(--bezel-dark-1, #0a0a0a);
        }
        body.jg-kawaii .az-hundido { box-shadow: inset 0 0 0 2px var(--border, #49388B); }
        .az-relieve {
            background: var(--win-bg, silver);
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-1, #fff),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-2, #dfdfdf);
        }
        body.jg-kawaii .az-relieve { box-shadow: none; border: 2px solid var(--border, #49388B); }

        /* Formularios */
        .az-form { display: grid; grid-template-columns: auto 1fr; gap: 6px 8px; align-items: center; }
        .az-form label { white-space: nowrap; }
        .az-form select, .az-form input[type=text], .az-form input[type=datetime-local] { width: 100%; box-sizing: border-box; height: auto; min-height: 21px; }
        .az-form input[type=number] { width: 60px; }
        .az-ritmo { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .az-form .az-span { grid-column: 1 / -1; }
        .az-acc { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
        .az-err { color: #c00000; font-weight: bold; }
        body.jg-oscuro .az-err { color: #ff6b6b; }
        #jg-ajz input, #jg-ajz-crear input, .az-modal input { background-color: var(--input-bg, #fff); color: var(--input-text, #000); }
        .az-retar-btn { min-width: 96px; font-weight: bold; }

        /* Listas: partidas, retos y torneos */
        .az-list { display: flex; flex-direction: column; }
        .az-item { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; padding: 6px; }
        .az-item + .az-item { border-top: 1px solid color-mix(in srgb, var(--text, #000) 18%, transparent); }
        .az-item .bm-av { --av: 28px; }
        .az-item-t { min-width: 0; line-height: 1.45; }
        .az-item-t b { font-size: 12px; }
        .az-item-sub { color: var(--text-muted, #444); display: flex; flex-wrap: wrap; gap: 3px 6px; align-items: center; }
        .az-item-btns { display: flex; gap: 4px; }
        .az-item-btns button { min-width: 0; padding: 0 10px; }
        .az-vacio { color: var(--text-muted, #444); padding: 4px 6px; }
        .az-vacio-g { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 14px 6px; color: var(--text-muted, #444); text-align: center; }
        .az-vacio-g .az-pz { width: 34px; height: 34px; opacity: .55; }
        /* Etiquetas pequeñas: ritmo, apuesta, turno… */
        .az-chip {
            display: inline-flex; align-items: center; gap: 3px; padding: 0 5px; line-height: 15px; white-space: nowrap;
            border: 1px solid color-mix(in srgb, var(--text, #000) 30%, transparent); color: var(--text, #000);
        }
        .az-chip .pt-ic { width: 11px; height: 11px; }
        .az-chip.acento, .az-badge { background: var(--accent, navy); color: var(--accent-text, #fff); border-color: var(--accent, navy); }
        .az-chip.suave { background: color-mix(in srgb, var(--accent, navy) 15%, transparent); }
        .az-badge { padding: 1px 6px; white-space: nowrap; font-weight: bold; border: 1px solid transparent; }
        .az-badge.gris { background: color-mix(in srgb, var(--text, #000) 22%, var(--win-bg, silver)); color: var(--text, #000); }
        .az-pulso { animation: az-pulso 1.2s ease-in-out infinite; }
        @keyframes az-pulso { 50% { opacity: .55; } }
        .az-saldo { display: inline-flex; align-items: center; gap: 3px; }

        /* Fila de torneo: hoja de calendario, datos, plazas y premios. */
        .az-tor { cursor: pointer; grid-template-columns: auto 1fr auto; gap: 10px; }
        .az-tor:hover { background: color-mix(in srgb, var(--accent, navy) 12%, transparent); }
        .az-dia { width: 44px; text-align: center; flex-shrink: 0; padding: 0 0 2px; }
        .az-dia i { display: block; font-style: normal; font-weight: bold; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; padding: 1px 0;
                    background: linear-gradient(90deg, var(--titlebar-start, navy), var(--titlebar-end, #1084d0)); color: var(--titlebar-text, #fff); }
        .az-dia b { display: block; font-size: 18px; line-height: 20px; }
        .az-dia small { display: block; color: var(--text-muted, #444); font-size: 9px; }
        .az-plazas { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
        .az-barra { width: 110px; height: 10px; padding: 2px; box-sizing: border-box; }
        .az-barra span { display: block; height: 100%;
                         background: repeating-linear-gradient(90deg, var(--accent, navy) 0 6px, transparent 6px 8px); }
        body.jg-kawaii .az-barra span { background: var(--accent, #FF66B2); }
        .az-tor-der { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
        .az-meds { display: flex; gap: 6px; font-weight: bold; }
        .az-meds span { display: inline-flex; align-items: center; gap: 3px; }
        .az-meds .bm-med { width: 14px; height: 14px; font-size: 9px; }

        /* Crear torneo: formulario y resumen al lado. */
        .az-crear-cols { display: grid; grid-template-columns: 1fr 250px; gap: 8px; align-items: stretch; }
        .az-resumen .bm-win-body { display: flex; flex-direction: column; gap: 8px; }
        .az-res-fila { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
        .az-res-fila + .az-res-fila { border-top: 1px dotted color-mix(in srgb, var(--text, #000) 25%, transparent); }
        .az-res-fila span { color: var(--text-muted, #444); }
        .az-res-fila b { display: inline-flex; align-items: center; gap: 3px; text-align: right; }
        .az-res-total { padding: 6px; text-align: center; line-height: 1.5; }
        .az-res-total b { font-size: 16px; }
        .az-reglas { margin: 0; padding: 0; list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; line-height: 1.4; }
        .az-reglas li { position: relative; padding-left: 14px; }
        .az-reglas li::before { content: ''; position: absolute; left: 2px; top: 4px; width: 6px; height: 6px; background: var(--accent, navy); }

        /* Podio de premios (crear torneo y torneo). */
        .az-podio { display: flex; align-items: flex-end; justify-content: center; gap: 4px; padding-top: 4px; }
        .az-podio-col { display: flex; flex-direction: column; align-items: center; gap: 3px; width: 66px; }
        .az-podio-col .bm-av { --av: 26px; }
        .az-podio-nom { max-width: 66px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: bold; }
        .az-podio-pt { display: inline-flex; align-items: center; gap: 2px; font-weight: bold; }
        .az-podio-caja { width: 100%; display: flex; align-items: flex-start; justify-content: center; padding-top: 4px; box-sizing: border-box; }
        .az-podio-col.p1 .az-podio-caja { height: 44px; }
        .az-podio-col.p2 .az-podio-caja { height: 32px; }
        .az-podio-col.p3 .az-podio-caja { height: 22px; }

        /* Tablero: casillas con los colores del tema. El tamaño de casilla
           (--az-sq) lo pone ajedrez.js según el sitio que haya. */
        .az-cols { display: flex; gap: 10px; width: 100%; flex: 1; min-height: 0; margin-top: 6px; }
        .az-main { flex: 1; min-width: 0; min-height: 0; display: flex; align-items: flex-start; justify-content: center; }
        .az-board-wrap { position: relative; padding: 4px;
            background: var(--bezel-dark-2, grey);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-2, grey),
                        inset -3px -3px var(--bezel-light-2, #dfdfdf), inset 3px 3px var(--bezel-dark-1, #0a0a0a); }
        body.jg-kawaii .az-board-wrap { background: var(--border, #49388B); box-shadow: none; padding: 3px; }
        .az-board { display: grid; grid-template-columns: repeat(8, var(--az-sq, 54px)); grid-template-rows: repeat(8, var(--az-sq, 54px));
                    user-select: none; -webkit-user-select: none; touch-action: none; }
        .az-sq { position: relative; display: flex; align-items: center; justify-content: center; }
        .az-sq.cl { background: color-mix(in srgb, var(--input-bg, #fff) 70%, var(--win-bg, silver)); }
        .az-sq.os { background: color-mix(in srgb, var(--accent, navy) 55%, var(--win-bg, silver)); }
        .az-sq.ult::before { content: ''; position: absolute; inset: 0; background: color-mix(in srgb, var(--titlebar-end, #1084d0) 42%, transparent); }
        .az-sq.sel::before { content: ''; position: absolute; inset: 0; background: color-mix(in srgb, var(--titlebar-start, navy) 55%, transparent); }
        .az-sq.jaque::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle, rgba(224,48,48,.95) 0, rgba(224,48,48,.55) 45%, transparent 72%); }
        .az-sq.dest::after { content: ''; position: absolute; width: 30%; height: 30%; border-radius: 50%;
                             background: color-mix(in srgb, var(--text, #000) 38%, transparent); pointer-events: none; z-index: 2; }
        .az-sq.dest.come::after { width: 86%; height: 86%; background: none; box-sizing: border-box;
                                  border: 5px solid color-mix(in srgb, var(--text, #000) 38%, transparent); }
        .az-sq.dest:hover::before { content: ''; position: absolute; inset: 0; background: color-mix(in srgb, var(--titlebar-end, #1084d0) 30%, transparent); }
        .az-sq.dest, .az-sq.mia { cursor: pointer; }
        .az-coord { position: absolute; font-size: 10px; font-weight: bold; line-height: 1; pointer-events: none; z-index: 1; }
        .az-coord.f { right: 2px; bottom: 2px; }
        .az-coord.r { left: 2px; top: 2px; }
        .az-sq.cl .az-coord { color: color-mix(in srgb, var(--accent, navy) 70%, var(--win-bg, silver)); }
        .az-sq.os .az-coord { color: color-mix(in srgb, var(--input-bg, #fff) 85%, var(--win-bg, silver)); }
        .az-pz { position: relative; z-index: 1; width: 88%; height: 88%; display: block; pointer-events: none;
                 filter: drop-shadow(0 1px 1px rgba(0,0,0,.28)); }
        .az-pz.mueve { transition: transform .18s ease-out; z-index: 3; }
        .az-sq.arrastre .az-pz { opacity: .35; }
        .az-fantasma { position: fixed; z-index: 60; pointer-events: none; width: var(--az-sq, 54px); height: var(--az-sq, 54px); transform: translate(-50%, -50%); }
        .az-fantasma .az-pz { width: 100%; height: 100%; filter: drop-shadow(0 4px 4px rgba(0,0,0,.35)); }
        /* Piezas negras sobre casillas oscuras con un tema oscuro: un halo claro. */
        body.jg-oscuro .az-pz.n { filter: drop-shadow(0 0 1px #fff) drop-shadow(0 0 1px rgba(255,255,255,.7)); }
        /* Encima del tablero: esperando al rival, coronación y final. */
        .az-capa { position: absolute; inset: 4px; z-index: 5; display: flex; align-items: center; justify-content: center;
                   background: color-mix(in srgb, var(--win-bg, silver) 55%, transparent); }
        .az-capa .window { min-width: 230px; max-width: 86%; }
        .az-capa .window-body { text-align: center; line-height: 1.6; }
        .az-coronar { display: flex; gap: 4px; justify-content: center; }
        .az-coronar button { width: 54px; height: 54px; padding: 2px; min-width: 0; }
        .az-coronar button .az-pz { width: 100%; height: 100%; }
        .az-fin-ic { display: flex; justify-content: center; gap: 2px; margin: 2px 0 4px; }
        .az-fin-ic .az-pz { width: 52px; height: 52px; }
        .az-fin-ic .az-pz.caido { transform: rotate(90deg) translate(12px, 0); opacity: .85; }
        .az-fin-tit { font-size: 18px; font-weight: bold; }
        .az-fin-pts { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; padding: 2px 8px; font-weight: bold; font-size: 13px; }
        .az-fin-btns { display: flex; gap: 6px; justify-content: center; margin-top: 10px; }

        /* Columna de la derecha: jugadores, relojes y jugadas. */
        .az-side { width: 232px; flex-shrink: 0; display: flex; flex-direction: column; gap: 6px; min-height: 0; }
        .az-jug { padding: 6px; display: flex; flex-direction: column; gap: 4px; position: relative; }
        .az-jug.turno::before { content: ''; position: absolute; left: 3px; top: 3px; bottom: 3px; width: 4px;
                                background: linear-gradient(180deg, var(--titlebar-start, navy), var(--titlebar-end, #1084d0)); }
        body.jg-kawaii .az-jug.turno::before { left: 0; top: 0; bottom: 0; background: var(--accent, #FF66B2); }
        .az-jug.turno { padding-left: 12px; }
        .az-jug-top { display: flex; align-items: center; gap: 8px; }
        .az-jug .bm-av { --av: 32px; }
        .az-jug-nom { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: bold; font-size: 12px; }
        .az-jug-nom small { display: flex; align-items: center; gap: 4px; font-weight: normal; font-size: 11px; color: var(--text-muted, #444); }
        .az-color { width: 9px; height: 9px; border: 1px solid #161616; flex-shrink: 0; }
        .az-color.w { background: #f6f6f6; } .az-color.b { background: #2a2a2a; }
        .az-comidas { display: flex; align-items: center; min-height: 16px; padding-left: 2px; }
        .az-comidas .az-pz { width: 16px; height: 16px; margin-right: -5px; filter: none; }
        .az-comidas .az-pz.sep { margin-left: 6px; }
        .az-comidas b { margin-left: 9px; color: var(--text-muted, #444); }
        .az-reloj { min-width: 70px; padding: 3px 6px; text-align: center; font-weight: bold; font-size: 17px; font-variant-numeric: tabular-nums; box-sizing: border-box; }
        .az-reloj.corre { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .az-reloj.urge { color: #c00000; }
        .az-reloj.corre.urge { background: #c00000; color: #fff; }
        .az-jugadas-win { flex: 1; min-height: 70px; display: flex; flex-direction: column; padding: 3px; }
        .az-jugadas-tit { padding: 2px 6px; font-weight: bold;
                          background: linear-gradient(90deg, var(--titlebar-start, navy), var(--titlebar-end, #1084d0)); color: var(--titlebar-text, #fff); }
        body.jg-kawaii .az-jugadas-tit { background: var(--win-body-bg, #F0E8F3); color: var(--text, #000); border-bottom: 2px solid var(--border, #49388B); }
        .az-jugadas { flex: 1; min-height: 0; overflow: auto; margin-top: 3px; font-variant-numeric: tabular-nums; }
        .az-jg { display: grid; grid-template-columns: 30px 1fr 1fr; gap: 4px; padding: 2px 6px 2px 0; align-items: center; }
        .az-jg:nth-child(even) { background: color-mix(in srgb, var(--text, #000) 6%, transparent); }
        .az-jg i { font-style: normal; color: var(--text-muted, #444); text-align: right; }
        .az-jg span { padding: 0 3px; justify-self: start; }
        .az-jg .ult { font-weight: bold; background: var(--accent, navy); color: var(--accent-text, #fff); }
        .az-estado { text-align: center; font-weight: bold; min-height: 15px; padding: 3px; }
        .az-estado.yo { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .az-estado.jaque { background: #c00000; color: #fff; }
        .az-aviso { padding: 6px; text-align: center; line-height: 1.5;
                    background: color-mix(in srgb, var(--accent, navy) 16%, var(--win-bg, silver)); }
        .az-btns { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
        .az-btns button { flex: 1; min-width: 0; }
        .az-fin { display: none; padding: 6px; text-align: center; line-height: 1.5; }
        .az-fin.on { display: block; }

        /* Torneo */
        .az-tor-body { width: 100%; overflow: auto; flex: 1; min-height: 0; margin-top: 8px; display: flex; flex-direction: column; gap: 8px; }
        .az-tor-cab { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; }
        .az-tor-datos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; }
        .az-tor-datos .bm-campo b { font-size: 12px; }
        .az-tor-estado { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .az-tor-estado .az-cuenta { font-weight: bold; }
        .az-apuntados { display: flex; flex-wrap: wrap; gap: 5px; }
        .az-pj { display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px 2px 2px; }
        .az-pj .bm-av { --av: 20px; }
        .az-pj.libre { padding: 2px 10px; color: var(--text-muted, #444); border: 1px dashed color-mix(in srgb, var(--text, #000) 35%, transparent); box-shadow: none; background: none; }
        .az-pj.yo { background: var(--accent, navy); color: var(--accent-text, #fff); }
        /* Cuadro: cada ronda es una columna con sus cruces repartidos a lo
           alto (mismo alto para todas) y líneas que los unen. */
        .az-cuadro-scroll { overflow-x: auto; padding-bottom: 4px; }
        .az-cuadro { display: flex; gap: 24px; align-items: stretch; min-width: min-content; }
        .az-ronda { display: flex; flex-direction: column; width: 150px; flex-shrink: 0; }
        .az-ronda-tit { font-weight: bold; text-align: center; margin-bottom: 6px; padding: 1px 0;
                        border-bottom: 1px solid color-mix(in srgb, var(--text, #000) 25%, transparent); }
        .az-ronda-cr { flex: 1; display: flex; flex-direction: column; }
        .az-celda { flex: 1; display: flex; align-items: center; position: relative; min-height: 58px; padding: 3px 0; }
        .az-celda > .az-cruce { width: 100%; }
        .az-linea .az-celda::after { content: ''; position: absolute; right: -12px; width: 12px; height: 50%;
                                     border-right: 2px solid var(--bezel-dark-2, grey); }
        .az-linea .az-celda:nth-child(odd)::after { top: 50%; border-top: 2px solid var(--bezel-dark-2, grey); }
        .az-linea .az-celda:nth-child(even)::after { bottom: 50%; border-bottom: 2px solid var(--bezel-dark-2, grey); }
        .az-entra .az-celda::before { content: ''; position: absolute; left: -12px; top: 50%; width: 12px; border-top: 2px solid var(--bezel-dark-2, grey); }
        body.jg-kawaii .az-celda::after, body.jg-kawaii .az-celda::before { border-color: var(--border, #49388B) !important; }
        .az-cruce { padding: 3px; }
        .az-cruce.mio { outline: 2px solid var(--accent, navy); outline-offset: 1px; }
        .az-cr-j { display: flex; align-items: center; gap: 5px; padding: 2px 3px; }
        .az-cr-j + .az-cr-j { border-top: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .az-cr-j .az-cr-nom { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .az-cr-j .az-cr-v { font-weight: bold; }
        .az-cr-j.gana { font-weight: bold; }
        .az-cr-j.pierde { color: var(--text-muted, #444); }
        .az-cr-j.pierde .bm-av { opacity: .5; }
        .az-cr-j.yo .az-cr-nom { text-decoration: underline; text-underline-offset: 2px; }
        .az-cr-est { color: var(--text-muted, #444); padding: 1px 3px 0; display: flex; align-items: center; gap: 4px; }
        .az-cr-est .az-punto { width: 6px; height: 6px; border-radius: 50%; background: #2ea043; }
        .az-campeon { width: 120px; flex-shrink: 0; display: flex; flex-direction: column; }
        .az-campeon-caja { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; text-align: center; position: relative; }
        .az-campeon-caja::before { content: ''; position: absolute; left: -24px; top: 50%; width: 24px; border-top: 2px solid var(--bezel-dark-2, grey); }
        body.jg-kawaii .az-campeon-caja::before { border-color: var(--border, #49388B); }
        .az-copa { width: 34px; height: 34px; image-rendering: pixelated; }
        .bm-win-bar .az-copa { width: 13px; height: 13px; }
        .az-campeon .bm-av { --av: 34px; }
        .az-tercero { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .az-tercero .az-cruce { width: 150px; }
        .az-card-ic { display: flex; align-items: center; justify-content: center; }
        .az-card-ic .az-pz { width: 38px; height: 38px; }
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
        <div class="jg-card" id="jg-open-ajz" role="button" tabindex="0" style="margin-top:6px">
            <div class="jg-card-ic az-card-ic" id="az-card-ic" aria-hidden="true"></div>
            <div>
                <div class="jg-card-name">Ajedrez</div>
                <div class="jg-card-sub">Reta a tus amigos, apuesta puntos y organiza torneos</div>
            </div>
            <div class="jg-card-state" id="jg-ajz-state"></div>
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
                        <div class="az-span az-acc"><span class="az-err" id="az-retar-msg"></span><button type="button" class="az-retar-btn" id="az-retar">Retar</button></div>
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
        <div class="wd-head"><div class="wd-title">Crear torneo</div><div class="wd-date az-saldo" id="azc-saldo"></div></div>
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
    <div class="az-tor-body" id="azt-body"></div>
</div>

<!-- AJEDREZ: partida -->
<div class="jg-view" id="jg-ajz-partida">
    <div class="wd-top">
        <button type="button" id="azp-back">&lt; Ajedrez</button>
        <div class="wd-head"><div class="wd-title" id="azp-title">Ajedrez</div><div class="wd-date" id="azp-sub"></div></div>
        <div class="wd-top-right"></div>
    </div>
    <div class="az-cols">
        <div class="az-main" id="azp-main">
            <div class="az-board-wrap" id="azp-wrap"><div class="az-board" id="azp-board"></div></div>
        </div>
        <div class="az-side">
            <div class="az-jug az-relieve" id="azp-rival"></div>
            <section class="az-jugadas-win az-hundido"><div class="az-jugadas-tit">Jugadas</div><div class="az-jugadas" id="azp-jugadas"></div></section>
            <div class="az-jug az-relieve" id="azp-yo"></div>
            <div class="az-estado" id="azp-estado"></div>
            <div id="azp-aviso"></div>
            <div class="az-btns" id="azp-btns">
                <button type="button" id="azp-tablas">Ofrecer tablas</button>
                <button type="button" id="azp-rendirse">Rendirse</button>
            </div>
            <div class="az-fin az-hundido" id="azp-fin"></div>
        </div>
    </div>
</div>
<div class="wd-modal-bg" id="az-modal">
    <div class="window wd-modal">
        <div class="title-bar">
            <div class="title-bar-text">Cómo se juega</div>
            <div class="title-bar-controls"><button aria-label="Close" id="az-modal-x"></button></div>
        </div>
        <div class="window-body">
            Ajedrez de siempre, en tiempo real y con reloj. Mueve con un clic en la pieza y otro en la casilla, o arrastrándola.
            <div style="margin-top:6px"><b>Retos</b>: solo a tus amigos. Eliges el ritmo (minutos y segundos que se suman en cada jugada) y, si quieres, una apuesta de puntos de Autismo: los dos ponéis lo mismo y quien gana se lo lleva todo. En tablas, cada uno recupera lo suyo. Si alguno no entra en 2 minutos, se anula y se devuelve la apuesta.</div>
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
<?php
/* ?abrir=partida:N | torneo:N (aviso del haro o enlace del anuncio de
   Discord, ver openJuegos en desktop-base.php). */
$_jgAbrir = (string)($_GET['abrir'] ?? '');
if (!preg_match('/^(partida|torneo):[0-9]{1,10}$/', $_jgAbrir)) $_jgAbrir = '';
/* Archivo del timbre del usuario (assets/php/timbre.php): el menú del
   ajedrez lo mira para saber cuándo volver a pedir sus retos y partidas. */
require_once dirname(__DIR__) . '/assets/php/timbre.php';
$_jgSt = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
$_jgSt->execute([$userKey]);
$_jgUid = (int)$_jgSt->fetchColumn();
rt_asegurarUsuario($_jgUid);
?>
<script>window.JG_ABRIR = <?= json_encode($_jgAbrir) ?>; window.JG_TIMBRE = <?= json_encode($_jgUid > 0 ? rt_urlUsuario($_jgUid) : '') ?>;</script>
<script src="../assets/juegos/ajedrez.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/juegos/ajedrez.js') ?>"></script>
</body>
</html>
