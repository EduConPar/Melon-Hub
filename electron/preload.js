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
});
