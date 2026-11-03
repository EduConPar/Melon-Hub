/* ──────────────────────────────────────────────────────────────────────
   PRESENCIA EN DISCORD (Rich Presence)
   ──────────────────────────────────────────────────────────────────────
   Mientras suena algo en el reproductor, el perfil de Discord enseña
   "Escuchando a <aplicación>" con la canción, el artista, la carátula, la
   barra de tiempo y los botones "Escuchar conmigo" y "Abrir Melon Hub". En pausa o sin canción no
   enseña nada. Se puede apagar desde el menú de la bandeja (tray.js,
   setting `discordPresence`).

   Habla directamente con el cliente de Discord que haya abierto (Discord,
   Vesktop/arRPC…) por su socket local discord-ipc-N: mensajes de cabecera
   [opcode, longitud] en little-endian + JSON. El Application ID es el de la
   aplicación de Discord de la web (DISCORD_CLIENT_ID del .env): llega con
   lo que suena (apps/reproductor.php → melon:now-playing).

   Dentro de Flatpak el socket solo se ve con los permisos del manifiesto
   (xdg-run/discord-ipc-0 y xdg-run/app/com.discordapp.Discord).
   ────────────────────────────────────────────────────────────────────── */
const net = require('net');
const path = require('path');
const settings = require('./settings');

const OP_HANDSHAKE = 0, OP_FRAME = 1, OP_CLOSE = 2, OP_PING = 3, OP_PONG = 4;
const RETRY_MS = 15000;
/* Discord limita SET_ACTIVITY (unas 5 cada 20 s): se manda solo cuando
   cambia algo que se ve, y como mucho una vez por segundo. */
const MIN_GAP_MS = 1000;
const SITE_URL = 'https://melonhub.es/';
/* La web que abre la app (la de dev en la build de dev): el botón
   "Escuchar conmigo" tiene que llevar a la misma, que es donde están la
   cuenta y la sesión de escuchar juntos (tray.js → setSite). */
let appSite = SITE_URL;

let clientId = '';
let sock = null, ready = false, connecting = false, retryTimer = null;
let wanted = null;      /* actividad que debería verse (null = ninguna) */
let sentKey = null;     /* lo último mandado, para no repetir */
let lastSend = 0, sendTimer = null, nonce = 0;
/* Al cambiar de canción el reproductor enseña primero la miniatura de
   YouTube y, en cuanto la encuentra, la carátula del álbum: se espera un poco
   antes de mandar la de YouTube para no publicar dos portadas seguidas. */
const COVER_WAIT_MS = 2500;
let trackVideo = null, trackSince = 0;

function candidates() {
    if (process.platform === 'win32') {
        return Array.from({ length: 10 }, (_, i) => '\\\\?\\pipe\\discord-ipc-' + i);
    }
    const env = process.env;
    const bases = [env.XDG_RUNTIME_DIR, env.TMPDIR, env.TMP, env.TEMP, '/tmp'].filter(Boolean);
    /* Discord normal, Discord en Flatpak, Vesktop en Flatpak y Snap. */
    const subdirs = ['', 'app/com.discordapp.Discord', '.flatpak/dev.vencord.Vesktop/xdg-run', 'snap.discord'];
    const out = [];
    for (const b of bases) for (const d of subdirs) for (let i = 0; i < 10; i++) out.push(path.join(b, d, 'discord-ipc-' + i));
    return [...new Set(out)];
}

function encode(op, data) {
    const json = Buffer.from(JSON.stringify(data));
    const head = Buffer.alloc(8);
    head.writeInt32LE(op, 0);
    head.writeInt32LE(json.length, 4);
    return Buffer.concat([head, json]);
}

function reset() {
    if (sock) { sock.removeAllListeners(); sock.destroy(); }
    sock = null; ready = false; connecting = false; sentKey = null;
}

function scheduleRetry() {
    if (retryTimer || !wanted) return;
    retryTimer = setTimeout(() => { retryTimer = null; connect(); }, RETRY_MS);
}

/* Prueba los sockets en orden hasta que uno conteste. */
function connect() {
    if (sock || connecting || !clientId) return;
    connecting = true;
    const list = candidates();
    const tryNext = (i) => {
        if (i >= list.length) { connecting = false; scheduleRetry(); return; }
        const s = net.createConnection(list[i]);
        s.once('error', () => { s.destroy(); tryNext(i + 1); });
        s.once('connect', () => {
            s.removeAllListeners('error');
            sock = s;
            connecting = false;
            listen(s);
            s.write(encode(OP_HANDSHAKE, { v: 1, client_id: clientId }));
        });
    };
    tryNext(0);
}

