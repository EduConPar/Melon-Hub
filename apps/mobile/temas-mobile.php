<?php
/* ──────────────────────────────────────────────────────────────────────
   TEMAS — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Versión móvil de apps/temas.php. Reusa los MISMOS IDs del HTML del
   desktop y el MISMO JS — los endpoints (themes/api.php, personalize/
   api.php, save-wallpaper, save-start-icon) son los mismos.
   La única diferencia es el layout (un solo flujo vertical con tabs
   horizontales scrollable) y los paths relativos (../../ en vez de ../).

   ⚠ Si tocas el JS del desktop, ten en cuenta que el bloque de JS de
      este archivo es una copia adaptada de paths. Mantenlo en sync.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__, 2) . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';

if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../../index.php');
    exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

/* Tema activo del usuario — mismo flow que el resto de apps móviles.
   refreshAllUserThemesCss regenera el CSS de TODOS los temas del usuario
   por si uploads/themes/ se perdió en un deploy (la BD es la fuente
   de verdad). */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshAllUserThemesCss($userKey, $userLabel);
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes = loadUserThemes($userKey);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $userLabel);
    $activeThemeCss   = themeCssRelPath($activeTheme, $userLabel);
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

/* Detecta packs de iconos: subcarpetas de assets/img/appIcons/.
   Cada carpeta = un pack. Idéntico al PHP de temas.php desktop. */
$iconPacks = [];
$appIconsDir = dirname(__DIR__, 2) . '/assets/img/appIcons';
if (is_dir($appIconsDir)) {
    foreach (scandir($appIconsDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($appIconsDir . '/' . $entry)) continue;
        $previewIcon = '';
        foreach (scandir($appIconsDir . '/' . $entry) as $f) {
            if (preg_match('/\.(png|jpg|gif|webp)$/i', $f)) {
                $previewIcon = '../../assets/img/appIcons/' . $entry . '/' . $f;
                break;
            }
        }
        $iconPacks[] = [
            'name'    => $entry,
            'preview' => $previewIcon,
        ];
    }
    usort($iconPacks, fn($a, $b) => strcasecmp($a['name'], $b['name']));
}

/* Detecta interfaces instaladas en assets/interfaces/. Cada una con
   style.css + meta.json + (opcional) preview.png. Las "premium" se
   ocultan hasta que se compren en la tienda. */
