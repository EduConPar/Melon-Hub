/* ──────────────────────────────────────────────────────────────────────
   INTERFACE INIT — Kawaii
   ──────────────────────────────────────────────────────────────────────
   Wrappea cada .window con un .kw-frame interno para que el CSS pueda
   dibujar el marco lila + cards flotantes (title-bar + body).

   Filosofía conservadora — para no romper apps con layouts custom:
     · `ensurePositioned` SOLO añade position:relative si el .window
       es static (las ventanas absolute del shell no se tocan)
     · `ensureWindowBody` SOLO envuelve cuando hay UN iframe único
       (typical para apps tipo Temas/Calendario que solo cargan iframe).
       Si la app tiene controles propios (mini-player, dnd, mascota,
       etc.) NO toca la estructura — se queda como esté
     · NO hay observer per-window — apps que añaden hijos dinámicos
       al .window mantienen su flujo natural
     · Observer global solo para wrappear ventanas NUEVAS al inyectarse

   Como init.js solo se carga si la interfaz es kawaii (lo emite
   assets/php/active-interface.php), esto NO afecta a melonos98.
   ────────────────────────────────────────────────────────────────────── */
(function() {
    'use strict';

    var FRAME_CLASS    = 'kw-frame';
    var WRAPPED_MARK   = 'kwWrapped';   /* → data-kw-wrapped="1" */

    /* Si el .window tiene position:static (default), nuestro .kw-frame
       absoluto se anclaría al ancestro positioned (o al viewport) en
       vez del .window. Para evitarlo le ponemos position:relative SOLO
       si está static. Si el JS del shell luego le pone position:absolute
       (inline style), gana porque inline más reciente sobreescribe al
       anterior. */
    function ensurePositioned(el) {
        if (!el) return;
        var pos = window.getComputedStyle(el).position;
        if (pos === 'static') {
            el.style.position = 'relative';
        }
    }

    /* Algunos .window son "fake" — se usan solo para el chrome styling
       de menús contextuales / dropdowns (#pl-more-menu en reproductor,
       pop-overs varios). Estos NO tienen .title-bar ni .window-body ni
       iframe — solo divs con items.

       Si los envolvemos en .kw-frame, mi CSS .kw-frame { display:flex;
       flex-direction:column } los rompe (los items quedan estirados,
       borde 3px lila gigante, etc.).

       Detección: si NO hay título-bar, window-body, ni iframe directo,
       no es una ventana "real" → no la envolvemos. */
    function shouldWrap(windowEl) {
        /* Skip ventanas del MÓVIL (mh-window): mobile-theme.css usa
           selectores `.mh-window > .title-bar` y `.mh-window >
           .window-body` con flex column para que el body llene la
           pantalla. Si envolvemos con .kw-frame en medio, esos
           selectores dejan de matchear y el contenido se va arriba. */
        if (windowEl.classList && windowEl.classList.contains('mh-window')) {
            return false;
        }

        var children = windowEl.children;
        for (var i = 0; i < children.length; i++) {
            var c = children[i];
            if (c.classList && (c.classList.contains('title-bar') || c.classList.contains('window-body'))) return true;
            if (c.tagName === 'IFRAME') return true;
        }
        return false;
    }

    /* Envuelve los hijos de .window en un .kw-frame. Idempotente.

       NO crea body sintético — el CSS de kawaii tiene reglas
       separadas para .kw-frame > .window-body y .kw-frame > iframe
       que les dan a ambos el card style con borde lila.

       El .kw-frame usa flex:1 en CSS — fills su parent cuando es flex,
       auto-sizes cuando es block. Sin lógica de detección en JS. */
    function wrap(windowEl) {
        if (!windowEl || windowEl.dataset[WRAPPED_MARK]) return;

        if (!shouldWrap(windowEl)) {
            /* Marcamos como "procesada" para que el observer global no
               vuelva a intentarlo cada vez que cambie el contenido. */
            windowEl.dataset[WRAPPED_MARK] = '1';
            return;
        }

        windowEl.dataset[WRAPPED_MARK] = '1';

        ensurePositioned(windowEl);

        var frame = document.createElement('div');
        frame.className = FRAME_CLASS;

        /* Mover TODOS los nodos hijos al frame. Copia primera el live
           NodeList para que appendChild no nos rompa el iterador. */
        var children = [];
        for (var i = 0; i < windowEl.childNodes.length; i++) {
            children.push(windowEl.childNodes[i]);
        }
        for (var j = 0; j < children.length; j++) {
            frame.appendChild(children[j]);
        }
        windowEl.appendChild(frame);
    }

    function wrapAll(root) {
        if (!root) return;
        if (root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.classList && root.classList.contains('window')) wrap(root);
        if (root.querySelectorAll) {
            var wins = root.querySelectorAll('.window');
            for (var i = 0; i < wins.length; i++) wrap(wins[i]);
        }
    }

    /* Pass inicial cuando el DOM ya está listo. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            wrapAll(document.body);
        });
    } else {
        wrapAll(document.body);
    }

    /* Observer global — solo detecta .window NUEVOS añadidos al DOM.
       NO observa cambios per-window: si una app añade contenido custom
       a su .window tras el wrap, ese contenido se queda como hijo
       directo del .window (no se mueve al .kw-frame). Esto es a
       propósito — la alternativa rompía apps con layout custom. */
    function startGlobalObserver() {
        if (!document.body) return;
        new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type !== 'childList') continue;
                for (var j = 0; j < m.addedNodes.length; j++) {
                    wrapAll(m.addedNodes[j]);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.body) {
        startGlobalObserver();
    } else {
        document.addEventListener('DOMContentLoaded', startGlobalObserver);
    }
})();
