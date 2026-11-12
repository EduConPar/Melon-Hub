/* Ajustes de la app que se guardan entre sesiones (settings.json en la
   carpeta de datos de la app). */
const { app } = require('electron');
const fs = require('fs');
const path = require('path');

const FILE = path.join(app.getPath('userData'), 'settings.json');
const DEFAULTS = {
    floatingPlayer: true,   /* reproductor flotante al cerrar con música */
    miniPosition: null,     /* { x, y } del reproductor flotante */
    discordPresence: true,  /* presencia en Discord con lo que suena (discord.js) */
};

let data = null;

function load() {
    if (!data) {
        try { data = { ...DEFAULTS, ...JSON.parse(fs.readFileSync(FILE, 'utf8')) }; }
        catch (_) { data = { ...DEFAULTS }; }
    }
    return data;
}

function get(key) {
    return load()[key];
}

function set(key, value) {
    load()[key] = value;
    try { fs.writeFileSync(FILE, JSON.stringify(data, null, 2)); } catch (_) { /* no es grave */ }
}

module.exports = { get, set };
