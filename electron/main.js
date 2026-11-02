/* ──────────────────────────────────────────────────────────────────────
   MELON HUB — app de escritorio (Electron)
   ──────────────────────────────────────────────────────────────────────
   Una ventana que abre la web desde el servidor (package.json →
   melonhub.url, o la variable de entorno MELONHUB_URL para probar contra
   otro sitio). El PHP no se toca: Electron se presenta como un Chrome de
   escritorio, así que mobile-detect.php y pwa-guard.js lo tratan como PC.

   Lo que añade sobre abrir la web en el navegador:
     - Sesión persistente (las cookies viven en el perfil de la app).
     - El reproductor no se frena con la ventana minimizada o tapada
       (backgroundThrottling: false).
     - Enlaces a otras webs → navegador del sistema. Dentro de la app solo
       se queda la propia web y el login de Discord (vincular cuenta).
     - Recuerda tamaño/posición de la ventana. Una sola instancia.
     - F12 herramientas de desarrollador, F5 / Ctrl+R recargar.
   ────────────────────────────────────────────────────────────────────── */
const { app, BrowserWindow, shell, Menu, session } = require('electron');
const path = require('path');
const fs = require('fs');

const START_URL  = process.env.MELONHUB_URL || require('./package.json').melonhub.url;
const APP_ORIGIN = new URL(START_URL).origin;

/* Webs que pueden ocupar la ventana de la app además de la propia.
   Discord: el OAuth de vincular cuenta (discord-oauth/start.php) pasa
   por discord.com y vuelve a discord-oauth/callback.php. */
const INTERNAL_HOSTS = ['discord.com'];

/* Orígenes a los que se conceden permisos (notificaciones, portapapeles,
   pantalla completa…). YouTube va dentro del reproductor. */
const TRUSTED_HOSTS = ['youtube.com', 'youtube-nocookie.com'];

function hostMatches(hostname, list) {
    return list.some(h => hostname === h || hostname.endsWith('.' + h));
}

function isInternal(url) {
    try {
        const u = new URL(url);
        return u.origin === APP_ORIGIN || hostMatches(u.hostname, INTERNAL_HOSTS);
    } catch (_) {
        return false;
    }
}

function openOutside(url) {
    if (/^https?:\/\//i.test(url)) shell.openExternal(url);
}

/* ── Tamaño y posición de la ventana ──────────────────────────────── */
const STATE_FILE = path.join(app.getPath('userData'), 'window-state.json');

function loadWindowState() {
    try {
        return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    } catch (_) {
        return { width: 1280, height: 800 };
    }
}

function saveWindowState(win) {
    try {
        const state = { ...win.getNormalBounds(), maximized: win.isMaximized() };
        fs.writeFileSync(STATE_FILE, JSON.stringify(state));
    } catch (_) { /* no es grave */ }
}

/* ── Pantalla de "sin conexión" ───────────────────────────────────── */
function offlinePage(reason) {
    const html = `<!doctype html><meta charset="utf-8"><title>Melon Hub</title>
<body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;
             background:#1a1a1a;color:#ddd;font:15px system-ui,sans-serif;text-align:center">
<div><p style="font-size:40px;margin:0">🍈</p>
<p>No se puede conectar con Melon Hub.</p>
<p style="color:#888;font-size:12px">${reason.replace(/[<>&]/g, '')}</p>
<button onclick="location.href=${JSON.stringify(START_URL)}"
        style="font:inherit;padding:6px 18px;cursor:pointer">Reintentar</button></div>`;
    return 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
}

/* ── Ventana principal ────────────────────────────────────────────── */
let mainWindow = null;

function createWindow() {
    const state = loadWindowState();
    const win = new BrowserWindow({
        x: state.x, y: state.y,
        width: state.width || 1280, height: state.height || 800,
        minWidth: 900, minHeight: 600,
        title: 'Melon Hub',
        icon: path.join(__dirname, 'icon.png'),
        backgroundColor: '#1a1a1a',
        show: false,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            sandbox: true,
            nodeIntegration: false,
            backgroundThrottling: false,
        },
    });
    if (state.maximized) win.maximize();
    win.once('ready-to-show', () => win.show());
    win.on('close', () => saveWindowState(win));

    const wc = win.webContents;

    /* Ventanas nuevas (target=_blank, window.open): las de la propia web
       se abren en otra ventana de la app; el resto, en el navegador. */
    wc.setWindowOpenHandler(({ url }) => {
        if (isInternal(url)) return { action: 'allow' };
        openOutside(url);
        return { action: 'deny' };
    });
    wc.on('will-navigate', (e, url) => {
        if (isInternal(url) || url.startsWith('data:')) return;
        e.preventDefault();
        openOutside(url);
    });

    wc.on('did-fail-load', (_e, code, desc, url, isMainFrame) => {
        /* -3 = navegación cancelada (p. ej. por will-navigate): no es un fallo. */
        if (!isMainFrame || code === -3) return;
        wc.loadURL(offlinePage(desc + ' — ' + url));
    });

    /* Sin barra de menú: los atajos útiles a mano. */
    wc.on('before-input-event', (e, input) => {
        if (input.type !== 'keyDown') return;
        const ctrl = input.control || input.meta;
        if (input.key === 'F12') {
            wc.toggleDevTools();
        } else if (input.key === 'F5' || (ctrl && input.key.toLowerCase() === 'r')) {
            input.shift ? wc.reloadIgnoringCache() : wc.reload();
        } else {
            return;
        }
        e.preventDefault();
    });

    win.loadURL(START_URL);
    return win;
}

/* Permisos solo para la propia web y YouTube. */
function setupPermissions() {
    const allowed = (origin) => {
        try {
            const u = new URL(origin);
            return u.origin === APP_ORIGIN || hostMatches(u.hostname, TRUSTED_HOSTS);
        } catch (_) {
            return false;
        }
    };
    session.defaultSession.setPermissionRequestHandler((wc, _perm, cb, details) => {
        cb(allowed(details.requestingUrl || wc.getURL()));
    });
    session.defaultSession.setPermissionCheckHandler((_wc, _perm, origin) => allowed(origin));
}

/* Una sola instancia: abrir la app otra vez enfoca la ventana que hay. */
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (!mainWindow) return;
        if (mainWindow.isMinimized()) mainWindow.restore();
        mainWindow.focus();
    });

    app.whenReady().then(() => {
        Menu.setApplicationMenu(null);
        setupPermissions();
        mainWindow = createWindow();
        mainWindow.on('closed', () => { mainWindow = null; });
    });

    app.on('window-all-closed', () => app.quit());
}
