<?php
/* ──────────────────────────────────────────────────────────────────────
   enlace.php — puerta de los enlaces compartidos (ver assets/links.php)
   ──────────────────────────────────────────────────────────────────────
   Intenta abrir la app y, si no está instalada, sigue en la web sin
   preguntar:
     - PC: melonhub://<tipo>/<valor>?n=<código>&s=<web>. La app avisa a
       assets/link-ack.php con el código nada más recibirlo; con ese aviso
       la página se queda en "Se ha abierto en la app". Sin aviso: si la
       ventana ni se ha movido en 6 s no hay app; si salió el "¿Abrir
       Melon Hub?" del navegador y se ha vuelto aquí, 3 s más; 15 s como
       mucho. Luego, a la web. (Antes se miraba solo el foco, pero con KDE
       el navegador no lo pierde al abrirse la app y seguía también a la
       web.)
     - Android: intent:// con la web de respaldo (Chrome sigue solo a la
       web si la app no está).
     - iPhone (sin app), la propia app de Android o la de escritorio:
       directo a la web.
   La app de escritorio no llega a cargar esta página: main.js corta la
   navegación y abre el enlace dentro (por si se pulsa en el chat).
   Vista previa: etiquetas Open Graph (assets/link-preview.php) para la
   tarjeta de Discord, WhatsApp, Telegram… (leen la página sin JS).
   ────────────────────────────────────────────────────────────────────── */
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/assets/links.php';

/* Invitación (enlace.php?invitar=<user_key>, botón "Invitar" del perfil):
   no abre la app. Se guarda quién invita en una cookie (30 días) y se va
   a la web; register-user.php la lee al crear la cuenta (racha.php). */
if (isset($_GET['invitar'])) {
    $quien = (string)$_GET['invitar'];
    if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $quien)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('melon_invita', $quien, ['expires' => time() + 30 * 86400, 'path' => '/',
                                           'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    }
    header('Location: index.php');
    exit;
}

$link = null;
foreach (['perfil', 'cancion', 'album', 'playlist', 'escuchar', 'torneo', 'reto'] as $tipo) {
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
$nonce  = bin2hex(random_bytes(12));
$appUrl = 'melonhub://' . $tipo . '/' . rawurlencode($valor)
        . '?n=' . $nonce . '&s=' . rawurlencode($_SERVER['HTTP_HOST'] ?? '');
require_once __DIR__ . '/assets/php/active-interface.php';

/* Vista previa (Open Graph). */
$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base   = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'melonhub.es')
        . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$selfUrl = $base . 'enlace.php?' . rawurlencode($tipo) . '=' . rawurlencode($valor);
$preview = ['title' => 'Melon Hub', 'description' => 'Ábrelo en Melon Hub.', 'image' => $base . 'icon.png', 'type' => 'website'];
try {
    require_once __DIR__ . '/assets/config.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/assets/link-preview.php';
    $preview = melonLinkPreview($pdo, $tipo, $valor, $base);
} catch (Throwable $e) { /* tarjeta genérica */ }
$og = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $og($preview['title']) ?></title>
    <meta name="description" content="<?= $og($preview['description']) ?>">
    <meta property="og:site_name" content="Melon Hub">
    <meta property="og:type" content="<?= $og($preview['type']) ?>">
    <meta property="og:url" content="<?= $og($selfUrl) ?>">
    <meta property="og:title" content="<?= $og($preview['title']) ?>">
    <meta property="og:description" content="<?= $og($preview['description']) ?>">
    <meta property="og:image" content="<?= $og($preview['image']) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="theme-color" content="#008080">
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
        /* torneo y reto: las apps de Android de antes no los conocen (se
           abrirían sin hacer nada). Mientras, a la web del móvil. */
        if (<?= json_encode(in_array($tipo, ['torneo', 'reto'], true)) ?>) return web();
        var p = appUrl.slice('melonhub://'.length);
        location.href = 'intent://' + p + '#Intent;scheme=melonhub;package=es.melonhub.MelonHub;' +
            'S.browser_fallback_url=' + encodeURIComponent(webAbs) + ';end';
        /* Si Chrome no deja abrir la app sin tocar nada, a la web igual. */
        setTimeout(function () { if (!document.hidden) web(); }, 2500);
        return;
    }

    var nonce = <?= json_encode($nonce) ?>;
    var left = false, back = 0, done = false, start = Date.now();
    window.addEventListener('blur', function () { left = true; back = 0; });
    window.addEventListener('focus', function () { if (left) back = Date.now(); });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { left = true; back = 0; } else if (left) back = Date.now();
    });
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
    document.getElementById('en-web').onclick = web;
    /* Mientras espera, quien no tenga la app puede seguir ya. */
    setTimeout(function () { if (!done) document.getElementById('en-actions').style.display = 'block'; }, 2000);
    function abierta() {
        done = true;
        document.getElementById('en-msg').textContent = 'Se ha abierto en la app de Melon Hub.';
        document.getElementById('en-actions').style.display = 'block';
    }
    function sigue() {
        if (done) return;
        var t = Date.now() - start;
        if ((!left && t > 6000) || (back && Date.now() - back > 3000) || t > 15000) { done = true; return web(); }
        setTimeout(mira, 500);
    }
    function mira() {
        if (done) return;
        fetch('assets/link-ack.php?n=' + nonce, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.opened) abierta(); else sigue(); })
            .catch(sigue);
    }
    setTimeout(mira, 500);
})();
</script>
</body>
</html>
