/* ──────────────────────────────────────────────────────────────────────
   CALENDARIO MÓVIL — JS port completo del desktop
   Reusa los mismos endpoints couple/api.php sin cambios.
   ────────────────────────────────────────────────────────────────────── */
(function() {
'use strict';

const CFG_RAW = window.__CM_CFG || {};
const API_BASE = CFG_RAW.API_BASE || '/assets/couple/api.php';
const parejaId = CFG_RAW.parejaId || 0;
const fechaInicio = CFG_RAW.fechaInicio || null;
const userLabel = CFG_RAW.userLabel || '';
const hasPareja = !!CFG_RAW.hasPareja;

const CFG = {
    sidebarDays:      14,
    expandPadMonths:  1,
    invitePollMinMs:  5000,
    invitePollMaxMs:  120000,
    countdownTickMs:  1000,
    ytRetryMs:        200,
    ytMaxRetries:     50,
};

/* ── Helpers fecha (LOCAL, NUNCA UTC) ── */
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
function parseISODate(s) {
    if (!s || typeof s !== 'string' || !ISO_DATE_RE.test(s)) return null;
    const p = s.split('-');
    const d = new Date(+p[0], +p[1]-1, +p[2]);
    return isNaN(d.getTime()) ? null : d;
}
function toISODate(d) {
    if (!d || isNaN(d.getTime())) return '';
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}
function startOfDay(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}
function escHTML(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function fmtFechaLarga(s) {
    const d = parseISODate(s);
    if (!d) return s || '';
    const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return d.getDate() + ' ' + meses[d.getMonth()].toLowerCase() + ' ' + d.getFullYear();
}

/* ── Estado global ── */
const hoy = new Date();
const hoyMidnight = startOfDay(hoy);
let currentYear = hoy.getFullYear();
let currentMonth = hoy.getMonth();
let momentosPorFecha = {};
let recordatoriosPorFecha = {};
let todosMomentos = [];
let todosRecordatorios = [];
const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

/* Días juntos contador. */
if (hasPareja && fechaInicio) {
    const inicio = parseISODate(fechaInicio);
    if (inicio) {
        const dias = Math.floor((hoyMidnight - inicio) / 86400000);
        const el = document.getElementById('cm-dias');
        if (el) el.textContent = dias;
    }
}

/* ── apiFetch helper ── */
async function apiFetch(url, opts) {
    try {
        const r = await fetch(url, opts);
        if (!r.ok) return { ok: false, status: r.status };
        const d = await r.json();
        return { ok: true, data: d };
    } catch(_) {
        return { ok: false };
    }
}

/* ── Toast ── */
function toast(msg, ms) {
    const t = document.createElement('div');
    t.className = 'cm-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), ms || 2500);
}

/* ── Confirm modal nativo ── */
function cmConfirm(msg, title, onOk) {
    document.getElementById('cm-confirm-title').textContent = title || 'Confirmar';
    document.getElementById('cm-confirm-msg').textContent = msg;
    const modal = document.getElementById('cm-confirm-modal');
    modal.classList.add('visible');
    function cleanup(){ modal.classList.remove('visible'); okBtn.onclick = null; caBtn.onclick = null; }
    const okBtn = document.getElementById('cm-confirm-ok');
    const caBtn = document.getElementById('cm-confirm-cancel');
    okBtn.onclick = function() { cleanup(); onOk(); };
    caBtn.onclick = function() { cleanup(); };
}

/* ── Expansión de periodicidad (mismo algoritmo desktop) ── */
function expandirRecordatorios(lista) {
    const expandidos = [];
    const desde = new Date(currentYear, currentMonth - CFG.expandPadMonths, 1);
    const hasta = new Date(currentYear, currentMonth + CFG.expandPadMonths + 1, 0);
    const sidebarLimit = new Date(hoyMidnight);
    sidebarLimit.setDate(sidebarLimit.getDate() + CFG.sidebarDays);
    if (sidebarLimit > hasta) hasta.setTime(sidebarLimit.getTime());

    lista.forEach(r => {
        const p = (r.periodicidad || 'ninguna');
        const base = parseISODate(r.fecha);
        if (!base) return;
        if (p === 'ninguna') { expandidos.push(r); return; }
        const effDesde = desde > base ? desde : base;
        let cursor;
        if (p === 'anual') {
            cursor = new Date(effDesde.getFullYear(), base.getMonth(), base.getDate());
            if (cursor < effDesde) cursor.setFullYear(cursor.getFullYear() + 1);
        } else if (p === 'mensual') {
            const dayBase = base.getDate();
            cursor = new Date(effDesde.getFullYear(), effDesde.getMonth(), 1);
            while (cursor < effDesde) cursor.setMonth(cursor.getMonth() + 1);
            const lastDay = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
            cursor.setDate(Math.min(dayBase, lastDay));
            if (cursor < effDesde) cursor.setMonth(cursor.getMonth() + 1);
        } else if (p === 'semanal') {
            cursor = new Date(effDesde);
            const dowBase = base.getDay();
            const dowDesde = cursor.getDay();
            const diff = (dowBase - dowDesde + 7) % 7;
            cursor.setDate(cursor.getDate() + diff);
        } else return;

        while (cursor <= hasta) {
            expandidos.push(Object.assign({}, r, { fecha: toISODate(cursor), _periodico: true }));
            if (p === 'anual') cursor.setFullYear(cursor.getFullYear() + 1);
            else if (p === 'mensual') {
                const targetDay = base.getDate();
                cursor.setDate(1);
                cursor.setMonth(cursor.getMonth() + 1);
                const lastDay = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
                cursor.setDate(Math.min(targetDay, lastDay));
            }
            else if (p === 'semanal') cursor.setDate(cursor.getDate() + 7);
            else break;
        }
    });
    return expandidos;
}

/* ── Cargar todo: fetch desde server (async) + render inicial.
   Re-expande recordatorios y re-renderiza. Solo se llama al cargar la
   app y tras crear/eliminar elementos. La navegación mes-a-mes usa
   recalcAndRender() (síncrono) porque los datos ya están en memoria. */
async function cargarTodo() {
    /* Sin pareja también carga (como el escritorio): pareja_id=0 trae los
       momentos y recordatorios personales y los cumpleaños de los amigos. */
    const [mResp, rResp] = await Promise.all([
        apiFetch(API_BASE + '?action=get-momentos&pareja_id=' + parejaId),
        apiFetch(API_BASE + '?action=get-recordatorios&pareja_id=' + parejaId),
    ]);
    todosMomentos     = (mResp.ok && Array.isArray(mResp.data)) ? mResp.data : [];
    todosRecordatorios = (rResp.ok && Array.isArray(rResp.data)) ? rResp.data : [];
    recalcAndRender();
}

/* Solo re-expande recordatorios para el mes visible y re-renderiza —
   sin red. Usado por cmAvanzarMes (swipe) para cambio instantáneo. */
function recalcAndRender() {
    momentosPorFecha = {};
    todosMomentos.forEach(m => {
        if (!momentosPorFecha[m.fecha]) momentosPorFecha[m.fecha] = [];
        momentosPorFecha[m.fecha].push(m);
    });
    const expandidos = expandirRecordatorios(todosRecordatorios);
    recordatoriosPorFecha = {};
    expandidos.forEach(r => {
        if (!recordatoriosPorFecha[r.fecha]) recordatoriosPorFecha[r.fecha] = [];
        recordatoriosPorFecha[r.fecha].push(r);
    });
    renderAllGrids();
    renderSidebar(expandidos);
}

/* Renderiza UN grid (cualquier elemento) para un mes/año dado. Lee
   los maps globales momentosPorFecha/recordatoriosPorFecha. */
function renderGridIntoEl(grid, year, month) {
    if (!grid) return;
    grid.innerHTML = '';
    const first = new Date(year, month, 1);
    const last  = new Date(year, month + 1, 0);
    let startDow = (first.getDay() + 6) % 7;
    const monthDays = last.getDate();
    /* SIEMPRE 5 semanas (35 celdas) → altura fija independiente del mes.
       Para los pocos meses cuyo natural ocupa 6 semanas (31 días empezando
       sábado/domingo, ~3 al año), desplazamos el grid 7 días hacia atrás:
       la primera semana queda "off-shift" y el mes entero encaja en las
       5 filas visibles. Los días 1-3 que caerían en la fila 0 se muestran
       en la última columna de la fila 5 como overflow del mes. */
    const totalCells = 35;
    if (startDow + monthDays > totalCells) startDow -= 7;

    for (let i = 0; i < totalCells; i++) {
        const dayNum = i - startDow + 1;
        const date = new Date(year, month, dayNum);
        const fechaStr = toISODate(date);
        const isOther = dayNum < 1 || dayNum > monthDays;
        const isToday = date.toDateString() === hoy.toDateString();
        const cell = document.createElement('div');
        cell.className = 'cm-cell';
        if (isOther) cell.classList.add('cm-cell-other');
        if (isToday) cell.classList.add('cm-cell-today');
        cell.textContent = date.getDate();

        const momArr = momentosPorFecha[fechaStr] || [];
        const recArr = recordatoriosPorFecha[fechaStr] || [];
        const momFoto = momArr.find(m => m && m.foto);
        if (momFoto) {
            const fotoUrl = obtenerUrlFoto(momFoto.foto);
            if (fotoUrl) {
                cell.style.backgroundImage = 'url("' + fotoUrl + '")';
                cell.classList.add('cm-cell-foto');
            }
        }
        if (momArr.length || recArr.length) {
            const dotWrap = document.createElement('span');
            dotWrap.className = 'cm-cell-dot';
            if (momArr.length) { const d = document.createElement('span'); d.className = 'd-mom'; dotWrap.appendChild(d); }
            if (recArr.length) { const d = document.createElement('span'); d.className = 'd-rec'; dotWrap.appendChild(d); }
            cell.appendChild(dotWrap);
        }
        cell.addEventListener('click', () => abrirSheetDia(fechaStr));
        grid.appendChild(cell);
    }
}

/* Renderiza los 3 grids del slider (prev/current/next) + label.
   Resetea el transform del slider sin animación para que el grid
   actual quede centrado tras un cambio de mes. */
function renderAllGrids() {
    document.getElementById('cm-month-label').textContent = meses[currentMonth] + ' ' + currentYear;
    const slider = document.getElementById('cm-grid-slider');
    if (!slider) {
        renderGridIntoEl(document.getElementById('cm-grid'), currentYear, currentMonth);
        return;
    }
    const grids = slider.querySelectorAll('.cm-grid');
    let pm = currentMonth - 1, py = currentYear;
    if (pm < 0) { pm = 11; py--; }
    let nm = currentMonth + 1, ny = currentYear;
    if (nm > 11) { nm = 0; ny++; }
    renderGridIntoEl(grids[0], py, pm);
    renderGridIntoEl(grids[1], currentYear, currentMonth);
    renderGridIntoEl(grids[2], ny, nm);
    /* Reset transform a centro SIN animar (evita "salto" visible). */
    slider.classList.add('cm-swiping');
    slider.style.transform = 'translateX(-33.333%)';
    /* Force reflow para que el cambio se aplique antes de reactivar transición. */
    void slider.offsetHeight;
    slider.classList.remove('cm-swiping');
}

/* Backwards-compat — algún caller podría llamar renderGrid() antiguo. */
function renderGrid() { renderAllGrids(); }

/* ── Resolver URL de foto (mismo helper que desktop) ── */
function obtenerUrlFoto(foto) {
    if (!foto) return '';
    if (/^https?:\/\//i.test(foto)) return foto;
    return '../../uploads/momentos/' + foto;
}

/* ── Render sidebar / lista de próximos recordatorios ── */
function renderSidebar(expandidos) {
    const div = document.getElementById('cm-rec-list');
    const hoyStr = toISODate(hoyMidnight);
    const limite = new Date(hoyMidnight);
    limite.setDate(limite.getDate() + CFG.sidebarDays);
    const limiteStr = toISODate(limite);
    const lista = expandidos.filter(r => r && r.fecha >= hoyStr && r.fecha <= limiteStr);
    if (!lista.length) {
        div.innerHTML = '<p style="font-size:11px;color:var(--text-faint);">Sin recordatorios en las próximas 2 semanas.</p>';
        return;
    }
    lista.sort((a,b) => a.fecha.localeCompare(b.fecha));
    div.innerHTML = '';
    lista.forEach(r => {
        const recDate = parseISODate(r.fecha);
        if (!recDate) return;
        const diasRestantes = Math.round((recDate - hoyMidnight) / 86400000);
        const cuandoStr = diasRestantes === 0 ? '¡Hoy!' : 'En ' + diasRestantes + ' día' + (diasRestantes === 1 ? '' : 's');
        const periodicoLabel = r.periodicidad && r.periodicidad !== 'ninguna' ? ' · 🔁 ' + escHTML(r.periodicidad) : '';
        const item = document.createElement('div');
        item.className = 'cm-rec-item';
        item.innerHTML =
            '<div class="cm-rec-item-info"><strong>' + escHTML(r.titulo) + '</strong>' +
            '<span class="when">' + escHTML(r.fecha) + ' · ' + cuandoStr + periodicoLabel + '</span>' +
            (r.descripcion ? '<span class="when" style="color:var(--text);margin-top:4px;">' + escHTML(r.descripcion) + '</span>' : '') +
            '</div>' +
            '<div class="cm-rec-item-btns">' +
                '<button class="button" data-act="ver" type="button">👁</button>' +
                /* Cumpleaños de amigos: salen solos del perfil, sin ✕. */
                (r.cumple ? '' : '<button class="button" data-act="del" type="button">✕</button>') +
            '</div>';
        item.querySelector('[data-act="ver"]').addEventListener('click', () => abrirCountdown(r));
        if (!r.cumple) item.querySelector('[data-act="del"]').addEventListener('click', () => eliminarRecordatorio(r.id));
        div.appendChild(item);
    });
}

/* ── Bottom sheet del día ── */
function abrirSheetDia(fecha) {
    const momentos = momentosPorFecha[fecha] || [];
    const recordatorios = recordatoriosPorFecha[fecha] || [];
    document.getElementById('cm-sheet-fecha').textContent = fmtFechaLarga(fecha);
    const body = document.getElementById('cm-sheet-body');
    body.innerHTML = '';

    if (!momentos.length && !recordatorios.length) {
        const empty = document.createElement('p');
        empty.style.cssText = 'font-size:12px;color:var(--text-faint);text-align:center;padding:14px 0;';
        empty.textContent = 'Sin momentos ni recordatorios este día.';
        body.appendChild(empty);
    }

    if (momentos.length) {
        const h = document.createElement('div');
        h.className = 'cm-section-title momentos';
        h.innerHTML = '<img src="../../assets/img/appIcons/galeriaIcon.png" alt="">Momentos';
        body.appendChild(h);
        momentos.forEach(m => body.appendChild(buildMomentoCard(m)));
    }

    if (recordatorios.length) {
        const h = document.createElement('div');
        h.className = 'cm-section-title recs';
        h.innerHTML = '<img src="../../assets/img/appIcons/bellIcon.png" alt="">Recordatorios';
        body.appendChild(h);
        recordatorios.forEach(r => body.appendChild(buildRecRow(r)));
    }

    /* Botones de añadir rápidos (pre-rellenan fecha) */
    const quick = document.createElement('div');
    quick.style.cssText = 'display:flex;gap:6px;margin-top:12px;';
    quick.innerHTML =
        '<button class="button" id="cm-quick-mom" type="button" style="flex:1;min-height:32px;font-size:11px;">+ Momento</button>' +
        '<button class="button" id="cm-quick-rec" type="button" style="flex:1;min-height:32px;font-size:11px;">+ Recordatorio</button>';
    body.appendChild(quick);
    document.getElementById('cm-quick-mom').onclick = function() {
        cerrarSheet();
        abrirModalMomento(fecha);
    };
    document.getElementById('cm-quick-rec').onclick = function() {
        cerrarSheet();
        abrirModalRecordatorio(fecha);
    };

    document.getElementById('cm-sheet-overlay').classList.add('visible');
}
function cerrarSheet() {
    document.getElementById('cm-sheet-overlay').classList.remove('visible');
}
document.getElementById('cm-sheet-close').addEventListener('click', cerrarSheet);
document.getElementById('cm-sheet-overlay').addEventListener('click', function(e) {
    if (e.target === this) cerrarSheet();
});

function buildMomentoCard(m) {
    const card = document.createElement('div');
    card.className = 'cm-mom';
    const fotoUrl = m.foto ? obtenerUrlFoto(m.foto) : '';
    if (fotoUrl) {
        const img = document.createElement('img');
        img.className = 'cm-mom-img';
        img.src = fotoUrl;
        img.onerror = () => img.remove();
        card.appendChild(img);
    }
    const row = document.createElement('div');
    row.className = 'cm-mom-row';
    row.innerHTML =
        '<div class="info"><strong>' + escHTML(m.titulo) + '</strong>' +
        (m.descripcion ? '<br><span class="muted">' + escHTML(m.descripcion) + '</span>' : '') +
        '</div>' +
        '<button class="button" style="min-width:32px;padding:4px 6px;font-size:11px;" data-del>✕</button>';
    row.querySelector('[data-del]').addEventListener('click', () => eliminarMomento(m.id));
    card.appendChild(row);
    return card;
}

function buildRecRow(r) {
    const row = document.createElement('div');
    row.className = 'cm-rec';
    const periodicoLabel = (r.periodicidad && r.periodicidad !== 'ninguna')
        ? ' <span class="muted">· 🔁 ' + escHTML(r.periodicidad) + '</span>'
        : '';
    row.innerHTML =
        '<div class="cm-rec-info"><strong>' + escHTML(r.titulo) + '</strong>' + periodicoLabel +
        (r.descripcion ? '<br>' + escHTML(r.descripcion) : '') +
        '</div>' +
        '<div class="cm-rec-btns">' +
            '<button class="button" data-ver type="button">👁</button>' +
            (r.cumple ? '' : '<button class="button" data-del type="button">✕</button>') +
        '</div>';
    row.querySelector('[data-ver]').addEventListener('click', () => { cerrarSheet(); abrirCountdown(r); });
    if (!r.cumple) row.querySelector('[data-del]').addEventListener('click', () => eliminarRecordatorio(r.id));
    return row;
}

/* ── Modales: Momento ── */
function abrirModalMomento(fecha) {
    document.getElementById('cm-mom-titulo').value = '';
    document.getElementById('cm-mom-desc').value = '';
    document.getElementById('cm-mom-foto-url').value = '';
    document.getElementById('cm-mom-fecha').value = fecha || toISODate(hoy);
    document.getElementById('cm-status').textContent = '';
    /* Forzar update del display del date widget. */
    if (document.getElementById('cm-mom-fecha')._widget) {
        document.getElementById('cm-mom-fecha')._widget.refresh();
    }
    document.getElementById('cm-mom-modal').classList.add('visible');
}
function cerrarModalMomento() { document.getElementById('cm-mom-modal').classList.remove('visible'); }
document.getElementById('cm-mom-close').addEventListener('click', cerrarModalMomento);
document.getElementById('cm-mom-cancel').addEventListener('click', cerrarModalMomento);
document.getElementById('cm-mom-modal').addEventListener('click', function(e){
    if (e.target === this) cerrarModalMomento();
});
document.getElementById('cm-add-mom-btn').addEventListener('click', () => abrirModalMomento(toISODate(hoy)));

document.getElementById('cm-mom-foto-upload-btn').addEventListener('click', () => document.getElementById('cm-mom-foto').click());
document.getElementById('cm-mom-foto').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('cm-mom-foto-url').value = '[Archivo: ' + file.name + ']';
    }
});

document.getElementById('cm-mom-save').addEventListener('click', async function() {
    const titulo = document.getElementById('cm-mom-titulo').value.trim();
    const fecha  = document.getElementById('cm-mom-fecha').value;
    const desc   = document.getElementById('cm-mom-desc').value.trim();
    const fileEl = document.getElementById('cm-mom-foto');
    const status = document.getElementById('cm-status');
    if (!titulo || !fecha) {
        status.style.color = '#c00';
        status.textContent = 'Título y fecha son obligatorios.';
        return;
    }
    let foto = '';
    if (fileEl.files[0]) {
        const formData = new FormData();
        formData.append('foto', fileEl.files[0]);
        formData.append('pareja_id', parejaId);
        status.style.color = 'inherit';
        status.textContent = 'Subiendo foto…';
        try {
            const fr = await fetch(API_BASE + '?action=upload-foto', { method: 'POST', body: formData });
            const fd = await fr.json();
            if (fd && fd.foto) foto = fd.foto;
        } catch(_) {}
    } else {
        const fotoUrl = document.getElementById('cm-mom-foto-url').value.trim();
        if (fotoUrl && !fotoUrl.startsWith('[Archivo:')) foto = fotoUrl;
    }
    status.textContent = 'Guardando…';
    const resp = await apiFetch(API_BASE + '?action=save-momento', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pareja_id: parejaId, titulo, fecha, descripcion: desc, foto }),
    });
    if (resp.ok && resp.data && resp.data.ok) {
        cerrarModalMomento();
        toast('Momento guardado');
        cargarTodo();
    } else {
        status.style.color = '#c00';
        status.textContent = (resp.data && resp.data.error) || 'Error al guardar.';
    }
});

