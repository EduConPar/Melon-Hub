/* ──────────────────────────────────────────────────────────────────────
   REPRODUCTOR FLOTANTE
   ──────────────────────────────────────────────────────────────────────
   Ventanita sin marco, siempre encima, que sale al cerrar Melon Hub con
   música sonando (tray.js decide cuándo). Carga apps/mini-player.html de
   la web, una copia de la ventana del reproductor del escritorio.
   La música sigue en la ventana principal (escondida): esta ventana solo
   enseña lo que suena (melon:mini-state) y le reenvía los botones como
   melon:player-command. El CSS del tema y la interfaz se copia de la
   ventana principal cada vez que se enseña (melon:mini-look).
   ────────────────────────────────────────────────────────────────────── */
const { BrowserWindow, ipcMain, screen } = require('electron');
const path = require('path');
const settings = require('./settings');

const MARGIN = 20;   /* separación del borde, como el reproductor del escritorio */

let win = null;
let mainWin = null;
let pageUrl = '';
let state = null;
let fitted = false;
let wantVisible = false;
let opts = { isQuitting: () => false, onOpenMain: () => {}, onClose: () => {} };

function sendToMain(...args) {
    if (mainWin && !mainWin.isDestroyed()) mainWin.webContents.send(...args);
}

/* Posición guardada si sigue dentro de alguna pantalla; si no, abajo a
   la derecha de la pantalla principal. */
function place() {
    const [w, h] = win.getSize();
    const saved = settings.get('miniPosition');
    if (saved) {
        const area = screen.getDisplayMatching({ x: saved.x, y: saved.y, width: w, height: h }).workArea;
        if (saved.x >= area.x && saved.y >= area.y && saved.x + w <= area.x + area.width && saved.y + h <= area.y + area.height) {
            win.setPosition(saved.x, saved.y);
            return;
        }
    }
    const area = screen.getPrimaryDisplay().workArea;
    win.setPosition(area.x + area.width - w - MARGIN, area.y + area.height - h - MARGIN);
}

async function sendLook() {
    if (!win || !mainWin || mainWin.isDestroyed()) return;
    try {
        const look = await mainWin.webContents.executeJavaScript(`({
            css: Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(l => l.href),
            htmlClass: document.documentElement.className,
            bodyClass: document.body.className,
        })`);
        if (win && !win.isDestroyed()) win.webContents.send('melon:mini-look', look);
    } catch (_) { /* la ventana principal aún no ha cargado */ }
}

function create() {
    win = new BrowserWindow({
        width: 290,
        height: 150,
        useContentSize: true,
        show: false,
        frame: false,
        resizable: false,
        maximizable: false,
        minimizable: false,
        fullscreenable: false,
        alwaysOnTop: true,
        skipTaskbar: true,
        title: 'Melon Hub',
        icon: path.join(__dirname, 'icon.png'),
        backgroundColor: '#c0c0c0',
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            sandbox: true,
            nodeIntegration: false,
        },
    });
    fitted = false;
    win.on('moved', () => {
        const [x, y] = win.getPosition();
        settings.set('miniPosition', { x, y });
    });
    /* Alt+F4 o el gestor de ventanas = la X de la barra de título. */
    win.on('close', (e) => {
        if (opts.isQuitting()) return;
        e.preventDefault();
        opts.onClose();
    });
    win.on('closed', () => { win = null; });
    win.webContents.on('did-finish-load', sendLook);
    win.loadURL(pageUrl);
}

function show() {
    wantVisible = true;
    if (!win) create();
    else sendLook();
    if (state) win.webContents.send('melon:mini-state', state);
    /* La primera vez se enseña cuando la página ha medido su tamaño (fit). */
    if (fitted) win.show();
}

function hide() {
    wantVisible = false;
    if (win && win.isVisible()) win.hide();
}

function isVisible() {
    return !!(win && win.isVisible());
}

function setState(s) {
    state = s;
    if (win && !win.isDestroyed()) win.webContents.send('melon:mini-state', s);
}

function init(mainWindow, startUrl, options) {
    mainWin = mainWindow;
    pageUrl = new URL('apps/mini-player.html', startUrl).href;
    opts = { ...opts, ...options };

    ipcMain.handle('melon:mini-get', () => state);
    ipcMain.on('melon:mini-command', (_e, action, value) => sendToMain('melon:player-command', action, value));
    ipcMain.on('melon:mini-open-main', () => opts.onOpenMain());
    ipcMain.on('melon:mini-close', () => opts.onClose());
    ipcMain.on('melon:mini-fit', (_e, width, height) => {
        if (!win || !(width > 0) || !(height > 0)) return;
        win.setContentSize(Math.ceil(width), Math.ceil(height));
        if (!fitted) {
            fitted = true;
            place();
            if (wantVisible) win.show();
        }
    });
}

module.exports = { init, show, hide, isVisible, setState };
