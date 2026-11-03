<?php
header('Content-Type: text/html; charset=UTF-8');
/* Sesión persistente 30 días → al abrir la PWA / volver al móvil, no
   pide login. Tiene que ir antes de session_start(). */
require_once __DIR__ . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once __DIR__ . '/assets/config.php';
/* db.php define $pdo en el scope global. CRÍTICO: hacerlo aquí (a
   nivel de archivo) para que TODOS los helpers que necesitan PDO lo
   encuentren via $GLOBALS['pdo']. Si lo requerimos dentro de una
   función, $pdo se setea local y nunca llega al scope global. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assets/themes/theme-helpers.php';

/* Móviles SIEMPRE entran por la landing. Es la única puerta a Melon
   Hub — la landing decide client-side qué hacer:
     - Standalone (la PWA está abierta) + sesión → mobile.php?pwa=1.
     - Standalone sin sesión → renderiza el form de login propio de la
       landing (evita el ping-pong con mobile.php).
     - Navegador externo → muestra el pitch de instalación.
   Override con ?desktop=1 (o cookie force_desktop). */
if (isMobileDevice()) {
    header('Location: mobile-landing.php');
    exit;
}

/* Con la sesión abierta (o recordada, ver assets/remember-login.php) se
   entra directo al escritorio, sin introducción ni selector de usuario:
   así la app de escritorio abre ya dentro. Para volver al selector hay
   que cerrar sesión (logout.php). */
require_once __DIR__ . '/assets/remember-login.php';
/* "Escuchar conmigo" (botón de la presencia de Discord): ?escuchar=<user_key>
   se guarda hasta entrar y se le pasa al escritorio (apps/reproductor.php). */
if (isset($_GET['escuchar'])) {
    $_SESSION['lt_join'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['escuchar']);
}
function desktopUrlFor(string $label): string {
    $url = 'desktops/' . strtolower($label) . '-desktop.php';
    if (!empty($_SESSION['lt_join'])) {
        $url .= '?escuchar=' . rawurlencode($_SESSION['lt_join']);
        unset($_SESSION['lt_join']);
    }
    return $url;
}
$rememberedUser = restoreRememberedLogin($pdo);
if ($rememberedUser !== null && isset($loginUsers[$rememberedUser]) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rememberedLabel = $loginUsers[$rememberedUser]['label'];
    ensureDesktopStub($rememberedLabel);
    header('Location: ' . desktopUrlFor($rememberedLabel));
    exit;
}

/* Base URL del proyecto, derivada del script actual. Asegura que las
   rutas a CSS de temas funcionen sea cual sea la URL de acceso
   (localhost/scrapbookOnline/, localhost/, virtual host, etc.). */
$projectBaseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
if ($projectBaseUrl === '/') $projectBaseUrl = '/';

/* Para cada usuario disponible, calcula su tema activo (si existe).
   Devuelve ['class' => '<className>', 'css' => '/scrapbookOnline/assets/themes/...css']
   o null si el usuario no tiene tema activo. */
function getUserActiveTheme($userKey, $label) {
    global $projectBaseUrl;
    /* Resuelve la interfaz preferida del TARGET user (no del browser).
       Sin esto, loadUserThemes lee la cookie del browser → carga el
       tema de la interfaz del último user logueado. */
    $iface = getUserActiveInterfaceSlug($userKey);
    if ($iface === '') $iface = 'win98';
    $data = loadUserThemes($userKey, $iface);
    $active = !empty($data['active']) ? sanitizeThemeName($data['active']) : '';

    /* Si el user NO tiene tema activo para SU interfaz, devolvemos
       null → el login se renderiza con los defaults del :root de la
       interfaz (kawaii pastel / win98 silver). NO mezclamos temas de
       otras interfaces — un tema de win98 se vería raro sobre kawaii. */
    if ($active === '') {
        $def = defaultThemeForUser($userKey);
        if (!$def) return null;
        $active = sanitizeThemeName($def['name']);
    }

    $cssRel = themeCssRelPath($active, $label);
    if (!file_exists(__DIR__ . '/' . $cssRel)) {
        /* Asegura que el CSS del tema (default o activo) existe en disco */
        $themes = (array)($data['themes'] ?? []);
        $colors = isset($themes[$active]['colors']) ? $themes[$active]['colors'] : null;
        if (!$colors) {
            $def = defaultThemeForUser($userKey);
            if ($def && sanitizeThemeName($def['name']) === $active) $colors = $def['colors'];
        }
        if ($colors) {
            $css = generateThemeCss(themeCssClassName($active, $label), $colors);
            @file_put_contents(__DIR__ . '/' . $cssRel, $css);
        }
        if (!file_exists(__DIR__ . '/' . $cssRel)) return null;
    }
    return [
        'class' => themeCssClassName($active, $label),
        'css'   => $projectBaseUrl . $cssRel,
    ];
}

$adImages = [];
$adDir = __DIR__ . '/assets/img/archiveAd';
if (is_dir($adDir)) {
    foreach (scandir($adDir) as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $adImages[] = 'assets/img/archiveAd/' . $f;
        }
    }
}
$adImage = $adImages ? $adImages[array_rand($adImages)] : null;

$users = $loginUsers;

$selectedUser = $_POST['user'] ?? '';
$selectedLabel = '';
$selectedImage = '';
$selectedWallpaper = '';
$showLogin = false;
$loginError = false;

