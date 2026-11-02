/* Puente entre la web y la app de escritorio (window.melonDesktop). La web
   lo usa para saber que corre dentro de la app y para el aviso de
   actualización del haro (desktop-base.php). */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('melonDesktop', {
    platform: process.platform,

    /* Actualizaciones (updater.js). cb recibe { status, version, percent,
       error } con status idle | available | downloading | error: primero
       el estado actual y después cada cambio. */
    onUpdate(cb) {
        ipcRenderer.on('melon:update', (_e, s) => cb(s));
        ipcRenderer.invoke('melon:update-state').then(cb).catch(() => {});
    },
    installUpdate() {
        return ipcRenderer.invoke('melon:update-install');
    },

    /* Reproductor de la ventana principal (apps/reproductor.php): manda lo
       que suena y obedece los botones del reproductor flotante. */
    setNowPlaying(info) {
        ipcRenderer.send('melon:now-playing', info);
    },
    onPlayerCommand(cb) {
        ipcRenderer.on('melon:player-command', (_e, action, value) => cb(action, value));
    },

    /* Reproductor flotante (apps/mini-player.html, mini-player.js). */
    mini: {
        onState(cb) {
            ipcRenderer.on('melon:mini-state', (_e, s) => cb(s));
            ipcRenderer.invoke('melon:mini-get').then(cb).catch(() => {});
        },
        onLook(cb) {
            ipcRenderer.on('melon:mini-look', (_e, look) => cb(look));
        },
        command(action, value) {
            ipcRenderer.send('melon:mini-command', action, value);
        },
        openMain() {
            ipcRenderer.send('melon:mini-open-main');
        },
        minimize() {
            ipcRenderer.send('melon:mini-minimize');
        },
        close() {
            ipcRenderer.send('melon:mini-close');
        },
        fit(width, height) {
            ipcRenderer.send('melon:mini-fit', width, height);
        },
    },
});
