<?php
session_start();
require_once dirname(__DIR__) . '/assets/config.php';
/* Solo-dev. La wiki (MelonWiki) vive sobre MelonArchive y SOLO se activa
   en dev o con el modo dev (assets/php/modo-dev.php). Si no, la wiki queda
   inerte: MelonArchive abre directo al archivo de vídeos de siempre. */
require_once dirname(__DIR__) . '/assets/php/modo-dev.php';
$WIKI = melon_funcionesDev();

$userKey = $_SESSION['user'] ?? null;
if (!$userKey || !isset($loginUsers[$userKey])) {
    header('Location: ../index.php');
    exit;
}
$userLabel = $loginUsers[$userKey]['label'];

/* Tema activo del usuario */
require_once dirname(__DIR__) . '/assets/themes/theme-helpers.php';
refreshActiveThemeCss($userKey, $userLabel);
$_userThemes = loadUserThemes($userKey);
$activeTheme = !empty($_userThemes['active']) ? sanitizeThemeName($_userThemes['active']) : '';
$activeThemeClass = '';
$activeThemeCss   = '';
if ($activeTheme !== '' && isset(((array)$_userThemes['themes'])[$activeTheme])) {
    $activeThemeClass = themeCssClassName($activeTheme, $userLabel);
    $activeThemeCss   = '../' . themeCssRelPath($activeTheme, $userLabel);
    if (!file_exists(dirname(__DIR__) . '/' . themeCssRelPath($activeTheme, $userLabel))) $activeThemeCss = '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <script src="../assets/js/pwa-guard.js"></script>
    <title>MelonArchive</title>
    <link rel="stylesheet" href="../assets/css/98.css">
    <link rel="stylesheet" href="../assets/css/tokens.css">
    <link rel="stylesheet" href="../assets/css/base.css">
    <script>try{if(localStorage.getItem('lcd-filter')!=='0'){var c=document.documentElement.classList;c.add('lcd-filter-on');if(window.top===window)c.add('lcd-filter-top');}}catch(e){}</script>
    <script src="../assets/js/icon-pack.js"></script>
    <?php require_once dirname(__DIR__) . "/assets/php/active-interface.php"; emitInterfaceCss("../"); ?>
    <script src="../assets/js/interface-loader.js?v=fs1"></script>
    <link rel="stylesheet" href="../assets/css/melonarchive.css?v=<?php echo filemtime(dirname(__DIR__) . '/assets/css/melonarchive.css'); ?>">
    <?php if ($WIKI): /* CSS de la wiki: solo en dev */ ?>
    <link rel="stylesheet" href="../assets/dev/wiki.css?v=<?php echo filemtime(dirname(__DIR__) . '/assets/dev/wiki.css'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="../assets/css/themes.css">
    <?php if ($activeThemeCss): ?>
    <link rel="stylesheet" id="active-theme-link" href="<?php echo htmlspecialchars($activeThemeCss); ?>">
    <?php endif; ?>
</head>
<body class="<?php
    $bc = [];
    if ($activeThemeClass) $bc[] = $activeThemeClass;
    echo htmlspecialchars(implode(' ', $bc));
?>">

<div id="archive-root">
    <div id="archive-toolbar">
        <button class="button" id="nav-home">⌂ Inicio</button>
        <button class="button" id="nav-back" disabled>◄ Atrás</button>
        <span id="archive-breadcrumb">MelonArchive</span>
        <span style="flex:1"></span>
        <button class="button" id="nav-action" style="display:none"></button>
    </div>

    <div id="view-host">

        <!-- ───────── INICIO (estructura tipo portada de Fandom) ───────── -->
        <section id="view-home" class="wiki-view">
            <!-- Cabecera con título de la wiki + pestañas de navegación -->
            <div class="wh-header">
                <div class="wh-brand">
                    <span class="wh-logo">📚</span>
                    <span class="wh-name">MelonWiki</span>
                    <span class="wh-pagecount" id="wh-pagecount"></span>
                </div>
                <nav class="wh-nav" id="wh-nav">
                    <button class="wh-nav-item" data-go="wiki-list">Explorar</button>
                    <button class="wh-nav-item" data-go="wiki-new">Crear página</button>
                    <button class="wh-nav-item" data-go="wiki-requests">Mis solicitudes</button>
                    <button class="wh-nav-item" data-go="archive">Archivo</button>
                    <button class="wh-nav-item" data-go="wiki-mod" id="wh-nav-mod" style="display:none">Moderación</button>
                </nav>
            </div>
            <div class="wh-scroll">
                <!-- Banner de bienvenida -->
                <div class="wh-welcome">
                    <div class="wh-welcome-title">Bienvenido a la <b>MelonWiki</b></div>
                    <div class="wh-welcome-text">Una enciclopedia escrita y mantenida por la comunidad. Hola, <?= htmlspecialchars($userLabel) ?> — entra a cualquier sección:</div>
                </div>
                <!-- Dos columnas: contenido principal + barra lateral -->
                <div class="wh-cols">
                    <div class="wh-main">
                        <div class="wh-card">
                            <div class="wh-card-title">Secciones</div>
                            <div class="wiki-tiles" id="home-tiles">
                                <button class="wiki-tile" data-go="archive">
                                    <span class="wiki-tile-icon">📼</span>
                                    <span class="wiki-tile-text">
                                        <span class="wiki-tile-name">Archivo de vídeos</span>
                                        <span class="wiki-tile-desc">Playlists y vídeos de YouTube</span>
                                    </span>
                                </button>
                                <button class="wiki-tile" data-go="wiki-list">
                                    <span class="wiki-tile-icon">📖</span>
                                    <span class="wiki-tile-text">
                                        <span class="wiki-tile-name">Explorar la wiki</span>
                                        <span class="wiki-tile-desc">Navega las entradas</span>
                                    </span>
                                </button>
                                <button class="wiki-tile" data-go="wiki-new">
                                    <span class="wiki-tile-icon">✎</span>
                                    <span class="wiki-tile-text">
                                        <span class="wiki-tile-name">Crear página</span>
                                        <span class="wiki-tile-desc">Propón una entrada nueva</span>
                                    </span>
                                </button>
                                <button class="wiki-tile" data-go="wiki-requests">
                                    <span class="wiki-tile-icon">🗳</span>
                                    <span class="wiki-tile-text">
                                        <span class="wiki-tile-name">Mis solicitudes</span>
                                        <span class="wiki-tile-desc">Estado de tus envíos</span>
                                    </span>
                                </button>
                                <button class="wiki-tile" data-go="wiki-mod" id="tile-mod" style="display:none">
                                    <span class="wiki-tile-icon">🛡</span>
                                    <span class="wiki-tile-text">
                                        <span class="wiki-tile-name">Moderación</span>
                                        <span class="wiki-tile-desc">Revisar solicitudes <span class="wiki-badge" id="mod-count" style="display:none">0</span></span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <aside class="wh-side">
                        <div class="wh-card">
                            <div class="wh-card-title">Páginas recientes</div>
                            <div class="wh-recent" id="wh-recent"><div class="wiki-empty">Cargando…</div></div>
                        </div>
                    </aside>
                </div>
            </div>
        </section>

        <!-- ───────── ARCHIVO DE VÍDEOS (lo de siempre) ───────── -->
        <section id="view-archive" class="wiki-view" style="display:none">
            <div id="archive-body">
                <div id="archive-content">
                    <div id="archive-status"></div>
                    <div id="archive-grid"></div>
                </div>
                <div id="archive-player">
                    <iframe id="archive-iframe" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                </div>
            </div>
        </section>

        <!-- ───────── WIKI: LISTA ───────── -->
        <section id="view-wiki-list" class="wiki-view" style="display:none">
            <div class="wiki-listbar">
                <input type="search" id="wiki-search" placeholder="Buscar páginas…" autocomplete="off">
                <button class="button" id="wiki-new-from-list">＋ Crear</button>
            </div>
            <div id="wiki-list" class="wiki-list"></div>
        </section>

        <!-- ───────── WIKI: LEER PÁGINA (estructura tipo Fandom) ───────── -->
        <section id="view-wiki-page" class="wiki-view" style="display:none">
            <div class="wp-breadcrumb" id="wp-breadcrumb"></div>
            <div class="wp-titlebar">
                <h1 class="wp-title" id="wp-title">Página</h1>
                <div class="wp-titlebar-actions">
                    <button class="button" id="wp-edit">&#9998; Editar</button>
                </div>
            </div>
            <div class="wp-scroll">
                <div class="wp-layout">
                    <article class="wp-main" id="wiki-article"></article>
                    <aside class="wp-side">
                        <div class="wp-infobox" id="wp-infobox" style="display:none"></div>
                        <div class="wp-toc" id="wp-toc" style="display:none"></div>
                    </aside>
                </div>
            </div>
        </section>

        <!-- ───────── WIKI: EDITOR (estructura tipo editor visual de Fandom) ───────── -->
        <section id="view-wiki-edit" class="wiki-view" style="display:none">
            <div class="ve-modehead">
                <span class="ve-mode"><span class="ve-mode-ic">&#9998;</span> Editor visual <span class="ve-caret">&#9662;</span></span>
            </div>
            <div class="we-toolbar" id="we-toolbar">
                <button type="button" class="we-tb-btn" data-fmt="undo" title="Deshacer">&#8630;</button>
                <button type="button" class="we-tb-btn" data-fmt="redo" title="Rehacer">&#8631;</button>
                <span class="we-tb-sep"></span>
                <select class="we-tb-style" id="we-style" title="Estilo de párrafo">
                    <option value="">Texto normal</option>
                    <option value="# ">Título</option>
                    <option value="## ">Subtítulo</option>
                </select>
                <span class="we-tb-sep"></span>
                <button type="button" class="we-tb-btn" data-fmt="bold" title="Negrita (Ctrl+B)"><b>B</b></button>
                <button type="button" class="we-tb-btn" data-fmt="italic" title="Cursiva (Ctrl+I)"><i>I</i></button>
                <button type="button" class="we-tb-btn" data-fmt="more" title="Más formato"><span style="font-style:italic">T</span>&#9662;</button>
                <span class="we-tb-sep"></span>
                <button type="button" class="we-tb-btn" data-fmt="ul" title="Lista con viñetas">&#9776;</button>
                <button type="button" class="we-tb-btn" data-fmt="ol" title="Lista numerada">1.&#9776;</button>
                <button type="button" class="we-tb-btn" data-fmt="indent" title="Sangría">&#8677;&#9662;</button>
                <span class="we-tb-sep"></span>
                <button type="button" class="we-tb-btn we-tb-wide" data-fmt="cite" title="Citar">&#10077; Citar &#9662;</button>
                <span class="we-tb-sep"></span>
                <button type="button" class="we-tb-btn" data-fmt="link" title="Enlace">&#128279;</button>
                <button type="button" class="we-tb-btn" data-fmt="ilink" title="Enlace interno">[[ ]]</button>
                <button type="button" class="we-tb-btn" data-fmt="image" title="Imagen">&#128444;</button>
                <button type="button" class="we-tb-btn we-tb-wide" data-fmt="insert" title="Insertar">Insertar &#9662;</button>
                <span class="we-tb-spacer"></span>
                <button type="button" class="we-tb-btn" data-fmt="menu" title="Opciones">&#9776;</button>
            </div>
            <div class="we-doc">
                <input type="text" class="we-title-input" id="we-title" autocomplete="off" placeholder="Título de la página">
                <textarea class="we-body-input" id="we-body" placeholder="Empieza a escribir…"></textarea>
            </div>
            <div class="we-bottombar">
                <input type="text" class="we-summary-input" id="we-summary" autocomplete="off" placeholder="Describe qué cambiaste">
                <label class="we-minor"><input type="checkbox" id="we-minor"> Es una edición menor</label>
                <span class="we-status-msg" id="we-status"></span>
                <button class="button" id="we-cancel">Cancelar</button>
                <button class="button default" id="we-submit">Guardar</button>
            </div>
            <div class="we-license">Las contribuciones se envían a los moderadores y se revisan antes de publicarse.</div>
        </section>

        <!-- ───────── WIKI: MIS SOLICITUDES ───────── -->
        <section id="view-wiki-requests" class="wiki-view" style="display:none">
            <div id="wiki-requests" class="wiki-cards"></div>
        </section>

        <!-- ───────── WIKI: MODERACIÓN (admin) ───────── -->
        <section id="view-wiki-mod" class="wiki-view" style="display:none">
            <div id="wiki-mod" class="wiki-cards"></div>
        </section>

    </div>
</div>

<script>
/* Flag solo-dev (lo emite PHP con melon_funcionesDev). Fuera de dev, false →
   MelonArchive abre directo al archivo de vídeos y la wiki queda inerte. */
const WIKI_ENABLED = <?php echo $WIKI ? 'true' : 'false'; ?>;
const WIKI_API = '../assets/wiki/api.php';
const YT_API   = '../assets/yt-archive.php';

const navHome   = document.getElementById('nav-home');
const navBack   = document.getElementById('nav-back');
const navAction = document.getElementById('nav-action');
const breadcrumb= document.getElementById('archive-breadcrumb');

let IS_ADMIN = false;
let editorCtx = null;       /* {pageId|null, slug, title, body} mientras editas */

/* ── Router de vistas ───────────────────────────────────────────── */
const VIEWS = ['home','archive','wiki-list','wiki-page','wiki-edit','wiki-requests','wiki-mod'];
function showView(name) {
    VIEWS.forEach(v => {
        const el = document.getElementById('view-' + v);
        if (el) el.style.display = (v === name) ? '' : 'none';
    });
}
function setNav(opts) {
    breadcrumb.textContent = opts.title || 'MelonArchive';
    navBack.disabled = !opts.back;
    navBack.onclick  = opts.back || null;
    if (opts.action) {
        navAction.style.display = '';
        navAction.textContent = opts.action.label;
        navAction.onclick = opts.action.fn;
    } else {
        navAction.style.display = 'none';
        navAction.onclick = null;
    }
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function wikiSlug(s) {
    s = (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    return s.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,150) || 'pagina';
}

/* ── API helpers ────────────────────────────────────────────────── */
async function wikiGet(action, params) {
    const qs = new URLSearchParams(Object.assign({action}, params || {})).toString();
    const r = await fetch(WIKI_API + '?' + qs, { credentials: 'same-origin' });
    return r.json();
}
async function wikiPost(action, body) {
    const r = await fetch(WIKI_API + '?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return r.json();
}

/* ── Render Markdown-lite seguro ────────────────────────────────── */
function wikiRender(text) {
    const lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
    let html = '', inP = false;
    function closeP(){ if (inP) { html += '</p>'; inP = false; } }
    function inline(s) {
        s = esc(s);
        /* imágenes ![alt](url) — antes que los enlaces para no confundir */
        s = s.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/g, function(_, a, u){
            return '<img class="wiki-img" src="' + esc(u) + '" alt="' + esc(a) + '" loading="lazy">';
        });
        /* enlaces internos [[Página]] → navega a la wiki */
        s = s.replace(/\[\[([^\]]+)\]\]/g, function(_, t){
            const slug = wikiSlug(t);
            return '<a href="#" class="wiki-ilink" data-slug="' + esc(slug) + '">' + esc(t) + '</a>';
        });
        /* enlaces [texto](url) */
        s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function(_, t, u){
            return '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(t) + '</a>';
        });
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
        s = s.replace(/_([^_]+)_/g, '<em>$1</em>');
        return s;
    }
    lines.forEach(function(ln){
        const t = ln.trim();
        if (t === '') { closeP(); return; }
        let m;
        if ((m = t.match(/^(#{1,3})\s+(.*)$/))) {
            closeP();
            const lvl = m[1].length + 1;   /* # → h2, ## → h3, ### → h4 */
            html += '<h' + lvl + '>' + inline(m[2]) + '</h' + lvl + '>';
            return;
        }
        if ((m = t.match(/^[-*]\s+(.*)$/))) {
            closeP();
            html += '<ul><li>' + inline(m[1]) + '</li></ul>';
            return;
        }
        if ((m = t.match(/^>\s?(.*)$/))) {
            closeP();
            html += '<blockquote>' + inline(m[1]) + '</blockquote>';
            return;
        }
        if (!inP) { html += '<p>'; inP = true; } else { html += '<br>'; }
        html += inline(t);
    });
    closeP();
    return html;
}

/* ── HOME (portada tipo Fandom) ─────────────────────────────────── */
function navGo(go) {
    if (go === 'archive')            openArchive();
    else if (go === 'wiki-list')     openWikiList();
    else if (go === 'wiki-new')      openEditor(null);
    else if (go === 'wiki-requests') openMyRequests();
    else if (go === 'wiki-mod')      openModeration();
}
function goHome() {
    showView('home');
    setNav({ title: 'MelonArchive' });
    stopVideo();
    /* Rellena el contador de páginas + la barra lateral de recientes. */
    wikiGet('list-pages').then(function(res){
        const pages = (res && res.pages) || [];
        const pc = document.getElementById('wh-pagecount');
        if (pc) pc.textContent = pages.length + (pages.length === 1 ? ' página' : ' páginas');
        const rec = document.getElementById('wh-recent');
        if (!rec) return;
        if (!pages.length) { rec.innerHTML = '<div class="wiki-empty">Aún no hay páginas.</div>'; return; }
        const recent = pages.slice().sort(function(a, b){ return (b.updatedAt || '').localeCompare(a.updatedAt || ''); }).slice(0, 8);
        rec.innerHTML = recent.map(function(p){
            return '<a href="#" class="wh-recent-item" data-slug="' + esc(p.slug) + '">' + esc(p.title) + '</a>';
        }).join('');
    });
}
/* Click en tiles, pestañas de navegación y recientes → navegar. */
document.getElementById('view-home').addEventListener('click', function(e){
    const go = e.target.closest('[data-go]');
    if (go) { navGo(go.dataset.go); return; }
    const rec = e.target.closest('.wh-recent-item');
    if (rec) { e.preventDefault(); openPage(rec.dataset.slug); }
});
navHome.addEventListener('click', goHome);

/* ════════════════════════════════════════════════════════════════
   ARCHIVO DE VÍDEOS (YouTube) — la vista original
═══════════════════════════════════════════════════════════════════ */
const archiveBack    = navBack;       /* reusa el botón Atrás del toolbar */
const archiveGrid    = document.getElementById('archive-grid');
const archiveStatus  = document.getElementById('archive-status');
const archiveIframe  = document.getElementById('archive-iframe');
const archiveContent = document.getElementById('archive-content');
const archivePlayer  = document.getElementById('archive-player');
let archiveView = 'playlists';

function openArchive() {
    showView('archive');
    loadPlaylists();
}
function stopVideo() {
    archiveIframe.src = '';
    archivePlayer.style.display = 'none';
    archiveContent.style.display = 'flex';
    if (archiveView === 'video') archiveView = 'videos';
}
function archiveSetStatus(msg) { archiveStatus.textContent = msg; archiveGrid.innerHTML = ''; }

async function loadPlaylists() {
    archiveView = 'playlists';
    archivePlayer.style.display = 'none';
    archiveContent.style.display = 'flex';
    setNav({ title: 'Archivo · Playlists' });
    archiveSetStatus('Cargando playlists...');
    try {
        const res  = await fetch(YT_API + '?action=playlists');
        const data = await res.json();
        archiveStatus.textContent = '';
        if (data.error) { archiveStatus.textContent = data.error; return; }
        renderPlaylists(data.playlists || []);
    } catch(e) { archiveStatus.textContent = 'Error: ' + e.message; }
}
async function loadVideos(id, title) {
    archiveView = 'videos';
    setNav({ title: 'Archivo · ' + title, back: function(){ loadPlaylists(); } });
    archiveSetStatus('Cargando vídeos...');
    try {
        const res  = await fetch(YT_API + '?action=videos&list=' + id);
        const data = await res.json();
        archiveStatus.textContent = '';
        if (data.error) { archiveStatus.textContent = data.error; return; }
        renderVideos(data.videos || [], title);
    } catch(e) { archiveStatus.textContent = 'Error: ' + e.message; }
}
function renderPlaylists(list) {
    archiveGrid.innerHTML = '';
    if (!list.length) { archiveStatus.textContent = 'No se encontraron playlists.'; return; }
    list.forEach(pl => {
        const el = document.createElement('div');
        el.className = 'archive-item';
        el.innerHTML = `<img src="${esc(pl.thumb)}" alt="" loading="lazy">
            <div class="archive-item-title">${esc(pl.title)}</div>
            <div class="archive-item-meta">${esc(pl.count)}</div>`;
        el.addEventListener('click', () => loadVideos(pl.id, pl.title));
        archiveGrid.appendChild(el);
    });
}
function renderVideos(list, plTitle) {
    archiveGrid.innerHTML = '';
    if (!list.length) { archiveStatus.textContent = 'No se encontraron vídeos.'; return; }
    list.forEach(v => {
        const el = document.createElement('div');
        el.className = 'archive-item';
        el.innerHTML = `<img src="${esc(v.thumb)}" alt="" loading="lazy">
            <div class="archive-item-title">${esc(v.title)}</div>
            <div class="archive-item-meta">${esc(v.duration)}</div>`;
        el.addEventListener('click', () => playVideo(v.id, plTitle));
        archiveGrid.appendChild(el);
    });
}
function playVideo(id, plTitle) {
    archiveView = 'video';
    setNav({ title: 'Archivo · reproduciendo', back: function(){ stopVideo(); setNav({ title: 'Archivo · ' + (plTitle||''), back: function(){ loadPlaylists(); } }); } });
    archiveContent.style.display = 'none';
    archivePlayer.style.display = 'flex';
    archiveIframe.src = 'https://www.youtube.com/embed/' + id + '?autoplay=1&rel=0';
}

/* Parent puede pedir parar la reproducción al cerrar la ventana. */
window.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'archive-stop') stopVideo();
});

