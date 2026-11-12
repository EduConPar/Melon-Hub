<?php
/* ──────────────────────────────────────────────────────────────────────
   LOGIN MANUAL — username + password en TEXTO
   ──────────────────────────────────────────────────────────────────────
   Variante de index.php pensada para "cambiar de cuenta" desde el menú
   de ajustes del móvil. El usuario escribe su nombre y contraseña en
   inputs de texto (sin picker de avatares).

   Compatible con el resto del sistema: misma tabla `usuarios`, mismo
   bcrypt, misma sesión PHP. Resuelve el `user_key` buscando por `label`
   (case-insensitive).

   El look replica index.php: 98.css + login.css + tokens.css + themes
   + filtro LCD/VHS inline + wallpaper de fondo. Misma fuente VT323.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once __DIR__ . '/assets/config.php';

$projectBaseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
if ($projectBaseUrl === '/') $projectBaseUrl = '/';

$enteredUsername = '';
$error           = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $enteredUsername = $username;

    if ($username === '' || $password === '') {
        $error = true;
    } else {
        $matchedKey = null;
        foreach ($loginUsers as $key => $u) {
            if (strcasecmp($u['label'], $username) === 0) {
                $matchedKey = $key; break;
            }
        }
        if ($matchedKey !== null && password_verify($password, $loginUsers[$matchedKey]['password'])) {
            $_SESSION['user'] = $matchedKey;
            require_once __DIR__ . '/db.php';
            require_once __DIR__ . '/assets/remember-login.php';
            rememberLogin($pdo, $matchedKey);
            /* Self-heal del stub si fue borrado: lo regeneramos antes del
               redirect para que el usuario aterrice en su escritorio en
               vez de en un 404. */
            ensureDesktopStub($loginUsers[$matchedKey]['label']);
            $target = isMobileDevice()
                ? 'mobile.php'
                : 'desktops/' . strtolower($loginUsers[$matchedKey]['label']) . '-desktop.php';
            header('Location: ' . $target);
            exit;
        }
        $error = true;
    }
}

$baseWallpaper = '';
foreach (['png','jpg','jpeg','webp','gif'] as $ext) {
    if (file_exists(__DIR__ . "/assets/img/wallpapers/base-wallpaper.{$ext}")) {
        $baseWallpaper = 'assets/img/wallpapers/base-wallpaper.' . $ext;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <!-- Color de las barras del sistema en la app de Android (MainActivity) y en Chrome. -->
    <meta name="theme-color" content="#000000">
    <title>Iniciar sesión — Scrapbook Melon</title>
    <link rel="icon" href="assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/themes.css">
    <!-- Filtro LCD/VHS: scanlines + viñeta (idéntico a index.php) -->
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
    <style>body::before{ background-image:url('<?php echo htmlspecialchars($baseWallpaper); ?>'); opacity:1; }</style>
    <?php endif; ?>
    <!-- Paleta "consola roja" idéntica a mobile-landing.php para que el
         look sea uniforme al volver de cerrar sesión. Reusamos el id
         #installWindow porque mobile-landing ya define los estilos para
         ese selector — aquí solo los duplicamos para que login-manual
         sea autosuficiente (no hay shared partial CSS). -->
    <style>
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
            font-size: 16px; /* iOS NO hace auto-zoom si font-size >=16px. */
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
        /* Hero: icono Melon + título VT323, igual que mobile-landing. */
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
        .mh-error {
            color: #ff8080;
            background: #2a0c0c;
            border: 1px solid #5a0000;
            padding: 4px 8px;
            font-size: 11px;
            margin: 8px 0;
        }
        .mh-cta-full { width: 100%; margin-top: 10px; min-height: 32px; font-size: 13px; }
        /* Link "Crear cuenta nueva" abajo del form, pequeño, en rojo
           tenue para no competir con el CTA principal. */
        .mh-altlink {
            display: block;
            text-align: center;
            margin-top: 12px;
            font-size: 11px;
            color: #d4a0a0;
            text-decoration: underline;
            text-decoration-style: dotted;
            text-underline-offset: 3px;
        }
        .mh-altlink:visited { color: #d4a0a0; }
        .mh-altlink:hover { color: #fff; }
    </style>
</head>

<body>

<div class="login-window">

    <!-- LOGIN MANUAL — username + password.
         id="installWindow" hereda toda la paleta "consola roja" de
         mobile-landing.php para que el look sea idéntico al volver de
         cerrar sesión. -->
    <div class="window" id="installWindow">
        <div class="title-bar">
            <div class="title-bar-text">Iniciar sesión</div>
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
            <form method="POST" autocomplete="on">
                <div class="field-row-stacked">
                    <label>Usuario</label>
                    <input type="text" name="username" autocomplete="username"
                           value="<?php echo htmlspecialchars($enteredUsername); ?>" autofocus required>
                </div>
                <div class="field-row-stacked" style="margin-top:8px;">
                    <label>Contraseña</label>
                    <input type="password" name="password" autocomplete="current-password" required>
                </div>
                <?php if ($error): ?>
                <p class="mh-error">Usuario o contraseña incorrectos.</p>
                <?php endif; ?>
                <div class="login-actions" style="margin-top:12px;">
                    <button class="button default mh-cta-full" type="submit">Ingresar</button>
                </div>
            </form>
            <a class="mh-altlink" href="mobile-landing.php">¿No tienes cuenta? Crear una nueva</a>
        </div>
    </div>

</div>

</body>
</html>