require_once dirname(__DIR__, 2) . '/assets/php/active-interface.php';
require_once dirname(__DIR__, 2) . '/db.php';
/** @var PDO $pdo */
$interfacePacks = listInterfacesForUser($pdo, userIdByKey($userKey));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="../../assets/js/pwa-guard.js"></script>
    <script>
    /* --mh-vh: viewport height en px sincronizado con window.innerHeight.
       Sin esto, .mh-body cae a 100dvh (stale tras bfcache) o 100vh
       (incluye la barra de URL → la ventana se desborda por debajo del
       área visible). Hay que setearlo ANTES de que el CSS lo lea. */
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
    <title>Temas</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <!-- Marcos de perfil en las fotos de los usuarios (data-marco-user) -->
    <link rel="stylesheet" href="../../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/css/marcos.css') ?>">
    <script src="../../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-marcos.js') ?>"></script>
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <!-- Icon pack swap — debe cargar antes del primer render. -->
    <script src="../../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__, 2) . "/assets/php/active-interface.php"; emitInterfaceCss("../../"); ?>
    <script src="../../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="../../<?= htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../../assets/css/mobile-theme.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/mobile-theme.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
    <style>
        /* ──────────────────────────────────────────────────────────────
           Look Win98 + tema del usuario:
           - El reset / fuente / fondo lo aporta mobile-theme.css
             (.mh-body + .mh-window + .title-bar + .window-body).
           - El tema activo se inyecta vía <link id="active-theme-link">
             y sobreescribe las CSS vars (--win-bg, --accent, etc).
           - Aquí solo definimos tweaks específicos de esta app
             (tabs, lista de temas, editor de colores, grids de personali-
             zación, library) usando esas mismas vars para heredar el
             tema sin colores hardcoded. */

        /* Tabs Win98 — estilo "ribbon" arriba del primer panel. Botones
           Win98 estándar con el activo pintado en accent del tema. */
        .tm-tabs {
            display: flex; gap: 4px;
            padding: 6px 4px 4px;
            background: var(--win-bg, silver);
            overflow-x: auto;
            flex-shrink: 0;
            -webkit-overflow-scrolling: touch;
        }
        .tm-tab {
            min-height: 30px;
            padding: 4px 12px;
            font-size: 11px;
            line-height: 1;
            white-space: nowrap;
            flex-shrink: 0;
            /* Centrado vertical del texto — sin esto el botón Win98
               con min-height pinta el contenido pegado arriba. */
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .tm-tab.active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }

        /* Paneles — heredan mh-panel (sunken Win98). Reforzamos las
           propiedades de scroll por si la cascade falla en algún
           browser móvil (algunos no propagan flex:1 + overflow:auto
           cuando el padding-bottom del padre cambia con safe-area). */
        .tm-pane {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }
        .tm-pane[hidden] { display: none; }

        /* Sección titulada (cabecera con título + body interno).
           Replica el patrón de "Datos / Conexiones" de perfil-mobile. */
        .tm-section { margin-bottom: 10px; }
        .tm-section:last-child { margin-bottom: 0; }
        .tm-section-title {
            font-size: 11px; font-weight: bold;
            padding: 4px 8px;
            background: var(--titlebar-start, #000080);
            color: var(--titlebar-text, #fff);
            letter-spacing: 1px;
            margin: 0;
            text-transform: uppercase;
        }
        .tm-section-body {
            background: var(--win-bg, silver);
            padding: 8px;
            color: var(--text, #000);
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }

        /* ── Lista de "Tus temas" ──
           Cada item es una mini-ventana Win98 con:
           - Mini-preview de la paleta del tema (4 swatches representan
             titlebar, win-bg, accent, text). Da contexto visual claro
             de cómo se ve cada tema sin tener que activarlo.
           - Nombre del tema.
           - Indicadores (<img src="../../assets/img/appIcons/downloadIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> descargado / 🌐 publicado).
           - Badge "ACTIVO" cuando es el tema actual. */
        #temas-list { display: flex; flex-direction: column; gap: 6px; }
        .temas-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px;
            color: var(--text, #000);
            background: var(--win-bg, silver);
            cursor: pointer;
            min-height: 56px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        .temas-item:active {
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-2, grey),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        /* Item seleccionado para edición — borde discontinuo Win98
           clásico (estilo "selección de icono en escritorio"). */
        .temas-item.active {
            outline: 1px dotted var(--text, #000);
            outline-offset: -3px;
        }
        /* Item correspondiente al TEMA ACTIVADO DEL SISTEMA — marca
           visualmente fuerte: outline accent + fondo titlebar. */
        .temas-item.is-active {
            outline: 2px solid var(--accent, #000080);
            outline-offset: -2px;
            background: var(--titlebar-start, #000080);
            color: var(--titlebar-text, #fff);
        }
        .temas-item.is-active .temas-item-name { font-weight: bold; }

        /* Mini-preview de paleta — 2×2 grid con 4 colores definitorios. */
        .temas-item-preview {
            flex-shrink: 0;
            width: 40px; height: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 0;
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
            overflow: hidden;
        }
        .temas-item-preview > span { display: block; }
        .temas-item.is-active .temas-item-preview {
            box-shadow:
                inset  1px  1px rgba(0,0,0,0.6),
                inset -1px -1px rgba(255,255,255,0.5);
        }

        .temas-item-name {
            flex: 1; min-width: 0;
            font-size: 13px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Indicadores (descargado / publicado en biblioteca). */
        .temas-item-dl, .temas-item-pub {
            width: 16px;
            height: 16px;
            object-fit: contain;
            image-rendering: pixelated;
            flex-shrink: 0;
        }
        /* Badge "ACTIVO" — contrasta con el tema activo. */
        .temas-item-badge {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            padding: 2px 6px;
            background: var(--accent-text, #fff);
            color: var(--accent, #000080);
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .temas-item:not(.is-active) .temas-item-badge {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }

        /* Footer de la pestaña "Mis temas" con botones grandes. */
        .tm-actions {
            display: flex; gap: 6px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .tm-actions .button { flex: 1; min-width: 0; min-height: 32px; font-size: 11px; }

        /* Estado / status de operaciones — sunken Win98. */
        #temas-status {
            font-size: 11px;
            padding: 6px 8px;
            margin-top: 8px;
            background: var(--input-bg, #fff);
            color: var(--input-text, var(--text, #000));
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
            min-height: 21px;
        }

        /* Toolbar de tema (input nombre + Guardar/Activar/Export/Import).
           Misma estética raised que las section-body. */
        #temas-toolbar {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 8px;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            margin-bottom: 8px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        #temas-toolbar .field-row-stacked {
            grid-column: 1 / -1;
            margin: 0;
        }
        #temas-toolbar label { font-size: 10px; }
        #temas-toolbar input[type="text"] {
            width: 100%; font-size: 12px; padding: 4px;
            box-sizing: border-box;
        }
        #temas-toolbar .button { min-height: 28px; font-size: 11px; }

        /* Editor de colores — agrupado en cards Win98. Una columna en
           móvil. Cada fila: label (truncable) + color picker + hex. */
        #temas-editor {
            display: flex; flex-direction: column; gap: 6px;
        }
        #temas-editor .color-group {
            background: var(--win-bg, silver);
            color: var(--text, #000);
            padding: 8px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        #temas-editor .color-group-title {
            font-size: 11px; font-weight: bold;
            margin: 0 0 6px;
            color: var(--text, #000);
            letter-spacing: 1px;
            text-transform: uppercase;
            border-bottom: 1px dotted var(--text-faint, #aaa);
            padding-bottom: 3px;
        }
        #temas-editor .color-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 6px; align-items: center;
            margin-bottom: 6px;
        }
        #temas-editor .color-row:last-child { margin-bottom: 0; }
        #temas-editor .color-row label {
            font-size: 11px;
            color: var(--text, #000);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        #temas-editor input[type="color"] {
            width: 44px; height: 30px; padding: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
        }
        #temas-editor input[type="text"] {
            width: 80px; font-size: 11px; padding: 3px 4px;
            box-sizing: border-box;
            font-family: Consolas, Monaco, monospace;
        }

        /* Wallpaper / start-icon previews — sunken Win98 con doble bezel. */
        #temas-wallpaper-preview, #temas-starticon-preview {
            width: 100%; height: 100px;
            background-color: var(--inset-bg, #808080);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            margin-bottom: 6px;
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        #temas-starticon-preview {
            height: 80px;
            background-size: contain;
        }

        /* Personalización — grids de Interfaces / Haros / Mascotas. */
        .pers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
            gap: 6px;
            padding: 6px;
            background: var(--inset-bg, #c0c0c0);
            color: var(--input-text, var(--text, #000));
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
            min-height: 60px;
        }
        .pers-item {
            display: flex; flex-direction: column;
            align-items: center; gap: 4px;
            padding: 8px 4px 6px;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
            cursor: pointer;
            font-size: 10px; text-align: center;
            user-select: none; position: relative;
            min-height: 86px;
        }
        .pers-item.active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -1px -1px var(--bezel-light-1, #fff);
        }
        .pers-item-icon {
            width: 48px; height: 48px;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; line-height: 1;
            overflow: hidden;
        }
        .pers-item-icon img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .pers-item-name { line-height: 1.2; word-break: break-word; }
        .pers-item-badge {
            position: absolute; top: 3px; right: 3px;
            font-size: 8px; padding: 1px 4px;
            background: rgba(0,0,0,0.4);
            color: #fff;
            border-radius: 6px; letter-spacing: 1px;
        }
        .pers-item.active .pers-item-badge { background: rgba(255,255,255,0.3); }
        /* Fuentes: "Aa" y el nombre con su fuente (como el PC). */
        .pers-fuente .pers-item-icon { font-size: 30px; white-space: nowrap; }
        .pers-fuente .pers-item-name { font-size: 12px; }

        /* ── Biblioteca de temas ──
           Cada item es un card Win98 raised con:
           - .lib-preview arriba: vista previa del escritorio con el
             tema (wallpaper + colores).
           - .lib-name: TÍTULO del tema (grande, bold, alta jerarquía).
           - .lib-author: barra inferior con avatar redondo del usuario
             y su nombre (más pequeño, color muted). Separada del
             título por un divider para distinguir jerarquía. */
        #temas-library {
            margin-top: 4px;
        }
        #temas-library-grid {
            display: flex; flex-direction: column;
            gap: 10px;
        }
        .lib-card {
            background: var(--win-bg, silver);
            color: var(--text, #000);
            padding: 8px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        /* El preview ya NO muestra el escritorio del tema: solo una
           mini ventana Win98 con los colores del tema, centrada sobre
           un fondo neutro (a rayas tipo "checkered" sutil para que se
           note que es un mockup, no el escritorio real). */
        .lib-preview {
            width: 100%; height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            box-sizing: border-box;
            background:
                repeating-linear-gradient(
                    45deg,
                    rgba(0,0,0,0.04) 0 6px,
                    transparent       6px 12px
                ),
                var(--inset-bg, #c0c0c0);
            margin-bottom: 8px;
            cursor: pointer;
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }

        /* Mini ventana Win98 que renderiza buildThemePreview(). */
        .lib-win {
            width: 70%;
            max-width: 180px;
            border: 1px solid #000;
            box-shadow: 2px 2px 0 rgba(0,0,0,0.35);
        }
        .lib-win-tb {
            display: flex; align-items: center; gap: 4px;
            padding: 3px 5px;
            font-size: 9px; font-weight: bold;
        }
        .lib-win-tb-dots {
            margin-left: auto;
            display: flex; gap: 2px;
        }
        .lib-win-tb-dots i {
            width: 7px; height: 7px;
            display: block;
            border: 1px solid rgba(0,0,0,0.4);
        }
        .lib-win-body { padding: 6px 7px; min-height: 40px; }
        .lib-win-row { height: 7px; margin-bottom: 4px; }
        .lib-win-btns { display: flex; gap: 4px; margin-top: 4px; }
        .lib-win-btn {
            font-size: 8px;
            padding: 1px 6px;
            line-height: 1.4;
        }
        /* Título del tema — protagonista visual. */
        .lib-name {
            font-size: 15px;
            font-weight: bold;
            color: var(--text, #000);
            padding: 0 2px 6px;
            margin: 0;
            line-height: 1.2;
            word-break: break-word;
            border-bottom: 1px dotted var(--text-muted, var(--text-faint, #888));
        }
        /* Autor — barra inferior secundaria, tap → ver perfil. */
        .lib-author {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 2px 2px;
            cursor: pointer;
            min-height: 32px;
        }
        .lib-author:active { opacity: 0.7; }
        .lib-author-img {
            width: 24px; height: 24px;
            flex-shrink: 0;
            object-fit: cover;
            background: var(--inset-bg, #808080);
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
            margin: 2px;
        }
        /* Con foto, va dentro de .lib-author-av, que lleva el marco de
           perfil del autor (data-marco-user, melon-marcos.js). */
        .lib-author-av { display:block; width:24px; height:24px; margin:2px; flex-shrink:0; }
        .lib-author-av > .lib-author-img { margin:0; }
        .lib-author-ph {
            display: inline-flex;
            align-items: center; justify-content: center;
            width: 24px; height: 24px;
            flex-shrink: 0;
            font-size: 16px;
            background: var(--inset-bg, #808080);
            color: var(--accent-text, #fff);
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff);
            margin: 2px;
        }
        .lib-author-name {
            flex: 1; min-width: 0;
            font-size: 11px;
            font-style: italic;
            color: var(--text-muted, var(--text-faint, #808080));
            text-decoration: underline;
            text-decoration-style: dotted;
            text-decoration-thickness: 1px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #temas-library-empty { text-align:center; color: var(--text-muted, var(--text-faint, #808080)); font-size: 11px; padding: 24px 10px; }

        /* Field-rows en panes — ajustes táctiles. */
        .tm-pane .field-row { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
        .tm-pane .field-row .button { min-height: 30px; font-size: 11px; }
        .tm-pane input[type="text"] { font-size: 12px; padding: 4px 6px; }
        .tm-pane input[type="checkbox"] { min-width: 20px; min-height: 20px; }

        /* Modal de confirmación — centrado bien en móvil. */
        #theme-delete-modal { max-width: 92vw !important; min-width: 280px !important; }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">
<div class="window mh-window">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Temas</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="tm-back"></button>
        </div>
    </div>
    <div class="window-body">

<div class="tm-tabs" id="temas-tabs">
    <button class="button tm-tab active" data-tab="themes"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Mis temas</button>
    <button class="button tm-tab"        data-tab="personalize"><img src="../../assets/img/appIcons/drawingIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Personalización</button>
    <button class="button tm-tab"        data-tab="library"><img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Biblioteca</button>
</div>

<!-- TAB 1: MIS TEMAS — lista + editor de colores + toolbar -->
<div class="mh-panel tm-pane" id="temas-pane-themes">
    <div class="tm-section">
        <h3 class="tm-section-title">Tus temas</h3>
        <div class="tm-section-body">
            <div id="temas-list"></div>
            <div class="tm-actions">
                <button class="button" id="temas-new">+ Nuevo</button>
                <button class="button" id="temas-deactivate">Usar default</button>
            </div>
        </div>
    </div>

    <!-- Editor (visible cuando se selecciona o crea uno). -->
    <div class="tm-section">
        <div id="temas-toolbar">
            <div class="field-row-stacked">
                <label for="theme-name-input">Nombre del tema</label>
                <input type="text" id="theme-name-input" maxlength="30" placeholder="MiTema">
            </div>
            <button class="button" id="theme-save">Guardar</button>
            <button class="button" id="theme-activate">Activar</button>
            <button class="button" id="theme-export"  title="Descargar tema como JSON">⬇ Exportar</button>
            <button class="button" id="theme-import"  title="Cargar tema desde JSON">⬆ Importar</button>
            <input type="file" id="theme-import-file" accept="application/json,.json" style="display:none;">
        </div>
        <div id="temas-editor"></div>
        <!-- Slider de tamaño de fuente: delta en px sumado a TODAS las
             fuentes (mantiene jerarquías). -6 = más pequeño, +10 = más
             grande. Cambio en vivo via window.setFontScaleDelta + se
             propaga al shell padre con postMessage. -->
        <div id="temas-font-row" class="color-field" style="margin-top:14px;">
            <div class="color-field-label">Tamaño de fuente (delta px)</div>
            <div class="color-field-row" style="gap:8px;align-items:center;">
                <input type="range" id="theme-font-delta" min="-6" max="10" step="1" value="0" style="flex:1 1 auto;min-width:0;">
                <span id="theme-font-delta-readout" style="min-width:3.5em;text-align:right;font-variant-numeric:tabular-nums;">0</span>
            </div>
        </div>
        <div id="temas-status">Crea un tema nuevo o selecciona uno existente para editarlo.</div>
    </div>
</div>

<!-- TAB 2: PERSONALIZACIÓN — efectos + grids de inventario.
     (En móvil ocultamos Fondo e Icono de inicio: la PWA no los usa
     visualmente, son props del escritorio Win98.) -->
<div class="tm-pane" id="temas-pane-personalize" hidden>
    <!-- Stubs ocultos para que el JS compartido del editor no pete al
         hacer getElementById(). NO se renderizan en pantalla. -->
    <div hidden>
        <div id="temas-wallpaper-preview"></div>
        <input type="text" id="temas-wallpaper-name">
        <button id="temas-wallpaper-browse"></button>
        <input type="file" id="temas-wallpaper-file">
        <button id="temas-wallpaper-save"></button>
        <p id="temas-wallpaper-status"></p>
        <div id="temas-starticon-preview"></div>
        <input type="text" id="temas-starticon-name">
        <button id="temas-starticon-browse"></button>
        <input type="file" id="temas-starticon-file">
        <button id="temas-starticon-save"></button>
        <p id="temas-starticon-status"></p>
    </div>

    <div class="tm-section">
        <h3 class="tm-section-title"><img src="../../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Efectos visuales</h3>
        <div class="tm-section-body">
            <div class="field-row">
                <input type="checkbox" id="lcd-filter-toggle">
                <label for="lcd-filter-toggle" style="font-size:12px;">Filtro LCD (scanlines)</label>
            </div>
            <p style="font-size:10px;margin:6px 0 0;color:var(--text-faint);line-height:1.4;">
                Líneas horizontales sutiles sobre toda la app. Se guarda por dispositivo.
            </p>
        </div>
    </div>

    <!-- Vista de Interfaces / Iconos / Haros / Mascotas con cosas que tienes. -->
    <div id="temas-personalize">
        <section class="tm-section">
            <h3 class="tm-section-title"><img src="../../assets/img/appIcons/interfaceIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Interfaces</h3>
            <div class="pers-grid" id="pers-themes-grid"></div>
        </section>
        <section class="tm-section">
            <h3 class="tm-section-title"><img src="../../assets/img/appIcons/interfaceIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Fuentes</h3>
            <div class="pers-grid" id="pers-fonts-grid"></div>
        </section>
        <section class="tm-section">
            <h3 class="tm-section-title"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Iconos</h3>
            <div class="pers-grid" id="pers-icons-grid"></div>
        </section>
        <section class="tm-section">
            <h3 class="tm-section-title"><img src="../../assets/img/appIcons/haroIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Haros</h3>
            <div class="pers-grid" id="pers-haros-grid"></div>
        </section>
        <section class="tm-section">
            <h3 class="tm-section-title"><img src="../../assets/img/appIcons/mascotaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Mascotas</h3>
            <div class="pers-grid" id="pers-mascots-grid" style="font-size:13px;color:var(--text-muted, var(--text));padding:6px 0;">Coming soon...</div>
        </section>
    </div>
</div>

<!-- TAB 3: BIBLIOTECA -->
<div class="tm-pane" id="temas-pane-library" hidden>
    <div class="tm-section">
        <h3 class="tm-section-title"><img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Biblioteca de temas</h3>
        <div class="tm-section-body">
            <p style="font-size:11px;color:var(--text);margin:0 0 8px;line-height:1.4;">
                Temas publicados por la comunidad. Pulsa para probar; pulsa al autor para visitar su perfil.
            </p>
            <button class="button" id="temas-lib-refresh" style="width:100%;min-height:32px;">↻ Recargar</button>
        </div>
    </div>
    <div id="temas-library">
        <div id="temas-library-grid"></div>
    </div>
</div>

        <!-- Status bar Win98 al pie con "Volver al menú". Replica el
             patrón de perfil-mobile / musica-mobile. Vive fuera del
             flujo de paneles para que esté siempre visible aunque
             cambies de pestaña. -->
        <div class="mh-statusbar">
            <a href="#" id="tm-menu-link">‹ Menú</a>
        </div>

    </div><!-- /.window-body -->
</div><!-- /.window.mh-window -->

<!-- Modal de confirmación de borrado (reusado del desktop). -->
<div class="window" id="theme-delete-modal" style="display:none; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); min-width:280px; max-width:92vw; z-index:8500; flex-direction:column;">
    <div class="title-bar">
        <div class="title-bar-text">Confirmar eliminación de tema</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="theme-delete-x"></button>
        </div>
    </div>
    <div class="window-body w98-confirm-body">
        <div class="w98-confirm-row">
            <div class="w98-icon-question"></div>
            <div class="w98-confirm-text" id="theme-delete-text">¿Eliminar el tema?</div>
        </div>
        <div class="w98-confirm-btns">
            <button id="theme-delete-ok" class="default">Sí</button>
            <button id="theme-delete-cancel">No</button>
        </div>
    </div>
</div>

<script>
/* Botones "‹ Menú": X de la title-bar Win98 + enlace de la status-bar
   abajo. Ambos vuelven al shell de mobile.php vía postMessage
   (cuando estamos en iframe) o history.back si la app está standalone. */
(function(){
    function goMenu(e){
        if (e) e.preventDefault();
        if (window.parent && window.parent !== window) {
            try { window.parent.postMessage({ type: 'shell:back' }, '*'); return; } catch (_) {}
        }
        try { history.back(); } catch (_) { location.href = '../../mobile.php'; }
    }
    var topBtn = document.getElementById('tm-back');
    if (topBtn) topBtn.addEventListener('click', goMenu);
    var bottomLink = document.getElementById('tm-menu-link');
    if (bottomLink) bottomLink.addEventListener('click', goMenu);
})();

/* Tab switcher — replica el del desktop pero sobre los paneles full-width. */
(function() {
    var tabs  = document.querySelectorAll('.tm-tab');
    var panes = {
        themes:      document.getElementById('temas-pane-themes'),
        personalize: document.getElementById('temas-pane-personalize'),
        library:     document.getElementById('temas-pane-library')
    };
    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            tabs.forEach(function(b) { b.classList.remove('active'); });
            t.classList.add('active');
            var which = t.dataset.tab;
            Object.keys(panes).forEach(function(k) {
                if (k === which) panes[k].removeAttribute('hidden');
                else             panes[k].setAttribute('hidden', '');
            });
            if (which === 'library')     loadLibrary();
            if (which === 'personalize') loadPersonalize();
        });
    });
    /* Auto-restore tab tras un reload por cambio de pack de iconos.
       DOMContentLoaded asegura que TODOS los <script> del archivo se han
       parseado (en mobile, loadPersonalize vive en un <script> posterior
       a este IIFE → sin esperar, el click trigger falla con
       ReferenceError porque loadPersonalize aún no está definido). */
    document.addEventListener('DOMContentLoaded', function() {
        try {
            var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
            var restore = topStorage.getItem('temas-restore-tab');
            if (restore) {
                topStorage.removeItem('temas-restore-tab');
                var btn = document.querySelector('.tm-tab[data-tab="' + restore + '"]');
                if (btn) btn.click();
            }
        } catch (_) {}
    });
})();
</script>

<!-- ====================================================================
     JS PRINCIPAL — copiado de apps/temas.php con paths ajustados.
     Mantener en sync con el original si se modifica la lógica.
     =================================================================== -->
<script>
/* Packs de iconos detectados por PHP. Mismo formato que en temas.php
   desktop — la función renderIconPacks() los usa. */
window.__ICON_PACKS__      = <?= json_encode($iconPacks, JSON_UNESCAPED_UNICODE) ?>;
window.__INTERFACE_PACKS__ = <?= json_encode($interfacePacks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
const COLOR_DEFS = [
    /* — Superficies — */
    { key: 'winBg',         label: 'Fondo de ventana',          def: '#c0c0c0', group: 'Superficies' },
    { key: 'winBodyBg',     label: 'Fondo del cuerpo',          def: '#c0c0c0', group: 'Superficies' },
    { key: 'surfaceDeep',   label: 'Taskbar / paneles',         def: '#c0c0c0', group: 'Superficies' },
    { key: 'insetBg',       label: 'Fondo hundido (placeholder)', def: '#808080', group: 'Superficies' },
    /* — Inputs — */
    { key: 'inputBg',       label: 'Fondo del input',           def: '#ffffff', group: 'Inputs' },
    { key: 'inputText',     label: 'Texto del input',           def: '#000000', group: 'Inputs' },
    /* — Botones — */
    { key: 'btnBg',         label: 'Fondo del botón',           def: '#c0c0c0', group: 'Botones' },
    { key: 'btnText',       label: 'Texto del botón',           def: '#000000', group: 'Botones' },
    { key: 'startBtnBg',    label: 'Fondo del botón Inicio',    def: '#c0c0c0', group: 'Botones' },
    { key: 'startBtnText',  label: 'Texto del botón Inicio',    def: '#000000', group: 'Botones' },
    /* — Texto — */
    { key: 'text',          label: 'Texto principal',           def: '#000000', group: 'Texto' },
    { key: 'textMuted',     label: 'Texto secundario',          def: '#666666', group: 'Texto' },
    { key: 'textFaint',     label: 'Texto terciario',           def: '#808080', group: 'Texto' },
    { key: 'textInset',     label: 'Texto sobre hundido',       def: '#808080', group: 'Texto' },
    /* — Acento / barra de título — */
    { key: 'titlebarStart', label: 'Título — gradiente inicio', def: '#000080', group: 'Acento' },
    { key: 'titlebarEnd',   label: 'Título — gradiente final',  def: '#1084d0', group: 'Acento' },
    { key: 'titlebarText',  label: 'Título — texto',            def: '#ffffff', group: 'Acento' },
    { key: 'accent',        label: 'Acento (hover/activo)',     def: '#000080', group: 'Acento' },
    { key: 'accentText',    label: 'Texto sobre acento',        def: '#ffffff', group: 'Acento' },
    { key: 'accentDeep',    label: 'Acento profundo (hover²)',  def: '#00004a', group: 'Acento' },
    /* — Iconos de ventana (close / minimize / maximize) — */
    { key: 'titlebarIconColor',      label: 'Color del dibujo del icono', def: '#000000', group: 'Iconos de ventana' },
    { key: 'titlebarIconBg',         label: 'Fondo del botón del icono',  def: '#c0c0c0', group: 'Iconos de ventana' },
    { key: 'titlebarIconBezelLight', label: 'Borde 3D claro (top-left)',  def: '#ffffff', group: 'Iconos de ventana' },
    { key: 'titlebarIconBezelDark',  label: 'Borde 3D oscuro (bot-right)', def: '#0a0a0a', group: 'Iconos de ventana' },
    /* — Bordes — */
    { key: 'border',        label: 'Borde general',             def: '#808080', group: 'Bordes' },
    /* — Bezels Win98 (4 capas) — */
    { key: 'bezelLight1',   label: 'Bezel claro (1px)',         def: '#ffffff', group: 'Bezels' },
    { key: 'bezelLight2',   label: 'Bezel claro (2px)',         def: '#dfdfdf', group: 'Bezels' },
    { key: 'bezelDark1',    label: 'Bezel oscuro (1px)',        def: '#0a0a0a', group: 'Bezels' },
    { key: 'bezelDark2',    label: 'Bezel oscuro (2px)',        def: '#808080', group: 'Bezels' },
    /* — Escritorio — */
    { key: 'desktopBg',     label: 'Fondo del escritorio',      def: '#008080', group: 'Escritorio' },
    /* — Estados — */
    { key: 'linkText',      label: 'Hipervínculos',             def: '#0000ff', group: 'Estados' },
    { key: 'errorText',     label: 'Texto de error',            def: '#c00000', group: 'Estados' },
    { key: 'warningBg',     label: 'Fondo de aviso',            def: '#fffbe6', group: 'Estados' },
    { key: 'warningText',   label: 'Texto de aviso',            def: '#444444', group: 'Estados' },
    /* — Selección / badges — */
    { key: 'badgeBg',       label: 'Fondo del badge',           def: '#d72638', group: 'Selección' },
    { key: 'badgeText',     label: 'Texto del badge',           def: '#ffffff', group: 'Selección' },
    /* — Decorativos — */
    { key: 'starColor',     label: 'Estrellas (rating)',        def: '#ffd700', group: 'Decorativos' }
];

/* Defaults dinámicos por interfaz: lee :root de la interfaz activa
   con getComputedStyle y reemplaza los `def` Win98 hardcoded. */
(function() {
    var rootStyle = getComputedStyle(document.documentElement);
    function keyToVar(key) {
        return '--' + key
            .replace(/([A-Z])/g, '-$1')
            .replace(/([a-zA-Z])(\d)/g, '$1-$2')
            .toLowerCase();
    }
    function normalizeColor(v) {
        v = (v || '').trim();
        if (!v) return '';
        if (/^#[0-9a-f]{3}$/i.test(v)) {
            return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
        }
        if (/^#[0-9a-f]{6}$/i.test(v)) return v.toLowerCase();
        if (/^#[0-9a-f]{8}$/i.test(v)) return v.substring(0, 7).toLowerCase();
        return '';
    }
    COLOR_DEFS.forEach(function(d) {
        var live = normalizeColor(rootStyle.getPropertyValue(keyToVar(d.key)));
        if (live) d.def = live;
    });
})();

/* Mapeo de claves legacy → nuevas (para temas guardados antes del refactor) */
const LEGACY_KEY_MAP = {
    'bg':            'winBg',
    'taskbarBg':     'surfaceDeep',
    'windowBg':      'winBg',
    'windowText':    'text',
    'titleBarStart': 'titlebarStart',
    'titleBarEnd':   'titlebarEnd',
    'titleBarText':  'titlebarText',
    'windowShadow':  'bezelDark1'
};

function migrateLegacyColors(colors) {
    if (!colors) return {};
    var out = {};
    Object.keys(colors).forEach(function(k) {
        var newKey = LEGACY_KEY_MAP[k] || k;
        if (out[newKey] === undefined) out[newKey] = colors[k];
    });
    return out;
}

var editorEl = document.getElementById('temas-editor');
var nameInput = document.getElementById('theme-name-input');
var listEl = document.getElementById('temas-list');
var statusEl = document.getElementById('temas-status');
var savedThemes = {};
var activeName = '';
/* Nombre del tema que se está editando (su nombre ORIGINAL). Si al guardar
   el usuario cambió el nombre, se renombra el original en vez de duplicar. */
var editingOriginalName = null;

/* DEFAULT de la app: se usan cuando el TEMA ACTIVO no define fondo/icono.
   - Fondo  → base-wallpaper (default de la app).
   - Icono  → '' (sin imagen → logo Win98 por defecto). */
var THEME_DEFAULT_WP = <?php echo json_encode(defaultWallpaper()); ?>;
var THEME_DEFAULT_SI = '';

/* Cookie helper: persiste qué tema usar para la interfaz activa.
   PHP lo lee en getActiveThemeForInterface() en el siguiente reload. */
function persistThemeForInterface(themeName) {
    try {
        var iface = (typeof window.getActiveInterface === 'function')
            ? window.getActiveInterface() : 'win98';
        var oneYear = 60 * 60 * 24 * 365;
        document.cookie = 'themeFor_' + iface + '=' + encodeURIComponent(themeName || '') +
                          '; path=/; max-age=' + oneYear + '; SameSite=Lax';
    } catch (_) {}
}
/* Fondo / icono GLOBAL del usuario (su baseline cuando NO hay tema activo). */
var USER_GLOBAL_WP = <?php echo json_encode(getUserWallpaper($userLabel)); ?>;
var USER_GLOBAL_SI = <?php echo json_encode(getUserStartIcon($userLabel)); ?>;

/* Pide al escritorio (padre) aplicar el fondo y el icono del tema. */
function applyThemeAssets(wallpaper, startIcon){
    if (!window.parent || window.parent === window) return;
    window.parent.postMessage({ type: 'wallpaper-changed', wallpaper: wallpaper || '' }, '*');
    window.parent.postMessage({ type: 'start-icon-changed', icon: startIcon || '' }, '*');
}

function buildEditor() {
    editorEl.innerHTML = '';
    /* Agrupar por def.group */
    var groups = {};
    var groupOrder = [];
    COLOR_DEFS.forEach(function(def) {
        var g = def.group || 'Otros';
        if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
        groups[g].push(def);
    });
    groupOrder.forEach(function(g) {
        var head = document.createElement('div');
        head.className = 'color-group-head';
        head.textContent = g;
        editorEl.appendChild(head);
        groups[g].forEach(function(def) {
            var wrap = document.createElement('div');
            wrap.className = 'color-field';
            var l = document.createElement('div');
            l.className = 'color-field-label';
            l.textContent = def.label;
            wrap.appendChild(l);
            var row = document.createElement('div');
            row.className = 'color-field-row';
            var picker = document.createElement('input');
            picker.type = 'color';
            picker.value = def.def;
            picker.dataset.key = def.key;
            picker.id = 'col-' + def.key;
            var hex = document.createElement('input');
            hex.type = 'text';
            hex.value = def.def;
            hex.maxLength = 9;
            hex.dataset.key = def.key;
            hex.id = 'hex-' + def.key;
            picker.addEventListener('input', function() {
                hex.value = picker.value;
                _autoSaveExisting();
            });
            hex.addEventListener('input', function() {
                if (/^#[0-9a-f]{6}$/i.test(hex.value)) {
                    picker.value = hex.value;
                    _autoSaveExisting();
                }
            });
            row.appendChild(picker);
            row.appendChild(hex);
            wrap.appendChild(row);
            editorEl.appendChild(wrap);
        });
    });
}

function getEditorColors() {
    var c = {};
    COLOR_DEFS.forEach(function(def) {
        c[def.key] = document.getElementById('hex-' + def.key).value;
    });
    /* fontDelta NO es un color pero viaja en el mismo objeto colors
       para que el backend lo persista sin cambios de schema. Clamp
       defensivo por si el slider está en un estado raro. */
    var fdEl = document.getElementById('theme-font-delta');
    var fd = fdEl ? parseInt(fdEl.value, 10) : 0;
    if (!isFinite(fd)) fd = 0;
    if (fd < -6) fd = -6;
    if (fd >  10) fd = 10;
    c.fontDelta = fd;
    return c;
}

function setEditorColors(colors) {
    COLOR_DEFS.forEach(function(def) {
        var v = (colors && colors[def.key]) ? colors[def.key] : def.def;
        document.getElementById('hex-' + def.key).value = v;
        if (/^#[0-9a-f]{6}$/i.test(v)) document.getElementById('col-' + def.key).value = v;
    });
    /* fontDelta: si el tema lo trae, lo metemos en el slider y lo
       aplicamos en vivo. Default 0 (no toca nada). */
    var fd = 0;
    if (colors && typeof colors.fontDelta === 'number') fd = colors.fontDelta;
    if (fd < -6) fd = -6;
    if (fd >  10) fd = 10;
    var fdEl  = document.getElementById('theme-font-delta');
    var fdOut = document.getElementById('theme-font-delta-readout');
    if (fdEl) fdEl.value = String(fd);
    if (fdOut) fdOut.textContent = (fd > 0 ? '+' : '') + fd + (fd === 0 ? '' : ' px');
    if (typeof window.setFontScaleDelta === 'function') window.setFontScaleDelta(fd);
    _propagateFontDelta(fd);
}

/* Notifica al shell padre y al iframe activo del escritorio (si lo
   hubiera) el nuevo delta. El shell, a su vez, lo retransmite a su
   app-frame para que el preview en vivo abarque todo. */
function _propagateFontDelta(d) {
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'font-scale-delta', delta: d }, '*');
        }
    } catch (_) {}
}

function resetEditor() {
    nameInput.value = '';
    var defaults = {};
    COLOR_DEFS.forEach(function(d) { defaults[d.key] = d.def; });
    setEditorColors(defaults);
    setActiveItem(null);
    editingOriginalName = null;   /* tema nuevo: no hay original que renombrar */
    statusEl.textContent = 'Nuevo tema. Pon un nombre y guarda.';
}

function setActiveItem(name) {
    document.querySelectorAll('.temas-item').forEach(function(el) {
        el.classList.toggle('active', el.dataset.name === name);
    });
}

function renderList() {
    listEl.innerHTML = '';
    var names = Object.keys(savedThemes).sort();
    if (!names.length) {
        var empty = document.createElement('div');
        empty.style.cssText = 'padding:14px;font-size:11px;color:var(--text-muted, var(--text-faint, #808080));text-align:center;';
        empty.textContent = 'No tienes temas todavía. Pulsa "+ Nuevo" para crear uno.';
        listEl.appendChild(empty);
        return;
    }
    names.forEach(function(name) {
        var t = savedThemes[name] || {};
        var c = migrateLegacyColors(t.colors || {});
        /* Cuatro colores definitorios del tema para el mini-preview.
           Si el tema no los define, caemos a los defaults Win98 silver
           para que el preview no quede vacío en temas viejos. */
        var pTitle  = c.titlebarStart || '#000080';
        var pWinBg  = c.winBg         || '#c0c0c0';
        var pAccent = c.accent        || '#000080';
        var pText   = c.text          || '#000000';

        var item = document.createElement('div');
        item.className = 'temas-item' + (name === activeName ? ' is-active' : '');
        item.dataset.name = name;

        /* Preview de paleta — 2×2 con los 4 colores. */
        var prev = document.createElement('div');
        prev.className = 'temas-item-preview';
        ['titlebarStart','winBg','accent','text'].forEach(function(k){
            var sw = document.createElement('span');
            sw.style.background = ({
                titlebarStart: pTitle,
                winBg:         pWinBg,
                accent:        pAccent,
                text:          pText
            })[k];
            prev.appendChild(sw);
        });
        item.appendChild(prev);

        var n = document.createElement('span');
        n.className = 'temas-item-name';
        n.textContent = name;
        item.appendChild(n);

        if (t.downloaded) {
            var dl = document.createElement('img');
            dl.className = 'temas-item-dl';
            dl.src = '../../assets/img/appIcons/downloadIcon.png';
            dl.alt = '';
            dl.title = 'Descargado de la biblioteca';
            item.appendChild(dl);
        }
        if (t.public) {
            var pub = document.createElement('img');
            pub.className = 'temas-item-pub';
            pub.src = '../../assets/img/appIcons/booksIcon.png';
            pub.alt = '';
            pub.title = 'Publicado en la biblioteca';
            item.appendChild(pub);
        }
        if (name === activeName) {
            var badge = document.createElement('span');
            badge.className = 'temas-item-badge';
            badge.textContent = 'Activo';
            item.appendChild(badge);
        }
        item.addEventListener('click', function() {
            nameInput.value = name;
            setEditorColors(migrateLegacyColors(savedThemes[name].colors || {}));
            setActiveItem(name);
            editingOriginalName = name;
            statusEl.textContent = 'Editando "' + name + '"';
        });
        item.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            showThemeCtxMenu(e.clientX, e.clientY, name);
        });
        listEl.appendChild(item);
    });
}

/* Menú contextual de un tema (publicar/quitar + eliminar) */
var _themeCtxMenu = null;
function hideThemeCtxMenu(){ if(_themeCtxMenu){ _themeCtxMenu.remove(); _themeCtxMenu = null; } }
function showThemeCtxMenu(x, y, name){
    hideThemeCtxMenu();
    var t = savedThemes[name] || {};
    var isPub = !!t.public;
    var isDownloaded = !!t.downloaded;
    var menu = document.createElement('div');
    menu.className = 'temas-ctx-menu';

    var opts = [];
    /* Los temas descargados de la biblioteca NO se pueden publicar */
    if (!isDownloaded) {
        opts.push({ label: '<img src="../../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">' + (isPub ? 'Quitar de la biblioteca' : 'Publicar en la biblioteca'),
                    action: function(){ setThemePublic(name, !isPub); } });
    }
    opts.push({ label: '📋 Crear copia', action: function(){ duplicateTheme(name); } });
    opts.push({ label: '<img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Eliminar', danger: true, action: function(){ deleteTheme(name); } });
    opts.forEach(function(o){
        var el = document.createElement('div');
        el.className = 'temas-ctx-opt' + (o.danger ? ' danger' : '');
        el.innerHTML = o.label;
        el.addEventListener('click', function(){ hideThemeCtxMenu(); o.action(); });
        menu.appendChild(el);
    });

    document.body.appendChild(menu);
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    menu.style.left = Math.min(x, window.innerWidth  - mw - 4) + 'px';
    menu.style.top  = Math.min(y, window.innerHeight - mh - 4) + 'px';
    _themeCtxMenu = menu;
}
document.addEventListener('click', hideThemeCtxMenu);
document.addEventListener('contextmenu', function(e){
    if(!e.target.closest('.temas-item, .lib-card')) hideThemeCtxMenu();
});

/* Crear una copia del tema (nuevo tema ORIGINAL → se puede publicar) */
function duplicateTheme(name){
    var src = savedThemes[name];
    if(!src) return;
    var colors = migrateLegacyColors(src.colors || {});
    var copyName = (name + ' copia').slice(0, 30);
    if (savedThemes[copyName]) {
        var base = (name + ' copia').slice(0, 27), n = 2;
        while (savedThemes[copyName] && n < 99) { copyName = base + ' ' + n; n++; }
    }
    fetch('../../assets/themes/api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: copyName, colors: colors })   /* sin downloaded → original */
    }).then(function(r){ return r.json(); })
      .then(function(d){
          if(!d || d.error){ statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
          statusEl.textContent = 'Copia "' + copyName + '" creada.';
          editingOriginalName = copyName;
          loadThemes(function(){
              nameInput.value = copyName;
              setEditorColors(colors);
              setActiveItem(copyName);
          });
      });
}

/* Eliminar un tema (confirmación Win98 + borrado en servidor) */
function deleteTheme(name){
    if(!savedThemes[name]) return;
    window._w98ConfirmDeleteTheme(name, function(){
        fetch('../../assets/themes/api.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name })
        }).then(function(r){ return r.json(); })
          .then(function(d){
              if(!d || d.error){ statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
              statusEl.textContent = 'Tema "' + name + '" eliminado.';
              if(nameInput.value.trim() === name) resetEditor();
              loadThemes();
          });
    });
}

function setThemePublic(name, makePublic){
    if(!savedThemes[name]) return;
    fetch('../../assets/themes/api.php?action=set-public', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name, public: makePublic })
    }).then(function(r){ return r.json(); })
      .then(function(d){
          if(!d || d.error){ statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
          savedThemes[name].public = d.public;
          renderList();
          statusEl.textContent = d.public
              ? 'Tema "' + name + '" publicado en la biblioteca.'
              : 'Tema "' + name + '" quitado de la biblioteca.';
      });
}

function loadThemes(cb) {
    fetch('../../assets/themes/api.php?action=get')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) return;
            savedThemes = d.themes || {};
            activeName  = d.active || '';
            renderList();
            /* Reflejar en Personalización el fondo/icono EFECTIVO:
               con tema → su asset o el default; sin tema → el global. */
            var act = activeName && savedThemes[activeName] ? savedThemes[activeName] : null;
            var prevWp = act ? (act.wallpaper || THEME_DEFAULT_WP) : USER_GLOBAL_WP;
            var prevSi = act ? (act.startIcon || THEME_DEFAULT_SI) : USER_GLOBAL_SI;
            if (window._setWpPreview) window._setWpPreview(prevWp);
            if (window._setSiPreview) window._setSiPreview(prevSi);
            /* Si el caller pasa callback, él decide qué mostrar en el editor.
               Si no, mostramos el tema activo del usuario (comportamiento normal). */
            if (typeof cb === 'function') { cb(); return; }
            if (activeName && savedThemes[activeName]) {
                nameInput.value = activeName;
                setEditorColors(migrateLegacyColors(savedThemes[activeName].colors || {}));
                setActiveItem(activeName);
                editingOriginalName = activeName;
                statusEl.textContent = 'Editando "' + activeName + '" (activo)';
            }
        });
}

document.getElementById('temas-new').addEventListener('click', resetEditor);
document.getElementById('temas-deactivate').addEventListener('click', function() {
    persistThemeForInterface('');
    fetch('../../assets/themes/api.php?action=set-active', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: '' })
    }).then(function(r) { return r.json(); })
      .then(function(d) {
          if (d && d.ok) {
              activeName = '';
              renderList();
              applyLiveTheme('', '');
              /* Sin tema → fontDelta vuelve a 0 (tamaño base). */
              if (typeof window.setFontScaleDelta === 'function') window.setFontScaleDelta(0);
              _propagateFontDelta(0);
              /* Sin tema → fondo/icono GLOBAL del usuario (su baseline) */
              applyThemeAssets(USER_GLOBAL_WP, USER_GLOBAL_SI);
              if (window._setWpPreview) window._setWpPreview(USER_GLOBAL_WP);
              if (window._setSiPreview) window._setSiPreview(USER_GLOBAL_SI);
              statusEl.textContent = 'Tema desactivado.';
          }
      });
});

document.getElementById('theme-save').addEventListener('click', function() {
    var name = nameInput.value.trim();
    if (!name) { statusEl.textContent = 'Falta el nombre.'; return; }
    var colors = getEditorColors();
    /* oldName: si estamos editando un tema existente y cambió el nombre,
       el backend renombra el original en vez de crear un duplicado. */
    var payload = { name: name, colors: colors };
    if (editingOriginalName) payload.oldName = editingOriginalName;
    var renamed = !!(editingOriginalName && editingOriginalName !== name);
    fetch('../../assets/themes/api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function(r) { return r.json(); })
      .then(function(d) {
          if (!d || d.error) { statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
          /* Si renombramos el tema activo, refrescar el activeName */
          if (renamed && activeName === editingOriginalName) activeName = name;
          editingOriginalName = name;
          statusEl.textContent = renamed
              ? 'Tema renombrado a "' + name + '".'
              : 'Tema "' + name + '" guardado.';
          /* Si el tema guardado es el activo, re-aplicar con la cssPath
             que devuelve api.php (apunta a uploads/themes/, donde viven
             ahora los CSS regenerados). Construirla a mano apuntando a
             assets/themes/ daba 404 → tema activo se veía como default
             hasta recargar. */
          if (name === activeName && d.cssPath) {
              applyLiveTheme(d.className, d.cssPath);
          }
          loadThemes(function(){
              nameInput.value = name;
              if (savedThemes[name]) setEditorColors(migrateLegacyColors(savedThemes[name].colors || {}));
              setActiveItem(name === activeName ? activeName : name);
          });
      });
});

/* Slider de tamaño de fuente: live preview mientras el usuario
   arrastra. Persiste automáticamente si el tema ya existía. */
(function wireFontSlider() {
    var sl  = document.getElementById('theme-font-delta');
    var out = document.getElementById('theme-font-delta-readout');
    if (!sl) return;
    sl.addEventListener('input', function() {
        var d = parseInt(sl.value, 10) || 0;
        if (out) out.textContent = (d > 0 ? '+' : '') + d + (d === 0 ? '' : ' px');
        if (typeof window.setFontScaleDelta === 'function') window.setFontScaleDelta(d);
        _propagateFontDelta(d);
        _autoSaveExisting();
    });
})();

/* ── AUTO-SAVE de temas existentes ──
   Cualquier cambio (color, hex, slider de fuente, nombre) en un tema
   YA persistido se guarda automáticamente sin tener que pulsar
   "Guardar". Se identifica por `editingOriginalName`: si es null es
   un tema nuevo todavía sin nombre → flujo manual con el botón. */
var _autoSaveTimer = null;
var _autoSaveInFlight = false;
function _autoSaveExisting() {
    if (!editingOriginalName) return;
    if (_autoSaveTimer) clearTimeout(_autoSaveTimer);
    _autoSaveTimer = setTimeout(_autoSaveFlush, 400);
}
function _autoSaveFlush() {
    _autoSaveTimer = null;
    if (_autoSaveInFlight) {
        _autoSaveTimer = setTimeout(_autoSaveFlush, 200);
        return;
    }
    if (!editingOriginalName) return;
    var name = nameInput.value.trim();
    if (!name) return;
    var colors  = getEditorColors();
    var oldName = editingOriginalName;
    var renamed = (oldName !== name);
    _autoSaveInFlight = true;
    fetch('../../assets/themes/api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name, colors: colors, oldName: oldName })
    }).then(function(r){ return r.json(); })
      .then(function(d){
          _autoSaveInFlight = false;
          if (!d || d.error) {
              statusEl.textContent = (d && d.error) ? d.error : 'Error guardando.';
              return;
          }
          if (renamed) {
              if (savedThemes[oldName]) {
                  savedThemes[name] = savedThemes[oldName];
                  delete savedThemes[oldName];
              } else {
                  savedThemes[name] = savedThemes[name] || {};
              }
              if (activeName === oldName) activeName = name;
              editingOriginalName = name;
          } else {
              savedThemes[name] = savedThemes[name] || {};
          }
          savedThemes[name].colors = colors;
          if (name === activeName && d.cssPath) {
              applyLiveTheme(d.className, d.cssPath);
          }
          statusEl.textContent = 'Guardado automáticamente.';
      })
      .catch(function(){
          _autoSaveInFlight = false;
          statusEl.textContent = 'Error de red al guardar.';
      });
}

/* Auto-save al editar el nombre del tema (debounce largo para no
   renombrar mientras el usuario aún escribe). */
(function wireNameAutoSave(){
    if (!nameInput) return;
    var nameTimer = null;
    nameInput.addEventListener('input', function(){
        if (!editingOriginalName) return;
        if (nameTimer) clearTimeout(nameTimer);
        nameTimer = setTimeout(_autoSaveExisting, 900);
    });
    nameInput.addEventListener('blur',  function(){ if (editingOriginalName) _autoSaveExisting(); });
    nameInput.addEventListener('change',function(){ if (editingOriginalName) _autoSaveExisting(); });
})();

document.getElementById('theme-activate').addEventListener('click', function() {
    var name = nameInput.value.trim();
    if (!name) { statusEl.textContent = 'Selecciona un tema primero.'; return; }
    if (!savedThemes[name]) { statusEl.textContent = 'Guarda el tema antes de activarlo.'; return; }
    persistThemeForInterface(name);
    fetch('../../assets/themes/api.php?action=set-active', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name })
    }).then(function(r) { return r.json(); })
      .then(function(d) {
          if (!d || d.error) { statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
          activeName = name;
          renderList();
          /* d.cssPath viene de api.php apuntando a uploads/themes/.
             Antes hardcodeábamos assets/themes/ → 404. */
          applyLiveTheme(d.className, d.cssPath);
          /* Al activar, el fontDelta del tema toma efecto inmediato en
             el shell + la app abierta. Si no lo guarda, 0 (base). */
          var fd = 0;
          if (savedThemes[name] && savedThemes[name].colors && typeof savedThemes[name].colors.fontDelta === 'number') {
              fd = savedThemes[name].colors.fontDelta;
          }
          if (typeof window.setFontScaleDelta === 'function') window.setFontScaleDelta(fd);
          _propagateFontDelta(fd);
          /* Aplicar el fondo y el icono vinculados al tema (o el DEFAULT si no tiene) */
          applyThemeAssets(d.wallpaper || THEME_DEFAULT_WP, d.startIcon || THEME_DEFAULT_SI);
          /* Reflejar en Personalización los assets efectivos del tema activo */
          if (savedThemes[name]) { savedThemes[name].wallpaper = d.wallpaper || ''; savedThemes[name].startIcon = d.startIcon || ''; }
          if (window._setWpPreview) window._setWpPreview(d.wallpaper || THEME_DEFAULT_WP);
          if (window._setSiPreview) window._setSiPreview(d.startIcon || THEME_DEFAULT_SI);
          statusEl.textContent = 'Tema "' + name + '" activado.';
      });
});

function applyLiveTheme(className, basePath) {
    /* Local (la propia iframe de Temas-mobile, que vive en apps/mobile/):
       sube DOS niveles para llegar a la raíz del proyecto. */
    var localHref = basePath ? '../../' + basePath : '';
    applyToDocLocal(document, className, localHref);
    /* Padre: que se encargue de su body + propagar a sus iframes */
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'theme-activated', className: className, cssBasePath: basePath }, '*');
    }
}

function applyToDocLocal(doc, className, cssHref) {
    if (!doc || !doc.body) return;
    var body = doc.body;
    /* Preservar clases estructurales (mh-body es CRÍTICA en la app
       móvil — define el layout). El filtro elimina cualquier clase
       que termine en "-{userLabel}" (el patrón themeCssClassName) para
       quitar el tema anterior sin tocar otras clases personalizadas. */
    var userLabelSlug = <?php echo json_encode(preg_replace('/[^A-Za-z0-9_-]/', '', $userLabel)); ?>;
    var themeClassRe  = new RegExp('-' + userLabelSlug + '$');
    var keep = (body.className || '').split(/\s+/).filter(function(c) {
        return c && !themeClassRe.test(c);
    });
    if (className) keep.push(className);
    body.className = keep.join(' ');
    var existing = doc.getElementById('active-theme-link');
    if (cssHref) {
        var bust = (cssHref.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
        if (existing) {
            existing.href = cssHref + bust;
        } else {
            var link = doc.createElement('link');
            link.rel = 'stylesheet';
            link.id  = 'active-theme-link';
            link.href = cssHref + bust;
            doc.head.appendChild(link);
        }
    } else if (existing) {
        existing.remove();
    }
}

/* (Eliminar tema → ahora vía clic derecho en la lista: deleteTheme) */

/* =========================================================
   EXPORTAR / IMPORTAR temas (JSON)
   - Export: descarga el tema actual del editor como JSON.
   - Import: carga un JSON al editor; el usuario decide si guardar.
   El formato es self-contained y portable entre usuarios/instalaciones. */
function sanitizeFilename(s) {
    return (s || 'theme').replace(/[^A-Za-z0-9_-]+/g, '_').slice(0, 40);
}

document.getElementById('theme-export').addEventListener('click', function() {
    var name = nameInput.value.trim() || 'tema';
    /* Embebemos la interfaz activa en el JSON — al reimportar este
       fichero lo dirigimos a ESA interfaz (no a la actual). */
    var iface = (typeof window.getActiveInterface === 'function')
        ? window.getActiveInterface() : 'win98';
    var payload = {
        format:     'melon-theme',
        version:    2,
        name:       name,
        interface:  iface,
        exportedAt: new Date().toISOString(),
        colors:     getEditorColors()
    };
    var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url;
    a.download = sanitizeFilename(name) + '.melon-theme.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function() { URL.revokeObjectURL(url); }, 1500);
    statusEl.textContent = '✔ "' + name + '" descargado (' + iface + ').';
});

document.getElementById('theme-import').addEventListener('click', function() {
    document.getElementById('theme-import-file').click();
});
document.getElementById('theme-import-file').addEventListener('change', function(e) {
    var file = e.target.files && e.target.files[0];
    e.target.value = '';   // resetear para permitir reimportar el mismo fichero
    if (!file) return;
    if (file.size > 100 * 1024) {
        statusEl.textContent = '✗ Fichero demasiado grande (>100KB).';
        return;
    }
    var reader = new FileReader();
    reader.onload = function() {
        var data;
        try { data = JSON.parse(reader.result); }
        catch (err) { statusEl.textContent = '✗ JSON inválido.'; return; }
        if (!data || typeof data !== 'object' || !data.colors || typeof data.colors !== 'object') {
            statusEl.textContent = '✗ Formato de tema desconocido.'; return;
        }
        var colors = migrateLegacyColors(data.colors);
        /* Filtrar a las claves del editor + validar hex */
        var clean = {};
        var bad = 0;
        COLOR_DEFS.forEach(function(def) {
            var v = colors[def.key];
            if (typeof v === 'string' && /^#[0-9a-f]{3,8}$/i.test(v)) clean[def.key] = v;
            else if (v !== undefined) bad++;
        });
        /* Nombre: usa el del fichero o cae al filename sin extensión.
           Si ya existe, añadimos sufijo " (importado)" para no pisar. */
        var nameRaw = (data.name && typeof data.name === 'string')
                      ? data.name
                      : file.name.replace(/\.melon-theme\.json$|\.json$/i, '');
        var name = String(nameRaw).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 30) || 'Importado';
        if (savedThemes[name]) {
            var base = name, n = 2;
            while (savedThemes[name] && name.length < 30) {
                name = (base + '_' + n).slice(0, 30); n++;
                if (n > 99) break;
            }
        }
        nameInput.value = name;
        setEditorColors(clean);
        setActiveItem(null);

        /* Auto-save + auto-activate */
        /* Determinar interfaz destino: la del JSON si la trae (v2+), o la
           actual si no (legacy). Solo auto-activamos si coincide. */
        var currentIface = (typeof window.getActiveInterface === 'function')
            ? window.getActiveInterface() : 'win98';
        var targetIface  = (typeof data.interface === 'string' && data.interface)
            ? data.interface.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 30)
            : currentIface;
        var samePack = (targetIface === currentIface);

        statusEl.textContent = 'Importando "' + name + '"…';
        fetch('../../assets/themes/api.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name, colors: clean, interface: targetIface })
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              if (!d || d.error) throw new Error((d && d.error) || 'Error al guardar');
              if (!samePack) {
                  loadThemes();
                  statusEl.textContent = '✔ "' + name + '" importado en interfaz "'
                      + targetIface + '". Cámbiate a esa interfaz para usarlo'
                      + (bad ? ' (' + bad + ' valores inválidos descartados)' : '') + '.';
                  return;
              }
              persistThemeForInterface(name);
              return fetch('../../assets/themes/api.php?action=set-active', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ name: name })
              }).then(function(r) { return r.json(); }).then(function(d2) {
                  if (!d2 || d2.error) throw new Error((d2 && d2.error) || 'Error al activar');
                  activeName = name;
                  loadThemes();
                  applyLiveTheme(d.className, d.cssPath);
                  statusEl.textContent = '✔ "' + name + '" importado y activado'
                      + (bad ? ' (' + bad + ' valores inválidos descartados)' : '') + '.';
              });
          })
          .catch(function(err) { statusEl.textContent = '✗ ' + err.message; });
    };
    reader.onerror = function() { statusEl.textContent = '✗ Error leyendo el fichero.'; };
    reader.readAsText(file);
});

buildEditor();
resetEditor();
loadThemes();

/* =========================================================
   TABS DE LA SIDEBAR (Mis temas / Personalización)
========================================================= */
(function() {
    var tabs  = document.querySelectorAll('.temas-tab-btn');
    var panes = {
        themes:      document.getElementById('temas-pane-themes'),
        personalize: document.getElementById('temas-pane-personalize'),
        library:     document.getElementById('temas-pane-library')
    };
    var editorEls = ['temas-toolbar', 'temas-editor', 'temas-status'];
    var libEl     = document.getElementById('temas-library');
    var persEl    = document.getElementById('temas-personalize');

    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            tabs.forEach(function(b) { b.classList.remove('active'); });
            t.classList.add('active');
            var which = t.dataset.tab;
            Object.keys(panes).forEach(function(k) {
                if (k === which) panes[k].removeAttribute('hidden');
                else             panes[k].setAttribute('hidden', '');
            });
            /* El main panel muestra:
                 - editor de colores (themes)
                 - grid de biblioteca (library)
                 - vista de personalización (personalize, NUEVO) */
            var lib = (which === 'library');
            var pers = (which === 'personalize');
            editorEls.forEach(function(id){
                var el = document.getElementById(id);
                if (el) el.style.display = (lib || pers) ? 'none' : '';
            });
            libEl.hidden  = !lib;
            persEl.hidden = !pers;
            if (lib)  loadLibrary();
            if (pers) loadPersonalize();
        });
    });
})();