/* ── Modal Recordatorio ── */
function abrirModalRecordatorio(fecha) {
    document.getElementById('cm-rec-titulo').value = '';
    document.getElementById('cm-rec-desc').value = '';
    document.getElementById('cm-rec-fecha').value = fecha || toISODate(hoy);
    document.getElementById('cm-rec-periodicidad').value = 'ninguna';
    document.getElementById('cm-rec-status').textContent = '';
    if (document.getElementById('cm-rec-fecha')._widget) {
        document.getElementById('cm-rec-fecha')._widget.refresh();
    }
    if (document.getElementById('cm-rec-periodicidad')._widget) {
        document.getElementById('cm-rec-periodicidad')._widget.refresh();
    }
    document.getElementById('cm-rec-modal').classList.add('visible');
}
function cerrarModalRecordatorio() { document.getElementById('cm-rec-modal').classList.remove('visible'); }
document.getElementById('cm-rec-close').addEventListener('click', cerrarModalRecordatorio);
document.getElementById('cm-rec-cancel').addEventListener('click', cerrarModalRecordatorio);
document.getElementById('cm-rec-modal').addEventListener('click', function(e){
    if (e.target === this) cerrarModalRecordatorio();
});
document.getElementById('cm-add-rec-btn').addEventListener('click', () => abrirModalRecordatorio(toISODate(hoy)));

