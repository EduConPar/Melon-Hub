<?php
/* ──────────────────────────────────────────────────────────────────────
   CHAT — versión móvil standalone
   ──────────────────────────────────────────────────────────────────────
   App dedicada de chat. Reusa los mismos endpoints de chat de
   /assets/profile/api.php (get-profile, get-followers, get-messages,
   send-message, get-unread-chats) — sin tocar backend.

   Dos vistas:
     · Lista: amigos (mutual follow) con avatar + nombre + badge de no
       leídos. Al final, nota explicativa para hacer más amigos.
     · Chat: conversación con un amigo seleccionado, con header (avatar
       + nombre + atrás), feed de mensajes y composer abajo.

   Soporte de notificaciones del SO igual que perfil-mobile:
     · El SW pregunta vía postMessage si tenemos un chat focused +
       visible → respondemos true para suprimir la notif.
     · Al abrir un chat, limpiamos las notifs pendientes de ese tag.
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

/* Tema activo del usuario — mismo patrón que el resto de apps móviles. */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php';
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

/* Avatar helper (mismo patrón que getUserImage en assets/config.php).
   Mira primero en uploads/profile-photos/ (sube de usuarios, sobrevive
   los deploys de Hostinger porque está gitignored), después cae al
   seed del repo en assets/img/. */
function getUserImage_chat($label) {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    $root = dirname(__DIR__, 2);
    foreach (['webp','png','jpg','jpeg','gif'] as $ext) {
        $p = $root . "/uploads/profile-photos/{$safe}.{$ext}";
        if (file_exists($p)) return "uploads/profile-photos/{$safe}.{$ext}";
    }
    foreach (['png','jpg','jpeg','gif'] as $ext) {
        $p = $root . "/assets/img/{$safe}.{$ext}";
        if (file_exists($p)) return "assets/img/{$safe}.{$ext}";
    }
    return '';
}

