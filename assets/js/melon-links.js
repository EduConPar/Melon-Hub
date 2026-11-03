/* ──────────────────────────────────────────────────────────────────────
   "🔗 Copiar enlace" (ver assets/links.php)
   ──────────────────────────────────────────────────────────────────────
   melonLinkUrl('perfil', 'user2') → https://…/enlace.php?perfil=user2
   melonCopyLink(tipo, valor)       → lo copia y avisa ("Enlace copiado").
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

    function aviso(msg) {
        /* El haro del escritorio (también desde un iframe del escritorio). */
        var ns = window.notifSystem;
        try { if (!ns && window.parent && window.parent !== window) ns = window.parent.notifSystem; } catch (_) {}
        if (ns && ns.show) {
            ns.show({ id: 'melon-link-copied', type: 'info', title: '🔗 Enlace', message: msg, autoDismissAfter: 3000 });
            return;
        }
        /* Móvil / sin haro: un aviso pequeño abajo. */
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;' +
            'background:var(--win-bg,silver);color:var(--text,#000);padding:6px 12px;font-size:12px;' +
            'box-shadow:inset -1px -1px #0a0a0a,inset 1px 1px #fff,inset -2px -2px grey,inset 2px 2px #dfdfdf;';
        document.body.appendChild(t);
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 2500);
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
