<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

session_start();
require_once dirname(__DIR__) . '/assets/config.php';
require_once dirname(__DIR__) . '/assets/dev-build.php';   /* MELON_DEV_BUILD: cajita "DEV" de darse puntos */
require_once dirname(__DIR__) . '/assets/tienda/catalogo.php';   /* pestañas de la tienda (catalogo.json) */
require_once dirname(__DIR__) . '/assets/themes/theme-helpers.php';

$userKey = $_SESSION['user'] ?? null;
if (!$userKey || !isset($loginUsers[$userKey])) {
    header('Location: ../index.php');
    exit;
}
$userLabel = $loginUsers[$userKey]['label'];

refreshActiveThemeCss($userKey, $userLabel);
$_userThemes      = loadUserThemes($userKey);
$activeTheme      = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
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
    <title>Tienda</title>
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css?v=<?php echo filemtime(dirname(__DIR__) . '/assets/css/tokens.css'); ?>">
    <link rel="stylesheet" href="../assets/css/base.css?v=<?php echo filemtime(dirname(__DIR__) . '/assets/css/base.css'); ?>">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../assets/css/themes.css?v=<?php echo filemtime(dirname(__DIR__) . '/assets/css/themes.css'); ?>">
    <!-- Marcos de perfil (vista previa en las tarjetas de la pestaña Perfil) -->
    <link rel="stylesheet" href="../assets/css/marcos.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/css/marcos.css'); ?>">
    <script src="../assets/js/melon-marcos.js?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/js/melon-marcos.js'); ?>"></script>
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?php echo htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
    <style>
    html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: var(--win-bg); color: var(--text); font-family: 'Pixelated MS Sans Serif', Arial, sans-serif; }

    #tienda-main { display: flex; height: 100vh; overflow: hidden; }

    /* Sidebar — mismas medidas que la galería. Tabs apiladas verticalmente,
       Principal arriba y Donaciones empujada al fondo con margin-top:auto. */
    #tienda-sidebar {
      width: 220px; flex-shrink: 0;
      background: var(--win-bg);
      border-right: 1px solid var(--border);
      box-shadow: 1px 0 0 var(--bezel-light-1);
      display: flex; flex-direction: column; overflow: hidden;
    }
    .tienda-tab {
      padding: 8px 12px; font-size: 12px; cursor: pointer;
      color: var(--text);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 8px;
    }
    .tienda-tab:hover  { background: var(--accent); color: var(--accent-text); }
    .tienda-tab.active { background: var(--accent); color: var(--accent-text); font-weight: bold; }

    /* Botón de Donaciones — separado de la lista de tabs, raised Win98
       estándar. Vive al fondo de la sidebar dentro de su propia caja. */
    #tienda-donar-footer {
      margin-top: auto;
      padding: 10px 8px;
      border-top: 1px solid var(--border);
      box-shadow: 0 -1px 0 var(--bezel-light-1);
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    #tienda-donar-btn,
    #tienda-help-btn {
      width: 100%;
      min-height: 28px;
      padding: 0 10px;
      font-size: 12px;
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    }
    /* Cuando la vista activa es Donaciones, el botón aparece "pulsado". */
    #tienda-donar-btn.is-active {
      box-shadow:
        inset -1px -1px var(--bezel-light-1),
        inset  1px  1px var(--bezel-dark-1),
        inset -2px -2px var(--bezel-light-2),
        inset  2px  2px var(--bezel-dark-2);
    }

    /* ═══ MODAL DE AYUDA — estilos base (win98). El override kawaii
       de .window viene de assets/interfaces/kawaii/style.css. ═══ */
    .th-help-body { font-size: 12px; line-height: 1.55; color: var(--text); }
    .th-help-intro {
      margin-bottom: 12px;
      padding: 8px 10px;
      background: var(--surface-deep);
      box-shadow:
        inset -1px -1px var(--bezel-light-1),
        inset  1px  1px var(--bezel-dark-1);
      border-radius: 2px;
    }
    .th-help-list {
      list-style: none;
      padding: 0;
      margin: 0 0 12px 0;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .th-help-item {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      padding: 10px;
      background: var(--win-bg);
      border: 1px solid var(--border);
      box-shadow:
        inset -1px -1px var(--bezel-light-1),
        inset  1px  1px var(--bezel-light-1);
    }
    .th-help-icon {
      font-size: 22px;
      line-height: 1;
      flex-shrink: 0;
      width: 32px;
      text-align: center;
    }
    .th-help-item strong {
      display: block;
      font-size: 13px;
      margin-bottom: 2px;
    }
    .th-help-amt {
      display: inline-block;
      margin-top: 2px;
      padding: 1px 6px;
      background: var(--accent, #000080);
      color: var(--accent-text, #fff);
      font-weight: bold;
      font-size: 11px;
      border-radius: 2px;
    }
    .th-help-sub {
      margin-top: 4px;
      font-size: 11px;
      color: var(--text-muted, #555);
    }
    .th-help-foot {
      margin-top: 6px;
      padding-top: 8px;
      border-top: 1px dotted var(--border);
      font-size: 11px;
      color: var(--text-muted, #555);
    }

    /* Área principal */
    #tienda-main-area {
      flex: 1; display: flex; flex-direction: column;
      overflow: hidden; background: var(--win-bg);
    }

    /* Wallet — balance prominente en la sidebar, siempre visible en
       cualquier pestaña. Marco hundido de 4 capas, como las thumbs. */
    #tienda-wallet {
      position: relative;
      margin: 8px; padding: 10px 12px;
      background: var(--inset-bg); color: var(--text);
      text-align: center;
      box-shadow:
        inset  1px  1px var(--bezel-dark-1),
        inset -1px -1px var(--bezel-light-1),
        inset  2px  2px var(--bezel-dark-2),
        inset -2px -2px var(--bezel-light-2);
    }
    /* Botón de ayuda anclado a la esquina sup-izq del wallet. Solo un
       interrogante dentro de un círculo, sin fondo, mismo color que
       "Tu balance" → no roba protagonismo al número. */
    /* Botón Ayuda — pixel art puro. La forma (círculo + ?) está dibujada
       en un único SVG con rectángulos 1×1; usamos `mask-image` para que
       `currentColor` pinte solo donde hay pixel, y `image-rendering:pixelated`
       para que el escalado 13px→22px mantenga el escalón sin antialias. */
    #tienda-help-btn {
      position: absolute;
      top: 6px; left: 6px;
      width: 22px; height: 22px;
      /* 98.css fuerza min 75×23 y color:transparent en cualquier button. */
      min-width: 0; min-height: 0; max-width: 22px; max-height: 22px;
      aspect-ratio: 1 / 1;
      box-sizing: border-box;
      flex: 0 0 22px;
      padding: 0; margin: 0;
      background: none;
      border: none;
      color: var(--text-muted);
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
    #tienda-help-btn:hover  { color: var(--text); }
    #tienda-help-btn:active { transform: translateY(1px); }
    #tienda-help-btn:focus  { outline: none; }
    #tienda-wallet-label {
      font-size: 9px; color: var(--text-muted);
      text-transform: uppercase; letter-spacing: 0.12em;
      margin-bottom: 4px;
    }
    #tienda-wallet-amount {
      font-size: 22px; font-weight: bold;
      color: var(--accent);
      display: flex; align-items: center; justify-content: center; gap: 6px;
      line-height: 1.1;
    }
    #tienda-wallet-amount .ic { font-size: 48px; display: inline-flex; align-items: center; }
    #tienda-wallet-amount .ic img { width: 48px; height: 48px; object-fit: contain; image-rendering: pixelated; vertical-align: middle; }
    .punto-autismo-ic { width: 14px; height: 14px; object-fit: contain; image-rendering: pixelated; vertical-align: middle; }
    #tienda-wallet-unit {
      font-size: 9px; color: var(--text-muted);
      margin-top: 2px; letter-spacing: 0.05em;
    }

    /* Bloque Discord en la sidebar — debajo del wallet. Muestra estado
       (vinculado / no vinculado) + botón de acción. */
    #tienda-discord {
      margin: 0 8px 8px; padding: 8px 10px;
      background: var(--win-bg);
      box-shadow:
        inset -1px -1px var(--bezel-dark-1),
        inset  1px  1px var(--bezel-light-1),
        inset -2px -2px var(--bezel-dark-2),
        inset  2px  2px var(--bezel-light-2);
      font-size: 11px;
    }
    #tienda-discord-label {
      font-size: 9px; color: var(--text-muted);
      text-transform: uppercase; letter-spacing: 0.12em;
      margin-bottom: 4px; text-align: center;
    }
    #tienda-discord-name {
      font-size: 11px; color: var(--accent); font-weight: bold;
      text-align: center; padding: 4px 0;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #tienda-discord-name:empty::before { content: '— no vinculado —'; color: var(--text-muted); font-weight: normal; }
    #tienda-discord button { width: 100%; margin-top: 4px; min-height: 22px; font-size: 11px; }

    /* Toast flotante para mensajes de estado (Cargando, Compra OK, errores).
       Solo se ve cuando tiene texto; en reposo está oculto. */
    #tienda-status {
      position: fixed; right: 12px; bottom: 12px;
      padding: 6px 12px; font-size: 11px; z-index: 100;
      background: var(--win-bg); color: var(--text);
      box-shadow:
        inset  1px  1px var(--bezel-light-1),
        inset -1px -1px var(--bezel-dark-1),
        inset  2px  2px var(--bezel-light-2),
        inset -2px -2px var(--bezel-dark-2),
        2px 2px 4px rgba(0,0,0,0.25);
      display: none;
    }
    #tienda-status:not(:empty)      { display: block; }
    #tienda-status.is-error          { color: var(--error-text); }
    #tienda-status.is-ok             { color: var(--accent); font-weight: bold; }

    /* Vista Principal — grid de items */
    #tienda-view-principal {
      flex: 1; overflow-y: auto; padding: 14px;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
      gap: 14px; align-content: start;
    }
    /* Pestaña Perfil: secciones (renderPerfil) en vez de la cuadrícula. */
    #tienda-view-principal.is-secciones { display: block; }
    .tienda-sec { margin-bottom: 18px; }
    /* Título en mayúsculas con una línea grabada hasta el final (como los
       separadores de Win98: oscura + clara). */
    .tienda-sec-h { display: flex; align-items: center; gap: 8px; margin: 0 0 4px;
      font-size: 12px; font-weight: bold; letter-spacing: 1px; color: var(--text); }
    .tienda-sec-h::after { content: ''; flex: 1; border-top: 1px solid var(--bezel-dark-1); border-bottom: 1px solid var(--bezel-light-1); }
    .tienda-sec-sub { font-size: 11px; color: var(--text-muted); margin-bottom: 8px; }
    .tienda-sec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; }
    .tienda-sec-wide { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
    /* Tarjeta ancha (expositores): icono a la izquierda y los datos al lado. */
    .tienda-wide { display: flex; background: var(--win-bg); min-height: 96px;
      border-top: 2px solid var(--bezel-light-1); border-left: 2px solid var(--bezel-light-1);
      border-right: 2px solid var(--bezel-dark-2); border-bottom: 2px solid var(--bezel-dark-2); }
    .tienda-wide-icon { width: 88px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--inset-bg);
      box-shadow: inset -1px -1px var(--bezel-light-1), inset 1px 1px var(--bezel-dark-1); margin: 4px; }
    .tienda-wide-icon img { width: 44px; height: 44px; object-fit: contain; image-rendering: pixelated; }
    .tienda-wide-body { flex: 1; min-width: 0; padding: 6px 8px; display: flex; flex-direction: column; gap: 3px; }
    .tienda-wide-name { font-size: 12px; font-weight: bold; color: var(--text); }
    .tienda-wide-type { font-size: 10px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tienda-wide-pips { display: inline-flex; gap: 2px; }
    .tienda-wide-pips i { width: 10px; height: 6px; background: var(--inset-bg); box-shadow: inset 1px 1px var(--bezel-dark-1); }
    .tienda-wide-pips i.on { background: var(--accent); }
    .tienda-wide-desc { font-size: 10px; color: var(--text-muted); flex: 1; }
    .tienda-wide-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    .tienda-wide-price { font-size: 11px; font-weight: bold; color: var(--text); }
    .tienda-wide-foot .button { min-height: 22px; }
    .tienda-wide-foot .button:disabled { opacity: 0.55; cursor: not-allowed; }
    .tienda-wide-done { font-size: 11px; font-weight: bold; color: var(--accent); }
    /* Vista previa de marco con tu foto: hueco fijo de 72 px y el sitio
       que pide cada marco alrededor (--mk-* de marcos.css). */
    .tienda-marco-prev.is-foto { width: 72px; height: 72px;
      margin: calc(72px * var(--mk-t, .14)) calc(72px * var(--mk-r, .14)) calc(72px * var(--mk-b, .14)) calc(72px * var(--mk-l, .14)); }
    .tienda-marco-prev.is-foto > img { object-fit: cover; image-rendering: auto; }
    .tienda-card {
      background: var(--win-bg);
      border-top: 2px solid var(--bezel-light-1);
      border-left: 2px solid var(--bezel-light-1);
      border-right: 2px solid var(--bezel-dark-2);
      border-bottom: 2px solid var(--bezel-dark-2);
      padding: 8px; display: flex; flex-direction: column;
      align-items: center; gap: 6px;
    }
    .tienda-card-icon {
      position: relative;            /* ancla del badge de precio */
      width: calc(100% - 4px); aspect-ratio: 1 / 1;
      background: var(--inset-bg);
      box-shadow:
        -1px -1px 0 var(--bezel-dark-1),
         1px  1px 0 var(--bezel-light-1),
        -2px -2px 0 var(--bezel-dark-2),
         2px  2px 0 var(--bezel-light-2);
      align-self: center;
      display: flex; align-items: center; justify-content: center;
      font-size: 48px;
      overflow: hidden;
    }
    .tienda-card-icon-img {
      max-width: 80%;
      max-height: 80%;
      object-fit: contain;
      image-rendering: -webkit-optimize-contrast;
    }
    .tienda-card-name { font-size: 12px; font-weight: bold; text-align: center; color: var(--text); }
    .tienda-card-desc { font-size: 10px; color: var(--text-muted); text-align: center; min-height: 26px; }
    /* Badge de precio anclado arriba-derecha de la imagen del item. */
    .tienda-card-price {
      position: absolute; top: 4px; right: 4px;
      font-size: 11px; font-weight: bold;
      background: var(--accent); color: var(--accent-text);
      padding: 2px 6px; border-radius: 2px;
      box-shadow: 1px 1px 0 rgba(0,0,0,0.35);
      line-height: 1.2;
    }
    .tienda-card .button { width: 100%; min-height: 22px; }
    .tienda-card .button:disabled { opacity: 0.55; cursor: not-allowed; }
    /* Card de un item que el usuario ya posee — atenuada para indicarlo. */
    .tienda-card.is-owned { opacity: 0.75; }
    .tienda-card.is-owned .tienda-card-icon::after {
      content: '✓'; position: absolute; left: 4px; top: 4px;
      font-size: 14px; font-weight: bold;
      color: var(--accent); background: var(--win-bg);
      padding: 1px 5px; border-radius: 2px;
      box-shadow: 1px 1px 0 rgba(0,0,0,0.35);
    }

    /* Vista Donaciones — explicación + lista de donantes + botón al fondo.
       El botón cambia la vista al iframe de Ko-fi (que sí se ve por
       dentro con su look, pero envuelto en marco Win98). */
    #tienda-view-donaciones {
      flex: 1; display: none;
      flex-direction: column;
      overflow: hidden;
      background: var(--win-bg);
    }
    #tienda-view-donaciones.is-active { display: flex; }

    #donar-info {
      flex: 1; display: flex; flex-direction: column;
      overflow: hidden;
    }

    /* Grid de 3 columnas a todo el ancho del contenedor:
       [placeholder 220px] [contenido fluido] [cuadro de encargos 220px].
       El placeholder izquierdo es invisible pero ocupa exactamente el
       mismo ancho que el cuadro de la derecha, así el texto del centro
       sigue visualmente centrado en TODA la sección. El cuadro queda
       fijo en la esquina derecha, no centrado con el texto. */
    .donar-intro {
      display: grid;
      grid-template-columns: 220px minmax(0, 1fr) 220px;
      align-items: start;
      gap: 24px;
      padding: 22px 24px 18px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .donar-intro::before { content: ''; }   /* placeholder izquierdo (col 1) */
    .donar-intro-main {
      text-align: center;
      max-width: 520px;
      margin: 0 auto;                        /* centra el bloque dentro de la col 2 */
      min-width: 0;
    }
    .donar-intro-emoji { font-size: 38px; line-height: 1; }
    .donar-intro-title { font-size: 15px; font-weight: bold; margin-top: 8px; }
    .donar-intro-text  { font-size: 11px; color: var(--text-muted); margin: 8px auto 14px; line-height: 1.55; }
    .donar-intro-main #donar-go-btn { min-height: 28px; padding: 0 22px; font-size: 12px; font-weight: bold; }
    .donar-intro-hint { font-size: 10px; color: var(--text-faint); margin-top: 8px; }

    /* Cuadro de encargos — vive en la columna 3 del grid. Como es un grid
       item normal contribuye a la altura del padre, así que el border-bottom
       de .donar-intro queda SIEMPRE debajo del cuadro. */
    .donar-encargos {
      padding: 10px 12px;
      background: var(--inset-bg); color: var(--text);
      box-shadow:
        inset  1px  1px var(--bezel-dark-1),
        inset -1px -1px var(--bezel-light-1),
        inset  2px  2px var(--bezel-dark-2),
        inset -2px -2px var(--bezel-light-2);
      display: flex; flex-direction: column; gap: 6px;
    }
    .donar-encargos-title {
      font-size: 10px; font-weight: bold;
      text-transform: uppercase; letter-spacing: 0.15em;
      color: var(--text-muted); text-align: center;
      border-bottom: 1px solid var(--border);
      padding-bottom: 4px; margin-bottom: 2px;
    }
    .donar-encargo-btn {
      min-height: 28px; padding: 4px 10px;
      font-size: 11px;
      display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
      text-decoration: none;
    }
    .donar-encargo-btn .ic { font-size: 14px; line-height: 1; }
    .donar-encargo-btn .label { flex: 1; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .donar-encargo-btn .price {
      font-size: 11px; font-weight: bold;
      background: var(--accent); color: var(--accent-text);
      padding: 1px 6px; border-radius: 2px;
    }
    /* Texto informativo bajo los botones — separa visualmente con un
       border-top sutil y se queda en tamaño pequeño + color atenuado. */
    .donar-encargos-info {
      font-size: 9px; color: var(--text-faint);
      text-align: center; line-height: 1.45;
      margin-top: 4px; padding-top: 6px;
      border-top: 1px solid var(--border);
    }

    .donar-donors-section { flex: 1; overflow-y: auto; padding: 14px 18px; }
    .donar-donors-title {
      font-size: 9px; font-weight: bold; letter-spacing: 0.15em;
      text-transform: uppercase; color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      padding-bottom: 4px; margin-bottom: 10px;
    }
    /* Flex en lugar de grid — así con pocos items quedan centrados
       en lugar de pegados a la izquierda. */
    .donar-donors-grid {
      display: flex; flex-wrap: wrap;
      justify-content: center;
      gap: 12px;
    }
    .donar-donor {
      width: 160px;
      background: var(--win-bg);
      border-top: 2px solid var(--bezel-light-1);
      border-left: 2px solid var(--bezel-light-1);
      border-right: 2px solid var(--bezel-dark-2);
      border-bottom: 2px solid var(--bezel-dark-2);
      padding: 10px 8px;
      display: flex; flex-direction: column; align-items: center; gap: 6px;
    }
    .donar-donor-avatar {
      width: 64px; height: 64px;
      background: var(--inset-bg);
      /* Mismo marco hundido de 4 capas que las fotos de perfil. */
      box-shadow:
        -1px -1px 0 var(--bezel-dark-1),
         1px  1px 0 var(--bezel-light-1),
        -2px -2px 0 var(--bezel-dark-2),
         2px  2px 0 var(--bezel-light-2);
      overflow: hidden; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 30px; color: var(--text-inset);
    }
    .donar-donor-avatar img {
      width: 64px; height: 64px; object-fit: cover; display: block;
      image-rendering: auto;
    }
    .donar-donor-name {
      font-size: 12px; font-weight: bold; text-align: center;
      color: var(--text);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      max-width: 100%;
    }
    .donar-donor-msg {
      font-size: 10px; color: var(--text); text-align: center;
      line-height: 1.4;
      padding: 4px 6px; width: 100%; box-sizing: border-box;
      background: var(--inset-bg);
      box-shadow: inset 1px 1px var(--bezel-dark-1), inset -1px -1px var(--bezel-light-1);
      font-style: italic;
      display: -webkit-box; -webkit-line-clamp: 4; line-clamp: 4; -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .donar-donor-msg:empty { display: none; }

    /* Etiqueta del tipo de aportación. Color del fondo según tipo:
       donante = accent del tema (amarillo en Capi),
       suscriptor = morado neutral,
       encargo = naranja. Todos con texto blanco para contraste. */
    .donar-donor-tipo {
      font-size: 9px; font-weight: bold;
      text-transform: uppercase; letter-spacing: 0.08em;
      padding: 2px 6px; border-radius: 2px;
      color: #fff;
      text-shadow: 0 0 2px rgba(0,0,0,0.5);
      display: inline-flex; align-items: center; gap: 3px;
    }
    .donar-donor-tipo.donacion     { background: var(--accent); color: var(--accent-text); text-shadow: none; }
    .donar-donor-tipo.suscripcion  { background: #7c4dff; }
    .donar-donor-tipo.encargo      { background: #e67e22; }


    /* Ko-fi vive ahora en su propia ventana del escritorio. La pestaña
       Donaciones solo muestra la explicación + el grid de donantes; el
       botón "Donar" pide al desktop que abra la ventana de Ko-fi vía
       postMessage (handler en desktop-base.php). */

    /* Estado vacío / mensajes */
    .tienda-empty {
      padding: 40px; text-align: center;
      color: var(--text-faint); font-size: 12px;
    }
    #tienda-status {
      font-size: 11px; color: var(--text-muted); padding: 0 4px;
    }
    #tienda-status.is-error { color: var(--error-text); }
    #tienda-status.is-ok    { color: var(--accent); }
    </style>
</head>
<body class="<?php echo htmlspecialchars($activeThemeClass); ?>">

<div id="tienda-main">
    <aside id="tienda-sidebar">
        <div id="tienda-wallet">
            <button type="button" id="tienda-help-btn" title="Cómo conseguir puntos de Autismo" aria-label="Ayuda">?</button>
            <div id="tienda-wallet-label">Tu balance</div>
            <div id="tienda-wallet-amount">
                <span class="ic"><img src="../assets/img/appIcons/puntosAutismo.png" alt=""></span>
                <span id="tienda-balance-v">—</span>
            </div>
            <div id="tienda-wallet-unit">puntos de Autismo</div>
            <?php if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD): ?>
            <!-- Solo en la build de dev: darse puntos para probar la tienda. -->
            <div id="tienda-dev" style="margin-top:6px;padding:4px;border:1px dashed var(--error-text,#c00);font-size:10px;display:flex;gap:4px;align-items:center;">
                <b style="color:var(--error-text,#c00);">DEV</b>
                <input type="number" id="tienda-dev-amount" value="1000" min="1" max="100000" style="width:64px;">
                <button type="button" class="button" id="tienda-dev-grant" style="min-width:0;padding:0 6px;font-size:10px;">+ Puntos</button>
            </div>
            <?php endif; ?>
        </div>
        <div id="tienda-discord">
            <div id="tienda-discord-label">Discord</div>
            <div id="tienda-discord-name"></div>
            <button type="button" class="button" id="tienda-discord-btn">…</button>
        </div>
        <?php
        /* Pestañas: salen de assets/tienda/catalogo.json (la primera empieza
           activa; "proximamente" → la pestaña enseña "Coming soon..."). */
        foreach (tiendaPestanas() as $_i => $_t): ?>
        <div class="tienda-tab<?= $_i === 0 ? ' active' : '' ?>" data-view="principal" data-cat="<?= htmlspecialchars($_t['id']) ?>"<?= $_t['proximamente'] ? ' data-soon="1"' : '' ?>><?php if ($_t['icono'] !== ''): ?><img src="../<?= htmlspecialchars($_t['icono']) ?>" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;"><?php endif; ?><?= htmlspecialchars($_t['nombre']) ?></div>
        <?php endforeach; ?>
        <div id="tienda-donar-footer">
            <button type="button" class="button" id="tienda-donar-btn" data-view="donaciones">☕ Donaciones</button>
        </div>
    </aside>
    <main id="tienda-main-area">
        <div id="tienda-view-principal">
            <div class="tienda-empty">Cargando…</div>
        </div>
        <div id="tienda-view-donaciones">
            <!-- Vista por defecto: explicación + botón + donantes. -->
            <div id="donar-info">
                <div class="donar-intro">
                    <div class="donar-intro-main">
                        <div class="donar-intro-emoji">🍉</div>
                        <div class="donar-intro-title">Apoya el desarrollo</div>
                        <div class="donar-intro-text">
                            Scrapbook Melon es un proyecto personal y gratuito. Cualquier aportación
                            ayuda a mantener el servidor encendido, pagar el dominio y seguir añadiendo
                            funciones nuevas. Gracias 💛
                        </div>
                        <button type="button" class="button default" id="donar-go-btn">☕ Donar</button>
                        <div class="donar-intro-hint">Pago seguro vía Stripe / PayPal en Ko-fi.</div>
                    </div>

                    <div class="donar-encargos">
                        <div class="donar-encargos-title">Encargos</div>
                        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/064181251c" target="_blank" rel="noopener"
                           data-kofi-title="Haro personalizado">
                            <span class="ic"><img src="../assets/img/appIcons/haroIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span><span class="label">Haro personalizado</span><span class="price">5 €</span>
                        </a>
                        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/4de28dd45e" target="_blank" rel="noopener"
                           data-kofi-title="Tema personalizado">
                            <span class="ic"><img src="../assets/img/appIcons/temasIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span><span class="label">Tema personalizado</span><span class="price">10 €</span>
                        </a>
                        <a class="button donar-encargo-btn" href="https://ko-fi.com/c/16c92f9fdf" target="_blank" rel="noopener"
                           data-kofi-title="Mascota personalizada">
                            <span class="ic"><img src="../assets/img/appIcons/mascotaIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;"></span><span class="label">Mascota personalizada</span><span class="price">15 €</span>
                        </a>
                        <p class="donar-encargos-info">
                            Si no te apetece donar también puedes hacer un encargo para tener algo personalizado en tu perfil y no limitarte a las opciones de la tienda.
                        </p>
                    </div>
                </div>

                <div class="donar-donors-section">
                    <div class="donar-donors-title">Quienes han apoyado</div>
                    <div class="donar-donors-grid" id="donar-donors-grid">
                        <div style="text-align:center;color:var(--text-muted);font-size:11px;padding:20px;width:100%;">Cargando…</div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<div id="tienda-status"></div>

<script>
(function(){
'use strict';
var API = '../assets/tienda/api.php';
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
/* Tu foto de perfil: va dentro de la vista previa de los marcos. */
var MY_AVATAR = <?php $_myAv = getUserImage($userLabel); echo json_encode($_myAv ? '../' . $_myAv : ''); ?>;
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
        if (_owned[id]) { btn.disabled = true; btn.textContent = '✓ Ya lo tienes'; return; }
        if (btn.dataset.locked) { btn.disabled = true; return; }   /* falta Discord o el hueco anterior */
        var p = parseInt(btn.dataset.price, 10);
        btn.disabled = p > _balance;
    });
}
function renderItems(){
    var view = document.getElementById('tienda-view-principal');
    /* Perfil va por secciones (renderPerfil); el resto, en cuadrícula. */
    view.classList.toggle('is-secciones', _activeCat === 'perfil');
    /* Pestañas con "proximamente" en catalogo.json (ahora Mascotas): placeholder.
       Ocupa todo el alto disponible y se centra horizontal+verticalmente. */
    var _tab = document.querySelector('.tienda-tab[data-cat="' + _activeCat + '"]');
    if (_tab && _tab.dataset.soon) {
        view.innerHTML = '<div class="tienda-empty" style="display:flex;align-items:center;justify-content:center;min-height:60vh;font-size:22px;">Coming soon...</div>';
        return;
    }
    var items = _items.filter(function(it){ return (it.categoria || 'discord') === _activeCat; });
    if (!items.length) {
        view.innerHTML = '<div class="tienda-empty">No hay items en esta categoría todavía.</div>';
        return;
    }
    if (_activeCat === 'perfil') { renderPerfil(view, items); return; }
    view.innerHTML = items.map(function(it){
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
        var owned = !!_owned[it.id|0];
        /* Items que conceden un rol de Discord requieren vinculación.
           Sin Discord enlazado deshabilitamos el botón y mostramos el motivo
           — el server también lo rechaza (403) por si alguien parchea esto. */
        var needsDiscord = !!it.discord_role_name;
        var discordLinked = window.__DISCORD_LINKED__ === true;
        var locked = needsDiscord && !discordLinked && !owned;
        /* Huecos de expositor del perfil: en orden (el 4 pide el 3…). */
        var SLOT_SLUGS = ['expositor-hueco-3', 'expositor-hueco-4', 'expositor-hueco-5'];
        var slotIdx = SLOT_SLUGS.indexOf(it.slug || '');
        var needsPrev = false;
        if (slotIdx > 0 && !owned) {
            var prev = _items.filter(function(x){ return x.slug === SLOT_SLUGS[slotIdx - 1]; })[0];
            needsPrev = !prev || !_owned[prev.id|0];
        }
        var btnLabel = owned     ? '✓ Ya lo tienes'
                     : locked    ? 'Vincula Discord'
                     : needsPrev ? 'Antes, el hueco ' + (slotIdx + 2)
                                 : 'Comprar';
        var btnAttrs = locked ? ' disabled data-locked="1" title="Vincula tu Discord (sidebar) para comprar este item"'
                     : needsPrev ? ' disabled data-locked="1"' : '';
        /* Para los haros el icono es el PNG del último frame del gif
           (convención: assets/vids/{slug}Haro-last.png). Para el resto
           se cae al emoji del campo `icono`. */
        var iconHtml;
        if (it.categoria === 'haros' && it.slug) {
            /* Convención: PNG curado del haro en assets/img/haro/
               {slug}Haro-preview.png. Para haros antiguos sin curated,
               cae al último frame del gif (assets/vids/{slug}Haro-last.png). */
            iconHtml = '<img class="tienda-card-icon-img"'
                + ' src="../assets/img/haro/' + esc(it.slug) + 'Haro-preview.png"'
                + ' onerror="this.onerror=null;this.src=\'../assets/vids/' + esc(it.slug) + 'Haro-last.png\';"'
                + ' alt="">';
        } else if (it.categoria === 'perfil' && it.marco) {
            /* Marcos: el marco alrededor del icono de perfil del pack
               (dibujo en assets/css/marcos.css / melon-marcos.js). */
            iconHtml = '<span class="tienda-marco-prev" data-marco="' + esc(it.slug) + '"><img src="../assets/img/appIcons/profileIcon.png" alt=""></span>';
        } else if (it.categoria === 'perfil') {
            /* Huecos de expositor: icono del perfil del pack del tema. */
            iconHtml = '<img class="tienda-card-icon-img" src="../assets/img/appIcons/profileIcon.png" alt="" style="image-rendering:pixelated;">';
        } else {
            iconHtml = esc(it.icono || '🎁');
        }
        return '<div class="tienda-card' + (owned ? ' is-owned' : '') + '">' +
            '<div class="tienda-card-icon">' +
                iconHtml +
                '<span class="tienda-card-price">' + it.precio + ' <img src="../assets/img/appIcons/puntosAutismo.png" class="punto-autismo-ic" alt=""></span>' +
            '</div>' +
            '<div class="tienda-card-name"' + nameStyle + '>' + esc(displayName) + '</div>' +
            '<div class="tienda-card-desc">' + esc(desc) + '</div>' +
            '<button type="button" class="button" data-buy-id="' + it.id + '" data-price="' + it.precio + '"' + btnAttrs + '>' + btnLabel + '</button>' +
        '</div>';
    }).join('');
    view.querySelectorAll('[data-buy-id]').forEach(function(btn){
        btn.addEventListener('click', function(){ buy(parseInt(btn.dataset.buyId, 10), btn); });
    });
    renderBalance();
}

/* ── Pestaña Perfil, por secciones (como la tienda de puntos de Steam) ──
   MARCOS DE AVATAR: tarjetas con tu foto dentro de cada marco.
   EXPOSITORES: UNA sola tarjeta ancha para los huecos de expositor que va
   cambiando según los que ya tengas (hueco 3 → 4 → 5, con su precio);
   comprados los tres, sale completa. Lo que no sea ni marco ni hueco va
   en OTROS, con la tarjeta normal. */
var SLOT_SLUGS_PERFIL = ['expositor-hueco-3', 'expositor-hueco-4', 'expositor-hueco-5'];
function perfilPrecio(p) {
    return p + ' <img src="../assets/img/appIcons/puntosAutismo.png" class="punto-autismo-ic" alt="">';
}
function perfilSeccion(titulo, sub, cuerpo, cls) {
    return '<section class="tienda-sec">' +
        '<h3 class="tienda-sec-h">' + esc(titulo) + '</h3>' +
        (sub ? '<div class="tienda-sec-sub">' + esc(sub) + '</div>' : '') +
        '<div class="' + cls + '">' + cuerpo + '</div></section>';
}
function renderPerfil(view, items) {
    var marcos = items.filter(function(it){ return !!it.marco; });
    var huecos = SLOT_SLUGS_PERFIL.map(function(sl){ return items.filter(function(it){ return it.slug === sl; })[0]; })
                                  .filter(Boolean);
    var otros  = items.filter(function(it){ return !it.marco && SLOT_SLUGS_PERFIL.indexOf(it.slug) < 0; });
    var foto = MY_AVATAR || '../assets/img/appIcons/profileIcon.png';
    var html = '';

    if (marcos.length) {
        html += perfilSeccion('MARCOS DE AVATAR', 'Un marco alrededor de tu foto en el perfil, el chat, Social y los comentarios. Se elige en Personalizar del perfil.',
            marcos.map(function(it){
                var owned = !!_owned[it.id|0];
                return '<div class="tienda-card' + (owned ? ' is-owned' : '') + '">' +
                    '<div class="tienda-card-icon">' +
                        '<span class="tienda-marco-prev is-foto" data-marco="' + esc(it.slug) + '"><img src="' + esc(foto) + '" alt=""></span>' +
                        '<span class="tienda-card-price">' + perfilPrecio(it.precio) + '</span>' +
                    '</div>' +
                    '<div class="tienda-card-name">' + esc(it.nombre) + '</div>' +
                    '<div class="tienda-card-desc">' + esc(it.descripcion || '') + '</div>' +
                    '<button type="button" class="button" data-buy-id="' + it.id + '" data-price="' + it.precio + '">' + (owned ? '✓ Ya lo tienes' : 'Comprar') + '</button>' +
                '</div>';
            }).join(''), 'tienda-sec-grid');
    }

    if (huecos.length) {
        /* El primero que no tienes es el que se vende ahora. */
        var tienes = huecos.filter(function(it){ return _owned[it.id|0]; }).length;
        var sig = huecos.filter(function(it){ return !_owned[it.id|0]; })[0];
        var total = 2 + huecos.length;
        var pips = '';
        for (var i = 0; i < huecos.length; i++) pips += '<i class="' + (i < tienes ? 'on' : '') + '"></i>';
        var card = '<div class="tienda-wide' + (sig ? '' : ' is-owned') + '">' +
            '<div class="tienda-wide-icon"><img src="../assets/img/appIcons/profileIcon.png" alt=""></div>' +
            '<div class="tienda-wide-body">' +
                '<div class="tienda-wide-name">' + esc(sig ? sig.nombre : 'Huecos de expositor') + '</div>' +
                '<div class="tienda-wide-type">Mejora de expositor · tienes ' + (2 + tienes) + ' de ' + total + ' huecos <span class="tienda-wide-pips">' + pips + '</span></div>' +
                '<div class="tienda-wide-desc">' + esc(sig ? (sig.descripcion || '') : 'Ya tienes todos los huecos: ' + total + ' expositores a la vez en tu perfil.') + '</div>' +
                '<div class="tienda-wide-foot">' +
                    (sig ? '<span class="tienda-wide-price">' + perfilPrecio(sig.precio) + '</span>' +
                           '<button type="button" class="button" data-buy-id="' + sig.id + '" data-price="' + sig.precio + '">Comprar</button>'
                         : '<span class="tienda-wide-done">✓ Completo</span>') +
                '</div>' +
            '</div></div>';
        html += perfilSeccion('EXPOSITORES', 'Más huecos para enseñar cosas en tu perfil a la vez. Cada mejora añade uno, hasta ' + total + '.', card, 'tienda-sec-wide');
    }

    if (otros.length) {
        html += perfilSeccion('OTROS', '', otros.map(function(it){
            var owned = !!_owned[it.id|0];
            return '<div class="tienda-card' + (owned ? ' is-owned' : '') + '">' +
                '<div class="tienda-card-icon"><img class="tienda-card-icon-img" src="../assets/img/appIcons/profileIcon.png" alt="" style="image-rendering:pixelated;">' +
                    '<span class="tienda-card-price">' + perfilPrecio(it.precio) + '</span></div>' +
                '<div class="tienda-card-name">' + esc(it.nombre) + '</div>' +
                '<div class="tienda-card-desc">' + esc(it.descripcion || '') + '</div>' +
                '<button type="button" class="button" data-buy-id="' + it.id + '" data-price="' + it.precio + '">' + (owned ? '✓ Ya lo tienes' : 'Comprar') + '</button>' +
            '</div>';
        }).join(''), 'tienda-sec-grid');
    }

    view.innerHTML = html;
    view.querySelectorAll('[data-buy-id]').forEach(function(btn){
        btn.addEventListener('click', function(){ buy(parseInt(btn.dataset.buyId, 10), btn); });
    });
    renderBalance();
}

async function buy(itemId, btn){
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
                : '👤';
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
    function startPolling(){
        if (pollHandle) return;
        pollHandle = setInterval(loadDonors, 30000);
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
    var DISCORD_BASE = '../assets/discord-oauth';

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
    /* Primera llamada a los 3 s para arrancar rápido, luego cada 15 s. */
    setTimeout(pollBalance, 3000);
    setInterval(pollBalance, 15000);

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
})();

/* =========================================================
   VENTANA DE AYUDA — Cómo conseguir puntos de Autismo
   ─────────────────────────────────────────────────────────
   El markup del modal vive DESPUÉS de este <script>; sin el
   guard de DOMContentLoaded los getElementById devuelven null
   y el botón no haría nada.
   ========================================================= */
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
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && backdrop.style.display === 'flex') close();
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTiendaHelp);
} else {
    initTiendaHelp();
}
</script>

