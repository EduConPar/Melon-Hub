/* ─────────────────────────────────────────────────────────────────
   font-scale.js — escalado global de fuentes por DELTA

   El tema activo del usuario puede llevar un `fontDelta` (entero en
   píxeles, default 0). Cuando se aplica, TODAS las font-size de la
   app crecen/decrecen ese delta de forma uniforme:
     - Si una regla CSS dice `font-size: 11px` y delta=2 → 13px.
     - Si dice 14px → 16px.

   No es un multiplicador (12→14 no escala ×1.17). Es un delta, así
   que las jerarquías visuales se mantienen aunque los textos chicos
   no queden desproporcionadamente grandes vs los titulares.

   Mecanismo:
     1. Variable CSS `--fs-delta` (en `<html>`). Default 0px.
     2. Stylesheet inyectada por JS que, para CADA regla que define
        font-size en px, emite un override `selector { font-size:
        calc(Npx + var(--fs-delta)) !important; }`. Se genera UNA vez
        al boot. Cambiar `--fs-delta` recalcula vía CSS, sin recorrer
        nada de nuevo.
     3. MutationObserver para inline styles: cuando un elemento tiene
        `style="font-size:Npx"`, lo reescribimos como
        `calc(Npx + var(--fs-delta))` (guardamos el original en
        `dataset.origFs` para no perder la fuente de verdad).

   El alcance es TODO el escritorio + iframes hijos: cada app que
   incluya este script recoge el delta de su shell padre (postMessage
   o ventana padre directa) o de un atributo en `<html data-fs-delta>`
   emitido por PHP al renderizar.
   ───────────────────────────────────────────────────────────────── */
