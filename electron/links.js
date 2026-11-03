/* ──────────────────────────────────────────────────────────────────────
   Enlaces melonhub://<tipo>/<valor> (ver assets/links.php en la web)
   ──────────────────────────────────────────────────────────────────────
   La web comparte enlaces normales (enlace.php?perfil=…); esa página
   prueba melonhub://… y, si la app está instalada, el sistema la abre
   con el enlace como argumento:
     - Windows: lo registra el instalador (package.json → build.protocols)
       y setAsDefaultProtocolClient para las instalaciones de antes.
     - Flatpak: MimeType=x-scheme-handler/melonhub en el .desktop.
     - AppImage: solo si el sistema la tiene integrada (no hay .desktop
       instalado al que apuntar).
   El enlace llega en process.argv (app cerrada) o en second-instance (app
   abierta). Se pasa a la página como "<tipo>:<valor>" (melonOpenLink en
   desktop-base.php) sin recargarla, para no cortar la música; si la
   página aún no está lista (o es una versión vieja de la web), se carga
   index.php?abrir=… y el escritorio lo abre al entrar.
   Los enlaces de la web pulsados dentro de la app (chat, posts…) se
   cortan aquí y se abren igual, sin pasar por enlace.php.
   ────────────────────────────────────────────────────────────────────── */
const { app, ipcMain } = require('electron');

const SCHEME = 'melonhub';

/* Igual que melonParseLink (assets/links.php). */
const VALID = {
    perfil:   /^[A-Za-z0-9_-]{1,50}$/,
    escuchar: /^[A-Za-z0-9_-]{1,50}$/,
    cancion:  /^[A-Za-z0-9_-]{11}$/,
    album:    /^[a-z]{2,12}:[A-Za-z0-9_-]{1,80}$/,
    playlist: /^[0-9]{1,10}$/,
};

function check(tipo, valor) {
    return VALID[tipo] && VALID[tipo].test(valor) ? tipo + ':' + valor : null;
}

/* melonhub://perfil/user2 → "perfil:user2" */
function fromProtocol(url) {
    try {
        const u = new URL(url);
        if (u.protocol !== SCHEME + ':') return null;
        const valor = decodeURIComponent(u.pathname.replace(/^\/+/, '').replace(/\/+$/, ''));
        return check(u.hostname, valor);
    } catch (_) {
        return null;
    }
}

/* Enlaces de la propia web: enlace.php?<tipo>=… y el index.php?escuchar=…
   del botón de la presencia de Discord. */
function fromWebUrl(url, origin) {
    try {
        const u = new URL(url);
        if (u.origin !== origin) return null;
        const p = u.searchParams;
        if (/\/enlace\.php$/.test(u.pathname)) {
            for (const tipo of Object.keys(VALID)) {
                if (p.has(tipo)) return check(tipo, p.get(tipo));
            }
        }
        if (/\/(index\.php)?$/.test(u.pathname) && p.has('escuchar')) return check('escuchar', p.get('escuchar'));
    } catch (_) { /* no es un enlace */ }
    return null;
}

function fromArgv(argv) {
    for (const a of argv || []) {
        if (typeof a === 'string' && a.toLowerCase().startsWith(SCHEME + '://')) {
            const l = fromProtocol(a);
            if (l) return l;
        }
    }
    return null;
}

/* Primera página: con enlace, index.php?abrir=… (el escritorio lo abre). */
function startUrl(base, argv) {
    const l = fromArgv(argv);
    return l ? new URL('index.php?abrir=' + encodeURIComponent(l), base).href : base;
}

let win = null;
let base = null;
let showMain = () => {};
let ready = false;   /* la página ha puesto melonDesktop.onOpenLink */

function open(link) {
    if (!link || !win || win.isDestroyed()) return;
    showMain();
    if (ready) win.webContents.send('melon:open-link', link);
    else win.loadURL(new URL('index.php?abrir=' + encodeURIComponent(link), base).href);
}

function start(mainWindow, startUrlBase, opts) {
    win = mainWindow;
    base = startUrlBase;
    showMain = (opts && opts.openMain) || showMain;

    /* Windows: también para quien instaló antes de existir los enlaces. */
    if (process.platform === 'win32' && app.isPackaged) {
        try { app.setAsDefaultProtocolClient(SCHEME); } catch (_) {}
    }

    /* Cada página nueva de la ventana vuelve a avisar cuando escucha. */
    win.webContents.on('did-navigate', () => { ready = false; });
    ipcMain.on('melon:open-link-ready', (e) => {
        if (win && !win.isDestroyed() && e.sender === win.webContents) ready = true;
    });
}

module.exports = { start, open, fromArgv, fromWebUrl, startUrl };
