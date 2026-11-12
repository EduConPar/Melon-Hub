<?php
/* ──────────────────────────────────────────────────────────────────────
   TV — la música suena aquí y el móvil hace de mando (reproductor → 📺).
   ──────────────────────────────────────────────────────────────────────
   Versión con CSS/JS legacy para Smart TVs viejos (Tizen 2017+, webOS
   3+, Android TV con Chrome 50+). Sin aspect-ratio, sin inset, sin
   clamp(), sin min(). Solo features con 8+ años de soporte estable.
   ────────────────────────────────────────────────────────────────────── */
/* Fuerza HTTP plano cuando se accede por IP LAN.
   Si la TV abre https://192.168.1.18/... acepta el cert self-signed
   de XAMPP para la página, pero el iframe interno se rompe porque cada
   nuevo request al endpoint también valida el cert (y muchos browsers
   de TV no extienden la excepción a subresources). Forzando HTTP
   evitamos todo TLS. Solo aplica si HOST es una IP privada — no rompe
   producción en dominios reales con cert válido. */
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostNoPort = preg_replace('/:\d+$/', '', $host);
    $isPrivateIp = (bool) preg_match(
        '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.|169\.254\.)/',
        $hostNoPort
    );
    if ($isPrivateIp) {
        /* Limpia cualquier HSTS para que el redirect a HTTP se obedezca. */
        header('Strict-Transport-Security: max-age=0');
        header('Location: http://' . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

session_start();
require_once __DIR__ . '/assets/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/assets/remember-login.php';
require_once __DIR__ . '/assets/music/tv-lib.php';

/* ──────────────────────────────────────────────────────────────────────
   La TV suena y lleva la cola; el móvil (reproductor → 📺) es el mando.
   Se hablan con archivos estáticos de rt/tv/ (assets/music/tv-lib.php):
   la TV mira sus órdenes cada ~0,75 s sin gastar PHP y solo llama a
   tv-api.php para contar cómo va (al cambiar algo y un latido cada
   20–60 s). Sin órdenes del móvil, espera ("Esperando música").
   ────────────────────────────────────────────────────────────────────── */

/* Cierre de sesión de la TV: GET ?logout=1 (el botón "Desconectar"). */
if (isset($_GET['logout'])) {
    forgetLogin($pdo);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    header('Location: tv.php');
    exit;
}

/* El hosting borra las sesiones mucho antes de 30 días: la TV vuelve a
   entrar con su sesión recordada. */
if (empty($_SESSION['user'])) restoreRememberedLogin($pdo);
$loggedIn = isset($_SESSION['user']) && isset($loginUsers[$_SESSION['user']]);

/* ── Emparejar ──
   La TV sin sesión enseña un QR (enlace.php?tv=<token>, lo abre la app
   del móvil) y un código de 6 cifras (se teclea en el móvil, ventana
   📺). El móvil lo aprueba (tv-api.php pair) y escribe ok en el archivo
   de emparejamiento de rt/tv/, que la TV mira cada 2 s; entonces vuelve
   aquí con ?emparejar=1 y entra si el token de SU sesión está aprobado. */
$par = null;
if (!$loggedIn) {
    try {
        tv_esquema($pdo);
        if (isset($_GET['emparejar']) && !empty($_SESSION['tv_par'])) {
            $q = $pdo->prepare("SELECT user_key FROM tv_pairs WHERE token = ? AND user_key IS NOT NULL
                                AND created_at > NOW() - INTERVAL " . TV_PAR_MINUTOS . " MINUTE");
            $q->execute([$_SESSION['tv_par']]);
            $k = (string)$q->fetchColumn();
            if ($k !== '' && isset($loginUsers[$k])) {
                $pdo->prepare("DELETE FROM tv_pairs WHERE token = ?")->execute([$_SESSION['tv_par']]);
                @unlink(__DIR__ . '/' . tv_urlPar($_SESSION['tv_par']));
                unset($_SESSION['tv_par']);
                session_regenerate_id(true);
                $_SESSION['user'] = $k;
                rememberLogin($pdo, $k);
                header('Location: tv.php');
                exit;
            }
        }
        /* Limpieza de lo caducado (tabla y archivos). */
        $pdo->exec("DELETE FROM tv_pairs WHERE created_at < NOW() - INTERVAL 30 MINUTE");
        foreach (glob(__DIR__ . '/rt/tv/p-*.html') ?: [] as $f) {
            if (@filemtime($f) < time() - 3600) @unlink($f);
        }
        $token = bin2hex(random_bytes(12));
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code = sprintf('%06d', random_int(0, 999999));
            $q = $pdo->prepare("SELECT 1 FROM tv_pairs WHERE code = ?");
            $q->execute([$code]);
            if (!$q->fetchColumn()) break;
        }
        $pdo->prepare("INSERT INTO tv_pairs (token, code) VALUES (?, ?)")->execute([$token, $code]);
        $_SESSION['tv_par'] = $token;
        tv_escribir(tv_urlPar($token), ['ok' => false]);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'melonhub.es')
              . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
        $par = [
            'code' => $code,
            'qr'   => $base . 'enlace.php?tv=' . $token,
            'rt'   => tv_urlPar($token),
        ];
    } catch (Throwable $e) {
        error_log('[tv] emparejar: ' . $e->getMessage());
    }
}
/* La sesión no hace falta más: que no bloquee las llamadas de la TV. */
session_write_close();

$userLabel = $loggedIn ? $loginUsers[$_SESSION['user']]['label'] : '';
$rt = null;
if ($loggedIn) {
    $q = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $q->execute([$_SESSION['user']]);
    $uid = (int)$q->fetchColumn();
    $rt = [
        'ordenes' => tv_urlOrdenes($uid),
        'cola'    => tv_urlCola($uid),
        'estado'  => tv_urlEstado($uid),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $loggedIn ? '♪ TV — ' . htmlspecialchars($userLabel) : 'Conectar TV' ?></title>
    <link rel="icon" href="assets/img/mobile/icon.png" type="image/png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            width: 100%;
            height: 100%;
            background: #050505;
            color: #fff;
            font-family: Arial, Helvetica, sans-serif;
            overflow: hidden;
        }

        /* IFrame de YouTube oculto detrás del fondo. La TV reproduce el
           audio aquí. El video se ve si quitas el .tv-bg, pero por
           defecto queda tapado por el fondo difuminado y el overlay. */
        .tv-player-wrap {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 0;
            overflow: hidden;
            background: #050505;
        }
        .tv-player-wrap iframe,
        .tv-player-wrap #tv-player {
            position: absolute;
            top: -10%; left: -10%;
            width: 120%; height: 120%;
            border: 0;
        }

        /* Fondo difuminado con el cover del track. */
        .tv-bg {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #050505;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            -webkit-filter: blur(60px) saturate(1.5) brightness(0.4);
            filter: blur(60px) saturate(1.5) brightness(0.4);
            z-index: 1;
        }
        .tv-bg-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 2;
        }

        /* Layout principal. */
        .tv-stage {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 3;
            display: none;
            text-align: center;
            padding: 5%;
        }
        .tv-stage.active { display: block; }

        /* Cover circular del track. Fijo a 40vmin (40% del lado más corto)
           para que funcione en cualquier ratio sin aspect-ratio. */
        .tv-cover {
            display: inline-block;
            width: 40vmin;
            height: 40vmin;
            background-color: #111;
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
            border-radius: 50%;
            margin: 5vh auto 4vh;
            box-shadow: 0 20px 50px rgba(0,0,0,0.7);
            position: relative;
            -webkit-animation: tv-spin 18s linear infinite;
                    animation: tv-spin 18s linear infinite;
            -webkit-animation-play-state: paused;
                    animation-play-state: paused;
        }
        .tv-cover.playing {
            -webkit-animation-play-state: running;
                    animation-play-state: running;
        }
        @-webkit-keyframes tv-spin {
            0% { -webkit-transform: rotate(0deg); }
            100% { -webkit-transform: rotate(360deg); }
        }
        @keyframes tv-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Agujero del vinilo (centro). */
        .tv-cover-hole {
            position: absolute;
            top: 43%; left: 43%;
            width: 14%; height: 14%;
            background: #000;
            border-radius: 50%;
            box-shadow: 0 0 0 5px rgba(0,0,0,0.7);
        }

        /* Textos. */
        .tv-now {
            font-size: 14px;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            letter-spacing: 4px;
            margin-bottom: 14px;
        }
        .tv-title {
            font-size: 36px;
            font-weight: bold;
            color: #fff;
            line-height: 1.2;
            margin-bottom: 14px;
            padding: 0 5%;
        }
        .tv-artist {
            font-size: 22px;
            color: rgba(255,255,255,0.78);
            margin-bottom: 10px;
        }
        .tv-playlist {
            font-size: 15px;
            color: rgba(255,255,255,0.55);
            margin-bottom: 24px;
        }

        /* Barra de progreso. */
        .tv-progress {
            width: 70%;
            margin: 0 auto;
        }
        .tv-progress-bar {
            width: 100%;
            height: 5px;
            background: rgba(255,255,255,0.18);
            border-radius: 3px;
            overflow: hidden;
        }
        .tv-progress-fill {
            height: 100%;
            background: #fff;
            width: 0%;
        }
        .tv-progress-times {
            margin-top: 6px;
            font-size: 13px;
            color: rgba(255,255,255,0.5);
        }
        .tv-progress-times .right { float: right; }

        /* Estado idle (esperando). */
        .tv-idle {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #000;
            z-index: 4;
            text-align: center;
        }
        .tv-idle.hidden { display: none; }
        .tv-idle-inner {
            position: absolute;
            top: 50%; left: 50%;
            -webkit-transform: translate(-50%, -50%);
                    transform: translate(-50%, -50%);
            width: 80%;
        }
        .tv-idle-icon {
            font-size: 100px;
            color: rgba(255,255,255,0.25);
            margin-bottom: 20px;
            -webkit-animation: tv-pulse 3s ease-in-out infinite;
                    animation: tv-pulse 3s ease-in-out infinite;
        }
        .tv-idle-title {
            font-size: 24px;
            color: rgba(255,255,255,0.7);
            font-weight: normal;
            margin-bottom: 12px;
        }
        .tv-idle-sub {
            font-size: 14px;
            color: rgba(255,255,255,0.4);
        }
        @-webkit-keyframes tv-pulse {
            0%, 100% { opacity: 0.25; }
            50% { opacity: 0.5; }
        }
        @keyframes tv-pulse {
            0%, 100% { opacity: 0.25; }
            50% { opacity: 0.5; }
        }

        /* Badge del usuario en esquina. */
        .tv-user {
            position: fixed;
            top: 18px; right: 24px;
            z-index: 5;
            font-size: 13px;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .tv-user-dot {
            display: inline-block;
            width: 9px; height: 9px;
            background: #ff3b30;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }
        .tv-user.connected .tv-user-dot { background: #34c759; }

        .tv-logout {
            position: fixed;
            top: 18px; left: 22px;
            z-index: 5;
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(255,255,255,0.72);
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 9px 18px 9px 14px;
            border-radius: 999px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(0,0,0,0.35);
            -webkit-transition: color .2s, background .2s, border-color .2s, -webkit-transform .2s;
                    transition: color .2s, background .2s, border-color .2s, transform .2s;
        }
        .tv-logout-icon {
            display: inline-block;
            width: 14px; height: 14px;
            margin-right: 8px;
            vertical-align: -2px;
            border: 1.5px solid currentColor;
            border-radius: 50%;
            position: relative;
        }
        .tv-logout-icon::after {
            content: '';
            position: absolute;
            top: -3px; left: 50%;
            width: 1.5px; height: 7px;
            margin-left: -0.75px;
            background: currentColor;
        }
        .tv-logout:hover, .tv-logout:focus {
            color: #fff;
            background: rgba(255,68,68,0.22);
            border-color: rgba(255,68,68,0.7);
            outline: none;
            -webkit-transform: translateY(-1px);
                    transform: translateY(-1px);
        }

        /* Pantallas pequeñas (móvil/tablet vertical): textos un poco más
           pequeños. */
        @media (max-width: 600px) {
            .tv-title { font-size: 22px; }
            .tv-artist { font-size: 16px; }
            .tv-cover { width: 60vmin; height: 60vmin; }
        }

        /* ── Emparejar (cuando no hay sesión): QR + código ── */
        .tv-pair {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #0a0a0a;
            text-align: center;
        }
        .tv-pair-inner {
            position: absolute;
            top: 50%; left: 50%;
            -webkit-transform: translate(-50%, -50%);
                    transform: translate(-50%, -50%);
            width: 90%;
            max-width: 900px;
        }
        .tv-pair-title {
            font-size: 34px;
            font-weight: bold;
            color: #fff;
            margin-bottom: 30px;
        }
        /* Dos columnas con inline-block (sin flex: TVs viejas). */
        .tv-pair-col {
            display: inline-block;
            vertical-align: middle;
            width: 44%;
            margin: 0 2%;
        }
        .tv-pair-qr {
            display: inline-block;
            background: #fff;
            padding: 14px;
            border-radius: 10px;
            line-height: 0;
        }
        .tv-pair-qr img {
            width: 250px;
            height: 250px;
            image-rendering: pixelated;
        }
        .tv-pair-step {
            font-size: 17px;
            color: rgba(255,255,255,0.75);
            line-height: 1.5;
            margin-top: 16px;
        }
        .tv-pair-or {
            font-size: 14px;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            letter-spacing: 4px;
            margin-bottom: 18px;
        }
        .tv-pair-code {
            font-family: Consolas, Monaco, monospace;
            font-size: 64px;
            font-weight: bold;
            color: #fff;
            letter-spacing: 8px;
            background: #1c1c1c;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 18px 10px;
        }
        .tv-pair-hint {
            font-size: 13px;
            color: rgba(255,255,255,0.4);
            margin-top: 34px;
            line-height: 1.55;
        }
        .tv-pair-err {
            font-size: 18px;
            color: #ff5546;
        }

        /* Aviso cuando YouTube bloquea la reproducción en embed (errores
           101/150). El audio se queda en el móvil, la TV sigue mostrando
           carátula y título pero indica que no puede reproducirlo. */
        .tv-err-banner {
            display: none;
            margin: 0 auto 14px;
            max-width: 80%;
            background: rgba(255,68,68,0.22);
            border: 1px solid rgba(255,68,68,0.7);
            color: #ff8080;
            font-size: 14px;
            padding: 10px 16px;
            border-radius: 6px;
            letter-spacing: 1px;
        }
        .tv-err-banner.visible { display: block; }
    </style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- Emparejar: QR (lo abre la app del móvil) o código (se teclea en el
     móvil, reproductor → 📺). -->
<div class="tv-pair">
    <div class="tv-pair-inner">
        <div class="tv-pair-title">📺 Conecta tu TV</div>
        <?php if ($par): ?>
        <div class="tv-pair-col">
            <div class="tv-pair-qr" id="tv-pair-qr"></div>
            <div class="tv-pair-step">Escanéalo con la cámara del móvil</div>
        </div><div class="tv-pair-col">
            <div class="tv-pair-or">o en la app</div>
            <div class="tv-pair-code"><?= substr($par['code'], 0, 3) . ' ' . substr($par['code'], 3) ?></div>
            <div class="tv-pair-step">Reproductor → 📺 → escribe este código</div>
        </div>
        <div class="tv-pair-hint">
            Una vez conectada, esta TV se queda recordada.
        </div>
        <?php else: ?>
        <div class="tv-pair-err">No se ha podido preparar el emparejamiento. Recarga la página.</div>
        <?php endif; ?>
    </div>
</div>
<?php if ($par): ?>
<script src="assets/vendor/qrcode-generator/qrcode.js"></script>
<script>
(function(){
    /* QR del enlace (tipo 0 = el tamaño que haga falta; corrección M). */
    try {
        var qr = qrcode(0, 'M');
        qr.addData(<?= json_encode($par['qr']) ?>);
        qr.make();
        document.getElementById('tv-pair-qr').innerHTML = qr.createImgTag(6, 0);
    } catch (e) {}

    /* ¿Lo ha aprobado el móvil? Archivo estático de rt/tv/ cada 2 s, con un
       iframe (como el resto de la TV: XHR falla en algunas tras la primera
       petición). */
    var url = <?= json_encode($par['rt']) ?>;
    var f = document.createElement('iframe');
    f.style.cssText = 'position:absolute;width:1px;height:1px;border:0;left:-9999px;top:-9999px;visibility:hidden';
    var marca = '';
    f.onload = function(){
        /* Solo la carga que se ha pedido (no la página en blanco). */
        var aqui = '';
        try { aqui = String(f.contentWindow.location.href); } catch (e) {}
        if (!marca || aqui.indexOf(marca) === -1) return;
        marca = '';
        var ok = false;
        try {
            var pre = f.contentDocument.getElementById('d');
            ok = !!JSON.parse(pre.textContent || pre.innerText || '{}').ok;
        } catch (e) {}
        if (ok) { location.replace('tv.php?emparejar=1'); return; }
        setTimeout(mirar, 2000);
    };
    document.body.appendChild(f);
    function mirar(){
        marca = '_=' + (new Date()).getTime();
        f.src = url + '?' + marca;
        /* Si no carga (red), se vuelve a probar. */
        var esta = marca;
        setTimeout(function(){ if (marca === esta) mirar(); }, 8000);
    }
    mirar();
    /* El QR y el código caducan: uno nuevo antes de que pase. */
    setTimeout(function(){ location.reload(); }, (<?= TV_PAR_MINUTOS ?> * 60 - 30) * 1000);
})();
</script>
<?php endif; ?>
<?php else: ?>

<!-- IFrame de YouTube — emite el audio por los altavoces de la TV.
     Detrás del fondo difuminado (z-index 0). YT.Player lo reemplaza
     por un iframe real cuando la API termina de cargar. -->
<div class="tv-player-wrap"><div id="tv-player"></div></div>

<div class="tv-bg" id="tv-bg"></div>
<div class="tv-bg-overlay"></div>
<div class="tv-user connected" id="tv-user"><span class="tv-user-dot"></span><?= htmlspecialchars($userLabel) ?></div>
<a class="tv-logout" href="tv.php?logout=1"><span class="tv-logout-icon"></span>Desconectar</a>

<!-- Esperando: hasta que el móvil mande algo (reproductor → 📺). -->
<div class="tv-idle" id="tv-idle">
    <div class="tv-idle-inner">
        <div class="tv-idle-icon">♪</div>
        <div class="tv-idle-title">Esperando música</div>
        <div class="tv-idle-sub">En el móvil: reproductor → 📺</div>
    </div>
</div>

<!-- Sonando. -->
<div class="tv-stage" id="tv-stage">
    <div class="tv-cover" id="tv-cover">
        <div class="tv-cover-hole"></div>
    </div>
    <div class="tv-err-banner" id="tv-err-banner"></div>
    <div class="tv-now">Reproduciendo</div>
    <div class="tv-title"   id="tv-title">—</div>
    <div class="tv-artist"  id="tv-artist">—</div>
    <div class="tv-playlist" id="tv-playlist"></div>
    <div class="tv-progress">
        <div class="tv-progress-bar"><div class="tv-progress-fill" id="tv-progress-fill"></div></div>
        <div class="tv-progress-times">
            <span id="tv-time-cur">0:00</span>
            <span class="right" id="tv-time-tot">0:00</span>
        </div>
    </div>
</div>

<script>
/* Solo ES5 y nada moderno: navegadores de TVs viejas (Tizen 2017+,
   webOS 3+, Android TV con Chrome 50+). */
var RT  = <?= json_encode($rt) ?>;
var API = 'assets/music/tv-api.php';
/* Diferencia entre el reloj del servidor y el de la TV (ms). */
var RELOJ = <?= tv_ahora() ?> - now();

function now() { return Date.now ? Date.now() : (new Date()).getTime(); }

var bgEl      = document.getElementById('tv-bg');
var coverEl   = document.getElementById('tv-cover');
var titleEl   = document.getElementById('tv-title');
var artistEl  = document.getElementById('tv-artist');
var plEl      = document.getElementById('tv-playlist');
var fillEl    = document.getElementById('tv-progress-fill');
var curEl     = document.getElementById('tv-time-cur');
var totEl     = document.getElementById('tv-time-tot');
var idleEl    = document.getElementById('tv-idle');
var stageEl   = document.getElementById('tv-stage');
var errBanner = document.getElementById('tv-err-banner');

/* ── Lo que suena ── */
var ON = false;          /* el móvil la está usando (si no: "Esperando") */
var Q = [], IDX = -1, PL = '', SHUFFLE = false;
var QV = '';             /* versión de la cola cargada (rt …-q.html) */
var QUIERE_SONAR = false;
var ULTIMA = 0;          /* última orden hecha (n) */

/* ── Leer archivos de rt/ ──
   Un solo iframe oculto, una lectura detrás de otra: lee el JSON del
   <pre id="d"> del documento cargado. (XHR y <script> dinámicos dejan de
   funcionar tras la primera petición en algunos navegadores de TV.) */
var LECTOR = { f: null, ocupado: false, cola: [], turno: 0 };
function leerRt(url, cb) {
    LECTOR.cola.push({ url: url, cb: cb });
    siguienteLectura();
}
function siguienteLectura() {
    if (LECTOR.ocupado || !LECTOR.cola.length) return;
    var job = LECTOR.cola.shift();
    var turno = ++LECTOR.turno;
    LECTOR.ocupado = true;
    if (!LECTOR.f) {
        LECTOR.f = iframeOculto();
    }
    var hecho = false;
    /* Marca de esta lectura: un onload que no sea el suyo (el de la
       página en blanco al crear el iframe, o uno que llega tarde) no
       cuenta. */
    var marca = '_=' + turno + 'x' + now();
    function fin(datos) {
        if (hecho || turno !== LECTOR.turno) return;
        hecho = true;
        LECTOR.ocupado = false;
        try { job.cb(datos); } catch (e) {}
        setTimeout(siguienteLectura, 0);
    }
    LECTOR.f.onload = function() {
        if (!esCarga(LECTOR.f, marca)) return;
        fin(leerPre(LECTOR.f));
    };
    setTimeout(function(){ fin(null); }, 5000);
    try { LECTOR.f.src = job.url + '?' + marca; } catch (e) { fin(null); }
}
function esCarga(f, marca) {
    try {
        var w = f.contentWindow;
        return String(w.location.href).indexOf(marca) !== -1;
    } catch (e) { return false; }
}
function iframeOculto() {
    var f = document.createElement('iframe');
    f.style.cssText = 'position:absolute;width:1px;height:1px;border:0;left:-9999px;top:-9999px;visibility:hidden';
    f.setAttribute('aria-hidden', 'true');
    document.body.appendChild(f);
    return f;
}
function leerPre(f) {
    try {
        var doc = f.contentDocument || (f.contentWindow && f.contentWindow.document);
        var pre = doc && doc.getElementById ? doc.getElementById('d') : null;
        if (!pre) return null;
        return JSON.parse(pre.textContent || pre.innerText || '');
    } catch (e) { return null; }
}

/* ── Contar cómo va (tv-api.php estado) ──
   Otro iframe; si hay uno en marcha, el siguiente espera y solo se manda
   el último (con "ev" si alguno lo llevaba). ev = ha cambiado algo:
   también lo apunta como "escuchando" del perfil. */
var ESCRITOR = { f: null, ocupado: false, pendiente: false, ev: false, ultimo: 0, sonando: false };
function contarEstado(ev) {
    ESCRITOR.pendiente = true;
    if (ev) ESCRITOR.ev = true;
    enviarEstado();
}
function enviarEstado() {
    if (ESCRITOR.ocupado || !ESCRITOR.pendiente) return;
    if (!ESCRITOR.f) ESCRITOR.f = iframeOculto();
    var d = estadoActual(ESCRITOR.ev);
    ESCRITOR.sonando = d.playing;
    ESCRITOR.pendiente = false;
    ESCRITOR.ev = false;
    ESCRITOR.ocupado = true;
    ESCRITOR.ultimo = now();
    var hecho = false;
    function fin() {
        if (hecho) return;
        hecho = true;
        ESCRITOR.ocupado = false;
        setTimeout(enviarEstado, 0);
    }
    ESCRITOR.f.onload = fin;
    setTimeout(fin, 6000);
    try {
        ESCRITOR.f.src = API + '?action=estado&frame=1&_=' + now() + '&d=' + encodeURIComponent(JSON.stringify(d));
    } catch (e) { fin(); }
}
function estadoActual(ev) {
    var tr = (ON && IDX >= 0) ? Q[IDX] : null;
    return {
        on: ON && tr ? 1 : 0,
        track: tr,
        idx: IDX,
        pl: PL,
        playing: !!(tr && sonando()),
        pos: posicion(),
        dur: tr ? duracion() : 0,
        shuffle: SHUFFLE,
        n: ULTIMA,
        ev: ev ? 1 : 0
    };
}
/* Latido: el móvil sabe que la TV está encendida si su estado tiene
   menos de 2,5 min. Cada 20 s sonando (para que la barra del móvil no
   se desvíe), cada 60 s esperando. */
setInterval(function(){
    var cada = sonando() ? 20000 : 60000;
    if (now() - ESCRITOR.ultimo >= cada - 500) contarEstado(false);
}, 5000);

/* ── Órdenes del móvil ── */
function mirarOrdenes() {
    leerRt(RT.ordenes, function(d){
        var cmds = (d && d.cmds) || [];
        var nuevas = [];
        for (var i = 0; i < cmds.length; i++) {
            if (cmds[i] && cmds[i].n > ULTIMA) nuevas.push(cmds[i]);
        }
        hacerOrdenes(nuevas, function(){ setTimeout(mirarOrdenes, 750); });
    });
}
function hacerOrdenes(lista, fin) {
    if (!lista.length) { fin(); return; }
    var o = lista.shift();
    ULTIMA = o.n;
    hacerOrden(o, function(){
        contarEstado(true);
        hacerOrdenes(lista, fin);
    });
}
function hacerOrden(o, fin) {
    switch (o.c) {
        case 'load':
            conCola(o.qv, function(ok){
                if (!ok) { fin(); return; }
                PL = o.pl || '';
                SHUFFLE = !!o.shuffle;
                ON = true;
                QUIERE_SONAR = !!o.play;
                historialBorrar();
                cargar(Math.max(0, Math.min(o.idx || 0, Q.length - 1)), o.pos || 0);
                fin();
            });
            return;
        case 'play':  QUIERE_SONAR = true;  if (ON) reproducir(); break;
        case 'pause': QUIERE_SONAR = false; pausar(); break;
        case 'next':  if (ON) siguiente(); break;
        case 'prev':  if (ON) anterior(); break;
        case 'seek':
            if (ON && ytPlayer) {
                try { ytPlayer.seekTo(o.pos || 0, true); } catch (e) {}
            }
            break;
        case 'shuffle': SHUFFLE = !!o.on; break;
        case 'stop':
            ON = false;
            QUIERE_SONAR = false;
            if (ytPlayer) { try { ytPlayer.stopVideo(); } catch (e) {} }
            Q = []; IDX = -1;
            break;
    }
    render();
    fin();
}
/* La cola de una orden "load" (rt …-q.html), si no es la que ya hay. */
function conCola(qv, cb) {
    if (qv && qv === QV && Q.length) { cb(true); return; }
    leerRt(RT.cola, function(d){
        if (!d || !d.queue || !d.queue.length) { cb(false); return; }
        Q = d.queue;
        QV = d.qv || '';
        cb(true);
    });
}

/* ── Aleatorio con memoria (como mobile.php y reproductor.php): ⏮ vuelve
   a la que sonaba antes y ⏭, tras volver atrás, rehace el camino. ── */
var HIST_ATRAS = [], HIST_ADELANTE = [], HIST_AHORA = null, HIST_NAV = false;
function historialBorrar() { HIST_ATRAS = []; HIST_ADELANTE = []; HIST_AHORA = null; HIST_NAV = false; }
function recordar() {
    var e = { idx: IDX, vid: Q[IDX].videoId };
    var misma = HIST_AHORA && HIST_AHORA.vid === e.vid;
    if (!misma && !HIST_NAV && HIST_AHORA) {
        HIST_ATRAS.push(HIST_AHORA);
        if (HIST_ATRAS.length > 200) HIST_ATRAS.shift();
        HIST_ADELANTE = [];
    }
    HIST_AHORA = e;
    HIST_NAV = false;
}
function pasoHistorial(pila) {
    while (pila.length) {
        var e = pila.pop();
        if (Q[e.idx] && Q[e.idx].videoId === e.vid) return e.idx;
    }
    return -1;
}
function siguiente() {
    if (!Q.length) return;
    var i;
    if (SHUFFLE && Q.length > 1) {
        i = pasoHistorial(HIST_ADELANTE);
        if (i !== -1) {
            if (HIST_AHORA) HIST_ATRAS.push(HIST_AHORA);
            HIST_NAV = true;
        } else {
            do { i = Math.floor(Math.random() * Q.length); } while (i === IDX);
        }
    } else {
        i = (IDX + 1) % Q.length;
    }
    cargar(i, 0);
}
function anterior() {
    if (!Q.length) return;
    var i = SHUFFLE ? pasoHistorial(HIST_ATRAS) : -1;
    if (i !== -1) {
        if (HIST_AHORA) HIST_ADELANTE.push(HIST_AHORA);
        HIST_NAV = true;
    } else {
        i = (IDX - 1 + Q.length) % Q.length;
    }
    cargar(i, 0);
}

/* ── Reproductor de YouTube ── */
var ytReady = false, ytPlayer = null, ytVid = null;
var YT_ENDED = 0, YT_PLAYING = 1, YT_PAUSED = 2, YT_BUFFERING = 3, YT_CUED = 5;
var ATASCO_DESDE = 0;     /* desde cuándo debería sonar y no suena */
var CARGA_PENDIENTE = null;
var BLOQUEADA = false;    /* el autor no deja reproducirla fuera de YouTube */

window.onYouTubeIframeAPIReady = function() {
    ytReady = true;
    if (CARGA_PENDIENTE) { var c = CARGA_PENDIENTE; CARGA_PENDIENTE = null; cargar(c.idx, c.pos); }
};

function cargar(idx, pos) {
    if (!Q[idx]) return;
    IDX = idx;
    recordar();
    BLOQUEADA = false;
    ocultarError();
    render();
    if (!ytReady) { CARGA_PENDIENTE = { idx: idx, pos: pos }; return; }
    var vid = Q[idx].videoId;
    ytVid = vid;
    ATASCO_DESDE = 0;
    if (!ytPlayer) { crearPlayer(vid, pos); return; }
    /* cue + play en vez de loadVideoById: algunos vídeos no dejan el
       "embed con autoplay" pero sí ▶ a mano. */
    try { ytPlayer.cueVideoById({ videoId: vid, startSeconds: Math.floor(pos || 0) }); } catch (e) {}
    if (QUIERE_SONAR) {
        setTimeout(function(){
            if (ytPlayer && ytVid === vid && QUIERE_SONAR) { try { ytPlayer.playVideo(); } catch (e) {} }
        }, 300);
    }
}
function crearPlayer(vid, pos) {
    try {
        ytPlayer = new YT.Player('tv-player', {
            videoId: vid,
            playerVars: { playsinline: 1, autoplay: 0, start: Math.floor(pos || 0) },
            events: {
                onReady: function(e) {
                    if (QUIERE_SONAR) { try { e.target.playVideo(); } catch (x) {} }
                },
                onStateChange: alCambiarYT,
                onError: alFallarYT
            }
        });
    } catch (e) {}
}
function alCambiarYT(e) {
    var s = e.data;
    if (s === YT_PLAYING || s === YT_BUFFERING) ATASCO_DESDE = 0;
    /* Algo lo puso a sonar sin que nadie lo pidiera (autoplay). */
    if (s === YT_PLAYING && !QUIERE_SONAR) { try { ytPlayer.pauseVideo(); } catch (x) {} }
    if (s === YT_CUED && QUIERE_SONAR) {
        setTimeout(function(){ if (ytPlayer && QUIERE_SONAR) { try { ytPlayer.playVideo(); } catch (x) {} } }, 120);
    }
    if (s === YT_ENDED && ON) { siguiente(); contarEstado(true); }
    /* Al móvil: cuando suena o se pausa y no es lo último que se le ha
       contado (no en cada fin de buffer; sí al empezar la canción nueva,
       que se contó cuando aún estaba preparándose). */
    if ((s === YT_PLAYING || s === YT_PAUSED) && (s === YT_PLAYING) !== ESCRITOR.sonando) contarEstado(true);
    render();
}
/* 2 = id inválido, 5 = error HTML5, 100 = no existe o privado,
   101/150 = el autor no deja reproducirlo fuera de YouTube: se avisa y se
   pasa a la siguiente. Otros: se reintenta una vez. */
function alFallarYT(e) {
    var code = e && e.data;
    mostrarError(code);
    if (code === 101 || code === 150 || code === 100 || code === 2) {
        BLOQUEADA = true;
        var vid = ytVid;
        setTimeout(function(){ if (ON && ytVid === vid) { siguiente(); contarEstado(true); } }, 3000);
        return;
    }
    setTimeout(function(){
        if (!ytPlayer || !ON || IDX < 0) return;
        try { ytPlayer.cueVideoById({ videoId: Q[IDX].videoId, startSeconds: Math.floor(posicion()) }); } catch (x) {}
        if (QUIERE_SONAR) setTimeout(function(){ try { ytPlayer.playVideo(); } catch (x) {} }, 300);
    }, 500);
}
function reproducir() { if (ytPlayer) { try { ytPlayer.playVideo(); } catch (e) {} } }
function pausar() { if (ytPlayer) { try { ytPlayer.pauseVideo(); } catch (e) {} } }
function estadoYT() { try { return ytPlayer ? ytPlayer.getPlayerState() : -1; } catch (e) { return -1; } }
function sonando() { return estadoYT() === YT_PLAYING; }
function posicion() {
    try { return ytPlayer ? (ytPlayer.getCurrentTime() || 0) : 0; } catch (e) { return 0; }
}
function duracion() {
    var d = 0;
    try { d = ytPlayer ? (ytPlayer.getDuration() || 0) : 0; } catch (e) {}
    return d || (IDX >= 0 && Q[IDX] ? (Q[IDX].duration || 0) : 0);
}

/* Debería sonar y no suena: los navegadores de TV bloquean playVideo()
   sin un gesto del usuario, pero dejan si está en silencio. Se escala:
   ▶ otra vez → silencio + ▶ + sonido (imperceptible) → recargar el vídeo. */
setInterval(function(){
    if (!ON || !QUIERE_SONAR || !ytPlayer || BLOQUEADA || IDX < 0) { ATASCO_DESDE = 0; return; }
    var s = estadoYT();
    if (s === YT_PLAYING || s === YT_BUFFERING || s === YT_ENDED) { ATASCO_DESDE = 0; return; }
    if (!ATASCO_DESDE) ATASCO_DESDE = now();
    var t = now() - ATASCO_DESDE;
    if (t > 6000) {
        ATASCO_DESDE = now();
        try { ytPlayer.cueVideoById({ videoId: Q[IDX].videoId, startSeconds: Math.floor(posicion()) }); } catch (e) {}
        setTimeout(reproducir, 300);
    } else if (t > 1500) {
        try {
            ytPlayer.mute();
            ytPlayer.playVideo();
            setTimeout(function(){
                try { ytPlayer.unMute(); ytPlayer.setVolume(100); } catch (e) {}
            }, 200);
        } catch (e) {}
    } else {
        reproducir();
    }
}, 1000);

/* ── Pantalla ── */
function render() {
    var tr = (ON && IDX >= 0) ? Q[IDX] : null;
    if (!tr) {
        idleEl.className  = 'tv-idle';
        stageEl.className = 'tv-stage';
        return;
    }
    idleEl.className  = 'tv-idle hidden';
    stageEl.className = 'tv-stage active';
    var bgVal = 'url("https://i.ytimg.com/vi/' + tr.videoId + '/maxresdefault.jpg")';
    if (coverEl.style.backgroundImage !== bgVal) {
        coverEl.style.backgroundImage = bgVal;
        bgEl.style.backgroundImage    = bgVal;
    }
    titleEl.innerHTML  = escapeHtml(tr.title || '—');
    artistEl.innerHTML = escapeHtml(tr.artist || '');
    plEl.innerHTML     = PL ? ('📋 ' + escapeHtml(PL)) : '';
    coverEl.className  = sonando() ? 'tv-cover playing' : 'tv-cover';
}
function tickProgress() {
    if (!ON || IDX < 0) return;
    var dur = duracion(), pos = posicion();
    fillEl.style.width = (dur > 0 ? Math.min(100, pos / dur * 100) : 0) + '%';
    curEl.innerHTML = fmt(pos);
    totEl.innerHTML = fmt(dur);
}
function mostrarError(code) {
    var msg;
    switch (code) {
        case 101: case 150: msg = 'Esta canción no se puede reproducir en la TV — pasando a la siguiente'; break;
        case 100:           msg = 'Vídeo no encontrado o privado — pasando a la siguiente'; break;
        case 2:             msg = 'Vídeo no válido — pasando a la siguiente'; break;
        default:            msg = 'Error de reproducción (código ' + code + ')';
    }
    errBanner.innerHTML = msg;
    errBanner.className = 'tv-err-banner visible';
}
function ocultarError() { errBanner.className = 'tv-err-banner'; }
function fmt(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}
function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ── Arranque ──
   1. Las órdenes que ya había no se repiten: se empieza tras la última.
   2. Si la TV estaba sonando (recarga, se fue la luz…), sigue donde iba.
   3. A mirar órdenes y avisar al móvil de que está encendida. */
(function cargarYT() {
    var s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.getElementsByTagName('head')[0].appendChild(s);
})();
leerRt(RT.ordenes, function(d){
    var cmds = (d && d.cmds) || [];
    for (var i = 0; i < cmds.length; i++) if (cmds[i] && cmds[i].n > ULTIMA) ULTIMA = cmds[i].n;
    leerRt(RT.estado, function(st){
        if (st && st.on && st.track && st.track.videoId) {
            leerRt(RT.cola, function(c){
                if (c && c.queue && c.queue.length) {
                    Q = c.queue;
                    QV = c.qv || '';
                    var i = (Q[st.idx] && Q[st.idx].videoId === st.track.videoId) ? st.idx : -1;
                    for (var k = 0; i < 0 && k < Q.length; k++) if (Q[k].videoId === st.track.videoId) i = k;
                    if (i >= 0) {
                        ON = true;
                        PL = st.pl || '';
                        SHUFFLE = !!st.shuffle;
                        QUIERE_SONAR = !!st.playing;
                        var pos = st.pos || 0;
                        if (st.playing && st.t) pos += Math.max(0, (now() + RELOJ - st.t) / 1000);
                        cargar(i, pos);
                    }
                }
                arrancar();
            });
        } else {
            arrancar();
        }
    });
});
function arrancar() {
    render();
    contarEstado(true);
    mirarOrdenes();
}
setInterval(tickProgress, 500);
setInterval(render, 2000);
</script>

<?php endif; ?>

</body>
</html>
