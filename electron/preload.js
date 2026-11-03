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

    /* Descargar la canción que suena (download.js). info: { id, videoId,
       title, artist, album, cover, format: mp3 | m4a | wav, kbps }. Devuelve
       { ok, name, file } | { ok: false, error } | { ok: false, cancelled }.
       onDownloadProgress recibe { id, stage: setup | update | download |
       convert, percent }. */
    download(info) {
        return ipcRenderer.invoke('melon:download', info);
    },
    cancelDownload(id) {
        ipcRenderer.send('melon:download-cancel', id);
    },
    onDownloadProgress(cb) {
        ipcRenderer.on('melon:download-progress', (_e, p) => cb(p));
    },

    /* Extras de escritorio (desktop-extras.js). notify: notificación del
       sistema { title, body } (solo sale si no se está mirando la app).
       setBadge: mensajes del chat sin leer en el icono de la bandeja.
       prepareDrag / startDrag: arrastrar una imagen fuera de la app como
       archivo (preparar al pulsarla, empezar al arrastrarla). */
    notify(n) {
        ipcRenderer.send('melon:notify', n);
    },
    setBadge(n) {
        ipcRenderer.send('melon:badge', n);
    },
    prepareDrag(url) {
        ipcRenderer.send('melon:drag-prepare', url);
    },
    startDrag(url) {
        ipcRenderer.send('melon:drag-start', url);
    },

    /* Enlaces melonhub://… y los de la web pulsados dentro de la app
       (links.js): cb recibe "<tipo>:<valor>" (melonOpenLink en
       desktop-base.php). Registrarlo avisa de que la página ya escucha. */
    onOpenLink(cb) {
        ipcRenderer.on('melon:open-link', (_e, link) => cb(link));
        ipcRenderer.send('melon:open-link-ready');
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