document.getElementById('cm-rec-save').addEventListener('click', async function() {
    const titulo = document.getElementById('cm-rec-titulo').value.trim();
    const fecha  = document.getElementById('cm-rec-fecha').value;
    const desc   = document.getElementById('cm-rec-desc').value.trim();
    const periodicidad = document.getElementById('cm-rec-periodicidad').value;
    const status = document.getElementById('cm-rec-status');
    if (!titulo || !fecha) {
        status.style.color = '#c00';
        status.textContent = 'Título y fecha son obligatorios.';
        return;
    }
    status.style.color = 'inherit';
    status.textContent = 'Guardando…';
    const resp = await apiFetch(API_BASE + '?action=save-recordatorio', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pareja_id: parejaId, titulo, fecha, descripcion: desc, periodicidad }),
    });
    if (resp.ok && resp.data && resp.data.ok) {
        cerrarModalRecordatorio();
        toast('Recordatorio guardado');
        cargarTodo();
    } else {
        status.style.color = '#c00';
        status.textContent = (resp.data && resp.data.error) || 'Error al guardar.';
    }
});

/* ── Eliminar momento/recordatorio ── */
async function eliminarMomento(id) {
    cmConfirm('¿Eliminar este momento?', 'Eliminar momento', async () => {
        const resp = await apiFetch(API_BASE + '?action=delete-momento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        if (resp.ok && resp.data && resp.data.ok) {
            cerrarSheet();
            toast('Momento eliminado');
            cargarTodo();
        } else toast('Error al eliminar');
    });
}
async function eliminarRecordatorio(id) {
    cmConfirm('¿Eliminar este recordatorio?', 'Eliminar recordatorio', async () => {
        const resp = await apiFetch(API_BASE + '?action=delete-recordatorio', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        if (resp.ok && resp.data && resp.data.ok) {
            cerrarSheet();
            toast('Recordatorio eliminado');
            cargarTodo();
        } else toast('Error al eliminar');
    });
}

/* ── Navegación mes por SWIPE horizontal con preview de los meses
   vecinos. El slider tiene 3 grids (prev/curr/next) y se translada para
   mostrar el vecino mientras el dedo arrastra. Al soltar:
     · si el dx supera el threshold (35% del ancho) → animar hasta el
       vecino y commit (cmAvanzarMes), que re-renderiza y resetea
       transform sin animación
     · si no → spring back al centro */
function cmAvanzarMes(dir) {
    currentMonth += dir;
    if (currentMonth < 0)   { currentMonth = 11; currentYear--; }
    if (currentMonth > 11)  { currentMonth = 0;  currentYear++; }
    recalcAndRender();   /* síncrono — sin red */
}
(function attachSwipe() {
    const wrap   = document.getElementById('cm-grid-wrap');
    const slider = document.getElementById('cm-grid-slider');
    if (!wrap || !slider) return;
    let sx = 0, sy = 0, st = 0, active = false, horizontal = false;
    let wrapW = 0, animating = false;

    function setTransform(pct) {
        slider.style.transform = 'translateX(' + pct + '%)';
    }
    function springBack() {
        slider.classList.remove('cm-swiping');
        setTransform(-33.333);
    }

    wrap.addEventListener('touchstart', (e) => {
        if (animating) return;
        if (e.touches.length !== 1) { active = false; return; }
        const t = e.touches[0];
        sx = t.clientX; sy = t.clientY; st = Date.now();
        active = true; horizontal = false;
        wrapW = wrap.offsetWidth || 1;
        slider.classList.add('cm-swiping');   /* desactiva transición */
    }, { passive: true });

    wrap.addEventListener('touchmove', (e) => {
        if (!active) return;
        const t = e.touches[0];
        const dx = t.clientX - sx;
        const dy = t.clientY - sy;
        /* Decide eje en el primer movimiento significativo. */
        if (!horizontal && Math.abs(dx) > 10) {
            horizontal = Math.abs(dx) > Math.abs(dy);
            if (!horizontal) { active = false; springBack(); return; }
        }
        if (!horizontal) return;
        /* Convierte dx (px) a % del wrap y aplica al slider. La base es
           -33.333% (centro); sumamos dx/wrapW * 33.333 para alinear el
           movimiento del slider con el del dedo (1:1). */
        const pctOffset = (dx / wrapW) * 33.333;
        setTransform(-33.333 + pctOffset);
    }, { passive: true });

    wrap.addEventListener('touchend', (e) => {
        if (!active) return;
        active = false;
        const t = (e.changedTouches && e.changedTouches[0]) || null;
        if (!t || !horizontal) { springBack(); return; }
        const dx = t.clientX - sx;
        const dt = Date.now() - st;
        /* Commit si dx > 35% del ancho O si fue un flick rápido (<300ms y >40px). */
        const isFlick     = dt < 300 && Math.abs(dx) > 40;
        const isLongSwipe = Math.abs(dx) > wrapW * 0.35;
        if (!isFlick && !isLongSwipe) { springBack(); return; }
        /* Anima al vecino con transición habilitada. */
        slider.classList.remove('cm-swiping');
        const target = dx > 0 ? 0 : -66.666;      /* derecha → prev (0%), izq → next (-66.666%) */
        const dir    = dx > 0 ? -1 : 1;            /* derecha = mes anterior */
        animating = true;
        setTransform(target);
        /* En la transitionend ejecutamos el commit. one-time listener.
           Algunos browsers no disparan transitionend si el valor era ya
           el actual — fallback con setTimeout 280ms. */
        let committed = false;
        function commit() {
            if (committed) return;
            committed = true;
            slider.removeEventListener('transitionend', commit);
            cmAvanzarMes(dir);
            animating = false;
        }
        slider.addEventListener('transitionend', commit, { once: true });
        setTimeout(commit, 280);
    }, { passive: true });

    wrap.addEventListener('touchcancel', () => {
        active = false;
        springBack();
    }, { passive: true });
})();

/* ── "‹ Menú" → vuelve al shell de mobile.php. Mismo handler que las
   otras apps móviles (tienda, perfil…): postMessage('shell:back') si
   está embebido en iframe, history.back / location.href si standalone.
   El listener del shell vive en mobile.php (busca d.type === 'shell:back').
   Aplicado al link de la status-bar (#cm-back) Y a la X de la title-bar
   Win98 (#cm-back-top) — ambos vuelven al menú. */
function cmGoMenu(e) {
    if (e) e.preventDefault();
    if (window.parent && window.parent !== window) {
        try { window.parent.postMessage({ type: 'shell:back' }, '*'); return; } catch(_){}
    }
    try { history.back(); } catch(_) { location.href = '../../mobile.php'; }
}
document.getElementById('cm-back').addEventListener('click', cmGoMenu);
var __cmTop = document.getElementById('cm-back-top');
if (__cmTop) __cmTop.addEventListener('click', cmGoMenu);

/* ─────────────────────────────────────────────────────────
   COUNTDOWN FULLSCREEN — port mínimo del desktop
   ───────────────────────────────────────────────────────── */
let countdownTimer = null;
let countdownYtPlayer = null;
let countdownYtReady = false;
let countdownTargetMs = 0;

/* Carga YT API si no está. */
(function loadYT() {
    if (window.YT && window.YT.Player) { countdownYtReady = true; return; }
    window.onYouTubeIframeAPIReady = function() { countdownYtReady = true; };
})();

/* Calcula la próxima ocurrencia de la fecha base con la periodicidad. */
function siguienteOcurrencia(r) {
    const base = parseISODate(r.fecha);
    if (!base) return null;
    const today = hoyMidnight;
    if (base >= today) return base;
    const p = r.periodicidad || 'ninguna';
    if (p === 'ninguna') return base;
    let cursor = new Date(base);
    while (cursor < today) {
        if (p === 'anual') cursor.setFullYear(cursor.getFullYear() + 1);
        else if (p === 'mensual') cursor.setMonth(cursor.getMonth() + 1);
        else if (p === 'semanal') cursor.setDate(cursor.getDate() + 7);
        else break;
    }
    return cursor;
}

function abrirCountdown(r) {
    const target = siguienteOcurrencia(r);
    if (!target) return;
    countdownTargetMs = target.getTime();
    document.getElementById('cm-cd-titulo').textContent = r.titulo || 'Recordatorio';
    document.getElementById('cm-cd-fecha').textContent = toISODate(target);
    document.getElementById('cm-countdown').classList.add('visible');
    tickCountdown();
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(tickCountdown, CFG.countdownTickMs);
    /* Música opcional si el server retorna URL — opcional, dejado por compat. */
}

function tickCountdown() {
    const now = Date.now();
    let delta = countdownTargetMs - now;
    const status = document.getElementById('cm-cd-status');
    if (delta < 0) {
        status.textContent = '¡HOY!';
        document.getElementById('cm-cd-digits').innerHTML = '';
        return;
    }
    status.textContent = 'QUEDAN';
    const dias = Math.floor(delta / 86400000);
    delta -= dias * 86400000;
    const horas = Math.floor(delta / 3600000);
    delta -= horas * 3600000;
    const mins = Math.floor(delta / 60000);
    delta -= mins * 60000;
    const segs = Math.floor(delta / 1000);
    document.getElementById('cm-cd-digits').innerHTML =
        '<div class="cd-cell"><div class="cd-cell-num">' + String(dias).padStart(2,'0') + '</div><div class="cd-cell-lbl">DÍAS</div></div>' +
        '<div class="cd-cell"><div class="cd-cell-num">' + String(horas).padStart(2,'0') + '</div><div class="cd-cell-lbl">HRS</div></div>' +
        '<div class="cd-cell"><div class="cd-cell-num">' + String(mins).padStart(2,'0') + '</div><div class="cd-cell-lbl">MIN</div></div>' +
        '<div class="cd-cell"><div class="cd-cell-num">' + String(segs).padStart(2,'0') + '</div><div class="cd-cell-lbl">SEG</div></div>';
}

document.getElementById('cm-countdown-close').addEventListener('click', function() {
    document.getElementById('cm-countdown').classList.remove('visible');
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
});

/* ─────────────────────────────────────────────────────────
   PARTNER INVITE — enviar + recibir (polling con backoff)
   ───────────────────────────────────────────────────────── */
const inviteBtn = document.getElementById('cm-invite-btn');
if (inviteBtn) {
    inviteBtn.addEventListener('click', async () => {
        const r = await apiFetch(API_BASE + '?action=get-users');
        const list = document.getElementById('cm-user-list');
        if (!r.ok || !r.data || !Array.isArray(r.data) || !r.data.length) {
            list.innerHTML = '<div style="text-align:center;font-size:11px;line-height:1.4;padding:10px;">Aún no tienes amigos que invitar.</div>';
        } else {
            list.innerHTML = '';
            r.data.forEach(u => {
                const item = document.createElement('div');
                item.className = 'cm-rec-item';
                item.style.cssText = 'border:1px solid var(--bezel-dark-1);padding:8px;margin-bottom:6px;';
                item.innerHTML = '<div style="flex:1;font-size:12px;">' + escHTML(u.label || u.username || '?') + '</div>' +
                    '<button class="button" type="button" data-uid="' + escHTML(u.id) + '" style="min-width:60px;font-size:11px;">Invitar</button>';
                item.querySelector('button').addEventListener('click', async () => {
                    document.getElementById('cm-invite-status').style.color = 'inherit';
                    document.getElementById('cm-invite-status').textContent = 'Enviando…';
                    const resp = await fetch(API_BASE + '?action=invite-partner', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ to_user_id: u.id }),
                    }).then(r => r.json()).catch(() => ({ error: 'red' }));
                    if (resp && resp.ok) {
                        document.getElementById('cm-invite-status').style.color = 'green';
                        document.getElementById('cm-invite-status').textContent = '✓ Invitación enviada';
                    } else {
                        document.getElementById('cm-invite-status').style.color = '#c00';
                        document.getElementById('cm-invite-status').textContent = (resp && resp.error) || 'Error';
                    }
                });
                list.appendChild(item);
            });
        }
        document.getElementById('cm-invite-modal').classList.add('visible');
    });
    document.getElementById('cm-invite-close').addEventListener('click', () => {
        document.getElementById('cm-invite-modal').classList.remove('visible');
    });
}

