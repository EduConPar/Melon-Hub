/* ──────────────────────────────────────────────────────────────────────
   TIMBRE (assets/php/timbre.php) — lo usan desktop-base.php y mobile.php
   ──────────────────────────────────────────────────────────────────────
   Un archivo estático por usuario (rt/u/<código>.json) con un contador por
   tema que el servidor sube cuando cambia algo para él: chat (mensajes),
   notif (notificaciones del perfil), items (invitaciones de listas),
   musica (de playlists), lt (Escuchar juntos), regalo, aviso (ajedrez),
   saldo (puntos), cal (invitaciones a eventos) y pareja.
   Se mira cada 3 s (cada segundo con un chat abierto; con la ventana
   oculta, cada `oculto` ms): lo sirve LiteSpeed sin PHP ni BD y casi
   siempre contesta 304. Cada parte se apunta a sus temas con
   melonTimbre.on(tema, fn, respaldo) y solo llama a su API cuando cambian.
   Antes cada una preguntaba a su API cada 1,5–60 s y había dos SSE
   abiertos todo el rato por escritorio (.claude/notas/rendimiento.md).
   Si el archivo no responde, cada parte vuelve a preguntar a su API a su
   ritmo de antes (respaldo, ms; sin respaldo, nada) hasta que vuelva.
   Se parte de lo que tenía el archivo al generar la página (visto): un
   cambio entre la carga y la primera lectura no se pierde.
   Las apps en iframe (tienda, calendario, perfil del móvil…) se apuntan con
   window.parent.melonTimbre.on(…) y se dan de baja con lo que devuelve (al
   recargarse, para no dejar avisos a una página que ya no existe). */
window.melonCrearTimbre = function (url, visto, oculto) {
    var temas = {}, ok = !!url, rapidos = {};
    visto = visto || {};
    oculto = oculto || 3000;
    function llamar(s) { s.ultima = Date.now(); try { s.fn(); } catch (e) {} }
    function mirar() {
        fetch(url, { cache: 'no-cache', credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('timbre ' + r.status); return r.json(); })
            .then(function (d) {
                ok = true;
                Object.keys(temas).forEach(function (t) {
                    if ((d[t] || 0) !== (visto[t] || 0)) temas[t].forEach(llamar);
                });
                visto = d;
                setTimeout(mirar, document.hidden ? oculto : (Object.keys(rapidos).length ? 1000 : 3000));
            })
            .catch(function () { ok = false; setTimeout(mirar, 10000); });
    }
    /* Sin timbre: cada parte, a su ritmo de antes. */
    setInterval(function () {
        if (ok) return;
        var ahora = Date.now();
        Object.keys(temas).forEach(function (t) {
            temas[t].forEach(function (s) { if (s.cada && ahora - s.ultima >= s.cada) llamar(s); });
        });
    }, 1000);
    if (url) setTimeout(mirar, 1000);
    return {
        on: function (tema, fn, respaldo) {
            var s = { fn: fn, cada: respaldo || 0, ultima: Date.now() };
            (temas[tema] = temas[tema] || []).push(s);
            return function () { temas[tema] = (temas[tema] || []).filter(function (x) { return x !== s; }); };
        },
        ok: function () { return ok; },
        /* Mirar cada segundo mientras haga falta (quien: p. ej. 'chat'). */
        rapido: function (quien, si) { if (si) rapidos[quien] = 1; else delete rapidos[quien]; }
    };
};
