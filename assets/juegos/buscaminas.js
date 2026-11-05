/* ──────────────────────────────────────────────────────────────────────
   Buscaminas de la app Juegos (apps/juegos.php; servidor en
   assets/juegos/buscaminas.php, reglas allí).
   Tres modos:
     - dia: tableros del día. Cada clic va al servidor (bm-jugar), que es
       el que sabe dónde están las minas y mide el tiempo.
     - inf: modo infinito, igual que el del día pero por rondas con
       cuenta atrás; al pasar una ronda, Continuar o Retirarse.
     - libre: todo aquí, sin servidor ni puntos. El tablero se genera al
       primer clic, que nunca es mina (ni sus vecinas).
   El tablero se pinta siempre a partir de una "vista": una letra por
   casilla ('.' tapada, 'F' bandera, '0'…'8', 'M' mina, 'X' la que explotó,
   'W' bandera mal puesta), la misma que manda el servidor.
   Usa window.JG (api, show, fecha, PT) de apps/juegos.php.
   ────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';
    var JG = window.JG;
    if (!JG) return;
    var $ = JG.$;
    var NIVELES = {
        1: { nombre: 'Principiante', w: 9,  h: 9,  m: 10 },
        2: { nombre: 'Intermedio',   w: 16, h: 16, m: 40 },
        3: { nombre: 'Experto',      w: 30, h: 16, m: 99 }
    };

    var juego = null;      /* { modo, w, h, minas, vista, estado, … } */
    var cola = Promise.resolve();   /* clics al servidor, de uno en uno */
    var reloj = null;      /* setInterval del contador */
    var infAntes = 0;      /* lo acumulado antes de la ronda (para decir qué se pierde) */

    /* ── Utilidades ── */
    function tiempo(ms, decimas) {
        ms = Math.max(0, ms | 0);
        var s = Math.floor(ms / 1000);
        var t = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        return decimas ? t + '.' + Math.floor((ms % 1000) / 100) : t;
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function vecinas(i, w, h) {
        var x = i % w, y = Math.floor(i / w), out = [];
        for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) {
            if (!dx && !dy) continue;
            var nx = x + dx, ny = y + dy;
            if (nx >= 0 && ny >= 0 && nx < w && ny < h) out.push(ny * w + nx);
        }
        return out;
    }
    function contar(str, ch) { var n = 0; for (var i = 0; i < str.length; i++) if (str[i] === ch) n++; return n; }

    /* ── Menú ── */
    function abrirMenu() {
        JG.show('jg-bm');
        pararReloj();
        $('bm-dia-list').innerHTML = 'Cargando…';
        $('bm-inf-box').innerHTML = '';
        JG.api('bm-dia').then(function (r) {
            if (!r || !r.ok) { $('bm-dia-list').textContent = 'No se ha podido cargar'; return; }
            $('bm-date').textContent = JG.fecha(r.dia);
            pintaDia(r);
            estadoPortada(r);
        }).catch(function () { $('bm-dia-list').textContent = 'Sin conexión'; });
        JG.api('bm-inf').then(function (r) { if (r && r.ok) pintaInf(r.infinito); });
    }

    function pintaDia(r) {
        var box = $('bm-dia-list');
        box.innerHTML = '';
        r.tableros.forEach(function (t) {
            var row = document.createElement('div');
            row.className = 'bm-row';
            var est = '';
            var btn = 'Jugar';
            if (t.estado === 'jugando') { est = 'A medias'; btn = 'Continuar'; }
            else if (t.estado === 'ganada') { est = tiempo(t.ms, true) + ' · ' + t.puesto + '.º · +' + t.puntos; btn = 'Ver'; }
            else if (t.estado === 'perdida') { est = 'Mina · +' + t.puntos; btn = 'Ver'; }
            else est = t.base + ' puntos';
            row.innerHTML = '<div class="bm-row-name"><b>' + esc(t.nombre) + '</b><br>' + t.w + '×' + t.h + ', ' + t.m + ' minas</div>' +
                '<div class="bm-row-state">' + esc(est) + '</div>';
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = btn;
            b.addEventListener('click', function () { abrirDia(t.nivel); });
            row.appendChild(b);
            box.appendChild(row);
        });
    }

    function pintaInf(inf) {
        var box = $('bm-inf-box');
        var s = inf.siguiente;
        var html = '<div class="bm-inf-line">Récord: <b>' + (inf.mejor ? 'ronda ' + inf.mejor : 'ninguno') + '</b>';
        if (inf.estado === 'jugando') html += '<br>Tienes una partida en la ronda ' + inf.ronda + ' (llevas ' + inf.pendientes + ' puntos).';
        else if (inf.estado === 'superada') html += '<br>Has superado la ronda ' + inf.ronda + ' y llevas <b>' + inf.pendientes + '</b> puntos.';
        html += '</div>';
        box.innerHTML = html;
        var btns = document.createElement('div');
        btns.className = 'bm-btns';
        if (inf.estado === 'jugando') btns.appendChild(boton('Seguir jugando', function () { jugarInf(inf); }));
        else if (inf.estado === 'superada') {
            btns.appendChild(boton('Ronda ' + s.ronda + ' (' + s.w + '×' + s.h + ', ' + s.seg + ' s)', function () { infContinuar(); }));
            btns.appendChild(boton('Retirarse con ' + inf.pendientes, function () { infRetirarse(); }));
        } else btns.appendChild(boton('Empezar', function () { infEmpezar(); }));
        box.appendChild(btns);
    }

    function boton(txt, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = txt;
        b.addEventListener('click', fn);
        return b;
    }

    function estadoPortada(r) {
        var hechos = r.tableros.filter(function (t) { return t.estado === 'ganada' || t.estado === 'perdida'; }).length;
        $('jg-bm-state').textContent = 'Hoy: ' + hechos + '/' + r.tableros.length;
    }

    /* ── Tablero ── */
    function tamCasilla(w, h) {
        var v = $('jg-bm-juego');
        var anchoLibre = v.clientWidth - 24 - 8;
        var altoLibre = v.clientHeight - 190;
        return Math.max(14, Math.min(26, Math.floor(anchoLibre / w), Math.floor(altoLibre / h)));
    }

    function montarTablero() {
        var b = $('bmj-board');
        b.innerHTML = '';
        var cs = tamCasilla(juego.w, juego.h);
        b.style.setProperty('--cs', cs + 'px');
        b.style.gridTemplateColumns = 'repeat(' + juego.w + ', ' + cs + 'px)';
        for (var i = 0; i < juego.w * juego.h; i++) {
            var c = document.createElement('i');
            c.dataset.i = i;
            b.appendChild(c);
        }
        pintar();
    }

    function pintar() {
        var celdas = $('bmj-board').children;
        var v = juego.vista;
        for (var i = 0; i < celdas.length; i++) {
            var ch = v[i], c = celdas[i], cls = 'bm-c';
            c.textContent = '';
            if (ch === '.') cls += ' t';
            else if (ch === 'F') cls += ' t bm-flag';
            else if (ch === 'M') cls += ' a bm-mina';
            else if (ch === 'X') cls += ' a x bm-mina';
            else if (ch === 'W') cls += ' a bm-mal';
            else { cls += ' a n' + ch; if (ch !== '0') c.textContent = ch; }
            if (juego.pulsada === i && ch === '.') cls += ' p';
            c.className = cls;
        }
        $('bmj-minas').textContent = String(juego.minas - contar(v, 'F'));
    }

    /* Clics: izquierdo destapa (o, sobre un número, abre las vecinas si
       tiene sus banderas); derecho pone o quita bandera. */
    function enClic(e, derecho) {
        var c = e.target.closest('.bm-c');
        if (!c || !juego || juego.estado !== 'jugando') return;
        e.preventDefault();
        var i = +c.dataset.i, ch = juego.vista[i];
        if (derecho) {
            if (ch !== '.' && ch !== 'F') return;
            /* Al momento en pantalla; el servidor lo confirma detrás. */
            juego.vista = juego.vista.substring(0, i) + (ch === 'F' ? '.' : 'F') + juego.vista.substring(i + 1);
            pintar();
            accion('bandera', i);
        } else if (ch === '.') {
            juego.pulsada = i;
            pintar();
            accion('abrir', i);
        } else if (/[1-8]/.test(ch)) {
            accion('acorde', i);
        }
    }

    function accion(tipo, i) {
        if (juego.modo === 'libre') { libreAccion(tipo, i); return; }
        var id = juego.id;
        cola = cola.then(function () {
            return JG.api('bm-jugar', { id: id, tipo: tipo, celda: i }).then(function (r) {
                if (!juego || juego.id !== id) return;
                if (!r || !r.ok) return;
                recibir(r);
            }).catch(function () {});
        });
    }

    /* Respuesta del servidor (bm-jugar, bm-dia-abrir…). */
    function recibir(r) {
        var p = r.partida;
        juego = Object.assign(juego || {}, {
            modo: p.modo, id: p.id, w: p.w, h: p.h, minas: p.minas, vista: p.vista, estado: p.estado,
            pulsada: null, ms: p.ms, recibido: performance.now(), datos: p
        });
        if (r.infinito) juego.inf = r.infinito;
        pintar();
        if (juego.estado === 'jugando') arrancarReloj(); else { pararReloj(); pintaReloj(); }
        /* Al acabar (o al abrir una ya acabada), el panel de abajo. */
        if (juego.estado !== 'jugando' && !$('bmj-end').classList.contains('on')) pintaFinal(r);
    }

    /* ── Reloj: del día y libre cuentan hacia arriba; infinito, la cuenta
       atrás de la ronda (al llegar a 0 se pregunta al servidor). ── */
    function arrancarReloj() {
        pararReloj();
        pintaReloj();
        reloj = setInterval(pintaReloj, 200);
    }
    function pararReloj() { if (reloj) clearInterval(reloj); reloj = null; }
    function pintaReloj() {
        if (!juego) return;
        var el = $('bmj-tiempo');
        var corre = juego.estado === 'jugando' ? performance.now() - juego.recibido : 0;
        if (juego.modo === 'inf') {
            var queda = (juego.estado === 'jugando' ? juego.datos.restante_ms - corre : juego.datos.restante_ms);
            el.textContent = tiempo(Math.ceil(Math.max(0, queda) / 1000) * 1000);
            el.classList.toggle('urge', queda < 20000);
            if (juego.estado === 'jugando' && queda <= 0 && !juego.preguntado) {
                juego.preguntado = true;
                JG.api('bm-inf').then(function (r) {
                    if (r && r.ok && r.infinito.partida && r.infinito.partida.id === juego.id) recibir({ partida: r.infinito.partida, infinito: r.infinito });
                });
            }
        } else if (juego.modo === 'libre') {
            el.classList.remove('urge');
            el.textContent = tiempo(juego.inicio ? (juego.fin || Date.now()) - juego.inicio : 0);
        } else {
            el.classList.remove('urge');
            el.textContent = tiempo(juego.ms + corre);
        }
    }

    function abrirJuego(titulo, sub, nueva) {
        JG.show('jg-bm-juego');
        $('bmj-title').textContent = titulo;
        $('bmj-sub').textContent = sub || '';
        $('bmj-nueva').style.display = nueva ? '' : 'none';
        $('bmj-end').classList.remove('on');
        $('bmj-end').innerHTML = '';
        $('bmj-info').textContent = '';
    }

    /* ── Final de partida ── */
    function pintaFinal(r) {
        var e = $('bmj-end'), p = juego.datos, html = '';
        e.innerHTML = '';
        if (juego.modo === 'dia') {
            if (juego.estado === 'ganada') {
                html = '<b>¡Despejado en ' + tiempo(p.ms, true) + '!</b><br>+' + p.puntos + ' ' + JG.PT + ' puntos de Autismo' +
                       '<br>Los 3 más rápidos de hoy se llevan +50, +30 y +15 al acabar el día.';
            } else {
                var bien = contar(juego.vista, 'F');
                html = '<b>¡Mina!</b><br>Minas bien marcadas: ' + bien + ' de ' + juego.minas + ' → +' + p.puntos + ' ' + JG.PT + ' puntos de Autismo';
            }
            e.innerHTML = html;
        } else if (juego.modo === 'inf') {
            var inf = juego.inf || r.infinito;
            if (juego.estado === 'ganada' && inf) {
                var s = inf.siguiente;
                e.innerHTML = '<b>¡Ronda ' + p.ronda + ' superada!</b> +' + p.puntosRonda +
                    '<br>Llevas <b>' + inf.pendientes + '</b> ' + JG.PT + ' puntos. Si sigues y pierdes, los pierdes todos.';
                var btns = document.createElement('div');
                btns.className = 'bm-btns';
                btns.appendChild(boton('Ronda ' + s.ronda + ' (' + s.w + '×' + s.h + ', ' + s.seg + ' s)', infContinuar));
                btns.appendChild(boton('Retirarse con ' + inf.pendientes, infRetirarse));
                e.appendChild(btns);
            } else if (juego.estado === 'perdida') {
                var como = contar(juego.vista, 'X') ? '¡Mina!' : '¡Se acabó el tiempo!';
                e.innerHTML = '<b>' + como + '</b><br>' + (infAntes ? 'Pierdes los ' + infAntes + ' puntos que llevabas.' : 'No llevabas puntos.') +
                    (inf ? '<br>Récord: ronda ' + inf.mejor : '');
                var b2 = document.createElement('div');
                b2.className = 'bm-btns';
                b2.appendChild(boton('Volver a empezar', infEmpezar));
                e.appendChild(b2);
            }
        } else {
            e.innerHTML = juego.estado === 'ganada'
                ? '<b>¡Despejado en ' + tiempo(juego.fin - juego.inicio, true) + '!</b>'
                : '<b>¡Mina!</b>';
            var b3 = document.createElement('div');
            b3.className = 'bm-btns';
            b3.appendChild(boton('Otra', function () { jugarLibre(juego.nivel); }));
            e.appendChild(b3);
        }
        e.classList.add('on');
    }

    /* ── Del día ── */
    function abrirDia(nivel) {
        var n = NIVELES[nivel];
        juego = null;
        abrirJuego(n.nombre + ' del día', n.w + '×' + n.h + ', ' + n.m + ' minas', false);
        $('bmj-board').innerHTML = '';
        JG.api('bm-dia-abrir', { nivel: nivel }).then(function (r) {
            if (!r || !r.ok) { $('bmj-info').textContent = (r && r.error) || 'No se ha podido abrir'; return; }
            juego = null;
            recibir(r);
            montarTablero();
        });
    }

    /* ── Infinito ── */
    function jugarInf(inf) {
        if (!inf.partida) return;
        infAntes = inf.pendientes;
        juego = null;
        var p = inf.partida;
        abrirJuego('Infinito · Ronda ' + p.ronda, p.w + '×' + p.h + ', ' + p.minas + ' minas · ' + p.puntosRonda + (p.puntosRonda === 1 ? ' punto' : ' puntos'), false);
        $('bmj-info').textContent = 'Llevas ' + inf.pendientes;
        recibir({ partida: p, infinito: inf });
        montarTablero();
    }
    function infEmpezar() { infPedir('bm-inf-empezar'); }
    function infContinuar() { infPedir('bm-inf-continuar'); }
    function infPedir(accion) {
        $('bmj-end').innerHTML = 'Preparando el tablero…';
        JG.api(accion, {}).then(function (r) {
            if (!r || !r.ok) { $('bmj-end').textContent = (r && r.error) || 'No se ha podido'; $('bmj-end').classList.add('on'); return; }
            jugarInf(r.infinito);
        });
    }
    function infRetirarse() {
        JG.api('bm-inf-retirarse', {}).then(function (r) {
            if (!r || !r.ok) { $('bmj-end').textContent = (r && r.error) || 'No se ha podido'; $('bmj-end').classList.add('on'); return; }
            abrirMenu();
            setTimeout(function () {
                var box = $('bm-inf-box');
                var d = document.createElement('div');
                d.className = 'bm-inf-line';
                d.innerHTML = '<b>Te has retirado con +' + r.ganados + ' ' + JG.PT + ' puntos de Autismo.</b>';
                box.insertBefore(d, box.firstChild);
            }, 400);
        });
    }

    /* ── Libre (todo aquí) ── */
    function jugarLibre(nivel) {
        var n = NIVELES[nivel];
        abrirJuego('Libre · ' + n.nombre, n.w + '×' + n.h + ', ' + n.m + ' minas · sin puntos', true);
        juego = {
            modo: 'libre', nivel: nivel, w: n.w, h: n.h, minas: n.m, estado: 'jugando',
            vista: new Array(n.w * n.h + 1).join('.'), mapa: null, inicio: 0, fin: 0
        };
        montarTablero();
        arrancarReloj();
    }

    function libreGenerar(primera) {
        var w = juego.w, h = juego.h, n = w * h;
        var prohibidas = {}; prohibidas[primera] = 1;
        vecinas(primera, w, h).forEach(function (v) { prohibidas[v] = 1; });
        var libres = [];
        for (var i = 0; i < n; i++) if (!prohibidas[i]) libres.push(i);
        for (var k = libres.length - 1; k > 0; k--) { var j = Math.floor(Math.random() * (k + 1)); var t = libres[k]; libres[k] = libres[j]; libres[j] = t; }
        var mina = new Array(n).fill(false);
        libres.slice(0, juego.minas).forEach(function (i) { mina[i] = true; });
        juego.mapa = mina.map(function (m, i) {
            if (m) return -1;
            return vecinas(i, w, h).filter(function (v) { return mina[v]; }).length;
        });
        juego.inicio = Date.now();
    }

    function libreAccion(tipo, i) {
        var w = juego.w, h = juego.h, v = juego.vista.split('');
        if (tipo === 'bandera') return;   /* ya pintada en enClic */
        if (!juego.mapa) libreGenerar(i);
        var abrir = [];
        if (tipo === 'abrir') abrir = [i];
        else if (tipo === 'acorde') {
            var vec = vecinas(i, w, h);
            var f = vec.filter(function (x) { return v[x] === 'F'; }).length;
            if (f === juego.mapa[i]) abrir = vec.filter(function (x) { return v[x] === '.'; });
        }
        var boom = -1;
        abrir.forEach(function (c) {
            if (boom >= 0) return;
            if (juego.mapa[c] < 0) { boom = c; return; }
            var pila = [c];
            while (pila.length) {
                var x = pila.pop();
                if (v[x] !== '.' && v[x] !== 'F') continue;
                v[x] = String(juego.mapa[x]);
                if (juego.mapa[x] === 0) vecinas(x, w, h).forEach(function (y) { if (v[y] === '.' || v[y] === 'F') pila.push(y); });
            }
        });
        juego.pulsada = null;
        if (boom >= 0) {
            for (var k = 0; k < v.length; k++) {
                var m = juego.mapa[k] < 0;
                if (k === boom) v[k] = 'X';
                else if (m && v[k] === '.') v[k] = 'M';
                else if (!m && v[k] === 'F') v[k] = 'W';
            }
            juego.estado = 'perdida';
        } else {
            var tapadas = v.filter(function (c) { return c === '.' || c === 'F'; }).length;
            if (tapadas === juego.minas) {
                for (var q = 0; q < v.length; q++) if (v[q] === '.') v[q] = 'F';
                juego.estado = 'ganada';
            }
        }
        juego.vista = v.join('');
        if (juego.estado !== 'jugando') { juego.fin = Date.now(); pararReloj(); pintaReloj(); }
        pintar();
        if (juego.estado !== 'jugando') pintaFinal({});
    }

    /* ── Estadísticas ── */
    function abrirStats() {
        JG.show('jg-bm-stats');
        cargarStats();
    }
    function cargarStats() {
        var box = $('bms-scroll');
        $('bms-sum').textContent = 'Cargando…';
        box.innerHTML = '';
        JG.api('bm-hoy').then(function (r) {
            if (!r || !r.ok) { $('bms-sum').textContent = 'No se han podido cargar'; return; }
            $('bms-date').textContent = JG.fecha(r.dia);
            $('bms-sum').textContent = 'Tableros de hoy y récord del infinito';
            if (!r.jugadores) { box.innerHTML = '<div class="st-empty">Todavía no ha jugado nadie hoy.</div>'; return; }
            if (!r.imagen) { box.innerHTML = '<div class="st-empty">El servidor no puede dibujar las tarjetas.</div>'; return; }
            var img = new Image();
            img.alt = 'Resultados de hoy';
            img.onload = function () { img.style.width = img.naturalWidth + 'px'; };
            var cols = Math.max(1, Math.min(5, Math.floor((box.clientWidth - 16 + 8) / 120)));
            img.src = JG.API + '?action=bm-hoy-img&escala=1&cols=' + cols + '&_=' + Date.now();
            box.appendChild(img);
        }).catch(function () { $('bms-sum').textContent = 'Sin conexión, prueba otra vez'; });
    }

    /* ── Eventos ── */
    var board = $('bmj-board');
    board.addEventListener('click', function (e) { enClic(e, false); });
    board.addEventListener('contextmenu', function (e) { enClic(e, true); });
    $('jg-open-bm').addEventListener('click', abrirMenu);
    $('jg-open-bm').addEventListener('keydown', function (e) { if (e.key === 'Enter') abrirMenu(); });
    $('bm-back').addEventListener('click', function () { JG.show('jg-home'); });
    $('bmj-back').addEventListener('click', function () {
        juego = null; abrirMenu();
    });
    $('bmj-nueva').addEventListener('click', function () { if (juego && juego.modo === 'libre') jugarLibre(juego.nivel); });
    $('bm-libre-jugar').addEventListener('click', function () { jugarLibre(+$('bm-libre-nivel').value); });
    $('bm-stats').addEventListener('click', abrirStats);
    $('bms-back').addEventListener('click', abrirMenu);
    $('bms-reload').addEventListener('click', cargarStats);
    $('bm-help').addEventListener('click', function () { $('bm-modal').classList.add('on'); });
    $('bm-modal-x').addEventListener('click', function () { $('bm-modal').classList.remove('on'); });
    $('bm-modal-ok').addEventListener('click', function () { $('bm-modal').classList.remove('on'); });

    /* Rótulo de la portada (Hoy: N/3). */
    JG.api('bm-dia').then(function (r) { if (r && r.ok) estadoPortada(r); }).catch(function () {});
})();