$projectBaseUrl = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="../../assets/js/pwa-guard.js"></script>
    <script>
    (function(){
        function setVh(){ document.documentElement.style.setProperty('--mh-vh', window.innerHeight + 'px'); }
        setVh();
        window.addEventListener('resize', setVh);
        window.addEventListener('orientationchange', setVh);
        window.addEventListener('pageshow', setVh);
        window.addEventListener('visibilitychange', setVh);
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($themeBgColor) ?>">
    <title>Chat</title>
    <link rel="icon" href="../../assets/img/mobile/icon.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/98.css">
    <link rel="stylesheet" href="../../assets/css/tokens.css">
    <link rel="stylesheet" href="../../assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../../assets/js/icon-pack.js"></script>
    <script src="../../assets/js/notif-sound.js"></script>
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
    /* ────────────────────────────────────────────────────
       CHAT MÓVIL — layout flex: titlebar + body + composer
       ──────────────────────────────────────────────────── */
    html, body { font-family: 'VT323', monospace; font-size: 16px; letter-spacing: 0.5px; }
    * { box-sizing: border-box; }
    button, input, textarea, select { font-family: 'VT323', monospace !important; letter-spacing: 0.5px; }

    .window-body { padding: 0; display: flex; flex-direction: column; }

    /* Vistas (list/chat) — ambas ocupan TODO el window-body con flex:1.
       La inactiva se oculta con display:none vía .is-hidden / la ausencia
       de .is-active. ANTES tenía el flex:1 inline en el HTML, lo que
       hacía que `display:flex` ganara al `display:none` de .is-hidden y
       la ventana del chat aparecía solo en la parte inferior visible
       junto a la lista. */
    .ch-list {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .ch-list.is-hidden { display: none; }

    /* ── Vista lista de amigos ── */
    .ch-list-wrap { flex: 1; min-height: 0; overflow-y: auto; padding: 6px 8px 12px; }
    .ch-list-empty {
        text-align: center;
        padding: 30px 14px;
        color: var(--text-faint, #808080);
        font-size: 14px;
    }
    .ch-friend {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 8px;
        margin: 4px 0;
        cursor: pointer;
        background: var(--win-bg);
        box-shadow:
            inset -1px -1px 0 var(--bezel-dark-1),
            inset  1px  1px 0 var(--bezel-light-1),
            inset -2px -2px 0 var(--bezel-dark-2),
            inset  2px  2px 0 var(--bezel-light-2);
        user-select: none;
    }
    .ch-friend:active {
        box-shadow:
            inset  1px  1px 0 var(--bezel-dark-1),
            inset -1px -1px 0 var(--bezel-light-1),
            inset  2px  2px 0 var(--bezel-dark-2),
            inset -2px -2px 0 var(--bezel-light-2);
    }
    .ch-friend-av {
        width: 40px; height: 40px;
        background: var(--input-bg);
        border: 1px solid var(--bezel-dark-1);
        flex-shrink: 0;
        overflow: hidden;
        display: flex; align-items: center; justify-content: center;
    }
    .ch-friend-av img {
        width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .ch-friend-av-fallback {
        width: 24px; height: 24px;
        object-fit: contain;
        image-rendering: pixelated;
        opacity: 0.6;
    }
    .ch-friend-info {
        flex: 1; min-width: 0;
        display: flex; flex-direction: column;
        gap: 2px;
    }
    .ch-friend-name {
        font-size: 16px;
        font-weight: bold;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    /* Icono de mute (campana tachada) al lado del nombre. */
    .ch-friend-mute-icon {
        width: 12px; height: 12px;
        opacity: 0.6;
        flex-shrink: 0;
    }
    .ch-friend-sub {
        font-size: 12px;
        color: var(--text-faint, #808080);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ch-friend-sub.has-unread {
        color: var(--accent);
        font-weight: bold;
    }
    .ch-friend-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        flex-shrink: 0;
    }
    .ch-friend-time {
        font-size: 10px;
        color: var(--text-faint, #888);
    }
    .ch-friend-badge {
        background: var(--accent);
        color: var(--accent-text);
        font-size: 12px;
        font-weight: bold;
        padding: 2px 7px;
        min-width: 20px;
        text-align: center;
    }
    /* Dot de presencia online — usa el accent del tema. position:relative
       en AMBOS contenedores de avatar para que el dot quede anclado a la
       esquina del avatar (lista + cabecera del chat). */
    .ch-friend-av, .ch-chat-av { position: relative; }
    .ch-presence-dot {
        position: absolute;
        right: -2px; bottom: -2px;
        width: 12px; height: 12px;
        background: var(--accent);
        border: 2px solid var(--win-bg);
        border-radius: 50%;
        display: none;
    }
    .ch-presence-dot.is-online { display: block; background: #2ecc71; }
    /* Ausente: el usuario tiene heartbeat reciente pero está inactivo
       o con la pantalla bloqueada — amarillo, sin animación. */
    .ch-presence-dot.is-away   { display: block; background: #f1c40f; }
    /* No molestar: mute_messages activo — rojo, gana sobre away/online. */
    .ch-presence-dot.is-dnd    { display: block; background: #e74c3c; }
    .ch-chat-av .ch-presence-dot {
        right: -1px; bottom: -1px;
        width: 10px; height: 10px;
        border-width: 1px;
    }

    /* ── Nota explicativa al final ── */
    .ch-note {
        margin: 16px 4px 0;
        padding: 10px 10px;
        background: var(--win-body-bg, var(--win-bg));
        border: 1px dashed var(--bezel-dark-1);
        color: var(--text-faint, #888);
        font-size: 13px;
        line-height: 1.4;
        text-align: center;
    }
    .ch-note img {
        width: 14px; height: 14px;
        object-fit: contain;
        image-rendering: pixelated;
        vertical-align: -2px;
        margin: 0 4px 0 0;
    }

    /* ── Vista chat individual ── */
    .ch-chat { flex: 1; min-height: 0; display: none; flex-direction: column; }
    .ch-chat.is-active { display: flex; }
    .ch-list.is-hidden { display: none; }

    .ch-chat-head {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 8px;
        background: var(--win-bg);
        box-shadow: 0 1px 0 var(--bezel-dark-2);
        flex-shrink: 0;
    }
    .ch-back-btn {
        min-height: 30px;
        min-width: 36px;
        font-size: 14px;
    }
    .ch-chat-av {
        width: 30px; height: 30px;
        flex-shrink: 0;
        border: 1px solid var(--bezel-dark-1);
        overflow: hidden;
    }
    .ch-chat-av img { width:100%; height:100%; object-fit:cover; display:block; }
    /* Wrapper que agrupa nombre + sub-status (Online / Última vez). */
    .ch-chat-name-wrap {
        flex: 1; min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
        line-height: 1.1;
    }
    .ch-chat-name {
        font-size: 14px; font-weight: bold;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ch-chat-status {
        font-size: 11px;
        color: var(--text-faint, #888);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ch-chat-status.is-online {
        color: var(--accent);
        font-weight: bold;
    }

    .ch-feed {
        flex: 1; min-height: 0;
        overflow-y: auto;
        padding: 8px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        background: var(--win-body-bg, var(--win-bg));
    }
    .ch-msg {
        max-width: 75%;
        padding: 6px 9px;
        font-size: 14px;
        line-height: 1.3;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .ch-msg.is-mine {
        align-self: flex-end;
        background: var(--accent);
        color: var(--accent-text);
    }
    .ch-msg.is-them {
        align-self: flex-start;
        background: var(--win-bg);
        color: var(--text);
        border: 1px solid var(--bezel-dark-1);
    }
    .ch-msg-meta {
        font-size: 10px;
        opacity: 0.7;
        margin-top: 2px;
        display: flex;
        align-items: center;
        gap: 3px;
        justify-content: flex-end;
    }
    .ch-msg.is-them .ch-msg-meta { justify-content: flex-start; }
    /* "(editado)" — entre paréntesis pequeño, en la meta-row. */
    .ch-msg-edited {
        font-style: italic;
        opacity: 0.7;
        font-size: 10px;
    }
    /* Mensaje eliminado (soft delete). */
    .ch-msg.is-deleted .ch-msg-text {
        font-style: italic;
        opacity: 0.6;
    }
    /* Hipervínculos detectados dentro de un mensaje: subrayado claro,
       hereda color del bubble para mantener contraste con accent o
       fondo Win98. */
    .ch-msg-link {
        color: inherit;
        text-decoration: underline;
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
        word-break: break-all;
    }
    .ch-msg.is-mine .ch-msg-link { color: var(--accent-text); }
    /* Imagen embebida (link a jpg/png/gif/webp o GIF). Ocupa el ancho
       disponible del bubble, sin desbordar. Cuadrado máximo 240px para
       que GIFs grandes no rompan la conversación. Click abre lightbox. */
    .ch-msg-img {
        display: block;
        max-width: 100%;
        max-height: 240px;
        margin: 2px 0;
        border: 1px solid var(--bezel-dark-1, #808080);
        object-fit: contain;
        background: rgba(0,0,0,0.05);
        cursor: zoom-in;
    }
    /* Lightbox compartido del chat: overlay full-screen con la imagen
       al máximo tamaño, click fuera o en la X → cierra. */
    .ch-lightbox {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.92);
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        box-sizing: border-box;
        cursor: zoom-out;
        touch-action: none;
    }
    .ch-lightbox.is-open { display: flex; }
    .ch-lightbox img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        cursor: default;
    }
    .ch-lightbox-close {
        position: absolute;
        top: max(12px, env(safe-area-inset-top));
        right: 12px;
        width: 40px;
        height: 40px;
        background: rgba(255,255,255,0.15);
        border: 0;
        border-radius: 50%;
        color: #fff;
        font-size: 22px;
        font-weight: bold;
        line-height: 1;
        cursor: pointer;
    }
    .ch-msg.is-mine.is-deleted {
        background: var(--win-bg);
        color: var(--text-faint);
        border: 1px dashed var(--bezel-dark-1);
    }
    /* Modal de edición tipo bottom-sheet (mismo patrón que long-press). */
    .ch-edit-backdrop {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.55);
        z-index: 2100;
        display: none;
        align-items: flex-end;
        justify-content: center;
    }
    .ch-edit-backdrop.is-open { display: flex; }
    .ch-edit-sheet {
        background: var(--win-bg);
        width: 100%;
        max-width: 520px;
        animation: chLpIn 0.22s cubic-bezier(0.2, 0.9, 0.3, 1.2);
        box-shadow: 0 -4px 12px rgba(0,0,0,0.4);
        display: flex; flex-direction: column;
    }
    .ch-edit-sheet .ch-lp-title { padding: 6px 10px; }
    .ch-edit-sheet .ch-lp-body  { padding: 10px; }
    .ch-edit-sheet textarea {
        width: 100%;
        min-height: 60px;
        max-height: 200px;
        resize: vertical;
        font-size: 14px;
        padding: 6px 8px;
        margin-bottom: 8px;
    }
    /* Ticks estilo WhatsApp: ✓ enviado, ✓✓ recibido, ✓✓ accent leído. */
    .ch-ticks {
        display: inline-flex;
        align-items: center;
        font-family: 'VT323', monospace;
        font-size: 13px;
        line-height: 1;
        letter-spacing: -3px;
        padding-right: 3px;
    }
    .ch-ticks.is-sent  { opacity: 0.7; }
    .ch-ticks.is-delivered { opacity: 0.85; }
    /* Read = doble tick con el accent del tema. Glow para resaltar.
       (Antes era #4fc3f7 hardcoded — ahora respeta el tema del usuario.) */
    .ch-ticks.is-read  {
        color: var(--accent);
        opacity: 1;
        text-shadow: 0 0 4px var(--accent);
    }
    /* En burbujas mías (sobre fondo accent) el read usa accent-text
       para garantizar contraste — accent-on-accent sería invisible. */
    .ch-msg.is-mine .ch-ticks.is-sent,
    .ch-msg.is-mine .ch-ticks.is-delivered { opacity: 0.85; }
    .ch-msg.is-mine .ch-ticks.is-read {
        color: var(--accent-text);
        text-shadow: 0 0 4px var(--accent-text);
    }
    .ch-feed-empty {
        text-align: center;
        color: var(--text-faint, #888);
        padding: 30px 14px;
        font-size: 14px;
    }

    .ch-composer {
        display: flex;
        gap: 4px;
        padding: 6px;
        background: var(--win-bg);
        box-shadow: 0 -1px 0 var(--bezel-light-1);
        flex-shrink: 0;
        padding-bottom: calc(6px + env(safe-area-inset-bottom));
    }
    .ch-composer textarea {
        flex: 1;
        min-height: 34px;
        max-height: 90px;
        resize: none;
        font-size: 14px;
        padding: 5px 7px;
    }
    /* Botones cuadrados 34×34 para tap-target táctil cómodo.
       OJO: 98.css aplica `min-width: 75px` al elemento <button>. Sin
       sobreescribirlo, el width:34px se ignora porque el browser respeta
       el min-width (max(min-width, width) = 75px). Lo bajamos a 0 para
       que el width tome efecto. */
    .ch-composer .button {
        width: 34px;
        min-width: 0;
        height: 34px;
        min-height: 34px;
        padding: 0;
        font-size: 18px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .ch-composer .button.ch-emoji-btn { font-size: 18px; }
    /* Flecha ▸ como icono de "Enviar". Tamaño ligeramente mayor que el
       emoji para que destaque como botón principal de acción. */
    .ch-composer .button.ch-send-btn { font-size: 20px; font-weight: bold; }

    /* ── Picker panel (Emojis + GIFs en tabs) ──
       El panel exterior ya no es la grid de emojis; ahora contiene
       una fila de tabs y un body con paneles intercambiables. */
    .ch-emoji-panel {
        display: none;
        flex-direction: column;
        padding: 0;
        background: var(--win-body-bg, var(--win-bg));
        box-shadow: inset 0 1px 0 var(--bezel-dark-1);
        max-height: 230px;
        flex-shrink: 0;
    }
    .ch-emoji-panel.is-open { display: flex; }
    .ch-picker-tabs {
        display: flex;
        gap: 0;
        background: var(--win-bg);
        border-bottom: 1px solid var(--bezel-dark-1);
        flex-shrink: 0;
    }
    .ch-picker-tabs button {
        flex: 1;
        background: var(--win-bg);
        border: 0;
        border-right: 1px solid var(--bezel-dark-1);
        padding: 6px 8px;
        font-size: 12px;
        cursor: pointer;
        color: var(--text);
    }
    .ch-picker-tabs button:last-child { border-right: 0; }
    .ch-picker-tabs button.is-active {
        background: var(--win-body-bg, var(--win-bg));
        font-weight: bold;
    }
    .ch-picker-body {
        flex: 1;
        min-height: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    /* Base: solo control de tamaño en el flex parent. Cada pane define
       su propio layout, padding y scroll internamente — meter overflow
       y padding aquí rompía el flex column del pane GIFs (input
       quedaba debajo de un wrapper scrollable mal dimensionado). */
    .ch-picker-pane {
        flex: 1;
        min-height: 0;
    }
    .ch-picker-pane.ch-pane-emoji {
        display: flex;
        flex-wrap: wrap;
        gap: 2px;
        padding: 6px;
        overflow-y: auto;
    }
    .ch-emoji {
        font-size: 22px;
        padding: 4px 6px;
        cursor: pointer;
        line-height: 1;
        background: none;
        border: 1px solid transparent;
    }
    /* GIF picker: input de búsqueda fijo arriba, grid de previews
       2 columnas en mobile. Padding propio (no heredado del base). */
    .ch-picker-pane.ch-pane-gifs {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 6px;
    }
    /* `[hidden]` debe ir AL FINAL con !important para que gane al
       `display:flex` de cada pane específico cuando se cambia de tab.
       Antes el orden de cascade dejaba siempre visible el emoji
       (display:flex declarado después de [hidden]) y siempre oculto
       el GIFs (display:none por defecto), causando que la barra de
       búsqueda y el grid de emojis se vieran a la vez al fondo. */
    .ch-picker-pane[hidden] { display: none !important; }
    .ch-gif-search {
        width: 100%;
        box-sizing: border-box;
        font-size: 13px;
        padding: 4px 6px;
        flex-shrink: 0;
    }
    .ch-gif-results {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px;
    }
    .ch-gif-results img {
        width: 100%;
        height: 90px;
        object-fit: cover;
        cursor: pointer;
        background: rgba(0,0,0,0.08);
        border: 1px solid var(--bezel-dark-1, #808080);
    }
    .ch-gif-results img:active { opacity: 0.75; }
    .ch-gif-status {
        font-size: 11px;
        color: var(--text-faint);
        text-align: center;
        padding: 12px;
    }
    .ch-gif-attribution {
        font-size: 9px;
        color: var(--text-faint);
        text-align: right;
        padding: 2px 4px;
        flex-shrink: 0;
    }
    .ch-emoji:active {
        background: var(--accent);
        border: 1px solid var(--bezel-dark-1);
    }

    /* ── Modal long-press (acciones del chat) ── */
    .ch-lp-backdrop {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.55);
        z-index: 2000;
        display: none;
        align-items: flex-end;
        justify-content: center;
    }
    .ch-lp-backdrop.is-open { display: flex; }
    .ch-lp-sheet {
        background: var(--win-bg);
        width: 100%;
        max-width: 520px;
        animation: chLpIn 0.22s cubic-bezier(0.2, 0.9, 0.3, 1.2);
        box-shadow: 0 -4px 12px rgba(0,0,0,0.4);
        max-height: 80vh;
        display: flex;
        flex-direction: column;
    }
    @keyframes chLpIn {
        from { transform: translateY(100%); }
        to   { transform: translateY(0); }
    }
    .ch-lp-title {
        background: linear-gradient(to right, var(--titlebar-start), var(--titlebar-end));
        color: var(--titlebar-text);
        padding: 6px 10px;
        font-size: 13px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    .ch-lp-close {
        background: var(--win-bg);
        color: var(--text);
        border: 1px solid var(--bezel-dark-1);
        box-shadow:
            inset -1px -1px 0 var(--bezel-dark-2),
            inset  1px  1px 0 var(--bezel-light-2);
        width: 24px; height: 22px;
        font-size: 12px;
        padding: 0;
        cursor: pointer;
    }
    .ch-lp-body {
        padding: 8px;
        overflow-y: auto;
    }
    .ch-lp-section { margin-bottom: 10px; }
    .ch-lp-label {
        font-size: 11px;
        color: var(--text-faint);
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 4px;
        padding: 0 2px;
    }
    .ch-lp-opt {
        display: block;
        width: 100%;
        text-align: left;
        padding: 8px 10px;
        font-size: 14px;
        background: var(--win-bg);
        color: var(--text);
        border: none;
        box-shadow:
            inset -1px -1px 0 var(--bezel-dark-1),
            inset  1px  1px 0 var(--bezel-light-1),
            inset -2px -2px 0 var(--bezel-dark-2),
            inset  2px  2px 0 var(--bezel-light-2);
        margin-bottom: 4px;
        cursor: pointer;
    }
    .ch-lp-opt:active {
        box-shadow:
            inset  1px  1px 0 var(--bezel-dark-1),
            inset -1px -1px 0 var(--bezel-light-1),
            inset  2px  2px 0 var(--bezel-dark-2),
            inset -2px -2px 0 var(--bezel-light-2);
    }
    .ch-lp-opt.is-selected {
        background: var(--accent);
        color: var(--accent-text);
    }
    .ch-lp-opt.danger { color: var(--error-text, #c33); }
    .ch-lp-input {
        width: 100%;
        font-size: 14px;
        padding: 6px 8px;
        margin-bottom: 6px;
    }
    .ch-lp-row { display: flex; gap: 6px; }
    .ch-lp-row .button { flex: 1; min-height: 32px; font-size: 13px; }
    .ch-mute-status {
        font-size: 12px;
        color: var(--text-faint);
        margin-top: 4px;
        padding: 4px 2px;
    }
    </style>
</head>
<body class="mh-body <?= htmlspecialchars($activeThemeClass) ?>">
<div class="window mh-window">
    <div class="title-bar">
        <div class="title-bar-text">
            <img src="../../assets/img/appIcons/chatIcon.png" alt="" style="width:16px;height:16px;object-fit:contain;image-rendering:pixelated;vertical-align:middle;margin-right:4px;">Chat
        </div>
        <div class="title-bar-controls">
            <button aria-label="Close" id="ch-titlebar-close"></button>
        </div>
    </div>
    <div class="window-body">

        <!-- VISTA: lista de amigos. El flex:1 viene del CSS de .ch-list
             (no inline) para que .is-hidden pueda ganarle con display:none. -->
        <div class="ch-list" id="ch-list">
            <div class="ch-list-wrap">
                <div id="ch-list-content"><p style="font-size:13px;color:var(--text-faint);text-align:center;padding:18px;">Cargando…</p></div>
                <!-- Nota explicativa: cómo hacer más amigos. -->
                <div class="ch-note">
                    <img src="../../assets/img/appIcons/profileIcon.png" alt="">
                    Para chatear con más gente debes hacer amigos siguiéndoos mutuamente con otro usuario.
                </div>
            </div>
        </div>

        <!-- VISTA: chat individual -->
        <div class="ch-chat" id="ch-chat">
            <div class="ch-chat-head">
                <button class="button ch-back-btn" id="ch-back-list" type="button">‹</button>
                <div class="ch-chat-av" id="ch-chat-av"></div>
                <div class="ch-chat-name-wrap">
                    <div class="ch-chat-name" id="ch-chat-name">—</div>
                    <div class="ch-chat-status" id="ch-chat-status"></div>
                </div>
            </div>
            <div class="ch-feed" id="ch-feed"></div>
            <!-- Panel de emojis (toggle) -->
            <div class="ch-emoji-panel" id="ch-emoji-panel">
                <div class="ch-picker-tabs" id="ch-picker-tabs">
                    <button type="button" data-tab="emoji" class="is-active">Emojis</button>
                    <button type="button" data-tab="gifs">GIFs</button>
                </div>
                <div class="ch-picker-body">
                    <div class="ch-picker-pane ch-pane-emoji" data-pane="emoji" id="ch-pane-emoji"></div>
                    <div class="ch-picker-pane ch-pane-gifs" data-pane="gifs" id="ch-pane-gifs" hidden>
                        <input type="text" class="ch-gif-search" id="ch-gif-search" placeholder="Buscar GIFs...">
                        <div class="ch-gif-results" id="ch-gif-results"></div>
                        <div class="ch-gif-attribution">Powered by GIPHY</div>
                    </div>
                </div>
            </div>
            <div class="ch-composer">
                <button class="button ch-emoji-btn" id="ch-emoji-btn" type="button" aria-label="Emoji">☺</button>
                <textarea id="ch-input" placeholder="Mensaje…" maxlength="2000"></textarea>
                <button class="button ch-send-btn" id="ch-send" type="button" aria-label="Enviar">▸</button>
            </div>
        </div>

    <!-- Status bar Win98 al pie. DEBE ir DENTRO de .window-body —
         .mh-window tiene height:100% y .mh-body overflow:hidden, así
         que cualquier cosa fuera del .mh-window queda invisible. -->
    <div class="mh-statusbar">
        <a href="#" id="ch-menu-link">‹ Menú</a>
    </div>

    </div><!-- /.window-body -->
</div><!-- /.window.mh-window -->

<!-- Modal long-press en MENSAJE: editar / eliminar. -->
<div class="ch-lp-backdrop" id="ch-msg-backdrop">
    <div class="ch-lp-sheet">
        <div class="ch-lp-title">
            <span>Mensaje</span>
            <button class="ch-lp-close" id="ch-msg-close" type="button">✕</button>
        </div>
        <div class="ch-lp-body">
            <button class="ch-lp-opt" id="ch-msg-edit"   type="button">Editar mensaje</button>
            <button class="ch-lp-opt danger" id="ch-msg-delete" type="button">Eliminar mensaje</button>
        </div>
    </div>
</div>

<!-- Modal edición de mensaje. -->
<div class="ch-edit-backdrop" id="ch-edit-backdrop">
    <div class="ch-edit-sheet">
        <div class="ch-lp-title">
            <span>Editar mensaje</span>
            <button class="ch-lp-close" id="ch-edit-close" type="button">✕</button>
        </div>
        <div class="ch-lp-body">
            <textarea id="ch-edit-input" maxlength="2000"></textarea>
            <div class="ch-lp-row">
                <button class="button" id="ch-edit-cancel" type="button">Cancelar</button>
                <button class="button" id="ch-edit-save"   type="button">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal long-press en FRIEND CARD: silenciar + apodo del chat. -->
<div class="ch-lp-backdrop" id="ch-lp-backdrop">
    <div class="ch-lp-sheet">
        <div class="ch-lp-title">
            <span id="ch-lp-title-text">Opciones del chat</span>
            <button class="ch-lp-close" id="ch-lp-close" type="button">✕</button>
        </div>
        <div class="ch-lp-body">
            <div class="ch-lp-section">
                <div class="ch-lp-label">Apodo</div>
                <input type="text" id="ch-lp-nick-input" class="ch-lp-input" placeholder="Sin apodo" maxlength="60">
                <div class="ch-lp-row">
                    <button class="button" id="ch-lp-nick-clear" type="button">Quitar apodo</button>
                    <button class="button" id="ch-lp-nick-save" type="button">Guardar</button>
                </div>
            </div>
            <div class="ch-lp-section">
                <div class="ch-lp-label">Silenciar notificaciones</div>
                <button class="ch-lp-opt" data-mute="off"     type="button">Activar notificaciones</button>
                <button class="ch-lp-opt" data-mute="1h"      type="button">Silenciar 1 hora</button>
                <button class="ch-lp-opt" data-mute="8h"      type="button">Silenciar 8 horas</button>
                <button class="ch-lp-opt" data-mute="24h"     type="button">Silenciar 24 horas</button>
                <button class="ch-lp-opt" data-mute="1w"      type="button">Silenciar 1 semana</button>
                <button class="ch-lp-opt" data-mute="forever" type="button">Silenciar indefinidamente</button>
                <div class="ch-mute-status" id="ch-lp-mute-status"></div>
            </div>
        </div>
    </div>
</div>

<script>
window.__CH_CFG = {
    API: <?= json_encode($projectBaseUrl . '/assets/profile/api.php') ?>,
    USER_KEY: <?= json_encode($userKey) ?>,
    USER_LABEL: <?= json_encode($userLabel) ?>,
    USERS: <?php
        $u = [];
        foreach ($loginUsers as $k => $info) {
            $img = getUserImage_chat($info['label']);
            $u[$k] = [
                'label' => $info['label'],
                'image' => $img !== '' ? '../../' . $img : ''
            ];
        }
        echo json_encode($u);
    ?>
};
</script>
<script src="chat-mobile.js?v=<?= filemtime(__DIR__ . '/chat-mobile.js') ?>"></script>
</body>
</html>