/* =========================================================
   PERSONALIZACIÓN — Temas / Haros / Mascotas
   --------------------------------------------------------
   Vista que reemplaza al editor de colores cuando se entra
   en la pestaña "Personalización". Muestra 3 secciones con
   lo que el usuario tiene desbloqueado (base + comprado en
   tienda) y permite cambiar el activo con un click.
========================================================= */
function loadPersonalize() {
    /* Las 3 secciones (Interfaces / Haros / Mascotas) salen del mismo
       endpoint. "Interfaces" son skins/apariencias de la app — items
       con categoria='temas' en tienda_items. Los TEMAS DE COLORES del
       usuario (savedThemes) son otra cosa distinta y viven en el
       sidebar "Mis temas". */
    fetch('../../assets/personalize/api.php?action=inventory', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            renderPersInventory('pers-haros-grid',   d.haros,      d.activeHaro,      'haro');
            /* Mascotas: placeholder "Coming soon..." — no se llena. */
            renderInterfacePacks();
            renderFontPacks();
            renderIconPacks();
        })
        .catch(function(){
            document.getElementById('pers-haros-grid').innerHTML   = '';
            renderInterfacePacks();
            renderFontPacks();
        });
}

/* Render del grid de interfaces. Click → setActiveInterface (cookie +
   reload del top). Es la misma función conceptualmente que en desktop
   pero adaptada a mobile (paths con ../../). */
