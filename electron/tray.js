/* ──────────────────────────────────────────────────────────────────────
   SEGUNDO PLANO E ICONO DE LA BARRA DE TAREAS
   ──────────────────────────────────────────────────────────────────────
   Cerrar la ventana de Melon Hub no cierra la app: se esconde y queda el
   icono en la bandeja de la barra de tareas. Con el clic derecho:
     Abrir Melon Hub · Reproductor flotante · Presencia en Discord
     (las dos activables) · Reiniciar · Cerrar
   (el clic normal también la abre).
   Al cerrar la ventana:
     - con música sonando y "Reproductor flotante" activo (lo está por
       defecto) → sale el reproductor flotante (mini-player.js);
     - si no → la música se pausa: nunca suena nada sin una ventana que
       lo enseñe.
   ────────────────────────────────────────────────────────────────────── */
const { app, Tray, Menu, nativeImage, ipcMain } = require('electron');
const path = require('path');
const settings = require('./settings');
const mini = require('./mini-player');
const flatpak = require('./flatpak-update');
const discord = require('./discord');

let tray = null;
let mainWin = null;
let quitting = false;
let playing = false;
let lastState = null;

function openMain() {
    mini.hide();
    if (!mainWin || mainWin.isDestroyed()) return;
    if (mainWin.isMinimized()) mainWin.restore();
    mainWin.show();
    mainWin.focus();
}

function pauseMusic() {
    if (mainWin && !mainWin.isDestroyed()) mainWin.webContents.send('melon:player-command', 'pause');
}

function closeMini() {
    mini.hide();
    pauseMusic();
}

function restart() {
    if (flatpak.isFlatpak()) {
        /* Dentro de Flatpak la app nueva tiene que arrancar en su propio
           sandbox (el de esta se cierra con ella). */
        app.releaseSingleInstanceLock();
        flatpak.relaunchLatest();
    } else if (process.env.APPIMAGE) {
        app.relaunch({ execPath: process.env.APPIMAGE, args: process.argv.slice(1) });
    } else {
        app.relaunch();
    }
    app.quit();
}

function menuTemplate() {
    return [
        { label: 'Abrir Melon Hub', click: openMain },
        {
            label: 'Reproductor flotante',
            type: 'checkbox',
            checked: settings.get('floatingPlayer'),
            click: (item) => {
                settings.set('floatingPlayer', item.checked);
                if (!item.checked && mini.isVisible()) closeMini();
                refreshMenu();
            },
        },
        {
            label: 'Presencia en Discord',
            type: 'checkbox',
            checked: settings.get('discordPresence'),
            click: (item) => {
                settings.set('discordPresence', item.checked);
                discord.update(lastState);
                refreshMenu();
            },
        },
        { type: 'separator' },
        { label: 'Reiniciar', click: restart },
        { label: 'Cerrar', click: () => app.quit() },
    ];
}

function refreshMenu() {
    if (tray) tray.setContextMenu(Menu.buildFromTemplate(menuTemplate()));
}

function start(mainWindow, startUrl) {
    mainWin = mainWindow;
    app.on('before-quit', () => { quitting = true; });

    discord.setSite(startUrl);
    mini.init(mainWin, startUrl, {
        isQuitting: () => quitting,
        onOpenMain: openMain,
        onClose: closeMini,
    });

    ipcMain.on('melon:now-playing', (_e, s) => {
        playing = !!(s && s.playing);
        lastState = s;
        mini.setState(s);
        discord.update(s);
    });

    mainWin.on('close', (e) => {
        if (quitting) return;
        e.preventDefault();
        mainWin.hide();
        if (settings.get('floatingPlayer') && playing) mini.show();
        else pauseMusic();
    });

    tray = new Tray(nativeImage.createFromPath(path.join(__dirname, 'icon.png')).resize({ width: 32, height: 32 }));
    tray.setToolTip('Melon Hub');
    refreshMenu();
    tray.on('click', openMain);
}

function getTray() { return tray; }

module.exports = { start, openMain, menuTemplate, getTray };
