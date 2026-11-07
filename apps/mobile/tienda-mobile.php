<?php
/* ──────────────────────────────────────────────────────────────────────
   TIENDA — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   Versión móvil de apps/tienda.php. Reusa los MISMOS IDs del HTML del
   desktop y el MISMO JS — los endpoints (assets/tienda/api.php) son
   los mismos. Solo cambia el layout (un único flujo vertical con tabs
   horizontales scrollable y la status-bar al pie) y los paths
   relativos (../../ en vez de ../).

   ⚠ Si tocas el JS del desktop, ten en cuenta que el bloque de JS de
      este archivo es una copia adaptada de paths. Mantenlo en sync.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__, 2) . '/assets/mobile-detect.php';
setLongSessionCookie();
session_start();
require_once dirname(__DIR__, 2) . '/assets/config.php';
require_once dirname(__DIR__, 2) . '/assets/dev-build.php';   /* MELON_DEV_BUILD: cajita "DEV" de darse puntos */
require_once dirname(__DIR__, 2) . '/assets/tienda/catalogo.php';   /* pestañas de la tienda (catalogo.json) */

if (!isset($_SESSION['user']) || !isset($loginUsers[$_SESSION['user']])) {
    header('Location: ../../index.php');
    exit;
}
$userKey   = $_SESSION['user'];
$userLabel = $loginUsers[$userKey]['label'];