/* ════════════════════════════════════════════════════════════════
   WIKI — lista / lectura / editor / solicitudes / moderación
═══════════════════════════════════════════════════════════════════ */
function fmtDate(s) {
    if (!s) return '';
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('es-ES', { day:'2-digit', month:'short', year:'numeric' });
}

/* ── Lista ── */
let searchTimer = null;
async function openWikiList(q) {
    showView('wiki-list');
    setNav({ title: 'Wiki · Explorar', back: goHome });
    const cont = document.getElementById('wiki-list');
    cont.innerHTML = '<div class="wiki-empty">Cargando…</div>';
    const res = await wikiGet('list-pages', q ? { q } : null);
    const pages = (res && res.pages) || [];
    if (!pages.length) {
        cont.innerHTML = '<div class="wiki-empty">' + (q ? 'Sin resultados.' : 'Todavía no hay páginas. ¡Crea la primera!') + '</div>';
        return;
    }
    cont.innerHTML = pages.map(p =>
        '<button class="wiki-row" data-slug="' + esc(p.slug) + '">' +
            '<span class="wiki-row-title">' + esc(p.title) + '</span>' +
            '<span class="wiki-row-meta">' + fmtDate(p.updatedAt) + '</span>' +
        '</button>'
    ).join('');
}
document.getElementById('wiki-list').addEventListener('click', function(e){
    const row = e.target.closest('.wiki-row');
    if (row) openPage(row.dataset.slug);
});
document.getElementById('wiki-search').addEventListener('input', function(){
    clearTimeout(searchTimer);
    const q = this.value.trim();
    searchTimer = setTimeout(function(){ openWikiList(q); }, 300);
});
document.getElementById('wiki-new-from-list').addEventListener('click', function(){ openEditor(null); });