/* Devuelve la slug de la interfaz activa del usuario (lee BD), o ''
   si no tiene preferencia. Usa user_settings.active_interface_slug
   con CHECK(json_valid(value)) → decodificar JSON. */
function getUserActiveInterfaceSlug($userKey) {
    try {
        require_once __DIR__ . '/db.php';
        global $pdo;
        if (!$pdo instanceof PDO) return '';
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
        $stmt->execute([$userKey]);
        $uid = (int)($stmt->fetchColumn() ?: 0);
        if (!$uid) return '';
        $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_interface_slug'");
        $stmt->execute([$uid]);
        $raw = (string)$stmt->fetchColumn();
        if ($raw === '') return '';
        $slug = json_decode($raw, true);
        if (!is_string($slug) || $slug === '') return '';
        if (!is_dir(__DIR__ . '/assets/interfaces/' . $slug . '/')) return '';
        return $slug;
    } catch (Throwable $e) {
        return '';
    }
}

/* Devuelve el icon pack activo del usuario para una interfaz, o
   'Melon' como fallback. */
function getUserActiveIconPack($userKey, $iface) {
    try {
        require_once __DIR__ . '/db.php';
        global $pdo;
        if (!$pdo instanceof PDO) return 'Melon';
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
        $stmt->execute([$userKey]);
        $uid = (int)($stmt->fetchColumn() ?: 0);
        if (!$uid) return 'Melon';
        $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
        $stmt->execute([$uid, 'icon_pack:' . $iface]);
        $raw = (string)$stmt->fetchColumn();
        if ($raw === '') return 'Melon';
        $pack = json_decode($raw, true);
        return (is_string($pack) && $pack !== '') ? $pack : 'Melon';
    } catch (Throwable $e) {
        return 'Melon';
    }
}

$selectedTheme = null;  /* ['class'=>..., 'css'=>...] del usuario seleccionado */
$selectedInterface = ''; /* slug — '' si no selecciona o no tiene preferencia */
$selectedIconPack  = 'Melon';
if ($selectedUser && isset($users[$selectedUser])) {
    $selectedLabel = $users[$selectedUser]['label'];
    $selectedImage = getUserImage($selectedLabel);
    $selectedWallpaper = getUserEffectiveWallpaper($selectedUser, $selectedLabel);
    $selectedTheme = getUserActiveTheme($selectedUser, $selectedLabel);
    $selectedInterface = getUserActiveInterfaceSlug($selectedUser);
    if ($selectedInterface !== '') {
        $selectedIconPack = getUserActiveIconPack($selectedUser, $selectedInterface);
    }
    $showLogin = true;

    if (isset($_POST['password'])) {
        if (password_verify($_POST['password'], $users[$selectedUser]['password'])) {
            $_SESSION['user'] = $selectedUser;
            rememberLogin($pdo, $selectedUser);
            /* Móviles → menú feature-phone. PC/tablet → escritorio Win98.
               ensureDesktopStub() regenera el stub si fue borrado en algún
               momento — el usuario aterriza en su escritorio en vez de 404. */
            ensureDesktopStub($selectedLabel);
            $target = isMobileDevice()
                ? 'mobile.php'
                : desktopUrlFor($selectedLabel);
            header('Location: ' . $target);
            exit;
        }
        $loginError = true;
    }
}

/* Tema por defecto = Win98 (tokens.css). No añadimos la clase {$selectedUser}
   para que body.user1/body.user2 (que disparan paletas Capi/Angie) no aplique. */
$bodyClass = $showLogin ? 'user-selected' : '';
if ($selectedTheme) $bodyClass .= ' ' . $selectedTheme['class'];
$skipIntro = isset($_GET['nointro']);

