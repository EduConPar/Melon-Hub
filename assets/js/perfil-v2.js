/* ──────────────────────────────────────────────────────────────────────
   PERFIL NUEVO (estilo Steam, fase 1) — apps/perfil.php
   ──────────────────────────────────────────────────────────────────────
   Pinta lo que devuelve `v2` en get-profile / view-user
   (assets/profile/perfil2.php):
     - expositores (Juegos favoritos, Capturas —de la Galería, en Google
       Drive—, Reseña destacada, Música favorita) en el orden que elige el
       usuario;
     - comentarios del muro (sustituyen a los posts);
     - columna lateral: nivel, medallas, amigos y actividad;
     - estado (en línea / ausente / no molestar / desconectado).
   Personalizar (el botón de la cabecera, el modo edición de perfil.php):
   arrastrar para ordenar, ✕ para quitar, "Editar" para elegir el
   contenido y "＋ Añadir expositor" en los huecos libres (2 de serie,
   hasta 5 comprando en la tienda). Se guarda con el resto del perfil.
   Sin emojis de interfaz: iconos del pack del tema (icon-pack.js
   sustituye las rutas assets/img/appIcons/…); las medallas sí llevan
   emoji (decidido así).
   Lo que necesita de perfil.php llega por window.__pf2Bridge.
   ────────────────────────────────────────────────────────────────────── */