/* ── Lectura (estructura Fandom: miga + título + columnas + ToC/infobox) ── */
async function openPage(slug) {
    showView('wiki-page');
    setNav({ title: 'Wiki', back: function(){ openWikiList(); } });
    const art   = document.getElementById('wiki-article');
    const toc   = document.getElementById('wp-toc');
    const info  = document.getElementById('wp-infobox');
    const bc    = document.getElementById('wp-breadcrumb');
    const ttl   = document.getElementById('wp-title');
    const editB = document.getElementById('wp-edit');
    toc.style.display = 'none'; info.style.display = 'none';
    bc.innerHTML = 'en: <a href="#" id="wp-bc-wiki">Wiki</a>';
    art.innerHTML = '<div class="wiki-empty">Cargando…</div>';
    ttl.textContent = '…';
    editB.style.display = 'none';

    const res = await wikiGet('get-page', { slug });
    const page = res && res.page;
    if (!page) {
        ttl.textContent = slug.replace(/-/g, ' ');
        art.innerHTML =
            '<div class="wiki-empty">Esta página no existe todavía.' +
            '<div style="margin-top:12px"><button class="button" id="wiki-create-missing">Crear "' + esc(slug) + '"</button></div></div>';
        const b = document.getElementById('wiki-create-missing');
        if (b) b.onclick = function(){ openEditor(null, slug.replace(/-/g,' ')); };
        return;
    }
    ttl.textContent = page.title;
    editB.style.display = '';
    editB.onclick = function(){ openEditor(page.id, page.title, page.body, page.slug); };
    art.innerHTML =
        '<div class="wiki-article-meta">Última edición: ' + fmtDate(page.updatedAt) + ' · ' + esc(page.updatedBy) + '</div>' +
        '<div class="wiki-article-body" id="wp-body">' + wikiRender(page.body) + '</div>';

    /* Índice de contenidos (Contents): construido a partir de los h2/h3. */
    const body = document.getElementById('wp-body');
    const heads = body.querySelectorAll('h2, h3');
    if (heads.length >= 2) {
        let items = '';
        heads.forEach(function(h, i){
            const id = 'sec-' + i;
            h.id = id;
            const lvl = h.tagName === 'H3' ? ' wp-toc-sub' : '';
            items += '<a href="#" class="wp-toc-item' + lvl + '" data-target="' + id + '">' +
                     (i + 1) + '. ' + esc(h.textContent) + '</a>';
        });
        toc.innerHTML = '<div class="wp-toc-head">Contenidos</div>' + items;
        toc.style.display = '';
    }
    /* Infobox: si la página tiene una imagen, la mostramos arriba en la barra lateral. */
    const firstImg = body.querySelector('img.wiki-img');
    if (firstImg) {
        info.innerHTML = '<img src="' + firstImg.getAttribute('src') + '" alt="">' +
                         '<div class="wp-infobox-cap">' + esc(page.title) + '</div>';
        info.style.display = '';
    }
}
/* Delegación: enlaces internos, ToC y miga de pan. */
document.getElementById('view-wiki-page').addEventListener('click', function(e){
    const il = e.target.closest('.wiki-ilink');
    if (il) { e.preventDefault(); openPage(il.dataset.slug); return; }
    const tocItem = e.target.closest('.wp-toc-item');
    if (tocItem) {
        e.preventDefault();
        const tgt = document.getElementById(tocItem.dataset.target);
        if (tgt) tgt.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    if (e.target.closest('#wp-bc-wiki')) { e.preventDefault(); openWikiList(); }
});

/* ── Editor (crear o editar) — estilo Fandom ── */
var weBody = document.getElementById('we-body');
function weAutoGrow() { weBody.style.height = 'auto'; weBody.style.height = (weBody.scrollHeight) + 'px'; }
function openEditor(pageId, title, body, slug) {
    showView('wiki-edit');
    editorCtx = { pageId: pageId || null, slug: slug || null };
    document.getElementById('we-title').value = title || '';
    weBody.value  = body || '';
    document.getElementById('we-summary').value = '';
    document.getElementById('we-status').textContent = '';
    document.getElementById('we-minor').checked = false;
    document.getElementById('we-style').value = '';
    weAutoGrow();
    setNav({
        title: pageId ? 'Editar página' : 'Crear página',
        back: function(){ pageId ? openPage(slug) : openWikiList(); }
    });
}
weBody.addEventListener('input', weAutoGrow);

/* Inserción de formato Markdown desde la barra de herramientas. */
function weWrap(token) {
    var ta = weBody, s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e) || 'texto';
    ta.value = ta.value.slice(0, s) + token + sel + token + ta.value.slice(e);
    ta.focus();
    ta.selectionStart = s + token.length;
    ta.selectionEnd   = s + token.length + sel.length;
    weAutoGrow();
}
function wePrefix(kind) {   /* 'ul' | 'ol' */
    var ta = weBody, s = ta.selectionStart, e = ta.selectionEnd;
    var startLine = ta.value.lastIndexOf('\n', s - 1) + 1;
    var block = ta.value.slice(startLine, e) || 'elemento';
    var n = 1;
    var out = block.split('\n').map(function(ln){
        var clean = ln.replace(/^\s*(?:[-*]\s+|\d+\.\s+)/, '');
        return (kind === 'ol' ? (n++) + '. ' : '- ') + clean;
    }).join('\n');
    ta.value = ta.value.slice(0, startLine) + out + ta.value.slice(e);
    ta.focus(); weAutoGrow();
}
function weBlockHash(hash) {   /* '' | '# ' | '## ' */
    var ta = weBody, s = ta.selectionStart;
    var startLine = ta.value.lastIndexOf('\n', s - 1) + 1;
    var endLine = ta.value.indexOf('\n', s); if (endLine === -1) endLine = ta.value.length;
    var line = ta.value.slice(startLine, endLine).replace(/^#{1,3}\s+/, '');
    ta.value = ta.value.slice(0, startLine) + hash + line + ta.value.slice(endLine);
    ta.focus(); weAutoGrow();
}
function weInsertAt(ins) {
    var ta = weBody, s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + ins + ta.value.slice(e);
    ta.focus(); ta.selectionStart = ta.selectionEnd = s + ins.length;
    weAutoGrow();
}
function weLink(internal) {
    var ta = weBody, s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e);
    var ins;
    if (internal) {
        ins = '[[' + (sel || 'Otra página') + ']]';
    } else {
        var url = prompt('URL del enlace:', 'https://');
        if (!url) return;
        ins = '[' + (sel || 'texto') + '](' + url + ')';
    }
    ta.value = ta.value.slice(0, s) + ins + ta.value.slice(e);
    ta.focus(); weAutoGrow();
}
function weImage() {
    var url = prompt('URL de la imagen (https://…):', 'https://');
    if (!url) return;
    var alt = prompt('Texto alternativo (opcional):', '') || '';
    weInsertAt('\n![' + alt + '](' + url + ')\n');
}
document.getElementById('we-toolbar').addEventListener('click', function(e){
    var btn = e.target.closest('.we-tb-btn');
    if (!btn) return;
    var fmt = btn.dataset.fmt;
    if (fmt === 'bold')        weWrap('**');
    else if (fmt === 'italic') weWrap('*');
    else if (fmt === 'ul')     wePrefix('ul');
    else if (fmt === 'ol')     wePrefix('ol');
    else if (fmt === 'link')   weLink(false);
    else if (fmt === 'ilink')  weLink(true);
    else if (fmt === 'image')  weImage();
    else if (fmt === 'cite')   weBlockquote();
    else if (fmt === 'undo')   { weBody.focus(); try { document.execCommand('undo'); } catch (_) {} }
    else if (fmt === 'redo')   { weBody.focus(); try { document.execCommand('redo'); } catch (_) {} }
    /* 'more' | 'indent' | 'insert' | 'menu' son decorativos (paridad visual
       con el editor de Fandom); no alteran el contenido. */
});
function weBlockquote() {
    var ta = weBody, s = ta.selectionStart, e = ta.selectionEnd;
    var startLine = ta.value.lastIndexOf('\n', s - 1) + 1;
    var block = ta.value.slice(startLine, e) || 'cita';
    var out = block.split('\n').map(function(ln){ return '> ' + ln.replace(/^>\s?/, ''); }).join('\n');
    ta.value = ta.value.slice(0, startLine) + out + ta.value.slice(e);
    ta.focus(); weAutoGrow();
}
document.getElementById('we-style').addEventListener('change', function(){
    weBlockHash(this.value);
    this.value = '';   /* el desplegable vuelve a "Texto normal" tras aplicar */
});
weBody.addEventListener('keydown', function(e){
    if (!(e.ctrlKey || e.metaKey)) return;
    var k = e.key.toLowerCase();
    if (k === 'b') { e.preventDefault(); weWrap('**'); }
    else if (k === 'i') { e.preventDefault(); weWrap('*'); }
});

