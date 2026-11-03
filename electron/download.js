/* ──────────────────────────────────────────────────────────────────────
   DESCARGAR LA CANCIÓN QUE SUENA (MP3 / M4A / WAV)
   ──────────────────────────────────────────────────────────────────────
   La web lo pide con melonDesktop.download() (clic derecho → Descargar…
   del reproductor y del reproductor flotante) y el archivo acaba en la
   carpeta Descargas como "Artista - Título.ext".

   Solo se puede desde la app: el audio de YouTube no se deja leer desde
   otra web (CORS) y YouTube bloquea las descargas desde servidores. Aquí
   va con la conexión del usuario:
     1. yt-dlp baja el audio original (M4A/AAC). No va dentro de la app:
        se descarga de su release de GitHub la primera vez (~35 MB, en
        userData/bin) y se actualiza solo una vez al día, que YouTube
        cambia a menudo. Para resolver los enlaces de YouTube usa como
        intérprete de JavaScript el Node de la propia app
        (ELECTRON_RUN_AS_NODE).
     2. M4A: se guarda tal cual, sin recomprimir. MP3 y WAV: se convierte
        en una ventana oculta (convert.html) con el decodificador de
        Chromium y lamejs, sin ffmpeg.
     3. Título, artista, álbum y carátula van dentro del archivo (ID3 en
        el MP3, átomos iTunes en el M4A). El WAV va sin datos.
   ────────────────────────────────────────────────────────────────────── */
const { app, BrowserWindow, ipcMain, net } = require('electron');
const { spawn } = require('child_process');
const fs = require('fs');
const fsp = fs.promises;
const os = require('os');
const path = require('path');

const BIN_DIR = path.join(app.getPath('userData'), 'bin');
const YTDLP = path.join(BIN_DIR, process.platform === 'win32' ? 'yt-dlp.exe' : 'yt-dlp');
const YTDLP_ASSET = { win32: 'yt-dlp.exe', darwin: 'yt-dlp_macos' }[process.platform] || 'yt-dlp_linux';
const YTDLP_URL = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/' + YTDLP_ASSET;
const UPDATE_EVERY_MS = 24 * 60 * 60 * 1000;
const FORMATS = { mp3: 'MP3', m4a: 'M4A', wav: 'WAV' };
const BITRATES = [128, 192, 320];

const running = new Map();   /* id → { child, cancelled } */

/* ── yt-dlp ── */
async function exists(p) { try { await fsp.access(p); return true; } catch (_) { return false; } }

async function fetchToFile(url, file, onPercent) {
    const res = await net.fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const total = Number(res.headers.get('content-length')) || 0;
    const out = fs.createWriteStream(file);
    const reader = res.body.getReader();
    let got = 0;
    for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        got += value.length;
        if (!out.write(Buffer.from(value))) await new Promise((r) => out.once('drain', r));
        if (total && onPercent) onPercent(Math.round((got / total) * 100));
    }
    await new Promise((r, j) => out.end((e) => (e ? j(e) : r())));
}

/* Lanza un programa; onSpawn recibe el proceso (para poder cancelarlo). */
function run(bin, args, { onLine, onSpawn, env, timeoutMs } = {}) {
    return new Promise((resolve) => {
        const child = spawn(bin, args, { env: env || process.env, windowsHide: true });
        if (onSpawn) onSpawn(child);
        let out = '', err = '', timer = null;
        const lines = (chunk, store) => {
            const s = chunk.toString();
            if (store === 'out') out += s; else err += s;
            if (onLine) s.split(/\r?\n|\r/).forEach((l) => l && onLine(l));
        };
        child.stdout.on('data', (c) => lines(c, 'out'));
        child.stderr.on('data', (c) => lines(c, 'err'));
        if (timeoutMs) timer = setTimeout(() => child.kill(), timeoutMs);
        child.on('error', (e) => { clearTimeout(timer); resolve({ code: -1, out, err: err + e.message }); });
        child.on('close', (code) => { clearTimeout(timer); resolve({ code, out, err }); });
    });
}

