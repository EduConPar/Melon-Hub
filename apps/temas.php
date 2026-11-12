<?php
session_start();
require_once dirname(__DIR__) . '/assets/config.php';
require_once dirname(__DIR__) . '/assets/dev-build.php';   /* MELON_DEV_BUILD: botón "Probar" de los haros */

$userKey = $_SESSION['user'] ?? null;
if (!$userKey || !isset($loginUsers[$userKey])) {
    header('Location: ../index.php');
    exit;
}
$userLabel = $loginUsers[$userKey]['label'];

/* Detecta packs de iconos: subcarpetas de assets/img/appIcons/.
   Cada carpeta = un pack. El default 'Melon' debería existir siempre. */
$iconPacks = [];
$appIconsDir = dirname(__DIR__) . '/assets/img/appIcons';
if (is_dir($appIconsDir)) {
    foreach (scandir($appIconsDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($appIconsDir . '/' . $entry)) continue;
        /* Coge un icono representativo del pack para el preview (el
           primer .png que encuentre). */
        $previewIcon = '';
        foreach (scandir($appIconsDir . '/' . $entry) as $f) {
            if (preg_match('/\.(png|jpg|gif|webp)$/i', $f)) {
                $previewIcon = '../assets/img/appIcons/' . $entry . '/' . $f;
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

/* Detecta interfaces instaladas en assets/interfaces/. Cada una es
   una carpeta con style.css + meta.json + (opcional) preview.png.
   Las interfaces "premium" (con fila en tienda_items categoria='interfaces'
   y precio>0) se ocultan al usuario hasta que las compre en la tienda. */
require_once dirname(__DIR__) . '/assets/php/active-interface.php';
require_once dirname(__DIR__) . '/assets/themes/theme-helpers.php';
require_once dirname(__DIR__) . '/db.php';
/** @var PDO $pdo */
$interfacePacks = listInterfacesForUser($pdo, userIdByKey($userKey));

/* Tema activo del usuario. refreshAllUserThemesCss regenera el CSS de
   TODOS los temas guardados — necesario porque uploads/themes/ se borra
   en los deploys de Hostinger y los demás temas del picker quedarían
   sin CSS hasta que el usuario los reactivara. La BD (themes.colors)
   es la fuente de verdad. */
refreshAllUserThemesCss($userKey, $userLabel);
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes = loadUserThemes($userKey);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $userLabel);
    $activeThemeCss   = '../' . themeCssRelPath($activeTheme, $userLabel);
    if (!file_exists(dirname(__DIR__) . '/' . themeCssRelPath($activeTheme, $userLabel))) $activeThemeCss = '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="../assets/js/pwa-guard.js"></script>
    <title>Temas</title>
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <!-- Marcos de perfil en las fotos de los usuarios (data-marco-user) -->
    <link rel="stylesheet" href="../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/css/marcos.css') ?>">
    <script src="../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/melon-marcos.js') ?>"></script>
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <!-- Icon pack swap — debe cargar antes del primer render. -->
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?php echo htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: var(--win-bg); color: var(--text); }
        #temas-app { display: flex; height: 100vh; }
        #temas-sidebar {
            width: 180px;
            background: var(--win-bg);
            color: var(--text);
            border-right: 1px solid var(--border);
            box-shadow: 1px 0 0 var(--bezel-light-1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .temas-side-head {
            padding: 4px 8px;
            background: linear-gradient(to right, var(--titlebar-start), var(--titlebar-end));
            color: var(--titlebar-text);
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        #temas-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .temas-item {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 5px 6px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            color: var(--text);
        }
        .temas-item:hover {
            background: var(--accent);
            color: var(--accent-text);
        }
        .temas-item.active {
            background: var(--accent);
            color: var(--accent-text);
        }
        .temas-item-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .temas-item-badge {
            font-size: 9px;
            color: #008000;
            font-weight: bold;
        }
        .temas-item.active .temas-item-badge,
        .temas-item:hover .temas-item-badge { color: var(--accent-text); }
        .temas-item-pub,
        .temas-item-dl {
            width: 14px;
            height: 14px;
            object-fit: contain;
            image-rendering: pixelated;
            flex-shrink: 0;
        }
        /* Menú contextual de tema (publicar/quitar) */
        .temas-ctx-menu {
            position: fixed; z-index: 99999;
            background: var(--btn-bg); color: var(--text);
            border-top: 2px solid var(--bezel-light-1); border-left: 2px solid var(--bezel-light-1);
            border-right: 2px solid var(--bezel-dark-2); border-bottom: 2px solid var(--bezel-dark-2);
            box-shadow: 2px 2px 5px rgba(0,0,0,.35); font-size: 11px; padding: 2px; min-width: 170px;
        }
        .temas-ctx-opt { padding: 5px 12px; cursor: pointer; white-space: nowrap; }
        .temas-ctx-opt:hover { background: var(--accent); color: var(--accent-text); }
        .temas-ctx-opt.danger { color: var(--error-text); }
        .temas-ctx-opt.danger:hover { background: var(--error-text); color: var(--win-bg); }
        #temas-sidebar-footer {
            padding: 6px 8px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -1px 0 var(--bezel-light-1);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        #temas-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--win-bg);
            color: var(--text);
        }
        #temas-wallpaper-area {
            padding: 6px 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -1px 0 var(--bezel-light-1);
        }
        #temas-wallpaper-preview {
            width: 100%;
            aspect-ratio: 16/10;
            background-color: var(--inset-bg);
            background-size: cover;
            background-position: center;
            box-shadow: inset 1px 1px var(--bezel-dark-1),
                        inset -1px -1px var(--bezel-light-1),
                        inset 2px 2px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2);
        }
        #temas-tabs {
            display: flex;
            gap: 2px;
            padding: 4px 4px 0 4px;
            flex-shrink: 0;
        }
        .temas-tab-btn {
            flex: 1 1 0;
            min-width: 0;                 /* permite encoger por debajo del contenido */
            font-size: 10px;
            padding: 3px 4px;
            min-height: 22px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: center;
        }
        .temas-tab-btn.active {
            background: var(--accent);
            color: var(--accent-text);
        }
        .temas-tab-pane {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            min-height: 0;
        }
        .temas-tab-pane[hidden] { display: none; }

        /* ── Biblioteca de temas ── */
        #temas-library { flex:1; overflow-y:auto; padding:12px; }
        #temas-library-grid {
            display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));
            gap:14px;
        }
        .lib-card {
            border-top:2px solid var(--bezel-light-1); border-left:2px solid var(--bezel-light-1);
            border-right:2px solid var(--bezel-dark-2); border-bottom:2px solid var(--bezel-dark-2);
            background:var(--win-bg); display:flex; flex-direction:column;
        }
        .lib-preview { cursor:pointer; padding:8px; }
        .lib-preview:hover { filter:brightness(1.04); }
        /* mini maqueta de ventana win98 con los colores del tema */
        .lib-win { border:1px solid #000; border-radius:2px; overflow:hidden; box-shadow:2px 2px 0 rgba(0,0,0,.25); }
        .lib-win-tb { display:flex; align-items:center; gap:4px; padding:3px 5px; font-size:9px; font-weight:bold; }
        .lib-win-tb-dots { margin-left:auto; display:flex; gap:2px; }
        .lib-win-tb-dots i { width:7px; height:7px; display:block; border:1px solid rgba(0,0,0,.4); }
        .lib-win-body { padding:8px; min-height:54px; }
        .lib-win-row { height:9px; border-radius:1px; margin-bottom:5px; }
        .lib-win-btns { display:flex; gap:4px; margin-top:6px; }
        .lib-win-btn { font-size:8px; padding:2px 6px; border-radius:1px; }
        .lib-name { font-size:11px; font-weight:bold; text-align:center; padding:2px 4px; color:var(--text);
                    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .lib-author {
            display:flex; align-items:center; gap:6px; padding:6px 8px; cursor:pointer;
            border-top:1px solid var(--border);
        }
        .lib-author:hover { background:var(--accent); color:var(--accent-text); }
        /* Marco cuadrado tipo Win98 (igual que el avatar de la pantalla de login) */
        .lib-author-img {
            width:24px; height:24px; object-fit:cover; flex-shrink:0; display:block;
            image-rendering:pixelated;
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1),
                 1px  1px 0 var(--bezel-light-1),
                -2px -2px 0 var(--bezel-dark-2),
                 2px  2px 0 var(--bezel-light-2);
            margin:2px;
        }
        /* Con foto, va dentro de .lib-author-av, que lleva el marco de
           perfil del autor (data-marco-user, melon-marcos.js). */
        .lib-author-av { display:block; width:24px; height:24px; margin:2px; flex-shrink:0; }
        .lib-author-av > .lib-author-img { margin:0; }
        .lib-author-ph {
            width:24px; height:24px; flex-shrink:0; display:flex;
            align-items:center; justify-content:center; font-size:14px;
            background:var(--inset-bg); color:var(--text-inset);
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1),
                 1px  1px 0 var(--bezel-light-1),
                -2px -2px 0 var(--bezel-dark-2),
                 2px  2px 0 var(--bezel-light-2);
            margin:2px;
        }
        .lib-author-name { font-size:11px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        /* Empty state: ocupa el ancho completo del grid (no cae a una
           celda) y se centra vertical+horizontalmente dentro del área
           visible de la biblioteca. */
        #temas-library-empty {
            grid-column: 1 / -1;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-faint);
            font-size: 12px;
            padding: 20px;
            line-height: 1.6;
        }

        /* ── Vista de Personalización (Temas / Haros / Mascotas) ── */
        #temas-personalize { flex:1; overflow-y:auto; padding:12px; background:var(--win-bg); color:var(--text); }
        .pers-section { margin-bottom:18px; }
        .pers-section:last-child { margin-bottom:0; }
        .pers-section-title {
            font-size:11px;
            font-weight:bold;
            margin:0 0 6px;
            padding:3px 6px;
            background:var(--titlebar-start, #000080);
            color:var(--titlebar-text, #fff);
            letter-spacing:1px;
        }
        /* Botón "Probar" (solo dev) a la derecha del título de Haros:
           compacto para no hacer más alta la barra del título. */
        .pers-section-title.has-action { display:flex; align-items:center; }
        .pers-title-btn {
            margin-left:auto;
            min-width:0;
            min-height:0;
            padding:1px 8px;
            font-size:11px;
            letter-spacing:0;
            font-weight:normal;
        }
        .pers-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(110px, 1fr));
            gap:8px;
            padding:6px;
            box-shadow:
                inset  1px  1px var(--bezel-dark-1),
                inset -1px -1px var(--bezel-light-1),
                inset  2px  2px var(--bezel-dark-2),
                inset -2px -2px var(--bezel-light-2);
            background:var(--inset-bg);
            min-height:80px;
        }
        .pers-item {
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:4px;
            padding:8px 4px 6px;
            background:var(--win-bg);
            box-shadow:
                inset -1px -1px var(--bezel-dark-1),
                inset  1px  1px var(--bezel-light-1);
            cursor:pointer;
            font-size:10px;
            text-align:center;
            user-select:none;
            position:relative;
        }
        .pers-item:hover { background:var(--btn-bg, #d4d0c8); }
        .pers-item:active,
        .pers-item.active {
            box-shadow:
                inset  1px  1px var(--bezel-dark-1),
                inset -1px -1px var(--bezel-light-1);
        }
        .pers-item.active { background:var(--accent, #000080); color:var(--accent-text, #fff); }
        .pers-item-icon {
            width:48px; height:48px;
            display:flex; align-items:center; justify-content:center;
            font-size:32px; line-height:1;
        }
        .pers-item-icon img { max-width:100%; max-height:100%; display:block; }
        .pers-item-name { line-height:1.2; word-break:break-word; }
        /* Fuentes: "Aa" y el nombre con su fuente. */
        .pers-fuente .pers-item-icon { font-size:30px; }
        .pers-fuente .pers-item-icon { white-space:nowrap; }
        .pers-fuente .pers-item-name { font-size:12px; }
        .pers-item-badge {
            position:absolute;
            top:3px; right:3px;
            font-size:8px;
            padding:1px 4px;
            background:rgba(0,0,0,0.4);
            color:#fff;
            border-radius:6px;
            letter-spacing:1px;
        }
        .pers-item.active .pers-item-badge { background:rgba(255,255,255,0.25); }
        #temas-starticon-area {
            padding: 6px 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        #temas-starticon-preview {
            width: 64px;
            height: 64px;
            align-self: center;
            background-color: var(--inset-bg);
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            box-shadow: inset 1px 1px var(--bezel-dark-1),
                        inset -1px -1px var(--bezel-light-1),
                        inset 2px 2px var(--bezel-dark-2),
                        inset -2px -2px var(--bezel-light-2);
        }
        #temas-toolbar {
            background: var(--win-bg);
            color: var(--text);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 0 var(--bezel-light-1);
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #temas-toolbar .field-row-stacked { margin: 0; flex: 1; }
        #temas-toolbar label { font-size: 10px; color: var(--text); }
        #temas-toolbar input { font-size: 11px; padding: 2px 4px; }
        #temas-editor {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            background: var(--win-bg);
            color: var(--text);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            align-content: start;
        }
        .color-group-head {
            grid-column: 1 / -1;
            font-size: 11px;
            font-weight: bold;
            color: var(--accent);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 0 var(--bezel-light-1);
            padding: 4px 2px 2px;
            margin-top: 6px;
            letter-spacing: 0.4px;
        }
        .color-group-head:first-child { margin-top: 0; }
        .color-field {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .color-field-label { font-size: 11px; font-weight: bold; color: var(--text); }
        .color-field-row { display: flex; gap: 4px; align-items: center; }
        .color-field-row input[type="color"] {
            width: 36px;
            height: 24px;
            padding: 0;
            border: 1px solid var(--border);
            cursor: pointer;
        }
        .color-field-row input[type="text"] {
            width: 80px;
            font-size: 11px;
            padding: 2px 4px;
        }
        #temas-status {
            font-size: 11px;
            padding: 4px 10px;
            background: var(--win-bg);
            color: var(--text);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
        }
    </style>
</head>
<body class="<?php
    /* Tema default = Win98 (tokens.css) para todos. Sin clase capi/angie. */
    $bc = [];
    if ($activeThemeClass) $bc[] = $activeThemeClass;
    echo htmlspecialchars(implode(' ', $bc));
?>">

<div id="temas-app">
    <div id="temas-sidebar">
        <div id="temas-tabs">
            <button class="button temas-tab-btn active" data-tab="themes"><img src="../assets/img/appIcons/temasIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;"> Mis temas</button>
            <button class="button temas-tab-btn" data-tab="personalize"><img src="../assets/img/appIcons/drawingIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;"> Personalización</button>
            <button class="button temas-tab-btn" data-tab="library"><img src="../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Biblioteca</button>
        </div>

        <!-- Tab: Mis temas -->
        <div class="temas-tab-pane" id="temas-pane-themes">
            <div id="temas-list"></div>
            <div id="temas-sidebar-footer">
                <button class="button" id="temas-new">+ Nuevo</button>
                <button class="button" id="temas-deactivate">Usar default</button>
            </div>
        </div>

        <!-- Tab: Personalización -->
        <div class="temas-tab-pane" id="temas-pane-personalize" hidden>
            <div class="temas-side-head">🖼 Fondo</div>
            <div id="temas-wallpaper-area">
                <div id="temas-wallpaper-preview"></div>
                <div class="field-row" style="gap:4px;">
                    <input type="text" id="temas-wallpaper-name" readonly placeholder="Sin archivo" style="flex:1;min-width:0;cursor:default;font-size:11px;height:21px;">
                    <button class="button" id="temas-wallpaper-browse" style="min-width:70px;flex-shrink:0;height:21px;min-height:21px;">Examinar...</button>
                </div>
                <input type="file" id="temas-wallpaper-file" accept="image/*" style="display:none;">
                <button class="button" id="temas-wallpaper-save" style="width:100%;margin-top:4px;">Subir y aplicar</button>
                <p id="temas-wallpaper-status" style="font-size:10px;margin:3px 0 0;color:var(--text-faint);min-height:13px;"></p>
            </div>

            <div class="temas-side-head" style="border-top:1px solid var(--border);">▶ Icono de inicio</div>
            <div id="temas-starticon-area">
                <div id="temas-starticon-preview"></div>
                <div class="field-row" style="gap:4px;">
                    <input type="text" id="temas-starticon-name" readonly placeholder="Sin archivo" style="flex:1;min-width:0;cursor:default;font-size:11px;height:21px;">
                    <button class="button" id="temas-starticon-browse" style="min-width:70px;flex-shrink:0;height:21px;min-height:21px;">Examinar...</button>
                </div>
                <input type="file" id="temas-starticon-file" accept="image/*,image/svg+xml" style="display:none;">
                <button class="button" id="temas-starticon-save" style="width:100%;margin-top:4px;">Subir y aplicar</button>
                <p id="temas-starticon-status" style="font-size:10px;margin:3px 0 0;color:var(--text-faint);min-height:13px;"></p>
            </div>

            <div class="temas-side-head" style="border-top:1px solid var(--border);"><img src="../assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Efectos visuales</div>
            <div id="temas-effects-area" style="padding:6px 4px;">
                <div class="field-row">
                    <input type="checkbox" id="lcd-filter-toggle">
                    <label for="lcd-filter-toggle" style="font-size:11px;">Filtro LCD (scanlines)</label>
                </div>
                <p style="font-size:10px;margin:4px 0 0;color:var(--text-faint);line-height:1.4;">
                    Líneas horizontales sutiles sobre toda la app. Se guarda por dispositivo.
                </p>
            </div>

        </div>

        <!-- Tab: Biblioteca -->
        <div class="temas-tab-pane" id="temas-pane-library" hidden>
            <div class="temas-side-head"><img src="../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Biblioteca de temas</div>
            <p style="font-size:10px;color:var(--text-faint);padding:6px 4px;line-height:1.4;">
                Temas publicados por la comunidad. Pulsa un tema para probarlo;
                pulsa el autor para visitar su perfil.
            </p>
            <button class="button" id="temas-lib-refresh" style="width:100%;">↻ Recargar</button>
        </div>
    </div>

    <div id="temas-main">
        <div id="temas-toolbar">
            <div class="field-row-stacked">
                <label for="theme-name-input">Nombre del tema</label>
                <input type="text" id="theme-name-input" maxlength="30" placeholder="MiTema">
            </div>
            <button class="button" id="theme-save">Guardar</button>
            <button class="button" id="theme-activate">Activar</button>
            <button class="button" id="theme-export"  title="Descargar este tema como JSON">⬇ Exportar</button>
            <button class="button" id="theme-import"  title="Cargar un tema desde un fichero JSON">⬆ Importar</button>
            <input type="file" id="theme-import-file" accept="application/json,.json" style="display:none;">
        </div>
        <div id="temas-editor">
            <!-- color fields injected by JS -->
        </div>
        <!-- Tamaño de fuente: delta que se suma a TODAS las font-size
             de la app. 0 = tamaño por defecto; cada paso ±1 px. Vive
             como propiedad del tema (themes.colors.fontDelta). -->
        <div id="temas-font-row" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-top:1px solid var(--border);">
            <label for="theme-font-delta" style="font-size:11px;flex-shrink:0;">Tamaño de fuente</label>
            <input type="range" id="theme-font-delta" min="-6" max="10" step="1" value="0" style="flex:1;">
            <span id="theme-font-delta-val" style="font-size:11px;min-width:48px;text-align:right;">0 px</span>
        </div>
        <div id="temas-status">Crea un tema nuevo o selecciona uno existente para editarlo.</div>
        <!-- Biblioteca (grid de temas publicados) -->
        <div id="temas-library" hidden>
            <div id="temas-library-grid"></div>
        </div>
        <!-- Personalización (Interfaces / Iconos / Haros / Mascotas) -->
        <div id="temas-personalize" hidden>
            <section class="pers-section">
                <h3 class="pers-section-title"><img src="../assets/img/appIcons/interfaceIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Interfaces</h3>
                <div class="pers-grid" id="pers-themes-grid"></div>
            </section>
            <section class="pers-section">
                <h3 class="pers-section-title"><img src="../assets/img/appIcons/interfaceIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Fuentes</h3>
                <div class="pers-grid" id="pers-fonts-grid"></div>
            </section>
            <section class="pers-section">
                <h3 class="pers-section-title"><img src="../assets/img/appIcons/temasIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Iconos</h3>
                <div class="pers-grid" id="pers-icons-grid"></div>
            </section>
            <section class="pers-section">
                <?php $_devHaroTest = defined('MELON_DEV_BUILD') && MELON_DEV_BUILD; ?>
                <h3 class="pers-section-title<?= $_devHaroTest ? ' has-action' : '' ?>"><img src="../assets/img/appIcons/haroIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Haros<?php if ($_devHaroTest): ?><button type="button" class="pers-title-btn" id="pers-haro-test" title="Saca una notificación de prueba con el haro activo (solo dev)">Probar</button><?php endif; ?></h3>
                <div class="pers-grid" id="pers-haros-grid"></div>
            </section>
            <section class="pers-section">
                <h3 class="pers-section-title"><img src="../assets/img/appIcons/mascotaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Mascotas</h3>
                <div class="pers-grid" id="pers-mascots-grid" style="font-size:13px;color:var(--text-muted, var(--text));padding:6px 0;">Coming soon...</div>
            </section>
        </div>
    </div>
</div>

<script>
/* Lista de INTERFACES instaladas — escaneadas desde assets/interfaces/.
   Cada item tiene { name, label, description, isDefault, preview }. */
window.__INTERFACE_PACKS__ = <?= json_encode($interfacePacks, JSON_UNESCAPED_UNICODE) ?>;
/* Lista de packs de iconos detectados por PHP (scandir de
   assets/img/appIcons/). El usuario puede crear más packs creando
   carpetas con los mismos nombres de archivo. */
window.__ICON_PACKS__ = <?= json_encode($iconPacks, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
/* `hideIn: ['kawaii']` esconde la variable del editor cuando la interfaz
   activa NO la usa. Kawaii sólo lee 11 vars de su style.css (verificado
   con `grep var(--`):
       winBg, winBodyBg, border, text, textMuted, textFaint,
       titlebarStart, titlebarText, accent, accentDeep, linkText
   Las demás las DEFINE en su :root para mantener compat con shells que
   las leen (98.css, themes.css), pero las USA hardcoded en sus propias
   reglas — editarlas no produce cambio visual en kawaii. */
const COLOR_DEFS = [
    /* — Superficies — */
    { key: 'winBg',         label: 'Fondo de ventana',          def: '#c0c0c0', group: 'Superficies' },
    { key: 'winBodyBg',     label: 'Fondo del cuerpo',          def: '#c0c0c0', group: 'Superficies' },
    { key: 'surfaceDeep',   label: 'Taskbar / paneles',         def: '#c0c0c0', group: 'Superficies', hideIn: ['kawaii'] },
    /* insetBg → en kawaii es el bg blanco de inputs/scrollbar/cards.
       VISIBLE en kawaii — el user quiere editarlo. */
    { key: 'insetBg',       label: 'Fondo hundido (inputs, scroll, cards)', def: '#808080', group: 'Superficies' },
    /* — Inputs — */
    { key: 'inputBg',       label: 'Fondo del input/textbox',   def: '#ffffff', group: 'Inputs' },
    { key: 'inputText',     label: 'Texto del input',           def: '#000000', group: 'Inputs', hideIn: ['kawaii'] },
    /* — Botones — */
    { key: 'btnBg',         label: 'Fondo del botón',           def: '#c0c0c0', group: 'Botones', hideIn: ['kawaii'] },
    { key: 'btnText',       label: 'Texto del botón',           def: '#000000', group: 'Botones', hideIn: ['kawaii'] },
    { key: 'startBtnBg',    label: 'Fondo del botón Inicio',    def: '#c0c0c0', group: 'Botones', hideIn: ['kawaii'] },
    { key: 'startBtnText',  label: 'Texto del botón Inicio',    def: '#000000', group: 'Botones', hideIn: ['kawaii'] },
    /* — Texto — */
    { key: 'text',          label: 'Texto principal',           def: '#000000', group: 'Texto' },
    { key: 'textMuted',     label: 'Texto secundario',          def: '#666666', group: 'Texto' },
    { key: 'textFaint',     label: 'Texto terciario / disabled',def: '#808080', group: 'Texto' },
    { key: 'textInset',     label: 'Texto sobre hundido',       def: '#808080', group: 'Texto', hideIn: ['kawaii'] },
    /* — Acento / barra de título — */
    { key: 'titlebarStart', label: 'Título — gradiente inicio', def: '#000080', group: 'Acento' },
    { key: 'titlebarEnd',   label: 'Título — gradiente final',  def: '#1084d0', group: 'Acento', hideIn: ['kawaii'] },
    { key: 'titlebarText',  label: 'Título — texto',            def: '#ffffff', group: 'Acento' },
    { key: 'accent',        label: 'Acento (hover/activo)',     def: '#000080', group: 'Acento' },
    /* accentText → blanco sobre fondos accent (rosa kawaii). VISIBLE
       en kawaii. */
    { key: 'accentText',    label: 'Texto sobre acento (blanco)', def: '#ffffff', group: 'Acento' },
    { key: 'accentDeep',    label: 'Acento profundo (hover²)',  def: '#00004a', group: 'Acento' },
    /* — Iconos de ventana (close / minimize / maximize) — kawaii hardcoded */
    { key: 'titlebarIconColor',      label: 'Color del dibujo del icono', def: '#000000', group: 'Iconos de ventana', hideIn: ['kawaii'] },
    { key: 'titlebarIconBg',         label: 'Fondo del botón del icono',  def: '#c0c0c0', group: 'Iconos de ventana', hideIn: ['kawaii'] },
    { key: 'titlebarIconBezelLight', label: 'Borde 3D claro (top-left)',  def: '#ffffff', group: 'Iconos de ventana', hideIn: ['kawaii'] },
    { key: 'titlebarIconBezelDark',  label: 'Borde 3D oscuro (bot-right)', def: '#0a0a0a', group: 'Iconos de ventana', hideIn: ['kawaii'] },
    /* — Bordes — */
    { key: 'border',        label: 'Borde general',             def: '#808080', group: 'Bordes' },
    /* — Bezels — bezelLight1 (#FFFFFF en kawaii) se usa para sombras
       blancas de algunos botones. VISIBLE para editar. */
    { key: 'bezelLight1',   label: 'Sombra clara (bezel 1px)',  def: '#ffffff', group: 'Bezels' },
    { key: 'bezelLight2',   label: 'Sombra clara (bezel 2px)',  def: '#dfdfdf', group: 'Bezels' },
    { key: 'bezelDark1',    label: 'Sombra oscura (bezel 1px)', def: '#0a0a0a', group: 'Bezels', hideIn: ['kawaii'] },
    { key: 'bezelDark2',    label: 'Sombra oscura (bezel 2px)', def: '#808080', group: 'Bezels', hideIn: ['kawaii'] },
    /* — Escritorio — kawaii pinta su propio wallpaper SVG */
    { key: 'desktopBg',     label: 'Fondo del escritorio',      def: '#008080', group: 'Escritorio', hideIn: ['kawaii'] },
    /* — Estados — */
    { key: 'linkText',      label: 'Hipervínculos',             def: '#0000ff', group: 'Estados' },
    { key: 'errorText',     label: 'Texto de error',            def: '#c00000', group: 'Estados' },
    { key: 'warningBg',     label: 'Fondo de aviso',            def: '#fffbe6', group: 'Estados', hideIn: ['kawaii'] },
    { key: 'warningText',   label: 'Texto de aviso',            def: '#444444', group: 'Estados', hideIn: ['kawaii'] },
    /* — Selección / badges — */
    { key: 'badgeBg',       label: 'Fondo del badge',           def: '#d72638', group: 'Selección', hideIn: ['kawaii'] },
    { key: 'badgeText',     label: 'Texto del badge',           def: '#ffffff', group: 'Selección', hideIn: ['kawaii'] },
    /* — Decorativos — */
    { key: 'starColor',     label: 'Estrellas (rating)',        def: '#ffd700', group: 'Decorativos', hideIn: ['kawaii'] },
    /* — Kawaii-only — solo las vars SEMÁNTICAS clave que el user
       querría editar. Las variantes de tono internas (rose-50,
       accent-coral, button-blue, etc.) viven en :root pero NO se
       exponen aquí — quedan como detalles de implementación. */
    { key: 'hoverBg',        label: 'Fondo hover (botones/items)',     def: '#CCE7F7', group: 'Kawaii', hideIn: ['win98'] },
    { key: 'activeRose',     label: 'Fondo :active (touch)',           def: '#FCE7F5', group: 'Kawaii', hideIn: ['win98'] },
    { key: 'inactiveBg',     label: 'Title-bar inactiva / disabled',   def: '#E8DEEB', group: 'Kawaii', hideIn: ['win98'] },
    { key: 'linkHover',      label: 'Hover de hipervínculo',           def: '#6B3A6B', group: 'Kawaii', hideIn: ['win98'] },
    { key: 'paleRose',       label: 'Rose muy claro (fondos sutiles)', def: '#FFF4F9', group: 'Kawaii', hideIn: ['win98'] },
    { key: 'shadowColor',    label: 'Color base de sombras',           def: '#2D2052', group: 'Kawaii', hideIn: ['win98'] }
];

/* ── Defaults dinámicos por interfaz activa ──────────────────────────
   Los `def` de arriba son fallback Win98. En runtime los reemplazamos
   con los VALORES REALES del :root de la interfaz activa (definido en
   assets/interfaces/<active>/style.css). Así al entrar a Temas en Win7
   los color pickers muestran los azules Aero, no los grises Win98. */
(function() {
    var rootStyle = getComputedStyle(document.documentElement);
    /* Convierte camelCase → kebab-case con guion también ANTES de los
       dígitos (bezelLight1 → bezel-light-1). */
    function keyToVar(key) {
        return '--' + key
            .replace(/([A-Z])/g, '-$1')
            .replace(/([a-zA-Z])(\d)/g, '$1-$2')
            .toLowerCase();
    }
    /* Resuelve var() y color-mix() si están encadenados — getComputedStyle
       devuelve el valor calculado de :root, así que ya viene resuelto. */
    function normalizeColor(v) {
        v = (v || '').trim();
        if (!v) return '';
        /* Solo aceptamos hex de 3/6 dígitos para los pickers. rgba/color-mix
           se quedan como string y se ignoran (cae al `def` hardcoded). */
        if (/^#[0-9a-f]{3}$/i.test(v)) {
            return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
        }
        if (/^#[0-9a-f]{6}$/i.test(v)) return v.toLowerCase();
        if (/^#[0-9a-f]{8}$/i.test(v)) return v.substring(0, 7).toLowerCase();
        return '';
    }
    COLOR_DEFS.forEach(function(d) {
        var raw  = rootStyle.getPropertyValue(keyToVar(d.key));
        var live = normalizeColor(raw);
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

/* Cookie helper: persiste qué tema usar para la interfaz ACTUALMENTE
   activa. PHP la lee en getActiveThemeForInterface() en el siguiente
   reload. Llamamos esto en TODAS las activaciones de tema (incluso
   desactivación con '') para que el server siempre sepa cuál usar. */
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
    /* Interfaz activa — para filtrar variables que no pinta la interfaz
       (e.g. kawaii es flat y no usa bezels 3D Win98). Si una def tiene
       `hideIn: ['kawaii']` y la activa es kawaii, se omite. */
    var activeIface = (typeof window.getActiveInterface === 'function')
        ? window.getActiveInterface()
        : 'win98';
    var visibleDefs = COLOR_DEFS.filter(function(def) {
        return !def.hideIn || def.hideIn.indexOf(activeIface) === -1;
    });
    /* Agrupar por def.group — solo grupos con al menos 1 var visible */
    var groups = {};
    var groupOrder = [];
    visibleDefs.forEach(function(def) {
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
            /* Convierte camelCase de la def.key a kebab-case CSS var
               (winBg → --win-bg, bezelLight1 → --bezel-light-1). */
            var cssVar = '--' + def.key
                .replace(/([A-Z])/g, '-$1')
                .replace(/([a-zA-Z])(\d)/g, '$1-$2')
                .toLowerCase();
            /* Live preview: aplica el color al body local (iframe de
               Temas) Y propaga al shell padre via postMessage para que
               el escritorio entero refleje el cambio en tiempo real,
               sin necesidad de guardar. */
            function _applyLivePreview(val) {
                if (!/^#[0-9a-f]{6}$/i.test(val)) return;
                /* Usamos !important porque algunas interfaces (kawaii)
                   declaran sus vars con !important en :root/body[class].
                   Sin !important, el setProperty inline pierde contra
                   esas reglas. Con !important sí gana. */
                try { document.body.style.setProperty(cssVar, val, 'important'); } catch(_){}
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'theme-color-preview',
                            cssVar: cssVar,
                            value: val
                        }, '*');
                    }
                } catch(_){}
            }
            picker.addEventListener('input', function() {
                hex.value = picker.value;
                _applyLivePreview(picker.value);
                _autoSaveExisting();
            });
            hex.addEventListener('input', function() {
                if (/^#[0-9a-f]{6}$/i.test(hex.value)) {
                    picker.value = hex.value;
                    _applyLivePreview(hex.value);
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

/* Estas funciones soportan COLOR_DEFS filtrados: si una def fue ocultada
   en buildEditor (porque no aplica en la interfaz activa, p.ej. bezels
   en kawaii), su <input> no existe en el DOM. Para esos casos
   preservamos el valor desde el último `loaded` (HIDDEN_COLORS) para
   no perder los datos del tema cuando el user cambia entre interfaces. */
var HIDDEN_COLORS = {};   /* { key: hex } — valores de vars ocultas */

function getEditorColors() {
    var c = {};
    COLOR_DEFS.forEach(function(def) {
        var el = document.getElementById('hex-' + def.key);
        if (el) {
            c[def.key] = el.value;
        } else if (HIDDEN_COLORS[def.key]) {
            /* Var oculta en esta interfaz — conservamos el valor cargado
               para que al guardar el tema no perdamos los bezels Win98. */
            c[def.key] = HIDDEN_COLORS[def.key];
        } else {
            c[def.key] = def.def;
        }
    });
    /* fontDelta vive junto a los colores en themes.colors. Lo añadimos
       al payload guardado y se transmite igual que los colores. */
    var fsSlider = document.getElementById('theme-font-delta');
    if (fsSlider) {
        var d = parseInt(fsSlider.value, 10);
        if (isFinite(d)) c.fontDelta = d;
    }
    return c;
}

function setEditorColors(colors) {
    COLOR_DEFS.forEach(function(def) {
        var v = (colors && colors[def.key]) ? colors[def.key] : def.def;
        var hex = document.getElementById('hex-' + def.key);
        if (hex) {
            hex.value = v;
            if (/^#[0-9a-f]{6}$/i.test(v)) {
                var col = document.getElementById('col-' + def.key);
                if (col) col.value = v;
            }
        } else {
            /* Var oculta — cache para que getEditorColors() lo preserve al guardar */
            HIDDEN_COLORS[def.key] = v;
        }
    });
    /* Carga fontDelta (default 0). */
    var fsSlider  = document.getElementById('theme-font-delta');
    var fsReadout = document.getElementById('theme-font-delta-val');
    var d = (colors && typeof colors.fontDelta === 'number') ? colors.fontDelta : 0;
    if (d < -6) d = -6; if (d > 10) d = 10;
    if (fsSlider)  fsSlider.value = d;
    if (fsReadout) fsReadout.textContent = (d >= 0 ? '+' : '') + d + ' px';
    /* Aplica el delta a esta misma app (preview en vivo en la propia
       app de Temas). Si esta es la ventana padre, también propaga a
       los iframes hijos del shell vía postMessage. */
    if (typeof window.setFontScaleDelta === 'function') window.setFontScaleDelta(d);
    _propagateFontDelta(d);
}

/* Helper: propaga el delta a TODOS los iframes (perfil, reproductor,
   etc.) y al padre si vivimos como iframe del shell. Mismo origen, así
   que postMessage es suficiente. */
function _propagateFontDelta(d) {
    try {
        /* Subimos al top y desde ahí bajamos a todos los iframes. */
        var top = window.top || window;
        if (top !== window) {
            top.postMessage({ type: 'font-scale-delta', delta: d }, '*');
        }
        if (typeof top.setFontScaleDelta === 'function') {
            top.setFontScaleDelta(d);
        }
        var iframes = top.document ? top.document.querySelectorAll('iframe') : [];
        for (var i = 0; i < iframes.length; i++) {
            try { iframes[i].contentWindow.postMessage({ type: 'font-scale-delta', delta: d }, '*'); }
            catch (_) {}
        }
    } catch (_) { /* cross-origin → silenciamos */ }
}

/* Listener del slider: cambio en vivo. */
(function wireFontSlider() {
    var sl = document.getElementById('theme-font-delta');
    var rd = document.getElementById('theme-font-delta-val');
    if (!sl) return;
    sl.addEventListener('input', function() {
        var d = parseInt(sl.value, 10) || 0;
        if (rd) rd.textContent = (d >= 0 ? '+' : '') + d + ' px';
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
    /* Tema nuevo (sin guardar todavía) → no auto-save. */
    if (!editingOriginalName) return;
    /* Coalesce ráfagas de eventos (el color picker dispara `input`
       muchas veces por segundo mientras se arrastra). */
    if (_autoSaveTimer) clearTimeout(_autoSaveTimer);
    _autoSaveTimer = setTimeout(_autoSaveFlush, 400);
}
function _autoSaveFlush() {
    _autoSaveTimer = null;
    if (_autoSaveInFlight) {
        /* Otra request en vuelo — reintenta cuando termine. */
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
    fetch('../assets/themes/api.php?action=save', {
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
          /* Actualizar el espejo en memoria sin recargar la lista
             entera (para no perder selección/scroll). */
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
          /* Si el tema que estamos editando es el activo, hay que
             re-apuntar el <link id="active-theme-link"> al CSS recién
             regenerado por el backend. Sin esto, los cambios sólo se
             ven en el live preview pero no persisten visualmente al
             recargar otras apps que ya estaban abiertas. */
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

/* Disparadores del auto-save sobre el nombre del tema. Debounce más
   largo para no renombrar/duplicar mientras el usuario aún escribe. */
(function wireNameAutoSave(){
    if (!nameInput) return;
    var nameTimer = null;
    nameInput.addEventListener('input', function(){
        if (!editingOriginalName) return;
        if (nameTimer) clearTimeout(nameTimer);
        nameTimer = setTimeout(_autoSaveExisting, 900);
    });
    /* Si el usuario sale del input (blur) o pulsa enter, persistir
       inmediatamente sin esperar al debounce. */
    nameInput.addEventListener('blur',  function(){ if (editingOriginalName) _autoSaveExisting(); });
    nameInput.addEventListener('change',function(){ if (editingOriginalName) _autoSaveExisting(); });
})();

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
        empty.style.cssText = 'padding:10px;font-size:11px;color:#808080;text-align:center;';
        empty.textContent = 'No tienes temas';
        listEl.appendChild(empty);
        return;
    }
    names.forEach(function(name) {
        var item = document.createElement('div');
        item.className = 'temas-item';
        item.dataset.name = name;
        var n = document.createElement('span');
        n.className = 'temas-item-name';
        n.textContent = name;
        item.appendChild(n);
        /* Indicador de tema descargado de la biblioteca */
        if (savedThemes[name] && savedThemes[name].downloaded) {
            var dl = document.createElement('img');
            dl.className = 'temas-item-dl';
            dl.src = '../assets/img/appIcons/downloadIcon.png';
            dl.alt = '';
            dl.title = 'Descargado de la biblioteca';
            item.appendChild(dl);
        }
        /* Indicador de publicado (subido) en biblioteca */
        if (savedThemes[name] && savedThemes[name].public) {
            var pub = document.createElement('img');
            pub.className = 'temas-item-pub';
            pub.src = '../assets/img/appIcons/booksIcon.png';
            pub.alt = '';
            pub.title = 'Publicado en la biblioteca';
            item.appendChild(pub);
        }
        if (name === activeName) {
            var badge = document.createElement('span');
            badge.className = 'temas-item-badge';
            badge.textContent = '✓ activo';
            item.appendChild(badge);
        }
        item.addEventListener('click', function() {
            nameInput.value = name;
            setEditorColors(migrateLegacyColors(savedThemes[name].colors || {}));
            setActiveItem(name);
            editingOriginalName = name;   /* editando un tema existente */
            statusEl.textContent = 'Editando "' + name + '"';
        });
        /* Clic derecho → menú publicar/quitar de la biblioteca */
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
        opts.push({ label: '<img src="../assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">' + (isPub ? 'Quitar de la biblioteca' : 'Publicar en la biblioteca'),
                    action: function(){ setThemePublic(name, !isPub); } });
    }
    opts.push({ label: '📋 Crear copia', action: function(){ duplicateTheme(name); } });
    opts.push({ label: '<img src="../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Eliminar', danger: true, action: function(){ deleteTheme(name); } });
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
    fetch('../assets/themes/api.php?action=save', {
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
        fetch('../assets/themes/api.php?action=delete', {
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
    fetch('../assets/themes/api.php?action=set-public', {
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
    fetch('../assets/themes/api.php?action=get')
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
    fetch('../assets/themes/api.php?action=set-active', {
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
    fetch('../assets/themes/api.php?action=save', {
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
          /* Si el tema guardado es el activo, re-aplicar para que el
             <link id="active-theme-link"> tire la versión nueva del CSS
             (api.php ya la regeneró con los colores nuevos). Usamos
             d.cssPath del response — apunta a uploads/themes/, no a
             assets/themes/. Sin esto los cambios "no se veían" hasta
             recargar la página. */
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

document.getElementById('theme-activate').addEventListener('click', function() {
    var name = nameInput.value.trim();
    if (!name) { statusEl.textContent = 'Selecciona un tema primero.'; return; }
    if (!savedThemes[name]) { statusEl.textContent = 'Guarda el tema antes de activarlo.'; return; }
    persistThemeForInterface(name);
    fetch('../assets/themes/api.php?action=set-active', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name })
    }).then(function(r) { return r.json(); })
      .then(function(d) {
          if (!d || d.error) { statusEl.textContent = (d && d.error) ? d.error : 'Error'; return; }
          activeName = name;
          renderList();
          /* La ruta del CSS la SIEMPRE devuelve api.php en d.cssPath
             (vive en uploads/themes/, no en assets/themes/, desde que
             los temas se regeneran al vuelo). Construirla a mano aquí
             apuntaba a assets/themes/ → 404 → el <link> quedaba roto y
             el tema activo se veía como el default hasta recargar. */
          applyLiveTheme(d.className, d.cssPath);
          /* Al activar, el fontDelta del tema toma efecto inmediato en
             todas las apps. Si el tema no tiene fontDelta guardado, 0. */
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
    /* Local (la propia iframe de Temas): rutas con "../" */
    var localHref = basePath ? '../' + basePath : '';
    applyToDocLocal(document, className, localHref);
    /* Padre: que se encargue de su body + propagar a sus iframes */
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'theme-activated', className: className, cssBasePath: basePath }, '*');
    }
}

function applyToDocLocal(doc, className, cssHref) {
    if (!doc || !doc.body) return;
    var body = doc.body;
    /* capi/angie ya no se aplican como clases de body; sólo conservar
       'has-start-icon' que es estructural. */
    var keep = (body.className || '').split(/\s+/).filter(function(c) {
        return c === 'has-start-icon';
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
    /* Embebemos la interfaz activa en el JSON. Al reimportar este fichero
       lo dirigimos a ESA interfaz (no a la actual del que importa) — un
       tema de win98 no tiene sentido en kawaii (paletas/tokens distintos). */
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

        /* Determinar interfaz destino:
            - JSON con `interface` (v2+) → respetar el origen del tema.
            - JSON sin campo (v1 / legacy) → asumir interfaz actual.
           El tema solo se auto-activa si su interfaz coincide con la
           activa; si no, lo guardamos y dejamos al usuario que cambie
           de interfaz para usarlo. */
        var currentIface = (typeof window.getActiveInterface === 'function')
            ? window.getActiveInterface() : 'win98';
        var targetIface  = (typeof data.interface === 'string' && data.interface)
            ? data.interface.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 30)
            : currentIface;
        var samePack = (targetIface === currentIface);

        statusEl.textContent = 'Importando "' + name + '"…';
        var savePayload = { name: name, colors: clean, interface: targetIface };
        fetch('../assets/themes/api.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(savePayload)
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              if (!d || d.error) throw new Error((d && d.error) || 'Error al guardar');
              if (!samePack) {
                  /* No auto-activamos: el tema está guardado en otra
                     interfaz y activarlo desde aquí pintaría tokens que
                     no aplican. */
                  loadThemes();
                  statusEl.textContent = '✔ "' + name + '" importado en interfaz "'
                      + targetIface + '". Cámbiate a esa interfaz para usarlo'
                      + (bad ? ' (' + bad + ' valores inválidos descartados)' : '') + '.';
                  return;
              }
              persistThemeForInterface(name);
              return fetch('../assets/themes/api.php?action=set-active', {
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
    /* Auto-restore: si tras un reload (típicamente por cambio de pack
       de iconos) hay un flag pidiendo abrir un tab específico, lo
       activamos simulando un click en el botón correspondiente. */
    try {
        var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
        var restore = topStorage.getItem('temas-restore-tab');
        if (restore) {
            topStorage.removeItem('temas-restore-tab');
            var btn = document.querySelector('.temas-tab-btn[data-tab="' + restore + '"]');
            if (btn) btn.click();
        }
    } catch (_) {}
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
    fetch('../assets/personalize/api.php?action=inventory', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d || !d.ok) throw new Error(d && d.error || 'error');
            renderPersInventory('pers-haros-grid',   d.haros,      d.activeHaro,      'haro');
            /* Sección Mascotas: placeholder "Coming soon..." hasta que
               esté implementada. No se rellena con el inventario. */
        })
        .catch(function(){
            document.getElementById('pers-haros-grid').innerHTML   = '';
        });
    /* Interfaces: ahora vienen del filesystem (assets/interfaces/) en
       lugar del inventario de la tienda. Selección persiste en cookie. */
    renderInterfacePacks();
    /* Fuentes: las que tienes (assets/php/fuentes.php); cada interfaz
       guarda la suya. */
    renderFontPacks();
    /* Iconos: lista construida desde el filesystem (scandir de
       assets/img/appIcons/). Selección persiste en localStorage. */
    renderIconPacks();
}

/* Render del grid de interfaces. window.__INTERFACE_PACKS__ se inyecta
   desde PHP con listInterfaces(). Click → setActiveInterface() del
   loader, que escribe cookie + recarga window.top. */
function renderInterfacePacks() {
    var grid = document.getElementById('pers-themes-grid');
    if (!grid) return;
    /* Escape helper local — temas.php no expone uno global. */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var packs = (window.__INTERFACE_PACKS__ || []);
    var active = (typeof window.getActiveInterface === 'function')
        ? window.getActiveInterface()
        : 'win98';
    if (!packs.length) {
        grid.innerHTML = '<p style="font-size:11px;color:var(--text-faint);">No hay interfaces instaladas.</p>';
        return;
    }
    grid.innerHTML = packs.map(function(p) {
        var isActive = (p.name === active);
        var iconHtml;
        if (p.preview) {
            iconHtml = '<img src="../' + esc(p.preview) + '" alt="" style="max-width:100%;max-height:100%;object-fit:contain;image-rendering:pixelated;">';
        } else {
            /* Sin preview: icono genérico de interfaz. */
            iconHtml = '<img src="../assets/img/appIcons/interfaceIcon.png" alt="" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;">';
        }
        return '<div class="pers-item' + (isActive ? ' active' : '') + '" data-iface="' + esc(p.name) + '" title="' + esc(p.description || '') + '">' +
            '<div class="pers-item-icon">' + iconHtml + '</div>' +
            '<div class="pers-item-name">' + esc(p.label || p.name) + '</div>' +
            (isActive ? '<div class="pers-item-badge">ACTIVA</div>' : '') +
        '</div>';
    }).join('');
    grid.querySelectorAll('[data-iface]').forEach(function(card) {
        card.addEventListener('click', function() {
            var name = card.dataset.iface;
            if (typeof window.applyInterfacePack !== 'function') return;
            try {
                var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
                topStorage.setItem('temas-restore-tab', 'personalize');
            } catch (_) {}
            /* PRIMERO persistimos la slug en BD (para que sobreviva al
               cambio de browser/cookies y para que el sync server-side
               en desktop-base.php / mobile.php no la sobrescriba con la
               vieja). LUEGO disparamos applyInterfacePack que setea
               cookie+localStorage y recarga. Usamos finally para que el
               reload también ocurra si el POST falla (mejor que dejar
               el user atascado). */
            fetch('../assets/personalize/api.php?action=set-active-interface-slug', {
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

/* Fuentes que tienes (las de serie, las compradas en la tienda y las que
   vienen con una interfaz tuya). La de cada tarjeta se ve con su fuente
   (mh-fuente-muestra la libra de la fuente puesta en la página). Click →
   set-font para la interfaz activa y recarga, como las interfaces. */
function renderFontPacks() {
    var grid = document.getElementById('pers-fonts-grid');
    if (!grid) return;
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    fetch('../assets/personalize/api.php?action=fonts', { credentials: 'same-origin' })
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
                return '<div class="pers-item pers-fuente' + (on ? ' active' : '') + '" data-fuente="' + esc(f.slug) + '"' +
                    (f.slug === d.defecto ? ' title="La de serie de esta interfaz"' : '') + '>' +
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
                    fetch('../assets/personalize/api.php?action=set-font', {
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
            var iface = (typeof window.getActiveInterface === 'function')
                ? window.getActiveInterface() : 'win98';
            try {
                /* Guarda el icon pack en namespace por interfaz para que
                   cada interface recuerde su propio pack. La interfaz
                   actual viene de la cookie 'activeInterface'. */
                localStorage.setItem('iconPack:' + iface, name);
                /* Mantenemos también el global para retrocompat (cualquier
                   código viejo que lo lea). */
                localStorage.setItem('iconPack', name);
                /* Flag para que tras el reload el shell reabra Temas y
                   active el tab Personalización. */
                var topStorage = (window.top && window.top.sessionStorage) || sessionStorage;
                topStorage.setItem('temas-restore-tab', 'personalize');
            } catch (_) {}
            /* Sync server-side: necesario para que cuando alguien visite
               tu perfil cargue TU icon pack (no el suyo). Esperamos al
               POST antes de recargar — sin .finally había race condition
               donde el reload llegaba antes que la BD se actualizara, y
               el sync server-side en desktop-base.php volvía a setear
               localStorage al valor viejo. */
            fetch('../assets/personalize/api.php?action=set-icon-pack', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pack: name, interface: iface })
            }).catch(function(){}).finally(function() {
                try { window.top.location.reload(); }
                catch (_) { location.reload(); }
            });
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
                + ' src="../assets/img/haro/' + it.slug + 'Haro-preview.png"'
                + ' onerror="this.onerror=null;this.src=\'../assets/vids/' + it.slug + 'Haro-last.png\';"'
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
    fetch('../assets/personalize/api.php?action=set-active-interface', {
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

/* Botón "Probar" de los haros (solo dev, ver MELON_DEV_BUILD): Temas va
   en un iframe, así que le pide al escritorio que saque la notificación
   de prueba (lo atiende assets/dev/dev-desktop.php, también solo dev). */
(function(){
    var btn = document.getElementById('pers-haro-test');
    if (!btn) return;
    btn.addEventListener('click', function(){
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'dev-haro-test' }, location.origin);
        }
    });
})();

function setActiveHaro(item) {
    fetch('../assets/personalize/api.php?action=set-active-haro', {
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
    fetch('../assets/personalize/api.php?action=set-active-mascot', {
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
    fetch('../assets/themes/api.php?action=library')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(!d || !d.ok || !Array.isArray(d.items) || !d.items.length){
                /* El endpoint ya filtra por interfaz activa, así que un
                   array vacío significa "no hay temas para ESTA interfaz".
                   Mensaje contextual con el nombre de la interfaz activa. */
                var ifaceName = (typeof window.getActiveInterface === 'function')
                    ? window.getActiveInterface() : 'win98';
                var ifaceLabel = ifaceName === 'kawaii' ? 'MelonOS Overdose'
                              : ifaceName === 'win98'  ? 'MelonOS 98'
                              : ifaceName;
                grid.innerHTML = '<div id="temas-library-empty">' +
                    'No hay temas publicados para <b>' + escapeHtml(ifaceLabel) + '</b>.<br>' +
                    'Publica uno desde «Mis temas».' +
                    '</div>';
                return;
            }
            grid.innerHTML = '';
            d.items.forEach(function(it){
                var card = document.createElement('div');
                card.className = 'lib-card';

                var prev = document.createElement('div');
                prev.className = 'lib-preview';
                prev.title = 'Probar este tema';
                /* El fondo del preview representa el escritorio del tema:
                   color desktopBg + wallpaper encima si el tema trae uno. */
                var prevColors = migrateLegacyColors(it.colors || {});
                prev.style.backgroundColor = _libColor(prevColors, 'desktopBg', '#008080');
                if (it.wallpaper) {
                    prev.style.backgroundImage    = 'url("../' + it.wallpaper + '")';
                    prev.style.backgroundSize     = 'cover';
                    prev.style.backgroundPosition = 'center';
                }
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
                    ? '<span class="lib-author-av" data-marco-user="' + escapeHtml(it.userKey || '') + '"><img class="lib-author-img" src="../' + it.image + '" alt=""></span>'
                    : '<span class="lib-author-ph">👤</span>';
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
    el.innerHTML = '<img src="../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Quitar de la biblioteca';
    el.addEventListener('click', function(){
        hideThemeCtxMenu();
        fetch('../assets/themes/api.php?action=set-public', {
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
    fetch('../assets/themes/api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            name: name, colors: colors, downloaded: true,
            wallpaper: it.wallpaper || '', startIcon: it.startIcon || '',
            /* Identifica al autor para que el server le premie 50 puntos
               (la primera vez que ESTE usuario descarga este tema). */
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
          return fetch('../assets/themes/api.php?action=set-active', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ name: name })
          }).then(function(r){ return r.json(); })
            .then(function(d2){
                activeName = name;
                var className = name + '-' + labelSafe;
                applyLiveTheme(className, 'assets/themes/' + className + '.css');
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
        fetch('../assets/img/wallpapers/save-wallpaper.php', { method: 'POST', body: fd })
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
        fetch('../assets/img/start-icons/save-start-icon.php', { method: 'POST', body: fd })
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

<!-- LCD filter toggle: sincroniza checkbox <-> localStorage <-> clase en
     <html>. Si esta página está embebida en iframe (mobile.php o
     desktop-base.php cargan temas/ vía iframe), aplicamos también la
     clase en el documento padre — mismo origen, acceso directo OK. -->
<script>
(function(){
    var KEY = 'lcd-filter';
    var cb = document.getElementById('lcd-filter-toggle');
    if (!cb) return;
    /* Default ON: filtro activo salvo que el usuario lo desactive explícitamente. */
    function get(){ try { return localStorage.getItem(KEY) !== '0'; } catch(_) { return true; } }
    function apply(on){
        document.documentElement.classList.toggle('lcd-filter-on', on);
        /* Propagar al window TOP. */
        var topWin = window.top;
        if (topWin !== window) {
            try { topWin.document.documentElement.classList.toggle('lcd-filter-on', on); } catch(_) {}
        }
        /* Propagar a TODOS los iframes hijos del top (mismo origen,
           acceso directo OK). Sin esto, apps abiertas previamente como
           Tienda, Perfil, etc. siguen mostrando el filtro aunque se
           desactive — su boot script solo lee localStorage al cargar. */
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