document.getElementById('we-cancel').addEventListener('click', function(){
    if (editorCtx && editorCtx.pageId) openPage(editorCtx.slug); else openWikiList();
});
document.getElementById('we-submit').addEventListener('click', async function(){
    const title = document.getElementById('we-title').value.trim();
    const body  = document.getElementById('we-body').value.trim();
    const summary = document.getElementById('we-summary').value.trim();
    const st = document.getElementById('we-status');
    if (!title) { st.textContent = 'Ponle un título.'; return; }
    if (!body)  { st.textContent = 'El contenido no puede estar vacío.'; return; }
    this.disabled = true;
    st.textContent = 'Enviando…';
    const payload = { title, body, summary };
    if (editorCtx && editorCtx.pageId) payload.pageId = editorCtx.pageId;
    const res = await wikiPost('submit', payload);
    this.disabled = false;
    if (!res || res.error) { st.textContent = (res && res.error) || 'Error al enviar.'; return; }
    st.textContent = '';
    alert('Tu ' + (editorCtx && editorCtx.pageId ? 'edición' : 'página') + ' se ha enviado a los moderadores. Te avisaremos cuando la revisen.');
    openMyRequests();
});

/* ── Mis solicitudes ── */
async function openMyRequests() {
    showView('wiki-requests');
    setNav({ title: 'Wiki · Mis solicitudes', back: goHome });
    const cont = document.getElementById('wiki-requests');
    cont.innerHTML = '<div class="wiki-empty">Cargando…</div>';
    const res = await wikiGet('my-requests');
    const reqs = (res && res.requests) || [];
    if (!reqs.length) { cont.innerHTML = '<div class="wiki-empty">No has enviado ninguna solicitud todavía.</div>'; return; }
    cont.innerHTML = reqs.map(function(r){
        const badge = r.status === 'accepted' ? '<span class="wiki-badge ok">Aceptada</span>'
                    : r.status === 'declined' ? '<span class="wiki-badge no">Denegada</span>'
                    : '<span class="wiki-badge pend">Pendiente</span>';
        let extra = '';
        if (r.status === 'accepted' && r.pointsAwarded) extra += '<div class="wiki-card-pts">+' + r.pointsAwarded + ' autismo</div>';
        if (r.reason) extra += '<div class="wiki-card-reason"><b>Motivo:</b> ' + esc(r.reason) + '</div>';
        return '<div class="wiki-card">' +
            '<div class="wiki-card-head">' + badge +
                '<span class="wiki-card-title">' + esc(r.title) + '</span>' +
                '<span class="wiki-card-type">' + (r.isNew ? 'Nueva' : 'Edición') + '</span>' +
            '</div>' +
            '<div class="wiki-card-meta">Enviada: ' + fmtDate(r.createdAt) + (r.reviewedAt ? ' · Revisada: ' + fmtDate(r.reviewedAt) : '') + '</div>' +
            extra +
        '</div>';
    }).join('');
}

