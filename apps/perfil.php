<?php
// perfil.php - Profile window + all profile-related dialogs
//
// MODOS DE CARGA:
//   1) INCLUIDO desde desktop-base.php (modo clásico — escritorio del
//      usuario logueado, $desktopLabel ya está definido)
//   2) STANDALONE vía iframe con ?standalone=1 (modo nuevo — usado
//      para "visitar el perfil de otro" cargando SU interfaz/tema/iconos
//      en una ventana separada, sin afectar al shell padre)
//
// En modo standalone emitimos un documento HTML completo con las CSS
// del shell. En modo include, sólo el bloque .window#profile-window.
require_once dirname(__DIR__) . '/assets/config.php';
require_once dirname(__DIR__) . '/assets/mobile-detect.php';

$_perfilStandalone = !empty($_GET['standalone']) && !isset($desktopLabel);
$_perfilIsTablet   = isTabletDevice();
if ($_perfilStandalone) {
    @ini_set('display_errors', '0');
    error_reporting(E_ALL);
    session_start();
    if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
        header('Location: ../index.php'); exit;
    }
    $desktopUserKey = $_SESSION['user'];
    $desktopLabel   = $loginUsers[$desktopUserKey]['label'];
    require_once dirname(__DIR__) . '/db.php';
    require_once dirname(__DIR__) . '/assets/themes/theme-helpers.php';

    /* Si pasaron ?as=USERKEY o ?as=Label, esa será la persona "visitada".
       Cargamos SU interfaz/tema/icon pack en lugar de los del viewer.

       Aceptamos AMBAS formas para mejor UX:
         · user_key directo  (e.g. ?as=user1)
         · label              (e.g. ?as=Capi → resuelve a user1)
       La búsqueda por label es case-insensitive. */
    $_perfilViewingKey = isset($_GET['as']) ? (string)$_GET['as'] : '';
    if ($_perfilViewingKey !== '') {
        if (!isset($loginUsers[$_perfilViewingKey])) {
            /* No match directo por key — busca por label. */
            $needle = strtolower($_perfilViewingKey);
            $resolved = '';
            foreach ($loginUsers as $k => $u) {
                if (strtolower($u['label']) === $needle) { $resolved = $k; break; }
            }
            $_perfilViewingKey = $resolved;   /* '' si tampoco hubo label match */
        }
    }

    /* Resuelve el user_id en BD del "look owner" (visitado si hay ?as,
       sino el propio viewer). Todas las queries de interfaz/tema/icon
       pack van a este UID. */
    $_lookUserKey   = $_perfilViewingKey ?: $desktopUserKey;
    $_lookLabel     = $loginUsers[$_lookUserKey]['label'];
    $_stmtLook = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $_stmtLook->execute([$_lookUserKey]);
    $_lookUid = (int)($_stmtLook->fetchColumn() ?: 0);

    /* INTERFACE: lee active_interface_slug del look owner (JSON string).
       Fallback 'win98'. NOTA: la key vieja `active_interface` era item_id;
       la nueva key es `active_interface_slug` con la slug como string JSON. */
    $_lookIface = 'win98';
    if ($_lookUid) {
        $_st = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_interface_slug'");
        $_st->execute([$_lookUid]);
        $_raw = (string)$_st->fetchColumn();
        if ($_raw !== '') {
            $_slug = json_decode($_raw, true);
            if (is_string($_slug) && $_slug !== ''
                && is_dir(dirname(__DIR__) . '/assets/interfaces/' . $_slug)) {
                $_lookIface = $_slug;
            }
        }
    }

    /* ICON PACK por interfaz. Fallback 'Melon' (default cliente).
       Valor es JSON string (por CHECK json_valid en user_settings). */
    $_lookIconPack = 'Melon';
    if ($_lookUid) {
        $_st = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = ?");
        $_st->execute([$_lookUid, 'icon_pack:' . $_lookIface]);
        $_raw = (string)$_st->fetchColumn();
        if ($_raw !== '') {
            $_v = json_decode($_raw, true);
            if (is_string($_v) && $_v !== '') $_lookIconPack = $_v;
        }
    }

    /* TEMA: el activo del look owner para SU interfaz. */
    refreshActiveThemeCss($_lookUserKey, $_lookLabel);
    $_userThemes = loadUserThemes($_lookUserKey, $_lookIface);
    $activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
    $activeThemeClass = '';
    $activeThemeCss   = '';
    if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
        $activeThemeClass = themeCssClassName($activeTheme, $_lookLabel);
        $_themeRel        = themeCssRelPath($activeTheme, $_lookLabel);
        if (file_exists(dirname(__DIR__) . '/' . $_themeRel)) $activeThemeCss = $_themeRel;
    }

    /* Override del $_COOKIE para que emitInterfaceCss() del shell emita
       el link correcto. Restauramos luego para no afectar más PHP. */
    $_origIfaceCookie = $_COOKIE['activeInterface'] ?? null;
    $_COOKIE['activeInterface'] = $_lookIface;

    /* Pareja del viewer (para keys de funciones que la usan). */
    $stmtP = $pdo->prepare("SELECT p.id FROM parejas p
        JOIN usuarios u1 ON p.usuario1_id = u1.id
        JOIN usuarios u2 ON p.usuario2_id = u2.id
        WHERE u1.username = ? OR u2.username = ?");
    $stmtP->execute([strtolower($desktopLabel), strtolower($desktopLabel)]);
    $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
    $parejaId = $rowP ? (int)$rowP['id'] : 0;

    /* appTitleIcon: helper que normalmente vive en desktop-base.php.
       En modo include lo provee el padre; en standalone lo definimos
       aquí. Lógica idéntica salvo el path base — usamos el de root
       porque emitimos <base href="../"> abajo. */
    if (!function_exists('appTitleIcon')) {
        function appTitleIcon(string $pngName, string $emoji): string {
            $root  = dirname(__DIR__);          // /opt/.../scrapbookOnline
            $rel   = "assets/img/appIcons/{$pngName}.png";
            $melon = "assets/img/appIcons/Melon/{$pngName}.png";
            if (file_exists("{$root}/{$melon}") || file_exists("{$root}/{$rel}")) {
                return '<img src="' . $rel . '" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;" alt="">';
            }
            return $emoji . ' ';
        }
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php if ($_perfilIsTablet): ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <?php else: ?>
    <meta name="viewport" content="width=1280, user-scalable=yes">
    <?php endif; ?>
    <title>Perfil<?= $_perfilViewingKey ? ' — ' . htmlspecialchars($loginUsers[$_perfilViewingKey]['label']) : '' ?></title>
    <!-- <base> hace que TODAS las URLs relativas (en HTML, CSS y JS)
         se resuelvan desde la raíz del proyecto. Sin esto, los cientos
         de "<img src='assets/img/...'>" en perfil.php darían 404 porque
         el documento standalone vive en apps/. Con base, "assets/X"
         resuelve como si fuera /scrapbookOnline/assets/X. -->
    <base href="../">
    <link rel="stylesheet" href="assets/css/98.css">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <?php /* Override del icon pack ANTES de cargar icon-pack.js (que lee
              window.__ICON_PACK_OVERRIDE como primera fuente). Sólo
              aplica si visitamos a otro user → cargamos SU pack. */ ?>
    <script>
        window.__ICON_PACK_OVERRIDE = <?= json_encode($_perfilViewingKey !== '' ? $_lookIconPack : null) ?>;
    </script>
    <script src="assets/js/icon-pack.js"></script>
    <script src="assets/js/cover-fallback.js"></script>
    <link rel="stylesheet" href="assets/css/reproductor.css">
    <link rel="stylesheet" href="assets/css/perfil.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/css/perfil.css'); ?>">
    <link rel="stylesheet" href="assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <?php /* $activeThemeCss ya es root-relative (themeCssRelPath
              devuelve "assets/themes/..."). Con <base href="../"> se
              resuelve correctamente. */ ?>
    <link rel="stylesheet" id="active-theme-link" href="<?= htmlspecialchars($activeThemeCss) ?>">
    <?php endif; ?>
    <?php
        require_once dirname(__DIR__) . '/assets/php/active-interface.php';
        emitInterfaceCss('');
        /* Restauramos la cookie original ahora que emitInterfaceCss
           ya tomó el override. Evita afectar otros require's posteriores. */
        if ($_origIfaceCookie === null) unset($_COOKIE['activeInterface']);
        else $_COOKIE['activeInterface'] = $_origIfaceCookie;
    ?>
    <script src="assets/js/interface-loader.js?v=fs1"></script>
    <style>
        /* Standalone: el iframe contiene 1 sola ventana que llena el
           viewport. Sin escritorio, sin taskbar — el shell padre
           gestiona la posición/drag de la ventana exterior. */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: var(--win-bg, silver); }
        body { display: flex; }
        #profile-window {
            position: static !important;
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            box-sizing: border-box;
            /* Minimum content size — el layout de perfil tiene sidebar
               + main area con anchos mínimos. Sin esto, al achicar la
               ventana del shell por debajo de ese mínimo el contenido
               se cortaba sin scrollbar. */
            min-width: 360px;
            min-height: 320px;
        }
        /* Window body scrolleable como último recurso si el iframe se
           hace MÁS pequeño que el contenido mínimo. Sin esto, el user
           veía elementos cortados al redimensionar muy pequeño. Las
           áreas internas siguen con su propio scroll cuando hay sitio
           (sunken-panel, lists, etc.) — esto solo entra en juego al
           overflow del propio wrapper. */
        #profile-window > .window-body { flex: 1; min-height: 0; overflow: auto; }
        /* En standalone (iframe del perfil ajeno), el "← Volver" no tiene
           sentido — la única forma de salir es cerrando la ventana iframe
           desde el shell padre. Lo ocultamos completamente. */
        #profile-sidebar-back { display: none !important; }
        /* Cursor de arrastre en el title-bar: grab cuando el user
           puede agarrarlo, grabbing mientras lo está moviendo. Los
           botones (min/max/close) mantienen pointer normal. */
        #profile-window > .title-bar { cursor: grab; }
        #profile-window > .title-bar.is-dragging { cursor: grabbing; }
        #profile-window > .title-bar .title-bar-controls,
        #profile-window > .title-bar .title-bar-controls * { cursor: default; }
    </style>
    <script>
        /* Flag global para que la JS de perfil sepa que está en standalone
           y omita registraciones de taskbar / notifSystem (que no existen
           aquí) y cierre vía postMessage en lugar de DOM del shell. */
        window.__PERFIL_STANDALONE = true;
        window.__PERFIL_VIEWING_KEY = <?= json_encode($_perfilViewingKey) ?>;

        /* Mocks de los globals del shell que perfil.php usa pero que no
           existen aquí (no hay escritorio/taskbar). Stub no-op para que
           las llamadas existentes (taskbarManager.register / restore /
           unregister) no tiren ReferenceError. notifSystem ya tiene
           guard `if (window.notifSystem)` en perfil.php → no requiere
           mock; las notificaciones se delegan al shell padre vía
           postMessage si el iframe quiere mostrarlas. */
        window.taskbarManager = {
            register:     function(){},
            unregister:   function(){},
            restore:      function(){},
            minimize:     function(){},
            isRegistered: function(){ return false; },
            getButton:    function(){ return null; }
        };

        /* notifSystem: bridge a parent shell via postMessage. Las notifs
           sí queremos verlas — las muestra el shell con su look. */
        window.notifSystem = {
            show: function(opts) {
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage(
                            { type: 'perfil-notif-show', opts: opts },
                            '*'
                        );
                    }
                } catch (_) {}
            },
            isShown:     function(){ return false; },
            isDismissed: function(){ return false; }
        };
    </script>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">
<?php
}
?>
<!-- PROFILE WINDOW -->
<div class="window" id="profile-window">
    <div class="title-bar">
        <div class="title-bar-text"><?php
            echo appTitleIcon('profileIcon', '👤');
            /* En standalone visitando a otro user mostramos el nombre
               del VISITADO (no del viewer). $_lookLabel se setea arriba
               en el bloque standalone con el label del target. */
            if (!empty($_perfilStandalone) && !empty($_perfilViewingKey) && !empty($_lookLabel)) {
                echo htmlspecialchars($_lookLabel);
            } else {
                echo htmlspecialchars($desktopLabel);
            }
        ?></div>
        <div class="title-bar-controls">
            <button aria-label="Minimize"></button>
            <button aria-label="Maximize"></button>
            <button aria-label="Close" id="profile-close"></button>
        </div>
    </div>
    <div class="window-body" id="profile-body">
        <!-- SIDEBAR IZQUIERDO -->
        <div id="profile-sidebar">
            <div class="profile-sidebar-heading" id="profile-listas-heading"><span id="profile-listas-heading-text">Mis Listas</span></div>
            <div id="profile-listas-nav">
                <div class="profile-nav-item" data-cat="movies">
                    <span class="profile-nav-icon"><img src="assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                    <span class="profile-nav-label">Películas</span>
                    <span class="profile-nav-count" id="profile-count-movies">—</span>
                </div>
                <div class="profile-nav-item" data-cat="series">
                    <span class="profile-nav-icon"><img src="assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                    <span class="profile-nav-label">Series</span>
                    <span class="profile-nav-count" id="profile-count-series">—</span>
                </div>
                <div class="profile-nav-item" data-cat="books">
                    <span class="profile-nav-icon"><img src="assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                    <span class="profile-nav-label">Libros</span>
                    <span class="profile-nav-count" id="profile-count-books">—</span>
                </div>
                <div class="profile-nav-item" data-cat="games">
                    <span class="profile-nav-icon"><img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                    <span class="profile-nav-label">Videojuegos</span>
                    <span class="profile-nav-count" id="profile-count-games">—</span>
                </div>
                <div class="profile-nav-item" data-cat="music">
                    <span class="profile-nav-icon"><img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                    <span class="profile-nav-label">Música</span>
                    <span class="profile-nav-count" id="profile-count-music">—</span>
                </div>
            </div>
            <div id="profile-melon-section">
                <div class="profile-sidebar-heading"><span>Melon reviews</span></div>
                <div id="profile-melon-nav">
                    <div class="profile-nav-item" data-melon="year">
                        <span class="profile-nav-icon"><img src="assets/img/appIcons/bestOfTheYearIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                        <span class="profile-nav-label">Mejor del año</span>
                    </div>
                    <div class="profile-nav-item" data-melon="recent">
                        <span class="profile-nav-icon"><img src="assets/img/appIcons/newsIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                        <span class="profile-nav-label">Reciente</span>
                    </div>
                    <div class="profile-nav-item" data-melon="alltime">
                        <span class="profile-nav-icon"><img src="assets/img/appIcons/calendarioIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
                        <span class="profile-nav-label">Todo el tiempo</span>
                    </div>
                </div>
            </div>
            <div id="profile-social-section">
                <div class="profile-sidebar-heading">Social</div>
                <div id="profile-followed-nav"></div>
                <div class="profile-nav-item" id="profile-nav-social" data-cat="social">
                    <span class="profile-nav-icon">+</span>
                    <span class="profile-nav-label">Explorar</span>
                </div>
            </div>
            <div id="profile-sidebar-footer">
                <div id="profile-sidebar-back">
                    <button class="button" id="profile-catview-back">← Volver</button>
                </div>
                <?php /* Botón solo visible en standalone (iframe del perfil ajeno).
                          Resetea la vista al perfil principal del visitado. */ ?>
                <button class="button" id="profile-go-home" style="display:none;">Volver al perfil</button>
                <button class="button" id="profile-info-edit-btn">✏ Editar perfil</button>
                <button class="button" id="profile-info-edit-save-btn" style="display:none;">💾 Guardar</button>
                <button class="button" id="profile-info-edit-cancel-btn" style="display:none;">Cancelar</button>
                <input type="file" id="profile-photo-input" accept="image/*" style="display:none;">
            </div>
        </div>
        <!-- CONTENIDO PRINCIPAL -->
        <div id="profile-main">
            <!-- VISTA POR DEFECTO -->
            <div id="profile-view-default">
                <div id="profile-top">
                    <div id="profile-avatar-col">
                        <div class="profile-avatar-frame">
                            <?php $profileImg = getUserImage($desktopLabel); ?>
                            <?php if ($profileImg): ?>
                            <img src="<?php echo htmlspecialchars($profileImg); ?>" alt="" class="profile-avatar-img">
                            <?php else: ?>
                            <div class="profile-avatar-placeholder">👤</div>
                            <?php endif; ?>
                        </div>
                        <div id="profile-username"><?php echo htmlspecialchars($desktopLabel); ?></div>
                        <button class="button" id="profile-follow-btn" style="display:none;font-size:9px;margin-top:5px;">+ Seguir</button>
                    </div>
                    <div id="profile-info-col">
                        <div id="profile-info-row">
                            <div id="profile-info-meta"></div>
                            <div id="profile-info-bio" class="pinfo-bio"></div>
                            <div id="profile-info-links"></div>
                        </div>
                    </div>
                </div>
                <div id="profile-posts-area">
                    <div id="profile-posts-header">
                        <span>Posts</span>
                        <button type="button" id="profile-notif-btn" title="Notificaciones">
                            <span class="profile-notif-icon"><img src="assets/img/appIcons/bellIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;display:block;margin:auto;"></span>
                            <span class="profile-notif-badge" id="profile-notif-badge" style="display:none;">0</span>
                        </button>
                    </div>
                    <div id="profile-new-post">
                        <textarea id="profile-post-input" placeholder="Escribe algo..."></textarea>
                        <button class="button" id="profile-post-btn">Publicar</button>
                    </div>
                    <div id="profile-posts-list"></div>
                </div>
            </div>
            <!-- VISTA DE CATEGORÍA -->
            <div id="profile-view-cat">
                <div id="profile-catview-topbar">
                    <div id="profile-catview-avatar-wrap">
                        <div class="profile-avatar-frame">
                            <?php if (isset($profileImg) && $profileImg): ?>
                            <img src="<?php echo htmlspecialchars($profileImg); ?>" alt="" class="profile-avatar-img">
                            <?php else: ?>
                            <div class="profile-avatar-placeholder">👤</div>
                            <?php endif; ?>
                        </div>
                        <div class="profile-catview-username"><?php echo htmlspecialchars($desktopLabel); ?></div>
                    </div>
                    <div id="profile-catview-encurso">
                        <div class="profile-encurso-heading">
                            <span>▶ En curso</span>
                            <div class="profile-catview-pager" id="profile-encurso-pager"></div>
                        </div>
                        <div id="profile-catview-encurso-slots"></div>
                    </div>
                </div>
                <div class="profile-cat-toolbar">
                    <span id="profile-catview-title"><img src="assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Películas</span>
                    <button class="button" id="profile-catview-add-btn">+ Añadir</button>
                </div>
                <div id="profile-catview-sections">
                    <div class="profile-catview-section">
                        <div class="profile-catview-section-head">
                            <span class="profile-catview-section-head-text">Pendientes</span>
                            <div class="profile-catview-pager" id="profile-catview-pending-pager"></div>
                        </div>
                        <div class="profile-gallery" id="profile-catview-pending"></div>
                    </div>
                    <div class="profile-catview-section">
                        <div class="profile-catview-section-head">
                            <span class="profile-catview-section-head-text" id="profile-catview-done-head">Vistas</span>
                            <div class="profile-catview-pager" id="profile-catview-done-pager"></div>
                        </div>
                        <div class="profile-gallery" id="profile-catview-done"></div>
                    </div>
                </div>
            </div>
            <!-- MUSIC VIEW -->
            <div id="profile-view-music">
                <div id="music-catview-topbar">
                    <div id="music-catview-avatar-wrap">
                        <div class="profile-avatar-frame">
                            <?php if (isset($profileImg) && $profileImg): ?>
                            <img src="<?php echo htmlspecialchars($profileImg); ?>" alt="" class="profile-avatar-img">
                            <?php else: ?>
                            <div class="profile-avatar-placeholder">👤</div>
                            <?php endif; ?>
                        </div>
                        <div class="profile-catview-username"><?php echo htmlspecialchars($desktopLabel); ?></div>
                    </div>
                    <div id="music-catview-destacados">
                        <div class="profile-encurso-heading">★ Destacados</div>
                        <div id="music-catview-destacados-slots"></div>
                    </div>
                </div>
                <div class="profile-cat-toolbar">
                    <span id="music-catview-title"><img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Música</span>
                    <button class="button" id="music-catview-add-btn">+ Añadir</button>
                </div>
                <div id="music-tab-bar">
                    <button class="button music-tab" data-tab="albums">Álbumes</button>
                    <button class="button music-tab" data-tab="songs">Canciones</button>
                </div>
                <div id="music-list-wrap">
                    <div id="music-list"></div>
                </div>
            </div>
            <!-- SOCIAL VIEW -->
            <div id="profile-view-social" style="display:none;">
                <div class="profile-social-head">👥 Explorar</div>
                <div id="profile-social-body">
                    <div id="profile-social-explore" class="profile-social-list"></div>
                </div>
            </div>
            <!-- MELON REVIEWS VIEW -->
            <div id="profile-view-melon" style="display:none;">
                <div class="profile-social-head" id="profile-melon-title">⭐ Melon reviews</div>
                <div id="profile-melon-cats">
                    <button class="button melon-cat-btn" data-mcat="movies"><img src="assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Películas</button>
                    <button class="button melon-cat-btn" data-mcat="series"><img src="assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Series</button>
                    <button class="button melon-cat-btn" data-mcat="books"><img src="assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Libros</button>
                    <button class="button melon-cat-btn" data-mcat="games"><img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Videojuegos</button>
                    <button class="button melon-cat-btn" data-mcat="music" data-mtype="album"><img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Álbumes</button>
                    <button class="button melon-cat-btn" data-mcat="music" data-mtype="song"><img src="assets/img/appIcons/songIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Canciones</button>
                </div>
                <div id="profile-melon-body">
                    <div id="profile-melon-status" style="padding:14px;text-align:center;font-size:11px;color:#808080;">Selecciona una categoría</div>
                    <div id="profile-melon-list"></div>
                    <div id="profile-melon-pager"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CHAT WINDOW -->
<div class="window" id="profile-chat-window" style="display:none;position:fixed;z-index:10002;width:340px;height:420px;">
    <div class="title-bar">
        <div class="title-bar-text" id="profile-chat-title" style="display:flex;align-items:center;gap:6px;">
            <span id="profile-chat-title-av" style="width:18px;height:18px;display:inline-block;background:var(--inset-bg,#fff);overflow:hidden;box-shadow:-1px -1px 0 var(--bezel-dark-1,#0a0a0a), 1px 1px 0 var(--bezel-light-1,#fff);flex-shrink:0;"></span>
            <span id="profile-chat-title-text">Chat</span>
            <span id="profile-chat-status"></span>
        </div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-chat-close"></button>
        </div>
    </div>
    <div class="window-body" id="profile-chat-body">
        <div id="profile-chat-messages"></div>
        <!-- Panel Emojis + GIFs (tabs). Oculto por defecto. Se
             posiciona absoluto sobre el input-row al togglear. -->
        <div id="profile-chat-emoji-panel" style="display:none;position:absolute;bottom:48px;left:8px;right:8px;background:var(--win-bg,silver);z-index:5;box-shadow:inset -1px -1px var(--bezel-dark-1,#0a0a0a),inset 1px 1px var(--bezel-light-1,#fff),inset -2px -2px var(--bezel-dark-2,grey),inset 2px 2px var(--bezel-light-2,#dfdfdf);max-height:240px;flex-direction:column;">
            <div id="pf-picker-tabs" style="display:flex;border-bottom:1px solid var(--bezel-dark-1,#808080);flex-shrink:0;">
                <button type="button" data-tab="emoji" class="pf-picker-tab is-active" style="flex:1;border:0;background:var(--win-bg,silver);padding:5px 8px;font-size:11px;cursor:pointer;border-right:1px solid var(--bezel-dark-1,#808080);">Emojis</button>
                <button type="button" data-tab="gifs"  class="pf-picker-tab"           style="flex:1;border:0;background:var(--win-bg,silver);padding:5px 8px;font-size:11px;cursor:pointer;">GIFs</button>
            </div>
            <div id="pf-picker-body" style="flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column;">
                <div id="pf-pane-emoji" data-pane="emoji" style="flex:1;min-height:0;overflow-y:auto;padding:6px;"></div>
                <!-- `display:flex` lo aplica el tab handler (JS) cuando se
                     selecciona la pestaña. Si va inline aquí, sobreescribe
                     al atributo `hidden` y el panel queda visible siempre,
                     pegado al fondo del panel de emojis. -->
                <div id="pf-pane-gifs"  data-pane="gifs"  hidden style="flex:1;min-height:0;flex-direction:column;gap:4px;padding:6px;">
                    <input type="text" id="pf-gif-search" placeholder="Buscar GIFs..." style="font-size:12px;padding:3px 6px;box-sizing:border-box;width:100%;flex-shrink:0;">
                    <div id="pf-gif-results" style="flex:1;min-height:0;overflow-y:auto;display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;"></div>
                    <div style="font-size:9px;color:var(--text-faint,#666);text-align:right;flex-shrink:0;">Powered by GIPHY</div>
                </div>
            </div>
        </div>
        <div id="profile-chat-input-row">
            <input type="text" id="profile-chat-input" maxlength="2000" placeholder="Escribe un mensaje…">
            <button class="button" id="profile-chat-emoji-btn"  type="button" title="Emotes" style="padding:3px 8px;">😀</button>
        </div>
    </div>
</div>

<!-- MELON REVIEWS DETAILS WINDOW -->
<!-- width / min-width / max-width / height / max-height viven en
     perfil.css. El style.width='' del JS al reabrir resetea overrides
     de redimensionado pero NO el ancho base (CSS sigue mandando). -->
<div class="window" id="profile-melon-details-window" style="display:none;flex-direction:column;position:fixed;z-index:10002;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" id="profile-melon-details-title">Reseñas</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-melon-details-close"></button>
        </div>
    </div>
    <!-- Body sin flex:1: así toma su ALTURA NATURAL (suma de las
         reseñas + sus márgenes). La ventana sin height fija crece a
         esa medida. Si el body supera max-height (definido en
         perfil.css), aparece scroll. Una reseña → body pequeño → ventana
         compacta. Muchas reseñas → ventana al límite + scroll. -->
    <!-- La cabecera (carátula + título + nota) queda FIJA; solo el
         listado de reseñas scrollea cuando hay muchas. -->
    <div class="window-body" style="padding:0;flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;">
        <div id="profile-melon-details-head" style="flex-shrink:0;"></div>
        <div id="profile-melon-details-list" style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:6px;"></div>
    </div>
</div>

<!-- EPISODES WINDOW (capítulos de una serie, compartidos) -->
<div class="window" id="profile-episodes-window" style="display:none;flex-direction:column;position:fixed;z-index:10003;width:540px;max-width:95vw;height:72vh;max-height:80vh;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" id="profile-episodes-title">Capítulos</div>
        <div class="title-bar-controls"><button aria-label="Close" id="profile-episodes-close"></button></div>
    </div>
    <div class="window-body" style="padding:0;flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;">
        <div id="profile-episodes-list" style="flex:1;min-height:0;overflow-y:auto;padding:8px;"></div>
        <div id="profile-episodes-addbar" style="flex-shrink:0;border-top:1px solid var(--border);padding:8px;display:none;">
            <div style="font-size:11px;font-weight:bold;margin-bottom:4px;">Añadir capítulo</div>
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
                <label for="ep-add-season" style="font-size:11px;">Temporada</label>
                <select id="ep-add-season" style="flex:1;box-sizing:border-box;"></select>
                <button class="button" id="ep-add-newseason" type="button">+ Temporada</button>
            </div>
            <input type="text" id="ep-add-title" placeholder="Título del capítulo" style="width:100%;box-sizing:border-box;margin-bottom:4px;">
            <input type="text" id="ep-add-image" placeholder="Imagen de preview (URL horizontal)" style="width:100%;box-sizing:border-box;margin-bottom:4px;">
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="number" id="ep-add-dur" placeholder="min" min="0" style="width:80px;box-sizing:border-box;">
                <button class="button default" id="ep-add-btn">+ Añadir capítulo</button>
            </div>
        </div>
    </div>
</div>

<!-- EPISODE REVIEW POPUP (reseña de un capítulo) -->
<div class="window" id="profile-episode-review" style="display:none;flex-direction:column;position:fixed;z-index:10004;width:300px;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" id="profile-episode-review-title">Editar capítulo</div>
        <div class="title-bar-controls"><button aria-label="Close" id="profile-episode-review-close"></button></div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <div style="font-size:11px;margin-bottom:3px;">Título</div>
        <input type="text" id="profile-episode-edit-title" style="width:100%;box-sizing:border-box;margin-bottom:8px;">
        <div style="font-size:11px;margin-bottom:3px;">Imagen (URL horizontal)</div>
        <input type="text" id="profile-episode-edit-image" style="width:100%;box-sizing:border-box;margin-bottom:8px;" placeholder="https://...">
        <div style="font-size:11px;margin-bottom:3px;">Duración (min)</div>
        <input type="number" id="profile-episode-edit-dur" min="0" style="width:90px;box-sizing:border-box;margin-bottom:8px;" placeholder="0">
        <div style="font-size:11px;margin-bottom:3px;">Puntuación</div>
        <div style="display:flex;align-items:center;margin-bottom:8px;">
            <div id="profile-episode-review-stars" style="font-size:24px;letter-spacing:4px;"></div>
            <span id="profile-episode-review-num" style="font-size:13px;margin-left:8px;font-weight:bold;"></span>
        </div>
        <textarea id="profile-episode-review-comment" rows="3" style="width:100%;box-sizing:border-box;resize:none;" placeholder="Comentario (opcional)"></textarea>
        <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:8px;">
            <button class="button" id="profile-episode-review-cancel">Cancelar</button>
            <button class="button default" id="profile-episode-review-save">Guardar</button>
        </div>
    </div>
</div>

<!-- PROFILE NOTIFICATIONS WINDOW -->
<!-- flex-direction:column + body flex:1 + min-height:0 → al resizear
     la ventana, el body se encoge con ella y aparece scroll en lugar
     de items que sobresalen por abajo. -->
<div class="window" id="profile-notifs-window" style="display:none;flex-direction:column;position:fixed;z-index:10002;width:320px;height:60vh;max-height:60vh;min-height:120px;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" style="display:inline-flex;align-items:center;gap:4px;"><img src="assets/img/appIcons/bellIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;">Notificaciones</div>
        <div class="title-bar-controls">
            <!-- Botón engranaje. `aria-label="Settings"` es un valor
                 custom (no presente en 98.css), así que NO hereda el
                 sprite "?" del Help button. Pero las reglas genéricas
                 de themes.css para `.title-bar-controls button[aria-label]`
                 sí matchean — y así el botón coge los mismos colores
                 (bg, bezels, icon-color) del tema activo que los de
                 Close/Min/Max. El SVG del engranaje se aplica como
                 mask-image en themes.css (regla [aria-label=Settings]). -->
            <button aria-label="Settings" id="profile-notifs-settings-btn" title="Preferencias de notificaciones"></button>
            <button aria-label="Close" id="profile-notifs-close"></button>
        </div>
    </div>
    <div class="window-body" id="profile-notifs-body" style="padding:6px;flex:1;min-height:0;overflow-y:auto;">
        <div id="profile-notifs-list"></div>
        <div id="profile-notifs-empty" style="display:none;padding:14px;text-align:center;font-size:11px;color:#808080;">No tienes notificaciones</div>
    </div>
</div>

<!-- Preferencias de sonido de notificaciones -->
<div class="window" id="profile-notif-prefs-window" style="display:none;flex-direction:column;position:fixed;z-index:10003;width:300px;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text">Notificaciones</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-notif-prefs-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:12px;display:flex;flex-direction:column;gap:10px;font-size:11px;">
        <p style="margin:0;">Activar el sonido para:</p>
        <!-- IMPORTANTE: 98.css dibuja el cuadrado visualmente en :before
             del <label> HERMANO del <input>. Si el input está DENTRO de
             un label-padre, el :before no aparece y el checkbox queda
             invisible. Hay que usar siempre <input id> + <label for>.

             Semántica invertida vs storage: el checkbox refleja "suena"
             (marcado = recibe ping). El backend persiste `mute_*`, así
             que al guardar/cargar mapeamos enable = !mute. Default UI:
             todo marcado (todo activo). -->
        <div class="field-row">
            <input type="checkbox" id="np-mute-profile" checked>
            <label for="np-mute-profile">Perfil de otros usuarios (reseñas, posts, follows)</label>
        </div>
        <div class="field-row">
            <input type="checkbox" id="np-mute-social" checked>
            <label for="np-mute-social">Likes y comentarios</label>
        </div>
        <div class="field-row">
            <input type="checkbox" id="np-mute-messages" checked>
            <label for="np-mute-messages">Mensajes (al desactivar, te aparecerás como "no molestar")</label>
        </div>
        <p id="np-status" style="margin:6px 0 0;min-height:14px;color:var(--text-muted,#808080);"></p>
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:4px;">
            <button class="button" id="np-cancel">Cerrar</button>
            <button class="button" id="np-save">Guardar</button>
        </div>
    </div>
</div>

<!-- PROFILE ADD DIALOG
     flex-direction:column + max-width:95vw + max-height:90vh + body
     con flex:1 min-height:0 → la ventana se ajusta a viewports
     pequeños y los campos hacen scroll interno en lugar de salirse
     por abajo o por los lados. -->
<div class="window" id="profile-add-dialog" style="flex-direction:column;min-width:240px;max-width:95vw;max-height:90vh;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" id="profile-add-dialog-title">+ Añadir</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-add-dialog-close"></button>
        </div>
    </div>
    <div class="window-body" style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:auto;">
        <div class="field-row-stacked profile-add-name-wrap" style="flex-shrink:0;">
            <label for="profile-add-name">Nombre</label>
            <input type="text" id="profile-add-name" placeholder="Nombre..." autocomplete="off">
            <!-- Dropdown de autocomplete: aparece debajo del input
                 cuando hay sugerencias (titles ya creados en esa categoría
                 por otros usuarios). Click en una opción autocompleta
                 el input + image. -->
            <div id="profile-add-suggest" class="profile-add-suggest" hidden></div>
        </div>
        <div class="field-row-stacked" style="margin-top:8px;flex-shrink:0;">
            <label for="profile-add-image">Imagen (URL, opcional)</label>
            <input type="text" id="profile-add-image" placeholder="Déjala vacía y se busca sola">
        </div>
        <!-- Duración (pelis) / nº de capítulos (series, libros). El label y
             la visibilidad los ajusta openAddDialog según la categoría.
             Se autorrellena al elegir una sugerencia. -->
        <div id="profile-add-runtime-row" class="field-row-stacked" style="margin-top:8px;flex-shrink:0;display:none;">
            <label id="profile-add-runtime-label" for="profile-add-runtime">Duración (min)</label>
            <input type="number" id="profile-add-runtime" min="0" step="1" placeholder="0">
        </div>
        <!-- Series: gestionar capítulos al añadir (compartidos por título). -->
        <div id="profile-add-eps-row" style="margin-top:8px;flex-shrink:0;display:none;">
            <button class="button" id="profile-add-eps-btn" type="button">🎬 Capítulos</button>
        </div>
        <!-- Libros: enlace a un PDF (compartido por título). -->
        <div id="profile-add-pdf-row" class="field-row-stacked" style="margin-top:8px;flex-shrink:0;display:none;">
            <label for="profile-add-pdf">PDF (URL, opcional)</label>
            <input type="text" id="profile-add-pdf" placeholder="https://....pdf  o  Drive .../preview">
        </div>
        <p id="profile-add-error" style="color:#c00;font-size:10px;margin:6px 0 0;min-height:14px;flex-shrink:0;"></p>
        <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:4px;flex-shrink:0;flex-wrap:wrap;">
            <button class="button" id="profile-add-dialog-cancel">Cancelar</button>
            <button class="button" id="profile-add-dialog-submit">Añadir</button>
        </div>
    </div>
</div>

<!-- REVIEW VIEW WINDOW (ver una reseña concreta) -->
<div class="window" id="profile-review-view" style="display:none;flex-direction:column;position:fixed;z-index:10002;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text">★ Review</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-review-view-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:0;flex:1;min-height:0;overflow:auto;">
        <!-- min-width:0 + overflow-wrap:anywhere → un comentario sin
             espacios o un username largo se rompe en vez de empujar
             el contenedor más ancho que la ventana. -->
        <div style="padding:16px 18px 14px;min-width:0;overflow-wrap:anywhere;word-break:break-word;">
            <div id="profile-review-view-comment"></div>
            <div id="profile-review-view-header"></div>
        </div>
    </div>
</div>

<!-- REVIEW PROMPT -->
<div class="window" id="profile-review-prompt" style="display:none;position:fixed;z-index:10002;width:270px;">
    <div class="title-bar">
        <div class="title-bar-text">¿Añadir una review?</div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <p id="profile-review-prompt-msg" style="margin:0 0 12px;font-size:11px;"></p>
        <div class="field-row" style="justify-content:flex-end;gap:4px;">
            <button class="button" id="profile-review-prompt-no">No, gracias</button>
            <button class="button" id="profile-review-prompt-yes">Sí</button>
        </div>
    </div>
</div>

<!-- REVIEW WINDOW -->
<div class="window" id="profile-review-window" style="display:none;flex-direction:column;position:fixed;z-index:10002;">
    <div class="title-bar" style="flex-shrink:0;">
        <div class="title-bar-text" id="profile-review-window-title">Review</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-review-window-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;flex:1;min-height:0;display:flex;flex-direction:column;overflow:auto;">
        <!-- Edición del item: título + imagen + (duración) + nota + reseña. -->
        <div class="field-row-stacked" style="margin-bottom:8px;flex-shrink:0;">
            <label for="profile-review-title" style="font-size:11px;margin-bottom:3px;">Título</label>
            <input type="text" id="profile-review-title" style="width:100%;box-sizing:border-box;">
        </div>
        <div class="field-row-stacked" style="margin-bottom:8px;flex-shrink:0;">
            <label for="profile-review-image" style="font-size:11px;margin-bottom:3px;">Imagen (URL, opcional)</label>
            <input type="text" id="profile-review-image" style="width:100%;box-sizing:border-box;" placeholder="Déjala vacía y se busca sola">
        </div>
        <div id="profile-review-rt-row" class="field-row-stacked" style="margin-bottom:8px;flex-shrink:0;display:none;">
            <label id="profile-review-rt-label" for="profile-review-rt" style="font-size:11px;margin-bottom:3px;">Duración (min)</label>
            <input type="number" id="profile-review-rt" min="0" step="1" style="width:120px;box-sizing:border-box;" placeholder="0">
        </div>
        <!-- Series: en lugar de un número, un botón a la ventana de capítulos. -->
        <div id="profile-review-eps-row" class="field-row" style="margin-bottom:8px;flex-shrink:0;display:none;">
            <button class="button" id="profile-review-eps-btn" type="button">🎬 Capítulos</button>
        </div>
        <!-- Libros: enlace a un PDF (compartido por título). Al clicar el
             título del libro en Melon Reviews se abre embebido. -->
        <div id="profile-review-pdf-row" class="field-row-stacked" style="margin-bottom:8px;flex-shrink:0;display:none;">
            <label for="profile-review-pdf" style="font-size:11px;margin-bottom:3px;">PDF (URL) — al clicar el título del libro se abre</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="text" id="profile-review-pdf" style="flex:1;box-sizing:border-box;" placeholder="https://....pdf  o  Drive .../preview">
                <button class="button" id="profile-review-pdf-btn" type="button">📖 Abrir</button>
            </div>
        </div>
        <div id="profile-review-only-completed" style="font-size:11px;margin-bottom:8px;flex-shrink:0;color:var(--text-faint);display:none;">Marca el item como completado para poder puntuarlo y reseñarlo.</div>
        <div id="profile-review-score-label" style="font-size:11px;margin-bottom:3px;flex-shrink:0;">Puntuación</div>
        <div id="profile-review-score-row" style="display:flex;align-items:center;margin-bottom:10px;flex-shrink:0;">
            <div id="profile-review-stars" style="font-size:26px;letter-spacing:4px;"></div>
            <span id="profile-review-stars-num" style="font-size:14px;margin-left:10px;min-width:2em;font-weight:bold;"></span>
        </div>
        <div id="profile-review-comment-row" class="field-row-stacked" style="margin-bottom:10px;flex:1;min-height:0;display:flex;flex-direction:column;">
            <label for="profile-review-comment" style="font-size:11px;margin-bottom:3px;flex-shrink:0;">Comentario (opcional)</label>
            <!-- resize:none — el resize:vertical permitía al usuario
                 arrastrar el handle y agrandar el textarea fuera de su
                 slot flex, empujando los botones hasta solaparse con
                 el área del texto. Sin resize manual el textarea solo
                 crece/encoge con la ventana (vía flex:1). -->
            <textarea id="profile-review-comment" rows="4" style="resize:none;width:100%;box-sizing:border-box;flex:1;min-height:60px;" placeholder="Escribe tu opinión..."></textarea>
        </div>
        <div class="field-row" style="justify-content:flex-end;gap:4px;flex-shrink:0;flex-wrap:wrap;">
            <button class="button" id="profile-review-window-delete" style="margin-right:auto;display:none;"><img src="assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Eliminar</button>
            <button class="button" id="profile-review-window-cancel">Cancelar</button>
            <button class="button" id="profile-review-window-submit">Guardar</button>
        </div>
    </div>
</div>

<!-- PROFILE INVITE DIALOG -->
<div class="window" id="profile-invite-dialog" style="display:none;position:fixed;z-index:10002;width:260px;">
    <div class="title-bar">
        <div class="title-bar-text">👥 Colaboradores</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-invite-close"></button>
        </div>
    </div>
    <div class="window-body">
        <p id="profile-invite-msg" style="margin:0 0 6px;font-size:11px;"></p>
        <div id="profile-invite-users"></div>
        <p id="profile-invite-status"></p>
        <div class="field-row" style="justify-content:flex-end;margin-top:6px;">
            <button class="button" id="profile-invite-cancel">Cerrar</button>
        </div>
    </div>
</div>


<!-- PROFILE PHOTO CROP DIALOG -->
<div class="window" id="profile-photo-crop-dialog" style="display:none;position:fixed;z-index:10003;width:420px;">
    <div class="title-bar">
        <div class="title-bar-text">✂ Recortar foto (1:1)</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-photo-crop-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <p style="font-size:10px;margin:0 0 6px;color:var(--text-muted);">Arrastra el recuadro para mover, las esquinas para redimensionar.</p>
        <div id="profile-photo-crop-stage">
            <img id="profile-photo-crop-img" alt="">
            <div id="profile-photo-crop-region">
                <div class="photo-crop-handle" data-corner="nw"></div>
                <div class="photo-crop-handle" data-corner="ne"></div>
                <div class="photo-crop-handle" data-corner="sw"></div>
                <div class="photo-crop-handle" data-corner="se"></div>
            </div>
        </div>
        <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:10px;">
            <button class="button" id="profile-photo-crop-cancel">Cancelar</button>
            <button class="button" id="profile-photo-crop-confirm">Recortar y aplicar</button>
        </div>
    </div>
</div>

<!-- PROFILE SOCIAL LINK ADD DIALOG (edit mode) -->
<div class="window" id="profile-social-add-dialog" style="display:none;position:fixed;z-index:10002;width:280px;">
    <div class="title-bar">
        <div class="title-bar-text">+ Añadir conexión</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="profile-social-add-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <div class="field-row-stacked">
            <label for="profile-social-add-platform" style="font-size:10px;">Plataforma</label>
            <select id="profile-social-add-platform" style="width:100%;box-sizing:border-box;">
                <option value="steam">Steam</option>
                <option value="discord">Discord</option>
                <option value="twitter">Twitter / X</option>
                <option value="instagram">Instagram</option>
            </select>
        </div>
        <div class="field-row-stacked" style="margin-top:6px;">
            <label for="profile-social-add-value" style="font-size:10px;">Usuario o URL</label>
            <input type="text" id="profile-social-add-value" maxlength="200" placeholder="@usuario o https://..." style="width:100%;box-sizing:border-box;">
        </div>
        <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:10px;">
            <button class="button" id="profile-social-add-cancel">Cancelar</button>
            <button class="button" id="profile-social-add-confirm">Añadir</button>
        </div>
    </div>
</div>

<!-- MUSIC ADD DIALOG -->
<div class="window" id="music-add-dialog" style="display:none;position:fixed;z-index:10002;width:320px;">
    <div class="title-bar">
        <div class="title-bar-text" id="music-add-dialog-title">+ Añadir música</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="music-add-close"></button>
        </div>
    </div>
    <div class="window-body" style="padding:10px 12px 12px;">
        <div id="music-add-step1">
            <p style="margin:0 0 10px;font-size:11px;">¿Qué quieres añadir?</p>
            <div class="field-row" style="gap:6px;">
                <button class="button" id="music-add-type-song" style="flex:1;"><img src="assets/img/appIcons/songIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Canción</button>
                <button class="button" id="music-add-type-album" style="flex:1;"><img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Álbum / Playlist</button>
            </div>
        </div>
        <div id="music-add-step2" style="display:none;">
            <div class="field-row-stacked">
                <label id="music-add-url-label" for="music-add-url">Enlace de YouTube o Spotify</label>
                <input type="text" id="music-add-url" placeholder="https://..." style="width:100%;box-sizing:border-box;">
            </div>
            <div id="music-add-preview" style="min-height:18px;font-size:10px;margin:4px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
            <div class="field-row-stacked" style="margin-top:4px;">
                <label for="music-add-artist">Artista</label>
                <input type="text" id="music-add-artist" placeholder="Artista..." style="width:100%;box-sizing:border-box;">
            </div>
            <p id="music-add-error" style="color:#c00;font-size:10px;margin:3px 0 0;min-height:14px;"></p>
            <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:8px;">
                <button class="button" id="music-add-back">← Atrás</button>
                <button class="button" id="music-add-cancel">Cancelar</button>
                <button class="button" id="music-add-submit">Siguiente →</button>
            </div>
        </div>
        <div id="music-add-step3" style="display:none;">
            <div id="music-add-review-prompt">
                <p style="margin:0 0 10px;font-size:11px;">¿Quieres añadir una reseña?</p>
                <div class="field-row" style="justify-content:flex-end;gap:4px;">
                    <button class="button" id="music-add-review-no">No, guardar</button>
                    <button class="button" id="music-add-review-yes">★ Sí</button>
                </div>
            </div>
            <div id="music-add-review-form" style="display:none;">
                <div style="display:flex;align-items:center;margin-bottom:8px;">
                    <div id="music-add-review-stars" style="font-size:24px;letter-spacing:3px;cursor:pointer;user-select:none;-webkit-user-select:none;"></div>
                    <span id="music-add-review-stars-num" style="font-size:13px;margin-left:10px;min-width:2em;font-weight:bold;"></span>
                </div>
                <div class="field-row-stacked" style="margin-bottom:4px;">
                    <label for="music-add-review-comment" style="font-size:11px;margin-bottom:3px;">Comentario (opcional)</label>
                    <textarea id="music-add-review-comment" rows="3" style="resize:vertical;width:100%;box-sizing:border-box;" placeholder="Tu opinión..."></textarea>
                </div>
                <div class="field-row" style="justify-content:flex-end;gap:4px;margin-top:8px;">
                    <button class="button" id="music-add-review-back2">← Atrás</button>
                    <button class="button" id="music-add-review-save">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lightbox de imágenes de posts (click en preview → pantalla completa) -->
<div id="profile-post-lightbox" role="dialog" aria-hidden="true">
    <button type="button" id="profile-post-lightbox-close" title="Cerrar (Esc)">×</button>
    <img id="profile-post-lightbox-img" src="" alt="" referrerpolicy="no-referrer">
</div>

<script>
/* =========================
   PROFILE
========================= */
var PROFILE_USERS = <?php
    $udata = [];
    foreach ($loginUsers as $k => $u) {
        $udata[$k] = ['label' => $u['label'], 'image' => getUserImage($u['label'])];
    }
    echo json_encode($udata);
?>;

(function() {
    var profileWin = document.getElementById('profile-window');
    if (!profileWin) return;

    var lists        = { movies: [], series: [], books: [], games: [], music: [] };
    var loaded       = false;
    var addDialogCat = null;
    var currentCat   = null;
    var currentMusicTab = 'albums';

    /* EXPOSE EL INIT TEMPRANO — antes de los IIFE internos que pueden
       crashear con "null is not an object" en standalone (DOM parcial).
       Las funciones referenciadas adentro (loadLists, loadProfile, etc.)
       son `function` declarations → hoisted, accesibles aunque su
       definición esté más abajo en este IIFE. */
    /* Espera (con poll suave) a que las `lists` del visitado se hayan
       poblado tras viewOtherUser, busca el item por título (case-
       insensitive) en la categoría dada y abre su reseña vía
       showReviewView. Si no se encuentra tras N intentos, se rinde
       en silencio (la reseña pudo haberse borrado entre la notif y
       el click). */
    function _perfilOpenReviewWhenReady(cat, title, mtype, ownerKey) {
        if (!cat || !title) return;
        /* Dueño esperado de la reseña = el reseñador (el perfil que estamos
           abriendo). Por defecto, el usuario de este iframe standalone. */
        var expectedOwner = ownerKey || window.__PERFIL_VIEWING_KEY || null;
        var attempts = 0;
        var maxAttempts = 60;   /* ~6 segundos a 100ms (viewOtherUser es async) */
        var titleNorm = String(title).toLowerCase().trim();
        var navigated = false;
        var tick = function(){
            attempts++;
            /* CLAVE: esperar a que las `lists` sean las del DUEÑO de la
               reseña. En el arranque, loadLists() carga primero MIS listas
               y viewOtherUser las reemplaza de forma asíncrona. Sin esta
               guarda, el primer tick leía MIS listas y mostraba MI reseña
               del item en vez de la del reseñador (bug "primera vez"). */
            if (expectedOwner && (typeof viewingUser === 'undefined' || viewingUser !== expectedOwner)) {
                if (attempts < maxAttempts) return setTimeout(tick, 100);
                return;
            }
            if (typeof lists === 'undefined' || !lists || !lists[cat]) {
                if (attempts < maxAttempts) return setTimeout(tick, 100);
                return;
            }
            var items = lists[cat] || [];
            var found = null;
            for (var i = 0; i < items.length; i++) {
                if (items[i] && items[i].title && items[i].title.toLowerCase().trim() === titleNorm) {
                    found = items[i]; break;
                }
            }
            /* Navega a la sección correspondiente la PRIMERA vez que
               podemos (lists ya están cargadas, aunque no hayamos
               encontrado el item aún). Música → showMusicView con tab
               'albums' o 'songs' según mtype del item. Resto → showCatView. */
            if (!navigated) {
                navigated = true;
                if (cat === 'music' && typeof showMusicView === 'function') {
                    /* Determina tab: prefiere mtype del item encontrado;
                       fallback a mtype del param; fallback a 'albums'. */
                    var mt = (found && found.type) || mtype || 'album';
                    var tab = (mt === 'song') ? 'songs' : 'albums';
                    showMusicView();
                    if (typeof renderMusicView === 'function') renderMusicView(tab);
                } else if (typeof showCatView === 'function') {
                    showCatView(cat);
                }
            }
            if (found && found.review && typeof showReviewView === 'function') {
                showReviewView(found.review, found);
                return;
            }
            if (!found && attempts < maxAttempts) {
                /* Lists puede que aún no esté del visitado (timing con
                   viewOtherUser que es async). Reintenta. */
                return setTimeout(tick, 100);
            }
        };
        tick();
    }
    /* Permite al perfil ya abierto recibir un deep-link nuevo (cuando el
       shell padre nos manda postMessage en vez de recargar el iframe). */
    window.addEventListener('message', function(e){
        if (!e || !e.data) return;
        if (e.data.type === 'perfil-open-review' && e.data.reviewCategory && e.data.reviewTitle) {
            _perfilOpenReviewWhenReady(e.data.reviewCategory, e.data.reviewTitle, e.data.reviewMtype || '');
        }
        /* Petición desde la ventana standalone de otro usuario: abrir el
           EDITOR de reseña de un item de MÚSICA recién añadido a MI perfil,
           en MI app (no en la del otro user). Solo lo atiende la instancia
           principal (incluida en el shell), no las standalone. */
        if (e.data.type === 'perfil-open-own-music-review' && e.data.title && !window.__PERFIL_STANDALONE) {
            _openOwnMusicReviewByTitle(e.data.title);
        }
    });

    /* Abre, en MI perfil, el editor de reseña del item de música con ese
       título (recién copiado desde el perfil de otra persona). */
    function _openOwnMusicReviewByTitle(title) {
        var titleNorm = String(title || '').toLowerCase().trim();
        if (!titleNorm) return;
        if (typeof viewingUser !== 'undefined' && viewingUser && typeof exitViewingUser === 'function') exitViewingUser();
        if (typeof openProfileWindow === 'function') openProfileWindow();
        var go = function(){
            if (typeof showMusicView === 'function') showMusicView();
            var arr = (typeof lists !== 'undefined' && lists && lists.music) ? lists.music : [];
            for (var i = 0; i < arr.length; i++) {
                if (arr[i] && (arr[i].title || '').toLowerCase().trim() === titleNorm) {
                    if (typeof showMusicReviewWindow === 'function') showMusicReviewWindow(i);
                    return;
                }
            }
        };
        /* Recargamos MIS listas para incluir el item recién guardado. */
        if (typeof loadLists === 'function') loadLists(function(){ if (typeof updateCounts === 'function') updateCounts(); go(); });
        else go();
    }

    window.__perfilStandaloneInit = function() {
        loaded = true;
        console.log('[PERFIL standalone] init, viewingKey:', window.__PERFIL_VIEWING_KEY);
        if (typeof loadLists === 'function') loadLists(typeof updateCounts === 'function' ? updateCounts : function(){});
        if (typeof loadProfile === 'function') {
            loadProfile(function() {
                var asKey = window.__PERFIL_VIEWING_KEY;
                console.log('[PERFIL standalone] loadProfile done, asKey:', asKey);
                if (asKey && typeof viewOtherUser === 'function') {
                    console.log('[PERFIL standalone] calling viewOtherUser:', asKey);
                    viewOtherUser(asKey);
                    /* Deep-link a una reseña concreta (?reviewCat=...&reviewTitle=...).
                       Usado por las notificaciones de tipo 'review' para
                       llevar al usuario directamente a la reseña en vez
                       de solo al perfil. Espera a que viewOtherUser cargue
                       las lists del visitado y entonces busca el item por
                       título y abre showReviewView. */
                    try {
                        var q = new URLSearchParams(location.search);
                        var rc = q.get('reviewCat');
                        var rt = q.get('reviewTitle');
                        var rm = q.get('reviewMtype') || '';
                        if (rc && rt) _perfilOpenReviewWhenReady(rc, rt, rm, asKey);
                    } catch (_) {}
                }
            });
        }
        if (typeof loadProfileNotifs === 'function') loadProfileNotifs();

        /* Botón "Ver perfil principal" — solo en standalone. Cuando el
           user clica resetea la vista al perfil por defecto del visitado
           (oculta categorías, social, melon) y muestra info+posts. */
        var goHomeBtn = document.getElementById('profile-go-home');
        if (goHomeBtn) {
            goHomeBtn.style.display = '';
            goHomeBtn.addEventListener('click', function() {
                /* Misma lógica que viewOtherUser usa para mostrar el
                   perfil por defecto del visitado al cargarlo. */
                var ids = ['profile-view-cat', 'profile-view-social', 'profile-view-melon', 'profile-view-music'];
                ids.forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });
                var def = document.getElementById('profile-view-default');
                if (def) def.style.display = 'flex';
            });
        }
    };

    var CATS = {
        movies: { label: 'Películas',   icon: '<img src="assets/img/appIcons/pelisIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' },
        series: { label: 'Series',      icon: '<img src="assets/img/appIcons/melonArchiveIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' },
        books:  { label: 'Libros',      icon: '<img src="assets/img/appIcons/booksIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' },
        games:  { label: 'Videojuegos', icon: '<img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' },
        music:  { label: 'Música',      icon: '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' }
    };
    /* Iconos de categoría usados como PLACEHOLDER cuando un item no tiene
       imagen — antes era 🖼 genérico para todos; ahora cada categoría
       muestra su propio icono semitransparente. Se usa también para el
       empty-state de cada tab (gallery sin items). */
    var CAT_PLACEHOLDER_ICON = {
        movies: 'assets/img/appIcons/pelisIcon.png',
        series: 'assets/img/appIcons/melonArchiveIcon.png',
        books:  'assets/img/appIcons/booksIcon.png',
        games:  'assets/img/appIcons/juegosIcon.png',
        music:  'assets/img/appIcons/musicaIcon.png'
    };
    function catPlaceholderHTML(cat, size) {
        var src = CAT_PLACEHOLDER_ICON[cat];
        var sz  = size || 48;
        if (!src) return '<div class="profile-gallery-placeholder">🖼</div>';
        return '<div class="profile-gallery-placeholder">' +
            '<img src="' + src + '" alt="" style="width:' + sz + 'px;height:' + sz + 'px;object-fit:contain;image-rendering:pixelated;opacity:0.55;">' +
            '</div>';
    }

    var DONE_LABELS = { movies: 'Vistas', series: 'Vistas', books: 'Leídas', games: 'Jugadas' };
    var CAT_VERBS   = { movies: 'ver', series: 'ver', books: 'leer', games: 'jugar', music: 'escuchar' };

    var STATUS_CYCLE  = ['pending', 'in-progress', 'completed'];
    var STATUS_LABELS = {
        'pending':     '○ Pendiente',
        'in-progress': '◑ En curso',
        'completed':   '● Completado'
    };

    /* Formatea un unix-ts como "DD MMM YYYY" (ej: "8 jun 2026") — corto
       y legible, suficiente para el footer del item. */
    var SHORT_MONTHS = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    function fmtShortDate(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        return d.getDate() + ' ' + SHORT_MONTHS[d.getMonth()] + ' ' + d.getFullYear();
    }
    /* Renderiza el contenido del footer del item. Para los items
       completados con fecha, mostramos SOLO la fecha (la sección
       "Completados" del catview ya identifica que está completado, así
       que la etiqueta sería redundante). Para los otros estados,
       devolvemos la etiqueta — solo se ve momentáneamente justo tras
       un cambio de status antes del re-render. */
    function renderStatusFooter(item) {
        var status = item.status || 'pending';
        if (status === 'completed' && item.completedAt) {
            return fmtShortDate(item.completedAt);
        }
        return STATUS_LABELS[status];
    }

    var confirmFn = window.win98Confirm || function(msg, title, cb) { if (confirm(msg)) cb(); };

    /* ──── Data ──── */
    function loadLists(cb) {
        fetch('assets/profile/api.php?action=get-lists')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && !data.error) {
                    /* get-lists devuelve SIEMPRE las listas del usuario de
                       sesión. Si ahora mismo estamos viendo el perfil de
                       otra persona, `lists` contiene LAS SUYAS: escribir
                       aquí las mías dejaba su perfil mostrando mi
                       contenido en cuanto cualquier callback llamara a
                       loadLists (notif de item-edited, respuesta a una
                       invitación, alta de música…). En ese caso
                       refrescamos el backup propio y dejamos `lists`
                       intacta; viewOtherUser ya la restaura al volver. */
                    var destino = viewingUser ? (ownLists = ownLists || {}) : lists;
                    ['movies', 'series', 'books', 'games', 'music'].forEach(function(k) {
                        destino[k] = Array.isArray(data[k]) ? data[k] : [];
                    });
                }
                if (cb) cb();
            })
            .catch(function() { if (cb) cb(); });
    }

    function saveCategory(cat) {
        function listSig(arr) {
            return (arr || []).map(function(x){ return x.id + '|' + (x.image || ''); }).join(',');
        }
        var prevSig = listSig(lists[cat]);
        fetch('assets/profile/api.php?action=save-lists', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: lists[cat] })
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              /* El backend asigna IDs definitivos a los items nuevos
                 (los que llevaban id "item_...") y devuelve el snapshot
                 canónico. Reemplazar la lista local para que el próximo
                 save no duplique. */
              if (d && Array.isArray(d.items)) {
                  lists[cat] = d.items;
                  /* Si los IDs cambiaron (item nuevo recibió su id real),
                     re-renderizar la vista activa para que los closures del
                     DOM apunten a los nuevos objetos. Sin esto, un ítem recién
                     creado no respondería a clicks hasta recargar. También
                     si cambió alguna foto: el servidor copia las nuevas en
                     local y busca una a los items que se guardan sin foto. */
                  if (listSig(lists[cat]) !== prevSig) {
                      if (cat === 'music' && currentCat === 'music') {
                          renderMusicView(currentMusicTab); renderMusicDestacados();
                      } else if (currentCat === cat) {
                          renderCatView(cat); renderCatEncurso(cat);
                      }
                  }
              }
          }).catch(function() {});
    }

    function updateCounts() {
        ['movies', 'series', 'books', 'games'].forEach(function(cat) {
            var el = document.getElementById('profile-count-' + cat);
            if (el) el.textContent = lists[cat].length;
        });
        var musicEl = document.getElementById('profile-count-music');
        if (musicEl) musicEl.textContent = lists.music.length;
    }

    /* ──── Context menu ──── */
    var _ctxMenu = null;

    function showCtxMenu(x, y, options) {
        if (!options || !options.length) return;
        hideCtxMenu();
        var menu = document.createElement('div');
        menu.className = 'profile-ctx-menu';
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';
        options.forEach(function(opt) {
            var el = document.createElement('div');
            el.className = 'profile-ctx-option' + (opt.disabled ? ' disabled' : '');
            el.textContent = opt.label;
            if (!opt.disabled) {
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    hideCtxMenu();
                    opt.action();
                });
            }
            menu.appendChild(el);
        });
        document.body.appendChild(menu);
        _ctxMenu = menu;
    }

    function hideCtxMenu() {
        if (_ctxMenu) { _ctxMenu.remove(); _ctxMenu = null; }
    }

    document.addEventListener('mousedown', function(e) {
        if (_ctxMenu && !_ctxMenu.contains(e.target)) hideCtxMenu();
    });

    /* ──── En curso: 3 slots por página, paginación si hay más ──── */
    var ENCURSO_PER_PAGE = 3;
    var _encursoPage = {};   /* { cat: pageIndex } */
    function renderCatEncurso(cat) {
        var slotsEl = document.getElementById('profile-catview-encurso-slots');
        if (!slotsEl) return;
        slotsEl.innerHTML = '';
        var inProgress = (lists[cat] || []).filter(function(i) { return i.status === 'in-progress'; });
        var totalPages = Math.max(1, Math.ceil(inProgress.length / ENCURSO_PER_PAGE));
        if (_encursoPage[cat] === undefined) _encursoPage[cat] = 0;
        if (_encursoPage[cat] >= totalPages) _encursoPage[cat] = totalPages - 1;
        if (_encursoPage[cat] < 0) _encursoPage[cat] = 0;
        var pageStart = _encursoPage[cat] * ENCURSO_PER_PAGE;
        var pageItems = inProgress.slice(pageStart, pageStart + ENCURSO_PER_PAGE);
        for (var s = 0; s < ENCURSO_PER_PAGE; s++) {
            var item = pageItems[s] || null;
            var slot = document.createElement('div');
            slot.className = 'profile-encurso-slot' + (item ? ' filled' : '');

            var tb = document.createElement('div');
            tb.className = 'profile-encurso-slot-tb';
            tb.textContent = item ? item.title : '—';
            slot.appendChild(tb);

            var body = document.createElement('div');
            body.className = 'profile-encurso-slot-body';
            body.style.position = 'relative';
            if (item && item.image) {
                var img = document.createElement('img');
                img.src = item.image;
                img.alt = item.title;
                img.setAttribute('data-cover-cat', cat);
                img.setAttribute('data-cover-title', item.title);
                (function(b, ct) {
                    img.onerror = function() { b.innerHTML = catPlaceholderHTML(ct, 42); };
                })(body, cat);
                body.appendChild(img);
            } else if (item) {
                body.innerHTML = catPlaceholderHTML(cat, 42);
            }
            if (item) {
                /* Construye la lista de keys a mostrar en la strip:
                   - Si soy invitado (item.sharedFrom), el host va primero
                     y luego los otros colaboradores (el backend ya me
                     filtró a mí mismo).
                   - Si soy el dueño, solo los colaboradores. */
                var collabKeys = [];
                if (item.sharedFrom) collabKeys.push(item.sharedFrom);
                if (item.collaborators && item.collaborators.length) {
                    item.collaborators.forEach(function(k) {
                        if (k !== item.sharedFrom) collabKeys.push(k);
                    });
                }
                if (collabKeys.length) {
                    var strip = document.createElement('div');
                    strip.className = 'profile-gallery-collabs';
                    collabKeys.forEach(function(uKey) {
                        var uInfo = PROFILE_USERS[uKey];
                        if (!uInfo) return;
                        var avFrame = document.createElement('div');
                        avFrame.className = 'profile-avatar-frame';
                        avFrame.title = uInfo.label;
                        if (uInfo.image) {
                            var avImg = document.createElement('img');
                            avImg.src = uInfo.image;
                            avImg.alt = uInfo.label;
                            avFrame.appendChild(avImg);
                        }
                        strip.appendChild(avFrame);
                    });
                    body.appendChild(strip);
                }
            }
            slot.appendChild(body);

            if (item) {
                (function(it) {
                    slot.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        if (viewingUser) {
                            showCtxMenu(e.clientX, e.clientY, [
                                { label: '+ Añadir a mi perfil', action: function() { addItemToOwnProfile(cat, it); }}
                            ]);
                            return;
                        }
                        showCtxMenu(e.clientX, e.clientY, [
    { label: '✏ Editar', action: function() {
                                var i = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (i !== -1) showReviewWindow(cat, i);
                            }},
    { label: '✓ Completar', action: function() {
                                var idx = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (idx !== -1) {
                                    lists[cat][idx].status = 'completed';
                                    if (!lists[cat][idx].completedAt) {
                                        lists[cat][idx].completedAt = Math.floor(Date.now() / 1000);
                                    }
                                    /* Serie completada → marca todos sus capítulos como vistos. */
                                    if (cat === 'series' && lists[cat][idx].title) {
                                        fetch('assets/profile/api.php?action=complete-series-episodes', {
                                            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({ seriesTitle: lists[cat][idx].title })
                                        }).catch(function(){});
                                    }
                                    saveCategory(cat);
                                    renderCatView(cat);
                                    renderCatEncurso(cat);
                                    showReviewPrompt(cat, idx);
                                    crearMomentoDesdeItem(cat, lists[cat][idx]);
                                }
                            }},
                            { label: '✕ Quitar de en curso', action: function() {
                                var idx = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (idx !== -1) {
                                    lists[cat][idx].status = 'pending';
                                    delete lists[cat][idx].completedAt;
                                    saveCategory(cat);
                                    renderCatView(cat);
                                    renderCatEncurso(cat);
                                }
                            }},
                            { label: '👥 Colaboradores', action: function() {
                                showCollabDialog(cat, it);
                            }}
                        ]);
                    });
                })(item);
            }

            slotsEl.appendChild(slot);
        }
        /* Paginación en el header (mismo patrón que Pendientes/Vistas):
           contenedor #profile-encurso-pager dentro de .profile-encurso-heading.
           No ocupa espacio del área de slots → no los encoge. */
        var pagerEl = document.getElementById('profile-encurso-pager');
        if (pagerEl) {
            pagerEl.innerHTML = '';
            if (inProgress.length > ENCURSO_PER_PAGE) {
                var prev = document.createElement('button');
                prev.className = 'button'; prev.textContent = '◄';
                prev.disabled = (_encursoPage[cat] <= 0);
                prev.addEventListener('click', function(){
                    _encursoPage[cat] = Math.max(0, _encursoPage[cat] - 1);
                    renderCatEncurso(cat);
                });
                pagerEl.appendChild(prev);
                var info = document.createElement('span');
                info.className = 'profile-catview-pager-info';
                info.textContent = (_encursoPage[cat] + 1) + ' / ' + totalPages;
                pagerEl.appendChild(info);
                var next = document.createElement('button');
                next.className = 'button'; next.textContent = '►';
                next.disabled = (_encursoPage[cat] >= totalPages - 1);
                next.addEventListener('click', function(){
                    _encursoPage[cat] = Math.min(totalPages - 1, _encursoPage[cat] + 1);
                    renderCatEncurso(cat);
                });
                pagerEl.appendChild(next);
            }
        }
    }

    /* ──── Review ──── */
    function showReviewPrompt(cat, itemIdx) {
        var prompt = document.getElementById('profile-review-prompt');
        var item = lists[cat][itemIdx];
        document.getElementById('profile-review-prompt-msg').textContent =
            '¿Quieres añadir una review para "' + item.title + '"?';
        prompt.style.display = 'block';
        prompt.style.left = Math.round((window.innerWidth  - prompt.offsetWidth)  / 2) + 'px';
        prompt.style.top  = Math.round((window.innerHeight - prompt.offsetHeight) / 2) + 'px';

        var yesBtn = document.getElementById('profile-review-prompt-yes');
        var noBtn  = document.getElementById('profile-review-prompt-no');
        var newYes = yesBtn.cloneNode(true); yesBtn.parentNode.replaceChild(newYes, yesBtn);
        var newNo  = noBtn.cloneNode(true);  noBtn.parentNode.replaceChild(newNo,  noBtn);

        newNo.addEventListener('click', function() { prompt.style.display = 'none'; });
        newYes.addEventListener('click', function() {
            prompt.style.display = 'none';
            showReviewWindow(cat, itemIdx);
        });
    }

    /* ──── Invite system (usa notifSystem unificado) ──── */
    var itemNotifEs        = null;
    var currentSessionUser = <?php echo json_encode($desktopUserKey); ?>;

    function postItemAction(id, action) {
        return fetch('assets/profile/api.php?action=respond-item-invite', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ inviteId: id, action: action })
        });
    }

    var ITEM_TITLES = {
        'invite':         'Invitación',
        'item-accepted':  'Aceptado',
        'item-rejected':  'Rechazado',
        'collab-left':    'Colaboración',
        'collab-removed': 'Eliminado'
    };

    function pushItemNotif(notif) {
        /* item-edited: un colaborador/host editó un item compartido →
           refrescamos en silencio (sin popup ni sonido) y descartamos la
           notif en el servidor para que no se acumule. */
        if (notif.type === 'item-edited') {
            if (typeof postItemAction === 'function') postItemAction(notif.id, 'dismiss').catch(function(){});
            if (typeof loadLists === 'function') loadLists(function() { if (typeof updateCounts === 'function') updateCounts(); if (typeof reloadCurrentView === 'function') reloadCurrentView(); });
            return;
        }
        if (!window.notifSystem || window.notifSystem.isShown(notif.id) || window.notifSystem.isDismissed(notif.id)) return;
        /* SSE recibió una notif nueva → sonido inmediato (respetando
           el filtro por tipo). El throttle interno colapsa con el play
           del polling si llegan a la vez. */
        if (typeof playProfileNotifSound === 'function') playProfileNotifSound(notif.type);
        var isAction = notif.type === 'invite';
        var verb = CAT_VERBS[notif.category] || 'ver';
        var msg;
        if (notif.type === 'invite')              msg = notif.fromLabel + ' te ha invitado a ' + verb + ' "' + notif.itemTitle + '"';
        else if (notif.type === 'item-accepted')  msg = notif.fromLabel + ' ha aceptado tu invitación a ' + verb + ' "' + notif.itemTitle + '"';
        else if (notif.type === 'item-rejected')  msg = notif.fromLabel + ' ha rechazado tu invitación a ' + verb + ' "' + notif.itemTitle + '"';
        else if (notif.type === 'collab-left')    msg = notif.fromLabel + ' ha abandonado "' + notif.itemTitle + '"';
        else if (notif.type === 'collab-removed') msg = 'Has sido eliminado de "' + notif.itemTitle + '"';
        else msg = '';

        if (isAction) {
            var senderImg = (notif.fromUser && PROFILE_USERS[notif.fromUser]) ? PROFILE_USERS[notif.fromUser].image : null;
            window.notifSystem.show({
                id:          notif.id,
                type:        'action',
                title:       ITEM_TITLES[notif.type] || 'Notificación',
                message:     msg,
                senderImage: senderImg,
                sentAt:      notif.sentAt,
                onAccept: function() {
                    postItemAction(notif.id, 'accept')
                        .then(function(r) { return r.json(); })
                        .then(function(d) { if (d && d.ok) loadLists(function() { updateCounts(); reloadCurrentView(); }); })
                        .catch(function(){});
                },
                onReject: function() { postItemAction(notif.id, 'reject').catch(function(){}); }
            });
        } else {
            /* Info: dismiss en servidor de inmediato y refrescar listas si aplica */
            postItemAction(notif.id, 'dismiss').catch(function(){});
            if (notif.type === 'item-accepted' || notif.type === 'collab-left' || notif.type === 'collab-removed') {
                loadLists(function() { updateCounts(); reloadCurrentView(); });
            }
            window.notifSystem.show({
                id:      notif.id,
                type:    'info',
                title:   ITEM_TITLES[notif.type] || 'Notificación',
                message: msg,
                sentAt:  notif.sentAt
            });
        }
    }

    function startItemNotifStream() {
        if (itemNotifEs) return;
        var es = new EventSource('assets/profile/item-notifications-stream.php');
        itemNotifEs = es;
        es.onmessage = function(e) {
            var items = [];
            try { items = JSON.parse(e.data); } catch(err) { return; }
            if (!Array.isArray(items)) return;
            items.forEach(pushItemNotif);
        };
        es.onerror = function() { es.close(); itemNotifEs = null; setTimeout(startItemNotifStream, 5000); };
    }

    function makeAvRow(uKey, uInfo, btnLabel, btnSetup) {
        var row = document.createElement('div');
        row.className = 'collab-user-row';
        var avWrap = document.createElement('div');
        avWrap.className = 'collab-avatar-wrap';
        if (uInfo.image) {
            var avImg = document.createElement('img');
            avImg.className = 'collab-avatar-img';
            avImg.src = uInfo.image;
            avImg.alt = uInfo.label;
            avWrap.appendChild(avImg);
        }
        row.appendChild(avWrap);
        var lbl = document.createElement('span');
        lbl.style.cssText = 'flex:1;';
        lbl.textContent = uInfo.label;
        row.appendChild(lbl);
        if (btnLabel && btnSetup) {
            var btn = document.createElement('button');
            btn.className = 'button collab-invite-btn';
            btn.textContent = btnLabel;
            btnSetup(btn);
            row.appendChild(btn);
        }
        return row;
    }

    function collabSectionTitle(text) {
        var el = document.createElement('div');
        el.style.cssText = 'font-size:10px;color:#666;padding:4px 0 2px;';
        el.textContent = text;
        return el;
    }

    /* Los elementos recién añadidos llevan un id provisional ("item_…") hasta
       que save-lists les da el suyo, y las invitaciones necesitan el
       definitivo: con el provisional la API respondía "Datos inválidos". Se
       espera un poco al guardado que ya va en camino y, si no llega, se
       guarda la categoría aquí. cb(id) recibe 0 si no se pudo. */
    function withSavedItemId(cat, item, cb) {
        var real = function(v) { return /^\d+$/.test(String(v)); };
        function find() {
            if (real(item.id)) return Number(item.id);
            var same = (lists[cat] || []).filter(function(x) { return x.title === item.title && real(x.id); });
            return same.length ? Number(same[same.length - 1].id) : 0;
        }
        var tries = 0;
        (function wait() {
            var id = find();
            if (id) { cb(id); return; }
            if (++tries <= 10) { setTimeout(wait, 300); return; }
            fetch('assets/profile/api.php?action=save-lists', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category: cat, items: lists[cat] })
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (d && Array.isArray(d.items)) lists[cat] = d.items;
                cb(find());
            }).catch(function() { cb(0); });
        })();
    }

    function showCollabDialog(cat, item) {
        var dlg      = document.getElementById('profile-invite-dialog');
        var msg      = document.getElementById('profile-invite-msg');
        var list     = document.getElementById('profile-invite-users');
        var statusEl = document.getElementById('profile-invite-status');
        var isCollab = !!item.sharedFrom;
        msg.textContent   = '"' + item.title + '"';
        statusEl.textContent = '';
        list.innerHTML = '';

        if (isCollab) {
            var ownerKey  = item.sharedFrom;
            var ownerInfo = PROFILE_USERS[ownerKey];
            if (ownerInfo) {
                list.appendChild(collabSectionTitle('Host'));
                list.appendChild(makeAvRow(ownerKey, ownerInfo, null, null));
            }
            var othersSec = document.createElement('div');
            list.appendChild(othersSec);
            fetch('assets/profile/api.php?action=get-item-collabs&category=' + encodeURIComponent(cat) + '&itemId=' + encodeURIComponent(item.id))
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    var others = (d.collaborators || []).filter(function(k) { return k !== currentSessionUser; });
                    if (!others.length) return;
                    othersSec.appendChild(collabSectionTitle('Otros colaboradores'));
                    others.forEach(function(uKey) {
                        var uInfo = PROFILE_USERS[uKey];
                        if (uInfo) othersSec.appendChild(makeAvRow(uKey, uInfo, null, null));
                    });
                })
                .catch(function() {});
        } else {
            var existingCollabs = item.collaborators || [];
            if (existingCollabs.length) {
                list.appendChild(collabSectionTitle('Colaboradores'));
                existingCollabs.forEach(function(uKey) {
                    var uInfo = PROFILE_USERS[uKey];
                    if (!uInfo) return;
                    list.appendChild(makeAvRow(uKey, uInfo, 'Eliminar', (function(cKey, cLabel) {
                        return function(btn) {
                            btn.addEventListener('click', function() {
                                confirmFn('¿Eliminar a ' + cLabel + ' como colaborador?', 'Eliminar colaborador', function() {
                                    statusEl.textContent = 'Eliminando…';
                                    fetch('assets/profile/api.php?action=leave-collab', {
                                        method: 'POST', headers: {'Content-Type':'application/json'},
                                        body: JSON.stringify({ action: 'remove', category: cat, itemId: item.id, collaboratorUser: cKey })
                                    }).then(function(r) { return r.json(); }).then(function(d) {
                                        if (d.ok) {
                                            loadLists(function() {
                                                updateCounts();
                                                if (cat === 'music') { renderMusicView(currentMusicTab); renderMusicDestacados(); }
                                                else { renderCatView(cat); renderCatEncurso(cat); }
                                                var updated = null;
                                                (lists[cat] || []).forEach(function(i) { if (i.id === item.id) updated = i; });
                                                if (updated) showCollabDialog(cat, updated);
                                                else dlg.style.display = 'none';
                                            });
                                        }
                                    }).catch(function(){ statusEl.textContent = 'Error al eliminar.'; });
                                });
                            });
                        };
                    })(uKey, uInfo.label)));
                });
            }

            var invitable = Object.keys(PROFILE_USERS).filter(function(k) {
                return k !== currentSessionUser
                    && existingCollabs.indexOf(k) === -1
                    && isMutual(k);   /* solo seguidores mutuos */
            });
            if (invitable.length) {
                if (existingCollabs.length) {
                    var sep = document.createElement('hr');
                    sep.style.cssText = 'margin:4px 0;border:none;border-top:1px solid #dfdfdf;';
                    list.appendChild(sep);
                }
                list.appendChild(collabSectionTitle(existingCollabs.length ? 'Invitar más' : 'Invitar'));
                invitable.forEach(function(uKey) {
                    var uInfo = PROFILE_USERS[uKey];
                    list.appendChild(makeAvRow(uKey, uInfo, 'Invitar', (function(key) {
                        return function(btn) {
                            btn.addEventListener('click', function() {
                                btn.disabled = true; btn.textContent = '…';
                                statusEl.textContent = 'Enviando…';
                                withSavedItemId(cat, item, function(itemId) {
                                if (!itemId) { btn.disabled = false; btn.textContent = 'Invitar'; statusEl.textContent = 'No se pudo guardar el elemento. Inténtalo otra vez.'; return; }
                                fetch('assets/profile/api.php?action=send-item-invite', {
                                    method: 'POST', headers: {'Content-Type':'application/json'},
                                    body: JSON.stringify({ toUser: key, category: cat, itemId: itemId })
                                }).then(function(r) { return r.json(); }).then(function(d) {
                                    if (d.ok) {
                                        var sent = document.createElement('span');
                                        sent.style.cssText = 'font-size:10px;color:#008000;font-weight:bold;';
                                        sent.textContent = '✓ Invitado';
                                        btn.parentNode.replaceChild(sent, btn);
                                        statusEl.textContent = 'Invitación enviada.';
                                    } else {
                                        btn.disabled = false; btn.textContent = 'Invitar';
                                        statusEl.textContent = d.error || 'Error al enviar.';
                                    }
                                }).catch(function() { btn.disabled = false; btn.textContent = 'Invitar'; statusEl.textContent = ''; });
                                });
                            });
                        };
                    })(uKey)));
                });
            } else if (!existingCollabs.length) {
                var empty = document.createElement('div');
                empty.style.cssText = 'text-align:center;padding:10px 6px;font-size:12px;line-height:1.45;';
                empty.innerHTML = 'Aún no tienes amigos que invitar.<br><span style="opacity:0.75;font-size:11px;">Seguíos entre vosotros para haceros amigos.</span>';
                list.appendChild(empty);
            }
        }

        dlg.style.display = 'block';
        dlg.style.left = Math.round((window.innerWidth  - dlg.offsetWidth)  / 2) + 'px';
        dlg.style.top  = Math.round((window.innerHeight - dlg.offsetHeight) / 2) + 'px';
        var closeBtn = document.getElementById('profile-invite-close');
        var newClose = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newClose, closeBtn);
        newClose.addEventListener('click', function() { dlg.style.display = 'none'; });
        var cancelBtn = document.getElementById('profile-invite-cancel');
        var newCancel = cancelBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
        newCancel.addEventListener('click', function() { dlg.style.display = 'none'; });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function makeStarsHtml(val, total) {
        var h = '';
        for (var i = 1; i <= total; i++) {
            if (val >= i) {
                h += '<span>★</span>';
            } else if (val >= i - 0.5) {
                h += '<span style="display:inline-block;clip-path:inset(0 50% 0 0);">★</span>';
            } else {
                h += '<span>☆</span>';
            }
        }
        return h;
    }

    function showReviewView(review, item) {
        var win = document.getElementById('profile-review-view');
        var username = (document.getElementById('profile-username') || {}).textContent || 'Usuario';
        document.getElementById('profile-review-view-comment').textContent = review.comment ? '" ' + review.comment + ' "' : '';
        document.getElementById('profile-review-view-header').innerHTML = '— ' + escHtml(username) + '  —  ' + makeStarsHtml(review.stars, 5) + '<span style="font-size:11px;margin-left:4px;vertical-align:middle;">' + review.stars + '</span>';
        /* Reset al tamaño base definido en el HTML — si el usuario
           había redimensionado la ventana antes, los style inline
           width/height persistían entre aperturas. Borrarlos hace que
           la ventana vuelva a sus dimensiones por defecto cada vez. */
        win.style.width = ''; win.style.height = '';
        /* display:flex (no block) para que el flex-direction:column
           del HTML surta efecto con el body interno scrolleable. */
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        var closeBtn = document.getElementById('profile-review-view-close');
        var newClose = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newClose, closeBtn);
        newClose.addEventListener('click', function() { win.style.display = 'none'; });
    }

    function showReviewWindow(cat, itemIdx) {
        var win     = document.getElementById('profile-review-window');
        var starsEl = document.getElementById('profile-review-stars');
        var commentEl = document.getElementById('profile-review-comment');
        var item = lists[cat][itemIdx];
        if (!item) return;
        var itemId = item.id;   /* re-resolver por id en submit/delete */
        document.getElementById('profile-review-window-title').textContent = '✏ ' + item.title;
        commentEl.value = (item.review && item.review.comment) ? item.review.comment : '';
        var sel = (item.review && item.review.stars) ? item.review.stars : 0;

        /* Campos de edición del item: título, imagen y duración/capítulos. */
        var titleEl = document.getElementById('profile-review-title');
        var imageEl = document.getElementById('profile-review-image');
        var rtRow   = document.getElementById('profile-review-rt-row');
        var rtEl    = document.getElementById('profile-review-rt');
        var rtLbl   = document.getElementById('profile-review-rt-label');
        var epsRow  = document.getElementById('profile-review-eps-row');
        var epsBtn  = document.getElementById('profile-review-eps-btn');
        var RW_RT_LABEL = { movies: 'Duración (min)', books: 'Nº de capítulos' };
        titleEl.value = item.title || '';
        imageEl.value = item.image || '';
        if (cat === 'series') {
            /* Series → botón a la ventana de capítulos (visto editable si está en curso). */
            rtRow.style.display = 'none'; rtEl.value = '';
            epsRow.style.display = 'flex';
            var newEps = epsBtn.cloneNode(true); epsBtn.parentNode.replaceChild(newEps, epsBtn);
            newEps.addEventListener('click', function(){
                openEpisodesWindow(titleEl.value.trim() || item.title || '', item.status === 'in-progress');
            });
        } else if (RW_RT_LABEL[cat]) {
            rtLbl.textContent = RW_RT_LABEL[cat];
            rtEl.value = item.runtime ? item.runtime : '';
            rtRow.style.display = '';
            epsRow.style.display = 'none';
        } else {
            rtRow.style.display = 'none'; rtEl.value = '';
            epsRow.style.display = 'none';
        }
        /* Libros: campo de PDF (compartido por título). Se carga el actual
           y se guarda al pulsar Guardar. El botón Abrir lo previsualiza. */
        var pdfRow = document.getElementById('profile-review-pdf-row');
        var pdfEl  = document.getElementById('profile-review-pdf');
        var pdfBtn = document.getElementById('profile-review-pdf-btn');
        if (cat === 'books') {
            pdfRow.style.display = '';
            pdfEl.value = '';
            fetch('assets/profile/api.php?action=book-pdf&bookTitle=' + encodeURIComponent(item.title || ''), { credentials: 'same-origin' })
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(d){ if (d && d.url) pdfEl.value = d.url; }).catch(function(){});
            var newPdfBtn = pdfBtn.cloneNode(true); pdfBtn.parentNode.replaceChild(newPdfBtn, pdfBtn);
            newPdfBtn.addEventListener('click', function(){
                var u = pdfEl.value.trim();
                if (u) openPdfViewer(u, titleEl.value.trim() || item.title || 'Libro');
            });
        } else {
            pdfRow.style.display = 'none';
        }

        /* La nota y la reseña solo se pueden añadir si el item está
           completado. En pendiente/en curso solo se editan título/imagen/
           duración (y, en series, capítulos). */
        var allowReview = (item.status === 'completed');
        document.getElementById('profile-review-only-completed').style.display = allowReview ? 'none' : '';
        document.getElementById('profile-review-score-label').style.display = allowReview ? '' : 'none';
        document.getElementById('profile-review-score-row').style.display = allowReview ? '' : 'none';
        document.getElementById('profile-review-comment-row').style.display = allowReview ? '' : 'none';

        var numEl = document.getElementById('profile-review-stars-num');
        function setStarDisp(el, val, pos) {
            if (val >= pos) { el.innerHTML = '★'; el.style.clipPath = ''; el.style.opacity = ''; }
            else if (val >= pos - 0.5) { el.innerHTML = '★'; el.style.clipPath = 'inset(0 50% 0 0)'; el.style.opacity = ''; }
            else { el.innerHTML = '☆'; el.style.clipPath = ''; el.style.opacity = ''; }
        }
        function drawStars() {
            starsEl.innerHTML = '';
            numEl.textContent = sel > 0 ? sel : '';
            for (var n = 1; n <= 5; n++) {
                (function(star) {
                    var s = document.createElement('span');
                    s.setAttribute('data-star', star);
                    s.style.cssText = 'display:inline-block;position:relative;width:1.1em;cursor:pointer;';
                    setStarDisp(s, sel, star);
                    s.addEventListener('mousemove', function(e) {
                        var isHalf = e.offsetX < this.offsetWidth / 2;
                        var hover = isHalf ? star - 0.5 : star;
                        starsEl.querySelectorAll('[data-star]').forEach(function(sp) {
                            setStarDisp(sp, hover, parseFloat(sp.getAttribute('data-star')));
                        });
                        numEl.textContent = hover;
                    });
                    s.addEventListener('mouseout', function() {
                        starsEl.querySelectorAll('[data-star]').forEach(function(sp) {
                            setStarDisp(sp, sel, parseFloat(sp.getAttribute('data-star')));
                        });
                        numEl.textContent = sel > 0 ? sel : '';
                    });
                    s.addEventListener('click', function(e) {
                        var isHalf = e.offsetX < this.offsetWidth / 2;
                        sel = isHalf ? star - 0.5 : star;
                        drawStars();
                    });
                    starsEl.appendChild(s);
                })(n);
            }
        }
        drawStars();
        /* Reset al tamaño base definido en el HTML (sin width/height
           inline que persistan de un resize anterior). */
        win.style.width = ''; win.style.height = '';
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';

        var closeBtn  = document.getElementById('profile-review-window-close');
        var cancelBtn = document.getElementById('profile-review-window-cancel');
        var submitBtn = document.getElementById('profile-review-window-submit');
        var deleteBtn = document.getElementById('profile-review-window-delete');
        var newClose  = closeBtn.cloneNode(true);  closeBtn.parentNode.replaceChild(newClose,  closeBtn);
        var newCancel = cancelBtn.cloneNode(true); cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
        var newSubmit = submitBtn.cloneNode(true); submitBtn.parentNode.replaceChild(newSubmit, submitBtn);
        var newDelete = deleteBtn.cloneNode(true); deleteBtn.parentNode.replaceChild(newDelete, deleteBtn);
        /* Botón Eliminar solo si ya hay una reseña guardada y el item está
           completado (en otros estados no se gestiona reseña). */
        newDelete.style.display = (allowReview && item.review && item.review.stars) ? '' : 'none';

        function closeWin() { win.style.display = 'none'; }
        newClose.addEventListener('click', closeWin);
        newCancel.addEventListener('click', closeWin);
        newSubmit.addEventListener('click', function() {
            var i = lists[cat].findIndex(function(x){ return x.id === itemId; });
            if (i === -1) { closeWin(); return; }
            /* Edición del item. */
            var newTitle = titleEl.value.trim();
            if (newTitle) lists[cat][i].title = newTitle;
            lists[cat][i].image = imageEl.value.trim();
            if (RW_RT_LABEL[cat]) {
                var rt = parseInt(rtEl.value, 10) || 0;
                if (rt > 0) lists[cat][i].runtime = rt; else delete lists[cat][i].runtime;
            }
            /* Libros: guardar/actualizar el PDF compartido por título. */
            if (cat === 'books') {
                fetch('assets/profile/api.php?action=save-book-pdf', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bookTitle: lists[cat][i].title, url: pdfEl.value.trim() })
                }).catch(function(){});
            }
            /* Reseña: solo se toca si el item está completado. En otros
               estados se preserva lo que hubiera (no se añade nota/reseña). */
            if (allowReview) {
                if (sel > 0) {
                    lists[cat][i].review = { stars: sel, comment: commentEl.value.trim(), reviewedAt: Math.floor(Date.now() / 1000) };
                    notifyReviewToFollowers(cat, lists[cat][i].title, lists[cat][i].type);
                } else {
                    delete lists[cat][i].review;
                }
            }
            saveCategory(cat);
            renderCatView(cat);
            closeWin();
        });
        newDelete.addEventListener('click', function() {
            var i = lists[cat].findIndex(function(x){ return x.id === itemId; });
            if (i !== -1) delete lists[cat][i].review;
            saveCategory(cat);
            renderCatView(cat);
            closeWin();
        });
    }

    function notifyReviewToFollowers(cat, itemTitle, mtype) {
        if (!itemTitle) return;
        if (viewingUser) return; /* sólo notifica reseñas hechas en MI perfil */
        fetch('assets/profile/api.php?action=notify-review', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, itemTitle: itemTitle, mtype: mtype || '' })
        }).catch(function() {});
    }

    /* ──── Category view ──── */
    var CATVIEW_PAGE_SIZE = 4;
    var catPage = { pending: 1, done: 1 };

    function showCatView(cat) {
        document.dispatchEvent(new Event('profile-edit-cancel'));
        currentCat = cat;
        catPage = { pending: 1, done: 1 };
        document.getElementById('profile-view-default').style.display = 'none';
        document.getElementById('profile-view-social').style.display  = 'none';
        var melonV = document.getElementById('profile-view-melon');
        if (melonV) melonV.style.display = 'none';
        var mv = document.getElementById('profile-view-music');
        if (mv) mv.style.display = 'none';
        document.getElementById('profile-sidebar-back').style.display = 'block';
        document.getElementById('profile-info-edit-btn').style.display = 'none';
        var addBtn = document.getElementById('profile-catview-add-btn');
        if (addBtn) addBtn.style.display = viewingUser ? 'none' : '';
        var catView = document.getElementById('profile-view-cat');
        catView.style.display = 'flex';
        var titleEl  = document.getElementById('profile-catview-title');
        applyTopbarUser('profile-catview-avatar-wrap', titleEl, CATS[cat].icon, CATS[cat].label);
        /* Sustituir emoji por PNG en las categorías que tienen icono
           dedicado (series → melonArchive, music → musica). El resto
           (movies, books, games) se queda con su emoji. */
        if (titleEl) {
            var CAT_PNG = {
                series: 'assets/img/appIcons/melonArchiveIcon.png',
                music:  'assets/img/appIcons/musicaIcon.png',
                movies: 'assets/img/appIcons/pelisIcon.png'
            };
            if (CAT_PNG[cat]) {
                titleEl.innerHTML = '<img src="' + CAT_PNG[cat] + '" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">' + CATS[cat].label;
            }
        }
        var doneHead = document.getElementById('profile-catview-done-head');
        if (doneHead) doneHead.textContent = DONE_LABELS[cat];
        renderCatView(cat);
        renderCatEncurso(cat);
    }

    function showDefaultView() {
        /* Si vamos al perfil de OTRO usuario, sal del modo edición */
        if (viewingUser) document.dispatchEvent(new Event('profile-edit-cancel'));
        currentCat = null;
        document.getElementById('profile-view-cat').style.display = 'none';
        document.getElementById('profile-view-social').style.display = 'none';
        var melonV = document.getElementById('profile-view-melon');
        if (melonV) melonV.style.display = 'none';
        var mv = document.getElementById('profile-view-music');
        if (mv) mv.style.display = 'none';
        document.getElementById('profile-sidebar-back').style.display = 'none';
        document.getElementById('profile-info-edit-btn').style.display = viewingUser ? 'none' : '';
        document.getElementById('profile-view-default').style.display = 'flex';
    }

    function renderCatView(cat) {
        var pendingGallery = document.getElementById('profile-catview-pending');
        var doneGallery    = document.getElementById('profile-catview-done');
        if (!pendingGallery || !doneGallery) return;
        var items   = lists[cat] || [];
        var pending = items.filter(function(i) { return i.status === 'pending'; });
        var done    = items.filter(function(i) { return i.status === 'completed'; });
        renderPaginatedGallery(cat, pending, pendingGallery, 'pending', 'profile-catview-pending-pager');
        renderPaginatedGallery(cat, done,    doneGallery,    'done',    'profile-catview-done-pager');
    }

    function renderPaginatedGallery(cat, items, gallery, key, pagerId) {
        var totalPages = Math.max(1, Math.ceil(items.length / CATVIEW_PAGE_SIZE));
        if (catPage[key] > totalPages) catPage[key] = totalPages;
        if (catPage[key] < 1)          catPage[key] = 1;
        var start = (catPage[key] - 1) * CATVIEW_PAGE_SIZE;
        var pageItems = items.slice(start, start + CATVIEW_PAGE_SIZE);
        renderGallery(cat, pageItems, gallery, true, false);
        renderPager(totalPages, key, pagerId);
    }

    function renderPager(totalPages, key, pagerId) {
        var pager = document.getElementById(pagerId);
        if (!pager) return;
        pager.innerHTML = '';
        if (totalPages <= 1) return;
        var prevBtn = document.createElement('button');
        prevBtn.className = 'button';
        prevBtn.textContent = '◄';
        prevBtn.disabled = catPage[key] <= 1;
        prevBtn.addEventListener('click', function() {
            if (catPage[key] > 1) { catPage[key]--; if (currentCat) renderCatView(currentCat); }
        });
        pager.appendChild(prevBtn);
        var info = document.createElement('span');
        info.className = 'profile-catview-pager-info';
        info.textContent = catPage[key] + ' / ' + totalPages;
        pager.appendChild(info);
        var nextBtn = document.createElement('button');
        nextBtn.className = 'button';
        nextBtn.textContent = '►';
        nextBtn.disabled = catPage[key] >= totalPages;
        nextBtn.addEventListener('click', function() {
            if (catPage[key] < totalPages) { catPage[key]++; if (currentCat) renderCatView(currentCat); }
        });
        pager.appendChild(nextBtn);
    }

    function renderGallery(cat, items, gallery, withCtx, showFooter) {
        gallery.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'profile-gallery-empty';
            /* Empty state con el icono de la categoría — más visual que
               el genérico "Sin elementos". El icono va semitransparente
               y un texto pequeño debajo da contexto. */
            var iconSrc = CAT_PLACEHOLDER_ICON[cat];
            if (iconSrc) {
                empty.innerHTML =
                    '<img src="' + iconSrc + '" alt="" style="width:72px;height:72px;object-fit:contain;image-rendering:pixelated;opacity:0.35;display:block;margin:0 auto 8px;">' +
                    '<div style="opacity:0.6;">Sin elementos</div>';
            } else {
                empty.textContent = 'Sin elementos';
            }
            gallery.appendChild(empty);
            return;
        }
        items.forEach(function(item) {
            /* idx ya no se captura por posición: lists[cat] puede haber sido
               reemplazado por la respuesta canónica de saveCategory, lo que
               invalidaría el índice fijo. Resolvemos por id en cada click. */
            var card = document.createElement('div');
            card.className = 'profile-gallery-card';

            var tb = document.createElement('div');
            tb.className = 'profile-gallery-tb';
            var tbTitle = document.createElement('span');
            tbTitle.className = 'profile-gallery-tb-title';
            tbTitle.textContent = item.title;
            tbTitle.title = item.title;
            tb.appendChild(tbTitle);
            if (item.review && item.review.stars) {
                var starsSpan = document.createElement('span');
                starsSpan.className = 'profile-gallery-tb-stars';
                starsSpan.innerHTML = makeStarsHtml(item.review.stars, 5) + '<span class="profile-star-num" style="font-size:9px;margin-left:2px;vertical-align:middle;">' + item.review.stars + '</span>';
                tb.appendChild(starsSpan);
            }
            if (item.review && item.review.comment) {
                var bubbleBtn = document.createElement('span');
                bubbleBtn.className = 'profile-gallery-tb-bubble';
                bubbleBtn.innerHTML = '<img src="assets/img/appIcons/chatIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
                (function(r, it) {
                    bubbleBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        showReviewView(r, it);
                    });
                })(item.review, item);
                tb.appendChild(bubbleBtn);
            }
            /* Misma decisión que abajo (forceShowFooter) — necesario aquí
               porque el className diferente ajusta cómo se reparte el
               espacio interno cuando hay/no hay footer. */
            var imgWrapWillHaveFooter = showFooter || (item.status === 'completed' && !!item.completedAt);
            var imgWrap = document.createElement('div');
            imgWrap.className = imgWrapWillHaveFooter ? 'profile-gallery-img-wrap' : 'profile-gallery-img-wrap profile-gallery-img-slot';
            imgWrap.style.position = 'relative';
            if (item.image) {
                var img = document.createElement('img');
                img.className = 'profile-gallery-img';
                img.src = item.image;
                img.alt = item.title;
                img.setAttribute('data-cover-cat', cat);
                img.setAttribute('data-cover-title', item.title);
                img.onerror = function() {
                    imgWrap.innerHTML = catPlaceholderHTML(cat, 48);
                };
                imgWrap.appendChild(img);
            } else {
                imgWrap.innerHTML = catPlaceholderHTML(cat, 48);
            }
            /* Strip de avatares: si es item compartido, el host va
               primero y luego el resto de invitados; si es propio,
               solo los colaboradores. */
            var collabKeys = [];
            if (item.sharedFrom) collabKeys.push(item.sharedFrom);
            if (item.collaborators && item.collaborators.length) {
                item.collaborators.forEach(function(k) {
                    if (k !== item.sharedFrom) collabKeys.push(k);
                });
            }
            if (collabKeys.length) {
                var collabStrip = document.createElement('div');
                collabStrip.className = 'profile-gallery-collabs';
                collabKeys.forEach(function(uKey) {
                    var uInfo = PROFILE_USERS[uKey];
                    if (!uInfo) return;
                    var avFrame = document.createElement('div');
                    avFrame.className = 'profile-avatar-frame';
                    avFrame.title = uInfo.label;
                    if (uInfo.image) {
                        var avImg = document.createElement('img');
                        avImg.src = uInfo.image;
                        avImg.alt = uInfo.label;
                        avFrame.appendChild(avImg);
                    }
                    collabStrip.appendChild(avFrame);
                });
                imgWrap.appendChild(collabStrip);
            }

            var footer = document.createElement('div');
            footer.className = 'profile-gallery-footer';
            footer.dataset.status = item.status || 'pending';
            footer.innerHTML = renderStatusFooter(item);
            /* El footer ya NO es clickable: antes ciclaba pending →
               in-progress → completed → pending, lo que provocaba que
               al pulsar sobre la fecha (que ahora vive ahí) el item se
               devolviera a pendientes accidentalmente. Si necesitas
               cambiar el estado, usa el menú contextual (click derecho).
               Cambios visuales: cursor default + sin :hover de fondo. */
            footer.style.cursor = 'default';

            card.appendChild(tb);
            card.appendChild(imgWrap);
            /* Footer visible si el caller lo pide explícitamente O si
               el item es completado CON fecha. Antes el footer estaba
               siempre `showFooter=false` desde catview → la etiqueta de
               estado nunca aparecía. Para items completados queremos
               mostrar al menos la fecha (la sección ya identifica que
               es "Completado", pero la fecha es info NUEVA). */
            var forceShowFooter = (item.status === 'completed' && !!item.completedAt);
            if (showFooter || forceShowFooter) card.appendChild(footer);

            if (withCtx) {
                (function(it) {
                    card.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        var menuItems = [];
                        if (viewingUser) {
                            menuItems.push({ label: '+ Añadir a mi perfil', action: function() {
                                addItemToOwnProfile(cat, it);
                            }});
                            showCtxMenu(e.clientX, e.clientY, menuItems);
                            return;
                        }
                        var isCollab = !!it.sharedFrom;
                        if (it.status === 'completed') {
                            var reviewLabel = (it.review && it.review.stars) ? '✏ Editar' : '✏ Añadir reseña';
                            menuItems.push({ label: reviewLabel, action: function() {
                                var i = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (i !== -1) showReviewWindow(cat, i);
                            }});
                        } else {
                            /* Cualquier item se puede editar en cualquier estado
                               (título/imagen/reseña/duración y, en series, capítulos),
                               no solo al completarlo. */
                            menuItems.push({ label: '✏ Editar', action: function() {
                                var i = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (i !== -1) showReviewWindow(cat, i);
                            }});
                            menuItems.push({ label: '▶ Poner en curso', action: function() {
                                var i = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                if (i !== -1) {
                                    lists[cat][i].status = 'in-progress';
                                    saveCategory(cat);
                                    renderCatView(cat);
                                    renderCatEncurso(cat);
                                }
                            }});
                            menuItems.push({ label: '👥 Colaboradores', action: function() {
                                showCollabDialog(cat, it);
                            }});
                        }
                        if (isCollab) {
                            menuItems.push({ label: '🚪 Abandonar actividad', action: function() {
                                confirmFn('¿Abandonar "' + it.title + '"?', 'Abandonar', function() {
                                    fetch('assets/profile/api.php?action=leave-collab', {
                                        method: 'POST', headers: {'Content-Type':'application/json'},
                                        body: JSON.stringify({ action: 'leave', category: cat, itemId: it.id })
                                    }).then(function(r) { return r.json(); }).then(function(d) {
                                        if (d.ok) { loadLists(function() { updateCounts(); renderCatView(cat); renderCatEncurso(cat); }); }
                                    }).catch(function(){});
                                });
                            }});
                        } else {
                            menuItems.push({ label: '✕ Eliminar', action: function() {
                                confirmFn('¿Eliminar "' + it.title + '"?', 'Eliminar', function() {
                                    var i = lists[cat].findIndex(function(x){ return x.id === it.id; });
                                    if (i !== -1) {
                                        /* Capturamos el item antes del splice para que el
                                           helper tenga el título original. Best-effort:
                                           si nunca existió un momento, no pasa nada. */
                                        var removed = lists[cat][i];
                                        if (typeof window.eliminarMomentoDeItem === 'function') {
                                            window.eliminarMomentoDeItem(cat, removed);
                                        }
                                        lists[cat].splice(i, 1);
                                        saveCategory(cat);
                                        updateCounts();
                                        renderCatView(cat);
                                        renderCatEncurso(cat);
                                    }
                                });
                            }});
                        }
                        showCtxMenu(e.clientX, e.clientY, menuItems);
                    });
                })(item);
            }

            gallery.appendChild(card);
        });
    }

    /* ──── Add dialog ──── */
    var addDlg       = document.getElementById('profile-add-dialog');
    var addDlgTitle  = document.getElementById('profile-add-dialog-title');
    var addNameInput = document.getElementById('profile-add-name');
    var addImgInput  = document.getElementById('profile-add-image');
    var addSuggest   = document.getElementById('profile-add-suggest');
    var addRuntimeRow = document.getElementById('profile-add-runtime-row');
    var addRuntimeEl  = document.getElementById('profile-add-runtime');
    var addRuntimeLbl = document.getElementById('profile-add-runtime-label');
    /* Series no usa número: gestiona capítulos tras crearla (botón Capítulos). */
    var ADD_RUNTIME_LABEL = { movies: 'Duración (min)', books: 'Nº de capítulos' };

    /* ── Autocomplete del nombre ──
       Busca titles existentes en la categoría (por otros usuarios) que
       matchen lo que vas escribiendo. Debounce 200ms para no spamear al
       backend con un fetch por keystroke. AbortController cancela
       fetches en vuelo si llega uno nuevo (race con typing rápido). */
    var addSuggestTimer = null;
    var addSuggestCtrl  = null;
    var addSuggestActiveIdx = -1;  /* índice resaltado para navegación con teclado */

    function hideSuggest() {
        addSuggest.hidden = true;
        addSuggest.innerHTML = '';
        addSuggestActiveIdx = -1;
    }

    function renderSuggest(suggestions) {
        addSuggest.innerHTML = '';
        if (!suggestions || !suggestions.length) {
            /* Mostrar el dropdown VACÍO con un placeholder — confirma
               visualmente que la búsqueda corrió aunque no haya match,
               y deja claro al usuario que está libre para inventar. */
            var empty = document.createElement('div');
            empty.className = 'profile-add-suggest-empty';
            empty.textContent = 'Sin sugerencias — escribe libre.';
            addSuggest.appendChild(empty);
            addSuggest.hidden = false;
            addSuggestActiveIdx = -1;
            return;
        }
        suggestions.forEach(function(s, i) {
            var row = document.createElement('div');
            row.className = 'profile-add-suggest-row';
            row.dataset.idx = i;
            if (s.image) {
                var im = document.createElement('img');
                im.className = 'profile-add-suggest-thumb';
                im.src = s.image;
                im.alt = '';
                im.onerror = function(){ im.style.display = 'none'; };
                row.appendChild(im);
            } else {
                var ph = document.createElement('div');
                ph.className = 'profile-add-suggest-thumb empty';
                ph.textContent = '🖼';
                row.appendChild(ph);
            }
            var t = document.createElement('span');
            t.className = 'profile-add-suggest-title';
            t.textContent = s.title;
            row.appendChild(t);
            row.addEventListener('mousedown', function(e) {
                /* mousedown (no click) → previene blur del input antes
                   de que el value se actualice si el input pierde foco. */
                e.preventDefault();
                addNameInput.value = s.title;
                if (s.image && !addImgInput.value.trim()) addImgInput.value = s.image;
                /* Autorrelleno de duración/capítulos desde la sugerencia. */
                if (s.runtime && ADD_RUNTIME_LABEL[addDialogCat] && !addRuntimeEl.value.trim()) {
                    addRuntimeEl.value = s.runtime;
                }
                hideSuggest();
                addNameInput.focus();
            });
            addSuggest.appendChild(row);
        });
        addSuggest.hidden = false;
        addSuggestActiveIdx = -1;
    }

    function fetchSuggestions() {
        var q = addNameInput.value.trim();
        if (q.length < 1 || !addDialogCat) { hideSuggest(); return; }
        if (addSuggestCtrl) { try { addSuggestCtrl.abort(); } catch(_){} }
        addSuggestCtrl = new AbortController();
        var ctrl = addSuggestCtrl;
        var cat = addDialogCat;
        fetch('assets/profile/api.php?action=search-titles&category=' +
              encodeURIComponent(cat) + '&q=' + encodeURIComponent(q), {
            credentials: 'same-origin', signal: ctrl.signal
        })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (ctrl.signal.aborted) return;
                /* Guard contra stale: si el user cambió de categoría o
                   vació el input mientras llegaba la respuesta, ignora. */
                if (cat !== addDialogCat) return;
                if (addNameInput.value.trim() !== q) return;
                renderSuggest((d && d.ok) ? (d.suggestions || []) : []);
            })
            .catch(function(){});
    }

    function openAddDialog(cat) {
        addDialogCat = cat;
        /* Series y music tienen PNG dedicado — los renderizamos como
           innerHTML; el resto usa textContent con el emoji del dict. */
        var ADD_PNG = {
            series: 'assets/img/appIcons/melonArchiveIcon.png',
            music:  'assets/img/appIcons/musicaIcon.png'
        };
        if (ADD_PNG[cat]) {
            addDlgTitle.innerHTML = '+ Añadir · <img src="' + ADD_PNG[cat] + '" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        } else {
            addDlgTitle.innerHTML = '+ Añadir · ' + CATS[cat].icon;
        }
        addNameInput.value = '';
        addImgInput.value  = '';
        if (ADD_RUNTIME_LABEL[cat]) {
            addRuntimeLbl.textContent = ADD_RUNTIME_LABEL[cat];
            addRuntimeEl.value = '';
            addRuntimeRow.style.display = '';
        } else {
            addRuntimeRow.style.display = 'none';
            addRuntimeEl.value = '';
        }
        /* Botón de capítulos solo en series. */
        var addEpsRow = document.getElementById('profile-add-eps-row');
        if (addEpsRow) addEpsRow.style.display = (cat === 'series') ? '' : 'none';
        /* Campo de PDF solo en libros. */
        var addPdfRow = document.getElementById('profile-add-pdf-row');
        var addPdfEl  = document.getElementById('profile-add-pdf');
        if (addPdfEl) addPdfEl.value = '';
        if (addPdfRow) addPdfRow.style.display = (cat === 'books') ? '' : 'none';
        document.getElementById('profile-add-error').textContent = '';
        addDlg.style.display = 'flex';
        setTimeout(function() { addNameInput.focus(); }, 0);
    }

    function closeAddDialog() {
        addDlg.style.display = 'none';
        addDialogCat = null;
        hideSuggest();
        if (addSuggestTimer) { clearTimeout(addSuggestTimer); addSuggestTimer = null; }
        if (addSuggestCtrl)  { try { addSuggestCtrl.abort(); } catch(_){} addSuggestCtrl = null; }
    }

    function submitAdd() {
        var title = addNameInput.value.trim();
        if (!title || !addDialogCat) return;
        var lower = title.toLowerCase();
        var errEl = document.getElementById('profile-add-error');
        if (lists[addDialogCat].some(function(it) { return it.title.toLowerCase() === lower; })) {
            errEl.textContent = '⚠ Ya tienes "' + title + '" en tu lista.';
            return;
        }
        errEl.textContent = '';
        var newItem = {
            id:     'item_' + Date.now(),
            title:  title,
            image:  addImgInput.value.trim(),
            status: 'pending'
        };
        if (ADD_RUNTIME_LABEL[addDialogCat]) {
            var rt = parseInt(addRuntimeEl.value, 10) || 0;
            if (rt > 0) newItem.runtime = rt;
        }
        /* Libros: guardar el PDF (compartido por título) si se puso uno. */
        if (addDialogCat === 'books') {
            var pdfEl = document.getElementById('profile-add-pdf');
            var pdfUrl = pdfEl ? pdfEl.value.trim() : '';
            if (pdfUrl) {
                fetch('assets/profile/api.php?action=save-book-pdf', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bookTitle: title, url: pdfUrl })
                }).catch(function(){});
            }
        }
        lists[addDialogCat].push(newItem);
        saveCategory(addDialogCat);
        updateCounts();
        if (currentCat) { renderCatView(currentCat); renderCatEncurso(currentCat); }
        closeAddDialog();
    }

    /* Capítulos al añadir una serie: usa el nombre escrito (los capítulos
       son compartidos por título de serie, así que se pueden gestionar
       antes incluso de guardar el item). */
    var addEpsBtn = document.getElementById('profile-add-eps-btn');
    if (addEpsBtn) addEpsBtn.addEventListener('click', function() {
        var t = addNameInput.value.trim();
        if (!t) { document.getElementById('profile-add-error').textContent = '⚠ Escribe el nombre de la serie primero.'; return; }
        openEpisodesWindow(t, false);
    });
    document.getElementById('profile-add-dialog-submit').addEventListener('click', submitAdd);
    document.getElementById('profile-add-dialog-cancel').addEventListener('click', closeAddDialog);
    document.getElementById('profile-add-dialog-close').addEventListener('click', closeAddDialog);

    /* Autocomplete: debounce 200ms al teclear → pide sugerencias. */
    addNameInput.addEventListener('input', function() {
        if (addSuggestTimer) clearTimeout(addSuggestTimer);
        addSuggestTimer = setTimeout(fetchSuggestions, 200);
    });
    addNameInput.addEventListener('blur', function() {
        /* Pequeño delay para permitir click en row (handled por mousedown). */
        setTimeout(hideSuggest, 120);
    });
    addNameInput.addEventListener('focus', function() {
        /* Re-mostrar sugerencias si el input tiene contenido. */
        if (addNameInput.value.trim().length >= 1) fetchSuggestions();
    });

    /* Teclado en addNameInput: Enter submit, Escape cierra, flechas
       navegan el dropdown si está visible. */
    addNameInput.addEventListener('keydown', function(e) {
        var rows = addSuggest.querySelectorAll('.profile-add-suggest-row');
        var open = !addSuggest.hidden && rows.length > 0;
        if (open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            addSuggestActiveIdx += (e.key === 'ArrowDown') ? 1 : -1;
            if (addSuggestActiveIdx < 0) addSuggestActiveIdx = rows.length - 1;
            if (addSuggestActiveIdx >= rows.length) addSuggestActiveIdx = 0;
            for (var i = 0; i < rows.length; i++) {
                rows[i].classList.toggle('active', i === addSuggestActiveIdx);
            }
            rows[addSuggestActiveIdx].scrollIntoView({ block: 'nearest' });
            return;
        }
        if (e.key === 'Enter') {
            if (open && addSuggestActiveIdx >= 0 && addSuggestActiveIdx < rows.length) {
                /* Seleccionar fila resaltada del dropdown en lugar de submit. */
                e.preventDefault();
                rows[addSuggestActiveIdx].dispatchEvent(new MouseEvent('mousedown'));
                return;
            }
            submitAdd();
        }
        if (e.key === 'Escape') {
            if (open) { hideSuggest(); return; }
            closeAddDialog();
        }
    });
    /* Image input mantiene el comportamiento simple (Enter/Escape). */
    addImgInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter')  submitAdd();
        if (e.key === 'Escape') closeAddDialog();
    });

    /* ──── Category view buttons ──── */
    var catviewAddBtn  = document.getElementById('profile-catview-add-btn');
    var catviewBackBtn = document.getElementById('profile-catview-back');
    if (catviewAddBtn) catviewAddBtn.addEventListener('click', function() {
        if (viewingUser) return;
        if (currentCat) openAddDialog(currentCat);
    });
    if (catviewBackBtn) catviewBackBtn.addEventListener('click', function() {
        /* Volver SIEMPRE devuelve al perfil propio */
        if (viewingUser) exitViewingUser();
        showDefaultView();
    });

    /* ──── Posts ──── */
    function relTime(ts) {
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 60)   return 'ahora';
        if (diff < 3600) return 'hace ' + Math.floor(diff / 60) + 'm';
        if (diff < 86400) return 'hace ' + Math.floor(diff / 3600) + 'h';
        return 'hace ' + Math.floor(diff / 86400) + 'd';
    }

    function loadProfile(cb) {
        fetch('assets/profile/api.php?action=get-profile')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && !data.error) {
                    renderPosts(data.posts || []);
                    renderProfileInfo(data);
                    ownFollowing = Array.isArray(data.following) ? data.following : [];
                    loadMyFollowers(function() { renderFollowedNav(); });
                }
                if (cb) cb();
            })
            .catch(function() { if (cb) cb(); });
    }

    function fitBioText(bioEl) {
        var textEl = bioEl && bioEl.querySelector('.pinfo-bio-text');
        if (!textEl) return;
        if (bioEl.offsetWidth === 0 || bioEl.offsetHeight === 0) return;
        var availW = bioEl.clientWidth  - 8;
        var availH = bioEl.clientHeight - 8;
        if (availW <= 0 || availH <= 0) return;
        var lo = 9, hi = 16, best = lo;
        while (lo <= hi) {
            var mid = (lo + hi) >> 1;
            textEl.style.fontSize = mid + 'px';
            if (textEl.scrollWidth <= availW && textEl.scrollHeight <= availH) {
                best = mid;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }
        textEl.style.fontSize = best + 'px';
    }

    /* Cumpleaños 'AAAA-MM-DD' → "3 de noviembre" (sin año: la edad ya
       va en su línea) y → años cumplidos. '' si la fecha no vale. */
    function partesCumple(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
        return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
    }
    function cumpleLegible(s) {
        var p = partesCumple(s);
        var meses = ['enero','febrero','marzo','abril','mayo','junio','julio',
                     'agosto','septiembre','octubre','noviembre','diciembre'];
        return p ? p.d + ' de ' + meses[p.m - 1] : '';
    }
    function edadDesdeCumple(s) {
        var p = partesCumple(s);
        if (!p) return '';
        var hoy = new Date();
        var edad = hoy.getFullYear() - p.y;
        if (hoy.getMonth() + 1 < p.m || (hoy.getMonth() + 1 === p.m && hoy.getDate() < p.d)) edad--;
        return edad >= 0 ? String(edad) : '';
    }

    function renderProfileInfo(data) {
        var bioEl   = document.getElementById('profile-info-bio');
        var metaEl  = document.getElementById('profile-info-meta');
        var linksEl = document.getElementById('profile-info-links');
        if (!bioEl) return;

        if (data.bio) {
            bioEl.innerHTML = '<span class="pinfo-bio-text"></span>';
            bioEl.querySelector('.pinfo-bio-text').textContent = '" ' + data.bio + ' "';
            bioEl.style.display = '';
            requestAnimationFrame(function() { fitBioText(bioEl); });
        } else {
            bioEl.innerHTML = '';
            bioEl.style.display = 'none';
        }

        metaEl.innerHTML = '';
        function line(txt) { var s = document.createElement('div'); s.className = 'pinfo-line'; s.textContent = txt; return s; }
        /* Con cumpleaños la edad se calcula aquí también (al guardar se
           pinta el borrador, sin volver a pedir el perfil). */
        var age = data.birthday ? edadDesdeCumple(data.birthday) : data.age;
        if (data.pronouns) metaEl.appendChild(line(data.pronouns));
        if (age !== '' && age != null) metaEl.appendChild(line(age + ' años'));
        if (data.birthday) metaEl.appendChild(line('🎂 ' + cumpleLegible(data.birthday)));
        if (data.country)  metaEl.appendChild(line('📍 ' + data.country));

        linksEl.innerHTML = '';
        /* Iconos PNG dedicados para cada red social. */
        var STEAM_PNG     = '<img src="assets/img/appIcons/steamIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        var DISCORD_PNG   = '<img src="assets/img/appIcons/discordIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        var TWITTER_PNG   = '<img src="assets/img/appIcons/twitterIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        var INSTAGRAM_PNG = '<img src="assets/img/appIcons/instagramIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
        var socials = [
            { key: 'steam',     icon: STEAM_PNG,     label: 'Steam',
              url: function(v) { return /^https?:\/\//.test(v) ? v : 'https://steamcommunity.com/id/' + encodeURIComponent(v); } },
            { key: 'discord',   icon: DISCORD_PNG,   label: 'Discord',   url: null },
            { key: 'twitter',   icon: TWITTER_PNG,   label: 'Twitter',
              url: function(v) { return /^https?:\/\//.test(v) ? v : 'https://x.com/' + encodeURIComponent(v.replace(/^@/, '')); } },
            { key: 'instagram', icon: INSTAGRAM_PNG, label: 'Instagram',
              url: function(v) { return /^https?:\/\//.test(v) ? v : 'https://instagram.com/' + encodeURIComponent(v.replace(/^@/, '')); } },
        ];
        socials.forEach(function(s) {
            if (!data[s.key]) return;
            var a = document.createElement('a');
            a.className = 'pinfo-social';
            a.innerHTML = s.icon;
            a.title = s.label + ': ' + data[s.key];
            if (s.url) {
                a.href = s.url(data[s.key]);
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
            } else {
                a.href = '#';
                a.title = s.label + ' (clic para copiar): ' + data[s.key];
                (function(val) {
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (navigator.clipboard) navigator.clipboard.writeText(val).catch(function(){});
                    });
                })(data[s.key]);
            }
            linksEl.appendChild(a);
        });
    }

    (function setupBioResize() {
        var bioEl = document.getElementById('profile-info-bio');
        if (!bioEl) return;
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function() { fitBioText(bioEl); });
            ro.observe(bioEl);
        } else {
            window.addEventListener('resize', function() { fitBioText(bioEl); });
        }
    })();

    /* ──── Collapsible sections ──── */
    function makeCollapsible(headerEl, bodyEl) {
        /* Si se pasa bodyEl, pliega sólo ese elemento. Si no, todos los siguientes hermanos. */
        if (!headerEl || headerEl.dataset.collapsibleReady === '1') return;
        var arrow = document.createElement('button');
        arrow.type = 'button';
        arrow.className = 'profile-collapse-arrow';
        arrow.title = 'Plegar/desplegar';
        arrow.textContent = '▼';
        headerEl.appendChild(arrow);
        headerEl.dataset.collapsibleReady = '1';

        function toggleEl(el, collapsed) {
            if (collapsed) {
                if (el.style.display !== 'none') el.dataset._prevDisp = el.style.display || '';
                el.style.display = 'none';
            } else {
                el.style.display = (el.dataset._prevDisp !== undefined) ? el.dataset._prevDisp : '';
                delete el.dataset._prevDisp;
            }
        }

        arrow.addEventListener('click', function(e) {
            e.stopPropagation();
            var collapsed = headerEl.classList.toggle('section-collapsed');
            if (bodyEl) {
                toggleEl(bodyEl, collapsed);
            } else {
                var sib = headerEl.nextElementSibling;
                while (sib) { toggleEl(sib, collapsed); sib = sib.nextElementSibling; }
            }
            arrow.textContent = collapsed ? '▶' : '▼';
        });
    }

    (function setupCollapsibles() {
        /* Pendientes / Vistas / En curso / Destacados NO son plegables (sin flecha ▼). */
        /* Mis Listas pliega sólo su contenedor de nav (NO el resto del sidebar) */
        var listasHd  = document.getElementById('profile-listas-heading');
        var listasNav = document.getElementById('profile-listas-nav');
        if (listasHd && listasNav) makeCollapsible(listasHd, listasNav);
        /* Melon reviews: pliega sólo su nav (NO el resto del sidebar) */
        var melonHd  = document.querySelector('#profile-melon-section > .profile-sidebar-heading');
        var melonNav = document.getElementById('profile-melon-nav');
        if (melonHd && melonNav) makeCollapsible(melonHd, melonNav);
        /* Social: la cabecera plega sus hermanos dentro de #profile-social-section */
        var socialHd = document.querySelector('#profile-social-section > .profile-sidebar-heading');
        if (socialHd) makeCollapsible(socialHd);
    })();

    /* ──── Profile notifications ──── */
    var profileNotifs = [];
    /* Sonido de notif del perfil. Suena cuando llega una nueva (SSE o
       polling), tenga o no abierta la ventana de perfil. Throttle de 1.5s
       para que varios eventos seguidos no se acumulen.

       - _prevProfileUnread = -1 marca "primer load" → NO suena el contador
         inicial; solo subidas posteriores.
       - El play() puede fallar si el navegador bloqueó autoplay antes de
         cualquier interacción del usuario; lo ignoramos silenciosamente. */
    var _notifAudio = null;
    var _lastNotifSoundAt = 0;
    var _prevProfileUnread = -1;
    /* Preferencias de mute. Cargadas del backend al boot; default todo
       a false. Categoría por tipo:
         - 'profile' : review, follow, post (notifs sobre TUS contenidos
                       que un OTRO usuario interactuó — "perfil de otros
                       usuarios" desde tu perspectiva).
         - 'social'  : like, comment.
         - 'messages': mensajes de chat.
       Si la única notif nueva entre dos polls está toda muteada, no
       suena. Si hay al menos una no-muteada, suena (cubre el caso
       mixto). El indicador online del usuario pasa a rojo (DND) si
       mute_messages está activo. */
    var NOTIF_PREFS = { mute_profile: false, mute_social: false, mute_messages: false };
    var NOTIF_CATEGORY = {
        follow: 'profile', review: 'profile', post: 'profile',
        like: 'social', comment: 'social',
        chat: 'messages', message: 'messages'
    };
    function notifIsMuted(type) {
        var cat = NOTIF_CATEGORY[type];
        if (cat === 'profile')  return !!NOTIF_PREFS.mute_profile;
        if (cat === 'social')   return !!NOTIF_PREFS.mute_social;
        if (cat === 'messages') return !!NOTIF_PREFS.mute_messages;
        return false;   /* desconocido → suena */
    }
    /* Expuesto para que el chat de la misma página llame antes de su
       propio playNotifSound. */
    window.profileNotifIsMuted = notifIsMuted;
    window.profileNotifPrefs   = function(){ return NOTIF_PREFS; };
    function playProfileNotifSound(typeHint) {
        if (typeHint && notifIsMuted(typeHint)) return;
        var now = Date.now();
        if (now - _lastNotifSoundAt < 1500) return;
        _lastNotifSoundAt = now;
        try {
            if (!_notifAudio) {
                _notifAudio = new Audio('assets/sound/notificacion.wav');
                _notifAudio.preload = 'auto';
            }
            _notifAudio.currentTime = 0;
            var p = _notifAudio.play();
            if (p && typeof p.catch === 'function') p.catch(function(){});
        } catch (_) {}
    }
    /* Sincronización con localStorage: el iframe de chat lee la misma
       clave para decidir si reproducir el ping al recibir un mensaje
       (sin tener que hacer otro fetch al backend). */
    function _persistPrefsLocal() {
        try { localStorage.setItem('melonNotifPrefs', JSON.stringify(NOTIF_PREFS)); } catch (_) {}
    }
    /* Carga inicial: intentamos localStorage primero (instant) y luego
       refrescamos desde BD para alinear con el otro dispositivo. */
    try {
        var cached = JSON.parse(localStorage.getItem('melonNotifPrefs') || 'null');
        if (cached && typeof cached === 'object') {
            NOTIF_PREFS.mute_profile  = !!cached.mute_profile;
            NOTIF_PREFS.mute_social   = !!cached.mute_social;
            NOTIF_PREFS.mute_messages = !!cached.mute_messages;
        }
    } catch (_) {}
    fetch('assets/profile/api.php?action=notif-settings')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.ok) {
                NOTIF_PREFS.mute_profile  = !!d.mute_profile;
                NOTIF_PREFS.mute_social   = !!d.mute_social;
                NOTIF_PREFS.mute_messages = !!d.mute_messages;
                _persistPrefsLocal();
            }
        })
        .catch(function(){});

    /* Set de notif IDs ya vistos en pollings anteriores — sirve para
       detectar los nuevos sin necesidad de comparar `unread` (que se
       resetea cuando el usuario abre la ventana). */
    var _seenProfileNotifIds = {};
    function loadProfileNotifs(cb) {
        fetch('assets/profile/api.php?action=get-profile-notifs')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.ok) {
                    profileNotifs = Array.isArray(data.notifs) ? data.notifs : [];
                    var unread = data.unread || 0;
                    /* Suena solo si subió respecto al baseline anterior.
                       En el primer load (_prevProfileUnread === -1) NO
                       suena — no queremos disparar el sonido por notifs
                       ya existentes al abrir la sesión. */
                    if (_prevProfileUnread >= 0 && unread > _prevProfileUnread) {
                        /* Solo suena si al menos una de las notifs sin
                           leer NO está muteada. Si todas las nuevas son
                           de categorías muteadas, silencio total. */
                        var unmuted = (profileNotifs || []).filter(function(n){
                            return !n.read && !notifIsMuted(n.type);
                        });
                        if (unmuted.length) playProfileNotifSound();
                    }
                    /* Detecta notifs nuevas por ID (no se ven en pollings
                       anteriores). Si entre ellas hay un `follow`,
                       alguien nos empezó a seguir → refresca la sección
                       Social para que el botón "Seguir de vuelta" / la
                       lista de seguidores se vea sin recargar la app. */
                    var firstLoad = !Object.keys(_seenProfileNotifIds).length;
                    var newFollow = false;
                    profileNotifs.forEach(function(n) {
                        if (n && n.id != null && !_seenProfileNotifIds[n.id]) {
                            _seenProfileNotifIds[n.id] = true;
                            if (!firstLoad && n.type === 'follow') newFollow = true;
                        }
                    });
                    if (newFollow) {
                        loadMyFollowers(function() {
                            if (typeof renderFollowedNav === 'function') renderFollowedNav();
                            if (typeof renderSocialList   === 'function') renderSocialList();
                        });
                    }
                    _prevProfileUnread = unread;
                    updateNotifBadge(unread);
                }
                if (cb) cb();
            })
            .catch(function() { if (cb) cb(); });
    }

    function updateNotifBadge(count) {
        var badge = document.getElementById('profile-notif-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }
        /* Campana en el botón del taskbar */
        var tbBtn = (window.taskbarManager && typeof taskbarManager.getButton === 'function')
            ? taskbarManager.getButton('profile-window') : null;
        if (tbBtn) {
            var bell = tbBtn.querySelector('.taskbar-notif-bell');
            if (count > 0) {
                if (!bell) {
                    bell = document.createElement('span');
                    bell.className = 'taskbar-notif-bell';
                    bell.innerHTML = '<img src="assets/img/appIcons/bellIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;display:block;margin:auto;">';
                    tbBtn.appendChild(bell);
                }
            } else if (bell) {
                bell.remove();
            }
        }
    }

    function renderProfileNotifs() {
        var listEl  = document.getElementById('profile-notifs-list');
        var emptyEl = document.getElementById('profile-notifs-empty');
        if (!listEl) return;
        listEl.innerHTML = '';
        if (!profileNotifs.length) {
            if (emptyEl) emptyEl.style.display = '';
            return;
        }
        if (emptyEl) emptyEl.style.display = 'none';
        profileNotifs.forEach(function(n) {
            var u = PROFILE_USERS[n.fromUser];
            if (!u) return;
            var row = document.createElement('div');
            row.className = 'profile-notif-row' + (n.read ? '' : ' unread');
            var avFrame = document.createElement('div');
            avFrame.className = 'profile-avatar-frame profile-notif-av';
            if (u.image) {
                var img = document.createElement('img');
                img.src = u.image; img.alt = u.label;
                avFrame.appendChild(img);
            } else {
                avFrame.innerHTML = '<div class="profile-avatar-placeholder">👤</div>';
            }
            row.appendChild(avFrame);
            var info = document.createElement('div');
            info.className = 'profile-notif-info';
            var msg = document.createElement('div');
            msg.className = 'profile-notif-msg';
            if (n.type === 'follow') {
                msg.innerHTML = '<strong>' + escHtml(u.label) + '</strong> te ha seguido';
            } else if (n.type === 'like') {
                var snippet = n.postText ? n.postText : '';
                if (snippet.length > 60) snippet = snippet.substring(0, 60) + '…';
                msg.innerHTML = '<strong>' + escHtml(u.label) + '</strong> le ha dado ❤ a tu publicación'
                    + (snippet ? ': <em>"' + escHtml(snippet) + '"</em>' : '');
            } else if (n.type === 'review') {
                var noun;
                if (n.category === 'movies')      noun = 'la película';
                else if (n.category === 'series') noun = 'la serie';
                else if (n.category === 'books')  noun = 'el libro';
                else if (n.category === 'games')  noun = 'el videojuego';
                else if (n.category === 'music')  noun = (n.mtype === 'album') ? 'el álbum' : 'la canción';
                else                              noun = '';
                var itemT = n.itemTitle ? n.itemTitle : '';
                msg.innerHTML = '<strong>' + escHtml(u.label) + '</strong> ha reseñado '
                    + (noun ? noun + ' ' : '')
                    + '<em>"' + escHtml(itemT) + '"</em>';
            } else {
                msg.textContent = u.label;
            }
            info.appendChild(msg);
            var t = document.createElement('div');
            t.className = 'profile-notif-time';
            t.textContent = relTime(n.createdAt || 0);
            info.appendChild(t);
            row.appendChild(info);

            /* Botón "Seguir" para notifs de tipo follow */
            if (n.type === 'follow') {
                var fbBtn = document.createElement('button');
                fbBtn.className = 'button profile-notif-follow-btn';
                var nowFollowing = ownFollowing.indexOf(n.fromUser) !== -1;
                fbBtn.textContent = nowFollowing ? '✓ Siguiendo' : '+ Seguir';
                if (nowFollowing) fbBtn.classList.add('following');
                (function(uk, b) {
                    b.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if (b.dataset.busy === '1') return;
                        b.dataset.busy = '1';
                        fetch('assets/profile/api.php?action=toggle-follow', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ targetUser: uk })
                        }).then(function(r) { return r.json(); })
                          .then(function(d) {
                              b.dataset.busy = '';
                              if (!d || d.error) { if (d && d.error) alert(d.error); return; }
                              ownFollowing = Array.isArray(d.list) ? d.list : ownFollowing;
                              b.textContent = d.following ? '✓ Siguiendo' : '+ Seguir';
                              b.classList.toggle('following', !!d.following);
                              renderFollowedNav();
                              /* La lista "Explorar" de la sección Social
                                 filtra por `ownFollowing`. Tras seguir/
                                 dejar de seguir, hay que repintarla para
                                 que el usuario aparezca/desaparezca de
                                 ahí en tiempo real. */
                              if (typeof renderSocialList === 'function') renderSocialList();
                              /* Si el perfil que se está viendo es este usuario, sincroniza también el botón principal */
                              if (viewingUser === uk) updateFollowButton(!!d.following);
                          }).catch(function() { b.dataset.busy = ''; });
                    });
                })(n.fromUser, fbBtn);
                row.appendChild(fbBtn);
            }

            (function(uk, notif) {
                row.addEventListener('click', function() {
                    closeNotifsWindow();
                    /* Si es notif de reseña, deep-link a la reseña
                       concreta del visitado (categoría + título del
                       item). El perfil destino abre la ventana de
                       reseña directamente tras cargar las lists. */
                    var opts = null;
                    if (notif.type === 'review' && notif.category && notif.itemTitle) {
                        opts = {
                            reviewCategory: notif.category,
                            reviewTitle:    notif.itemTitle,
                            reviewMtype:    notif.mtype || ''   /* 'album' | 'song' (solo música) */
                        };
                    }
                    /* Abre el perfil de uk como NUEVA ventana iframe
                       (con su interfaz/tema/iconos). Si estamos en
                       standalone, forward al shell padre vía postMessage. */
                    if (typeof window.openProfileAtUser === 'function') {
                        window.openProfileAtUser(uk, opts);
                    } else {
                        viewOtherUser(uk, opts);
                    }
                });
            })(n.fromUser, n);
            listEl.appendChild(row);
        });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function openNotifsWindow() {
        var win = document.getElementById('profile-notifs-window');
        if (!win) return;
        renderProfileNotifs();
        /* flex (no block) para que flex-direction:column de la ventana
           se active y el window-body pueda usar flex:1 para encogerse
           con la ventana al hacerse pequeña. */
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        /* Marca todas como leídas en servidor + UI */
        if (profileNotifs.some(function(n) { return !n.read; })) {
            fetch('assets/profile/api.php?action=mark-notifs-read', { method: 'POST' }).catch(function(){});
            profileNotifs.forEach(function(n) { n.read = true; });
            updateNotifBadge(0);
        }
    }
    function closeNotifsWindow() {
        var win = document.getElementById('profile-notifs-window');
        if (win) win.style.display = 'none';
    }

    (function setupNotifBtn() {
        var btn  = document.getElementById('profile-notif-btn');
        var clo  = document.getElementById('profile-notifs-close');
        if (btn) {
            /* Toggle: si la ventana ya está visible, otro click en la
               campana la cierra. Antes solo abría — el usuario tenía que
               ir a la X de la ventana para cerrarla.
               openNotifsWindow setea display:'flex'; closeNotifsWindow lo
               vuelve a 'none'. Por eso comparamos contra el set 'flex'. */
            btn.addEventListener('click', function() {
                var win = document.getElementById('profile-notifs-window');
                var open = !!(win && win.style.display === 'flex');
                if (open) closeNotifsWindow();
                else      openNotifsWindow();
            });
        }
        if (clo) clo.addEventListener('click', closeNotifsWindow);
    })();

    /* Dialog de preferencias de notificaciones (silenciar perfil /
       social / mensajes). El mute_messages también activa el modo
       "no molestar" → el indicador online del usuario pasa a rojo
       (renderizado en el backend `presence` → frontend mira el set
       `dnd` y aplica la clase). */
    (function setupNotifPrefs() {
        var openBtn  = document.getElementById('profile-notifs-settings-btn');
        var win      = document.getElementById('profile-notif-prefs-window');
        var closeBtn = document.getElementById('profile-notif-prefs-close');
        var cancel   = document.getElementById('np-cancel');
        var save     = document.getElementById('np-save');
        var cP = document.getElementById('np-mute-profile');
        var cS = document.getElementById('np-mute-social');
        var cM = document.getElementById('np-mute-messages');
        var status = document.getElementById('np-status');
        if (!openBtn || !win) return;
        function open() {
            /* UI: checked = SUENA. Storage: mute_* = true cuando NO
               suena. Invertimos al cargar. */
            cP.checked = !NOTIF_PREFS.mute_profile;
            cS.checked = !NOTIF_PREFS.mute_social;
            cM.checked = !NOTIF_PREFS.mute_messages;
            status.textContent = '';
            win.style.display = 'flex';
            win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
            win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        }
        function close() { win.style.display = 'none'; }
        openBtn.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (cancel)   cancel.addEventListener('click', close);
        save.addEventListener('click', function(){
            /* UI: checked = SUENA → storage mute_* = !checked. */
            var payload = {
                mute_profile:  !cP.checked,
                mute_social:   !cS.checked,
                mute_messages: !cM.checked
            };
            status.textContent = 'Guardando…';
            fetch('assets/profile/api.php?action=notif-settings', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.ok) {
                    NOTIF_PREFS.mute_profile  = payload.mute_profile;
                    NOTIF_PREFS.mute_social   = payload.mute_social;
                    NOTIF_PREFS.mute_messages = payload.mute_messages;
                    _persistPrefsLocal();
                    status.textContent = 'Guardado.';
                    setTimeout(close, 600);
                } else {
                    status.textContent = 'Error al guardar.';
                }
            })
            .catch(function(){ status.textContent = 'Error de red.'; });
        });
    })();

    (function setupFollowBtn() {
        var btn = document.getElementById('profile-follow-btn');
        if (!btn) return;
        btn.addEventListener('click', function() {
            if (!viewingUser || btn.dataset.busy === '1') return;
            btn.dataset.busy = '1';
            fetch('assets/profile/api.php?action=toggle-follow', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ targetUser: viewingUser })
            }).then(function(r) { return r.json(); })
              .then(function(d) {
                  btn.dataset.busy = '';
                  if (!d || d.error) { if (d && d.error) alert(d.error); return; }
                  ownFollowing = Array.isArray(d.list) ? d.list : ownFollowing;
                  updateFollowButton(!!d.following);
                  loadMyFollowers(function() {
                      renderFollowedNav();
                      /* La sección "Social → Explorar" filtra por
                         `ownFollowing`; el usuario que acabamos de
                         seguir/dejar de seguir debe aparecer/
                         desaparecer de la lista sin recargar la app. */
                      if (typeof renderSocialList === 'function') renderSocialList();
                  });
              }).catch(function() { btn.dataset.busy = ''; });
        });
    })();

    /* Exposed globally so external code can open the profile on the music tab */
    window.profileOpenMusic = function() {
        if (taskbarManager.isRegistered('profile-window')) {
            taskbarManager.restore('profile-window');
        } else {
            profileWin.style.height = Math.max(380, window.innerHeight - 80) + 'px';
            taskbarManager.register('profile-window', 'Perfil', '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
            startItemNotifStream();
        }
        if (!loaded) {
            loaded = true;
            loadLists(function() { updateCounts(); showMusicView(); });
            loadProfile();
        } else {
            showMusicView();
        }
    };

    /* Exposed globally so the player right-click menu can add the current track */
    /* Análogo a profileAddTrackAndReview pero para álbumes — se usa
       desde el reproductor (ctx menu del cover del álbum). Acepta
       { name, artist, spotifyAlbumId, image } y crea un item de
       tipo "album" en lists.music con su propia entrada (dedupe por
       spotifyAlbumId / título normalizado). */
    window.profileAddAlbum = function(album) {
        if (!album || !album.name) return;
        if (taskbarManager.isRegistered('profile-window')) {
            taskbarManager.restore('profile-window');
        } else {
            profileWin.style.height = Math.max(380, window.innerHeight - 80) + 'px';
            taskbarManager.register('profile-window', 'Perfil', '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
            startItemNotifStream();
        }
        function doAdd() {
            var albTitle = (album.name || '').toLowerCase().trim();
            var existIdx = -1;
            for (var i = 0; i < lists.music.length; i++) {
                var it = lists.music[i];
                if (it.type !== 'album') continue;
                if (album.spotifyAlbumId && it.spotifyAlbumId === album.spotifyAlbumId) { existIdx = i; break; }
                if (albTitle && (it.title || '').toLowerCase().trim() === albTitle) { existIdx = i; break; }
            }
            var targetIdx;
            if (existIdx !== -1) {
                targetIdx = existIdx;
            } else {
                var entry = {
                    id:             'music_' + Date.now(),
                    type:           'album',
                    title:          album.name || 'Sin título',
                    artist:         album.artist || '',
                    image:          album.image || '',
                    featured:       false,
                    spotifyAlbumId: album.spotifyAlbumId || '',
                };
                lists.music.push(entry);
                saveCategory('music');
                updateCounts();
                renderMusicView(currentMusicTab);
                renderMusicDestacados();
                targetIdx = lists.music.length - 1;
            }
            var prompt = document.getElementById('profile-review-prompt');
            document.getElementById('profile-review-prompt-msg').textContent = existIdx !== -1
                ? '"' + (album.name || 'Sin título') + '" ya está en tu lista. ¿Editar la reseña?'
                : '¿Añadir una reseña para "' + (album.name || 'Sin título') + '"?';
            prompt.style.display = 'block';
            prompt.style.left = Math.round((window.innerWidth  - prompt.offsetWidth)  / 2) + 'px';
            prompt.style.top  = Math.round((window.innerHeight - prompt.offsetHeight) / 2) + 'px';
            var yesBtn = document.getElementById('profile-review-prompt-yes');
            var noBtn  = document.getElementById('profile-review-prompt-no');
            var newYes = yesBtn.cloneNode(true); yesBtn.parentNode.replaceChild(newYes, yesBtn);
            var newNo  = noBtn.cloneNode(true);  noBtn.parentNode.replaceChild(newNo,  noBtn);
            (function(idx) {
                newYes.addEventListener('click', function() { prompt.style.display = 'none'; showMusicReviewWindow(idx); });
                newNo.addEventListener('click',  function() { prompt.style.display = 'none'; });
            })(targetIdx);
        }
        if (!loaded) { loaded = true; loadLists(function() { updateCounts(); showMusicView(); doAdd(); }); loadProfile(); }
        else doAdd();
    };

    window.profileAddTrackAndReview = function(track) {
        if (!track || !track.videoId) return;
        if (taskbarManager.isRegistered('profile-window')) {
            taskbarManager.restore('profile-window');
        } else {
            profileWin.style.height = Math.max(380, window.innerHeight - 80) + 'px';
            taskbarManager.register('profile-window', 'Perfil', '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
            startItemNotifStream();
        }
        function doAdd() {
            var existIdx = -1;
            var trackTitle = (track.title || '').toLowerCase().trim();
            for (var i = 0; i < lists.music.length; i++) {
                if (lists.music[i].ytId === track.videoId || (trackTitle && lists.music[i].title.toLowerCase().trim() === trackTitle)) {
                    existIdx = i; break;
                }
            }
            var targetIdx;
            if (existIdx !== -1) {
                targetIdx = existIdx;
            } else {
                var entry = {
                    id:       'music_' + Date.now(),
                    type:     'song',
                    title:    track.title  || 'Sin título',
                    artist:   track.artist || '',
                    image:    'https://img.youtube.com/vi/' + track.videoId + '/mqdefault.jpg',
                    featured: false,
                    ytId:     track.videoId
                };
                lists.music.push(entry);
                saveCategory('music');
                updateCounts();
                renderMusicView(currentMusicTab);
                renderMusicDestacados();
                targetIdx = lists.music.length - 1;
            }
            var prompt = document.getElementById('profile-review-prompt');
            document.getElementById('profile-review-prompt-msg').textContent = existIdx !== -1
                ? '"' + (track.title || 'Sin título') + '" ya está en tu lista. ¿Editar la reseña?'
                : '¿Añadir una reseña para "' + (track.title || 'Sin título') + '"?';
            prompt.style.display = 'block';
            prompt.style.left = Math.round((window.innerWidth  - prompt.offsetWidth)  / 2) + 'px';
            prompt.style.top  = Math.round((window.innerHeight - prompt.offsetHeight) / 2) + 'px';
            var yesBtn = document.getElementById('profile-review-prompt-yes');
            var noBtn  = document.getElementById('profile-review-prompt-no');
            var newYes = yesBtn.cloneNode(true); yesBtn.parentNode.replaceChild(newYes, yesBtn);
            var newNo  = noBtn.cloneNode(true);  noBtn.parentNode.replaceChild(newNo,  noBtn);
            (function(idx) {
                newYes.addEventListener('click', function() { prompt.style.display = 'none'; showMusicReviewWindow(idx); });
                newNo.addEventListener('click',  function() { prompt.style.display = 'none'; });
            })(targetIdx);
        }
        if (!loaded) { loaded = true; loadLists(function() { updateCounts(); showMusicView(); doAdd(); }); loadProfile(); }
        else doAdd();
    };

    function renderPosts(posts) {
        var list = document.getElementById('profile-posts-list');
        if (!list) return;
        list.innerHTML = '';
        if (!posts.length) {
            var empty = document.createElement('div');
            empty.className = 'profile-post-empty';
            empty.textContent = 'Sin posts aún';
            list.appendChild(empty);
            return;
        }
        var isViewing = !!viewingUser;
        posts.forEach(function(post) {
            var box = document.createElement('div');
            box.className = 'profile-post-box';

            var hdr = document.createElement('div');
            hdr.className = 'profile-post-hdr';
            var timeEl = document.createElement('span');
            timeEl.className = 'profile-post-time';
            timeEl.textContent = relTime(post.createdAt || 0);
            hdr.appendChild(timeEl);

            /* Like (corazón) en la esquina derecha cuando se ve otro perfil */
            if (isViewing) {
                var likes = Array.isArray(post.likes) ? post.likes : [];
                var likedByMe = likes.indexOf(currentSessionUser) !== -1;
                var likeWrap = document.createElement('span');
                likeWrap.className = 'profile-post-like' + (likedByMe ? ' liked' : '');
                var countEl = document.createElement('span');
                countEl.className = 'profile-post-like-count';
                countEl.textContent = likes.length;
                var heart = document.createElement('span');
                heart.className = 'profile-post-like-icon';
                heart.textContent = likedByMe ? '❤' : '♡';
                likeWrap.appendChild(countEl);
                likeWrap.appendChild(heart);
                (function(p, wrap, c, h) {
                    wrap.addEventListener('click', function() {
                        if (wrap.dataset.busy === '1') return;
                        wrap.dataset.busy = '1';
                        fetch('assets/profile/api.php?action=toggle-post-like', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ targetUser: viewingUser, postId: p.id })
                        }).then(function(r) { return r.json(); })
                          .then(function(d) {
                              wrap.dataset.busy = '';
                              if (!d || d.error) return;
                              c.textContent = d.count;
                              h.textContent = d.liked ? '❤' : '♡';
                              wrap.classList.toggle('liked', !!d.liked);
                              /* Actualiza el array local para que el estado persista en re-render */
                              if (!Array.isArray(p.likes)) p.likes = [];
                              var idx = p.likes.indexOf(currentSessionUser);
                              if (d.liked && idx === -1)      p.likes.push(currentSessionUser);
                              else if (!d.liked && idx !== -1) p.likes.splice(idx, 1);
                          }).catch(function() { wrap.dataset.busy = ''; });
                    });
                })(post, likeWrap, countEl, heart);
                hdr.appendChild(likeWrap);
            } else {
                var rightCol = document.createElement('div');
                rightCol.className = 'profile-post-right';
                var delBtn = document.createElement('button');
                delBtn.className = 'button profile-post-del';
                delBtn.textContent = '×';
                delBtn.addEventListener('click', function() {
                    confirmFn('¿Eliminar este post?', 'Eliminar', function() {
                        fetch('assets/profile/api.php?action=delete-post', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: post.id })
                        }).then(function() { loadProfile(); }).catch(function() {});
                    });
                });
                rightCol.appendChild(delBtn);
                var ownLikes = Array.isArray(post.likes) ? post.likes.length : 0;
                var ownLikeEl = document.createElement('span');
                ownLikeEl.className = 'profile-post-like-display';
                ownLikeEl.innerHTML = '<span class="profile-post-like-count">' + ownLikes + '</span><span class="profile-post-like-icon">❤</span>';
                rightCol.appendChild(ownLikeEl);
                hdr.appendChild(rightCol);
            }

            box.appendChild(hdr);
            /* Texto primero (como un caption arriba), luego la imagen */
            if (post.text) {
                var txt = document.createElement('div');
                txt.className = 'profile-post-text';
                txt.textContent = post.text;
                box.appendChild(txt);
            }
            if (post.imageUrl) {
                var imgWrap = document.createElement('div');
                imgWrap.className = 'profile-post-img';
                var imgEl = document.createElement('img');
                /* no-referrer evita que la Enhanced Tracking Protection de
                   Firefox bloquee la petición a drive.google.com como si
                   fuese un tracker de Google. */
                imgEl.referrerPolicy = 'no-referrer';
                imgEl.loading = 'lazy';
                imgEl.alt = '';
                imgEl.onerror = function() {
                    imgWrap.innerHTML = '<div class="profile-post-img-err">' +
                        '⚠ No se pudo cargar la imagen ' +
                        '<small>(el fichero ha sido borrado de Drive o ya no es público)</small>' +
                        '</div>';
                };
                imgEl.src = post.imageUrl;
                imgWrap.appendChild(imgEl);
                box.appendChild(imgWrap);
            }
            /* Comentarios */
            var commentsWrap = document.createElement('div');
            commentsWrap.className = 'profile-post-comments';
            renderCommentsInto(commentsWrap, post);
            box.appendChild(commentsWrap);
            list.appendChild(box);
        });
    }

    /* Pinta la lista de comentarios + input para añadir uno nuevo dentro de
       `wrap`. Por defecto colapsado: solo el toggle "💬 N comentarios". Al
       expandir aparecen lista y form. El estado expandido se preserva entre
       re-renders (al añadir/borrar) porque vive en wrap.classList. */
    var COMMENTS_PER_PAGE = 10;
    function renderCommentsInto(wrap, post) {
        var wasExpanded = wrap.classList.contains('is-expanded');
        wrap.innerHTML = '';
        var comments  = Array.isArray(post.comments) ? post.comments : [];
        var ownProfile = !viewingUser;

        /* Paginación: estado en wrap.dataset.page (1-indexed) para
           preservar la página entre re-renders (al añadir/borrar). */
        var totalPages  = Math.max(1, Math.ceil(comments.length / COMMENTS_PER_PAGE));
        var currentPage = parseInt(wrap.dataset.page || '1', 10) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        wrap.dataset.page = String(currentPage);
        var pageStart = (currentPage - 1) * COMMENTS_PER_PAGE;
        var pageSlice = comments.slice(pageStart, pageStart + COMMENTS_PER_PAGE);

        /* Toggle visible siempre */
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'profile-post-comments-toggle';
        var chatImg = '<img src="assets/img/appIcons/chatIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">';
        var label = comments.length === 0
            ? chatImg + 'Comentar'
            : chatImg + comments.length + ' ' + (comments.length === 1 ? 'comentario' : 'comentarios');
        toggle.innerHTML = '<span>' + label + '</span><span class="profile-post-comments-chev">▾</span>';
        wrap.appendChild(toggle);

        var inner = document.createElement('div');
        inner.className = 'profile-post-comments-inner';
        inner.style.display = wasExpanded ? '' : 'none';
        wrap.appendChild(inner);

        toggle.addEventListener('click', function() {
            wrap.classList.toggle('is-expanded');
            var nowExp = wrap.classList.contains('is-expanded');
            inner.style.display = nowExp ? '' : 'none';
            toggle.querySelector('.profile-post-comments-chev').textContent = nowExp ? '▴' : '▾';
        });
        /* Restaurar chevron tras re-render si estaba abierto */
        if (wasExpanded) toggle.querySelector('.profile-post-comments-chev').textContent = '▴';

        var listEl = document.createElement('div');
        listEl.className = 'profile-post-comments-list';
        pageSlice.forEach(function(c) {
            var row = document.createElement('div');
            row.className = 'profile-post-comment';
            var avSrc = (PROFILE_USERS[c.authorKey] && PROFILE_USERS[c.authorKey].image) || '';
            var avHtml = avSrc
                ? '<img class="profile-post-comment-av" src="' + escAttr(avSrc) + '" alt="">'
                : '<span class="profile-post-comment-av-ph">👤</span>';
            row.innerHTML = avHtml +
                '<div class="profile-post-comment-body">' +
                    '<div class="profile-post-comment-head">' +
                        '<span class="profile-post-comment-author">' + escHtml(c.authorLabel || c.authorKey) + '</span>' +
                        '<span class="profile-post-comment-time">' + relTime(c.createdAt || 0) + '</span>' +
                    '</div>' +
                    '<div class="profile-post-comment-text"></div>' +
                '</div>';
            row.querySelector('.profile-post-comment-text').textContent = c.text;

            /* Borrar: si soy el autor del comentario O dueño del post */
            var canDelete = (c.authorKey === currentSessionUser) || ownProfile;
            if (canDelete) {
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'profile-post-comment-del';
                x.textContent = '×';
                x.title = 'Borrar comentario';
                (function(cid) {
                    x.addEventListener('click', function(e) {
                        e.stopPropagation();
                        confirmFn('¿Eliminar este comentario?', 'Eliminar', function() {
                            fetch('assets/profile/api.php?action=delete-comment', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id: cid })
                            }).then(function(r) { return r.json(); })
                              .then(function(d) {
                                  if (!d || d.error) return;
                                  post.comments = post.comments.filter(function(x) { return x.id !== cid; });
                                  renderCommentsInto(wrap, post);
                              });
                        });
                    });
                })(c.id);
                row.appendChild(x);
            }
            listEl.appendChild(row);
        });
        inner.appendChild(listEl);

        /* Controles de paginación (solo si hay más de una página) */
        if (totalPages > 1) {
            var pag = document.createElement('div');
            pag.className = 'profile-post-comments-pag';
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'button profile-post-comments-pag-btn';
            prev.textContent = '«';
            prev.title = 'Página anterior';
            prev.disabled = currentPage <= 1;
            prev.addEventListener('click', function() {
                wrap.dataset.page = String(currentPage - 1);
                renderCommentsInto(wrap, post);
            });
            var info = document.createElement('span');
            info.className = 'profile-post-comments-pag-info';
            info.textContent = currentPage + ' / ' + totalPages;
            var next = document.createElement('button');
            next.type = 'button';
            next.className = 'button profile-post-comments-pag-btn';
            next.textContent = '»';
            next.title = 'Página siguiente';
            next.disabled = currentPage >= totalPages;
            next.addEventListener('click', function() {
                wrap.dataset.page = String(currentPage + 1);
                renderCommentsInto(wrap, post);
            });
            pag.appendChild(prev);
            pag.appendChild(info);
            pag.appendChild(next);
            inner.appendChild(pag);
        }

        /* Caja de input para nuevo comentario */
        var form = document.createElement('div');
        form.className = 'profile-post-comment-form';
        var input = document.createElement('input');
        input.type = 'text';
        input.maxLength = 500;
        input.placeholder = 'Comentar…';
        input.className = 'profile-post-comment-input';
        var sendBtn = document.createElement('button');
        sendBtn.type = 'button';
        sendBtn.className = 'button profile-post-comment-send';
        sendBtn.textContent = 'Enviar';
        function send() {
            var t = input.value.trim();
            if (!t) return;
            sendBtn.disabled = true;
            fetch('assets/profile/api.php?action=add-comment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ postId: post.id, text: t })
            }).then(function(r) { return r.json(); })
              .then(function(d) {
                  sendBtn.disabled = false;
                  if (!d || d.error) return;
                  if (!Array.isArray(post.comments)) post.comments = [];
                  post.comments.push(d.comment);
                  input.value = '';
                  /* Saltar a la última página para que el comentario recién
                     enviado sea visible (los comments van ASC por fecha). */
                  wrap.dataset.page = String(Math.ceil(post.comments.length / COMMENTS_PER_PAGE));
                  renderCommentsInto(wrap, post);
              })
              .catch(function() { sendBtn.disabled = false; });
        }
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); send(); }
        });
        sendBtn.addEventListener('click', send);
        form.appendChild(input);
        form.appendChild(sendBtn);
        inner.appendChild(form);
    }
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function(c) {
            return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' })[c];
        });
    }
    function escAttr(s) { return escHtml(s); }

    var postInput = document.getElementById('profile-post-input');
    var postBtn   = document.getElementById('profile-post-btn');
    if (postBtn) {
        /* Guard contra doble-submit: con lag el usuario podía pulsar
           varias veces antes de que la primera request acabase y se
           insertaban posts duplicados. Mientras _postInFlight=true los
           clicks subsiguientes se ignoran y el botón queda desactivado. */
        var _postInFlight = false;
        postBtn.addEventListener('click', function() {
            if (_postInFlight) return;
            var text = postInput ? postInput.value.trim() : '';
            if (!text) return;
            _postInFlight = true;
            postBtn.disabled = true;
            fetch('assets/profile/api.php?action=add-post', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text: text })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { if (postInput) postInput.value = ''; loadProfile(); }
            })
            .catch(function() {})
            .finally(function() {
                _postInFlight = false;
                postBtn.disabled = false;
            });
        });
    }
    if (postInput) {
        postInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (postBtn) postBtn.click(); }
        });
    }

    /* ──── reloadCurrentView helper ──── */
    function reloadCurrentView() {
        if (currentCat === 'music') {
            renderMusicView(currentMusicTab);
            renderMusicDestacados();
        } else if (currentCat) {
            renderCatView(currentCat);
            renderCatEncurso(currentCat);
        }
    }

    /* ──── Social: list of other users + read-only view of their profile ──── */
    var viewingUser  = null;     /* userKey si estamos viendo otro perfil, null si no */
    var ownLists     = null;     /* backup de las listas propias mientras se ve a otro */
    var ownFollowing = [];       /* lista (array de userKey) de la gente que sigo */
    var myFollowers  = [];       /* lista (array de userKey) de la gente que me sigue */
    var OWN_AVATAR_IMG = <?php echo json_encode(isset($profileImg) ? $profileImg : ''); ?>;
    var OWN_LABEL      = <?php echo json_encode($desktopLabel); ?>;

    function applyTopbarUser(wrapId, titleEl, icon, ownLabel) {
        /* Cambia avatar/nombre del topbar y el título según viewingUser */
        var wrap = document.getElementById(wrapId);
        if (!wrap) return;
        var frame = wrap.querySelector('.profile-avatar-frame');
        var nameEl = wrap.querySelector('.profile-catview-username');
        var img, label;
        if (viewingUser && PROFILE_USERS[viewingUser]) {
            var u = PROFILE_USERS[viewingUser];
            img   = u.image || '';
            label = u.label;
        } else {
            img   = OWN_AVATAR_IMG;
            label = OWN_LABEL;
        }
        if (frame) {
            frame.innerHTML = img
                ? '<img src="' + img + '" alt="" class="profile-avatar-img">'
                : '<div class="profile-avatar-placeholder">👤</div>';
            /* Dot de presencia para el usuario al que pertenece la topbar.
               Si no estoy viewing-other, es mi propio user — el dot
               siempre se pintará como online (mi heartbeat está activo). */
            if (window.__attachPresenceDot) {
                window.__attachPresenceDot(frame, viewingUser || currentSessionUser);
            }
        }
        if (nameEl) nameEl.textContent = label;
        if (titleEl) titleEl.innerHTML = icon + ' ' + ownLabel;
    }

    function showSocialView() {
        document.dispatchEvent(new Event('profile-edit-cancel'));
        currentCat = null;
        document.getElementById('profile-view-default').style.display = 'none';
        document.getElementById('profile-view-cat').style.display     = 'none';
        var melonV = document.getElementById('profile-view-melon');
        if (melonV) melonV.style.display = 'none';
        var mv = document.getElementById('profile-view-music');
        if (mv) mv.style.display = 'none';
        document.getElementById('profile-view-social').style.display  = 'flex';
        document.getElementById('profile-sidebar-back').style.display = 'block';
        document.getElementById('profile-info-edit-btn').style.display = 'none';
        renderSocialList();
        loadProfile(function() { renderSocialList(); });
    }

    /* ──── Melon reviews ──── */
    var MELON_LABELS = { year: 'Mejor del año', recent: 'Reciente', alltime: 'Todo el tiempo' };
    var MELON_ICONS  = { movies: '<img src="assets/img/appIcons/pelisIcon.png" alt="" style="width:24px;height:24px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', series: '📺', books: '<img src="assets/img/appIcons/booksIcon.png" alt="" style="width:24px;height:24px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', games: '<img src="assets/img/appIcons/juegosIcon.png" alt="" style="width:24px;height:24px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', music: '🎵' };
    var MELON_MUST_VERBS = { movies: 'watch', series: 'watch', books: 'read', games: 'play', music: 'listen' };
    var melonPeriod = null;
    var melonCat    = null;
    var melonType   = null;
    var melonItemsCache = [];
    var melonPage = 1;
    var MELON_PAGE_SIZE = 20;

    function addMelonItemToProfile(cat, item) {
        if (!cat || !item || !item.title) return;
        var titleLower = item.title.toLowerCase().trim();
        if (!titleLower) return;
        var ownLs = ownLists || lists;
        var arr = ownLs[cat] || [];
        var dup = arr.some(function(it) { return (it.title || '').toLowerCase().trim() === titleLower; });
        if (dup) {
            if (window.notifSystem) {
                window.notifSystem.show({
                    id:      'dup_' + Date.now(),
                    type:    'info',
                    title:   'Ya en tu perfil',
                    message: '"' + item.title + '" ya está en tu lista.'
                });
            }
            return;
        }
        var entry;
        if (cat === 'music') {
            entry = {
                id:       'music_' + Date.now(),
                type:     item.mtype || 'song',
                title:    item.title,
                artist:   item.artist || '',
                image:    item.image  || '',
                featured: false
            };
            if (item.ytId)           entry.ytId           = item.ytId;
            if (item.spotifyId)      entry.spotifyId      = item.spotifyId;
            if (item.ytPlaylistId)   entry.ytPlaylistId   = item.ytPlaylistId;
            if (item.spotifyAlbumId) entry.spotifyAlbumId = item.spotifyAlbumId;
        } else {
            entry = {
                id:     'item_' + Date.now(),
                title:  item.title,
                image:  item.image || '',
                status: 'pending'
            };
        }
        if (!ownLs[cat]) ownLs[cat] = [];
        ownLs[cat].push(entry);
        var newIdx = ownLs[cat].length - 1;
        fetch('assets/profile/api.php?action=save-lists', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: ownLs[cat] })
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              if (d && d.error) { alert(d.error); return; }
              if (window.notifSystem) {
                  window.notifSystem.show({
                      id:      'added_' + Date.now(),
                      type:    'info',
                      title:   cat === 'music' ? 'Música añadida' : 'Añadido a tu perfil',
                      message: cat === 'music'
                          ? '"' + item.title + '" añadido a tu lista.'
                          : '"' + item.title + '" añadido como pendiente.'
                  });
              }
              if (!viewingUser) updateCounts();
              if (cat === 'music') showAddedMusicReviewPrompt(newIdx, entry);
          }).catch(function() { alert('Error al guardar'); });
    }

    function showMelonView(period) {
        document.dispatchEvent(new Event('profile-edit-cancel'));
        if (!MELON_LABELS[period]) return;
        melonPeriod = period;
        currentCat  = null;
        document.getElementById('profile-view-default').style.display = 'none';
        document.getElementById('profile-view-cat').style.display     = 'none';
        document.getElementById('profile-view-social').style.display  = 'none';
        var mv = document.getElementById('profile-view-music');
        if (mv) mv.style.display = 'none';
        document.getElementById('profile-view-melon').style.display   = 'flex';
        document.getElementById('profile-sidebar-back').style.display = 'block';
        document.getElementById('profile-info-edit-btn').style.display = 'none';
        document.getElementById('profile-melon-title').textContent     = '⭐ ' + MELON_LABELS[period];
        /* Cargar películas por defecto al entrar */
        melonCat  = 'movies';
        melonType = null;
        document.querySelectorAll('.melon-cat-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.mcat === 'movies' && !b.dataset.mtype);
        });
        var listEl = document.getElementById('profile-melon-list');
        if (listEl) listEl.innerHTML = '';
        loadMelonItems();
    }

    function loadMelonItems() {
        if (!melonPeriod || !melonCat) return;
        var statusEl = document.getElementById('profile-melon-status');
        var listEl   = document.getElementById('profile-melon-list');
        if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'Cargando...'; }
        if (listEl) listEl.innerHTML = '';
        var url = 'assets/profile/api.php?action=melon-reviews&period=' + encodeURIComponent(melonPeriod) + '&cat=' + encodeURIComponent(melonCat);
        if (melonType) url += '&type=' + encodeURIComponent(melonType);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.ok) {
                    if (statusEl) { statusEl.style.display = ''; statusEl.textContent = (data && data.error) ? data.error : 'Error'; }
                    return;
                }
                /* Reordena: "Melon must" siempre delante. Mismo criterio
                   que el badge (avg ≥ 4.6 && count ≥ 2). Estable: dentro
                   de cada grupo conservamos el orden del backend
                   (ordenado por nota descendente). Aplica a todos los
                   filtros (mejor del año, todo el tiempo, etc.). */
                var raw = data.items || [];
                var musts = [], rest = [];
                raw.forEach(function(it) {
                    if (it && it.avg >= 4.6 && it.count >= 2) musts.push(it);
                    else rest.push(it);
                });
                melonItemsCache = musts.concat(rest);
                melonPage = 1;
                renderMelonItems();
            }).catch(function() {
                if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'Error de red'; }
            });
    }

    function renderMelonItems() {
        var statusEl = document.getElementById('profile-melon-status');
        var listEl   = document.getElementById('profile-melon-list');
        var pagerEl  = document.getElementById('profile-melon-pager');
        if (!listEl) return;
        listEl.innerHTML = '';
        if (pagerEl) pagerEl.innerHTML = '';
        if (!melonItemsCache.length) {
            if (statusEl) { statusEl.style.display = ''; statusEl.textContent = 'No hay reseñas en este período.'; }
            return;
        }
        if (statusEl) statusEl.style.display = 'none';

        var totalPages = Math.max(1, Math.ceil(melonItemsCache.length / MELON_PAGE_SIZE));
        if (melonPage > totalPages) melonPage = totalPages;
        if (melonPage < 1) melonPage = 1;
        var start = (melonPage - 1) * MELON_PAGE_SIZE;
        var pageItems = melonItemsCache.slice(start, start + MELON_PAGE_SIZE);

        var isMusic = (melonCat === 'music');
        pageItems.forEach(function(item) {
            /* "Melon must": nota media ≥ 4.6 con al menos 2 reseñas.
               El mínimo de reseñas evita que una sola puntuación
               extrema (4.6+ de una sola persona) marque el item como
               must — la curaduría exige consenso. */
            var isMust = item.avg >= 4.6 && item.count >= 2;
            var slot = document.createElement('div');
            slot.className = 'profile-encurso-slot filled melon-slot'
                + (isMusic ? ' melon-slot-music' : '')
                + (isMust ? ' melon-must' : '');

            if (isMust) {
                var badge = document.createElement('div');
                badge.className = 'melon-must-badge';
                badge.textContent = '★ Melon must ' + (MELON_MUST_VERBS[melonCat] || 'see');
                slot.appendChild(badge);
            }

            /* Title bar */
            var tb = document.createElement('div');
            tb.className = 'profile-encurso-slot-tb' + (isMusic ? ' music-slot-tb' : '');
            if (isMusic) {
                var tbTitle = document.createElement('div');
                tbTitle.className = 'music-slot-tb-title';
                tbTitle.textContent = item.title; tbTitle.title = item.title;
                tb.appendChild(tbTitle);
                if (item.artist) {
                    var tbArtist = document.createElement('div');
                    tbArtist.className = 'music-slot-tb-artist';
                    tbArtist.textContent = item.artist;
                    tb.appendChild(tbArtist);
                }
                var tbStars = document.createElement('div');
                tbStars.className = 'music-slot-tb-stars';
                tbStars.innerHTML = makeStarsHtml(item.avg, 5)
                    + '<span class="profile-star-num" style="font-size:8px;margin-left:2px;vertical-align:middle;">' + item.avg.toFixed(1) + '</span>'
                    + '<span style="font-size:8px;margin-left:3px;color:#c0c0c0;">(' + item.count + ')</span>';
                tb.appendChild(tbStars);
            } else {
                tb.textContent = item.title;
                tb.title = item.title;
            }
            slot.appendChild(tb);

            /* Body */
            var body = document.createElement('div');
            body.className = 'profile-encurso-slot-body';
            var fallback = isMusic ? (item.mtype === 'album' ? '💿' : '🎵') : (MELON_ICONS[melonCat] || '🖼');
            if (item.image) {
                var img = document.createElement('img');
                img.src = item.image; img.alt = item.title;
                img.setAttribute('data-cover-cat', melonCat);
                img.setAttribute('data-cover-title', item.title);
                (function(b, fb) { img.onerror = function() { b.innerHTML = fb; }; })(body, fallback);
                body.appendChild(img);
            } else {
                body.innerHTML = fallback;
            }
            slot.appendChild(body);

            /* For non-music slots, add rating below the image */
            if (!isMusic) {
                var rating = document.createElement('div');
                rating.className = 'melon-slot-rating';
                rating.innerHTML = makeStarsHtml(item.avg, 5)
                    + '<span class="melon-slot-rating-avg">' + item.avg.toFixed(1) + '</span>'
                    + '<span class="melon-slot-rating-count">(' + item.count + ')</span>';
                slot.appendChild(rating);
            }

            /* Click → ver reseñas */
            slot.style.cursor = 'pointer';
            (function(it) { slot.addEventListener('click', function() { showMelonDetails(it); }); })(item);

            /* Click derecho → menú contextual */
            (function(it, cat) {
                slot.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    var menuItems = [];
                    if (isMusic) {
                        menuItems.push({ label: '▶ Reproducir', action: function() { playMusicItem(it); } });
                    }
                    menuItems.push({ label: '+ Añadir a mi perfil', action: function() { addMelonItemToProfile(cat, it); } });
                    showCtxMenu(e.clientX, e.clientY, menuItems);
                });
            })(item, melonCat);

            listEl.appendChild(slot);
        });

        /* Pagination */
        if (pagerEl && totalPages > 1) {
            var prev = document.createElement('button');
            prev.className = 'button';
            prev.textContent = '◄';
            prev.disabled = melonPage <= 1;
            prev.addEventListener('click', function() { if (melonPage > 1) { melonPage--; renderMelonItems(); } });
            pagerEl.appendChild(prev);
            var info = document.createElement('span');
            info.className = 'melon-pager-info';
            info.textContent = melonPage + ' / ' + totalPages + '  ·  ' + melonItemsCache.length + ' items';
            pagerEl.appendChild(info);
            var next = document.createElement('button');
            next.className = 'button';
            next.textContent = '►';
            next.disabled = melonPage >= totalPages;
            next.addEventListener('click', function() { if (melonPage < totalPages) { melonPage++; renderMelonItems(); } });
            pagerEl.appendChild(next);
        }
    }

    /* ═══ Capítulos de series (compartidos por título) ═══════════════ */
    var _epSeriesTitle = '', _epCanWatch = false, _epReadOnly = false, _epList = [], _epExtraSeasons = [];
    function epFmtDur(min) {
        min = parseInt(min, 10) || 0;
        if (min <= 0) return '';
        var h = Math.floor(min / 60), m = min % 60;
        return h > 0 ? (h + ' h' + (m ? ' ' + m + ' min' : '')) : (m + ' min');
    }
    function epTotalDurStr() {
        var t = _epList.reduce(function(a, e){ return a + (e.duration || 0); }, 0);
        return epFmtDur(t);
    }
    function openEpisodesWindow(seriesTitle, canWatch, readOnly) {
        if (!seriesTitle) return;
        _epSeriesTitle = seriesTitle; _epCanWatch = !!canWatch; _epReadOnly = !!readOnly; _epExtraSeasons = [];
        var win = document.getElementById('profile-episodes-window');
        document.getElementById('profile-episodes-title').textContent = '🎬 Capítulos · ' + seriesTitle;
        /* Desde Melon Reviews: solo lectura — sin barra para añadir capítulos. */
        document.getElementById('profile-episodes-addbar').style.display = _epReadOnly ? 'none' : '';
        document.getElementById('profile-episodes-list').innerHTML = '<div class="ep-empty">Cargando…</div>';
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth - win.offsetWidth) / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        fetch('assets/profile/api.php?action=series-episodes&seriesTitle=' + encodeURIComponent(seriesTitle), { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){ _epList = (d && d.episodes) || []; renderEpisodesList(); })
            .catch(function(){ document.getElementById('profile-episodes-list').innerHTML = '<div class="ep-empty">Error.</div>'; });
    }
    /* Persiste el orden actual de _epList en el servidor. */
    function persistEpisodeOrder() {
        fetch('assets/profile/api.php?action=reorder-series-episodes', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ seriesTitle: _epSeriesTitle, order: _epList.map(function(x){ return x.id; }) })
        }).catch(function(){});
    }
    /* Lista ordenada de temporadas presentes (capítulos + transitorias). */
    function epSeasonsList() {
        var set = {};
        _epList.forEach(function(ep){ set[ep.season || 1] = true; });
        _epExtraSeasons.forEach(function(s){ set[s] = true; });
        var arr = Object.keys(set).map(function(s){ return parseInt(s, 10); });
        if (!arr.length) arr = [1];
        arr.sort(function(a, b){ return a - b; });
        return arr;
    }
    function epRenderRow(ep, n) {
        var dur = epFmtDur(ep.duration);
        var avg = (ep.avgStars != null)
            ? (makeStarsHtml(ep.avgStars, 5) + ' <span class="ep-avg">' + ep.avgStars.toFixed(1) + '</span> <span class="ep-cnt">(' + ep.reviewCount + ')</span>')
            : '<span class="ep-noavg">Sin reseñas</span>';
        var thumbInner = ep.image ? '<img src="' + escHtml(ep.image) + '" alt="">' : '<div class="ep-thumb-ph">🎬</div>';
        if (_epReadOnly) {
            return '<div class="ep-row">' +
                '<div class="ep-thumb ep-thumb-click" data-ep-reviews="' + ep.id + '" title="Ver reseñas">' + thumbInner + '</div>' +
                '<div class="ep-info">' +
                    '<div class="ep-title">' + n + '. ' + escHtml(ep.title) + '</div>' +
                    '<div class="ep-meta">' + (dur ? dur + ' · ' : '') + avg + '</div>' +
                '</div>' +
            '</div>';
        }
        var watchHtml = _epCanWatch
            ? '<label class="ep-watch"><input type="checkbox" data-ep-watch="' + ep.id + '"' + (ep.watched ? ' checked' : '') + '> Visto</label>'
            : (ep.watched ? '<span class="ep-watched-tag">✓ Visto</span>' : '');
        /* Indicador discreto de "tienes reseña" (icono de gráfica). El
           editar/eliminar viven ahora en el menú de click derecho. */
        var chartIco = (ep.myStars != null) ? '<img src="assets/img/appIcons/chatIcon.png" alt="Reseñado" title="Tu reseña: ' + ep.myStars + '" class="ep-chart-ico" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">' : '';
        var controls = watchHtml + chartIco;
        return '<div class="ep-row" data-ep-id="' + ep.id + '" title="Click derecho para editar o eliminar">' +
            '<div class="ep-drag" data-ep-drag="' + ep.id + '" title="Arrastrar para reordenar">⠿</div>' +
            '<div class="ep-thumb">' + thumbInner + '</div>' +
            '<div class="ep-info">' +
                '<div class="ep-title">' + n + '. ' + escHtml(ep.title) + '</div>' +
                '<div class="ep-meta">' + (dur ? dur + ' · ' : '') + avg + '</div>' +
                (controls ? '<div class="ep-controls">' + controls + '</div>' : '') +
            '</div>' +
        '</div>';
    }
    function epSyncSeasonSelect() {
        var sel = document.getElementById('ep-add-season');
        if (!sel) return;
        var prev = sel.value;
        var seasons = epSeasonsList();
        sel.innerHTML = seasons.map(function(s){ return '<option value="' + s + '">Temporada ' + s + '</option>'; }).join('');
        if (prev && seasons.indexOf(parseInt(prev, 10)) !== -1) sel.value = prev;
    }
    function renderEpisodesList() {
        var listEl = document.getElementById('profile-episodes-list');
        epSyncSeasonSelect();
        if (!_epList.length && !_epExtraSeasons.length) { listEl.innerHTML = '<div class="ep-empty">Sin capítulos todavía. Añade el primero abajo.</div>'; return; }
        var seasons = epSeasonsList();
        listEl.innerHTML = seasons.map(function(seasonNum){
            var eps = _epList.filter(function(ep){ return (ep.season || 1) === seasonNum; });
            var rowsHtml = eps.length
                ? eps.map(function(ep, i){ return epRenderRow(ep, i + 1); }).join('')
                : '<div class="ep-season-empty">Sin capítulos en esta temporada.</div>';
            var delBtn = _epReadOnly ? '' : '<button class="button ep-season-del" data-ep-delseason="' + seasonNum + '" title="Eliminar temporada">✕</button>';
            /* Resumen de la temporada: nº de capítulos, duración total y
               nota media (media de las notas medias de sus capítulos). */
            var cnt = eps.length;
            var durSum = eps.reduce(function(a, ep){ return a + (ep.duration || 0); }, 0);
            var rated = eps.filter(function(ep){ return ep.avgStars != null; });
            var savg = rated.length ? (rated.reduce(function(a, ep){ return a + ep.avgStars; }, 0) / rated.length) : null;
            var metaBits = [cnt + (cnt === 1 ? ' capítulo' : ' capítulos')];
            if (durSum > 0) metaBits.push(epFmtDur(durSum));
            var avgHtml = (savg != null) ? '<span class="ep-season-avg">★ ' + savg.toFixed(1) + '</span>' : '';
            return '<div class="ep-season">' +
                '<div class="ep-season-head">' +
                    '<span class="ep-season-title">Temporada ' + seasonNum + '</span>' +
                    '<span class="ep-season-meta">' + metaBits.join(' · ') + '</span>' +
                    avgHtml + delBtn +
                '</div>' +
                '<div class="ep-season-rows" data-season="' + seasonNum + '">' + rowsHtml + '</div>' +
            '</div>';
        }).join('');
    }
    (function setupEpisodesEvents(){
        var listEl = document.getElementById('profile-episodes-list');
        if (!listEl) return;
        listEl.addEventListener('change', function(e){
            var cb = e.target.closest('input[data-ep-watch]'); if (!cb) return;
            var id = parseInt(cb.getAttribute('data-ep-watch'), 10);
            var ep = _epList.filter(function(x){ return x.id === id; })[0]; if (ep) ep.watched = cb.checked;
            fetch('assets/profile/api.php?action=series-episode-state', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ episodeId: id, watched: cb.checked })
            }).catch(function(){});
        });
        /* Reordenar arrastrando el tirador (.ep-drag). Pointer events →
           funciona con ratón y con táctil. */
        var dragRow = null, dragCont = null;
        function epDragAfter(y) {
            /* Reordenado dentro de la misma temporada (su contenedor). */
            var els = Array.prototype.slice.call((dragCont || listEl).querySelectorAll('.ep-row:not(.ep-dragging)'));
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
            dragRow.classList.remove('ep-dragging');
            dragRow = null; dragCont = null;
            document.removeEventListener('pointermove', epDragMove);
            document.removeEventListener('pointerup', epDragUp);
            document.removeEventListener('pointercancel', epDragUp);
            var ids = Array.prototype.slice.call(listEl.querySelectorAll('.ep-row')).map(function(r){ return parseInt(r.getAttribute('data-ep-id'), 10); });
            _epList.sort(function(a, b){ return ids.indexOf(a.id) - ids.indexOf(b.id); });
            renderEpisodesList();   /* re-numera 1. 2. 3. */
            persistEpisodeOrder();
        }
        listEl.addEventListener('pointerdown', function(e){
            var h = e.target.closest('[data-ep-drag]'); if (!h) return;
            var row = h.closest('.ep-row'); if (!row) return;
            e.preventDefault();
            dragRow = row; dragCont = row.parentNode; row.classList.add('ep-dragging');
            document.addEventListener('pointermove', epDragMove);
            document.addEventListener('pointerup', epDragUp);
            document.addEventListener('pointercancel', epDragUp);
        });
        listEl.addEventListener('click', function(e){
            var rvw = e.target.closest('[data-ep-reviews]');
            if (rvw) { var rvid = parseInt(rvw.getAttribute('data-ep-reviews'), 10); var rvep = _epList.filter(function(x){ return x.id === rvid; })[0]; if (rvep) openEpisodeReviewsView(rvep); return; }
            var ds = e.target.closest('[data-ep-delseason]');
            if (ds) {
                var sNum = parseInt(ds.getAttribute('data-ep-delseason'), 10);
                var inSeason = _epList.filter(function(x){ return (x.season || 1) === sNum; });
                if (!inSeason.length) {
                    /* Temporada vacía (transitoria): solo quitarla localmente. */
                    _epExtraSeasons = _epExtraSeasons.filter(function(s){ return s !== sNum; });
                    renderEpisodesList();
                    return;
                }
                confirmFn('¿Eliminar la Temporada ' + sNum + ' y sus ' + inSeason.length + ' capítulo(s)? Afecta a todos.', 'Eliminar', function(){
                    fetch('assets/profile/api.php?action=delete-series-season', {
                        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ seriesTitle: _epSeriesTitle, season: sNum })
                    }).then(function(){
                        _epList = _epList.filter(function(x){ return (x.season || 1) !== sNum; });
                        _epExtraSeasons = _epExtraSeasons.filter(function(s){ return s !== sNum; });
                        renderEpisodesList();
                    }).catch(function(){});
                });
                return;
            }
        });
        /* Menú de capítulo (click derecho): Editar / Eliminar. Solo en
           modo edición (en solo lectura las filas no llevan data-ep-id). */
        function removeEpisode(ep) {
            confirmFn('¿Quitar el capítulo "' + (ep ? ep.title : '') + '"? Afecta a todos.', 'Quitar', function(){
                fetch('assets/profile/api.php?action=delete-series-episode', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: ep.id })
                }).then(function(){ _epList = _epList.filter(function(x){ return x.id !== ep.id; }); renderEpisodesList(); }).catch(function(){});
            });
        }
        listEl.addEventListener('contextmenu', function(e){
            if (_epReadOnly) return;
            var row = e.target.closest('.ep-row[data-ep-id]'); if (!row) return;
            e.preventDefault();
            var id = parseInt(row.getAttribute('data-ep-id'), 10);
            var ep = _epList.filter(function(x){ return x.id === id; })[0]; if (!ep) return;
            showCtxMenu(e.clientX, e.clientY, [
                { label: '✏ Editar', action: function(){ openEpisodeEditor(ep); } },
                { label: '✕ Eliminar', action: function(){ removeEpisode(ep); } }
            ]);
        });
        var addBtn = document.getElementById('ep-add-btn');
        if (addBtn) addBtn.addEventListener('click', function(){
            var t = document.getElementById('ep-add-title').value.trim(); if (!t) return;
            var img = document.getElementById('ep-add-image').value.trim();
            var dur = parseInt(document.getElementById('ep-add-dur').value, 10) || 0;
            var seasonEl = document.getElementById('ep-add-season');
            var season = seasonEl ? (parseInt(seasonEl.value, 10) || 1) : 1;
            fetch('assets/profile/api.php?action=save-series-episode', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ seriesTitle: _epSeriesTitle, title: t, image: img, duration: dur, season: season })
            }).then(function(r){ return r.ok ? r.json() : null; }).then(function(){
                document.getElementById('ep-add-title').value = '';
                document.getElementById('ep-add-image').value = '';
                document.getElementById('ep-add-dur').value = '';
                /* Recargar conservando la temporada seleccionada. */
                fetch('assets/profile/api.php?action=series-episodes&seriesTitle=' + encodeURIComponent(_epSeriesTitle), { credentials: 'same-origin' })
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(d){ _epList = (d && d.episodes) || []; renderEpisodesList(); var s = document.getElementById('ep-add-season'); if (s) s.value = season; })
                    .catch(function(){});
            }).catch(function(){});
        });
        /* Añadir temporada: crea una nueva (transitoria hasta que tenga
           capítulos) y la deja seleccionada en el desplegable. */
        var newSeasonBtn = document.getElementById('ep-add-newseason');
        if (newSeasonBtn) newSeasonBtn.addEventListener('click', function(){
            var seasons = epSeasonsList();
            var next = (seasons.length ? seasons[seasons.length - 1] : 0) + 1;
            _epExtraSeasons.push(next);
            renderEpisodesList();
            var s = document.getElementById('ep-add-season'); if (s) s.value = next;
        });
        var closeBtn = document.getElementById('profile-episodes-close');
        if (closeBtn) closeBtn.addEventListener('click', function(){ document.getElementById('profile-episodes-window').style.display = 'none'; });
    })();

    /* Reseña individual de un capítulo (popup con estrellas + comentario). */
    /* Editor de capítulo: título, imagen, duración (compartidos) + reseña
       propia (estrellas + comentario). */
    function openEpisodeEditor(ep) {
        var win = document.getElementById('profile-episode-review');
        document.getElementById('profile-episode-review-title').textContent = '✏ Editar capítulo';
        document.getElementById('profile-episode-edit-title').value = ep.title || '';
        document.getElementById('profile-episode-edit-image').value = ep.image || '';
        document.getElementById('profile-episode-edit-dur').value = ep.duration ? ep.duration : '';
        document.getElementById('profile-episode-review-comment').value = ep.myComment || '';
        var sel = ep.myStars || 0;
        var starsEl = document.getElementById('profile-episode-review-stars');
        var numEl = document.getElementById('profile-episode-review-num');
        function setDisp(el, val, pos) {
            if (val >= pos) { el.innerHTML = '★'; el.style.clipPath = ''; }
            else if (val >= pos - 0.5) { el.innerHTML = '★'; el.style.clipPath = 'inset(0 50% 0 0)'; }
            else { el.innerHTML = '☆'; el.style.clipPath = ''; }
        }
        function draw() {
            starsEl.innerHTML = ''; numEl.textContent = sel > 0 ? sel : '';
            for (var n = 1; n <= 5; n++) (function(star){
                var s = document.createElement('span');
                s.style.cssText = 'display:inline-block;position:relative;width:1.1em;cursor:pointer;';
                setDisp(s, sel, star);
                s.addEventListener('mousemove', function(e){ var hv = (e.offsetX < this.offsetWidth / 2) ? star - 0.5 : star; starsEl.querySelectorAll('span').forEach(function(sp, idx){ setDisp(sp, hv, idx + 1); }); numEl.textContent = hv; });
                s.addEventListener('mouseout', function(){ starsEl.querySelectorAll('span').forEach(function(sp, idx){ setDisp(sp, sel, idx + 1); }); numEl.textContent = sel > 0 ? sel : ''; });
                s.addEventListener('click', function(e){ sel = (e.offsetX < this.offsetWidth / 2) ? star - 0.5 : star; draw(); });
                starsEl.appendChild(s);
            })(n);
        }
        draw();
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth - win.offsetWidth) / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        var saveBtn = document.getElementById('profile-episode-review-save');
        var newSave = saveBtn.cloneNode(true); saveBtn.parentNode.replaceChild(newSave, saveBtn);
        newSave.addEventListener('click', function(){
            var title = document.getElementById('profile-episode-edit-title').value.trim() || ep.title || '';
            var image = document.getElementById('profile-episode-edit-image').value.trim();
            var dur = parseInt(document.getElementById('profile-episode-edit-dur').value, 10) || 0;
            var comment = document.getElementById('profile-episode-review-comment').value.trim();
            /* Primero los datos compartidos del capítulo, luego mi reseña. */
            fetch('assets/profile/api.php?action=save-series-episode', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: ep.id, seriesTitle: _epSeriesTitle, title: title, image: image, duration: dur, season: ep.season || 1 })
            }).then(function(){
                return fetch('assets/profile/api.php?action=series-episode-state', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ episodeId: ep.id, stars: sel, comment: comment })
                });
            }).then(function(){
                win.style.display = 'none';
                openEpisodesWindow(_epSeriesTitle, _epCanWatch);
            }).catch(function(){});
        });
    }
    (function(){
        var c = document.getElementById('profile-episode-review-close'); if (c) c.addEventListener('click', function(){ document.getElementById('profile-episode-review').style.display = 'none'; });
        var cancel = document.getElementById('profile-episode-review-cancel'); if (cancel) cancel.addEventListener('click', function(){ document.getElementById('profile-episode-review').style.display = 'none'; });
    })();
    /* Vista de TODAS las reseñas de un capítulo (solo lectura). */
    function openEpisodeReviewsView(ep) {
        var old = document.getElementById('profile-ep-reviews-view');
        if (old) old.parentNode.removeChild(old);
        var win = document.createElement('div');
        win.id = 'profile-ep-reviews-view';
        win.className = 'window';
        win.style.cssText = 'position:fixed;z-index:10005;width:380px;max-height:72vh;display:flex;flex-direction:column;';
        win.innerHTML =
            '<div class="title-bar">' +
                '<div class="title-bar-text">★ Reseñas · ' + escHtml(ep.title) + '</div>' +
                '<div class="title-bar-controls"><button aria-label="Close" type="button"></button></div>' +
            '</div>' +
            '<div class="window-body melon-detail-list" style="overflow-y:auto;flex:1;margin:0;padding:8px;">' +
                '<div class="ep-empty">Cargando…</div>' +
            '</div>';
        document.body.appendChild(win);
        win.style.left = Math.round((window.innerWidth - win.offsetWidth) / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        win.querySelector('.title-bar-controls button').addEventListener('click', function(){ win.parentNode.removeChild(win); });
        var body = win.querySelector('.window-body');
        fetch('assets/profile/api.php?action=series-episode-reviews&episodeId=' + ep.id, { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                var reviews = (d && d.reviews) || [];
                if (!reviews.length) { body.innerHTML = '<div class="ep-empty">Este capítulo no tiene reseñas todavía.</div>'; return; }
                body.innerHTML = '';
                reviews.forEach(function(rev){
                    var row = document.createElement('div');
                    row.className = 'melon-detail-row';
                    var avFrame = document.createElement('div');
                    avFrame.className = 'profile-avatar-frame melon-detail-av';
                    if (rev.userImg) { var img = document.createElement('img'); img.src = rev.userImg; img.alt = rev.userLabel; avFrame.appendChild(img); }
                    else { avFrame.innerHTML = '<div class="profile-avatar-placeholder">👤</div>'; }
                    row.appendChild(avFrame);
                    var b = document.createElement('div');
                    b.className = 'melon-detail-body';
                    var hdr = document.createElement('div');
                    hdr.className = 'melon-detail-hdr';
                    hdr.innerHTML = '<strong>' + escHtml(rev.userLabel) + '</strong> ' + makeStarsHtml(rev.stars, 5) + '<span class="melon-item-avg">' + rev.stars + '</span>';
                    b.appendChild(hdr);
                    if (rev.comment) { var cmt = document.createElement('div'); cmt.className = 'melon-detail-comment'; cmt.textContent = '" ' + rev.comment + ' "'; b.appendChild(cmt); }
                    var t = document.createElement('div'); t.className = 'melon-detail-time';
                    t.textContent = rev.reviewedAt ? (typeof relTime === 'function' ? relTime(rev.reviewedAt) : '') : '';
                    b.appendChild(t);
                    row.appendChild(b);
                    body.appendChild(row);
                });
            })
            .catch(function(){ body.innerHTML = '<div class="ep-empty">Error al cargar reseñas.</div>'; });
    }
    window.profileOpenEpisodes = openEpisodesWindow;

    /* Visor de PDF embebido (libros): ventana movible, redimensionable y
       con maximizar/minimizar. Solo acepta URLs http(s). */
    function openPdfViewer(url, title) {
        if (!url || /^https?:\/\//i.test(url) === false) { alert('El enlace del PDF debe empezar por http:// o https://'); return; }
        /* Drive → su visor /preview (se ve embebido también en móvil).
           Resto → visor completo de PDF.js (con barra de páginas/zoom/
           descarga) sobre nuestro proxy mismo-origen (esquiva CORS/móvil). */
        var embedSrc;
        if (/drive\.google\.com/i.test(url)) {
            var dm = url.match(/\/file\/d\/([^\/?#]+)/) || url.match(/[?&]id=([^&]+)/);
            embedSrc = dm ? ('https://drive.google.com/file/d/' + dm[1] + '/preview') : url;
        } else {
            var fileParam = '../../../profile/pdf-proxy.php?url=' + encodeURIComponent(url);
            /* ?v=<mtime> rompe la caché del navegador/edge cuando cambia el
               visor (evita servir un viewer.html viejo cacheado). */
            embedSrc = 'assets/vendor/pdfjs/web/viewer.html?v=<?php echo @filemtime(__DIR__ . "/../assets/vendor/pdfjs/web/viewer.html") ?: 0; ?>&file=' + encodeURIComponent(fileParam);
        }
        var old = document.getElementById('profile-pdf-viewer');
        if (old) old.parentNode.removeChild(old);
        var win = document.createElement('div');
        win.id = 'profile-pdf-viewer';
        win.className = 'window';
        win.style.cssText = 'position:fixed;z-index:10006;width:min(820px,94vw);height:min(88vh,1000px);min-width:320px;min-height:200px;display:flex;flex-direction:column;';
        win.innerHTML =
            '<div class="title-bar" style="cursor:move;">' +
                '<div class="title-bar-text">📖 ' + escHtml(title || 'PDF') + '</div>' +
                '<div class="title-bar-controls">' +
                    '<button aria-label="Minimize" type="button"></button>' +
                    '<button aria-label="Maximize" type="button"></button>' +
                    '<button aria-label="Close" type="button"></button>' +
                '</div>' +
            '</div>' +
            '<div class="window-body" id="pdf-viewer-body" style="flex:1;min-height:0;padding:0;overflow:hidden;">' +
                '<iframe src="' + escHtml(embedSrc) + '" style="width:100%;height:100%;border:0;display:block;"></iframe>' +
            '</div>';
        document.body.appendChild(win);
        win.style.left = Math.round((window.innerWidth - win.offsetWidth) / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';

        var iframe = win.querySelector('iframe');
        var body   = win.querySelector('#pdf-viewer-body');
        var titleBar = win.querySelector('.title-bar');
        var ctrls  = win.querySelector('.title-bar-controls');
        function closeWin(){ if (win.parentNode) win.parentNode.removeChild(win); }
        ctrls.querySelector('button[aria-label="Close"]').addEventListener('click', closeWin);

        /* Mover arrastrando el title-bar (no sobre los controles). */
        var maximized = false, prev = null, rolled = false, savedH = '';
        titleBar.addEventListener('pointerdown', function(e){
            if (e.target.closest('.title-bar-controls') || maximized) return;
            e.preventDefault();
            var r = win.getBoundingClientRect();
            var ox = e.clientX - r.left, oy = e.clientY - r.top;
            iframe.style.pointerEvents = 'none';
            function mv(ev){
                var x = Math.max(0, Math.min(ev.clientX - ox, window.innerWidth - 60));
                var y = Math.max(0, Math.min(ev.clientY - oy, window.innerHeight - 24));
                win.style.left = x + 'px'; win.style.top = y + 'px';
            }
            function up(){ iframe.style.pointerEvents = ''; document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); }
            document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
        });
        /* Redimensionar desde las 4 esquinas y los 4 lados. Cada handle
           lleva su 'edge' (combinación de n/s/e/w). */
        var MINW = 320, MINH = 200;
        var EDGES = [
            { edge: 'nw', css: 'top:0;left:0;width:14px;height:14px;cursor:nwse-resize;' },
            { edge: 'ne', css: 'top:0;right:0;width:14px;height:14px;cursor:nesw-resize;' },
            { edge: 'sw', css: 'bottom:0;left:0;width:14px;height:14px;cursor:nesw-resize;' },
            { edge: 'se', css: 'bottom:0;right:0;width:14px;height:14px;cursor:nwse-resize;' },
            { edge: 'n',  css: 'top:0;left:14px;right:14px;height:6px;cursor:ns-resize;' },
            { edge: 's',  css: 'bottom:0;left:14px;right:14px;height:6px;cursor:ns-resize;' },
            { edge: 'w',  css: 'left:0;top:14px;bottom:14px;width:6px;cursor:ew-resize;' },
            { edge: 'e',  css: 'right:0;top:14px;bottom:14px;width:6px;cursor:ew-resize;' }
        ];
        function startResize(e, edge){
            if (maximized) return;
            e.preventDefault(); e.stopPropagation();
            var r = win.getBoundingClientRect();
            var sx = e.clientX, sy = e.clientY, sw = r.width, sh = r.height, sl = r.left, st = r.top;
            iframe.style.pointerEvents = 'none';
            function mv(ev){
                var dx = ev.clientX - sx, dy = ev.clientY - sy;
                var w = sw, h = sh, l = sl, t = st;
                if (edge.indexOf('e') !== -1) w = Math.max(MINW, sw + dx);
                if (edge.indexOf('s') !== -1) h = Math.max(MINH, sh + dy);
                if (edge.indexOf('w') !== -1) { w = Math.max(MINW, sw - dx); l = sl + (sw - w); }
                if (edge.indexOf('n') !== -1) { h = Math.max(MINH, sh - dy); t = st + (sh - h); }
                win.style.width = w + 'px'; win.style.height = h + 'px';
                win.style.left = l + 'px'; win.style.top = t + 'px';
            }
            function up(){ iframe.style.pointerEvents = ''; document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); }
            document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
        }
        EDGES.forEach(function(h){
            var d = document.createElement('div');
            d.className = 'pdf-resize-handle';
            d.style.cssText = 'position:absolute;z-index:3;' + h.css;
            (function(edge){ d.addEventListener('pointerdown', function(e){ startResize(e, edge); }); })(h.edge);
            win.appendChild(d);
        });
        /* Maximizar: alterna pantalla completa / tamaño previo. */
        ctrls.querySelector('button[aria-label="Maximize"]').addEventListener('click', function(){
            if (!maximized) {
                prev = { left: win.style.left, top: win.style.top, width: win.style.width, height: win.style.height };
                win.style.left = '0'; win.style.top = '0'; win.style.width = '100vw'; win.style.height = '100vh';
                maximized = true;
            } else {
                if (prev) { win.style.left = prev.left; win.style.top = prev.top; win.style.width = prev.width; win.style.height = prev.height; }
                maximized = false;
            }
        });
        /* Minimizar: enrolla la ventana a solo el title-bar (toggle). */
        ctrls.querySelector('button[aria-label="Minimize"]').addEventListener('click', function(){
            rolled = !rolled;
            if (rolled) { savedH = win.style.height; body.style.display = 'none'; win.style.height = 'auto'; }
            else { body.style.display = ''; win.style.height = savedH || ''; }
        });
    }
    window.profileOpenPdf = openPdfViewer;

    /* Formatea segundos: álbum → "45 min" / "1 h 12 min"; canción → "3:45". */
    function melonFmtDur(sec) {
        sec = Math.round(sec || 0);
        if (sec <= 0) return '';
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        if (h > 0) return h + ' h ' + m + ' min';
        if (m >= 1) return m + ' min';
        return sec + ' s';
    }
    function melonFmtSong(sec) {
        sec = Math.round(sec || 0);
        if (sec <= 0) return '';
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }
    /* Añadir un item de música (álbum/canción) a una playlist: resuelve
       sus tracks (play-music-item) y los añade a la playlist elegida.
       Usa APIs estables (get-playlists / save-playlist-item). */
    function melonAddToPlaylist(item, x, y) {
        fetch('assets/music/api.php?action=get-playlists', { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(pls){
                if (!Array.isArray(pls) || !pls.length) {
                    if (window.notifSystem) window.notifSystem.show({ id:'np'+Date.now(), type:'info', title:'Sin playlists', message:'No tienes playlists todavía.' });
                    return;
                }
                showCtxMenu(x, y, pls.map(function(pl){
                    return { label: '📋 ' + (pl.name || 'Playlist'), action: function(){ melonDoAddToPlaylist(item, pl); } };
                }));
            }).catch(function(){});
    }
    function melonDoAddToPlaylist(item, pl) {
        fetch('assets/profile/api.php?action=play-music-item', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                itemType:       item.mtype || item.type || 'song',
                title:          item.title || '', artist: item.artist || '',
                ytId:           item.ytId || '', spotifyId: item.spotifyId || '',
                ytPlaylistId:   item.ytPlaylistId || '', spotifyAlbumId: item.spotifyAlbumId || '',
            })
        })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){
            var tracks = (data && data.tracks) || [];
            if (!tracks.length) {
                if (window.notifSystem) window.notifSystem.show({ id:'er'+Date.now(), type:'error', title:'Error', message:'No se pudo resolver la música.' });
                return;
            }
            var newTracks = (pl.tracks || []).slice();
            tracks.forEach(function(t){ newTracks.push({ videoId: t.videoId, title: t.title, artist: t.artist }); });
            var payload = { id: pl.id, name: pl.name, tracks: newTracks };
            if (pl.sharedFrom) payload.sharedFrom = pl.sharedFrom;
            fetch('assets/music/api.php?action=save-playlist-item', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(){
                if (window.notifSystem) window.notifSystem.show({ id:'ok'+Date.now(), type:'success', title:'Añadido', message:'"' + (item.title || '') + '" añadido a "' + (pl.name || '') + '".' });
            }).catch(function(){});
        }).catch(function(){});
    }
    /* Abre la ventana del álbum resolviéndolo por título+artista. */
    function melonOpenAlbum(item) {
        if (typeof window.openAlbumViewer !== 'function') return;
        var p = new URLSearchParams({ title: item.title || '', artist: item.artist || '' });
        fetch('assets/music/api.php?action=find-album&' + p.toString())
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                if (d && !d.notFound && d.albumKey) window.openAlbumViewer(d.albumKey, item.title || '');
            }).catch(function(){});
    }
    /* Carga duración + nº de tracks (álbum) o duración (canción) y lo pinta
       en `el`. find-album resuelve la albumKey; album-tracks da las pistas
       con su duración. Best-effort: cualquier fallo deja `el` vacío. */
    function melonLoadMusicMeta(item, isAlbum, el) {
        var p = new URLSearchParams({ title: item.title || '', artist: item.artist || '' });
        fetch('assets/music/api.php?action=find-album&' + p.toString())
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                if (!d || d.notFound || !d.albumKey) return;
                return fetch('assets/music/api.php?action=album-tracks&key=' + encodeURIComponent(d.albumKey))
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(al){
                        if (!al || !Array.isArray(al.tracks) || !al.tracks.length) return;
                        if (isAlbum) {
                            var total = al.tracks.reduce(function(a, t){ return a + (t.duration || 0); }, 0);
                            var parts = [al.tracks.length + (al.tracks.length === 1 ? ' canción' : ' canciones')];
                            var dur = melonFmtDur(total);
                            if (dur) parts.push(dur);
                            el.textContent = parts.join(' · ');
                        } else {
                            var lt = (item.title || '').toLowerCase();
                            var match = al.tracks.filter(function(t){ return (t.title || '').toLowerCase() === lt; })[0]
                                     || al.tracks.filter(function(t){ return (t.title || '').toLowerCase().indexOf(lt) !== -1; })[0];
                            var ds = melonFmtSong(match ? match.duration : 0);
                            if (ds) el.textContent = ds;
                        }
                    });
            }).catch(function(){});
    }

    function showMelonDetails(item) {
        var win = document.getElementById('profile-melon-details-window');
        if (!win) return;
        document.getElementById('profile-melon-details-title').textContent = '⭐ ' + item.title;
        var list = document.getElementById('profile-melon-details-list');
        var headEl = document.getElementById('profile-melon-details-head');
        list.innerHTML = '';
        headEl.innerHTML = '';
        /* Cabecera FIJA: carátula + título + (artista) + calificación media
           junto al número de reseñas. Debajo, en zona scrollable, las reseñas. */
        var head = document.createElement('div');
        head.className = 'melon-detail-header';
        var cover = document.createElement('div');
        cover.className = 'melon-detail-cover';
        /* Música (álbumes/canciones) = carátula cuadrada; el resto
           (pelis, series, libros, juegos…) = póster vertical. */
        if (melonCat !== 'music') cover.classList.add('melon-cover-portrait');
        if (item.image) {
            var cimg = document.createElement('img');
            cimg.src = item.image; cimg.alt = item.title;
            cimg.setAttribute('data-cover-cat', melonCat);
            cimg.setAttribute('data-cover-title', item.title);
            (function(c){ cimg.onerror = function(){ c.classList.add('melon-detail-cover-ph'); c.textContent = '🍈'; }; })(cover);
            cover.appendChild(cimg);
        } else {
            cover.classList.add('melon-detail-cover-ph'); cover.textContent = '🍈';
        }
        head.appendChild(cover);
        var hinfo = document.createElement('div');
        hinfo.className = 'melon-detail-hinfo';
        var htitle = document.createElement('div');
        htitle.className = 'melon-detail-htitle';
        htitle.textContent = item.title; htitle.title = item.title;
        /* Libros con PDF: clicar el título lo abre embebido. */
        if (melonCat === 'books' && item.pdfUrl) {
            htitle.classList.add('melon-detail-link');
            htitle.style.cursor = 'pointer';
            htitle.title = 'Abrir PDF';
            htitle.addEventListener('click', function(){ openPdfViewer(item.pdfUrl, item.title); });
        }
        hinfo.appendChild(htitle);
        if (item.artist) {
            var hart = document.createElement('div');
            hart.className = 'melon-detail-hartist';
            hart.textContent = item.artist;
            hinfo.appendChild(hart);
        }
        var hrating = document.createElement('div');
        hrating.className = 'melon-detail-hrating';
        var avgTxt = (typeof item.avg === 'number') ? item.avg.toFixed(1) : (item.avg || '0');
        var cnt = item.count || (item.reviews ? item.reviews.length : 0);
        hrating.innerHTML = makeStarsHtml(item.avg || 0, 5)
            + '<span class="melon-detail-havg">' + avgTxt + '</span>'
            + '<span class="melon-detail-hcount">' + cnt + ' ' + (cnt === 1 ? 'reseña' : 'reseñas') + '</span>';
        hinfo.appendChild(hrating);
        /* Consenso de duración/capítulos (la moda entre las reseñas). */
        if (item.runtime && item.runtime > 0) {
            var rtLabel = melonCat === 'movies'
                ? (item.runtime + ' min')
                : (melonCat === 'series' || melonCat === 'books')
                    ? (item.runtime + (item.runtime === 1 ? ' capítulo' : ' capítulos'))
                    : '';
            if (rtLabel) {
                var hrt = document.createElement('div');
                hrt.className = 'melon-detail-hruntime';
                hrt.textContent = rtLabel;
                hinfo.appendChild(hrt);
            }
        }
        /* Series con capítulos asignados: nº + duración total, clicable →
           abre la página de capítulos con sus notas. */
        if (melonCat === 'series' && item.episodeCount && item.episodeCount > 0) {
            var epDur = item.episodeDuration ? epFmtDur(item.episodeDuration) : '';
            var epBits = [];
            if (item.seasonCount && item.seasonCount > 0) epBits.push(item.seasonCount + (item.seasonCount === 1 ? ' temporada' : ' temporadas'));
            epBits.push(item.episodeCount + (item.episodeCount === 1 ? ' capítulo' : ' capítulos'));
            if (epDur) epBits.push(epDur);
            var epEl = document.createElement('div');
            epEl.className = 'melon-detail-hruntime melon-detail-link';
            epEl.style.cursor = 'pointer';
            epEl.title = 'Ver capítulos y sus notas';
            epEl.textContent = epBits.join(' · ');
            epEl.addEventListener('click', function(){ openEpisodesWindow(item.title, false, true); });
            hinfo.appendChild(epEl);
        }
        /* Música: nombre de álbum y artista clicables (abren sus ventanas
           en el reproductor) + duración y nº de tracks (álbum) o duración
           (canción). Todo best-effort: si algo falla, no se muestra. */
        if (melonCat === 'music') {
            var _isAlbum = (item.mtype === 'album' || item.type === 'album');
            if (item.artist) {
                hart.classList.add('melon-detail-link');
                hart.title = 'Ver artista';
                hart.addEventListener('click', function(){
                    if (typeof window.openArtistWindow === 'function') window.openArtistWindow(item.artist);
                });
            }
            if (_isAlbum) {
                htitle.classList.add('melon-detail-link');
                htitle.title = 'Ver álbum';
                htitle.addEventListener('click', function(){ melonOpenAlbum(item); });
            }
            var hmeta = document.createElement('div');
            hmeta.className = 'melon-detail-hmeta';
            hinfo.appendChild(hmeta);
            melonLoadMusicMeta(item, _isAlbum, hmeta);
        }
        head.appendChild(hinfo);
        headEl.appendChild(head);

        /* Carátula: click → añadir el item a mi perfil. Click derecho →
           menú: en música Reproducir / Añadir a playlist / Añadir a mi
           perfil; en el resto solo Añadir a mi perfil. */
        cover.style.cursor = 'pointer';
        cover.title = 'Click: añadir a mi perfil · Click derecho: más opciones';
        cover.addEventListener('click', function(){ addMelonItemToProfile(melonCat, item); });
        cover.addEventListener('contextmenu', function(e){
            e.preventDefault(); e.stopPropagation();
            var menu = [];
            if (melonCat === 'music') {
                menu.push({ label: '▶ Reproducir',           action: function(){ if (typeof playMusicItem === 'function') playMusicItem(item); } });
                menu.push({ label: '📋 Añadir a una playlist', action: function(){ melonAddToPlaylist(item, e.clientX, e.clientY); } });
            }
            menu.push({ label: '+ Añadir a mi perfil', action: function(){ addMelonItemToProfile(melonCat, item); } });
            showCtxMenu(e.clientX, e.clientY, menu);
        });
        (item.reviews || []).forEach(function(rev) {
            var row = document.createElement('div');
            row.className = 'melon-detail-row';
            var avFrame = document.createElement('div');
            avFrame.className = 'profile-avatar-frame melon-detail-av';
            if (rev.userImg) {
                var img = document.createElement('img');
                img.src = rev.userImg; img.alt = rev.userLabel;
                avFrame.appendChild(img);
            } else {
                avFrame.innerHTML = '<div class="profile-avatar-placeholder">👤</div>';
            }
            row.appendChild(avFrame);
            var body = document.createElement('div');
            body.className = 'melon-detail-body';
            var hdr = document.createElement('div');
            hdr.className = 'melon-detail-hdr';
            hdr.innerHTML = '<strong>' + escHtml(rev.userLabel) + '</strong> '
                + makeStarsHtml(rev.stars, 5)
                + '<span class="melon-item-avg">' + rev.stars + '</span>';
            body.appendChild(hdr);
            if (rev.comment) {
                var cmt = document.createElement('div');
                cmt.className = 'melon-detail-comment';
                cmt.textContent = '" ' + rev.comment + ' "';
                body.appendChild(cmt);
            }
            var t = document.createElement('div');
            t.className = 'melon-detail-time';
            t.textContent = rev.reviewedAt ? relTime(rev.reviewedAt) : '';
            body.appendChild(t);
            row.appendChild(body);
            list.appendChild(row);
        });
        /* Reset al tamaño base — el usuario pidió que las ventanas de
           reseñas siempre se abran con el tamaño mínimo definido. */
        win.style.width = ''; win.style.height = '';
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
    }

    (function setupMelon() {
        document.querySelectorAll('.melon-cat-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                melonCat  = btn.dataset.mcat;
                melonType = btn.dataset.mtype || null;
                document.querySelectorAll('.melon-cat-btn').forEach(function(b) { b.classList.toggle('active', b === btn); });
                loadMelonItems();
            });
        });
        var closeBtn = document.getElementById('profile-melon-details-close');
        if (closeBtn) closeBtn.addEventListener('click', function() {
            document.getElementById('profile-melon-details-window').style.display = 'none';
        });
    })();

    function buildSocialCard(userKey) {
        var u = PROFILE_USERS[userKey];
        if (!u) return null;
        var card = document.createElement('div');
        card.className = 'profile-social-card';
        var avWrap = document.createElement('div');
        avWrap.className = 'profile-avatar-frame';
        if (u.image) {
            var img = document.createElement('img');
            img.className = 'profile-avatar-img';
            img.src = u.image;
            img.alt = u.label;
            avWrap.appendChild(img);
        } else {
            avWrap.innerHTML = '<div class="profile-avatar-placeholder">👤</div>';
        }
        /* Punto de presencia — empieza offline, el poller lo actualizará.
           Necesita has-presence-dot para anular el overflow:hidden del
           frame que clipearía el dot que está en bottom:-2px right:-2px. */
        avWrap.classList.add('has-presence-dot');
        var dot = document.createElement('span');
        dot.className = 'pf-presence-dot';
        dot.setAttribute('data-userkey', userKey);
        avWrap.appendChild(dot);
        card.appendChild(avWrap);
        var nameEl = document.createElement('div');
        nameEl.className = 'profile-social-name';
        nameEl.textContent = u.label;
        card.appendChild(nameEl);
        /* Now-playing slot: applyNowPlaying() lo rellena/oculta según
           el state del usuario. Empieza display:none y se activa solo
           si el server reporta que está reproduciendo. */
        var npEl = document.createElement('div');
        npEl.className = 'profile-social-np';
        npEl.setAttribute('data-np-userkey', userKey);
        npEl.style.display = 'none';
        card.appendChild(npEl);
        card.addEventListener('click', function() {
            /* Abre el perfil del clicked user como ventana iframe-
               based (con su interfaz/tema/iconos) en vez de navegar
               dentro del embedded. */
            if (typeof window.openProfileAtUser === 'function') {
                window.openProfileAtUser(userKey);
            } else {
                viewOtherUser(userKey);
            }
        });
        return card;
    }

    function renderSocialList() {
        var exploreEl = document.getElementById('profile-social-explore');
        if (!exploreEl) return;
        exploreEl.innerHTML = '';

        var followingSet = {};
        ownFollowing.forEach(function(k) { followingSet[k] = true; });
        var exploreKeys = Object.keys(PROFILE_USERS)
            .filter(function(k) { return k !== currentSessionUser && !followingSet[k]; });
        exploreKeys.sort(function(a, b) {
            var la = (PROFILE_USERS[a].label || '').toLowerCase();
            var lb = (PROFILE_USERS[b].label || '').toLowerCase();
            return la < lb ? -1 : la > lb ? 1 : 0;
        });

        exploreKeys.forEach(function(k) {
            var card = buildSocialCard(k);
            if (card) exploreEl.appendChild(card);
        });
        /* Re-aplica el estado de presencia conocido sin esperar al fetch. */
        if (window.__applyPresence) window.__applyPresence();
        if (!exploreKeys.length) {
            exploreEl.innerHTML = '<div class="profile-social-empty">No hay más usuarios.</div>';
        }
    }

    function renderFollowedNav() {
        var nav = document.getElementById('profile-followed-nav');
        if (!nav) return;
        nav.innerHTML = '';
        var keys = ownFollowing.filter(function(k) { return PROFILE_USERS[k]; });
        keys.sort(function(a, b) {
            var la = (PROFILE_USERS[a].label || '').toLowerCase();
            var lb = (PROFILE_USERS[b].label || '').toLowerCase();
            return la < lb ? -1 : la > lb ? 1 : 0;
        });
        keys.forEach(function(k) {
            var u = PROFILE_USERS[k];
            var item = document.createElement('div');
            item.className = 'profile-nav-item profile-nav-followed';
            item.dataset.user = k;
            var iconWrap = document.createElement('span');
            iconWrap.className = 'profile-nav-icon profile-nav-icon-followed';
            if (u.image) {
                var img = document.createElement('img');
                img.className = 'profile-nav-avatar';
                img.src = u.image; img.alt = u.label;
                iconWrap.appendChild(img);
            } else {
                iconWrap.textContent = '👤';
            }
            /* Punto de presencia para los followed — más pequeño que en
               las social cards porque el avatar de la sidebar es 22×22. */
            var navDot = document.createElement('span');
            navDot.className = 'pf-presence-dot pf-presence-dot-small';
            navDot.setAttribute('data-userkey', k);
            iconWrap.appendChild(navDot);
            item.appendChild(iconWrap);
            /* Bloque con label + slot de now-playing apilados. */
            var textCol = document.createElement('span');
            textCol.className = 'profile-nav-text';
            var label = document.createElement('span');
            label.className = 'profile-nav-label';
            label.textContent = u.label;
            textCol.appendChild(label);
            var npEl = document.createElement('span');
            npEl.className = 'profile-nav-np';
            npEl.setAttribute('data-np-userkey', k);
            npEl.style.display = 'none';
            textCol.appendChild(npEl);
            item.appendChild(textCol);
            (function(uk) {
                item.addEventListener('click', function() {
                    /* Abre el perfil del seguido como ventana iframe-
                       based con SU interfaz/tema/iconos. */
                    if (typeof window.openProfileAtUser === 'function') {
                        window.openProfileAtUser(uk);
                    } else {
                        viewOtherUser(uk);
                    }
                });
                /* Click derecho → menú apodo / silenciar (solo mutuos
                   tienen acceso al chat; en no-mutuos no tiene sentido). */
                if (isMutual(uk)) {
                    item.addEventListener('contextmenu', function(ev) {
                        ev.preventDefault();
                        openUserCtxMenu(ev.clientX, ev.clientY, uk);
                    });
                }
            })(k);
            if (isMutual(k)) {
                var chatBtn = document.createElement('span');
                chatBtn.className = 'profile-nav-chat';
                chatBtn.title = 'Chat con ' + u.label;
                var ico = document.createElement('img');
                ico.className = 'profile-nav-chat-ico';
                ico.src = 'assets/img/appIcons/chatIcon.png';
                ico.alt = '';
                chatBtn.appendChild(ico);
                var unread = unreadChats[k] || 0;
                if (unread > 0) {
                    var badge = document.createElement('span');
                    badge.className = 'profile-nav-chat-badge';
                    badge.textContent = unread > 9 ? '+9' : String(unread);
                    chatBtn.appendChild(badge);
                }
                (function(uk) {
                    chatBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        openChatWith(uk);
                    });
                })(k);
                item.appendChild(chatBtn);
            }
            nav.appendChild(item);
        });
        /* Re-aplica el estado de presencia conocido. */
        if (window.__applyPresence) window.__applyPresence();
    }

    /* Convierte camelCase del JSON ({winBg, accentText, bezelLight1...})
       a kebab-case CSS var (--win-bg, --accent-text, --bezel-light-1).
       El "(\d)" extra mete guion antes de dígitos para no perder
       separadores en keys como bezelLight1 → bezel-light-1. */
    function _cssVarOf(camelKey) {
        return '--' + camelKey
            .replace(/([A-Z])/g, '-$1')
            .replace(/([a-zA-Z])(\d)/g, '$1-$2')
            .toLowerCase();
    }

    /* Aplica los colores del tema activo del visitado como style inline
       sobre #profile-window. Como son CSS vars (--win-bg, --accent, etc.)
       sobreescriben las del shell SOLO dentro del profile-window —
       hijos via cascada heredan automáticamente. */
    function applyVisitedUserTheme(targetUserKey) {
        var pw = document.getElementById('profile-window');
        if (!pw) return;
        fetch('assets/personalize/api.php?action=get-look&as=' + encodeURIComponent(targetUserKey), {
            credentials: 'same-origin'
        })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok || !d.theme || !d.theme.colors) {
                    /* Sin tema custom: limpiamos lo previo. */
                    clearVisitedUserTheme();
                    return;
                }
                var colors = d.theme.colors;
                var css = '';
                Object.keys(colors).forEach(function(k){
                    var v = colors[k];
                    if (!v) return;
                    css += _cssVarOf(k) + ': ' + v + ' !important; ';
                });
                /* Marcamos las vars con un data-attr para poder limpiarlas
                   sin tocar otros estilos inline pre-existentes. */
                pw.dataset.visitedTheme = '1';
                pw.dataset.visitedThemeCss = css;
                pw.style.cssText = (pw.style.cssText || '') + ' ' + css;
            })
            .catch(function(){});
    }

    /* Quita los CSS vars del visitado, restaurando el tema del viewer. */
    function clearVisitedUserTheme() {
        var pw = document.getElementById('profile-window');
        if (!pw || !pw.dataset.visitedTheme) return;
        var injected = pw.dataset.visitedThemeCss || '';
        if (injected) {
            /* Quita exactamente lo inyectado para no perder otros styles. */
            pw.style.cssText = (pw.style.cssText || '').replace(injected, '');
        }
        delete pw.dataset.visitedTheme;
        delete pw.dataset.visitedThemeCss;
    }

    /* Si el perfil del visitado no carga, la ventana standalone se quedaba
       enseñando el perfil PROPIO del visitante bajo el nombre y el tema del
       visitado: parecía que habías entrado y que sus listas eran las tuyas.
       Vaciamos el body entero — así es imposible confundir tus datos con
       los suyos — y ofrecemos reintentar. */
    function showProfileLoadError(msg) {
        var body = document.querySelector('#profile-window > .window-body');
        if (!body) { alert(msg); return; }
        body.innerHTML = '';
        body.style.display = 'flex';
        var box = document.createElement('div');
        box.style.cssText = 'margin:auto;padding:18px;text-align:center;font-size:12px;max-width:340px;';
        var p = document.createElement('p');
        p.style.cssText = 'margin:0 0 10px;';
        p.textContent = msg;
        var btn = document.createElement('button');
        btn.className = 'button';
        btn.textContent = 'Reintentar';
        btn.addEventListener('click', function() { location.reload(); });
        box.appendChild(p);
        box.appendChild(btn);
        body.appendChild(box);
    }

    function viewOtherUser(userKey, _intento) {
        _intento = _intento || 0;
        /* Al ver el perfil de otra persona, salimos de modo edición */
        document.dispatchEvent(new Event('profile-edit-cancel'));
        console.log('[viewOtherUser] fetch', userKey);
        var apiUrl = 'assets/profile/api.php?action=view-user&user=' + encodeURIComponent(userKey);
        fetch(apiUrl)
            .then(function(r) {
                /* Leemos el cuerpo como texto y parseamos a mano: con
                   r.json() directo, una respuesta no-JSON (fatal de PHP,
                   404 de Apache, HTML de login) solo dejaba un SyntaxError
                   pelado que el .catch se tragaba, sin URL ni status ni
                   cuerpo — imposible de diagnosticar desde consola. */
                return r.text().then(function(raw) {
                    try {
                        return JSON.parse(raw);
                    } catch (e) {
                        console.error('[viewOtherUser] respuesta no-JSON', {
                            url:         apiUrl,
                            status:      r.status + ' ' + r.statusText,
                            contentType: r.headers.get('content-type'),
                            body:        raw.slice(0, 1000)
                        });
                        throw e;
                    }
                });
            })
            .then(function(data) {
                console.log('[viewOtherUser] response', data);
                if (!data || data.error) {
                    console.error('[viewOtherUser] error', data);
                    /* La BD puede estar saturada un instante (ráfaga de
                       conexiones al recargar el shell y todas las apps).
                       Reintentamos con backoff antes de rendirnos: el
                       fallo es transitorio y así el usuario ni lo nota. */
                    if (_intento < 2) {
                        setTimeout(function() {
                            viewOtherUser(userKey, _intento + 1);
                        }, 600 * (_intento + 1));
                        return;
                    }
                    var msg = (data && data.error) ? data.error : 'No se pudo cargar el perfil';
                    if (window.__PERFIL_STANDALONE) { showProfileLoadError(msg); return; }
                    alert(msg);
                    return;
                }
                if (!ownLists) ownLists = lists;
                lists = data.lists;
                viewingUser = data.userKey;
                /* Pinta cabecera con datos del otro usuario */
                var u = PROFILE_USERS[data.userKey];
                var avFrame = document.querySelector('#profile-avatar-col .profile-avatar-frame');
                if (avFrame) {
                    avFrame.innerHTML = '';
                    if (u && u.image) {
                        var img = document.createElement('img');
                        img.className = 'profile-avatar-img';
                        img.src = u.image; img.alt = u.label;
                        avFrame.appendChild(img);
                    } else {
                        avFrame.innerHTML = '<div class="profile-avatar-placeholder">👤</div>';
                    }
                    if (window.__attachPresenceDot) window.__attachPresenceDot(avFrame, data.userKey);
                }
                var nameEl = document.getElementById('profile-username');
                if (nameEl) nameEl.textContent = data.label;
                renderProfileInfo(data.profile);
                renderPosts(data.profile.posts || []);
                updateCounts();
                document.getElementById('profile-view-social').style.display = 'none';
                document.getElementById('profile-view-cat').style.display     = 'none';
                var melonV = document.getElementById('profile-view-melon');
                if (melonV) melonV.style.display = 'none';
                var mv = document.getElementById('profile-view-music');
                if (mv) mv.style.display = 'none';
                document.getElementById('profile-view-default').style.display = 'flex';
                document.getElementById('profile-sidebar-back').style.display = 'block';
                document.getElementById('profile-info-edit-btn').style.display = 'none';
                var newPostEl = document.getElementById('profile-new-post');
                if (newPostEl) newPostEl.style.display = 'none';
                updateFollowButton(!!data.isFollowing);
                var listasHdText = document.getElementById('profile-listas-heading-text');
                if (listasHdText) listasHdText.textContent = 'Listas de ' + data.label;
                var socialSec = document.getElementById('profile-social-section');
                if (socialSec) socialSec.style.display = 'none';
                var melonSec  = document.getElementById('profile-melon-section');
                if (melonSec)  melonSec.style.display  = 'none';
                var notifBtn = document.getElementById('profile-notif-btn');
                if (notifBtn) notifBtn.style.display = 'none';
                /* Aplica el tema del visitado al #profile-window (colores
                   CSS vars como style inline → cascada hacia descendientes,
                   sin tocar el resto del shell). */
                applyVisitedUserTheme(data.userKey);
            })
            .catch(function(err) {
                /* Ojo: aquí caen TANTO los fallos de red/parseo como
                   cualquier excepción lanzada por el .then de render de
                   arriba. El log distingue unos de otros. */
                console.error('[viewOtherUser] fallo cargando', userKey, err);
                if (_intento < 2) {
                    setTimeout(function() {
                        viewOtherUser(userKey, _intento + 1);
                    }, 600 * (_intento + 1));
                    return;
                }
                if (window.__PERFIL_STANDALONE) {
                    showProfileLoadError('No se pudo cargar el perfil');
                    return;
                }
                alert('No se pudo cargar el perfil');
            });
    }

    function updateFollowButton(isFollowing) {
        var btn = document.getElementById('profile-follow-btn');
        if (!btn) return;
        if (!viewingUser) { btn.style.display = 'none'; return; }
        btn.style.display = '';
        btn.textContent = isFollowing ? '✓ Siguiendo' : '+ Seguir';
        btn.classList.toggle('following', !!isFollowing);
    }

    function isMutual(userKey) {
        return ownFollowing.indexOf(userKey) !== -1 && myFollowers.indexOf(userKey) !== -1;
    }

    /* Presencia: cada 20s pide la lista de online y refresca los puntos.
       Cacheamos el último set para que renderSocialList/renderFollowedNav
       puedan re-aplicar el estado sin esperar al siguiente fetch. */
    var lastOnlineSet  = {};
    var lastAwaySet    = {};
    var lastDndSet     = {};
    var lastNowPlaying = {};   /* user_key → {title, artist} */
    function applyPresence() {
        document.querySelectorAll('.pf-presence-dot[data-userkey]').forEach(function(dot) {
            var k = dot.getAttribute('data-userkey');
            dot.classList.toggle('online', !!lastOnlineSet[k]);
            /* away gana visualmente sobre online (verde→amarillo). */
            dot.classList.toggle('away',   !!lastAwaySet[k]);
            dot.classList.toggle('dnd',    !!lastDndSet[k]);
        });
        applyNowPlaying();
    }
    /* Marquee clonado tal cual de apps/reproductor.php — el texto
       arranca en su posición natural (visible desde el principio),
       pausa `pauseMs`, se desplaza a la izquierda a `speed` px/seg,
       sale completamente, reaparece por la derecha y vuelve a la
       posición 0. Allí pausa de nuevo `pauseMs` y vuelve a empezar.
       Devuelve un stop() para limpiar timers/raf al re-renderizar.
       Si el texto no desborda el contenedor, no anima. */
    /* ──────────────────────────────────────────────────────────────
       NOW-PLAYING — calcado del `setupMarquee` del reproductor móvil
       (mu-marquee), que sí funciona consistentemente. CSS animation
       con texto duplicado: el navegador hace toda la matemática del
       loop, sin RAF ni mediciones complicadas. La única medida que
       necesitamos es `text.offsetWidth` para calcular el ciclo en
       píxeles — y en la estructura wrap-block + track-inline-block
       + text-inline-block del reproductor móvil ese offsetWidth ES
       fiable (ya está probado en producción).
       ────────────────────────────────────────────────────────── */
    function pfSetupMarquee(slot) {
        var wrap  = slot.querySelector('.pf-np-wrap');
        var track = slot.querySelector('.pf-np-track');
        var text  = slot.querySelector('.pf-np-text');
        if (!wrap || !track || !text) return;
        /* Limpia estado previo. */
        wrap.classList.remove('is-marquee');
        while (track.children.length > 1) track.removeChild(track.lastChild);
        track.style.removeProperty('--pf-cycle');
        track.style.removeProperty('--pf-dur');
        track.style.removeProperty('--pf-gap');
        var go = function() {
            var textW = text.offsetWidth;
            var wrapW = wrap.clientWidth;
            /* No anima si el texto cabe (margen de 4px subpixel). */
            if (textW <= wrapW + 4) return;
            /* Clona el texto: la segunda copia ocupa el espacio
               cuando la primera se desplaza fuera por la izquierda →
               loop sin salto. */
            var dup = text.cloneNode(true);
            dup.setAttribute('aria-hidden', 'true');
            track.appendChild(dup);
            /* GAP = ancho del wrap → cuando la copia 1 sale por la
               izquierda, la copia 2 está aún a `wrapW` de distancia
               por la derecha, así que el contenedor visible queda
               COMPLETAMENTE VACÍO durante todo ese desplazamiento.
               Eso es el "espacio blanco" entre vueltas que pide el
               usuario. Mínimo 4em por si el wrap es muy pequeño. */
            var em    = parseFloat(getComputedStyle(text).fontSize) || 10;
            var gap   = Math.max(wrapW, em * 4);
            var cycle = textW + gap;
            /* 30 px/seg de scroll efectivo (sólo el 90% del tiempo
               se usa scrolleando — el 10% inicial es el hold). */
            var dur   = Math.max(10, (cycle / 30) / 0.9).toFixed(1) + 's';
            track.style.setProperty('--pf-cycle', cycle + 'px');
            track.style.setProperty('--pf-dur',   dur);
            track.style.setProperty('--pf-gap',   gap + 'px');
            wrap.classList.add('is-marquee');
        };
        /* Espera a las fuentes para que la medida coincida con el
           glifo final que se renderiza. */
        if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
            document.fonts.ready.then(go, go);
        } else {
            requestAnimationFrame(go);
        }
    }

    function applyNowPlaying() {
        document.querySelectorAll('[data-np-userkey]').forEach(function(slot) {
            var k  = slot.getAttribute('data-np-userkey');
            var np = lastNowPlaying[k];
            var nextText = np ? ((np.title || '') + (np.artist ? ' - ' + np.artist : '')) : '';
            var prevText = slot.getAttribute('data-np-text') || '';
            /* IDEMPOTENCIA: el texto no cambió → no tocamos nada.
               El marquee CSS sigue corriendo sin reset. */
            if (nextText === prevText) return;
            slot.setAttribute('data-np-text', nextText);
            if (!np) {
                slot.style.display = 'none';
                slot.innerHTML = '';
                return;
            }
            slot.style.display = '';
            /* Estructura calcada del reproductor móvil:
               icon + wrap(block) → track(inline-block) → text(inline-block) */
            slot.innerHTML =
                '<span class="pf-np-icon">♪</span>' +
                '<span class="pf-np-wrap">' +
                    '<span class="pf-np-track">' +
                        '<span class="pf-np-text">' + escHtml(nextText) + '</span>' +
                    '</span>' +
                '</span>';
            pfSetupMarquee(slot);
        });
    }
    /* Helper: marca un .profile-avatar-frame con su dot de presencia.
       Cualquier dot previo se elimina. userKey '' o falsy → sin dot.
       Si el usuario está en DND (mute_messages activo) el dot se
       muestra rojo aunque esté online. */
    function attachPresenceDot(frame, userKey) {
        if (!frame) return;
        var existing = frame.querySelector('.pf-presence-dot');
        if (existing) existing.remove();
        if (!userKey) {
            frame.classList.remove('has-presence-dot');
            return;
        }
        frame.classList.add('has-presence-dot');
        var dot = document.createElement('span');
        dot.className = 'pf-presence-dot';
        dot.setAttribute('data-userkey', userKey);
        frame.appendChild(dot);
        if (lastOnlineSet[userKey]) dot.classList.add('online');
        if (lastAwaySet[userKey])   dot.classList.add('away');
        if (lastDndSet[userKey])    dot.classList.add('dnd');
    }
    window.__attachPresenceDot = attachPresenceDot;
    /* Mapa de last-seen para mostrar "Última vez X" en la cabecera del
       chat cuando el otro está offline. Lo devuelve el endpoint extendido
       de presence. */
    var lastSeenMap = {};
    function refreshPresenceDots() {
        fetch('assets/profile/api.php?action=presence', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.ok || !Array.isArray(d.online)) return;
                lastOnlineSet = {};
                d.online.forEach(function(k) { lastOnlineSet[k] = true; });
                lastAwaySet = {};
                if (Array.isArray(d.away)) {
                    d.away.forEach(function(k) { lastAwaySet[k] = true; });
                }
                lastDndSet = {};
                if (Array.isArray(d.dnd)) {
                    d.dnd.forEach(function(k) { lastDndSet[k] = true; });
                }
                lastNowPlaying = (d.nowPlaying && typeof d.nowPlaying === 'object') ? d.nowPlaying : {};
                if (d.lastSeen && typeof d.lastSeen === 'object') {
                    lastSeenMap = d.lastSeen;
                }
                applyPresence();
                /* Refresca el status del chat header si hay chat abierto. */
                if (chatWithUser) refreshChatStatus();
            })
            .catch(function() {});
    }
    /* "Última vez …" — formato corto en local. */
    function formatLastSeen(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        if (isNaN(d.getTime())) return '';
        var now = new Date();
        var hh = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
        if (d.toDateString() === now.toDateString()) return 'hoy ' + hh;
        var y = new Date(now.getTime() - 86400000);
        if (d.toDateString() === y.toDateString()) return 'ayer ' + hh;
        var diff = (now - d) / 86400000;
        if (diff < 7) {
            var dows = ['dom','lun','mar','mié','jue','vie','sáb'];
            return dows[d.getDay()] + ' ' + hh;
        }
        return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + ' ' + hh;
    }
    /* Actualiza el sub-status del chat header — "· En línea" / "· Ausente"
       / "· No molestar" / "· Última vez X". Hereda los sets ya cacheados
       desde el último poll de presencia (los mismos que usa Social) →
       sin flicker "online → away" al abrir. Prioridad:
         1. DND (más restrictivo: el usuario no quiere ser molestado)
         2. Away  (online pero inactivo / lock screen)
         3. Online activo
         4. Última vez X (offline conocido)
         5. Sin status (offline desconocido) */
    function refreshChatStatus() {
        var stEl = document.getElementById('profile-chat-status');
        if (!stEl) return;
        if (!chatWithUser) { stEl.textContent = ''; stEl.classList.remove('is-online'); return; }
        if (lastDndSet[chatWithUser]) {
            stEl.textContent = '· No molestar';
            stEl.classList.remove('is-online');
        } else if (lastAwaySet[chatWithUser]) {
            stEl.textContent = '· Ausente';
            stEl.classList.remove('is-online');
        } else if (lastOnlineSet[chatWithUser]) {
            stEl.textContent = '· En línea';
            stEl.classList.add('is-online');
        } else if (lastSeenMap[chatWithUser]) {
            stEl.textContent = '· Última vez ' + formatLastSeen(lastSeenMap[chatWithUser]);
            stEl.classList.remove('is-online');
        } else {
            stEl.textContent = '';
            stEl.classList.remove('is-online');
        }
    }
    /* Expone applyPresence para que las funciones de render puedan
       re-aplicar el estado al recrear los dots. */
    window.__applyPresence = applyPresence;
    refreshPresenceDots();
    setInterval(refreshPresenceDots, 20000);

    /* Inicializa el dot en los 3 avatares hard-coded del HTML
       (default profile view, cat view, music view). Apuntan al usuario
       propio cuando se carga, ya que no estamos viewing-other. */
    [
        '#profile-avatar-col .profile-avatar-frame',
        '#profile-catview-avatar-wrap .profile-avatar-frame',
        '#music-catview-avatar-wrap .profile-avatar-frame',
    ].forEach(function(sel) {
        var fr = document.querySelector(sel);
        if (fr) attachPresenceDot(fr, currentSessionUser);
    });

    function loadMyFollowers(cb) {
        fetch('assets/profile/api.php?action=get-followers')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d && d.ok && Array.isArray(d.followers)) myFollowers = d.followers;
                if (cb) cb();
            })
            .catch(function() { if (cb) cb(); });
    }

    /* ──── Chat ──── */
    var chatWithUser   = null;
    var chatLastSeenId = null;
    var chatPollTimer  = null;
    var unreadChats    = {};  /* { userKey: count } — mensajes sin leer por chat */
    var unreadPollTimer = null;

    /* ── Supresión de notificaciones push del chat activo ──
       El service worker pregunta vía MessageChannel cada vez que llega
       un push si estamos focused en ese chat. Si lo estamos Y la página
       es visible, devolvemos true → el SW se salta showNotification.
       Sin esto el usuario recibe ping del SO mientras lee los mensajes
       en vivo en la ventana del escritorio. */
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function(e) {
            var d = e.data || {};
            if (d.type !== 'sw:is-chat-focused') return;
            var port = e.ports && e.ports[0];
            if (!port) return;
            var focused = (chatWithUser === d.fromKey)
                       && (document.visibilityState === 'visible');
            try { port.postMessage({ focused: focused }); } catch (_) {}
        });
    }

    function openChatWith(userKey) {
        if (!userKey || !PROFILE_USERS[userKey]) return;
        chatWithUser = userKey;
        chatLastSeenId = null;
        chatLastSig    = '';
        /* Limpia el listado de mensajes ANTES de pedir los del nuevo
           usuario: si abres un chat sin mensajes (ej. el primero con
           alguien), loadChatMessages devuelve [] y renderChatMessages
           hace early-return porque el sig ('' nuevo) coincide con el
           que dejamos en chatLastSig ('' reset). Como no re-renderiza,
           el DOM se quedaba con los mensajes del chat anterior. */
        var msgsEl = document.getElementById('profile-chat-messages');
        if (msgsEl) msgsEl.innerHTML = '';
        /* Pinta status (En línea / Última vez …) inmediatamente con el
           snapshot que ya tenemos en memoria — el siguiente refresh
           cada 20s lo actualiza si cambia. */
        refreshChatStatus();
        var u = PROFILE_USERS[userKey];
        /* Avatar del usuario en lugar del 💬. Si no hay imagen, fallback a la inicial. */
        var avEl = document.getElementById('profile-chat-title-av');
        if (avEl) {
            if (u.image) {
                avEl.innerHTML = '<img src="' + escHtml(u.image) + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            } else {
                avEl.innerHTML = '<div style="width:100%;height:100%;background:var(--accent);color:var(--accent-text,#fff);font-size:10px;font-weight:bold;display:flex;align-items:center;justify-content:center;">' + escHtml((u.label || '?').charAt(0).toUpperCase()) + '</div>';
            }
            /* `position:relative` para anclar el dot absoluto a la esquina
               del span; `overflow:visible` porque el dot se posiciona
               fuera del rect (bottom/right negativos). */
            avEl.style.position = 'relative';
            avEl.style.overflow = 'visible';
            /* Dot de presencia del user con el que chateo. Hereda
               online/away/dnd del cache (mismos sets que Social) para
               no parpadear: applyPresence() los re-aplica en cada poll. */
            var prevDot = avEl.querySelector('.pf-presence-dot');
            if (prevDot) prevDot.remove();
            var chatDot = document.createElement('span');
            chatDot.className = 'pf-presence-dot pf-presence-dot-small';
            chatDot.setAttribute('data-userkey', userKey);
            if (lastOnlineSet[userKey]) chatDot.classList.add('online');
            if (lastAwaySet[userKey])   chatDot.classList.add('away');
            if (lastDndSet[userKey])    chatDot.classList.add('dnd');
            avEl.appendChild(chatDot);
        }
        document.getElementById('profile-chat-title-text').textContent = u.label;
        var win = document.getElementById('profile-chat-window');
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        loadChatMessages(true);   /* initial=true: pin imágenes durante grace */
        if (chatPollTimer) clearInterval(chatPollTimer);
        chatPollTimer = setInterval(loadChatMessages, 1500);
        /* Quita el contador local al abrir el chat */
        if (unreadChats[userKey]) { delete unreadChats[userKey]; renderFollowedNav(); }
        setTimeout(function() {
            var input = document.getElementById('profile-chat-input');
            if (input) input.focus();
        }, 50);
    }

    /* Total acumulado de mensajes sin leer en la respuesta previa. -1 =
       primer load (baseline) → no suena por la cuenta inicial. Solo subidas
       posteriores hacen play. Reutilizamos playProfileNotifSound() — está
       throttled a 1.5s, así que si entran mensaje + notif a la vez, solo
       suena una. */
    var _prevChatUnread = -1;
    var _lastUnreadSig = '';
    function loadUnreadChats() {
        fetch('assets/profile/api.php?action=get-unread-chats')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.ok) return;
                unreadChats = d.counts || {};
                /* No mostrar contador para el chat actualmente abierto */
                if (chatWithUser && unreadChats[chatWithUser]) delete unreadChats[chatWithUser];
                /* Sumar todos los unread (excluido el chat abierto, ya
                   borrado arriba). Sonido solo si subió respecto al
                   baseline anterior y no es el primer load. */
                var total = 0;
                for (var k in unreadChats) {
                    if (Object.prototype.hasOwnProperty.call(unreadChats, k)) {
                        total += unreadChats[k] | 0;
                    }
                }
                if (_prevChatUnread >= 0 && total > _prevChatUnread
                    && typeof playProfileNotifSound === 'function') {
                    /* Hint 'chat' → notifIsMuted lo mapea a categoría
                       'messages' y silencia si el modo "no molestar"
                       (mute_messages) está activo. Antes se llamaba
                       sin hint → el filtro no se aplicaba y el ping
                       siempre sonaba al recibir un mensaje. */
                    playProfileNotifSound('chat');
                }
                _prevChatUnread = total;
                /* IDEMPOTENCIA: solo re-renderizar el sidebar si los
                   counts realmente cambiaron. Antes este poll de 4s
                   reconstruía siempre el DOM completo del nav →
                   mataba el marquee de now-playing cada 4 segundos
                   incluso cuando no había ningún chat nuevo. */
                var sig = JSON.stringify(unreadChats);
                if (sig !== _lastUnreadSig) {
                    _lastUnreadSig = sig;
                    renderFollowedNav();
                }
            }).catch(function() {});
    }

    function closeChat() {
        var win = document.getElementById('profile-chat-window');
        if (win) win.style.display = 'none';
        chatWithUser = null;
        chatLastSig  = '';
        var stEl = document.getElementById('profile-chat-status');
        if (stEl) { stEl.textContent = ''; stEl.classList.remove('is-online'); }
        if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
    }

    function loadChatMessages(initial) {
        if (!chatWithUser) return;
        fetch('assets/profile/api.php?action=get-messages&with=' + encodeURIComponent(chatWithUser))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.ok) return;
                renderChatMessages(d.messages || [], !!initial);
            }).catch(function() {});
    }

    /* Icono PNG para placeholder de mensaje eliminado (reemplaza emoji 🗑). */
    var CHAT_TRASH_ICON_HTML = '<img src="assets/img/appIcons/trashIcon.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">';


    /* Signature de los mensajes para evitar re-render cuando nada
       cambió pero los ticks SÍ pueden variar (read/delivered). Incluye
       max id + flags por mensaje propio. */
    function chatMessagesSig(messages) {
        return messages.map(function(m) {
            if (m.from === currentSessionUser) {
                var t = m.read ? 'R' : (m.delivered ? 'D' : 'S');
                return m.id + ':' + t + (m.edited ? 'E' : '') + (m.deleted ? 'X' : '');
            }
            return m.id + (m.edited ? 'E' : '') + (m.deleted ? 'X' : '');
        }).join(',');
    }
    var chatLastSig = '';

    /* Linkify + imagen inline para mensajes de chat. Detecta URLs
       (http(s):// y www.) en el texto: las que apuntan a imágenes
       (jpg/png/gif/webp/svg) se renderizan inline como <img>; el
       resto como <a target="_blank">. El texto fuera de URLs se
       escapa con escHtml para evitar XSS. */
    var _CH_URL_RE = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+)/gi;
    /* Detecta imágenes por DOS rutas:
       1. Extensión en el path antes de ?/# (jpg/png/gif/webp/svg/bmp/avif).
       2. Host conocido que sirve imágenes aunque la URL no tenga
          extensión (Google image thumbs, Discord CDN, imgur, reddit,
          twitter media, googleusercontent, tenor/giphy). Sin esto,
          URLs tipo `encrypted-tbn0.gstatic.com/images?q=tbn:…` no se
          reconocían como imagen y salían como link. */
    var _CH_IMG_EXT_RE  = /\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)(?:[?#].*)?$/i;
    var _CH_IMG_HOST_RE = /^https?:\/\/(?:encrypted-tbn\d*\.gstatic\.com\/images|lh\d+\.googleusercontent\.com|[^/]*\.googleusercontent\.com|i\.imgur\.com|media\d*\.tenor\.com|c\.tenor\.com|media\d*\.giphy\.com|i\.giphy\.com|cdn\.discordapp\.com\/attachments|media\.discordapp\.net\/attachments|i\.redd\.it|preview\.redd\.it|pbs\.twimg\.com\/media)/i;
    /* Google Drive: las URLs típicas que copia el usuario apuntan a
       una página HTML (`/file/d/<ID>/view`) que no se puede usar como
       <img src>. Extraemos el ID y devolvemos el endpoint
       `thumbnail?id=…&sz=w1920` que sirve la imagen directamente.
       Soporta formatos file/d/, uc?id=, open?id=, thumbnail?id=. */
    function _chatDriveImageUrl(url) {
        if (!/^https?:\/\/(?:drive|docs)\.google\.com\//i.test(url)) return null;
        var id = '';
        var m = url.match(/\/file\/d\/([A-Za-z0-9_-]{15,})/);
        if (m) id = m[1];
        if (!id) { m = url.match(/[?&]id=([A-Za-z0-9_-]{15,})/); if (m) id = m[1]; }
        if (!id) return null;
        return 'https://drive.google.com/thumbnail?id=' + id + '&sz=w1920';
    }
    function _chatIsImageUrl(url) {
        return _CH_IMG_EXT_RE.test(url) || _CH_IMG_HOST_RE.test(url);
    }
    function chatRenderUrlToken(url) {
        var isWww = /^www\./i.test(url);
        var full  = isWww ? ('http://' + url) : url;
        /* Drive: reescribir al endpoint thumbnail antes de pintar. */
        var driveSrc = _chatDriveImageUrl(url);
        if (driveSrc) {
            return '<img class="chat-msg-img" src="' + escHtml(driveSrc) + '" data-fullsrc="' + escHtml(driveSrc) + '" alt="" loading="lazy" referrerpolicy="no-referrer">';
        }
        if (_chatIsImageUrl(url)) {
            /* Imagen embebida: click abre lightbox in-page; sin
               envolver en <a> porque eso navegaría al link en lugar
               de mostrar preview. El handler delegado más abajo
               escucha clicks en .chat-msg-img. */
            return '<img class="chat-msg-img" src="' + escHtml(full) + '" data-fullsrc="' + escHtml(full) + '" alt="" loading="lazy" referrerpolicy="no-referrer">';
        }
        return '<a class="chat-msg-link" href="' + escHtml(full) + '" target="_blank" rel="noopener noreferrer">' +
               escHtml(url) + '</a>';
    }
    function chatRenderBody(text) {
        var s = String(text == null ? '' : text);
        if (!s) return '';
        var out = '', last = 0, m;
        _CH_URL_RE.lastIndex = 0;
        while ((m = _CH_URL_RE.exec(s)) !== null) {
            out += escHtml(s.slice(last, m.index));
            out += chatRenderUrlToken(m[0]);
            last = m.index + m[0].length;
        }
        out += escHtml(s.slice(last));
        return out;
    }

    /* Lightbox del chat: preview grande al click en cualquier imagen
       embebida. Setup una sola vez via flag global; el listener vive
       en document para que capture clicks de cualquier mensaje futuro. */
    if (!window.__chatLightboxReady) {
        window.__chatLightboxReady = true;
        document.addEventListener('click', function(e){
            var img = e.target && e.target.closest && e.target.closest('.chat-msg-img');
            if (!img) return;
            e.preventDefault();
            e.stopPropagation();
            var src = img.dataset.fullsrc || img.src;
            if (!src) return;
            var lb = document.getElementById('chat-lightbox');
            if (!lb) {
                lb = document.createElement('div');
                lb.id = 'chat-lightbox';
                lb.className = 'chat-lightbox';
                lb.innerHTML = '<img alt=""><button class="chat-lightbox-close" type="button" aria-label="Cerrar">×</button>';
                lb.addEventListener('click', function(ev){
                    if (ev.target === lb || ev.target.classList.contains('chat-lightbox-close')) {
                        lb.classList.remove('is-open');
                        lb.querySelector('img').src = '';
                    }
                });
                document.body.appendChild(lb);
            }
            lb.querySelector('img').src = src;
            lb.classList.add('is-open');
        });
        document.addEventListener('keydown', function(e){
            if (e.key !== 'Escape') return;
            var lb = document.getElementById('chat-lightbox');
            if (lb && lb.classList.contains('is-open')) {
                lb.classList.remove('is-open');
                lb.querySelector('img').src = '';
            }
        });
    }

    function renderChatMessages(messages, initial) {
        var listEl = document.getElementById('profile-chat-messages');
        if (!listEl) return;
        var sig = chatMessagesSig(messages);
        if (sig === chatLastSig) return;
        chatLastSig = sig;
        var atBottom = (listEl.scrollHeight - listEl.scrollTop - listEl.clientHeight) < 40;

        /* Render INCREMENTAL: reutilizamos las filas ya pintadas y solo
           añadimos las nuevas / actualizamos meta. CLAVE: así NO recreamos
           las <img>/GIF de los mensajes existentes (recrearlas las recarga,
           pasan a alto 0 y luego crecen → el scroll daba un tirón hacia
           arriba al llegar un mensaje nuevo). */
        var existing = {};
        Array.prototype.forEach.call(listEl.querySelectorAll('.chat-msg'), function(r){ existing[r.dataset.msgId] = r; });
        var seen = {};
        var newImgs = [];

        messages.forEach(function(m) {
            var idStr = String(m.id);
            seen[idStr] = 1;
            var mine = m.from === currentSessionUser;
            var row = existing[idStr];
            var isNew = !row;
            if (isNew) {
                row = document.createElement('div');
                row.dataset.msgId = m.id;
            }
            row.className = 'chat-msg' + (mine ? ' chat-msg-mine' : ' chat-msg-theirs');
            row.dataset.mine = mine ? '1' : '0';
            row.dataset.deleted = m.deleted ? '1' : '0';

            /* Burbuja: solo se (re)construye si es nueva o cambió el cuerpo
               (texto editado o paso a "eliminado"). Si no, se deja intacta
               para conservar las imágenes ya cargadas. */
            var bubble = row.querySelector('.chat-bubble');
            var bodyKey = m.deleted ? ' del' : ('t:' + (m.text || ''));
            if (isNew || !bubble || row._chatBodyKey !== bodyKey) {
                if (!bubble) { bubble = document.createElement('div'); row.insertBefore(bubble, row.firstChild); }
                bubble.className = 'chat-bubble' + (m.deleted ? ' is-deleted' : '');
                if (m.deleted) bubble.innerHTML = CHAT_TRASH_ICON_HTML + 'Mensaje eliminado';
                else bubble.innerHTML = chatRenderBody(m.text);
                row._chatBodyKey = bodyKey;
                if (!isNew && atBottom) {
                    Array.prototype.forEach.call(bubble.querySelectorAll('img'), function(img){ if (!img.complete) newImgs.push(img); });
                }
            }
            /* Context menu en MIS mensajes no borrados (onX → sin duplicar). */
            bubble.oncontextmenu = (mine && !m.deleted)
                ? function(ev) { ev.preventDefault(); openChatMsgMenu(ev.clientX, ev.clientY, m.id, m.text); }
                : null;

            /* Meta (tiempo + editado + ticks): se rehace siempre, no lleva
               imágenes, así que es barato y mantiene los ticks al día. */
            var meta = row.querySelector('.chat-time');
            if (!meta) { meta = document.createElement('div'); meta.className = 'chat-time'; row.appendChild(meta); }
            var timeStr = relTime(m.sentAt || 0);
            var editedHtml = (m.edited && !m.deleted) ? '<span class="chat-edited-tag">(editado)</span>' : '';
            var ticksHtml = '';
            if (mine && !m.deleted) {
                if (m.read)           ticksHtml = '<span class="chat-ticks is-read" title="Leído">✓✓</span>';
                else if (m.delivered) ticksHtml = '<span class="chat-ticks is-delivered" title="Entregado">✓✓</span>';
                else                  ticksHtml = '<span class="chat-ticks is-sent" title="Enviado">✓</span>';
            }
            meta.innerHTML = '<span>' + escHtml(timeStr) + '</span>' + editedHtml + ticksHtml;

            /* Las nuevas se añaden al final (el chat es siempre append). */
            if (isNew) {
                listEl.appendChild(row);
                Array.prototype.forEach.call(row.querySelectorAll('img'), function(img){ if (!img.complete) newImgs.push(img); });
            }
        });

        /* Quitar filas de mensajes que ya no estén (caso raro). */
        Object.keys(existing).forEach(function(id){ if (!seen[id]) existing[id].remove(); });

        if (atBottom) {
            listEl.scrollTop = listEl.scrollHeight;
            /* Mantener el fondo mientras cargan las imágenes/GIFs de los
               mensajes NUEVOS (las viejas ya estaban cargadas y no se
               recrearon). Grace corto; si el usuario hace scroll, paramos. */
            if (newImgs.length) {
                var keepPin = true;
                var until = Date.now() + 4000;
                var stop = function(){ keepPin = false; };
                listEl.addEventListener('wheel', stop, { once: true, passive: true });
                listEl.addEventListener('touchmove', stop, { once: true, passive: true });
                newImgs.forEach(function(img){
                    var pin = function(){ if (keepPin && Date.now() < until) listEl.scrollTop = listEl.scrollHeight; };
                    img.addEventListener('load',  pin, { once: true });
                    img.addEventListener('error', pin, { once: true });
                });
            }
        }
    }

    /* ──────────────────────────────────────────────
       Menu contextual del USUARIO (apodo + silenciar)
       ────────────────────────────────────────────── */
    var __userCtxMenu = null;
    function closeUserCtxMenu() {
        if (__userCtxMenu && __userCtxMenu.parentNode) __userCtxMenu.parentNode.removeChild(__userCtxMenu);
        __userCtxMenu = null;
        document.removeEventListener('click', closeUserCtxMenu);
        document.removeEventListener('contextmenu', closeUserCtxMenu);
    }
    function openUserCtxMenu(x, y, uKey) {
        closeUserCtxMenu();
        __userCtxMenu = document.createElement('div');
        __userCtxMenu.className = 'chat-msg-menu';
        __userCtxMenu.innerHTML =
            '<button data-act="nick" type="button">Cambiar apodo…</button>' +
            '<button data-act="mute" type="button">Silenciar…</button>';
        document.body.appendChild(__userCtxMenu);
        var w = __userCtxMenu.offsetWidth, h = __userCtxMenu.offsetHeight;
        var px = Math.min(x, window.innerWidth  - w - 4);
        var py = Math.min(y, window.innerHeight - h - 4);
        __userCtxMenu.style.left = px + 'px';
        __userCtxMenu.style.top  = py + 'px';
        __userCtxMenu.querySelector('[data-act="nick"]').addEventListener('click', function() {
            closeUserCtxMenu();
            openNicknameDialog(uKey);
        });
        __userCtxMenu.querySelector('[data-act="mute"]').addEventListener('click', function() {
            closeUserCtxMenu();
            openMuteDialog(uKey);
        });
        setTimeout(function() {
            document.addEventListener('click', closeUserCtxMenu);
            document.addEventListener('contextmenu', closeUserCtxMenu);
        }, 0);
    }
    /* Cache local del meta del usuario (nickname + mutedUntil) — se llena
       on-demand al abrir un menú del ctx. */
    var __userMeta = {};
    function fetchUserMeta(uKey, cb) {
        /* get-recent-chats nos da todos; barato. */
        fetch('assets/profile/api.php?action=get-recent-chats', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d && Array.isArray(d.chats)) {
                    d.chats.forEach(function(c) { __userMeta[c.userKey] = c; });
                }
                cb(__userMeta[uKey] || {});
            }).catch(function() { cb(__userMeta[uKey] || {}); });
    }
    function openNicknameDialog(uKey) {
        fetchUserMeta(uKey, function(meta) {
            var u = PROFILE_USERS[uKey] || { label: uKey };
            var cur = meta.nickname || '';
            var bd = document.createElement('div');
            bd.className = 'profile-modal-backdrop';
            bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:10020;display:flex;align-items:center;justify-content:center;';
            bd.innerHTML =
                '<div class="window" style="width:280px;">' +
                    '<div class="title-bar"><div class="title-bar-text">Apodo de ' + escHtml(u.label) + '</div>' +
                        '<div class="title-bar-controls"><button aria-label="Close" type="button" data-act="close"></button></div></div>' +
                    '<div class="window-body" style="padding:10px;">' +
                        '<label style="font-size:11px;display:block;margin-bottom:4px;">Apodo (vacío = quitar):</label>' +
                        '<input type="text" maxlength="60" style="width:100%;font-size:12px;padding:4px 6px;" value="' + escHtml(cur) + '">' +
                        '<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:10px;">' +
                            '<button class="button" type="button" data-act="cancel">Cancelar</button>' +
                            '<button class="button default" type="button" data-act="save">Guardar</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(bd);
            var inp = bd.querySelector('input');
            inp.focus(); inp.select();
            function close() { if (bd.parentNode) bd.parentNode.removeChild(bd); }
            bd.querySelector('[data-act="close"]').addEventListener('click', close);
            bd.querySelector('[data-act="cancel"]').addEventListener('click', close);
            bd.addEventListener('click', function(e) { if (e.target === bd) close(); });
            function save() {
                var v = inp.value.trim();
                fetch('assets/profile/api.php?action=set-chat-nickname', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ with: uKey, nickname: v })
                }).then(function(r){ return r.json(); }).then(function(d) {
                    if (d && d.ok) {
                        __userMeta[uKey] = __userMeta[uKey] || {};
                        __userMeta[uKey].nickname = d.nickname || '';
                        /* Refresca el label en la sidebar. */
                        document.querySelectorAll('.profile-nav-item[data-user="' + uKey + '"] .profile-nav-label').forEach(function(el) {
                            el.textContent = d.nickname || (PROFILE_USERS[uKey] && PROFILE_USERS[uKey].label) || uKey;
                        });
                        /* Si tengo el chat abierto con esta persona, refresca el title-bar. */
                        if (chatWithUser === uKey) {
                            var titleEl = document.getElementById('profile-chat-title-text');
                            if (titleEl) titleEl.textContent = d.nickname || (PROFILE_USERS[uKey] && PROFILE_USERS[uKey].label) || uKey;
                        }
                        close();
                    } else {
                        alert((d && d.error) || 'Error al guardar el apodo');
                    }
                }).catch(function() { alert('Error de red'); });
            }
            bd.querySelector('[data-act="save"]').addEventListener('click', save);
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter')  { e.preventDefault(); save(); }
                if (e.key === 'Escape') { close(); }
            });
        });
    }
    function openMuteDialog(uKey) {
        fetchUserMeta(uKey, function(meta) {
            var u = PROFILE_USERS[uKey] || { label: uKey };
            var muted = meta.mutedUntil > 0;
            var bd = document.createElement('div');
            bd.className = 'profile-modal-backdrop';
            bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:10020;display:flex;align-items:center;justify-content:center;';
            var status = '';
            if (muted) {
                var until = new Date(meta.mutedUntil * 1000);
                var diffDays = (until - new Date()) / 86400000;
                if (diffDays > 365) status = 'Silenciado indefinidamente.';
                else {
                    status = 'Silenciado hasta ' +
                        until.toLocaleDateString('es-ES', { day:'2-digit', month:'short' }) +
                        ' ' + String(until.getHours()).padStart(2,'0') + ':' +
                        String(until.getMinutes()).padStart(2,'0');
                }
            } else {
                status = 'Notificaciones activadas.';
            }
            bd.innerHTML =
                '<div class="window" style="width:280px;">' +
                    '<div class="title-bar"><div class="title-bar-text">Silenciar ' + escHtml(u.label) + '</div>' +
                        '<div class="title-bar-controls"><button aria-label="Close" type="button" data-act="close"></button></div></div>' +
                    '<div class="window-body" style="padding:10px;">' +
                        '<div style="font-size:11px;color:var(--text-faint);margin-bottom:8px;">' + escHtml(status) + '</div>' +
                        '<button class="button" data-mute="off"     type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Activar notificaciones</button>' +
                        '<button class="button" data-mute="1h"      type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Silenciar 1 hora</button>' +
                        '<button class="button" data-mute="8h"      type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Silenciar 8 horas</button>' +
                        '<button class="button" data-mute="24h"     type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Silenciar 24 horas</button>' +
                        '<button class="button" data-mute="1w"      type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Silenciar 1 semana</button>' +
                        '<button class="button" data-mute="forever" type="button" style="display:block;width:100%;margin-bottom:4px;text-align:left;padding:4px 8px;">Silenciar indefinidamente</button>' +
                        '<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px;">' +
                            '<button class="button" type="button" data-act="close">Cerrar</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(bd);
            function close() { if (bd.parentNode) bd.parentNode.removeChild(bd); }
            bd.querySelectorAll('[data-act="close"]').forEach(function(b) { b.addEventListener('click', close); });
            bd.addEventListener('click', function(e) { if (e.target === bd) close(); });
            bd.querySelectorAll('[data-mute]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    fetch('assets/profile/api.php?action=set-chat-mute', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ with: uKey, duration: btn.dataset.mute })
                    }).then(function(r){ return r.json(); }).then(function(d) {
                        if (d && d.ok) {
                            __userMeta[uKey] = __userMeta[uKey] || {};
                            __userMeta[uKey].mutedUntil = d.mutedUntil || 0;
                            close();
                        } else {
                            btn.disabled = false;
                            alert((d && d.error) || 'Error');
                        }
                    }).catch(function() { btn.disabled = false; alert('Error de red'); });
                });
            });
        });
    }

    /* ── Menu contextual edit/delete ── */
    var __chatMsgMenu = null;
    function closeChatMsgMenu() {
        if (__chatMsgMenu && __chatMsgMenu.parentNode) __chatMsgMenu.parentNode.removeChild(__chatMsgMenu);
        __chatMsgMenu = null;
        document.removeEventListener('click', closeChatMsgMenu);
        document.removeEventListener('contextmenu', closeChatMsgMenu);
    }
    function openChatMsgMenu(x, y, msgId, currentText) {
        closeChatMsgMenu();
        __chatMsgMenu = document.createElement('div');
        __chatMsgMenu.className = 'chat-msg-menu';
        __chatMsgMenu.innerHTML =
            '<button data-act="edit"  type="button">Editar mensaje</button>' +
            '<button data-act="delete" class="danger" type="button">Eliminar mensaje</button>';
        document.body.appendChild(__chatMsgMenu);
        /* Posicionado fixed; clamp dentro del viewport. */
        var w = __chatMsgMenu.offsetWidth, h = __chatMsgMenu.offsetHeight;
        var px = Math.min(x, window.innerWidth  - w - 4);
        var py = Math.min(y, window.innerHeight - h - 4);
        __chatMsgMenu.style.left = px + 'px';
        __chatMsgMenu.style.top  = py + 'px';
        __chatMsgMenu.querySelector('[data-act="edit"]').addEventListener('click', function() {
            closeChatMsgMenu();
            beginEditChatMessage(msgId, currentText);
        });
        __chatMsgMenu.querySelector('[data-act="delete"]').addEventListener('click', function() {
            closeChatMsgMenu();
            deleteChatMessage(msgId);
        });
        /* Click fuera = cierra. defer para no capturar el mismo click. */
        setTimeout(function() {
            document.addEventListener('click', closeChatMsgMenu);
            document.addEventListener('contextmenu', closeChatMsgMenu);
        }, 0);
    }

    function beginEditChatMessage(msgId, currentText) {
        var row = document.querySelector('.chat-msg[data-msg-id="' + msgId + '"]');
        if (!row) return;
        var bubble = row.querySelector('.chat-bubble');
        if (!bubble) return;
        var original = bubble.textContent;
        var box = document.createElement('div');
        box.className = 'chat-edit-row';
        box.innerHTML =
            '<input type="text" maxlength="2000" value="">' +
            '<button class="button" type="button" data-act="save">✓</button>' +
            '<button class="button" type="button" data-act="cancel">✕</button>';
        var inp = box.querySelector('input');
        inp.value = currentText;
        bubble.style.display = 'none';
        row.insertBefore(box, bubble);
        inp.focus();
        inp.select();
        function cancel() {
            if (box.parentNode) box.parentNode.removeChild(box);
            bubble.style.display = '';
        }
        function save() {
            var newText = inp.value.trim();
            if (!newText || newText === original) { cancel(); return; }
            fetch('assets/profile/api.php?action=edit-message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messageId: msgId, text: newText })
            }).then(function(r) { return r.json(); })
              .then(function(d) {
                  cancel();
                  if (d && d.ok) {
                      chatLastSig = '';   /* fuerza re-render */
                      loadChatMessages();
                  }
              }).catch(cancel);
        }
        box.querySelector('[data-act="save"]').addEventListener('click', save);
        box.querySelector('[data-act="cancel"]').addEventListener('click', cancel);
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter')   { e.preventDefault(); save(); }
            if (e.key === 'Escape')  { e.preventDefault(); cancel(); }
        });
    }

    function deleteChatMessage(msgId) {
        function doDelete() {
            fetch('assets/profile/api.php?action=delete-message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messageId: msgId })
            }).then(function(r) { return r.json(); })
              .then(function(d) {
                  if (d && d.ok) {
                      chatLastSig = '';
                      loadChatMessages();
                  }
              }).catch(function(){});
        }
        var msg = '¿Eliminar este mensaje?\n\nQuien lo haya visto verá "Mensaje eliminado".';
        if (window.win98Confirm) {
            window.win98Confirm(msg, 'Eliminar mensaje', doDelete, function(){});
        } else if (confirm(msg)) {
            doDelete();
        }
    }

    function sendChatMessage() {
        if (!chatWithUser) return;
        var input = document.getElementById('profile-chat-input');
        if (!input) return;
        var text = input.value.trim();
        if (!text) return;
        input.disabled = true;
        fetch('assets/profile/api.php?action=send-message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ to: chatWithUser, text: text })
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              input.disabled = false;
              if (d && d.error) { alert(d.error); return; }
              input.value = '';
              loadChatMessages();
              input.focus();
          }).catch(function() { input.disabled = false; });
    }

    (function setupChat() {
        var closeBtn = document.getElementById('profile-chat-close');
        if (closeBtn) closeBtn.addEventListener('click', closeChat);
        /* Botón "Enviar" eliminado del desktop — el envío se hace solo
           con Enter en el input. */
        var input = document.getElementById('profile-chat-input');
        if (input) input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatMessage(); }
        });
        /* Emoji picker — paleta de emotes comunes. Click en uno → append
           al input. Click fuera del panel o re-toggle del botón → cierra. */
        var EMOTES = ['😀','😂','🥲','😅','😍','😘','🤩','🤔','🙄','😎',
                      '😭','😡','🥺','😴','🤤','🤯','🤗','🫶','🫡','👀',
                      '🥳','😏','😉','🙃','😬','😱','🤣','😋','😇','🤧',
                      '👍','👎','🙌','👏','💪','🙏','🤝','✌️','🤞','👌',
                      '❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💖',
                      '🔥','✨','💯','⭐','🎉','🎊','🎵','🎶','🎁','☕',
                      '🍕','🍔','🍿','🍻','🍰','🌹','🌸','🌈','☀️','🌙'];
        var panel  = document.getElementById('profile-chat-emoji-panel');
        var btn    = document.getElementById('profile-chat-emoji-btn');
        var emojiPane = document.getElementById('pf-pane-emoji');
        var gifsPane  = document.getElementById('pf-pane-gifs');
        var tabsEl    = document.getElementById('pf-picker-tabs');
        var gifInput  = document.getElementById('pf-gif-search');
        var gifResults = document.getElementById('pf-gif-results');
        if (panel && btn && input) {
            /* Render del grid de emotes (una sola vez). */
            emojiPane.innerHTML = EMOTES.map(function(e){
                return '<button type="button" class="pf-emote" style="background:transparent;border:0;font-size:18px;padding:2px 4px;cursor:pointer;font-family:inherit;" data-em="' + e + '">' + e + '</button>';
            }).join('');
            panel.style.display = 'none';
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
            });
            emojiPane.addEventListener('click', function(e){
                var b = e.target.closest('button[data-em]');
                if (!b) return;
                var em = b.getAttribute('data-em');
                /* Inserta en la posición del cursor. */
                var start = input.selectionStart || input.value.length;
                var end   = input.selectionEnd   || input.value.length;
                input.value = input.value.slice(0, start) + em + input.value.slice(end);
                input.focus();
                input.setSelectionRange(start + em.length, start + em.length);
            });
            /* Tabs Emoji/GIFs. Hidden + display explícito porque
               cuando un pane usa flex internamente (el de GIFs), el
               atributo `hidden` se rompe si el style inline declara
               display:flex. Aquí controlamos display por JS para no
               depender del orden de cascade. */
            tabsEl.addEventListener('click', function(e){
                var t = e.target.closest('button[data-tab]');
                if (!t) return;
                var which = t.dataset.tab;
                tabsEl.querySelectorAll('button').forEach(function(b){ b.classList.toggle('is-active', b === t); b.style.background = (b === t) ? 'var(--win-body-bg, var(--win-bg, silver))' : 'var(--win-bg, silver)'; b.style.fontWeight = (b === t) ? 'bold' : 'normal'; });
                emojiPane.hidden = (which !== 'emoji');
                gifsPane.hidden  = (which !== 'gifs');
                emojiPane.style.display = (which === 'emoji') ? 'block' : 'none';
                gifsPane.style.display  = (which === 'gifs')  ? 'flex'  : 'none';
                if (which === 'gifs' && !gifsPane.dataset.loaded) {
                    gifsPane.dataset.loaded = '1';
                    pfSearchGifs('');
                }
            });
            /* GIPHY: búsqueda + render. Click en GIF → inyecta su URL en
               el input y dispara submit del form. */
            var _pfGifDebounce = null, _pfGifSeq = 0;
            function pfSearchGifs(q) {
                var seq = ++_pfGifSeq;
                gifResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;font-size:11px;color:var(--text-faint,#888);">Buscando…</div>';
                fetch('assets/profile/api.php?action=tenor-search&q=' + encodeURIComponent(q) + '&limit=24')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (seq !== _pfGifSeq) return;
                        if (d && d.code === 'tenorNotConfigured') {
                            gifResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:10px;font-size:11px;color:var(--text-faint,#888);">Búsqueda de GIFs no configurada. Añade GIPHY_API_KEY en .env.</div>';
                            return;
                        }
                        if (!d || !d.gifs || !d.gifs.length) {
                            gifResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;font-size:11px;color:var(--text-faint,#888);">Sin resultados.</div>';
                            return;
                        }
                        gifResults.innerHTML = d.gifs.map(function(g){
                            return '<img src="' + escHtml(g.preview) + '" data-url="' + escHtml(g.url) + '" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:100%;height:70px;object-fit:cover;cursor:pointer;background:rgba(0,0,0,0.08);border:1px solid var(--bezel-dark-1,#808080);">';
                        }).join('');
                    })
                    .catch(function(){
                        if (seq !== _pfGifSeq) return;
                        gifResults.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;font-size:11px;color:var(--error-text,#c00);">Error de red.</div>';
                    });
            }
            gifInput.addEventListener('input', function(){
                clearTimeout(_pfGifDebounce);
                _pfGifDebounce = setTimeout(function(){ pfSearchGifs(gifInput.value.trim()); }, 280);
            });
            gifResults.addEventListener('click', function(e){
                var img = e.target.closest('img[data-url]');
                if (!img) return;
                var url = img.dataset.url;
                panel.style.display = 'none';
                input.value = url;
                /* Disparar submit del form que contiene el input. */
                var form = input.closest('form');
                if (form) {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                } else {
                    /* Fallback si no hay form: simular Enter en el input. */
                    var ev = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true });
                    input.dispatchEvent(ev);
                }
            });
            /* Click fuera del panel cierra. */
            document.addEventListener('click', function(e){
                if (panel.style.display === 'none') return;
                if (!panel.contains(e.target) && e.target !== btn) panel.style.display = 'none';
            });
        }
    })();

    function addItemToOwnProfile(cat, item) {
        if (!viewingUser || !ownLists) return;
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
        var titleLower = (copy.title || '').toLowerCase().trim();
        if (!titleLower) return;
        var dup = (ownLists[cat] || []).some(function(it) {
            return (it.title || '').toLowerCase().trim() === titleLower;
        });
        if (dup) {
            if (window.notifSystem) {
                window.notifSystem.show({
                    id:      'dup_' + Date.now(),
                    type:    'info',
                    title:   'Ya en tu perfil',
                    message: '"' + copy.title + '" ya está en tu lista.'
                });
            }
            return;
        }
        if (!ownLists[cat]) ownLists[cat] = [];
        ownLists[cat].push(copy);
        var newIdx = ownLists[cat].length - 1;
        fetch('assets/profile/api.php?action=save-lists', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category: cat, items: ownLists[cat] })
        }).then(function(r) { return r.json(); })
          .then(function(d) {
              if (d && d.error) { alert(d.error); return; }
              if (window.notifSystem) {
                  window.notifSystem.show({
                      id:      'added_' + Date.now(),
                      type:    'info',
                      title:   cat === 'music' ? 'Música añadida' : 'Añadido a tu perfil',
                      message: cat === 'music'
                          ? '"' + copy.title + '" añadido a tu lista.'
                          : '"' + copy.title + '" añadido como pendiente.'
                  });
              }
              if (cat === 'music') {
                  showAddedMusicReviewPrompt(newIdx, copy);
              }
          }).catch(function() { alert('Error al guardar'); });
    }

    function showAddedMusicReviewPrompt(newIdx, item) {
        var prompt = document.getElementById('profile-review-prompt');
        if (!prompt) return;
        document.getElementById('profile-review-prompt-msg').textContent =
            '¿Añadir una reseña para "' + item.title + '"?';
        prompt.style.display = 'block';
        prompt.style.left = Math.round((window.innerWidth  - prompt.offsetWidth)  / 2) + 'px';
        prompt.style.top  = Math.round((window.innerHeight - prompt.offsetHeight) / 2) + 'px';
        var yesBtn = document.getElementById('profile-review-prompt-yes');
        var noBtn  = document.getElementById('profile-review-prompt-no');
        var newYes = yesBtn.cloneNode(true); yesBtn.parentNode.replaceChild(newYes, yesBtn);
        var newNo  = noBtn.cloneNode(true);  noBtn.parentNode.replaceChild(newNo, noBtn);
        newYes.addEventListener('click', function() {
            prompt.style.display = 'none';
            /* Si estamos en la ventana standalone de OTRO usuario, NO
               salimos de su perfil: pedimos a MI app de perfil (incluida
               en el shell) que abra la reseña. Así la ventana del otro
               usuario se queda como estaba. */
            if (window.__PERFIL_STANDALONE) {
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type:  'perfil-open-own-music-review',
                            title: item.title || '',
                            mtype: item.type || ''
                        }, '*');
                    }
                } catch (_) {}
                return;
            }
            if (viewingUser) exitViewingUser();
            showMusicView();
            showMusicReviewWindow(newIdx);
        });
        newNo.addEventListener('click', function() {
            prompt.style.display = 'none';
        });
    }

    function exitViewingUser() {
        viewingUser = null;
        if (ownLists) { lists = ownLists; ownLists = null; }
        /* Restaura el tema del propio viewer (quita los CSS vars
           inyectados del visitado). */
        clearVisitedUserTheme();
        /* Restaura cabecera al usuario propio */
        var avFrame = document.querySelector('#profile-avatar-col .profile-avatar-frame');
        if (avFrame) {
            avFrame.innerHTML = <?php
                $ownAv = isset($profileImg) ? $profileImg : '';
                if ($ownAv) {
                    echo json_encode('<img src="' . htmlspecialchars($ownAv) . '" alt="" class="profile-avatar-img">');
                } else {
                    echo json_encode('<div class="profile-avatar-placeholder">👤</div>');
                }
            ?>;
            if (window.__attachPresenceDot) window.__attachPresenceDot(avFrame, currentSessionUser);
        }
        var nameEl = document.getElementById('profile-username');
        if (nameEl) nameEl.textContent = <?php echo json_encode($desktopLabel); ?>;
        var newPostEl = document.getElementById('profile-new-post');
        if (newPostEl) newPostEl.style.display = '';
        var followBtn = document.getElementById('profile-follow-btn');
        if (followBtn) followBtn.style.display = 'none';
        var listasHdText = document.getElementById('profile-listas-heading-text');
        if (listasHdText) listasHdText.textContent = 'Mis Listas';
        var socialSec = document.getElementById('profile-social-section');
        if (socialSec) socialSec.style.display = '';
        var melonSec  = document.getElementById('profile-melon-section');
        if (melonSec)  melonSec.style.display  = '';
        var notifBtn = document.getElementById('profile-notif-btn');
        if (notifBtn) notifBtn.style.display = '';
        updateCounts();
        loadProfile();
    }

    /* ──── Music view ──── */
    function showMusicView() {
        document.dispatchEvent(new Event('profile-edit-cancel'));
        currentCat = 'music';
        document.getElementById('profile-view-default').style.display = 'none';
        document.getElementById('profile-view-cat').style.display = 'none';
        document.getElementById('profile-view-social').style.display = 'none';
        var melonV = document.getElementById('profile-view-melon');
        if (melonV) melonV.style.display = 'none';
        document.getElementById('profile-sidebar-back').style.display = 'block';
        document.getElementById('profile-info-edit-btn').style.display = 'none';
        var mv = document.getElementById('profile-view-music');
        if (mv) mv.style.display = 'flex';
        var musicAddBtn = document.getElementById('music-catview-add-btn');
        if (musicAddBtn) musicAddBtn.style.display = viewingUser ? 'none' : '';
        var musicTitleEl = document.getElementById('music-catview-title');
        applyTopbarUser('music-catview-avatar-wrap', musicTitleEl, '🎵', 'Música');
        /* applyTopbarUser ha hecho textContent='🎵 Música' — sobreescribimos
           con innerHTML para meter la imagen PNG en lugar del emoji. */
        if (musicTitleEl) musicTitleEl.innerHTML = '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Música';
        renderMusicDestacados();
        renderMusicView(currentMusicTab);
    }

    function renderMusicDestacados() {
        var slotsEl = document.getElementById('music-catview-destacados-slots');
        if (!slotsEl) return;
        slotsEl.innerHTML = '';
        var featured = lists.music.filter(function(i) { return i.featured; });
        for (var s = 0; s < 3; s++) {
            var item = featured[s] || null;
            var slot = document.createElement('div');
            slot.className = 'profile-encurso-slot' + (item ? ' filled' : '');

            var tb = document.createElement('div');
            tb.className = 'profile-encurso-slot-tb music-slot-tb';
            if (item) {
                var tbTitle = document.createElement('div');
                tbTitle.className = 'music-slot-tb-title';
                tbTitle.textContent = item.title;
                tb.appendChild(tbTitle);
                if (item.artist) {
                    var tbArtist = document.createElement('div');
                    tbArtist.className = 'music-slot-tb-artist';
                    tbArtist.textContent = item.artist;
                    tb.appendChild(tbArtist);
                }
                if (item.review && item.review.stars) {
                    var tbStars = document.createElement('div');
                    tbStars.className = 'music-slot-tb-stars';
                    tbStars.innerHTML = makeStarsHtml(item.review.stars, 5) +
                        '<span class="profile-star-num" style="font-size:8px;margin-left:2px;vertical-align:middle;">' + item.review.stars + '</span>';
                    if (item.review.comment) {
                        var tbBubble = document.createElement('span');
                        tbBubble.innerHTML = '<img src="assets/img/appIcons/chatIcon.png" alt="" style="width:10px;height:10px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
                        tbBubble.style.cssText = 'margin-left:3px;cursor:pointer;';
                        (function(r, it) { tbBubble.addEventListener('click', function(e) { e.stopPropagation(); showReviewView(r, it); }); })(item.review, item);
                        tbStars.appendChild(tbBubble);
                    }
                    tb.appendChild(tbStars);
                }
            } else {
                tb.textContent = '—';
            }
            slot.appendChild(tb);

            var body = document.createElement('div');
            body.className = 'profile-encurso-slot-body';
            body.style.position = 'relative';
            if (item && item.image) {
                var img = document.createElement('img');
                img.src = item.image; img.alt = item.title;
                img.setAttribute('data-cover-cat', 'music');
                img.setAttribute('data-cover-title', item.title);
                (function(b, it) { img.onerror = function() { b.textContent = it.type === 'album' ? '💿' : '🎵'; }; })(body, item);
                body.appendChild(img);
            } else if (item) {
                body.textContent = item.type === 'album' ? '💿' : '🎵';
            }
            if (item) {
                var collabKeys = [];
                if (item.sharedFrom) collabKeys.push(item.sharedFrom);
                if (item.collaborators && item.collaborators.length) {
                    item.collaborators.forEach(function(k) {
                        if (k !== item.sharedFrom) collabKeys.push(k);
                    });
                }
                if (collabKeys.length) {
                    var strip = document.createElement('div');
                    strip.className = 'profile-gallery-collabs';
                    collabKeys.forEach(function(uKey) {
                        var uInfo = PROFILE_USERS[uKey]; if (!uInfo) return;
                        var avFrame = document.createElement('div'); avFrame.className = 'profile-avatar-frame'; avFrame.title = uInfo.label;
                        if (uInfo.image) { var avImg = document.createElement('img'); avImg.src = uInfo.image; avImg.alt = uInfo.label; avFrame.appendChild(avImg); }
                        strip.appendChild(avFrame);
                    });
                    body.appendChild(strip);
                }
            }
            slot.appendChild(body);
            if (item) {
                (function(it) {
                    slot.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        if (viewingUser) {
                            showCtxMenu(e.clientX, e.clientY, [
                                { label: '▶ Reproducir',         action: function() { playMusicItem(it); } },
                                { label: '+ Añadir a mi perfil', action: function() { addItemToOwnProfile('music', it); } }
                            ]);
                            return;
                        }
                        showCtxMenu(e.clientX, e.clientY, [
                            { label: '▶ Reproducir', action: function() { playMusicItem(it); } },
                            { label: '★ Quitar de destacados', action: function() {
                                var idx = lists.music.findIndex(function(x){ return x.id === it.id; });
                                if (idx !== -1) { lists.music[idx].featured = false; saveCategory('music'); renderMusicDestacados(); renderMusicView(currentMusicTab); }
                            }},
                            { label: '👥 Colaboradores', action: function() { showCollabDialog('music', it); }}
                        ]);
                    });
                })(item);
            }
            slotsEl.appendChild(slot);
        }
    }

    function playMusicItem(item) {
        updatePlayerTitle('⏳ Cargando…');
        /* WRAPPED tracking: si es un ÁLBUM, lo registramos como evento
           de álbum reproducido. Fire-and-forget; los tracks
           individuales también se loguean via el reproductor más
           abajo, esto solo afecta al ranking de álbumes en Wrapped. */
        if (item && item.type === 'album') {
            try {
                fetch('assets/music/wrapped-api.php?action=log-album', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        albumTitle:     item.title          || '',
                        artist:         item.artist         || '',
                        actionType:     'play',
                        ytPlaylistId:   item.ytPlaylistId   || '',
                        spotifyAlbumId: item.spotifyAlbumId || '',
                        coverUrl:       item.image          || '',
                    }),
                }).catch(function(){});
            } catch (_) {}
        }
        fetch('assets/profile/api.php?action=play-music-item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                itemType:       item.type,
                title:          item.title          || '',
                artist:         item.artist         || '',
                ytId:           item.ytId           || '',
                spotifyId:      item.spotifyId      || '',
                ytPlaylistId:   item.ytPlaylistId   || '',
                spotifyAlbumId: item.spotifyAlbumId || '',
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error || !data.tracks || !data.tracks.length) {
                updatePlayerTitle(item.title || '—');
                return;
            }
            currentPlaylistId = null;
            currentPlaylistHasCollabs = false;
            playlist.length = 0;
            data.tracks.forEach(function(t) { playlist.push(t); });
            currentTrack = 0;
            updateTrackUI(0);
            updatePlayerTitle(item.title + (item.artist ? ' – ' + item.artist : ''));
            /* Si reproducimos un ALBUM y conocemos su spotifyAlbumId,
               inyectamos el contexto en el reproductor para que el
               título de la canción sea clickable y abra el viewer del
               álbum correcto (mismo del item, no uno random buscado a
               partir del title del primer track). Para songs, el flow
               normal de updateTrackUI → resolveAndShowAlbum hace su
               trabajo via find-album. */
            /* Aceptamos el item solo si NO arrastra spotifyAlbumId ni
               un albumKey:spotify:* — esos quedan ignorados a propósito
               porque la API de Spotify ya no se usa y el fallback en
               album-tracks era poco fiable con artists heredados.
               Si tiene una key itunes:/deezer: la usamos directa. Si
               no tiene NINGUNA key utilizable, dejamos al flow normal
               (resolveAndShowAlbum) que find-album lo resuelva fresh
               por title+artist de la canción primero. */
            var hasUsableKey = item.type === 'album'
                            && typeof item.albumKey === 'string'
                            && item.albumKey
                            && item.albumKey.indexOf('spotify:') !== 0;
            if (hasUsableKey
                && typeof window.setReproductorAlbumContext === 'function') {
                window.setReproductorAlbumContext({
                    albumKey:    item.albumKey,
                    albumName:   item.title  || '',
                    albumArtist: item.artist || '',
                    image:       item.image  || '',
                });
            }
            if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
                ytPlayer.loadVideoById(playlist[0].videoId);
            }
        })
        .catch(function() { updatePlayerTitle(item.title || '—'); });
    }

    function renderMusicView(tab) {
        currentMusicTab = tab;
        document.querySelectorAll('.music-tab').forEach(function(t) {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        var listEl = document.getElementById('music-list');
        if (!listEl) return;
        listEl.innerHTML = '';
        var items = lists.music.filter(function(i) { return i.type === (tab === 'songs' ? 'song' : 'album'); });
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'music-list-empty';
            empty.textContent = 'Sin elementos';
            listEl.appendChild(empty);
            return;
        }
        items.forEach(function(item) {
            /* idx no se captura: lists.music puede haber sido reemplazado
               por la respuesta de saveCategory. Resolvemos por id en cada click. */
            var row = document.createElement('div');
            row.className = 'music-list-row' + (item.featured ? ' music-list-featured' : '');

            var cover = document.createElement('div');
            cover.className = 'music-list-cover';
            if (item.image) {
                var img = document.createElement('img');
                img.src = item.image; img.alt = item.title;
                img.setAttribute('data-cover-cat', 'music');
                img.setAttribute('data-cover-title', item.title);
                (function(c, it) { img.onerror = function() { c.innerHTML = '<div class="music-list-cover-ph">' + (it.type === 'album' ? '💿' : '🎵') + '</div>'; }; })(cover, item);
                cover.appendChild(img);
            } else {
                cover.innerHTML = '<div class="music-list-cover-ph">' + (item.type === 'album' ? '💿' : '🎵') + '</div>';
            }
            row.appendChild(cover);

            var info = document.createElement('div');
            info.className = 'music-list-info';
            var titleEl = document.createElement('div');
            titleEl.className = 'music-list-title';
            titleEl.textContent = item.title; titleEl.title = item.title;
            info.appendChild(titleEl);
            var artistEl = document.createElement('div');
            artistEl.className = 'music-list-artist';
            artistEl.textContent = item.artist || '—';
            info.appendChild(artistEl);
            if (item.review && item.review.stars) {
                var starsEl = document.createElement('div');
                starsEl.className = 'music-list-stars profile-gallery-tb-stars';
                starsEl.innerHTML = makeStarsHtml(item.review.stars, 5) + '<span class="profile-star-num" style="font-size:9px;margin-left:2px;vertical-align:middle;">' + item.review.stars + '</span>';
                info.appendChild(starsEl);
            }
            row.appendChild(info);

            var right = document.createElement('div');
            right.className = 'music-list-right';
            if (item.review && item.review.comment) {
                var bubble = document.createElement('span');
                bubble.className = 'profile-gallery-tb-bubble';
                bubble.innerHTML = '<img src="assets/img/appIcons/chatIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
                (function(r, it) { bubble.addEventListener('click', function(e) { e.stopPropagation(); showReviewView(r, it); }); })(item.review, item);
                right.appendChild(bubble);
            }
            if (item.featured) {
                var featBadge = document.createElement('span');
                featBadge.className = 'music-featured-badge'; featBadge.textContent = '★';
                right.appendChild(featBadge);
            }
            var collabKeys = [];
            if (item.sharedFrom) collabKeys.push(item.sharedFrom);
            if (item.collaborators && item.collaborators.length) {
                item.collaborators.forEach(function(k) {
                    if (k !== item.sharedFrom) collabKeys.push(k);
                });
            }
            if (collabKeys.length) {
                var strip = document.createElement('div'); strip.className = 'music-list-collabs';
                collabKeys.forEach(function(uKey) {
                    var uInfo = PROFILE_USERS[uKey]; if (!uInfo) return;
                    var av = document.createElement('div'); av.className = 'profile-avatar-frame music-list-av'; av.title = uInfo.label;
                    if (uInfo.image) { var avImg = document.createElement('img'); avImg.src = uInfo.image; avImg.alt = uInfo.label; av.appendChild(avImg); }
                    strip.appendChild(av);
                });
                right.appendChild(strip);
            }
            row.appendChild(right);

            (function(it) {
                /* Resolver el índice por id en cada click — `lists.music`
                   puede haber sido reemplazado por la respuesta canónica de
                   saveCategory tras un save anterior. */
                function curIdx(){
                    return lists.music.findIndex(function(x){ return x.id === it.id; });
                }
                row.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    if (viewingUser) {
                        showCtxMenu(e.clientX, e.clientY, [
                            { label: '▶ Reproducir',          action: function() { playMusicItem(it); } },
                            { label: '+ Añadir a mi perfil',  action: function() { addItemToOwnProfile('music', it); } }
                        ]);
                        return;
                    }
                    var isCollab = !!it.sharedFrom;
                    var menuItems = [];
                    menuItems.push({ label: '▶ Reproducir', action: function() { playMusicItem(it); } });
                    var featuredCount = lists.music.filter(function(x) { return x.featured; }).length;
                    if (it.featured) {
                        menuItems.push({ label: '★ Quitar de destacados', action: function() {
                            var i = curIdx(); if (i === -1) return;
                            lists.music[i].featured = false; saveCategory('music'); renderMusicDestacados(); renderMusicView(currentMusicTab);
                        }});
                    } else {
                        menuItems.push({ label: '★ Destacar', disabled: featuredCount >= 3, action: function() {
                            var i = curIdx(); if (i === -1) return;
                            lists.music[i].featured = true; saveCategory('music'); renderMusicDestacados(); renderMusicView(currentMusicTab);
                        }});
                    }
                    var reviewLabel = (it.review && it.review.stars) ? '✏ Editar' : '✏ Añadir reseña';
                    menuItems.push({ label: reviewLabel, action: function() {
                        var i = curIdx(); if (i !== -1) showMusicReviewWindow(i);
                    }});
                    menuItems.push({ label: '👥 Colaboradores', action: function() { showCollabDialog('music', it); }});
                    if (isCollab) {
                        menuItems.push({ label: '🚪 Abandonar actividad', action: function() {
                            confirmFn('¿Abandonar "' + it.title + '"?', 'Abandonar', function() {
                                fetch('assets/profile/api.php?action=leave-collab', {
                                    method: 'POST', headers: {'Content-Type':'application/json'},
                                    body: JSON.stringify({ action: 'leave', category: 'music', itemId: it.id })
                                }).then(function(r) { return r.json(); }).then(function(d) {
                                    if (d.ok) { loadLists(function() { updateCounts(); renderMusicView(currentMusicTab); renderMusicDestacados(); }); }
                                }).catch(function(){});
                            });
                        }});
                    } else {
                        menuItems.push({ label: '✕ Eliminar', action: function() {
                            confirmFn('¿Eliminar "' + it.title + '"?', 'Eliminar', function() {
                                var i = curIdx(); if (i === -1) return;
                                var removed = lists.music[i];
                                if (typeof window.eliminarMomentoDeItem === 'function') {
                                    window.eliminarMomentoDeItem('music', removed);
                                }
                                lists.music.splice(i, 1); saveCategory('music'); updateCounts(); renderMusicView(currentMusicTab); renderMusicDestacados();
                            });
                        }});
                    }
                    showCtxMenu(e.clientX, e.clientY, menuItems);
                });
            })(item);

            listEl.appendChild(row);
        });
    }

    function showMusicReviewWindow(itemIdx) {
        var win = document.getElementById('profile-review-window');
        var starsEl = document.getElementById('profile-review-stars');
        var commentEl = document.getElementById('profile-review-comment');
        var item = lists.music[itemIdx];
        if (!item) return;
        /* id estable para re-resolver el ítem en submit/delete, por si
           lists.music fue reemplazado por un saveCategory intermedio. */
        var itemId = item.id;
        document.getElementById('profile-review-window-title').textContent = '✏ ' + item.title;
        commentEl.value = (item.review && item.review.comment) ? item.review.comment : '';
        var sel = (item.review && item.review.stars) ? item.review.stars : 0;
        /* Edición del item: título + imagen (música no usa duración manual). */
        var mTitleEl = document.getElementById('profile-review-title');
        var mImageEl = document.getElementById('profile-review-image');
        document.getElementById('profile-review-rt-row').style.display = 'none';
        document.getElementById('profile-review-eps-row').style.display = 'none';
        /* La música no usa el campo de PDF (es solo de libros). */
        document.getElementById('profile-review-pdf-row').style.display = 'none';
        mTitleEl.value = item.title || '';
        mImageEl.value = item.image || '';
        var numEl = document.getElementById('profile-review-stars-num');
        numEl.textContent = sel > 0 ? sel : '';
        function setStarDisp(el, val, pos) {
            if (val >= pos) { el.innerHTML = '★'; el.style.clipPath = ''; el.style.opacity = ''; }
            else if (val >= pos - 0.5) { el.innerHTML = '★'; el.style.clipPath = 'inset(0 50% 0 0)'; el.style.opacity = ''; }
            else { el.innerHTML = '☆'; el.style.clipPath = ''; el.style.opacity = ''; }
        }
        starsEl.innerHTML = '';
        for (var i = 1; i <= 5; i++) {
            var s = document.createElement('span'); s.style.cssText = 'display:inline-block;position:relative;width:1.1em;cursor:pointer;';
            setStarDisp(s, sel, i);
            (function(pos) {
                s.addEventListener('mousemove', function(e) {
                    var half = e.offsetX < s.offsetWidth / 2, v = half ? pos - 0.5 : pos;
                    for (var j = 0; j < starsEl.children.length; j++) setStarDisp(starsEl.children[j], v, j + 1);
                    numEl.textContent = v;
                });
                s.addEventListener('click', function(e) {
                    sel = (e.offsetX < s.offsetWidth / 2) ? pos - 0.5 : pos;
                    for (var j = 0; j < starsEl.children.length; j++) setStarDisp(starsEl.children[j], sel, j + 1);
                    numEl.textContent = sel;
                });
                s.addEventListener('mouseleave', function() {
                    for (var j = 0; j < starsEl.children.length; j++) setStarDisp(starsEl.children[j], sel, j + 1);
                    numEl.textContent = sel > 0 ? sel : '';
                });
            })(i);
            starsEl.appendChild(s);
        }
        /* Reset al tamaño base — las ventanas de reseñas siempre se
           abren con el tamaño mínimo (no persiste el resize anterior). */
        win.style.width = ''; win.style.height = '';
        win.style.display = 'flex';
        win.style.left = Math.round((window.innerWidth  - win.offsetWidth)  / 2) + 'px';
        win.style.top  = Math.round((window.innerHeight - win.offsetHeight) / 2) + 'px';
        function closeWin() { win.style.display = 'none'; }
        var closeBtn = document.getElementById('profile-review-window-close');
        var newClose = closeBtn.cloneNode(true); closeBtn.parentNode.replaceChild(newClose, closeBtn);
        newClose.addEventListener('click', closeWin);
        var cancelBtn = document.getElementById('profile-review-window-cancel');
        var newCancel = cancelBtn.cloneNode(true); cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
        newCancel.addEventListener('click', closeWin);
        var submitBtn = document.getElementById('profile-review-window-submit');
        var newSubmit = submitBtn.cloneNode(true); submitBtn.parentNode.replaceChild(newSubmit, submitBtn);
        newSubmit.addEventListener('click', function() {
            var mi = lists.music.findIndex(function(x){ return x.id === itemId; });
            if (mi === -1) { closeWin(); return; }
            var newTitle = mTitleEl.value.trim();
            if (newTitle) lists.music[mi].title = newTitle;
            lists.music[mi].image = mImageEl.value.trim();
            if (sel > 0) {
                lists.music[mi].review = { stars: sel, comment: commentEl.value.trim(), reviewedAt: Math.floor(Date.now() / 1000) };
                notifyReviewToFollowers('music', lists.music[mi].title, lists.music[mi].type);
            } else {
                delete lists.music[mi].review;
            }
            saveCategory('music');
            renderMusicView(currentMusicTab); renderMusicDestacados(); closeWin();
        });
        var deleteBtn = document.getElementById('profile-review-window-delete');
        var newDelete = deleteBtn.cloneNode(true); deleteBtn.parentNode.replaceChild(newDelete, deleteBtn);
        newDelete.style.display = (item.review && item.review.stars) ? '' : 'none';
        newDelete.addEventListener('click', function() {
            var mi = lists.music.findIndex(function(x){ return x.id === itemId; });
            if (mi !== -1) delete lists.music[mi].review;
            saveCategory('music');
            renderMusicView(currentMusicTab); renderMusicDestacados(); closeWin();
        });
    }

    /* ──── Music add dialog ──── */
    (function() {
        try {
        var dlg           = document.getElementById('music-add-dialog');
        if (!dlg) return;
        var step1         = document.getElementById('music-add-step1');
        var step2         = document.getElementById('music-add-step2');
        var step3         = document.getElementById('music-add-step3');
        var reviewPromptEl= document.getElementById('music-add-review-prompt');
        var reviewFormEl  = document.getElementById('music-add-review-form');
        var reviewStarsEl = document.getElementById('music-add-review-stars');
        var reviewCommentEl = document.getElementById('music-add-review-comment');
        var urlInput      = document.getElementById('music-add-url');
        var artistInput   = document.getElementById('music-add-artist');
        var preview       = document.getElementById('music-add-preview');
        var errEl         = document.getElementById('music-add-error');
        var titleEl       = document.getElementById('music-add-dialog-title');
        var currentType   = null;
        var fetchedMeta   = null;
        var reviewRating  = 0;
        var fetchTimer  = null;

        function openDialog() {
            step1.style.display = 'block'; step2.style.display = 'none'; step3.style.display = 'none';
            currentType = null; fetchedMeta = null; reviewRating = 0;
            titleEl.textContent = '+ Añadir música';
            dlg.style.display = 'block';
            dlg.style.left = Math.round((window.innerWidth  - dlg.offsetWidth)  / 2) + 'px';
            dlg.style.top  = Math.round((window.innerHeight - dlg.offsetHeight) / 2) + 'px';
        }
        function closeDialog() {
            dlg.style.display = 'none'; currentType = null; fetchedMeta = null; reviewRating = 0; clearTimeout(fetchTimer);
        }
        function goToStep3() {
            step2.style.display = 'none'; step3.style.display = 'block';
            reviewPromptEl.style.display = 'block'; reviewFormEl.style.display = 'none';
            reviewRating = 0; reviewCommentEl.value = '';
            titleEl.textContent = '★ Reseña';
        }
        function showReviewFormInline() {
            reviewPromptEl.style.display = 'none'; reviewFormEl.style.display = 'block';
            reviewStarsEl.innerHTML = '';
            var reviewNumEl = document.getElementById('music-add-review-stars-num');
            function setStarDisp(el, val, pos) {
                if (val >= pos) { el.innerHTML = '★'; el.style.clipPath = ''; el.style.opacity = ''; }
                else if (val >= pos - 0.5) { el.innerHTML = '★'; el.style.clipPath = 'inset(0 50% 0 0)'; el.style.opacity = ''; }
                else { el.innerHTML = '☆'; el.style.clipPath = ''; el.style.opacity = ''; }
            }
            function drawReviewStars() {
                reviewStarsEl.innerHTML = '';
                reviewNumEl.textContent = reviewRating > 0 ? reviewRating : '';
                for (var n = 1; n <= 5; n++) {
                    (function(star) {
                        var s = document.createElement('span');
                        s.setAttribute('data-star', star);
                        s.style.cssText = 'display:inline-block;position:relative;width:1.1em;cursor:pointer;';
                        setStarDisp(s, reviewRating, star);
                        s.addEventListener('mousemove', function(e) {
                            var isHalf = e.offsetX < this.offsetWidth / 2;
                            var hover = isHalf ? star - 0.5 : star;
                            reviewStarsEl.querySelectorAll('[data-star]').forEach(function(sp) {
                                setStarDisp(sp, hover, parseFloat(sp.getAttribute('data-star')));
                            });
                            reviewNumEl.textContent = hover;
                        });
                        s.addEventListener('mouseout', function() {
                            reviewStarsEl.querySelectorAll('[data-star]').forEach(function(sp) {
                                setStarDisp(sp, reviewRating, parseFloat(sp.getAttribute('data-star')));
                            });
                            reviewNumEl.textContent = reviewRating > 0 ? reviewRating : '';
                        });
                        s.addEventListener('click', function(e) {
                            var isHalf = e.offsetX < this.offsetWidth / 2;
                            reviewRating = isHalf ? star - 0.5 : star;
                            drawReviewStars();
                        });
                        reviewStarsEl.appendChild(s);
                    })(n);
                }
            }
            drawReviewStars();
        }
        function buildAndSave(withReview) {
            var entry = {
                id:       'music_' + Date.now(),
                type:     currentType,
                title:    fetchedMeta.title,
                artist:   artistInput.value.trim(),
                image:    fetchedMeta.image || '',
                featured: false
            };
            if (fetchedMeta.ytId)           entry.ytId           = fetchedMeta.ytId;
            if (fetchedMeta.spotifyId)      entry.spotifyId      = fetchedMeta.spotifyId;
            if (fetchedMeta.ytPlaylistId)   entry.ytPlaylistId   = fetchedMeta.ytPlaylistId;
            if (fetchedMeta.spotifyAlbumId) entry.spotifyAlbumId = fetchedMeta.spotifyAlbumId;
            if (withReview && reviewRating > 0) {
                entry.review = { stars: reviewRating, comment: reviewCommentEl.value.trim(), reviewedAt: Math.floor(Date.now() / 1000) };
            }
            lists.music.push(entry);
            saveCategory('music');
            if (withReview && reviewRating > 0) notifyReviewToFollowers('music', entry.title, entry.type);
            /* WRAPPED tracking: si el item añadido es un ÁLBUM, lo
               registramos como evento 'import' (lo importó a su
               colección/playlist). Suma puntos en el ranking del
               wrapped, distinto de 'play' que viene de Reproducir. */
            if (currentType === 'album') {
                try {
                    fetch('assets/music/wrapped-api.php?action=log-album', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            albumTitle:     entry.title,
                            artist:         entry.artist,
                            actionType:     'import',
                            ytPlaylistId:   entry.ytPlaylistId   || '',
                            spotifyAlbumId: entry.spotifyAlbumId || '',
                            coverUrl:       entry.image          || '',
                        }),
                    }).catch(function(){});
                } catch (_) {}
            }
            updateCounts();
            renderMusicView(currentMusicTab);
            renderMusicDestacados();
            closeDialog();
        }
        function goToStep2(type) {
            currentType = type; fetchedMeta = null;
            urlInput.value = ''; artistInput.value = '';
            preview.textContent = ''; preview.style.color = ''; errEl.textContent = '';
            /* Song usa PNG musicaIcon; album mantiene su emoji 💿. */
            if (type === 'song') {
                titleEl.innerHTML = '<img src="assets/img/appIcons/musicaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:3px;">Añadir canción';
            } else {
                titleEl.textContent = '💿 Añadir álbum';
            }
            step1.style.display = 'none'; step2.style.display = 'block';
            setTimeout(function() { urlInput.focus(); }, 0);
        }

        document.getElementById('music-add-type-song').addEventListener('click',  function() { goToStep2('song');  });
        document.getElementById('music-add-type-album').addEventListener('click', function() { goToStep2('album'); });
        document.getElementById('music-add-back').addEventListener('click', function() {
            step2.style.display = 'none'; step1.style.display = 'block';
            currentType = null; fetchedMeta = null; clearTimeout(fetchTimer);
            titleEl.textContent = '+ Añadir música';
        });
        document.getElementById('music-add-close').addEventListener('click',  closeDialog);
        document.getElementById('music-add-cancel').addEventListener('click', closeDialog);

        urlInput.addEventListener('input', function() {
            clearTimeout(fetchTimer); fetchedMeta = null;
            preview.style.color = '';
            var raw = urlInput.value.trim();
            if (!raw) { preview.textContent = ''; return; }
            preview.textContent = 'Buscando...';
            fetchTimer = setTimeout(function() {
                fetch('assets/profile/api.php?action=resolve-music-item&url=' + encodeURIComponent(raw) + '&itemType=' + currentType)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { preview.textContent = data.error; preview.style.color = '#c00'; return; }
                    fetchedMeta = data;
                    preview.textContent = (currentType === 'album' ? '💿 ' : '♪ ') + data.title;
                    if (data.artist && !artistInput.value.trim()) artistInput.value = data.artist;
                })
                .catch(function() { preview.textContent = 'Error de conexión'; preview.style.color = '#c00'; });
            }, 500);
        });

        document.getElementById('music-add-submit').addEventListener('click', function() {
            if (!fetchedMeta) { errEl.textContent = 'Espera a que se cargue el enlace.'; return; }
            var lower = fetchedMeta.title.toLowerCase().trim();
            if (lists.music.some(function(it) { return it.title.toLowerCase().trim() === lower; })) {
                errEl.textContent = '⚠ Ya tienes "' + fetchedMeta.title + '" en tu lista.';
                return;
            }
            errEl.textContent = '';
            goToStep3();
        });
        document.getElementById('music-add-review-no').addEventListener('click', function() {
            buildAndSave(false);
        });
        document.getElementById('music-add-review-yes').addEventListener('click', function() {
            showReviewFormInline();
        });
        document.getElementById('music-add-review-back2').addEventListener('click', function() {
            reviewFormEl.style.display = 'none'; reviewPromptEl.style.display = 'block';
            reviewRating = 0;
        });
        document.getElementById('music-add-review-save').addEventListener('click', function() {
            buildAndSave(true);
        });
        urlInput.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDialog(); });

        var addBtn = document.getElementById('music-catview-add-btn');
        if (addBtn) addBtn.addEventListener('click', openDialog);

        document.querySelectorAll('.music-tab').forEach(function(btn) {
            btn.addEventListener('click', function() { renderMusicView(btn.dataset.tab); });
        });
        } catch (e) {
            console.warn('[perfil] music dialog IIFE error:', e);
        }
    })();

    /* ──── Profile info INLINE edit mode ──── */
    (function() {
        try {
        var editBtn   = document.getElementById('profile-info-edit-btn');
        var saveBtn   = document.getElementById('profile-info-edit-save-btn');
        var cancelBtn = document.getElementById('profile-info-edit-cancel-btn');
        var photoIn   = document.getElementById('profile-photo-input');
        var avatarCol = document.getElementById('profile-avatar-col');
        /* Guard: si cualquiera de los elementos críticos no está en el
           DOM (e.g. layout standalone parcial), abortamos el IIFE
           silenciosamente. Antes esto reventaba todo el `<script>` con
           "addEventListener on null" y bloqueaba la inicialización
           subsiguiente del perfil. */
        if (!editBtn || !saveBtn || !cancelBtn || !photoIn || !avatarCol) return;

        /* Diálogo flotante para añadir una conexión social */
        var addDlg    = document.getElementById('profile-social-add-dialog');
        var addClose  = document.getElementById('profile-social-add-close');
        var addCancel = document.getElementById('profile-social-add-cancel');
        var addConfirm= document.getElementById('profile-social-add-confirm');
        var addPlat   = document.getElementById('profile-social-add-platform');
        var addVal    = document.getElementById('profile-social-add-value');

        var FIELDS = ['bio', 'pronouns', 'birthday', 'country'];
        var SOCIALS = ['steam', 'discord', 'twitter', 'instagram'];
        /* Iconos PNG dedicados para cada red social. */
        var SOCIAL_ICONS = {
            steam:     '<img src="assets/img/appIcons/steamIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
            discord:   '<img src="assets/img/appIcons/discordIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
            twitter:   '<img src="assets/img/appIcons/twitterIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
            instagram: '<img src="assets/img/appIcons/instagramIcon.png" alt="" style="width:22px;height:22px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">'
        };
        var SOCIAL_LABELS = { steam: 'Steam', discord: 'Discord', twitter: 'Twitter', instagram: 'Instagram' };
        var SOCIAL_PLACEHOLDERS = {
            steam:     'https://steamcommunity.com/id/...',
            discord:   'usuario o usuario#1234',
            twitter:   '@usuario',
            instagram: '@usuario'
        };

        /* Estado de edición */
        var editing = false;
        var draft   = null;     /* copia mutable de los datos en edición */
        var newPhotoFile = null;/* archivo de foto pendiente de subir (null = sin cambios) */

        function buildMetaInputs(d) {
            var metaEl = document.getElementById('profile-info-meta');
            if (!metaEl) return;
            metaEl.innerHTML = '';
            metaEl.style.flexDirection = 'column';
            metaEl.style.gap = '3px';
            function row(key, ph, type, maxlen) {
                var inp = document.createElement('input');
                inp.type = type || 'text';
                if (maxlen) inp.maxLength = maxlen;
                inp.placeholder = ph;
                inp.value = d[key] || '';
                inp.style.cssText = 'width:100%;box-sizing:border-box;font-size:10px;';
                inp.dataset.field = key;
                inp.addEventListener('input', function() { draft[key] = this.value; });
                metaEl.appendChild(inp);
                return inp;
            }
            row('pronouns', 'pronombres (él/ella/elle...)', 'text',   30);
            /* Cumpleaños (la edad sale de aquí). Un <input type="date">
               vacío no enseña el placeholder, así que vacío va como texto
               ("🎂 cumpleaños", igual que "📍 país") y pasa a fecha al
               entrar. No vuelve a texto al salir: el selector de fecha
               puede quitar el foco y se cerraría. */
            var hoyD = new Date();
            var bdInp = row('birthday', '🎂 cumpleaños', d.birthday ? 'date' : 'text');
            bdInp.title = 'Cumpleaños';
            bdInp.min = '1900-01-01';
            bdInp.max = hoyD.getFullYear() + '-' + String(hoyD.getMonth() + 1).padStart(2, '0') + '-' + String(hoyD.getDate()).padStart(2, '0');
            bdInp.addEventListener('focus', function() {
                if (this.type === 'date') return;
                this.type = 'date';
                try { this.showPicker(); } catch (e) {}
            });
            row('country',  '📍 país',                        'text',   50);
        }

        function buildBioTextarea(d) {
            var bioEl = document.getElementById('profile-info-bio');
            if (!bioEl) return;
            bioEl.innerHTML = '';
            bioEl.style.display = '';
            var ta = document.createElement('textarea');
            ta.maxLength = 200;
            ta.placeholder = 'Cuéntanos algo sobre ti...';
            ta.rows = 3;
            ta.style.cssText = 'width:100%;height:100%;box-sizing:border-box;resize:none;font-size:11px;font-style:italic;background:transparent;';
            ta.value = d.bio || '';
            ta.addEventListener('input', function() { draft.bio = this.value; });
            bioEl.appendChild(ta);
        }

        var MAX_SOCIALS = 4;

        function countSocials(d) {
            var n = 0;
            SOCIALS.forEach(function(k) { if (d[k]) n++; });
            return n;
        }

        function buildSocialEditable(d) {
            var linksEl = document.getElementById('profile-info-links');
            if (!linksEl) return;
            linksEl.innerHTML = '';
            SOCIALS.forEach(function(k) {
                if (!d[k]) return;
                var wrap = document.createElement('div');
                wrap.style.cssText = 'position:relative;display:inline-flex;';
                var span = document.createElement('span');
                span.className = 'pinfo-social';
                span.innerHTML = SOCIAL_ICONS[k] || '🔗';
                span.title = (SOCIAL_LABELS[k] || k) + ': ' + d[k];
                wrap.appendChild(span);
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'profile-social-remove-btn';
                del.title = 'Quitar';
                del.innerHTML = '×';
                del.addEventListener('click', function(e) {
                    e.preventDefault(); e.stopPropagation();
                    delete draft[k];
                    buildSocialEditable(draft);
                });
                wrap.appendChild(del);
                linksEl.appendChild(wrap);
            });
            /* Botón + para añadir conexión — solo si quedan huecos (max 4) */
            if (countSocials(d) < MAX_SOCIALS) {
                var addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'pinfo-social-add';
                addBtn.title = 'Añadir conexión';
                addBtn.textContent = '+';
                addBtn.addEventListener('click', openAddSocial);
                linksEl.appendChild(addBtn);
            }
        }

        function openAddSocial() {
            /* Por defecto, primera plataforma disponible (no ocupada) */
            var first = SOCIALS.find(function(s) { return !draft[s]; }) || 'steam';
            addPlat.value = first;
            addVal.value = '';
            addVal.placeholder = SOCIAL_PLACEHOLDERS[first] || '';
            addDlg.style.display = 'block';
            addDlg.style.left = Math.round((window.innerWidth  - addDlg.offsetWidth)  / 2) + 'px';
            addDlg.style.top  = Math.round((window.innerHeight - addDlg.offsetHeight) / 2) + 'px';
            setTimeout(function() { addVal.focus(); }, 50);
        }
        function closeAddSocial() { addDlg.style.display = 'none'; }
        addPlat.addEventListener('change', function() {
            addVal.placeholder = SOCIAL_PLACEHOLDERS[addPlat.value] || '';
        });
        addClose.addEventListener('click', closeAddSocial);
        addCancel.addEventListener('click', closeAddSocial);
        addConfirm.addEventListener('click', function() {
            var plat = addPlat.value;
            var val  = (addVal.value || '').trim();
            if (!plat || !val) return;
            draft[plat] = val;
            buildSocialEditable(draft);
            closeAddSocial();
        });
        addVal.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); addConfirm.click(); }
        });

        function enterEditMode() {
            fetch('assets/profile/api.php?action=get-profile')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    draft = Object.assign({}, data || {});
                    /* Con cumpleaños, `age` es la calculada: si lo borra,
                       se queda sin edad (como en el servidor). */
                    if (draft.birthday) draft.age = '';
                    newPhotoFile = null;
                    editing = true;
                    editBtn.style.display    = 'none';
                    saveBtn.style.display    = '';
                    cancelBtn.style.display  = '';
                    avatarCol.classList.add('profile-edit-avatar');
                    buildMetaInputs(draft);
                    buildBioTextarea(draft);
                    buildSocialEditable(draft);
                })
                .catch(function() {});
        }

        function exitEditMode(saved) {
            editing = false;
            editBtn.style.display    = '';
            saveBtn.style.display    = 'none';
            cancelBtn.style.display  = 'none';
            avatarCol.classList.remove('profile-edit-avatar');
            /* Restaurar layout del meta */
            var metaEl = document.getElementById('profile-info-meta');
            if (metaEl) { metaEl.style.flexDirection = ''; metaEl.style.gap = ''; }
            /* Re-renderizar con datos finales (draft si guardado, recarga si cancelado) */
            if (saved) {
                renderProfileInfo(draft);
            } else {
                loadProfile();
            }
        }

        function saveAll() {
            saveBtn.classList.add('btn-busy');
            /* Compone payload con todos los campos editables */
            var payload = {};
            FIELDS.forEach(function(f) { payload[f] = (draft[f] || '').toString().trim(); });
            SOCIALS.forEach(function(f) { payload[f] = (draft[f] || '').toString().trim(); });

            var infoPromise = fetch('assets/profile/api.php?action=save-info', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function(r) { return r.json(); });

            var photoPromise = Promise.resolve(null);
            if (newPhotoFile) {
                var fd = new FormData();
                fd.append('photo', newPhotoFile);
                photoPromise = fetch('assets/img/save-profile-photo.php', {
                    method: 'POST', body: fd
                }).then(function(r) { return r.json(); });
            }

            Promise.all([infoPromise, photoPromise]).then(function(res) {
                saveBtn.classList.remove('btn-busy');
                var infoRes  = res[0] || {};
                var photoRes = res[1];
                if (infoRes.error) { alert(infoRes.error); return; }
                if (photoRes && photoRes.error) { alert(photoRes.error); }
                /* Refresca avatares del escritorio */
                if (photoRes && photoRes.ok && window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'profile-photo-changed', photo: photoRes.photo }, '*');
                }
                /* Actualiza los <img> del propio iframe de perfil */
                if (photoRes && photoRes.ok) {
                    var nm = photoRes.photo.match(/\/([^/]+)\.[^/]+$/);
                    var bn = nm ? nm[1].toLowerCase() : '';
                    document.querySelectorAll('img').forEach(function(img) {
                        var src = img.getAttribute('src') || '';
                        var sm = src.match(/\/([^/?]+)\.[a-zA-Z0-9]+(?:\?|$)/);
                        if (sm && sm[1].toLowerCase() === bn) {
                            img.src = photoRes.photo + '?t=' + Date.now();
                        }
                    });
                }
                exitEditMode(true);
            }).catch(function() {
                saveBtn.classList.remove('btn-busy');
                alert('Error de red al guardar.');
            });
        }

        /* Click en el avatar (en modo edición) → abre file picker */
        avatarCol.addEventListener('click', function(e) {
            if (!editing) return;
            /* Solo si clickaste en el marco o la imagen, no en el username/botón */
            var t = e.target;
            if (t === avatarCol) photoIn.click();
            else if (t.classList && (t.classList.contains('profile-avatar-frame') || t.classList.contains('profile-avatar-img') || t.classList.contains('profile-avatar-placeholder'))) {
                photoIn.click();
            } else if (t.closest && t.closest('.profile-avatar-frame')) {
                photoIn.click();
            }
        });
        photoIn.addEventListener('change', function() {
            if (!editing || !this.files.length) return;
            openCropDialog(this.files[0]);
            this.value = ''; /* permite reabrir con el mismo archivo */
        });

        /* ──── Crop dialog ──── */
        var cropDlg     = document.getElementById('profile-photo-crop-dialog');
        var cropStage   = document.getElementById('profile-photo-crop-stage');
        var cropImg     = document.getElementById('profile-photo-crop-img');
        var cropRegion  = document.getElementById('profile-photo-crop-region');
        var cropClose   = document.getElementById('profile-photo-crop-close');
        var cropCancel  = document.getElementById('profile-photo-crop-cancel');
        var cropConfirm = document.getElementById('profile-photo-crop-confirm');

        /* Estado del cropper */
        var cropSrcFile = null;     /* File original elegido */
        var cropImgNat  = { w: 0, h: 0 };    /* tamaño natural de la imagen */
        var cropImgDisp = { w: 0, h: 0, x: 0, y: 0 };  /* tamaño/posición renderizada en el stage */
        var cropRect    = { x: 0, y: 0, size: 0 };     /* en coords del stage */

        function openCropDialog(file) {
            cropSrcFile = file;
            var url = URL.createObjectURL(file);
            cropImg.onload = function() {
                cropImgNat.w = cropImg.naturalWidth;
                cropImgNat.h = cropImg.naturalHeight;
                layoutImage();
                /* Crop inicial: cuadrado centrado, 80% del lado menor del área visible */
                var minSide = Math.min(cropImgDisp.w, cropImgDisp.h);
                cropRect.size = Math.floor(minSide * 0.8);
                cropRect.x = cropImgDisp.x + Math.floor((cropImgDisp.w - cropRect.size) / 2);
                cropRect.y = cropImgDisp.y + Math.floor((cropImgDisp.h - cropRect.size) / 2);
                drawRegion();
            };
            cropImg.src = url;
            cropDlg.style.display = 'block';
            cropDlg.style.left = Math.round((window.innerWidth  - cropDlg.offsetWidth)  / 2) + 'px';
            cropDlg.style.top  = Math.round((window.innerHeight - cropDlg.offsetHeight) / 2) + 'px';
        }

        function closeCropDialog() {
            cropDlg.style.display = 'none';
            if (cropImg.src) URL.revokeObjectURL(cropImg.src);
            cropImg.src = '';
            cropSrcFile = null;
        }

        /* Ajusta la imagen al stage manteniendo aspecto (contain) */
        function layoutImage() {
            var sw = cropStage.clientWidth, sh = cropStage.clientHeight;
            var ratio = cropImgNat.w / cropImgNat.h;
            var w, h;
            if (sw / sh > ratio) { h = sh; w = h * ratio; }
            else                 { w = sw; h = w / ratio; }
            cropImgDisp.w = Math.floor(w);
            cropImgDisp.h = Math.floor(h);
            cropImgDisp.x = Math.floor((sw - w) / 2);
            cropImgDisp.y = Math.floor((sh - h) / 2);
            cropImg.style.left   = cropImgDisp.x + 'px';
            cropImg.style.top    = cropImgDisp.y + 'px';
            cropImg.style.width  = cropImgDisp.w + 'px';
            cropImg.style.height = cropImgDisp.h + 'px';
        }

        function drawRegion() {
            cropRegion.style.left   = cropRect.x + 'px';
            cropRegion.style.top    = cropRect.y + 'px';
            cropRegion.style.width  = cropRect.size + 'px';
            cropRegion.style.height = cropRect.size + 'px';
        }

        /* Mantiene cropRect dentro de la imagen visible */
        function clampRect() {
            var maxSize = Math.min(cropImgDisp.w, cropImgDisp.h);
            if (cropRect.size > maxSize) cropRect.size = maxSize;
            if (cropRect.size < 32)      cropRect.size = 32;
            if (cropRect.x < cropImgDisp.x) cropRect.x = cropImgDisp.x;
            if (cropRect.y < cropImgDisp.y) cropRect.y = cropImgDisp.y;
            var maxX = cropImgDisp.x + cropImgDisp.w - cropRect.size;
            var maxY = cropImgDisp.y + cropImgDisp.h - cropRect.size;
            if (cropRect.x > maxX) cropRect.x = maxX;
            if (cropRect.y > maxY) cropRect.y = maxY;
        }

        /* Drag del recuadro completo */
        cropRegion.addEventListener('pointerdown', function(e) {
            if (e.target !== cropRegion) return; /* ignora si pinchas en un handle */
            e.preventDefault();
            cropRegion.setPointerCapture(e.pointerId);
            var sx = e.clientX, sy = e.clientY;
            var origX = cropRect.x, origY = cropRect.y;
            function onMove(ev) {
                cropRect.x = origX + (ev.clientX - sx);
                cropRect.y = origY + (ev.clientY - sy);
                clampRect();
                drawRegion();
            }
            function onUp(ev) {
                cropRegion.removeEventListener('pointermove', onMove);
                cropRegion.removeEventListener('pointerup', onUp);
                cropRegion.releasePointerCapture(ev.pointerId);
            }
            cropRegion.addEventListener('pointermove', onMove);
            cropRegion.addEventListener('pointerup', onUp);
        });

        /* Resize desde cualquier esquina (1:1) */
        cropRegion.querySelectorAll('.photo-crop-handle').forEach(function(h) {
            h.addEventListener('pointerdown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                h.setPointerCapture(e.pointerId);
                var corner = h.dataset.corner;
                var sx = e.clientX, sy = e.clientY;
                var orig = { x: cropRect.x, y: cropRect.y, size: cropRect.size };
                function onMove(ev) {
                    var dx = ev.clientX - sx;
                    var dy = ev.clientY - sy;
                    /* Convierte el delta en cambio de tamaño según esquina */
                    var sizeDelta;
                    if (corner === 'se')      sizeDelta =  Math.max(dx, dy);
                    else if (corner === 'nw') sizeDelta =  Math.max(-dx, -dy);
                    else if (corner === 'ne') sizeDelta =  Math.max(dx, -dy);
                    else                      sizeDelta =  Math.max(-dx, dy); /* sw */
                    var newSize = orig.size + sizeDelta;
                    /* Aplica límites antes de mover el origen */
                    var maxSize = Math.min(cropImgDisp.w, cropImgDisp.h);
                    if (newSize > maxSize) newSize = maxSize;
                    if (newSize < 32)      newSize = 32;
                    var newX = orig.x, newY = orig.y;
                    if (corner === 'nw' || corner === 'sw') newX = orig.x + (orig.size - newSize);
                    if (corner === 'nw' || corner === 'ne') newY = orig.y + (orig.size - newSize);
                    cropRect.x = newX; cropRect.y = newY; cropRect.size = newSize;
                    clampRect();
                    drawRegion();
                }
                function onUp(ev) {
                    h.removeEventListener('pointermove', onMove);
                    h.removeEventListener('pointerup', onUp);
                    h.releasePointerCapture(ev.pointerId);
                }
                h.addEventListener('pointermove', onMove);
                h.addEventListener('pointerup', onUp);
            });
        });

        function applyCrop() {
            if (!cropSrcFile) return;
            /* Pasar de coords del stage a coords originales de la imagen */
            var scale = cropImgNat.w / cropImgDisp.w; /* mismo en x e y por contain */
            var srcX = Math.max(0, Math.round((cropRect.x - cropImgDisp.x) * scale));
            var srcY = Math.max(0, Math.round((cropRect.y - cropImgDisp.y) * scale));
            var srcS = Math.round(cropRect.size * scale);
            /* Salida cuadrada — tope 1024 para no generar archivos enormes */
            var outSide = Math.min(1024, srcS);
            var canvas = document.createElement('canvas');
            canvas.width  = outSide;
            canvas.height = outSide;
            var ctx = canvas.getContext('2d');
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(cropImg, srcX, srcY, srcS, srcS, 0, 0, outSide, outSide);
            /* Conservamos el tipo MIME original si es png/webp; si no, jpeg */
            var ext = (cropSrcFile.name.split('.').pop() || 'jpg').toLowerCase();
            var mime = (ext === 'png') ? 'image/png' : (ext === 'webp' ? 'image/webp' : 'image/jpeg');
            canvas.toBlob(function(blob) {
                if (!blob) return;
                /* Convertir Blob en File para mantener el nombre con extensión */
                var fileName = cropSrcFile.name.replace(/\.[^.]+$/, '') + '-crop.' + (mime === 'image/png' ? 'png' : (mime === 'image/webp' ? 'webp' : 'jpg'));
                try { newPhotoFile = new File([blob], fileName, { type: mime }); }
                catch (err) { newPhotoFile = blob; newPhotoFile.name = fileName; }
                /* Preview local en el avatar */
                var previewUrl = URL.createObjectURL(blob);
                avatarCol.querySelectorAll('.profile-avatar-img').forEach(function(img) { img.src = previewUrl; });
                var ph = avatarCol.querySelector('.profile-avatar-placeholder');
                if (ph) {
                    var imgNew = document.createElement('img');
                    imgNew.className = 'profile-avatar-img';
                    imgNew.src = previewUrl;
                    ph.replaceWith(imgNew);
                }
                closeCropDialog();
            }, mime, 0.92);
        }

        cropClose.addEventListener('click', closeCropDialog);
        cropCancel.addEventListener('click', closeCropDialog);
        cropConfirm.addEventListener('click', applyCrop);

        if (editBtn)   editBtn.addEventListener('click', enterEditMode);
        if (cancelBtn) cancelBtn.addEventListener('click', function() { exitEditMode(false); });
        if (saveBtn)   saveBtn.addEventListener('click', saveAll);

        /* Salir de edición cuando alguien externo lo pide (cerrar perfil,
           cambiar de sección, ver perfil de otro usuario). */
        document.addEventListener('profile-edit-cancel', function() {
            if (editing) exitEditMode(false);
        });
        } catch (e) {
            /* Cualquier excepción en este IIFE (e.g. un elemento del DOM
               que falta en standalone) NO debe abortar el resto del
               <script>. Log y seguimos — la edición de perfil simplemente
               no funcionará en esta página. */
            console.warn('[perfil] photo/edit IIFE error:', e);
        }
    })();

    /* ──── Nav sidebar items ──── */
    profileWin.querySelectorAll('.profile-nav-item').forEach(function(navItem) {
        navItem.addEventListener('click', function() {
            var cat   = navItem.dataset.cat;
            var melon = navItem.dataset.melon;
            function navigate() {
                if (melon)              showMelonView(melon);
                else if (cat === 'social') showSocialView();
                else if (cat === 'music')  showMusicView();
                else if (cat)              showCatView(cat);
            }
            if (!loaded) {
                loaded = true;
                loadLists(function() { updateCounts(); navigate(); });
            } else {
                navigate();
            }
        });
    });

    /* ──── Profile icon ──── */
    function openProfileWindow() {
        if (taskbarManager.isRegistered('profile-window')) {
            taskbarManager.restore('profile-window');
        } else {
            profileWin.style.height = Math.max(380, window.innerHeight - 80) + 'px';
            taskbarManager.register('profile-window', 'Perfil', '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">', 'flex');
            if (!loaded) {
                loaded = true;
                loadLists(updateCounts);
                loadProfile();
                loadProfileNotifs();
            }
            startItemNotifStream();
        }
    }
    /* profile-icon es el icono del escritorio (vive en desktop-base.php).
       En standalone (iframe) no existe → guard. */
    var profileIconEl = document.getElementById('profile-icon');
    if (profileIconEl) profileIconEl.addEventListener('dblclick', openProfileWindow);

    /* Abrir el perfil de otro usuario.
       - Modo SHELL (incluido en desktop-base.php): crea una ventana
         iframe propia con perfil.php?standalone=1&as=USERKEY.
       - Modo STANDALONE (este código vive en un iframe): no crea
         iframe localmente (sería una iframe dentro de iframe); avisa
         al shell padre vía postMessage para que cree la ventana a
         nivel raíz del escritorio. */
    window.openProfileAtUser = function(userKey, opts) {
        if (!userKey) return;
        opts = opts || {};
        if (window.__PERFIL_STANDALONE) {
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'perfil-open-at-user',
                        userKey: userKey,
                        reviewCategory: opts.reviewCategory || '',
                        reviewTitle:    opts.reviewTitle    || '',
                        reviewMtype:    opts.reviewMtype    || ''
                    }, '*');
                }
            } catch (_) {}
            return;
        }
        openProfileInIframe(userKey, opts);
    };

    function openProfileInIframe(userKey, opts) {
        opts = opts || {};
        var winId = 'profile-iframe-' + userKey;
        var existing = document.getElementById(winId);
        if (existing) {
            existing.style.display = 'block';
            if (window.windowZ) windowZ.bringToFront(winId);
            if (window.taskbarManager && taskbarManager.isRegistered(winId)) {
                taskbarManager.restore(winId);
            }
            /* Si ya estaba abierto y nos piden abrir una reseña, mandamos
               postMessage al iframe para que la abra sin recargar. */
            if (opts.reviewCategory && opts.reviewTitle) {
                var iframeEl = existing.querySelector('iframe');
                if (iframeEl && iframeEl.contentWindow) {
                    try {
                        iframeEl.contentWindow.postMessage({
                            type: 'perfil-open-review',
                            reviewCategory: opts.reviewCategory,
                            reviewTitle:    opts.reviewTitle,
                            reviewMtype:    opts.reviewMtype || ''
                        }, '*');
                    } catch (_) {}
                }
            }
            return;
        }

        var visited = PROFILE_USERS[userKey] || {};
        var visitedLabel = visited.label || userKey;

        /* Posición: offset inicial de 60px desde el #profile-window
           del shell (que está en 8vw, 4vh) para que la ventana del
           ajeno NO aparezca encima. Después en cascada por cada
           ventana iframe abierta para no superponerse entre sí. */
        var openCount = document.querySelectorAll('[id^="profile-iframe-"]').length;
        var offsetPx  = 60 + openCount * 28;

        /* Contenedor sin chrome — el iframe es el marco. data-no-auto-z
           para que el observer del shell no auto-traiga al frente; lo
           manejamos manualmente vía bringToFront en clicks. */
        var win = document.createElement('div');
        win.id = winId;
        win.setAttribute('data-no-auto-z', '1');
        win.style.cssText =
            'position: fixed;' +
            'left: calc(8vw + ' + offsetPx + 'px);' +
            'top:  calc(4vh + ' + offsetPx + 'px);' +
            'width: 860px;' +
            'height: 900px;' +
            'z-index: 500;' +
            'display: block;' +
            'overflow: hidden;' +
            'padding: 0;' +
            'margin: 0;' +
            'background: transparent;';

        var iframe = document.createElement('iframe');
        /* Propagar la señal de tablet al iframe child para que use el
           mismo viewport responsive. Detectamos buscando ?tablet=1 en
           nuestra URL (lo añade appSrc() del shell padre cuando es tablet)
           o el flag global window.__IS_TABLET__. */
        var _isTab = window.__IS_TABLET__ === true || /[?&]tablet=1/.test(location.search);
        var _reviewQs = '';
        if (opts.reviewCategory && opts.reviewTitle) {
            _reviewQs = '&reviewCat=' + encodeURIComponent(opts.reviewCategory)
                      + '&reviewTitle=' + encodeURIComponent(opts.reviewTitle);
            if (opts.reviewMtype) {
                _reviewQs += '&reviewMtype=' + encodeURIComponent(opts.reviewMtype);
            }
        }
        iframe.src = 'apps/perfil.php?standalone=1&as=' + encodeURIComponent(userKey) + (_isTab ? '&tablet=1' : '') + _reviewQs;
        iframe.style.cssText = 'width: 100%; height: 100%; border: 0; display: block; background: transparent;';
        iframe.setAttribute('title', 'Perfil de ' + visitedLabel);
        iframe.setAttribute('allowtransparency', 'true');
        win.appendChild(iframe);

        /* Resize handles — 4 esquinas + 4 lados (8 en total).
           Cada uno es un overlay transparente sobre el iframe (que
           captura pointer events). El nombre `dir` codifica las
           direcciones afectadas: 'n','s','e','w' (lados); 'nw','ne',
           'sw','se' (esquinas). Por ejemplo 'ne' = afecta TOP + RIGHT.
           Mínimos compartidos: 360 × 320 (coherente con el min-width
           / min-height del #profile-window standalone). */
        var EDGE      = 6;     /* grosor de los lados */
        var CORNER    = 14;    /* tamaño de las esquinas (más fáciles de agarrar) */
        var MIN_W     = 360;
        var MIN_H     = 320;
        var handlesDef = [
            { dir: 'nw', cur: 'nwse-resize', css: 'top:0;left:0;width:'+CORNER+'px;height:'+CORNER+'px;' },
            { dir: 'ne', cur: 'nesw-resize', css: 'top:0;right:0;width:'+CORNER+'px;height:'+CORNER+'px;' },
            { dir: 'sw', cur: 'nesw-resize', css: 'bottom:0;left:0;width:'+CORNER+'px;height:'+CORNER+'px;' },
            { dir: 'se', cur: 'nwse-resize', css: 'bottom:0;right:0;width:'+CORNER+'px;height:'+CORNER+'px;' },
            { dir: 'n',  cur: 'ns-resize',   css: 'top:0;left:'+CORNER+'px;right:'+CORNER+'px;height:'+EDGE+'px;' },
            { dir: 's',  cur: 'ns-resize',   css: 'bottom:0;left:'+CORNER+'px;right:'+CORNER+'px;height:'+EDGE+'px;' },
            { dir: 'w',  cur: 'ew-resize',   css: 'top:'+CORNER+'px;left:0;bottom:'+CORNER+'px;width:'+EDGE+'px;' },
            { dir: 'e',  cur: 'ew-resize',   css: 'top:'+CORNER+'px;right:0;bottom:'+CORNER+'px;width:'+EDGE+'px;' }
        ];
        handlesDef.forEach(function(h) {
            var el = document.createElement('div');
            el.className = 'pf-resize-handle pf-resize-' + h.dir;
            el.style.cssText =
                'position:absolute;' + h.css +
                'cursor:' + h.cur + ';' +
                'z-index:10;background:transparent;touch-action:none;';
            el.addEventListener('pointerdown', function(e) {
                if (win.dataset.maximized === '1') return;
                e.preventDefault(); e.stopPropagation();
                el.setPointerCapture(e.pointerId);
                var startX = e.screenX, startY = e.screenY;
                var startW = win.offsetWidth,  startH = win.offsetHeight;
                var startL = win.offsetLeft,   startT = win.offsetTop;
                var dir = h.dir;
                function onMove(ev) {
                    var dx = ev.screenX - startX, dy = ev.screenY - startY;
                    var newW = startW, newH = startH, newL = startL, newT = startT;
                    if (dir.indexOf('e') >= 0) {
                        newW = Math.max(MIN_W, startW + dx);
                    } else if (dir.indexOf('w') >= 0) {
                        newW = Math.max(MIN_W, startW - dx);
                        newL = startL + (startW - newW);
                    }
                    if (dir.indexOf('s') >= 0) {
                        newH = Math.max(MIN_H, startH + dy);
                    } else if (dir.indexOf('n') >= 0) {
                        newH = Math.max(MIN_H, startH - dy);
                        newT = startT + (startH - newH);
                    }
                    win.style.width  = newW + 'px';
                    win.style.height = newH + 'px';
                    win.style.left   = newL + 'px';
                    win.style.top    = newT + 'px';
                }
                function onUp(ev) {
                    try { el.releasePointerCapture(ev.pointerId); } catch (_) {}
                    el.removeEventListener('pointermove', onMove);
                    el.removeEventListener('pointerup', onUp);
                    el.removeEventListener('pointercancel', onUp);
                }
                el.addEventListener('pointermove', onMove);
                el.addEventListener('pointerup', onUp);
                el.addEventListener('pointercancel', onUp);
            });
            win.appendChild(el);
        });

        document.body.appendChild(win);

        if (window.windowZ) windowZ.bringToFront(winId);
        if (window.taskbarManager) {
            taskbarManager.register(winId, 'Perfil — ' + visitedLabel,
                '<img src="assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">',
                'block');
        }

        /* Click en el iframe → traer al frente. Click se detecta a
           nivel de blur/focus (no podemos escuchar dentro del iframe
           desde fuera por mismo-origin restrictions ... pero como SÍ
           es mismo origin, podríamos. Aquí usamos focus listener para
           simplificar). */
        iframe.addEventListener('load', function() {
            try {
                iframe.contentWindow.addEventListener('focus', function() {
                    if (window.windowZ) windowZ.bringToFront(winId);
                });
                iframe.contentDocument.addEventListener('pointerdown', function() {
                    if (window.windowZ) windowZ.bringToFront(winId);
                });
            } catch (_) {}
        });
    }

    function closeProfileIframe(winId) {
        var w = document.getElementById(winId);
        if (!w) return;
        if (window.taskbarManager && taskbarManager.isRegistered(winId)) {
            taskbarManager.unregister(winId);
        }
        w.remove();
    }

    /* Listener postMessage: el iframe standalone manda señales al shell
       cuando el user interactúa (cerrar, minimize, maximize, drag, notif). */
    var _dragState = null;   /* { winId, startLeft, startTop } */
    window.addEventListener('message', function(e) {
        if (!e.data || typeof e.data !== 'object') return;
        var src = e.source;
        /* Resuelve qué iframe mandó el mensaje */
        var iframes = document.querySelectorAll('[id^="profile-iframe-"] iframe');
        var winId = null, winEl = null;
        for (var i = 0; i < iframes.length; i++) {
            if (iframes[i].contentWindow === src) {
                winEl = iframes[i].closest('[id^="profile-iframe-"]');
                winId = winEl.id;
                break;
            }
        }
        if (!winId) return;

        switch (e.data.type) {
            case 'perfil-close':
                closeProfileIframe(winId);
                return;

            case 'perfil-notif-show':
                if (window.notifSystem && e.data.opts) window.notifSystem.show(e.data.opts);
                return;

            case 'perfil-open-at-user':
                /* Forward desde standalone iframe: queremos abrir el
                   perfil de e.data.userKey como NUEVA ventana iframe-
                   based a nivel del shell. Si ya está abierto, trae al
                   frente. openProfileInIframe maneja ambos casos.
                   Si la notif era de reseña, propagamos los hints para
                   que el perfil destino abra directamente la reseña. */
                if (e.data.userKey && typeof openProfileInIframe === 'function') {
                    openProfileInIframe(e.data.userKey, {
                        reviewCategory: e.data.reviewCategory || '',
                        reviewTitle:    e.data.reviewTitle    || '',
                        reviewMtype:    e.data.reviewMtype    || ''
                    });
                }
                return;

            case 'perfil-minimize':
                /* Igual que un minimize normal — usa taskbarManager si está. */
                if (window.taskbarManager && taskbarManager.minimize) {
                    taskbarManager.minimize(winId);
                } else {
                    winEl.style.display = 'none';
                }
                return;

            case 'perfil-maximize':
                /* Toggle full screen. Memorizamos el bbox original para
                   restaurar después. */
                if (winEl.dataset.maximized === '1') {
                    winEl.style.left   = winEl.dataset.prevLeft   || '';
                    winEl.style.top    = winEl.dataset.prevTop    || '';
                    winEl.style.width  = winEl.dataset.prevWidth  || '';
                    winEl.style.height = winEl.dataset.prevHeight || '';
                    winEl.dataset.maximized = '0';
                } else {
                    winEl.dataset.prevLeft   = winEl.style.left;
                    winEl.dataset.prevTop    = winEl.style.top;
                    winEl.dataset.prevWidth  = winEl.style.width;
                    winEl.dataset.prevHeight = winEl.style.height;
                    /* Llena la pantalla menos taskbar (32px aprox). */
                    winEl.style.left   = '0';
                    winEl.style.top    = '0';
                    winEl.style.width  = '100vw';
                    winEl.style.height = 'calc(100vh - 32px)';
                    winEl.dataset.maximized = '1';
                }
                return;

            case 'perfil-drag-start':
                if (winEl.dataset.maximized === '1') return;   /* no drag si maximizado */
                _dragState = {
                    winId: winId,
                    startLeft: parseFloat(getComputedStyle(winEl).left) || 0,
                    startTop:  parseFloat(getComputedStyle(winEl).top)  || 0
                };
                return;

            case 'perfil-drag-move':
                if (!_dragState || _dragState.winId !== winId) return;
                var _newLeft = _dragState.startLeft + (e.data.dx || 0);
                var _newTop  = _dragState.startTop  + (e.data.dy || 0);
                /* Clamp: la ventana NO puede salir por encima del viewport
                   ni perderse por los lados/abajo. Mantenemos al menos
                   ~40px de title-bar visible en cada lado para que el
                   user pueda recuperarla con un drag. Sin esto la ventana
                   del perfil ajeno se podía subir hasta quedar fuera de
                   la pantalla y ya no había forma de volver a agarrarla. */
                var _wbW = winEl.offsetWidth  || 400;
                var _wbH = winEl.offsetHeight || 200;
                var _vpW = window.innerWidth;
                var _vpH = window.innerHeight;
                var _minVisible = 40;
                var _maxLeft = _vpW - _minVisible;
                var _minLeft = _minVisible - _wbW;
                var _maxTop  = _vpH - _minVisible;
                if (_newLeft < _minLeft) _newLeft = _minLeft;
                else if (_newLeft > _maxLeft) _newLeft = _maxLeft;
                if (_newTop < 0) _newTop = 0;
                else if (_newTop > _maxTop) _newTop = _maxTop;
                winEl.style.left = _newLeft + 'px';
                winEl.style.top  = _newTop  + 'px';
                return;

            case 'perfil-drag-end':
                _dragState = null;
                return;
        }
    });

    /* Cargar notificaciones de perfil + polling cada 30s */
    loadProfileNotifs();
    setInterval(loadProfileNotifs, 30000);

    /* Cargar contador de mensajes sin leer + polling cada 4s */
    loadUnreadChats();
    unreadPollTimer = setInterval(loadUnreadChats, 4000);

    document.getElementById('profile-close').addEventListener('click', function() {
        document.dispatchEvent(new Event('profile-edit-cancel'));
        if (window.__PERFIL_STANDALONE) {
            /* En standalone (iframe) el shell padre cierra la ventana
               exterior. Avisamos vía postMessage. */
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'perfil-close' }, '*');
                }
            } catch (_) {}
            return;
        }
        taskbarManager.unregister('profile-window');
    });

    /* STANDALONE: handlers de minimize / maximize / drag desde el iframe
       a la shell padre. Solo activos cuando estamos en iframe. */
    if (window.__PERFIL_STANDALONE) {
        var _profileTitleBar = document.querySelector('#profile-window > .title-bar');
        var _minBtn = _profileTitleBar && _profileTitleBar.querySelector('button[aria-label="Minimize"]');
        var _maxBtn = _profileTitleBar && _profileTitleBar.querySelector('button[aria-label="Maximize"]');
        function _post(type, payload) {
            try {
                if (window.parent && window.parent !== window) {
                    var msg = { type: type };
                    if (payload) for (var k in payload) msg[k] = payload[k];
                    window.parent.postMessage(msg, '*');
                }
            } catch (_) {}
        }
        if (_minBtn) _minBtn.addEventListener('click', function() { _post('perfil-minimize'); });
        if (_maxBtn) _maxBtn.addEventListener('click', function() { _post('perfil-maximize'); });

        /* Drag del title-bar: detectamos pointerdown en el title-bar
           (excluyendo los botones de control). Mandamos un evento al
           padre con la posición inicial; los siguientes pointermove
           mandan deltas. El padre mueve el wrapper-div en consecuencia. */
        if (_profileTitleBar) {
            _profileTitleBar.addEventListener('pointerdown', function(e) {
                /* Si pinchamos sobre un botón de control, dejamos su
                   handler propio (close/min/max) — no iniciamos drag. */
                if (e.target.closest('.title-bar-controls')) return;
                e.preventDefault();
                var startX = e.screenX, startY = e.screenY;
                _profileTitleBar.classList.add('is-dragging');
                _post('perfil-drag-start');
                function onMove(ev) {
                    _post('perfil-drag-move', { dx: ev.screenX - startX, dy: ev.screenY - startY });
                }
                function onUp(ev) {
                    _profileTitleBar.classList.remove('is-dragging');
                    _post('perfil-drag-end');
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                }
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
            });
        }
    }

    /* Arrancar stream aunque el perfil no se abra (para recibir
       notificaciones en segundo plano).
       En standalone NO: cada ventana de perfil ajeno es otro perfil.php
       completo, y su stream (a) duplicaría notifs que el shell padre ya
       emite —el notifSystem de standalone se las reenvía por postMessage—
       y (b) retiene un proceso PHP del pool compartido 25s en bucle,
       compitiendo con el propio fetch de view-user de esa misma ventana. */
    if (!window.__PERFIL_STANDALONE) startItemNotifStream();

    /* Escuchar postMessage de iframes (galería) cuando publican un post.
       Solo refrescamos si estoy viendo MI propio perfil (no interrumpe la
       vista si estaba ojeando el de otro usuario). */
    window.addEventListener('message', function(e) {
        if (!e.data || e.data.type !== 'profile-post-added') return;
        if (viewingUser) return;
        loadProfile();
    });

    /* STANDALONE INIT — ya está expuesto al inicio del IIFE (línea ~744)
       para sobrevivir crashes en los IIFE internos. Aquí no hace falta
       redefinirlo. */
})();

/* ─── Lightbox de imágenes de posts ───────────────────────
   Delegación de click sobre cualquier <img> dentro de un
   .profile-post-img → abrir overlay con la imagen a tamaño
   completo. Cerrar con click fuera, × o Esc. */
(function() {
    var lb    = document.getElementById('profile-post-lightbox');
    var lbImg = document.getElementById('profile-post-lightbox-img');
    var lbX   = document.getElementById('profile-post-lightbox-close');
    if (!lb) return;
    function open(src) {
        lbImg.src = src;
        lb.classList.add('is-open');
        lb.setAttribute('aria-hidden', 'false');
    }
    function close() {
        lb.classList.remove('is-open');
        lb.setAttribute('aria-hidden', 'true');
        lbImg.src = '';
    }
    /* Click delegado sobre cualquier preview de post */
    document.addEventListener('click', function(e) {
        var t = e.target;
        if (t && t.tagName === 'IMG' && t.closest && t.closest('.profile-post-img')) {
            open(t.src);
        }
    });
    /* Click en el overlay (no en la imagen) → cerrar */
    lb.addEventListener('click', function(e) {
        if (e.target === lb) close();
    });
    lbX.addEventListener('click', close);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && lb.classList.contains('is-open')) close();
    });

    /* Reconstruye el título exacto que crearMomentoDesdeItem usa
       cuando crea el momento. Si el verbo de la categoría cambia, este
       helper también debe cambiar. Expuesto en window para que el IIFE
       principal (renderGallery, etc.) lo pueda usar al eliminar items. */
    window.momentoTitleForItem = function(cat, item) {
        var catVerbs = { movies: 'Vista', series: 'Vista', books: 'Leída', games: 'Jugado', music: 'Escuchado' };
        return (catVerbs[cat] || 'Completado') + ': ' + (item && item.title ? item.title : '');
    };
    /* Best-effort: borra el momento que crearMomentoDesdeItem creó en
       el calendario. Idempotente — si nunca se creó (el item nunca pasó
       por completed) o ya se borró desde calendario, el endpoint
       devuelve {ok:true, deleted:0}. Tras borrar, refresca el iframe. */
    window.eliminarMomentoDeItem = function(cat, item) {
        if (!item || !item.title) return;
        var titulo = window.momentoTitleForItem(cat, item);
        fetch('assets/couple/api.php?action=delete-momento-by-title', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: titulo })
        }).then(function(r){ return r.json(); }).then(function(d){
            if (d && d.ok && d.deleted > 0) {
                try {
                    var calFr = document.getElementById('calendar-iframe');
                    if (calFr && calFr.contentWindow) {
                        calFr.contentWindow.postMessage({ type: 'momento-saved' }, '*');
                    }
                } catch(_) {}
            }
        }).catch(function(){});
    };

    /* ──── Auto-momento al completar ──── */
    window.crearMomentoDesdeItem = function(cat, item) {
    console.log('item.image:', item.image);
    console.log('item completo:', JSON.stringify(item));
    var parejaId = window.DesktopParejaId || 0;
    var catEmojis = { movies: '🎬', series: '📺', books: '📚', games: '🎮', music: '🎵' };
    var catVerbs  = { movies: 'Vista', series: 'Vista', books: 'Leída', games: 'Jugado', music: 'Escuchado' };
    var emocion   = catEmojis[cat] || '😊';
    var titulo    = (catVerbs[cat] || 'Completado') + ': ' + item.title;
    var hoy       = new Date();
    var fecha     = hoy.getFullYear() + '-'
        + String(hoy.getMonth() + 1).padStart(2, '0') + '-'
        + String(hoy.getDate()).padStart(2, '0');
    var desc = '';
    var withKeys = [];
    if (item.sharedFrom) withKeys.push(item.sharedFrom);
    if (item.collaborators && item.collaborators.length) {
        item.collaborators.forEach(function(k) {
            if (k !== item.sharedFrom) withKeys.push(k);
        });
    }
    if (withKeys.length) {
        var labels = withKeys.map(function(k) {
            return PROFILE_USERS[k] ? PROFILE_USERS[k].label : k;
        });
        desc = 'Con ' + labels.join(', ');
    }
    console.log('enviando save-momento:', JSON.stringify({
    pareja_id:   parejaId,
    titulo:      titulo,
    fecha:       fecha,
    descripcion: desc,
    emocion:     emocion
}));

    fetch('assets/couple/api.php?action=save-momento', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            pareja_id:   parejaId,
            titulo:      titulo,
            fecha:       fecha,
            descripcion: desc,
            emocion:     emocion
        })
    }).then(function(r) { return r.json(); })
      .then(function(data) {
          console.log('save-momento respuesta:', JSON.stringify(data));
          if (!data || !data.ok) return;
          /* Avisa al iframe del calendario para que recargue. La perfil
             y el calendar-iframe viven en el mismo top-level (perfil es
             partial); mandar postMessage al iframe le dispara su
             listener interno de "momento-saved". */
          try {
              var calFr = document.getElementById('calendar-iframe');
              if (calFr && calFr.contentWindow) {
                  calFr.contentWindow.postMessage({ type: 'momento-saved' }, '*');
              }
          } catch(_) {}
          if (!data.id || !item.image) return;
          fetch('assets/couple/api.php?action=save-momento-foto-url', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ momento_id: data.id, foto_url: item.image })
          }).catch(function() {});
      })
      .catch(function(err) {
          console.log('ERROR save-momento:', err);
      });

    if (window.notifSystem) {
        window.notifSystem.show({
            id:      'momento_' + Date.now(),
            type:    'info',
            title:   '<img src="assets/img/appIcons/calendarioIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">Añadido al calendario',
            message: titulo + ' guardado como momento.',
            autoDismissAfter: 4000
        });
    }
}

})();
</script>
<?php if (!empty($_perfilStandalone)): ?>
<script>
    /* Init standalone en un script SEPARADO — si los IIFE de arriba
       crashearon con "null is not an object" o similar, el motor JS
       sigue ejecutando este script. Aquí llamamos al init expuesto
       por el IIFE grande (si llegó a definirlo). */
    (function() {
        function tryInit(attempts) {
            if (typeof window.__perfilStandaloneInit === 'function') {
                try { window.__perfilStandaloneInit(); }
                catch (e) { console.error('[PERFIL standalone init] error', e); }
                return;
            }
            if (attempts <= 0) {
                console.error('[PERFIL standalone] init no expuesto — el IIFE grande crasheó antes de llegar a definirlo');
                return;
            }
            setTimeout(function(){ tryInit(attempts - 1); }, 50);
        }
        tryInit(20);  /* hasta 1s para esperar al IIFE */
    })();
</script>
</body>
</html>
<?php endif; ?>
