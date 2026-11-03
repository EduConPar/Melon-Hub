<?php
/* ──────────────────────────────────────────────────────────────────────
   enlace.php — puerta de los enlaces compartidos (ver assets/links.php)
   ──────────────────────────────────────────────────────────────────────
   Intenta abrir la app y, si no está instalada, sigue en la web sin
   preguntar:
     - PC: melonhub://<tipo>/<valor>. Si la ventana pierde el foco (sale
       el "¿Abrir Melon Hub?" del navegador o se abre la app) se queda
       aquí con "Seguir en el navegador"; si no pasa nada en 2 s, no hay
       app y seguimos en la web.
     - Android: intent:// con la web de respaldo (Chrome sigue solo a la
       web si la app no está).
     - iPhone (sin app), la propia app de Android o la de escritorio:
       directo a la web.
   La app de escritorio no llega a cargar esta página: main.js corta la
   navegación y abre el enlace dentro (por si se pulsa en el chat).
   ────────────────────────────────────────────────────────────────────── */
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/assets/links.php';

$link = null;
foreach (['perfil', 'cancion', 'album', 'playlist', 'escuchar'] as $tipo) {
    if (isset($_GET[$tipo])) { $link = melonParseLink($tipo, (string)$_GET[$tipo]); break; }
}
if ($link === null) {
    header('Location: index.php');
    exit;
}
$webUrl = 'index.php?abrir=' . rawurlencode($link);
if (isset($_GET['web'])) {
    header('Location: ' . $webUrl);
    exit;
}
[$tipo, $valor] = explode(':', $link, 2);
$appUrl = 'melonhub://' . $tipo . '/' . rawurlencode($valor);
require_once __DIR__ . '/assets/php/active-interface.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Melon Hub</title>
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <?php emitInterfaceCss(''); ?>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: var(--desktop-bg, #008080); }
        .window { width: 300px; max-width: calc(100vw - 32px); }
        .window-body { text-align: center; padding: 12px; }
        #en-actions { display: none; margin-top: 12px; }
    </style>
</head>
<body>
<div class="window">
    <div class="title-bar"><div class="title-bar-text">Melon Hub</div></div>
    <div class="window-body">
        <p id="en-msg">Abriendo Melon Hub…</p>
        <div id="en-actions"><button class="button" id="en-web" type="button">Seguir en el navegador</button></div>
    </div>
</div>
<script>
(function () {
    var appUrl = <?= json_encode($appUrl) ?>;
    var webUrl = <?= json_encode($webUrl) ?>;
    var webAbs = new URL(webUrl, location.href).href;
    var ua = navigator.userAgent;
    function web() { location.replace(webUrl); }

    /* Dentro de la app de Android (pulsado en su propio WebView): directo
       a su pantalla, sin pasar por la portada de los móviles. */
    if (/MelonHubApp/.test(ua)) return location.replace('mobile.php?abrir=' + encodeURIComponent(<?= json_encode($link) ?>));
    /* Ya dentro de la app de escritorio, o en un sistema sin app: a la web. */
    if (window.melonDesktop || /Electron\//.test(ua) || /iPhone|iPad|iPod/.test(ua)) return web();

    if (/Android/i.test(ua)) {
        var p = appUrl.slice('melonhub://'.length);
        location.href = 'intent://' + p + '#Intent;scheme=melonhub;package=es.melonhub.MelonHub;' +
            'S.browser_fallback_url=' + encodeURIComponent(webAbs) + ';end';
        /* Si Chrome no deja abrir la app sin tocar nada, a la web igual. */
        setTimeout(function () { if (!document.hidden) web(); }, 2500);
        return;
    }

    var left = false;
    window.addEventListener('blur', function () { left = true; });
    document.addEventListener('visibilitychange', function () { if (document.hidden) left = true; });
    /* Firefox enseña una página de error con un protocolo que no conoce
       si se navega a él; en un iframe escondido no. */
    if (/Firefox\//.test(ua)) {
        var f = document.createElement('iframe');
        f.style.display = 'none';
        f.src = appUrl;
        document.body.appendChild(f);
    } else {
        location.href = appUrl;
    }
    setTimeout(function () {
        if (!left) return web();
        document.getElementById('en-msg').textContent = 'Se ha abierto en la app de Melon Hub.';
        document.getElementById('en-actions').style.display = 'block';
        document.getElementById('en-web').onclick = web;
    }, 2000);
})();
</script>
</body>
</html>
