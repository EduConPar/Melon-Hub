/* ──────────────────────────────────────────────────────────────────────
   CHAT MÓVIL — JS
   Reusa endpoints de /assets/profile/api.php:
     get-recent-chats   — un solo endpoint con todo: amigos mutuos +
                         último mensaje + unread + online + mute +
                         nickname
     get-messages       — historial de un chat (incluye read/delivered
                         por mensaje cuando es mío)
     send-message       — postear
     set-chat-mute      — silenciar 1h/8h/24h/1w/forever/off
     set-chat-nickname  — asignar apodo (vacío = limpiar)
   ────────────────────────────────────────────────────────────────────── */
(function() {
'use strict';

const CFG = window.__CH_CFG || {};
const API = CFG.API || '/assets/profile/api.php';
const USER_KEY = CFG.USER_KEY || '';
const USERS = CFG.USERS || {};

const POLL_MESSAGES_MS  = 2500;
const POLL_RECENTS_MS   = 8000;
const POLL_HEARTBEAT_MS = 30000;
const LONG_PRESS_MS     = 500;

/* Set de emojis usable en el composer. Pixel-friendly + comunes. */
const EMOJI_SET = [
    '😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰',
    '😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🥸','🤩','🥳',
    '😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤',
    '😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫',
    '🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵',
    '🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','👍','👎','👌','✌️','🤞','🤟',
    '🤘','🤙','👋','🤚','✋','🖖','👏','🙌','👐','🤲','🙏','💪','❤️','🧡','💛','💚',
    '💙','💜','🖤','🤍','💔','💕','💖','💗','💘','💝','✨','⭐','🌟','💫','🔥','💯',
    '🎉','🎊','🎁','🎂','🍰','🍕','🍔','🍟','🍿','☕','🍻','🍺','🥂','🍷'
];

const STATE = {
    chats: [],           /* array de {userKey, lastText, lastAt, lastFromMe,
                            unread, online, mutedUntil, nickname} */
    view: 'list',        /* 'list' | 'chat' */
    chatWith: null,
    chatLastSig: '',     /* signature de los msgs renderizados → evita re-paint */
    chatPollTimer: null,
    recentPollTimer: null,
    /* Acceso rápido al meta del chat actual. */
    currentMeta: null,
};

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* Render del body de un mensaje:
   - Detecta URLs (http(s)://… y www.…) y las convierte en hipervínculos
     que abren en nueva pestaña.
   - Si la URL apunta a una imagen (jpg/png/gif/webp), la pinta inline
     como <img>. Discord/Tenor URLs típicas terminan en .gif y caen aquí.
   - El texto SIN URLs se escapa con esc() para evitar XSS.
   - Una URL "sola" sin texto alrededor también puede ser una imagen
     embebida (la regex es greedy hasta whitespace o tag).
*/
const _URL_RE = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+)/gi;
/* Detecta imágenes por DOS rutas:
   1. Extensión en el path antes de ?/# (jpg, png, gif, webp, svg, bmp, avif).
   2. Host conocido que sirve imágenes aunque la URL no tenga extensión
      (Google image thumbs, Discord CDN, imgur, reddit, twitter media,
      googleusercontent, tenor/giphy CDNs…). Sin esto, URLs tipo
      `https://encrypted-tbn0.gstatic.com/images?q=tbn:…` no se
      reconocían como imagen y salían como link. */
const _IMG_EXT_RE = /\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)(?:[?#].*)?$/i;
const _IMG_HOST_RE = /^https?:\/\/(?:encrypted-tbn\d*\.gstatic\.com\/images|lh\d+\.googleusercontent\.com|[^/]*\.googleusercontent\.com|i\.imgur\.com|media\d*\.tenor\.com|c\.tenor\.com|media\d*\.giphy\.com|i\.giphy\.com|cdn\.discordapp\.com\/attachments|media\.discordapp\.net\/attachments|i\.redd\.it|preview\.redd\.it|pbs\.twimg\.com\/media)/i;
/* Google Drive: las URLs típicas que copia un usuario apuntan a una
   PÁGINA HTML (`/file/d/<ID>/view`) que no se puede usar como <img src>.
   Extraemos el ID del fichero de cualquiera de los formatos que
   comparte Drive (file/d/, uc?id=, open?id=, thumbnail?id=) y lo
   convertimos al endpoint `thumbnail?id=…&sz=w1920` que SÍ sirve la
   imagen directamente. Devolvemos null si no es una URL de Drive. */
function _driveImageUrl(url) {
    if (!/^https?:\/\/(?:drive|docs)\.google\.com\//i.test(url)) return null;
    let id = '';
    let m = url.match(/\/file\/d\/([A-Za-z0-9_-]{15,})/);
    if (m) id = m[1];
    if (!id) { m = url.match(/[?&]id=([A-Za-z0-9_-]{15,})/); if (m) id = m[1]; }
    if (!id) return null;
    return 'https://drive.google.com/thumbnail?id=' + id + '&sz=w1920';
}
function _isImageUrl(url) {
    return _IMG_EXT_RE.test(url) || _IMG_HOST_RE.test(url);
}
function _renderUrlToken(url) {
    /* Normaliza www.… a http://www.… para que href sea válido. */
    const isWww   = /^www\./i.test(url);
    const fullUrl = isWww ? ('http://' + url) : url;
    /* Detecta imagen por extensión, host conocido o link de Drive
       (Drive necesita además REESCRIBIR la URL al endpoint thumbnail
       porque la URL que comparte el usuario apunta a una página HTML
       no embebible). Click abre lightbox; sin <a> alrededor para no
       navegar al link en vez de previsualizar. */
    const driveSrc = _driveImageUrl(url);
    if (driveSrc) {
        return '<img class="ch-msg-img" src="' + esc(driveSrc) + '" data-fullsrc="' + esc(driveSrc) + '" alt="" loading="lazy" referrerpolicy="no-referrer">';
    }
    if (_isImageUrl(url)) {
        return '<img class="ch-msg-img" src="' + esc(fullUrl) + '" data-fullsrc="' + esc(fullUrl) + '" alt="" loading="lazy" referrerpolicy="no-referrer">';
    }
    return '<a class="ch-msg-link" href="' + esc(fullUrl) + '" target="_blank" rel="noopener noreferrer">' +
           esc(url) + '</a>';
}
function renderMessageBody(text) {
    const s = String(text == null ? '' : text);
    if (!s) return '';
    let out = '';
    let lastIdx = 0;
    let m;
    _URL_RE.lastIndex = 0;
    while ((m = _URL_RE.exec(s)) !== null) {
        out += esc(s.slice(lastIdx, m.index));
        out += _renderUrlToken(m[0]);
        lastIdx = m.index + m[0].length;
    }
    out += esc(s.slice(lastIdx));
    return out;
}

/* ── Win98 Alert / Confirm modales ──
   Reemplazos de alert()/confirm() nativos. Usan el mismo bezel Win98
   que el resto de modales del chat. chAlert resuelve cuando el usuario
   cierra el modal. chConfirm devuelve Promise<boolean>. */
function chDialog(opts) {
    return new Promise(function(resolve) {
        var bd = document.createElement('div');
        bd.className = 'ch-lp-backdrop is-open';
        bd.style.zIndex = '3100';
        var msgHtml = String(opts.message || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;')
            .replace(/\n/g, '<br>');
        var btns = (opts.showCancel !== false)
            ? '<div class="ch-lp-row" style="margin-top:10px;">' +
                  '<button class="button" data-act="cancel" type="button">' + (opts.cancelLabel || 'Cancelar') + '</button>' +
                  '<button class="button" data-act="ok" type="button">' + (opts.okLabel || 'Aceptar') + '</button>' +
              '</div>'
            : '<div class="ch-lp-row" style="margin-top:10px;">' +
                  '<button class="button" data-act="ok" type="button" style="flex:1;">' + (opts.okLabel || 'Aceptar') + '</button>' +
              '</div>';
        bd.innerHTML =
            '<div class="ch-lp-sheet">' +
                '<div class="ch-lp-title">' +
                    '<span>' + String(opts.title || 'Aviso').replace(/</g,'&lt;') + '</span>' +
                    '<button class="ch-lp-close" data-act="close" type="button">✕</button>' +
                '</div>' +
                '<div class="ch-lp-body" style="font-size:13px;line-height:1.4;">' +
                    msgHtml +
                    btns +
                '</div>' +
            '</div>';
        document.body.appendChild(bd);
        function done(val) {
            if (bd.parentNode) bd.parentNode.removeChild(bd);
            resolve(val);
        }
        bd.querySelector('[data-act="close"]').addEventListener('click', function() { done(false); });
        bd.querySelector('[data-act="ok"]').addEventListener('click', function() { done(true); });
        var cancelBtn = bd.querySelector('[data-act="cancel"]');
        if (cancelBtn) cancelBtn.addEventListener('click', function() { done(false); });
        bd.addEventListener('click', function(e) { if (e.target === bd) done(false); });
    });
}
function chAlert(message, title) {
    return chDialog({ message: message, title: title || 'Aviso', showCancel: false, okLabel: 'Aceptar' });
}
function chConfirm(message, title, okLabel) {
    return chDialog({ message: message, title: title || 'Confirmar', okLabel: okLabel || 'Aceptar' });
}

/* ── API helpers ── */
function apiGet(action) {
    return fetch(API + '?action=' + action, { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : { error: 'http' })
        .catch(() => ({ error: 'net' }));
}
function apiPost(action, body) {
    return fetch(API + '?action=' + action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    })
        .then(r => r.ok ? r.json() : { error: 'http' })
        .catch(() => ({ error: 'net' }));
}

/* Devuelve el meta del chat con uKey desde STATE.chats; null si no existe. */
function getMeta(uKey) {
    return STATE.chats.find(c => c.userKey === uKey) || null;
}
/* Nombre a mostrar: nickname si existe, label real si no. */
function displayName(uKey, meta) {
    const m = meta || getMeta(uKey);
    if (m && m.nickname) return m.nickname;
    const u = USERS[uKey];
    return (u && u.label) || uKey;
}

/* ── Carga lista (get-recent-chats) ── */
/* Baseline para detectar mensajes nuevos. -1 = primer load (no suena). */
let _prevRecentsTotalUnread = -1;
async function loadRecents() {
    const d = await apiGet('get-recent-chats');
    if (d && Array.isArray(d.chats)) {
        STATE.chats = d.chats;
        /* Sumar unread excluyendo el chat abierto — los mensajes que llegan
           a la conversación activa no deben hacer "ding", el usuario los
           está viendo. */
        let total = 0;
        for (const c of d.chats) {
            if (STATE.view === 'chat' && c.userKey === STATE.chatWith) continue;
            total += (c.unread | 0);
        }
        /* Respeta la preferencia mute_messages del usuario. La guarda
           el dialog de prefs del perfil en localStorage; el chat
           móvil la lee sin hacer fetch extra (mismo origen). */
        var muteMsgs = false;
        try {
            var prefs = JSON.parse(localStorage.getItem('melonNotifPrefs') || 'null');
            if (prefs && prefs.mute_messages) muteMsgs = true;
        } catch (_) {}
        if (_prevRecentsTotalUnread >= 0 && total > _prevRecentsTotalUnread
            && !muteMsgs && typeof window.playNotifSound === 'function') {
            window.playNotifSound();
        }
        _prevRecentsTotalUnread = total;
    }
    if (STATE.view === 'list') renderList();
    /* Si estoy en chat, refrescar la cabecera (online/nickname). */
    if (STATE.view === 'chat' && STATE.chatWith) refreshChatHeader();
}

/* ── Render lista ── */
function renderList() {
    const el = document.getElementById('ch-list-content');
    if (!el) return;
    if (!STATE.chats.length) {
        el.innerHTML = '<div class="ch-list-empty">Aún no tienes amigos para chatear.<br>Sigue a otros usuarios desde tu perfil — cuando os sigáis mutuamente aparecerán aquí.</div>';
        return;
    }
    el.innerHTML = STATE.chats.map(c => {
        const u = USERS[c.userKey] || { label: c.userKey, image: '' };
        const name = c.nickname || u.label;
        const av = u.image
            ? '<img src="' + esc(u.image) + '" alt="">'
            : '<img class="ch-friend-av-fallback" src="../../assets/img/appIcons/profileIcon.png" alt="">';
        const presenceCls = c.dnd  ? ' is-dnd'
                          : c.away ? ' is-away'
                          : c.online ? ' is-online' : '';
        const presence = '<span class="ch-presence-dot' + presenceCls + '"></span>';
        /* Sub-text: último mensaje o "Tocar para chatear" si no hay. */
        let sub;
        if (c.lastText) {
            const prefix = c.lastFromMe ? 'Tú: ' : '';
            sub = prefix + c.lastText;
        } else {
            sub = 'Tocar para chatear';
        }
        const muteIcon = (c.mutedUntil > 0)
            ? '<img class="ch-friend-mute-icon" src="../../assets/img/appIcons/bellIcon.png" alt="" style="filter:grayscale(1);opacity:0.5;">'
            : '';
        const time = c.lastAt ? formatRelTime(c.lastAt) : '';
        const badge = c.unread > 0
            ? '<div class="ch-friend-badge">' + c.unread + '</div>'
            : '';
        return '<div class="ch-friend" data-user="' + esc(c.userKey) + '">' +
            '<div class="ch-friend-av" data-marco-user="' + esc(c.userKey) + '">' + av + presence + '</div>' +
            '<div class="ch-friend-info">' +
                '<div class="ch-friend-name">' + esc(name) + muteIcon + '</div>' +
                '<div class="ch-friend-sub' + (c.unread > 0 ? ' has-unread' : '') + '">' + esc(sub) + '</div>' +
            '</div>' +
            '<div class="ch-friend-right">' +
                (time ? '<div class="ch-friend-time">' + esc(time) + '</div>' : '') +
                badge +
            '</div>' +
        '</div>';
    }).join('');
    /* Tap + long-press handlers. */
    el.querySelectorAll('[data-user]').forEach(card => attachCardHandlers(card));
}

function formatRelTime(ts) {
    const d = new Date(ts * 1000);
    if (isNaN(d.getTime())) return '';
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    if (sameDay) {
        return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
    }
    const diff = (now - d) / 86400000;
    if (diff < 7) {
        const dows = ['dom','lun','mar','mié','jue','vie','sáb'];
        return dows[d.getDay()];
    }
    return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0');
}

/* ── Long-press detection sobre las cards ── */
function attachCardHandlers(card) {
    let pressTimer = null;
    let longPressed = false;
    const uKey = card.dataset.user;

    function start() {
        longPressed = false;
        pressTimer = setTimeout(() => {
            longPressed = true;
            openLongPress(uKey);
        }, LONG_PRESS_MS);
    }
    function cancel() {
        if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
    }
    card.addEventListener('touchstart', start, { passive: true });
    card.addEventListener('touchend',   () => { cancel(); });
    card.addEventListener('touchmove',  () => { cancel(); });
    card.addEventListener('touchcancel', cancel);
    /* Mouse fallback para desktop. */
    card.addEventListener('mousedown',  start);
    card.addEventListener('mouseup',    cancel);
    card.addEventListener('mouseleave', cancel);
    /* Click solo se ejecuta si NO fue long-press. */
    card.addEventListener('click', (e) => {
        if (longPressed) { e.preventDefault(); e.stopPropagation(); return; }
        openChat(uKey);
    });
    /* Bloquea el menú contextual del browser en long-press. */
    card.addEventListener('contextmenu', e => e.preventDefault());
}

/* ── Vista chat individual ── */
function openChat(uKey) {
    const meta = getMeta(uKey);
    if (!meta) return;
    STATE.chatWith    = uKey;
    STATE.chatLastSig = '';
    STATE.view        = 'chat';
    STATE.currentMeta = meta;

    refreshChatHeader();

    document.getElementById('ch-list').classList.add('is-hidden');
    document.getElementById('ch-chat').classList.add('is-active');
    closeEmojiPanel();

    /* Limpia notif push del SO. Helper vive en parent (mobile.php). */
    try {
        const fn = (window.parent && window.parent !== window)
            ? window.parent.mhClearNotifications
            : window.mhClearNotifications;
        if (typeof fn === 'function') fn({ tag: 'chat:' + uKey });
    } catch (_) {}

    /* Poll de mensajes. */
    pollMessages(true);
    if (STATE.chatPollTimer) clearInterval(STATE.chatPollTimer);
    STATE.chatPollTimer = setInterval(() => pollMessages(false), POLL_MESSAGES_MS);

    setTimeout(() => {
        const inp = document.getElementById('ch-input');
        if (inp) inp.focus();
    }, 100);
}

function refreshChatHeader() {
    const uKey = STATE.chatWith;
    if (!uKey) return;
    const meta = getMeta(uKey) || STATE.currentMeta;
    const u = USERS[uKey] || { label: uKey, image: '' };
    const name = (meta && meta.nickname) || u.label;
    const avEl = document.getElementById('ch-chat-av');
    const nmEl = document.getElementById('ch-chat-name');
    const stEl = document.getElementById('ch-chat-status');
    const av = u.image
        ? '<img src="' + esc(u.image) + '" alt="">'
        : '<img class="ch-friend-av-fallback" src="../../assets/img/appIcons/profileIcon.png" alt="" style="width:60%;height:60%;margin:20%;">';
    const presenceCls = (meta && meta.dnd)  ? ' is-dnd'
                      : (meta && meta.away) ? ' is-away'
                      : (meta && meta.online) ? ' is-online' : '';
    const presence = '<span class="ch-presence-dot' + presenceCls + '"></span>';
    avEl.innerHTML = av + presence;
    avEl.setAttribute('data-marco-user', uKey);   /* marco de perfil (melon-marcos.js) */
    nmEl.textContent = name;
    /* Status — hereda los flags del meta cacheado (los mismos que usa
       Social). Prioridad:
         1. No molestar  (DND activo)
         2. Ausente      (online pero state='away')
         3. En línea     (heartbeat reciente)
         4. Última vez X (offline conocido)
         5. Sin status   (offline desconocido) */
    if (stEl) {
        if (meta && meta.dnd) {
            stEl.textContent = 'No molestar';
            stEl.classList.remove('is-online');
        } else if (meta && meta.away) {
            stEl.textContent = 'Ausente';
            stEl.classList.remove('is-online');
        } else if (meta && meta.online) {
            stEl.textContent = 'En línea';
            stEl.classList.add('is-online');
        } else if (meta && meta.lastSeenAt > 0) {
            stEl.textContent = 'Última vez ' + formatLastSeen(meta.lastSeenAt);
            stEl.classList.remove('is-online');
        } else {
            stEl.textContent = '';
            stEl.classList.remove('is-online');
        }
    }
}

/* "Última vez" — formato compacto. Hoy → "hoy HH:MM", ayer → "ayer HH:MM",
   esta semana → "lun HH:MM", más viejo → "DD/MM HH:MM". */
function formatLastSeen(ts) {
    const d = new Date(ts * 1000);
    if (isNaN(d.getTime())) return '';
    const now = new Date();
    const hh = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
    const sameDay = d.toDateString() === now.toDateString();
    if (sameDay) return 'hoy ' + hh;
    const y = new Date(now.getTime() - 86400000);
    if (d.toDateString() === y.toDateString()) return 'ayer ' + hh;
    const diffDays = (now - d) / 86400000;
    if (diffDays < 7) {
        const dows = ['dom','lun','mar','mié','jue','vie','sáb'];
        return dows[d.getDay()] + ' ' + hh;
    }
    return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + ' ' + hh;
}

function closeChat() {
    STATE.chatWith    = null;
    STATE.chatLastSig = '';
    STATE.view        = 'list';
    STATE.currentMeta = null;
    if (STATE.chatPollTimer) { clearInterval(STATE.chatPollTimer); STATE.chatPollTimer = null; }
    closeEmojiPanel();
    document.getElementById('ch-chat').classList.remove('is-active');
    document.getElementById('ch-list').classList.remove('is-hidden');
    loadRecents();
}

async function pollMessages(initial) {
    if (!STATE.chatWith) return;
    const target = STATE.chatWith;
    const d = await apiGet('get-messages&with=' + encodeURIComponent(target));
    if (STATE.chatWith !== target) return;
    if (!d || !Array.isArray(d.messages)) return;
    /* Signature incluye max id + read/delivered de los míos → re-render
       si cambia cualquier estado de ticks (no solo si llega msg nuevo). */
    const sig = d.messages.map(m => {
        const flags = (m.edited ? 'E' : '') + (m.deleted ? 'X' : '');
        if (m.from === USER_KEY) {
            return m.id + ':' + (m.read ? 'R' : (m.delivered ? 'D' : 'S')) + flags;
        }
        return m.id + flags;
    }).join(',');
    if (!initial && sig === STATE.chatLastSig) return;
    STATE.chatLastSig = sig;
    renderFeed(d.messages, !!initial);
}

/* HTML helper para el placeholder de mensajes eliminados — replazado el
   emoji 🗑 por trashIcon.png para coherencia visual con el tema. */
const TRASH_ICON_HTML = '<img src="../../assets/img/appIcons/trashIcon.png" alt="" style="width:14px;height:14px;object-fit:contain;image-rendering:pixelated;vertical-align:-2px;margin-right:4px;">';

/* ── Renderizar el feed de mensajes ──
   initial=true cuando se acaba de abrir el chat: las imágenes aún no
   han cargado, así que activamos un GRACE de 3s donde cualquier
   image-load fuerza scroll al fondo sin condiciones. Tras el grace
   o en polls subsiguientes, NO registramos pin handlers: si llega un
   msg con imagen mientras estás al fondo, el scroll inicial te deja
   ahí y la imagen al cargar empuja solo un poco — eso evita que un
   GIF que carga tarde te lance hacia abajo cuando ya scrolleaste
   arriba a leer. */
/* Construye las partes mutables de un mensaje + una "firma" (sig) de los
   campos que pueden cambiar entre polls (ticks/editado/eliminado/texto).
   La sig permite saltarse nodos que NO han cambiado → no se tocan sus
   <img>/GIFs y no hay reflow. */
function chMsgParts(m) {
    const isMine = (m.from === USER_KEY);
    const isDel  = !!m.deleted;
    let cls = 'ch-msg ' + (isMine ? 'is-mine' : 'is-them');
    if (isDel) cls += ' is-deleted';
    const time = formatTime(m.sentAt);
    const body = isDel
        ? '<span class="ch-msg-text">' + TRASH_ICON_HTML + 'Mensaje eliminado</span>'
        : '<span class="ch-msg-text">' + renderMessageBody(m.text) + '</span>';
    const editedTag = (m.edited && !isDel) ? '<span class="ch-msg-edited">(editado)</span>' : '';
    let ticks = '';
    if (isMine && !isDel) {
        if (m.read)            ticks = '<span class="ch-ticks is-read" title="Leído">✓✓</span>';
        else if (m.delivered)  ticks = '<span class="ch-ticks is-delivered" title="Entregado">✓✓</span>';
        else                   ticks = '<span class="ch-ticks is-sent" title="Enviado">✓</span>';
    }
    const html = body + '<span class="ch-msg-meta">' + ticks + editedTag + '<span>' + time + '</span></span>';
    const sig = [isDel ? 1 : 0, m.edited ? 1 : 0, m.read ? 1 : 0, m.delivered ? 1 : 0,
                 isDel ? '' : (m.text || '')].join('|');
    return { isMine, isDel, cls, html, sig };
}

/* Render INCREMENTAL. Antes se reconstruía todo el feed con innerHTML en
   cada poll: eso recreaba los <img> (GIFs/imágenes recargaban desde cero
   → la altura colapsaba y reflowaba) y, al reemplazar innerHTML, se perdía
   la posición de scroll → al llegar un mensaje nuevo el chat saltaba
   arriba. Ahora reconciliamos: conservamos los nodos existentes (no se
   recargan imágenes), añadimos solo los mensajes nuevos al final y
   actualizamos in situ únicamente los que cambian de estado. */
function renderFeed(messages, initial) {
    const feed = document.getElementById('ch-feed');
    if (!messages.length) {
        feed.innerHTML = '<div class="ch-feed-empty">No hay mensajes todavía. Escribe el primero ↓</div>';
        return;
    }
    const wasAtBottom = (feed.scrollHeight - feed.scrollTop - feed.clientHeight) < 60;

    /* Quita el placeholder de "sin mensajes" si estaba puesto. */
    const emptyEl = feed.querySelector('.ch-feed-empty');
    if (emptyEl) emptyEl.remove();

    /* Mapa de nodos existentes por id de mensaje. */
    const existing = {};
    feed.querySelectorAll('.ch-msg').forEach(function(el){
        existing[el.getAttribute('data-msg-id')] = el;
    });

    const seen = {};
    let prevNode = null;
    messages.forEach(function(m){
        const id = String(m.id);
        seen[id] = true;
        const p = chMsgParts(m);
        let el = existing[id];
        if (!el) {
            /* Mensaje nuevo → crear nodo e insertarlo en orden. */
            el = document.createElement('div');
            el.className = p.cls;
            el.setAttribute('data-msg-id', id);
            el.setAttribute('data-mine', p.isMine ? '1' : '0');
            el.setAttribute('data-deleted', p.isDel ? '1' : '0');
            el.setAttribute('data-sig', p.sig);
            el.innerHTML = p.html;
            if (prevNode && prevNode.nextSibling) feed.insertBefore(el, prevNode.nextSibling);
            else if (prevNode)                    feed.appendChild(el);
            else if (feed.firstChild)             feed.insertBefore(el, feed.firstChild);
            else                                  feed.appendChild(el);
            if (p.isMine && !p.isDel) attachMsgLongPress(el);
        } else if (el.getAttribute('data-sig') !== p.sig) {
            /* Cambió un campo mutable (ticks/editado/eliminado/texto) →
               actualizamos SOLO este nodo. El resto (con imágenes) queda
               intacto. El listener de long-press persiste en el nodo. */
            el.className = p.cls;
            el.setAttribute('data-deleted', p.isDel ? '1' : '0');
            el.setAttribute('data-sig', p.sig);
            el.innerHTML = p.html;
        }
        prevNode = el;
    });

    /* Elimina nodos de mensajes que ya no existen (poco habitual). */
    Object.keys(existing).forEach(function(id){
        if (!seen[id]) existing[id].remove();
    });

    if (wasAtBottom) {
        feed.scrollTop = feed.scrollHeight;
        /* Solo en el render INICIAL registramos pin handlers para mantener
           el scroll al fondo mientras las imágenes/GIFs cargan. Grace de 3s
           para no pegar tirones si el user empieza a scrollear. En polls
           posteriores NO pineamos: una imagen que carga tarde no debe
           lanzar al user al fondo cuando ya scrolleó arriba a leer. */
        if (initial) {
            const startTime = Date.now();
            const GRACE_MS  = 3000;
            feed.querySelectorAll('img').forEach(function(img){
                if (img.complete) return;
                var pin = function(){
                    if (Date.now() - startTime > GRACE_MS) return;
                    feed.scrollTop = feed.scrollHeight;
                };
                img.addEventListener('load',  pin, { once: true });
                img.addEventListener('error', pin, { once: true });
            });
        }
    }
}

/* ── Long-press en mensaje → menu edit/delete ── */
function attachMsgLongPress(el) {
    let pressTimer = null;
    let longPressed = false;
    function start() {
        longPressed = false;
        pressTimer = setTimeout(() => {
            longPressed = true;
            openMsgMenu(el);
        }, 500);
    }
    function cancel() { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } }
    el.addEventListener('touchstart',  start, { passive: true });
    el.addEventListener('touchend',    cancel);
    el.addEventListener('touchmove',   cancel);
    el.addEventListener('touchcancel', cancel);
    el.addEventListener('mousedown',   start);
    el.addEventListener('mouseup',     cancel);
    el.addEventListener('mouseleave',  cancel);
    el.addEventListener('contextmenu', e => {
        e.preventDefault();
        openMsgMenu(el);
    });
}

function openMsgMenu(msgEl) {
    /* Un mensaje que pasó a eliminado conserva su listener de long-press
       (actualizamos el nodo in situ, no lo recreamos). No abrir el menú. */
    if (msgEl.dataset.deleted === '1') return;
    const msgId = +msgEl.dataset.msgId;
    const text  = (msgEl.querySelector('.ch-msg-text')?.textContent) || '';
    const bd = document.getElementById('ch-msg-backdrop');
    bd.dataset.msgId = msgId;
    bd.dataset.text  = text;
    bd.classList.add('is-open');
}
function closeMsgMenu() { document.getElementById('ch-msg-backdrop').classList.remove('is-open'); }

/* Wire ONCE — defer al final del módulo. */

function formatTime(sentAt) {
    if (!sentAt) return '';
    const d = new Date(sentAt * 1000);
    if (isNaN(d.getTime())) return '';
    return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}

async function sendMessage() {
    const inp = document.getElementById('ch-input');
    const text = (inp.value || '').trim();
    if (!text || !STATE.chatWith) return;
    inp.value = '';
    inp.style.height = '';
    const target = STATE.chatWith;
    await apiPost('send-message', { to: target, text });
    if (STATE.chatWith === target) pollMessages(true);
}

/* ─────────────────────────────────────────────────────────
   LONG-PRESS MODAL: nickname + mute
   ───────────────────────────────────────────────────────── */
function openLongPress(uKey) {
    const meta = getMeta(uKey);
    if (!meta) return;
    const u = USERS[uKey] || { label: uKey };
    document.getElementById('ch-lp-title-text').textContent = (meta.nickname || u.label);
    document.getElementById('ch-lp-nick-input').value = meta.nickname || '';
    /* Marca la opción de mute activa visualmente. */
    refreshMuteStatus(uKey);
    const backdrop = document.getElementById('ch-lp-backdrop');
    backdrop.dataset.user = uKey;
    backdrop.classList.add('is-open');
}
function closeLongPress() {
    document.getElementById('ch-lp-backdrop').classList.remove('is-open');
}
function refreshMuteStatus(uKey) {
    const meta = getMeta(uKey);
    const muted = meta && meta.mutedUntil > 0;
    const status = document.getElementById('ch-lp-mute-status');
    if (!muted) {
        status.textContent = 'Notificaciones activadas.';
    } else {
        const until = new Date(meta.mutedUntil * 1000);
        const now = new Date();
        const diffDays = (until - now) / 86400000;
        if (diffDays > 365) {
            status.textContent = 'Silenciado indefinidamente.';
        } else {
            const fmt = until.toLocaleDateString('es-ES', { day:'2-digit', month:'short' }) +
                        ' ' + String(until.getHours()).padStart(2,'0') + ':' +
                        String(until.getMinutes()).padStart(2,'0');
            status.textContent = 'Silenciado hasta ' + fmt + '.';
        }
    }
    /* Resaltar opción activa. */
    const opts = document.querySelectorAll('.ch-lp-opt[data-mute]');
    opts.forEach(o => o.classList.remove('is-selected'));
    if (!muted) {
        const off = document.querySelector('.ch-lp-opt[data-mute="off"]');
        if (off) off.classList.add('is-selected');
    }
}

/* Handlers del modal. */
document.getElementById('ch-lp-close').addEventListener('click', closeLongPress);
document.getElementById('ch-lp-backdrop').addEventListener('click', function(e) {
    if (e.target === this) closeLongPress();
});

/* Mute options. */
document.querySelectorAll('.ch-lp-opt[data-mute]').forEach(btn => {
    btn.addEventListener('click', async () => {
        const uKey = document.getElementById('ch-lp-backdrop').dataset.user;
        if (!uKey) return;
        const duration = btn.dataset.mute;
        btn.disabled = true;
        const r = await apiPost('set-chat-mute', { with: uKey, duration });
        btn.disabled = false;
        if (r && r.ok) {
            const meta = getMeta(uKey);
            if (meta) meta.mutedUntil = r.mutedUntil || 0;
            refreshMuteStatus(uKey);
            renderList();
        }
    });
});

/* Nickname save. */
document.getElementById('ch-lp-nick-save').addEventListener('click', async () => {
    const uKey = document.getElementById('ch-lp-backdrop').dataset.user;
    if (!uKey) return;
    const nick = document.getElementById('ch-lp-nick-input').value.trim();
    const r = await apiPost('set-chat-nickname', { with: uKey, nickname: nick });
    if (r && r.ok) {
        const meta = getMeta(uKey);
        if (meta) meta.nickname = r.nickname || '';
        document.getElementById('ch-lp-title-text').textContent = (r.nickname || (USERS[uKey] && USERS[uKey].label) || uKey);
        renderList();
        if (STATE.view === 'chat' && STATE.chatWith === uKey) refreshChatHeader();
        closeLongPress();
    }
});
document.getElementById('ch-lp-nick-clear').addEventListener('click', async () => {
    const uKey = document.getElementById('ch-lp-backdrop').dataset.user;
    if (!uKey) return;
    document.getElementById('ch-lp-nick-input').value = '';
    const r = await apiPost('set-chat-nickname', { with: uKey, nickname: '' });
    if (r && r.ok) {
        const meta = getMeta(uKey);
        if (meta) meta.nickname = '';
        document.getElementById('ch-lp-title-text').textContent = (USERS[uKey] && USERS[uKey].label) || uKey;
        renderList();
        if (STATE.view === 'chat' && STATE.chatWith === uKey) refreshChatHeader();
    }
});

/* ─────────────────────────────────────────────────────────
   PICKER PANEL — Emojis + GIFs (Tenor)
   ───────────────────────────────────────────────────────── */
const emojiPanel = document.getElementById('ch-emoji-panel');
const emojiPane  = document.getElementById('ch-pane-emoji');
const gifsPane   = document.getElementById('ch-pane-gifs');
const pickerTabs = document.getElementById('ch-picker-tabs');

/* Rellena el pane de emojis con la lista pre-definida. */
emojiPane.innerHTML = EMOJI_SET.map(e =>
    '<button class="ch-emoji" type="button" tabindex="-1">' + e + '</button>'
).join('');
emojiPane.addEventListener('click', (e) => {
    const btn = e.target.closest('.ch-emoji');
    if (!btn) return;
    const inp = document.getElementById('ch-input');
    const txt = btn.textContent;
    const start = inp.selectionStart || inp.value.length;
    const end = inp.selectionEnd || inp.value.length;
    inp.value = inp.value.slice(0, start) + txt + inp.value.slice(end);
    inp.focus();
    inp.selectionStart = inp.selectionEnd = start + txt.length;
});

/* Tabs Emoji / GIF: cambia el pane visible. */
pickerTabs.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-tab]');
    if (!btn) return;
    const tab = btn.dataset.tab;
    pickerTabs.querySelectorAll('button').forEach(b => b.classList.toggle('is-active', b === btn));
    emojiPane.hidden = (tab !== 'emoji');
    gifsPane.hidden  = (tab !== 'gifs');
    /* Al abrir GIFs por primera vez, mostramos trending sin necesidad
       de escribir nada. */
    if (tab === 'gifs' && !gifsPane.dataset.loaded) {
        gifsPane.dataset.loaded = '1';
        searchGifs('');
    }
});

/* GIPHY: búsqueda + render del grid. Click en un GIF → envía un mensaje
   con SU URL (el receptor la renderiza inline como imagen, gracias a
   renderMessageBody). */
const gifResults = document.getElementById('ch-gif-results');
const gifSearch  = document.getElementById('ch-gif-search');
let _gifDebounce = null;
let _gifSeq = 0;
async function searchGifs(q) {
    const seq = ++_gifSeq;
    gifResults.innerHTML = '<div class="ch-gif-status">Buscando…</div>';
    try {
        const r = await fetch('../../assets/profile/api.php?action=tenor-search&q=' + encodeURIComponent(q) + '&limit=24');
        const d = await r.json();
        /* Si el usuario tipeó algo nuevo mientras esto cargaba, lo
           descartamos para no pisar resultados más recientes. */
        if (seq !== _gifSeq) return;
        if (d && d.code === 'tenorNotConfigured') {
            gifResults.innerHTML = '<div class="ch-gif-status">Búsqueda de GIFs no configurada. Añade GIPHY_API_KEY en .env.</div>';
            return;
        }
        if (!d || !d.gifs || !d.gifs.length) {
            gifResults.innerHTML = '<div class="ch-gif-status">Sin resultados.</div>';
            return;
        }
        gifResults.innerHTML = d.gifs.map(g =>
            '<img src="' + esc(g.preview) + '" data-url="' + esc(g.url) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
        ).join('');
    } catch (e) {
        if (seq !== _gifSeq) return;
        gifResults.innerHTML = '<div class="ch-gif-status">Error de red.</div>';
    }
}
gifSearch.addEventListener('input', () => {
    clearTimeout(_gifDebounce);
    _gifDebounce = setTimeout(() => searchGifs(gifSearch.value.trim()), 280);
});
gifResults.addEventListener('click', async (e) => {
    const img = e.target.closest('img[data-url]');
    if (!img) return;
    const url = img.dataset.url;
    closeEmojiPanel();
    /* Inyecta la URL en el input + dispara submit del form para que
       siga el mismo flujo que un mensaje normal (incluyendo optimistic
       UI, ticks, etc.). */
    const inp = document.getElementById('ch-input');
    inp.value = url;
    const form = inp.closest('form');
    if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
});

function toggleEmojiPanel() { emojiPanel.classList.toggle('is-open'); }
function closeEmojiPanel()  { emojiPanel.classList.remove('is-open'); }
document.getElementById('ch-emoji-btn').addEventListener('click', toggleEmojiPanel);

/* ── Modal mensaje (edit/delete) wiring ── */
document.getElementById('ch-msg-close').addEventListener('click', closeMsgMenu);
document.getElementById('ch-msg-backdrop').addEventListener('click', function(e) {
    if (e.target === this) closeMsgMenu();
});
document.getElementById('ch-msg-edit').addEventListener('click', () => {
    const bd = document.getElementById('ch-msg-backdrop');
    const msgId = +bd.dataset.msgId;
    const text  = bd.dataset.text || '';
    closeMsgMenu();
    openEditModal(msgId, text);
});
document.getElementById('ch-msg-delete').addEventListener('click', async () => {
    const bd = document.getElementById('ch-msg-backdrop');
    const msgId = +bd.dataset.msgId;
    closeMsgMenu();
    const ok = await chConfirm(
        '¿Eliminar este mensaje?\n\nQuien lo haya visto verá "Mensaje eliminado".',
        'Eliminar mensaje',
        'Eliminar'
    );
    if (!ok) return;
    const r = await apiPost('delete-message', { messageId: msgId });
    if (r && r.ok) {
        STATE.chatLastSig = '';   /* fuerza re-render */
        pollMessages(true);
    }
});

/* ── Modal de edición ── */
function openEditModal(msgId, currentText) {
    const bd  = document.getElementById('ch-edit-backdrop');
    const inp = document.getElementById('ch-edit-input');
    bd.dataset.msgId = msgId;
    bd.dataset.original = currentText;
    inp.value = currentText;
    bd.classList.add('is-open');
    setTimeout(() => { inp.focus(); inp.select(); }, 50);
}
function closeEditModal() { document.getElementById('ch-edit-backdrop').classList.remove('is-open'); }
document.getElementById('ch-edit-close').addEventListener('click', closeEditModal);
document.getElementById('ch-edit-cancel').addEventListener('click', closeEditModal);
document.getElementById('ch-edit-backdrop').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
document.getElementById('ch-edit-save').addEventListener('click', async () => {
    const bd  = document.getElementById('ch-edit-backdrop');
    const msgId = +bd.dataset.msgId;
    const orig  = bd.dataset.original || '';
    const newText = document.getElementById('ch-edit-input').value.trim();
    if (!newText || newText === orig) { closeEditModal(); return; }
    const r = await apiPost('edit-message', { messageId: msgId, text: newText });
    closeEditModal();
    if (r && r.ok) {
        STATE.chatLastSig = '';
        pollMessages(true);
    }
});

/* ── Event listeners ── */
document.getElementById('ch-back-list').addEventListener('click', closeChat);
/* preventDefault en pointerdown del botón Enviar evita que el botón
   robe el foco al textarea — el SO móvil mantiene el teclado abierto.
   Click normal sigue funcionando (pointerdown no cancela el click,
   solo el cambio de foco). Mismo truco aplica al botón de emojis. */
document.getElementById('ch-send').addEventListener('pointerdown', (e) => e.preventDefault());
document.getElementById('ch-send').addEventListener('click', sendMessage);
document.getElementById('ch-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});
document.getElementById('ch-input').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(90, this.scrollHeight) + 'px';
});

