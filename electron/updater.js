/* ──────────────────────────────────────────────────────────────────────
   ACTUALIZACIONES DE LA APP DE ESCRITORIO
   ──────────────────────────────────────────────────────────────────────
   Solo afecta a la parte nativa (esta carpeta): la web se carga del
   servidor y siempre está al día. Al arrancar (y cada 6 h) se mira si hay
   versión nueva; si la hay, se avisa a la web (preload.js →
   window.melonDesktop.onUpdate) y el haro ofrece "Actualizar". Al pulsar:
     - Windows / AppImage: electron-updater descarga la versión nueva de
       la release de GitHub (latest.yml / latest-linux.yml, los genera
       electron-builder), la instala y reabre la app.
     - Flatpak: el portal de Flatpak la actualiza desde su repositorio y
       se reabre en la versión nueva (flatpak-update.js).
   Con `npm start` (sin empaquetar) no se busca nada.
   ────────────────────────────────────────────────────────────────────── */
const { app, ipcMain, net } = require('electron');
const flatpak = require('./flatpak-update');

const FEED = require('./package.json').melonhub.updateFeed;
const CHECK_EVERY_MS = 6 * 60 * 60 * 1000;

let win = null;
let updater = null;              /* electron-updater (Windows / AppImage) */
/* status: idle | available | downloading | error */
let state = { status: 'idle' };

function setState(next) {
    state = next;
    if (win && !win.isDestroyed()) win.webContents.send('melon:update', state);
}

function kind() {
    if (!app.isPackaged) return null;
    if (flatpak.isFlatpak()) return 'flatpak';
    if (process.platform === 'win32' || process.env.APPIMAGE) return 'electron-updater';
    return null;
}

/* "0.2.10" es más nuevo que "0.2.9". */
function isNewer(a, b) {
    const pa = a.split('.').map(Number);
    const pb = b.split('.').map(Number);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) > (pb[i] || 0);
    }
    return false;
}

async function check() {
    if (state.status === 'downloading') return;
    try {
        if (kind() === 'flatpak') {
            /* La versión publicada se lee del latest-linux.yml de la
               release, que build.sh sube junto al repositorio Flatpak. */
            const res = await net.fetch(FEED + 'latest-linux.yml', { cache: 'no-store' });
            const m = res.ok && /^version:\s*['"]?([\d.]+)/m.exec(await res.text());
            if (m && isNewer(m[1], app.getVersion())) setState({ status: 'available', version: m[1] });
        } else {
            await updater.checkForUpdates();
        }
    } catch (_) {
        /* Sin red o release a medio subir: se vuelve a mirar más tarde. */
    }
}

async function install() {
    if (state.status !== 'available' && state.status !== 'error') return;
    const version = state.version;
    setState({ status: 'downloading', version, percent: 0 });
    try {
        if (kind() === 'flatpak') {
            await flatpak.update((percent) => setState({ status: 'downloading', version, percent }));
            /* Suelta el bloqueo de instancia única para que la versión
               nueva pueda arrancar aunque esta aún no haya cerrado. */
            app.releaseSingleInstanceLock();
            flatpak.relaunchLatest();
            app.quit();
        } else {
            await updater.downloadUpdate();   /* sigue en 'update-downloaded' */
        }
    } catch (err) {
        setState({ status: 'error', version, error: String((err && err.message) || err) });
    }
}

function setupElectronUpdater() {
    const { autoUpdater } = require('electron-updater');
    autoUpdater.autoDownload = false;
    autoUpdater.autoInstallOnAppQuit = false;
    autoUpdater.on('update-available', (info) => setState({ status: 'available', version: info.version }));
    autoUpdater.on('download-progress', (p) => setState({ status: 'downloading', version: state.version, percent: Math.round(p.percent) }));
    /* Instalación silenciosa y reabrir la app al terminar. */
    autoUpdater.on('update-downloaded', () => autoUpdater.quitAndInstall(true, true));
    /* Los errores ya llegan por las promesas; sin este listener, un
       'error' sin escuchar tumbaría el proceso. */
    autoUpdater.on('error', () => {});
    return autoUpdater;
}

function start(window) {
    win = window;
    ipcMain.handle('melon:update-state', () => state);
    ipcMain.handle('melon:update-install', () => install());
    const k = kind();
    if (!k) return;
    if (k === 'electron-updater') updater = setupElectronUpdater();
    setTimeout(check, 15000);
    setInterval(check, CHECK_EVERY_MS);
}

module.exports = { start };