/* Descarga yt-dlp si falta y lo actualiza (como mucho una vez al día). */
async function ensureYtdlp(progress) {
    await fsp.mkdir(BIN_DIR, { recursive: true });
    const stamp = YTDLP + '.checked';
    if (!(await exists(YTDLP))) {
        progress('setup', 0);
        const tmp = YTDLP + '.part';
        await fetchToFile(YTDLP_URL, tmp, (p) => progress('setup', p));
        await fsp.chmod(tmp, 0o755);
        await fsp.rename(tmp, YTDLP);
        await fsp.writeFile(stamp, String(Date.now()));
        return;
    }
    let last = 0;
    try { last = Number(await fsp.readFile(stamp, 'utf8')) || 0; } catch (_) {}
    if (Date.now() - last > UPDATE_EVERY_MS) {
        progress('update', 0);
        await run(YTDLP, ['-U'], { timeoutMs: 90000 });   /* si falla, se sigue con la que hay */
        await fsp.writeFile(stamp, String(Date.now()));
    }
}

/* Baja el audio original de YouTube (M4A si lo hay). Devuelve la ruta. */
async function fetchAudio(job, dir, progress) {
    const args = [
        '-f', 'bestaudio[ext=m4a]/bestaudio',
        '--no-playlist', '--no-part', '--no-mtime', '--newline',
        '--js-runtimes', 'node:' + process.execPath,
        '--progress-template', 'download:%(progress._percent_str)s',
        '-o', path.join(dir, 'audio.%(ext)s'),
        'https://www.youtube.com/watch?v=' + job.videoId,
    ];
    /* El ejecutable de la app hace de Node para yt-dlp. */
    const env = { ...process.env, ELECTRON_RUN_AS_NODE: '1' };
    const r = await run(YTDLP, args, {
        env,
        onSpawn: (child) => { job.child = child; },
        onLine: (l) => {
            const m = l.match(/^\s*([\d.]+)%/);
            if (m) progress('download', Math.round(parseFloat(m[1])));
        },
    });
    if (job.cancelled) throw new Error('cancelled');
    const file = (await fsp.readdir(dir)).find((f) => f.startsWith('audio.'));
    if (r.code !== 0 || !file) {
        const why = (r.err.match(/ERROR: (.*)/) || [])[1] || r.err.trim().split('\n').pop() || 'yt-dlp ha fallado';
        throw new Error(why.replace(/^\[[^\]]+\]\s*[\w-]+:\s*/, ''));
    }
    return path.join(dir, file);
}

/* ── Conversión (ventana oculta con el decodificador de Chromium) ── */
async function convert(input, output, format, kbps) {
    const win = new BrowserWindow({
        show: false,
        webPreferences: { nodeIntegration: true, contextIsolation: false, sandbox: false, backgroundThrottling: false },
    });
    try {
        await win.loadFile(path.join(__dirname, 'convert.html'));
        await win.webContents.executeJavaScript(`convertAudio(${JSON.stringify({ input, output, format, kbps })})`);
    } finally {
        win.destroy();
    }
}

/* ── Etiquetas ── */
function imageType(buf) {
    if (buf[0] === 0xff && buf[1] === 0xd8) return 'jpeg';
    if (buf[0] === 0x89 && buf[1] === 0x50) return 'png';
    return null;   /* webp y otros: los reproductores no los entienden como carátula */
}

