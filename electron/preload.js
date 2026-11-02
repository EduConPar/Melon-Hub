/* Puente entre la web y la app de escritorio. De momento solo deja que la
   web sepa que corre dentro de la app (window.melonDesktop); aquí irán la
   bandeja, las teclas multimedia, Discord, etc. */
const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('melonDesktop', {
    platform: process.platform,
});
