<?php
/* ──────────────────────────────────────────────────────────────────────
   MOBILE-LANDING.PHP — Pantalla de bienvenida + instalación PWA
   ──────────────────────────────────────────────────────────────────────
   Step 1: pitch + login. Step 2: guía paso-a-paso para añadir a
   pantalla de inicio (con detección de navegador + fallback al prompt
   nativo `beforeinstallprompt` cuando el navegador lo dispara).

   Estrategia: las instrucciones manuales son la VÍA PRIMARIA (siempre
   funcionan, incluso en HTTP sobre LAN). El botón "instalar
   automáticamente" es secundario — solo aparece si el navegador
   permite (HTTPS / Android Chrome con engagement).
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/assets/mobile-detect.php';
setLongSessionCookie();
/* Cookie de bypass del interstitial de ngrok-free. Cuando servimos a
   través de un túnel ngrok, su proxy intercepta peticiones de
   navegadores para mostrar un aviso HTML; eso rompe el fetch del
   manifest y del SW. Esta cookie le dice a ngrok "es un humano, ya
   advertido, deja pasar". Inocua fuera de ngrok. */
setcookie('abuse_interstitial', 'true', [
    'expires'  => time() + 86400 * 30,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'samesite' => 'Lax',
]);
session_start();

/* Wallpaper de fondo — mismo helper que index.php para que el ambiente
   visual sea idéntico (foto blureada de melón). */
$baseWallpaper = '';
foreach (['png','jpg','jpeg','webp','gif'] as $ext) {
    if (file_exists(__DIR__ . "/assets/img/wallpapers/base-wallpaper.{$ext}")) {
        $baseWallpaper = "assets/img/wallpapers/base-wallpaper.{$ext}";
        break;
    }
}
require_once __DIR__ . '/assets/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assets/mobile-token.php';

$projectBaseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

/* NO saltamos server-side a mobile.php aunque is_pwa esté en sesión.
   En Android las cookies son compartidas entre PWA y navegador, así
   que is_pwa=true puede aparecer también en el navegador normal y
   provocaría loop con el client-guard de mobile.php. El client-side
   script del <head> (más abajo) ya redirige a mobile.php cuando el
   display-mode es standalone DE VERDAD — única señal fiable. */