$baseWallpaper = '';
foreach (['png','jpg','jpeg','webp','gif'] as $ext) {
    if (file_exists(__DIR__ . "/assets/img/wallpapers/base-wallpaper.{$ext}")) {
        $baseWallpaper = "assets/img/wallpapers/base-wallpaper.{$ext}";
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- Deep link de Discord: si llega ?openEvent=N, lo guardamos en
         sessionStorage para que el shell (mobile.php / desktop-base) lo
         consuma tras el login y abra la app de Calendario con el evento. -->
    <script>(function(){
        try {
            var p = new URLSearchParams(location.search).get('openEvent');
            if (p) sessionStorage.setItem('pendingOpenEvent', p);
        } catch(_){}
    })();</script>
    <!-- Login responsivo: el formulario y el grid de usuarios usan flex/grid
         y caben tanto en móvil-tablet como en PC. Sin esto, móvil/tablet
         heredan el viewport default del browser (~980px) y se descuajan. -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Scrapbook Melon</title>
    <!-- Favicon vacío para evitar 404 automático cuando el host no tiene
         /favicon.ico (InfinityFree intercepta esos 404 con su redirect). -->
    <link rel="icon" href="assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <!-- Filtro LCD/VHS — siempre activo en el login, no depende del toggle.
         Inline porque index.php no carga base.css. Scanlines + vignette. -->
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
    <script>
    /* Win98 dialogs — inline para no depender de un fichero JS externo
       (algunos hosts compartidos rechazan /assets/js/ recién creados sin
       refresh de la web app). Lógica idéntica a assets/js/win98-dialogs.js. */
    (function(){
        if (window.__win98DialogsLoaded) return;
        window.__win98DialogsLoaded = true;
        var Z = 999999;
        function build(opts){
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.25);z-index:' + (Z++) + ';display:flex;align-items:center;justify-content:center;';
            var win = document.createElement('div');
            win.className = 'window';
            win.style.cssText = 'min-width:300px;max-width:480px;z-index:' + (Z++) + ';';
            var bar = document.createElement('div'); bar.className = 'title-bar';
            var barText = document.createElement('div'); barText.className = 'title-bar-text';
            barText.textContent = opts.title || (opts.type === 'alert' ? 'Aviso' : opts.type === 'confirm' ? 'Confirmar' : 'Introduce un valor');
            var barCtl = document.createElement('div'); barCtl.className = 'title-bar-controls';
            var closeBtn = document.createElement('button'); closeBtn.setAttribute('aria-label','Close');
            barCtl.appendChild(closeBtn); bar.appendChild(barText); bar.appendChild(barCtl);
            var body = document.createElement('div'); body.className = 'window-body'; body.style.cssText = 'padding:12px 14px;';
            var row = document.createElement('div'); row.style.cssText = 'display:flex;gap:12px;align-items:flex-start;';
            var icon = document.createElement('div');
            icon.textContent = opts.type === 'confirm' ? '❓' : opts.type === 'prompt' ? '✏️' : 'ℹ️';
            icon.style.cssText = 'font-size:24px;line-height:1;flex:0 0 28px;';
            var msg = document.createElement('div');
            msg.style.cssText = 'flex:1;font-size:11px;white-space:pre-wrap;line-height:1.4;';
            msg.textContent = opts.message || '';
            row.appendChild(icon); row.appendChild(msg); body.appendChild(row);
            var input = null;
            if (opts.type === 'prompt') {
                input = document.createElement('input'); input.type = 'text';
                input.value = (opts.defaultVal != null ? String(opts.defaultVal) : '');
                input.style.cssText = 'width:100%;margin-top:10px;box-sizing:border-box;';
                body.appendChild(input);
            }
            var btns = document.createElement('div');
            btns.style.cssText = 'display:flex;justify-content:flex-end;gap:6px;margin-top:14px;';
            var okBtn = document.createElement('button'); okBtn.className = 'default'; okBtn.textContent = opts.okLabel || 'Aceptar';
            var cancelBtn = null;
            if (opts.type !== 'alert') {
                cancelBtn = document.createElement('button'); cancelBtn.textContent = opts.cancelLabel || 'Cancelar';
                btns.appendChild(cancelBtn);
            }
            btns.appendChild(okBtn); body.appendChild(btns);
            win.appendChild(bar); win.appendChild(body);
            overlay.appendChild(win); document.body.appendChild(overlay);
            if (input) setTimeout(function(){ input.focus(); input.select(); }, 30);
            else       setTimeout(function(){ okBtn.focus(); }, 30);
            function close(){ document.removeEventListener('keydown', onKey, true); overlay.remove(); }
            function doOk(){ close(); if (opts.onOk) opts.onOk(input ? input.value : true); }
            function doCancel(){ close(); if (opts.onCancel) opts.onCancel(); }
            function onKey(e){
                if (e.key === 'Enter')      { e.preventDefault(); doOk(); }
                else if (e.key === 'Escape'){ e.preventDefault(); doCancel(); }
            }
            document.addEventListener('keydown', onKey, true);
            okBtn.addEventListener('click', doOk);
            if (cancelBtn) cancelBtn.addEventListener('click', doCancel);
            closeBtn.addEventListener('click', doCancel);
        }
        window.win98Alert = function(message, title, onOk){
            if (typeof title === 'function') { onOk = title; title = undefined; }
            build({ type:'alert', message:String(message), title:title, onOk:onOk });
        };
        window.win98Confirm = function(message, title, onOk, onCancel){
            if (typeof title === 'function') { onCancel = onOk; onOk = title; title = undefined; }
            build({ type:'confirm', message:String(message), title:title, onOk:onOk, onCancel:onCancel });
        };
        window.win98Prompt = function(message, defaultVal, onOk, onCancel, title){
            build({ type:'prompt', message:String(message), defaultVal:defaultVal, onOk:onOk, onCancel:onCancel, title:title });
        };
        window.alert = function(message){ window.win98Alert(message); };
    })();
    </script>
    <?php if ($baseWallpaper): ?>
    <style>body::before{ background-image:url('<?php echo htmlspecialchars($baseWallpaper); ?>'); opacity:1; }</style>
    <?php endif; ?>
    <?php if ($selectedWallpaper): ?>
    <style id="wallpaper-style">body::before{ background-image:url('<?php echo htmlspecialchars($selectedWallpaper); ?>'); }</style>
    <?php endif; ?>
    <?php if ($selectedTheme): ?>
    <link rel="stylesheet" id="user-theme-link" href="<?php echo htmlspecialchars($selectedTheme['css']); ?>">
    <?php endif; ?>
    <?php if ($selectedInterface !== '' && is_file(__DIR__ . '/assets/interfaces/' . $selectedInterface . '/login.css')): ?>
    <?php /* Interfaz del user seleccionado: SOLO cargamos su login.css
              (overrides scoped a #userPreview). El style.css completo
              afectaría globalmente — selector de usuarios incluido — lo
              cual no queremos. login.css contiene solo lo necesario
              para que la ventana de password tenga el look del user. */ ?>
    <link rel="stylesheet" id="user-interface-link"
          href="assets/interfaces/<?php echo htmlspecialchars($selectedInterface); ?>/login.css?v=<?php echo filemtime(__DIR__ . '/assets/interfaces/' . $selectedInterface . '/login.css'); ?>">
    <?php endif; ?>
</head>

<body class="<?php echo $bodyClass; ?>">

<!-- INTRO -->
<?php if (!$skipIntro): ?>
<div id="intro-overlay">
    <div id="intro-start">
        <span>Click to start...<span id="intro-cursor">_</span></span>
    </div>
    <video id="intro-video" muted playsinline>
        <source src="https://raw.githubusercontent.com/EduConPar/Scrapbook-Online/dev/assets/vids/inicio.mp4" type="video/mp4">
    </video>
</div>
<?php endif; ?>

<div class="login-window">

    <!-- FOTO -->
    <div class="user-preview <?php echo $showLogin ? 'visible' : ''; ?>" id="userPreview">
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">!ERROR</div>
            </div>
            <div class="window-body">
                <img id="previewImage" src="<?php echo htmlspecialchars($selectedImage); ?>" alt=""<?php if (!$selectedImage) echo ' style="display:none;"'; ?>>
                <div id="previewPlaceholder" class="login-avatar-placeholder"<?php if ($selectedImage) echo ' style="display:none;"'; ?>>👤</div>
            </div>
        </div>
    </div>

    <!-- LOGIN (usuario + contraseña) -->
    <div class="window visible" id="loginWindow">
        <div class="title-bar">
            <div class="title-bar-text"><span id="error-icon">✕</span> Iniciar sesión</div>
        </div>
        <div class="window-body">
            <?php if (empty($users) && !empty($GLOBALS['_loginUsersError'])): ?>
                <p style="font-size:11px;color:#c00;margin:0 0 8px;padding:6px;background:#fee;border:1px solid #fcc;">
                    <strong>No hay usuarios para mostrar.</strong><br>
                    <span style="font-size:10px;"><?php echo htmlspecialchars($GLOBALS['_loginUsersError']); ?></span>
                </p>
            <?php endif; ?>
            <form method="POST" id="loginForm">
                <input type="hidden" name="user" id="selectedUser" value="<?php echo htmlspecialchars($selectedUser); ?>">
                <div class="field-row-stacked">
                    <label>Usuario</label>
                    <input type="text" name="username" id="usernameInput" autocomplete="off" maxlength="30" value="<?php echo htmlspecialchars($selectedLabel); ?>"<?php echo $showLogin ? '' : ' autofocus'; ?>>
                    <p class="error-text" id="userNotFound" style="display:none;">Usuario no encontrado.</p>
                </div>
                <div class="field-row-stacked">
                    <label>Contraseña</label>
                    <input type="password" name="password"<?php echo $showLogin ? ' autofocus' : ''; ?>>
                    <?php if ($loginError): ?>
                    <p class="error-text">Contraseña incorrecta.</p>
                    <?php endif; ?>
                </div>
                <div class="login-actions">
                    <button class="button" type="button" id="openRegister">+ Crear usuario</button>
                    <?php $_showDel = $showLogin && $selectedUser !== '' && $selectedUser !== 'user1' && $selectedUser !== 'user2'; ?>
                    <button class="button" type="button" id="deleteUser" style="display:<?php echo $_showDel ? 'inline-block' : 'none'; ?>;"><img src="assets/img/appIcons/Claro/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;"> Eliminar cuenta</button>
                    <button class="button default" type="submit">Ingresar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- REGISTER -->
    <div class="window hidden" id="registerWindow">
        <div class="title-bar">
            <div class="title-bar-text">+ Crear usuario</div>
        </div>
        <div class="window-body">
            <form id="registerForm" enctype="multipart/form-data">
                <div class="field-row-stacked">
                    <label>Foto de perfil</label>
                    <div class="register-file-row">
                        <input type="text" id="registerPhotoName" readonly placeholder="Sin archivo seleccionado">
                        <button class="button" type="button" id="registerPhotoBrowse">Examinar...</button>
                    </div>
                    <input type="file" id="registerPhoto" name="photo" accept="image/*" style="display:none;">
                </div>
                <div class="field-row-stacked">
                    <label>Nombre de usuario</label>
                    <input type="text" id="registerUsername" name="username" maxlength="30" required>
                </div>
                <div class="field-row-stacked">
                    <label>Contraseña</label>
                    <input type="password" id="registerPassword" name="password" required>
                </div>
                <p class="error-text" id="registerStatus" style="display:none;"></p>
                <div class="login-actions">
                    <button class="button" type="button" id="registerCancel">Cancelar</button>
                    <button class="button default" type="submit" id="registerSubmit">Crear</button>
                </div>
            </form>
        </div>
    </div>

</div>

<?php if ($adImage): ?>
<!-- POPUP AD -->
<div id="ad-popup" class="window">
    <div class="title-bar" id="ad-titlebar">
        <div class="title-bar-text">&#128276; MelonArchive</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="ad-close"></button>
        </div>
    </div>
    <div class="window-body" id="ad-body">
        <img id="ad-image" src="<?php echo htmlspecialchars($adImage); ?>" alt="MelonArchive">
        <button class="button" id="ad-btn">!VISIT NOW</button>
    </div>
</div>
<?php endif; ?>

<?php
/* Mapa de usuarios para la detección por nombre en el login (JS). Mismos
   datos que antes alimentaban los botones data-* del selector. */
$jsUsers = [];
foreach ($users as $key => $user) {
    $_theme = getUserActiveTheme($key, $user['label']);
    $_userIface = getUserActiveInterfaceSlug($key);
    $_userIfaceLoginCss = '';
    if ($_userIface !== '') {
        $_loginCssAbs = __DIR__ . '/assets/interfaces/' . $_userIface . '/login.css';
        if (is_file($_loginCssAbs)) {
            $_userIfaceLoginCss = 'assets/interfaces/' . $_userIface . '/login.css?v=' . filemtime($_loginCssAbs);
        }
    }
    $jsUsers[] = [
        'key'               => $key,
        'label'             => $user['label'],
        'img'               => getUserImage($user['label']),
        'wallpaper'         => getUserEffectiveWallpaper($key, $user['label']),
        'themeClass'        => $_theme['class'] ?? '',
        'themeCss'          => $_theme['css'] ?? '',
        'interfaceLoginCss' => $_userIfaceLoginCss,
        'isDefault'         => ($key === 'user1' || $key === 'user2'),
    ];
}
?>

<script>
const body = document.body;
const loginWindow = document.getElementById('loginWindow');
const selectedUserInput = document.getElementById('selectedUser');
const usernameInput = document.getElementById('usernameInput');
const userNotFound = document.getElementById('userNotFound');
const userPreview = document.getElementById('userPreview');
const previewImage = document.getElementById('previewImage');
const previewPlaceholder = document.getElementById('previewPlaceholder');
const introOverlay = document.getElementById('intro-overlay');

/* Mapa de usuarios disponibles para la detección por nombre. Incluye
   todo lo necesario para pintar el preview del usuario al escribir su
   nombre: imagen, fondo, tema e interfaz (login.css scoped). */
const USERS = <?php echo json_encode($jsUsers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const USERS_BY_LABEL = (function(){
    var m = {};
    USERS.forEach(function(u){ m[(u.label || '').trim().toLowerCase()] = u; });
    return m;
})();
function findUserByName(name){
    return USERS_BY_LABEL[(name || '').trim().toLowerCase()] || null;
}

/* =========================
   INTRO
========================= */

if (introOverlay) {
    if (sessionStorage.getItem('introSeen')) {
        introOverlay.style.display = 'none';
    } else {
        const introStart = document.getElementById('intro-start');

        const introVideo = document.getElementById('intro-video');

        /* Cierra y marca como visto. Lo dispara tanto el final natural
           del vídeo como el click de skip durante la reproducción. */
        function finishIntro() {
            if (sessionStorage.getItem('introSeen')) return;
            sessionStorage.setItem('introSeen', '1');
            try { introVideo.pause(); } catch (_) {}
            introOverlay.classList.add('oculto');
            setTimeout(function() { introOverlay.style.display = 'none'; }, 800);
        }

        introStart.addEventListener('click', function() {
            introStart.style.display = 'none';
            introVideo.muted = false;
            introVideo.play();
            /* A partir de aquí, click en el overlay = skip. Lo
               registramos en el NEXT tick para no atrapar el mismo
               click que arrancó la reproducción. */
            setTimeout(function() {
                introOverlay.addEventListener('click', finishIntro, { once: true });
                /* Pista visual no-intrusiva en la esquina. */
                const skipHint = document.createElement('div');
                skipHint.id = 'intro-skip-hint';
                skipHint.textContent = 'Click para saltar';
                skipHint.style.cssText = 'position:absolute;bottom:20px;right:24px;' +
                    'color:#fff;font-family:monospace;font-size:14px;' +
                    'text-shadow:0 0 6px rgba(0,0,0,0.9);opacity:0;' +
                    'transition:opacity .4s ease;pointer-events:none;z-index:2;';
                introOverlay.appendChild(skipHint);
                requestAnimationFrame(function(){ skipHint.style.opacity = '0.85'; });
            }, 0);
        }, { once: true });

        introVideo.addEventListener('ended', finishIntro);

        introVideo.addEventListener('error', function() {
            sessionStorage.setItem('introSeen', '1');
            introOverlay.style.display = 'none';
        });
    }
}

/* =========================
   CAMBIO USUARIO
========================= */

function setWallpaper(src)
{
    let style = document.getElementById('wallpaper-style');
    if (!style) {
        style = document.createElement('style');
        style.id = 'wallpaper-style';
        document.head.appendChild(style);
    }
    style.textContent = src ? `body::before{ background-image:url('${src}'); }` : '';
}

/* Aplica/quita el CSS del tema activo del usuario seleccionado.
   Se inyecta/actualiza un <link id="user-theme-link"> en <head>. */
let currentUserThemeClass = <?php echo json_encode($selectedTheme['class'] ?? ''); ?>;
function applyUserTheme(themeClass, themeCss)
{
    /* Quitar clase del tema anterior */
    if (currentUserThemeClass) body.classList.remove(currentUserThemeClass);
    currentUserThemeClass = themeClass || '';
    /* Si no hay tema → quitar el <link> entero (evita cargar página como CSS) */
    let link = document.getElementById('user-theme-link');
    if (!themeClass || !themeCss) {
        if (link) link.remove();
        return;
    }
    if (!link) {
        link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id  = 'user-theme-link';
        document.head.appendChild(link);
    }
    body.classList.add(themeClass);
    /* Cache-buster por si el navegador tenía el CSS cacheado */
    link.href = themeCss + (themeCss.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
}

function pixelateImg(img, factor) {
    if (!img.naturalWidth || img.src.startsWith('data:')) return;
    const w = Math.max(1, Math.round(img.naturalWidth  * factor));
    const h = Math.max(1, Math.round(img.naturalHeight * factor));
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(img, 0, 0, w, h);
    img.src = c.toDataURL('image/png');
}

previewImage.addEventListener('load', function() { pixelateImg(previewImage, 0.1); });
if (previewImage.complete && previewImage.naturalWidth) pixelateImg(previewImage, 0.1);

const userKeys = USERS.map(u => u.key);

/* Estado de la detección por nombre: el usuario actualmente reconocido
   (o null si lo escrito no coincide con ninguno). Los wraps de los
   popups (ad / fatal errors) se enganchan a applyDetectedUser. */
let detectedUser = null;

/* Pinta el preview (imagen + fondo + tema + interfaz) del usuario `u`.
   Si `u` es null, limpia todo y vuelve al estado "sin usuario". */
function applyDetectedUser(u)
{
    detectedUser = u || null;
    selectedUserInput.value = u ? u.key : '';
    setWallpaper(u ? u.wallpaper : '');
    /* No añadir la clase userKey: dispara la paleta capi/angie de themes.css.
       Solo 'user-selected' que controla la transición del avatar/wallpaper. */
    body.classList.remove(...userKeys);
    if (u) body.classList.add('user-selected'); else body.classList.remove('user-selected');
    applyUserTheme(u ? u.themeClass : '', u ? u.themeCss : '');
    /* login.css de la interfaz del user — scoped, no afecta al resto. */
    applyUserInterfaceLogin(u ? (u.interfaceLoginCss || '') : '');
    if (u && u.img) {
        previewImage.src = u.img;
        previewImage.style.display = '';
        if (previewPlaceholder) previewPlaceholder.style.display = 'none';
    } else {
        previewImage.style.display = 'none';
        if (previewPlaceholder) previewPlaceholder.style.display = '';
    }
    /* Botón "Eliminar" sólo para usuarios que no sean Capi/Angie (defaults). */
    var delBtn = document.getElementById('deleteUser');
    if (delBtn) delBtn.style.display = (u && !u.isDefault) ? 'inline-block' : 'none';
    /* Preview visible solo cuando hay usuario reconocido. */
    userPreview.classList.toggle('visible', !!u);
    if (userNotFound) userNotFound.style.display = 'none';
}

/* =========================
   DETECCIÓN POR NOMBRE
========================= */

let _lastDetectedKey = null;
function handleUsernameInput()
{
    var u = findUserByName(usernameInput.value);
    var key = u ? u.key : null;
    if (key === _lastDetectedKey) {
        /* Sin cambio real de usuario reconocido: solo limpia el aviso de
           "no encontrado" si ahora hay match. */
        if (u && userNotFound) userNotFound.style.display = 'none';
        return;
    }
    _lastDetectedKey = key;
    applyDetectedUser(u);
}
usernameInput.addEventListener('input', handleUsernameInput);
/* Estado inicial: si el server precargó un usuario (reload tras password
   incorrecta), sincroniza la detección con el nombre ya escrito. */
if (usernameInput.value.trim()) {
    _lastDetectedKey = (findUserByName(usernameInput.value) || {}).key || null;
    detectedUser = findUserByName(usernameInput.value);
}

/* Guard de envío: no enviar si el usuario escrito no coincide con ninguno
   (evita un POST que el servidor descartaría y reiniciaría la pantalla). */
document.getElementById('loginForm').addEventListener('submit', function(e) {
    var u = findUserByName(usernameInput.value);
    if (!u) {
        e.preventDefault();
        if (userNotFound) userNotFound.style.display = '';
        usernameInput.focus();
        return;
    }
    /* Asegura que el user_key viaje correcto aunque el input cambiara
       sin disparar 'input' (autocompletado del navegador, etc.). */
    selectedUserInput.value = u.key;
});

/* ─── INTERFAZ DEL USUARIO (login.css scoped) ────────────────────────
   Carga/quita dinámicamente el login.css de la interfaz del usuario
   seleccionado. Aplica solo dentro de #userPreview (la ventana de
   password) — el resto del index.php queda intacto en Win98.
   Mismo patrón que applyUserTheme. */
function applyUserInterfaceLogin(cssUrl) {
    var link = document.getElementById('user-interface-link');
    if (!cssUrl) {
        if (link) link.remove();
        return;
    }
    if (!link) {
        link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id  = 'user-interface-link';
        document.head.appendChild(link);
    }
    /* Cache-buster por si el CSS cambió */
    link.href = cssUrl + (cssUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
}

/* =========================
   BORRAR USUARIO
========================= */
document.getElementById('deleteUser').addEventListener('click', function() {
    var userKey = selectedUserInput.value;
    var label   = (detectedUser && detectedUser.label) || usernameInput.value;
    if (!userKey || userKey === 'user1' || userKey === 'user2') return;
    var pwdInput = document.querySelector('input[name="password"]');
    var pwd = pwdInput ? pwdInput.value : '';
    if (!pwd) { alert('Introduce la contraseña para eliminar la cuenta.'); if (pwdInput) pwdInput.focus(); return; }
    win98Confirm(
        '¿Eliminar la cuenta de "' + label + '"?\nTodos sus datos (perfil, listas, mensajes) se borrarán de forma permanente.',
        'Eliminar cuenta',
        function() {
            var fd = new FormData();
            fd.append('user', userKey);
            fd.append('password', pwd);
            fetch('delete-user.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d || d.error) { alert((d && d.error) ? d.error : 'Error'); return; }
                    window.location.href = 'index.php?nointro=1';
                })
                .catch(function() { alert('Error de red'); });
        }
    );
});

/* =========================
   REGISTRO DE USUARIO
========================= */

const registerWindow = document.getElementById('registerWindow');
const registerForm   = document.getElementById('registerForm');
const registerStatus = document.getElementById('registerStatus');
const registerSubmit = document.getElementById('registerSubmit');

document.getElementById('openRegister').addEventListener('click', function() {
    /* Limpia el estado de login: vacía el campo usuario, oculta la foto
       de perfil y devuelve el fondo/tema al default. */
    usernameInput.value = '';
    var pwd = document.querySelector('input[name="password"]');
    if (pwd) pwd.value = '';
    _lastDetectedKey = null;
    applyDetectedUser(null);
    loginWindow.classList.remove('visible');
    registerWindow.classList.remove('hidden');
    registerWindow.classList.add('visible');
    registerStatus.style.display = 'none';
    registerStatus.textContent = '';
});

document.getElementById('registerPhotoBrowse').addEventListener('click', function() {
    document.getElementById('registerPhoto').click();
});
document.getElementById('registerPhoto').addEventListener('change', function() {
    document.getElementById('registerPhotoName').value = this.files.length ? this.files[0].name : '';
});

document.getElementById('registerCancel').addEventListener('click', function() {
    registerWindow.classList.remove('visible');
    registerWindow.classList.add('hidden');
    loginWindow.classList.add('visible');
    registerForm.reset();
});

registerForm.addEventListener('submit', function(e) {
    e.preventDefault();
    registerStatus.style.display = 'none';
    registerSubmit.disabled = true;
    var fd = new FormData(registerForm);
    fetch('register-user.php', { method: 'POST', body: fd })
        .then(function(r) {
            return r.text().then(function(body) {
                /* Si no es 2xx o el body no es JSON parseable, lo enseñamos
                   tal cual (truncado) para ver el error real del servidor. */
                if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + body.slice(0, 200));
                try { return JSON.parse(body); }
                catch (err) { throw new Error('Respuesta no JSON: ' + body.slice(0, 200)); }
            });
        })
        .then(function(d) {
            registerSubmit.disabled = false;
            if (!d || d.error) {
                registerStatus.textContent = (d && d.error) ? d.error : 'Error';
                registerStatus.style.display = '';
                return;
            }
            /* Recargar para que la lista de usuarios incluya al nuevo */
            window.location.href = 'index.php?nointro=1';
        })
        .catch(function(err) {
            registerSubmit.disabled = false;
            registerStatus.textContent = err.message || 'Error de red';
            registerStatus.style.display = '';
        });
});

/* =========================
   POPUP AD
========================= */

const adPopup = document.getElementById('ad-popup');

if (adPopup) {
    const adClose = document.getElementById('ad-close');
    const adBody  = document.getElementById('ad-body');
    const adUrl   = 'https://www.youtube.com/@melondeaguaarchive/playlists';

    const adImg      = document.getElementById('ad-image');
    const adAllImages = <?php echo json_encode(array_values($adImages)); ?>;
    const corners = [
        { top: true,  left: true,  anim: 'adFromTL' },
        { top: true,  left: false, anim: 'adFromTR' },
        { top: false, left: true,  anim: 'adFromBL' },
        { top: false, left: false, anim: 'adFromBR' },
    ];

    function placeAd() {
        const c   = corners[Math.floor(Math.random() * 4)];
        const gap = 80 + Math.floor(Math.random() * 80);
        const taskbar = 50;

        adPopup.style.top    = 'auto';
        adPopup.style.left   = 'auto';
        adPopup.style.right  = 'auto';
        adPopup.style.bottom = 'auto';

        if (c.top)  adPopup.style.top    = gap + 'px';
        else        adPopup.style.bottom = (gap + taskbar) + 'px';
        if (c.left) adPopup.style.left   = gap + 'px';
        else        adPopup.style.right  = gap + 'px';

        adPopup.style.animation = 'none';
        adPopup.offsetHeight; /* reflow para reiniciar */
        adPopup.style.animation = c.anim + ' 0.35s ease-out forwards';
    }

    function pixelate(img) {
        if (img.dataset.px) return;
        img.dataset.px = '1';
        const factor = 0.15;
        const w = Math.max(1, Math.round(img.naturalWidth  * factor));
        const h = Math.max(1, Math.round(img.naturalHeight * factor));
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        const ctx = c.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(img, 0, 0, w, h);
        img.src = c.toDataURL('image/png');
    }

    adImg.addEventListener('load', function() { pixelate(adImg); });
    if (adImg.complete && adImg.naturalWidth) pixelate(adImg);

    function showAd() {
        if (adAllImages.length) {
            const newSrc = adAllImages[Math.floor(Math.random() * adAllImages.length)];
            delete adImg.dataset.px;
            adImg.src = newSrc;
        }
        placeAd();
        adPopup.style.display = 'block';
    }

    adClose.addEventListener('click', function(e) {
        e.stopPropagation();
        adPopup.style.display = 'none';
    });

    adBody.addEventListener('click', function() {
        window.open(adUrl, '_blank');
    });

    /* Arrastrar */
    (function() {
        const bar = document.getElementById('ad-titlebar');
        let dragging = false, ox, oy;
        bar.addEventListener('mousedown', function(e) {
            if (e.target.tagName === 'BUTTON') return;
            dragging = true;
            const rect = adPopup.getBoundingClientRect();
            adPopup.style.animation = 'none';
            adPopup.style.top    = rect.top  + 'px';
            adPopup.style.left   = rect.left + 'px';
            adPopup.style.bottom = 'auto';
            adPopup.style.right  = 'auto';
            ox = e.clientX - rect.left;
            oy = e.clientY - rect.top;
        });
        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            adPopup.style.left = (e.clientX - ox) + 'px';
            adPopup.style.top  = (e.clientY - oy) + 'px';
        });
        document.addEventListener('mouseup', () => { dragging = false; });
    })();

    /* Mostrar el ad al reconocer un usuario (transición sin-usuario →
       usuario). No re-dispara mientras se sigue editando el mismo. */
    const _origApplyDetected = applyDetectedUser;
    applyDetectedUser = function(u) {
        var had = !!detectedUser;
        _origApplyDetected(u);
        if (u && !had) setTimeout(showAd, 400);
        else if (!u) adPopup.style.display = 'none';
    };

    <?php if ($showLogin): ?>
    showAd();
    <?php endif; ?>
}