function renderInterfacePacks() {
    var grid = document.getElementById('pers-themes-grid');
    if (!grid) return;
    var packs = (window.__INTERFACE_PACKS__ || []);
    var active = (typeof window.getActiveInterface === 'function')
        ? window.getActiveInterface() : 'win98';
    if (!packs.length) {
        grid.innerHTML = '<p style="font-size:11px;color:var(--text-faint);">No hay interfaces instaladas.</p>';
        return;
    }
    grid.innerHTML = packs.map(function(p) {
        var isActive = (p.name === active);
        var iconHtml;
        if (p.preview) {
            iconHtml = '<img src="../../' + p.preview + '" alt="" style="max-width:100%;max-height:100%;object-fit:contain;image-rendering:pixelated;">';
        } else {
            iconHtml = '<img src="../../assets/img/appIcons/interfaceIcon.png" alt="" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">';
        }
        return '<div class="pers-item' + (isActive ? ' active' : '') + '" data-iface="' + p.name + '" title="' + (p.description || '') + '">' +
            '<div class="pers-item-icon">' + iconHtml + '</div>' +
            '<div class="pers-item-name">' + (p.label || p.name) + '</div>' +
            (isActive ? '<div class="pers-item-badge">ACTIVA</div>' : '') +
        '</div>';
    }).join('');
    grid.querySelectorAll('[data-iface]').forEach(function(card) {
        card.addEventListener('click', function() {
            var name = card.dataset.iface;
            /* applyInterfacePack viene de interface-loader.js. NO usamos
               setActiveInterface porque ese nombre lo ocupa una función
               local que POSTea al endpoint viejo y devuelve 400. */
            if (typeof window.applyInterfacePack !== 'function') return;
            try {
                var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
                topStorage.setItem('temas-restore-tab', 'personalize');
            } catch (_) {}
            /* PRIMERO persistimos la slug en BD — sin esto, el sync
               server-side de mobile.php (cookie ← BD) sobrescribiría la
               cookie nueva con la vieja en el siguiente reload y el
               cambio de interfaz no surtiría efecto. Mismo patrón que
               desktop (apps/temas.php). El reload se dispara en
               `finally` para que también ocurra si el POST falla. */
            fetch('../../assets/personalize/api.php?action=set-active-interface-slug', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ slug: name })
            }).catch(function(){}).finally(function() {
                window.applyInterfacePack(name);
            });
        });
    });
}