$loginError = '';
$step       = 1;
$deviceToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawUser = trim((string)($_POST['username'] ?? ''));
    $pwd     = (string)($_POST['password'] ?? '');
    $matchKey = '';
    foreach ($loginUsers as $k => $u) {
        if (strcasecmp($rawUser, $k) === 0 || strcasecmp($rawUser, $u['label']) === 0) {
            $matchKey = $k; break;
        }
    }
    if ($matchKey === '' || !password_verify($pwd, $loginUsers[$matchKey]['password'])) {
        $loginError = 'Usuario o contraseña incorrectos.';
    } else {
        $_SESSION['user'] = $matchKey;
        require_once __DIR__ . '/assets/remember-login.php';
        rememberLogin($pdo, $matchKey);
        $uid = (int)$pdo->query('SELECT id FROM usuarios WHERE user_key = '
                                . $pdo->quote($matchKey))->fetchColumn();
        if ($uid > 0) {
            $deviceToken = mtCreateToken($pdo, $uid, $_SERVER['HTTP_USER_AGENT'] ?? null);
            $_SESSION['device_token'] = $deviceToken;
        }
        $step = 2;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- Deep link Discord: ?openEvent=N → sessionStorage para que el
         shell móvil lo consuma tras el login y abra Calendario. -->
    <script>(function(){
        try {
            var p = new URLSearchParams(location.search).get('openEvent');
            if (p) sessionStorage.setItem('pendingOpenEvent', p);
        } catch(_){}
    })();</script>
    <!-- Si la landing se abre DENTRO de la PWA (alguien volvió desde
         dentro o el icono apunta aquí), redirigimos a mobile.php con
         el marcador pwa=1 para que el servidor establezca la sesión
         como PWA y permita el acceso. -->
    <script>
    /* Solo saltamos a la app si ESTAMOS en standalone Y tenemos sesión.
       Antes saltábamos sin checkear sesión, y como mobile.php redirige
       a esta landing cuando no hay sesión, el ping-pong era infinito
       (landing → mobile.php?pwa=1 → no session → landing → ...).
       Si no hay sesión, dejamos que la landing renderice el form de
       login normalmente, incluso si la PWA está abierta. */
    window.MELON_HAS_SESSION = <?= json_encode(!empty($_SESSION['user']) && isset($loginUsers[$_SESSION['user']])) ?>;
    (function(){
        /* "MelonHubApp" en el User-Agent: la app de Android. */
        var sa = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
              || window.navigator.standalone === true
              || /MelonHubApp/.test(navigator.userAgent);
        if (sa && window.MELON_HAS_SESSION) {
            window.location.replace('mobile.php?pwa=1');
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <title>Melon Hub — Instalar</title>
    <link rel="icon" href="assets/img/mobile/icon.png" type="image/png">
    <?php if ($deviceToken !== ''): ?>
        <link rel="manifest" href="manifest.php?token=<?= htmlspecialchars($deviceToken) ?>">
    <?php else: ?>
        <link rel="manifest" href="manifest.php">
    <?php endif; ?>
    <link rel="apple-touch-icon" href="assets/img/mobile/icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Melon Hub">
    <!-- Mismas hojas que index.php para mantener el look Win98 -->
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <!-- Filtro LCD/VHS — siempre activo en la landing de instalación.
         Inline porque esta página no carga base.css y login.css ya usa
         body::before para el wallpaper. Aplicamos a html::before/::after
         para no colisionar. -->
    <style>
    html::before {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 2147483645;
        background-image: repeating-linear-gradient(
            180deg,
            rgba(0, 0, 0, 0.10) 0,
            rgba(0, 0, 0, 0.10) 1px,
            rgba(255, 255, 255, 0.05) 1px,
            rgba(255, 255, 255, 0.05) 2px,
            transparent 2px,
            transparent 3px
        );
    }
    html::after {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 2147483646;
        background: radial-gradient(
            ellipse 75% 60% at center,
            transparent 60%,
            rgba(0, 0, 0, 0.10) 85%,
            rgba(0, 0, 0, 0.24) 100%
        );
    }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
    <?php if ($baseWallpaper): ?>
    <style>body::before{ background-image:url('<?= htmlspecialchars($baseWallpaper) ?>'); opacity:1; }</style>
    <?php endif; ?>
    <style>
        /* ── Tweaks mobile-landing ──
           Misma paleta "consola roja oscura !ERROR" que index.php
           aplica a #selectWindow / #registerWindow. Aquí pegamos los
           mismos valores a #installWindow para que el aspecto sea
           uniforme con la web de PC. */

        /* La ventana Win98 ocupa casi todo el ancho útil del móvil. */
        .login-window {
            width: calc(100% - 24px);
            max-width: 380px;
            height: auto;
            position: static;
        }
        body {
            padding: 16px 0;
            overflow-y: auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body::before { opacity: 1 !important; }

        /* ── PALETA CONSOLA ROJA (idéntica a #selectWindow/#registerWindow) ── */
        #installWindow {
            min-width: 200px;
            background: #120808;
            border-color: #3a0000 #0a0000 #0a0000 #3a0000;
            box-shadow:
                1px 1px 0 #000,
                -1px -1px 0 #5a1010,
                0 8px 32px rgba(0,0,0,0.8),
                0 0 12px rgba(180,0,0,0.15);
        }
        #installWindow .title-bar {
            background: linear-gradient(to right, #6a0000, #b02020);
            text-shadow: 0 1px 2px rgba(0,0,0,0.6);
            border-bottom: 1px solid #3a0000;
        }
        #installWindow .window-body {
            background: #120808;
            padding: 10px;
            color: #d4a0a0;
        }
        #installWindow label {
            color: #d4a0a0;
            font-size: 11px;
        }
        #installWindow input[type="text"],
        #installWindow input[type="password"] {
            background: #1e0c0c;
            color: #d4a0a0;
            border: 1px solid #0a0000;
            border-top-color: #0a0000;
            border-left-color: #0a0000;
            border-right-color: #4a1010;
            border-bottom-color: #4a1010;
            padding: 6px 8px;
            margin-top: 4px;
            /* iOS NO hace auto-zoom si font-size >=16px. */
            font-size: 16px;
            box-shadow:
                inset 1px 1px 0 #000,
                inset -1px -1px 0 #3a0000,
                inset 2px 2px 4px rgba(0,0,0,0.6);
            border-radius: 0;
        }
        #installWindow input[type="text"]:focus,
        #installWindow input[type="password"]:focus {
            outline: none;
            background: #2a0c0c;
            color: #fff;
            box-shadow:
                inset 1px 1px 0 #000,
                inset -1px -1px 0 #5a1010,
                inset 2px 2px 4px rgba(0,0,0,0.7),
                0 0 4px rgba(180,0,0,0.4);
        }
        #installWindow .login-actions .button {
            background: #1e0c0c !important;
            color: #d4a0a0 !important;
            border: 1px solid #3a1515 !important;
            text-shadow: none !important;
            box-shadow:
                inset 0 1px 0 rgba(255,100,100,0.08),
                0 1px 3px rgba(0,0,0,0.5) !important;
        }
        #installWindow .login-actions .button:hover {
            background: #5a0000 !important;
            color: #fff !important;
            border-color: #a03030 !important;
        }
        #installWindow .login-actions .button:active {
            background: #3a0000 !important;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.5) !important;
        }
        #installWindow fieldset {
            border: 1px solid #3a0000;
            background: #1a0808;
            margin: 8px 0;
            padding: 6px 8px 8px;
        }
        #installWindow legend {
            color: #d4a0a0;
            background: #120808;
            font-size: 11px;
            padding: 0 4px;
        }

        /* Hero (paso 1): icono Melon + título VT323. */
        .mh-hero {
            text-align: center;
            margin: 4px 0 12px;
        }
        .mh-hero img {
            width: 72px; height: 72px;
            display: block;
            margin: 0 auto 6px;
            image-rendering: pixelated;
            filter: drop-shadow(0 2px 8px rgba(180,0,0,0.5));
        }
        .mh-hero .mh-title {
            font-family: 'VT323', monospace;
            font-size: 26px;
            color: #ff6060;
            letter-spacing: 2px;
            line-height: 1;
            text-shadow: 0 0 8px rgba(255,60,60,0.4);
        }
        .mh-hero .mh-sub {
            font-size: 10px;
            color: #a06060;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .mh-perks {
            margin: 6px 0 10px 8px;
            padding: 0;
            font-size: 11px;
            line-height: 1.5;
            list-style: none;
            color: #d4a0a0;
        }
        .mh-perks li { margin-bottom: 2px; }

        .mh-error {
            color: #ff8080;
            background: #2a0c0c;
            border: 1px solid #5a0000;
            padding: 4px 8px;
            font-size: 11px;
            margin: 8px 0;
        }

        /* Aviso HTTP — en lugar del amarillo brillante, ámbar oscuro
           para que case con la consola roja. */
        .mh-http-warn {
            background: #3a2a00;
            border: 1px solid #6a4a00;
            color: #ffd47a;
            padding: 6px 8px;
            font-size: 11px;
            line-height: 1.4;
            margin: 8px 0;
        }
        .mh-http-warn strong { color: #ffeaa7; }
        .mh-http-warn .button { width: 100%; margin-top: 6px; }

        /* Guía instalación: <ol> en panel rojo oscuro hundido. */
        .mh-install-steps {
            background: #1e0c0c;
            border: 2px inset #4a1010;
            color: #d4a0a0;
            padding: 6px 6px 6px 24px;
            margin: 6px 0 2px;
            font-size: 11px;
            line-height: 1.5;
        }
        .mh-install-steps li { margin-bottom: 4px; }
        .mh-install-steps li:last-child { margin-bottom: 0; }
        .mh-install-steps strong {
            color: #ffb070;
            background: rgba(255,176,112,0.10);
            border: 1px dotted #6a4a30;
            padding: 0 4px;
            font-family: 'Courier New', monospace;
            font-weight: normal;
        }

        /* CTA principal — botón Win98 default a ancho completo. */
        .mh-cta-full { width: 100%; margin-top: 10px; min-height: 32px; font-size: 13px; }

        /* ── Tabs (login / crear usuario) ── */
        #installWindow .mh-tabs {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 12px 0 0;
            border-bottom: 1px solid #3a0000;
            text-indent: 0;
        }
        #installWindow .mh-tabs > li {
            flex: 1;
            margin: 0;
            background: #0c0404;
            border: 1px solid #3a0000;
            border-bottom: none;
            text-align: center;
            cursor: pointer;
            box-shadow: none;
            border-radius: 0;
            z-index: 1;
        }
        #installWindow .mh-tabs > li[aria-selected="true"] {
            background: #2a0c0c;
            border-bottom: 1px solid #2a0c0c;
            margin-bottom: -1px;
            position: relative;
            z-index: 2;
        }
        #installWindow .mh-tabs > li > a {
            color: #d4a0a0;
            display: block;
            padding: 7px 4px;
            font-size: 11px;
            text-decoration: none;
        }
        #installWindow .mh-tabs > li[aria-selected="true"] > a {
            color: #fff;
            font-weight: bold;
        }
        #installWindow .mh-tabpanel { padding-top: 10px; }

        /* ── Register form ── */
        #installWindow .register-file-row {
            gap: 4px;
            display: flex;
            margin-top: 4px;
        }
        #installWindow .register-file-row input[type="text"] {
            flex: 1;
            min-width: 0;
            cursor: default;
            margin-top: 0;
        }
        #installWindow #registerPhotoBrowse {
            flex-shrink: 0;
            min-width: 80px;
            font-size: 11px;
            padding: 3px 10px;
            background: #1e0c0c !important;
            color: #d4a0a0 !important;
            border: 1px solid #3a1515 !important;
            text-shadow: none !important;
            box-shadow:
                inset 0 1px 0 rgba(255,100,100,0.08),
                0 1px 3px rgba(0,0,0,0.5) !important;
        }
        #installWindow #registerPhotoBrowse:hover {
            background: #5a0000 !important;
            color: #fff !important;
            border-color: #a03030 !important;
        }
        #installWindow #registerPhotoBrowse:active {
            background: #3a0000 !important;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.5) !important;
        }
    </style>