(function () {
    var API = 'assets/profile/api.php';
    var TYPES = {
        games:  { label: 'Juegos favoritos', icon: 'juegosIcon' },
        shots:  { label: 'Capturas',         icon: 'galeriaIcon' },
        review: { label: 'Reseña destacada', icon: 'bestOfTheYearIcon' },
        music:  { label: 'Música favorita',  icon: 'musicaIcon' },
        steam_rare: { label: 'Logros raros de Steam', icon: 'steamIcon' },
        steam_ach:  { label: 'Logros de Steam',       icon: 'steamIcon' },
    };
    var MAX = { games: 4, shots: 3, music: 5, steam_ach: 8 };
    var CAT_LABEL = { movies: 'Película', series: 'Serie', books: 'Libro', games: 'Juego', music: 'Música' };
    var TIERS = [
        null,
        ['#b06a2c', '#e6a46a', 'Bronce'],
        ['#8c8c8c', '#e8e8e8', 'Plata'],
        ['#b8860b', '#ffe066', 'Oro'],
    ];

    var S = {
        v2: null, ctx: null, editing: false,
        draft: [],            /* expositores en edición: { type, config, data } */
        catalog: {},          /* título de juego (minúsculas) → imagen */
        myShots: null,
    };

    function bridge() { return window.__pf2Bridge || {}; }
    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function ic(name, size, mr) {
        size = size || 14;
        return '<img class="pf2-ic" src="assets/img/appIcons/' + name + '.png" alt="" style="width:' + size + 'px;height:' + size +
            'px;vertical-align:-2px;margin-right:' + (mr == null ? 4 : mr) + 'px;">';
    }
    function stars(n) {
        n = Math.max(0, Math.min(5, Number(n) || 0));
        var full = Math.floor(n), half = n - full >= 0.5;
        return '★'.repeat(full) + (half ? '½' : '');
    }
    function copy(o) { return JSON.parse(JSON.stringify(o)); }
    function api(action, body) {
        return fetch(API + '?action=' + action, body === undefined ? { credentials: 'same-origin' } : {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }
    function isYtThumb(u) { return /ytimg\.com|youtube\.com|img\.youtube/i.test(u || ''); }

    /* ════════════════════ Pintar ════════════════════ */
    function render(v2, ctx) {
        if (!v2) return;
        S.v2 = v2;
        S.ctx = ctx || {};
        if (!S.editing) S.draft = copy(v2.showcases || []);
        renderShowcases();
        renderComments();
        renderSide();
        refreshStatus();
    }

    function panel(type, sub, idx) {
        var t = TYPES[type];
        var p = document.createElement('div');
        p.className = 'pf2-panel pf2-show';
        p.dataset.idx = idx;
        p.innerHTML =
            '<div class="profile-sidebar-heading pf2-panel-head">' +
                '<span class="pf2-edit-ctl pf2-handle" title="Arrastra para moverlo">⠿</span>' +
                ic(t.icon) + esc(t.label) +
                '<span class="pf2-sub">' + (sub || '') + '</span>' +
                (type === 'steam_rare' ? '' : '<button type="button" class="button pf2-edit-ctl pf2-hbtn" data-act="edit">Editar</button>') +
                '<button type="button" class="button pf2-edit-ctl pf2-hbtn" data-act="del" title="Quitar expositor">✕</button>' +
            '</div><div class="pf2-panel-body"></div>';
        return p;
    }

    function renderShowcases() {
        var box = $('pf2-showcases');
        if (!box || !S.v2) return;
        var view = $('profile-view-default');
        if (view) view.classList.toggle('pf2-editing', S.editing);
        box.innerHTML = '';
        var own = !!S.ctx.own;
        S.draft.forEach(function (sc, i) {
            var empty = !sc.data || (Array.isArray(sc.data) ? !sc.data.length : (sc.type.indexOf('steam_') === 0 ? !(sc.data.items || []).length : !sc.data.title));
            if (empty && !own) return;            /* a los demás no se les enseña un expositor vacío */
            var sub = '';
            if (sc.type === 'games' && !own) sub = '';
            if (sc.type === 'shots' && S.v2.stats) sub = (sc.data || []).length + ' de ' + (S.v2.stats.counts.shots || 0);
            var p = panel(sc.type, sub, i);
            var body = p.querySelector('.pf2-panel-body');
            if (empty) body.innerHTML = '<div class="pf2-empty">' + emptyText(sc.type) + '</div>';
            else RENDER[sc.type](body, sc.data);
            var eb = p.querySelector('[data-act="edit"]');
            if (eb) eb.addEventListener('click', function () { openPicker(i); });
            p.querySelector('[data-act="del"]').addEventListener('click', function () {
                S.draft.splice(i, 1);
                renderShowcases();
            });
            if (S.editing) {
                p.draggable = true;
                p.addEventListener('dragstart', function (e) { p.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(i)); } catch (_) {} });
                p.addEventListener('dragend', function () { p.classList.remove('dragging'); syncOrder(); });
            }
            box.appendChild(p);
        });
        if (S.editing) {
            var used = S.draft.length, slots = S.v2.slots || 2, max = S.v2.maxSlots || 5;
            for (var k = used; k < slots; k++) {
                var add = document.createElement('div');
                add.className = 'pf2-slot add';
                add.textContent = '＋ Añadir expositor';
                add.addEventListener('click', function (e) { openTypeMenu(e); });
                box.appendChild(add);
            }
            var info = document.createElement('div');
            info.className = 'pf2-slot';
            info.innerHTML = 'Usas <b>' + used + ' de ' + slots + '</b> huecos' +
                (slots < max ? ' · consigue más en la tienda de puntos (hasta ' + max + ')' : ' (máximo)');
            box.appendChild(info);
        } else if (own && !S.draft.length) {
            var hint = document.createElement('div');
            hint.className = 'pf2-slot';
            hint.textContent = 'Pulsa Personalizar para añadir expositores a tu perfil.';
            box.appendChild(hint);
        }
    }

    function emptyText(type) {
        if (type.indexOf('steam_') === 0) {
            var st = S.v2.steam || {};
            if (!st.configured) return 'Steam no está configurado en la web.';
            if (!st.linked) return 'Conecta tu Steam en Personalizar.';
            if (st['private']) return 'Tu perfil de Steam es privado: pon el perfil y "Detalles del juego" en público.';
            if (type === 'steam_rare') return 'Aún no hay logros que enseñar.';
        }
        return S.editing ? 'Pulsa "Editar" para elegir qué enseñar.' : 'Vacío: elige qué enseñar en Personalizar.';
    }

    /* Arrastrar: la posición del ratón decide delante de qué expositor va. */
    document.addEventListener('dragover', function (e) {
        var box = $('pf2-showcases');
        var cur = box && box.querySelector('.pf2-show.dragging');
        if (!cur) return;
        e.preventDefault();
        var shows = Array.prototype.slice.call(box.querySelectorAll('.pf2-show')).filter(function (x) { return x !== cur; });
        var after = shows.find(function (x) { var r = x.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
        if (after) box.insertBefore(cur, after);
        else {
            var firstSlot = box.querySelector('.pf2-slot');
            box.insertBefore(cur, firstSlot || null);
        }
    });
    function syncOrder() {
        var box = $('pf2-showcases');
        var order = Array.prototype.map.call(box.querySelectorAll('.pf2-show'), function (p) { return +p.dataset.idx; });
        S.draft = order.map(function (i) { return S.draft[i]; });
        renderShowcases();
    }

    /* Horas y logros de Steam en la ventanita de un juego favorito. */
    function steamRows(st) {
        if (!st) return '';
        if (!st.inSteam) return '<div class="pf2-tip-row" style="opacity:.8;">' + ic('steamIcon', 12, 3) + 'No está en su Steam</div>';
        var pct = st.total ? Math.round(100 * st.achieved / st.total) : 0;
        return '<div class="pf2-tip-row">' + ic('steamIcon', 12, 3) + 'Horas en Steam <b>' + st.hours.toLocaleString('es') + ' h</b></div>' +
            (st.total ? '<div class="pf2-tip-row">' + ic('bestOfTheYearIcon', 12, 3) + 'Logros <b>' + st.achieved + ' / ' + st.total + '</b></div>' +
                        '<div class="pf2-bar"><i style="width:' + pct + '%"></i></div>' : '');
    }
    /* Fila de logros (iconos) con su ventanita y el resumen de Steam. */
    function renderAch(body, data, rare) {
        var items = data.items || [], tot = data.totals || {};
        body.innerHTML = '<div class="pf2-ach"><div class="pf2-ach-list"></div>' +
            '<div class="pf2-ach-sum"><b>' + (tot.games || 0) + '</b> juegos en Steam<br><b>' + (tot.hours || 0).toLocaleString('es') + '</b> h jugadas</div></div>';
        var list = body.querySelector('.pf2-ach-list');
        items.forEach(function (a, i) {
            var d = document.createElement('div');
            d.className = 'pf2-ach-it' + (i % 8 >= 4 ? ' flip' : '');
            d.innerHTML = '<img src="' + esc(a.icon) + '" alt="">' +
                (rare && a.percent != null ? '<div>' + String(a.percent).replace('.', ',') + ' %</div>' : '') +
                '<div class="pf2-mtip"><b>' + esc(a.name) + '</b>' + (a.desc ? '<br>' + esc(a.desc) : '') +
                '<br><i>' + esc(a.game || '') + '</i>' +
                (a.percent != null ? '<br>Lo tiene el ' + String(a.percent).replace('.', ',') + ' % de los jugadores' : '') + '</div>';
            list.appendChild(d);
        });
    }

    var RENDER = {
        steam_rare: function (body, data) { renderAch(body, data, true); },
        steam_ach:  function (body, data) { renderAch(body, data, false); },
        games: function (body, data) {
            body.innerHTML = '<div class="pf2-games"></div>';
            var g = body.firstChild;
            data.forEach(function (it, i) {
                var d = document.createElement('div');
                d.className = 'pf2-game' + (i % 4 >= 2 ? ' flip' : '');
                d.innerHTML = (it.image ? '<img src="' + esc(it.image) + '" alt="" loading="lazy">' : '<div class="pf2-game-noimg">' + esc(it.title) + '</div>') +
                    '<div class="window pf2-tip"><div class="title-bar"><div class="title-bar-text">' + esc(it.title) + '</div></div>' +
                    '<div class="window-body">' + steamRows(it.steam) +
                        '<div class="pf2-tip-row">Reseña ' + (it.stars != null ? '<b class="pf2-stars">' + stars(it.stars) + '</b>' : '<b>Sin reseña</b>') + '</div>' +
                    '</div></div>';
                g.appendChild(d);
            });
        },
        shots: function (body, data) {
            var big = data[0], rest = data.slice(1);
            body.innerHTML = '<div class="pf2-shots' + (rest.length ? '' : ' n1') + '">' +
                '<div class="pf2-shot-big profile-post-img"><img src="' + esc(big.url) + '" alt="" loading="lazy">' +
                    ((big.caption || big.game) ? '<div class="pf2-shot-cap">' + esc(big.caption) + (big.caption && big.game ? ' · ' : '') + (big.game ? '<i>' + esc(big.game) + '</i>' : '') + '</div>' : '') +
                '</div>' +
                (rest.length ? '<div class="pf2-shot-thumbs">' + rest.map(function (s) {
                    return '<div class="profile-post-img" title="' + esc([s.caption, s.game].filter(Boolean).join(' · ')) + '"><img src="' + esc(s.url) + '" alt="" loading="lazy"></div>';
                }).join('') + '</div>' : '') +
            '</div>';
        },
        review: function (body, r) {
            body.innerHTML = '<div class="pf2-review">' +
                (r.image ? '<img src="' + esc(r.image) + '" alt="">' : '') +
                '<div><div class="pf2-review-t">' + esc(r.title) + (r.artist ? ' — ' + esc(r.artist) : '') +
                    ' <span class="pf2-stars">' + stars(r.stars) + '</span></div>' +
                    '<div style="font-size:10px;color:var(--text-faint,#808080);">' + esc(CAT_LABEL[r.category] || '') + '</div>' +
                    (r.comment ? '<div class="pf2-review-txt">' + esc(r.comment) + '</div>' : '') +
                '</div></div>';
        },
        music: function (body, data) {
            body.innerHTML = '<div class="pf2-music"></div>';
            var g = body.firstChild;
            data.forEach(function (it) {
                var d = document.createElement('div');
                d.className = 'pf2-album';
                d.title = it.title + (it.artist ? ' — ' + it.artist : '');
                d.innerHTML = (it.cover ? '<img src="' + esc(it.cover) + '" alt="">' : '<div class="pf2-album-ph"></div>') +
                    '<div>' + esc(it.title) + '</div>';
                if (!it.cover) albumCover(it, d);
                d.addEventListener('contextmenu', function (e) {
                    e.preventDefault();
                    showMenu(e, [{ label: '▶ Reproducir', fn: function () { play(it); } }]);
                });
                g.appendChild(d);
            });
        },
    };

    /* Portada del ÁLBUM (no la miniatura del vídeo): la busca find-album,
       que la deja en caché en el servidor; aquí también en localStorage. */
    function albumCover(it, el) {
        var key = 'pf2:cover:' + (it.ytId || (it.title + '|' + it.artist));
        var cached = null;
        try { cached = localStorage.getItem(key); } catch (_) {}
        var put = function (url) {
            if (!url) return;
            var ph = el.querySelector('.pf2-album-ph');
            if (ph) ph.outerHTML = '<img src="' + esc(url) + '" alt="">';
        };
        if (cached) { put(cached); return; }
        var q = 'assets/music/api.php?action=find-album&title=' + encodeURIComponent(it.title || '') +
            '&artist=' + encodeURIComponent(it.artist || '') + (it.ytId ? '&videoId=' + encodeURIComponent(it.ytId) : '');
        fetch(q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            var url = d && (d.albumImage || d.image);
            if (!url) return;
            try { localStorage.setItem(key, url); } catch (_) {}
            put(url);
        }).catch(function () {});
    }
    function play(it) {
        var b = bridge();
        if (!b.playMusicItem) return;
        b.playMusicItem({ type: it.type, title: it.title, artist: it.artist, ytId: it.ytId,
                          ytPlaylistId: it.ytPlaylistId, spotifyAlbumId: it.spotifyAlbumId, image: it.cover || '' });
    }

    /* Menú contextual Win98 (uno para todo el perfil). */
    function showMenu(e, items) {
        var m = $('pf2-menu');
        if (!m) {
            m = document.createElement('div');
            m.id = 'pf2-menu';
            m.className = 'window pf2-menu';
            document.body.appendChild(m);
            document.addEventListener('click', function () { m.style.display = 'none'; });
            document.addEventListener('contextmenu', function (ev) { if (!ev.defaultPrevented) m.style.display = 'none'; });
        }
        m.innerHTML = '';
        items.forEach(function (it) {
            var d = document.createElement('div');
            d.textContent = it.label;
            d.addEventListener('click', function () { m.style.display = 'none'; it.fn(); });
            m.appendChild(d);
        });
        m.style.display = 'block';
        m.style.left = Math.min(e.clientX, window.innerWidth - m.offsetWidth - 4) + 'px';
        m.style.top = Math.min(e.clientY, window.innerHeight - m.offsetHeight - 4) + 'px';
    }

    /* ── Comentarios del muro ── */
    function renderComments() {
        var box = $('pf2-comments');
        if (!box || !S.v2) return;
        var list = S.v2.comments || [];
        box.innerHTML =
            '<div class="profile-sidebar-heading pf2-panel-head">' + ic('chatIcon') + 'Comentarios<span class="pf2-sub">' + list.length + '</span></div>' +
            '<div class="pf2-panel-body">' +
                '<div class="pf2-new-comment"><input type="text" maxlength="500" placeholder="Escribe un comentario…"><button type="button" class="button">Publicar</button></div>' +
                '<div class="pf2-comment-list"></div>' +
            '</div>';
        var input = box.querySelector('input'), btn = box.querySelector('.pf2-new-comment .button');
        var send = function () {
            var text = input.value.trim();
            if (!text || btn.disabled) return;
            btn.disabled = true;
            api('wall-comment', { user: S.ctx.userKey, text: text }).then(function (d) {
                btn.disabled = false;
                if (d && d.ok) { S.v2.comments = d.comments; renderComments(); var i2 = $('pf2-comments').querySelector('input'); if (i2) i2.focus(); }
                else alert((d && d.error) || 'No se pudo publicar');
            }).catch(function () { btn.disabled = false; });
        };
        btn.addEventListener('click', send);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
        var ul = box.querySelector('.pf2-comment-list');
        if (!list.length) { ul.innerHTML = '<div class="pf2-empty">Aún no hay comentarios.</div>'; return; }
        var rel = bridge().relTime || function () { return ''; };
        list.forEach(function (c) {
            var d = document.createElement('div');
            d.className = 'pf2-comment';
            d.innerHTML = (c.avatar ? '<img src="' + esc(c.avatar) + '" alt="">' : '<img alt="" style="visibility:hidden">') +
                '<div class="pf2-comment-main"><span class="pf2-comment-who">' + esc(c.author) + '</span>' +
                    '<span class="pf2-comment-when">' + esc(rel(c.ts)) + '</span>' +
                    (c.text ? '<div class="pf2-comment-txt">' + esc(c.text) + '</div>' : '') +
                    (c.imageUrl ? '<div class="profile-post-img"><img src="' + esc(c.imageUrl) + '" alt="" loading="lazy"></div>' : '') +
                '</div>' +
                (c.canDelete ? '<button type="button" class="button pf2-del" title="Borrar comentario">✕</button>' : '');
            var open = function () { if (bridge().openProfile) bridge().openProfile(c.authorKey); };
            d.querySelector('.pf2-comment-who').addEventListener('click', open);
            d.querySelector('img').addEventListener('click', open);
            var del = d.querySelector('.pf2-del');
            if (del) del.addEventListener('click', function () {
                if (!confirm('¿Borrar este comentario?')) return;
                api('delete-wall-comment', { id: c.id }).then(function (r) {
                    if (r && r.ok) { S.v2.comments = r.comments; renderComments(); }
                    else alert((r && r.error) || 'No se pudo borrar');
                });
            });
            ul.appendChild(d);
        });
    }

    /* ── Columna lateral ── */
    function medalSvg(m) {
        var t = TIERS[m.tier] || TIERS[1];
        return '<svg viewBox="0 0 34 40"><path d="M9 0h6l2 10-6 0z" fill="var(--accent)"/><path d="M19 0h6l-5 10h-3z" fill="var(--accent-deep, var(--accent))"/>' +
            '<circle cx="17" cy="25" r="13" fill="' + t[1] + '" stroke="' + t[0] + '" stroke-width="3"/>' +
            '<text x="17" y="30" font-size="13" text-anchor="middle">' + m.emoji + '</text></svg>';
    }
    function renderSide() {
        var box = $('pf2-side');
        if (!box || !S.v2) return;
        var st = S.v2.stats || {}, c = st.counts || {};
        var html = '';
        html += '<div class="pf2-panel"><div class="profile-sidebar-heading pf2-panel-head">Nivel</div><div class="pf2-panel-body">' +
            '<div class="pf2-lvl"><span class="pf2-level">' + (st.level || 1) + '</span> Nivel ' + (st.level || 1) + '</div>' +
            '<div class="pf2-bar" style="margin-top:6px;"><i style="width:' + (st.xpInto || 0) + '%"></i></div>' +
            '<div class="pf2-xp">' + (st.xpInto || 0) + ' / 100 XP para el nivel ' + ((st.level || 1) + 1) + '</div></div></div>';
        html += '<div class="pf2-panel"><div class="profile-sidebar-heading pf2-panel-head">Medallas</div><div class="pf2-panel-body pf2-medals">' +
            (st.medals || []).map(function (m) {
                var tierName = m.tier ? TIERS[m.tier][2] : 'Sin medalla';
                var nextTxt = m.next == null ? 'Nivel máximo'
                    : (TIERS[m.tier + 1][2] + ' con ' + m.next.toLocaleString('es') + ' ' + m.unit.split(' ')[0]);
                return '<div class="pf2-medal' + (m.tier ? '' : ' locked') + '">' + medalSvg(m) +
                    '<div class="pf2-mtip"><b>' + esc(m.name) + ' · ' + tierName + '</b><br>' +
                    m.value.toLocaleString('es') + ' ' + esc(m.unit) + '<br>' + esc(nextTxt) +
                    '<div class="pf2-bar"><i style="width:' + m.progress + '%"></i></div></div></div>';
            }).join('') + '</div></div>';
        var fr = S.v2.friends || [];
        html += '<div class="pf2-panel"><div class="profile-sidebar-heading pf2-panel-head">' + ic('profileIcon') + 'Amigos<span class="pf2-sub">' + fr.length + '</span></div>' +
            '<div class="pf2-panel-body">' + (fr.length ? '<div class="pf2-friends">' + fr.slice(0, 8).map(function (f) {
                return f.image ? '<img src="' + esc(f.image) + '" alt="" title="' + esc(f.label) + '" data-key="' + esc(f.key) + '">'
                               : '<div class="pf2-friend-ph" title="' + esc(f.label) + '" data-key="' + esc(f.key) + '">' + esc(f.label.slice(0, 2)) + '</div>';
            }).join('') + '</div>' : '<div class="pf2-empty">Sin amigos todavía.</div>') + '</div></div>';
        html += '<div class="pf2-panel"><div class="profile-sidebar-heading pf2-panel-head">Actividad</div><div class="pf2-panel-body pf2-counts">' +
            '<div>Reseñas <b>' + (c.reviews || 0) + '</b></div><div>Capturas <b>' + (c.shots || 0) + '</b></div>' +
            '<div>Horas escuchadas <b>' + (c.hours || 0).toLocaleString('es') + '</b></div><div>En Melon Hub <b>' + (c.days || 0) + ' días</b></div></div></div>';
        box.innerHTML = html;
        box.querySelectorAll('[data-key]').forEach(function (el) {
            el.addEventListener('click', function () { if (bridge().openProfile) bridge().openProfile(el.dataset.key); });
        });
    }

    /* "● En línea / Ausente / No molestar / Desconectado". */
    function refreshStatus() {
        var el = $('pf2-status');
        if (!el || !S.ctx) return;
        var st = S.ctx.own ? 'online' : (bridge().presence ? bridge().presence(S.ctx.userKey) : 'offline');
        var txt = { online: 'En línea', away: 'Ausente', dnd: 'No molestar', offline: 'Desconectado' }[st];
        el.className = 'pf2-status is-' + st;
        el.innerHTML = '● <b>' + txt + '</b>';
    }

    /* ════════════════════ Personalizar ════════════════════ */
    function setEditing(on, fresh) {
        S.editing = !!on;
        if (on && fresh) { S.v2.slots = fresh.slots; S.v2.maxSlots = fresh.maxSlots; S.v2.steam = fresh.steam; S.draft = copy(fresh.showcases || []); }
        if (!on && S.v2) S.draft = copy(S.v2.showcases || []);
        closePicker();
        renderShowcases();
        renderSteamCtl();
    }

    /* "Conectar Steam" (solo en Personalizar, debajo de las redes): inicio
       de sesión de Steam en una ventana (steam-login.php), que avisa al
       terminar con postMessage 'steam-linked'. */
    function renderSteamCtl() {
        var old = $('pf2-steam-ctl');
        if (old) old.remove();
        if (!S.editing || !S.v2) return;
        var head = $('pf2-head');
        if (!head) return;
        var st = S.v2.steam || {};
        var box = document.createElement('div');
        box.id = 'pf2-steam-ctl';
        if (!st.configured) {
            box.innerHTML = ic('steamIcon', 12, 3) + 'Steam no está configurado en la web';
        } else if (st.linked) {
            box.innerHTML = '<span>' + ic('steamIcon', 12, 3) + esc(st.persona || 'Steam vinculado') + '</span>' +
                '<button type="button" class="button" data-a="off">Desconectar</button>' +
                (st['private'] ? '<div class="pf2-steam-warn">Tu perfil de Steam es privado: pon el perfil y "Detalles del juego" en público.</div>' : '');
            box.querySelector('[data-a="off"]').addEventListener('click', function () {
                if (!confirm('¿Desconectar tu cuenta de Steam?')) return;
                api('steam-disconnect', {}).then(function (d) {
                    if (!d || !d.ok) { alert((d && d.error) || 'No se pudo desconectar'); return; }
                    S.v2.steam = d.steam;
                    if (window.__pf2SetSocial) window.__pf2SetSocial('steam', '');
                    renderSteamCtl(); renderShowcases();
                });
            });
        } else {
            box.innerHTML = '<button type="button" class="button" data-a="on">' + ic('steamIcon', 12, 3) + 'Conectar Steam</button>';
            box.querySelector('[data-a="on"]').addEventListener('click', function () {
                var w = window.open('assets/profile/steam-login.php', 'steam-openid', 'width=820,height=720');
                if (!w) location.href = 'assets/profile/steam-login.php';
            });
        }
        head.appendChild(box);
    }
    window.addEventListener('message', function (e) {
        var d = e && e.data;
        if (!d || d.type !== 'steam-linked') return;
        if (d.status !== 'ok') { alert('No se pudo vincular Steam: ' + (d.detail || '')); return; }
        api('get-profile').then(function (p) {
            if (!p || !p.v2 || !S.v2) return;
            S.v2.steam = p.v2.steam;
            if (window.__pf2SetSocial && p.v2.steam && p.v2.steam.steamId) {
                window.__pf2SetSocial('steam', 'https://steamcommunity.com/profiles/' + p.v2.steam.steamId);
            }
            renderSteamCtl(); renderShowcases();
        });
    });

    function save() {
        if (!S.v2) return Promise.resolve({ ok: true });
        var body = { showcases: S.draft.map(function (s) { return { type: s.type, config: s.config }; }) };
        return api('save-showcases', body).then(function (d) {
            if (d && d.ok) { S.v2.showcases = d.showcases; S.v2.slots = d.slots; S.draft = copy(d.showcases); }
            return d || { error: 'No se pudieron guardar los expositores' };
        }).catch(function () { return { error: 'Error de red al guardar los expositores' }; });
    }

    /* Elegir el tipo del expositor nuevo (sin repetir los que ya hay). */
    function openTypeMenu(e) {
        var used = S.draft.map(function (s) { return s.type; });
        var items = Object.keys(TYPES).filter(function (t) { return used.indexOf(t) < 0; }).map(function (t) {
            return { label: TYPES[t].label, fn: function () {
                var cfg = t === 'review' ? { category: '', title: '' } : t === 'games' ? { titles: [] }
                        : t === 'steam_rare' ? {} : t === 'steam_ach' ? { items: [] } : { ids: [] };
                S.draft.push({ type: t, config: cfg, data: (t === 'review' || t.indexOf('steam_') === 0) ? {} : [] });
                renderShowcases();
                /* Los raros se calculan solos al guardar. */
                if (t !== 'steam_rare') openPicker(S.draft.length - 1);
            } };
        });
        if (!items.length) items = [{ label: 'Ya tienes todos los tipos', fn: function () {} }];
        e.stopPropagation();
        showMenu(e, items);
    }

    /* Ventana Win98 para elegir el contenido. */
    function closePicker() { var w = $('pf2-picker'); if (w) w.remove(); window.__pf2AfterAdd = null; }
    function pickerWindow(title) {
        closePicker();
        var w = document.createElement('div');
        w.id = 'pf2-picker';
        w.className = 'window pf2-picker';
        w.innerHTML = '<div class="title-bar"><div class="title-bar-text">' + esc(title) + '</div>' +
            '<div class="title-bar-controls"><button aria-label="Close"></button></div></div>' +
            '<div class="window-body"></div>';
        document.body.appendChild(w);
        w.querySelector('[aria-label="Close"]').addEventListener('click', closePicker);
        w.style.left = Math.max(8, (window.innerWidth - w.offsetWidth) / 2) + 'px';
        w.style.top = Math.max(8, (window.innerHeight - 520) / 2) + 'px';
        return w.querySelector('.window-body');
    }
    function pickerFoot(body, info, onOk) {
        var f = document.createElement('div');
        f.className = 'pf2-pick-foot';
        f.innerHTML = '<span class="pf2-pick-info"></span><button type="button" class="button" data-a="ok">Aceptar</button><button type="button" class="button" data-a="no">Cancelar</button>';
        f.querySelector('.pf2-pick-info').textContent = info || '';
        f.querySelector('[data-a="ok"]').addEventListener('click', function () { onOk(); closePicker(); renderShowcases(); });
        f.querySelector('[data-a="no"]').addEventListener('click', closePicker);
        body.appendChild(f);
        return f.querySelector('.pf2-pick-info');
    }

    function ownList(cat) { var l = bridge().ownLists ? bridge().ownLists() : {}; return (l && l[cat]) || []; }
    function ownReview(cat, title) {
        var low = String(title).toLowerCase();
        var it = ownList(cat).find(function (x) { return String(x.title).toLowerCase() === low; });
        return it && it.review ? it.review : null;
    }

    function openPicker(idx) {
        var sc = S.draft[idx];
        if (!sc) return;
        PICK[sc.type](sc);
    }

    /* ── Google Drive de la Galería ──
       Mismo cliente, permiso (drive.file), carpeta y token en localStorage
       que apps/galeria.php: si la Galería ya está conectada, aquí también,
       y conectar desde aquí la deja conectada allí. */
    var GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
    var GDRIVE_FOLDER = 'Scrapbook Melon - Galería';
    var DRIVE_TOKEN_KEY = 'galeria_drive_token', DRIVE_EVER_AUTH_KEY = 'galeria_drive_ever_auth';
    var _thumbs = {};
    function driveToken() {
        try {
            var t = JSON.parse(localStorage.getItem(DRIVE_TOKEN_KEY) || 'null');
            if (t && t.expires_at > Date.now() + 30000) return t;
        } catch (_) {}
        return null;
    }
    function loadGis(cb) {
        var ready = function () { return window.google && google.accounts && google.accounts.oauth2; };
        if (ready()) { cb(); return; }
        if (!document.querySelector('script[data-pf2-gis]')) {
            var sc = document.createElement('script');
            sc.src = 'https://accounts.google.com/gsi/client';
            sc.async = true;
            sc.setAttribute('data-pf2-gis', '1');
            document.head.appendChild(sc);
        }
        var n = 0, iv = setInterval(function () {
            if (ready()) { clearInterval(iv); cb(); }
            else if (++n > 80) { clearInterval(iv); cb(new Error('no se pudo cargar el inicio de sesión de Google')); }
        }, 250);
    }
    function connectDrive(cb) {
        loadGis(function (err) {
            if (err) { cb(err); return; }
            if (!window.__GOOGLE_CLIENT_ID) { cb(new Error('Google no está configurado en la web')); return; }
            var c = google.accounts.oauth2.initTokenClient({
                client_id: window.__GOOGLE_CLIENT_ID,
                scope: GDRIVE_SCOPE,
                callback: function (resp) {
                    if (resp.error) { cb(new Error(resp.error)); return; }
                    var t = { access_token: resp.access_token, expires_at: Date.now() + resp.expires_in * 1000 - 60000 };
                    try { localStorage.setItem(DRIVE_TOKEN_KEY, JSON.stringify(t)); localStorage.setItem(DRIVE_EVER_AUTH_KEY, '1'); } catch (_) {}
                    cb(null, t);
                },
            });
            c.requestAccessToken({ prompt: '' });
        });
    }
    function driveFetch(url, opts) {
        var t = driveToken();
        if (!t) return Promise.reject({ auth: true });
        opts = opts || {};
        opts.headers = Object.assign({}, opts.headers || {}, { Authorization: 'Bearer ' + t.access_token });
        return fetch(url, opts).then(function (r) {
            if (r.status === 401) { try { localStorage.removeItem(DRIVE_TOKEN_KEY); } catch (_) {} throw { auth: true }; }
            if (!r.ok) return r.text().then(function (x) { throw new Error('Drive ' + r.status + ': ' + x.slice(0, 120)); });
            return r;
        });
    }
    /* Imágenes de la carpeta de la Galería (sin los WIP .psd/.kra…). */
    function listGallery() {
        var q = "mimeType='application/vnd.google-apps.folder' and name='" + GDRIVE_FOLDER.replace(/'/g, "\\'") + "' and trashed=false";
        return driveFetch('https://www.googleapis.com/drive/v3/files?q=' + encodeURIComponent(q) + '&fields=files(id)&spaces=drive')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.files || !d.files.length) return [];
                var q2 = "'" + d.files[0].id + "' in parents and trashed=false and mimeType contains 'image/'";
                return driveFetch('https://www.googleapis.com/drive/v3/files?q=' + encodeURIComponent(q2) +
                    '&fields=files(id,name,mimeType,thumbnailLink)&orderBy=modifiedTime desc&pageSize=200')
                    .then(function (r) { return r.json(); })
                    .then(function (x) { return (x.files || []).filter(function (f) { return !/\.(psd|kra|ora|csp)$/i.test(f.name); }); });
            });
    }
    /* Miniatura: thumbnailLink (sin referrer, como la Galería) y, si no
       carga, la imagen descargada con el permiso de Drive. */
    function driveThumb(f, ph) {
        var put = function (url) {
            var img = new Image();
            img.alt = '';
            img.referrerPolicy = 'no-referrer';
            img.src = url;
            img.style.cssText = 'width:100%;aspect-ratio:16/9;object-fit:cover;display:block;';
            if (ph.parentNode) ph.parentNode.replaceChild(img, ph);
        };
        if (_thumbs[f.id]) { put(_thumbs[f.id]); return; }
        var blob = function () {
            driveFetch('https://www.googleapis.com/drive/v3/files/' + f.id + '?alt=media')
                .then(function (r) { return r.blob(); })
                .then(function (b) { _thumbs[f.id] = URL.createObjectURL(b); put(_thumbs[f.id]); })
                .catch(function () {});
        };
        if (!f.thumbnailLink) { blob(); return; }
        var probe = new Image();
        probe.referrerPolicy = 'no-referrer';
        probe.onload = function () { _thumbs[f.id] = f.thumbnailLink; put(f.thumbnailLink); };
        probe.onerror = blob;
        probe.src = f.thumbnailLink;
    }
    /* "Cualquiera con el enlace puede ver" (si ya lo era, Drive da error de
       duplicado y no pasa nada), como al publicar en la Galería. */
    function makePublic(id) {
        return driveFetch('https://www.googleapis.com/drive/v3/files/' + id + '/permissions', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ role: 'reader', type: 'anyone' }),
        }).catch(function (e) {
            if (e && e.auth) throw new Error('Tu conexión con Google Drive ha caducado: vuelve a abrir Capturas.');
            if (!/duplicate|alreadyExists|exists/i.test((e && e.message) || '')) throw e;
        });
    }

    var PICK = {
        /* Juegos: del catálogo de juegos de las reseñas/listas; "Crear juego"
           abre el mismo diálogo que añadir un juego pendiente. */
        games: function (sc) {
            var sel = (sc.config.titles || []).slice();
            var body = pickerWindow('Juegos favoritos');
            body.innerHTML =
                '<div style="font-size:11px;">Elegidos (máx. ' + MAX.games + '):</div>' +
                '<div class="pf2-pick-list" data-r="sel" style="max-height:150px;"><div class="pf2-pick-grid"></div></div>' +
                '<div style="display:flex;gap:4px;"><input type="text" placeholder="Buscar juego…" style="flex:1;"><button type="button" class="button" data-a="new">＋ Crear juego</button></div>' +
                '<div class="pf2-pick-list" data-r="res"><div class="pf2-pick-grid"></div></div>';
            var input = body.querySelector('input');
            var selG = body.querySelector('[data-r="sel"] .pf2-pick-grid'), resG = body.querySelector('[data-r="res"] .pf2-pick-grid');
            var info;
            var tile = function (t, on, n) {
                var img = S.catalog[t.toLowerCase()];
                return '<div class="pf2-pick portrait' + (on ? ' on' : '') + '" data-t="' + esc(t) + '">' +
                    (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '<div class="pf2-pick-ph"></div>') +
                    (n ? '<span class="pf2-pick-n">' + n + '</span>' : '') +
                    '<div class="pf2-pick-t">' + esc(t) + '</div></div>';
            };
            var paintSel = function () {
                selG.innerHTML = sel.length ? sel.map(function (t, i) { return tile(t, true, i + 1); }).join('') : '<div class="pf2-empty">Ninguno todavía.</div>';
                selG.querySelectorAll('.pf2-pick').forEach(function (el) {
                    el.title = 'Quitar';
                    el.addEventListener('click', function () { sel.splice(sel.indexOf(el.dataset.t), 1); paintSel(); paintRes(lastRes); });
                });
                if (info) info.textContent = sel.length + ' de ' + MAX.games;
            };
            var lastRes = [];
            var paintRes = function (games) {
                lastRes = games;
                resG.innerHTML = games.length ? games.map(function (g) {
                    return tile(g.title, sel.indexOf(g.title) >= 0, 0);
                }).join('') : '<div class="pf2-empty">No hay juegos con ese nombre. Puedes crearlo.</div>';
                resG.querySelectorAll('.pf2-pick').forEach(function (el) {
                    el.addEventListener('click', function () {
                        var t = el.dataset.t, k = sel.indexOf(t);
                        if (k >= 0) sel.splice(k, 1);
                        else if (sel.length < MAX.games) sel.push(t);
                        paintSel(); paintRes(lastRes);
                    });
                });
            };
            var timer = null;
            var search = function () {
                api('game-catalog&q=' + encodeURIComponent(input.value.trim())).then(function (d) {
                    var g = (d && d.games) || [];
                    g.forEach(function (x) { if (x.image) S.catalog[x.title.toLowerCase()] = x.image; });
                    paintRes(g);
                    paintSel();
                });
            };
            input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
            body.querySelector('[data-a="new"]').addEventListener('click', function () {
                var b = bridge();
                if (!b.openAddDialog) return;
                window.__pf2AfterAdd = function (cat, item) {
                    if (cat !== 'games') return;
                    if (item.image) S.catalog[item.title.toLowerCase()] = item.image;
                    if (sel.indexOf(item.title) < 0 && sel.length < MAX.games) sel.push(item.title);
                    paintSel(); search();
                };
                b.openAddDialog('games');
                var dlg = $('profile-add-dialog');
                if (dlg) dlg.style.zIndex = 3000;
            });
            info = pickerFoot(body, '', function () {
                sc.config = { titles: sel.slice() };
                sc.data = sel.map(function (t) {
                    var rv = ownReview('games', t);
                    var own = ownList('games').find(function (x) { return x.title.toLowerCase() === t.toLowerCase(); });
                    return { title: t, image: S.catalog[t.toLowerCase()] || (own && own.image) || '', stars: rv ? rv.stars : null };
                });
            });
            sel.forEach(function (t) {
                var own = ownList('games').find(function (x) { return x.title.toLowerCase() === t.toLowerCase(); });
                if (own && own.image && !S.catalog[t.toLowerCase()]) S.catalog[t.toLowerCase()] = own.image;
            });
            (sc.data || []).forEach(function (d) { if (d.image) S.catalog[d.title.toLowerCase()] = d.image; });
            search();
            input.focus();
        },

        /* Capturas: de la Galería (Google Drive). Sin Drive conectado, pide
           conectarlo. Hasta 3 (la 1.ª sale grande), con pie y juego. Al
           aceptar se hacen públicas en Drive (como "Publicar" de la
           Galería) y el servidor guarda el enlace. */
        shots: function (sc) {
            var body = pickerWindow('Capturas');
            body.innerHTML =
                '<div class="pf2-drive-msg" style="font-size:11px;"></div>' +
                '<div class="pf2-pick-list" style="max-height:230px;"><div class="pf2-pick-grid" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));"></div></div>' +
                '<div class="pf2-shot-meta" style="display:flex;flex-direction:column;gap:3px;"></div>' +
                '<div class="pf2-pick-foot"><span class="pf2-pick-info"></span><button type="button" class="button" data-a="ok">Aceptar</button><button type="button" class="button" data-a="no">Cancelar</button></div>';
            var msg = body.querySelector('.pf2-drive-msg'), grid = body.querySelector('.pf2-pick-grid');
            var metaBox = body.querySelector('.pf2-shot-meta'), info = body.querySelector('.pf2-pick-info');
            var okBtn = body.querySelector('[data-a="ok"]');
            body.querySelector('[data-a="no"]').addEventListener('click', closePicker);
            var files = [], sel = [], meta = {};     /* meta: driveId → { caption, game } */

            /* Lo que ya estaba elegido (y sus pies) sale de mis capturas. */
            var mine = S.myShots ? Promise.resolve(S.myShots) : api('my-screenshots').then(function (d) { return (S.myShots = (d && d.shots) || []); });
            mine.then(function (shots) {
                shots.forEach(function (x) { if (x.driveId) meta[x.driveId] = { caption: x.caption || '', game: x.game || '' }; });
                (sc.config.ids || []).forEach(function (id) {
                    var x = shots.find(function (y) { return y.id === id; });
                    if (x && x.driveId) sel.push(x.driveId);
                });
                start();
            });

            function start() {
                if (!driveToken()) { askConnect(); return; }
                msg.textContent = 'Cargando tu Galería…';
                listGallery().then(function (list) {
                    files = list;
                    msg.textContent = files.length ? 'Elige hasta ' + MAX.shots + ' imágenes de tu Galería (la primera sale grande):'
                                                   : 'Tu Galería está vacía: sube imágenes desde la app Galería.';
                    paint();
                }).catch(function (e) {
                    if (e && e.auth) { askConnect(); return; }
                    msg.textContent = 'No se pudo leer tu Galería: ' + ((e && e.message) || 'error');
                });
            }
            function askConnect() {
                msg.innerHTML = 'Para elegir capturas de tu Galería tienes que conectar tu Google Drive (el mismo que usa la Galería). ' +
                    '<button type="button" class="button" data-a="drive" style="margin-left:4px;">Conectar Google Drive</button>';
                grid.innerHTML = ''; metaBox.innerHTML = ''; okBtn.disabled = true;
                msg.querySelector('[data-a="drive"]').addEventListener('click', function () {
                    connectDrive(function (err) {
                        if (err) { msg.textContent = 'No se pudo conectar: ' + err.message; return; }
                        okBtn.disabled = false;
                        start();
                    });
                });
            }
            function paint() {
                grid.innerHTML = files.map(function (f) {
                    var n = sel.indexOf(f.id) + 1;
                    return '<div class="pf2-pick wide' + (n ? ' on' : '') + '" data-id="' + esc(f.id) + '" title="' + esc(f.name) + '">' +
                        '<div class="pf2-pick-ph" style="aspect-ratio:16/9;"></div>' +
                        (n ? '<span class="pf2-pick-n">' + n + '</span>' : '') +
                        '<div class="pf2-pick-t">' + esc(f.name) + '</div></div>';
                }).join('');
                grid.querySelectorAll('.pf2-pick').forEach(function (el) {
                    var f = files.find(function (x) { return x.id === el.dataset.id; });
                    driveThumb(f, el.querySelector('.pf2-pick-ph'));
                    el.addEventListener('click', function () {
                        var k = sel.indexOf(f.id);
                        if (k >= 0) sel.splice(k, 1); else if (sel.length < MAX.shots) sel.push(f.id);
                        paint();
                    });
                });
                metaBox.innerHTML = sel.map(function (id, i) {
                    var m = meta[id] || { caption: '', game: '' };
                    return '<div style="display:flex;gap:4px;align-items:center;font-size:11px;"><b style="width:14px;">' + (i + 1) + '</b>' +
                        '<input type="text" data-id="' + esc(id) + '" data-f="caption" maxlength="200" placeholder="Pie de foto (opcional)" value="' + esc(m.caption) + '" style="flex:1;">' +
                        '<input type="text" data-id="' + esc(id) + '" data-f="game" maxlength="200" placeholder="Juego (opcional)" value="' + esc(m.game) + '" style="flex:1;"></div>';
                }).join('');
                metaBox.querySelectorAll('input').forEach(function (inp) {
                    inp.addEventListener('input', function () {
                        var m = meta[inp.dataset.id] || (meta[inp.dataset.id] = { caption: '', game: '' });
                        m[inp.dataset.f] = inp.value;
                    });
                });
                info.textContent = sel.length + ' de ' + MAX.shots;
            }
            okBtn.addEventListener('click', function () {
                okBtn.disabled = true; okBtn.textContent = 'Guardando…';
                Promise.all(sel.map(makePublic)).then(function () {
                    return api('gallery-screenshots', { items: sel.map(function (id) {
                        var m = meta[id] || {};
                        return { driveId: id, caption: m.caption || '', game: m.game || '' };
                    }) });
                }).then(function (d) {
                    if (!d || !d.ok) throw new Error((d && d.error) || 'No se pudo guardar');
                    sc.config = { ids: d.shots.map(function (x) { return x.id; }) };
                    sc.data = d.shots;
                    S.myShots = null;      /* se vuelven a pedir la próxima vez */
                    closePicker();
                    renderShowcases();
                }).catch(function (e) {
                    okBtn.disabled = false; okBtn.textContent = 'Aceptar';
                    alert((e && e.message) || 'No se pudo guardar');
                });
            });
        },

        /* Logros de Steam a elegir: hasta 8 desbloqueados, de los juegos que
           quieras (eliges juego a la izquierda y sus logros a la derecha). */
        steam_ach: function (sc) {
            var st = S.v2.steam || {};
            var body = pickerWindow('Logros de Steam');
            if (!st.linked) { body.innerHTML = '<div class="pf2-empty">' + emptyText('steam_ach') + '</div>'; pickerFoot(body, '', function () {}); return; }
            var sel = ((sc.data && sc.data.items) || []).slice();
            body.innerHTML =
                '<div style="font-size:11px;">Elegidos (máx. ' + MAX.steam_ach + '):</div>' +
                '<div class="pf2-pick-list" data-r="sel" style="max-height:80px;"><div class="pf2-ach-list"></div></div>' +
                '<div style="display:flex;gap:6px;min-height:0;">' +
                    '<div style="width:190px;display:flex;flex-direction:column;gap:4px;"><input type="text" placeholder="Buscar juego…"><div class="pf2-pick-list" data-r="games" style="height:240px;"></div></div>' +
                    '<div class="pf2-pick-list" data-r="ach" style="flex:1;height:270px;"><div class="pf2-empty">Elige un juego.</div></div>' +
                '</div>';
            var selL = body.querySelector('[data-r="sel"] .pf2-ach-list'), gamesL = body.querySelector('[data-r="games"]'), achL = body.querySelector('[data-r="ach"]');
            var input = body.querySelector('input');
            var games = [], info, curGame = null;
            var key = function (a) { return a.appid + '|' + a.apiname; };
            var paintSel = function () {
                selL.innerHTML = sel.length ? sel.map(function (a, i) {
                    return '<div class="pf2-ach-it" data-k="' + esc(key(a)) + '" title="Quitar: ' + esc(a.name) + '" style="cursor:pointer;"><img src="' + esc(a.icon) + '" alt=""><div>' + (i + 1) + '</div></div>';
                }).join('') : '<div class="pf2-empty">Ninguno todavía.</div>';
                selL.querySelectorAll('.pf2-ach-it').forEach(function (el) {
                    el.addEventListener('click', function () { sel = sel.filter(function (a) { return key(a) !== el.dataset.k; }); paintSel(); if (curGame) showGame(curGame); });
                });
                if (info) info.textContent = sel.length + ' de ' + MAX.steam_ach;
            };
            var paintGames = function () {
                var q = input.value.trim().toLowerCase();
                var r = games.filter(function (g) { return !q || g.name.toLowerCase().indexOf(q) >= 0; }).slice(0, 300);
                gamesL.innerHTML = r.map(function (g) {
                    return '<div class="pf2-pick-row' + (curGame && curGame.appid === g.appid ? ' on' : '') + '" data-app="' + g.appid + '"><span style="flex:1;">' + esc(g.name) + '</span><small>' + g.hours + ' h</small></div>';
                }).join('') || '<div class="pf2-empty">Sin juegos.</div>';
                gamesL.querySelectorAll('.pf2-pick-row').forEach(function (el) {
                    el.addEventListener('click', function () { curGame = games.find(function (g) { return g.appid === +el.dataset.app; }); paintGames(); showGame(curGame); });
                });
            };
            var showGame = function (g) {
                achL.innerHTML = '<div class="pf2-empty">Cargando logros…</div>';
                api('steam-game-achievements&appid=' + g.appid).then(function (d) {
                    if (!curGame || curGame.appid !== g.appid) return;
                    var list = (d && d.achievements) || [];
                    if (!list.length) { achL.innerHTML = '<div class="pf2-empty">' + ((d && d.total) ? 'Aún no tienes logros de este juego.' : 'Este juego no tiene logros.') + '</div>'; return; }
                    achL.innerHTML = list.map(function (a) {
                        var on = sel.some(function (x) { return x.appid === g.appid && x.apiname === a.apiname; });
                        return '<div class="pf2-pick-row' + (on ? ' on' : '') + '" data-api="' + esc(a.apiname) + '">' +
                            '<img src="' + esc(a.icon) + '" alt=""><span style="flex:1;"><b>' + esc(a.name) + '</b><br><small>' + esc(a.desc) + '</small></span>' +
                            (a.percent != null ? '<small>' + String(a.percent).replace('.', ',') + ' %</small>' : '') + '</div>';
                    }).join('');
                    achL.querySelectorAll('.pf2-pick-row').forEach(function (el) {
                        el.addEventListener('click', function () {
                            var a = list.find(function (x) { return x.apiname === el.dataset.api; });
                            var k = g.appid + '|' + a.apiname;
                            var at = sel.findIndex(function (x) { return key(x) === k; });
                            if (at >= 0) sel.splice(at, 1);
                            else if (sel.length < MAX.steam_ach) sel.push(Object.assign({}, a, { appid: g.appid, game: g.name }));
                            paintSel(); showGame(g);
                        });
                    });
                });
            };
            input.addEventListener('input', paintGames);
            info = pickerFoot(body, '', function () {
                sc.config = { items: sel.map(function (a) { return { appid: a.appid, apiname: a.apiname }; }) };
                sc.data = { items: sel.slice(), totals: { games: st.games || 0, hours: st.hours || 0 } };
            });
            paintSel();
            gamesL.innerHTML = '<div class="pf2-empty">Cargando tu biblioteca…</div>';
            api('steam-games').then(function (d) {
                if (!d || !d.ok) { gamesL.innerHTML = '<div class="pf2-empty"></div>'; gamesL.firstChild.textContent = (d && d.error) || 'No se pudo leer tu Steam'; return; }
                games = d.games; paintGames();
            });
            input.focus();
        },

        /* Reseña destacada: una de mis reseñas (cualquier lista). */
        review: function (sc) {
            var cur = sc.config && sc.config.title ? sc.config.category + '|' + sc.config.title.toLowerCase() : '';
            var body = pickerWindow('Reseña destacada');
            var rows = [];
            ['games', 'movies', 'series', 'books', 'music'].forEach(function (cat) {
                ownList(cat).forEach(function (it) { if (it.review && it.review.stars != null) rows.push({ cat: cat, it: it }); });
            });
            body.innerHTML = '<input type="text" placeholder="Buscar…"><div class="pf2-pick-list"></div>';
            var list = body.querySelector('.pf2-pick-list'), input = body.querySelector('input');
            var paint = function () {
                var q = input.value.trim().toLowerCase();
                var r = rows.filter(function (x) { return !q || x.it.title.toLowerCase().indexOf(q) >= 0; });
                list.innerHTML = r.length ? r.map(function (x) {
                    var k = x.cat + '|' + x.it.title.toLowerCase();
                    return '<div class="pf2-pick-row' + (k === cur ? ' on' : '') + '" data-k="' + esc(k) + '">' +
                        (x.it.image ? '<img src="' + esc(x.it.image) + '" alt="">' : '<span style="width:32px;"></span>') +
                        '<span style="flex:1;">' + esc(x.it.title) + ' <small>(' + CAT_LABEL[x.cat] + ')</small></span>' +
                        '<span class="pf2-stars">' + stars(x.it.review.stars) + '</span></div>';
                }).join('') : '<div class="pf2-empty">No tienes reseñas todavía.</div>';
                list.querySelectorAll('.pf2-pick-row').forEach(function (el) {
                    el.addEventListener('click', function () { cur = el.dataset.k; paint(); });
                });
            };
            input.addEventListener('input', paint);
            pickerFoot(body, '', function () {
                var x = rows.find(function (r) { return r.cat + '|' + r.it.title.toLowerCase() === cur; });
                if (!x) return;
                sc.config = { category: x.cat, title: x.it.title };
                sc.data = { category: x.cat, title: x.it.title, artist: x.it.artist || '', image: x.it.image || '',
                            stars: x.it.review.stars, comment: x.it.review.comment || '' };
            });
            paint();
        },

        /* Música favorita: hasta 5 de mi lista de música. */
        music: function (sc) {
            var sel = (sc.config.ids || []).slice();
            var body = pickerWindow('Música favorita');
            var items = ownList('music').filter(function (it) { return /^\d+$/.test(String(it.id)); });
            body.innerHTML = '<input type="text" placeholder="Buscar…"><div class="pf2-pick-list"><div class="pf2-pick-grid"></div></div>';
            var grid = body.querySelector('.pf2-pick-grid'), input = body.querySelector('input');
            var info;
            var paint = function () {
                var q = input.value.trim().toLowerCase();
                var r = items.filter(function (it) { return !q || (it.title + ' ' + (it.artist || '')).toLowerCase().indexOf(q) >= 0; });
                grid.innerHTML = r.length ? r.map(function (it) {
                    var n = sel.indexOf(+it.id) + 1;
                    var img = isYtThumb(it.image) ? '' : it.image;
                    return '<div class="pf2-pick square' + (n ? ' on' : '') + '" data-id="' + it.id + '" title="' + esc(it.title + (it.artist ? ' — ' + it.artist : '')) + '">' +
                        (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '<div class="pf2-pick-ph"></div>') +
                        (n ? '<span class="pf2-pick-n">' + n + '</span>' : '') +
                        '<div class="pf2-pick-t">' + esc(it.title) + '</div></div>';
                }).join('') : '<div class="pf2-empty">No tienes música en tu lista.</div>';
                grid.querySelectorAll('.pf2-pick').forEach(function (el) {
                    el.addEventListener('click', function () {
                        var id = +el.dataset.id, k = sel.indexOf(id);
                        if (k >= 0) sel.splice(k, 1); else if (sel.length < MAX.music) sel.push(id);
                        paint();
                    });
                });
                if (info) info.textContent = sel.length + ' de ' + MAX.music;
            };
            input.addEventListener('input', paint);
            info = pickerFoot(body, '', function () {
                sc.config = { ids: sel.slice() };
                sc.data = sel.map(function (id) {
                    var it = items.find(function (x) { return +x.id === id; });
                    if (!it) return null;
                    var prev = (sc.data || []).find(function (d) { return d && d.id === id; });
                    return { id: id, title: it.title, artist: it.artist || '', type: it.type || 'song', ytId: it.ytId || '',
                             ytPlaylistId: it.ytPlaylistId || '', spotifyAlbumId: it.spotifyAlbumId || '',
                             cover: (prev && prev.cover) || (isYtThumb(it.image) ? '' : (it.image || '')) };
                }).filter(Boolean);
            });
            paint();
        },
    };

    window.PerfilV2 = { render: render, setEditing: setEditing, save: save, refreshStatus: refreshStatus };
})();
