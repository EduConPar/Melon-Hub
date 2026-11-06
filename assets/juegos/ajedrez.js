/* ──────────────────────────────────────────────────────────────────────
   Ajedrez de la app Juegos (apps/juegos.php; servidor y reglas en
   assets/juegos/ajedrez.php, ajedrez-torneos.php y ajedrez-reglas.php).
   Vistas: menú (retar a un amigo, partidas y retos, torneos), crear
   torneo, torneo (apuntados y cuadro) y partida.
   El navegador no sabe de reglas: el servidor le manda las jugadas que
   valen (legales, en UCI) cuando le toca, y cada jugada va al servidor
   (ajz-mover). Mientras llega la respuesta, la pieza se ve ya movida
   (moverLocal). La partida se pide cada segundo: así se ve la jugada del
   rival y además es la señal de vida (60 s sin ella = partida perdida).
   El menú y el torneo se piden cada 3 s y, si te ha tocado una partida
   sin abrir (reto aceptado, ronda nueva), entran solos en ella.
   Piezas: SVG lisas clásicas dibujadas aquí (pieza()), blancas y negras
   fijas; las casillas, con los colores del tema (juegos.php, .az-sq).
   Nada de confirm()/alert(): bloquean las pruebas de Electron (az-conf).
   Usa window.JG (api, show, fecha, PT, $) de apps/juegos.php.
   ────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';
    var JG = window.JG;
    if (!JG) return;
    var $ = JG.$;

    var vista = null;          /* 'menu' | 'crear' | 'torneo' | 'partida' */
    var menuTimer = null, torTimer = null, partTimer = null, relojTimer = null;
    var estado = null;         /* ajz-estado */
    var torneoId = 0, torneo = null;
    var partida = null;        /* ajz-partida (vista del servidor) */
    var recibida = 0;          /* Date.now() al recibir la partida (relojes) */
    var version = 0;           /* sube con cada jugada: respuestas viejas se tiran */
    var enviando = false;
    var sel = -1;              /* casilla elegida (índice del FEN) */
    var autoAbiertas = {};     /* partidas que ya han entrado solas */
    var volverA = 'menu';      /* de dónde se vino a la partida */

    /* ── Utilidades ── */
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function avatar(u, tam) {
        var dentro = u && u.foto ? '<img src="../' + esc(u.foto) + '" alt="">' : esc(((u && u.nombre) || '?').charAt(0).toUpperCase());
        return '<span class="mh-marco-av bm-av"' + (tam ? ' style="--av:' + tam + 'px"' : '') +
            (u && u.key ? ' data-marco-user="' + esc(u.key) + '"' : '') + '>' + dentro + '</span>';
    }
    function pts(n) { return n + ' ' + JG.PT; }
    function boton(txt, fn, extra) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = txt;
        if (extra) b.className = extra;
        b.addEventListener('click', function (e) { e.stopPropagation(); fn(b); });
        return b;
    }
    function mmss(ms, decimas) {
        ms = Math.max(0, ms);
        var s = Math.floor(ms / 1000);
        if (decimas && ms < 10000) return '0:0' + s + '.' + Math.floor((ms % 1000) / 100);
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = ('0' + (s % 60)).slice(-2);
        return h ? h + ':' + ('0' + m).slice(-2) + ':' + ss : m + ':' + ss;
    }
    var DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    /* Partes de una fecha en hora de Madrid (como la guarda el servidor). */
    function madrid(ms) {
        var f = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', weekday: 'short', hourCycle: 'h23' });
        var o = {};
        f.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = p.value; });
        return o;
    }
    function fechaCorta(ms) {
        var o = madrid(ms);
        var wd = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 }[o.weekday];
        return DIAS[wd] + ' ' + (+o.day) + ' ' + MESES[+o.month - 1] + ', ' + o.hour + ':' + o.minute;
    }
    function dentroDe(ms) {
        var min = Math.round(ms / 60000);
        if (min < 1) return 'menos de un minuto';
        if (min < 60) return min + ' min';
        var h = Math.floor(min / 60), d = Math.floor(h / 24);
        if (d >= 1) return d + (d === 1 ? ' día' : ' días') + (h % 24 ? ' y ' + (h % 24) + ' h' : '');
        return h + ' h' + (min % 60 ? ' ' + (min % 60) + ' min' : '');
    }
    function pararTodo() {
        [menuTimer, torTimer, partTimer].forEach(function (t) { if (t) clearInterval(t); });
        if (relojTimer) clearInterval(relojTimer);
        menuTimer = torTimer = partTimer = relojTimer = null;
    }

    /* Ventanita de confirmación (Win98, en vez de confirm()). */
    var confFn = null;
    function confirmar(titulo, html, si, fn) {
        $('az-conf-tit').textContent = titulo;
        $('az-conf-txt').innerHTML = html;
        $('az-conf-si').textContent = si || 'Aceptar';
        confFn = fn;
        $('az-conf').classList.add('on');
    }
    function cerrarConf() { $('az-conf').classList.remove('on'); confFn = null; }
    $('az-conf-si').addEventListener('click', function () { var f = confFn; cerrarConf(); if (f) f(); });
    $('az-conf-no').addEventListener('click', cerrarConf);
    $('az-conf-x').addEventListener('click', cerrarConf);

    /* ── Piezas (SVG, 100×100) ── */
    var BASE = '<path class="c" d="M28 73h44v7H28z"/><rect class="c" x="22" y="80" width="56" height="8" rx="3"/>';
    var FORMAS = {
        p: '<path class="c" d="M38 47c1 11-3 19-9 26h42c-6-7-10-15-9-26z"/><ellipse class="c" cx="50" cy="45" rx="14" ry="4.5"/><circle class="c" cx="50" cy="29" r="11.5"/>',
        r: '<path class="c" d="M33 73l3-33h28l3 33z"/><path class="c" d="M30 34h40v7H30z"/><path class="c" d="M28 34V17h9v6h8v-6h10v6h8v-6h9v17z"/>',
        n: '<path class="c" d="M66 73c2-16 6-30 0-43-4-8-11-12-17-12l-3-7-6 9c-7 4-11 12-13 20-2 6-4 10-2 14 2 5 9 5 13 1 4-2 8-4 12-4-4 6-12 12-14 22z"/>' +
           '<circle class="d" cx="41" cy="31" r="2.6"/><circle class="d" cx="28.5" cy="47.5" r="1.6"/><path class="l" d="M60 27c5 10 5 24 1 38"/>',
        b: '<path class="c" d="M37 73c-1-9 1-15 7-19h12c6 4 8 10 7 19z"/><ellipse class="c" cx="50" cy="52" rx="11" ry="3.8"/>' +
           '<path class="c" d="M50 17c-14 9-16 25-8 32h16c8-7 6-23-8-32z"/><circle class="c" cx="50" cy="13" r="4.2"/><path class="l" d="M55 26l-8 12"/>',
        q: '<path class="c" d="M30 64l-6-32 12 18 1-26 8 22 5-26 5 26 8-22 1 26 12-18-6 32z"/><rect class="c" x="29" y="63" width="42" height="10" rx="2"/>' +
           '<circle class="c" cx="24" cy="29" r="4"/><circle class="c" cx="37" cy="21" r="4"/><circle class="c" cx="50" cy="17" r="4"/><circle class="c" cx="63" cy="21" r="4"/><circle class="c" cx="76" cy="29" r="4"/>',
        k: '<path class="c" d="M32 73c-6-15-3-28 10-29h16c13 1 16 14 10 29z"/><rect class="c" x="35" y="38" width="30" height="7" rx="2"/>' +
           '<path class="c" d="M46 11h8v8h8v8h-8v11h-8V27h-8v-8h8z"/><path class="l" d="M38 58h24"/>'
    };
    /* letra del FEN → <svg>; blancas en mayúscula. Los colores van como
       atributos de cada trazo: un <style> dentro del SVG vale para toda la
       página y todas las piezas salían del color de la última. */
    function pieza(letra) {
        var w = letra === letra.toUpperCase();
        var f = w ? '#f6f6f6' : '#2a2a2a', s = w ? '#161616' : '#000', d = w ? '#161616' : '#e6e6e6';
        var trazos = (FORMAS[letra.toLowerCase()] + BASE)
            .replace(/class="c"/g, 'fill="' + f + '" stroke="' + s + '" stroke-width="3.5" stroke-linejoin="round"')
            .replace(/class="d"/g, 'fill="' + d + '"')
            .replace(/class="l"/g, 'fill="none" stroke="' + d + '" stroke-width="3" stroke-linecap="round"');
        return '<svg class="az-pz ' + (w ? 'b' : 'n') + '" viewBox="0 0 100 100" aria-hidden="true">' + trazos + '</svg>';
    }
    $('az-card-ic').innerHTML = pieza('N');

    /* ── Tablero (64 letras, orden del FEN: 0 = a8) ── */
    function tableroDe(fen) {
        var out = '';
        fen.split(' ')[0].split('/').forEach(function (fila) {
            for (var i = 0; i < fila.length; i++) {
                var c = fila[i];
                out += /[1-8]/.test(c) ? new Array(+c + 1).join('.') : c;
            }
        });
        return out;
    }
    function sqNombre(s) { return 'abcdefgh'[s & 7] + (8 - (s >> 3)); }
    function sqDe(n) { return (8 - (+n[1])) * 8 + ('abcdefgh'.indexOf(n[0])); }
    /* La jugada vista en el tablero mientras contesta el servidor
       (enroque, al paso y coronación incluidos). */
    function moverLocal(b, uci) {
        var a = b.split(''), de = sqDe(uci.slice(0, 2)), en = sqDe(uci.slice(2, 4)), p = a[de];
        var w = p === p.toUpperCase();
        if (p.toLowerCase() === 'p' && (de & 7) !== (en & 7) && a[en] === '.') a[(de >> 3) * 8 + (en & 7)] = '.';
        if (p.toLowerCase() === 'k' && Math.abs((en & 7) - (de & 7)) === 2) {
            var t = { 62: [63, 61], 58: [56, 59], 6: [7, 5], 2: [0, 3] }[en];
            a[t[1]] = a[t[0]]; a[t[0]] = '.';
        }
        a[en] = uci.length > 4 ? (w ? uci[4].toUpperCase() : uci[4]) : p;
        a[de] = '.';
        return a.join('');
    }

    /* ── Menú ── */
    function abrirMenu() {
        pararTodo();
        vista = 'menu';
        JG.show('jg-ajz');
        cargarMenu();
        menuTimer = setInterval(cargarMenu, 3000);
        JG.api('ajz-amigos').then(function (r) {
            var s = $('az-amigo'), prev = s.value;
            if (!r || !r.ok) return;
            s.innerHTML = r.amigos.length ? '' : '<option value="">Todavía no tienes amigos</option>';
            r.amigos.forEach(function (u) {
                var o = document.createElement('option');
                o.value = u.key; o.textContent = u.nombre;
                s.appendChild(o);
            });
            if (prev && r.amigos.some(function (u) { return u.key === prev; })) s.value = prev;
            s.disabled = !r.amigos.length;
            $('az-retar').disabled = !r.amigos.length;
        }).catch(function () {});
    }

    function cargarMenu() {
        return JG.api('ajz-estado').then(function (r) {
            if (!r || !r.ok || vista !== 'menu') return;
            estado = r;
            pintaMenu();
            /* Reto aceptado / ronda de torneo sin abrir: se entra solo. */
            var nueva = r.partidas.filter(function (p) { return p.nueva && !autoAbiertas[p.id]; })[0];
            if (nueva) { autoAbiertas[nueva.id] = 1; abrirPartida(nueva.id, 'menu'); }
        }).catch(function () {});
    }

    function pintaSaldo(n) {
        ['az-saldo', 'azc-saldo'].forEach(function (id) { $(id).innerHTML = 'Tienes ' + pts(n); });
        $('az-apuesta-pt').innerHTML = JG.PT;
        Array.prototype.forEach.call(document.querySelectorAll('.azc-pt'), function (e) { e.innerHTML = JG.PT; });
    }

    function pintaMenu() {
        var r = estado;
        pintaSaldo(r.autismo);
        var box = $('az-mios');
        box.innerHTML = '';
        var fila = function (u, titulo, sub, botones) {
            var d = document.createElement('div');
            d.className = 'az-item';
            d.innerHTML = avatar(u) + '<div class="az-item-t"><b>' + titulo + '</b><div class="az-item-sub">' + sub + '</div></div>';
            var bs = document.createElement('div'); bs.className = 'az-item-btns';
            (botones || []).forEach(function (b) { bs.appendChild(b); });
            d.appendChild(bs);
            box.appendChild(d);
        };
        var apuestaTxt = function (a) { return a > 0 ? ' · apuesta ' + pts(a) : ' · sin apuesta'; };
        r.partidas.forEach(function (p) {
            var nom = p.rival ? esc(p.rival.nombre) : '?';
            fila(p.rival, 'Contra ' + nom, p.ritmo + (p.torneo ? ' · torneo' : apuestaTxt(p.apuesta)) + (p.estado === 'esperando' ? ' · empezando' : ' · en juego'),
                 [boton(p.estado === 'esperando' ? 'Jugar' : 'Volver', function () { abrirPartida(p.id, 'menu'); })]);
        });
        r.recibidos.forEach(function (x) {
            var nom = x.de ? esc(x.de.nombre) : '?';
            fila(x.de, nom + ' te reta', x.ritmo + apuestaTxt(x.apuesta) + ' · caduca en ' + mmss(x.quedan),
                 [boton('Rechazar', function (b) { responder(x.id, false, b); }),
                  boton('Aceptar', function (b) { responder(x.id, true, b); })]);
        });
        r.enviados.forEach(function (x) {
            var nom = x.a ? esc(x.a.nombre) : '?';
            if (x.estado === 'pendiente') {
                fila(x.a, 'Has retado a ' + nom, x.ritmo + apuestaTxt(x.apuesta) + ' · esperando respuesta',
                     [boton('Cancelar', function (b) { b.disabled = true; JG.api('ajz-reto-cancelar', { id: x.id }).then(cargarMenu); })]);
            } else {
                fila(x.a, x.estado === 'rechazado' ? nom + ' ha rechazado tu reto' : 'Tu reto a ' + nom + ' ha caducado',
                     x.apuesta > 0 ? 'Se te ha devuelto la apuesta' : x.ritmo, []);
            }
        });
        if (!box.children.length) box.innerHTML = '<div class="az-vacio">No tienes partidas ni retos pendientes.</div>';
        pintaListaTorneos();
        $('jg-ajz-state').textContent = r.recibidos.length ? r.recibidos.length + (r.recibidos.length === 1 ? ' reto' : ' retos') : '';
    }

    function pintaListaTorneos() {
        var box = $('az-torneos');
        box.innerHTML = '';
        estado.torneos.forEach(function (t) {
            var d = document.createElement('div');
            d.className = 'az-item az-tor';
            var badge = { inscripcion: t.apuntado ? 'Apuntado' : 'Abierto', jugando: 'En juego', terminado: 'Terminado', cancelado: 'Cancelado' }[t.estado];
            var gris = t.estado === 'terminado' || t.estado === 'cancelado';
            d.innerHTML = '<div class="az-item-t"><b>' + esc(t.nombre) + '</b>' +
                '<div class="az-item-sub">Organiza ' + esc(t.host ? t.host.nombre : '?') + ' · ' + fechaCorta(t.empieza) + ' · ' + t.ritmo +
                ' · ' + t.apuntados + '/' + t.plazas + ' jugadores</div>' +
                '<div class="az-item-sub">Entrada ' + (t.entrada > 0 ? pts(t.entrada) : 'gratis') +
                ' · Premios ' + t.premios[0] + ' / ' + t.premios[1] + ' / ' + t.premios[2] + ' ' + JG.PT + '</div></div>' +
                '<span class="az-badge' + (gris ? ' gris' : '') + '">' + badge + '</span>';
            d.addEventListener('click', function () { abrirTorneo(t.id); });
            box.appendChild(d);
        });
        if (!box.children.length) box.innerHTML = '<div class="az-vacio">No hay torneos. ¡Crea uno!</div>';
    }

    function responder(id, aceptar, b) {
        b.disabled = true;
        JG.api('ajz-reto-responder', { id: id, aceptar: aceptar }).then(function (r) {
            if (!r || !r.ok) { $('az-retar-msg').textContent = (r && r.error) || 'No se ha podido'; cargarMenu(); return; }
            if (r.partida) { autoAbiertas[r.partida] = 1; abrirPartida(r.partida, 'menu'); }
            else cargarMenu();
        }).catch(function () { b.disabled = false; });
    }

    $('az-retar').addEventListener('click', function () {
        var msg = $('az-retar-msg');
        msg.textContent = '';
        var amigo = $('az-amigo').value;
        if (!amigo) { msg.textContent = 'Elige un amigo'; return; }
        var b = $('az-retar');
        b.disabled = true;
        JG.api('ajz-retar', { amigo: amigo, min: +$('az-min').value, inc: +$('az-inc').value, apuesta: +$('az-apuesta').value || 0 })
            .then(function (r) {
                b.disabled = false;
                if (!r || !r.ok) { msg.textContent = (r && r.error) || 'No se ha podido retar'; return; }
                cargarMenu();
            }).catch(function () { b.disabled = false; msg.textContent = 'Sin conexión'; });
    });

    /* ── Crear torneo ── */
    function abrirCrear() {
        pararTodo();
        vista = 'crear';
        JG.show('jg-ajz-crear');
        $('azc-msg').textContent = '';
        if (!$('azc-empieza').value) {
            /* Por defecto, dentro de una hora (redondeado al cuarto). */
            var ms = Math.ceil((Date.now() + 3600000) / 900000) * 900000, o = madrid(ms);
            $('azc-empieza').value = o.year + '-' + o.month + '-' + o.day + 'T' + o.hour + ':' + o.minute;
        }
        if (estado) pintaSaldo(estado.autismo);
        coste();
    }
    function coste() {
        var p = ['azc-p1', 'azc-p2', 'azc-p3'].reduce(function (s, id) { return s + Math.max(0, +$(id).value || 0); }, 0);
        $('azc-coste').innerHTML = p > 0 ? 'Al crearlo se te quitan <b>' + p + '</b> ' + JG.PT + ' para los premios.' : 'Sin premios: crearlo no te cuesta nada.';
    }
    ['azc-p1', 'azc-p2', 'azc-p3'].forEach(function (id) { $(id).addEventListener('input', coste); });
    $('azc-crear').addEventListener('click', function () {
        var b = $('azc-crear'), msg = $('azc-msg');
        msg.textContent = '';
        b.disabled = true;
        JG.api('ajz-torneo-crear', {
            nombre: $('azc-nombre').value, plazas: +$('azc-plazas').value, min: +$('azc-min').value, inc: +$('azc-inc').value,
            empieza: $('azc-empieza').value, entrada: +$('azc-entrada').value || 0,
            premio1: +$('azc-p1').value || 0, premio2: +$('azc-p2').value || 0, premio3: +$('azc-p3').value || 0
        }).then(function (r) {
            b.disabled = false;
            if (!r || !r.ok) { msg.textContent = (r && r.error) || 'No se ha podido crear'; return; }
            $('azc-nombre').value = '';
            abrirTorneo(r.torneo);
        }).catch(function () { b.disabled = false; msg.textContent = 'Sin conexión'; });
    });

    /* ── Torneo ── */
    function abrirTorneo(id) {
        pararTodo();
        vista = 'torneo';
        torneoId = id;
        JG.show('jg-ajz-torneo');
        $('azt-body').innerHTML = '<div class="az-vacio">Cargando…</div>';
        cargarTorneo();
        torTimer = setInterval(cargarTorneo, 3000);
    }
    function cargarTorneo() {
        var id = torneoId;
        return JG.api('ajz-torneo&id=' + id).then(function (r) {
            if (vista !== 'torneo' || torneoId !== id) return;
            if (!r || !r.ok) { $('azt-body').innerHTML = '<div class="az-vacio">' + esc((r && r.error) || 'No se ha podido cargar') + '</div>'; return; }
            torneo = r.torneo;
            if (estado) estado.autismo = r.autismo;
            pintaTorneo(r.autismo);
            /* Ronda nueva: a tu partida sin tocar nada. */
            if (torneo.miPartida && !autoAbiertas[torneo.miPartida]) {
                autoAbiertas[torneo.miPartida] = 1;
                abrirPartida(torneo.miPartida, 'torneo');
            }
        }).catch(function () {});
    }

    function pintaTorneo(saldo) {
        var t = torneo;
        $('azt-title').textContent = t.nombre;
        $('azt-sub').innerHTML = 'Organiza ' + esc(t.host ? t.host.nombre : '?') + ' · tienes ' + pts(saldo);
        var h = '';
        h += '<div class="az-tor-campos">' +
            '<div class="bm-campo"><small>Empieza</small><b>' + fechaCorta(t.empieza) + '</b></div>' +
            '<div class="bm-campo"><small>Ritmo</small><b>' + t.ritmo + '</b></div>' +
            '<div class="bm-campo"><small>Jugadores</small><b>' + t.apuntados + '/' + t.plazas + '</b></div>' +
            '<div class="bm-campo"><small>Entrada</small><b>' + (t.entrada > 0 ? t.entrada : 'Gratis') + '</b>' + (t.entrada > 0 ? JG.PT : '') + '</div>' +
            '<div class="bm-campo ancho"><small>Premios</small><b class="az-pod">' +
                '<span><i class="bm-med m1">1</i>' + t.premios[0] + '</span><span><i class="bm-med m2">2</i>' + t.premios[1] +
                '</span><span><i class="bm-med m3">3</i>' + t.premios[2] + '</span></b></div>' +
            '</div>';
        var est = '';
        if (t.estado === 'inscripcion') {
            var falta = t.empieza - t.ahora;
            est = 'Empieza dentro de ' + dentroDe(falta) + '. Si a esa hora no está lleno, se cancela y se devuelve todo.';
        } else if (t.estado === 'jugando') est = 'En juego · ' + t.rondaNombre;
        else if (t.estado === 'terminado') est = 'Terminado';
        else est = t.motivo === 'vacio' ? 'Cancelado: no se llenó a tiempo. Se ha devuelto todo.' : 'Cancelado por quien lo organizaba. Se ha devuelto todo.';
        h += '<section class="bm-win"><div class="bm-win-bar"><span>' + esc(est) + '</span></div><div class="bm-win-body">';
        if (t.podio) {
            h += '<div class="az-pod" style="margin-bottom:8px">' + t.podio.map(function (u, i) {
                return u ? '<span><i class="bm-med m' + (i + 1) + '">' + (i + 1) + '</i>' + avatar(u) + ' <b>' + esc(u.nombre) + '</b> +' + pts(t.premios[i]) + '</span>' : '';
            }).join('') + '</div>';
        }
        h += '<div class="az-acc" id="azt-acc" style="justify-content:flex-start"></div><div class="az-err" id="azt-msg" style="margin-top:4px"></div></div></section>';
        if (t.cuadro.length) h += '<section class="bm-win"><div class="bm-win-bar"><span>Cuadro</span></div><div class="bm-win-body"><div class="az-cuadro">' + cuadroHtml(t) + '</div></div></section>';
        h += '<section class="bm-win"><div class="bm-win-bar"><span>Apuntados (' + t.apuntados + '/' + t.plazas + ')</span></div><div class="bm-win-body"><div class="az-apuntados">' +
            (t.lista.length ? t.lista.map(function (u) { return '<span>' + avatar(u) + esc(u.nombre) + '</span>'; }).join('') : '<span class="az-vacio">Todavía no hay nadie.</span>') +
            '</div></div></section>';
        var prevMsg = $('azt-msg') ? $('azt-msg').textContent : '';
        var body = $('azt-body'), top = body.scrollTop, cq = body.querySelector('.az-cuadro'), izq = cq ? cq.scrollLeft : 0;
        body.innerHTML = h;
        body.scrollTop = top;
        if (body.querySelector('.az-cuadro')) body.querySelector('.az-cuadro').scrollLeft = izq;
        $('azt-msg').textContent = prevMsg;
        var acc = $('azt-acc');
        var hacer = function (accion, b) {
            b.disabled = true;
            JG.api(accion, { id: t.id }).then(function (r) {
                $('azt-msg').textContent = r && r.ok ? '' : ((r && r.error) || 'No se ha podido');
                cargarTorneo();
            }).catch(function () { b.disabled = false; });
        };
        if (t.miPartida) acc.appendChild(boton('Ir a mi partida', function () { abrirPartida(t.miPartida, 'torneo'); }));
        if (t.estado === 'inscripcion') {
            if (!t.apuntado && t.apuntados < t.plazas) {
                var precio = t.soyHost || t.entrada <= 0 ? 'Apuntarme' : 'Apuntarme (' + t.entrada + ' puntos)';
                acc.appendChild(boton(precio, function (b) { hacer('ajz-torneo-apuntarse', b); }));
            }
            if (t.apuntado) acc.appendChild(boton('Borrarme', function (b) { hacer('ajz-torneo-salir', b); }));
            if (t.soyHost) acc.appendChild(boton('Cancelar torneo', function (b) {
                confirmar('Cancelar torneo', '¿Cancelar <b>' + esc(t.nombre) + '</b>? Se devuelven las entradas y los premios.', 'Cancelar torneo',
                          function () { hacer('ajz-torneo-cancelar', b); });
            }));
        }
        if (!acc.children.length) acc.style.display = 'none';
    }

    function cuadroHtml(t) {
        var porRonda = {};
        t.cuadro.forEach(function (c) { (porRonda[c.ronda] = porRonda[c.ronda] || []).push(c); });
        var yo = t.yoKey || null;
        var html = '';
        for (var r = 1; r <= t.rondas; r++) {
            var cs = porRonda[r] || [];
            var n = r < t.rondas ? t.plazas / Math.pow(2, r) : 1;
            html += '<div class="az-ronda"><div class="az-ronda-tit">' + esc(nombreRonda(r, t.rondas)) + '</div><div class="az-ronda-cr">';
            for (var s = 0; s < n; s++) html += cruceHtml(cs.filter(function (c) { return c.slot === s; })[0], yo);
            if (r === t.rondas) {
                html += '<div class="az-ronda-tit" style="margin-top:6px">3.er puesto</div>' + cruceHtml(cs.filter(function (c) { return c.slot === 1; })[0], yo);
            }
            html += '</div></div>';
        }
        return html;
    }
    function nombreRonda(r, total) {
        var f = total - r;
        return f === 0 ? 'Final' : (['Semifinales', 'Cuartos', 'Octavos', 'Dieciseisavos'][f - 1] || 'Ronda ' + r);
    }
    function cruceHtml(c, yo) {
        if (!c) return '<div class="az-cruce"><div class="az-cr-j"><span class="az-vacio">—</span></div><div class="az-cr-j"><span class="az-vacio">—</span></div></div>';
        var j = function (u, lado) {
            var cls = c.ganador ? (c.ganador === lado ? ' gana' : ' pierde') : '';
            if (u && yo && u.key === yo) cls += ' yo';
            return '<div class="az-cr-j' + cls + '">' + avatar(u, 18) + '<span class="az-cr-nom">' + esc(u ? u.nombre : '?') + '</span></div>';
        };
        var est = c.estado === 'hecho' ? '' : (c.estado === 'jugando' ? 'Jugando' : 'Empezando');
        if (c.estado !== 'hecho' && c.juegos > 1) est += ' · desempate ' + (c.juegos - 1);
        return '<div class="az-cruce">' + j(c.a, 'a') + j(c.b, 'b') + (est ? '<div class="az-cr-est">' + est + '</div>' : '') + '</div>';
    }

    /* ── Partida ── */
    function abrirPartida(id, desde) {
        pararTodo();
        vista = 'partida';
        volverA = desde || 'menu';
        partida = null; sel = -1; enviando = false;
        pintadoId = 0; firmaTablero = ''; jugadasPintadas = -1;
        version++;
        JG.show('jg-ajz-partida');
        $('azp-board').innerHTML = '';
        $('azp-fin').classList.remove('on');
        $('azp-estado').textContent = 'Cargando…';
        pedirPartida(id);
        partTimer = setInterval(function () { pedirPartida(id); }, 1000);
        relojTimer = setInterval(pintaRelojes, 100);
    }
    function pedirPartida(id) {
        var v = version;
        return JG.api('ajz-partida&id=' + id).then(function (r) {
            if (vista !== 'partida' || v !== version || enviando) return;
            if (!r || !r.ok) { $('azp-estado').textContent = (r && r.error) || 'No se ha podido cargar'; return; }
            recibir(r.partida);
        }).catch(function () {});
    }
    function recibir(p) {
        var antes = partida;
        partida = p;
        recibida = Date.now();
        /* Si ha cambiado la posición, lo elegido ya no vale. */
        if (!antes || antes.n !== p.n || antes.estado !== p.estado) sel = -1;
        pintaPartida();
        if (p.estado === 'terminada' || p.estado === 'anulada') {
            if (partTimer) { clearInterval(partTimer); partTimer = null; }
        }
    }

    function pintaPartida() {
        var p = partida;
        var rival = p.yo === 'b' ? p.blanco : p.negro, yo = p.yo === 'b' ? p.negro : p.blanco;
        $('azp-title').textContent = p.torneo ? p.torneo.nombre : 'Ajedrez';
        $('azp-sub').innerHTML = p.torneo ? esc(p.torneo.ronda) + ' · ' + p.ritmo : p.ritmo + (p.apuesta > 0 ? ' · apuesta ' + pts(p.apuesta) : ' · sin apuesta');
        $('azp-back').textContent = volverA === 'torneo' ? '< Torneo' : '< Ajedrez';
        var jug = function (u, color) {
            return avatar(u) + '<div class="az-jug-nom">' + esc(u ? u.nombre : '?') + '<small>' + (color === 'w' ? 'Blancas' : 'Negras') + '</small></div>' +
                '<div class="az-reloj" data-c="' + color + '"></div>';
        };
        if (pintadoId !== p.id) {
            pintadoId = p.id;
            $('azp-rival').innerHTML = jug(rival, p.yo === 'b' ? 'w' : 'b');
            $('azp-yo').innerHTML = jug(yo, p.yo === 'b' ? 'b' : 'w');
        }
        /* El tablero, si ha cambiado algo (y nunca a media pieza arrastrada). */
        var firma = p.fen + '|' + p.estado + '|' + sel + '|' + p.legales.length;
        if (firma !== firmaTablero && !arr) pintaTablero();
        if (p.sans.length !== jugadasPintadas) { jugadasPintadas = p.sans.length; pintaJugadas(); }
        pintaRelojes();
        pintaEstado(rival);
    }

    var pintadoId = 0, firmaTablero = '', jugadasPintadas = -1;
    function pintaTablero() {
        var p = partida, b = tableroDe(p.fen), flip = p.yo === 'b';
        firmaTablero = p.fen + '|' + p.estado + '|' + sel + '|' + p.legales.length;
        var board = $('azp-board');
        var ult = p.ultima ? [sqDe(p.ultima.slice(0, 2)), sqDe(p.ultima.slice(2, 4))] : [];
        var jaque = p.jaque ? sqDe(p.jaque) : -1;
        var destinos = {};
        if (sel >= 0) p.legales.forEach(function (u) { if (sqDe(u.slice(0, 2)) === sel) destinos[sqDe(u.slice(2, 4))] = 1; });
        var origen = {};
        p.legales.forEach(function (u) { origen[sqDe(u.slice(0, 2))] = 1; });
        var h = '';
        for (var i = 0; i < 64; i++) {
            var s = flip ? 63 - i : i, r = s >> 3, c = s & 7;
            var cls = 'az-sq ' + ((r + c) % 2 ? 'os' : 'cl');
            if (ult.indexOf(s) >= 0) cls += ' ult';
            if (s === sel) cls += ' sel';
            if (s === jaque) cls += ' jaque';
            if (destinos[s]) cls += ' dest' + (b[s] !== '.' || (b[sel] && b[sel].toLowerCase() === 'p' && (sel & 7) !== c) ? ' come' : '');
            if (origen[s]) cls += ' mia';
            var coord = '';
            if (i % 8 === 0) coord += '<span class="az-coord r">' + (8 - r) + '</span>';
            if (i >= 56) coord += '<span class="az-coord f">' + 'abcdefgh'[c] + '</span>';
            h += '<div class="' + cls + '" data-s="' + s + '">' + coord + (b[s] !== '.' ? pieza(b[s]) : '') + '</div>';
        }
        board.innerHTML = h;
        pintaCapa();
    }

    /* Encima del tablero: esperando a que entre el rival. */
    function pintaCapa() {
        var p = partida, wrap = $('azp-wrap');
        var vieja = wrap.querySelector('.az-capa:not(.coronar)');
        if (p.estado !== 'esperando') { if (vieja) vieja.remove(); return; }
        if (vieja) return;
        var rival = p.yo === 'b' ? p.blanco : p.negro;
        var capa = document.createElement('div');
        capa.className = 'az-capa';
        capa.innerHTML = '<div class="window"><div class="title-bar"><div class="title-bar-text">Empezando</div></div><div class="window-body">' +
            'Esperando a <b>' + esc(rival ? rival.nombre : '?') + '</b>…<br>Si no entra en <b class="az-quedan"></b>, ' +
            (p.torneo ? 'gana quien está.' : 'se anula y se devuelve la apuesta.') + '</div></div>';
        wrap.appendChild(capa);
        pintaRelojes();
    }

    function pintaJugadas() {
        var p = partida, box = $('azp-jugadas'), h = '';
        for (var i = 0; i < p.sans.length; i += 2) {
            var ulti = p.sans.length - 1;
            h += '<div class="az-jg"><i>' + (i / 2 + 1) + '.</i><span' + (i === ulti ? ' class="ult"' : '') + '>' + esc(p.sans[i]) + '</span><span' +
                (i + 1 === ulti ? ' class="ult"' : '') + '>' + esc(p.sans[i + 1] || '') + '</span></div>';
        }
        box.innerHTML = h || '<div class="az-vacio">Sin jugadas todavía</div>';
        box.scrollTop = box.scrollHeight;
    }

    function pintaRelojes() {
        var p = partida;
        if (!p || vista !== 'partida') return;
        var pasado = Date.now() - recibida;
        Array.prototype.forEach.call(document.querySelectorAll('#jg-ajz-partida .az-reloj'), function (el) {
            var c = el.dataset.c, ms = p.reloj[c];
            if (p.reloj.corre === c) ms -= pasado;
            el.textContent = mmss(ms, true);
            el.classList.toggle('corre', p.reloj.corre === c);
            el.classList.toggle('urge', ms < 20000 && p.estado === 'jugando');
        });
        var q = document.querySelector('#azp-wrap .az-quedan');
        if (q && p.esperando) q.textContent = mmss(p.esperando.quedan - pasado);
        var f = document.querySelector('#azp-aviso .az-fuera');
        if (f && p.rivalFuera != null) f.textContent = Math.max(0, Math.ceil((p.rivalFuera - pasado) / 1000));
    }

    var MOTIVOS = {
        mate: 'por jaque mate', tiempo: 'por tiempo', rendicion: 'por rendición', abandono: 'por abandono',
        'no-presentado': 'porque no se presentó', nadie: 'no se presentó nadie: pasa quien se apuntó antes',
        ahogado: 'por rey ahogado', material: 'por falta de material', 'tiempo-material': 'se acabó el tiempo, pero no había material para dar mate',
        repeticion: 'por triple repetición', '50': 'por la regla de las 50 jugadas', acuerdo: 'de mutuo acuerdo'
    };

    function pintaEstado(rival) {
        var p = partida, est = $('azp-estado'), aviso = $('azp-aviso'), fin = $('azp-fin');
        var nomR = esc(rival ? rival.nombre : 'tu rival');
        aviso.innerHTML = '';
        var activa = p.estado === 'jugando' || p.estado === 'esperando';
        $('azp-btns').style.display = activa ? '' : 'none';
        $('azp-tablas').disabled = p.estado !== 'jugando' || p.tablas === 'yo';
        $('azp-tablas').textContent = p.tablas === 'yo' ? 'Tablas ofrecidas' : 'Ofrecer tablas';
        if (p.estado === 'esperando') est.textContent = 'Esperando a ' + (rival ? rival.nombre : 'tu rival');
        else if (p.estado === 'jugando') est.textContent = (p.turno === p.yo ? 'Te toca' : 'Le toca a ' + (rival ? rival.nombre : 'tu rival')) + (p.jaque ? ' · ¡Jaque!' : '');
        else est.textContent = '';
        if (p.estado === 'jugando' && p.tablas === 'rival') {
            aviso.innerHTML = '<div class="az-aviso"><b>' + nomR + '</b> ofrece tablas<div class="az-btns" style="margin-top:4px"></div></div>';
            var bs = aviso.querySelector('.az-btns');
            bs.appendChild(boton('Rechazar', function () { tablas('rechazar'); }));
            bs.appendChild(boton('Aceptar', function () { tablas('aceptar'); }));
        } else if (p.estado === 'jugando' && p.rivalFuera != null) {
            aviso.innerHTML = '<div class="az-aviso"><b>' + nomR + '</b> se ha desconectado.<br>Si no vuelve en <b class="az-fuera"></b> s, ganas.</div>';
        }
        if (activa) { fin.classList.remove('on'); return; }
        var h;
        if (p.estado === 'anulada') {
            h = '<b>Partida anulada</b><br>' + nomR + ' no entró a tiempo.' + (p.apuesta > 0 ? '<br>Se ha devuelto la apuesta.' : '');
        } else {
            var gano = (p.resultado === '1-0' && p.yo === 'w') || (p.resultado === '0-1' && p.yo === 'b');
            var titulo = p.resultado === '1/2' ? 'Tablas' : (gano ? '¡Has ganado!' : 'Has perdido');
            h = '<b>' + titulo + '</b><br>' + esc(MOTIVOS[p.motivo] || '').replace(/^./, function (c) { return c.toUpperCase(); });
            if (p.puntos > 0) h += '<br>+' + pts(p.puntos * 2) + ' (la apuesta de los dos)';
            else if (p.puntos < 0) h += '<br>Pierdes los ' + pts(-p.puntos) + ' de la apuesta';
            else if (p.apuesta > 0 && p.resultado === '1/2') h += '<br>Cada uno recupera su apuesta';
            if (p.torneo && p.resultado === '1/2') h += '<br>Se juega otra con los colores cambiados.';
        }
        fin.innerHTML = h + '<div class="az-btns" style="margin-top:6px"></div>';
        var bs2 = fin.querySelector('.az-btns');
        if (p.torneo) bs2.appendChild(boton('Ver el torneo', function () { abrirTorneo(p.torneo.id); }));
        else bs2.appendChild(boton('Volver', volver));
        fin.classList.add('on');
    }

    function volver() {
        if (volverA === 'torneo' && partida && partida.torneo) abrirTorneo(partida.torneo.id);
        else abrirMenu();
    }

    function tablas(tipo) {
        if (!partida) return;
        version++;
        JG.api('ajz-tablas', { id: partida.id, tipo: tipo }).then(function (r) { if (r && r.ok && vista === 'partida') recibir(r.partida); });
    }
    $('azp-tablas').addEventListener('click', function () { tablas('ofrecer'); });
    $('azp-rendirse').addEventListener('click', function () {
        if (!partida) return;
        var id = partida.id;
        confirmar('Rendirse', '¿Seguro que te rindes?' + (partida.apuesta > 0 ? ' Perderás los ' + pts(partida.apuesta) + ' de la apuesta.' : ''), 'Rendirse', function () {
            version++;
            JG.api('ajz-rendirse', { id: id }).then(function (r) { if (r && r.ok && vista === 'partida') recibir(r.partida); });
        });
    });
    $('azp-back').addEventListener('click', volver);

    /* ── Mover: clic-clic o arrastrar ── */
    function opcionesDe(de, a) {
        return (partida.legales || []).filter(function (u) { return sqDe(u.slice(0, 2)) === de && sqDe(u.slice(2, 4)) === a; });
    }
    function intentar(de, a) {
        var ops = opcionesDe(de, a);
        if (!ops.length) return false;
        if (ops.length > 1) { elegirCoronacion(de, a); return true; }
        jugar(ops[0]);
        return true;
    }
    function elegirCoronacion(de, a) {
        var wrap = $('azp-wrap'), blanca = partida.yo === 'w';
        var capa = document.createElement('div');
        capa.className = 'az-capa coronar';
        capa.innerHTML = '<div class="window"><div class="title-bar"><div class="title-bar-text">Coronar</div></div><div class="window-body"><div class="az-coronar"></div></div></div>';
        var fila = capa.querySelector('.az-coronar');
        ['q', 'r', 'b', 'n'].forEach(function (x) {
            var b = boton('', function () { capa.remove(); jugar(sqNombre(de) + sqNombre(a) + x); });
            b.innerHTML = pieza(blanca ? x.toUpperCase() : x);
            fila.appendChild(b);
        });
        capa.addEventListener('click', function (e) { if (e.target === capa) { capa.remove(); sel = -1; pintaTablero(); } });
        wrap.appendChild(capa);
    }
    function jugar(uci) {
        var p = partida;
        sel = -1;
        enviando = true;
        version++;
        /* Se ve ya movida; el reloj propio se para mientras contesta. */
        var tras = moverLocal(tableroDe(p.fen), uci);
        var n = p.n;
        var reloj = { w: p.reloj.w, b: p.reloj.b, corre: null };
        if (p.reloj.corre) reloj[p.reloj.corre] -= Date.now() - recibida;
        recibida = Date.now();
        partida = Object.assign({}, p, { fen: fenDe(tras, p.turno === 'w' ? 'b' : 'w'), ultima: uci, legales: [], jaque: null,
                                         turno: p.turno === 'w' ? 'b' : 'w', reloj: reloj });
        pintaTablero();
        JG.api('ajz-mover', { id: p.id, uci: uci, n: n }).then(function (r) {
            enviando = false;
            if (vista !== 'partida') return;
            if (!r || !r.ok) { partida = p; pintaTablero(); $('azp-estado').textContent = (r && r.error) || 'No se ha podido mover'; return; }
            recibir(r.partida);
        }).catch(function () { enviando = false; partida = p; pintaTablero(); $('azp-estado').textContent = 'Sin conexión, prueba otra vez'; });
    }
    /* FEN de mentira (solo para pintar mientras contesta el servidor). */
    function fenDe(b, turno) {
        var filas = [];
        for (var r = 0; r < 8; r++) {
            var f = '', v = 0;
            for (var c = 0; c < 8; c++) {
                var x = b[r * 8 + c];
                if (x === '.') { v++; continue; }
                if (v) { f += v; v = 0; }
                f += x;
            }
            filas.push(f + (v || ''));
        }
        return filas.join('/') + ' ' + turno + ' - - 0 1';
    }

    var arr = null;   /* arrastre: { de, x, y, fantasma, movido } */
    var board = $('azp-board');
    function casillaEn(x, y) {
        var el = document.elementFromPoint(x, y);
        el = el && el.closest ? el.closest('.az-sq') : null;
        return el && board.contains(el) ? +el.dataset.s : -1;
    }
    board.addEventListener('pointerdown', function (e) {
        if (!partida || e.button !== 0) return;
        var el = e.target.closest('.az-sq');
        if (!el) return;
        var s = +el.dataset.s;
        /* Con una pieza elegida, clic en un destino = jugar. */
        if (sel >= 0 && sel !== s && intentar(sel, s)) { e.preventDefault(); return; }
        var mueve = (partida.legales || []).some(function (u) { return sqDe(u.slice(0, 2)) === s; });
        if (!mueve) { if (sel >= 0) { sel = -1; pintaTablero(); } return; }
        e.preventDefault();
        var antes = sel;
        sel = s;
        pintaTablero();
        arr = { de: s, x: e.clientX, y: e.clientY, movido: false, deseleccionar: antes === s };
        board.setPointerCapture(e.pointerId);
    });
    board.addEventListener('pointermove', function (e) {
        if (!arr) return;
        if (!arr.movido && Math.abs(e.clientX - arr.x) + Math.abs(e.clientY - arr.y) < 4) return;
        if (!arr.movido) {
            arr.movido = true;
            var sq = board.querySelector('[data-s="' + arr.de + '"]');
            var pz = sq && sq.querySelector('.az-pz');
            if (!pz) { arr = null; return; }
            sq.classList.add('arrastre');
            arr.fantasma = document.createElement('div');
            arr.fantasma.className = 'az-fantasma';
            arr.fantasma.innerHTML = pz.outerHTML;
            document.body.appendChild(arr.fantasma);
        }
        arr.fantasma.style.left = e.clientX + 'px';
        arr.fantasma.style.top = e.clientY + 'px';
    });
    function soltar(e, cancelar) {
        if (!arr) return;
        var a = arr;
        arr = null;
        if (a.fantasma) a.fantasma.remove();
        if (!a.movido) {
            /* Clic sin arrastrar sobre la misma pieza ya elegida: soltarla. */
            if (a.deseleccionar) { sel = -1; pintaTablero(); }
            return;
        }
        var s = cancelar ? -1 : casillaEn(e.clientX, e.clientY);
        if (s >= 0 && s !== a.de && intentar(a.de, s)) return;
        pintaTablero();
    }
    board.addEventListener('pointerup', function (e) { soltar(e, false); });
    board.addEventListener('pointercancel', function (e) { soltar(e, true); });

    /* ── Abrir desde fuera (aviso del haro, enlace del anuncio) ── */
    function abrirDesde(txt) {
        var m = /^(partida|torneo):(\d+)$/.exec(txt || '');
        if (!m) return false;
        if (m[1] === 'partida') { autoAbiertas[+m[2]] = 1; abrirPartida(+m[2], 'menu'); }
        else abrirTorneo(+m[2]);
        return true;
    }
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data || e.data.type !== 'juegos-abrir') return;
        abrirDesde(String(e.data.abrir || ''));
    });
    /* Lo mira desktop-base.php para no sacar el aviso del haro de una
       partida que ya va a entrar sola (o en la que ya se está). */
    window.ajzViendo = function () {
        return { vista: vista, partida: vista === 'partida' && partida ? partida.id : 0, torneo: vista === 'torneo' ? torneoId : 0 };
    };

    /* Al salir del ajedrez (otra vista de Juegos) se paran las llamadas. */
    var obs = new MutationObserver(function () {
        var on = document.querySelector('.jg-view.on');
        var mio = on && /^jg-ajz/.test(on.id);
        if (!mio && vista) { pararTodo(); vista = null; partida = null; }
    });
    Array.prototype.forEach.call(document.querySelectorAll('.jg-view'), function (v) {
        obs.observe(v, { attributes: true, attributeFilter: ['class'] });
    });

    $('jg-open-ajz').addEventListener('click', abrirMenu);
    $('jg-open-ajz').addEventListener('keydown', function (e) { if (e.key === 'Enter') abrirMenu(); });
    $('az-back').addEventListener('click', function () { pararTodo(); vista = null; JG.show('jg-home'); });
    $('azc-back').addEventListener('click', abrirMenu);
    $('azt-back').addEventListener('click', abrirMenu);
    $('az-crear-btn').addEventListener('click', function (e) { e.stopPropagation(); abrirCrear(); });
    $('az-help').addEventListener('click', function () { $('az-modal').classList.add('on'); });
    $('az-modal-x').addEventListener('click', function () { $('az-modal').classList.remove('on'); });
    $('az-modal-ok').addEventListener('click', function () { $('az-modal').classList.remove('on'); });

    /* Rótulo de la portada (retos pendientes). */
    JG.api('ajz-estado').then(function (r) {
        if (r && r.ok) $('jg-ajz-state').textContent = r.recibidos.length ? r.recibidos.length + (r.recibidos.length === 1 ? ' reto' : ' retos') : '';
    }).catch(function () {});

    if (window.JG_ABRIR) abrirDesde(window.JG_ABRIR);
})();
