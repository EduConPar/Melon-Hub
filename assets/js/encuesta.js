/* ──────────────────────────────────────────────────────────────────────
   Encuestas de satisfacción: el formulario, igual en el escritorio
   (desktop-base.php, ventana #encuesta-modal que abre el haro) y en el
   móvil (mobile.php, #mh-enc-backdrop que abre el aviso). La lógica y
   los puntos están en assets/encuestas/encuestas.php; aquí solo se pinta
   y se manda. Estilos en assets/css/encuesta.css.

   MelonEncuesta.pedir()                → Promise con la encuesta o null
   MelonEncuesta.rechazar(enc, plat)
   MelonEncuesta.pintar(cuerpo, enc, { plataforma, cerrar, titulo })
     cuerpo: el .window-body de la ventana; cerrar(): la cierra (sin
     responder, la encuesta sigue pendiente y vuelve a salir otro día);
     titulo: el .title-bar-text, para poner el nombre de la app.
   ────────────────────────────────────────────────────────────────────── */
(function () {
    var API = 'assets/encuestas/api.php';

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function post(accion, datos) {
        return fetch(API + '?action=' + accion, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        }).then(function (r) { return r.json(); });
    }

    var PREGUNTAS = [
        { k: 'intuitiva', t: function (n) { return '¿Cómo de intuitiva es ' + n + '?'; } },
        { k: 'util',      t: function (n) { return '¿Cómo de útil te parece ' + n + '?'; } },
        { k: 'uso',       t: function (n) { return 'Del 1 al 5, ¿cuánto usas ' + n + '?'; } },
        { k: 'bonita',    t: function (n) { return '¿Cómo de bonita te parece ' + n + '?'; } },
        { k: 'rapida',    t: function (n) { return '¿Cómo de rápida va ' + n + '?'; } },
        { k: 'recomendar', t: function (n) { return '¿Sería ' + n + ' una razón para recomendar Melon Hub a un amigo?'; } },
        { k: 'general',   t: function (n) { return 'En general, ¿cómo de contento estás con ' + n + '?'; } }
    ];

    function pintar(cuerpo, enc, op) {
        op = op || {};
        var nombre = '<b>' + esc(enc.nombre) + '</b>';
        if (op.titulo) op.titulo.innerHTML = '<img src="' + esc(enc.icono) + '" alt="" class="enc-tit-ic">Encuesta: ' + esc(enc.nombre);
        var notas = {};
        var h = '<div class="enc">'
            + '<p class="enc-intro">Ayúdanos a mejorar ' + nombre + '. Al responder ganas <b>' + enc.puntos
            + '</b> puntos, y <b>' + enc.extra + '</b> más si nos cuentas qué cambiarías.</p>';
        PREGUNTAS.forEach(function (p, i) {
            h += '<div class="enc-preg" data-k="' + p.k + '"><div class="enc-q">' + (i + 1) + '. ' + p.t(nombre) + '</div>'
               + '<div class="enc-escala"><span class="enc-ext">Nada</span>';
            for (var v = 1; v <= 5; v++) h += '<button type="button" class="enc-nota" data-v="' + v + '" aria-pressed="false">' + v + '</button>';
            h += '<span class="enc-ext">Mucho</span></div></div>';
        });
        h += '<div class="enc-preg"><label class="enc-q" for="enc-coment">' + (PREGUNTAS.length + 1) + '. ¿Qué cambiarías o añadirías a ' + nombre
           + '? <span class="enc-opc">(opcional, +' + enc.extra + ' puntos)</span></label>'
           + '<textarea id="enc-coment" class="enc-coment" rows="4" maxlength="' + enc.maxComentario + '"></textarea>'
           + '<div class="enc-cont"></div></div>'
           + '<p class="enc-estado" role="status"></p>'
           + '<div class="enc-botones"><button type="button" class="enc-enviar default" disabled>Enviar</button></div>'
           + '</div>';
        cuerpo.innerHTML = h;

        var enviar = cuerpo.querySelector('.enc-enviar');
        var coment = cuerpo.querySelector('.enc-coment');
        var cont   = cuerpo.querySelector('.enc-cont');
        var estado = cuerpo.querySelector('.enc-estado');

        function listo() { return PREGUNTAS.every(function (p) { return notas[p.k]; }); }
        function contar() {
            var n = coment.value.trim().length;
            cont.textContent = n >= enc.minComentario ? '¡+' + enc.extra + ' puntos!'
                : (n ? 'Escribe ' + (enc.minComentario - n) + ' letras más para los +' + enc.extra : '');
            cont.classList.toggle('enc-cont-ok', n >= enc.minComentario);
        }
        cuerpo.querySelectorAll('.enc-preg[data-k]').forEach(function (fila) {
            fila.querySelectorAll('.enc-nota').forEach(function (b) {
                b.addEventListener('click', function () {
                    notas[fila.dataset.k] = +b.dataset.v;
                    fila.querySelectorAll('.enc-nota').forEach(function (o) {
                        o.classList.toggle('enc-on', o === b);
                        o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
                    });
                    enviar.disabled = !listo();
                });
            });
        });
        coment.addEventListener('input', contar);

        enviar.addEventListener('click', function () {
            if (!listo()) return;
            enviar.disabled = true;
            estado.textContent = 'Enviando…';
            var datos = { id: enc.id, comentario: coment.value, plataforma: op.plataforma };
            PREGUNTAS.forEach(function (p) { datos[p.k] = notas[p.k]; });
            post('responder', datos).then(function (d) {
                if (!d || !d.ok) {
                    estado.textContent = (d && d.error) || 'No se ha podido enviar. Inténtalo otra vez.';
                    enviar.disabled = !!(d && d.error && /disponible/.test(d.error));
                    return;
                }
                cuerpo.innerHTML = '<div class="enc enc-fin"><p>¡Gracias por responder!</p>'
                    + '<p>Has ganado <b>' + d.puntos + '</b> puntos.</p>'
                    + '<div class="enc-botones"><button type="button" class="enc-cerrar default">Cerrar</button></div></div>';
                cuerpo.querySelector('.enc-cerrar').addEventListener('click', function () { if (op.cerrar) op.cerrar(); });
            }).catch(function () {
                estado.textContent = 'No se ha podido enviar. Inténtalo otra vez.';
                enviar.disabled = false;
            });
        });
    }

    window.MelonEncuesta = {
        pedir: function () {
            return fetch(API + '?action=toca', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { return (d && d.ok && d.encuesta) || null; })
                .catch(function () { return null; });
        },
        rechazar: function (enc, plataforma) {
            return post('rechazar', { id: enc.id, plataforma: plataforma }).catch(function () {});
        },
        pintar: pintar
    };
})();