/* Polling de invitaciones recibidas. */
let __invitePollDelay = CFG.invitePollMinMs;
let __invitePollEmpty = 0;
let __currentInvite = null;

async function pollInvites() {
    const r = await apiFetch(API_BASE + '?action=get-partner-invites');
    if (!r.ok || !r.data || !Array.isArray(r.data.invites) || !r.data.invites.length) {
        __invitePollEmpty++;
        scheduleInvitePoll();
        return;
    }
    const inv = r.data.invites[0];
    __invitePollEmpty = 0;
    __invitePollDelay = CFG.invitePollMinMs;
    __currentInvite = inv;
    document.getElementById('cm-partner-msg').textContent = inv.fromLabel + ' te ha invitado a compartir calendario';
    document.getElementById('cm-partner-fecha').value = toISODate(hoy);
    if (document.getElementById('cm-partner-fecha')._widget) {
        document.getElementById('cm-partner-fecha')._widget.refresh();
    }
    document.getElementById('cm-partner-modal').classList.add('visible');
}
function scheduleInvitePoll() {
    if (__invitePollEmpty > 2) {
        __invitePollDelay = Math.min(__invitePollDelay * 2, CFG.invitePollMaxMs);
    }
    setTimeout(pollInvites, __invitePollDelay);
}
if (!hasPareja) {
    setTimeout(pollInvites, 1000);
}

