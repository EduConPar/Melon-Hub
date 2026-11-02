/* ──────────────────────────────────────────────────────────────────────
   icon-pack.js
   ──────────────────────────────────────────────────────────────────────
   Cambia los iconos de la app a una variante alternativa que vive en
   `assets/img/appIcons/<PackName>/`. La preferencia se guarda en
   localStorage como `iconPack`.

   Por defecto, el pack es 'Melon' (idéntico a los iconos en raíz —
   `appIcons/Melon/*.png` es copia de `appIcons/*.png`). El usuario
   puede crear más packs creando carpetas dentro de `appIcons/`.

   Flujo:
     1) Al cargar la página, este script lee `localStorage.iconPack`.
     2) Si el pack no es 'Melon' (o vacío), reescribe el `src` de
        todos los `<img>` cuyo path contenga `/assets/img/appIcons/<file>.png`
        a `/assets/img/appIcons/<pack>/<file>.png`.
     3) Un MutationObserver mantiene la sustitución para imágenes
        añadidas dinámicamente (modales, popups, etc.).

   El script SOLO toca `<img>` directos. Para iconos en CSS
   `background-image:` no aplica — esos se mantienen como están.
   ────────────────────────────────────────────────────────────────────── */
(function() {
    'use strict';

    /* Pack por defecto. SIEMPRE hacemos swap — los iconos en la raíz
       de `appIcons/` se eliminaron, ahora viven SOLO en subcarpetas
       (Melon como default). Sin swap, todos los <img src="...
       appIcons/file.png"> darían 404. */
    var DEFAULT_PACK = 'Melon';

    /* Lee la interfaz activa para namespacing por interfaz. La cookie
       'activeInterface' la setea interface-loader.js cuando el usuario
       cambia de interfaz. Default 'win98' (mismo fallback que el PHP). */
    function readCookie(name) {
        var parts = (document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            var kv = parts[i].split('=');
            if ((kv[0] || '').trim() === name) return decodeURIComponent((kv[1] || '').trim());
        }
        return '';
    }
    var activeInterface = readCookie('activeInterface') || 'win98';

    /* Override de window (precedencia máxima): permite a páginas que
       cargan en iframe (p.ej. apps/perfil.php?standalone=1&as=USERKEY)
       forzar un pack específico — el del usuario visitado — en lugar
       del pack del viewer guardado en localStorage. Debe definirse
       ANTES de que este script cargue (en el <head> del documento). */
    var current = '';
    if (typeof window.__ICON_PACK_OVERRIDE === 'string' && window.__ICON_PACK_OVERRIDE) {
        current = window.__ICON_PACK_OVERRIDE.replace(/[^A-Za-z0-9_-]/g, '');
    }
    /* Si no hay override, lee el icon pack del namespace de la interfaz
       activa. Si no hay preferencia, intenta leer el global antiguo
       (retrocompat), si tampoco, cae al DEFAULT_PACK. */
    if (!current) {
        try {
            current = localStorage.getItem('iconPack:' + activeInterface) || '';
            if (!current) current = localStorage.getItem('iconPack') || '';
        } catch (_) {}
    }
    if (!current) current = DEFAULT_PACK;

    /* Mapping icon-name → emoji default. Cuando un icono falla al
       cargar (porque el pack seleccionado no lo tiene), reemplazamos
       el <img> por un <span> con el emoji equivalente. Esto da pistas
       visuales claras de qué icono representa, manteniendo coherencia
       con los emojis que usábamos antes de tener PNG. */
    var EMOJI_MAP = {
        bellIcon:         '🔔',
        booksIcon:        '📚',
        calendarioIcon:   '📅',
        chatIcon:         '💬',
        companionIcon:    '💀',
        discordIcon:      '🔗',
        dndIcon:          '⚔',
        downloadIcon:     '⬇',
        drawingIcon:      '✏',
        folderIcon:       '📁',
        galeriaIcon:      '🖼',
        haroIcon:         '⚪',
        instagramIcon:    '📷',
        interfaceIcon:    '🖥',
        juegosIcon:       '🎮',
        mascotaIcon:      '🐾',
        melonArchiveIcon: '📺',
        musicaIcon:       '🎵',
        newsIcon:         '📰',
        pelisIcon:        '🎬',
        profileIcon:      '👤',
        puntosAutismo:    '🟧',
        songIcon:         '🎶',
        steamIcon:        '🎮',
        temasIcon:        '🎨',
        tiendaIcon:       '🛒',
        trashIcon:        '🗑'
    };

    function rewriteSrc(src) {
        if (!src) return src;
        return src.replace(
            /(\/?assets\/img\/appIcons\/)([A-Za-z0-9_-]+\.(?:png|jpg|jpeg|gif|webp))/g,
            '$1' + current + '/$2'
        );
    }

    /* Reemplaza el <img> por un <span> con el emoji default. Preserva
       tamaño (width:px → font-size:px) y márgenes para no romper el
       layout. Si no hay emoji mapeado, deja la imagen rota. */
    function replaceWithEmoji(img, iconName) {
        var emoji = EMOJI_MAP[iconName];
        if (!emoji || !img.parentNode) return;
        var span = document.createElement('span');
        span.textContent = emoji;
        span.setAttribute('data-icon-emoji', iconName);
        /* Extrae tamaño y márgenes para que el emoji ocupe el mismo
           espacio que ocuparía el icono. El size puede venir de:
             1) style inline width:Npx     (mayoría de los <img>)
             2) atributo width="N"
             3) computed style (cuando el size viene de una clase CSS,
                p. ej. .temas-item-dl { width: 12px })
           El fallback final es 14px. Esto evita que iconos cuyo tamaño
           es dictado por clase CSS rendericen al cambiar de pack a otro
           tamaño cuando se cae al fallback emoji. */
        var styleStr = img.getAttribute('style') || '';
        var widthMatch = styleStr.match(/width:\s*(\d+)px/);
        var size;
        if (widthMatch) {
            size = parseInt(widthMatch[1], 10);
        } else if (img.getAttribute('width')) {
            size = parseInt(img.getAttribute('width'), 10) || 14;
        } else if (window.getComputedStyle) {
            var w = parseFloat(window.getComputedStyle(img).width);
            size = w > 0 ? Math.round(w) : 14;
        } else {
            size = 14;
        }
        var margins = (styleStr.match(/margin[^;]+;/g) || []).join('');
        var verticalAlign = (styleStr.match(/vertical-align:[^;]+;?/) || ['vertical-align:middle;'])[0];
        if (verticalAlign.slice(-1) !== ';') verticalAlign += ';';
        /* Forzamos width/height al size en píxeles para que las reglas
           CSS de la clase (e.g. width:12px en .temas-item-dl) no
           desincronicen con el font-size del emoji al cambiar de pack. */
        span.style.cssText = 'font-size:' + size + 'px;' +
                              'width:' + size + 'px;height:' + size + 'px;' +
                              'display:inline-flex;align-items:center;justify-content:center;' +
                              'line-height:1;' +
                              verticalAlign + margins;
        if (img.className) span.className = img.className;
        img.parentNode.replaceChild(span, img);
    }

    function rewriteImg(img) {
        if (!img || img.tagName !== 'IMG') return;
        var src = img.getAttribute('src');
        if (!src) return;
        /* Si ya tiene subdir (preview cards o ya swappeado), skip. */
        if (/\/appIcons\/[^/]+\/[^/]+$/.test(src)) return;
        if (!/\/appIcons\/[^/]+\.[a-z]+/i.test(src)) return;
        var newSrc = rewriteSrc(src);
        if (newSrc === src) return;
        /* Extrae el nombre del icono (sin extensión) para buscar el
           emoji equivalente en el fallback. */
        var nameMatch = newSrc.match(/\/appIcons\/[^/]+\/([A-Za-z0-9_-]+)\.[a-z]+/i);
        var iconName = nameMatch ? nameMatch[1] : '';
        /* Listener DEBE registrarse ANTES de cambiar src para garantizar
           que captura el `error` event (si responde 404 cacheado super
           rápido). */
        if (iconName && !img.dataset.iconFallback) {
            img.dataset.iconFallback = '1';
            img.addEventListener('error', function onErr() {
                if (img.dataset.iconFallback !== '1') return;
                img.dataset.iconFallback = '2';
                replaceWithEmoji(img, iconName);
            }, { once: true });
        }
        img.src = newSrc;
    }

    function rewriteAll(root) {
        var imgs = (root || document).querySelectorAll('img');
        for (var i = 0; i < imgs.length; i++) rewriteImg(imgs[i]);
    }

    /* MutationObserver attached INMEDIATAMENTE (no esperamos a DOMContentLoaded)
       para captar imágenes durante el parsing del body. Esto reduce el
       flash de 404 al mínimo posible: cuando el browser parsea
       `<img src="appIcons/X.png">`, lanza el fetch, pero el observer
       cambia el src casi al mismo tiempo y el browser cancela y reabre. */
    attachObserver();

    /* Apply al DOM ya construido. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { rewriteAll(); });
    } else {
        rewriteAll();
    }

    /* MutationObserver: capta imágenes añadidas dinámicamente (modales,
       previews, listas que se renderizan en JS, etc.). */
    function attachObserver() {
        if (!('MutationObserver' in window)) return;
        var mo = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList') {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var n = m.addedNodes[j];
                        if (n.nodeType !== 1) continue;
                        if (n.tagName === 'IMG') rewriteImg(n);
                        else if (n.querySelectorAll) rewriteAll(n);
                    }
                } else if (m.type === 'attributes' && m.attributeName === 'src' && m.target.tagName === 'IMG') {
                    rewriteImg(m.target);
                }
            }
        });
        mo.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['src']
        });
    }
})();