</head>
<body>

<div class="login-window">

    <?php if ($step === 1): ?>
        <!-- VENTANA: Login + Crear usuario (dos tabs) + pitch -->
        <div class="window" id="installWindow">
            <div class="title-bar">
                <div class="title-bar-text">📲 Instalar Melon Hub</div>
                <div class="title-bar-controls">
                    <button aria-label="Close" onclick="return false;"></button>
                </div>
            </div>
            <div class="window-body">
                <div class="mh-hero">
                    <img src="assets/img/mobile/icon.png" alt="Melon Hub">
                    <div class="mh-title">Melon Hub</div>
                    <div class="mh-sub">para móviles</div>
                </div>

                <p style="font-size:11px;margin:4px 0 6px;">
                    ¿Quieres instalar <strong>Melon Hub</strong> en tu móvil?
                    Se añade a tu pantalla de inicio y se abre como una app
                    de verdad.
                </p>
                <ul class="mh-perks">
                    <li>✓ Icono directo en la pantalla de inicio</li>
                    <li>✓ Inicio de sesión automatico</li>
                    <li>✓ Sin pestañas, sin barra de URL</li>
                    <li>✓ Reproductor de musica integrado</li>
                </ul>

                <!-- TABS: Iniciar sesión / Crear usuario -->
                <menu role="tablist" class="mh-tabs">
                    <li role="tab" aria-selected="true"  data-tab="login"><a href="#">Iniciar sesión</a></li>
                    <li role="tab" aria-selected="false" data-tab="register"><a href="#">+ Crear usuario</a></li>
                </menu>

                <!-- PANEL: LOGIN -->
                <div class="mh-tabpanel" data-panel="login">
                    <form method="POST">
                        <div class="field-row-stacked">
                            <label for="usr">Usuario</label>
                            <input type="text" id="usr" name="username"
                                   autocapitalize="off" autocorrect="off"
                                   autocomplete="username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        </div>
                        <div class="field-row-stacked" style="margin-top:8px;">
                            <label for="pwd">Contraseña</label>
                            <input type="password" id="pwd" name="password"
                                   autocomplete="current-password" required>
                        </div>
                        <?php if ($loginError): ?>
                            <p class="mh-error"><?= htmlspecialchars($loginError) ?></p>
                        <?php endif; ?>
                        <div class="login-actions" style="margin-top:12px;">
                            <button class="button default mh-cta-full" type="submit">
                                Sí, instalar Melon Hub
                            </button>
                        </div>
                    </form>
                </div>

                <!-- PANEL: CREAR USUARIO (oculto por defecto) -->
                <div class="mh-tabpanel" data-panel="register" hidden>
                    <form id="registerForm" enctype="multipart/form-data">
                        <div class="field-row-stacked">
                            <label>Foto de perfil</label>
                            <div class="register-file-row">
                                <input type="text" id="registerPhotoName" readonly placeholder="Sin archivo seleccionado">
                                <button class="button" type="button" id="registerPhotoBrowse">Examinar...</button>
                            </div>
                            <input type="file" id="registerPhoto" name="photo" accept="image/*" style="display:none;">
                        </div>
                        <div class="field-row-stacked" style="margin-top:8px;">
                            <label for="registerUsername">Nombre de usuario</label>
                            <input type="text" id="registerUsername" name="username"
                                   maxlength="30" autocapitalize="off" autocorrect="off" required>
                        </div>
                        <div class="field-row-stacked" style="margin-top:8px;">
                            <label for="registerPassword">Contraseña</label>
                            <input type="password" id="registerPassword" name="password" required>
                        </div>
                        <p class="mh-error" id="registerStatus" style="display:none;"></p>
                        <div class="login-actions" style="margin-top:12px;">
                            <button class="button default mh-cta-full" type="submit" id="registerSubmit">
                                Crear cuenta
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: /* step 2 */ ?>
        <!-- VENTANA: Instrucciones de instalación -->
        <div class="window" id="installWindow">
            <div class="title-bar">
                <div class="title-bar-text">📲 Casi listo - Melon Hub</div>
                <div class="title-bar-controls">
                    <button aria-label="Close" onclick="return false;"></button>
                </div>
            </div>
            <div class="window-body">
                <div class="mh-hero">
                    <img src="assets/img/mobile/icon.png" alt="Melon Hub">
                    <div class="mh-title">¡Casi listo!</div>
                    <div class="mh-sub">Cuenta activa: <?= htmlspecialchars($loginUsers[$_SESSION['user']]['label']) ?></div>
                </div>

                <!-- Aviso amarillo HTTP (solo se ve si JS detecta HTTP) -->
                <div class="mh-http-warn" id="http-warn" hidden>
                    ⚠️ <strong>Estás en HTTP.</strong> Para que el icono se
                    abra <strong>sin barras del navegador</strong>, el servidor
                    tiene que estar en HTTPS.
                    <button type="button" class="button" id="https-switch">
                        🔒 Probar con HTTPS
                    </button>
                </div>

                <!-- Botón auto-install — vía PRINCIPAL. Se ofrece primero
                     para que el usuario lo intente antes de la guía manual.
                     Si el navegador no lo soporta o el evento beforeinstallprompt
                     no disparó, el click queda inerte y entonces el usuario
                     tiene la guía manual de abajo como respaldo. -->
                <div class="login-actions" style="margin-top:4px;">
                    <button class="button default mh-cta-full" type="button"
                            id="install-auto-btn">
                        📲 Instalar como app
                    </button>
                </div>

                <!-- Aviso del paso intermedio + guía manual de instalación.
                     Es el FALLBACK: solo hay que usarla si el botón de
                     arriba no hace nada (algunos navegadores móviles
                     —Safari iOS, Firefox móvil— no soportan el prompt
                     automático y exigen pasos manuales). -->
                <p style="margin:14px 0 6px;font-size:11px;color:#a07070;line-height:1.4;">
                    <strong style="color:#ff8080;">¿El botón no hace nada?</strong>
                    Algunos navegadores (Safari, Firefox móvil…) no permiten
                    instalar la app con un solo toque. Sigue estos pasos
                    desde el menú de tu navegador:
                </p>
                <fieldset>
                    <legend>Instalación manual paso a paso</legend>
                    <ol class="mh-install-steps" id="install-steps">
                        <!-- Se rellena por JS según el navegador detectado -->
                    </ol>
                </fieldset>
            </div>
        </div>

    <?php endif; ?>

