/* ──────────────────────────────────────────────────────────────────────
   EXTRAS DE ESCRITORIO
   ──────────────────────────────────────────────────────────────────────
   Lo que la app añade a la web y un navegador no puede hacer:
     - Atajos globales de la música, aunque Melon Hub no tenga el foco:
         Ctrl+Alt+Espacio  reproducir / pausar
         Ctrl+Alt+→ / ←    siguiente / anterior
         Ctrl+Alt+↑ / ↓    subir / bajar el volumen
       (Las teclas multimedia del teclado ya van por MPRIS / SMTC.)
     - Notificaciones del sistema: todo lo que avisa el haro sale también
       como notificación de KDE / Windows cuando no se está mirando Melon
       Hub (melon:notify desde desktop-base.php). Al pulsarla se abre.
     - Contador de mensajes del chat sin leer en el icono de la bandeja y,
       en Windows, en el de la barra de tareas (melon:badge).
     - Windows: botones ⏮ ⏯ ⏭ en la miniatura de la barra de tareas.
     - Arrastrar una imagen de Melon Hub (galería, momentos, chat…) fuera
       de la app, a una carpeta o a otro programa, como archivo
       (melon:drag-prepare al pulsarla y melon:drag-start al arrastrarla).
   ────────────────────────────────────────────────────────────────────── */
const { app, globalShortcut, ipcMain, Notification, nativeImage, net } = require('electron');
const crypto = require('crypto');
const fs = require('fs');
const fsp = fs.promises;
const os = require('os');
const path = require('path');

const ICONS = path.join(__dirname, 'icons');
let win = null, tray = null, openMain = () => {};

function send(action, value) {
    if (win && !win.isDestroyed()) win.webContents.send('melon:player-command', action, value);
}

/* ── Atajos globales ── */
const SHORTCUTS = {
    'Control+Alt+Space': ['toggle'],
    'Control+Alt+Right': ['nexttrack'],
    'Control+Alt+Left': ['previoustrack'],
    'Control+Alt+Up': ['volume-step', 10],
    'Control+Alt+Down': ['volume-step', -10],
};
function registerShortcuts() {
    for (const [keys, [action, value]] of Object.entries(SHORTCUTS)) {
        /* Si otro programa ya usa la combinación, no se registra (devuelve false). */
        try {
            if (!globalShortcut.register(keys, () => send(action, value))) console.warn('[atajos] ocupado:', keys);
        } catch (e) { console.warn('[atajos]', keys, e.message); }
    }
    app.on('will-quit', () => globalShortcut.unregisterAll());
}

/* ── Notificaciones del sistema ── */
const appIcon = () => nativeImage.createFromPath(path.join(__dirname, 'icon.png'));
function notify(_e, n) {
    if (!n || !Notification.isSupported()) return;
    /* Solo si no se está mirando la ventana principal (la web ya lo
       comprueba; esto cubre el reproductor flotante con el foco). */
    if (win && !win.isDestroyed() && win.isVisible() && win.isFocused()) return;
    const note = new Notification({
        title: String(n.title || 'Melon Hub').slice(0, 120),
        body: String(n.body || '').slice(0, 300),
        icon: appIcon(),
        silent: false,
    });
    note.on('click', () => openMain());
    note.show();
}

/* ── Contador de mensajes sin leer ── */
let badge = 0;
function badgeFile(kind, n) {
    return path.join(ICONS, `${kind}-${n > 9 ? '9plus' : n}.png`);
}
function setBadge(_e, n) {
    n = Math.max(0, Math.floor(Number(n) || 0));
    if (n === badge) return;
    badge = n;
    if (tray && !tray.isDestroyed()) {
        const img = n ? nativeImage.createFromPath(badgeFile('tray', n)) : appIcon().resize({ width: 32, height: 32 });
        tray.setImage(img);
        tray.setToolTip(n ? `Melon Hub · ${n} ${n === 1 ? 'mensaje sin leer' : 'mensajes sin leer'}` : 'Melon Hub');
    }
    if (process.platform === 'win32' && win && !win.isDestroyed()) {
        win.setOverlayIcon(n ? nativeImage.createFromPath(badgeFile('overlay', n)) : null, n ? `${n} sin leer` : '');
    }
}