/* Fuentes que tienes (como renderFontPacks de apps/temas.php): las de
   serie, las compradas en la tienda y las que vienen con una interfaz
   tuya; cada tarjeta con su fuente (mh-fuente-muestra la libra de la
   puesta en la página). Tocar → set-font para la interfaz activa y
   recarga, como las interfaces (mobile.php vuelve a Personalización). */
function renderFontPacks() {
    var grid = document.getElementById('pers-fonts-grid');
    if (!grid) return;
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    fetch('../../assets/personalize/api.php?action=fonts', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            var mias = (d.fuentes || []).filter(function(f){ return f.tiene; });
            mias.forEach(function(f){
                if (!f.url || document.querySelector('link[data-fuente="' + f.slug + '"]')) return;
                var l = document.createElement('link');
                l.rel = 'stylesheet'; l.href = f.url; l.dataset.fuente = f.slug;
                document.head.appendChild(l);
            });
            grid.innerHTML = mias.map(function(f){
                var on = f.slug === d.activa;
                var fam = 'font-family:&quot;' + esc(f.familia) + '&quot;, sans-serif;';
                return '<div class="pers-item pers-fuente' + (on ? ' active' : '') + '" data-fuente="' + esc(f.slug) + '">' +
                    '<div class="pers-item-icon mh-fuente-muestra" style="' + fam + '">Aa</div>' +
                    '<div class="pers-item-name mh-fuente-muestra" style="' + fam + '">' + esc(f.nombre) + '</div>' +
                    (on ? '<div class="pers-item-badge">ACTIVA</div>' : '') +
                '</div>';
            }).join('');
            grid.querySelectorAll('[data-fuente]').forEach(function(card){
                card.addEventListener('click', function(){
                    if (card.classList.contains('active')) return;
                    try {
                        var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
                        topStorage.setItem('temas-restore-tab', 'personalize');
                    } catch (_) {}
                    fetch('../../assets/personalize/api.php?action=set-font', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ slug: card.dataset.fuente, interface: d.interfaz })
                    }).catch(function(){}).finally(function() {
                        try { window.top.location.reload(); }
                        catch (_) { location.reload(); }
                    });
                });
            });
        })
        .catch(function(){ grid.innerHTML = ''; });
}