</div>

<script>
<?php if ($step === 1): ?>
/* ── Tabs Iniciar sesión / Crear usuario ────────────────────────── */
(function(){
    var tabs = document.querySelectorAll('.mh-tabs > li');
    var panels = document.querySelectorAll('.mh-tabpanel');
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(e){
            e.preventDefault();
            var target = tab.dataset.tab;
            tabs.forEach(function(t){
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
            });
            panels.forEach(function(p){
                p.hidden = (p.dataset.panel !== target);
            });
        });
    });
})();

/* ── Formulario de registro (réplica del flujo de index.php) ─────── */
(function(){
    var form     = document.getElementById('registerForm');
    var status   = document.getElementById('registerStatus');
    var submit   = document.getElementById('registerSubmit');
    var browse   = document.getElementById('registerPhotoBrowse');
    var fileEl   = document.getElementById('registerPhoto');
    var fileName = document.getElementById('registerPhotoName');
    if (!form) return;

    browse.addEventListener('click', function(){ fileEl.click(); });
    fileEl.addEventListener('change', function(){
        fileName.value = this.files.length ? this.files[0].name : '';
    });

    form.addEventListener('submit', function(e){
        e.preventDefault();
        status.style.display = 'none';
        submit.disabled = true;
        var fd = new FormData(form);
        fetch('register-user.php', { method: 'POST', body: fd })
            .then(function(r){
                return r.text().then(function(body){
                    /* Mostramos cuerpo crudo si no es JSON parseable o si el
                       status no es 2xx — así vemos el error real del server. */
                    if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + body.slice(0, 200));
                    try { return JSON.parse(body); }
                    catch (err) { throw new Error('Respuesta no JSON: ' + body.slice(0, 200)); }
                });
            })
            .then(function(d){
                submit.disabled = false;
                if (!d || d.error) {
                    status.textContent = (d && d.error) ? d.error : 'Error';
                    status.style.display = '';
                    return;
                }
                /* Si la foto se subió pero getUserImage no la encuentra,
                   el backend devuelve photoWarning con el diagnóstico.
                   Lo mostramos COMO TEXTO en el status para que el
                   usuario lo vea sin abrir DevTools, y NO hacemos el
                   autologin → así pueden enviar el mensaje completo
                   para diagnosticar. La cuenta sí queda creada; el
                   usuario puede subir la foto desde Ajustes. */
                if (d.photoWarning) {
                    try { console.warn('[register] photo:', d.photoWarning, d.photoDebug || ''); } catch (_) {}
                    status.style.color = 'var(--mh-error, #ffcc66)';
                    status.textContent = '⚠ Cuenta creada, pero la foto NO se ha podido aplicar: '
                        + d.photoWarning + ' — Inicia sesión normalmente y sube la foto desde Ajustes.';
                    status.style.display = '';
                    /* Botón para continuar al login manualmente. */
                    var cont = document.createElement('button');
                    cont.type = 'button';
                    cont.className = 'button mh-cta-full';
                    cont.style.marginTop = '8px';
                    cont.textContent = 'Continuar al login';
                    cont.addEventListener('click', function(){
                        var loginForm2 = document.querySelector('.mh-tabpanel[data-panel="login"] form');
                        if (loginForm2) {
                            document.getElementById('usr').value = document.getElementById('registerUsername').value;
                            document.getElementById('pwd').value = document.getElementById('registerPassword').value;
                            loginForm2.submit();
                        } else {
                            window.location.href = 'mobile-landing.php';
                        }
                    });
                    status.parentNode.insertBefore(cont, status.nextSibling);
                    return;
                }
                /* Cuenta creada → auto-login con las credenciales que el
                   usuario acaba de escribir. Inyectamos los valores en el
                   formulario de login y lo enviamos: mobile-landing.php
                   los valida (acaban de crearse, así que pasarán),
                   monta la sesión y renderiza directamente el step 2. */
                var loginForm = document.querySelector('.mh-tabpanel[data-panel="login"] form');
                if (loginForm) {
                    document.getElementById('usr').value = document.getElementById('registerUsername').value;
                    document.getElementById('pwd').value = document.getElementById('registerPassword').value;
                    loginForm.submit();
                } else {
                    /* Fallback defensivo — no debería pasar. */
                    window.location.href = 'mobile-landing.php';
                }
            })
            .catch(function(err){
                submit.disabled = false;
                status.textContent = err.message || 'Error de red';
                status.style.display = '';
            });
    });
})();
<?php endif; ?>