/* Helper común: limpia la notificación push de invitación cuando el
   usuario "responde" a ella (acepta o rechaza). El SW vive en el shell
   parent. El tag real generado por send-push.php es 'invite-partner'
   (sendInviteNotification → 'invite-' . $source con $source='partner'). */
function cmClearInviteNotification() {
    try {
        const fn = (window.parent && window.parent !== window) ? window.parent.mhClearNotifications : window.mhClearNotifications;
        if (typeof fn === 'function') fn({ tag: 'invite-partner' });
    } catch (_) {}
}

document.getElementById('cm-partner-accept').addEventListener('click', async () => {
    const fecha = document.getElementById('cm-partner-fecha').value;
    if (!fecha) {
        document.getElementById('cm-partner-status').style.color = '#c00';
        document.getElementById('cm-partner-status').textContent = 'Indica la fecha en que empezasteis';
        return;
    }
    const r = await apiFetch(API_BASE + '?action=respond-partner-invite', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invite_id: __currentInvite.id, action: 'accept', fecha_inicio: fecha }),
    });
    if (r.ok && r.data && r.data.ok) {
        cmClearInviteNotification();
        location.reload();
    } else {
        document.getElementById('cm-partner-status').style.color = '#c00';
        document.getElementById('cm-partner-status').textContent = 'Error';
    }
});
document.getElementById('cm-partner-reject').addEventListener('click', async () => {
    await apiFetch(API_BASE + '?action=respond-partner-invite', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invite_id: __currentInvite.id, action: 'reject' }),
    });
    cmClearInviteNotification();
    document.getElementById('cm-partner-modal').classList.remove('visible');
    scheduleInvitePoll();
});

