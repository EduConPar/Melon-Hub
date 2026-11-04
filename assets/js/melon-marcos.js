/* ──────────────────────────────────────────────
   MARCOS DE PERFIL en las fotos (assets/profile/marcos.php)
   ──────────────────────────────────────────────
   Cualquier contenedor de una foto de perfil con
   data-marco-user="<user_key>" recibe data-marco="<slug>" si ese usuario
   lleva un marco; el dibujo lo pone assets/css/marcos.css (::before).
   data-marco-preview="<slug>" ("" = sin marco) manda sobre el del usuario:
   lo usa Personalizar para enseñar el marco antes de guardar.

   Un MutationObserver mira lo que se va pintando, así que las páginas solo
   tienen que poner el atributo (y no saber nada de marcos). La lista se
   pide una vez por página (acción "frames" de la API del perfil).

   window.MelonMarcos:
     ready          → promesa de la lista cargada
     set(key, slug) → cambia el marco de un usuario aquí ('' = ninguno)
     defs()         → { slug: { slug, nombre, tipo, src } }
     refresh()      → vuelve a pedir la lista */
(function () {
    if (window.MelonMarcos) return;
    var me = document.currentScript;
    var BASE = me && me.src ? me.src.replace(/assets\/js\/melon-marcos\.js.*$/, '') : '';
    var M = { defs: {}, users: {} };
    var styleEl = null;

    /* Reglas de los marcos de imagen (los de CSS ya están en marcos.css). */
    function writeStyles() {
        var css = '';
        Object.keys(M.defs).forEach(function (s) {
            var d = M.defs[s];
            if (d.tipo !== 'imagen' || !d.src || !/^[a-z0-9_-]+$/.test(s)) return;
            css += '[data-marco="' + s + '"]::before{background-image:url("' + (BASE + d.src).replace(/"/g, '%22') + '")}\n';
        });
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'mh-marcos-img';
            (document.head || document.documentElement).appendChild(styleEl);
        }
        styleEl.textContent = css;
    }

    function paint(el) {
        var pv = el.getAttribute('data-marco-preview');
        var key = el.getAttribute('data-marco-user');
        var slug = pv !== null ? pv : (key ? (M.users[key] || '') : '');
        if (slug && !M.defs[slug]) slug = '';
        if (slug) {
            if (el.getAttribute('data-marco') !== slug) el.setAttribute('data-marco', slug);
            /* El ::before se coloca respecto al contenedor. */
            if (getComputedStyle(el).position === 'static') el.classList.add('mh-marco-rel');
        } else if (el.hasAttribute('data-marco')) {
            el.removeAttribute('data-marco');
        }
    }
    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (root.hasAttribute('data-marco-user') || root.hasAttribute('data-marco-preview')) paint(root);
        var list = root.querySelectorAll('[data-marco-user],[data-marco-preview]');
        for (var i = 0; i < list.length; i++) paint(list[i]);
    }

    var loaded = false;
    function refresh() {
        return fetch(BASE + 'assets/profile/api.php?action=frames', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                M.defs = d.defs || {};
                M.users = d.users || {};
                loaded = true;
                writeStyles();
                scan(document.body);
            })
            .catch(function () {});
    }

    new MutationObserver(function (muts) {
        if (!loaded) return;
        for (var i = 0; i < muts.length; i++) {
            var m = muts[i];
            if (m.type === 'attributes') paint(m.target);
            else for (var j = 0; j < m.addedNodes.length; j++) scan(m.addedNodes[j]);
        }
    }).observe(document.documentElement, {
        subtree: true, childList: true, attributes: true,
        attributeFilter: ['data-marco-user', 'data-marco-preview'],
    });

    window.MelonMarcos = {
        ready: refresh(),
        refresh: refresh,
        defs: function () { return M.defs; },
        set: function (key, slug) {
            if (slug) M.users[key] = slug; else delete M.users[key];
            scan(document.body);
        },
    };
})();
