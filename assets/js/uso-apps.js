/* ──────────────────────────────────────────────────────────────────────
   Tiempo en cada app (para las estadísticas de devs, apps/devstats.php)
   ──────────────────────────────────────────────────────────────────────
   MelonUso.iniciar({ plataforma, appActual }) — appActual() devuelve la
   clave de la app que se está usando ahora (o null si no hay ninguna:
   cuenta como "_inicio").
   MelonUso.apertura(clave) — suma una apertura de esa app.

   Cada 5 s, si la pestaña se ve y la persona ha hecho algo hace menos de
   5 min, se suman esos segundos a la app de delante y a la hora local.
   Lo acumulado se manda en UN envío cada 5 min (y al cerrar o esconder
   la página, con sendBeacon): nada de pedir a PHP cada pocos segundos
   (.claude/notas/rendimiento.md).

   Las apps van en iframes del mismo origen y los eventos de dentro no
   llegan a esta página: a cada iframe se le ponen los mismos oyentes de
   actividad en cuanto aparece (y otra vez si navega).
   ────────────────────────────────────────────────────────────────────── */
(function () {
    if (window.MelonUso) return;

    var PASO_MS   = 5000;
    var ENVIO_MS  = 5 * 60 * 1000;
    var INACTIVO  = 5 * 60 * 1000;
    var URL_ENVIO = 'assets/stats/uso.php';

    var plataforma = 'web';
    var appActual  = function () { return null; };
    var ultimaActividad = Date.now();
    var ultimoPaso = Date.now();
    var apps = {};    /* clave → {s, n} */
    var horas = {};   /* hora local → segundos */
    var diaActual = hoy();
    var docsVistos = (typeof WeakSet === 'function') ? new WeakSet() : null;

    function hoy() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function actividad() { ultimaActividad = Date.now(); }
    var EVENTOS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'];
    function escuchar(doc) {
        if (!doc || (docsVistos && docsVistos.has(doc))) return;
        if (docsVistos) docsVistos.add(doc);
        EVENTOS.forEach(function (ev) {
            try { doc.addEventListener(ev, actividad, { passive: true, capture: true }); } catch (_) {}
        });
    }
    function escucharIframes() {
        var ifr = document.getElementsByTagName('iframe');
        for (var i = 0; i < ifr.length; i++) {
            try { escuchar(ifr[i].contentDocument); } catch (_) { /* otro origen (YouTube…) */ }
        }
    }
    function entrada(k) { return apps[k] || (apps[k] = { s: 0, n: 0 }); }

    function paso() {
        var ahora = Date.now();
        /* Como mucho dos pasos de golpe: si el navegador duerme la pestaña,
           el hueco no cuenta. */
        var seg = Math.min(ahora - ultimoPaso, PASO_MS * 2) / 1000;
        ultimoPaso = ahora;
        escucharIframes();
        if (hoy() !== diaActual) { enviar(false); diaActual = hoy(); }
        if (document.visibilityState !== 'visible') return;
        if (ahora - ultimaActividad > INACTIVO) return;
        var k = null;
        try { k = appActual(); } catch (_) {}
        entrada(k || '_inicio').s += seg;
        var h = new Date().getHours();
        horas[h] = (horas[h] || 0) + seg;
    }

    function enviar(alCerrar) {
        var a = {}, hay = false;
        Object.keys(apps).forEach(function (k) {
            var s = Math.round(apps[k].s), n = apps[k].n;
            if (s > 0 || n > 0) { a[k] = { s: s, n: n }; hay = true; }
        });
        var h = {};
        Object.keys(horas).forEach(function (k) { var s = Math.round(horas[k]); if (s > 0) h[k] = s; });
        if (!hay) return;
        apps = {}; horas = {};
        var cuerpo = JSON.stringify({ p: plataforma, a: a, h: h, d: diaActual });
        if (alCerrar && navigator.sendBeacon) {
            try { navigator.sendBeacon(URL_ENVIO, new Blob([cuerpo], { type: 'application/json' })); return; } catch (_) {}
        }
        try {
            fetch(URL_ENVIO, { method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json' }, body: cuerpo }).catch(function () {});
        } catch (_) {}
    }

    window.MelonUso = {
        iniciar: function (op) {
            op = op || {};
            if (op.plataforma) plataforma = op.plataforma;
            if (typeof op.appActual === 'function') appActual = op.appActual;
            if (op.url) URL_ENVIO = op.url;
            escuchar(document);
            setInterval(paso, PASO_MS);
            setInterval(function () { enviar(false); }, ENVIO_MS);
            window.addEventListener('pagehide', function () { enviar(true); });
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') enviar(true);
            });
        },
        apertura: function (k) { if (k) entrada(k).n++; }
    };
})();