(function() {
    'use strict';

    /* Marcador para que no se ejecute dos veces si por error se
       incluye el script dos veces (apps que se cargan dinámicamente). */
    if (window.__fontScaleInstalled) return;
    window.__fontScaleInstalled = true;

    const OVERLAY_ID    = 'fs-delta-overlay';
    const INLINE_ATTR   = 'data-fs-rewritten';
    const ORIG_DS_KEY   = 'origFs';
    const FS_RE_INLINE  = /font-size\s*:\s*([\d.]+)px/i;
    const FS_RE_ANY     = /^([\d.]+)px$/;

    function getDelta() {
        const v = parseFloat(window.__fontScaleDelta);
        return isFinite(v) ? v : 0;
    }

    function setRootVar(delta) {
        /* OJO con la especificidad: el CSS del tema activo emite
           `--fs-delta: Npx` dentro de `body.tema-xxx-userLabel`, lo
           que PISA un setProperty plano en <html> (selector class
           gana al inline). Para que el JS siempre gane:
             - inline en <html> con `important`
             - inline en <body> con `important` (cubre el caso del
               selector class del tema, mismo nodo).
           Así el slider en vivo + el primer paint emitido por PHP
           siempre se imponen al valor congelado en el CSS del tema. */
        var val = delta + 'px';
        document.documentElement.style.setProperty('--fs-delta', val, 'important');
        if (document.body) {
            document.body.style.setProperty('--fs-delta', val, 'important');
        }
    }

    /* ── Generación del overlay (UNA vez por sesión) ──
       Recorremos document.styleSheets, leemos las reglas accesibles
       (cross-origin las salta el catch) y construimos un texto CSS
       con `calc(Npx + var(--fs-delta))` para cada font-size en px. */
    function buildOverlay() {
        const lines = [];
        const seen  = new Set();   /* dedupe por (selector|original) */

        function visit(rule) {
            /* CSSStyleRule normal */
            if (rule.style && rule.selectorText) {
                const fs = rule.style.fontSize;
                if (fs) {
                    const m = fs.match(FS_RE_ANY);
                    if (m) {
                        const sel = rule.selectorText;
                        const orig = m[1];
                        const key = sel + '|' + orig;
                        if (!seen.has(key)) {
                            seen.add(key);
                            lines.push(sel + ' { font-size: calc(' + orig + 'px + var(--fs-delta, 0px)) !important; }');
                        }
                    }
                }
            }
            /* @media, @supports, etc. tienen .cssRules dentro */
            if (rule.cssRules) {
                for (const sub of rule.cssRules) visit(sub);
            }
        }

        for (const sheet of document.styleSheets) {
            let rules;
            try { rules = sheet.cssRules; } catch (_) { continue; /* CORS */ }
            if (!rules) continue;
            for (const rule of rules) visit(rule);
        }

        let overlay = document.getElementById(OVERLAY_ID);
        if (!overlay) {
            overlay = document.createElement('style');
            overlay.id = OVERLAY_ID;
            /* Lo metemos al FINAL del <head> para que su especificidad
               por orden gane a lo declarado antes. `!important` cierra
               cualquier override posterior. */
            document.head.appendChild(overlay);
        }
        overlay.textContent = lines.join('\n');
    }

    /* ── Inline styles: recorre el DOM y reescribe ──
       Cada elemento con `style` que contenga font-size:Npx pasa a
       calc(Npx + var(--fs-delta)). Guarda el valor original en
       data-orig-fs para que un cambio futuro de delta NO acumule. */
    function rewriteInlineFor(el) {
        if (!el || el.nodeType !== 1) return;
        if (el.hasAttribute(INLINE_ATTR)) return;
        const styleAttr = el.getAttribute('style');
        if (!styleAttr || styleAttr.indexOf('font-size') === -1) return;
        const m = styleAttr.match(FS_RE_INLINE);
        if (!m) return;
        const orig = m[1];
        el.dataset[ORIG_DS_KEY] = orig;
        /* Reemplazamos el font-size por calc; el resto del style se
           preserva. */
        const newStyle = styleAttr.replace(
            FS_RE_INLINE,
            'font-size: calc(' + orig + 'px + var(--fs-delta, 0px))'
        );
        el.setAttribute('style', newStyle);
        el.setAttribute(INLINE_ATTR, '1');
    }

    function rewriteAllInline(root) {
        root = root || document;
        const els = root.querySelectorAll('[style*="font-size"]');
        for (const el of els) rewriteInlineFor(el);
    }

    /* MutationObserver para elementos NUEVOS o cambios de style. */
    function installObserver() {
        if (window.__fontScaleObserver) return;
        const obs = new MutationObserver(muts => {
            for (const m of muts) {
                if (m.type === 'childList') {
                    for (const node of m.addedNodes) {
                        if (node.nodeType !== 1) continue;
                        rewriteInlineFor(node);
                        if (node.querySelectorAll) {
                            const inner = node.querySelectorAll('[style*="font-size"]');
                            for (const el of inner) rewriteInlineFor(el);
                        }
                    }
                } else if (m.type === 'attributes' && m.attributeName === 'style') {
                    /* Si JS cambió el style, hay que re-detectar. Pero
                       solo si el cambio NO fue nuestro (evita loop). */
                    const el = m.target;
                    if (!el.dataset[ORIG_DS_KEY]) {
                        rewriteInlineFor(el);
                    } else {
                        /* Si lo cambió otro código, posiblemente el
                           atributo data-fs-rewritten quedó pero el
                           font-size cambió. Re-evaluamos. */
                        const styleAttr = el.getAttribute('style') || '';
                        if (styleAttr.indexOf('var(--fs-delta') === -1 &&
                            styleAttr.indexOf('font-size') !== -1) {
                            el.removeAttribute(INLINE_ATTR);
                            delete el.dataset[ORIG_DS_KEY];
                            rewriteInlineFor(el);
                        }
                    }
                }
            }
        });
        obs.observe(document.body || document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style'],
        });
        window.__fontScaleObserver = obs;
    }

    /* ── API pública ──
       window.setFontScaleDelta(N) — cambia el delta en runtime.
       window.getFontScaleDelta()  — devuelve el delta actual.
       Si el script es incluido por una app dentro de un iframe del
       shell, escucha postMessage 'font-scale-delta' del padre para
       sincronizarse cuando el usuario cambia el setting. */
    window.setFontScaleDelta = function(deltaPx) {
        const d = parseFloat(deltaPx) || 0;
        window.__fontScaleDelta = d;
        setRootVar(d);
    };
    window.getFontScaleDelta = function() {
        return getDelta();
    };

    /* Init: delta inicial puede venir de:
        1. window.__fontScaleDelta seteado por inline script PHP.
        2. <html data-fs-delta="N">.
        3. Default 0. */
    function readInitialDelta() {
        if (typeof window.__fontScaleDelta === 'number') return window.__fontScaleDelta;
        const attr = document.documentElement.getAttribute('data-fs-delta');
        if (attr !== null) {
            const v = parseFloat(attr);
            if (isFinite(v)) return v;
        }
        return 0;
    }

    function boot() {
        const d = readInitialDelta();
        window.__fontScaleDelta = d;
        setRootVar(d);
        /* Las stylesheets pueden no estar todas listas hasta load.
           Hacemos un primer pase con lo que haya, luego otro en load. */
        buildOverlay();
        rewriteAllInline();
        installObserver();
        /* Pases extra en intervalos crecientes — en PWA móvil las
           hojas vienen del cache de Service Worker y a veces se
           incorporan a document.styleSheets justo después del boot,
           sin disparar `load` (si load ya pasó). Cubre ese hueco. */
        setTimeout(() => { buildOverlay(); rewriteAllInline(); }, 100);
        setTimeout(() => { buildOverlay(); rewriteAllInline(); }, 500);
        setTimeout(() => { buildOverlay(); rewriteAllInline(); }, 1500);
        window.addEventListener('load', () => {
            /* Segundo pase: las hojas externas cargadas tarde se
               incorporan a styleSheets cuando load termina. */
            buildOverlay();
            rewriteAllInline();
        });
        /* Escucha del padre (cuando vivimos en iframe) para sincronizar
           con cambios en runtime. */
        window.addEventListener('message', ev => {
            if (!ev || !ev.data) return;
            if (ev.data.type === 'font-scale-delta' && typeof ev.data.delta === 'number') {
                window.setFontScaleDelta(ev.data.delta);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