/* Iconos: packs detectados desde el filesystem por PHP. Click selecciona
   y guarda en localStorage; recarga para que icon-pack.js aplique. */
function renderIconPacks() {
    var grid = document.getElementById('pers-icons-grid');
    if (!grid) return;
    var packs = (window.__ICON_PACKS__ || []);
    var active = '';
    /* Lee del namespace por interfaz para mostrar el pack activo correcto. */
    var iface = (typeof window.getActiveInterface === 'function') ? window.getActiveInterface() : 'win98';
    try {
        active = localStorage.getItem('iconPack:' + iface)
              || localStorage.getItem('iconPack')
              || 'Melon';
    } catch (_) { active = 'Melon'; }
    if (!packs.length) {
        grid.innerHTML = '<p style="font-size:11px;color:var(--text-faint);">No hay packs de iconos disponibles.</p>';
        return;
    }
    grid.innerHTML = packs.map(function(p) {
        var isActive = (p.name === active);
        return '<div class="pers-item' + (isActive ? ' active' : '') + '" data-pack="' + p.name + '">' +
            '<div class="pers-item-icon">' +
                (p.preview
                    ? '<img src="' + p.preview + '" alt="" style="image-rendering:pixelated;">'
                    : '<span>📦</span>') +
            '</div>' +
            '<div class="pers-item-name">' + p.name + '</div>' +
            (isActive ? '<div class="pers-item-badge">ACTIVO</div>' : '') +
        '</div>';
    }).join('');
    grid.querySelectorAll('[data-pack]').forEach(function(card) {
        card.addEventListener('click', function() {
            var name = card.dataset.pack;
            try {
                /* Guarda en namespace por interfaz para que cada interfaz
                   recuerde su propio icon pack. */
                var iface = (typeof window.getActiveInterface === 'function')
                    ? window.getActiveInterface() : 'win98';
                localStorage.setItem('iconPack:' + iface, name);
                localStorage.setItem('iconPack', name);  /* retrocompat */
                /* Flag para que mobile.php reabra Temas y temas-mobile
                   active el tab Personalización tras el reload. */
                var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
                topStorage.setItem('temas-restore-tab', 'personalize');
            } catch (_) {}
            try { window.top.location.reload(); }
            catch (_) { location.reload(); }
        });
    });
}