<?php if ($step === 2): ?>
/* ── Service Worker ────────────────────────────────────────────── */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js', { scope: '<?= $projectBaseUrl ?>' })
        .catch(function(err){ console.warn('[landing] sw register fail', err); });
}

/* ── Detección de contexto y navegador ────────────────────────── */
(function(){
    var ua = navigator.userAgent;
    var isHTTPS    = window.location.protocol === 'https:';
    var isLocal    = /^(localhost|127\.|\[?::1)/.test(window.location.hostname);
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true
                    || /MelonHubApp/.test(ua);   /* app de Android */
    if (isStandalone) {
        /* Ya estamos dentro de la PWA — la guía no aplica. */
        window.location.replace('mobile.php');
        return;
    }

    /* HTTP fuera de localhost: avisar al usuario. */
    if (!isHTTPS && !isLocal) {
        document.getElementById('http-warn').hidden = false;
    }

    /* Detectar navegador. Orden importante: Edge contiene "Chrome", iOS
       Chrome también, etc. — comprobamos en orden de especificidad. */
    var isIOS     = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    var isAndroid = /Android/.test(ua);
    var browser;
    if (isIOS && /CriOS/.test(ua))         browser = 'ios-chrome';
    else if (isIOS && /FxiOS/.test(ua))    browser = 'ios-firefox';
    else if (isIOS)                        browser = 'ios-safari';
    else if (/EdgA/.test(ua))              browser = 'edge-android';
    else if (/SamsungBrowser/.test(ua))    browser = 'samsung';
    else if (/Firefox/.test(ua))           browser = 'firefox';
    else if (isAndroid && /Chrome/.test(ua)) browser = 'chrome-android';
    else                                   browser = 'generic';

    /* Pasos por navegador. Cada paso renderiza icono/etiqueta con
       <strong> para que el usuario reconozca lo que tiene que buscar. */
    var GUIDES = {
        'chrome-android': [
            'Pulsa el menú <strong>⋮</strong> arriba a la derecha de Chrome.',
            'Selecciona <strong>«Instalar app»</strong> o <strong>«Añadir a pantalla principal»</strong>.',
            'Confirma <strong>«Instalar»</strong>. El icono aparecerá en tu pantalla.'
        ],
        'ios-safari': [
            'Pulsa el botón <strong>Compartir</strong> <strong>⬆︎</strong> (parte inferior de Safari).',
            'Desliza y pulsa <strong>«Añadir a pantalla de inicio»</strong>.',
            'Confirma <strong>«Añadir»</strong> arriba a la derecha.'
        ],
        'ios-chrome': [
            'Pulsa el botón <strong>Compartir</strong> <strong>⬆︎</strong> (parte inferior).',
            'Selecciona <strong>«Añadir a pantalla de inicio»</strong>.',
            'Confirma <strong>«Añadir»</strong>. <em>Nota:</em> en iOS solo Safari instala como PWA — Chrome creará un marcador.'
        ],
        'ios-firefox': [
            'Pulsa el menú <strong>⋯</strong> de Firefox.',
            'Selecciona <strong>«Compartir»</strong> → <strong>«Añadir a pantalla principal»</strong>.',
            '<em>Nota:</em> en iOS solo Safari instala como app de verdad.'
        ],
        'edge-android': [
            'Pulsa el menú <strong>⋯</strong> abajo de Edge.',
            'Pulsa <strong>«Añadir al teléfono»</strong>.',
            'Confirma <strong>«Instalar»</strong> / <strong>«Añadir»</strong>.'
        ],
        'samsung': [
            'Pulsa el menú <strong>☰</strong> abajo a la derecha.',
            'Selecciona <strong>«Añadir página a»</strong> → <strong>«Pantalla principal»</strong>.',
            'Confirma <strong>«Añadir»</strong>.'
        ],
        'firefox': [
            'Pulsa el menú <strong>⋮</strong> de Firefox.',
            'Selecciona <strong>«Instalar»</strong> o <strong>«Añadir a pantalla principal»</strong>.',
            'Confirma. El icono aparecerá en tu pantalla.'
        ],
        'generic': [
            'Abre el menú de tu navegador (suele ser <strong>⋮</strong> o <strong>⋯</strong>).',
            'Busca <strong>«Instalar app»</strong>, <strong>«Añadir a pantalla de inicio»</strong> o similar.',
            'Confirma. El icono aparecerá en tu pantalla.'
        ]
    };
    var steps = GUIDES[browser] || GUIDES.generic;
    var ol = document.getElementById('install-steps');
    /* El <ol> renderiza numeración nativa, no nos hace falta envoltorio. */
    steps.forEach(function(html){
        var li = document.createElement('li');
        li.innerHTML = html;
        ol.appendChild(li);
    });
})();

/* ── Instalación PWA ───────────────────────────────────────────────
   El botón "Instalar como app" está SIEMPRE visible. Solo dispara el
   diálogo nativo si el navegador ya nos pasó beforeinstallprompt
   (HTTPS + manifest válido + SW + engagement). Si aún no llegó, el
   click no hace nada — la vía es la guía manual de arriba. */
(function(){
    var btn = document.getElementById('install-auto-btn');
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
    });

    /* Muestra el OK debajo del botón. Evita redirects al menú
       principal — el usuario querrá abrir la PWA desde el icono
       recién creado, no quedarse en la web. */
    function showInstalled(){
        if (btn.dataset.installed === '1') return;
        btn.dataset.installed = '1';
        btn.textContent = '✓ Instalada — ábrela desde el icono';
        btn.disabled = true;
    }
    btn.addEventListener('click', function() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(choice){
            deferredPrompt = null;
            if (choice && choice.outcome === 'accepted') {
                showInstalled();
            }
        });
    });
    /* Disparado por el navegador cuando termina el flujo de instalación
       (independiente del botón — puede venir desde el "Añadir a inicio"
       del menú del browser). NO redirige; deja la web visible y el
       usuario decide cuándo abrir el icono. */
    window.addEventListener('appinstalled', showInstalled);
})();

/* ── Switch a HTTPS desde el banner amarillo ───────────────────── */
(function(){
    var swBtn = document.getElementById('https-switch');
    if (!swBtn) return;
    swBtn.addEventListener('click', function(){
        var url = window.location.href.replace(/^http:/, 'https:');
        window.location.href = url;
    });
})();
<?php endif; ?>
</script>

</body>
</html>
