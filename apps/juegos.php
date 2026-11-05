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
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
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
        .wd-top { display: flex; align-items: center; gap: 8px; width: 100%; }
        .wd-top button { min-width: 0; }
        .wd-head { flex: 1; text-align: center; }
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
<body class="<?= htmlspecialchars($themeClass) ?>">

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
        <button type="button" class="wd-help" id="wd-help" title="Cómo se juega" aria-label="Cómo se juega">?</button>
    </div>
    <div class="wd-msg" id="wd-msg"></div>
    <div class="wd-board" id="wd-board"></div>
    <div class="wd-kb" id="wd-kb"></div>
    <div class="wd-end" id="wd-end"></div>
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
        ['jg-home', 'jg-wordelon'].forEach(function (id) { $(id).classList.toggle('on', id === view); });
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

    $('jg-open-wordelon').addEventListener('click', abrirWordelon);
    $('jg-open-wordelon').addEventListener('keydown', function (e) { if (e.key === 'Enter') abrirWordelon(); });
    $('wd-back').addEventListener('click', function () { show('jg-home'); paintHomeState(); });
    $('wd-help').addEventListener('click', function () { $('wd-modal').classList.add('on'); });
    $('wd-modal-x').addEventListener('click', function () { $('wd-modal').classList.remove('on'); });
    $('wd-modal-ok').addEventListener('click', function () { $('wd-modal').classList.remove('on'); });

    buildKeyboard();
    cargar().then(paintHomeState);
})();
</script>
</body>
</html>
