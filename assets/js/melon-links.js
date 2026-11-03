/* ──────────────────────────────────────────────────────────────────────
   "🔗 Copiar enlace" (ver assets/links.php)
   ──────────────────────────────────────────────────────────────────────
   melonLinkUrl('perfil', 'user2') → https://…/enlace.php?perfil=user2
   melonCopyLink(tipo, valor)       → lo copia y avisa ("Enlace copiado"
                                      junto al ratón).
   La raíz de la web sale de dónde está este archivo (assets/js/), así
   vale igual en el escritorio, en las apps del móvil (apps/mobile/) y en
   local (/scrapbookOnline/).
   ────────────────────────────────────────────────────────────────────── */
(function () {
    var me = document.currentScript && document.currentScript.src;
    var root = me ? new URL('../../', me).href : new URL('./', location.href).href;

    window.melonLinkUrl = function (tipo, valor) {
        return root + 'enlace.php?' + encodeURIComponent(tipo) + '=' + encodeURIComponent(valor);
    };

    /* "Enlace copiado" sale junto al ratón (donde se pulsó la opción), como
       los tooltips de la web (.has-tip: --warning-bg / --bezel-dark-2; en
       Kawaii, su regla de tooltip, que incluye .melon-link-tip). Con
       teclado, junto al elemento con el foco. */
    var ultimo = null;
    document.addEventListener('pointerdown', function (e) { ultimo = { x: e.clientX, y: e.clientY }; }, true);

    function estilo() {
        if (document.getElementById('melon-link-tip-css')) return;
        var st = document.createElement('style');
        st.id = 'melon-link-tip-css';
        st.textContent = '.melon-link-tip{position:fixed;z-index:2147483647;pointer-events:none;white-space:nowrap;' +
            'background:var(--warning-bg,#ffffe1);color:var(--warning-text,#000);font-size:10px;padding:3px 7px;' +
            'border-radius:3px;border:1px solid var(--bezel-dark-2,#808080);}';
        (document.head || document.documentElement).appendChild(st);
    }

    function aviso(msg) {
        estilo();
        var old = document.querySelector('.melon-link-tip');
        if (old) old.parentNode.removeChild(old);
        var pos = ultimo;
        var a = document.activeElement;
        if (!pos && a && a !== document.body && a.getBoundingClientRect) {
            var r = a.getBoundingClientRect();
            pos = { x: r.left + r.width / 2, y: r.bottom };
        }
        if (!pos) pos = { x: window.innerWidth / 2, y: window.innerHeight / 2 };
        var t = document.createElement('div');
        t.className = 'melon-link-tip';
        t.textContent = msg;
        document.body.appendChild(t);
        /* Abajo a la derecha del puntero, sin salirse de la ventana. */
        var x = Math.min(pos.x + 12, window.innerWidth - t.offsetWidth - 4);
        var y = pos.y + 16;
        if (y + t.offsetHeight > window.innerHeight - 4) y = pos.y - t.offsetHeight - 8;
        t.style.left = Math.max(4, x) + 'px';
        t.style.top = Math.max(4, y) + 'px';
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 1500);
    }

    function copiarViejo(txt) {
        var ta = document.createElement('textarea');
        ta.value = txt;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(ta);
        return ok;
    }

    window.melonCopyLink = function (tipo, valor) {
        var url = window.melonLinkUrl(tipo, valor);
        var hecho = function () { aviso('Enlace copiado'); };
        var falla = function () {
            if (copiarViejo(url)) hecho();
            else window.prompt('Copia el enlace:', url);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(hecho, falla);
        } else {
            falla();
        }
    };
})();