/* ── Moderación (admin) ── */
async function openModeration() {
    if (!IS_ADMIN) { goHome(); return; }
    showView('wiki-mod');
    setNav({ title: 'Wiki · Moderación', back: goHome });
    const cont = document.getElementById('wiki-mod');
    cont.innerHTML = '<div class="wiki-empty">Cargando…</div>';
    const res = await wikiGet('pending');
    const reqs = (res && res.requests) || [];
    refreshModCount(reqs.length);
    if (!reqs.length) { cont.innerHTML = '<div class="wiki-empty">No hay solicitudes pendientes. ✓</div>'; return; }
    cont.innerHTML = reqs.map(function(r){
        return '<div class="wiki-modcard" data-id="' + r.id + '">' +
            '<div class="wiki-card-head">' +
                '<span class="wiki-card-type">' + (r.isNew ? 'Nueva página' : 'Edición') + '</span>' +
                '<span class="wiki-card-title">' + esc(r.title) + '</span>' +
            '</div>' +
            '<div class="wiki-card-meta">Por ' + esc(r.author) + ' · ' + fmtDate(r.createdAt) + '</div>' +
            (r.summary ? '<div class="wiki-card-reason"><b>Comentario:</b> ' + esc(r.summary) + '</div>' : '') +
            '<div class="wiki-modcard-body">' + wikiRender(r.body) + '</div>' +
            '<input type="text" class="wiki-mod-reason" placeholder="Motivo (se le enviará al usuario)">' +
            '<div class="wiki-modcard-actions">' +
                '<button class="button default" data-act="accept">Aceptar</button>' +
                '<button class="button" data-act="decline">Denegar</button>' +
                '<span class="wiki-inline-msg" data-role="msg"></span>' +
            '</div>' +
        '</div>';
    }).join('');
}
document.getElementById('wiki-mod').addEventListener('click', async function(e){
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const card = btn.closest('.wiki-modcard');
    const id = parseInt(card.dataset.id, 10);
    const decision = btn.dataset.act;
    const reason = card.querySelector('.wiki-mod-reason').value.trim();
    const msg = card.querySelector('[data-role="msg"]');
    if (decision === 'decline' && !reason) {
        if (!confirm('Vas a denegar sin indicar motivo. ¿Continuar?')) return;
    }
    card.querySelectorAll('button').forEach(b => b.disabled = true);
    msg.textContent = 'Procesando…';
    const res = await wikiPost('review', { revisionId: id, decision, reason });
    if (!res || res.error) {
        msg.textContent = (res && res.error) || 'Error.';
        card.querySelectorAll('button').forEach(b => b.disabled = false);
        return;
    }
    card.style.opacity = '0.5';
    msg.textContent = decision === 'accept' ? ('Aceptada' + (res.points ? ' (+' + res.points + ')' : '')) : 'Denegada';
    setTimeout(function(){ openModeration(); }, 700);
});

function refreshModCount(n) {
    const badge = document.getElementById('mod-count');
    if (!badge) return;
    if (n > 0) { badge.textContent = n; badge.style.display = ''; }
    else badge.style.display = 'none';
}

/* ── Init ── */
(async function init(){
    /* En main (wiki desactivada) MelonArchive es solo el archivo de vídeos:
       ocultamos el botón Inicio/acción del portal y abrimos las playlists. */
    if (!WIKI_ENABLED) {
        navHome.style.display = 'none';
        navAction.style.display = 'none';
        openArchive();
        return;
    }
    goHome();
    try {
        const a = await wikiGet('am-admin');
        IS_ADMIN = !!(a && a.isAdmin);
        if (IS_ADMIN) {
            document.getElementById('tile-mod').style.display = '';
            document.getElementById('wh-nav-mod').style.display = '';
            const p = await wikiGet('pending');
            refreshModCount(((p && p.requests) || []).length);
        }
    } catch(e) {}
})();
</script>

</body>
</html>