async function fetchCover(url) {
    if (!/^https:\/\//.test(url || '')) return null;
    try {
        const res = await net.fetch(url);
        if (!res.ok) return null;
        const buf = Buffer.from(await res.arrayBuffer());
        const type = imageType(buf);
        return type ? { buf, type } : null;
    } catch (_) { return null; }
}

/* ID3v2.3 delante del MP3: TIT2, TPE1, TALB y APIC (texto en UTF-16). */
function id3(tags, cover) {
    const frames = [];
    const text = (id, value) => {
        if (!value) return;
        const body = Buffer.concat([Buffer.from([1, 0xff, 0xfe]), Buffer.from(value, 'utf16le')]);
        frames.push(frame(id, body));
    };
    const frame = (id, body) => {
        const h = Buffer.alloc(10);
        h.write(id, 0, 'latin1');
        h.writeUInt32BE(body.length, 4);
        return Buffer.concat([h, body]);
    };
    text('TIT2', tags.title);
    text('TPE1', tags.artist);
    text('TALB', tags.album);
    if (cover) {
        const mime = 'image/' + cover.type;
        frames.push(frame('APIC', Buffer.concat([Buffer.from([0]), Buffer.from(mime + '\0', 'latin1'), Buffer.from([3, 0]), cover.buf])));
    }
    const body = Buffer.concat(frames);
    const head = Buffer.from([0x49, 0x44, 0x33, 3, 0, 0, 0, 0, 0, 0]);
    /* Tamaño "synchsafe": 7 bits por byte. */
    for (let i = 0, n = body.length; i < 4; i++) head[9 - i] = (n >> (7 * i)) & 0x7f;
    return Buffer.concat([head, body]);
}

/* M4A: añade moov/udta/meta/ilst con ©nam, ©ART, ©alb y covr. Si el moov va
   delante de los datos, los offsets de stco/co64 se corren lo que crece. */
function box(type, ...parts) {
    const body = Buffer.concat(parts);
    const h = Buffer.alloc(8);
    h.writeUInt32BE(body.length + 8, 0);
    h.write(type, 4, 'latin1');
    return Buffer.concat([h, body]);
}
function children(buf, start, end) {
    const out = [];
    for (let p = start; p + 8 <= end;) {
        let size = buf.readUInt32BE(p);
        const type = buf.toString('latin1', p + 4, p + 8);
        let head = 8;
        if (size === 1) { size = Number(buf.readBigUInt64BE(p + 8)); head = 16; }
        else if (size === 0) size = end - p;
        if (size < head || p + size > end) break;
        out.push({ type, start: p, end: p + size, head });
        p += size;
    }
    return out;
}
function shiftChunkOffsets(moov, delta) {
    const walk = (start, end) => {
        for (const b of children(moov, start, end)) {
            if (['trak', 'mdia', 'minf', 'stbl'].includes(b.type)) walk(b.start + b.head, b.end);
            else if (b.type === 'stco' || b.type === 'co64') {
                const n = moov.readUInt32BE(b.start + b.head + 4);
                for (let i = 0; i < n; i++) {
                    const at = b.start + b.head + 8 + i * (b.type === 'stco' ? 4 : 8);
                    if (b.type === 'stco') moov.writeUInt32BE(moov.readUInt32BE(at) + delta, at);
                    else moov.writeBigUInt64BE(moov.readBigUInt64BE(at) + BigInt(delta), at);
                }
            }
        }
    };
    walk(8, moov.length);
}
function tagM4a(file, tags, cover) {
    const buf = fs.readFileSync(file);
    const top = children(buf, 0, buf.length);
    const moov = top.find((b) => b.type === 'moov');
    if (!moov) return;
    const item = (name, type, payload) => box(name, box('data', Buffer.from([0, 0, 0, type, 0, 0, 0, 0]), payload));
    const items = [];
    if (tags.title) items.push(item('\xa9nam', 1, Buffer.from(tags.title, 'utf8')));
    if (tags.artist) items.push(item('\xa9ART', 1, Buffer.from(tags.artist, 'utf8')));
    if (tags.album) items.push(item('\xa9alb', 1, Buffer.from(tags.album, 'utf8')));
    if (cover) items.push(item('covr', cover.type === 'png' ? 14 : 13, cover.buf));
    const hdlr = box('hdlr', Buffer.alloc(8), Buffer.from('mdirappl', 'latin1'), Buffer.alloc(9));
    const udta = box('udta', box('meta', Buffer.alloc(4), hdlr, box('ilst', ...items)));
    /* El moov nuevo: sus hijos sin el udta que pudiera tener, más el nuestro. */
    const kids = children(buf, moov.start + moov.head, moov.end).filter((b) => b.type !== 'udta');
    const newMoov = box('moov', ...kids.map((b) => buf.subarray(b.start, b.end)), udta);
    const delta = newMoov.length - (moov.end - moov.start);
    const dataAfter = top.some((b) => b.type === 'mdat' && b.start > moov.start);
    if (dataAfter && delta) shiftChunkOffsets(newMoov, delta);
    fs.writeFileSync(file, Buffer.concat([buf.subarray(0, moov.start), newMoov, buf.subarray(moov.end)]));
}

/* ── Nombre del archivo ── */
function safeName(s) {
    return String(s || '').replace(/[\\/:*?"<>|\x00-\x1f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
}
async function freePath(dir, base, ext) {
    for (let i = 1; ; i++) {
        const p = path.join(dir, (i === 1 ? base : `${base} (${i})`) + '.' + ext);
        if (!(await exists(p))) return p;
    }
}

/* ── La descarga entera ── */
async function download(info, progress) {
    const format = FORMATS[info.format] ? info.format : 'mp3';
    const kbps = BITRATES.includes(Number(info.kbps)) ? Number(info.kbps) : 192;
    if (!/^[\w-]{11}$/.test(info.videoId || '')) throw new Error('Canción no válida');
    const job = { videoId: info.videoId, cancelled: false, child: null };
    running.set(info.id, job);
    const dir = await fsp.mkdtemp(path.join(os.tmpdir(), 'melonhub-dl-'));
    try {
        await ensureYtdlp(progress);
        if (job.cancelled) throw new Error('cancelled');
        progress('download', 0);
        const audio = await fetchAudio(job, dir, progress);
        if (job.cancelled) throw new Error('cancelled');
        const base = safeName([info.artist, info.title].filter(Boolean).join(' - ')) || 'Canción';
        const out = await freePath(app.getPath('downloads'), base, format);
        const tags = { title: info.title || '', artist: info.artist || '', album: info.album || '' };
        progress('convert', null);
        const cover = format === 'wav' ? null : await fetchCover(info.cover);
        if (format === 'm4a') {
            if (!/\.m4a$/.test(audio)) throw new Error('Esta canción no tiene audio M4A en YouTube: prueba con MP3.');
            tagM4a(audio, tags, cover);
            await fsp.copyFile(audio, out);
        } else {
            const tmpOut = path.join(dir, 'out.' + format);
            await convert(audio, tmpOut, format, kbps);
            if (format === 'mp3') {
                await fsp.writeFile(out, Buffer.concat([id3(tags, cover), await fsp.readFile(tmpOut)]));
            } else {
                await fsp.copyFile(tmpOut, out);
            }
        }
        return { ok: true, file: out, name: path.basename(out) };
    } finally {
        running.delete(info.id);
        fsp.rm(dir, { recursive: true, force: true }).catch(() => {});
    }
}

function init() {
    ipcMain.handle('melon:download', async (e, info) => {
        const send = (stage, percent) => {
            if (!e.sender.isDestroyed()) e.sender.send('melon:download-progress', { id: info.id, stage, percent });
        };
        try {
            return await download(info || {}, send);
        } catch (err) {
            const msg = String((err && err.message) || err);
            if (msg === 'cancelled') return { ok: false, cancelled: true };
            console.warn('[download]', msg);
            return { ok: false, error: msg };
        }
    });
    ipcMain.on('melon:download-cancel', (_e, id) => {
        const job = running.get(id);
        if (!job) return;
        job.cancelled = true;
        if (job.child) job.child.kill();
    });
}

module.exports = { init, download, convert, tagM4a, id3 };