<!-- ═════════════════════════════════════════════════════════
     MODAL DE AYUDA — usa la clase .window estándar, por lo que
     la interfaz activa decide el look:
       · win98 → ventana 98.css clásica (bezel, title-bar azul).
       · kawaii (MelonOS Overdose) → flat lavanda/rosa pastel,
         título Pixelify Sans (override en interfaces/kawaii/
         style.css aplicado a TODO .window).
     ═════════════════════════════════════════════════════════ -->
<div id="tienda-help-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:10000; align-items:center; justify-content:center;">
    <div class="window" id="tienda-help-modal" style="width: min(520px, 92vw); max-height: 86vh; display:flex; flex-direction:column;">
        <div class="title-bar">
            <div class="title-bar-text">
                <img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">
                Cómo conseguir puntos de Autismo
            </div>
            <div class="title-bar-controls">
                <button aria-label="Close" id="tienda-help-close"></button>
            </div>
        </div>
        <div class="window-body th-help-body" style="padding:14px; overflow-y:auto;">
            <p class="th-help-intro">
                Los puntos de Autismo (<img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;">) se ganan participando en el Discord :melonduagua: 3.0. El bot los reparte automáticamente — solo tienes que vincular tu cuenta de Discord desde el panel de aquí al lado.
            </p>
            <ul class="th-help-list">
                <li class="th-help-item">
                    <span class="th-help-icon"><img src="../assets/img/appIcons/chatIcon.png" alt="" style="width:20px;height:20px;object-fit:contain;image-rendering:pixelated;"></span>
                    <div>
                        <strong>Mensajes en cualquier canal</strong>
                        <span class="th-help-amt">+1 <img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-1px;"></span>
                        <div class="th-help-sub">Cooldown anti-spam de 10 s por usuario.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon">🎙</span>
                    <div>
                        <strong>Tiempo en canales de voz</strong>
                        <span class="th-help-amt">+1 <img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-1px;"> cada 5 min</span>
                        <div class="th-help-sub">Se contabiliza al salir del canal; intervalos sueltos no cuentan.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon">❤</span>
                    <div>
                        <strong>Reacciones de corazón en tus posts</strong>
                        <span class="th-help-amt">+10 <img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-1px;"></span>
                        <div class="th-help-sub">Solo cuentan reacciones ❤ en posts que publiques desde la app (Galería → Publicar a Discord). Una persona = un corazón por post.</div>
                    </div>
                </li>
                <li class="th-help-item">
                    <span class="th-help-icon"><img src="../assets/img/appIcons/temasIcon.png" alt="" style="width:20px;height:20px;object-fit:contain;image-rendering:pixelated;"></span>
                    <div>
                        <strong>Descargas de tus temas en la biblioteca</strong>
                        <span class="th-help-amt">+50 <img src="../assets/img/appIcons/puntosAutismo.png" alt="" style="width:12px;height:12px;object-fit:contain;image-rendering:pixelated;vertical-align:-1px;"></span>
                        <div class="th-help-sub">Publica tu tema en la biblioteca compartida (Temas → ✓ Publicar). Cada usuario distinto que lo descargue te da +50 una vez. No cuentan tus propias descargas ni descargar el mismo tema varias veces.</div>
                    </div>
                </li>
            </ul>
            <p class="th-help-foot">
                ¿Tu balance no sube? Asegúrate de que has vinculado tu Discord desde la sección
                <strong>Discord</strong> de la izquierda. Sin vincular, el bot no sabe quién eres.
            </p>
        </div>
    </div>
</div>

</body>
</html>