/* ========================
   FATAL ERROR POPUPS
======================== */
(function() {
    var msgs = [
        'SOUL.EXE stopped working.\nPlease select a user to continue.',
        'User not responding.\nIs your body still yours?',
        'Biometric data confusing. \nTry to remember who you are.',
        'Integrated GPS failure.\nInfinity cannot be measured.',
        'Unauthorised presence.\nLog in as fast as possible.',
        'Connection to reality lost.\nPlease select a user to reconnect.',
        'Consciousness leaking.\nEncapsulate yourself.',
        'Questioning existence.\n Think to stabilize.',
        '"I" is not a valid user.\nPlease select yourself to proceed.',
        'Hostile environment detected.\nPlease flee.',
        'Kernel panic - not fitting: Attempted to kill init!\n Task failed.',
        'const self = null;\nError: Cannot set property "self" of null at line 42.',
        'booting failed: no operating system found.\nPlease select a user to load your world.',
        'Module human.exe failed to initialize.\n Check the list to proceed.',
    ];

    var active = false;
    var timer  = null;

    function createPopup() {
        var msg = msgs[Math.floor(Math.random() * msgs.length)];
        var el  = document.createElement('div');
        el.className = 'error-popup window';
        el.innerHTML =
            '<div class="title-bar">' +
                '<div class="title-bar-text"><span class="ep-title-icon">✕</span> !FATAL ERROR</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" class="ep-close-btn"></button></div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="ep-body">' +
                    '<div class="ep-big-icon">✕</div>' +
                    '<p class="ep-msg">' + msg.replace('\n', '<br>') + '</p>' +
                '</div>' +
                '<div class="ep-actions"><button class="button ep-ok-btn">OK</button></div>' +
            '</div>';

        var maxX = Math.max(50, window.innerWidth  - 290);
        var maxY = Math.max(50, window.innerHeight - 180);
        el.style.left = (20 + Math.floor(Math.random() * maxX)) + 'px';
        el.style.top  = (20 + Math.floor(Math.random() * maxY)) + 'px';

        document.body.appendChild(el);
        setTimeout(function() { el.classList.add('ep-visible'); }, 10);

        function close() { el.classList.remove('ep-visible'); setTimeout(function() { el.remove(); }, 180); }
        el.querySelector('.ep-close-btn').addEventListener('click', close);
        el.querySelector('.ep-ok-btn').addEventListener('click', close);
    }

    function schedule() {
        if (!active) return;
        var delay = timer === null ? 8000 : (6000 + Math.random() * 5000);
        timer = setTimeout(function() { if (!active) return; createPopup(); schedule(); }, delay);
    }

    window.startErrorPopups = function() {
        if (active) return;
        active = true;
        timer = null;
        schedule();
    };

    window.stopErrorPopups = function() {
        active = false;
        clearTimeout(timer);
        timer = null;
        document.querySelectorAll('.error-popup').forEach(function(p) { p.remove(); });
    };
})();

<?php if (!$showLogin): ?>startErrorPopups();<?php endif; ?>

/* Los fatal-error popups suenan mientras no hay usuario reconocido; se
   paran al detectar uno y se reanudan al limpiar el formulario. */
const _origApplyDetected2 = applyDetectedUser;
applyDetectedUser = function(u) {
    _origApplyDetected2(u);
    if (u) stopErrorPopups();
    else   setTimeout(startErrorPopups, 400);
};
</script>

</body>
</html>