function renderPersInventory(gridId, items, activeId, kind) {
    var grid = document.getElementById(gridId);
    grid.innerHTML = '';
    if (!items || !items.length) return;
    items.forEach(function(it){
        /* Para haros el icono es el PNG del último frame del gif. Para
           el resto se usa el emoji del campo `icono`. */
        var iconHtml;
        if (kind === 'haro' && it.slug) {
            /* Convención: PNG curado del haro en assets/img/haro/
               {slug}Haro-preview.png. Para haros antiguos sin curated,
               cae al último frame del gif (assets/vids/{slug}Haro-last.png). */
            iconHtml = '<img'
                + ' src="../../assets/img/haro/' + it.slug + 'Haro-preview.png"'
                + ' onerror="this.onerror=null;this.src=\'../../assets/vids/' + it.slug + 'Haro-last.png\';"'
                + ' alt=""'
                + ' style="max-width:100%;max-height:100%;object-fit:contain;">';
        } else {
            iconHtml = it.icono || '◽';
        }
        grid.appendChild(buildPersItem({
            id:     it.id,
            name:   it.nombre,
            icon:   iconHtml,
            base:   it.isBase,
            active: activeId === it.id,
            onClick: function(){
                if (kind === 'interface') setActiveInterface(it);
                if (kind === 'haro')      setActiveHaro(it);
                if (kind === 'mascot')    setActiveMascot(it);
            }
        }));
    });
}

function setActiveInterface(item) {
    fetch('../../assets/personalize/api.php?action=set-active-interface', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ itemId: item.id })
    })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            loadPersonalize();
        })
        .catch(function(){});
}

function buildPersItem(opts) {
    var el = document.createElement('div');
    el.className = 'pers-item' + (opts.active ? ' active' : '');
    el.dataset.id = opts.id;
    var icon = document.createElement('div');
    icon.className = 'pers-item-icon';
    icon.innerHTML = opts.icon || '';
    el.appendChild(icon);
    var name = document.createElement('div');
    name.className = 'pers-item-name';
    name.textContent = opts.name || '';
    el.appendChild(name);
    if (opts.base) {
        var b = document.createElement('div');
        b.className = 'pers-item-badge';
        b.textContent = 'BASE';
        el.appendChild(b);
    }
    el.addEventListener('click', function(){
        if (typeof opts.onClick === 'function') opts.onClick();
    });
    return el;
}

function setActiveHaro(item) {
    fetch('../../assets/personalize/api.php?action=set-active-haro', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ itemId: item.id })
    })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            /* Aplicación al instante en el escritorio padre: pasa el
               nuevo slug y el notifSystem cambia sus URLs sin recargar. */
            var slug = d.slug || item.slug || '';
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'haro-changed', slug: slug }, '*');
            }
            loadPersonalize();
        })
        .catch(function(){});
}

function setActiveMascot(item) {
    fetch('../../assets/personalize/api.php?action=set-active-mascot', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ itemId: item.id })
    })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            if (typeof window.applyMascotSlug === 'function') {
                window.applyMascotSlug(item.slug || '');
            }
            loadPersonalize();
        })
        .catch(function(){});
}

/* =========================================================
   BIBLIOTECA DE TEMAS (públicos de todos los usuarios)
========================================================= */
var OWN_USER_KEY = <?php echo json_encode($userKey); ?>;

function _libColor(colors, key, def){
    var v = colors && colors[key];
    return (typeof v === 'string' && /^#[0-9a-f]{3,8}$/i.test(v)) ? v : def;
}

/* Mini-maqueta de una ventana Win98 con los colores del tema */
function buildThemePreview(colors){
    var c = migrateLegacyColors(colors || {});
    var winBg   = _libColor(c, 'winBg', '#c0c0c0');
    var bodyBg  = _libColor(c, 'winBodyBg', winBg);
    var tbStart = _libColor(c, 'titlebarStart', '#000080');
    var tbEnd   = _libColor(c, 'titlebarEnd', '#1084d0');
    var tbText  = _libColor(c, 'titlebarText', '#ffffff');
    var accent  = _libColor(c, 'accent', '#000080');
    var accentT = _libColor(c, 'accentText', '#ffffff');
    var btnBg   = _libColor(c, 'btnBg', '#c0c0c0');
    var btnText = _libColor(c, 'btnText', '#000000');
    var text    = _libColor(c, 'text', '#000000');
    var inset   = _libColor(c, 'insetBg', '#808080');
    var border  = _libColor(c, 'border', '#808080');
    return '' +
    '<div class="lib-win" style="background:' + winBg + ';border-color:' + border + ';">' +
        '<div class="lib-win-tb" style="background:linear-gradient(to right,' + tbStart + ',' + tbEnd + ');color:' + tbText + ';">' +
            '<span>Aa</span>' +
            '<span class="lib-win-tb-dots">' +
                '<i style="background:' + btnBg + ';"></i>' +
                '<i style="background:' + btnBg + ';"></i>' +
            '</span>' +
        '</div>' +
        '<div class="lib-win-body" style="background:' + bodyBg + ';">' +
            '<div class="lib-win-row" style="background:' + inset + ';width:90%;"></div>' +
            '<div class="lib-win-row" style="background:' + inset + ';width:65%;"></div>' +
            '<div class="lib-win-btns">' +
                '<span class="lib-win-btn" style="background:' + btnBg + ';color:' + btnText + ';border:1px solid ' + border + ';">Ok</span>' +
                '<span class="lib-win-btn" style="background:' + accent + ';color:' + accentT + ';">★</span>' +
            '</div>' +
        '</div>' +
    '</div>';
}

function loadLibrary(){
    var grid = document.getElementById('temas-library-grid');
    grid.innerHTML = '<div id="temas-library-empty">Cargando…</div>';
    fetch('../../assets/themes/api.php?action=library')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(!d || !d.ok || !Array.isArray(d.items) || !d.items.length){
                grid.innerHTML = '<div id="temas-library-empty">Aún no hay temas publicados.<br>Publica uno desde «Mis temas».</div>';
                return;
            }
            grid.innerHTML = '';
            d.items.forEach(function(it){
                var card = document.createElement('div');
                card.className = 'lib-card';

                var prev = document.createElement('div');
                prev.className = 'lib-preview';
                prev.title = 'Probar este tema';
                /* En móvil mostramos SOLO una ventana de prueba con
                   los colores del tema (sin wallpaper / desktopBg).
                   Así se ve directamente cómo quedan las superficies
                   y controles aunque el tema no traiga fondo. */
                prev.innerHTML = buildThemePreview(it.colors);
                prev.addEventListener('click', function(){ tryLibraryTheme(it); });
                card.appendChild(prev);

                var nm = document.createElement('div');
                nm.className = 'lib-name';
                nm.textContent = it.name;
                card.appendChild(nm);

                var author = document.createElement('div');
                author.className = 'lib-author';
                author.title = 'Ver el perfil de ' + it.label;
                var imgHtml = it.image
                    ? '<span class="lib-author-av" data-marco-user="' + escapeHtml(it.userKey || '') + '"><img class="lib-author-img" src="../../' + it.image + '" alt=""></span>'
                    : '<span class="lib-author-ph"><img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span>';
                author.innerHTML = imgHtml + '<span class="lib-author-name">' + escapeHtml(it.label) + '</span>';
                author.addEventListener('click', function(e){
                    e.stopPropagation();
                    window.parent.postMessage({ type: 'open-profile', userKey: it.userKey }, '*');
                });
                card.appendChild(author);

                /* Clic derecho → si el tema es MÍO, opción de quitarlo de la biblioteca */
                if(it.userKey === OWN_USER_KEY){
                    card.addEventListener('contextmenu', function(e){
                        e.preventDefault();
                        showLibraryCtxMenu(e.clientX, e.clientY, it);
                    });
                }

                grid.appendChild(card);
            });
        })
        .catch(function(){
            grid.innerHTML = '<div id="temas-library-empty">✗ No se pudo cargar la biblioteca.</div>';
        });
}

/* Clic derecho sobre una tarjeta de la biblioteca que es MÍA → quitarla */
function showLibraryCtxMenu(x, y, it){
    hideThemeCtxMenu();
    var menu = document.createElement('div');
    menu.className = 'temas-ctx-menu';
    var el = document.createElement('div');
    el.className = 'temas-ctx-opt danger';
    el.innerHTML = '<img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Quitar de la biblioteca';
    el.addEventListener('click', function(){
        hideThemeCtxMenu();
        fetch('../../assets/themes/api.php?action=set-public', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: it.name, public: false })
        }).then(function(r){ return r.json(); })
          .then(function(d){
              if(!d || d.error){ statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
              if(savedThemes[it.name]) savedThemes[it.name].public = false;
              statusEl.textContent = 'Tema "' + it.name + '" quitado de la biblioteca.';
              loadLibrary();
          });
    });
    menu.appendChild(el);
    document.body.appendChild(menu);
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    menu.style.left = Math.min(x, window.innerWidth  - mw - 4) + 'px';
    menu.style.top  = Math.min(y, window.innerHeight - mh - 4) + 'px';
    _themeCtxMenu = menu;
}

/* Probar un tema de la biblioteca: pide confirmación y, al aceptar,
   lo guarda en "Mis temas" del usuario. */
function tryLibraryTheme(it){
    var extras = [];
    if (it.wallpaper) extras.push('fondo');
    if (it.startIcon) extras.push('icono');
    var extraNote = extras.length
        ? '<br><small>Incluye ' + extras.join(' e ') + '.</small>'
        : '';
    window._w98Confirm({
        title: 'Descargar tema',
        html: '¿Descargar el tema <strong>' + escapeHtml(it.name) + '</strong> de '
              + escapeHtml(it.label) + '?<br><small>Se guardará en «Mis temas».</small>' + extraNote,
        okText: 'Descargar',
        onOk: function(){ downloadLibraryTheme(it); }
    });
}