/* Tema activo del usuario — mismo flow que el resto de apps móviles. */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes      = loadUserThemes($userKey);
$activeTheme      = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
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
    <title>Tienda</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__, 2) . "/assets/php/active-interface.php"; emitInterfaceCss("../../"); ?>
    <script src="../../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../../assets/css/themes.css">
    <!-- Marcos de perfil en las fotos (data-marco-user) -->
    <link rel="stylesheet" href="../../assets/css/marcos.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/css/marcos.css') ?>">
    <script src="../../assets/js/melon-marcos.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/assets/js/melon-marcos.js') ?>"></script>
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
           - Aquí solo definimos tweaks específicos de esta app. */

        /* Mini-header con wallet (balance) + Discord status. Replica el
           sidebar del desktop pero condensado a una franja horizontal. */
        .ti-header {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            padding: 8px;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            flex-shrink: 0;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        .ti-wallet {
            position: relative;
            display: flex; flex-direction: column;
            min-width: 0;
        }
        /* Botón de ayuda en la esquina sup-izq del wallet — solo un
           interrogante en un círculo, mismo color que el label "Tu balance"
           (var(--text-muted)) y sin fondo. */
        /* Botón Ayuda pixel-art — círculo + ? dibujados en SVG (rect 1×1)
           y aplicados con mask-image para que currentColor los tinte
           del color del label "Tu balance". image-rendering:pixelated
           mantiene el escalón al subir de 13px a 22px. */
        #tienda-help-btn {
            /* Inline al lado del texto "Tu balance" — el label es flex
               row gracias a la regla .ti-wallet-label de abajo. */
            display: inline-block;
            vertical-align: middle;
            width: 14px; height: 14px;
            min-width: 0; min-height: 0; max-width: 14px; max-height: 14px;
            aspect-ratio: 1 / 1;
            box-sizing: border-box;
            padding: 0; margin: 0 0 0 4px;
            background: none;
            border: none;
            color: var(--text-muted, #666);
            text-shadow: none;
            cursor: pointer;
            box-shadow: none;
            line-height: 0;
            font-size: 0;
        }
        #tienda-help-btn::before {
            content: "";
            display: block;
            width: 100%;
            height: 100%;
            background-color: currentColor;
            -webkit-mask:
                url("data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 13 13' shape-rendering='crispEdges'%3E%3Cpath d='M5,0h3v1H5zM3,1h2v1H3zM8,1h2v1H8zM2,2h1v1H2zM10,2h1v1H10zM1,3h1v1H1zM11,3h1v1H11zM1,4h1v1H1zM11,4h1v1H11zM0,5h1v1H0zM12,5h1v1H12zM0,6h1v1H0zM12,6h1v1H12zM0,7h1v1H0zM12,7h1v1H12zM1,8h1v1H1zM11,8h1v1H11zM1,9h1v1H1zM11,9h1v1H11zM2,10h1v1H2zM10,10h1v1H10zM3,11h2v1H3zM8,11h2v1H8zM5,12h3v1H5zM5,3h3v1H5zM4,4h1v1H4zM8,4h1v1H8zM8,5h1v1H8zM7,6h1v1H7zM6,7h1v1H6zM6,8h1v1H6zM6,10h1v1H6z'/%3E%3C/svg%3E")
                center / contain no-repeat;
                    mask:
                url("data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 13 13' shape-rendering='crispEdges'%3E%3Cpath d='M5,0h3v1H5zM3,1h2v1H3zM8,1h2v1H8zM2,2h1v1H2zM10,2h1v1H10zM1,3h1v1H1zM11,3h1v1H11zM1,4h1v1H1zM11,4h1v1H11zM0,5h1v1H0zM12,5h1v1H12zM0,6h1v1H0zM12,6h1v1H12zM0,7h1v1H0zM12,7h1v1H12zM1,8h1v1H1zM11,8h1v1H11zM1,9h1v1H1zM11,9h1v1H11zM2,10h1v1H2zM10,10h1v1H10zM3,11h2v1H3zM8,11h2v1H8zM5,12h3v1H5zM5,3h3v1H5zM4,4h1v1H4zM8,4h1v1H8zM8,5h1v1H8zM7,6h1v1H7zM6,7h1v1H6zM6,8h1v1H6zM6,10h1v1H6z'/%3E%3C/svg%3E")
                center / contain no-repeat;
            image-rendering: pixelated;
            image-rendering: -moz-crisp-edges;
            image-rendering: crisp-edges;
        }
        #tienda-help-btn:active { transform: translateY(1px); }
        .ti-wallet-label {
            display: inline-flex; align-items: center;
            font-size: 9px; letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted, var(--text-faint, #666));
        }
        .ti-wallet-amount {
            display: flex; align-items: center; gap: 6px;
            font-size: 20px; font-weight: bold;
            color: var(--text, #000);
            line-height: 1;
            padding: 2px 0;
        }
        .ti-wallet-amount .ic { font-size: 18px; }
        .ti-wallet-unit {
            font-size: 9px;
            color: var(--text-muted, var(--text-faint, #666));
            white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .ti-discord {
            display: flex; flex-direction: column; align-items: flex-end;
            gap: 3px;
            min-width: 0;
        }
        .ti-discord-label {
            font-size: 9px; letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted, var(--text-faint, #666));
        }
        #tienda-discord-name {
            font-size: 11px;
            font-weight: bold;
            color: var(--accent, #000080);
            max-width: 110px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Botones del header (Donar | Vincular/Desvincular Discord)
           agrupados en una row a la derecha. Donar a la IZQUIERDA del
           botón Discord — alineado con cómo el desktop separa los dos. */
        .ti-actions {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .ti-actions .button,
        #tienda-discord-btn,
        #tienda-donar-btn {
            min-height: 26px;
            font-size: 10px;
            padding: 4px 10px;
            line-height: 1;
            cursor: pointer;
            /* flex centering para que el texto quede vertical-aligned
               aunque el contenido sea solo 1 línea de 10px. */
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        #tienda-donar-btn.is-active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }

        /* Tabs Win98 horizontales con scroll. Usamos las clases del JS
           desktop (.tienda-tab + #tienda-donar-btn con .is-active) para
           que el handler de tabs del JS principal funcione sin cambios. */
        .ti-tabs {
            display: flex; gap: 4px;
            padding: 6px 4px 4px;
            background: var(--win-bg, silver);
            overflow-x: auto;
            flex-shrink: 0;
            -webkit-overflow-scrolling: touch;
        }
        .ti-tabs .tienda-tab {
            min-height: 30px;
            padding: 4px 12px;
            font-size: 11px;
            line-height: 1;
            white-space: nowrap;
            flex-shrink: 0;
            cursor: pointer;
            /* flex centering: con min-height + line-height:1 el texto
               queda anclado al baseline (sube). Centramos vertical y
               horizontalmente con inline-flex. */
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .ti-tabs .tienda-tab.active {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
        }

        /* Paneles ocupan flex:1 y scrollean. Donaciones empieza oculta;
           el JS pone display:none al principal y añade .is-active a
           donaciones para mostrarla (mismo patrón que desktop). */
        .ti-pane {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }
        /* Donaciones empieza oculta; el JS añade .is-active al pulsar
           el botón Donar (ver el handler [data-view] del JS principal). */
        #tienda-view-donaciones { display: none; }
        #tienda-view-donaciones.is-active {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .tienda-empty {
            text-align: center;
            color: var(--text-muted, var(--text-faint, #808080));
            font-size: 11px;
            padding: 28px 12px;
        }

        /* Grid de items — 2 columnas en móvil (auto-fit min 140px).
           - align-content: start → las rows se apilan al inicio sin
             estirarse para llenar el contenedor si sobran items.
           - align-items: start → cada card mantiene su altura natural
             en lugar de estirarse a la altura de la row más alta.
           Sin esto, un haro solo se inflaba hasta el final del .ti-pane
           y dos cards en la misma row se igualaban verticalmente. */
        #tienda-view-principal {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 8px;
            align-content: start;
            align-items: start;
        }
        .tienda-card {
            background: var(--win-bg, silver);
            color: var(--text, #000);
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        .tienda-card-icon {
            position: relative;
            width: 100%; aspect-ratio: 1 / 1;
            background: var(--inset-bg, #808080);
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
            display: flex; align-items: center; justify-content: center;
            font-size: 38px;
            overflow: hidden;
        }
        .tienda-card-icon-img {
            max-width: 80%; max-height: 80%;
            object-fit: contain;
            image-rendering: -webkit-optimize-contrast;
        }
        .tienda-card-price {
            position: absolute;
            top: 3px; right: 3px;
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            font-size: 10px;
            padding: 1px 5px;
            letter-spacing: 0.5px;
        }
        .tienda-card.is-owned { opacity: 0.7; }
        .tienda-card.is-owned .tienda-card-icon::after {
            content: '✓';
            position: absolute;
            left: 4px; top: 4px;
            font-size: 14px; font-weight: bold;
            color: var(--accent, #000080);
            background: var(--win-bg, silver);
            padding: 1px 5px;
        }
        .tienda-card-name {
            font-size: 11px; font-weight: bold;
            color: var(--text, #000);
            text-align: center;
            line-height: 1.2;
            word-break: break-word;
        }
        .tienda-card-desc {
            font-size: 10px;
            color: var(--text-muted, var(--text-faint, #666));
            text-align: center;
            line-height: 1.3;
            min-height: 26px;
        }
        .tienda-card .button {
            width: 100%;
            min-height: 26px;
            font-size: 11px;
        }
        .tienda-card .button:disabled { opacity: 0.55; cursor: not-allowed; }

        /* Vista Donaciones — info + encargos + lista de donantes. El
           display/flex se aplica solo cuando .is-active está presente
           (regla de arriba). */
        .donar-intro,
        .donar-encargos,
        .donar-donors-section {
            background: var(--win-bg, silver);
            color: var(--text, #000);
            padding: 10px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
        }
        .donar-intro-main { text-align: center; }
        .donar-intro-emoji { font-size: 36px; line-height: 1; }
        .donar-intro-title {
            font-size: 14px; font-weight: bold;
            margin-top: 6px;
        }
        .donar-intro-text {
            font-size: 11px; color: var(--text, #000);
            line-height: 1.5;
            margin: 6px 0 8px;
        }
        #donar-go-btn { min-height: 32px; font-size: 12px; padding: 0 18px; }
        .donar-intro-hint {
            font-size: 10px;
            color: var(--text-muted, var(--text-faint, #888));
            margin-top: 6px;
        }
        .donar-encargos-title {
            font-size: 11px; font-weight: bold;
            letter-spacing: 1px; text-transform: uppercase;
            background: var(--titlebar-start, #000080);
            color: var(--titlebar-text, #fff);
            padding: 4px 8px;
            margin: -10px -10px 8px;
        }
        .donar-encargo-btn {
            display: flex; align-items: center; gap: 8px;
            width: 100%; min-height: 36px;
            margin-bottom: 6px;
            padding: 0 10px;
            text-decoration: none;
            color: var(--text, #000);
            font-size: 12px;
        }
        .donar-encargo-btn .ic { font-size: 18px; flex-shrink: 0; }
        .donar-encargo-btn .label { flex: 1; text-align: left; }
        .donar-encargo-btn .price {
            background: var(--accent, #000080);
            color: var(--accent-text, #fff);
            font-size: 10px;
            padding: 2px 6px;
            font-weight: bold;
            flex-shrink: 0;
        }
        .donar-encargos-info {
            font-size: 10px;
            color: var(--text-muted, var(--text-faint, #888));
            line-height: 1.4;
            margin: 6px 0 0;
        }

        .donar-donors-title {
            font-size: 11px; font-weight: bold;
            letter-spacing: 1px; text-transform: uppercase;
            background: var(--titlebar-start, #000080);
            color: var(--titlebar-text, #fff);
            padding: 4px 8px;
            margin: -10px -10px 8px;
        }
        /* Grid de donantes — flex wrap centrado, mismas mini-cards
           Win98 que el desktop pero compactas para móvil. */
        .donar-donors-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
        }
        .donar-donor {
            width: 130px;
            background: var(--win-bg, silver);
            color: var(--text, #000);
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            box-shadow:
                inset -1px -1px var(--bezel-dark-1, #0a0a0a),
                inset  1px  1px var(--bezel-light-1, #fff),
                inset -2px -2px var(--bezel-dark-2, grey),
                inset  2px  2px var(--bezel-light-2, #dfdfdf);
            box-sizing: border-box;
        }
        .donar-donor-avatar {
            width: 56px; height: 56px;
            flex-shrink: 0;
            background: var(--inset-bg, #808080);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            overflow: hidden;
            box-shadow:
                -1px -1px 0 var(--bezel-dark-1, #0a0a0a),
                 1px  1px 0 var(--bezel-light-1, #fff),
                -2px -2px 0 var(--bezel-dark-2, grey),
                 2px  2px 0 var(--bezel-light-2, #dfdfdf);
            margin: 2px;
        }
        .donar-donor-avatar img {
            width: 56px; height: 56px;
            object-fit: cover;
            display: block;
            image-rendering: auto;
        }
        .donar-donor-name {
            font-size: 11px; font-weight: bold;
            text-align: center;
            color: var(--text, #000);
            line-height: 1.2;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* Etiqueta del tipo de aportación. Mismos colores que desktop. */
        .donar-donor-tipo {
            font-size: 9px; font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 2px 6px;
            color: #fff;
            display: inline-flex; align-items: center; gap: 3px;
            line-height: 1;
        }
        .donar-donor-tipo.donacion    { background: var(--accent, #000080); color: var(--accent-text, #fff); }
        .donar-donor-tipo.suscripcion { background: #7c4dff; }
        .donar-donor-tipo.encargo     { background: #e67e22; }
        .donar-donor-msg {
            font-size: 10px;
            color: var(--text, #000);
            text-align: center;
            line-height: 1.35;
            padding: 4px 6px;
            width: 100%;
            box-sizing: border-box;
            background: var(--inset-bg, #c0c0c0);
            box-shadow:
                inset  1px  1px var(--bezel-dark-1, #0a0a0a),
                inset -1px -1px var(--bezel-light-1, #fff);
            font-style: italic;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .donar-donor-msg:empty { display: none; }

        /* Status footer chip. */
        #tienda-status {
            font-size: 10px;
            padding: 4px 8px;
            color: var(--text-muted, var(--text-faint, #666));
            text-align: center;
            background: var(--input-bg, #fff);
            min-height: 18px;
            box-shadow:
                inset  1px  1px var(--bezel-dark-2, grey),
                inset -1px -1px var(--bezel-light-1, #fff),
                inset  2px  2px var(--bezel-dark-1, #0a0a0a),
                inset -2px -2px var(--bezel-light-2, #dfdfdf);
        }
        /* ── Pestaña Perfil por secciones (renderPerfil; como la del PC,
           apps/tienda.php): MARCOS DE AVATAR con tu foto, SUBIR DE NIVEL
           EXPOSITORES (una tarjeta ancha por cadena, con pips), EXPOSITORES
           ADICIONALES, RACHA (el fueguito) y OTROS. En el móvil: tarjetas
           de 2 en 2 y las anchas a todo lo ancho. */
        #tienda-view-principal.is-secciones { display: block; }
        .tienda-sec { margin-bottom: 16px; }
        .tienda-sec-h { display: flex; align-items: center; gap: 8px; margin: 0 0 4px;
            font-size: 12px; font-weight: bold; letter-spacing: 1px; color: var(--text, #000); }
        .tienda-sec-h::after { content: ''; flex: 1; border-top: 1px solid var(--bezel-dark-1, #808080); border-bottom: 1px solid var(--bezel-light-1, #fff); }
        .tienda-sec-sub { font-size: 11px; color: var(--text-muted, #666); margin-bottom: 8px; line-height: 1.35; }
        .tienda-sec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; align-items: start; }
        .tienda-sec-wide { display: flex; flex-direction: column; gap: 8px; }
        /* Tarjeta ancha: también .tienda-card (su relieve, y Kawaii la pinta
           igual que las demás); aquí, en fila: icono a la izquierda. */
        .tienda-card.tienda-wide { flex-direction: row; align-items: stretch; gap: 0; padding: 4px; }
        .tienda-wide-icon { width: 72px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--inset-bg, #808080);
            box-shadow: inset -1px -1px var(--bezel-light-1, #fff), inset 1px 1px var(--bezel-dark-1, #0a0a0a); }
        .tienda-wide-icon img { width: 40px; height: 40px; object-fit: contain; image-rendering: pixelated; }
        .tienda-wide-body { flex: 1; min-width: 0; padding: 4px 4px 2px 8px; display: flex; flex-direction: column; gap: 3px; }
        .tienda-wide-name { font-size: 12px; font-weight: bold; color: var(--text, #000); }
        .tienda-wide-type { font-size: 10px; color: var(--text-muted, #666); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .tienda-wide-pips { display: inline-flex; gap: 2px; }
        .tienda-wide-pips i { width: 10px; height: 6px; background: var(--inset-bg, #808080); box-shadow: inset 1px 1px var(--bezel-dark-1, #0a0a0a); }
        .tienda-wide-pips i.on { background: var(--accent, navy); }
        .tienda-wide-desc { font-size: 10px; color: var(--text-muted, #666); flex: 1; line-height: 1.35; }
        .tienda-wide-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 2px; }
        .tienda-wide-price { font-size: 11px; font-weight: bold; color: var(--text, #000); display: inline-flex; align-items: center; gap: 3px; }
        .tienda-wide-foot .button { min-height: 26px; min-width: 0; padding: 0 12px; }
        .tienda-wide-done { font-size: 11px; font-weight: bold; color: var(--accent, navy); }
        /* Marco con tu foto: hueco de 72 px y el sitio que pide cada marco
           (--mk-* de marcos.css), como en el PC. */
        .tienda-marco-prev.is-foto { width: 72px; height: 72px;
            margin: calc(72px * var(--mk-t, .14)) calc(72px * var(--mk-r, .14)) calc(72px * var(--mk-b, .14)) calc(72px * var(--mk-l, .14)); }
        .tienda-marco-prev.is-foto > img { object-fit: cover; image-rendering: auto; }
        .tienda-fuego { width: 48px; height: 56px; display: block; }
        /* FUENTES: "Aa Ññ" con la fuente de cada tarjeta. */
        .tienda-fuente-prev { font-size: 28px; line-height: 1.1; color: var(--text, #000); text-align: center; padding: 0 4px; white-space: nowrap; }
        /* ── Regalos (como los del PC, apps/tienda.php): botón "Regalos (N)"
           en la cabecera → ventana con los sin abrir y "Regalar a un
           amigo"; barra del modo regalo encima de los objetos. ── */
        .tienda-av { width: 22px; height: 22px; flex-shrink: 0; display: inline-block; position: relative; background: var(--inset-bg, #808080); }
        .tienda-av[data-marco] { margin: calc(var(--mk-t) * 22px) calc(var(--mk-r) * 22px) calc(var(--mk-b) * 22px) calc(var(--mk-l) * 22px); }
        .tienda-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
        #tienda-gift-bar { display: none; align-items: center; gap: 8px; margin: 0 0 8px; padding: 6px 8px; font-size: 11px;
            background: var(--accent, navy); color: var(--accent-text, #fff); }
        #tienda-gift-bar.on { display: flex; }
        #tienda-gift-bar .tienda-gift-txt { flex: 1; min-width: 0; line-height: 1.35; }
        #tienda-gift-bar .button { min-height: 26px; font-size: 10px; padding: 0 8px; min-width: 0; flex-shrink: 0; }
        .tienda-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 10px; }
        .tienda-modal { width: 100%; max-width: 420px; max-height: 86vh; display: flex; flex-direction: column; color: var(--text, #000); }
        .tienda-modal .window-body { padding: 10px; display: flex; flex-direction: column; gap: 10px; font-size: 11px; overflow-y: auto; }
        .tienda-modal-foot { display: flex; justify-content: flex-end; align-items: center; gap: 6px; }
        .tienda-modal-foot .button { min-height: 28px; min-width: 0; padding: 0 12px; }
        .tienda-modal-item { display: flex; gap: 10px; align-items: flex-start; }
        .tienda-modal-item .tienda-card-icon { width: 88px; height: 88px; flex-shrink: 0; font-size: 36px; }
        .tienda-modal-item .tienda-card-icon > img, .tienda-modal-item .tienda-card-icon > svg { max-width: 60px; max-height: 60px; }
        .tienda-modal-item .tienda-marco-prev.is-foto { width: 48px; height: 48px;
            margin: calc(48px * var(--mk-t, .14)) calc(48px * var(--mk-r, .14)) calc(48px * var(--mk-b, .14)) calc(48px * var(--mk-l, .14)); }
        .tienda-modal-name { font-size: 13px; font-weight: bold; }
        .tienda-modal-desc { color: var(--text-muted, #666); margin-top: 3px; line-height: 1.35; }
        .tienda-modal-from { display: flex; align-items: center; gap: 8px; }
        .tienda-modal-msg { padding: 6px 8px; background: var(--input-bg, #fff); color: var(--input-text, #000); box-shadow: inset 1px 1px var(--bezel-dark-1, #808080), inset -1px -1px var(--bezel-light-1, #fff); white-space: pre-wrap; word-break: break-word; font-style: italic; }
        .tienda-friends { display: flex; flex-direction: column; max-height: 50vh; overflow-y: auto; background: var(--input-bg, #fff); box-shadow: inset 1px 1px var(--bezel-dark-1, #808080), inset -1px -1px var(--bezel-light-1, #fff); padding: 2px; }
        .tienda-friend { display: flex; align-items: center; gap: 8px; padding: 6px; cursor: pointer; color: var(--input-text, #000); font-size: 12px; }
        .tienda-friend .tienda-av { width: 30px; height: 30px; }
        .tienda-friend .tienda-av[data-marco] { margin: calc(var(--mk-t) * 30px) calc(var(--mk-r) * 30px) calc(var(--mk-b) * 30px) calc(var(--mk-l) * 30px); }
        .tienda-friend span:last-child { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tienda-friend i { font-style: normal; color: var(--text-muted, #666); }
        .tienda-modal-h { font-weight: bold; font-size: 11px; }
        .tienda-modal textarea { width: 100%; box-sizing: border-box; resize: vertical; font-size: 12px;
            background-color: var(--input-bg, #fff) !important; color: var(--input-text, #000) !important; }
        #tienda-status:empty { display: none; }
        #tienda-status.is-error { color: var(--error-text, #c00); font-weight: bold; }
        #tienda-status.is-ok    { color: var(--accent, #000080); font-weight: bold; }

        /* Modal de ayuda — mismas clases que desktop (.th-help-*) para que
           el override kawaii (assets/interfaces/kawaii/style.css) los pinte
           en estilo Needy Streamer Overload sin duplicar reglas. */
        .th-help-body { font-size: 12px; line-height: 1.55; color: var(--text); }
        .th-help-intro {
            margin-bottom: 12px; padding: 8px 10px;
            background: var(--surface-deep);
            box-shadow:
                inset -1px -1px var(--bezel-light-1),
                inset  1px  1px var(--bezel-dark-1);
            border-radius: 2px;
        }
        .th-help-item {
            display: flex; gap: 12px; align-items: flex-start; padding: 10px;
            background: var(--win-bg);
            border: 1px solid var(--border);
            box-shadow:
                inset -1px -1px var(--bezel-light-1),
                inset  1px  1px var(--bezel-light-1);
        }
        .th-help-icon {
            font-size: 22px; line-height: 1; flex-shrink: 0;
            width: 32px; text-align: center;
        }
        .th-help-item strong { display: block; font-size: 13px; margin-bottom: 2px; }
        .th-help-amt {
            display: inline-block; margin-top: 2px; padding: 1px 6px;
            background: var(--accent, #000080); color: var(--accent-text, #fff);
            font-weight: bold; font-size: 11px; border-radius: 2px;
        }
        .th-help-sub { margin-top: 4px; font-size: 11px; color: var(--text-muted, #555); }
        .th-help-foot {
            margin-top: 6px; padding-top: 8px;
            border-top: 1px dotted var(--border);
            font-size: 11px; color: var(--text-muted, #555);
        }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">
<div class="window mh-window">
    <div class="title-bar">
        <div class="title-bar-text"><img src="../../assets/img/appIcons/tiendaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Tienda</div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="ti-back"></button>
        </div>
    </div>
    <div class="window-body">

<!-- Cabecera con wallet (balance) + estado Discord. Mismos IDs que
     desktop para que el JS los rellene sin tocar nada. -->
<div class="ti-header">
    <div class="ti-wallet">
        <div class="ti-wallet-label">Tu balance<button type="button" id="tienda-help-btn" title="Cómo conseguir puntos" aria-label="Ayuda">?</button></div>
        <div class="ti-wallet-amount">
            <span class="ic"><img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:48px;height:48px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>
            <span id="tienda-balance-v">—</span>
        </div>
        <div class="ti-wallet-unit">puntos de Autismo</div>
        <?php if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD): ?>
        <!-- Solo en la build de dev: darse puntos para probar la tienda. -->
        <div id="tienda-dev" style="margin-top:6px;padding:4px;border:1px dashed var(--error-text,#c00);font-size:11px;display:flex;gap:4px;align-items:center;justify-content:center;">
            <b style="color:var(--error-text,#c00);">DEV</b>
            <input type="number" id="tienda-dev-amount" value="1000" min="1" max="100000" style="width:72px;">
            <button type="button" class="button" id="tienda-dev-grant">+ Puntos</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="ti-discord">
        <div class="ti-discord-label">Discord</div>
        <div id="tienda-discord-name"></div>
        <!-- Donaciones vive en el header (no en las tabs), a la
             izquierda del botón Vincular/Desvincular Discord. Así queda
             separada de las categorías de items. -->
        <div class="ti-actions">
            <!-- Regalos: los sin abrir ("Regalos (N)") y "Regalar a un amigo". -->
            <button type="button" class="button" id="tienda-gifts-btn">Regalos</button>
            <button type="button" class="button" id="tienda-donar-btn" data-view="donaciones">☕ Donar</button>
            <button type="button" class="button" id="tienda-discord-btn">…</button>
        </div>
    </div>
</div>

<!-- Tabs horizontales: las categorías de catalogo.json. Donaciones ya no vive
     aquí — se accede desde el botón del header. -->
<div class="ti-tabs">
    <?php
    /* Pestañas: salen de assets/tienda/catalogo.json (la primera empieza
       activa; "proximamente" → la pestaña enseña "Coming soon..."). */
    foreach (tiendaPestanas() as $_i => $_t): ?>
    <div class="button tienda-tab<?= $_i === 0 ? ' active' : '' ?>" data-view="principal" data-cat="<?= htmlspecialchars($_t['id']) ?>"<?= $_t['proximamente'] ? ' data-soon="1"' : '' ?>><?php if ($_t['icono'] !== ''): ?><img src="../../<?= htmlspecialchars($_t['icono']) ?>" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;"><?php endif; ?><?= htmlspecialchars($_t['nombre']) ?></div>
    <?php endforeach; ?>
</div>

<!-- Modo regalo (regalando a un amigo): barra encima de los objetos. -->
<div id="tienda-gift-bar"></div>

<!-- Vista Principal — grid de items de la categoría seleccionada. -->
<div class="ti-pane" id="tienda-view-principal">
    <div class="tienda-empty">Cargando…</div>
</div>

<!-- Vista Donaciones — explicación, encargos Ko-fi y donantes. -->
<div class="ti-pane" id="tienda-view-donaciones">
    <div class="donar-intro">
        <div class="donar-intro-main">
            <div class="donar-intro-emoji">🍉</div>
            <div class="donar-intro-title">Apoya el desarrollo</div>
            <div class="donar-intro-text">
                Scrapbook Melon es un proyecto personal y gratuito. Cualquier
                aportación ayuda a mantener el servidor encendido, pagar el
                dominio y seguir añadiendo funciones nuevas. Gracias 💛
            </div>
            <button type="button" class="button default" id="donar-go-btn">☕ Donar</button>
            <div class="donar-intro-hint">Pago seguro vía Stripe / PayPal en Ko-fi.</div>
        </div>
    </div>

    <div class="donar-encargos">
        <div class="donar-encargos-title"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"> Encargos</div>
        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/064181251c" target="_blank" rel="noopener"
           data-kofi-title="Haro personalizado">
            <span class="ic"><img src="../../assets/img/appIcons/haroIcon.png" alt="" style="width:18px;height:18px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span><span class="label">Haro personalizado</span><span class="price">5 €</span>
        </a>
        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/4de28dd45e" target="_blank" rel="noopener"
           data-kofi-title="Tema personalizado">
            <span class="ic"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;"></span><span class="label">Tema personalizado</span><span class="price">10 €</span>
        </a>
        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/16c92f9fdf" target="_blank" rel="noopener"
           data-kofi-title="Mascota personalizada">
            <span class="ic"><img src="../../assets/img/appIcons/mascotaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span><span class="label">Mascota personalizada</span><span class="price">15 €</span>
        </a>
        <p class="donar-encargos-info">
            Si no te apetece donar también puedes hacer un encargo para tener
            algo personalizado en tu perfil y no limitarte a las opciones de
            la tienda.
        </p>
    </div>

    <div class="donar-donors-section">
        <div class="donar-donors-title">Quienes han apoyado</div>
        <div class="donar-donors-grid" id="donar-donors-grid">
            <div style="text-align:center;color:var(--text-muted);font-size:11px;padding:18px;grid-column:1/-1;">Cargando…</div>
        </div>
    </div>
</div>

<!-- Status footer (mensajes de "Cargando…", "Error: …", etc.). -->
<div id="tienda-status"></div>

        <!-- Status bar Win98 al pie con "Volver al menú". -->
        <div class="mh-statusbar">
            <a href="#" id="ti-menu-link">‹ Menú</a>
        </div>

    </div><!-- /.window-body -->
</div><!-- /.window.mh-window -->

<script>
/* Botones "‹ Menú": X de la title-bar Win98 + enlace de la status-bar
   abajo. Ambos vuelven al shell de mobile.php vía postMessage (en
   iframe) o history.back si la app está standalone. */
(function(){
    function goMenu(e){
        if (e) e.preventDefault();
        if (window.parent && window.parent !== window) {
            try { window.parent.postMessage({ type: 'shell:back' }, '*'); return; } catch (_) {}
        }
        try { history.back(); } catch (_) { location.href = '../../mobile.php'; }
    }
    var topBtn = document.getElementById('ti-back');
    if (topBtn) topBtn.addEventListener('click', goMenu);
    var bottomLink = document.getElementById('ti-menu-link');
    if (bottomLink) bottomLink.addEventListener('click', goMenu);
})();

/* El handler de tabs vive en el JS principal del desktop (que se
   anexa abajo). Reconoce las clases .tienda-tab + #tienda-donar-btn
   y los atributos data-view / data-cat — mismas que usamos aquí. */

/* Override del botón "Donar": el JS desktop envía postMessage
   {type:'open-kofi'} al parent esperando que desktop-base.php abra
   una ventana iframe con Ko-fi. En el shell de mobile.php no existe
   ese listener, así que el botón se queda sin efecto. Registramos
   nuestro handler ANTES del JS principal y abrimos Ko-fi en una
   pestaña nueva. SIN fallback a location.href: si window.open
   fallara, redirigir la propia pestaña de la PWA rompe el history
   (al volver con el back-button del SO la app queda en mal estado). */
(function(){
    var KOFI_USER = 'melonhub';
    var btn = document.getElementById('donar-go-btn');
    if (!btn) return;
    btn.addEventListener('click', function(){
        window.open('https://ko-fi.com/' + KOFI_USER, '_blank', 'noopener');
    });
})();
</script>

<!-- ====================================================================
     JS PRINCIPAL — copiado de apps/tienda.php con paths ajustados.
     Mantener en sync con el original si se modifica la lógica.
     =================================================================== -->
<script>
(function(){
'use strict';
var API = '../../assets/tienda/api.php';
var KOFI_USER = 'melonhub';

function esc(s){ return String(s||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function setStatus(msg, kind){
    var el = document.getElementById('tienda-status');
    el.textContent = msg || '';
    el.className = 'tienda-status' + (kind === 'error' ? ' is-error' : kind === 'ok' ? ' is-ok' : '');
}
async function api(action, body){
    var opts = { headers:{'Content-Type':'application/json'} };
    if (body) { opts.method='POST'; opts.body=JSON.stringify(body); }
    var r = await fetch(API+'?action='+action, opts);
    return r.json();
}

var _balance = 0, _items = [], _owned = {};
/* Modo regalo (como el PC): la tienda como si fueras esa persona (lo que
   ya tiene, sus regalos pendientes, sus mejoras) y "Comprar" → "Regalar".
   _gift = { key, label, image, discord, owned: {id: true}, pending: {id: true} } */
var _gift = null;
function isOwned(id){ return !!(_gift ? _gift.owned : _owned)[id|0]; }
function isPending(id){ return !!(_gift && _gift.pending[id|0]); }
function lblOwned(){ return _gift ? '✓ Ya lo tiene' : '✓ Ya lo tienes'; }
function lblBuy(){ return _gift ? 'Regalar' : 'Comprar'; }
/* Categoría inicial = la primera pestaña (las pinta PHP desde catalogo.json). */
var _firstTab = document.querySelector('.tienda-tab[data-cat]');
var _activeCat = _firstTab ? _firstTab.dataset.cat : 'discord';

/* SOLO DEV: darse puntos para probar (assets/tienda/api.php dev-grant). */
(function(){
    var b = document.getElementById('tienda-dev-grant');
    if (!b) return;
    b.addEventListener('click', async function(){
        var n = parseInt(document.getElementById('tienda-dev-amount').value, 10) || 0;
        if (n < 1) return;
        b.disabled = true;
        try {
            var r = await api('dev-grant', { amount: n });
            if (r.error) throw new Error(r.error);
            _balance = r.autismo|0;
            renderBalance();
            setStatus('DEV: +' + n + ' puntos', 'ok');
        } catch (e) { setStatus('Error: ' + e.message, 'error'); }
        b.disabled = false;
    });
})();

async function loadState(){
    setStatus('Cargando…');
    try {
        var r = await api('state');
        if (r.error) throw new Error(r.error);
        _balance = r.autismo|0;
        _items   = r.items || [];
        _owned   = {};
        (r.owned || []).forEach(function(id){ _owned[id|0] = true; });
        renderBalance();
        renderItems();
        setStatus('');
    } catch (e) {
        setStatus('Error: ' + e.message, 'error');
    }
}

function renderBalance(){
    document.getElementById('tienda-balance-v').textContent = _balance;
    /* Botones se rehabilitan/deshabilitan según balance Y posesión. */
    document.querySelectorAll('[data-buy-id]').forEach(function(btn){
        var id = btn.dataset.buyId | 0;
        if (isOwned(id)) { btn.disabled = true; btn.textContent = lblOwned(); return; }
        if (isPending(id)) { btn.disabled = true; btn.textContent = 'Regalo pendiente'; return; }
        if (btn.dataset.locked) { btn.disabled = true; return; }   /* falta Discord o el hueco anterior */
        var p = parseInt(btn.dataset.price, 10);
        btn.disabled = p > _balance;
    });
}
/* Compras en cadena de la pestaña Perfil: el objeto que hay que tener
   antes (null = ninguno). Misma regla que tiendaPrevSlug de
   assets/tienda/catalogo.php (la que vale al comprar). */
function tiendaPrevSlug(slug) {
    var m;
    if ((m = /^expositor-hueco-(\d)$/.exec(slug)) && +m[1] > 3) return 'expositor-hueco-' + (m[1] - 1);
    if ((m = /^(media-[a-z]+)-(\d)$/.exec(slug)) && +m[2] > 4) return m[1] + '-' + (m[2] - 1);
    if ((m = /^(steam-(?:rare|ach)-fila)-(\d)$/.exec(slug)) && +m[2] > 2) return m[1] + '-' + (m[2] - 1);
    return null;
}

/* Título y descripción de la sección de las pestañas que no son Perfil
   (como SECCION_TAB de apps/tienda.php). */
var SECCION_TAB = {
    discord:    ['ROLES DE DISCORD', 'Roles para tu cuenta en el servidor de Discord. Para comprarlos tienes que tener tu Discord vinculado.'],
    haros:      ['HAROS ESTÁNDAR', 'Otros colores para el haro que te trae los avisos. Se elige en Temas, en Personalización.'],
    interfaces: ['INTERFACES', 'Cambian el aspecto de todo Melon Hub. Se elige en Temas, en Personalización.'],
};
/* FUENTES: muestra "Aa Ññ" escrita con la fuente (mh-fuente-muestra la
   libra de la fuente puesta en la página, assets/php/fuentes.php) y su
   CSS de Google Fonts, una vez cada una. */
function fuenteEstilo(it) {
    return 'font-family:&quot;' + esc(it.fuente.familia) + '&quot;, sans-serif;';
}
function fuenteMuestra(it) {
    return '<span class="tienda-fuente-prev mh-fuente-muestra" style="' + fuenteEstilo(it) + '">Aa Ññ</span>';
}
var _fuentesCargadas = {};
function cargaFuentes(items) {
    items.forEach(function(it){
        var u = it.fuente && it.fuente.url;
        if (!u || _fuentesCargadas[u]) return;
        _fuentesCargadas[u] = true;
        var l = document.createElement('link');
        l.rel = 'stylesheet'; l.href = u;
        document.head.appendChild(l);
    });
}

/* Tu foto (va dentro de la vista previa de los marcos). */
var MY_AVATAR = <?php $_myAv = getUserImage($userLabel); echo json_encode($_myAv ? '../../' . $_myAv : ''); ?>;

/* ── Pestaña Perfil, por secciones (como renderPerfil de apps/tienda.php) ──
   Cadenas de mejoras: una tarjeta ancha cada una, que va cambiando según
   lo que ya tengas (el primero que no tienes es el que se vende); con
   todo comprado sale "✓ Completo". */
var SLOT_SLUGS_PERFIL = ['expositor-hueco-3', 'expositor-hueco-4', 'expositor-hueco-5'];
var EXPO_ADICIONALES = ['expositor-resena', 'expositor-juego-destacado', 'expositor-playlist'];
var CADENAS_PERFIL = [
    { slugs: SLOT_SLUGS_PERFIL, icono: 'profileIcon', base: 2, cosa: 'huecos', nombre: 'Huecos de expositor',
      fin: function(n){ return 'Ya tiene' + (_gift ? '' : 's') + ' todos los huecos: ' + n + ' expositores a la vez en ' + (_gift ? 'su' : 'tu') + ' perfil.'; } }
].concat([['games', 'juegosIcon', 'Juegos favoritos', 'juegos'], ['movies', 'pelisIcon', 'Películas favoritas', 'películas'],
          ['series', 'melonArchiveIcon', 'Series favoritas', 'series'], ['books', 'booksIcon', 'Libros favoritos', 'libros'],
          ['music', 'musicaIcon', 'Música favorita', 'canciones']].map(function(m){
    return { slugs: ['media-' + m[0] + '-4', 'media-' + m[0] + '-5'], icono: m[1], base: 3, cosa: m[3], nombre: m[2],
             fin: function(n){ return 'Ya tiene' + (_gift ? '' : 's') + ' todos los huecos: ' + n + ' ' + m[3] + ' en ' + (_gift ? 'su' : 'tu') + ' Media favorita.'; } };
})).concat([['rare', 'Logros raros de Steam'], ['ach', 'Logros de Steam']].map(function(m){
    return { slugs: ['steam-' + m[0] + '-fila-2', 'steam-' + m[0] + '-fila-3'], icono: 'steamIcon', base: 1,
             cosa: 'filas', nombre: m[1], fin: function(n){ return 'Ya tiene' + (_gift ? '' : 's') + ' las ' + n + ' filas: ' + (n * 7) + ' logros en el expositor.'; } };
}));
function perfilPrecio(p) {
    return p + ' <img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;">';
}
function perfilSeccion(titulo, sub, cuerpo, cls) {
    return '<section class="tienda-sec"><h3 class="tienda-sec-h">' + esc(titulo) + '</h3>' +
        (sub ? '<div class="tienda-sec-sub">' + esc(sub) + '</div>' : '') +
        '<div class="' + cls + '">' + cuerpo + '</div></section>';
}
/* El fueguito de la racha encendido (el de perfil-v2.js): icono del
   Protector de racha. */
function rachaFuego() {
    var fuera = [[6,6],[5,6],[5,7],[4,7],[4,8],[3,8],[2,9],[2,9],[1,10],[1,10],[1,10],[2,9],[3,8],[4,7]];
    var dentro = { 7: [5,6], 8: [4,7], 9: [4,7], 10: [4,7], 11: [5,6] };
    var r = '';
    fuera.forEach(function(x, y){ r += '<rect x="' + x[0] + '" y="' + y + '" width="' + (x[1] - x[0] + 1) + '" height="1" fill="#ff7a1a"/>'; });
    Object.keys(dentro).forEach(function(y){ var x = dentro[y]; r += '<rect x="' + x[0] + '" y="' + y + '" width="' + (x[1] - x[0] + 1) + '" height="1" fill="#ffd34d"/>'; });
    return '<svg class="tienda-fuego" viewBox="0 0 12 14" shape-rendering="crispEdges" aria-hidden="true">' + r + '</svg>';
}
/* Tarjeta normal (las de 2 en 2). */
function perfilTarjeta(it, iconHtml) {
    var owned = isOwned(it.id);
    return '<div class="tienda-card' + (owned ? ' is-owned' : '') + '">' +
        '<div class="tienda-card-icon">' + iconHtml + '<span class="tienda-card-price">' + perfilPrecio(it.precio) + '</span></div>' +
        '<div class="tienda-card-name">' + esc(it.nombre) + '</div>' +
        '<div class="tienda-card-desc">' + esc(it.descripcion || '') + '</div>' +
        '<button type="button" class="button" data-buy-id="' + it.id + '" data-price="' + it.precio + '">' + (owned ? lblOwned() : lblBuy()) + '</button>' +
    '</div>';
}
function renderPerfil(view, items) {
    var marcos = items.filter(function(it){ return !!it.marco; });
    var enCadena = {};
    CADENAS_PERFIL.forEach(function(c){ c.slugs.forEach(function(sl){ enCadena[sl] = true; }); });
    var adicionales = EXPO_ADICIONALES.map(function(sl){ return items.filter(function(it){ return it.slug === sl; })[0]; }).filter(Boolean);
    var racha = items.filter(function(it){ return it.slug === 'protector-racha'; });
    var otros = items.filter(function(it){ return !it.marco && !enCadena[it.slug] && EXPO_ADICIONALES.indexOf(it.slug) < 0 && it.slug !== 'protector-racha'; });
    /* En modo regalo, los marcos se ven con la foto de esa persona. */
    var foto = (_gift ? _gift.image : MY_AVATAR) || '../../assets/img/appIcons/profileIcon.png';
    var html = '';
    if (marcos.length) {
        html += perfilSeccion('MARCOS DE AVATAR', 'Un marco alrededor de tu foto en el perfil, el chat, Social y los comentarios. Se elige en Personalizar del perfil.',
            marcos.map(function(it){
                return perfilTarjeta(it, '<span class="tienda-marco-prev is-foto" data-marco="' + esc(it.slug) + '"><img src="' + esc(foto) + '" alt=""></span>');
            }).join(''), 'tienda-sec-grid');
    }
    var anchas = CADENAS_PERFIL.map(function(c){
        var cad = c.slugs.map(function(sl){ return items.filter(function(it){ return it.slug === sl; })[0]; }).filter(Boolean);
        if (!cad.length) return '';
        var tienes = cad.filter(function(it){ return isOwned(it.id); }).length;
        var sig = cad.filter(function(it){ return !isOwned(it.id); })[0];
        var total = c.base + cad.length;
        var pips = '';
        for (var i = 0; i < cad.length; i++) pips += '<i class="' + (i < tienes ? 'on' : '') + '"></i>';
        return '<div class="tienda-card tienda-wide' + (sig ? '' : ' is-owned') + '">' +
            '<div class="tienda-wide-icon"><img src="../../assets/img/appIcons/' + c.icono + '.png" alt=""></div>' +
            '<div class="tienda-wide-body">' +
                '<div class="tienda-wide-name">' + esc(c.nombre) + '</div>' +
                '<div class="tienda-wide-type">Mejora de expositor · ' + (_gift ? 'tiene ' : 'tienes ') + (c.base + tienes) + ' de ' + total + ' ' + c.cosa + ' <span class="tienda-wide-pips">' + pips + '</span></div>' +
                '<div class="tienda-wide-desc">' + esc(sig ? (sig.descripcion || '') : c.fin(total)) + '</div>' +
                '<div class="tienda-wide-foot">' +
                    (sig ? '<span class="tienda-wide-price">' + perfilPrecio(sig.precio) + '</span>' +
                           '<button type="button" class="button" data-buy-id="' + sig.id + '" data-price="' + sig.precio + '">' + lblBuy() + '</button>'
                         : '<span class="tienda-wide-done">✓ Completo</span>') +
                '</div>' +
            '</div></div>';
    }).join('');
    if (anchas) {
        html += perfilSeccion('SUBIR DE NIVEL EXPOSITORES', 'Más huecos de expositor, más elementos en Media favorita (de 3 a 5, por tipo) y más filas en los expositores de logros de Steam (de 1 a 3).', anchas, 'tienda-sec-wide');
    }
    if (adicionales.length) {
        var iconos = { 'expositor-resena': 'bestOfTheYearIcon', 'expositor-juego-destacado': 'juegosIcon', 'expositor-playlist': 'songIcon' };
        html += perfilSeccion('EXPOSITORES ADICIONALES', 'Expositores nuevos para tu perfil. Se ponen en Personalizar, en un hueco libre.', adicionales.map(function(it){
            return perfilTarjeta(it, '<img class="tienda-card-icon-img" src="../../assets/img/appIcons/' + iconos[it.slug] + '.png" alt="" style="image-rendering:pixelated;">');
        }).join(''), 'tienda-sec-grid');
    }
    /* Protector de racha (assets/profile/racha.php): se gasta al salvar la
       racha; mientras está guardado sale como "Ya lo tienes". */
    if (racha.length) {
        html += perfilSeccion('RACHA', 'La racha se enciende cada día con el fueguito que sale al lado de tu nombre en el perfil.',
            racha.map(function(it){ return perfilTarjeta(it, rachaFuego()); }).join(''), 'tienda-sec-grid');
    }
    if (otros.length) {
        html += perfilSeccion('OTROS', '', otros.map(function(it){
            return perfilTarjeta(it, '<img class="tienda-card-icon-img" src="../../assets/img/appIcons/profileIcon.png" alt="" style="image-rendering:pixelated;">');
        }).join(''), 'tienda-sec-grid');
    }
    view.innerHTML = html;
    view.querySelectorAll('[data-buy-id]').forEach(function(btn){
        btn.addEventListener('click', function(){ buy(parseInt(btn.dataset.buyId, 10), btn); });
    });
    renderBalance();
}

function renderItems(){
    var view = document.getElementById('tienda-view-principal');
    /* Perfil va por secciones (renderPerfil); Discord, Haros e
       Interfaces, en una sección con título (SECCION_TAB, como el PC). */
    view.classList.toggle('is-secciones', _activeCat === 'perfil' || !!SECCION_TAB[_activeCat]);
    /* Pestañas con "proximamente" en catalogo.json (ahora Mascotas): placeholder.
       height:100% rellena el alto visible del .ti-pane (flex:1), así
       el flex-center realmente centra el texto en pantalla. */
    var _tab = document.querySelector('.tienda-tab[data-cat="' + _activeCat + '"]');
    if (_tab && _tab.dataset.soon) {
        view.innerHTML = '<div class="tienda-empty" style="display:flex;align-items:center;justify-content:center;height:100%;font-size:22px;">Coming soon...</div>';
        return;
    }
    var items = _items.filter(function(it){ return (it.categoria || 'discord') === _activeCat; });
    if (!items.length) {
        view.innerHTML = '<div class="tienda-empty">No hay items en esta categoría todavía.</div>';
        return;
    }
    if (_activeCat === 'perfil') { renderPerfil(view, items); return; }
    var cards = items.map(function(it){
        /* El nombre visible es el del rol de Discord (si lo lleva); si no,
           cae al nombre interno del item. Coloreamos el texto del nombre
           con el color del rol cuando exista, así se mantiene la identidad
           visual sin necesidad de un badge aparte. */
        var displayName = it.discord_role_name || it.nombre;
        var nameStyle = '';
        if (it.discord_role_name) {
            var c = it.discord_role_color | 0;
            if (c > 0) {
                var hex = '#' + ('000000' + c.toString(16)).slice(-6);
                nameStyle = ' style="color:' + hex + ';"';
            }
        }
        /* Descripción dinámica: si hay rol, genera la frase con el nombre
           real; si no, cae al campo `descripcion` de la BD como fallback. */
        var desc = it.discord_role_name
            ? 'Adquiere el rol ' + it.discord_role_name + ' en :melonduagua: 3.0'
            : (it.descripcion || '');
        var owned = isOwned(it.id);
        /* Items con rol de Discord requieren vinculación obligatoria.
           El server también lo enforza (403) por si el frontend se parchea. */
        var needsDiscord  = !!it.discord_role_name;
        var discordLinked = _gift ? _gift.discord : window.__DISCORD_LINKED__ === true;
        var locked        = needsDiscord && !discordLinked && !owned;
        /* Compras en cadena del perfil (huecos, mejoras de expositor): en
           orden (el 4 pide el 3…). */
        var prevSlug = tiendaPrevSlug(it.slug || '');
        var needsPrev = false;
        if (prevSlug && !owned) {
            var prev = _items.filter(function(x){ return x.slug === prevSlug; })[0];
            needsPrev = !prev || !isOwned(prev.id);
        }
        var prevHueco = prevSlug && /^expositor-hueco-(\d)$/.exec(prevSlug);
        var btnLabel = owned     ? lblOwned()
                     : locked    ? (_gift ? 'No tiene Discord' : 'Vincula Discord')
                     : needsPrev ? (prevHueco ? 'Antes, el hueco ' + prevHueco[1] : 'Antes, la mejora anterior')
                                 : lblBuy();
        var btnAttrs = (locked || needsPrev) ? ' disabled data-locked="1"' : '';
        /* Para los haros el icono usa la convención
           assets/img/haro/{slug}Haro-preview.png (PNG curado).
           Si no existe, cae al último frame del gif vía onerror. */
        var iconHtml;
        if (it.categoria === 'haros' && it.slug) {
            iconHtml = '<img class="tienda-card-icon-img"'
                + ' src="../../assets/img/haro/' + esc(it.slug) + 'Haro-preview.png"'
                + ' onerror="this.onerror=null;this.src=\'../../assets/vids/' + esc(it.slug) + 'Haro-last.png\';"'
                + ' alt="">';
        } else {
            iconHtml = (it.categoria === 'perfil' && it.marco)
                /* Marcos: el marco alrededor del icono de perfil del pack. */
                ? '<span class="tienda-marco-prev" data-marco="' + esc(it.slug) + '"><img src="../../assets/img/appIcons/profileIcon.png" alt=""></span>'
                : it.categoria === 'perfil'
                ? '<img class="tienda-card-icon-img" src="../../assets/img/appIcons/profileIcon.png" alt="" style="image-rendering:pixelated;">'
                : it.fuente ? fuenteMuestra(it)
                : esc(it.icono || '🎁');
        }
        /* Fuentes: el nombre también con su fuente. */
        if (it.fuente) nameStyle = ' style="' + fuenteEstilo(it) + '"';
        return '<div class="tienda-card' + (owned ? ' is-owned' : '') + (it.fuente ? ' is-fuente' : '') + '">' +
            '<div class="tienda-card-icon">' +
                iconHtml +
                '<span class="tienda-card-price">' + it.precio + ' <img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span>' +
            '</div>' +
            '<div class="tienda-card-name' + (it.fuente ? ' mh-fuente-muestra' : '') + '"' + nameStyle + '>' + esc(displayName) + '</div>' +
            '<div class="tienda-card-desc">' + esc(desc) + '</div>' +
            '<button type="button" class="button" data-buy-id="' + it.id + '" data-price="' + it.precio + '"' + btnAttrs + '>' + btnLabel + '</button>' +
        '</div>';
    });
    var sec = SECCION_TAB[_activeCat];
    if (_activeCat === 'interfaces') {
        /* Interfaces y FUENTES (assets/php/fuentes.php), cada una en su
           sección. Las muestras se pintan con la fuente de cada tarjeta. */
        var ifc = [], fts = [];
        items.forEach(function(it, i){ (it.fuente ? fts : ifc).push(cards[i]); });
        view.innerHTML = (ifc.length ? perfilSeccion(sec[0], sec[1], ifc.join(''), 'tienda-sec-grid') : '') +
            (fts.length ? perfilSeccion('FUENTES', 'Cambian la letra de todo Melon Hub. Cada interfaz guarda la suya. Se elige en Temas, en Personalización.', fts.join(''), 'tienda-sec-grid') : '');
        cargaFuentes(items);
    } else {
        cards = cards.join('');
        view.innerHTML = sec ? perfilSeccion(sec[0], sec[1], cards, 'tienda-sec-grid') : cards;
    }
    view.querySelectorAll('[data-buy-id]').forEach(function(btn){
        btn.addEventListener('click', function(){ buy(parseInt(btn.dataset.buyId, 10), btn); });
    });
    renderBalance();
}

async function buy(itemId, btn){
    if (_gift) { openGiftConfirm(itemId); return; }
    btn.disabled = true;
    setStatus('Comprando…');
    try {
        var r = await api('buy', { item_id: itemId });
        if (r.error) throw new Error(r.error);
        _balance = r.autismo|0;
        _owned[itemId|0] = true;     /* marcar como propio inmediatamente */
        renderItems();               /* repinta para que la card pase a is-owned */
        var msg = 'Compra realizada: ' + r.item.nombre + ' (-' + r.item.precio + ' puntos)';
        if (r.discord && r.discord.attempted) {
            msg += r.discord.ok
                ? ' · 🎉 rol de Discord asignado'
                : ' · ⚠ rol no asignado (' + (r.discord.error || 'error') + ')';
        }
        setStatus(msg, 'ok');
    } catch (e) {
        setStatus('Error: ' + e.message, 'error');
        btn.disabled = false;
    }
}

/* ═══════════════════ REGALOS (como los del PC) ═══════════════════
   Botón "Regalos (N)" de la cabecera → ventana con los regalos sin abrir
   y "Regalar a un amigo" → eliges un amigo → modo regalo (barra arriba,
   la tienda como si fueras esa persona) → "Regalar" en un objeto →
   ventana con mensaje opcional (200) → se cobra y le queda pendiente.
   Los que te mandan: aviso de la carcasa ("Abrir" → ?regalo=ID) o desde
   la ventana: el objeto, quién lo manda y el mensaje; Aceptar / Rechazar
   (devuelve los puntos). Las fotos de la API van con "../" (apps/): aquí
   hace falta otro. */
var _gifts = [];
function fotoApi(p) { return p ? '../' + p : ''; }
function avHtml(p) {
    return '<span class="tienda-av" data-marco-user="' + esc(p.key) + '">' +
        (p.image ? '<img src="' + esc(fotoApi(p.image)) + '" alt="">'
                 : '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="image-rendering:pixelated;">') + '</span>';
}
function perfilIcono(slug) {
    var m = /^media-([a-z]+)-/.exec(slug || '');
    if (m) return { games: 'juegosIcon', movies: 'pelisIcon', series: 'melonArchiveIcon', books: 'booksIcon', music: 'musicaIcon' }[m[1]] || 'profileIcon';
    if (/^steam-/.test(slug)) return 'steamIcon';
    return { 'expositor-resena': 'bestOfTheYearIcon', 'expositor-juego-destacado': 'juegosIcon', 'expositor-playlist': 'songIcon' }[slug] || 'profileIcon';
}
/* Icono de un objeto en las ventanas de regalo (como en su tarjeta). */
function itemIconHtml(it, foto) {
    if (it.categoria === 'haros' && it.slug) {
        return '<img class="tienda-card-icon-img" src="../../assets/img/haro/' + esc(it.slug) + 'Haro-preview.png"' +
            ' onerror="this.onerror=null;this.src=\'../../assets/vids/' + esc(it.slug) + 'Haro-last.png\';" alt="">';
    }
    if (it.categoria === 'perfil' && /^marco-/.test(it.slug || '')) {
        return '<span class="tienda-marco-prev is-foto" data-marco="' + esc(it.slug) + '"><img src="' + esc(foto || '../../assets/img/appIcons/profileIcon.png') + '" alt=""></span>';
    }
    if (it.slug === 'protector-racha') return rachaFuego();
    if (it.fuente) { cargaFuentes([it]); return fuenteMuestra(it); }
    if (it.categoria === 'perfil') {
        return '<img class="tienda-card-icon-img" src="../../assets/img/appIcons/' + perfilIcono(it.slug) + '.png" alt="" style="image-rendering:pixelated;">';
    }
    return '<span>' + esc(it.icono || '🎁') + '</span>';
}
function itemTexts(it) {
    return {
        nombre: it.discord_role_name || it.nombre,
        desc: it.discord_role_name ? 'Adquiere el rol ' + it.discord_role_name + ' en :melonduagua: 3.0' : (it.descripcion || ''),
    };
}
function itemBlock(it, foto, extra) {
    var tx = itemTexts(it);
    return '<div class="tienda-modal-item"><div class="tienda-card-icon">' + itemIconHtml(it, foto) + '</div>' +
        '<div><div class="tienda-modal-name">' + esc(tx.nombre) + '</div><div class="tienda-modal-desc">' + esc(tx.desc) + '</div>' + (extra || '') + '</div></div>';
}
var GIFT_IC = '<img src="../../assets/img/appIcons/tiendaIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">';
/* Ventana Win98 sobre la tienda. */
function tiendaModal(titleHtml) {
    var bg = document.createElement('div');
    bg.className = 'tienda-modal-bg';
    bg.innerHTML = '<div class="window tienda-modal"><div class="title-bar"><div class="title-bar-text">' + titleHtml + '</div>' +
        '<div class="title-bar-controls"><button aria-label="Close"></button></div></div><div class="window-body"></div></div>';
    document.body.appendChild(bg);
    var close = function () { bg.remove(); };
    bg.querySelector('[aria-label="Close"]').addEventListener('click', close);
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    return { body: bg.querySelector('.window-body'), close: close };
}

async function loadGifts() {
    try { var r = await api('gifts'); _gifts = (r && r.gifts) || []; } catch (_) { _gifts = []; }
    var b = document.getElementById('tienda-gifts-btn');
    if (b) b.textContent = 'Regalos' + (_gifts.length ? ' (' + _gifts.length + ')' : '');
}
/* La ventana del botón "Regalos". */
function openGiftsWindow() {
    var m = tiendaModal(GIFT_IC + 'Regalos');
    m.body.innerHTML = (_gifts.length
        ? '<div class="tienda-modal-h">Sin abrir</div><div class="tienda-friends">' + _gifts.map(function (g) {
            return '<div class="tienda-friend" data-gift="' + g.id + '">' + avHtml(g.from) + '<span>De ' + esc(g.from.label) + '</span><i>›</i></div>';
          }).join('') + '</div>'
        : '<div class="tienda-modal-desc">No tienes regalos sin abrir.</div>') +
        '<div class="tienda-modal-foot"><button type="button" class="button" data-a="regalar" style="flex:1;">Regalar a un amigo</button></div>';
    m.body.querySelectorAll('[data-gift]').forEach(function (row) {
        row.addEventListener('click', function () { m.close(); openGift(+row.dataset.gift); });
    });
    m.body.querySelector('[data-a="regalar"]').addEventListener('click', function () { m.close(); openFriendPicker(); });
}
function openGift(id) {
    var g = _gifts.filter(function (x) { return x.id === id; })[0];
    if (!g) { setStatus('Ese regalo ya no está pendiente.', 'error'); return; }
    var it = _items.filter(function (x) { return (x.id|0) === g.item.id; })[0] || g.item;
    var m = tiendaModal(GIFT_IC + 'Regalo de ' + esc(g.from.label));
    m.body.innerHTML =
        '<div class="tienda-modal-from">' + avHtml(g.from) + '<span><b>' + esc(g.from.label) + '</b> te ha enviado un regalo</span></div>' +
        itemBlock(it, MY_AVATAR) +
        (g.mensaje ? '<div class="tienda-modal-msg"></div>' : '') +
        '<div class="tienda-modal-foot"><button type="button" class="button" data-a="no">Rechazar</button><button type="button" class="button" data-a="si">Aceptar</button></div>';
    if (g.mensaje) m.body.querySelector('.tienda-modal-msg').textContent = g.mensaje;
    var answer = async function (accept) {
        m.body.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        try {
            var r = await api('gift-answer', { id: g.id, accept: accept });
            if (r.error) throw new Error(r.error);
            m.close();
            /* Su aviso en la carcasa (mobile.php), si sigue a la vista. */
            try { var t = window.parent.document.getElementById('m-toast-regalo-' + g.id); if (t) t.remove(); } catch (_) {}
            if (r.accepted) {
                _owned[g.item.id] = true;
                var msg = 'Regalo aceptado: ' + itemTexts(it).nombre;
                if (r.discord && r.discord.attempted) msg += ' · 🎉 rol de Discord asignado';
                setStatus(msg, 'ok');
            } else {
                setStatus(r.refunded || ('Regalo rechazado: se le devuelven los puntos a ' + g.from.label + '.'), r.refunded ? 'error' : 'ok');
            }
            loadGifts();
            renderItems();
        } catch (e) {
            setStatus('Error: ' + e.message, 'error');
            m.body.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        }
    };
    m.body.querySelector('[data-a="si"]').addEventListener('click', function () { answer(true); });
    m.body.querySelector('[data-a="no"]').addEventListener('click', function () { answer(false); });
}
async function openFriendPicker() {
    var m = tiendaModal(GIFT_IC + 'Regalar a un amigo');
    m.body.innerHTML = '<div>Elige a quién le quieres hacer un regalo. La tienda se verá como si fueras esa persona.</div>' +
        '<div class="tienda-friends"><div class="tienda-empty">Cargando…</div></div>';
    var box = m.body.querySelector('.tienda-friends');
    var r;
    try { r = await api('gift-friends'); } catch (_) { r = null; }
    var fr = (r && r.friends) || [];
    box.innerHTML = fr.length ? fr.map(function (f, i) {
        return '<div class="tienda-friend" data-i="' + i + '">' + avHtml(f) + '<span>' + esc(f.label) + '</span></div>';
    }).join('') : '<div class="tienda-empty" style="padding:12px;">Aún no tienes amigos a los que regalar.</div>';
    box.querySelectorAll('.tienda-friend').forEach(function (row) {
        row.addEventListener('click', function () { m.close(); enterGiftMode(fr[+row.dataset.i].key); });
    });
}
async function enterGiftMode(key) {
    setStatus('Cargando…');
    try {
        var r = await api('gift-target&user=' + encodeURIComponent(key));
        if (r.error) throw new Error(r.error);
        var owned = {}, pending = {};
        (r.owned || []).forEach(function (id) { owned[id|0] = true; });
        (r.pending || []).forEach(function (id) { pending[id|0] = true; });
        _gift = { key: r.key, label: r.label, image: fotoApi(r.image), discord: !!r.discord, owned: owned, pending: pending };
        renderGiftBar();
        renderItems();
        setStatus('');
    } catch (e) { setStatus('Error: ' + e.message, 'error'); }
}
function exitGiftMode() { _gift = null; renderGiftBar(); renderItems(); }
function renderGiftBar() {
    var bar = document.getElementById('tienda-gift-bar');
    bar.classList.toggle('on', !!_gift);
    if (!_gift) { bar.innerHTML = ''; return; }
    bar.innerHTML = avHtml({ key: _gift.key, image: _gift.image ? _gift.image.replace(/^\.\.\//, '') : '' }) +
        '<span class="tienda-gift-txt">Regalando a <b>' + esc(_gift.label) + '</b>. Lo que ya tiene sale como "Ya lo tiene".</span>' +
        '<button type="button" class="button">Salir</button>';
    bar.querySelector('button').addEventListener('click', exitGiftMode);
}
function openGiftConfirm(itemId) {
    var it = _items.filter(function (x) { return (x.id|0) === (itemId|0); })[0];
    if (!it || !_gift) return;
    var to = _gift;
    var m = tiendaModal(GIFT_IC + 'Regalar a ' + esc(to.label));
    m.body.innerHTML = itemBlock(it, to.image, '<div style="margin-top:6px;">Precio: <b>' + perfilPrecio(it.precio) + '</b></div>') +
        '<div>Mensaje (opcional):</div><textarea rows="3" maxlength="200"></textarea>' +
        '<div class="tienda-modal-foot"><span class="tienda-modal-desc" style="margin-right:auto;" data-r="n">0 / 200</span>' +
        '<button type="button" class="button" data-a="no">Cancelar</button><button type="button" class="button" data-a="si">Regalar</button></div>';
    var ta = m.body.querySelector('textarea'), ok = m.body.querySelector('[data-a="si"]');
    ta.addEventListener('input', function () { m.body.querySelector('[data-r="n"]').textContent = ta.value.length + ' / 200'; });
    ok.disabled = it.precio > _balance;
    m.body.querySelector('[data-a="no"]').addEventListener('click', m.close);
    ok.addEventListener('click', async function () {
        ok.disabled = true;
        try {
            var r = await api('gift', { item_id: it.id, to: to.key, mensaje: ta.value.trim() });
            if (r.error) throw new Error(r.error);
            _balance = r.autismo|0;
            if (_gift && _gift.key === to.key) _gift.pending[it.id|0] = true;
            m.close();
            renderItems();
            setStatus('Regalo enviado a ' + to.label + ': ' + itemTexts(it).nombre + ' (-' + r.item.precio + ' puntos)', 'ok');
        } catch (e) {
            setStatus('Error: ' + e.message, 'error');
            ok.disabled = false;
        }
    });
}
document.getElementById('tienda-gifts-btn').addEventListener('click', openGiftsWindow);
/* Los regalos: al entrar (y si viene ?regalo=ID del aviso, se abre ese
   cuando ya están los objetos) y cuando el timbre de la carcasa dice que
   ha llegado uno ('regalo'). */
(function () {
    var m = /[?&]regalo=(\d+)/.exec(location.search);
    var pedido = m ? +m[1] : 0;
    var esperaObjetos = function () {
        return new Promise(function (ok) { (function mira() { if (_items.length) ok(); else setTimeout(mira, 150); })(); });
    };
    loadGifts().then(function () {
        if (!pedido) return;
        esperaObjetos().then(function () { openGift(pedido); });
        try { history.replaceState(null, '', location.pathname); } catch (_) {}
    });
    var timbre = null;
    try { timbre = window.parent !== window ? window.parent.melonTimbre : null; } catch (e) {}
    if (timbre) {
        var baja = timbre.on('regalo', loadGifts);
        window.addEventListener('pagehide', function () { try { baja(); } catch (_) {} });
    }
})();

/* ── Cambio de vista ──
   Cubre tanto los .tienda-tab (Principal) como el botón #tienda-donar-btn
   (Donaciones). La tab usa la clase .active (background acento), el botón
   usa .is-active (bezel pulsado) — visualmente coherente con su rol. */
document.querySelectorAll('[data-view]').forEach(function(el){
    el.addEventListener('click', function(){
        var view = el.dataset.view;
        document.querySelectorAll('.tienda-tab').forEach(function(t){ t.classList.remove('active'); });
        var donar = document.getElementById('tienda-donar-btn');
        donar.classList.remove('is-active');
        if (el.classList.contains('tienda-tab')) el.classList.add('active');
        else el === donar && donar.classList.add('is-active');
        var isDon = view === 'donaciones';
        document.getElementById('tienda-view-principal').style.display = isDon ? 'none' : '';
        document.getElementById('tienda-view-donaciones').classList.toggle('is-active', isDon);
        /* Si la tab pulsada lleva categoría, repintamos el grid filtrando. */
        if (!isDon && el.dataset.cat) {
            _activeCat = el.dataset.cat;
            renderItems();
        }
    });
});

/* ═══ DONACIONES ═══ */
(function(){
    var grid = document.getElementById('donar-donors-grid');
    var pollHandle = null;
    var lastJSON = null;

    function renderDonors(donors){
        if (!donors.length) {
            grid.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:11px;padding:20px;width:100%;">Aún no hay donantes. ¡Podrías ser el primero!</div>';
            return;
        }
        var TIPOS = {
            donacion:    { ic: '', label: 'Donante'     },
            suscripcion: { ic: '', label: 'Suscriptor'  },
            encargo:     { ic: '', label: 'Encargo'     },
        };
        grid.innerHTML = donors.map(function(d){
            var av = d.avatar_url
                ? '<img src="' + esc(d.avatar_url) + '" alt="" referrerpolicy="no-referrer">'
                : '<img src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin:0 4px 0 0;">';
            var tipo = TIPOS[d.tipo] || TIPOS.donacion;
            var tipoEl = '<div class="donar-donor-tipo ' + esc(d.tipo || 'donacion') + '">' +
                         (tipo.ic ? '<span>' + tipo.ic + '</span>' : '') +
                         '<span>' + tipo.label + '</span></div>';
            var msg = d.mensaje ? '<div class="donar-donor-msg">' + esc(d.mensaje) + '</div>' : '';
            return '<div class="donar-donor">' +
                '<div class="donar-donor-avatar">' + av + '</div>' +
                '<div class="donar-donor-name">' + esc(d.nombre) + '</div>' +
                tipoEl +
                msg +
            '</div>';
        }).join('');
    }

    async function loadDonors(){
        try {
            var r = await api('donors');
            if (r.error) throw new Error(r.error);
            /* Solo re-renderizamos si la respuesta cambió — evita parpadeos
               inútiles del DOM en cada poll. */
            var json = JSON.stringify(r.donors || []);
            if (json !== lastJSON) {
                lastJSON = json;
                renderDonors(r.donors || []);
            }
        } catch (e) {
            grid.innerHTML = '<div style="text-align:center;color:var(--error-text);font-size:11px;padding:20px;width:100%;">No se pudo cargar la lista: ' + esc(e.message) + '</div>';
        }
    }

    /* Polling cada 30 s mientras la pestaña Donaciones está visible. El
       webhook de Ko-fi guarda en BD y la siguiente vuelta del poll lo
       muestra sin necesidad de recargar la app. */
    /* La lista solo se pide cuando cambia rt/donantes.json (lo reescribe
       el webhook de Ko-fi; mirarlo no gasta PHP ni BD). Si no responde,
       la lista cada 30 s como antes. */
    var donantesVisto = null;
    function mirarDonantes(){
        fetch('../../rt/donantes.json', { cache: 'no-cache', credentials: 'same-origin' })
            .then(function(r){ if (!r.ok) throw new Error('donantes ' + r.status); return r.json(); })
            .then(function(d){
                if (donantesVisto !== null && d.v !== donantesVisto) loadDonors();
                donantesVisto = d.v;
            })
            .catch(function(){ loadDonors(); });
    }
    function startPolling(){
        if (pollHandle) return;
        donantesVisto = null;
        mirarDonantes();
        pollHandle = setInterval(mirarDonantes, 30000);
    }
    function stopPolling(){
        if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
    }

    /* Pide al desktop que abra la ventana de Ko-fi (iframe aparte).
       El handler vive en desktop-base.php → listener 'message'. */
    document.getElementById('donar-go-btn').addEventListener('click', function(){
        try {
            window.parent.postMessage({ type: 'open-kofi' }, '*');
        } catch (e) {}
        /* Refresco optimista cuando el usuario vuelva a esta pestaña. */
        setTimeout(loadDonors, 500);
    });

    /* Botones de encargos: las páginas /c/xxx de Ko-fi mandan
       X-Frame-Options:SAMEORIGIN que prohíbe embeberlas en iframe (al
       contrario que la página de donaciones, que sí permite ?embed=true).
       Por eso aquí abrimos popup centrada en lugar de la ventana iframe.
       El `target="_blank"` se conserva como fallback (click derecho →
       "Abrir en pestaña nueva" sigue funcionando si el navegador bloquea
       la popup). */
    document.querySelectorAll('.donar-encargo-btn').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            var w = 480, h = 740;
            var l = (window.screen.width  - w) / 2;
            var t = (window.screen.height - h) / 2;
            var win = window.open(a.href, 'kofi-commission',
                'width=' + w + ',height=' + h + ',left=' + l + ',top=' + t +
                ',menubar=no,toolbar=no,location=no,status=no'
            );
            /* Si el navegador bloqueó la popup, caemos a pestaña nueva. */
            if (!win) window.open(a.href, '_blank', 'noopener');
            setTimeout(loadDonors, 500);
        });
    });

    /* Las pestañas de la sidebar disparan el poll cuando entras en
       Donaciones y lo paran cuando sales (ahorra requests). */
    document.querySelectorAll('[data-view]').forEach(function(el){
        el.addEventListener('click', function(){
            if (el.dataset.view === 'donaciones') { loadDonors(); startPolling(); }
            else { stopPolling(); }
        });
    });

    loadDonors();
})();

/* ═══ DISCORD ═══ */
(function(){
    var nameEl = document.getElementById('tienda-discord-name');
    var btn    = document.getElementById('tienda-discord-btn');
    var linked = false;
    window.__DISCORD_LINKED__ = false;
    var DISCORD_BASE = '../../assets/discord-oauth';

    function render(){
        if (linked) {
            btn.textContent = 'Desvincular';
        } else {
            nameEl.textContent = '';
            btn.textContent    = 'Conectar Discord';
        }
    }

    async function refresh(){
        try {
            var r = await fetch(DISCORD_BASE + '/status.php', { credentials: 'same-origin' }).then(function(x){ return x.json(); });
            if (r.error) throw new Error(r.error);
            linked = !!r.linked;
            window.__DISCORD_LINKED__ = linked;
            nameEl.textContent = linked && r.username ? '@' + r.username : '';
            render();
            /* Re-render de items para refrescar el bloqueo de los Discord. */
            if (typeof renderItems === 'function') renderItems();
        } catch (e) {
            nameEl.textContent = '';
            btn.textContent    = 'Conectar Discord';
        }
    }

    function openOAuth(){
        var url = DISCORD_BASE + '/start.php';
        var w = 520, h = 700;
        var l = (window.screen.width  - w) / 2;
        var t = (window.screen.height - h) / 2;
        /* Como esta página vive en un iframe, abrimos la popup desde el
           opener-top para que window.opener apunte a algo cerrable. */
        window.open(url, 'discord-oauth',
            'width=' + w + ',height=' + h + ',left=' + l + ',top=' + t +
            ',menubar=no,toolbar=no,location=no,status=no'
        );
    }

    async function disconnect(){
        if (!confirm('¿Desvincular tu cuenta de Discord?')) return;
        try {
            var r = await fetch(DISCORD_BASE + '/disconnect.php', {
                method: 'POST', credentials: 'same-origin'
            }).then(function(x){ return x.json(); });
            if (r.error) throw new Error(r.error);
            linked = false;
            window.__DISCORD_LINKED__ = false;
            refresh();
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }

    btn.addEventListener('click', function(){
        if (linked) disconnect();
        else openOAuth();
    });

    /* La popup de OAuth nos avisa al terminar. */
    window.addEventListener('message', function(e){
        if (e.data && e.data.type === 'discord-linked') refresh();
    });

    refresh();
})();

/* Polling del balance cada 15 s para que el contador de autismo se vea en
   vivo (puntos por mensaje/voz/reacciones del bot, recompensas de admin,
   etc). Endpoint ligero `balance` que solo devuelve `{autismo}`.
   Usa fetch directo con `cache:'no-store'` + cache-buster por timestamp
   para sortear cualquier caché del navegador o de un proxy en medio. */
(function(){
    async function pollBalance(){
        if (document.hidden) return;
        try {
            var r = await fetch(API + '?action=balance&t=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json' }
            }).then(function(x){ return x.json(); });
            if (r.error || typeof r.autismo !== 'number') return;
            console.log('[tienda] poll balance:', r.autismo, '(local:', _balance, ')');
            if (r.autismo === _balance) return;
            _balance = r.autismo;
            renderBalance();
        } catch (e) { console.warn('[tienda] poll error:', e.message); }
    }
    /* Primera llamada a los 3 s para arrancar rápido; luego, dentro de la
       carcasa del móvil, cuando su timbre dice que ha cambiado el saldo
       ('saldo', window.parent.melonTimbre, assets/js/timbre.js). Sin
       timbre (o si no responde), cada 15 s como antes. Al salir, se da de
       baja. */
    setTimeout(pollBalance, 3000);
    (function(){
        var timbre = null;
        try { timbre = window.parent !== window ? window.parent.melonTimbre : null; } catch (e) {}
        if (!timbre) { setInterval(pollBalance, 15000); return; }
        var baja = timbre.on('saldo', pollBalance, 15000);
        window.addEventListener('pagehide', function(){ try { baja(); } catch (e) {} });
    })();

    /* El escritorio nos avisa cuando el usuario reabre la ventana de la
       tienda — refrescamos balance + items + compras al instante en lugar
       de esperar al próximo ciclo del polling. */
    window.addEventListener('message', function(e){
        if (e.data && e.data.type === 'tienda-refresh') {
            pollBalance();           /* update inmediato del wallet */
            loadState();             /* refresca items + compras */
        }
    });
})();

loadState();

/* ════ MODAL DE AYUDA ════
   El markup del modal va DESPUÉS de este <script>, así que esperamos
   a DOMContentLoaded para que getElementById encuentre los nodos. */
function initTiendaHelp() {
    var btn      = document.getElementById('tienda-help-btn');
    var modal    = document.getElementById('tienda-help-modal');
    var backdrop = document.getElementById('tienda-help-backdrop');
    var closeBtn = document.getElementById('tienda-help-close');
    if (!btn || !modal) return;
    function open()  { backdrop.style.display = 'flex'; }
    function close() { backdrop.style.display = 'none'; }
    btn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) close();
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTiendaHelp);
} else {
    initTiendaHelp();
}
})();
</script>

<!-- Modal de ayuda. Mismas clases que la versión desktop → reusa el
     override kawaii (style.css) sin duplicar reglas. -->
<div id="tienda-help-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:10000; align-items:center; justify-content:center; padding:12px;">
    <div class="window" id="tienda-help-modal" style="width:100%; max-width:480px; max-height:92vh; display:flex; flex-direction:column;">
        <div class="title-bar">
            <div class="title-bar-text">
                <img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">
                Cómo conseguir puntos
            </div>
            <div class="title-bar-controls">
                <button aria-label="Close" id="tienda-help-close"></button>
            </div>
        </div>
        <div class="window-body th-help-body" style="padding:14px; overflow-y:auto;">
            <p class="th-help-intro">
                Los puntos de Autismo (<img src="../../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;">) se ganan en el Discord :melonduagua: 3.0. Vincula tu Discord desde el botón de aquí arriba.
            </p>
            <ul class="th-help-list" style="list-style:none; padding:0; margin:0 0 12px 0; display:flex; flex-direction:column; gap:10px;">
                <li class="th-help-item">
                    <span class="th-help-icon"><img src="../../assets/img/appIcons/chatIcon.png" alt="" style="width:20px;height:20px;object-fit:contain;image-rendering:pixelated;"></span>
                    <div>
                        <strong>Mensajes</strong>
                        <span class="th-help-amt">+1 / mensaje</span>
                        <div class="th-help-sub">Cooldown anti-spam de 10 s.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon">🎙</span>
                    <div>
                        <strong>Tiempo en voz</strong>
                        <span class="th-help-amt">+1 cada 5 min</span>
                        <div class="th-help-sub">Se contabiliza al salir del canal.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon">❤</span>
                    <div>
                        <strong>Corazones en tus posts</strong>
                        <span class="th-help-amt">+10 / reacción</span>
                        <div class="th-help-sub">Solo posts publicados desde la app a Discord. Un usuario = un corazón por post.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon"><img src="../../assets/img/appIcons/temasIcon.png" alt="" style="width:20px;height:20px;object-fit:contain;image-rendering:pixelated;"></span>
                    <div>
                        <strong>Descargas de tus temas</strong>
                        <span class="th-help-amt">+50 / descarga única</span>
                        <div class="th-help-sub">Publica tu tema en la biblioteca (Temas → ✓ Publicar). Cada usuario distinto que lo descargue te da +50 una vez. No cuentan auto-descargas ni descargas repetidas.</div>
                    </div>
                </li>
            </ul>
            <p class="th-help-foot">¿Tu balance no sube? <strong>Vincula tu Discord</strong> arriba.</p>
        </div>
    </div>
</div>

</body>
</html>