/* Botones que vuelven al menú: status-bar, X title-bar, botón nuevo arriba. */
function chGoMenu(e) {
    if (e) e.preventDefault();
    if (window.parent && window.parent !== window) {
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); return; } catch (_) {}
    }
    try { history.back(); } catch (_) { location.href = '../../mobile.php'; }
}
document.getElementById('ch-menu-link').addEventListener('click', chGoMenu);
document.getElementById('ch-titlebar-close').addEventListener('click', chGoMenu);

/* ── Listener para que el SW suprima la notif si estamos en este chat ── */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
        const d = e.data || {};
        if (d.type !== 'sw:is-chat-focused') return;
        const port = e.ports && e.ports[0];
        if (!port) return;
        const focused = (STATE.chatWith === d.fromKey)
                     && (document.visibilityState === 'visible')
                     && (STATE.view === 'chat');
        try { port.postMessage({ focused }); } catch (_) {}
    });
}

/* ── Polling de recents mientras estamos en la lista. ── */
STATE.recentPollTimer = setInterval(() => {
    if (STATE.view === 'list' && document.visibilityState === 'visible') {
        loadRecents();
    }
}, POLL_RECENTS_MS);

/* ── Heartbeat de presencia ──
   Sin esto la app de Chat no actualiza user_presence.last_at → los otros
   usuarios ven al user como "offline" / "Última vez …" aunque esté
   leyendo activamente. Ping cada 30s solo si la pestaña está visible. */