/* Purgar recordatorios viejos al cargar. */
if (parejaId) {
    fetch(API_BASE + '?action=purge-recordatorios&pareja_id=' + parejaId, { method: 'POST' }).catch(()=>{});
}

/* ─────────────────────────────────────────────────────────
   WIN98 WIDGETS — date picker + custom select
   (mismo código que desktop, adaptado para position:fixed)
   ───────────────────────────────────────────────────────── */
(function() {
    const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const DOWS = ['L','M','X','J','V','S','D'];
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function ymd(date) { return date.getFullYear() + '-' + pad(date.getMonth()+1) + '-' + pad(date.getDate()); }
    function parseYmd(s) {
        if (!s) return null;
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (!m) return null;
        const d = new Date(+m[1], +m[2]-1, +m[3]);
        return isNaN(d.getTime()) ? null : d;
    }
    function fmtDisplay(s) {
        const d = parseYmd(s);
        if (!d) return '';
        return pad(d.getDate()) + '/' + pad(d.getMonth()+1) + '/' + d.getFullYear();
    }
    function interceptValueSetter(input, onSet) {
        const proto = Object.getPrototypeOf(input);
        const nd = Object.getOwnPropertyDescriptor(proto, 'value') || Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        if (!nd || !nd.set) return;
        Object.defineProperty(input, 'value', {
            configurable: true,
            get: function() { return nd.get.call(this); },
            set: function(v) { nd.set.call(this, v); try { onSet(v); } catch(_){} }
        });
    }

    function buildDatePicker(orig) {
        const initialValue = orig.value || '';
        orig.type = 'hidden';

        const wrap = document.createElement('div');
        wrap.className = 'w98-date-wrap';
        const display = document.createElement('div');
        display.className = 'w98-date-display';
        display.tabIndex = 0;
        display.innerHTML = '<span class="w98-date-label"></span><span class="w98-select-arrow">▼</span>';
        const popup = document.createElement('div');
        popup.className = 'w98-cal-popup';
        popup.hidden = true;

        orig.parentNode.insertBefore(wrap, orig);
        wrap.appendChild(display);
        wrap.appendChild(orig);
        document.body.appendChild(popup);

        const labelEl = display.querySelector('.w98-date-label');
        let currentView = new Date();

        function updateDisplay(v) { labelEl.textContent = fmtDisplay(v) || 'dd/mm/aaaa'; }
        updateDisplay(initialValue);
        if (initialValue) {
            const nd = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
            nd.set.call(orig, initialValue);
        }
        interceptValueSetter(orig, updateDisplay);

        function renderGrid() {
            const sel = parseYmd(orig.value);
            const today = new Date();
            const year = currentView.getFullYear();
            const month = currentView.getMonth();
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            let startDow = (firstDay.getDay() + 6) % 7;
            const monthDays = lastDay.getDate();
            if (startDow + monthDays > 35) startDow -= 7;

            let html = '<div class="w98-cal-header">' +
                '<button type="button" class="w98-cal-nav-btn" data-nav="prev">‹</button>' +
                '<div class="w98-cal-title">' + MONTHS[month] + ' ' + year + '</div>' +
                '<button type="button" class="w98-cal-nav-btn" data-nav="next">›</button>' +
            '</div><div class="w98-cal-grid">';
            DOWS.forEach(d => html += '<div class="w98-cal-dow">' + d + '</div>');
            for (let i = 0; i < 35; i++) {
                const dayNum = i - startDow + 1;
                const date = new Date(year, month, dayNum);
                const isOther = dayNum < 1 || dayNum > monthDays;
                const isToday = date.toDateString() === today.toDateString();
                const isSel = sel && date.toDateString() === sel.toDateString();
                let cls = 'w98-cal-day';
                if (isOther) cls += ' other-month';
                if (isToday) cls += ' today';
                if (isSel) cls += ' selected';
                html += '<div class="' + cls + '" data-date="' + ymd(date) + '">' + date.getDate() + '</div>';
            }
            html += '</div>';
            popup.innerHTML = html;
            popup.querySelectorAll('[data-nav]').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    const dir = btn.dataset.nav === 'next' ? 1 : -1;
                    currentView = new Date(year, month + dir, 1);
                    renderGrid();
                });
            });
            popup.querySelectorAll('.w98-cal-day').forEach(cell => {
                cell.addEventListener('click', e => {
                    e.stopPropagation();
                    orig.value = cell.dataset.date;
                    closePop();
                });
            });
        }
        function positionPop() {
            const r = display.getBoundingClientRect();
            popup.style.left = r.left + 'px';
            popup.style.top = (r.bottom + 2) + 'px';
            const pr = popup.getBoundingClientRect();
            if (pr.bottom > window.innerHeight - 8) {
                popup.style.top = Math.max(8, r.top - pr.height - 2) + 'px';
            }
            if (pr.right > window.innerWidth - 8) {
                popup.style.left = Math.max(8, window.innerWidth - pr.width - 8) + 'px';
            }
        }
        function openPop() {
            const v = parseYmd(orig.value);
            currentView = v ? new Date(v.getFullYear(), v.getMonth(), 1) : new Date();
            renderGrid();
            popup.hidden = false;
            positionPop();
        }
        function closePop() { popup.hidden = true; }
        display.addEventListener('click', e => {
            e.stopPropagation();
            if (popup.hidden) openPop(); else closePop();
        });
        document.addEventListener('click', e => {
            if (!wrap.contains(e.target) && !popup.contains(e.target)) closePop();
        });
        window.addEventListener('resize', () => { if (!popup.hidden) positionPop(); });

        /* Expose refresh para que el caller pueda invalidar tras setear value. */
        orig._widget = {
            refresh: () => updateDisplay(orig.value)
        };
    }

    function buildSelect(orig) {
        const options = Array.from(orig.options).map(opt => ({ value: opt.value, label: opt.textContent.trim() }));
        if (!options.length) return;
        const origId = orig.id;
        const origValue = orig.value;

        const wrap = document.createElement('div');
        wrap.className = 'w98-select-wrap';
        const btn = document.createElement('div');
        btn.className = 'w98-select-btn';
        btn.tabIndex = 0;
        btn.innerHTML = '<span class="w98-date-label"></span><span class="w98-select-arrow">▼</span>';
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        if (origId) hidden.id = origId;
        hidden.value = origValue;
        const popup = document.createElement('div');
        popup.className = 'w98-select-popup';
        popup.hidden = true;
        const labelEl = btn.querySelector('.w98-date-label');

        function findOpt(v) { return options.find(o => o.value === v); }
        function updateLabel(v) {
            const o = findOpt(v);
            labelEl.textContent = o ? o.label : '';
        }
        updateLabel(origValue);

        options.forEach(o => {
            const el = document.createElement('div');
            el.className = 'w98-select-opt';
            el.textContent = o.label;
            if (o.value === origValue) el.classList.add('active');
            el.addEventListener('click', e => {
                e.stopPropagation();
                hidden.value = o.value;
                popup.querySelectorAll('.w98-select-opt').forEach(x => x.classList.remove('active'));
                el.classList.add('active');
                popup.hidden = true;
            });
            popup.appendChild(el);
        });

        wrap.appendChild(btn);
        wrap.appendChild(hidden);
        orig.parentNode.replaceChild(wrap, orig);
        document.body.appendChild(popup);

        interceptValueSetter(hidden, updateLabel);
        function positionSelectPop() {
            const r = btn.getBoundingClientRect();
            popup.style.left = r.left + 'px';
            popup.style.width = r.width + 'px';
            popup.style.top = (r.bottom + 1) + 'px';
            const pr = popup.getBoundingClientRect();
            if (pr.bottom > window.innerHeight - 8) {
                popup.style.top = Math.max(8, r.top - pr.height - 1) + 'px';
            }
        }
        btn.addEventListener('click', e => {
            e.stopPropagation();
            if (popup.hidden) { popup.hidden = false; positionSelectPop(); }
            else popup.hidden = true;
        });
        document.addEventListener('click', e => {
            if (!wrap.contains(e.target) && !popup.contains(e.target)) popup.hidden = true;
        });
        window.addEventListener('resize', () => { if (!popup.hidden) positionSelectPop(); });

        hidden._widget = {
            refresh: () => updateLabel(hidden.value)
        };
    }

    document.querySelectorAll('input[type="date"]').forEach(buildDatePicker);
    document.querySelectorAll('select').forEach(buildSelect);
})();

/* ── Init final ── */
/* ?dia=AAAA-MM-DD (widgets de calendario de la app de Android): se abre
   en ese mes con el día abierto, como al tocarlo en la cuadrícula. */
const diaInicial = new URLSearchParams(location.search).get('dia');
cargarTodo().then(() => {
    const d = diaInicial && parseISODate(diaInicial);
    if (!d) return;
    currentYear = d.getFullYear();
    currentMonth = d.getMonth();
    recalcAndRender();
    abrirSheetDia(diaInicial);
});

})();