/* ── Windows: botones de la miniatura de la barra de tareas ── */
let thumbPlaying = null;
function setThumbar(playing) {
    if (process.platform !== 'win32' || !win || win.isDestroyed()) return;
    if (playing === thumbPlaying) return;
    thumbPlaying = playing;
    const icon = (n) => nativeImage.createFromPath(path.join(ICONS, `thumb-${n}.png`));
    win.setThumbarButtons([
        { tooltip: 'Anterior', icon: icon('prev'), click: () => send('previoustrack') },
        { tooltip: playing ? 'Pausar' : 'Reproducir', icon: icon(playing ? 'pause' : 'play'), click: () => send('toggle') },
        { tooltip: 'Siguiente', icon: icon('next'), click: () => send('nexttrack') },
    ]);
}

/* ── Arrastrar imágenes fuera de la app ──
   startDrag necesita un archivo que ya exista: al pulsar la imagen
   (melon:drag-prepare) se descarga a una carpeta temporal y, al empezar a
   arrastrarla (melon:drag-start), se entrega ese archivo. */
const DRAG_DIR = path.join(os.tmpdir(), 'melonhub-arrastrar');
const prepared = new Map();   /* url → Promise<ruta | null> */
const EXT = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp' };
function dragName(url, type) {
    let base = 'imagen';
    try { base = decodeURIComponent(path.basename(new URL(url).pathname)) || base; } catch (_) {}
    base = base.replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[\\/:*?"<>|\x00-\x1f]+/g, ' ').trim().slice(0, 80) || 'imagen';
    return base + (EXT[type] || '.png');
}
function prepareDrag(url) {
    if (!/^(https?:|data:image\/)/.test(url || '')) return Promise.resolve(null);
    if (prepared.has(url)) return prepared.get(url);
    const p = (async () => {
        try {
            const res = await net.fetch(url);   /* con las cookies de la app */
            if (!res.ok) return null;
            const type = (res.headers.get('content-type') || '').split(';')[0];
            if (!/^image\//.test(type)) return null;
            const dir = path.join(DRAG_DIR, crypto.randomBytes(6).toString('hex'));
            await fsp.mkdir(dir, { recursive: true });
            const file = path.join(dir, dragName(url, type));
            await fsp.writeFile(file, Buffer.from(await res.arrayBuffer()));
            return file;
        } catch (_) { return null; }
    })();
    prepared.set(url, p);
    if (prepared.size > 50) prepared.delete(prepared.keys().next().value);
    return p;
}
async function startDrag(e, url) {
    const file = await Promise.race([prepareDrag(url), new Promise((r) => setTimeout(() => r(null), 2000))]);
    if (!file || e.sender.isDestroyed()) return;
    let icon = nativeImage.createFromPath(file);
    icon = icon.isEmpty() ? appIcon().resize({ width: 48 }) : icon.resize({ width: 64 });
    e.sender.startDrag({ file, icon });
}

function start(mainWindow, trayIcon, options) {
    win = mainWindow;
    tray = trayIcon;
    openMain = (options && options.openMain) || openMain;
    registerShortcuts();
    ipcMain.on('melon:notify', notify);
    ipcMain.on('melon:badge', setBadge);
    ipcMain.on('melon:drag-prepare', (_e, url) => { prepareDrag(url); });
    ipcMain.on('melon:drag-start', startDrag);
    ipcMain.on('melon:now-playing', (_e, s) => setThumbar(!!(s && s.playing)));
    /* Windows borra los botones de la miniatura al esconder la ventana. */
    win.on('show', () => { const p = thumbPlaying; thumbPlaying = null; setThumbar(!!p); });
    setThumbar(false);
    app.on('will-quit', () => { fs.rm(DRAG_DIR, { recursive: true, force: true }, () => {}); });
}

module.exports = { start };