function chHeartbeat() {
    if (document.visibilityState !== 'visible') return;
    apiGet('heartbeat');
}
chHeartbeat();
setInterval(chHeartbeat, POLL_HEARTBEAT_MS);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') chHeartbeat();
});

/* ── Deep-link #chat=USERKEY (push) ── */
function handleHash() {
    const m = /#chat=([a-z0-9_-]+)/i.exec(location.hash || '');
    if (!m) return;
    const uKey = m[1];
    try { history.replaceState(null, '', location.pathname); } catch (_) {}
    /* Solo abrir si está en la lista de mutuos (ya cargada). Si aún no
       cargó, marcamos para procesarlo después. */
    if (getMeta(uKey)) {
        openChat(uKey);
    } else {
        STATE._pendingOpenKey = uKey;
    }
}
window.addEventListener('hashchange', handleHash);

/* Init. */
(async function init() {
    await loadRecents();
    handleHash();
    if (STATE._pendingOpenKey && getMeta(STATE._pendingOpenKey)) {
        openChat(STATE._pendingOpenKey);
        STATE._pendingOpenKey = null;
    }
})();

/* ─────────────────────────────────────────────────────────
   LIGHTBOX — preview de imagen/GIF en grande
   Click en cualquier `.ch-msg-img` (imagen embebida o GIF de GIPHY
   dentro de un mensaje) abre un overlay full-screen con la imagen
   al máximo. Click fuera, en la X, o tecla Escape → cierra.
   ───────────────────────────────────────────────────────── */