/* Guarda el tema de la biblioteca en los temas del usuario (action=save).
   Si ya existe uno con ese nombre, añade un sufijo para no pisarlo. */
function downloadLibraryTheme(it){
    var colors = migrateLegacyColors(it.colors || {});
    var name = it.name;
    if (savedThemes[name]) {
        var base = name.slice(0, 26), n = 2;
        while (savedThemes[name] && n < 99) { name = base + '_' + n; n++; }
    }
    var labelSafe = <?php echo json_encode(preg_replace('/[^A-Za-z0-9_-]/', '', $userLabel)); ?>;
    fetch('../../assets/themes/api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            name: name, colors: colors, downloaded: true,
            wallpaper: it.wallpaper || '', startIcon: it.startIcon || '',
            /* Premio +50 al autor (dedupe server-side por descargador). */
            sourceUserKey:   it.userKey || '',
            sourceThemeName: it.name    || ''
        })
    }).then(function(r){ return r.json(); })
      .then(function(d){
          if (!d || d.error) { statusEl.textContent = (d && d.error) ? d.error : 'Error al guardar'; return; }
          editingOriginalName = name;
          /* Activar el tema descargado para aplicar de golpe colores + fondo + icono.
             set-active devuelve los assets ya copiados al espacio del usuario. */
          persistThemeForInterface(name);
          return fetch('../../assets/themes/api.php?action=set-active', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ name: name })
          }).then(function(r){ return r.json(); })
            .then(function(d2){
                activeName = name;
                /* Usar el cssPath/className que devolvió set-active —
                   apunta a uploads/themes/. assets/themes/ ya no existe
                   como destino de CSS regenerados. */
                applyLiveTheme((d2 && d2.className) || (name + '-' + labelSafe),
                               (d2 && d2.cssPath)  || '');
                applyThemeAssets((d2 && d2.wallpaper) || THEME_DEFAULT_WP,
                                 (d2 && d2.startIcon) || THEME_DEFAULT_SI);
                /* Ir a "Mis temas", refrescar lista/previews y dejarlo seleccionado */
                document.querySelector('.temas-tab-btn[data-tab="themes"]').click();
                loadThemes(function(){
                    nameInput.value = name;
                    setEditorColors(colors);
                    setActiveItem(name);
                });
                statusEl.textContent = '✔ "' + name + '" descargado y activado.';
            });
      })
      .catch(function(){ statusEl.textContent = '✗ Error de red al guardar.'; });
}

function escapeHtml(s){
    return String(s).replace(/[&<>"]/g, function(ch){
        return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' })[ch];
    });
}

document.getElementById('temas-lib-refresh').addEventListener('click', loadLibrary);

/* =========================================================
   FONDO DE PANTALLA
========================================================= */
(function() {
    var wpFile    = document.getElementById('temas-wallpaper-file');
    var wpName    = document.getElementById('temas-wallpaper-name');
    var wpBrowse  = document.getElementById('temas-wallpaper-browse');
    var wpSave    = document.getElementById('temas-wallpaper-save');
    var wpPreview = document.getElementById('temas-wallpaper-preview');
    var wpStatus  = document.getElementById('temas-wallpaper-status');

    /* Wallpaper actual del usuario (calculado en PHP) */
    var currentWp = <?php echo json_encode(getUserWallpaper($userLabel)); ?>;

    function setPreview(rel) {
        if (rel) {
            /* Cache-bust por si el navegador tenía la versión vieja */
            wpPreview.style.backgroundImage = 'url("../' + rel + '?t=' + Date.now() + '")';
        } else {
            wpPreview.style.backgroundImage = '';
        }
    }
    setPreview(currentWp);
    /* Refresca el preview con el valor EFECTIVO que pasa el llamador
       (vacío = limpia el preview, mostrando el hueco hundido). */
    window._setWpPreview = function(rel){ currentWp = rel || ''; setPreview(currentWp); };

    wpBrowse.addEventListener('click', function() { wpFile.click(); });
    wpFile.addEventListener('change', function() {
        wpName.value = this.files.length ? this.files[0].name : '';
        wpStatus.textContent = '';
    });

    wpSave.addEventListener('click', function() {
        if (!wpFile.files.length) { wpStatus.textContent = 'Elige una imagen primero.'; return; }
        var fd = new FormData();
        fd.append('wallpaper', wpFile.files[0]);
        /* Vincular el fondo al tema activo (si lo hay) */
        if (activeName) fd.append('theme', activeName);
        wpStatus.textContent = 'Subiendo…';
        wpSave.classList.add('btn-busy');
        fetch('../../assets/img/wallpapers/save-wallpaper.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                wpSave.classList.remove('btn-busy');
                if (!d || d.error) { wpStatus.textContent = (d && d.error) ? d.error : 'Error'; return; }
                wpStatus.textContent = activeName ? 'Fondo vinculado al tema "' + activeName + '".' : 'Fondo actualizado.';
                currentWp = d.wallpaper;
                if (activeName && savedThemes[activeName]) savedThemes[activeName].wallpaper = d.wallpaper;
                setPreview(currentWp);
                /* Pide al escritorio padre que aplique el wallpaper nuevo */
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'wallpaper-changed', wallpaper: currentWp }, '*');
                }
                wpFile.value = '';
                wpName.value = '';
            })
            .catch(function() { wpSave.classList.remove('btn-busy'); wpStatus.textContent = 'Error de red.'; });
    });
})();

/* =========================================================
   ICONO DEL BOTÓN DE INICIO
========================================================= */
(function() {
    var siFile    = document.getElementById('temas-starticon-file');
    var siName    = document.getElementById('temas-starticon-name');
    var siBrowse  = document.getElementById('temas-starticon-browse');
    var siSave    = document.getElementById('temas-starticon-save');
    var siPreview = document.getElementById('temas-starticon-preview');
    var siStatus  = document.getElementById('temas-starticon-status');

    var currentSi = <?php echo json_encode(getUserStartIcon($userLabel)); ?>;

    function setPreview(rel) {
        if (rel) {
            siPreview.style.backgroundImage = 'url("../' + rel + '?t=' + Date.now() + '")';
        } else {
            siPreview.style.backgroundImage = '';
        }
    }
    setPreview(currentSi);
    window._setSiPreview = function(rel){ currentSi = rel || ''; setPreview(currentSi); };

    siBrowse.addEventListener('click', function() { siFile.click(); });
    siFile.addEventListener('change', function() {
        siName.value = this.files.length ? this.files[0].name : '';
        siStatus.textContent = '';
    });

    siSave.addEventListener('click', function() {
        if (!siFile.files.length) { siStatus.textContent = 'Elige una imagen primero.'; return; }
        var fd = new FormData();
        fd.append('icon', siFile.files[0]);
        if (activeName) fd.append('theme', activeName);
        siStatus.textContent = 'Subiendo…';
        siSave.classList.add('btn-busy');
        fetch('../../assets/img/start-icons/save-start-icon.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                siSave.classList.remove('btn-busy');
                if (!d || d.error) { siStatus.textContent = (d && d.error) ? d.error : 'Error'; return; }
                siStatus.textContent = activeName ? 'Icono vinculado al tema "' + activeName + '".' : 'Icono actualizado.';
                currentSi = d.icon;
                if (activeName && savedThemes[activeName]) savedThemes[activeName].startIcon = d.icon;
                setPreview(currentSi);
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'start-icon-changed', icon: currentSi }, '*');
                }
                siFile.value = '';
                siName.value = '';
            })
            .catch(function() { siSave.classList.remove('btn-busy'); siStatus.textContent = 'Error de red.'; });
    });
})();

</script>

<!-- Modal Win98 para confirmar eliminación de tema -->
<div class="window" id="theme-delete-modal" style="display:none; position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); min-width:340px; max-width:460px; z-index:8500; flex-direction:column;">
    <div class="title-bar">
        <div class="title-bar-text">Confirmar eliminación de tema</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="theme-delete-x"></button>
        </div>
    </div>
    <div class="window-body w98-confirm-body">
        <div class="w98-confirm-row">
            <div class="w98-icon-question"></div>
            <div class="w98-confirm-text" id="theme-delete-text">¿Eliminar el tema?</div>
        </div>
        <div class="w98-confirm-btns">
            <button id="theme-delete-ok" class="default">Sí</button>
            <button id="theme-delete-cancel">No</button>
        </div>
    </div>
</div>

<script>
/* Modal Win98 de confirmación genérico (sustituye al confirm() nativo). */
(function(){
    var modal = document.getElementById('theme-delete-modal');
    var titleEl = modal.querySelector('.title-bar-text');
    function openConfirm(opts){
        /* opts: { title, html, okText, onOk } */
        if(titleEl) titleEl.textContent = opts.title || 'Confirmar';
        document.getElementById('theme-delete-text').innerHTML = opts.html || '¿Continuar?';
        document.getElementById('theme-delete-ok').textContent = opts.okText || 'Sí';
        modal.style.display = 'flex';
        function cleanup(){
            modal.style.display = 'none';
            document.getElementById('theme-delete-ok').onclick = null;
            document.getElementById('theme-delete-cancel').onclick = null;
            document.getElementById('theme-delete-x').onclick = null;
            document.removeEventListener('keydown', keyHandler);
        }
        function ok(){ cleanup(); if(opts.onOk) opts.onOk(); }
        function cancel(){ cleanup(); }
        function keyHandler(ev){
            if(ev.key === 'Enter'){ ev.preventDefault(); ok(); }
            else if(ev.key === 'Escape'){ ev.preventDefault(); cancel(); }
        }
        document.getElementById('theme-delete-ok').onclick = ok;
        document.getElementById('theme-delete-cancel').onclick = cancel;
        document.getElementById('theme-delete-x').onclick = cancel;
        document.addEventListener('keydown', keyHandler);
    }
    window._w98Confirm = openConfirm;
    /* Compat: confirmación de borrado de tema */
    window._w98ConfirmDeleteTheme = function(name, onOk){
        openConfirm({
            title: 'Confirmar eliminación de tema',
            html: '¿Eliminar el tema <strong>' + name.replace(/[&<>]/g, function(c){
                      return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];
                  }) + '</strong>?',
            okText: 'Sí', onOk: onOk
        });
    };
})();
</script>

<script>
(function(){
    var KEY = 'lcd-filter';
    var cb = document.getElementById('lcd-filter-toggle');
    if (!cb) return;
    /* Default ON: filtro activo salvo que el usuario lo desactive explícitamente. */
    function get(){ try { return localStorage.getItem(KEY) !== '0'; } catch(_) { return true; } }
    function apply(on){
        document.documentElement.classList.toggle('lcd-filter-on', on);
        var topWin = window.top;
        if (topWin !== window) {
            try { topWin.document.documentElement.classList.toggle('lcd-filter-on', on); } catch(_) {}
        }
        /* Propagar a todos los iframes del top (apps abiertas previamente). */
        try {
            var frames = topWin.document.querySelectorAll('iframe');
            for (var i = 0; i < frames.length; i++) {
                try {
                    var d = frames[i].contentDocument;
                    if (d) d.documentElement.classList.toggle('lcd-filter-on', on);
                } catch(_) {}
            }
        } catch(_) {}
    }
    cb.checked = get();
    apply(cb.checked);
    cb.addEventListener('change', function(){
        try { localStorage.setItem(KEY, cb.checked ? '1' : '0'); } catch(_) {}
        apply(cb.checked);
    });
})();
</script>
</body>
</html>