function listen(s) {
    let buf = Buffer.alloc(0);
    s.on('data', (chunk) => {
        buf = Buffer.concat([buf, chunk]);
        while (buf.length >= 8) {
            const op = buf.readInt32LE(0), len = buf.readInt32LE(4);
            if (buf.length < 8 + len) break;
            let msg = null;
            try { msg = JSON.parse(buf.subarray(8, 8 + len).toString()); } catch (_) {}
            buf = buf.subarray(8 + len);
            if (op === OP_PING) s.write(encode(OP_PONG, msg || {}));
            else if (op === OP_CLOSE) { reset(); scheduleRetry(); return; }
            else if (msg && msg.evt === 'READY') { ready = true; flush(); }
            else if (msg && msg.evt === 'ERROR') console.warn('[discord]', msg.data && msg.data.message);
        }
    });
    s.on('error', () => {});
    s.on('close', () => { reset(); scheduleRetry(); });
}

function flush() {
    clearTimeout(sendTimer);
    sendTimer = null;
    if (!ready) { if (wanted) connect(); return; }
    const key = JSON.stringify(wanted);
    if (key === sentKey) return;
    let wait = lastSend + MIN_GAP_MS - Date.now();
    if (isYoutubeCover(wanted)) wait = Math.max(wait, trackSince + COVER_WAIT_MS - Date.now());
    if (wait > 0) { sendTimer = setTimeout(flush, wait); return; }
    lastSend = Date.now();
    sentKey = key;
    sock.write(encode(OP_FRAME, {
        cmd: 'SET_ACTIVITY',
        args: { pid: process.pid, activity: wanted || undefined },
        nonce: String(++nonce),
    }));
}

/* La portada es la misma que enseña el reproductor: la del álbum cuando la
   ha encontrado y, si no, la miniatura de YouTube. Esa se pide en mqdefault
   (16:9 sin bordes): Discord recorta la imagen a cuadrado por el centro y la
   hqdefault trae franjas negras arriba y abajo. */
function coverOf(s) {
    /* coverUrl: la original; cover puede ser el data: pixelado del reproductor. */
    const c = String(s.coverUrl || s.cover || '');
    const yt = c.match(/^https:\/\/(?:img\.youtube\.com|i\.ytimg\.com)\/vi\/([\w-]{11})\//);
    if (/^https:\/\//.test(c) && !yt) return c;
    const vid = yt ? yt[1] : s.videoId;
    return vid ? 'https://i.ytimg.com/vi/' + vid + '/mqdefault.jpg' : null;
}
function isYoutubeCover(a) { return !!(a && a.assets && /^https:\/\/i\.ytimg\.com\//.test(a.assets.large_image)); }

/* Discord pide entre 2 y 128 caracteres en los textos. */
function text(s, fallback) {
    s = String(s || '').trim() || fallback;
    if (s.length < 2) s += ' ';
    return s.length > 128 ? s.slice(0, 127) + '…' : s;
}

/* Lo que suena (mismo objeto que recibe el reproductor flotante). La barra
   de tiempo solo se recalcula si la posición se aleja más de 3 s de la
   esperada (un salto): así no se manda nada en cada tic de 500 ms. */
function update(s) {
    if (s && s.discordClientId && s.discordClientId !== clientId) {
        clientId = s.discordClientId;
        reset();
    }
    let next = null;
    if (s && s.videoId !== trackVideo) { trackVideo = s.videoId; trackSince = Date.now(); }
    const cover = s ? coverOf(s) : null;
    if (settings.get('discordPresence') && s && s.hasTrack && s.playing && s.title) {
        const now = Date.now();
        let start = now - Math.max(0, s.position || 0) * 1000;
        const prev = wanted && wanted._video === s.videoId ? wanted : null;
        if (prev && Math.abs(prev.timestamps.start - start) < 3000) start = prev.timestamps.start;
        next = {
            type: 2,   /* "Escuchando a" */
            details: text(s.title, 'Sin título'),
            state: text(s.artist, 'Melon Hub'),
            timestamps: s.duration > 0
                ? { start: Math.round(start), end: Math.round(start + s.duration * 1000) }
                : { start: Math.round(start) },
            assets: cover ? { large_image: cover, large_text: text(s.playlist || s.title, 'Melon Hub') } : undefined,
            /* "Escuchar conmigo": quien lo pulsa se une a lo que suena,
               amigo o no (index.php?escuchar → enlace.php → reproductor.php).
               No sale escuchando como invitado de otro. */
            buttons: (s.userKey && s.ltRole !== 'guest'
                ? [{ label: 'Escuchar conmigo', url: appSite + 'index.php?escuchar=' + encodeURIComponent(s.userKey) }]
                : []).concat([{ label: 'Abrir Melon Hub', url: SITE_URL }]),
        };
        Object.defineProperty(next, '_video', { value: s.videoId, enumerable: false });
    }
    wanted = next;
    if (!clientId) return;
    if (!sock && wanted) connect();
    /* Si se estaba esperando a la carátula del álbum y ya ha llegado, sale ya. */
    else if (!sendTimer || !isYoutubeCover(wanted)) flush();
}

function clear() { update(null); }

function setSite(url) {
    try { appSite = new URL('.', url).href; } catch (_) {}
}

module.exports = { update, clear, setSite };