(function setupLightbox(){
    function ensureLightbox() {
        let lb = document.getElementById('ch-lightbox');
        if (lb) return lb;
        lb = document.createElement('div');
        lb.id = 'ch-lightbox';
        lb.className = 'ch-lightbox';
        lb.innerHTML = '<img alt=""><button class="ch-lightbox-close" type="button" aria-label="Cerrar">×</button>';
        lb.addEventListener('click', function(e){
            /* Solo cerramos si el click fue en el backdrop o en la X,
               no si fue en la imagen (queremos que se pueda hacer
               long-press para guardar, etc). */
            if (e.target === lb || e.target.classList.contains('ch-lightbox-close')) {
                closeLightbox();
            }
        });
        document.body.appendChild(lb);
        return lb;
    }
    function openLightbox(src) {
        const lb = ensureLightbox();
        lb.querySelector('img').src = src;
        lb.classList.add('is-open');
    }
    function closeLightbox() {
        const lb = document.getElementById('ch-lightbox');
        if (!lb) return;
        lb.classList.remove('is-open');
        /* Libera el src para que la próxima apertura no flashe la
           imagen anterior. */
        lb.querySelector('img').src = '';
    }
    /* Listener delegado en TODO el feed del chat (no en cada msg). */
    document.addEventListener('click', function(e){
        const img = e.target.closest && e.target.closest('.ch-msg-img');
        if (!img) return;
        const src = img.dataset.fullsrc || img.src;
        if (!src) return;
        e.preventDefault();
        e.stopPropagation();
        openLightbox(src);
    });
    /* Escape para cerrar (solo aplica con teclado físico). */
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeLightbox();
    });
})();

})();
