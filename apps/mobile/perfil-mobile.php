<?php
/* ──────────────────────────────────────────────────────────────────────
   PERFIL — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Entrada independiente del escritorio: NO se incluye desde
   desktop-base.php y no depende de $desktopLabel / appTitleIcon().
   Lee toda la información vía /assets/profile/api.php:
     - get-profile  → cabecera (descripción, quote)
     - get-lists    → 5 categorías (movies/series/books/games/music)
   UI: cabecera + pestañas horizontales scroll + lista vertical.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__, 2) . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../../index.php');
    exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

/* ── pareja_id ── Mismo patrón que calendario.php — la query une la
   tabla parejas con usuarios por username (lowercased). Si el usuario
   no está en ninguna pareja, parejaId queda en 0 y crearMomento envía
   null (save-momento lo acepta). */
$parejaId = 0;
try {
    $stmt = $pdo->prepare("
        SELECT p.id FROM parejas p
        JOIN usuarios u1 ON p.usuario1_id = u1.id
        JOIN usuarios u2 ON p.usuario2_id = u2.id
        WHERE u1.username = ? OR u2.username = ?
    ");
    $stmt->execute([strtolower($userLabel), strtolower($userLabel)]);
    $pareja = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pareja) $parejaId = (int)$pareja['id'];
} catch (Throwable $e) { /* sin pareja → momento sin asociar */ }

/* Avatar del usuario logueado.
   Usamos getUserImage() (helper canónico en assets/config.php) en lugar
   de mirar a mano solo /assets/img/. La cascada correcta es:
     1. uploads/profile-photos/{label}.{ext}  ← foto del registro
     2. Restaurar desde BD usuarios.photo_data si filesystem se purgó
     3. assets/img/{label}.{ext}              ← seeds shipped
   getUserImage devuelve la ruta relativa a la raíz del proyecto, y
   este archivo vive en /apps/mobile/, así que prefijamos `../../`. */
$_userImgRel = getUserImage($userLabel);
$userImg = $_userImgRel !== '' ? '../../' . $_userImgRel : '';

/* ── VIEWING OTHER USER (?as=USERKEY) ──
   Si la query lleva ?as=USERKEY, estamos visitando el perfil de OTRA
   persona. Resolvemos su interfaz/tema/icon pack y los aplicamos
   server-side para que el HTML emitido tenga SU look (no el del viewer).
   Misma filosofía que el standalone de desktop. */
$_mobileViewingKey = '';
if (isset($_GET['as'])) {
    $_cand = (string)$_GET['as'];
    /* Acepta user_key directo o label (case-insensitive) — UX consistente
       con el desktop standalone. */
    if (isset($loginUsers[$_cand])) {
        $_mobileViewingKey = $_cand;
    } else {
        $needle = strtolower($_cand);
        foreach ($loginUsers as $k => $u) {
            if (strtolower($u['label']) === $needle) { $_mobileViewingKey = $k; break; }
        }
    }
}

/* Determina el "look owner" — el visitado si hay ?as, sino el viewer. */
$_lookUserKey = $_mobileViewingKey ?: $userKey;
$_lookLabel   = $loginUsers[$_lookUserKey]['label'];

/* Resolver interfaz del look owner. Lee active_interface_slug (JSON
   string) en user_settings. Fallback 'win98'. */
$_lookIface = 'win98';
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
$stmt->execute([$_lookUserKey]);
$_lookUid = (int)($stmt->fetchColumn() ?: 0);
if ($_lookUid) {
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_interface_slug'");
    $stmt->execute([$_lookUid]);
    $raw = (string)$stmt->fetchColumn();
    if ($raw !== '') {
        $slug = json_decode($raw, true);
        if (is_string($slug) && $slug !== ''
            && is_dir(dirname(__DIR__, 2) . '/assets/interfaces/' . $slug)) {
            $_lookIface = $slug;
        }
    }
}

/* Icon pack del look owner para su interfaz. Fallback 'Melon'. */
$_lookIconPack = 'Melon';
if ($_lookUid) {
    $stmt = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
    $stmt->execute([$_lookUid, 'icon_pack:' . $_lookIface]);
    $raw = (string)$stmt->fetchColumn();
    if ($raw !== '') {
        $v = json_decode($raw, true);
        if (is_string($v) && $v !== '') $_lookIconPack = $v;
    }
}

/* Override de cookie para que emitInterfaceCss lea la interfaz del
   look owner (sin esto leería la del viewer del browser). */
$_origIfaceCookie = $_COOKIE['activeInterface'] ?? null;
$_COOKIE['activeInterface'] = $_lookIface;

/* ── TEMA ACTIVO DEL LOOK OWNER ──
   Si visitamos a otro user, su tema. Sino, el del viewer. Filtra por
   la interfaz del look owner (themes son por interfaz). */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($_lookUserKey, $_lookLabel);
$_userThemes = loadUserThemes($_lookUserKey, $_lookIface);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $_lookLabel);
    $activeThemeCss   = themeCssRelPath($activeTheme, $_lookLabel);
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
    <!-- PWA GUARD: fuera de standalone → fuera de la app. -->
    <script>
    (function(){
        /* "MelonHubApp" en el User-Agent: la app de Android. */
        var sa = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
              || window.navigator.standalone === true
              || /MelonHubApp/.test(navigator.userAgent);
        if (!sa) window.location.replace('../../mobile-landing.php');
    })();
    /* --mh-vh sincronizado con window.innerHeight (sobrevive al bfcache). */
    (function(){
        function setVh(){
            document.documentElement.style.setProperty('--mh-vh', window.innerHeight + 'px');
        }
        setVh();
        window.addEventListener('resize', setVh);
        window.addEventListener('orientationchange', setVh);
        window.addEventListener('pageshow', setVh);
        window.addEventListener('visibilitychange', setVh);
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($themeBgColor) ?>">
    <title>Perfil — Scrapbook Melon</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <!-- Win98 + tema del usuario para consistencia con mobile.php -->
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <!-- Marcos de perfil en las fotos de los usuarios (data-marco-user) -->
    <link rel="stylesheet" href="../../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/css/marcos.css') ?>">
    <script src="../../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-marcos.js') ?>"></script>
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <?php /* Override del icon pack ANTES de cargar icon-pack.js. Sólo
              cuando visitamos a otro user. */ ?>
    <script>
        window.__ICON_PACK_OVERRIDE = <?= json_encode($_mobileViewingKey !== '' ? $_lookIconPack : null) ?>;
    </script>
    <script src="../../assets/js/icon-pack.js"></script>
    <script src="../../assets/js/notif-sound.js"></script>
    <script src="../../assets/js/cover-fallback.js"></script>
    <script src="../../assets/js/melon-links.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-links.js') ?>"></script>
    <?php
        require_once dirname(__DIR__, 2) . "/assets/php/active-interface.php";
        emitInterfaceCss("../../");
        /* Restauramos la cookie original ahora que emitInterfaceCss
           ya tomó el override. Evita afectar otros require posteriores. */
        if ($_origIfaceCookie === null) unset($_COOKIE['activeInterface']);
        else $_COOKIE['activeInterface'] = $_origIfaceCookie;
    ?>
    <script src="../../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="../../<?= htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../../assets/css/mobile-theme.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/mobile-theme.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
    <style>
        /* Tweaks específicos del perfil — el grueso del look viene de
           mobile-theme.css. Aquí solo añadimos lo propio de la pantalla
           (tabs de categorías, listado de items con poster). */

        /* ── Datos del perfil dentro del userbar: bio + meta + conexiones ──
           Replica `profile-info-bio`, `profile-info-meta`, `profile-info-links`
           del escritorio, pero embebidos en la cabecera para que no roben
           espacio adicional. */
        .pf-userbar { align-items: flex-start; padding: 8px; }
        .pf-userbar .mh-userbar-text { min-width: 0; }
        /* Columna izquierda: avatar + cuadrado de conexiones debajo.
           Flex column para alinear ambos a la misma anchura visual. */
        .pf-userbar-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        /* Bio = "descripción". Cursiva, color de acento. El placeholder
           "Sin descripción" usa otra clase para grisarlo. */
        .pf-about-bio {
            font-size: 11px;
            font-style: italic;
            line-height: 1.4;
            color: var(--accent, #000080);
            margin: 4px 0 3px;
            word-break: break-word;
        }
        .pf-about-bio.is-empty {
            color: var(--text-faint, #888);
            opacity: 0.85;
        }
        /* Línea de meta: pronombres · edad años · 📍 país. */
        .pf-about-meta {
            font-size: 10px;
            color: var(--text-faint, #666);
            margin: 0 0 4px;
            line-height: 1.3;
        }
        .pf-about-meta:empty { display: none; }
        .pf-about-meta .pf-meta-sep {
            margin: 0 4px;
            color: var(--text-faint, #aaa);
        }
        /* Cuadrado de conexiones — panel hundido Win98 con rejilla 2×2
           de iconos. Pensado para alinear visualmente con la anchura
           del avatar (~52px) que tiene encima. */
        .pf-about-socials {
            display: grid;
            grid-template-columns: repeat(2, 22px);
            grid-auto-rows: 22px;
            gap: 2px;
            padding: 3px;
            min-height: 46px;       /* fuerza forma cuadrada aunque haya 1-2 socials */
            background: var(--input-bg, #fff);
            box-shadow:
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -2px -2px var(--bezel-light-2, #dfdfdf),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a);
            box-sizing: content-box;
        }
        .pf-about-socials:empty { display: none; }
        .pf-about-social {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            font-size: 13px;
            text-decoration: none;
            color: var(--text, #000);
            background: var(--btn-bg, silver);
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-2, #fff);
            cursor: pointer;
            -webkit-user-select: none;
            user-select: none;
        }
        .pf-about-social:active {
            box-shadow:
                inset -1px -1px var(--bezel-light-2, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a);
        }

        /* ── Sub-tabs de música: álbumes vs canciones ──
           Solo visible en la pestaña 'música'. Replica el patrón del
           desktop (#music-tab-bar en perfil.php). */
        .pf-subtabs {
            display: flex;
            gap: 2px;
            padding: 4px 6px;
            background: var(--btn-bg, silver);
            border-bottom: 1px solid var(--bezel-dark-2, grey);
            flex-shrink: 0;
        }
        .pf-subtabs[hidden] { display: none !important; }
        .pf-subtab {
            flex: 1;
            padding: 6px 10px;
            background: var(--btn-bg, silver);
            color: var(--text, #000);
            font-size: 11px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            font-family: inherit;
            min-height: 30px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-2, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-1, #dfdfdf);
        }
        .pf-subtab.active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            box-shadow:
                inset -1px -1px var(--bezel-light-2, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-1, #dfdfdf),
                inset  2px  2px var(--bezel-dark-2, grey);
        }
        .pf-subtab:active {
            box-shadow:
                inset -1px -1px var(--bezel-light-2, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-1, #dfdfdf),
                inset  2px  2px var(--bezel-dark-2, grey);
        }

        /* ── Destacados ──
           Mismo concepto que `#music-catview-destacados` del escritorio:
           hasta 3 items con `featured: true`. Aquí los pintamos como una
           rejilla 3-col compacta encima de la lista. Tap en un slot con
           reseña abre el modal de reseña (mismo click delegado). */
        .pf-destacados {
            padding: 6px 8px;
            background: var(--input-bg, #fff);
            color: var(--input-text, var(--text, #000));
            border-bottom: 1px dotted var(--text-faint, #aaa);
            flex-shrink: 0;
        }
        .pf-destacados[hidden] { display: none !important; }
        .pf-destacados-title {
            font-size: 10px;
            color: var(--text-muted, var(--text-faint, #666));
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .pf-destacados-slots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }
        .pf-dest-slot {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            padding: 6px 4px;
            min-height: 92px;
            background: var(--btn-bg, silver);
            color: var(--text, #000);
            text-align: center;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-2, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-1, #dfdfdf);
            overflow: hidden;
        }
        .pf-dest-slot.empty {
            color: var(--text-faint, #888);
            justify-content: center;
            align-items: center;
            font-size: 18px;
        }
        .pf-dest-slot.has-review {
            cursor: pointer;
            -webkit-user-select: none;
            user-select: none;
            touch-action: manipulation;
        }
        .pf-dest-slot.has-review:active {
            box-shadow:
                inset -1px -1px var(--bezel-light-2, #fff),
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-1, #dfdfdf),
                inset  2px  2px var(--bezel-dark-2, grey);
        }
        .pf-dest-cover {
            width: 44px;
            height: 44px;
            object-fit: cover;
            image-rendering: pixelated;
            background: var(--inset-bg, #ddd);
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .pf-dest-cover-ph {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: var(--accent, #000080);
        }
        .pf-dest-title {
            font-size: 10px;
            font-weight: bold;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            width: 100%;
            color: inherit;
        }
        .pf-dest-artist {
            font-size: 9px;
            color: var(--text-faint, #666);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%;
        }
        .pf-dest-stars {
            font-size: 10px;
            color: var(--star-color, #f4c130);
            display: inline-flex;
            align-items: center;
            gap: 1px;
            line-height: 1;
        }
        .pf-dest-stars > .pf-stars > span { display: inline-block; }
        .pf-dest-stars-num {
            font-size: 9px;
            color: var(--text-faint, #666);
            font-weight: bold;
            margin-left: 2px;
        }

        /* ── Tabs horizontales (categorías) ── */
        .pf-tabs {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            background: var(--btn-bg, silver);
            border-bottom: 1px solid var(--bezel-dark-2, grey);
            scrollbar-width: none;
            flex-shrink: 0;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset 1px 1px var(--bezel-light-2, #fff);
        }
        .pf-tabs::-webkit-scrollbar { display: none; }
        .pf-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;          /* nunca colapsa contenido por flex sibling */
            padding: 8px 10px;
            color: var(--text, #000);
            background: transparent;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
            line-height: 1;          /* evita que el badge desborde verticalmente */
            letter-spacing: 0.3px;
            border: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-family: inherit;
            min-height: 36px;
        }
        .pf-tab.active {
            color: var(--accent, #000080);
            border-bottom-color: var(--accent, #000080);
            background: var(--input-bg, #fff);
        }
        .pf-tab .pf-tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            padding: 0 5px;
            box-sizing: border-box;
            font-size: 10px;
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            font-weight: bold;
            line-height: 1;
        }

        /* ── Lista de items dentro de un mh-panel ── */
        .pf-list { padding: 0; }
        .pf-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-top: 1px dotted var(--text-faint, #aaa);
            min-height: 56px;
            color: var(--input-text, var(--text, #000));
        }
        .pf-item:first-child { border-top: none; }
        /* Solo el item con reseña ofrece feedback `:active` — los
           que no son clicables se quedan sin highlight para no
           sugerir falsa interactividad. */
        .pf-item.has-review:active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .pf-item-poster {
            width: 40px;
            height: 56px;
            object-fit: cover;
            background: var(--inset-bg, #ddd);
            flex-shrink: 0;
            margin-left: 2px;          /* aire al shadow exterior */
            /* Marco de foto Win98 (idéntico al del escritorio). */
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
        }
        .pf-item.music .pf-item-poster { width: 44px; height: 44px; }
        .pf-item-poster.placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: var(--accent, #000080);
            background: var(--inset-bg, #ddd);
        }
        .pf-item-info { flex: 1; min-width: 0; }
        .pf-item-title {
            font-size: 13px;
            font-weight: bold;
            line-height: 1.3;
            color: inherit;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .pf-item-sub {
            font-size: 11px;
            color: var(--text-faint, #666);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pf-item.has-review:active .pf-item-sub { color: var(--accent-text, #fff); }
        /* Estrellas con medias estrellas — replica el patrón de
           perfil.css del escritorio (makeStarsHtml). Cada estrella es
           un <span> y para la media usamos clip-path inline.
           El contenedor con .pf-review-trigger es táctil y abre la
           reseña al pulsar. */
        .pf-stars {
            color: var(--star-color, #f4c130);
            font-size: 13px;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 1px;
            line-height: 1;
        }
        .pf-stars > span {
            display: inline-block;
            line-height: 1;
        }
        .pf-stars-num {
            color: var(--text-faint, #666);
            font-size: 10px;
            margin-left: 4px;
            font-weight: bold;
        }
        .pf-item.has-review:active .pf-stars-num { color: var(--accent-text, #fff); }
        /* Item con reseña: todo el row es clicable. Cursor pointer y
           feedback `:active` reforzado para indicar que la fila completa
           abre la reseña al pulsar. */
        .pf-item.has-review {
            cursor: pointer;
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
        }
        .pf-stars-wrap {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        /* ── Modal de reseña — centrado, con tema del usuario ──
           Centrado vertical (no bottom-sheet) para que se sienta como
           una ventana modal Win98 clásica. Mount como hijo de <body>
           (no de <html>) para heredar las variables del tema activo
           que viven en `body.theme-X { --win-bg: ...; ... }`. */
        .pf-modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.55);
            z-index: 10000;
            display: flex;
            align-items: center;            /* ← centrado vertical */
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }
        /* [hidden] necesita !important para vencer al display:flex de
           arriba (specificity tie + UA stylesheet pierde sin esto). */
        .pf-modal-backdrop[hidden] { display: none !important; }
        .pf-modal {
            width: 100%;
            max-width: 380px;
            max-height: calc(100vh - 32px);
            display: flex;
            flex-direction: column;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
        }
        .pf-modal > .title-bar {
            flex-shrink: 0;
            /* Refuerzo: el title-bar de 98.css usa --titlebar-* tokens
               que ya inyecta el tema. Aquí solo aseguramos que respete
               la altura sin colapsar dentro del flex. */
            min-height: 22px;
        }
        .pf-modal > .window-body {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 14px 16px;
            background: var(--win-body-bg, var(--win-bg, silver));
            color: var(--text, #000);
        }
        /* Cabecera de la reseña: carátula del item + título. */
        .pf-modal .pf-review-itemhead {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border, #c0c0c0);
            min-width: 0;
        }
        .pf-modal .pf-review-cover {
            width: 84px; aspect-ratio: 1 / 1; flex-shrink: 0;
            overflow: hidden; background: var(--input-bg, #000);
            display: flex; align-items: center; justify-content: center;
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
            margin: 2px;
        }
        /* Pelis/series/libros/juegos → póster vertical. */
        .pf-modal .pf-review-cover.pf-melon-cover-portrait { aspect-ratio: 2 / 3; }
        .pf-modal .pf-review-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pf-modal .pf-review-cover.pf-review-cover-ph { font-size: 28px; }
        .pf-modal .pf-review-title {
            font-size: 15px;
            font-weight: bold;
            color: var(--text, #000);
            margin: 0;
            word-break: break-word;
            min-width: 0;
        }
        /* Bloque comentario — match al #profile-review-view-comment del escritorio. */
        .pf-modal .pf-review-comment {
            font-size: 12px;
            font-style: italic;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.65;
            color: var(--accent, #000080);
            margin: 0 0 12px;
            max-height: 220px;
            overflow-y: auto;
        }
        .pf-modal .pf-review-comment-empty {
            font-size: 12px;
            font-style: italic;
            color: var(--text-faint, #888);
            margin: 0 0 12px;
        }
        /* Header con firma del usuario — match al #profile-review-view-header. */
        .pf-modal .pf-review-header {
            font-size: 12px;
            font-weight: bold;
            color: var(--accent, #000080);
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pf-modal .pf-review-header .pf-stars { font-size: 16px; }
        .pf-modal .pf-review-header .pf-stars-num {
            font-size: 12px;
            color: var(--accent, #000080);
        }
        .pf-modal .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid var(--bezel-dark-2, grey);
        }
        .pf-modal .modal-actions .button { min-height: 28px; min-width: 70px; }

        /* Context menu (long-press en música): lista vertical de
           botones Win98 con icono a la izquierda. Comparte el chasis
           de .pf-modal — solo añadimos el layout interno. */
        .pf-modal.pf-ctx-menu > .window-body { padding: 10px 12px; }
        .pf-ctx-list { display: flex; flex-direction: column; gap: 6px; }
        .pf-ctx-item {
            display: flex; align-items: center; gap: 12px;
            justify-content: flex-start; text-align: left;
            padding: 0 14px; font-size: 13px;
            min-height: 44px; width: 100%;
        }
        .pf-ctx-item:disabled { opacity: 0.45; cursor: not-allowed; }
        .pf-ctx-icon {
            font-size: 16px; width: 20px; text-align: center;
            flex-shrink: 0; color: var(--accent, #000080);
        }
        .pf-ctx-label { flex: 1; }

        /* Review editor: stars row + textarea. */
        .pf-rev-edit-stars {
            display: flex; align-items: center; gap: 4px;
            margin: 4px 0 12px;
        }
        .pf-rev-edit-star {
            font-size: 28px; line-height: 1; cursor: pointer;
            color: var(--accent, #000080); user-select: none;
            min-width: 32px; text-align: center;
        }
        .pf-rev-edit-star.empty { color: var(--text-faint, #888); }
        .pf-rev-edit-num {
            margin-left: 8px; font-size: 14px; font-weight: bold;
            color: var(--accent, #000080); min-width: 18px;
        }
        .pf-rev-edit-comment {
            width: 100%; min-height: 80px; box-sizing: border-box;
            font-family: inherit; font-size: 12px; padding: 6px 8px;
            resize: vertical;
        }
        .pf-rev-edit-label {
            font-size: 11px; margin: 8px 0 4px; color: var(--text, #000);
        }
        .pf-rev-edit-runtime {
            width: 110px; box-sizing: border-box;
            font-family: inherit; font-size: 13px; padding: 6px 8px;
        }
        .pf-modal .modal-actions .button.danger {
            margin-right: auto;
        }

        /* Collab dialog: lista de avatares + botones acción. */
        .pf-collab-section-title {
            font-size: 10px; color: var(--text-faint, #666);
            padding: 4px 0 2px; text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .pf-collab-row {
            display: flex; align-items: center; gap: 10px;
            padding: 6px 0; min-height: 32px;
        }
        .pf-collab-av {
            width: 28px; height: 28px; flex-shrink: 0;
            background: var(--input-bg, #fff);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
            display: flex; align-items: center; justify-content: center;
        }
        .pf-collab-av img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .pf-collab-av-ph {
            font-size: 14px; color: var(--text-faint, #888);
        }
        .pf-collab-label {
            flex: 1; font-size: 12px; color: var(--text, #000);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .pf-collab-action { min-height: 26px; min-width: 64px; font-size: 11px; }
        .pf-collab-status {
            font-size: 10px; color: var(--text-faint, #666);
            margin-top: 6px; min-height: 12px; font-style: italic;
        }
        .pf-collab-sep {
            border: none; border-top: 1px solid var(--bezel-dark-1, #808080);
            margin: 6px 0;
        }

        /* Item "+" al final de cada lista — clickable, look discreto.
           Pintado por renderActiveList al final de la lista. */
        .pf-item.pf-add-item {
            justify-content: center;
            min-height: 50px;
            cursor: pointer;
            background: transparent;
            border-top: 1px dashed var(--bezel-dark-1, #808080);
            color: var(--text-faint, #666);
            gap: 8px;
            opacity: 0.85;
        }
        .pf-item.pf-add-item:active { opacity: 1; }
        .pf-item.pf-add-item .pf-add-icon {
            font-size: 18px; font-weight: bold;
            color: var(--accent, #000080);
            min-width: 24px; text-align: center;
        }
        .pf-item.pf-add-item .pf-add-label {
            font-size: 12px;
            color: var(--text, #000);
        }

        /* Formularios de modales (Edit profile + Add item). */
        .pf-form-row {
            display: flex; flex-direction: column; gap: 3px;
            margin-bottom: 8px;
        }
        .pf-form-row label {
            font-size: 11px; font-weight: bold;
            color: var(--text, #000);
        }
        .pf-form-row input,
        .pf-form-row textarea,
        .pf-form-row select {
            width: 100%; box-sizing: border-box;
            font-family: inherit; font-size: 12px;
            padding: 4px 6px;
        }
        .pf-form-row textarea { resize: vertical; min-height: 60px; }
        /* Cumpleaños: 98.css no estiliza <input type="date">; igual que
           los de texto (Kawaii pone su borde con !important). */
        .pf-form-row input[type="date"] { border: none; border-radius: 0; height: 21px; }
        .pf-form-hint {
            font-size: 10px; color: var(--text-faint, #666);
            font-style: italic; margin-top: 2px;
        }
        .pf-form-error {
            font-size: 11px; color: var(--error-text, #c00);
            margin: 4px 0 0; min-height: 14px;
        }
        /* Botones tipo "tile" para selección de tipo (música song/album). */
        .pf-type-row {
            display: flex; gap: 8px; margin: 6px 0 4px;
        }
        .pf-type-btn {
            flex: 1; min-height: 56px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 2px; font-size: 11px;
        }
        .pf-type-btn .pf-type-emoji { font-size: 22px; }
        .pf-shared-from {
            color: var(--accent, #000080);
            font-size: 10px;
            background: var(--input-bg, #fff);
            padding: 1px 6px;
            font-weight: bold;
        }
        .pf-item.has-review:active .pf-shared-from {
            background: var(--accent-text, #fff);
            color: var(--accent, #000080);
        }

        /* ── Hamburguesa + sidebar drawer ── */
        .pf-userbar { position: relative; }
        .pf-hamburger {
            position: absolute; top: 6px; right: 6px;
            width: 28px; height: 28px; min-width: 28px;
            padding: 0; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
            line-height: 1; cursor: pointer; z-index: 2;
        }
        .pf-drawer-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 9000; opacity: 0;
            visibility: hidden; transition: opacity 0.2s ease, visibility 0s linear 0.2s;
        }
        .pf-drawer-backdrop.open {
            opacity: 1; visibility: visible;
            transition: opacity 0.2s ease;
        }
        .pf-drawer {
            position: fixed; top: 0; right: 0;
            width: 80%; max-width: 280px; height: 100%;
            background: var(--win-bg, silver);
            box-shadow: -4px 0 12px rgba(0,0,0,0.4);
            z-index: 9001; display: flex; flex-direction: column;
            transform: translateX(100%); transition: transform 0.2s ease;
            color: var(--text, #000);
        }
        .pf-drawer.open { transform: translateX(0); }
        .pf-drawer-head {
            padding: 8px 10px; font-size: 12px; font-weight: bold;
            background: var(--accent, #000080); color: var(--accent-text, #fff);
            display: flex; align-items: center; justify-content: space-between;
        }
        .pf-drawer-close {
            min-width: 22px; height: 22px; padding: 0;
            font-size: 14px;
        }
        .pf-drawer-body { flex: 1; overflow-y: auto; padding: 8px; }
        .pf-drawer-section { display: flex; flex-direction: column; gap: 4px; }
        .pf-drawer-section + .pf-drawer-section {
            margin-top: 12px; border-top: 1px solid var(--bezel-dark-1, #808080);
            padding-top: 10px;
        }
        .pf-drawer-section-title {
            font-size: 10px; color: var(--text-faint, #666);
            text-transform: uppercase; letter-spacing: 0.08em;
            margin: 0 0 4px 4px;
        }
        .pf-drawer-item {
            display: flex; align-items: center; gap: 10px;
            justify-content: flex-start; text-align: left;
            padding: 0 10px; min-height: 36px;
            font-size: 12px; width: 100%;
        }
        .pf-drawer-item.active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            font-weight: bold;
        }
        .pf-drawer-icon {
            font-size: 14px; width: 18px; text-align: center; flex-shrink: 0;
        }

        /* ── Vistas ── */
        .pf-view { display: flex; flex-direction: column; flex: 1; min-height: 0; }
        .pf-view[hidden] { display: none !important; }

        /* ── Vista Perfil (edit + posts) ── */
        .pf-profile-actions {
            display: flex; gap: 6px; padding: 8px 0;
            border-bottom: 1px solid var(--bezel-dark-1, #808080);
            margin-bottom: 8px;
        }
        .pf-profile-actions .button { min-height: 28px; font-size: 11px; flex: 1; }
        /* `.button` pisa el atributo hidden (Personalizar cambia los botones). */
        .pf-profile-actions [hidden] { display: none !important; }
        /* Franja de Steam en Personalizar (renderPf2Steam; en el PC, arriba a
           la derecha de la cabecera): debajo de Guardar / Datos / Cancelar. */
        .pf2m-steam {
            display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px;
            padding: 0 0 8px; margin-bottom: 8px; font-size: 12px; color: var(--text, #000);
            border-bottom: 1px solid var(--bezel-dark-1, #808080);
        }
        .pf2m-steam[hidden] { display: none !important; }
        .pf2m-steam-t { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pf2m-steam-t img { width: 16px; height: 16px; object-fit: contain; image-rendering: pixelated; flex-shrink: 0; }
        .pf2m-steam .button { min-height: 28px; font-size: 11px; min-width: 0; padding: 0 10px; flex-shrink: 0; }
        .pf2m-steam .button img { width: 12px; height: 12px; object-fit: contain; image-rendering: pixelated; vertical-align: -2px; margin-right: 4px; }
        .pf2m-steam-nota { width: 100%; font-size: 11px; color: var(--text-muted, #444); }
        .pf2m-steam-nota.is-aviso { color: var(--warning-text, #8a6d00); }
        /* Racha (renderPf2Racha): fueguito pixelado al lado del nombre, gris
           apagado / naranja encendido, con los días. Como el del PC. */
        .pf-name-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .pf-name-row .mh-userbar-name { min-width: 0; }
        .pf2m-racha { display: inline-flex; align-items: center; gap: 3px; flex-shrink: 0; font-size: 13px; color: var(--text, #000); }
        .pf2m-racha:empty { display: none; }
        .pf2m-racha svg { width: 15px; height: 18px; display: block; filter: drop-shadow(1px 1px 0 rgba(0,0,0,.6)); }
        .pf2m-racha.is-off b { color: var(--text-muted, #666); }
        .pf2m-racha.is-btn { cursor: pointer; }
        /* Invitar (menú lateral; pf2AbrirInvitar). */
        .pf2m-inv-txt { font-size: 11px; line-height: 1.4; color: var(--text, #000); margin-bottom: 8px; }
        .pf2m-inv-link { display: flex; gap: 4px; margin-bottom: 10px; }
        .pf2m-inv-link input { flex: 1; min-width: 0; min-height: 28px; box-sizing: border-box; font-size: 11px;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; }
        .pf2m-inv-link .button { min-width: 0; padding: 0 10px; min-height: 28px; font-size: 11px; flex-shrink: 0; }
        .pf2m-inv-h { font-weight: bold; font-size: 12px; color: var(--text, #000); margin: 0 0 4px; }
        .pf2m-inv-list { max-height: 45vh; overflow-y: auto; }
        .pf2m-inv-row { display: flex; align-items: center; gap: 8px; padding: 6px 4px; cursor: pointer; color: var(--text, #000);
            border-bottom: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .pf2m-inv-av { position: relative; width: 30px; height: 30px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
            background: var(--inset-bg, #808080); color: var(--text, #000); font-size: 11px; }
        .pf2m-inv-av[data-marco] { margin: calc(var(--mk-t) * 30px) calc(var(--mk-r) * 30px) calc(var(--mk-b) * 30px) calc(var(--mk-l) * 30px); }
        .pf2m-inv-av img { width: 30px; height: 30px; object-fit: cover; display: block; }
        .pf2m-inv-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
        .pf2m-inv-st { font-size: 10px; color: var(--text-muted, #666); text-align: right; flex-shrink: 0; max-width: 55%; }
        .pf2m-inv-row.is-cobrada .pf2m-inv-st { color: var(--accent, navy); font-weight: bold; }
        .pf2m-inv-vacio { font-size: 11px; color: var(--text-muted, #666); padding: 8px 2px; }
        /* Selector de marco (pf2AbrirMarcos): la foto en cada marco. El
           hueco deja sitio a lo que más sobresale (Polaroid, Overdose) y la
           foto se corre para que foto + marco queden centrados (--mk-*). */
        .pf2m-mk-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 4px; }
        .pf2m-mk { text-align: center; font-size: 11px; color: var(--text, #000); padding: 4px 2px; cursor: pointer; }
        .pf2m-mk.on { outline: 2px solid var(--accent, navy); outline-offset: -2px; }
        .pf2m-mk-hueco { height: 92px; display: flex; align-items: center; justify-content: center; }
        .pf2m-mk-foto { position: relative; display: block; width: 52px; height: 52px; flex-shrink: 0; background: var(--inset-bg, #808080); }
        .pf2m-mk-foto[data-marco] { transform: translate(calc(26px * (var(--mk-l) - var(--mk-r))), calc(26px * (var(--mk-t) - var(--mk-b)))); }
        .pf2m-mk-foto > img, .pf2m-mk-ph { width: 52px; height: 52px; object-fit: cover; display: block; }
        .pf2m-mk-t { margin-top: 2px; line-height: 1.25; overflow-wrap: anywhere; }
        /* ── Perfil nuevo (estilo Steam en el móvil; renderPerfil2) ──
           Como antes la lista de posts: la cabecera y los botones se
           quedan arriba y esto hace scroll por dentro (`flex: 1` +
           `min-height: 0` en la vista, que es flex en columna). */
        .pf2m {
            display: flex; flex-direction: column; gap: 6px;
            padding: 0 2px 10px 0; flex: 1; min-height: 0;
            overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
        }
        /* En una columna con scroll, que nada se encoja (la franja del
           estado salía aplastada). */
        .pf2m > * { flex-shrink: 0; }
        .pf2m-relieve {
            background: var(--win-bg, silver); color: var(--text, #000);
            box-shadow: inset -1px -1px var(--bezel-dark-1, #0a0a0a), inset 1px 1px var(--bezel-light-1, #fff),
                        inset -2px -2px var(--bezel-dark-2, grey), inset 2px 2px var(--bezel-light-2, #dfdfdf);
        }
        /* Kawaii cambia los relieves por bordes, como el resto de su interfaz. */
        body.pf-iface-kawaii .pf2m-relieve { box-shadow: none; border: 2px solid var(--border, #49388B); }
        .pf2m-level {
            width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 15px; flex-shrink: 0;
            background: var(--accent, navy); color: var(--accent-text, #fff);
            box-shadow: 0 0 0 2px var(--win-bg, silver), 0 0 0 3px var(--accent, navy);
        }
        /* Nivel + la mejor medalla (sin barra de XP, como Steam). */
        .pf2m-lvlbox { display: grid; grid-template-columns: 1fr 1fr; align-items: center; gap: 8px; padding: 12px 10px; }
        .pf2m-lvl { display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 22px; }
        .pf2m-best { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .pf2m-best svg { width: 44px; height: 52px; flex-shrink: 0; }
        .pf2m-best-t { font-size: 12px; line-height: 1.35; min-width: 0; }
        .pf2m-best-t small { display: block; color: var(--text-muted, #444); font-size: 11px; }
        /* Franja del estado ("Currently Online"). */
        .pf2m-estado { padding: 12px; font-size: 17px; color: var(--accent, navy); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pf2m-estado.is-offline { color: var(--text-muted, #444); }
        .pf2m-estado.is-dnd { color: #d04040; }
        .pf2m-estado.is-away { color: #c99a0e; }
        /* Cuadrícula de 2 columnas: título + número; Medallas y Amigos con sus iconos. */
        .pf2m-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .pf2m-tile { padding: 9px 9px 8px; min-width: 0; }
        .pf2m-tile[data-t] { cursor: pointer; }
        .pf2m-tile[data-t]:active { box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a), inset -1px -1px var(--bezel-light-1, #fff); }
        .pf2m-tile-t { font-size: 13px; display: flex; align-items: baseline; gap: 7px; white-space: nowrap; overflow: hidden; }
        .pf2m-tile-t span { color: var(--text-muted, #444); font-size: 17px; }
        .pf2m-icons { display: flex; gap: 6px; margin-top: 8px; align-items: center; flex-wrap: wrap; }
        .pf2m-icons svg { width: 30px; height: 36px; flex-shrink: 0; }
        .pf2m-fav { width: 34px; height: 34px; flex-shrink: 0; background: var(--input-bg, #fff); display: flex; align-items: center; justify-content: center; font-size: 11px; }
        .pf2m-fav img { width: 100%; height: 100%; object-fit: cover; display: block; }
        /* Ventana de medallas (al tocar "Medallas"). */
        .pf2m-mrow { display: flex; gap: 10px; align-items: center; padding: 6px 0; font-size: 11px; line-height: 1.4; }
        .pf2m-mrow + .pf2m-mrow { border-top: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .pf2m-mrow svg { width: 34px; height: 40px; flex-shrink: 0; }
        /* Medallas con sprite (PNG pixel, cuadrado): a la altura del SVG de cada sitio. */
        .pf2m-medal-img { display: block; flex-shrink: 0; image-rendering: pixelated; }
        .pf2m-best .pf2m-medal-img { width: 52px; height: 52px; }
        .pf2m-icons .pf2m-medal-img { width: 36px; height: 36px; }
        .pf2m-mrow .pf2m-medal-img { width: 40px; height: 40px; }
        .pf2m-mtxt { flex: 1; min-width: 0; }
        .pf2m-bar { height: 6px; margin-top: 3px; background: var(--input-bg, #fff); box-shadow: inset 1px 1px var(--bezel-dark-1, #808080), inset -1px -1px var(--bezel-light-1, #fff); }
        .pf2m-bar i { display: block; height: 100%; background: var(--accent, navy); }
        /* Comentarios (el muro; sustituye a los posts). */
        .pf2m-com-win { margin: 0; }
        .pf2m-com-win .title-bar-text { display: flex; align-items: center; gap: 5px; width: 100%; }
        .pf2m-com-win .title-bar-text span { margin-left: auto; font-weight: normal; opacity: .85; }
        .pf2m-com-win .window-body { margin: 6px; color: var(--text, #000); }
        .pf2m-new { display: flex; gap: 6px; }
        .pf2m-new input { flex: 1; min-width: 0; min-height: 30px; box-sizing: border-box;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; }
        .pf2m-new .button { min-width: 0; padding: 0 10px; }
        .pf2m-com { display: flex; gap: 8px; padding: 7px 0; align-items: flex-start; }
        .pf2m-com + .pf2m-com { border-top: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .pf2m-com-av { width: 32px; height: 32px; flex-shrink: 0; background: var(--input-bg, #fff); }
        .pf2m-com-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pf2m-com-main { flex: 1; min-width: 0; font-size: 11px; overflow-wrap: anywhere; }
        .pf2m-com-who { font-weight: bold; cursor: pointer; }
        .pf2m-com-when { color: var(--text-muted, #444); font-size: 10px; margin-left: 5px; }
        .pf2m-com-txt { margin-top: 2px; line-height: 1.35; white-space: pre-wrap; }
        .pf2m-com-img { margin-top: 5px; }
        .pf2m-com-img img { max-width: 100%; max-height: 260px; display: block; }
        .pf2m-del { min-width: 0 !important; width: 26px; min-height: 24px !important; padding: 0 !important; flex-shrink: 0; }
        .pf2m-vacio { font-size: 11px; color: var(--text-muted, #444); padding: 8px 2px; }

        /* ── Expositores (renderPf2Expositores): los del PC en una columna,
           cada uno en su ventanita como "Comentarios" (boceto aprobado:
           electron/.e2e/perfil-movil-mock/preview-expositores.png). ── */
        .pf2m-expo { display: flex; flex-direction: column; gap: 6px; }
        .pf2m-expo:empty { display: none; }
        .pf2m-x { margin: 0; }
        .pf2m-x .title-bar-text { display: flex; align-items: center; gap: 5px; width: 100%; }
        .pf2m-x .title-bar-text span { margin-left: auto; font-weight: normal; opacity: .85; }
        .pf2m-x .window-body { margin: 6px; color: var(--text, #000); position: relative; }
        .pf2m-hund { background: var(--input-bg, #fff); box-shadow: inset 1px 1px var(--bezel-dark-1, #808080), inset -1px -1px var(--bezel-light-1, #fff); }
        .pf2m-stars { color: var(--accent, navy); }
        .pf2m-noimg { display: flex; align-items: center; justify-content: center; text-align: center; padding: 4px; font-size: 10px;
            background: var(--input-bg, #fff); color: var(--input-text, #000); box-sizing: border-box; }
        /* Pósteres (juegos, películas, series, libros) y discos: 3 por fila. */
        /* minmax(0, 1fr) y min-width: 0: con 1fr a secas una columna crecía
           hasta el ancho del título más largo (va en una línea), y con 5
           canciones la 3.ª salía más grande que las demás. */
        .pf2m-row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 5px; }
        .pf2m-row3 > * { min-width: 0; }
        .pf2m-row3 img, .pf2m-row3 .pf2m-noimg { width: 100%; display: block; object-fit: cover; }
        .pf2m-poster img, .pf2m-poster .pf2m-noimg { aspect-ratio: 2/3; cursor: pointer; }
        .pf2m-album img, .pf2m-album .pf2m-noimg { aspect-ratio: 1; }
        .pf2m-album span { display: block; font-size: 10px; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pf2m-album > div, .pf2m-pl-row { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
        .pf2m-album > div.long-pressing, .pf2m-pl-row.long-pressing { opacity: .6; }
        /* Ficha al tocar (lo que en el PC sale al pasar el ratón). */
        .pf2m-tip { position: absolute; z-index: 5; left: 4px; right: 4px; font-size: 11px; padding: 6px 8px; line-height: 1.5;
            background: var(--warning-bg, #fffbe6); color: var(--warning-text, #444); border: 1px solid var(--bezel-dark-2, grey); }
        .pf2m-tip .pf2m-tr { display: flex; justify-content: space-between; gap: 8px; }
        .pf2m-tip .pf2m-tr > span { display: inline-flex; align-items: center; gap: 4px; }
        .pf2m-tip .pf2m-bar { margin: 2px 0 3px; }
        /* Juego destacado */
        .pf2m-gf { display: flex; gap: 10px; }
        .pf2m-gf > img, .pf2m-gf > .pf2m-noimg { width: 120px; aspect-ratio: 2/3; object-fit: cover; display: flex; flex-shrink: 0; }
        .pf2m-gf-t { flex: 1; min-width: 0; font-size: 11px; }
        .pf2m-gf-t h3 { margin: 0 0 4px; font-size: 15px; }
        .pf2m-gf-t .pf2m-bar { height: 8px; margin: 3px 0 6px; }
        .pf2m-gf-p { display: flex; justify-content: space-between; margin-top: 6px; }
        .pf2m-gf-achs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
        .pf2m-gf-achs img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; cursor: pointer; }
        /* Playlist destacada */
        .pf2m-pl { display: flex; gap: 10px; }
        .pf2m-pl > img, .pf2m-pl > .pf2m-noimg { width: 110px; height: 110px; object-fit: cover; flex-shrink: 0; }
        .pf2m-pl-t { flex: 1; min-width: 0; font-size: 11px; }
        .pf2m-pl-t h3 { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
        .pf2m-pl-t small { color: var(--text-muted, #444); }
        .pf2m-pl-btns { display: flex; gap: 4px; margin-top: 8px; flex-wrap: wrap; }
        .pf2m-pl-btns .button { min-width: 0; padding: 0 8px; min-height: 28px; }
        .pf2m-pl-btns svg { fill: var(--btn-text, #000); margin-right: 4px; vertical-align: 0; }
        .pf2m-pl-tracks { margin-top: 8px; font-size: 11px; }
        .pf2m-pl-row { display: flex; gap: 6px; padding: 3px 0; }
        .pf2m-pl-row i { font-style: normal; color: var(--text-muted, #444); width: 12px; text-align: right; flex-shrink: 0; }
        .pf2m-pl-row span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pf2m-pl-row em { font-style: normal; color: var(--text-muted, #444); }
        /* Logros de Steam: filas de 7; los raros (< 10 %) con brillo. */
        .pf2m-ach7 { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .pf2m-ach7 img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; cursor: pointer; }
        .pf2m-ach7 img.raro, .pf2m-gf-achs img.raro { box-shadow: 0 0 0 2px var(--accent, navy), 0 0 6px var(--accent, navy); }
        .pf2m-ach-stats { display: flex; gap: 26px; margin-top: 8px; padding-top: 6px; border-top: 1px solid color-mix(in srgb, var(--text, #000) 25%, transparent); }
        .pf2m-ach-stats b { display: block; font-size: 22px; }
        .pf2m-ach-stats span { font-size: 10px; }
        /* Reseña destacada */
        .pf2m-rev { display: flex; gap: 10px; }
        .pf2m-rev > img { width: 70px; aspect-ratio: 2/3; object-fit: cover; flex-shrink: 0; }
        .pf2m-rev h3 { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
        .pf2m-rev small { color: var(--text-muted, #444); display: block; }
        .pf2m-rev p { margin: 4px 0 0; font-size: 11px; line-height: 1.4; white-space: pre-wrap; }
        /* Capturas: la primera grande y las demás debajo. */
        .pf2m-shots { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
        .pf2m-shots img { width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block; }
        .pf2m-shots img:first-child { grid-column: 1 / -1; }
        .pf2m-shot-cap { grid-column: 1 / -1; font-size: 10px; color: var(--text-muted, #444); }
        .pf2m-txt { font-size: 12px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }

        /* ── Personalizar (pf2Editar / renderPf2Edicion; boceto aprobado:
           preview-personalizar.png). Se ordena manteniendo pulsada la barra. ── */
        .pf2m-ed { outline: 2px dashed var(--accent, navy); outline-offset: 2px; }
        .pf2m-ed { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
        .pf2m-ed-bar { cursor: grab; }
        .pf2m-ed.long-pressing { opacity: .7; }
        .pf2m-ed-asa { font-size: 14px; line-height: 1; margin-right: 1px; opacity: .9; }
        .pf2m-ed .title-bar-text { gap: 4px; }
        .pf2m-ed-t { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* 98.css lo pinta blanco y themes.css repite la flecha: con el tema. */
        .pf2m-ed-sel { flex: 1; min-width: 0; height: 24px; font-size: 11px;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important;
            background-repeat: no-repeat !important; background-position: top 2px right 2px !important; }
        .pf2m-ed-sel option { background-color: var(--input-bg, #fff); color: var(--input-text, #000); }
        body.pf-iface-kawaii .pf2m-ed-sel { font-size: 13px !important; height: 28px; }
        .pf2m-ed-btn { min-width: 0 !important; min-height: 24px !important; padding: 0 7px !important; font-size: 11px; flex-shrink: 0; }
        .pf2m-arrastra { opacity: .85; box-shadow: 0 8px 22px rgba(0,0,0,.45); position: relative; z-index: 5; }
        .pf2m-slot { border: 1px dashed var(--bezel-dark-1, #808080); color: var(--text, #000); font-size: 12px; text-align: center; padding: 16px 8px; }
        .pf2m-slot.add { cursor: pointer; font-weight: bold; }
        .pf2m-slot-info { font-size: 11px; color: var(--text-muted, #444); text-align: center; padding: 4px; }
        .pf2m-tipo { padding: 11px 8px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px; color: var(--text, #000);
            border-bottom: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .pf2m-tipo:active { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .pf2m-tipo small { margin-left: auto; color: var(--text-muted, #444); font-size: 10px; }
        /* Selectores */
        .pf2m-picker .window-body { overflow-y: auto; min-height: 0; }
        .pf2m-buscar { display: flex; gap: 6px; margin-bottom: 6px; }
        .pf2m-buscar input { flex: 1; min-width: 0; min-height: 30px; box-sizing: border-box;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; }
        .pf2m-buscar .button { min-width: 0; padding: 0 8px; }
        .pf2m-pk-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; padding: 4px; max-height: 46vh; overflow-y: auto; }
        .pf2m-pk-grid.pf2m-pk-wide { grid-template-columns: repeat(2, 1fr); }
        .pf2m-pk-corta { max-height: 26vh; }
        .pf2m-pk { position: relative; font-size: 10px; text-align: center; cursor: pointer; color: var(--input-text, #000); min-width: 0; }
        .pf2m-pk img, .pf2m-pk-ph { width: 100%; display: block; object-fit: cover; background: color-mix(in srgb, var(--text, #000) 8%, transparent); }
        .pf2m-pk.portrait img, .pf2m-pk.portrait .pf2m-pk-ph { aspect-ratio: 2/3; }
        .pf2m-pk.square img, .pf2m-pk.square .pf2m-pk-ph { aspect-ratio: 1; }
        .pf2m-pk.wide img, .pf2m-pk.wide .pf2m-pk-ph { aspect-ratio: 16/9; }
        .pf2m-pk.on img, .pf2m-pk.on .pf2m-pk-ph { outline: 3px solid var(--accent, navy); outline-offset: -3px; }
        .pf2m-pk-t { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .pf2m-pk-n { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: var(--accent, navy);
            color: var(--accent-text, #fff); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px; }
        .pf2m-pk-list { max-height: 40vh; overflow-y: auto; margin-bottom: 4px; }
        .pf2m-pk-row { display: flex; align-items: center; gap: 8px; padding: 6px; font-size: 11px; cursor: pointer; color: var(--input-text, #000);
            border-bottom: 1px solid color-mix(in srgb, var(--input-text, #000) 12%, transparent); }
        .pf2m-pk-row img, .pf2m-pk-ri { width: 32px; height: 32px; object-fit: cover; flex-shrink: 0; }
        .pf2m-pk-rt { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .pf2m-pk-row.on { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .pf2m-pk-row.on small, .pf2m-pk-row.on .pf2m-stars { color: var(--accent-text, #fff); }
        .pf2m-pk-h { font-size: 11px; margin: 6px 0 4px; color: var(--text, #000); }
        .pf2m-pk-h .button { min-width: 0; padding: 0 8px; margin-top: 4px; }
        .pf2m-ach-sel { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px; min-height: 40px; margin-bottom: 6px; }
        .pf2m-ach-it { position: relative; width: 40px; height: 40px; cursor: pointer; }
        .pf2m-ach-it img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pf2m-ach-it i { position: absolute; right: -3px; top: -3px; font-style: normal; font-size: 9px; font-weight: bold; min-width: 14px; height: 14px; border-radius: 7px;
            background: var(--accent, navy); color: var(--accent-text, #fff); display: flex; align-items: center; justify-content: center; }
        .pf2m-ta { width: 100%; box-sizing: border-box; resize: vertical; background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; font-size: 12px; }
        .pf2m-shot-meta { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
        .pf2m-shot-row { display: flex; gap: 4px; align-items: center; font-size: 11px; }
        .pf2m-shot-row input { flex: 1; min-width: 0; background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; }
        .pf2m-pie { display: flex; align-items: center; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
        .pf2m-pie span { flex: 1; min-width: 120px; font-size: 11px; color: var(--text-muted, #444); }
        .pf2m-pie .button { min-width: 0; padding: 0 10px; min-height: 28px; }

        /* ── Amigos (vista "social"; amPintar) ──
           Filas como en el PC: foto con marco y punto de presencia,
           nombre y estado. Todo con los colores del tema. */
        .pfa-tabs { margin: 0 0 8px; flex-shrink: 0; }
        /* La ventana del móvil no hace scroll (overflow hidden): sin esto
           la lista crecía hasta cortarse en el borde de la pantalla sin
           poder bajar. Como .pf2m: las pestañas se quedan arriba y el
           panel hace scroll por dentro. */
        .pfa-panel {
            padding: 0 2px 8px 0; flex: 1; min-height: 0;
            overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
        }
        .pfa-panel > * { flex-shrink: 0; }
        .pfa-input {
            width: 100%; box-sizing: border-box; height: auto; min-height: 30px; margin: 0 0 6px;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important;
        }
        .pfa-sec {
            font-size: 11px; font-weight: bold; color: var(--text, #000);
            margin: 8px 0 4px; padding-bottom: 3px; border-bottom: 1px solid var(--bezel-dark-1, #808080);
        }
        .pfa-nota { font-size: 11px; color: var(--text-muted, #444); margin: 0 0 6px; line-height: 1.4; }
        .pfa-buscar { display: flex; gap: 6px; align-items: flex-start; }
        .pfa-buscar .pfa-input { flex: 1; min-width: 0; }
        .pfa-buscar .button { min-height: 30px; }
        .pfa-grupo {
            font-size: 10px; font-weight: bold; text-transform: none; color: var(--text-muted, #444);
            margin: 6px 0 4px;
        }
        .pfa-fila {
            display: flex; align-items: center; gap: 10px; padding: 6px;
            color: var(--text, #000);
            -webkit-touch-callout: none; -webkit-user-select: none; user-select: none;
        }
        .pfa-fila + .pfa-fila { border-top: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent); }
        .pfa-fila.pfa-amigo { cursor: pointer; }
        .pfa-fila.pfa-amigo:active, .pfa-fila.long-pressing { background: color-mix(in srgb, var(--accent, navy) 18%, transparent); }
        .pfa-av {
            position: relative; width: 36px; height: 36px; flex-shrink: 0;
            background: var(--input-bg, #fff); display: flex; align-items: center; justify-content: center;
        }
        .pfa-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pfa-av.has-presence-dot { overflow: visible; }
        /* El marco (marcos.css) sobresale de la foto lo que digan sus
           --mk-* (14 % de serie; Paint, polaroid… más): se le deja ese
           hueco o lo recorta la vista (se cortaba por los lados). Igual en
           las fotos del perfil nuevo (recuadro Amigos, comentarios). */
        .pfa-av[data-marco] { margin: calc(var(--mk-t) * 36px) calc(var(--mk-r) * 36px) calc(var(--mk-b) * 36px) calc(var(--mk-l) * 36px); }
        .pf2m-fav[data-marco] { margin: calc(var(--mk-t) * 34px) calc(var(--mk-r) * 34px) calc(var(--mk-b) * 34px) calc(var(--mk-l) * 34px); position: relative; }
        /* La foto de la cabecera (48 px): si no, el Polaroid tapaba los
           iconos de redes de debajo. */
        #pf-userbar-avatar[data-marco] { margin: calc(var(--mk-t) * 48px) calc(var(--mk-r) * 48px) calc(var(--mk-b) * 48px) calc(var(--mk-l) * 48px); }
        .pf2m-com-av[data-marco] { margin: calc(var(--mk-t) * 32px) calc(var(--mk-r) * 32px) calc(var(--mk-b) * 32px) calc(var(--mk-l) * 32px); position: relative; }
        .pfa-txt { flex: 1; min-width: 0; }
        .pfa-nom { font-size: 12px; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pfa-est { font-size: 10px; color: var(--text-muted, #444); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        /* Los mismos colores de estado que el PC (perfil.css, .am-*). */
        .pfa-on .pfa-est   { color: #3a9d3a; }
        .pfa-away .pfa-est { color: #c99a0e; }
        .pfa-dnd .pfa-est  { color: #d04040; }
        .pfa-np .pfa-est   { color: var(--accent, navy); }
        .pfa-off { opacity: .75; }
        .pfa-btns .button:disabled { text-shadow: none; color: var(--text-muted, grey); }
        .pfa-btns { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; max-width: 55%; }
        .pfa-btns .button { min-width: 0; padding: 0 8px; min-height: 28px; }
        .pfa-vacio { font-size: 11px; color: var(--text-muted, #444); padding: 8px 4px; }
        /* Menú al mantener pulsado un amigo. */
        .pfa-menu-item {
            padding: 10px 8px; font-size: 12px; cursor: pointer; color: var(--text, #000);
            border-bottom: 1px solid color-mix(in srgb, var(--text, #000) 15%, transparent);
        }
        .pfa-menu-item:active { background: var(--accent, navy); color: var(--accent-text, #fff); }
        .pfa-menu-item.peligro { color: var(--error-text, #c00); font-weight: bold; }

        /* ── Vista Social ── */
        .pf-social-section-title {
            font-size: 11px; font-weight: bold;
            color: var(--text, #000); margin: 4px 0 6px;
            padding-bottom: 4px; border-bottom: 1px solid var(--bezel-dark-1, #808080);
        }
        .pf-friends-grid {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 8px; padding: 0 0 8px;
        }
        .pf-friend-card {
            display: flex; flex-direction: column;
            align-items: center; gap: 4px;
            padding: 8px 4px; cursor: pointer;
            background: var(--win-bg, silver);
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #808080),
                inset  1px  1px var(--bezel-light-1, #fff);
        }
        .pf-friend-card:active {
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
        }
        .pf-friend-card.is-explore {
            border: 1px dashed var(--bezel-dark-1, #808080);
            color: var(--text-faint, #666); background: transparent;
            box-shadow: none;
        }
        .pf-friend-av {
            width: 48px; height: 48px; flex-shrink: 0;
            background: var(--input-bg, #fff);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #808080),
                inset -1px -1px var(--bezel-light-1, #fff);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .pf-friend-av img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .pf-friend-av-plus {
            font-size: 32px; color: var(--accent, #000080); line-height: 1;
        }
        .pf-friend-label {
            font-size: 10px; text-align: center;
            line-height: 1.2; word-break: break-word;
            max-width: 100%; overflow: hidden;
        }
        /* Badge de chat — esquina inferior derecha del avatar. Solo
           aparece para amigos con seguimiento mutuo. */
        .pf-friend-av { position: relative; }
        .pf-friend-chat {
            position: absolute;
            bottom: -4px; right: -4px;
            width: 22px; height: 22px; min-width: 22px; min-height: 22px;
            padding: 0; font-size: 11px; line-height: 1;
            border-radius: 50%;
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            z-index: 1;
        }
        .pf-friend-chat:active { transform: scale(0.92); }

        /* .pf-friend-av tiene overflow:hidden para clipear la imagen
           interna. El dot vive FUERA (bottom/right -2px), así que el
           contenedor con dot necesita overflow:visible. */
        .pf-friend-av.has-presence-dot { overflow: visible !important; }

        /* Punto de presencia online/offline — esquina inferior derecha.
           Cuando hay chat-badge en la misma esquina, lo movemos arriba
           izquierda para no colisionar. */
        .pf-presence-dot {
            position: absolute;
            bottom: -2px; right: -2px;
            width: 12px; height: 12px;
            border-radius: 50%;
            background: #888;
            box-shadow: 0 0 0 2px var(--win-bg, silver),
                        inset -1px -1px 0 rgba(0,0,0,0.35);
            z-index: 2;
        }
        .pf-friend-av .pf-friend-chat + .pf-presence-dot {
            bottom: auto; right: auto;
            top: -2px; left: -2px;
        }
        .pf-presence-dot.online {
            background: #2ecc71;
            /* alternate elimina el "stop" en el rebote 50%→100% del patrón
               keyframes-en-3-puntos: con un único par from→to y direction
               alternate el ciclo es continuo en valor y en velocidad. */
            animation: pfPresencePulse 1.2s ease-in-out infinite alternate;
        }
        /* DND: el usuario tiene mute_messages activo. Rojo gana al
           verde aunque esté online (estado "no molestar"). */
        .pf-presence-dot.dnd,
        .pf-presence-dot.online.dnd {
            background: #e74c3c !important;
            animation: none !important;
        }
        @keyframes pfPresencePulse {
            from {
                box-shadow: 0 0 0 2px var(--win-bg, silver),
                            0 0 3px rgba(46,204,113,0.5);
                opacity: 0.85;
            }
            to {
                box-shadow: 0 0 0 2px var(--win-bg, silver),
                            0 0 12px rgba(46,204,113,1);
                opacity: 1;
            }
        }

        /* ── Badges de mensajes sin leer ──
           Pintamos un círculo rojo con el contador (1-9, o "9+" si excede).
           Vive sobre el avatar del amigo en Social (esquina superior derecha)
           y sobre el botón hamburguesa (esquina superior derecha) cuando hay
           cualquier chat sin leer. */
        .pf-unread-badge {
            position: absolute; top: -4px; right: -4px;
            min-width: 18px; height: 18px; padding: 0 4px;
            border-radius: 9px;
            background: #c0392b; color: #fff;
            font-size: 10px; font-weight: bold;
            display: inline-flex; align-items: center; justify-content: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4);
            z-index: 2;
        }
        /* En la hamburguesa lo desplazamos un poco más fuera para que
           no se confunda con el icono ☰. La regla base .pf-hamburger
           ya tiene position:absolute, no hace falta repetirla. */
        .pf-hamburger .pf-unread-badge { top: -6px; right: -6px; }

        /* ── Chat modal (bottom-sheet de pantalla casi completa) ── */
        .pf-chat-modal {
            width: 100%; max-width: 460px;
            height: calc(100vh - 32px);
            max-height: calc(100vh - 32px);
            display: flex; flex-direction: column;
        }
        .pf-chat-body {
            flex: 1; display: flex; flex-direction: column;
            min-height: 0; padding: 0;
            background: var(--input-bg, #fff);
        }
        .pf-chat-messages {
            flex: 1; overflow-y: auto;
            padding: 10px; display: flex; flex-direction: column;
            gap: 6px; -webkit-overflow-scrolling: touch;
        }
        .pf-chat-empty {
            text-align: center; color: var(--text-faint, #888);
            font-size: 11px; padding: 20px 10px; font-style: italic;
        }
        .pf-chat-msg { display: flex; flex-direction: column; max-width: 75%; }
        .pf-chat-msg.mine   { align-self: flex-end;   align-items: flex-end; }
        .pf-chat-msg.theirs { align-self: flex-start; align-items: flex-start; }
        .pf-chat-bubble {
            padding: 6px 10px; font-size: 12px;
            line-height: 1.35; word-break: break-word;
            border: 1px solid var(--bezel-dark-1, #808080);
        }
        .pf-chat-msg.mine .pf-chat-bubble {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }
        .pf-chat-msg.theirs .pf-chat-bubble {
            background: var(--win-bg, silver);
            color: var(--text, #000);
        }
        .pf-chat-time {
            font-size: 9px; color: var(--text-faint, #888);
            margin-top: 1px;
        }
        .pf-chat-input-row {
            display: flex; gap: 4px; padding: 6px 8px;
            border-top: 1px solid var(--bezel-dark-1, #808080);
            background: var(--win-bg, silver);
        }
        .pf-chat-input-row input {
            flex: 1; font-family: inherit; font-size: 12px;
            padding: 4px 6px;
        }
        .pf-chat-input-row button { min-height: 26px; font-size: 11px; }

        /* ── Viewing-user banner ── */
        .pf-viewing-banner {
            display: none; padding: 6px 8px;
            background: var(--accent, #000080); color: var(--accent-text, #fff);
            font-size: 11px;
            align-items: center; justify-content: space-between; gap: 8px;
        }
        .pf-viewing-banner.is-active { display: flex; }
        .pf-viewing-back {
            min-height: 22px; padding: 0 8px;
            font-size: 10px;
        }

        /* ════════════════════════════════════════════════════════
           MELON REVIEWS (ranking comunidad) — vista mobile
           ════════════════════════════════════════════════════════
           Layout vertical: period filter + category filter +
           lista (grid de slots) + paginación. Reusa los .pf-tabs /
           .pf-subtabs ya definidos para los filtros, así heredamos
           el styling Win98 (y kawaii vía overrides). */
        .pf-melon-status {
            padding: 14px;
            text-align: center;
            font-size: 11px;
            color: var(--text-faint, #808080);
        }
        /* Lista vertical igual que "Mis listas" — usa .pf-item directly,
           heredando el styling (poster 40×56 + info + sub con stars).
           Solo definimos el contenedor y los extras Melon-only
           (badge "must" y count entre paréntesis). */
        .pf-melon-list {
            flex: 1; min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }
        /* Count "(12)" — número de reseñas, gris al lado del rating */
        .pf-melon-count {
            font-size: 11px;
            color: var(--text-faint, #888);
            margin-left: 2px;
        }
        /* Badge "Melon must" inline (avg ≥ 4.6 con ≥ 2 reseñas) — placa
           gold con gradient + relieve (highlight superior + sombra
           inferior interna + drop shadow + borde oscuro). Estética
           tipo "trofeo" / medal pin. */
        .pf-melon-must-tag {
            display: inline-block;
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 10px;
            font-weight: bold;
            line-height: 1.2;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #fff;
            background: linear-gradient(to bottom, #f5d245 0%, #d4a017 55%, #b88810 100%);
            padding: 2px 8px;
            border: 1px solid #8a5800;
            white-space: nowrap;
            text-shadow: 0 1px 0 rgba(120, 70, 0, 0.55);
            box-shadow:
                inset 0  1px 0 rgba(255, 255, 255, 0.5),
                inset 0 -1px 0 rgba(140, 80, 0, 0.5),
                 0 1px 2px rgba(0, 0, 0, 0.25);
        }

        /* Paginación */
        .pf-melon-pager {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 6px 8px;
            background: var(--win-bg, silver);
            border-top: 1px solid var(--bezel-dark-1, #808080);
            flex-shrink: 0;
        }
        .pf-melon-pager:empty { display: none; }
        .pf-melon-pager .button {
            min-height: 28px;
            min-width: 44px;
            font-size: 12px;
        }
        .pf-melon-pager-info {
            font-size: 11px;
            color: var(--text, #000);
        }

        /* Modal de detalles (reseñas individuales del item) */
        .pf-melon-modal {
            width: 100%; max-width: 360px;
            max-height: 75vh;
            display: flex; flex-direction: column;
        }
        /* Body en columna: la cabecera queda fija y solo el listado de
           reseñas scrollea. */
        .pf-melon-modal-body {
            flex: 1; min-height: 0;
            display: flex; flex-direction: column;
            overflow: hidden;
            padding: 0;
        }
        #pf-melon-modal-head { flex-shrink: 0; }
        #pf-melon-modal-list { flex: 1; min-height: 0; overflow-y: auto; padding: 4px 10px 8px; }
        .pf-melon-review-row {
            display: flex;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px dotted var(--text-faint, #aaa);
        }
        .pf-melon-review-row:last-child { border-bottom: none; }
        .pf-melon-review-av {
            width: 32px; height: 32px;
            flex-shrink: 0;
            background: var(--inset-bg, #fff);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff);
        }
        .pf-melon-review-av img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .pf-melon-review-info { flex: 1; min-width: 0; }
        .pf-melon-review-hdr {
            font-size: 12px;
            color: var(--text, #000);
            display: flex; align-items: center; gap: 4px;
            flex-wrap: wrap;
        }
        .pf-melon-review-hdr strong { font-size: 13px; }
        .pf-melon-review-comment {
            font-size: 12px;
            color: var(--text, #000);
            margin-top: 3px;
            font-style: italic;
            word-break: break-word;
        }
        .pf-melon-review-time {
            font-size: 10px;
            color: var(--text-faint, #888);
            margin-top: 2px;
        }
        /* Cabecera de la ventana de reseñas: carátula + título + media/nº. */
        .pf-melon-detail-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px 10px;
            margin-bottom: 0;
            border-bottom: 2px solid var(--border, #c0c0c0);
            min-width: 0;
        }
        .pf-melon-detail-cover {
            width: 88px; aspect-ratio: 1 / 1; flex-shrink: 0;
            overflow: hidden; background: var(--input-bg, #000);
            display: flex; align-items: center; justify-content: center;
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
            margin: 2px;
        }
        /* Pelis/series/libros/juegos → póster vertical (más alto que ancho). */
        .pf-melon-detail-cover.pf-melon-cover-portrait { aspect-ratio: 2 / 3; }
        .pf-melon-detail-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pf-melon-detail-cover.pf-melon-detail-cover-ph { font-size: 28px; }
        .pf-melon-detail-hinfo { flex: 1; min-width: 0; }
        .pf-melon-detail-htitle { font-size: 14px; font-weight: bold; color: var(--text, #000); word-break: break-word; line-height: 1.25; }
        .pf-melon-detail-hartist { font-size: 11px; color: var(--text-faint, #888); margin-top: 1px; }
        .pf-melon-detail-hrating { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px; color: var(--star-color, #e8b923); font-size: 14px; }
        .pf-melon-detail-havg { font-size: 12px; font-weight: bold; }
        .pf-melon-detail-hcount { font-size: 10px; color: var(--text-faint, #888); }
        .pf-melon-detail-hruntime { font-size: 11px; color: var(--text-muted, var(--text-faint, #888)); margin-top: 3px; }
        .pf-melon-detail-hmeta { font-size: 11px; color: var(--text-faint, #888); margin-top: 3px; }
        .pf-melon-detail-eplink { cursor: pointer; text-decoration: underline; }
        /* ─── Capítulos de series ─── */
        .pf-episodes-modal { max-width: 420px; }
        .pf-episodes-body { display: flex; flex-direction: column; gap: 8px; max-height: calc(100vh - 90px); overflow: hidden; }
        .pf-ep-list { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding-right: 2px; }
        .pf-ep-empty { font-size: 12px; color: var(--text-faint, #888); text-align: center; padding: 12px 4px; }
        .pf-ep-season { margin-bottom: 8px; }
        .pf-ep-season-head { display: flex; align-items: center; gap: 8px; padding: 4px 6px; margin-bottom: 4px; background: var(--accent, #1db954); color: var(--accent-text, #fff); }
        .pf-ep-season-title { font-weight: bold; font-size: 12px; flex-shrink: 0; }
        .pf-ep-season-meta { flex: 1; font-size: 10px; opacity: 0.9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pf-ep-season-avg { flex-shrink: 0; font-size: 11px; font-weight: bold; }
        .pf-ep-season-del { min-height: 22px; padding: 0 7px; font-size: 11px; }
        .pf-ep-season-rows { display: flex; flex-direction: column; gap: 6px; }
        .pf-ep-season-empty { font-size: 11px; color: var(--text-faint, #888); text-align: center; padding: 8px 4px; }
        .pf-ep-row { display: flex; gap: 8px; align-items: flex-start; padding: 4px; border: 1px solid var(--win-border, #808080); background: var(--input-bg, #fff); color: var(--text, #000); }
        .pf-ep-thumb { width: 96px; aspect-ratio: 16/9; flex-shrink: 0; background: #000; display: flex; align-items: center; justify-content: center; font-size: 20px; overflow: hidden; }
        .pf-ep-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .pf-ep-thumb-click { cursor: pointer; }
        .pf-ep-thumb-click:active { outline: 2px solid var(--star-color, #e8b923); outline-offset: -2px; }
        .pf-ep-reviews-body { max-height: calc(100vh - 90px); overflow-y: auto; }
        .pf-ep-info { flex: 1; min-width: 0; }
        .pf-ep-title { font-size: 12px; font-weight: bold; color: var(--text, #000); word-break: break-word; line-height: 1.2; }
        .pf-ep-meta { font-size: 10px; color: var(--text-faint, #888); margin-top: 2px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .pf-ep-controls { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
        .pf-ep-controls .button { min-height: 22px; font-size: 11px; }
        .pf-ep-drag {
            flex-shrink: 0; align-self: stretch; display: flex; align-items: center; justify-content: center;
            width: 22px; color: var(--text-faint, #888); font-size: 17px; line-height: 1;
            cursor: grab; touch-action: none; user-select: none;
        }
        .pf-ep-row.pf-ep-dragging { opacity: 0.6; outline: 2px dashed var(--star-color, #e8b923); outline-offset: -2px; }
        .pf-ep-watch { font-size: 11px; display: flex; align-items: center; gap: 3px; }
        .pf-ep-wtag { font-size: 11px; color: #2e7d4f; font-weight: bold; }
        .pf-ep-addbar { flex-shrink: 0; display: flex; flex-direction: column; gap: 6px; border-top: 1px solid var(--win-border, #808080); padding-top: 8px; }
        .pf-ep-addbar input[type="text"] { width: 100%; box-sizing: border-box; }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?> pf-iface-<?= htmlspecialchars($_lookIface) ?>">

<div class="window mh-window" id="perfilWindow">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Perfil - <?= htmlspecialchars($userLabel) ?></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize" disabled></button>
            <button aria-label="Close" onclick="window.location.href='../../mobile.php';"></button>
        </div>
    </div>
    <div class="window-body">

        <!-- (Banner "Viendo perfil de … ← Mi perfil" eliminado: la
             navegación de vuelta vive en la statusbar inferior cuando
             ?as= está en la URL.) -->

        <!-- Header: izquierda avatar + cuadro de conexiones, derecha texto -->
        <div class="mh-userbar pf-userbar">
            <button class="button pf-hamburger" id="pf-hamburger" type="button" aria-label="Menú">☰</button>
            <div class="pf-userbar-left">
                <div class="mh-userbar-avatar" id="pf-userbar-avatar" data-marco-user="<?= htmlspecialchars($userKey) ?>"><?php /* marco de perfil: cambia con el perfil que se ve */ ?>
                    <?php if ($userImg): ?>
                        <img src="<?= htmlspecialchars($userImg) ?>" alt="">
                    <?php else: ?>
                        <span><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>
                    <?php endif; ?>
                </div>
                <!-- Cuadrado de conexiones: rejilla 2×2 de iconos sociales,
                     panel hundido Win98 para que destaque bajo el avatar. -->
                <div class="pf-about-socials" id="pf-about-socials"></div>
            </div>
            <div class="mh-userbar-text">
                <div class="mh-userbar-greeting" id="pf-userbar-greeting">Perfil de</div>
                <!-- Nombre + fueguito de la racha (renderPf2Racha). -->
                <div class="pf-name-row">
                    <div class="mh-userbar-name" id="pf-userbar-name"><?= htmlspecialchars($userLabel) ?></div>
                    <span class="pf2m-racha" id="pf2m-racha"></span>
                </div>
                <!-- Bio = "descripción" del escritorio. Si está vacía mostramos
                     "Sin descripción" como placeholder en gris. -->
                <div class="pf-about-bio" id="pf-about-bio">Sin descripción</div>
                <div class="pf-about-meta" id="pf-about-meta"></div>
            </div>
        </div>

        <!-- VISTA: Perfil (edit + posts) -->
        <div class="pf-view" id="pf-view-profile">
            <div class="pf-profile-actions" id="pf-profile-actions">
                <!-- Personalizar (antes "Editar perfil"): modo de los
                     expositores; los datos, con "Datos" de dentro. -->
                <button class="button" id="pf-edit-profile-btn" type="button"><img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Personalizar</button>
                <button class="button" id="pf2-guardar" type="button" hidden>✓ Guardar</button>
                <button class="button" id="pf2-datos" type="button" hidden>Datos</button>
                <button class="button" id="pf2-cancelar" type="button" hidden>Cancelar</button>
                <button class="button" id="pf-follow-btn" type="button" hidden>+ Agregar amigo</button>
                <button class="button" id="pf-chat-btn" type="button" hidden><img src="../../assets/img/appIcons/chatIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Chat</button>
                <!-- Enlace para compartir el perfil que se ve (enlace.php?perfil=…). -->
                <button class="button" id="pf-link-btn" type="button">🔗 Enlace</button>
            </div>
            <!-- Solo en Personalizar: Marco y Steam (conectar / desconectar). -->
            <div class="pf2m-steam" id="pf2m-steam" hidden></div>
            <!-- Perfil nuevo estilo Steam (renderPerfil2): nivel y mejor
                 medalla, estado, cuadrícula (Medallas, Amigos, actividad),
                 expositores y comentarios. -->
            <div class="pf2m" id="pf2m"><div class="mh-empty">Cargando…</div></div>
        </div>

        <!-- VISTA: Mis listas (tabs + sub-tabs + destacados + lista) -->
        <div class="pf-view" id="pf-view-lists" hidden>
            <!-- Pestañas categoría (scroll horizontal) -->
            <nav class="pf-tabs" id="pf-tabs">
                <button class="pf-tab active" data-cat="movies"><img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Películas <span class="pf-tab-count" id="cnt-movies">·</span></button>
                <button class="pf-tab" data-cat="series"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Series <span class="pf-tab-count" id="cnt-series">·</span></button>
                <button class="pf-tab" data-cat="books"><img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Libros <span class="pf-tab-count" id="cnt-books">·</span></button>
                <button class="pf-tab" data-cat="games"><img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Juegos <span class="pf-tab-count" id="cnt-games">·</span></button>
                <button class="pf-tab" data-cat="music"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Música <span class="pf-tab-count" id="cnt-music">·</span></button>
            </nav>

            <!-- Sub-toggle de música: álbumes vs canciones. Solo visible
                 cuando la pestaña activa es 'music'. -->
            <nav class="pf-subtabs" id="pf-music-subtabs" hidden>
                <button class="pf-subtab active" data-mtab="albums" type="button">💿 Álbumes</button>
                <button class="pf-subtab" data-mtab="songs" type="button"><img src="../../assets/img/appIcons/songIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Canciones</button>
            </nav>

            <!-- Sub-toggle de estado: pendientes / en curso / completadas.
                 Solo visible cuando la pestaña activa NO es 'music'. -->
            <nav class="pf-subtabs" id="pf-status-subtabs" hidden>
                <button class="pf-subtab active" data-st="pending"     type="button">○ Pendientes</button>
                <button class="pf-subtab"        data-st="in-progress" type="button">◑ En curso</button>
                <button class="pf-subtab"        data-st="completed"   type="button">● Completadas</button>
            </nav>

            <!-- Destacados: hasta 3 items con `featured`. Solo música. -->
            <div class="pf-destacados" id="pf-destacados" hidden>
                <div class="pf-destacados-title">★ Destacados</div>
                <div class="pf-destacados-slots" id="pf-destacados-slots"></div>
            </div>

            <!-- Lista de items dentro de un panel hundido -->
            <div class="mh-panel pf-list" id="pf-list">
                <div class="mh-empty"><span class="mh-empty-icon">⏳</span>Cargando…</div>
            </div>
        </div>

        <!-- VISTA: Amigos estilo Steam (assets/profile/amigos.php; antes
             "Social"). Tus amigos (en línea arriba) · Agregar amigo ·
             Solicitudes. La pinta amPintar(). -->
        <div class="pf-view" id="pf-view-social" hidden>
            <nav class="pf-tabs pfa-tabs">
                <button class="pf-tab active" data-amtab="amigos" type="button">Tus amigos <span id="pfa-n-amigos"></span></button>
                <button class="pf-tab" data-amtab="agregar" type="button">Agregar amigo</button>
                <button class="pf-tab" data-amtab="solicitudes" type="button">Solicitudes <span id="pfa-n-sol"></span></button>
            </nav>
            <div class="pfa-panel" data-ampanel="amigos">
                <input type="search" id="pfa-filtro" class="pfa-input" placeholder="Buscar entre tus amigos">
                <div id="pfa-lista"><div class="mh-empty">Cargando…</div></div>
            </div>
            <div class="pfa-panel" data-ampanel="agregar" hidden>
                <div class="pfa-sec">Agregar un amigo</div>
                <div class="pfa-nota">Busca a alguien por el nombre de su perfil y envíale una solicitud de amistad. Cuando la acepte, seréis amigos.</div>
                <form class="pfa-buscar" id="pfa-buscar-form">
                    <input type="search" id="pfa-q" class="pfa-input" placeholder="Nombre del perfil" autocomplete="off">
                    <button type="submit" class="button">Buscar</button>
                </form>
                <div id="pfa-resultados"></div>
            </div>
            <div class="pfa-panel" data-ampanel="solicitudes" hidden>
                <div class="pfa-sec">Recibidas</div>
                <div id="pfa-recibidas"></div>
                <div class="pfa-sec">Enviadas</div>
                <div id="pfa-enviadas"></div>
            </div>
        </div>

        <!-- VISTA: Melon reviews (ranking comunidad) -->
        <div class="pf-view" id="pf-view-melon" hidden>
            <!-- Filtro de período (Mejor del año / Reciente / Todo el tiempo) -->
            <nav class="pf-subtabs" id="pf-melon-periods">
                <button class="pf-subtab active" data-mperiod="year"    type="button"><img src="../../assets/img/appIcons/bestOfTheYearIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">Mejor del año</button>
                <button class="pf-subtab"        data-mperiod="recent"  type="button"><img src="../../assets/img/appIcons/newsIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Reciente</button>
                <button class="pf-subtab"        data-mperiod="alltime" type="button"><img src="../../assets/img/appIcons/calendarioIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Todo el tiempo</button>
            </nav>

            <!-- Filtro de categoría (Películas / Series / Libros / Juegos / Álbumes / Canciones) -->
            <nav class="pf-tabs" id="pf-melon-cats">
                <button class="pf-tab active" data-mcat="movies"><img src="../../assets/img/appIcons/pelisIcon.png"        alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Pelis</button>
                <button class="pf-tab"        data-mcat="series"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Series</button>
                <button class="pf-tab"        data-mcat="books"><img  src="../../assets/img/appIcons/booksIcon.png"        alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Libros</button>
                <button class="pf-tab"        data-mcat="games"><img  src="../../assets/img/appIcons/juegosIcon.png"       alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Juegos</button>
                <button class="pf-tab"        data-mcat="music" data-mtype="album"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Álbumes</button>
                <button class="pf-tab"        data-mcat="music" data-mtype="song"><img  src="../../assets/img/appIcons/songIcon.png"   alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 3px 0 0;">Canciones</button>
            </nav>

            <!-- Status (cargando / vacío / error) -->
            <div class="pf-melon-status" id="pf-melon-status">Selecciona una categoría</div>

            <!-- Lista de items con cover + rating -->
            <div class="mh-panel pf-melon-list" id="pf-melon-list"></div>

            <!-- Paginación -->
            <div class="pf-melon-pager" id="pf-melon-pager"></div>
        </div>

        <!-- Modal detalles de un item Melon (sus reseñas) -->
        <div class="pf-modal-backdrop" id="pf-melon-modal-backdrop" hidden>
            <div class="window pf-modal pf-melon-modal" id="pf-melon-modal">
                <div class="title-bar">
                    <div class="title-bar-text" id="pf-melon-modal-title">⭐ Reseñas</div>
                    <div class="title-bar-controls">
                        <button aria-label="Close" id="pf-melon-modal-close" type="button"></button>
                    </div>
                </div>
                <div class="window-body pf-melon-modal-body">
                    <div id="pf-melon-modal-head"></div>
                    <div id="pf-melon-modal-list"></div>
                </div>
            </div>
        </div>

        <!-- Sidebar drawer -->
        <div class="pf-drawer-backdrop" id="pf-drawer-backdrop"></div>
        <aside class="pf-drawer" id="pf-drawer">
            <div class="pf-drawer-head">
                <span id="pf-drawer-title">Menú</span>
                <button class="button pf-drawer-close" id="pf-drawer-close" type="button">×</button>
            </div>
            <div class="pf-drawer-body" id="pf-drawer-body">
                <!-- secciones inyectadas por JS según contexto -->
            </div>
        </aside>

        <!-- Status bar Win98 al pie -->
        <div class="mh-statusbar">
            <?php if ($_mobileViewingKey !== ''): ?>
                <?php /* onclick + location.replace para NO añadir entrada
                          al history del browser. Sin esto, el "‹ Menú"
                          posterior (que hace history.back) vuelve al
                          perfil ajeno previo en vez del menú real. */ ?>
                <a href="perfil-mobile.php"
                   onclick="event.preventDefault(); window.location.replace('perfil-mobile.php');">‹ Mi perfil</a>
            <?php else: ?>
                <a href="../../mobile.php">‹ Menú</a>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
/* ─── Estado y carga de datos ──────────────────────────────────── */
var API = '../../assets/profile/api.php';
/* Archivo estático de la presencia (assets/profile/presencia.php). */
var PRESENCIA_URL = '../../' + <?php require_once dirname(__DIR__, 2) . '/assets/php/timbre.php'; echo json_encode(rt_urlPresencia()); ?>;
var COUPLE_API = '../../assets/couple/api.php';
var STATE = {
    lists: null, profile: null,
    current: 'movies', musicView: 'albums', statusView: 'pending',
    /* view: 'profile' | 'lists' | 'social' — qué pintamos abajo del userbar.
       viewingUser: null cuando soy yo; un userKey cuando estoy en perfil ajeno. */
    view: 'profile',
    viewingUser: null,
    viewingLabel: null,
    myFollowing: [],       /* lista de userKeys a los que sigo (para Social) */
    myFollowers: [],       /* lista de userKeys que me siguen (para chat mutuo) */
    unreadChats: {}        /* { userKey: unreadCount } — actualizado por polling */
};
var USER_LABEL = <?= json_encode($userLabel) ?>;
/* Google Drive (Capturas de Personalizar): el mismo cliente que la Galería. */
var PF2_GOOGLE_CLIENT_ID = <?= json_encode(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '') ?>;
var PAREJA_ID  = <?= (int)$parejaId ?>;
var USER_KEY   = <?= json_encode($userKey) ?>;
/* Si llegamos vía ?as=USERKEY, el server ya emitió HTML con la
   interfaz/tema/iconos del visitado. Este global le dice al bootstrap
   que NO cargue el perfil propio sino el del visitado. */
window.__MOBILE_VIEWING_KEY = <?= json_encode($_mobileViewingKey) ?>;
/* Mapa { userKey: { label, image } } igual que el desktop, para el
   diálogo de colaboradores (avatares + listado de invitables). El
   `../../` viene de que perfil-mobile.php vive en /apps/ y getUserImage()
   devuelve la ruta relativa a la raíz del proyecto. */
var PROFILE_USERS = <?php
    $udata = [];
    foreach ($loginUsers as $k => $u) {
        $img = getUserImage($u['label']);
        $udata[$k] = [
            'label' => $u['label'],
            'image' => $img !== '' ? '../../' . $img : ''
        ];
    }
    echo json_encode($udata);
?>;

function fetchProfile() {
    return fetch(API + '?action=get-profile', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            STATE.profile = d || {};
            /* get-profile devuelve following → cache para vista Social. */
            STATE.myFollowing = (STATE.profile.following || []).slice();
            renderAbout(STATE.profile);
            /* Mi perfil: el perfil nuevo. */
            if (!STATE.viewingUser) renderPerfil2();
        })
        .catch(function(){
            var bioEl = document.getElementById('pf-about-bio');
            if (bioEl) { bioEl.textContent = '(error cargando perfil)'; bioEl.classList.add('is-empty'); }
        });
}

/* Carga mis followers (los que me siguen). Necesario para isMutual y
   para decidir si pintar el botón Chat. */
function fetchFollowers() {
    return fetch(API + '?action=get-followers', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.ok && Array.isArray(d.followers)) {
                STATE.myFollowers = d.followers.slice();
            }
        })
        .catch(function(){});
}

/* Pinta la sección "About" con bio, meta y conexiones — mismo
   conjunto de campos que `renderProfileInfo` del escritorio. */
/* Cumpleaños 'AAAA-MM-DD' → "3 de noviembre" (sin año: la edad ya va
   al lado). '' si la fecha no vale. */
function cumpleLegible(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio',
                 'agosto','septiembre','octubre','noviembre','diciembre'];
    return m ? (+m[3]) + ' de ' + meses[+m[2] - 1] : '';
}

function renderAbout(d) {
    var bioEl     = document.getElementById('pf-about-bio');
    var metaEl    = document.getElementById('pf-about-meta');
    var socialsEl = document.getElementById('pf-about-socials');
    if (!bioEl || !metaEl || !socialsEl) return;

    /* Bio = "descripción". Si está vacía deja "Sin descripción" como
       placeholder gris (idéntico al estado anterior de pf-cover-quote). */
    if (d && d.bio) {
        bioEl.textContent = '" ' + d.bio + ' "';
        bioEl.classList.remove('is-empty');
    } else {
        bioEl.textContent = 'Sin descripción';
        bioEl.classList.add('is-empty');
    }

    /* Meta: pronombres · edad · cumpleaños · país. Con cumpleaños la
       edad viene ya calculada del servidor (get-profile / view-user). */
    var parts = [];
    if (d && d.pronouns) parts.push(esc(d.pronouns));
    if (d && d.age)      parts.push(esc(d.age) + ' años');
    if (d && d.birthday) parts.push('🎂 ' + esc(cumpleLegible(d.birthday)));
    if (d && d.country)  parts.push('📍 ' + esc(d.country));
    metaEl.innerHTML = parts.length
        ? parts.join('<span class="pf-meta-sep">·</span>')
        : '';

    /* Conexiones — replica el array `socials` del escritorio
       (perfil.php:1551). Si el valor no es URL completa, construye una.
       Discord no tiene URL pública → tap copia el nombre al portapapeles. */
    /* Steam, Discord e Instagram usan PNG dedicado; Twitter mantiene emoji. */
    var STEAM_PNG_M     = '<img src="../../assets/img/appIcons/steamIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
    var DISCORD_PNG_M   = '<img src="../../assets/img/appIcons/discordIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
    var INSTAGRAM_PNG_M = '<img src="../../assets/img/appIcons/instagramIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
    var TWITTER_PNG_M   = '<img src="../../assets/img/appIcons/twitterIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
    var socials = [
        { key: 'steam',     icon: STEAM_PNG_M,     label: 'Steam',
          url: function(v){ return /^https?:\/\//.test(v) ? v : 'https://steamcommunity.com/id/' + encodeURIComponent(v); } },
        { key: 'discord',   icon: DISCORD_PNG_M,   label: 'Discord',   url: null },
        { key: 'twitter',   icon: TWITTER_PNG_M,   label: 'Twitter',
          url: function(v){ return /^https?:\/\//.test(v) ? v : 'https://x.com/' + encodeURIComponent(v.replace(/^@/, '')); } },
        { key: 'instagram', icon: INSTAGRAM_PNG_M, label: 'Instagram',
          url: function(v){ return /^https?:\/\//.test(v) ? v : 'https://instagram.com/' + encodeURIComponent(v.replace(/^@/, '')); } }
    ];
    socialsEl.innerHTML = '';
    socials.forEach(function(s){
        var v = d && d[s.key];
        if (!v) return;
        var a = document.createElement('a');
        a.className = 'pf-about-social';
        a.innerHTML = s.icon;
        if (s.url) {
            a.href   = s.url(v);
            a.target = '_blank';
            a.rel    = 'noopener noreferrer';
            a.title  = s.label + ': ' + v;
        } else {
            a.href  = '#';
            a.title = s.label + ' (tap para copiar): ' + v;
            a.addEventListener('click', function(e){
                e.preventDefault();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(v).then(function(){
                        a.style.outline = '1px dotted var(--accent, #000080)';
                        setTimeout(function(){ a.style.outline = ''; }, 700);
                    }).catch(function(){});
                }
            });
        }
        socialsEl.appendChild(a);
    });
}

function fetchLists() {
    return fetch(API + '?action=get-lists', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            STATE.lists = d || {};
            ['movies','series','books','games','music'].forEach(function(cat){
                var arr = STATE.lists[cat] || [];
                document.getElementById('cnt-' + cat).textContent = arr.length;
            });
            renderActiveList();
        })
        .catch(function(){
            document.getElementById('pf-list').innerHTML =
                '<div class="mh-empty"><span class="mh-empty-icon">⚠️</span>Error cargando listas</div>';
        });
}

/* ─── Render ───────────────────────────────────────────────────── */
/* Normaliza status a uno de los 3 canónicos del filtro: pending /
   in-progress / completed. Los valores legacy del DB (doing/done) y
   cualquier otro (dropped, '') caen en pending por defecto excepto los
   mapeos directos. */
function normalizeStatus(s) {
    if (s === 'doing' || s === 'in-progress') return 'in-progress';
    if (s === 'done'  || s === 'completed')   return 'completed';
    return 'pending';
}
/* makeStarsHtml: replica el render exacto del escritorio (perfil.php) —
   estrellas llenas, medias estrellas con clip-path:inset(0 50% 0 0) y
   estrellas vacías. Devuelve HTML para insertar dentro de .pf-stars. */
function makeStarsHtml(val, total) {
    total = total || 5;
    var h = '';
    for (var i = 1; i <= total; i++) {
        if (val >= i) {
            h += '<span>★</span>';
        } else if (val >= i - 0.5) {
            h += '<span style="clip-path:inset(0 50% 0 0);">★</span>';
        } else {
            h += '<span>☆</span>';
        }
    }
    return h;
}
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* src + data-cover-* de la foto de un item de lista. La ruta pasa por
   MhCover.url() porque las copias locales ("assets/profile/cover.php?h=…")
   son relativas al root y esta página no tiene <base href>; los data-*
   hacen que cover-fallback.js busque otra foto si esta no carga. */
function coverImgAttrs(cat, item) {
    var src = window.MhCover ? MhCover.url(item.image) : item.image;
    return 'src="' + esc(src) + '" data-cover-cat="' + esc(cat) + '" data-cover-title="' + esc(item.title || '') + '"';
}

function renderActiveList() {
    var listEl = document.getElementById('pf-list');
    var musicSubtabsEl  = document.getElementById('pf-music-subtabs');
    var statusSubtabsEl = document.getElementById('pf-status-subtabs');
    var isMusicCat = (STATE.current === 'music');
    var viewingOther = !!STATE.viewingUser;
    /* Cuando estoy viendo a otro usuario, los sub-tabs de status no
       aplican (mostramos solo reseñas). Los de música sí — el usuario
       quiere ver sus álbumes vs canciones reseñadas. */
    if (musicSubtabsEl)  musicSubtabsEl.hidden  = !isMusicCat;
    if (statusSubtabsEl) statusSubtabsEl.hidden = isMusicCat || viewingOther;
    /* Destacados también solo en música. */
    renderDestacados();

    var rawArr = (STATE.lists && STATE.lists[STATE.current]) || [];
    /* Mantenemos pares {item, origIdx} para que el filtro de música no
       rompa el lookup del modal de reseña (que indexa STATE.lists[cat]
       por la posición original). */
    var entries = rawArr.map(function(it, i){ return { it: it, origIdx: i }; });
    if (isMusicCat) {
        var wantType = STATE.musicView === 'songs' ? 'song' : 'album';
        entries = entries.filter(function(e){ return e.it && e.it.type === wantType; });
        if (viewingOther) {
            entries = entries.filter(function(e){ return e.it.review && e.it.review.stars; });
        }
    } else if (viewingOther) {
        /* En perfil ajeno mostramos solo las reseñas (no aplica status). */
        entries = entries.filter(function(e){ return e.it && e.it.review && e.it.review.stars; });
    } else {
        /* Filtro de estado para no-música. Normalizamos legacy
           (doing/done) → canónico (in-progress/completed). dropped
           se cuela en 'pending' para no esconderlo del usuario. */
        var wantSt = STATE.statusView;
        entries = entries.filter(function(e){ return e.it && normalizeStatus(e.it.status) === wantSt; });
    }
    var html = '';
    if (!entries.length) {
        var emoji = STATE.current === 'movies' ? '<img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:32px;height:32px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">'
                  : STATE.current === 'series' ? '<img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">'
                  : STATE.current === 'books'  ? '<img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">'
                  : STATE.current === 'games'  ? '<img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">'
                  : (STATE.musicView === 'songs' ? '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">' : '💿');
        var emptyMsg;
        if (viewingOther) {
            emptyMsg = 'Sin reseñas';
        } else if (isMusicCat) {
            emptyMsg = STATE.musicView === 'songs' ? 'Sin canciones' : 'Sin álbumes';
        } else {
            var stWord = STATE.statusView === 'in-progress' ? 'en curso'
                      :  STATE.statusView === 'completed'   ? 'completados'
                      :  'pendientes';
            var catWord = STATE.current === 'movies' ? 'películas'
                       :  STATE.current === 'series' ? 'series'
                       :  STATE.current === 'books'  ? 'libros'
                       :  'juegos';
            emptyMsg = 'Sin ' + catWord + ' ' + stWord;
        }
        html = '<div class="mh-empty"><span class="mh-empty-icon">' + emoji + '</span>' + emptyMsg + '</div>';
    }
    entries.forEach(function(entry){
        var it  = entry.it;
        var idx = entry.origIdx;       /* índice en STATE.lists[cat] sin filtrar */
        var isMusic = (STATE.current === 'music');
        var posterHtml = it.image
            ? '<img class="pf-item-poster" ' + coverImgAttrs(STATE.current, it) + ' alt="" loading="lazy">'
            : '<div class="pf-item-poster placeholder">' +
              (isMusic ? '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">' : STATE.current === 'movies' ? '<img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:32px;height:32px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">'
                : STATE.current === 'series' ? '<img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">'
                : STATE.current === 'books'  ? '<img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">' : '<img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">') +
              '</div>';
        var subParts = [];
        if (isMusic && it.artist) subParts.push('<span>' + esc(it.artist) + '</span>');
        /* La etiqueta de estado por item se eliminó — los sub-tabs de
           estado ya filtran por pendiente/en curso/completado, así que
           el badge era redundante. */
        var hasReview = !!(it.review && it.review.stars);
        if (hasReview) {
            subParts.push(
                '<span class="pf-stars-wrap">' +
                    '<span class="pf-stars">' + makeStarsHtml(it.review.stars) + '</span>' +
                    '<span class="pf-stars-num">' + esc(it.review.stars) + '</span>' +
                '</span>'
            );
        }
        if (it.sharedFrom) {
            subParts.push('<span class="pf-shared-from">compartido</span>');
        }
        /* Toda la fila es el target táctil cuando hay reseña — el click
           delegado lee data-cat/data-idx para abrir el modal. En música
           emitimos data-cat/data-idx SIEMPRE (con o sin reseña) para que
           el long-press pueda resolver la fila a un item sin contar
           posiciones DOM. */
        var classes = 'pf-item' + (isMusic ? ' music' : '') + (hasReview ? ' has-review' : '');
        /* data-cat/data-idx SIEMPRE en items reales (no en el row "+")
           para que el long-press y el click de reseña los resuelvan. */
        var dataAttrs = ' data-cat="' + esc(STATE.current) + '" data-idx="' + idx + '"';
        if (hasReview) dataAttrs += ' role="button" tabindex="0"';
        html += '<div class="' + classes + '"' + dataAttrs + '>' +
                  posterHtml +
                  '<div class="pf-item-info">' +
                    '<div class="pf-item-title">' + esc(it.title) + '</div>' +
                    (subParts.length ? '<div class="pf-item-sub">' + subParts.join('') + '</div>' : '') +
                  '</div>' +
                '</div>';
    });
    /* Row "+" final — solo en mi propio perfil. Cuando veo a otro
       usuario no tiene sentido añadir items a su lista. */
    if (!viewingOther) {
        var addLabel = isMusicCat
            ? (STATE.musicView === 'songs' ? 'Añadir canción' : 'Añadir álbum')
            : (STATE.current === 'movies' ? 'Añadir película'
             : STATE.current === 'series' ? 'Añadir serie'
             : STATE.current === 'books'  ? 'Añadir libro'
             : 'Añadir juego');
        html += '<div class="pf-item pf-add-item" data-act="add-item" role="button" tabindex="0">' +
                  '<span class="pf-add-icon">+</span>' +
                  '<span class="pf-add-label">' + esc(addLabel) + '</span>' +
                '</div>';
    }
    listEl.innerHTML = html;
}

/* Render de la sección Destacados (música). Hasta 3 slots; los vacíos
   son `—`. Mismo concepto que renderMusicDestacados del escritorio. */
function renderDestacados() {
    var box     = document.getElementById('pf-destacados');
    var slotsEl = document.getElementById('pf-destacados-slots');
    if (!box || !slotsEl) return;
    var isMusicCat = (STATE.current === 'music');
    box.hidden = !isMusicCat;
    if (!isMusicCat) return;

    var music = (STATE.lists && STATE.lists.music) || [];
    /* Preservamos los índices originales en STATE.lists.music para que
       el lookup del modal de reseña funcione. */
    var featured = [];
    music.forEach(function(it, origIdx){
        if (it && it.featured) featured.push({ it: it, origIdx: origIdx });
    });

    var html = '';
    for (var s = 0; s < 3; s++) {
        var entry = featured[s] || null;
        if (!entry) {
            html += '<div class="pf-dest-slot empty">—</div>';
            continue;
        }
        var it = entry.it;
        var imgHtml = it.image
            ? '<img class="pf-dest-cover" ' + coverImgAttrs('music', it) + ' alt="" loading="lazy">'
            : '<div class="pf-dest-cover pf-dest-cover-ph">' + (it.type === 'album' ? '💿' : '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">') + '</div>';
        var hasReview = !!(it.review && it.review.stars);
        var starsHtml = hasReview
            ? '<div class="pf-dest-stars">' +
                '<span class="pf-stars">' + makeStarsHtml(it.review.stars) + '</span>' +
                '<span class="pf-dest-stars-num">' + esc(it.review.stars) + '</span>' +
              '</div>'
            : '';
        /* data-cat/data-idx SIEMPRE en slots no vacíos: lo necesita
           tanto el click de reseña (cuando hay) como el long-press. */
        var classes = 'pf-dest-slot' + (hasReview ? ' has-review' : '');
        var dataAttrs = ' data-cat="music" data-idx="' + entry.origIdx + '"';
        if (hasReview) dataAttrs += ' role="button" tabindex="0" aria-label="Ver reseña"';
        html += '<div class="' + classes + '"' + dataAttrs + '>' +
                  imgHtml +
                  '<div class="pf-dest-title">' + esc(it.title) + '</div>' +
                  (it.artist ? '<div class="pf-dest-artist">' + esc(it.artist) + '</div>' : '') +
                  starsHtml +
                '</div>';
    }
    slotsEl.innerHTML = html;
}

/* ─── Tab switching ────────────────────────────────────────────── */
document.getElementById('pf-tabs').addEventListener('click', function(e){
    var btn = e.target.closest('.pf-tab');
    if (!btn) return;
    var cat = btn.dataset.cat;
    if (cat === STATE.current) return;
    STATE.current = cat;
    document.querySelectorAll('.pf-tab').forEach(function(b){
        b.classList.toggle('active', b.dataset.cat === cat);
    });
    renderActiveList();
});

/* Sub-tabs de música — álbumes vs canciones. Scope al contenedor para
   no interferir con los sub-tabs de estado. */
document.getElementById('pf-music-subtabs').addEventListener('click', function(e){
    var btn = e.target.closest('.pf-subtab');
    if (!btn) return;
    var mtab = btn.dataset.mtab;
    if (mtab === STATE.musicView) return;
    STATE.musicView = mtab;
    this.querySelectorAll('.pf-subtab').forEach(function(b){
        b.classList.toggle('active', b.dataset.mtab === mtab);
    });
    renderActiveList();
});

/* Sub-tabs de estado — pendientes / en curso / completadas.
   Solo aplica a categorías no-música. */
document.getElementById('pf-status-subtabs').addEventListener('click', function(e){
    var btn = e.target.closest('.pf-subtab');
    if (!btn) return;
    var st = btn.dataset.st;
    if (st === STATE.statusView) return;
    STATE.statusView = st;
    this.querySelectorAll('.pf-subtab').forEach(function(b){
        b.classList.toggle('active', b.dataset.st === st);
    });
    renderActiveList();
});

/* ─── Review modal — replica showReviewView del escritorio ──────
   Bottom-sheet con look Win98 que muestra el comentario en cursiva,
   las estrellas exactas (con media estrella) y la firma del usuario.
   No edita: es solo lectura, igual que en el escritorio. */
function openReviewView(item) {
    var review = item.review || {};
    var commentHtml = (review.comment && review.comment.trim())
        ? '<div class="pf-review-comment">" ' + esc(review.comment) + ' "</div>'
        : '<div class="pf-review-comment-empty">(Sin comentario)</div>';
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">★ Reseña</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                commentHtml +
                '<div class="pf-review-header">' +
                    '— ' + esc(USER_LABEL) + ' &nbsp;—&nbsp; ' +
                    '<span class="pf-stars">' + makeStarsHtml(review.stars) + '</span>' +
                    '<span class="pf-stars-num">' + esc(review.stars) + '</span>' +
                '</div>' +
                '<div class="modal-actions">' +
                    '<button class="button default" data-act="close" type="button">Cerrar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    /* Mount en <body> (NO en documentElement): el tema del usuario
       vive como `body.theme-X { --win-bg: ...; --accent: ...; }`, así
       que cualquier elemento debe ser descendiente de <body> para
       heredar las variables. */
    document.body.appendChild(bd);
    function close() { if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('[data-act="close"]').addEventListener('click', close);
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
}

/* ─── Long-press menu (música) ───────────────────────────────────
   Mantener pulsada una canción / álbum / slot destacado abre un menú
   bottom-sheet con dos acciones: Reproducir y Destacar / Quitar de
   destacados. El estado del long-press se publica en `lpFired` para
   que el click delegado de reseña pueda suprimirse durante una ventana
   corta y no se abra el modal en el mismo gesto. */

var LP_MS_PF   = 500;    /* duración para que se considere long-press */
var LP_MOVE_PF = 12;     /* px máx. antes de cancelarlo */
var lpFired    = false;  /* flag durante la ventana de "ya disparó" */

/* Generic helper para abrir un bottom-sheet de acciones con N items.
   Cada item: { act, icon, label, danger?, disabled? }. onPick(act) se
   llama tras cerrar el modal cuando el usuario tapea uno. */
function openCtxMenu(title, items, onPick) {
    var listHtml = items.map(function(it){
        return '<button class="button pf-ctx-item" data-act="' + esc(it.act) + '" type="button"' +
                (it.disabled ? ' disabled' : '') + '>' +
                  '<span class="pf-ctx-icon">' + (it.icon || '') + '</span>' +
                  '<span class="pf-ctx-label">' + esc(it.label) + '</span>' +
                '</button>';
    }).join('');
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal pf-ctx-menu">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">' + esc(title) + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-ctx-list">' + listHtml + '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    items.forEach(function(it){
        if (it.disabled) return;
        var btn = bd.querySelector('[data-act="' + it.act + '"]');
        if (!btn) return;
        btn.addEventListener('click', function(){ close(); onPick(it.act); });
    });
}

function showActionMenu(item, origIdx) {
    var cat = STATE.current;
    var isCollab = !!item.sharedFrom;
    var title = item.title || '—';

    /* Viendo a otro usuario → menú restringido (no puedo modificar sus
       items). Solo ofrezco copiarlo a mi perfil; en música además play. */
    if (STATE.viewingUser) {
        var items = [];
        if (cat === 'music') items.push({ act: 'play', icon: '▶', label: 'Reproducir' });
        items.push({ act: 'copy', icon: '➕', label: 'Añadir a mi perfil' });
        openCtxMenu(title, items, function(act){
            if (act === 'play') playMusicItem(item);
            if (act === 'copy') addItemToOwnProfile(cat, item);
        });
        return;
    }

    if (cat === 'music') {
        showMusicActionMenu(item, origIdx, title, isCollab);
        return;
    }

    /* Categorías con status (movies/series/books/games): menú según
       el estado normalizado del item. */
    var status = normalizeStatus(item.status);
    var lastLabel = isCollab ? 'Abandonar actividad' : 'Eliminar';
    var lastIcon  = isCollab ? '🚪' : '✕';
    var lastAct   = isCollab ? 'leave' : 'delete';
    var items = [];

    /* Cualquier item se puede editar en cualquier estado (título/imagen/
       reseña/duración y, en series, capítulos), no solo completado. */
    var editAction = { act: 'review', icon: '<img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Editar' };

    if (status === 'pending') {
        items.push(editAction);
        items.push({ act: 'inprogress', icon: '▶', label: 'Poner en curso' });
        items.push({ act: 'collab',     icon: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Colaboradores' });
        items.push({ act: lastAct,      icon: lastIcon, label: lastLabel });
    } else if (status === 'in-progress') {
        items.push(editAction);
        items.push({ act: 'complete', icon: '✓', label: 'Completar' });
        items.push({ act: 'unstart',  icon: '✕', label: 'Quitar de en curso' });
        items.push({ act: 'collab',   icon: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Colaboradores' });
    } else { /* completed */
        var hasReview = !!(item.review && item.review.stars);
        items.push({ act: 'review',  icon: '<img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: hasReview ? 'Editar' : 'Añadir reseña' });
        items.push({ act: lastAct,   icon: lastIcon, label: lastLabel });
    }

    openCtxMenu(title, items, function(act){
        if (act === 'inprogress') setItemStatus(cat, origIdx, 'in-progress');
        else if (act === 'complete') completeItem(cat, origIdx);
        else if (act === 'unstart')  setItemStatus(cat, origIdx, 'pending');
        else if (act === 'collab')   openCollabDialog(item, origIdx, cat);
        else if (act === 'review')   openReviewEditor(item, origIdx, cat);
        else if (act === 'delete') {
            pfConfirm('¿Eliminar "' + title + '" de tu perfil?', 'Eliminar', function(){
                deleteItem(origIdx, cat);
            });
        } else if (act === 'leave') {
            pfConfirm('¿Abandonar "' + title + '"?', 'Abandonar', function(){
                leaveCollabItem(item, cat);
            });
        }
    });
}

/* Menú largo de música — mantengo la lógica original (no status,
   sino destacados + reseña + colabs + delete). */
function showMusicActionMenu(item, origIdx, title, isCollab) {
    var music = (STATE.lists && STATE.lists.music) || [];
    var featuredCount = 0;
    for (var i = 0; i < music.length; i++) if (music[i] && music[i].featured) featuredCount++;
    var isFeatured = !!item.featured;
    var canFeature = isFeatured || featuredCount < 3;
    var featLabel  = isFeatured ? 'Quitar de destacados' : 'Destacar';
    var hasReview  = !!(item.review && item.review.stars);
    var revLabel   = hasReview ? 'Editar' : 'Añadir reseña';
    var lastLabel  = isCollab ? 'Abandonar actividad' : 'Eliminar';
    var lastIcon   = isCollab ? '🚪' : '✕';
    var lastAct    = isCollab ? 'leave' : 'delete';

    openCtxMenu(title, [
        { act: 'play',     icon: '▶', label: 'Reproducir' },
        { act: 'featured', icon: '★', label: featLabel, disabled: !canFeature },
        { act: 'review',   icon: '<img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: revLabel },
        { act: 'collab',   icon: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Colaboradores' },
        { act: lastAct,    icon: lastIcon, label: lastLabel }
    ], function(act){
        if (act === 'play')          playMusicItem(item);
        else if (act === 'featured') toggleFeatured(origIdx);
        else if (act === 'review')   openReviewEditor(item, origIdx, 'music');
        else if (act === 'collab')   openCollabDialog(item, origIdx, 'music');
        else if (act === 'delete') {
            pfConfirm('¿Eliminar "' + title + '" de tu perfil?', 'Eliminar', function(){
                deleteItem(origIdx, 'music');
            });
        } else if (act === 'leave') {
            pfConfirm('¿Abandonar "' + title + '"?', 'Abandonar', function(){
                leaveCollabItem(item, 'music');
            });
        }
    });
}

/* Cambia el status de un item y guarda. Optimistic update. */
function setItemStatus(cat, origIdx, newStatus) {
    var list = STATE.lists && STATE.lists[cat];
    if (!list || !list[origIdx]) return;
    var prev = list[origIdx].status;
    list[origIdx].status = newStatus;
    renderActiveList();
    fetch(API + '?action=save-lists', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category: cat, items: list })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && Array.isArray(d.items)) { STATE.lists[cat] = d.items; renderActiveList(); }
    })
    .catch(function(){
        list[origIdx].status = prev;
        renderActiveList();
    });
}

/* Flow de "Completar": status=completed → prompt reseña → crear momento.
   El momento se crea ANTES de la reseña para que se vea en el calendario
   aunque el usuario cancele la reseña. */
function completeItem(cat, origIdx) {
    var list = STATE.lists && STATE.lists[cat];
    if (!list || !list[origIdx]) return;
    var snapshot = {};
    var src = list[origIdx];
    for (var k in src) if (src.hasOwnProperty(k)) snapshot[k] = src[k];
    setItemStatus(cat, origIdx, 'completed');
    /* Serie completada → todos sus capítulos como vistos. */
    if (cat === 'series' && snapshot.title) {
        fetch(API + '?action=complete-series-episodes', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ seriesTitle: snapshot.title })
        }).catch(function(){});
    }
    crearMomentoFromItem(cat, snapshot);
    pfReviewPrompt(
        '"' + (snapshot.title || 'Item') + '" completada. ¿Quieres reseñarla?',
        function(){
            /* Re-resolvemos el item por si los IDs cambiaron con el save. */
            var fresh = STATE.lists[cat][origIdx] || snapshot;
            openReviewEditor(fresh, origIdx, cat);
        }
    );
}

/* Prompt "¿Reseñar?" — variante del confirm con copia adaptada. */
function pfReviewPrompt(message, onYes) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">¿Añadir reseña?</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-review-comment">' + esc(message) + '</div>' +
                '<div class="modal-actions">' +
                    '<button class="button" data-act="no" type="button">No, gracias</button>' +
                    '<button class="button default" data-act="yes" type="button">★ Sí</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="no"]').addEventListener('click', close);
    bd.querySelector('[data-act="yes"]').addEventListener('click', function(){
        close(); if (typeof onYes === 'function') onYes();
    });
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
}

/* Crea un momento (evento de calendario) para hoy con la info del item.
   Replica `crearMomentoDesdeItem` del escritorio: emoji por categoría,
   título "Verb: Item", descripción con colabs si las hay, y foto opcional. */
function crearMomentoFromItem(cat, item) {
    var emojis = { movies: '<img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', series: '<img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', books: '<img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', games: '<img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">' };
    var verbs  = { movies: 'Vista', series: 'Vista', books: 'Leído', games: 'Jugado' };
    var titulo = (verbs[cat] || 'Completado') + ': ' + (item.title || '');
    var d = new Date();
    var fecha = d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
    var desc = '';
    var withKeys = [];
    if (item.sharedFrom) withKeys.push(item.sharedFrom);
    if (item.collaborators && item.collaborators.length) {
        item.collaborators.forEach(function(k) {
            if (k !== item.sharedFrom) withKeys.push(k);
        });
    }
    if (withKeys.length) {
        var labels = withKeys.map(function(k){
            return (PROFILE_USERS[k] && PROFILE_USERS[k].label) || k;
        });
        desc = 'Con ' + labels.join(', ');
    }
    fetch(COUPLE_API + '?action=save-momento', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            pareja_id:   PAREJA_ID,
            titulo:      titulo,
            fecha:       fecha,
            descripcion: desc,
            emocion:     emojis[cat] || '😊'
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d || !d.ok || !d.id || !item.image) return;
        /* Foto del momento — endpoint específico para URLs externas. */
        fetch(COUPLE_API + '?action=save-momento-foto-url', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ momento_id: d.id, foto_url: item.image })
        }).catch(function(){});
    })
    .catch(function(){});
}

/* Confirm modal Win98 (reutilizable). Llama onOk si el usuario acepta. */
function pfConfirm(message, okLabel, onOk) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">Confirmar</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-review-comment">' + esc(message) + '</div>' +
                '<div class="modal-actions">' +
                    '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                    '<button class="button default" data-act="ok" type="button">' + esc(okLabel || 'OK') + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
    bd.querySelector('[data-act="ok"]').addEventListener('click', function(){
        close(); if (typeof onOk === 'function') onOk();
    });
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
}

/* ─── Capítulos de series (compartidos por título) ─── */
function epFmtDurM(min) {
    min = parseInt(min, 10) || 0;
    if (min <= 0) return '';
    var h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? (h + ' h' + (m ? ' ' + m + ' min' : '')) : (m + ' min');
}
function openEpisodesModal(seriesTitle, canWatch, readOnly) {
    if (!seriesTitle) return;
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    /* Desde Melon Reviews: solo lectura — sin barra para añadir. */
    var addBar = readOnly ? '' :
        '<div class="pf-ep-addbar">' +
            '<div style="display:flex;gap:6px;align-items:center;">' +
                '<label for="pf-ep-season" style="font-size:11px;">Temporada</label>' +
                '<select id="pf-ep-season" style="flex:1;"></select>' +
                '<button class="button" id="pf-ep-newseason" type="button">+ Temp.</button>' +
            '</div>' +
            '<input type="text" id="pf-ep-title" placeholder="Título del capítulo">' +
            '<input type="text" id="pf-ep-image" placeholder="Imagen de preview (URL)">' +
            '<div style="display:flex;gap:6px;align-items:center;">' +
                '<input type="number" id="pf-ep-dur" placeholder="min" min="0" inputmode="numeric" style="width:80px;">' +
                '<button class="button default" id="pf-ep-add" type="button">+ Añadir</button>' +
            '</div>' +
        '</div>';
    bd.innerHTML =
        '<div class="window pf-modal pf-episodes-modal">' +
            '<div class="title-bar"><div class="title-bar-text">🎬 Capítulos</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div>' +
            '<div class="window-body pf-episodes-body">' +
                '<div class="pf-ep-list" id="pf-ep-list"><div class="ma-status">Cargando…</div></div>' +
                addBar +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    var listEl = bd.querySelector('#pf-ep-list');
    var eps = [];
    var extraSeasons = [];
    function seasonsList() {
        var set = {};
        eps.forEach(function(ep){ set[ep.season || 1] = true; });
        extraSeasons.forEach(function(s){ set[s] = true; });
        var arr = Object.keys(set).map(function(s){ return parseInt(s, 10); });
        if (!arr.length) arr = [1];
        arr.sort(function(a, b){ return a - b; });
        return arr;
    }
    function syncSeasonSelect() {
        var sel = bd.querySelector('#pf-ep-season');
        if (!sel) return;
        var prev = sel.value;
        var seasons = seasonsList();
        sel.innerHTML = seasons.map(function(s){ return '<option value="' + s + '">Temporada ' + s + '</option>'; }).join('');
        if (prev && seasons.indexOf(parseInt(prev, 10)) !== -1) sel.value = prev;
    }
    function renderRow(ep, n) {
        var dur = epFmtDurM(ep.duration);
        var avg = (ep.avgStars != null) ? (makeStarsHtml(ep.avgStars, 5) + ' ' + ep.avgStars.toFixed(1) + ' (' + ep.reviewCount + ')') : 'Sin reseñas';
        var thumbInner = ep.image ? '<img src="' + esc(ep.image) + '" alt="">' : '🎬';
        if (readOnly) {
            return '<div class="pf-ep-row">' +
                '<div class="pf-ep-thumb pf-ep-thumb-click" data-rev="' + ep.id + '">' + thumbInner + '</div>' +
                '<div class="pf-ep-info">' +
                    '<div class="pf-ep-title">' + n + '. ' + esc(ep.title) + '</div>' +
                    '<div class="pf-ep-meta">' + (dur ? dur + ' · ' : '') + avg + '</div>' +
                '</div>' +
            '</div>';
        }
        var watch = canWatch ? '<label class="pf-ep-watch"><input type="checkbox" data-w="' + ep.id + '"' + (ep.watched ? ' checked' : '') + '> Visto</label>' : (ep.watched ? '<span class="pf-ep-wtag">✓ Visto</span>' : '');
        /* Indicador discreto de "tienes reseña"; editar/eliminar viven en
           el menú que se abre manteniendo pulsado el capítulo. */
        var chartIco = (ep.myStars != null) ? '<img src="../../assets/img/appIcons/chatIcon.png" alt="Reseñado" class="pf-ep-chart-ico" style="width:15px;height:15px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' : '';
        var controls = watch + chartIco;
        return '<div class="pf-ep-row" data-ep-id="' + ep.id + '">' +
            '<div class="pf-ep-drag" data-drag="' + ep.id + '" title="Arrastrar para reordenar">⠿</div>' +
            '<div class="pf-ep-thumb">' + thumbInner + '</div>' +
            '<div class="pf-ep-info">' +
                '<div class="pf-ep-title">' + n + '. ' + esc(ep.title) + '</div>' +
                '<div class="pf-ep-meta">' + (dur ? dur + ' · ' : '') + avg + '</div>' +
                (controls ? '<div class="pf-ep-controls">' + controls + '</div>' : '') +
            '</div>' +
        '</div>';
    }
    function render() {
        syncSeasonSelect();
        if (!eps.length && !extraSeasons.length) { listEl.innerHTML = '<div class="pf-ep-empty">Sin capítulos. Añade el primero abajo.</div>'; return; }
        var seasons = seasonsList();
        listEl.innerHTML = seasons.map(function(seasonNum){
            var group = eps.filter(function(ep){ return (ep.season || 1) === seasonNum; });
            var rowsHtml = group.length
                ? group.map(function(ep, i){ return renderRow(ep, i + 1); }).join('')
                : '<div class="pf-ep-season-empty">Sin capítulos en esta temporada.</div>';
            var delBtn = readOnly ? '' : '<button class="button pf-ep-season-del" data-delseason="' + seasonNum + '">✕</button>';
            /* Resumen de la temporada: nº de capítulos, duración total y
               nota media (media de las notas medias de sus capítulos). */
            var cnt = group.length;
            var durSum = group.reduce(function(a, ep){ return a + (ep.duration || 0); }, 0);
            var rated = group.filter(function(ep){ return ep.avgStars != null; });
            var savg = rated.length ? (rated.reduce(function(a, ep){ return a + ep.avgStars; }, 0) / rated.length) : null;
            var metaBits = [cnt + (cnt === 1 ? ' cap.' : ' caps.')];
            if (durSum > 0) metaBits.push(epFmtDurM(durSum));
            var avgHtml = (savg != null) ? '<span class="pf-ep-season-avg">★ ' + savg.toFixed(1) + '</span>' : '';
            return '<div class="pf-ep-season">' +
                '<div class="pf-ep-season-head">' +
                    '<span class="pf-ep-season-title">Temporada ' + seasonNum + '</span>' +
                    '<span class="pf-ep-season-meta">' + metaBits.join(' · ') + '</span>' +
                    avgHtml + delBtn +
                '</div>' +
                '<div class="pf-ep-season-rows" data-season="' + seasonNum + '">' + rowsHtml + '</div>' +
            '</div>';
        }).join('');
    }
    function load() {
        fetch(API + '?action=series-episodes&seriesTitle=' + encodeURIComponent(seriesTitle), { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){ eps = (d && d.episodes) || []; render(); })
            .catch(function(){ listEl.innerHTML = '<div class="pf-ep-empty">Error.</div>'; });
    }
    listEl.addEventListener('change', function(e){
        var cb = e.target.closest('input[data-w]'); if (!cb) return;
        var id = parseInt(cb.getAttribute('data-w'), 10);
        var ep = eps.filter(function(x){ return x.id === id; })[0]; if (ep) ep.watched = cb.checked;
        fetch(API + '?action=series-episode-state', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ episodeId: id, watched: cb.checked }) }).catch(function(){});
    });
    /* Reordenar arrastrando el tirador (pointer events: ratón + táctil).
       El reordenado es dentro de la misma temporada (su contenedor). */
    var dragRow = null, dragCont = null;
    function epDragAfter(y) {
        var els = Array.prototype.slice.call((dragCont || listEl).querySelectorAll('.pf-ep-row:not(.pf-ep-dragging)'));
        var closest = null, closestOff = -Infinity;
        els.forEach(function(ch){
            var box = ch.getBoundingClientRect();
            var off = y - box.top - box.height / 2;
            if (off < 0 && off > closestOff) { closestOff = off; closest = ch; }
        });
        return closest;
    }
    function epDragMove(e){
        if (!dragRow) return;
        e.preventDefault();
        var after = epDragAfter(e.clientY);
        if (after == null) (dragCont || listEl).appendChild(dragRow);
        else if (after !== dragRow) (dragCont || listEl).insertBefore(dragRow, after);
    }
    function epDragUp(){
        if (!dragRow) return;
        dragRow.classList.remove('pf-ep-dragging');
        dragRow = null; dragCont = null;
        document.removeEventListener('pointermove', epDragMove);
        document.removeEventListener('pointerup', epDragUp);
        document.removeEventListener('pointercancel', epDragUp);
        var ids = Array.prototype.slice.call(listEl.querySelectorAll('.pf-ep-row')).map(function(r){ return parseInt(r.getAttribute('data-ep-id'), 10); });
        eps.sort(function(a, b){ return ids.indexOf(a.id) - ids.indexOf(b.id); });
        render();
        fetch(API + '?action=reorder-series-episodes', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ seriesTitle: seriesTitle, order: ids }) }).catch(function(){});
    }
    listEl.addEventListener('pointerdown', function(e){
        var h = e.target.closest('[data-drag]'); if (!h) return;
        var row = h.closest('.pf-ep-row'); if (!row) return;
        e.preventDefault();
        dragRow = row; dragCont = row.parentNode; row.classList.add('pf-ep-dragging');
        document.addEventListener('pointermove', epDragMove);
        document.addEventListener('pointerup', epDragUp);
        document.addEventListener('pointercancel', epDragUp);
    });
    listEl.addEventListener('click', function(e){
        var rv = e.target.closest('[data-rev]');
        if (rv) { var rvid = parseInt(rv.getAttribute('data-rev'), 10); var rvep = eps.filter(function(x){ return x.id === rvid; })[0]; if (rvep) openEpReviewsModal(rvep); return; }
        var dsn = e.target.closest('[data-delseason]');
        if (dsn) {
            var sNum = parseInt(dsn.getAttribute('data-delseason'), 10);
            var inSeason = eps.filter(function(x){ return (x.season || 1) === sNum; });
            if (!inSeason.length) { extraSeasons = extraSeasons.filter(function(s){ return s !== sNum; }); render(); return; }
            if (confirm('¿Eliminar la Temporada ' + sNum + ' y sus ' + inSeason.length + ' capítulo(s)? Afecta a todos.')) {
                fetch(API + '?action=delete-series-season', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ seriesTitle: seriesTitle, season: sNum }) })
                    .then(function(){ eps = eps.filter(function(x){ return (x.season || 1) !== sNum; }); extraSeasons = extraSeasons.filter(function(s){ return s !== sNum; }); render(); }).catch(function(){});
            }
            return;
        }
    });
    /* Menú del capítulo (Editar / Eliminar): se abre manteniendo pulsado
       (long-press) o con click derecho. No se ofrece en solo lectura. */
    function openEpRowMenu(ep) {
        openCtxMenu(ep.title || 'Capítulo', [
            { act: 'edit',   icon: '<img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Editar' },
            { act: 'delete', icon: '✕', label: 'Eliminar' }
        ], function(act){
            if (act === 'edit') openEpEditorModal(ep, seriesTitle, load);
            else if (act === 'delete') {
                if (confirm('¿Quitar este capítulo? Afecta a todos.')) {
                    fetch(API + '?action=delete-series-episode', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: ep.id }) }).then(function(){ eps = eps.filter(function(y){ return y.id !== ep.id; }); render(); }).catch(function(){});
                }
            }
        });
    }
    function epRowFromEvent(target) {
        if (readOnly || !target || !target.closest) return null;
        if (target.closest('[data-drag]') || target.closest('.pf-ep-watch')) return null;
        var row = target.closest('.pf-ep-row[data-ep-id]'); if (!row) return null;
        var id = parseInt(row.getAttribute('data-ep-id'), 10);
        return eps.filter(function(x){ return x.id === id; })[0] || null;
    }
    var epLpT = null, epLsx = 0, epLsy = 0;
    listEl.addEventListener('touchstart', function(e){
        var t = e.touches && e.touches[0]; if (!t) return;
        var ep = epRowFromEvent(e.target); if (!ep) return;
        epLsx = t.clientX; epLsy = t.clientY;
        if (epLpT) clearTimeout(epLpT);
        epLpT = setTimeout(function(){
            epLpT = null;
            try { navigator.vibrate && navigator.vibrate(30); } catch (_) {}
            openEpRowMenu(ep);
        }, LP_MS_PF);
    }, { passive: true });
    listEl.addEventListener('touchmove', function(e){
        if (!epLpT) return;
        var t = e.touches && e.touches[0]; if (!t) return;
        if (Math.abs(t.clientX - epLsx) > LP_MOVE_PF || Math.abs(t.clientY - epLsy) > LP_MOVE_PF) { clearTimeout(epLpT); epLpT = null; }
    }, { passive: true });
    function epLpEnd(){ if (epLpT) { clearTimeout(epLpT); epLpT = null; } }
    listEl.addEventListener('touchend', epLpEnd);
    listEl.addEventListener('touchcancel', epLpEnd);
    listEl.addEventListener('contextmenu', function(e){
        var ep = epRowFromEvent(e.target); if (!ep) return;
        e.preventDefault();
        openEpRowMenu(ep);
    });
    var addEl = bd.querySelector('#pf-ep-add');
    if (addEl) addEl.addEventListener('click', function(){
        var t = bd.querySelector('#pf-ep-title').value.trim(); if (!t) return;
        var img = bd.querySelector('#pf-ep-image').value.trim();
        var dur = parseInt(bd.querySelector('#pf-ep-dur').value, 10) || 0;
        var seasonEl = bd.querySelector('#pf-ep-season');
        var season = seasonEl ? (parseInt(seasonEl.value, 10) || 1) : 1;
        fetch(API + '?action=save-series-episode', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ seriesTitle: seriesTitle, title: t, image: img, duration: dur, season: season }) })
            .then(function(){
                bd.querySelector('#pf-ep-title').value = ''; bd.querySelector('#pf-ep-image').value = ''; bd.querySelector('#pf-ep-dur').value = '';
                /* Recargar conservando la temporada seleccionada. */
                fetch(API + '?action=series-episodes&seriesTitle=' + encodeURIComponent(seriesTitle), { credentials: 'same-origin' })
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(d){ eps = (d && d.episodes) || []; render(); var s = bd.querySelector('#pf-ep-season'); if (s) s.value = season; })
                    .catch(function(){});
            }).catch(function(){});
    });
    /* Añadir temporada (transitoria hasta que tenga capítulos). */
    var newSeasonEl = bd.querySelector('#pf-ep-newseason');
    if (newSeasonEl) newSeasonEl.addEventListener('click', function(){
        var seasons = seasonsList();
        var next = (seasons.length ? seasons[seasons.length - 1] : 0) + 1;
        extraSeasons.push(next);
        render();
        var s = bd.querySelector('#pf-ep-season'); if (s) s.value = next;
    });
    load();
}
/* Lista de reseñas de un capítulo (solo lectura). */
function openEpReviewsModal(ep) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar"><div class="title-bar-text">★ Reseñas · ' + esc(ep.title || '—') + '</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div>' +
            '<div class="window-body pf-ep-reviews-body"><div class="pf-ep-empty">Cargando…</div></div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    var body = bd.querySelector('.pf-ep-reviews-body');
    fetch(API + '?action=series-episode-reviews&episodeId=' + ep.id, { credentials: 'same-origin' })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
            var reviews = (d && d.reviews) || [];
            if (!reviews.length) { body.innerHTML = '<div class="pf-ep-empty">Este capítulo no tiene reseñas todavía.</div>'; return; }
            body.innerHTML = reviews.map(function(rev){
                var src = rev.userImg || '';
                if (src && src.indexOf('/') !== 0 && src.indexOf('http') !== 0 && src.indexOf('../') !== 0) src = '../../' + src;
                var av = src ? '<img src="' + esc(src) + '" alt="">' : '👤';
                return '<div class="pf-melon-review-row">' +
                    '<div class="pf-melon-review-av" data-marco-user="' + esc(rev.user || '') + '">' + av + '</div>' +
                    '<div class="pf-melon-review-info">' +
                        '<div class="pf-melon-review-hdr"><strong>' + esc(rev.userLabel || '') + '</strong> ' + makeStarsHtml(rev.stars, 5) + ' <span class="pf-melon-review-num">' + rev.stars + '</span></div>' +
                        (rev.comment ? '<div class="pf-melon-review-comment">" ' + esc(rev.comment) + ' "</div>' : '') +
                    '</div>' +
                '</div>';
            }).join('');
        })
        .catch(function(){ body.innerHTML = '<div class="pf-ep-empty">Error al cargar reseñas.</div>'; });
}
/* Editor de capítulo: título, imagen y duración (compartidos) + reseña
   propia (estrellas + comentario). */
function openEpEditorModal(ep, seriesTitle, onSaved) {
    var sel = ep.myStars || 0;
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar"><div class="title-bar-text">✏ Editar capítulo</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div>' +
            '<div class="window-body">' +
                '<div class="pf-rev-edit-label">Título</div>' +
                '<input type="text" id="pf-epe-title" maxlength="255" style="width:100%;box-sizing:border-box;">' +
                '<div class="pf-rev-edit-label">Imagen (URL)</div>' +
                '<input type="text" id="pf-epe-image" maxlength="2000" placeholder="https://..." style="width:100%;box-sizing:border-box;">' +
                '<div class="pf-rev-edit-label">Duración (min)</div>' +
                '<input type="number" id="pf-epe-dur" min="0" step="1" inputmode="numeric" placeholder="0" class="pf-rev-edit-runtime">' +
                '<div class="pf-rev-edit-label">Puntuación</div>' +
                '<div class="pf-rev-edit-stars" id="pf-epr-stars"></div>' +
                '<div class="pf-rev-edit-label">Comentario</div>' +
                '<textarea class="pf-rev-edit-comment" id="pf-epr-comment" maxlength="500" placeholder="Opcional"></textarea>' +
                '<div class="modal-actions">' +
                    '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                    '<button class="button default" data-act="save" type="button">Guardar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    var titleEl = bd.querySelector('#pf-epe-title');
    var imageEl = bd.querySelector('#pf-epe-image');
    var durEl   = bd.querySelector('#pf-epe-dur');
    var starsBox = bd.querySelector('#pf-epr-stars');
    var commentEl = bd.querySelector('#pf-epr-comment');
    titleEl.value = ep.title || '';
    imageEl.value = ep.image || '';
    durEl.value = ep.duration ? ep.duration : '';
    commentEl.value = ep.myComment || '';
    function renderStars() {
        var html = '';
        for (var i = 1; i <= 5; i++) html += '<span class="pf-rev-edit-star' + (i > sel ? ' empty' : '') + '" data-v="' + i + '">' + (i > sel ? '☆' : '★') + '</span>';
        html += '<span class="pf-rev-edit-num">' + (sel || '') + '</span>';
        starsBox.innerHTML = html;
        Array.prototype.forEach.call(starsBox.querySelectorAll('.pf-rev-edit-star'), function(el){
            el.addEventListener('click', function(){ var v = parseInt(el.dataset.v, 10); sel = (v === sel) ? (v - 1) : v; renderStars(); });
        });
    }
    renderStars();
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    bd.querySelector('[data-act="save"]').addEventListener('click', function(){
        var title = titleEl.value.trim() || ep.title || '';
        var image = imageEl.value.trim();
        var dur = parseInt(durEl.value, 10) || 0;
        var comment = commentEl.value.trim();
        fetch(API + '?action=save-series-episode', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: ep.id, seriesTitle: seriesTitle, title: title, image: image, duration: dur, season: ep.season || 1 }) })
            .then(function(){
                return fetch(API + '?action=series-episode-state', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ episodeId: ep.id, stars: sel, comment: comment }) });
            })
            .then(function(){ close(); if (onSaved) onSaved(); }).catch(function(){});
    });
}

/* Visor de PDF embebido (libros). Modal a pantalla casi completa con un
   iframe. Solo URLs http(s). */
function openPdfViewerModal(url, title) {
    if (!url || !/^https?:\/\//i.test(url)) { alert('El enlace del PDF debe empezar por http:// o https://'); return; }
    /* Drive → su visor /preview (se ve embebido en móvil). Resto → visor
       completo de PDF.js (barra de páginas/zoom/descarga) sobre el proxy. */
    var embedSrc;
    if (/drive\.google\.com/i.test(url)) {
        var dm = url.match(/\/file\/d\/([^\/?#]+)/) || url.match(/[?&]id=([^&]+)/);
        embedSrc = dm ? ('https://drive.google.com/file/d/' + dm[1] + '/preview') : url;
    } else {
        var fileParam = '../../../profile/pdf-proxy.php?url=' + encodeURIComponent(url);
        /* ?v=<mtime> rompe la caché del navegador/edge cuando cambia el
           visor (evita servir un viewer.html viejo cacheado). */
        embedSrc = '../../assets/vendor/pdfjs/web/viewer.html?v=<?php echo @filemtime(__DIR__ . "/../../assets/vendor/pdfjs/web/viewer.html") ?: 0; ?>&file=' + encodeURIComponent(fileParam);
    }
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal pf-pdf-modal" style="width:96vw;max-width:96vw;height:90vh;display:flex;flex-direction:column;">' +
            '<div class="title-bar"><div class="title-bar-text">📖 ' + esc(title || 'PDF') + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Maximize" type="button"></button>' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div></div>' +
            '<div class="window-body" style="flex:1;min-height:0;padding:0;overflow:hidden;">' +
                '<iframe src="' + esc(embedSrc) + '" style="width:100%;height:100%;border:0;display:block;"></iframe>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    var win = bd.querySelector('.pf-pdf-modal');
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('button[aria-label="Close"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    /* Maximizar: alterna entre 96vw/90vh y pantalla completa real. */
    var maxed = false;
    bd.querySelector('button[aria-label="Maximize"]').addEventListener('click', function(){
        maxed = !maxed;
        if (maxed) {
            bd.style.padding = '0';
            win.style.width = '100vw'; win.style.maxWidth = '100vw'; win.style.height = '100vh';
        } else {
            bd.style.padding = '';
            win.style.width = '96vw'; win.style.maxWidth = '96vw'; win.style.height = '90vh';
        }
    });
}

/* Editor de reseña: 5 estrellas (full-star, sin medios para móvil) +
   textarea. Save → actualiza item.review + POST save-lists +
   notify-review. Delete → quita review + save-lists. */
function openReviewEditor(item, origIdx, cat) {
    cat = cat || 'music';
    var cur = (item.review && item.review.stars) ? Math.round(item.review.stars) : 0;
    var curComment = (item.review && item.review.comment) ? item.review.comment : '';
    var hadReview = cur > 0;
    /* Edición del item: título, imagen y duración (pelis) / capítulos (libros).
       Series no usa número: botón a la ventana de capítulos. */
    var RW_RT_LABEL = { movies: 'Duración (min)', books: 'Nº de capítulos' };
    var rtLabel = RW_RT_LABEL[cat];
    var isSeries = (cat === 'series');
    /* La nota y la reseña solo si el item está completado (la música no
       tiene estado → siempre se puede reseñar). */
    var allowReview = (cat === 'music') || (normalizeStatus(item.status) === 'completed');

    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">✏ ' + esc(item.title || '—') + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-rev-edit-label">Título</div>' +
                '<input type="text" id="pf-rev-edit-title" maxlength="200" style="width:100%;box-sizing:border-box;">' +
                '<div class="pf-rev-edit-label">Imagen (URL, opcional)</div>' +
                '<input type="text" id="pf-rev-edit-image" maxlength="500" placeholder="Déjala vacía y se busca sola" style="width:100%;box-sizing:border-box;">' +
                (rtLabel ? '<div class="pf-rev-edit-label">' + rtLabel + '</div>' +
                           '<input type="number" id="pf-rev-edit-runtime" min="0" step="1" inputmode="numeric" placeholder="0" class="pf-rev-edit-runtime">' : '') +
                (isSeries ? '<div class="pf-rev-edit-label">Capítulos</div>' +
                            '<button class="button" id="pf-rev-edit-eps" type="button" style="width:100%;box-sizing:border-box;">🎬 Ver / editar capítulos</button>' : '') +
                (cat === 'books' ? '<div class="pf-rev-edit-label">PDF (URL) — al clicar el título del libro se abre</div>' +
                            '<input type="text" id="pf-rev-edit-pdf" maxlength="2000" placeholder="https://....pdf  o  Drive .../preview" style="width:100%;box-sizing:border-box;">' +
                            '<button class="button" id="pf-rev-edit-pdf-btn" type="button" style="width:100%;box-sizing:border-box;margin-top:4px;">📖 Abrir PDF</button>' : '') +
                (allowReview
                    ? '<div class="pf-rev-edit-label">Puntuación</div>' +
                      '<div class="pf-rev-edit-stars" id="pf-rev-edit-stars"></div>' +
                      '<div class="pf-rev-edit-label">Comentario</div>' +
                      '<textarea class="pf-rev-edit-comment" id="pf-rev-edit-comment" maxlength="500" placeholder="Opcional"></textarea>'
                    : '<div class="pf-rev-edit-label" style="color:var(--text-faint,#888);">Marca el item como completado para poder puntuarlo y reseñarlo.</div>') +
                '<div class="modal-actions">' +
                    ((hadReview && allowReview) ? '<button class="button danger" data-act="delete" type="button"><img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Borrar</button>' : '') +
                    '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                    '<button class="button default" data-act="save" type="button">Guardar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);

    var starsBox = bd.querySelector('#pf-rev-edit-stars');
    var commentEl = bd.querySelector('#pf-rev-edit-comment');
    if (commentEl) commentEl.value = curComment;
    var titleEl = bd.querySelector('#pf-rev-edit-title');
    var imageEl = bd.querySelector('#pf-rev-edit-image');
    var runtimeEl = bd.querySelector('#pf-rev-edit-runtime');
    var epsBtn = bd.querySelector('#pf-rev-edit-eps');
    if (epsBtn) epsBtn.addEventListener('click', function(){
        openEpisodesModal((titleEl.value.trim() || item.title || ''), normalizeStatus(item.status) === 'in-progress');
    });
    titleEl.value = item.title || '';
    imageEl.value = item.image || '';
    if (runtimeEl) runtimeEl.value = item.runtime ? item.runtime : '';
    /* Libros: PDF compartido por título. Carga el actual y permite abrirlo. */
    var pdfEl = bd.querySelector('#pf-rev-edit-pdf');
    if (pdfEl) {
        fetch(API + '?action=book-pdf&bookTitle=' + encodeURIComponent(item.title || ''), { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){ if (d && d.url) pdfEl.value = d.url; }).catch(function(){});
        var pdfBtn = bd.querySelector('#pf-rev-edit-pdf-btn');
        if (pdfBtn) pdfBtn.addEventListener('click', function(){
            var u = pdfEl.value.trim();
            if (u) openPdfViewerModal(u, titleEl.value.trim() || item.title || 'Libro');
        });
    }
    var sel = cur;

    function renderStars() {
        var html = '';
        for (var i = 1; i <= 5; i++) {
            html += '<span class="pf-rev-edit-star' + (i > sel ? ' empty' : '') +
                    '" data-v="' + i + '">' + (i > sel ? '☆' : '★') + '</span>';
        }
        html += '<span class="pf-rev-edit-num">' + (sel || '') + '</span>';
        starsBox.innerHTML = html;
        Array.prototype.forEach.call(starsBox.querySelectorAll('.pf-rev-edit-star'), function(el){
            el.addEventListener('click', function(){
                var v = parseInt(el.dataset.v, 10);
                /* Tap en la estrella seleccionada → desactiva (queda en v-1). */
                sel = (v === sel) ? (v - 1) : v;
                renderStars();
            });
        });
    }
    if (starsBox) renderStars();

    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });

    bd.querySelector('[data-act="save"]').addEventListener('click', function(){
        var list = STATE.lists && STATE.lists[cat];
        if (!list || !list[origIdx]) { close(); return; }
        var newTitle = titleEl.value.trim();
        if (newTitle) list[origIdx].title = newTitle;
        list[origIdx].image = imageEl.value.trim();
        if (rtLabel && runtimeEl) {
            var rt = parseInt(runtimeEl.value, 10) || 0;
            if (rt > 0) list[origIdx].runtime = rt; else delete list[origIdx].runtime;
        }
        /* Libros: guardar/actualizar el PDF compartido por título. */
        if (cat === 'books' && pdfEl) {
            fetch(API + '?action=save-book-pdf', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bookTitle: list[origIdx].title, url: pdfEl.value.trim() })
            }).catch(function(){});
        }
        /* La reseña solo se toca si está permitida (item completado o
           música). En otros estados se preserva lo que hubiera. */
        if (allowReview) {
            if (sel > 0) {
                list[origIdx].review = {
                    stars: sel,
                    comment: commentEl ? commentEl.value.trim() : '',
                    reviewedAt: Math.floor(Date.now() / 1000)
                };
            } else {
                delete list[origIdx].review;
            }
        }
        renderActiveList();
        fetch(API + '?action=save-lists', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: list })
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && Array.isArray(d.items)) { STATE.lists[cat] = d.items; renderActiveList(); }
            /* Notify a followers solo si hay reseña — mismo flow que escritorio. */
            if (allowReview && sel > 0) {
                fetch(API + '?action=notify-review', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category: cat, itemTitle: (titleEl.value.trim() || item.title || ''), mtype: item.type || '' })
                }).catch(function(){});
            }
        })
        .catch(function(){});
        close();
    });

    var delBtn = bd.querySelector('[data-act="delete"]');
    if (delBtn) delBtn.addEventListener('click', function(){
        var list = STATE.lists && STATE.lists[cat];
        if (!list || !list[origIdx]) { close(); return; }
        delete list[origIdx].review;
        renderActiveList();
        fetch(API + '?action=save-lists', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: list })
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && Array.isArray(d.items)) { STATE.lists[cat] = d.items; renderActiveList(); }
        })
        .catch(function(){});
        close();
    });
}

/* Diálogo de colaboradores. Si el item es shared (isCollab) muestra
   solo el host + otros (read-only). Si soy el dueño, muestra colabs
   actuales con botón Eliminar + lista de invitables con botón Invitar.
   `cat` es la categoría del item (music/movies/series/books/games). */
function openCollabDialog(item, origIdx, cat) {
    cat = cat || 'music';
    var isCollab = !!item.sharedFrom;
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text"><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> ' + esc(item.title || '—') + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div id="pf-collab-list"></div>' +
                '<div class="pf-collab-status" id="pf-collab-status"></div>' +
                '<div class="modal-actions">' +
                    '<button class="button default" data-act="close" type="button">Cerrar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    var listEl   = bd.querySelector('#pf-collab-list');
    var statusEl = bd.querySelector('#pf-collab-status');

    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="close"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });

    function sectionTitle(text) {
        var el = document.createElement('div');
        el.className = 'pf-collab-section-title';
        el.textContent = text;
        return el;
    }
    function avRow(uKey, uInfo, btnLabel, onClick) {
        var row = document.createElement('div');
        row.className = 'pf-collab-row';
        var av = document.createElement('div');
        av.className = 'pf-collab-av';
        av.setAttribute('data-marco-user', uKey);   /* marco (melon-marcos.js) */
        if (uInfo.image) {
            var im = document.createElement('img');
            im.src = uInfo.image; im.alt = uInfo.label;
            av.appendChild(im);
        } else {
            var ph = document.createElement('div');
            ph.className = 'pf-collab-av-ph';
            ph.innerHTML = '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">';
            av.appendChild(ph);
        }
        row.appendChild(av);
        var lbl = document.createElement('span');
        lbl.className = 'pf-collab-label';
        lbl.textContent = uInfo.label;
        row.appendChild(lbl);
        if (btnLabel && typeof onClick === 'function') {
            var btn = document.createElement('button');
            btn.className = 'button pf-collab-action';
            btn.textContent = btnLabel;
            btn.addEventListener('click', function(){ onClick(btn); });
            row.appendChild(btn);
        }
        return row;
    }

    if (isCollab) {
        /* Soy colaborador → muestro host + otros (read-only). */
        var ownerKey = item.sharedFrom;
        var ownerInfo = PROFILE_USERS[ownerKey];
        if (ownerInfo) {
            listEl.appendChild(sectionTitle('Host'));
            listEl.appendChild(avRow(ownerKey, ownerInfo));
        }
        fetch(API + '?action=get-item-collabs&category=' + encodeURIComponent(cat) + '&itemId=' + encodeURIComponent(item.id), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                var others = (d.collaborators || []).filter(function(k){ return k !== USER_KEY; });
                if (!others.length) return;
                listEl.appendChild(sectionTitle('Otros colaboradores'));
                others.forEach(function(uKey){
                    var uInfo = PROFILE_USERS[uKey];
                    if (uInfo) listEl.appendChild(avRow(uKey, uInfo));
                });
            })
            .catch(function(){});
        return;
    }

    /* Soy el dueño → render colabs actuales + invitables. */
    /* Los elementos recién añadidos llevan un id provisional ("item_…") hasta
       que save-lists les da el suyo, y las invitaciones necesitan el
       definitivo: con el provisional la API respondía "Datos inválidos". Se
       espera un poco al guardado que ya va en camino y, si no llega, se
       guarda la categoría aquí. cb(id) recibe 0 si no se pudo. */
    function withSavedItemId(cat, item, cb) {
        var real = function(v) { return /^\d+$/.test(String(v)); };
        function find() {
            if (real(item.id)) return Number(item.id);
            var same = (STATE.lists[cat] || []).filter(function(x) { return x.title === item.title && real(x.id); });
            return same.length ? Number(same[same.length - 1].id) : 0;
        }
        var tries = 0;
        (function wait() {
            var id = find();
            if (id) { cb(id); return; }
            if (++tries <= 10) { setTimeout(wait, 300); return; }
            fetch(API + '?action=save-lists', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category: cat, items: STATE.lists[cat] })
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (d && Array.isArray(d.items)) STATE.lists[cat] = d.items;
                cb(find());
            }).catch(function() { cb(0); });
        })();
    }

    function render() {
        listEl.innerHTML = '';
        var list = STATE.lists && STATE.lists[cat];
        var cur = (list && list[origIdx] && list[origIdx].collaborators) || [];

        if (cur.length) {
            listEl.appendChild(sectionTitle('Colaboradores'));
            cur.forEach(function(uKey){
                var uInfo = PROFILE_USERS[uKey];
                if (!uInfo) return;
                listEl.appendChild(avRow(uKey, uInfo, 'Eliminar', function(btn){
                    pfConfirm('¿Eliminar a ' + uInfo.label + ' como colaborador?', 'Eliminar', function(){
                        btn.disabled = true;
                        statusEl.textContent = 'Eliminando…';
                        fetch(API + '?action=leave-collab', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'remove', category: cat, itemId: item.id, collaboratorUser: uKey })
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (!d || !d.ok) { statusEl.textContent = 'Error.'; btn.disabled = false; return; }
                            statusEl.textContent = '';
                            fetchLists().then(render);
                        })
                        .catch(function(){ statusEl.textContent = 'Error.'; btn.disabled = false; });
                    });
                }));
            });
        }

        var invitable = Object.keys(PROFILE_USERS).filter(function(k){
            return k !== USER_KEY
                && cur.indexOf(k) === -1
                && isMutual(k);   /* solo seguidores mutuos */
        });
        if (!invitable.length && !cur.length) {
            var empty = document.createElement('div');
            empty.style.cssText = 'text-align:center;padding:12px 8px;font-size:12px;line-height:1.45;';
            empty.innerHTML = 'Aún no tienes amigos que invitar.<br><span style="opacity:0.75;font-size:11px;">Seguíos entre vosotros para haceros amigos.</span>';
            listEl.appendChild(empty);
        }
        if (invitable.length) {
            if (cur.length) {
                var sep = document.createElement('hr');
                sep.className = 'pf-collab-sep';
                listEl.appendChild(sep);
            }
            listEl.appendChild(sectionTitle(cur.length ? 'Invitar más' : 'Invitar'));
            invitable.forEach(function(uKey){
                var uInfo = PROFILE_USERS[uKey];
                if (!uInfo) return;
                listEl.appendChild(avRow(uKey, uInfo, 'Invitar', function(btn){
                    btn.disabled = true; btn.textContent = '…';
                    statusEl.textContent = 'Enviando…';
                    withSavedItemId(cat, item, function(itemId) {
                    if (!itemId) { btn.disabled = false; btn.textContent = 'Invitar'; statusEl.textContent = 'No se pudo guardar el elemento. Inténtalo otra vez.'; return; }
                    fetch(API + '?action=send-item-invite', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ toUser: uKey, category: cat, itemId: itemId })
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (!d || !d.ok) {
                            statusEl.textContent = (d && d.error) || 'Error.';
                            btn.disabled = false; btn.textContent = 'Invitar';
                            return;
                        }
                        statusEl.textContent = '';
                        btn.textContent = 'Enviado';
                    })
                    .catch(function(){
                        statusEl.textContent = 'Error.';
                        btn.disabled = false; btn.textContent = 'Invitar';
                    });
                    });
                }));
            });
        }
    }
    render();
}

/* Eliminar item del perfil (yo soy el dueño). */
function deleteItem(origIdx, cat) {
    cat = cat || 'music';
    var list = STATE.lists && STATE.lists[cat];
    if (!list || !list[origIdx]) return;
    list.splice(origIdx, 1);
    var cntEl = document.getElementById('cnt-' + cat);
    if (cntEl) cntEl.textContent = list.length;
    renderActiveList();
    fetch(API + '?action=save-lists', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category: cat, items: list })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && Array.isArray(d.items)) {
            STATE.lists[cat] = d.items;
            if (cntEl) cntEl.textContent = d.items.length;
            renderActiveList();
        }
    })
    .catch(function(){
        /* No revertimos el splice — el render queda como el optimistic. */
    });
}

/* Abandonar actividad colaborativa (yo no soy el dueño). */
function leaveCollabItem(item, cat) {
    cat = cat || 'music';
    fetch(API + '?action=leave-collab', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'leave', category: cat, itemId: item.id })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d && d.ok) fetchLists().then(renderActiveList); })
    .catch(function(){});
}

/* Pide al backend la tracklist resuelta (igual que el escritorio) y se
   la pasa al MuShell del shell padre, que es quien posee el iframe de
   YouTube. Si no está embebida (acceso directo), no hay reproductor
   sobre el que actuar, así que no hacemos nada. */
function playMusicItem(item) {
    var SHELL = null;
    try { SHELL = window.parent.MuShell; } catch (_) {}
    fetch(API + '?action=play-music-item', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            itemType:       item.type           || '',
            title:          item.title          || '',
            artist:         item.artist         || '',
            ytId:           item.ytId           || '',
            spotifyId:      item.spotifyId      || '',
            ytPlaylistId:   item.ytPlaylistId   || '',
            spotifyAlbumId: item.spotifyAlbumId || ''
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d || !d.tracks || !d.tracks.length) return;
        if (SHELL && typeof SHELL.loadQueue === 'function') {
            SHELL.loadQueue(d.tracks, 0, item.title || '');
        }
    })
    .catch(function(){});
}

/* Toggle de featured con optimistic update + revert si falla el POST.
   Después del save canónico, sincronizamos STATE.lists.music con la
   respuesta del backend (puede haber re-id de items nuevos). */
function toggleFeatured(origIdx) {
    var music = STATE.lists && STATE.lists.music;
    if (!music || !music[origIdx]) return;
    music[origIdx].featured = !music[origIdx].featured;
    renderActiveList();
    fetch(API + '?action=save-lists', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category: 'music', items: music })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && Array.isArray(d.items)) { STATE.lists.music = d.items; renderActiveList(); }
    })
    .catch(function(){
        music[origIdx].featured = !music[origIdx].featured;
        renderActiveList();
    });
}

/* Long-press delegado a document. Resuelve target → {item, origIdx}
   leyendo data-cat/data-idx (renderActiveList y renderDestacados ya los
   emiten siempre en música). */
(function attachLongPress(){
    var lpTimer = null, sx = 0, sy = 0;
    function pickInfo(target){
        if (!target || !target.closest) return null;
        /* Excluye explícitamente el row "+" para que el long-press no
           dispare en él. Sí queremos pf-item + pf-dest-slot con data-cat. */
        var el = target.closest('.pf-item[data-cat]:not(.pf-add-item)') ||
                 target.closest('.pf-dest-slot[data-cat]');
        if (!el) return null;
        var cat = el.dataset.cat;
        var idx = parseInt(el.dataset.idx, 10);
        var it  = STATE.lists && STATE.lists[cat] && STATE.lists[cat][idx];
        return it ? { item: it, origIdx: idx } : null;
    }
    document.addEventListener('touchstart', function(e){
        var t = e.touches && e.touches[0]; if (!t) return;
        var info = pickInfo(e.target); if (!info) return;
        sx = t.clientX; sy = t.clientY;
        if (lpTimer) clearTimeout(lpTimer);
        lpTimer = setTimeout(function(){
            lpTimer = null; lpFired = true;
            try { navigator.vibrate && navigator.vibrate(30); } catch (_) {}
            showActionMenu(info.item, info.origIdx);
        }, LP_MS_PF);
    }, { passive: true });
    document.addEventListener('touchmove', function(e){
        if (!lpTimer) return;
        var t = e.touches && e.touches[0]; if (!t) return;
        if (Math.abs(t.clientX - sx) > LP_MOVE_PF || Math.abs(t.clientY - sy) > LP_MOVE_PF) {
            clearTimeout(lpTimer); lpTimer = null;
        }
    }, { passive: true });
    function endLp(){
        if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
        if (lpFired) setTimeout(function(){ lpFired = false; }, 400);
    }
    document.addEventListener('touchend',    endLp);
    document.addEventListener('touchcancel', endLp);
})();

/* ─── Editar perfil ──────────────────────────────────────────────
   Modal con form para bio, pronouns, birthday, country + 4 conexiones
   (la edad sale del cumpleaños).
   Guarda vía save-info y refresca la sección About con renderAbout. */
function openEditProfileDialog() {
    var p = STATE.profile || {};
    function hoyISO() {
        var h = new Date();
        return h.getFullYear() + '-' + String(h.getMonth() + 1).padStart(2, '0') + '-' + String(h.getDate()).padStart(2, '0');
    }
    function v(k){ return (p && p[k] != null) ? String(p[k]) : ''; }
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text"><img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Editar perfil</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-form-row">' +
                    '<label for="ep-bio">Descripción</label>' +
                    '<textarea id="ep-bio" maxlength="200" placeholder="Cuéntanos algo sobre ti...">' + esc(v('bio')) + '</textarea>' +
                    '<div class="pf-form-hint">Máx. 200 caracteres.</div>' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-pronouns">Pronombres</label>' +
                    '<input type="text" id="ep-pronouns" maxlength="30" value="' + esc(v('pronouns')) + '" placeholder="él/ella/elle...">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-birthday">Cumpleaños</label>' +
                    '<input type="date" id="ep-birthday" min="1900-01-01" max="' + hoyISO() + '" value="' + esc(v('birthday')) + '">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-country">País</label>' +
                    '<input type="text" id="ep-country" maxlength="50" value="' + esc(v('country')) + '" placeholder="📍 España">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-steam"><img src="../../assets/img/appIcons/steamIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Steam</label>' +
                    '<input type="text" id="ep-steam" maxlength="200" value="' + esc(v('steam')) + '" placeholder="usuario o URL">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-discord"><img src="../../assets/img/appIcons/discordIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Discord</label>' +
                    '<input type="text" id="ep-discord" maxlength="100" value="' + esc(v('discord')) + '" placeholder="usuario#1234">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-twitter"><img src="../../assets/img/appIcons/twitterIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Twitter</label>' +
                    '<input type="text" id="ep-twitter" maxlength="100" value="' + esc(v('twitter')) + '" placeholder="@usuario">' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ep-instagram"><img src="../../assets/img/appIcons/instagramIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Instagram</label>' +
                    '<input type="text" id="ep-instagram" maxlength="100" value="' + esc(v('instagram')) + '" placeholder="@usuario">' +
                '</div>' +
                '<div class="pf-form-error" id="ep-error"></div>' +
                '<div class="modal-actions">' +
                    '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                    '<button class="button default" data-act="save" type="button">Guardar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    bd.querySelector('[data-act="save"]').addEventListener('click', function(){
        var payload = {
            bio:       bd.querySelector('#ep-bio').value,
            pronouns:  bd.querySelector('#ep-pronouns').value,
            birthday:  bd.querySelector('#ep-birthday').value,
            country:   bd.querySelector('#ep-country').value,
            steam:     bd.querySelector('#ep-steam').value,
            discord:   bd.querySelector('#ep-discord').value,
            twitter:   bd.querySelector('#ep-twitter').value,
            instagram: bd.querySelector('#ep-instagram').value
        };
        var errEl = bd.querySelector('#ep-error');
        errEl.textContent = 'Guardando…';
        fetch(API + '?action=save-info', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.error) { errEl.textContent = d.error; return; }
            /* Recargamos el perfil server-side para reflejar saneamientos
               (trim, mb_substr) y actualizar el render. */
            fetchProfile().then(close);
        })
        .catch(function(){ errEl.textContent = 'Error de conexión.'; });
    });
}

/* ─── Añadir item NO-música (movies/series/books/games) ──────────
   Modal con title + image opcional + status. Reusa save-lists. */
function openAddItemDialog(cat) {
    var labels = {
        movies: { title: '<img src="../../assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Añadir película', titleField: 'Título' },
        series: { title: '<img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Añadir serie',    titleField: 'Título' },
        books:  { title: '<img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Añadir libro',    titleField: 'Título' },
        games:  { title: '<img src="../../assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Añadir juego',    titleField: 'Título' }
    };
    var cfg = labels[cat]; if (!cfg) return;
    /* Duración (pelis) / nº de capítulos (series, libros). */
    /* Series gestiona capítulos tras crearla (no un número aquí). */
    var RUNTIME_LABEL = { movies: 'Duración (min)', books: 'Nº de capítulos' };
    var rtLabel = RUNTIME_LABEL[cat];
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">' + cfg.title + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pf-form-row">' +
                    '<label for="ai-title">' + cfg.titleField + '</label>' +
                    '<input type="text" id="ai-title" maxlength="200" autofocus>' +
                '</div>' +
                '<div class="pf-form-row">' +
                    '<label for="ai-image">Imagen (URL, opcional)</label>' +
                    '<input type="text" id="ai-image" maxlength="500" placeholder="Déjala vacía y se busca sola">' +
                '</div>' +
                (rtLabel ? '<div class="pf-form-row">' +
                    '<label for="ai-runtime">' + rtLabel + '</label>' +
                    '<input type="number" id="ai-runtime" min="0" step="1" inputmode="numeric" placeholder="0">' +
                '</div>' : '') +
                (cat === 'series' ? '<div class="pf-form-row">' +
                    '<button class="button" id="ai-eps-btn" type="button" style="width:100%;box-sizing:border-box;">🎬 Capítulos</button>' +
                '</div>' : '') +
                (cat === 'books' ? '<div class="pf-form-row">' +
                    '<label for="ai-pdf">PDF (URL, opcional)</label>' +
                    '<input type="text" id="ai-pdf" maxlength="2000" placeholder="https://....pdf  o  Drive .../preview">' +
                '</div>' : '') +
                '<div class="pf-form-row">' +
                    '<label for="ai-status">Estado</label>' +
                    '<select id="ai-status">' +
                        '<option value="pending">Pendiente</option>' +
                        '<option value="in-progress">En curso</option>' +
                        '<option value="completed">Completado</option>' +
                    '</select>' +
                '</div>' +
                '<div class="pf-form-error" id="ai-error"></div>' +
                '<div class="modal-actions">' +
                    '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                    '<button class="button default" data-act="save" type="button">Añadir</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    /* Capítulos al añadir una serie (compartidos por título): se pueden
       gestionar con el nombre escrito antes de guardar el item. */
    var epsBtn = bd.querySelector('#ai-eps-btn');
    if (epsBtn) epsBtn.addEventListener('click', function(){
        var t = bd.querySelector('#ai-title').value.trim();
        if (!t) { bd.querySelector('#ai-error').textContent = 'Escribe el nombre de la serie primero.'; return; }
        openEpisodesModal(t, false);
    });
    bd.querySelector('[data-act="save"]').addEventListener('click', function(){
        var title  = bd.querySelector('#ai-title').value.trim();
        var image  = bd.querySelector('#ai-image').value.trim();
        var status = bd.querySelector('#ai-status').value;
        var errEl  = bd.querySelector('#ai-error');
        if (!title) { errEl.textContent = 'Ponle un título.'; return; }
        var list = (STATE.lists && STATE.lists[cat]) || [];
        var lower = title.toLowerCase();
        if (list.some(function(x){ return x && x.title && x.title.toLowerCase() === lower; })) {
            errEl.textContent = '⚠ Ya tienes "' + title + '" en tu lista.';
            return;
        }
        var item = {
            id:     'item_' + Date.now(),
            title:  title,
            image:  image,
            status: status
        };
        if (rtLabel) {
            var rtIn = bd.querySelector('#ai-runtime');
            var rt = rtIn ? (parseInt(rtIn.value, 10) || 0) : 0;
            if (rt > 0) item.runtime = rt;
        }
        var newList = list.slice(); newList.push(item);
        /* Libros: guardar el PDF (compartido por título) si se puso uno. */
        if (cat === 'books') {
            var pdfIn = bd.querySelector('#ai-pdf');
            var pdfUrl = pdfIn ? pdfIn.value.trim() : '';
            if (pdfUrl) {
                fetch(API + '?action=save-book-pdf', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bookTitle: title, url: pdfUrl })
                }).catch(function(){});
            }
        }
        errEl.textContent = 'Guardando…';
        fetch(API + '?action=save-lists', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: newList })
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.error) { errEl.textContent = d.error; return; }
            if (d && Array.isArray(d.items)) STATE.lists[cat] = d.items;
            else STATE.lists[cat] = newList;
            document.getElementById('cnt-' + cat).textContent = STATE.lists[cat].length;
            renderActiveList();
            close();
            /* Creado desde un selector de Personalizar ("＋ Crear juego"):
               queda elegido allí. */
            if (typeof window.__pf2mAfterAdd === 'function') {
                var creado = STATE.lists[cat].find(function(x){ return x && x.title && x.title.toLowerCase() === lower; }) || item;
                window.__pf2mAfterAdd(cat, creado);
            }
        })
        .catch(function(){ errEl.textContent = 'Error de conexión.'; });
    });
}

/* ─── Añadir música ──────────────────────────────────────────────
   Modal multi-paso: type → URL+artist → reseña opcional → save.
   Usa resolve-music-item para extraer metadata (ytId/spotifyId/etc),
   save-lists para insertar, y opcionalmente notify-review. */
function openMusicAddDialog() {
    var defaultType = STATE.musicView === 'songs' ? 'song' : 'album';
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text" id="ma-title">+ Añadir música</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body" id="ma-body"></div>' +
        '</div>';
    document.body.appendChild(bd);

    var titleEl = bd.querySelector('#ma-title');
    var bodyEl  = bd.querySelector('#ma-body');
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });

    var state = { type: null, meta: null, artist: '', reviewStars: 0, reviewComment: '' };

    function renderStep1() {
        titleEl.textContent = '+ Añadir música';
        bodyEl.innerHTML =
            '<p style="font-size:12px;margin:0 0 10px;">¿Qué quieres añadir?</p>' +
            '<div class="pf-type-row">' +
                '<button class="button pf-type-btn" data-t="song" type="button">' +
                    '<span class="pf-type-emoji"><img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>Canción</button>' +
                '<button class="button pf-type-btn" data-t="album" type="button">' +
                    '<span class="pf-type-emoji">💿</span>Álbum / Playlist</button>' +
            '</div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
            '</div>';
        bodyEl.querySelector('[data-act="cancel"]').addEventListener('click', close);
        bodyEl.querySelectorAll('[data-t]').forEach(function(btn){
            btn.addEventListener('click', function(){
                state.type = btn.dataset.t;
                renderStep2();
            });
        });
    }

    function renderStep2() {
        /* Song usa PNG musicaIcon vía innerHTML; album mantiene 💿. */
        if (state.type === 'song') {
            titleEl.innerHTML = '<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Añadir canción';
        } else {
            titleEl.textContent = '💿 Añadir álbum';
        }
        bodyEl.innerHTML =
            '<div class="pf-form-row">' +
                '<label for="ma-url">Enlace de YouTube o Spotify</label>' +
                '<input type="text" id="ma-url" placeholder="https://..." autofocus>' +
                '<div class="pf-form-hint" id="ma-preview" style="font-style:normal;color:var(--text-faint,#666);">Pega un enlace para buscar.</div>' +
            '</div>' +
            '<div class="pf-form-row">' +
                '<label for="ma-artist">Artista</label>' +
                '<input type="text" id="ma-artist" maxlength="200" placeholder="Artista..." value="' + esc(state.artist) + '">' +
            '</div>' +
            '<div class="pf-form-error" id="ma-error"></div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="back" type="button">← Atrás</button>' +
                '<button class="button" data-act="cancel" type="button">Cancelar</button>' +
                '<button class="button default" data-act="next" type="button">Siguiente →</button>' +
            '</div>';

        var urlEl     = bodyEl.querySelector('#ma-url');
        var artistEl  = bodyEl.querySelector('#ma-artist');
        var previewEl = bodyEl.querySelector('#ma-preview');
        var errEl     = bodyEl.querySelector('#ma-error');
        var fetchTimer = null;

        bodyEl.querySelector('[data-act="back"]').addEventListener('click', renderStep1);
        bodyEl.querySelector('[data-act="cancel"]').addEventListener('click', close);
        urlEl.addEventListener('input', function(){
            clearTimeout(fetchTimer); state.meta = null;
            previewEl.style.color = 'var(--text-faint, #666)';
            var raw = urlEl.value.trim();
            if (!raw) { previewEl.textContent = 'Pega un enlace para buscar.'; return; }
            previewEl.textContent = 'Buscando…';
            fetchTimer = setTimeout(function(){
                fetch(API + '?action=resolve-music-item&url=' + encodeURIComponent(raw) + '&itemType=' + state.type, { credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (!d || d.error) {
                            previewEl.style.color = 'var(--error-text, #c00)';
                            previewEl.textContent = (d && d.error) || 'No se pudo resolver.';
                            return;
                        }
                        state.meta = d;
                        previewEl.style.color = 'var(--text, #000)';
                        previewEl.innerHTML = (state.type === 'album' ? '💿 ' : '<img src="../../assets/img/appIcons/songIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> ') + (d.title || '');
                        if (d.artist && !artistEl.value.trim()) artistEl.value = d.artist;
                    })
                    .catch(function(){
                        previewEl.style.color = 'var(--error-text, #c00)';
                        previewEl.textContent = 'Error de conexión.';
                    });
            }, 500);
        });
        bodyEl.querySelector('[data-act="next"]').addEventListener('click', function(){
            state.artist = artistEl.value.trim();
            if (!state.meta) { errEl.textContent = 'Espera a que se cargue el enlace.'; return; }
            var music = (STATE.lists && STATE.lists.music) || [];
            var lower = state.meta.title.toLowerCase().trim();
            if (music.some(function(x){ return x && x.title && x.title.toLowerCase().trim() === lower; })) {
                errEl.textContent = '⚠ Ya tienes "' + state.meta.title + '" en tu lista.';
                return;
            }
            errEl.textContent = '';
            renderStep3();
        });
    }

    function renderStep3() {
        titleEl.textContent = '★ ¿Reseñar?';
        bodyEl.innerHTML =
            '<p style="font-size:12px;margin:0 0 10px;">¿Quieres añadir una reseña a "' + esc(state.meta.title) + '"?</p>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="back" type="button">← Atrás</button>' +
                '<button class="button" data-act="skip" type="button">No, guardar</button>' +
                '<button class="button default" data-act="yes" type="button">★ Sí</button>' +
            '</div>';
        bodyEl.querySelector('[data-act="back"]').addEventListener('click', renderStep2);
        bodyEl.querySelector('[data-act="skip"]').addEventListener('click', function(){ saveAll(false); });
        bodyEl.querySelector('[data-act="yes"]').addEventListener('click', renderStep4);
    }

    function renderStep4() {
        titleEl.textContent = '★ Reseña';
        bodyEl.innerHTML =
            '<div class="pf-rev-edit-label">Puntuación</div>' +
            '<div class="pf-rev-edit-stars" id="ma-stars"></div>' +
            '<div class="pf-rev-edit-label">Comentario</div>' +
            '<textarea class="pf-rev-edit-comment" id="ma-comment" maxlength="500" placeholder="Opcional"></textarea>' +
            '<div class="pf-form-error" id="ma-rev-error"></div>' +
            '<div class="modal-actions">' +
                '<button class="button" data-act="back" type="button">← Atrás</button>' +
                '<button class="button default" data-act="save" type="button">Guardar</button>' +
            '</div>';
        var starsEl   = bodyEl.querySelector('#ma-stars');
        var commentEl = bodyEl.querySelector('#ma-comment');
        commentEl.value = state.reviewComment;
        var sel = state.reviewStars || 0;
        function paint(){
            var html = '';
            for (var i = 1; i <= 5; i++) {
                html += '<span class="pf-rev-edit-star' + (i > sel ? ' empty' : '') +
                        '" data-v="' + i + '">' + (i > sel ? '☆' : '★') + '</span>';
            }
            html += '<span class="pf-rev-edit-num">' + (sel || '') + '</span>';
            starsEl.innerHTML = html;
            Array.prototype.forEach.call(starsEl.querySelectorAll('.pf-rev-edit-star'), function(el){
                el.addEventListener('click', function(){
                    var v = parseInt(el.dataset.v, 10);
                    sel = (v === sel) ? (v - 1) : v;
                    paint();
                });
            });
        }
        paint();
        bodyEl.querySelector('[data-act="back"]').addEventListener('click', renderStep3);
        bodyEl.querySelector('[data-act="save"]').addEventListener('click', function(){
            var errEl = bodyEl.querySelector('#ma-rev-error');
            if (!sel) { errEl.textContent = 'Selecciona una puntuación.'; return; }
            state.reviewStars   = sel;
            state.reviewComment = commentEl.value.trim();
            saveAll(true);
        });
    }

    function saveAll(withReview) {
        var meta = state.meta;
        var entry = {
            id:       'music_' + Date.now(),
            type:     state.type,
            title:    meta.title,
            artist:   state.artist || meta.artist || '',
            image:    meta.image || '',
            featured: false
        };
        if (meta.ytId)           entry.ytId           = meta.ytId;
        if (meta.spotifyId)      entry.spotifyId      = meta.spotifyId;
        if (meta.ytPlaylistId)   entry.ytPlaylistId   = meta.ytPlaylistId;
        if (meta.spotifyAlbumId) entry.spotifyAlbumId = meta.spotifyAlbumId;
        if (withReview && state.reviewStars > 0) {
            entry.review = {
                stars:      state.reviewStars,
                comment:    state.reviewComment,
                reviewedAt: Math.floor(Date.now() / 1000)
            };
        }
        var music = ((STATE.lists && STATE.lists.music) || []).slice();
        music.push(entry);
        fetch(API + '?action=save-lists', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: 'music', items: music })
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.error) { /* mostrar error en step actual */
                var e = bodyEl.querySelector('.pf-form-error');
                if (e) e.textContent = d.error;
                return;
            }
            if (d && Array.isArray(d.items)) STATE.lists.music = d.items;
            else STATE.lists.music = music;
            document.getElementById('cnt-music').textContent = STATE.lists.music.length;
            renderActiveList();
            if (withReview && state.reviewStars > 0) {
                fetch(API + '?action=notify-review', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category: 'music', itemTitle: meta.title, mtype: state.type })
                }).catch(function(){});
            }
            close();
        })
        .catch(function(){
            var e = bodyEl.querySelector('.pf-form-error');
            if (e) e.textContent = 'Error de conexión.';
        });
    }

    /* Arrancamos en step1 (chooser) si no hay sub-tab activo claro,
       o saltamos a step2 con el tipo derivado de musicView. */
    if (defaultType) { state.type = defaultType; renderStep2(); }
    else renderStep1();
}

/* Click delegado a nivel `document`: cualquier tap sobre una fila o
   slot con `.has-review` abre el modal de reseña. Delego en document
   por si un ancestro con overflow se traga el evento.
   El row "+" (data-act="add-item") abre el dialog de añadir item. */
document.addEventListener('click', function(e){
    if (lpFired) { e.preventDefault(); e.stopPropagation(); return; }
    if (!e.target || !e.target.closest) return;
    var addRow = e.target.closest('.pf-item.pf-add-item');
    if (addRow) {
        e.preventDefault(); e.stopPropagation();
        if (STATE.current === 'music') openMusicAddDialog();
        else openAddItemDialog(STATE.current);
        return;
    }
    var row = e.target.closest('.pf-item.has-review') ||
              e.target.closest('.pf-dest-slot.has-review');
    if (!row) return;
    e.preventDefault();
    e.stopPropagation();
    var cat = row.dataset.cat;
    var idx = parseInt(row.dataset.idx, 10);
    var item = (STATE.lists && STATE.lists[cat] && STATE.lists[cat][idx]) || null;
    if (item && item.review) openReviewView(item);
});

/* Botón "Editar perfil" — vive ahora en la vista Perfil. */
document.getElementById('pf-edit-profile-btn').addEventListener('click', function(){ pf2Editar(true); });
document.getElementById('pf2-datos').addEventListener('click', openEditProfileDialog);
document.getElementById('pf2-guardar').addEventListener('click', pf2Guardar);
document.getElementById('pf2-cancelar').addEventListener('click', function(){ pf2Editar(false); });

/* ─── Sidebar drawer ──────────────────────────────────────────────
   Botón ☰ abre el panel lateral. El backdrop y la × lo cierran.
   El contenido del body cambia según si estoy en mi perfil o
   viendo el de otro usuario. */
var DRAWER     = document.getElementById('pf-drawer');
var DRAWER_BD  = document.getElementById('pf-drawer-backdrop');
var DRAWER_BD2 = document.getElementById('pf-drawer-body');

function openDrawer() {
    renderDrawerBody();
    DRAWER.classList.add('open');
    DRAWER_BD.classList.add('open');
}
function closeDrawer() {
    DRAWER.classList.remove('open');
    DRAWER_BD.classList.remove('open');
}
document.getElementById('pf-hamburger').addEventListener('click', openDrawer);
document.getElementById('pf-drawer-close').addEventListener('click', closeDrawer);
DRAWER_BD.addEventListener('click', closeDrawer);

function renderDrawerBody() {
    var viewing = !!STATE.viewingUser;
    var sections = [];

    if (!viewing) {
        sections.push({
            title: null,
            items: [
                { view: 'profile', icon: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Perfil' },
                { view: 'lists',   icon: '<img src="../../assets/img/appIcons/calendarioIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Mis listas' },
                { view: 'melon',   icon: '⭐', label: 'Melon reviews' },
                { view: 'social',  icon: '+', label: 'Amigos' },
                /* No es una vista: abre la ventana de invitar (pf2AbrirInvitar). */
                { act: 'invitar',  icon: '<img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Invitar' }
            ]
        });
    } else {
        sections.push({
            title: null,
            items: [
                { view: 'profile', icon: '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">', label: 'Perfil' },
                { view: 'lists',   icon: '📋', label: 'Listas de ' + (STATE.viewingLabel || '?') }
            ]
        });
    }

    var html = '';
    sections.forEach(function(sec){
        html += '<div class="pf-drawer-section">';
        if (sec.title) html += '<div class="pf-drawer-section-title">' + esc(sec.title) + '</div>';
        sec.items.forEach(function(it){
            var active = STATE.view === it.view ? ' active' : '';
            html += '<button class="button pf-drawer-item' + active + '" ' + (it.act ? 'data-act="' + it.act + '"' : 'data-view="' + it.view + '"') + ' type="button">' +
                      '<span class="pf-drawer-icon">' + it.icon + '</span>' +
                      '<span>' + esc(it.label) + '</span>' +
                    '</button>';
        });
        html += '</div>';
    });
    DRAWER_BD2.innerHTML = html;
    DRAWER_BD2.querySelectorAll('[data-view]').forEach(function(btn){
        btn.addEventListener('click', function(){
            showView(btn.dataset.view);
            closeDrawer();
        });
    });
    DRAWER_BD2.querySelectorAll('[data-act="invitar"]').forEach(function(btn){
        btn.addEventListener('click', function(){ closeDrawer(); pf2AbrirInvitar(); });
    });
}

/* ─── View switching ────────────────────────────────────────────── */
function showView(name) {
    STATE.view = name;
    var V = { profile: 'pf-view-profile', lists: 'pf-view-lists', social: 'pf-view-social', melon: 'pf-view-melon' };
    Object.keys(V).forEach(function(k){
        var el = document.getElementById(V[k]);
        if (el) el.hidden = (k !== name);
    });
    /* Acciones específicas por vista. */
    if (name === 'profile') {
        renderPerfil2();
        /* Si estoy viendo otro usuario, oculto Editar y enseño amistad / chat. */
        toggleProfileActions();
    } else if (name === 'social') {
        renderSocial();
    } else if (name === 'melon') {
        /* Carga lo seleccionado actualmente (defaults a year + movies). */
        if (!MELON_STATE.loaded) {
            MELON_STATE.loaded = true;
            loadMelonItems();
        }
    }
}

/* ════════════════════════════════════════════════════════════════
   MELON REVIEWS — ranking comunidad
   ════════════════════════════════════════════════════════════════
   Igual que perfil.php (desktop) pero con UI mobile:
     · Filtro de período en .pf-subtabs (year / recent / alltime)
     · Filtro de categoría en .pf-tabs
     · Grid 2 columnas de "slots" con cover + rating
     · Modal con detalle de reseñas individuales
   El endpoint es el mismo (assets/profile/api.php?action=melon-reviews). */
var MELON_STATE = {
    loaded: false,
    period: 'year',
    cat:    'movies',
    type:   null,
    items:  [],
    page:   1,
    pageSize: 10
};
var MELON_LABELS = { year: 'Mejor del año', recent: 'Reciente', alltime: 'Todo el tiempo' };
var MELON_ICONS  = { movies: '🎬', series: '📺', books: '📚', games: '🎮', music: '🎵' };
var MELON_MUST_VERBS = { movies: 'see', series: 'watch', books: 'read', games: 'play', music: 'hear' };

function loadMelonItems() {
    if (!MELON_STATE.period || !MELON_STATE.cat) return;
    var statusEl = document.getElementById('pf-melon-status');
    var listEl   = document.getElementById('pf-melon-list');
    var pagerEl  = document.getElementById('pf-melon-pager');
    if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'Cargando…'; }
    if (listEl) listEl.innerHTML = '';
    if (pagerEl) pagerEl.innerHTML = '';

    var url = API + '?action=melon-reviews&period=' + encodeURIComponent(MELON_STATE.period)
            + '&cat=' + encodeURIComponent(MELON_STATE.cat);
    if (MELON_STATE.type) url += '&type=' + encodeURIComponent(MELON_STATE.type);

    fetch(url, { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) {
                if (statusEl) { statusEl.style.display = ''; statusEl.textContent = (d && d.error) ? d.error : 'Error'; }
                return;
            }
            /* Reordena: "Melon must" siempre delante (avg ≥ 4.6 con
               ≥ 2 reseñas). Estable: dentro de cada grupo respetamos
               el orden del backend. Aplica a todos los filtros. */
            var raw = d.items || [];
            var musts = [], rest = [];
            raw.forEach(function(it) {
                if (it && it.avg >= 4.6 && it.count >= 2) musts.push(it);
                else rest.push(it);
            });
            MELON_STATE.items = musts.concat(rest);
            MELON_STATE.page  = 1;
            renderMelonItems();
        })
        .catch(function(){
            if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'Error de red'; }
        });
}

function renderMelonItems() {
    var statusEl = document.getElementById('pf-melon-status');
    var listEl   = document.getElementById('pf-melon-list');
    var pagerEl  = document.getElementById('pf-melon-pager');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (pagerEl) pagerEl.innerHTML = '';

    if (!MELON_STATE.items.length) {
        if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'No hay reseñas en este período.'; }
        return;
    }
    if (statusEl) statusEl.style.display = 'none';

    var totalPages = Math.max(1, Math.ceil(MELON_STATE.items.length / MELON_STATE.pageSize));
    if (MELON_STATE.page > totalPages) MELON_STATE.page = totalPages;
    if (MELON_STATE.page < 1) MELON_STATE.page = 1;
    var start = (MELON_STATE.page - 1) * MELON_STATE.pageSize;
    var pageItems = MELON_STATE.items.slice(start, start + MELON_STATE.pageSize);

    var isMusic = (MELON_STATE.cat === 'music');
    var ICON_FALLBACK = {
        movies: '🎬', series: '📺', books: '📚', games: '🎮',
        music:  (MELON_STATE.type === 'song' ? '🎵' : '💿')
    };
    pageItems.forEach(function(item, idx){
        /* "Melon must": nota media ≥ 4.6 con al menos 2 reseñas.
           El mínimo de reseñas evita que una sola puntuación extrema
           (4.6+ de una sola persona) marque el item como must — la
           curaduría exige consenso. */
        var isMust = item.avg >= 4.6 && item.count >= 2;

        /* Poster — reusa la estructura .pf-item-poster de "Mis listas":
           img si hay cover, div.placeholder con emoji si no. */
        var posterHtml;
        if (item.image) {
            posterHtml = '<img class="pf-item-poster" ' + coverImgAttrs(MELON_STATE.cat, item) + ' alt="" loading="lazy">';
        } else {
            posterHtml = '<div class="pf-item-poster placeholder">' + (ICON_FALLBACK[MELON_STATE.cat] || '🖼') + '</div>';
        }

        /* Sub: artista (música) + rating estrellas + count + badge "must" inline */
        var subParts = [];
        if (isMusic && item.artist) subParts.push('<span>' + esc(item.artist) + '</span>');
        subParts.push(
            '<span class="pf-stars-wrap">' +
                '<span class="pf-stars">' + makeStarsHtml(item.avg, 5) + '</span>' +
                '<span class="pf-stars-num">' + item.avg.toFixed(1) + '</span>' +
                '<span class="pf-melon-count">(' + item.count + ')</span>' +
            '</span>'
        );
        if (isMust) {
            subParts.push('<span class="pf-melon-must-tag">★ Must ' + (MELON_MUST_VERBS[MELON_STATE.cat] || 'see') + '</span>');
        }

        var classes = 'pf-item has-review pf-melon-item' + (isMusic ? ' music' : '') + (isMust ? ' is-must' : '');
        var row = document.createElement('div');
        row.className = classes;
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.innerHTML = posterHtml +
            '<div class="pf-item-info">' +
                '<div class="pf-item-title">' + esc(item.title) + '</div>' +
                '<div class="pf-item-sub">' + subParts.join('') + '</div>' +
            '</div>';
        (function(it){ row.addEventListener('click', function(){ showMelonDetails(it); }); })(item);
        listEl.appendChild(row);
    });

    /* Paginación */
    if (pagerEl && totalPages > 1) {
        var prev = document.createElement('button');
        prev.className = 'button';
        prev.textContent = '◄';
        prev.disabled = MELON_STATE.page <= 1;
        prev.addEventListener('click', function(){ if (MELON_STATE.page > 1) { MELON_STATE.page--; renderMelonItems(); } });
        pagerEl.appendChild(prev);

        var info = document.createElement('span');
        info.className = 'pf-melon-pager-info';
        info.textContent = MELON_STATE.page + ' / ' + totalPages + '  ·  ' + MELON_STATE.items.length + ' items';
        pagerEl.appendChild(info);

        var next = document.createElement('button');
        next.className = 'button';
        next.textContent = '►';
        next.disabled = MELON_STATE.page >= totalPages;
        next.addEventListener('click', function(){ if (MELON_STATE.page < totalPages) { MELON_STATE.page++; renderMelonItems(); } });
        pagerEl.appendChild(next);
    }
}

/* makeStarsHtml ya está definida arriba (línea ~1583) con clip-path
   safe-cross-device para la media estrella. NO redefinirla aquí. */

/* Duración + nº de tracks (álbum) o duración (canción) en Melon Reviews.
   find-album resuelve la albumKey; album-tracks da las pistas con duración.
   Best-effort: cualquier fallo deja el elemento vacío. */
function pfFmtDur(sec) {
    sec = Math.round(sec || 0);
    if (sec <= 0) return '';
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + ' h ' + m + ' min';
    if (m >= 1) return m + ' min';
    return sec + ' s';
}
function pfFmtSong(sec) {
    sec = Math.round(sec || 0);
    if (sec <= 0) return '';
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}
function pfLoadMusicMeta(item, isAlbum, el) {
    var MUS = '../../assets/music/api.php';
    var p = new URLSearchParams({ title: item.title || '', artist: item.artist || '' });
    fetch(MUS + '?action=find-album&' + p.toString(), { credentials: 'same-origin' })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
            if (!d || d.notFound || !d.albumKey) return;
            return fetch(MUS + '?action=album-tracks&key=' + encodeURIComponent(d.albumKey), { credentials: 'same-origin' })
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(al){
                    if (!al || !Array.isArray(al.tracks) || !al.tracks.length) return;
                    if (isAlbum) {
                        var total = al.tracks.reduce(function(a, t){ return a + (t.duration || 0); }, 0);
                        var parts = [al.tracks.length + (al.tracks.length === 1 ? ' canción' : ' canciones')];
                        var dur = pfFmtDur(total);
                        if (dur) parts.push(dur);
                        el.textContent = parts.join(' · ');
                    } else {
                        var lt = (item.title || '').toLowerCase();
                        var match = al.tracks.filter(function(t){ return (t.title || '').toLowerCase() === lt; })[0]
                                 || al.tracks.filter(function(t){ return (t.title || '').toLowerCase().indexOf(lt) !== -1; })[0];
                        var ds = pfFmtSong(match ? match.duration : 0);
                        if (ds) el.textContent = ds;
                    }
                });
        }).catch(function(){});
}

function showMelonDetails(item) {
    var backdrop = document.getElementById('pf-melon-modal-backdrop');
    var titleEl  = document.getElementById('pf-melon-modal-title');
    var listEl   = document.getElementById('pf-melon-modal-list');
    var headEl   = document.getElementById('pf-melon-modal-head');
    if (!backdrop || !listEl) return;
    titleEl.textContent = '⭐ ' + item.title;
    listEl.innerHTML = '';
    if (headEl) headEl.innerHTML = '';
    /* Cabecera FIJA: carátula + título + (artista) + media/nº; debajo,
       en zona scrollable, las reseñas. */
    var head = document.createElement('div');
    head.className = 'pf-melon-detail-header';
    var cover = document.createElement('div');
    cover.className = 'pf-melon-detail-cover';
    /* Música = cuadrada; resto (pelis/series/libros/juegos) = póster vertical. */
    if (MELON_STATE.cat !== 'music') cover.classList.add('pf-melon-cover-portrait');
    if (item.image) {
        var cimg = document.createElement('img'); cimg.src = window.MhCover ? MhCover.url(item.image) : item.image; cimg.alt = item.title || '';
        cimg.setAttribute('data-cover-cat', MELON_STATE.cat);
        cimg.setAttribute('data-cover-title', item.title || '');
        (function(c){ cimg.onerror = function(){ c.classList.add('pf-melon-detail-cover-ph'); c.textContent = '🍈'; }; })(cover);
        cover.appendChild(cimg);
    } else { cover.classList.add('pf-melon-detail-cover-ph'); cover.textContent = '🍈'; }
    head.appendChild(cover);
    var hinfo = document.createElement('div'); hinfo.className = 'pf-melon-detail-hinfo';
    var ht = document.createElement('div'); ht.className = 'pf-melon-detail-htitle'; ht.textContent = item.title || '';
    /* Libros con PDF: clicar el título lo abre embebido. */
    if (MELON_STATE.cat === 'books' && item.pdfUrl) {
        ht.classList.add('pf-melon-detail-eplink');
        ht.style.cursor = 'pointer';
        ht.addEventListener('click', function(){ openPdfViewerModal(item.pdfUrl, item.title); });
    }
    hinfo.appendChild(ht);
    if (item.artist) { var ha = document.createElement('div'); ha.className = 'pf-melon-detail-hartist'; ha.textContent = item.artist; hinfo.appendChild(ha); }
    var hr = document.createElement('div'); hr.className = 'pf-melon-detail-hrating';
    var avgTxt = (typeof item.avg === 'number') ? item.avg.toFixed(1) : (item.avg || '0');
    var cnt = item.count || (item.reviews ? item.reviews.length : 0);
    hr.innerHTML = makeStarsHtml(item.avg || 0, 5)
        + '<span class="pf-melon-detail-havg">' + avgTxt + '</span>'
        + '<span class="pf-melon-detail-hcount">' + cnt + ' ' + (cnt === 1 ? 'reseña' : 'reseñas') + '</span>';
    hinfo.appendChild(hr);
    /* Consenso de duración/capítulos (la moda entre las reseñas). */
    if (item.runtime && item.runtime > 0) {
        var c = MELON_STATE.cat;
        var rtTxt = c === 'movies' ? (item.runtime + ' min')
                  : (c === 'series' || c === 'books') ? (item.runtime + (item.runtime === 1 ? ' capítulo' : ' capítulos'))
                  : '';
        if (rtTxt) {
            var hrt = document.createElement('div');
            hrt.className = 'pf-melon-detail-hruntime';
            hrt.textContent = rtTxt;
            hinfo.appendChild(hrt);
        }
    }
    /* Series con capítulos: nº de temporadas + nº de capítulos + duración
       total, clicable → modal de capítulos. */
    if (MELON_STATE.cat === 'series' && item.episodeCount > 0) {
        var hep = document.createElement('div');
        hep.className = 'pf-melon-detail-hruntime pf-melon-detail-eplink';
        var epBits = [];
        if (item.seasonCount > 0) epBits.push(item.seasonCount + (item.seasonCount === 1 ? ' temporada' : ' temporadas'));
        epBits.push(item.episodeCount + (item.episodeCount === 1 ? ' capítulo' : ' capítulos'));
        if (item.episodeDuration > 0) epBits.push(epFmtDurM(item.episodeDuration));
        hep.textContent = '🎬 ' + epBits.join(' · ');
        hep.style.cursor = 'pointer';
        hep.addEventListener('click', function(){ openEpisodesModal(item.title, false, true); });
        hinfo.appendChild(hep);
    }
    /* Música: duración + nº de tracks (álbum) o duración (canción). */
    if (MELON_STATE.cat === 'music') {
        var _isAlbum = (item.mtype === 'album' || item.type === 'album');
        var hmeta = document.createElement('div');
        hmeta.className = 'pf-melon-detail-hmeta';
        hinfo.appendChild(hmeta);
        pfLoadMusicMeta(item, _isAlbum, hmeta);
    }
    head.appendChild(hinfo);
    (headEl || listEl).appendChild(head);
    /* Tap en la carátula → añadir el item a mi perfil. */
    cover.style.cursor = 'pointer';
    cover.title = 'Añadir a mi perfil';
    cover.addEventListener('click', function(){
        if (typeof addItemToOwnProfile === 'function') addItemToOwnProfile(MELON_STATE.cat, item);
    });
    (item.reviews || []).forEach(function(rev){
        var row = document.createElement('div');
        row.className = 'pf-melon-review-row';

        var av = document.createElement('div');
        av.className = 'pf-melon-review-av';
        if (rev.user) av.setAttribute('data-marco-user', rev.user);   /* marco (melon-marcos.js) */
        if (rev.userImg) {
            /* getUserImage devuelve la ruta relativa al root del proyecto
               ("assets/img/Sami.jpg"); perfil-mobile vive en /apps/mobile/
               así que prefijamos "../../" salvo que ya sea absoluta. */
            var src = rev.userImg;
            if (src.indexOf('/') !== 0 && src.indexOf('http') !== 0 && src.indexOf('../') !== 0) {
                src = '../../' + src;
            }
            var img = document.createElement('img');
            img.src = src; img.alt = rev.userLabel || '';
            av.appendChild(img);
        } else {
            av.textContent = '👤';
        }
        row.appendChild(av);

        var info = document.createElement('div');
        info.className = 'pf-melon-review-info';
        var hdr = document.createElement('div');
        hdr.className = 'pf-melon-review-hdr';
        hdr.innerHTML = '<strong>' + esc(rev.userLabel || '?') + '</strong> '
            + makeStarsHtml(rev.stars, 5)
            + '<span class="pf-melon-review-num">' + rev.stars + '</span>';
        info.appendChild(hdr);
        if (rev.comment) {
            var cmt = document.createElement('div');
            cmt.className = 'pf-melon-review-comment';
            cmt.textContent = '" ' + rev.comment + ' "';
            info.appendChild(cmt);
        }
        var t = document.createElement('div');
        t.className = 'pf-melon-review-time';
        t.textContent = rev.reviewedAt ? relTimeStr(rev.reviewedAt) : '';
        info.appendChild(t);

        row.appendChild(info);
        listEl.appendChild(row);
    });
    backdrop.hidden = false;
}

/* Tiempo relativo. Si ya existe relTime en el scope del perfil-mobile
   (declarado más arriba), úsalo; si no, fallback simple. */
function relTimeStr(ts) {
    if (typeof relTime === 'function') {
        try { return relTime(ts); } catch (_) {}
    }
    if (!ts) return '';
    var d = new Date(ts);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString();
}

/* Setup de los filtros (period + cat) y close del modal.
   Los elementos están en el DOM cuando este script corre (script al
   final del body), así que no esperamos a DOMContentLoaded. */
(function setupMelon() {
    var periodBtns = document.querySelectorAll('#pf-melon-periods .pf-subtab');
    periodBtns.forEach(function(btn){
        btn.addEventListener('click', function(){
            MELON_STATE.period = btn.dataset.mperiod;
            periodBtns.forEach(function(b){ b.classList.toggle('active', b === btn); });
            loadMelonItems();
        });
    });
    var catBtns = document.querySelectorAll('#pf-melon-cats .pf-tab');
    catBtns.forEach(function(btn){
        btn.addEventListener('click', function(){
            MELON_STATE.cat  = btn.dataset.mcat;
            MELON_STATE.type = btn.dataset.mtype || null;
            catBtns.forEach(function(b){ b.classList.toggle('active', b === btn); });
            loadMelonItems();
        });
    });
    var closeBtn = document.getElementById('pf-melon-modal-close');
    var backdrop = document.getElementById('pf-melon-modal-backdrop');
    if (closeBtn) closeBtn.addEventListener('click', function(){ backdrop.hidden = true; });
    if (backdrop) backdrop.addEventListener('click', function(e){
        if (e.target === backdrop) backdrop.hidden = true;
    });
})();

/* Editar perfil solo en mi perfil. Botón Seguir solo cuando
   estoy viendo a otro. Chat solo si hay seguimiento mutuo. */
function toggleProfileActions() {
    var viewing = !!STATE.viewingUser;
    var editBtn = document.getElementById('pf-edit-profile-btn');
    var followBtn = document.getElementById('pf-follow-btn');
    var chatBtn = document.getElementById('pf-chat-btn');
    editBtn.hidden   = viewing;
    followBtn.hidden = !viewing;
    chatBtn.hidden   = !(viewing && isMutual(STATE.viewingUser));
}

/* Mutualidad: sigo a X Y X me sigue. Necesario para el chat (el server
   también lo verifica con 403 si falta). */
function isMutual(uKey) {
    if (!uKey) return false;
    return STATE.myFollowing.indexOf(uKey) !== -1 &&
           STATE.myFollowers.indexOf(uKey) !== -1;
}

/* Presencia: refresca los puntos online/offline en los avatares de
   Social. Llamado al cargar y cada 20s. Cachea el último set para que
   los re-renders apliquen el estado sin esperar al fetch. */
var __pfLastOnline = {};
var __pfLastAway   = {};
var __pfLastDnd    = {};
function applyPresence() {
    document.querySelectorAll('.pf-presence-dot[data-userkey]').forEach(function(dot) {
        var k = dot.getAttribute('data-userkey');
        dot.classList.toggle('online', !!__pfLastOnline[k]);
        /* away gana sobre online (verde→amarillo). */
        dot.classList.toggle('away',   !!__pfLastAway[k]);
        dot.classList.toggle('dnd',    !!__pfLastDnd[k]);
    });
}
/* Helper: attach/replace dot en cualquier wrapper de avatar. userKey
   '' o falsy → elimina el dot. Si el usuario está en DND el dot se
   pinta rojo aunque esté online. */
function attachPresenceDot(wrap, userKey, small) {
    if (!wrap) return;
    var existing = wrap.querySelector('.pf-presence-dot');
    if (existing) existing.remove();
    if (!userKey) {
        wrap.classList.remove('has-presence-dot');
        return;
    }
    wrap.classList.add('has-presence-dot');
    var dot = document.createElement('span');
    dot.className = 'pf-presence-dot' + (small ? ' pf-presence-dot-small' : '');
    dot.setAttribute('data-userkey', userKey);
    if (__pfLastOnline[userKey]) dot.classList.add('online');
    if (__pfLastAway[userKey])   dot.classList.add('away');
    if (__pfLastDnd[userKey])    dot.classList.add('dnd');
    wrap.appendChild(dot);
}
window.__pfAttachPresenceDot = attachPresenceDot;
/* La presencia sale del archivo estático que deja escrito el heartbeat
   (assets/profile/presencia.php): leerlo no gasta PHP ni BD. Si no
   responde o tiene más de 2 min, de la API como antes. */
function pedirPresencia() {
    return fetch(PRESENCIA_URL, { cache: 'no-cache', credentials: 'same-origin' })
        .then(function(r) { if (!r.ok) throw new Error('presencia ' + r.status); return r.json(); })
        .then(function(d) {
            if (!d || !d.ahora || Math.abs(Date.now() / 1000 - d.ahora) > 120) throw new Error('presencia vieja');
            return d;
        })
        .catch(function() {
            return fetch(API + '?action=presence', { credentials: 'same-origin' }).then(function(r) { return r.json(); });
        });
}
function refreshPresenceDots() {
    pedirPresencia()
        .then(function(d) {
            if (!d || !d.ok || !Array.isArray(d.online)) return;
            __pfLastOnline = {};
            d.online.forEach(function(k) { __pfLastOnline[k] = true; });
            __pfLastAway = {};
            if (Array.isArray(d.away)) {
                d.away.forEach(function(k) { __pfLastAway[k] = true; });
            }
            __pfLastDnd = {};
            if (Array.isArray(d.dnd)) {
                d.dnd.forEach(function(k) { __pfLastDnd[k] = true; });
            }
            /* Para el estado de la página de Amigos (escuchando… / hace X). */
            __pfLastNp   = (d.nowPlaying && typeof d.nowPlaying === 'object') ? d.nowPlaying : {};
            __pfLastSeen = (d.lastSeen && typeof d.lastSeen === 'object') ? d.lastSeen : {};
            if (STATE.view === 'social') amPintarLista();
            pf2Estado();
            applyPresence();
        })
        .catch(function() {});
}
window.__pfApplyPresence = applyPresence;
refreshPresenceDots();
setInterval(refreshPresenceDots, 20000);

function relTime(ts) {
    var diff = Math.floor(Date.now() / 1000) - (ts || 0);
    if (diff < 60)    return 'ahora';
    if (diff < 3600)  return 'hace ' + Math.floor(diff / 60) + 'm';
    if (diff < 86400) return 'hace ' + Math.floor(diff / 3600) + 'h';
    return 'hace ' + Math.floor(diff / 86400) + 'd';
}

/* ─── Perfil nuevo (estilo Steam; assets/profile/perfil2.php → v2) ────
   Decidido así (7/11, con una captura del perfil de Steam en el móvil
   de referencia). Debajo de la cabecera y los botones, de arriba abajo:
     - Nivel N y, a la derecha, la mejor medalla (la de más nivel) con su
       XP. Sin barra de XP (como Steam).
     - Franja con el estado: En línea / Escuchando… / Ausente / No
       molestar / Desconectado (el perfil propio, en línea).
     - Cuadrícula de 2 columnas, solo con lo que tiene el PC: Medallas
       (las ganadas en pequeño; al tocar, la lista con la ficha de cada
       una) · Amigos (fotos en fila; al tocar, la página de Amigos) ·
       Reseñas · Capturas · Horas escuchadas · En Melon Hub (estos no
       hacen nada).
     - Expositores (punto 3, aún no) justo encima de los comentarios.
     - Comentarios: el muro (los posts de antes pasaron aquí en el PC).
       Escribe cualquiera con cuenta; borra el dueño del muro o el autor.
   Las fotos de v2 vienen desde la raíz de la web: aquí se les pone
   ../../ delante (pf2Ruta). */
var PF2_TIERS = [null, ['#b06a2c', '#e6a46a', 'Bronce'], ['#8c8c8c', '#e8e8e8', 'Plata'],
                 ['#b8860b', '#ffe066', 'Oro'], ['#3a9bd5', '#bfe9ff', 'Diamante']];
var PF2_TIER_XP = [0, 100, 300, 600];   /* = PF2_TIER_XP de perfil2.php */
function pf2v() { return (STATE.profile && STATE.profile.v2) || null; }
function pf2Ruta(u) {
    if (!u) return '';
    return /^(https?:|data:|\/|\.\.\/)/.test(u) ? u : '../../' + u;
}
/* La misma medalla que el PC (perfil-v2.js, medalSvg). */
function pf2Medalla(m) {
    if (m.sprite) return '<img class="pf2m-medal-img" src="' + esc(pf2Ruta(m.sprite)) + '" alt="">';
    var t = m.unique ? ['var(--accent)', 'color-mix(in srgb, var(--accent) 30%, #fff)'] : (PF2_TIERS[m.tier] || PF2_TIERS[1]);
    return '<svg viewBox="0 0 34 40"><path d="M9 0h6l2 10-6 0z" fill="var(--accent)"/><path d="M19 0h6l-5 10h-3z" fill="var(--accent-deep, var(--accent))"/>' +
        '<circle cx="17" cy="25" r="13" style="fill:' + t[1] + ';stroke:' + t[0] + '" stroke-width="3"/>' +
        '<text x="17" y="30" font-size="13" text-anchor="middle">' + m.emoji + '</text></svg>';
}
/* La de más nivel (a igualdad, la primera de la lista); la de Tester
   solo si no hay ninguna otra. */
function pf2Mejor(meds) {
    var mejor = null;
    meds.forEach(function(m) {
        if (!m.tier) return;
        if (!mejor || (mejor.unique && !m.unique) || (!m.unique && m.tier > mejor.tier)) mejor = m;
    });
    return mejor;
}
function pf2ProfileKey() { return STATE.viewingUser || USER_KEY; }

function renderPerfil2() {
    var box = document.getElementById('pf2m');
    if (!box) return;
    var v2 = pf2v();
    renderPf2Racha();
    if (!v2) { box.innerHTML = '<div class="mh-empty">Cargando…</div>'; return; }
    var st = v2.stats || {}, c = st.counts || {}, meds = st.medals || [], fr = v2.friends || [];
    var mejor = pf2Mejor(meds);
    var ganadas = meds.filter(function(m) { return m.tier > 0; });
    var html = '<div class="pf2m-relieve pf2m-lvlbox"><div class="pf2m-lvl">Nivel <span class="pf2m-level">' + (st.level || 1) + '</span></div>' +
        (mejor
            ? '<div class="pf2m-best">' + pf2Medalla(mejor) + '<div class="pf2m-best-t">' + esc(mejor.name) +
              '<small>' + (mejor.unique ? 'Única' : esc(PF2_TIERS[mejor.tier][2]) + ' · ' + PF2_TIER_XP[mejor.tier] + ' XP') + '</small></div></div>'
            : '<div class="pf2m-best"><div class="pf2m-best-t"><small>Sin medallas todavía</small></div></div>') + '</div>';
    html += '<div class="pf2m-relieve pf2m-estado" id="pf2m-estado"></div>';
    var tile = function(tit, n, dentro, t) {
        return '<div class="pf2m-relieve pf2m-tile"' + (t ? ' data-t="' + t + '"' : '') + '><div class="pf2m-tile-t">' + tit + ' <span>' + n + '</span></div>' +
            (dentro ? '<div class="pf2m-icons">' + dentro + '</div>' : '') + '</div>';
    };
    /* Tres, como Steam: con marco no caben más en el recuadro. */
    var fotos = fr.slice(0, 3).map(function(f) {
        return '<span class="pf2m-fav" data-marco-user="' + esc(f.key) + '">' +
            (f.image ? '<img src="' + esc(pf2Ruta(f.image)) + '" alt="">' : esc((f.label || '?').slice(0, 2))) + '</span>';
    }).join('');
    html += '<div class="pf2m-grid">' +
        tile('Medallas', ganadas.length, ganadas.slice(0, 3).map(pf2Medalla).join(''), 'med') +
        tile('Amigos', fr.length, fotos, 'am') +
        tile('Reseñas', c.reviews || 0) + tile('Capturas', c.shots || 0) +
        tile('Horas escuchadas', (c.hours || 0).toLocaleString('es')) +
        tile('En Melon Hub', (c.days || 0) + (c.days === 1 ? ' día' : ' días')) + '</div>';
    /* Expositores, justo encima de los comentarios. */
    html += '<div class="pf2m-expo" id="pf2m-expo"></div>';
    html += '<div class="window pf2m-com-win" id="pf2m-com"></div>';
    box.innerHTML = html;
    pf2Estado();
    renderPf2Expositores();
    renderPf2Comentarios();
    box.querySelectorAll('.pf2m-tile[data-t]').forEach(function(t) {
        t.addEventListener('click', function() {
            if (t.dataset.t === 'med') pf2AbrirMedallas();
            else showView('social');   /* la página de Amigos (la tuya, como en el PC) */
        });
    });
}

/* La franja del estado; también al llegar la presencia nueva. */
function pf2Estado() {
    var el = document.getElementById('pf2m-estado');
    if (!el) return;
    var k = pf2ProfileKey(), cls = 'online', txt = 'En línea';
    if (STATE.viewingUser) {
        if (!__pfLastOnline[k]) { cls = 'offline'; txt = 'Desconectado'; }
        else if (__pfLastNp[k] && __pfLastNp[k].title) txt = 'Escuchando: ' + __pfLastNp[k].title + (__pfLastNp[k].artist ? ' — ' + __pfLastNp[k].artist : '');
        else if (__pfLastDnd[k]) { cls = 'dnd'; txt = 'No molestar'; }
        else if (__pfLastAway[k]) { cls = 'away'; txt = 'Ausente'; }
    }
    el.className = 'pf2m-relieve pf2m-estado is-' + cls;
    el.textContent = txt;
}

/* Tocar "Medallas": todas, con la ficha de cada una (lo que en el PC sale
   al pasar el ratón). */
function pf2AbrirMedallas() {
    var v2 = pf2v();
    if (!v2) return;
    /* Solo las ganadas: las que no tienes no salen (ni en gris). */
    var filas = ((v2.stats || {}).medals || []).filter(function(m) { return m.tier > 0; }).map(function(m) {
        var ficha;
        if (m.unique) ficha = esc(m.desc || '');
        else {
            var sig = m.next == null ? 'Nivel máximo'
                : PF2_TIERS[m.tier + 1][2] + ' con ' + m.next.toLocaleString('es') + ' ' + m.unit.split(' ')[0];
            ficha = m.value.toLocaleString('es') + ' ' + esc(m.unit) + ' · ' + esc(sig) +
                '<div class="pf2m-bar"><i style="width:' + (m.progress || 0) + '%"></i></div>';
        }
        return '<div class="pf2m-mrow">' + pf2Medalla(m) + '<div class="pf2m-mtxt"><b>' + esc(m.name) + ' · ' +
            (m.unique ? 'Única' : PF2_TIERS[m.tier][2]) + '</b><br>' + ficha + '</div></div>';
    }).join('') || '<div class="mh-empty">Sin medallas todavía.</div>';
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML = '<div class="window pf-modal"><div class="title-bar"><div class="title-bar-text">Medallas</div>' +
        '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div>' +
        '<div class="window-body">' + filas + '<div class="modal-actions"><button class="button" data-act="close" type="button">Cerrar</button></div></div></div>';
    document.body.appendChild(bd);
    var cerrar = function() { if (bd.parentNode) bd.parentNode.removeChild(bd); };
    bd.querySelector('.title-bar-controls button').addEventListener('click', cerrar);
    bd.querySelector('[data-act="close"]').addEventListener('click', cerrar);
    bd.addEventListener('click', function(e) { if (e.target === bd) cerrar(); });
}

/* ─── Expositores (los del PC, perfil-v2.js RENDER, en una columna) ───
   Decidido así (7/11, boceto aprobado en
   electron/.e2e/perfil-movil-mock/preview-expositores.png): cada uno en su
   ventanita, uno debajo de otro, encima de los comentarios. Lo que en el
   PC sale al pasar el ratón (ficha de un juego, de un logro) sale al
   tocar; lo que sale con clic derecho ("▶ Reproducir" en discos y en las
   canciones de la playlist), al mantener pulsado. A los demás no se les
   enseñan los vacíos; los de pago sin comprar no salen (se ven en
   Personalizar). */
var PF2_TYPES = {
    media:  { label: 'Media favorita',   icon: 'juegosIcon' },
    shots:  { label: 'Capturas',         icon: 'galeriaIcon' },
    review: { label: 'Reseña destacada', icon: 'bestOfTheYearIcon' },
    steam_rare: { label: 'Logros raros de Steam', icon: 'steamIcon' },
    steam_ach:  { label: 'Logros de Steam',       icon: 'steamIcon' },
    game_feat:  { label: 'Juego destacado',       icon: 'juegosIcon' },
    playlist:   { label: 'Playlist destacada',    icon: 'songIcon' },
    text:       { label: 'Texto libre',           icon: 'newsIcon' }
};
var PF2_MEDIA = {
    games:  { label: 'Juegos favoritos',    icon: 'juegosIcon' },
    movies: { label: 'Películas favoritas', icon: 'pelisIcon' },
    series: { label: 'Series favoritas',    icon: 'melonArchiveIcon' },
    books:  { label: 'Libros favoritos',    icon: 'booksIcon' },
    music:  { label: 'Música favorita',     icon: 'musicaIcon' }
};
var PF2_CAT_LABEL = { movies: 'Película', series: 'Serie', books: 'Libro', games: 'Juego', music: 'Música' };
function pf2Ic(n, size) {
    size = size || 14;
    return '<img src="../../assets/img/appIcons/' + n + '.png" alt="" style="width:' + size + 'px;height:' + size + 'px;object-fit:contain;image-rendering:pixelated;">';
}
function pf2Stars(n) {
    n = Math.max(0, Math.min(5, Number(n) || 0));
    var full = Math.floor(n);
    return '★'.repeat(full) + (n - full >= 0.5 ? '½' : '');
}
function pf2Vacio(sc) {
    if (sc.locked) return true;
    var d = sc.data;
    if (!d) return true;
    if (Array.isArray(d)) return !d.length;
    if (sc.type.indexOf('steam_') === 0) return !(d.items || []).length;
    if (sc.type === 'playlist') return !d.id;
    if (sc.type === 'text') return !d.text;
    return !d.title;
}
function pf2TextoVacio(type) {
    if (type.indexOf('steam_') === 0) {
        var st = (pf2v() || {}).steam || {};
        if (!st.configured) return 'Steam no está configurado en la web.';
        if (!st.linked) return 'Conecta tu Steam en Personalizar.';
        if (st['private']) return 'Tu perfil de Steam es privado: pon el perfil y "Detalles del juego" en público.';
        if (type === 'steam_rare') return 'Aún no hay logros que enseñar.';
    }
    return 'Vacío: elige qué enseñar en Personalizar.';
}
function pf2Img(u, cls) {
    return u ? '<img' + (cls ? ' class="' + cls + '"' : '') + ' src="' + esc(pf2Ruta(u)) + '" alt="" loading="lazy">' : '';
}

function renderPf2Expositores() {
    var box = document.getElementById('pf2m-expo');
    var v2 = pf2v();
    if (!box || !v2) return;
    var own = !STATE.viewingUser;
    if (own && PF2E.editing) { renderPf2Edicion(box); return; }
    box.innerHTML = '';
    (v2.showcases || []).forEach(function(sc) {
        var vacio = pf2Vacio(sc);
        if (sc.locked || (vacio && !own)) return;
        var t = sc.type === 'media' ? (PF2_MEDIA[(sc.config || {}).cat] || PF2_MEDIA.games) : PF2_TYPES[sc.type];
        if (!t) return;
        var sub = '';
        if (sc.type === 'shots' && v2.stats) sub = (sc.data || []).length + ' de ' + ((v2.stats.counts || {}).shots || 0);
        var w = document.createElement('div');
        w.className = 'window pf2m-x';
        w.innerHTML = '<div class="title-bar"><div class="title-bar-text">' + pf2Ic(t.icon) + esc(t.label) + (sub ? '<span>' + sub + '</span>' : '') +
            '</div></div><div class="window-body"></div>';
        var body = w.querySelector('.window-body');
        if (vacio) body.innerHTML = '<div class="pf2m-vacio">' + esc(pf2TextoVacio(sc.type)) + '</div>';
        else PF2_RENDER[sc.type](body, sc.data, sc);
        box.appendChild(w);
    });
}

/* Ficha al tocar, dentro del expositor (una abierta a la vez; tocar
   otra vez o en cualquier otro sitio la cierra). */
function pf2Ficha(body, html, y) {
    var vieja = document.querySelector('.pf2m-tip');
    var misma = vieja && vieja.parentNode === body && vieja.dataset.y === String(y);
    if (vieja) vieja.remove();
    if (misma) return;
    var t = document.createElement('div');
    t.className = 'pf2m-tip';
    t.dataset.y = String(y);
    t.style.top = y + 'px';
    t.innerHTML = html;
    body.appendChild(t);
}
document.addEventListener('click', function(e) {
    if (e.target.closest('[data-ficha]') || e.target.closest('.pf2m-tip')) return;
    var t = document.querySelector('.pf2m-tip');
    if (t) t.remove();
});
function pf2Ach(a) {
    return '<b>' + esc(a.name) + '</b>' + (a.desc ? '<br>' + esc(a.desc) : '') + (a.game ? '<br><i>' + esc(a.game) + '</i>' : '') +
        (a.percent != null ? '<br>Lo tiene el ' + String(a.percent).replace('.', ',') + ' % de los jugadores' : '');
}
/* Toca un elemento con data-ficha: la ficha sale debajo de él. */
function pf2Fichas(body, fichas) {
    body.querySelectorAll('[data-ficha]').forEach(function(el) {
        el.addEventListener('click', function() {
            var r = el.getBoundingClientRect(), rb = body.getBoundingClientRect();
            pf2Ficha(body, fichas[+el.dataset.ficha], Math.round(r.bottom - rb.top + 2));
        });
    });
}

/* Mantener pulsado (500 ms, como en Amigos y Música): menú con
   "▶ Reproducir" (en el PC, clic derecho). */
function pf2Mantener(el, fn) {
    var t = null, x = 0, y = 0;
    var parar = function() { if (t) { clearTimeout(t); t = null; } el.classList.remove('long-pressing'); };
    var empezar = function(px, py) {
        parar(); x = px; y = py;
        el.classList.add('long-pressing');
        t = setTimeout(function() { t = null; el.classList.remove('long-pressing'); try { navigator.vibrate && navigator.vibrate(40); } catch (_) {} fn(); }, 500);
    };
    el.addEventListener('touchstart', function(e) { if (e.touches.length === 1) empezar(e.touches[0].clientX, e.touches[0].clientY); }, { passive: true });
    el.addEventListener('touchmove', function(e) { if (t && (Math.abs(e.touches[0].clientX - x) > 12 || Math.abs(e.touches[0].clientY - y) > 12)) parar(); }, { passive: true });
    el.addEventListener('touchend', parar);
    el.addEventListener('touchcancel', parar);
    el.addEventListener('mousedown', function(e) { if (e.button === 0) empezar(e.clientX, e.clientY); });
    el.addEventListener('mouseup', parar);
    el.addEventListener('mouseleave', parar);
    el.addEventListener('contextmenu', function(e) { e.preventDefault(); });
}
function pf2Menu(titulo, items) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML = '<div class="window pf-modal"><div class="title-bar"><div class="title-bar-text">' + esc(titulo) + '</div>' +
        '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div><div class="window-body">' +
        items.map(function(it, i) { return '<div class="pfa-menu-item" data-i="' + i + '">' + esc(it.label) + '</div>'; }).join('') +
        '<div class="modal-actions"><button class="button" data-act="close" type="button">Cerrar</button></div></div></div>';
    document.body.appendChild(bd);
    var cerrar = function() { if (bd.parentNode) bd.parentNode.removeChild(bd); };
    bd.querySelector('.title-bar-controls button').addEventListener('click', cerrar);
    bd.querySelector('[data-act="close"]').addEventListener('click', cerrar);
    bd.addEventListener('click', function(e) { if (e.target === bd) cerrar(); });
    bd.querySelectorAll('[data-i]').forEach(function(el) {
        el.addEventListener('click', function() { cerrar(); items[+el.dataset.i].fn(); });
    });
}
/* Playlist de cualquiera (shared-playlist, como melonPlayPlaylistById del
   PC) en el reproductor de la carcasa (MuShell.loadQueue). */
function pf2TocarPlaylist(id, desde) {
    var SHELL = null;
    try { SHELL = window.parent.MuShell; } catch (_) {}
    fetch('../../assets/music/api.php?action=shared-playlist&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(pl) {
            if (!pl || !pl.ok) { pfAlert((pl && pl.error) || 'No se pudo abrir la playlist.'); return; }
            var tracks = (pl.tracks || []).filter(function(t) { return t && t.videoId; });
            if (!tracks.length || !SHELL || typeof SHELL.loadQueue !== 'function') return;
            SHELL.loadQueue(tracks, Math.max(0, Math.min(desde | 0, tracks.length - 1)), pl.name || 'Playlist');
        })
        .catch(function() { pfAlert('Error de conexión'); });
}

var PF2_RENDER = {
    media: function(body, data, sc) {
        if ((sc.config || {}).cat === 'music') PF2_RENDER.music(body, data);
        else PF2_RENDER.posters(body, data);
    },
    /* Juegos, películas, series, libros: tocar = su ficha (Steam y reseña). */
    posters: function(body, data) {
        var fichas = data.map(function(it) {
            var h = '<b>' + esc(it.title) + '</b>';
            var st = it.steam;
            if (st && !st.inSteam) h += '<div class="pf2m-tr"><span>' + pf2Ic('steamIcon', 12) + 'No está en su Steam</span></div>';
            else if (st) {
                /* hours null: no lo tiene (familia, devuelto) y no se saben. */
                if (st.hours != null) h += '<div class="pf2m-tr"><span>' + pf2Ic('steamIcon', 12) + 'Horas en Steam</span><b>' + st.hours.toLocaleString('es') + ' h</b></div>';
                if (st.total) h += '<div class="pf2m-tr"><span>' + pf2Ic('bestOfTheYearIcon', 12) + 'Logros</span><b>' + st.achieved + ' / ' + st.total + '</b></div>' +
                    '<div class="pf2m-hund pf2m-bar"><i style="width:' + Math.round(100 * st.achieved / st.total) + '%"></i></div>';
            }
            return h + '<div class="pf2m-tr"><span>Reseña</span>' + (it.stars != null ? '<span class="pf2m-stars">' + pf2Stars(it.stars) + '</span>' : '<b>Sin reseña</b>') + '</div>';
        });
        body.innerHTML = '<div class="pf2m-row3 pf2m-poster">' + data.map(function(it, i) {
            return it.image ? '<img data-ficha="' + i + '" src="' + esc(pf2Ruta(it.image)) + '" alt="" loading="lazy">'
                            : '<div class="pf2m-noimg" data-ficha="' + i + '">' + esc(it.title) + '</div>';
        }).join('') + '</div>';
        pf2Fichas(body, fichas);
    },
    /* Discos: portada del álbum y nombre; mantener pulsado = Reproducir. */
    music: function(body, data) {
        body.innerHTML = '<div class="pf2m-row3 pf2m-album">' + data.map(function(it) {
            return '<div>' + (it.cover ? '<img src="' + esc(pf2Ruta(it.cover)) + '" alt="" loading="lazy">' : '<div class="pf2m-noimg">' + esc(it.title) + '</div>') +
                '<span>' + esc(it.title) + '</span></div>';
        }).join('') + '</div>';
        body.querySelectorAll('.pf2m-album > div').forEach(function(el, i) {
            var it = data[i];
            pf2Mantener(el, function() {
                pf2Menu(it.title + (it.artist ? ' — ' + it.artist : ''), [{ label: '▶ Reproducir', fn: function() {
                    playMusicItem({ type: it.type, title: it.title, artist: it.artist, ytId: it.ytId, ytPlaylistId: it.ytPlaylistId, spotifyAlbumId: it.spotifyAlbumId });
                } }]);
            });
        });
    },
    shots: function(body, data) {
        var big = data[0];
        body.innerHTML = '<div class="pf2m-shots">' + data.map(function(sh) { return pf2Img(sh.url); }).join('') +
            ((big.caption || big.game) ? '<div class="pf2m-shot-cap">' + esc(big.caption || '') + (big.caption && big.game ? ' · ' : '') + (big.game ? '<i>' + esc(big.game) + '</i>' : '') + '</div>' : '') +
            '</div>';
    },
    review: function(body, r) {
        body.innerHTML = '<div class="pf2m-rev">' + pf2Img(r.image) + '<div><h3>' + esc(r.title) + (r.artist ? ' — ' + esc(r.artist) : '') +
            ' <span class="pf2m-stars">' + pf2Stars(r.stars) + '</span></h3><small>' + esc(PF2_CAT_LABEL[r.category] || '') + '</small>' +
            (r.comment ? '<p>' + esc(r.comment) + '</p>' : '') + '</div></div>';
    },
    /* Juego destacado: con Steam, horas, logros X / Y y los elegidos
       (tocar = su ficha); sin Steam, la reseña. */
    game_feat: function(body, g) {
        var st = g.steam, der = '<h3>' + esc(g.title) + '</h3>', fichas = [];
        if (st && st.inSteam) {
            if (st.hours != null) der += pf2Ic('steamIcon', 12) + ' <b>' + st.hours.toLocaleString('es') + ' h</b> jugadas';
            if (st.total) {
                var pct = Math.round(100 * st.achieved / st.total);
                der += '<div class="pf2m-gf-p"><span>Logros <b>' + st.achieved + ' / ' + st.total + '</b></span><span>' + pct + ' %</span></div>' +
                    '<div class="pf2m-hund pf2m-bar"><i style="width:' + pct + '%"></i></div>';
            }
            if ((st.items || []).length) der += '<div class="pf2m-gf-achs">' + st.items.map(function(a, i) {
                fichas.push(pf2Ach(a));
                return '<img data-ficha="' + i + '" class="' + (a.percent != null && a.percent < 10 ? 'raro' : '') + '" src="' + esc(a.icon) + '" alt="">';
            }).join('') + '</div>';
        } else {
            if (st && !st.inSteam) der += '<div style="opacity:.8">' + pf2Ic('steamIcon', 12) + ' No está en su Steam</div>';
            der += '<div style="margin-top:4px">Reseña ' + (g.stars != null ? '<span class="pf2m-stars">' + pf2Stars(g.stars) + '</span>' : '<b>Sin reseña</b>') + '</div>' +
                (g.comment ? '<div style="margin-top:4px;line-height:1.4;white-space:pre-wrap">' + esc(g.comment) + '</div>' : '');
        }
        body.innerHTML = '<div class="pf2m-gf">' + (g.image ? pf2Img(g.image) : '<div class="pf2m-noimg">' + esc(g.title) + '</div>') +
            '<div class="pf2m-gf-t">' + der + '</div></div>';
        pf2Fichas(body, fichas);
    },
    playlist: function(body, pl) {
        var fmt = function(sec) { sec = sec | 0; return sec ? Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2) : ''; };
        body.innerHTML = '<div class="pf2m-pl">' + (pl.image ? pf2Img(pl.image) : '<div class="pf2m-noimg"></div>') +
            '<div class="pf2m-pl-t"><h3>' + esc(pl.name) + '</h3><small>' + pl.count + (pl.count === 1 ? ' canción' : ' canciones') +
                (pl.owner ? ' · playlist de ' + esc(pl.owner) : '') + '</small>' +
            '<div class="pf2m-pl-btns">' +
                (pl.count ? '<button type="button" class="button" data-a="play"><svg viewBox="0 0 8 8" width="8" height="8"><path d="M1 0l6 4-6 4z"/></svg>Reproducir</button>' : '') +
                (window.melonCopyLink ? '<button type="button" class="button" data-a="link">Copiar enlace</button>' : '') +
            '</div></div></div>' +
            '<div class="pf2m-pl-tracks">' + (pl.tracks || []).map(function(t, i) {
                return '<div class="pf2m-pl-row"><i>' + (i + 1) + '</i><span><b>' + esc(t.title) + '</b>' + (t.artist ? ' — ' + esc(t.artist) : '') +
                    '</span><em>' + fmt(t.duration) + '</em></div>';
            }).join('') + '</div>';
        var pb = body.querySelector('[data-a="play"]');
        if (pb) pb.addEventListener('click', function() { pf2TocarPlaylist(pl.id, 0); });
        var lb = body.querySelector('[data-a="link"]');
        if (lb) lb.addEventListener('click', function() { window.melonCopyLink('playlist', String(pl.id)); });
        body.querySelectorAll('.pf2m-pl-row').forEach(function(row, i) {
            pf2Mantener(row, function() {
                var t = pl.tracks[i];
                pf2Menu(t.title + (t.artist ? ' — ' + t.artist : ''), [{ label: '▶ Reproducir', fn: function() { pf2TocarPlaylist(pl.id, i); } }]);
            });
        });
    },
    steam_rare: function(body, d) { PF2_RENDER.logros(body, d); },
    steam_ach:  function(body, d) { PF2_RENDER.logros(body, d); },
    logros: function(body, d) {
        var items = d.items || [], tot = d.totals || {};
        body.innerHTML = '<div class="pf2m-ach7">' + items.map(function(a, i) {
            return '<img data-ficha="' + i + '" class="' + (a.percent != null && a.percent < 10 ? 'raro' : '') + '" src="' + esc(a.icon) + '" alt="">';
        }).join('') + '</div>' +
            '<div class="pf2m-ach-stats"><div><b>' + (tot.games || 0).toLocaleString('es') + '</b><span>juegos</span></div>' +
            '<div><b>' + (tot.hours || 0).toLocaleString('es') + '</b><span>horas jugadas</span></div></div>';
        pf2Fichas(body, items.map(pf2Ach));
    },
    text: function(body, d) {
        body.innerHTML = '<div class="pf2m-txt"></div>';
        body.firstChild.textContent = d.text;
    }
};

/* ─── Personalizar (expositores) ──────────────────────────────────────
   Decidido así (7/11, boceto aprobado en
   electron/.e2e/perfil-movil-mock/preview-personalizar.png): el botón
   "Editar perfil" pasa a ser "Personalizar" y entra en este modo; los
   datos (bio, cumpleaños…) se editan con el botón "Datos" de dentro.
   Arriba ✓ Guardar · Datos · Cancelar. Cada expositor con su barra:
   título (Media favorita: desplegable con el tipo), Editar y ✕; se
   ORDENA manteniendo pulsada la barra y arrastrando (como el PC, con el
   dedo). "＋ Añadir expositor" en los huecos libres y "Usas N de M
   huecos". Los selectores (Editar) son los del PC (perfil-v2.js PICK) en
   ventanitas del móvil. Nada cambia hasta Guardar (save-showcases, la
   misma acción que el PC). */
var PF2_PAID = ['review', 'game_feat', 'playlist'];
var PF2_MEDIA_CATS = ['games', 'movies', 'series', 'books', 'music'];
var PF2_MAX = { shots: 3, game_feat: 8, text: 1000 };
var PF2_FAV_NOUN = { games: ['juego', 'juegos', false], movies: ['película', 'películas', true],
                     series: ['serie', 'series', true], books: ['libro', 'libros', false] };
var PF2E = { editing: false, draft: [], catalog: {}, myShots: null };

function pf2Copia(o) { return JSON.parse(JSON.stringify(o)); }
function pf2Api(action, body) {
    return fetch(API + '?action=' + action, body === undefined ? { credentials: 'same-origin' } : {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    }).then(function(r) { return r.json(); });
}
function pf2Perks() { return ((pf2v() || {}).perks) || {}; }
function pf2MediaMax(cat) { return (pf2Perks().media || {})[cat] || 3; }
function pf2AchMax(type) { return 7 * ((pf2Perks().steamRows || {})[type] || 1); }
function pf2Libre(type) { return PF2_PAID.indexOf(type) < 0 || !!(pf2Perks().unlocked || {})[type]; }
function pf2MediaUsadas(menos) {
    return PF2E.draft.filter(function(s) { return s.type === 'media' && s !== menos; }).map(function(s) { return s.config.cat; });
}
function pf2Vacia(t, cat) {
    if (t === 'review') return { config: { category: '', title: '' }, data: {} };
    if (t === 'media') return { config: cat === 'music' ? { cat: cat, ids: [] } : { cat: cat, titles: [] }, data: [] };
    if (t === 'steam_rare') return { config: {}, data: {} };
    if (t === 'steam_ach') return { config: { items: [] }, data: {} };
    if (t === 'game_feat') return { config: { title: '', ach: [] }, data: {} };
    if (t === 'playlist') return { config: { id: 0 }, data: {} };
    if (t === 'text') return { config: { text: '' }, data: {} };
    return { config: { ids: [] }, data: [] };
}
function pf2OwnList(cat) { return (STATE.lists && STATE.lists[cat]) || []; }
function pf2OwnReview(cat, title) {
    var low = String(title).toLowerCase();
    var it = pf2OwnList(cat).find(function(x) { return String(x.title).toLowerCase() === low; });
    return it && it.review ? it.review : null;
}
function pf2CatImg(cat, t) { return PF2E.catalog[cat + '|' + String(t).toLowerCase()]; }
function pf2SetCatImg(cat, t, img) { if (img) PF2E.catalog[cat + '|' + String(t).toLowerCase()] = img; }

/* Entrar / salir del modo. */
function pf2Editar(on) {
    var v2 = pf2v();
    if (on && !v2) return;
    PF2E.editing = !!on;
    if (on) PF2E.draft = pf2Copia(v2.showcases || []);
    ['pf-edit-profile-btn', 'pf-link-btn'].forEach(function(id) { var b = document.getElementById(id); if (b) b.hidden = !!on; });
    ['pf2-guardar', 'pf2-datos', 'pf2-cancelar'].forEach(function(id) { var b = document.getElementById(id); if (b) b.hidden = !on; });
    PF2E.steamEsperando = false;
    PF2E.marco = undefined;   /* marco elegido sin guardar */
    pf2VistaMarco();
    if (on) pf2CargarMarcos();
    renderPf2Steam();
    renderPf2Expositores();
    if (on) {
        var box = document.getElementById('pf2m'), ex = document.getElementById('pf2m-expo');
        if (box && ex) box.scrollTop = Math.max(0, ex.offsetTop - box.offsetTop - 6);
    }
}
function pf2Guardar() {
    var b = document.getElementById('pf2-guardar');
    b.disabled = true;
    var fr = PF2E.marcos;
    /* El marco, solo si ha cambiado (como save() del PC). */
    var marco = (PF2E.marco !== undefined && fr && PF2E.marco !== fr.active)
        ? pf2Api('set-frame', { slug: PF2E.marco }).then(function(d) {
            if (!d || !d.ok) return d || { error: 'No se pudo guardar el marco' };
            fr.active = d.active || '';
            /* Aquí y en la barra de la carcasa (mobile.php). */
            if (window.MelonMarcos) MelonMarcos.set(USER_KEY, fr.active);
            try { if (window.parent !== window && window.parent.MelonMarcos) window.parent.MelonMarcos.set(USER_KEY, fr.active); } catch (_) {}
            return d;
        }).catch(function() { return { error: 'Error de conexión al guardar el marco' }; })
        : Promise.resolve({ ok: true });
    var expos = pf2Api('save-showcases', { showcases: PF2E.draft.map(function(s) { return { type: s.type, config: s.config }; }) }).then(function(d) {
        if (!d || !d.ok) return d || { error: 'No se pudieron guardar los expositores' };
        var v2 = pf2v();
        v2.showcases = d.showcases; v2.slots = d.slots; if (d.perks) v2.perks = d.perks;
        return d;
    }).catch(function() { return { error: 'Error de conexión' }; });
    Promise.all([expos, marco]).then(function(r) {
        b.disabled = false;
        var err = (r[0] && r[0].error) || (r[1] && r[1].error);
        if (err) { pfAlert(err); return; }
        pf2Editar(false);
    });
}

/* ── Steam (franja de Personalizar; assets/profile/steam.php) ──
   Como "Conectar Steam" del PC, pero en la app de Android el inicio de
   sesión de Steam se abre en el navegador del teléfono (no tiene la
   sesión de aquí): steam-link-start da la URL con un código de un solo
   uso. Al acabar, el timbre ('steam') o volver a la app ponen esto al día. */
function renderPf2Steam() {
    var box = document.getElementById('pf2m-steam');
    if (!box) return;
    var v2 = pf2v();
    if (!PF2E.editing || !v2) { box.hidden = true; box.innerHTML = ''; return; }
    var st = v2.steam || {};
    var ico = '<img src="../../assets/img/appIcons/steamIcon.png" alt="">';
    box.hidden = false;
    /* Primero "Marco" (como en el PC, junto a lo de Steam). */
    var marco = '<button type="button" class="button" data-a="marco">Marco</button>';
    if (!st.configured) {
        box.innerHTML = marco + '<span class="pf2m-steam-t">' + ico + 'Steam no está configurado en la web</span>';
    } else if (st.linked) {
        box.innerHTML = marco + '<span class="pf2m-steam-t">' + ico + '<b>' + esc(st.persona || 'Steam vinculado') + '</b></span>' +
            '<button type="button" class="button" data-a="off">Desconectar</button>' +
            (st['private'] ? '<div class="pf2m-steam-nota is-aviso">Tu perfil de Steam es privado: pon el perfil y "Detalles del juego" en público.</div>' : '');
        box.querySelector('[data-a="off"]').addEventListener('click', function() {
            pfConfirm('¿Desconectar tu cuenta de Steam?', 'Desconectar', function() {
                pf2Api('steam-disconnect', {}).then(function(d) {
                    if (!d || !d.ok) { pfAlert((d && d.error) || 'No se pudo desconectar'); return; }
                    pf2SteamAlDia();
                }).catch(function() { pfAlert('Error de conexión'); });
            });
        });
    } else {
        box.innerHTML = marco + '<span class="pf2m-steam-t">' + ico + 'Steam</span>' +
            '<button type="button" class="button" data-a="on">' + ico + 'Conectar Steam</button>' +
            (PF2E.steamEsperando ? '<div class="pf2m-steam-nota">Inicia sesión en Steam en el navegador y vuelve aquí.</div>' : '');
        box.querySelector('[data-a="on"]').addEventListener('click', function() {
            var b = this;
            b.disabled = true;
            pf2Api('steam-link-start', {}).then(function(d) {
                b.disabled = false;
                if (!d || !d.ok || !d.url) { pfAlert((d && d.error) || 'No se pudo abrir Steam'); return; }
                /* Un enlace normal: la app lo abre fuera (como el icono de Steam
                   del perfil); en el navegador, en otra pestaña. */
                var a = document.createElement('a');
                a.href = d.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                document.body.appendChild(a); a.click(); a.remove();
                PF2E.steamEsperando = true;
                renderPf2Steam();
            }).catch(function() { b.disabled = false; pfAlert('Error de conexión'); });
        });
    }
    box.querySelector('[data-a="marco"]').addEventListener('click', pf2AbrirMarcos);
}

/* ── Racha diaria e invitaciones (assets/profile/racha.php) ──
   Como el PC (perfil-v2.js renderRacha / openInvite): fueguito al lado
   del nombre, apagado (gris) hasta que lo pulsas hoy y encendido después,
   con los días. En el perfil de otra persona se ve su racha sin pulsar.
   En el móvil no hay "title" al pasar el ratón: tocarlo cuando ya no se
   puede encender enseña ese mismo texto en un aviso. */
function pf2Fuego(on) {
    var fuera = [[6,6],[5,6],[5,7],[4,7],[4,8],[3,8],[2,9],[2,9],[1,10],[1,10],[1,10],[2,9],[3,8],[4,7]];
    var dentro = { 7: [5,6], 8: [4,7], 9: [4,7], 10: [4,7], 11: [5,6] };
    var c1 = on ? '#ff7a1a' : '#8c8c8c', c2 = on ? '#ffd34d' : '#c8c8c8';
    var r = '';
    fuera.forEach(function(x, y) { r += '<rect x="' + x[0] + '" y="' + y + '" width="' + (x[1] - x[0] + 1) + '" height="1" fill="' + c1 + '"/>'; });
    Object.keys(dentro).forEach(function(y) { var x = dentro[y]; r += '<rect x="' + x[0] + '" y="' + y + '" width="' + (x[1] - x[0] + 1) + '" height="1" fill="' + c2 + '"/>'; });
    return '<svg viewBox="0 0 12 14" shape-rendering="crispEdges" aria-hidden="true">' + r + '</svg>';
}
function pf2Dias(n) { return n + (n === 1 ? ' día' : ' días'); }
/* Aviso de la carcasa (mToast de mobile.php); fuera de ella, la ventanita. */
function pf2Aviso(titulo, texto) {
    try {
        if (window.parent !== window && window.parent.mToast) {
            window.parent.mToast({ id: 'racha', title: esc(titulo), message: esc(texto), duration: 6000 });
            return;
        }
    } catch (_) {}
    pfAlert(titulo + (texto ? ' · ' + texto : ''));
}
function pf2RachaTexto(r, own) {
    if (own && !r.today) {
        var t = 'Enciende tu racha de hoy (+' + r.puntosHoy + ' puntos de autismo)';
        if (r.enPeligro) t += '. Ayer no la encendiste: tu protector de racha la salva';
        else if (r.current) t += '. Llevas ' + pf2Dias(r.current);
        return t;
    }
    var s = 'Racha de ' + pf2Dias(r.current || 0) + ' · mejor: ' + pf2Dias(r.best || 0);
    if (own) s += r.protector ? ' · protector de racha guardado' : ' · sin protector de racha';
    return s;
}
function renderPf2Racha() {
    var el = document.getElementById('pf2m-racha');
    if (!el) return;
    var v2 = pf2v(), r = v2 && v2.racha;
    if (!r) { el.innerHTML = ''; return; }
    var own = !STATE.viewingUser, on = !!r.today;
    el.className = 'pf2m-racha ' + (on ? 'is-on' : 'is-off') + (own && !on ? ' is-btn' : '');
    el.innerHTML = pf2Fuego(on) + '<b>' + (r.current || 0) + '</b>';
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', pf2RachaTexto(r, own));
}
var PF2_RACHA_PIDIENDO = false;
(function setupPf2Racha() {
    var el = document.getElementById('pf2m-racha');
    if (!el) return;
    el.addEventListener('click', function() {
        var v2 = pf2v(), r = v2 && v2.racha;
        if (!r) return;
        var own = !STATE.viewingUser;
        if (!own || r.today) { pf2Aviso(own ? 'Tu racha' : 'Racha de ' + (STATE.viewingLabel || '?'), pf2RachaTexto(r, own)); return; }
        if (PF2_RACHA_PIDIENDO) return;
        PF2_RACHA_PIDIENDO = true;
        pf2Api('streak-claim', {}).then(function(d) {
            PF2_RACHA_PIDIENDO = false;
            if (!d || !d.ok) { if (d && d.error) pfAlert(d.error); return; }
            v2.racha = d.racha;
            renderPf2Racha();
            var g = d.ganado || {};
            var lineas = ['+' + g.dia + ' puntos de autismo'];
            (g.hitos || []).forEach(function(h) { lineas.push('¡' + h.dias + ' días de racha! +' + h.puntos + ' puntos'); });
            if (g.invitacion) lineas.push('Invitación cumplida: +' + g.invitacion + ' puntos');
            if (g.protector) lineas.push('Tu protector de racha la ha salvado');
            pf2Aviso('Racha de ' + pf2Dias(d.racha.current), lineas.join(' · '));
        }).catch(function() { PF2_RACHA_PIDIENDO = false; pfAlert('Error de conexión'); });
    });
})();

/* Invitar (menú lateral): el enlace de invitación (enlace.php?invitar=…)
   y cómo van mis invitaciones. Tocar una = su perfil. */
function pf2AbrirInvitar() {
    var v = pf2Ventana('Invitar a Melon Hub');
    var url = window.melonLinkUrl ? window.melonLinkUrl('invitar', USER_KEY) : '';
    v.body.innerHTML =
        '<div class="pf2m-inv-txt">Si alguien se crea una cuenta con tu enlace y llega a 7 días de racha en sus primeros 14 días, os lleváis 500 puntos de autismo cada uno.</div>' +
        '<div class="pf2m-inv-link"><input type="text" readonly aria-label="Enlace de invitación"><button type="button" class="button" data-a="copy">Copiar enlace</button></div>' +
        '<div class="pf2m-inv-h">Mis invitaciones</div>' +
        '<div class="pf2m-inv-list"><div class="pf2m-inv-vacio">Cargando…</div></div>' +
        '<div class="modal-actions"><button type="button" class="button" data-a="cerrar">Cerrar</button></div>';
    var input = v.body.querySelector('input');
    input.value = url;
    input.addEventListener('focus', function() { input.select(); });
    v.body.querySelector('[data-a="copy"]').addEventListener('click', function() { if (window.melonCopyLink) window.melonCopyLink('invitar', USER_KEY); });
    v.body.querySelector('[data-a="cerrar"]').addEventListener('click', v.close);
    var list = v.body.querySelector('.pf2m-inv-list');
    pf2Api('invites').then(function(d) {
        var inv = (d && d.invites) || [];
        if (!inv.length) { list.innerHTML = '<div class="pf2m-inv-vacio">Aún no has invitado a nadie.</div>'; return; }
        list.innerHTML = inv.map(function(i) {
            var st = i.estado === 'cobrada' ? '+' + d.puntos + ' puntos conseguidos'
                   : i.estado === 'caducada' ? 'Caducada'
                   : 'Racha ' + i.racha + ' / ' + d.racha + ' · quedan ' + pf2Dias(i.quedan);
            return '<div class="pf2m-inv-row is-' + esc(i.estado) + '" data-key="' + esc(i.key) + '">' +
                '<span class="pf2m-inv-av" data-marco-user="' + esc(i.key) + '">' +
                (i.image ? '<img src="' + esc(pf2Ruta(i.image)) + '" alt="">' : esc((i.label || '?').slice(0, 2))) + '</span>' +
                '<span class="pf2m-inv-name">' + esc(i.label) + '</span><span class="pf2m-inv-st">' + esc(st) + '</span></div>';
        }).join('');
        list.querySelectorAll('[data-key]').forEach(function(row) {
            row.addEventListener('click', function() { v.close(); viewOtherUser(row.dataset.key); });
        });
    }).catch(function() { list.innerHTML = '<div class="pf2m-inv-vacio">No se han podido cargar.</div>'; });
}

/* ── Marco de la foto (assets/profile/marcos.php; en el PC, el botón
   "Marco" de Personalizar) ──
   Ventanita con tu foto en cada marco comprado (pestaña Perfil de la
   tienda) y "Sin marco". Aceptar lo enseña ya en la cabecera
   (data-marco-preview); se guarda con "Guardar" y "Cancelar" lo quita. */
function pf2CargarMarcos() {
    PF2E.marcosP = pf2Api('my-frames').then(function(d) {
        if (d && d.ok) PF2E.marcos = { owned: d.owned || [], active: d.active || '' };
    }).catch(function() {});
    return PF2E.marcosP;
}
function pf2VistaMarco() {
    var el = document.getElementById('pf-userbar-avatar');
    if (!el) return;
    if (PF2E.editing && PF2E.marco !== undefined) el.setAttribute('data-marco-preview', PF2E.marco);
    else el.removeAttribute('data-marco-preview');
}
function pf2AbrirMarcos() {
    (PF2E.marcosP || pf2CargarMarcos()).then(function() {
        var fr = PF2E.marcos || { owned: [], active: '' };
        var sel = PF2E.marco !== undefined ? PF2E.marco : fr.active;
        var img = document.querySelector('#pf-userbar-avatar img');
        var foto = img ? '<img src="' + esc(img.getAttribute('src')) + '" alt="">' : '<span class="pf2m-mk-ph"></span>';
        var v = pf2Ventana('Marco de la foto');
        var grid = document.createElement('div');
        grid.className = 'pf2m-mk-grid';
        grid.innerHTML = [{ slug: '', nombre: 'Sin marco' }].concat(fr.owned).map(function(m) {
            return '<div class="pf2m-mk' + (m.slug === sel ? ' on' : '') + '" data-slug="' + esc(m.slug) + '">' +
                '<div class="pf2m-mk-hueco"><span class="pf2m-mk-foto" data-marco-preview="' + esc(m.slug) + '">' + foto + '</span></div>' +
                '<div class="pf2m-mk-t">' + esc(m.nombre) + '</div></div>';
        }).join('');
        grid.addEventListener('click', function(e) {
            var c = e.target.closest('.pf2m-mk');
            if (!c) return;
            sel = c.dataset.slug;
            grid.querySelectorAll('.pf2m-mk').forEach(function(x) { x.classList.toggle('on', x === c); });
        });
        v.body.appendChild(grid);
        var info = pf2Pie(v, function() { PF2E.marco = sel; pf2VistaMarco(); });
        info.textContent = fr.owned.length ? 'Se guarda al pulsar "Guardar".' : 'Aún no tienes marcos: están en la tienda, pestaña Perfil.';
    });
}

/* Vuelve a pedir el estado de Steam (y el icono de Steam de la cabecera,
   que el vincular y el desconectar cambian). Los expositores en edición
   se quedan como están: solo se repintan (el texto de los vacíos de
   Steam depende de si está conectado). */
function pf2SteamAlDia() {
    return pf2Api('get-profile').then(function(p) {
        var v2 = pf2v();
        if (!p || !p.v2 || !v2 || STATE.viewingUser) return;
        var antes = !!(v2.steam && v2.steam.linked);
        v2.steam = p.v2.steam;
        if (STATE.profile) { STATE.profile.steam = p.steam; renderAbout(STATE.profile); }
        if (v2.steam && v2.steam.linked !== antes) PF2E.steamEsperando = false;
        renderPf2Steam();
        renderPf2Expositores();
    }).catch(function() {});
}
(function setupPf2Steam() {
    function siEspera() { if (PF2E.editing && PF2E.steamEsperando) pf2SteamAlDia(); }
    var t = timbreMovil();
    if (t) {
        var baja = t.on('steam', function() { if (PF2E.editing) pf2SteamAlDia(); });
        window.addEventListener('pagehide', function() { try { baja(); } catch (_) {} });
    }
    /* Al volver del navegador (por si el timbre tarda). */
    document.addEventListener('visibilitychange', function() { if (!document.hidden) siEspera(); });
    window.addEventListener('focus', siEspera);
})();

/* Los expositores en modo Personalizar (lo llama renderPf2Expositores). */
function renderPf2Edicion(box) {
    var v2 = pf2v();
    box.innerHTML = '';
    PF2E.draft.forEach(function(sc, i) {
        var t = sc.type === 'media' ? (PF2_MEDIA[sc.config.cat] || PF2_MEDIA.games) : PF2_TYPES[sc.type];
        if (!t) return;
        var titulo = '<span class="pf2m-ed-t">' + esc(t.label) + '</span>';
        if (sc.type === 'media') {
            var usadas = pf2MediaUsadas(sc);
            titulo = '<select class="pf2m-ed-sel" aria-label="Tipo">' + PF2_MEDIA_CATS.filter(function(c) {
                return c === sc.config.cat || usadas.indexOf(c) < 0;
            }).map(function(c) {
                return '<option value="' + c + '"' + (c === sc.config.cat ? ' selected' : '') + '>' + esc(PF2_MEDIA[c].label) + '</option>';
            }).join('') + '</select>';
        }
        var w = document.createElement('div');
        w.className = 'window pf2m-x pf2m-ed';
        w.dataset.idx = i;
        w.innerHTML = '<div class="title-bar pf2m-ed-bar"><div class="title-bar-text"><span class="pf2m-ed-asa" aria-hidden="true">⠿</span>' + pf2Ic(t.icon) + titulo +
            (sc.type === 'steam_rare' || sc.locked ? '' : '<button type="button" class="button pf2m-ed-btn" data-a="edit">Editar</button>') +
            '<button type="button" class="button pf2m-ed-btn" data-a="del" aria-label="Quitar expositor">✕</button></div></div><div class="window-body"></div>';
        var body = w.querySelector('.window-body');
        if (sc.locked) body.innerHTML = '<div class="pf2m-vacio pf2m-ed-lock">Bloqueado: cómpralo en la tienda (pestaña Perfil) para que salga en tu perfil.</div>';
        else if (pf2Vacio(sc)) {
            /* Lo de Steam explica qué falta; lo demás, que se elige con Editar. */
            var tv = pf2TextoVacio(sc.type);
            body.innerHTML = '<div class="pf2m-vacio">' + esc(/^Vacío/.test(tv) ? 'Pulsa "Editar" para elegir qué enseñar.' : tv) + '</div>';
        }
        else PF2_RENDER[sc.type](body, sc.data, sc);
        var sel = w.querySelector('.pf2m-ed-sel');
        if (sel) sel.addEventListener('change', function() {
            var b = pf2Vacia('media', sel.value);
            sc.config = b.config; sc.data = b.data;
            renderPf2Expositores();
        });
        var eb = w.querySelector('[data-a="edit"]');
        if (eb) eb.addEventListener('click', function() { pf2AbrirSelector(i); });
        w.querySelector('[data-a="del"]').addEventListener('click', function() {
            PF2E.draft.splice(i, 1);
            renderPf2Expositores();
        });
        pf2Arrastrable(w, box);
        box.appendChild(w);
    });
    var usados = PF2E.draft.length, huecos = v2.slots || 2, max = v2.maxSlots || 5;
    for (var k = usados; k < huecos; k++) {
        var add = document.createElement('div');
        add.className = 'pf2m-slot add';
        add.textContent = '＋ Añadir expositor';
        add.addEventListener('click', pf2AbrirTipos);
        box.appendChild(add);
    }
    var info = document.createElement('div');
    info.className = 'pf2m-slot-info';
    info.innerHTML = 'Usas <b>' + usados + ' de ' + huecos + '</b> huecos' +
        (huecos < max ? ' · consigue más en la tienda de puntos (hasta ' + max + ')' : ' (máximo)');
    box.appendChild(info);
}

/* Ordenar: mantener pulsado el expositor (500 ms; en cualquier sitio
   menos los botones y el desplegable, que en Media favorita ocupa casi
   toda la barra) y arrastrar. Mientras se
   arrastra no hay scroll de la página (se mueve sola cerca de los
   bordes) y el expositor se coloca delante del primero cuyo centro queda
   por debajo del dedo. Al soltar, el borrador toma el orden nuevo. */
function pf2Arrastrable(w, box) {
    var bar = w;
    var t = null, x0 = 0, y0 = 0, arr = false, scrollT = null, ultY = 0;
    var scroller = document.getElementById('pf2m');
    var parar = function() { if (t) { clearTimeout(t); t = null; } bar.classList.remove('long-pressing'); };
    var empezar = function(x, y, e) {
        if (e.target.closest('button, select')) return;
        parar(); x0 = x; y0 = y;
        bar.classList.add('long-pressing');
        t = setTimeout(function() {
            t = null; arr = true; ultY = y0;
            bar.classList.remove('long-pressing');
            w.classList.add('pf2m-arrastra');
            try { navigator.vibrate && navigator.vibrate(40); } catch (_) {}
            scrollT = setInterval(function() {
                var r = scroller.getBoundingClientRect();
                if (ultY < r.top + 50) scroller.scrollTop -= 12;
                else if (ultY > r.bottom - 50) scroller.scrollTop += 12;
                else return;
                colocar(ultY);
            }, 30);
        }, 500);
    };
    var colocar = function(y) {
        var otros = Array.prototype.filter.call(box.querySelectorAll('.pf2m-ed'), function(x) { return x !== w; });
        var antes = otros.find(function(x) { var r = x.getBoundingClientRect(); return y < r.top + r.height / 2; });
        if (antes) { if (w.nextSibling !== antes) box.insertBefore(w, antes); }
        else {
            var ultimo = otros[otros.length - 1];
            if (ultimo && ultimo.nextSibling !== w) box.insertBefore(w, ultimo.nextSibling);
        }
    };
    var mover = function(x, y, e) {
        if (t && (Math.abs(x - x0) > 12 || Math.abs(y - y0) > 12)) parar();
        if (!arr) return;
        if (e.cancelable) e.preventDefault();
        ultY = y;
        colocar(y);
    };
    var soltar = function() {
        parar();
        if (!arr) return;
        arr = false;
        clearInterval(scrollT);
        w.classList.remove('pf2m-arrastra');
        var orden = Array.prototype.map.call(box.querySelectorAll('.pf2m-ed'), function(x) { return +x.dataset.idx; });
        PF2E.draft = orden.map(function(i) { return PF2E.draft[i]; });
        renderPf2Expositores();
    };
    bar.addEventListener('touchstart', function(e) { if (e.touches.length === 1) empezar(e.touches[0].clientX, e.touches[0].clientY, e); }, { passive: true });
    bar.addEventListener('touchmove', function(e) { mover(e.touches[0].clientX, e.touches[0].clientY, e); }, { passive: false });
    bar.addEventListener('touchend', soltar);
    bar.addEventListener('touchcancel', soltar);
    bar.addEventListener('contextmenu', function(e) { e.preventDefault(); });
    /* Ratón (pruebas en el escritorio): el mismo gesto. */
    bar.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        empezar(e.clientX, e.clientY, e);
        var mm = function(ev) { mover(ev.clientX, ev.clientY, ev); };
        var mu = function() { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); soltar(); };
        document.addEventListener('mousemove', mm);
        document.addEventListener('mouseup', mu);
    });
}

/* "＋ Añadir expositor": los tipos que faltan (Media favorita mientras
   quede algún tipo libre; los de pago, solo comprados, como el PC). */
function pf2AbrirTipos() {
    var usados = PF2E.draft.map(function(s) { return s.type; });
    var libres = PF2_MEDIA_CATS.filter(function(c) { return pf2MediaUsadas(null).indexOf(c) < 0; });
    var filas = [];
    libres.forEach(function(c) { filas.push({ t: 'media', cat: c, label: PF2_MEDIA[c].label, icon: PF2_MEDIA[c].icon }); });
    Object.keys(PF2_TYPES).forEach(function(t) {
        if (t === 'media' || usados.indexOf(t) >= 0 || !pf2Libre(t)) return;
        filas.push({ t: t, label: PF2_TYPES[t].label, icon: PF2_TYPES[t].icon, pago: PF2_PAID.indexOf(t) >= 0 });
    });
    var bd = pf2Ventana('Añadir expositor');
    bd.body.innerHTML = (filas.length ? filas.map(function(f, i) {
        return '<div class="pf2m-tipo" data-i="' + i + '">' + pf2Ic(f.icon) + esc(f.label) + (f.pago ? '<small>De pago</small>' : '') + '</div>';
    }).join('') : '<div class="pf2m-vacio">Ya tienes todos los tipos.</div>') +
        '<div class="modal-actions"><button class="button" data-act="close" type="button">Cerrar</button></div>';
    bd.body.querySelector('[data-act="close"]').addEventListener('click', bd.close);
    bd.body.querySelectorAll('[data-i]').forEach(function(el) {
        el.addEventListener('click', function() {
            var f = filas[+el.dataset.i], b = pf2Vacia(f.t, f.cat);
            bd.close();
            PF2E.draft.push({ type: f.t, config: b.config, data: b.data });
            renderPf2Expositores();
            /* Los raros se calculan solos al guardar. */
            if (f.t !== 'steam_rare') pf2AbrirSelector(PF2E.draft.length - 1);
        });
    });
}

/* Ventanita del móvil (como pfConfirm) para los selectores. */
function pf2Ventana(titulo) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML = '<div class="window pf-modal pf2m-picker"><div class="title-bar"><div class="title-bar-text">' + esc(titulo) + '</div>' +
        '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div></div><div class="window-body"></div></div>';
    document.body.appendChild(bd);
    var close = function() { if (bd.parentNode) bd.parentNode.removeChild(bd); window.__pf2mAfterAdd = null; };
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    return { bd: bd, body: bd.querySelector('.window-body'), close: close };
}
/* Pie: información + Aceptar / Cancelar. */
function pf2Pie(v, onOk) {
    var f = document.createElement('div');
    f.className = 'pf2m-pie';
    f.innerHTML = '<span></span><button type="button" class="button" data-a="ok">Aceptar</button><button type="button" class="button" data-a="no">Cancelar</button>';
    f.querySelector('[data-a="ok"]').addEventListener('click', function() { if (onOk() === false) return; v.close(); renderPf2Expositores(); });
    f.querySelector('[data-a="no"]').addEventListener('click', v.close);
    v.body.appendChild(f);
    return f.querySelector('span');
}
function pf2AbrirSelector(idx) {
    var sc = PF2E.draft[idx];
    if (!sc || sc.locked) return;
    if (sc.type === 'media') { if (sc.config.cat === 'music') PF2_PICK.music(sc); else PF2_PICK.fav(sc, sc.config.cat); return; }
    if (PF2_PICK[sc.type]) PF2_PICK[sc.type](sc);
}
/* Casilla de una cuadrícula de selector (póster, cuadrada o 16:9). */
function pf2Casilla(attr, valor, img, titulo, n, forma) {
    return '<div class="pf2m-pk ' + (forma || 'portrait') + (n ? ' on' : '') + '" data-' + attr + '="' + esc(valor) + '">' +
        (img ? '<img src="' + esc(pf2Ruta(img)) + '" alt="" loading="lazy">' : '<div class="pf2m-pk-ph"></div>') +
        (n ? '<span class="pf2m-pk-n">' + n + '</span>' : '') + '<div class="pf2m-pk-t">' + esc(titulo) + '</div></div>';
}
function pf2Marcar(grid, attr, sel, conv) {
    grid.querySelectorAll('.pf2m-pk').forEach(function(e) {
        var v = e.dataset[attr], n = sel.indexOf(conv ? conv(v) : v) + 1;
        e.classList.toggle('on', n > 0);
        var b = e.querySelector('.pf2m-pk-n');
        if (n && !b) { b = document.createElement('span'); b.className = 'pf2m-pk-n'; e.insertBefore(b, e.querySelector('.pf2m-pk-t')); }
        if (n) b.textContent = n; else if (b) b.remove();
    });
}
/* Portada del álbum de un disco sin portada (find-album, como el PC). */
function pf2BuscarPortada(it) {
    var key = 'pf2:cover:' + (it.ytId || (it.title + '|' + it.artist));
    try { var c = localStorage.getItem(key); if (c) return Promise.resolve(c); } catch (_) {}
    var q = '../../assets/music/api.php?action=find-album&title=' + encodeURIComponent(it.title || '') +
        '&artist=' + encodeURIComponent(it.artist || '') + (it.ytId ? '&videoId=' + encodeURIComponent(it.ytId) : '');
    return fetch(q, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(function(d) {
        var url = (d && (d.albumImage || d.image)) || '';
        if (url) { try { localStorage.setItem(key, url); } catch (_) {} }
        return url;
    }).catch(function() { return ''; });
}
function pf2EsMiniYt(u) { return /ytimg\.com|youtube\.com|img\.youtube/i.test(u || ''); }

/* Google Drive de la Galería (mismo permiso, carpeta y token que
   galeria-mobile.php): para Capturas. */
var PF2_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
var PF2_DRIVE_FOLDER = 'Scrapbook Melon - Galería';
function pf2DriveToken() {
    try { var t = JSON.parse(localStorage.getItem('galeria_drive_token') || 'null'); if (t && t.expires_at > Date.now() + 30000) return t; } catch (_) {}
    return null;
}
function pf2DriveConectar(cb) {
    var listo = function() { return window.google && google.accounts && google.accounts.oauth2; };
    var seguir = function() {
        if (!PF2_GOOGLE_CLIENT_ID) { cb(new Error('Google no está configurado en la web')); return; }
        google.accounts.oauth2.initTokenClient({ client_id: PF2_GOOGLE_CLIENT_ID, scope: PF2_DRIVE_SCOPE, callback: function(resp) {
            if (resp.error) { cb(new Error(resp.error)); return; }
            var t = { access_token: resp.access_token, expires_at: Date.now() + resp.expires_in * 1000 - 60000 };
            try { localStorage.setItem('galeria_drive_token', JSON.stringify(t)); localStorage.setItem('galeria_drive_ever_auth', '1'); } catch (_) {}
            cb(null, t);
        } }).requestAccessToken({ prompt: '' });
    };
    if (listo()) { seguir(); return; }
    if (!document.querySelector('script[data-pf2-gis]')) {
        var s = document.createElement('script');
        s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.setAttribute('data-pf2-gis', '1');
        document.head.appendChild(s);
    }
    var n = 0, iv = setInterval(function() {
        if (listo()) { clearInterval(iv); seguir(); }
        else if (++n > 80) { clearInterval(iv); cb(new Error('no se pudo cargar el inicio de sesión de Google')); }
    }, 250);
}
function pf2Drive(url, opts) {
    var t = pf2DriveToken();
    if (!t) return Promise.reject({ auth: true });
    opts = opts || {};
    opts.headers = Object.assign({}, opts.headers || {}, { Authorization: 'Bearer ' + t.access_token });
    return fetch(url, opts).then(function(r) {
        if (r.status === 401) { try { localStorage.removeItem('galeria_drive_token'); } catch (_) {} throw { auth: true }; }
        if (!r.ok) return r.text().then(function(x) { throw new Error('Drive ' + r.status + ': ' + x.slice(0, 120)); });
        return r;
    });
}
function pf2Galeria() {
    var q = "mimeType='application/vnd.google-apps.folder' and name='" + PF2_DRIVE_FOLDER.replace(/'/g, "\\'") + "' and trashed=false";
    return pf2Drive('https://www.googleapis.com/drive/v3/files?q=' + encodeURIComponent(q) + '&fields=files(id)&spaces=drive')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.files || !d.files.length) return [];
            var q2 = "'" + d.files[0].id + "' in parents and trashed=false and mimeType contains 'image/'";
            return pf2Drive('https://www.googleapis.com/drive/v3/files?q=' + encodeURIComponent(q2) +
                '&fields=files(id,name,mimeType,thumbnailLink)&orderBy=modifiedTime desc&pageSize=200')
                .then(function(r) { return r.json(); })
                .then(function(x) { return (x.files || []).filter(function(f) { return !/\.(psd|kra|ora|csp)$/i.test(f.name); }); });
        });
}
function pf2DriveMini(f, ph) {
    var poner = function(url) {
        var img = new Image();
        img.alt = ''; img.referrerPolicy = 'no-referrer'; img.src = url;
        if (ph.parentNode) ph.parentNode.replaceChild(img, ph);
    };
    var blob = function() {
        pf2Drive('https://www.googleapis.com/drive/v3/files/' + f.id + '?alt=media').then(function(r) { return r.blob(); })
            .then(function(b) { poner(URL.createObjectURL(b)); }).catch(function() {});
    };
    if (!f.thumbnailLink) { blob(); return; }
    var p = new Image();
    p.referrerPolicy = 'no-referrer';
    p.onload = function() { poner(f.thumbnailLink); };
    p.onerror = blob;
    p.src = f.thumbnailLink;
}
function pf2DrivePublico(id) {
    return pf2Drive('https://www.googleapis.com/drive/v3/files/' + id + '/permissions', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role: 'reader', type: 'anyone' })
    }).catch(function(e) {
        if (e && e.auth) throw new Error('Tu conexión con Google Drive ha caducado: vuelve a abrir Capturas.');
        if (!/duplicate|alreadyExists|exists/i.test((e && e.message) || '')) throw e;
    });
}

var PF2_PICK = {
    /* Juegos, películas, series, libros: del catálogo de todos; los
       elegidos salen primero, numerados por orden. "＋ Crear …" abre el
       diálogo de añadir a la lista y lo deja elegido. */
    fav: function(sc, cat) {
        var noun = PF2_FAV_NOUN[cat], fem = noun[2], max = pf2MediaMax(cat);
        var sel = (sc.config.titles || []).slice(0, max), res = [];
        var v = pf2Ventana(PF2_MEDIA[cat].label);
        v.body.innerHTML = '<div class="pf2m-buscar"><input type="search" placeholder="Buscar ' + noun[0] + '…"><button type="button" class="button" data-a="new">＋ Crear ' + noun[0] + '</button></div>' +
            '<div class="pf2m-pk-grid pf2m-hund"></div>';
        var input = v.body.querySelector('input'), grid = v.body.querySelector('.pf2m-pk-grid');
        var info = pf2Pie(v, function() {
            var prev = sc.data || [];
            sc.config = { cat: cat, titles: sel.slice() };
            sc.data = sel.map(function(t) {
                var rv = pf2OwnReview(cat, t);
                var own = pf2OwnList(cat).find(function(x) { return x.title.toLowerCase() === t.toLowerCase(); });
                var old = prev.find(function(x) { return x && x.title === t; });
                var d = { title: t, image: pf2CatImg(cat, t) || (own && own.image) || '', stars: rv ? rv.stars : null };
                if (old && old.steam !== undefined) d.steam = old.steam;
                return d;
            });
        });
        var pintar = function() {
            var titulos = sel.concat(res.map(function(g) { return g.title; }).filter(function(t) { return sel.indexOf(t) < 0; }));
            grid.innerHTML = titulos.length ? titulos.map(function(t) {
                return pf2Casilla('t', t, pf2CatImg(cat, t), t, sel.indexOf(t) + 1);
            }).join('') : '<div class="pf2m-vacio">No hay ' + noun[1] + ' con ese nombre. Puedes ' + (fem ? 'crearla' : 'crearlo') + '.</div>';
            grid.querySelectorAll('.pf2m-pk').forEach(function(el) {
                el.addEventListener('click', function() {
                    var t = el.dataset.t, k = sel.indexOf(t);
                    if (k >= 0) sel.splice(k, 1); else if (sel.length < max) sel.push(t);
                    pf2Marcar(grid, 't', sel);
                    info.textContent = sel.length + ' de ' + max + ' · toca para elegir; el número es el orden';
                });
            });
            info.textContent = sel.length + ' de ' + max + ' · toca para elegir; el número es el orden';
        };
        var timer = null;
        var buscar = function() {
            pf2Api('game-catalog&cat=' + cat + '&q=' + encodeURIComponent(input.value.trim())).then(function(d) {
                res = (d && d.games) || [];
                res.forEach(function(x) { pf2SetCatImg(cat, x.title, x.image); });
                pintar();
            });
        };
        input.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(buscar, 250); });
        v.body.querySelector('[data-a="new"]').addEventListener('click', function() {
            window.__pf2mAfterAdd = function(c, item) {
                if (c !== cat) return;
                pf2SetCatImg(cat, item.title, item.image);
                if (sel.indexOf(item.title) < 0 && sel.length < max) sel.push(item.title);
                buscar();
            };
            openAddItemDialog(cat);
        });
        sel.forEach(function(t) {
            var own = pf2OwnList(cat).find(function(x) { return x.title.toLowerCase() === t.toLowerCase(); });
            if (own && own.image && !pf2CatImg(cat, t)) pf2SetCatImg(cat, t, own.image);
        });
        (sc.data || []).forEach(function(d) { pf2SetCatImg(cat, d.title, d.image); });
        buscar();
    },
    /* Música favorita: de mi lista de música (hasta 3, 5 con mejoras). */
    music: function(sc) {
        var max = pf2MediaMax('music'), sel = (sc.config.ids || []).slice(0, max);
        var items = pf2OwnList('music').filter(function(it) { return /^\d+$/.test(String(it.id)); });
        var v = pf2Ventana('Música favorita');
        v.body.innerHTML = '<div class="pf2m-buscar"><input type="search" placeholder="Buscar…"></div><div class="pf2m-pk-grid pf2m-hund"></div>';
        var input = v.body.querySelector('input'), grid = v.body.querySelector('.pf2m-pk-grid');
        var info = pf2Pie(v, function() {
            sc.config = { cat: 'music', ids: sel.slice() };
            sc.data = sel.map(function(id) {
                var it = items.find(function(x) { return +x.id === id; });
                if (!it) return null;
                var prev = (sc.data || []).find(function(d) { return d && d.id === id; });
                return { id: id, title: it.title, artist: it.artist || '', type: it.type || 'song', ytId: it.ytId || '',
                         ytPlaylistId: it.ytPlaylistId || '', spotifyAlbumId: it.spotifyAlbumId || '',
                         cover: (prev && prev.cover) || (pf2EsMiniYt(it.image) ? '' : (it.image || '')) };
            }).filter(Boolean);
        });
        var pintar = function() {
            var q = input.value.trim().toLowerCase();
            var r = items.filter(function(it) { return !q || (it.title + ' ' + (it.artist || '')).toLowerCase().indexOf(q) >= 0; });
            grid.innerHTML = r.length ? r.map(function(it) {
                return pf2Casilla('id', it.id, pf2EsMiniYt(it.image) ? '' : it.image, it.title, sel.indexOf(+it.id) + 1, 'square');
            }).join('') : '<div class="pf2m-vacio">No tienes música en tu lista.</div>';
            grid.querySelectorAll('.pf2m-pk').forEach(function(el) {
                var ph = el.querySelector('.pf2m-pk-ph');
                if (ph) {
                    var it = items.find(function(x) { return String(x.id) === el.dataset.id; });
                    if (it) pf2BuscarPortada(it).then(function(url) { if (url && ph.parentNode) ph.outerHTML = '<img src="' + esc(url) + '" alt="">'; });
                }
                el.addEventListener('click', function() {
                    var id = +el.dataset.id, k = sel.indexOf(id);
                    if (k >= 0) sel.splice(k, 1); else if (sel.length < max) sel.push(id);
                    pf2Marcar(grid, 'id', sel, Number);
                    info.textContent = sel.length + ' de ' + max;
                });
            });
            info.textContent = sel.length + ' de ' + max;
        };
        input.addEventListener('input', pintar);
        pintar();
    },
    /* Reseña destacada: una de mis reseñas. */
    review: function(sc) {
        var cur = sc.config && sc.config.title ? sc.config.category + '|' + sc.config.title.toLowerCase() : '';
        var filas = [];
        PF2_MEDIA_CATS.forEach(function(cat) { pf2OwnList(cat).forEach(function(it) { if (it.review && it.review.stars != null) filas.push({ cat: cat, it: it }); }); });
        var v = pf2Ventana('Reseña destacada');
        v.body.innerHTML = '<div class="pf2m-buscar"><input type="search" placeholder="Buscar…"></div><div class="pf2m-pk-list pf2m-hund"></div>';
        var input = v.body.querySelector('input'), list = v.body.querySelector('.pf2m-pk-list');
        pf2Pie(v, function() {
            var x = filas.find(function(r) { return r.cat + '|' + r.it.title.toLowerCase() === cur; });
            if (!x) return;
            sc.config = { category: x.cat, title: x.it.title };
            sc.data = { category: x.cat, title: x.it.title, artist: x.it.artist || '', image: x.it.image || '', stars: x.it.review.stars, comment: x.it.review.comment || '' };
        });
        var pintar = function() {
            var q = input.value.trim().toLowerCase();
            var r = filas.filter(function(x) { return !q || x.it.title.toLowerCase().indexOf(q) >= 0; });
            list.innerHTML = r.length ? r.map(function(x) {
                var k = x.cat + '|' + x.it.title.toLowerCase();
                return '<div class="pf2m-pk-row' + (k === cur ? ' on' : '') + '" data-k="' + esc(k) + '">' +
                    (x.it.image ? '<img src="' + esc(pf2Ruta(x.it.image)) + '" alt="">' : '<span class="pf2m-pk-ri"></span>') +
                    '<span class="pf2m-pk-rt">' + esc(x.it.title) + ' <small>(' + PF2_CAT_LABEL[x.cat] + ')</small></span>' +
                    '<span class="pf2m-stars">' + pf2Stars(x.it.review.stars) + '</span></div>';
            }).join('') : '<div class="pf2m-vacio">No tienes reseñas todavía.</div>';
            list.querySelectorAll('.pf2m-pk-row').forEach(function(el) {
                el.addEventListener('click', function() {
                    cur = el.dataset.k;
                    list.querySelectorAll('.pf2m-pk-row').forEach(function(e) { e.classList.toggle('on', e === el); });
                });
            });
        };
        input.addEventListener('input', pintar);
        pintar();
    },
    /* Playlist destacada: una de mis playlists (propias o colaborativas). */
    playlist: function(sc) {
        var cur = sc.config.id || 0, todas = [];
        var v = pf2Ventana('Playlist destacada');
        v.body.innerHTML = '<div class="pf2m-buscar"><input type="search" placeholder="Buscar…"></div><div class="pf2m-pk-list pf2m-hund"><div class="pf2m-vacio">Cargando tus playlists…</div></div>';
        var input = v.body.querySelector('input'), list = v.body.querySelector('.pf2m-pk-list');
        pf2Pie(v, function() {
            var p = todas.find(function(x) { return x.id === cur; });
            if (!p) return;
            sc.config = { id: p.id };
            sc.data = p;
        });
        var pintar = function() {
            var q = input.value.trim().toLowerCase();
            var r = todas.filter(function(p) { return !q || p.name.toLowerCase().indexOf(q) >= 0; });
            list.innerHTML = r.length ? r.map(function(p) {
                return '<div class="pf2m-pk-row' + (p.id === cur ? ' on' : '') + '" data-id="' + p.id + '">' +
                    (p.image ? '<img src="' + esc(pf2Ruta(p.image)) + '" alt="">' : '<span class="pf2m-pk-ri"></span>') +
                    '<span class="pf2m-pk-rt">' + esc(p.name) + (p.owner ? ' <small>(de ' + esc(p.owner) + ')</small>' : '') + '</span>' +
                    '<small>' + p.count + (p.count === 1 ? ' canción' : ' canciones') + '</small></div>';
            }).join('') : '<div class="pf2m-vacio">' + (todas.length ? 'Ninguna con ese nombre.' : 'No tienes playlists todavía.') + '</div>';
            list.querySelectorAll('.pf2m-pk-row').forEach(function(el) {
                el.addEventListener('click', function() {
                    cur = +el.dataset.id;
                    list.querySelectorAll('.pf2m-pk-row').forEach(function(e) { e.classList.toggle('on', e === el); });
                });
            });
        };
        input.addEventListener('input', pintar);
        pf2Api('my-playlists').then(function(d) { todas = (d && d.playlists) || []; pintar(); });
    },
    /* Texto libre. */
    text: function(sc) {
        var v = pf2Ventana('Texto libre');
        v.body.innerHTML = '<textarea class="pf2m-ta" rows="9" maxlength="' + PF2_MAX.text + '"></textarea>';
        var ta = v.body.querySelector('textarea');
        ta.value = sc.config.text || '';
        var info = pf2Pie(v, function() {
            var t = ta.value.replace(/\r\n?/g, '\n').trim().slice(0, PF2_MAX.text);
            sc.config = { text: t };
            sc.data = { text: t };
        });
        var cuenta = function() { info.textContent = ta.value.length + ' / ' + PF2_MAX.text; };
        ta.addEventListener('input', cuenta);
        cuenta();
    },
    /* Juego destacado: un juego del catálogo y, con Steam, hasta 8 de sus
       logros conseguidos. */
    game_feat: function(sc) {
        var cur = sc.config.title || '', ach = (sc.config.ach || []).slice();
        var st = (pf2v() || {}).steam || {};
        var steamOn = !!(st.configured && st.linked && !st['private']);
        var match = null, achList = [], res = [];
        var v = pf2Ventana('Juego destacado');
        v.body.innerHTML = '<div class="pf2m-buscar"><input type="search" placeholder="Buscar juego…"><button type="button" class="button" data-a="new">＋ Crear juego</button></div>' +
            '<div class="pf2m-pk-grid pf2m-hund pf2m-pk-corta"></div><div class="pf2m-pk-h"></div>' +
            '<div class="pf2m-pk-list pf2m-hund" data-r="ach"></div>';
        var input = v.body.querySelector('input'), grid = v.body.querySelector('.pf2m-pk-grid');
        var achH = v.body.querySelector('.pf2m-pk-h'), achL = v.body.querySelector('[data-r="ach"]');
        var info = pf2Pie(v, function() {
            if (!cur) return;
            var rv = pf2OwnReview('games', cur);
            var own = pf2OwnList('games').find(function(x) { return x.title.toLowerCase() === cur.toLowerCase(); });
            var inSteam = !!(match && match.inSteam);
            sc.config = { title: cur, ach: inSteam ? ach.slice() : [] };
            sc.data = { title: cur, image: pf2CatImg('games', cur) || (own && own.image) || '', stars: rv ? rv.stars : null, comment: (rv && rv.comment) || '',
                steam: !steamOn ? null : !inSteam ? { inSteam: false } : { inSteam: true, appid: match.appid, hours: match.hours, achieved: match.achieved, total: match.total,
                    items: ach.map(function(a) { return achList.find(function(x) { return x.apiname === a; }); }).filter(Boolean) } };
        });
        var pintarRes = function() {
            var titulos = res.map(function(g) { return g.title; });
            if (cur && titulos.indexOf(cur) < 0) titulos.unshift(cur);
            grid.innerHTML = titulos.length ? titulos.map(function(t) { return pf2Casilla('t', t, pf2CatImg('games', t), t, t === cur ? 1 : 0); }).join('')
                : '<div class="pf2m-vacio">No hay juegos con ese nombre. Puedes crearlo.</div>';
            grid.querySelectorAll('.pf2m-pk').forEach(function(el) {
                el.addEventListener('click', function() {
                    if (el.dataset.t === cur) return;
                    cur = el.dataset.t; ach = [];
                    pf2Marcar(grid, 't', [cur]);
                    cargarSteam();
                });
            });
        };
        var pintarAch = function() {
            achL.innerHTML = achList.length ? achList.map(function(a) {
                var n = ach.indexOf(a.apiname) + 1;
                return '<div class="pf2m-pk-row' + (n ? ' on' : '') + '" data-api="' + esc(a.apiname) + '"><img src="' + esc(a.icon) + '" alt="">' +
                    '<span class="pf2m-pk-rt"><b>' + esc(a.name) + '</b><br><small>' + esc(a.desc || '') + '</small></span>' +
                    (a.percent != null ? '<small>' + String(a.percent).replace('.', ',') + ' %</small>' : '') + '</div>';
            }).join('') : '<div class="pf2m-vacio">' + (match && match.total ? 'Aún no tienes logros de este juego.' : 'Este juego no tiene logros.') + '</div>';
            achL.querySelectorAll('.pf2m-pk-row').forEach(function(el) {
                el.addEventListener('click', function() {
                    var k = ach.indexOf(el.dataset.api);
                    if (k >= 0) ach.splice(k, 1); else if (ach.length < PF2_MAX.game_feat) ach.push(el.dataset.api);
                    el.classList.toggle('on', ach.indexOf(el.dataset.api) >= 0);
                    info.textContent = ach.length + ' de ' + PF2_MAX.game_feat + ' logros';
                });
            });
            info.textContent = match && match.inSteam ? ach.length + ' de ' + PF2_MAX.game_feat + ' logros' : '';
        };
        var cargarSteam = function() {
            match = null; achList = []; achL.style.display = 'none'; info.textContent = '';
            if (!cur) { achH.textContent = ''; return; }
            if (!steamOn) { achH.textContent = pf2TextoVacio('steam_ach') + ' Sin Steam se enseñan la portada y tu reseña.'; return; }
            var t = cur;
            achH.textContent = 'Buscando el juego en tu Steam…';
            pf2Api('steam-game-match&title=' + encodeURIComponent(t)).then(function(d) {
                if (t !== cur) return;
                match = d && d.steam;
                if (!match || !match.inSteam) { achH.textContent = 'Este juego no está en tu Steam: se enseñan la portada y tu reseña.'; return; }
                achH.textContent = 'Logros que se enseñan (máx. ' + PF2_MAX.game_feat + ')' +
                    (match.hours != null ? ' · ' + match.hours.toLocaleString('es') + ' h jugadas' : match.owned === false ? ' · no lo tienes' : '') + ':';
                achL.style.display = '';
                achL.innerHTML = '<div class="pf2m-vacio">Cargando logros…</div>';
                return pf2Api('steam-game-achievements&appid=' + match.appid).then(function(r) {
                    if (t !== cur) return;
                    achList = (r && r.achievements) || [];
                    ach = ach.filter(function(x) { return achList.some(function(a) { return a.apiname === x; }); });
                    pintarAch();
                });
            });
        };
        var timer = null;
        var buscar = function() {
            pf2Api('game-catalog&q=' + encodeURIComponent(input.value.trim())).then(function(d) {
                res = (d && d.games) || [];
                res.forEach(function(x) { pf2SetCatImg('games', x.title, x.image); });
                pintarRes();
            });
        };
        input.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(buscar, 250); });
        v.body.querySelector('[data-a="new"]').addEventListener('click', function() {
            window.__pf2mAfterAdd = function(c, item) {
                if (c !== 'games') return;
                pf2SetCatImg('games', item.title, item.image);
                cur = item.title; ach = [];
                buscar(); cargarSteam();
            };
            openAddItemDialog('games');
        });
        if (sc.data && sc.data.image) pf2SetCatImg('games', sc.data.title, sc.data.image);
        buscar();
        cargarSteam();
    },
    /* Logros de Steam a elegir: primero el juego, debajo sus logros. */
    steam_ach: function(sc) {
        var st = (pf2v() || {}).steam || {};
        var v = pf2Ventana('Logros de Steam');
        if (!st.linked) { v.body.innerHTML = '<div class="pf2m-vacio">' + esc(pf2TextoVacio('steam_ach')) + '</div>'; pf2Pie(v, function() {}); return; }
        var max = pf2AchMax('steam_ach');
        var sel = ((sc.data && sc.data.items) || []).slice(0, max), juegos = [], curJ = null;
        /* Lo buscado en la tienda de Steam (como en el PC, perfil-v2.js). */
        var buscados = {}, buscaT = null;
        var clave = function(a) { return a.appid + '|' + a.apiname; };
        v.body.innerHTML = '<div class="pf2m-pk-h">Elegidos (máx. ' + max + '):</div><div class="pf2m-ach-sel pf2m-hund"></div>' +
            '<div class="pf2m-buscar"><input type="search" placeholder="Buscar juego…"></div>' +
            '<div class="pf2m-pk-list pf2m-hund pf2m-pk-corta" data-r="games"></div><div class="pf2m-pk-list pf2m-hund" data-r="ach"><div class="pf2m-vacio">Elige un juego.</div></div>';
        var selL = v.body.querySelector('.pf2m-ach-sel'), input = v.body.querySelector('input');
        var gamesL = v.body.querySelector('[data-r="games"]'), achL = v.body.querySelector('[data-r="ach"]');
        var info = pf2Pie(v, function() {
            sc.config = { items: sel.map(function(a) { return { appid: a.appid, apiname: a.apiname, game: a.game || '' }; }) };
            sc.data = { items: sel.slice(), totals: { games: st.games || 0, hours: st.hours || 0 } };
        });
        var pintarSel = function() {
            selL.innerHTML = sel.length ? sel.map(function(a, i) {
                return '<span class="pf2m-ach-it" data-k="' + esc(clave(a)) + '"><img src="' + esc(a.icon) + '" alt=""><i>' + (i + 1) + '</i></span>';
            }).join('') : '<div class="pf2m-vacio">Ninguno todavía.</div>';
            selL.querySelectorAll('[data-k]').forEach(function(el) {
                el.addEventListener('click', function() { sel = sel.filter(function(x) { return clave(x) !== el.dataset.k; }); pintarSel(); marcarAch(); });
            });
            info.textContent = sel.length + ' de ' + max;
        };
        var marcarAch = function() {
            if (!curJ) return;
            achL.querySelectorAll('.pf2m-pk-row').forEach(function(e) {
                e.classList.toggle('on', sel.some(function(x) { return x.appid === curJ.appid && x.apiname === e.dataset.api; }));
            });
        };
        /* Mezclados: los que no tiene (familia, devueltos) llevan "no lo
           tienes" en vez de las horas; lo que no está en la lista se busca
           también en la tienda de Steam. */
        var pintarJuegos = function() {
            var q = input.value.trim().toLowerCase();
            var r = juegos.filter(function(g) { return !q || g.name.toLowerCase().indexOf(q) >= 0; });
            var extra = q.length >= 2 && buscados[q] && buscados[q] !== 'buscando'
                ? buscados[q].filter(function(g) { return !juegos.some(function(x) { return x.appid === g.appid; }); }) : [];
            r = r.concat(extra).slice(0, 300);
            gamesL.innerHTML = r.map(function(g) {
                return '<div class="pf2m-pk-row' + (curJ && curJ.appid === g.appid ? ' on' : '') + '" data-app="' + g.appid + '"><span class="pf2m-pk-rt">' + esc(g.name) + '</span><small>' +
                    (g.owned === false ? 'no lo tienes' : g.hours + ' h') + '</small></div>';
            }).join('') + (q.length >= 2 && buscados[q] === 'buscando' ? '<div class="pf2m-vacio">Buscando en Steam…</div>' : '') ||
                '<div class="pf2m-vacio">Sin juegos.</div>';
            gamesL.querySelectorAll('.pf2m-pk-row').forEach(function(el) {
                el.addEventListener('click', function() {
                    curJ = r.find(function(g) { return g.appid === +el.dataset.app; });
                    gamesL.querySelectorAll('.pf2m-pk-row').forEach(function(e) { e.classList.toggle('on', +e.dataset.app === curJ.appid); });
                    verJuego(curJ);
                });
            });
        };
        var verJuego = function(g) {
            achL.innerHTML = '<div class="pf2m-vacio">Cargando logros…</div>';
            pf2Api('steam-game-achievements&appid=' + g.appid).then(function(d) {
                if (!curJ || curJ.appid !== g.appid) return;
                var list = (d && d.achievements) || [];
                if (!list.length) { achL.innerHTML = '<div class="pf2m-vacio">' + ((d && d.total) ? 'Aún no tienes logros de este juego.' : 'Este juego no tiene logros.') + '</div>'; return; }
                achL.innerHTML = list.map(function(a) {
                    var on = sel.some(function(x) { return x.appid === g.appid && x.apiname === a.apiname; });
                    return '<div class="pf2m-pk-row' + (on ? ' on' : '') + '" data-api="' + esc(a.apiname) + '"><img src="' + esc(a.icon) + '" alt="">' +
                        '<span class="pf2m-pk-rt"><b>' + esc(a.name) + '</b><br><small>' + esc(a.desc || '') + '</small></span>' +
                        (a.percent != null ? '<small>' + String(a.percent).replace('.', ',') + ' %</small>' : '') + '</div>';
                }).join('');
                achL.querySelectorAll('.pf2m-pk-row').forEach(function(el) {
                    el.addEventListener('click', function() {
                        var a = list.find(function(x) { return x.apiname === el.dataset.api; });
                        var k = g.appid + '|' + a.apiname, at = sel.findIndex(function(x) { return clave(x) === k; });
                        if (at >= 0) sel.splice(at, 1); else if (sel.length < max) sel.push(Object.assign({}, a, { appid: g.appid, game: g.name }));
                        pintarSel(); marcarAch();
                    });
                });
            });
        };
        var buscarSteam = function() {
            var q = input.value.trim().toLowerCase();
            if (q.length < 2 || buscados[q]) return;
            buscados[q] = 'buscando'; pintarJuegos();
            pf2Api('steam-search-games&q=' + encodeURIComponent(q)).then(function(d) {
                buscados[q] = (d && d.games) || [];
                if (input.value.trim().toLowerCase() === q) pintarJuegos();
            });
        };
        input.addEventListener('input', function() {
            pintarJuegos();
            clearTimeout(buscaT);
            buscaT = setTimeout(buscarSteam, 500);
        });
        pintarSel();
        gamesL.innerHTML = '<div class="pf2m-vacio">Cargando tu biblioteca…</div>';
        pf2Api('steam-games').then(function(d) {
            if (!d || !d.ok) { gamesL.innerHTML = '<div class="pf2m-vacio"></div>'; gamesL.firstChild.textContent = (d && d.error) || 'No se pudo leer tu Steam'; return; }
            juegos = d.games; pintarJuegos();
        });
    },
    /* Capturas: de la Galería (Google Drive), hasta 3, con pie y juego. */
    shots: function(sc) {
        var v = pf2Ventana('Capturas');
        v.body.innerHTML = '<div class="pf2m-pk-h"></div><div class="pf2m-pk-grid pf2m-hund pf2m-pk-wide"></div><div class="pf2m-shot-meta"></div>';
        var msg = v.body.querySelector('.pf2m-pk-h'), grid = v.body.querySelector('.pf2m-pk-grid'), metaBox = v.body.querySelector('.pf2m-shot-meta');
        var files = [], sel = [], meta = {};
        var info = pf2Pie(v, function() {
            var ok = v.body.querySelector('[data-a="ok"]');
            ok.disabled = true; ok.textContent = 'Guardando…';
            Promise.all(sel.map(pf2DrivePublico)).then(function() {
                return pf2Api('gallery-screenshots', { items: sel.map(function(id) { var m = meta[id] || {}; return { driveId: id, caption: m.caption || '', game: m.game || '' }; }) });
            }).then(function(d) {
                if (!d || !d.ok) throw new Error((d && d.error) || 'No se pudo guardar');
                sc.config = { ids: d.shots.map(function(x) { return x.id; }) };
                sc.data = d.shots;
                PF2E.myShots = null;
                v.close();
                renderPf2Expositores();
            }).catch(function(e) { ok.disabled = false; ok.textContent = 'Aceptar'; pfAlert((e && e.message) || 'No se pudo guardar'); });
            return false;   /* se cierra cuando acaba */
        });
        var ok = v.body.querySelector('[data-a="ok"]');
        var marcar = function() {
            pf2Marcar(grid, 'id', sel);
            metaBox.innerHTML = sel.map(function(id, i) {
                var m = meta[id] || { caption: '', game: '' };
                return '<div class="pf2m-shot-row"><b>' + (i + 1) + '</b>' +
                    '<input type="text" data-id="' + esc(id) + '" data-f="caption" maxlength="200" placeholder="Pie de foto (opcional)" value="' + esc(m.caption) + '">' +
                    '<input type="text" data-id="' + esc(id) + '" data-f="game" maxlength="200" placeholder="Juego (opcional)" value="' + esc(m.game) + '"></div>';
            }).join('');
            metaBox.querySelectorAll('input').forEach(function(inp) {
                inp.addEventListener('input', function() { var m = meta[inp.dataset.id] || (meta[inp.dataset.id] = { caption: '', game: '' }); m[inp.dataset.f] = inp.value; });
            });
            info.textContent = sel.length + ' de ' + PF2_MAX.shots;
        };
        var pintar = function() {
            grid.innerHTML = files.map(function(f) { return pf2Casilla('id', f.id, '', f.name, sel.indexOf(f.id) + 1, 'wide'); }).join('');
            grid.querySelectorAll('.pf2m-pk').forEach(function(el) {
                var f = files.find(function(x) { return x.id === el.dataset.id; });
                pf2DriveMini(f, el.querySelector('.pf2m-pk-ph'));
                el.addEventListener('click', function() {
                    var k = sel.indexOf(f.id);
                    if (k >= 0) sel.splice(k, 1); else if (sel.length < PF2_MAX.shots) sel.push(f.id);
                    marcar();
                });
            });
            marcar();
        };
        var pedirConexion = function() {
            msg.innerHTML = 'Para elegir capturas de tu Galería tienes que conectar tu Google Drive (el mismo que usa la Galería). ' +
                '<button type="button" class="button" data-a="drive">Conectar Google Drive</button>';
            grid.innerHTML = ''; metaBox.innerHTML = ''; ok.disabled = true;
            msg.querySelector('[data-a="drive"]').addEventListener('click', function() {
                pf2DriveConectar(function(err) {
                    if (err) { msg.textContent = 'No se pudo conectar: ' + err.message; return; }
                    ok.disabled = false;
                    empezar();
                });
            });
        };
        var empezar = function() {
            if (!pf2DriveToken()) { pedirConexion(); return; }
            msg.textContent = 'Cargando tu Galería…';
            pf2Galeria().then(function(list) {
                files = list;
                msg.textContent = files.length ? 'Elige hasta ' + PF2_MAX.shots + ' imágenes de tu Galería (la primera sale grande):' : 'Tu Galería está vacía: sube imágenes desde la app Galería.';
                pintar();
            }).catch(function(e) {
                if (e && e.auth) { pedirConexion(); return; }
                msg.textContent = 'No se pudo leer tu Galería: ' + ((e && e.message) || 'error');
            });
        };
        var mias = PF2E.myShots ? Promise.resolve(PF2E.myShots) : pf2Api('my-screenshots').then(function(d) { return (PF2E.myShots = (d && d.shots) || []); });
        mias.then(function(shots) {
            shots.forEach(function(x) { if (x.driveId) meta[x.driveId] = { caption: x.caption || '', game: x.game || '' }; });
            (sc.config.ids || []).forEach(function(id) { var x = shots.find(function(y) { return y.id === id; }); if (x && x.driveId) sel.push(x.driveId); });
            empezar();
        });
    }
};

/* Comentarios del muro (wall-comment / delete-wall-comment, como el PC). */
function renderPf2Comentarios() {
    var box = document.getElementById('pf2m-com');
    var v2 = pf2v();
    if (!box || !v2) return;
    var list = v2.comments || [];
    box.innerHTML = '<div class="title-bar"><div class="title-bar-text"><img src="../../assets/img/appIcons/chatIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;">' +
        'Comentarios<span>' + list.length + '</span></div></div><div class="window-body">' +
        '<div class="pf2m-new"><input type="text" maxlength="500" placeholder="Escribe un comentario…"><button type="button" class="button">Publicar</button></div>' +
        '<div class="pf2m-com-list"></div></div>';
    var input = box.querySelector('input'), btn = box.querySelector('.pf2m-new .button');
    var enviar = function() {
        var text = input.value.trim();
        if (!text || btn.disabled) return;
        btn.disabled = true;
        fetch(API + '?action=wall-comment', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user: pf2ProfileKey(), text: text })
        }).then(function(r) { return r.json(); }).then(function(d) {
            btn.disabled = false;
            if (d && d.ok) { v2.comments = d.comments; renderPf2Comentarios(); }
            else pfAlert((d && d.error) || 'No se pudo publicar');
        }).catch(function() { btn.disabled = false; pfAlert('Error de conexión'); });
    };
    btn.addEventListener('click', enviar);
    input.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); enviar(); } });
    var ul = box.querySelector('.pf2m-com-list');
    if (!list.length) { ul.innerHTML = '<div class="pf2m-vacio">Aún no hay comentarios.</div>'; return; }
    list.forEach(function(cm) {
        var d = document.createElement('div');
        d.className = 'pf2m-com';
        d.innerHTML = '<span class="pf2m-com-av" data-marco-user="' + esc(cm.authorKey) + '">' +
                (cm.avatar ? '<img src="' + esc(pf2Ruta(cm.avatar)) + '" alt="">' : '') + '</span>' +
            '<div class="pf2m-com-main"><span class="pf2m-com-who">' + esc(cm.author) + '</span><span class="pf2m-com-when">' + esc(relTime(cm.ts)) + '</span>' +
                (cm.text ? '<div class="pf2m-com-txt">' + esc(cm.text) + '</div>' : '') +
                (cm.imageUrl ? '<div class="pf2m-com-img"><img src="' + esc(cm.imageUrl) + '" alt="" loading="lazy"></div>' : '') +
            '</div>' + (cm.canDelete ? '<button type="button" class="button pf2m-del" title="Borrar comentario">✕</button>' : '');
        var abrir = function() { if (cm.authorKey !== USER_KEY) viewOtherUser(cm.authorKey); };
        d.querySelector('.pf2m-com-who').addEventListener('click', abrir);
        d.querySelector('.pf2m-com-av').addEventListener('click', abrir);
        var del = d.querySelector('.pf2m-del');
        if (del) del.addEventListener('click', function() {
            pfConfirm('¿Borrar este comentario?', 'Borrar', function() {
                fetch(API + '?action=delete-wall-comment', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: cm.id })
                }).then(function(r) { return r.json(); }).then(function(r) {
                    if (r && r.ok) { v2.comments = r.comments; renderPf2Comentarios(); }
                    else pfAlert((r && r.error) || 'No se pudo borrar');
                }).catch(function() { pfAlert('Error de conexión'); });
            });
        });
        ul.appendChild(d);
    });
}

/* 🔗 Enlace del perfil que se está viendo (el mío si no). */
document.getElementById('pf-link-btn').addEventListener('click', function(){
    if (window.melonCopyLink) window.melonCopyLink('perfil', STATE.viewingUser || USER_KEY);
});

/* Botón de amistad de la cabecera (solo viendo otro perfil), como en el
   PC: + Agregar amigo / Solicitud enviada (cancela) / Aceptar solicitud /
   ✓ Amigos (eliminar, con confirmación). */
var amistadVista = 'ninguna';
document.getElementById('pf-follow-btn').addEventListener('click', function(){
    var uk = STATE.viewingUser;
    if (!uk) return;
    var btn = this;
    if (amistadVista === 'amigos') {
        pfConfirm('¿Eliminar a ' + (STATE.viewingLabel || amUser(uk).label) + ' de tus amigos?', 'Eliminar', function(){ amigosAccion('friend-remove', uk); });
        return;
    }
    var act = { ninguna: 'friend-request', enviada: 'friend-cancel', recibida: 'friend-accept' }[amistadVista] || 'friend-request';
    btn.disabled = true;
    amigosAccion(act, uk).then(function(){ btn.disabled = false; }, function(){ btn.disabled = false; });
});
function updateFollowBtn(estado) {
    if (estado === true) estado = 'amigos';
    if (!estado) estado = 'ninguna';
    amistadVista = estado;
    var btn = document.getElementById('pf-follow-btn');
    btn.textContent = { ninguna: '+ Agregar amigo', enviada: 'Solicitud enviada', recibida: 'Aceptar solicitud', amigos: '✓ Amigos' }[estado] || '+ Agregar amigo';
}

/* Helper alert para JS sin window.alert. */
function pfAlert(msg) {
    pfConfirm(msg, 'OK', function(){});  /* reuso del confirm como info simple */
}

/* ─── Amigos (estilo Steam; assets/profile/amigos.php) ─────────────
   Decidido así (7/11): "Social" del menú pasa a ser "Amigos", como la
   página del PC: pestañas Tus amigos (filas con foto, nombre y estado;
   "En línea" arriba y "Desconectados" por última conexión, con filtro) ·
   Agregar amigo (buscador) · Solicitudes (recibidas y enviadas, con
   "(N)" en texto normal: sin contadores rojos). Tocar = su perfil;
   mantener pulsado un amigo = Enviar mensaje / Ver perfil / Eliminar
   amigo. Los avisos de solicitudes salen en la carcasa (mobile.php,
   mNotifSystem); la página se pone al día con el timbre ('amigos'). */
var AMIGOS = { amigos: [], recibidas: [], enviadas: [], tab: 'amigos', cargado: false, busqueda: '' };
var __pfLastNp = {}, __pfLastSeen = {};

function amPost(action, user) {
    return fetch(API + '?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user: user })
    }).then(function(r){ return r.json(); });
}
function amigosCargar() {
    return fetch(API + '?action=friends', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d && d.ok) amigosAplicar(d); })
        .catch(function(){});
}
function amigosAplicar(d) {
    AMIGOS.amigos = d.amigos || []; AMIGOS.recibidas = d.recibidas || []; AMIGOS.enviadas = d.enviadas || [];
    AMIGOS.cargado = true;
    /* Amigos = mutuos: lo que miran isMutual (botón Chat) y compañía. */
    STATE.myFollowing = AMIGOS.amigos.slice();
    STATE.myFollowers = AMIGOS.amigos.slice();
    if (STATE.view === 'social') renderSocial();
    if (STATE.viewingUser) toggleProfileActions();
}
/* Tras cualquier acción: la página y, si se está viendo a esa persona,
   el botón de su cabecera. */
function amigosAccion(action, uk) {
    return amPost(action, uk).then(function(d){
        if (!d || !d.ok) { pfAlert((d && d.error) || 'No se ha podido'); return d; }
        amigosAplicar(d);
        if (STATE.viewingUser === uk) updateFollowBtn(d.amistad);
        return d;
    }).catch(function(){ pfAlert('Error de conexión'); });
}

function amUser(k) { return PROFILE_USERS[k] || { label: k, image: '' }; }
function amAvatar(k) {
    var u = amUser(k);
    var dentro = u.image ? '<img src="' + esc(u.image) + '" alt="">'
        : '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:18px;height:18px;object-fit:contain;image-rendering:pixelated;">';
    return '<div class="pfa-av has-presence-dot" data-marco-user="' + esc(k) + '">' + dentro +
           '<span class="pf-presence-dot" data-userkey="' + esc(k) + '"></span></div>';
}
function amHace(ts) {
    if (!ts) return '';
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 3600) return 'hace ' + Math.max(1, Math.round(s / 60)) + ' min';
    if (s < 86400) return 'hace ' + Math.round(s / 3600) + ' h';
    var d = Math.round(s / 86400);
    return d === 1 ? 'hace 1 día' : 'hace ' + d + ' días';
}
/* Como en el PC: escuchando > no molestar > ausente > en línea; si no,
   desconectado (hace X). */
function amEstado(k) {
    if (__pfLastOnline[k]) {
        var np = __pfLastNp[k];
        if (np && np.title) return { cls: 'np', txt: 'Escuchando: ' + np.title + (np.artist ? ' — ' + np.artist : '') };
        if (__pfLastDnd[k])  return { cls: 'dnd',  txt: 'No molestar' };
        if (__pfLastAway[k]) return { cls: 'away', txt: 'Ausente' };
        return { cls: 'on', txt: 'En línea' };
    }
    var ts = __pfLastSeen[k] || 0;
    return { cls: 'off', txt: 'Desconectado' + (ts ? ' · ' + amHace(ts) : '') };
}
function amNombre(a, b) {
    var la = (amUser(a).label || '').toLowerCase(), lb = (amUser(b).label || '').toLowerCase();
    return la < lb ? -1 : la > lb ? 1 : 0;
}
function amBoton(txt, act, deshab) {
    return '<button class="button" type="button" data-act="' + act + '"' + (deshab ? ' disabled' : '') + '>' + txt + '</button>';
}
function amBotonesPara(estado) {
    if (estado === 'amigos')   return amBoton('Ya sois amigos', '', true);
    if (estado === 'enviada')  return amBoton('Solicitud enviada', '', true) + amBoton('Cancelar', 'friend-cancel');
    if (estado === 'recibida') return amBoton('Aceptar', 'friend-accept') + amBoton('Rechazar', 'friend-reject');
    return amBoton('Agregar amigo', 'friend-request');
}
function amFila(k, botones, extra) {
    return '<div class="pfa-fila" data-user="' + esc(k) + '">' + amAvatar(k) +
        '<div class="pfa-txt"><div class="pfa-nom">' + esc(amUser(k).label) + '</div>' + (extra ? '<div class="pfa-est">' + esc(extra) + '</div>' : '') + '</div>' +
        '<div class="pfa-btns">' + botones + '</div></div>';
}

function amPintarLista() {
    var box = document.getElementById('pfa-lista');
    if (!box) return;
    document.getElementById('pfa-n-amigos').textContent = AMIGOS.cargado ? '(' + AMIGOS.amigos.length + ')' : '';
    if (!AMIGOS.cargado) return;
    if (!AMIGOS.amigos.length) {
        box.innerHTML = '<div class="pfa-vacio">Todavía no tienes amigos. Búscalos en "Agregar amigo".</div>';
        return;
    }
    var f = (document.getElementById('pfa-filtro').value || '').trim().toLowerCase();
    var lista = AMIGOS.amigos.filter(function(k){ return !f || (amUser(k).label || '').toLowerCase().indexOf(f) !== -1; });
    var on = lista.filter(function(k){ return __pfLastOnline[k]; }).sort(amNombre);
    var off = lista.filter(function(k){ return !__pfLastOnline[k]; }).sort(function(a, b){
        return (__pfLastSeen[b] || 0) - (__pfLastSeen[a] || 0) || amNombre(a, b);
    });
    var grupo = function(tit, keys) {
        if (!keys.length) return '';
        return '<div class="pfa-grupo">' + tit + ' — ' + keys.length + '</div>' + keys.map(function(k){
            var e = amEstado(k);
            return '<div class="pfa-fila pfa-amigo pfa-' + e.cls + '" data-user="' + esc(k) + '">' + amAvatar(k) +
                '<div class="pfa-txt"><div class="pfa-nom">' + esc(amUser(k).label) + '</div>' +
                '<div class="pfa-est">' + esc(e.txt) + '</div></div></div>';
        }).join('');
    };
    box.innerHTML = grupo('En línea', on) + grupo('Desconectados', off) +
        (!on.length && !off.length ? '<div class="pfa-vacio">Ningún amigo se llama así.</div>' : '');
    applyPresence();
}
function amPintarSolicitudes() {
    var r = document.getElementById('pfa-recibidas'), e = document.getElementById('pfa-enviadas');
    if (!r) return;
    document.getElementById('pfa-n-sol').textContent = AMIGOS.recibidas.length ? '(' + AMIGOS.recibidas.length + ')' : '';
    if (!AMIGOS.cargado) return;
    r.innerHTML = AMIGOS.recibidas.length
        ? AMIGOS.recibidas.map(function(k){ return amFila(k, amBotonesPara('recibida'), 'Quiere ser tu amigo'); }).join('')
        : '<div class="pfa-vacio">No tienes solicitudes nuevas.</div>';
    e.innerHTML = AMIGOS.enviadas.length
        ? AMIGOS.enviadas.map(function(k){ return amFila(k, amBotonesPara('enviada'), 'Esperando respuesta'); }).join('')
        : '<div class="pfa-vacio">No has enviado ninguna.</div>';
    applyPresence();
}
function amBuscar() {
    var q = (document.getElementById('pfa-q').value || '').trim();
    var box = document.getElementById('pfa-resultados');
    AMIGOS.busqueda = q;
    if (!q) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="pfa-vacio">Buscando…</div>';
    fetch(API + '?action=friend-search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (q !== AMIGOS.busqueda) return;
            var res = (d && d.resultados) || [];
            box.innerHTML = res.length
                ? res.map(function(x){
                    if (!PROFILE_USERS[x.key]) PROFILE_USERS[x.key] = { label: x.nombre, image: '' };
                    return amFila(x.key, amBotonesPara(x.estado));
                }).join('')
                : '<div class="pfa-vacio">No hay ningún perfil con ese nombre.</div>';
            applyPresence();
        })
        .catch(function(){ box.innerHTML = '<div class="pfa-vacio">Sin conexión, prueba otra vez.</div>'; });
}
function amTab(t) {
    AMIGOS.tab = t;
    document.querySelectorAll('#pf-view-social [data-amtab]').forEach(function(b){ b.classList.toggle('active', b.dataset.amtab === t); });
    document.querySelectorAll('#pf-view-social [data-ampanel]').forEach(function(p){ p.hidden = p.dataset.ampanel !== t; });
}
/* Nombre de antes (lo llaman showView y amigosAplicar). */
function renderSocial() {
    amPintarLista();
    amPintarSolicitudes();
}

/* Mantener pulsado un amigo: Enviar mensaje / Ver perfil / Eliminar. */
function amMenu(k) {
    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.innerHTML =
        '<div class="window pf-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text">' + esc(amUser(k).label) + '</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div>' +
            '</div>' +
            '<div class="window-body">' +
                '<div class="pfa-menu-item" data-m="chat">Enviar mensaje</div>' +
                '<div class="pfa-menu-item" data-m="perfil">Ver perfil</div>' +
                '<div class="pfa-menu-item peligro" data-m="eliminar">Eliminar amigo</div>' +
                '<div class="modal-actions"><button class="button" data-act="close" type="button">Cerrar</button></div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);
    function close(){ if (bd.parentNode) bd.parentNode.removeChild(bd); }
    bd.querySelector('.title-bar-controls button').addEventListener('click', close);
    bd.querySelector('[data-act="close"]').addEventListener('click', close);
    bd.addEventListener('click', function(e){ if (e.target === bd) close(); });
    bd.querySelectorAll('[data-m]').forEach(function(it){
        it.addEventListener('click', function(){
            close();
            if (it.dataset.m === 'chat') openChat(k);
            else if (it.dataset.m === 'perfil') viewOtherUser(k);
            else pfConfirm('¿Eliminar a ' + amUser(k).label + ' de tus amigos?', 'Eliminar', function(){ amigosAccion('friend-remove', k); });
        });
    });
}

(function setupAmigos() {
    var view = document.getElementById('pf-view-social');
    if (!view) return;
    view.querySelectorAll('[data-amtab]').forEach(function(b){
        b.addEventListener('click', function(){ amTab(b.dataset.amtab); });
    });
    document.getElementById('pfa-filtro').addEventListener('input', amPintarLista);
    var tBuscar = null;
    document.getElementById('pfa-q').addEventListener('input', function(){ clearTimeout(tBuscar); tBuscar = setTimeout(amBuscar, 300); });
    document.getElementById('pfa-buscar-form').addEventListener('submit', function(e){ e.preventDefault(); amBuscar(); });

    /* Mantener pulsado (500 ms, como en Música) sobre un amigo. */
    var lp = null, lpX = 0, lpY = 0, lpHecho = false;
    function lpEmpezar(el, x, y) {
        lpCancelar();
        lpX = x; lpY = y; lpHecho = false;
        el.classList.add('long-pressing');
        lp = { el: el, t: setTimeout(function(){
            lpHecho = true;
            el.classList.remove('long-pressing');
            lp = null;
            try { navigator.vibrate && navigator.vibrate(40); } catch (_) {}
            amMenu(el.dataset.user);
        }, 500) };
    }
    function lpCancelar() {
        if (!lp) return;
        clearTimeout(lp.t);
        lp.el.classList.remove('long-pressing');
        lp = null;
    }
    function lpMover(x, y) { if (lp && (Math.abs(x - lpX) > 12 || Math.abs(y - lpY) > 12)) lpCancelar(); }
    view.addEventListener('touchstart', function(e){
        var a = e.target.closest('.pfa-amigo');
        if (a && e.touches && e.touches.length === 1) lpEmpezar(a, e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });
    view.addEventListener('touchmove', function(e){ if (e.touches[0]) lpMover(e.touches[0].clientX, e.touches[0].clientY); }, { passive: true });
    view.addEventListener('touchend', lpCancelar);
    view.addEventListener('touchcancel', lpCancelar);
    view.addEventListener('mousedown', function(e){
        var a = e.target.closest('.pfa-amigo');
        if (a && e.button === 0) lpEmpezar(a, e.clientX, e.clientY);
    });
    view.addEventListener('mousemove', function(e){ lpMover(e.clientX, e.clientY); });
    view.addEventListener('mouseup', lpCancelar);
    view.addEventListener('mouseleave', lpCancelar);
    /* Android también lanza contextmenu al mantener pulsado. */
    view.addEventListener('contextmenu', function(e){ if (e.target.closest('.pfa-amigo')) e.preventDefault(); });

    view.addEventListener('click', function(e){
        if (lpHecho) { lpHecho = false; e.preventDefault(); e.stopPropagation(); return; }
        var btn = e.target.closest('button[data-act]');
        var fila = e.target.closest('.pfa-fila[data-user]');
        if (btn && fila) {
            if (!btn.dataset.act) return;
            btn.disabled = true;
            amigosAccion(btn.dataset.act, fila.dataset.user).then(function(){ if (AMIGOS.busqueda) amBuscar(); });
            return;
        }
        if (fila && !e.target.closest('button')) viewOtherUser(fila.dataset.user);
    });

    /* Solicitudes nuevas, aceptadas o canceladas (desde aquí, el PC o el
       aviso de la carcasa): el timbre de la carcasa avisa. */
    var t = timbreMovil();
    if (t) {
        var baja = t.on('amigos', amigosCargar);
        window.addEventListener('pagehide', function(){ try { baja(); } catch (_) {} });
    }
    amigosCargar();
})();

/* ─── View other user ──────────────────────────────────────────── */
function viewOtherUser(userKey) {
    if (!userKey || userKey === USER_KEY) return;
    /* Si estamos YA viendo este user (por ?as= en URL), no recargues —
       la página ya se renderizó con SU look server-side, solo nos toca
       cargar sus datos del perfil. */
    if (window.__MOBILE_VIEWING_KEY === userKey) {
        viewOtherUserInPlace(userKey);
        return;
    }
    /* Sino, NAVEGAMOS a perfil-mobile.php?as=USERKEY para que el PHP
       resuelva server-side la INTERFAZ, TEMA e ICONOS del visitado.
       Usamos location.replace (NO .href) para no añadir entrada al
       history del browser — así "‹ Menú" (que hace history.back) vuelve
       al menú del shell, no a la cadena de perfiles ajenos visitados. */
    try {
        window.location.replace('perfil-mobile.php?as=' + encodeURIComponent(userKey));
        return;
    } catch (_) {}
}

/* Carga in-place sin recarga — usado SOLO en el bootstrap cuando ya
   estamos en perfil-mobile.php?as=USERKEY. */
function viewOtherUserInPlace(userKey) {
    fetch(API + '?action=view-user&user=' + encodeURIComponent(userKey), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || d.error) { pfAlert((d && d.error) || 'No se pudo cargar el perfil'); return; }
            STATE.viewingUser  = d.userKey;
            STATE.viewingLabel = d.label;
            STATE.profile      = d.profile || {};
            /* view-user manda el perfil nuevo aparte (get-profile, dentro). */
            if (d.v2) STATE.profile.v2 = d.v2;
            STATE.lists        = d.lists;
            updateHeaderForViewing();
            updateFollowBtn(d.amistad || !!d.isFollowing);
            renderAbout(STATE.profile);
            ['movies','series','books','games','music'].forEach(function(cat){
                var arr = (STATE.lists && STATE.lists[cat]) || [];
                var el = document.getElementById('cnt-' + cat);
                if (el) el.textContent = arr.length;
            });
            renderActiveList();
            showView('profile');
        })
        .catch(function(){ pfAlert('Error de conexión'); });
}

function exitViewingUser() {
    STATE.viewingUser  = null;
    STATE.viewingLabel = null;
    updateHeaderForViewing();
    /* Recarga mis datos. */
    fetchProfile();
    fetchLists();
    showView('profile');
}

function updateHeaderForViewing() {
    var viewing = !!STATE.viewingUser;
    /* El banner "Viendo perfil de …" se eliminó del HTML; los IDs ya
       no existen → guard con `if (el)` para no romper el flow del JS
       que sigue cambiando otros elementos del header. */
    var banner  = document.getElementById('pf-viewing-banner');
    if (banner) banner.classList.toggle('is-active', viewing);
    if (viewing) {
        var vt = document.getElementById('pf-viewing-text');
        if (vt) vt.textContent =
            'Viendo perfil de ' + (STATE.viewingLabel || '?');
    }
    /* Cambia el nombre y avatar del userbar al perfil que estoy viendo. */
    var nameEl    = document.getElementById('pf-userbar-name');
    var greetEl   = document.getElementById('pf-userbar-greeting');
    var avatarEl  = document.getElementById('pf-userbar-avatar');
    if (viewing) {
        var info = PROFILE_USERS[STATE.viewingUser];
        nameEl.textContent  = STATE.viewingLabel || (info && info.label) || '?';
        greetEl.textContent = 'Perfil de';
        if (info && info.image) {
            avatarEl.innerHTML = '<img src="' + esc(info.image) + '" alt="">';
        } else {
            avatarEl.innerHTML = '<span><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>';
        }
        attachPresenceDot(avatarEl, STATE.viewingUser);
        avatarEl.setAttribute('data-marco-user', STATE.viewingUser);   /* marco (melon-marcos.js) */
    } else {
        nameEl.textContent  = USER_LABEL;
        greetEl.textContent = 'Perfil de';
        var meInfo = PROFILE_USERS[USER_KEY];
        if (meInfo && meInfo.image) {
            avatarEl.innerHTML = '<img src="' + esc(meInfo.image) + '" alt="">';
        } else {
            avatarEl.innerHTML = '<span><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>';
        }
        attachPresenceDot(avatarEl, USER_KEY);
        avatarEl.setAttribute('data-marco-user', USER_KEY);   /* marco (melon-marcos.js) */
    }
    toggleProfileActions();
}

/* Guard: el botón #pf-viewing-back se eliminó del HTML — la navegación
   de vuelta vive en la statusbar inferior. */
var _pfBackBtn = document.getElementById('pf-viewing-back');
if (_pfBackBtn) _pfBackBtn.addEventListener('click', exitViewingUser);

/* ─── Chat ──────────────────────────────────────────────────────
   Modal de pantalla casi completa con polling cada 1.5s mientras está
   abierto. Solo accesible desde perfil ajeno con mutual follow — el
   server reenforza el 403 si no lo es. */

var CHAT = { withUser: null, pollTimer: null, lastId: null };

/* ── Supresión de notificaciones cuando el chat está activo ──
   El service worker pregunta vía MessageChannel cada vez que llega un
   push si estamos focused en el chat con ese usuario. Si lo estamos Y
   la página es visible, devolvemos true → el SW se salta showNotification.
   Sin esto el usuario recibe ping en el SO mientras lee los mensajes
   en vivo (poll de chat). */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
        var d = e.data || {};
        if (d.type !== 'sw:is-chat-focused') return;
        var port = e.ports && e.ports[0];
        if (!port) return;
        var focused = (CHAT.withUser === d.fromKey)
                   && (document.visibilityState === 'visible');
        try { port.postMessage({ focused: focused }); } catch (_) {}
    });
}

function openChat(uKey) {
    if (!uKey || !isMutual(uKey)) return;
    var uInfo = PROFILE_USERS[uKey];
    if (!uInfo) return;
    CHAT.withUser = uKey;
    CHAT.lastId   = null;
    /* Limpia el badge inmediatamente al abrir el chat — el server
       actualizará last_seen en la primera llamada a get-messages. */
    if (STATE.unreadChats[uKey]) {
        delete STATE.unreadChats[uKey];
        renderUnreadBadges();
    }
    /* Limpia la notificación push del SO de esta conversación cuando el
       usuario "responde" abriendo el chat. El helper vive en mobile.php
       (parent del iframe) y enruta al SW por postMessage. */
    try {
        var fn = (window.parent && window.parent !== window) ? window.parent.mhClearNotifications : window.mhClearNotifications;
        if (typeof fn === 'function') fn({ tag: 'chat:' + uKey });
    } catch (_) {}

    var bd = document.createElement('div');
    bd.className = 'pf-modal-backdrop';
    bd.id = 'pf-chat-backdrop';
    /* Avatar del usuario en la title-bar. Si tiene image → img, si no → inicial sobre accent. */
    var avHtml = uInfo.image
        ? '<img src="' + esc(uInfo.image) + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">'
        : '<div style="width:100%;height:100%;background:var(--accent);color:var(--accent-text,#fff);font-size:10px;font-weight:bold;display:flex;align-items:center;justify-content:center;">' + esc((uInfo.label || '?').charAt(0).toUpperCase()) + '</div>';
    bd.innerHTML =
        '<div class="window pf-modal pf-chat-modal">' +
            '<div class="title-bar">' +
                '<div class="title-bar-text" style="display:flex;align-items:center;gap:6px;">' +
                    '<span id="pf-chat-title-av" data-marco-user="' + esc(uKey) + '" style="position:relative;width:18px;height:18px;display:inline-block;background:var(--inset-bg,#fff);box-shadow:-1px -1px 0 var(--bezel-dark-1,#0a0a0a), 1px 1px 0 var(--bezel-light-1,#fff);flex-shrink:0;">' + avHtml + '</span>' +
                    '<span>' + esc(uInfo.label) + '</span>' +
                '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body pf-chat-body" style="position:relative;">' +
                '<div class="pf-chat-messages" id="pf-chat-messages">' +
                    '<div class="pf-chat-empty">Cargando…</div>' +
                '</div>' +
                '<div id="pf-chat-emoji-panel" style="display:none;position:absolute;bottom:48px;left:8px;right:8px;background:var(--win-bg,silver);padding:6px;z-index:5;box-shadow:inset -1px -1px var(--bezel-dark-1,#0a0a0a),inset 1px 1px var(--bezel-light-1,#fff),inset -2px -2px var(--bezel-dark-2,grey),inset 2px 2px var(--bezel-light-2,#dfdfdf);max-height:160px;overflow-y:auto;"></div>' +
                '<div class="pf-chat-input-row">' +
                    '<input type="text" id="pf-chat-input" maxlength="2000" placeholder="Escribe un mensaje…">' +
                    '<button class="button" id="pf-chat-emoji-btn" type="button" title="Emotes" style="padding:3px 8px;">😀</button>' +
                    '<button class="button" id="pf-chat-send" type="button">Enviar</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(bd);

    bd.querySelector('.title-bar-controls button').addEventListener('click', closeChat);
    bd.addEventListener('click', function(e){ if (e.target === bd) closeChat(); });

    /* Dot de presencia del user con el que estoy chateando — pequeño,
       en la esquina inferior derecha del avatar de la title-bar. */
    var chatAv = bd.querySelector('#pf-chat-title-av');
    if (chatAv && window.__pfAttachPresenceDot) {
        window.__pfAttachPresenceDot(chatAv, uKey, true);
    }

    var input = bd.querySelector('#pf-chat-input');
    var sendBtn = bd.querySelector('#pf-chat-send');
    sendBtn.addEventListener('click', sendChatMessage);
    input.addEventListener('keydown', function(e){ if (e.key === 'Enter') sendChatMessage(); });

    /* Emoji picker — paleta de emotes. Mismo set y comportamiento que desktop. */
    var EMOTES = ['😀','😂','🥲','😅','😍','😘','🤩','🤔','🙄','😎',
                  '😭','😡','🥺','😴','🤤','🤯','🤗','🫶','🫡','👀',
                  '🥳','😏','😉','🙃','😬','😱','🤣','😋','😇','🤧',
                  '👍','👎','🙌','👏','💪','🙏','🤝','✌️','🤞','👌',
                  '❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💖',
                  '🔥','✨','💯','⭐','🎉','🎊','<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">','<img src="../../assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">','🎁','☕',
                  '🍕','🍔','🍿','🍻','🍰','🌹','🌸','🌈','☀️','🌙'];
    var panel  = bd.querySelector('#pf-chat-emoji-panel');
    var emBtn  = bd.querySelector('#pf-chat-emoji-btn');
    if (panel && emBtn) {
        panel.innerHTML = EMOTES.map(function(e){
            return '<button type="button" style="background:transparent;border:0;font-size:20px;padding:4px 6px;cursor:pointer;font-family:inherit;min-height:32px;" data-em="' + e + '">' + e + '</button>';
        }).join('');
        emBtn.addEventListener('click', function(e){
            e.stopPropagation();
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        });
        panel.addEventListener('click', function(e){
            var b = e.target.closest('button[data-em]');
            if (!b) return;
            var em = b.getAttribute('data-em');
            var start = input.selectionStart || input.value.length;
            var end   = input.selectionEnd   || input.value.length;
            input.value = input.value.slice(0, start) + em + input.value.slice(end);
            input.focus();
            input.setSelectionRange(start + em.length, start + em.length);
        });
        bd.addEventListener('click', function(e){
            if (panel.style.display === 'none') return;
            if (!panel.contains(e.target) && e.target !== emBtn) panel.style.display = 'none';
        });
    }

    loadChatMessages();
    programarChat();
}

/* Timbre de la carcasa del móvil (window.parent.melonTimbre,
   assets/js/timbre.js), si estamos dentro de ella. */
function timbreMovil() {
    try { return window.parent !== window ? window.parent.melonTimbre || null : null; } catch (e) { return null; }
}
/* Chat abierto: lo nuevo llega por el timbre ('chat': mensajes, editados,
   borrados, leídos), que con un chat abierto se mira cada segundo; aquí
   solo se repasa cada 10 s (el "entregado"). Antes se pedía cada 1,5 s.
   Sin timbre (o si deja de responder), cada 1,5 s como antes. */
function programarChat() {
    if (CHAT.pollTimer) clearTimeout(CHAT.pollTimer);
    var t = timbreMovil();
    if (t) t.rapido('chat', true);
    CHAT.pollTimer = setTimeout(function() {
        if (!CHAT.withUser) return;
        loadChatMessages();
        programarChat();
    }, t && t.ok() ? 10000 : 1500);
}

function closeChat() {
    if (CHAT.pollTimer) { clearTimeout(CHAT.pollTimer); CHAT.pollTimer = null; }
    var t = timbreMovil();
    if (t) t.rapido('chat', false);
    CHAT.withUser = null;
    CHAT.lastId   = null;
    var bd = document.getElementById('pf-chat-backdrop');
    if (bd && bd.parentNode) bd.parentNode.removeChild(bd);
}

function loadChatMessages() {
    if (!CHAT.withUser) return;
    fetch(API + '?action=get-messages&with=' + encodeURIComponent(CHAT.withUser), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) return;
            renderChatMessages(d.messages || []);
        })
        .catch(function(){});
}

function renderChatMessages(msgs) {
    var listEl = document.getElementById('pf-chat-messages');
    if (!listEl) return;
    var lastId = msgs.length ? msgs[msgs.length - 1].id : null;
    /* Sin cambios → no repintamos para no romper scroll del usuario. */
    if (lastId === CHAT.lastId) return;
    CHAT.lastId = lastId;
    var atBottom = (listEl.scrollHeight - listEl.scrollTop - listEl.clientHeight) < 40;
    if (!msgs.length) {
        listEl.innerHTML = '<div class="pf-chat-empty">Sin mensajes todavía. Di hola 👋</div>';
        return;
    }
    var html = '';
    msgs.forEach(function(m){
        var mine = m.from === USER_KEY;
        html +=
            '<div class="pf-chat-msg ' + (mine ? 'mine' : 'theirs') + '">' +
                '<div class="pf-chat-bubble">' + esc(m.text) + '</div>' +
                '<div class="pf-chat-time">' + relTime(m.sentAt || 0) + '</div>' +
            '</div>';
    });
    listEl.innerHTML = html;
    if (atBottom) listEl.scrollTop = listEl.scrollHeight;
}

function sendChatMessage() {
    if (!CHAT.withUser) return;
    var input   = document.getElementById('pf-chat-input');
    var sendBtn = document.getElementById('pf-chat-send');
    if (!input) return;
    var text = (input.value || '').trim();
    if (!text) return;
    input.disabled = true; sendBtn.disabled = true;
    fetch(API + '?action=send-message', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to: CHAT.withUser, text: text })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        input.disabled = false; sendBtn.disabled = false;
        if (d && d.error) { pfAlert(d.error); return; }
        input.value = '';
        loadChatMessages();
        input.focus();
    })
    .catch(function(){
        input.disabled = false; sendBtn.disabled = false;
    });
}

document.getElementById('pf-chat-btn').addEventListener('click', function(){
    if (STATE.viewingUser) openChat(STATE.viewingUser);
});

/* ─── Notificaciones de chats sin leer ─────────────────────────
   Polling cada 10s a get-unread-chats. El server devuelve
   { userKey: unreadCount } solo para chats con mensajes posteriores
   a mi last_seen. Pintamos badge rojo en la hamburguesa (total) y en
   cada avatar de amigo en Social (per-user). "9+" si supera 9. */

var UNREAD_POLL_MS = 10000;
/* Baseline para detectar mensajes NUEVOS. -1 = primer load → no suena
   por la cuenta inicial. Solo subidas posteriores hacen play. */
var _prevChatUnreadTotal = -1;

function loadUnreadChats() {
    fetch(API + '?action=get-unread-chats', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) return;
            var counts = d.counts || {};
            /* No muestro contador para el chat que tengo abierto ahora —
               el server lo limpiará en el siguiente tick al actualizar
               last_seen, pero localmente lo elimino ya para feedback inmediato. */
            if (CHAT.withUser && counts[CHAT.withUser]) delete counts[CHAT.withUser];
            STATE.unreadChats = counts;
            /* Sonido al subir el total (excluido el chat abierto, ya borrado
               arriba). El chat abierto no dispara sonido — está en vivo. */
            var total = 0;
            for (var k in counts) {
                if (Object.prototype.hasOwnProperty.call(counts, k)) total += counts[k] | 0;
            }
            var muteMsgs = false;
            try {
                var prefs = JSON.parse(localStorage.getItem('melonNotifPrefs') || 'null');
                if (prefs && prefs.mute_messages) muteMsgs = true;
            } catch (_) {}
            if (_prevChatUnreadTotal >= 0 && total > _prevChatUnreadTotal
                && !muteMsgs && typeof window.playNotifSound === 'function') {
                window.playNotifSound();
            }
            _prevChatUnreadTotal = total;
            renderUnreadBadges();
        })
        .catch(function(){});
}

function fmtUnread(n) { return n > 9 ? '9+' : String(n); }

function renderUnreadBadges() {
    /* Hamburguesa: badge con la suma total de no-leídos. */
    var total = 0;
    Object.keys(STATE.unreadChats).forEach(function(k){
        total += STATE.unreadChats[k] || 0;
    });
    var hamb = document.getElementById('pf-hamburger');
    if (hamb) setBadge(hamb, total);

    /* Social view: badge por cada tarjeta de amigo. Solo si el grid
       está montado (vista activa o pre-renderizada). */
    var gridEl = document.getElementById('pf-friends-grid');
    if (gridEl) {
        gridEl.querySelectorAll('.pf-friend-card[data-user]').forEach(function(card){
            var av = card.querySelector('.pf-friend-av');
            if (!av) return;
            var n = STATE.unreadChats[card.dataset.user] || 0;
            setBadge(av, n);
        });
    }
}

function setBadge(container, n) {
    var existing = container.querySelector(':scope > .pf-unread-badge');
    if (n > 0) {
        var text = fmtUnread(n);
        if (existing) {
            existing.textContent = text;
        } else {
            var b = document.createElement('span');
            b.className = 'pf-unread-badge';
            b.textContent = text;
            container.appendChild(b);
        }
    } else if (existing) {
        existing.parentNode.removeChild(existing);
    }
}

/* Copia un item de la lista de otro usuario a mi propio perfil. Como
   STATE.lists ahora apunta a las listas del que veo, hacemos GET fresco
   de las mías, dedup por título, y POST save-lists. */
function addItemToOwnProfile(cat, item) {
    fetch(API + '?action=get-lists', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            var myList = (Array.isArray(d[cat]) ? d[cat] : [])
                .filter(function(x){ return x && !x.sharedFrom; });
            var lower = (item.title || '').toLowerCase().trim();
            if (myList.some(function(x){ return (x.title || '').toLowerCase().trim() === lower; })) {
                pfAlert('"' + (item.title || 'Item') + '" ya está en tu perfil');
                return;
            }
            var copy = JSON.parse(JSON.stringify(item));
            delete copy.collaborators;
            delete copy.sharedFrom;
            delete copy.review;
            if (cat === 'music') {
                copy.id = 'music_' + Date.now();
                copy.featured = false;
                delete copy.status;
            } else {
                copy.id = 'item_' + Date.now();
                copy.status = 'pending';
            }
            myList.push(copy);
            fetch(API + '?action=save-lists', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category: cat, items: myList })
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.error) { pfAlert(d.error); return; }
                pfAlert('"' + (item.title || 'Item') + '" añadida a tu perfil');
            })
            .catch(function(){ pfAlert('Error al guardar.'); });
        })
        .catch(function(){ pfAlert('Error obteniendo el perfil.'); });
}

/* ─── Bootstrap ─────────────────────────────────────────────────── */
/* Carga inicial: fetchProfile rellena perfil + posts + following; fetchLists
   las 5 categorías. La vista por defecto es "Mis listas".

   Si llegamos con ?as=USERKEY (visitando otro perfil), saltamos el fetch
   del perfil propio y cargamos directamente los datos del visitado. La
   página YA se renderizó con SU look (interfaz/tema/iconos) server-side. */
if (window.__MOBILE_VIEWING_KEY) {
    /* Necesitamos fetchProfile para que PROFILE_USERS y caches base
       estén listos; luego viewOtherUserInPlace muestra al visitado. */
    fetchProfile().then(function() {
        viewOtherUserInPlace(window.__MOBILE_VIEWING_KEY);
    });
} else {
    fetchProfile();
}
fetchFollowers();
fetchLists();
showView('profile');
/* Chats sin leer: primera carga y, dentro de la carcasa del móvil, cuando
   su timbre dice que hay algo nuevo ('chat'; también el chat abierto). Si
   el timbre no responde, o sin carcasa, cada UNREAD_POLL_MS como antes.
   Al salir de esta página, se da de baja. */
loadUnreadChats();
(function() {
    var t = timbreMovil();
    if (!t) { setInterval(loadUnreadChats, UNREAD_POLL_MS); return; }
    var bajas = [
        t.on('chat', loadUnreadChats, UNREAD_POLL_MS),
        t.on('chat', function() { if (CHAT.withUser) loadChatMessages(); })
    ];
    window.addEventListener('pagehide', function() {
        bajas.forEach(function(b) { try { b(); } catch (e) {} });
        try { t.rapido('chat', false); } catch (e) {}
    });
})();

/* Deep-link desde notificación push: #chat=USERKEY
   La notif del SO abrió la PWA con este hash. Esperamos a que
   fetchFollowers termine (para que isMutual funcione) y abrimos el
   chat directamente. Limpiamos el hash para no re-disparar en navs.
   También en hashchange por si el shell cambia el src del iframe a
   #chat=KEY sin recargar (mismo path, solo hash distinto). */
function handleChatHash(){
    var m = /#chat=([a-z0-9_-]+)/i.exec(location.hash);
    if (!m) return;
    var uKey = m[1];
    try { history.replaceState(null, '', location.pathname + location.search); } catch (_) {}
    /* Si ya hay un chat abierto con la misma persona, no re-abrimos. */
    if (CHAT && CHAT.withUser === uKey) return;
    fetchFollowers().then(function(){
        if (isMutual(uKey)) openChat(uKey);
    });
}
handleChatHash();
window.addEventListener('hashchange', handleChatHash);

/* ─── Notificaciones (foreground + push) ──────────────────────
   Foreground: cuando el polling detecta un cambio en unreadChats y la
   pestaña NO está activa, disparamos new Notification para que el usuario
   se entere aunque tenga otra pestaña delante.

   Background: registramos el Service Worker y nos suscribimos al
   PushManager. La VAPID public key viene del server. Si el navegador
   no soporta push (iOS < 16.4, etc.) hacemos no-op silencioso. */

var LAST_UNREAD = {};
var ORIGINAL_LOAD_UNREAD = loadUnreadChats;
loadUnreadChats = function() {
    return fetch(API + '?action=get-unread-chats', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) return;
            var counts = d.counts || {};
            if (CHAT.withUser && counts[CHAT.withUser]) delete counts[CHAT.withUser];
            /* Detect deltas — solo nuevos mensajes desde el último poll. */
            var anyNew = false;
            Object.keys(counts).forEach(function(k){
                var prev = LAST_UNREAD[k] || 0;
                var curr = counts[k] || 0;
                if (curr > prev) {
                    anyNew = true;
                    if (document.visibilityState !== 'visible') {
                        showForegroundNotification(k, curr - prev);
                    }
                }
            });
            /* Sonido aunque la pestaña sí esté visible — el throttle del
               helper colapsa con el del polling original si ambos disparan. */
            var muteMsgs2 = false;
            try {
                var prefs2 = JSON.parse(localStorage.getItem('melonNotifPrefs') || 'null');
                if (prefs2 && prefs2.mute_messages) muteMsgs2 = true;
            } catch (_) {}
            if (anyNew && Object.keys(LAST_UNREAD).length && !muteMsgs2
                && typeof window.playNotifSound === 'function') {
                window.playNotifSound();
            }
            LAST_UNREAD = Object.assign({}, counts);
            STATE.unreadChats = counts;
            renderUnreadBadges();
        })
        .catch(function(){});
};

function showForegroundNotification(fromKey, deltaCount) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    var uInfo = PROFILE_USERS[fromKey];
    var title = '<img src="../../assets/img/appIcons/chatIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> ' + ((uInfo && uInfo.label) || fromKey);
    var body  = deltaCount === 1 ? 'Nuevo mensaje' : deltaCount + ' nuevos mensajes';
    try {
        new Notification(title, {
            body: body,
            icon: (uInfo && uInfo.image) || undefined,
            tag:  'chat:' + fromKey
        });
    } catch (_) {}
}

/* La subscripción al push y el registro del SW viven en el shell
   (mobile.php) para que el permiso de notificaciones se pida al entrar
   a la PWA, no cuando entras al perfil específicamente. */

/* Embebido en el shell SPA → "‹ Menú" no navega, envía postMessage. */
(function(){
    var embedded = false;
    try { embedded = window.parent !== window; } catch (_) {}
    if (!embedded) return;
    document.addEventListener('click', function(e){
        var link = e.target && e.target.closest && e.target.closest('.mh-statusbar a[href*="../../mobile.php"]');
        if (!link) return;
        e.preventDefault();
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); } catch (_) {}
    }, true);
})();
</script>

</body>
</html>
