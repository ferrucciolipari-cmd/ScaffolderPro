// ============================================================================
// SCAFFOLDER PRO — frontend (Explorer, editor a schede, Claude, modali)
// ============================================================================
const APP = window.APP_DATA;
const ROOT_KEY = APP.rootDir;

const state = {
    dirs: [],
    files: [],
    openFolders: new Set(load('open', [])),
    selected: new Set(),
    anchor: null,
    tabs: [],                 // {path, kind, session, saved, writable, size, mtime, el}
    active: null,
    ctxFiles: new Set(load('ctx', [])),  // file aggiunti a mano al contesto di Claude
    ctxRequests: new Set(loadGlobal('ctxreq', [])), // richieste API la cui ultima risposta va a Claude
    httpList: [],
    chat: [],                 // conversazione con Claude (formato Messages API)
    query: '',
    dragging: null,
    split: false,
    proposals: new Map(),     // proposte di modifica di Claude: id → {type, path, old, new, note, status}
};

let editor = null;
let ctxTarget = null;
let currentZoom = parseInt(localStorage.getItem('scaffolder_editor_zoom') || APP.config.editor_font || 14, 10);
if (isNaN(currentZoom) || currentZoom < 10 || currentZoom > 32) currentZoom = 14;

// ----------------------------------------------------------------------------
// UTILITÀ
// ----------------------------------------------------------------------------
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const parentOf = (p) => p.includes('/') ? p.substring(0, p.lastIndexOf('/')) : '';
const baseName = (p) => p.split('/').pop();
const extOf = (p) => { const b = baseName(p); return b.includes('.') ? b.split('.').pop().toLowerCase() : ''; };
// Dispositivo touch senza mouse (telefono, tablet)
const IS_TOUCH = window.matchMedia('(hover: none) and (pointer: coarse)').matches;
const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif', 'svg'];
/** Icona SVG dallo sprite di index.php. */
const ic = (name) => `<svg class="ic" aria-hidden="true"><use href="#i-${name}"/></svg>`;

/** Testo nella lingua dell'interfaccia (lang/*.json); {nome} viene sostituito con p.nome. */
function L(key, p) {
    let s = (APP.i18n && APP.i18n[key]) || key;
    if (p) Object.keys(p).forEach(k => { s = s.split('{' + k + '}').join(String(p[k])); });
    return s;
}

function load(key, def) {
    try { const v = JSON.parse(localStorage.getItem('sc_' + key + ':' + ROOT_KEY)); return v ?? def; } catch (e) { return def; }
}
function loadGlobal(key, def) {
    try { const v = JSON.parse(localStorage.getItem('sc_' + key)); return v ?? def; } catch (e) { return def; }
}
function storeGlobal(key, val) {
    try { localStorage.setItem('sc_' + key, JSON.stringify(val)); } catch (e) { /* storage non disponibile */ }
}
function store(key, val) {
    try { localStorage.setItem('sc_' + key + ':' + ROOT_KEY, JSON.stringify(val)); } catch (e) { /* storage non disponibile */ }
}

function humanSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(2) + ' GB';
}

/** Login scaduto o uscita da un'altra scheda: la pagina si ricarica e mostra il login. */
function jsonOrLogin(r) {
    if (r.status === 401) { location.reload(); return new Promise(() => {}); }
    return r.json();
}

function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    return fetch('api.php?' + qs, { headers: { 'Accept': 'application/json' } }).then(jsonOrLogin);
}

// Invia un'azione a api.php includendo sempre il token CSRF.
// Senza file si usa x-www-form-urlencoded: con FormData (multipart) il browser trasforma ogni "\n" in "\r\n"
// e i file salvati dall'editor diventerebbero CRLF.
function apiPost(fields) {
    const hasBlob = Object.values(fields).some(v => v instanceof Blob);
    const body = hasBlob ? new FormData() : new URLSearchParams();
    Object.entries(fields).forEach(([k, v]) => body.append(k, typeof v === 'object' && !(v instanceof Blob) ? JSON.stringify(v) : v));
    body.append('csrf', APP.csrf);
    return fetch('api.php', {
        method: 'POST',
        body,
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': APP.csrf }
    }).then(jsonOrLogin);
}

function toast(msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = ic(type === 'error' ? 'alert' : (type === 'warn' ? 'alert' : 'check')) + '<span></span>';
    el.lastChild.textContent = msg;
    $('#toasts').appendChild(el);
    anim.pop(el);
    setTimeout(() => anim.out(el, () => el.remove()), type === 'error' ? 6000 : 2800);
}

// Animazioni "gommose" con GSAP (se la CDN non risponde tutto funziona senza animazioni)
const anim = {
    ok: () => !!window.gsap && !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    pop(el) { if (this.ok()) gsap.fromTo(el, { scale: 0.85, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.45, ease: 'back.out(2.2)', clearProps: 'transform' }); },
    out(el, done) { if (this.ok()) gsap.to(el, { opacity: 0, y: -6, duration: 0.2, onComplete: done }); else done(); },
    tabIn(el) { if (this.ok()) gsap.fromTo(el, { scaleX: 0.4, opacity: 0, transformOrigin: 'left center' }, { scaleX: 1, opacity: 1, duration: 0.6, ease: 'elastic.out(1, 0.55)', clearProps: 'transform' }); },
    tabOut(el, done) { if (this.ok()) gsap.to(el, { width: 0, opacity: 0, paddingLeft: 0, paddingRight: 0, duration: 0.18, ease: 'power2.in', onComplete: done }); else done(); },
    expand(el) {
        if (!this.ok()) return;
        gsap.fromTo(el, { height: 0, opacity: 0 }, { height: 'auto', opacity: 1, duration: 0.45, ease: 'elastic.out(1, 0.75)', clearProps: 'height,opacity' });
    },
    collapse(el, done) {
        if (!this.ok()) { done(); return; }
        gsap.to(el, { height: 0, opacity: 0, duration: 0.18, ease: 'power2.in', onComplete: () => { gsap.set(el, { clearProps: 'height,opacity' }); done(); } });
    },
    landed(els) { if (this.ok() && els.length) gsap.fromTo(els, { scale: 0.7, opacity: 0.3 }, { scale: 1, opacity: 1, duration: 0.8, ease: 'elastic.out(1.1, 0.45)', stagger: 0.04, clearProps: 'transform,opacity' }); },
    hoverOn(el) { if (this.ok()) gsap.to(el, { scale: 1.035, duration: 0.35, ease: 'back.out(3)' }); },
    hoverOff(el) { if (this.ok()) gsap.to(el, { scale: 1, duration: 0.25, ease: 'power2.out', clearProps: 'transform' }); },
    press(el) { if (this.ok()) gsap.fromTo(el, { scale: 0.97 }, { scale: 1, duration: 0.5, ease: 'elastic.out(1, 0.4)', clearProps: 'transform' }); },
};

// ----------------------------------------------------------------------------
// AVVIO
// ----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    initLayout();
    initRootSelect();
    initEditor();
    initExplorer();
    initContextMenu();
    initTopbar();
    initDialogs();
    initSettings();
    initUpload();
    initClaude();
    initSide();
    initShortcuts();
    initTooltips();
    preventZoom();
    initTabDrag();
    initRightCards();
    initRightOrder();
    initLogDialog();
    initHttp();
    initSearch();
    initErrors();
    initDiffView();
    initQuickOpen();
    initNavHistory();
    initEditorTools();
    initSetupAuth();
    initLogout();
    initMenuOverlay();

    $('#rootName').textContent = baseName(APP.rootDir) || APP.rootDir;
    $('#welcomeRoot').textContent = L('js.working_root', { path: APP.rootDir });

    refreshTree().then(() => {
        // Riapre le schede della sessione precedente
        const saved = load('tabs', { list: [], active: null });
        const list = saved.list.filter(p => state.files.includes(p) || p.startsWith('http:'));
        Promise.all(list.map(p => openFile(p, { activate: false, animate: false, silent: true }))).then(() => {
            const open = state.tabs.map(t => t.path);
            const act = open.includes(saved.active) ? saved.active : open[open.length - 1];
            if (act) activateTab(act); else showWelcome();
        });
    });
    refreshSide();
});

// ----------------------------------------------------------------------------
// LAYOUT: COLONNE RIDIMENSIONABILI
// ----------------------------------------------------------------------------
function initLayout() {
    const layout = $('#layout');
    const cols = JSON.parse(localStorage.getItem('sc_cols') || '{}');
    if (cols.left) layout.style.setProperty('--left-w', cols.left + 'px');
    if (cols.right) layout.style.setProperty('--right-w', cols.right + 'px');
    applyRightVisibility();

    $$('.gutter').forEach(g => {
        g.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            g.setPointerCapture(e.pointerId);
            g.classList.add('active');
            document.body.classList.add('resizing');
            const side = g.dataset.gutter;
            const startX = e.clientX;
            const startW = (side === 'left' ? $('#leftCol') : $('#rightCol')).getBoundingClientRect().width;
            const move = (ev) => {
                const dx = ev.clientX - startX;
                const w = Math.max(180, Math.min(640, side === 'left' ? startW + dx : startW - dx));
                layout.style.setProperty(side === 'left' ? '--left-w' : '--right-w', w + 'px');
                if (editor) editor.resize();
            };
            const up = () => {
                g.classList.remove('active');
                document.body.classList.remove('resizing');
                g.removeEventListener('pointermove', move);
                g.removeEventListener('pointerup', up);
                const c = JSON.parse(localStorage.getItem('sc_cols') || '{}');
                c[side] = Math.round((side === 'left' ? $('#leftCol') : $('#rightCol')).getBoundingClientRect().width);
                localStorage.setItem('sc_cols', JSON.stringify(c));
            };
            g.addEventListener('pointermove', move);
            g.addEventListener('pointerup', up);
        });
        g.addEventListener('dblclick', () => resetColumns());
    });
}

function resetColumns() {
    localStorage.removeItem('sc_cols');
    $('#layout').style.removeProperty('--left-w');
    $('#layout').style.removeProperty('--right-w');
    if (editor) editor.resize();
}

function applyRightVisibility() {
    $('#layout').classList.toggle('no-right', !APP.config.show_right);
}

// ----------------------------------------------------------------------------
// SELETTORE ROOT: cartelle dello spazio web (albero indentato) con ricerca e permessi
// ----------------------------------------------------------------------------
// Albero richiudibile: si parte dal primo livello (più il percorso fino alla root attuale) e si aprono i rami.
// Con una ricerca o "Solo modificabili" si vedono solo i risultati con le cartelle che li contengono.
const rootPicker = { items: [], loaded: false, loading: null, query: '', onlyWritable: false, focus: 0, visible: [],
    open: new Set(loadGlobal('rootopen', [])), byPath: new Map(), kids: new Map() };

function initRootSelect() {
    const picker = $('#rootPicker'), trigger = $('#rootTrigger'), dropdown = $('#rootDropdown');
    const input = $('#rootSearchInput'), clearBtn = $('#rootSearchClear'), list = $('#rootList');

    const overlay = $('#rootOverlay');
    const close = () => { dropdown.hidden = true; overlay.hidden = true; picker.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); };
    const open = () => {
        dropdown.hidden = false;
        overlay.hidden = false;
        picker.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
        anim.pop(dropdown);
        input.value = rootPicker.query;
        clearBtn.hidden = !rootPicker.query;
        renderRootPicker();
        loadRootPicker().then(() => { renderRootPicker(true); focusSoft(input); input.select(); });
    };
    trigger.addEventListener('click', (e) => { e.stopPropagation(); dropdown.hidden ? open() : close(); });
    document.addEventListener('click', (e) => { if (!dropdown.hidden && !picker.contains(e.target)) close(); });
    overlay.addEventListener('click', close);
    $('#rootClose').addEventListener('click', (e) => { e.stopPropagation(); close(); trigger.focus(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !dropdown.hidden) { e.stopPropagation(); close(); trigger.focus(); } }, true);

    input.addEventListener('input', () => {
        rootPicker.query = input.value.trim().toLowerCase();
        clearBtn.hidden = !rootPicker.query;
        rootPicker.focus = 0;
        renderRootPicker();
    });
    clearBtn.addEventListener('click', () => { input.value = ''; input.dispatchEvent(new Event('input')); input.focus(); });
    input.addEventListener('keydown', (e) => {
        const n = rootPicker.visible.length;
        const cur = rootPicker.visible[rootPicker.focus];
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            let i = rootPicker.focus;
            // Salta le cartelle in cui non si può entrare (Scaffolder Pro, senza accesso)
            for (let k = 0; k < n; k++) {
                i = (i + (e.key === 'ArrowDown' ? 1 : -1) + n) % n;
                if (rootNavigable(rootPicker.visible[i])) break;
            }
            rootPicker.focus = i;
            highlightRootRow();
        } else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && cur && !rootFiltering()
                   && (input.selectionStart === input.value.length || !input.value)) {
            // Come nell'Explorer: → apre il ramo (o scende al primo figlio), ← lo chiude (o risale al genitore)
            const isOpen = rootPicker.open.has(cur.path);
            if (e.key === 'ArrowRight' && rootExpandable(cur)) {
                e.preventDefault();
                if (!isOpen) toggleRootBranch(cur.path);
                else if (rootPicker.visible[rootPicker.focus + 1] && rootPicker.visible[rootPicker.focus + 1].depth > cur.depth) { rootPicker.focus++; highlightRootRow(); }
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                if (isOpen && rootExpandable(cur)) toggleRootBranch(cur.path);
                else {
                    const p = rootPicker.visible.findIndex(it => it.path === parentOf(cur.path) && it.group === 'workspace');
                    if (p >= 0) { rootPicker.focus = p; highlightRootRow(); }
                }
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const it = rootPicker.visible[rootPicker.focus];
            if (it && rootSelectable(it)) { close(); selectNewRoot(it.path); }
        }
    });
    $$('#rootFilterMode button').forEach(b => b.addEventListener('click', () => {
        rootPicker.onlyWritable = b.dataset.rf === 'writable';
        $$('#rootFilterMode button').forEach(x => x.classList.toggle('on', x === b));
        rootPicker.focus = 0;
        renderRootPicker();
        input.focus();
    }));
    $('#rootReload').addEventListener('click', () => {
        rootPicker.loaded = false;
        list.innerHTML = `<div class="log-empty">${esc(L('rp.loading'))}</div>`;
        loadRootPicker().then(() => renderRootPicker(true));
    });
    $('#rootCustomPathBtn').addEventListener('click', () => {
        close();
        const path = prompt(L('js.root_prompt'), APP.rootDir);
        if (path && path.trim()) selectNewRoot(path.trim());
    });
    list.addEventListener('click', (e) => {
        const chev = e.target.closest('[data-toggle]');
        if (chev) { e.stopPropagation(); toggleRootBranch(chev.dataset.toggle); return; }
        const row = e.target.closest('.root-item');
        if (!row || row.classList.contains('is-disabled') || row.classList.contains('is-current')) return;
        close();
        selectNewRoot(row.dataset.path);
    });
    list.addEventListener('mousemove', (e) => {
        const row = e.target.closest('.root-item');
        if (row && !row.classList.contains('is-disabled')) { rootPicker.focus = +row.dataset.i; highlightRootRow(false); }
    });
    fillRootSelect();
}

const rootSelectable = (it) => it && !it.tool && it.readable && !it.current;
const rootNavigable = (it) => it && !it.tool && it.readable;
const rootFiltering = () => !!(rootPicker.query || rootPicker.onlyWritable);
/** Si può aprire: cartella dell'albero con sottocartelle (se già lette, almeno una visibile). */
const rootExpandable = (it) => it && it.group === 'workspace' && rootNavigable(it) && it.dirs_n > 0
    && !(it.scanned && !(rootPicker.kids.get(it.path) || []).length);

/** Indici dell'albero: percorso → cartella, percorso → sottocartelle (nell'ordine della lettura). */
function indexRootPicker() {
    rootPicker.byPath = new Map();
    rootPicker.kids = new Map();
    rootPicker.items.forEach(it => {
        if (it.group !== 'workspace') return;
        rootPicker.byPath.set(it.path, it);
        if (it.depth > 0) {
            const p = parentOf(it.path);
            if (!rootPicker.kids.has(p)) rootPicker.kids.set(p, []);
            rootPicker.kids.get(p).push(it);
        }
    });
}

function saveRootOpen() {
    storeGlobal('rootopen', [...rootPicker.open].slice(-300));
}

/** Apre/chiude un ramo; se le sottocartelle non sono ancora state lette le chiede al server. */
function toggleRootBranch(path) {
    const it = rootPicker.byPath.get(path);
    if (!it) return;
    if (rootPicker.open.has(path)) {
        rootPicker.open.delete(path);
        saveRootOpen();
        renderRootPicker();
        return;
    }
    rootPicker.open.add(path);
    saveRootOpen();
    if (it.scanned || it.loadingKids) { renderRootPicker(); return; }
    it.loadingKids = true;
    renderRootPicker();
    apiGet('get_root_children', { path, depth: it.depth }).then(res => {
        it.loadingKids = false;
        if (!res.ok) { toast(res.error, 'error'); renderRootPicker(); return; }
        it.scanned = true;
        const pos = rootPicker.items.indexOf(it) + 1;
        const add = (res.items || []).filter(k => !rootPicker.byPath.has(k.path))
            .map(k => Object.assign(k, { rel: (it.rel || '') + '/' + k.name }));
        rootPicker.items.splice(pos, 0, ...add);
        indexRootPicker();
        renderRootPicker();
    }).catch(() => { it.loadingKids = false; renderRootPicker(); });
}

function loadRootPicker() {
    if (rootPicker.loaded) return Promise.resolve();
    if (rootPicker.loading) return rootPicker.loading;
    if (!rootPicker.items.length) $('#rootList').innerHTML = `<div class="log-empty">${esc(L('rp.loading'))}</div>`;
    rootPicker.loading = apiGet('get_roots').then(res => {
        rootPicker.loading = null;
        if (!res.ok) { toast(res.error, 'error'); return; }
        rootPicker.items = res.items || [];
        rootPicker.loaded = true;
        indexRootPicker();
        // Prima apertura: aperto il primo livello; sempre aperto il percorso fino alla root attuale
        if (loadGlobal('rootopen', null) === null) rootPicker.items.forEach(it => { if (it.group === 'workspace' && it.depth === 0) rootPicker.open.add(it.path); });
        for (let p = parentOf(APP.rootDir); p && rootPicker.byPath.has(p); p = parentOf(p)) rootPicker.open.add(p);
        saveRootOpen();
    }).catch(err => { rootPicker.loading = null; toast(L('js.network_error') + ': ' + err, 'error'); });
    return rootPicker.loading;
}

/** Pallino accanto al nome della root: verde = modificabile, giallo = sola lettura. */
function fillRootSelect() {
    $('#rootCurrentLabel').textContent = baseName(APP.rootDir) || APP.rootDir;
    $('#rootTrigger').title = APP.rootDir + ' — ' + L('hdr.root_title');
    const dot = $('#rootCurrentPerm');
    const rw = state.rootWritable !== false;
    dot.className = 'root-perm-dot ' + (rw ? 'rw' : 'ro');
    dot.title = L(rw ? 'rp.writable' : 'rp.readonly');
}

function renderRootPicker(scrollToCurrent = false) {
    const list = $('#rootList');
    if (!rootPicker.loaded && !rootPicker.items.length) return;
    const q = rootPicker.query;
    const items = rootPicker.items;
    const filtering = rootFiltering();
    // Con un filtro restano le cartelle trovate più quelle che le contengono (tutto aperto)
    let keep = null;
    if (filtering) {
        keep = new Set();
        items.forEach(it => {
            if (rootPicker.onlyWritable && !it.writable && !it.current) return;
            if (q && !(it.name + ' ' + it.rel).toLowerCase().includes(q)) return;
            keep.add(it);
            if (it.group === 'workspace') {
                for (let p = parentOf(it.path), a = rootPicker.byPath.get(p); a; p = parentOf(p), a = rootPicker.byPath.get(p)) keep.add(a);
            }
        });
    }
    // Ordine dell'albero: livello superiore, rami dello spazio web (solo quelli aperti), recenti
    const visible = [];
    const walk = (it) => {
        if (!keep || keep.has(it)) visible.push(it);
        if (filtering || rootPicker.open.has(it.path)) (rootPicker.kids.get(it.path) || []).forEach(walk);
    };
    items.forEach(it => {
        if (it.group !== 'workspace') { if (!keep || keep.has(it)) visible.push(it); }
        else if (it.depth === 0) walk(it);
    });
    rootPicker.visible = visible;
    if (!visible.length) { list.innerHTML = `<div class="log-empty">${esc(L('rp.none'))}</div>`; return; }
    const groupTitle = { up: L('js.root_up'), workspace: L('rp.workspace'), recent: L('js.root_recent') };
    let lastGroup = null;
    const hl = (name) => {
        if (!q) return esc(name);
        const i = name.toLowerCase().indexOf(q);
        return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<mark>' + esc(name.slice(i, i + q.length)) + '</mark>' + esc(name.slice(i + q.length));
    };
    list.innerHTML = visible.map((it, i) => {
        let head = '';
        if (it.group !== lastGroup) { head = `<div class="root-group">${esc(groupTitle[it.group] || '')}</div>`; lastGroup = it.group; }
        const disabled = it.tool || !it.readable;
        const badge = it.tool ? `<span class="perm-badge tool">Scaffolder Pro</span>`
            : !it.readable ? `<span class="perm-badge ro">${esc(L('rp.no_access'))}</span>`
            : it.writable ? `<span class="perm-badge rw" title="${esc(L('rp.writable_title'))}">R/W</span>`
            : `<span class="perm-badge ro" title="${esc(L('rp.readonly_title'))}">R</span>`;
        const counts = !it.tool && it.readable && it.group === 'workspace' ? `<span class="root-counts">${esc(L('rp.counts', { d: it.dirs_n, f: it.files_n }))}</span>` : '';
        const name = it.group === 'workspace' ? it.name : (it.group === 'up' ? '↰ ' + it.name : it.path);
        // Freccina per aprire/chiudere il ramo (con un filtro i rami sono tutti aperti)
        const exp = rootExpandable(it);
        const isOpen = filtering || rootPicker.open.has(it.path);
        const chev = it.group !== 'workspace' ? ''
            : exp && !filtering ? `<span class="root-chev${isOpen ? ' open' : ''}${it.loadingKids ? ' loading' : ''}" data-toggle="${esc(it.path)}" title="${esc(L(isOpen ? 'rp.collapse' : 'rp.expand'))}">${CHEVRON}</span>`
            : `<span class="root-chev${exp && isOpen ? ' open static' : ' none'}">${exp ? CHEVRON : ''}</span>`;
        return head + `<div class="root-item${it.current ? ' is-current' : ''}${disabled ? ' is-disabled' : ''}" role="option" data-i="${i}" data-path="${esc(it.path)}"`
            + ` style="--depth:${it.group === 'workspace' ? it.depth : 0}" title="${esc(it.path)}">`
            + chev + `${ic('folder')}<span class="root-item-name">${hl(name)}${it.current ? ` <em>(${esc(L('js.root_current'))})</em>` : ''}</span>`
            + `<span class="root-item-meta">${counts}${badge}</span></div>`;
    }).join('');
    if (scrollToCurrent || !rootNavigable(visible[rootPicker.focus])) {
        const cur = visible.findIndex(it => it.current);
        const first = visible.findIndex(filtering ? rootSelectable : rootNavigable);
        rootPicker.focus = scrollToCurrent && cur >= 0 ? cur : Math.max(0, first);
    }
    highlightRootRow(true, scrollToCurrent);
}

function highlightRootRow(scroll = true, center = false) {
    $$('#rootList .root-item').forEach(r => {
        const on = +r.dataset.i === rootPicker.focus;
        r.classList.toggle('focused', on);
        if (on && scroll) r.scrollIntoView({ block: center ? 'center' : 'nearest' });
    });
}

function selectNewRoot(path) {
    if (!path || path === APP.rootDir) return;
    if (anyDirty() && !confirm(L('js.root_dirty'))) return;
    apiPost({ action: 'set_root', root_folder: path }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        state.tabs.forEach(t => { t.saved = t.session ? t.session.getValue() : t.saved; });
        location.reload();
    });
}

// ----------------------------------------------------------------------------
// EXPLORER: ALBERO
// ----------------------------------------------------------------------------
function refreshTree() {
    return apiGet('get_tree').then(res => {
        if (!res.ok) return;
        state.dirs = res.dirs;
        state.files = res.files;
        state.toolDirs = res.tool_dirs || [];
        state.rootWritable = !!res.writable;
        rootPicker.loaded = false;   // cartelle cambiate: il selettore della root si ricarica alla prossima apertura
        const banner = $('#permBanner');
        if (!res.writable) {
            banner.hidden = false;
            banner.innerHTML = L('js.perm_banner', { user: esc(APP.daemonUser), root: esc(res.root) })
                + ` <code class="copyable" title="${esc(L('js.click_copy'))}">sudo chmod -R a+rwX "${esc(res.root)}"</code>`;
            $('code.copyable', banner).onclick = (e) => navigator.clipboard.writeText(e.target.textContent).then(() => toast(L('js.command_copied')));
        } else {
            banner.hidden = true;
        }
        // Scarta selezioni/contesto riferiti a file che non esistono più
        state.selected.forEach(p => { if (!state.files.includes(p) && !state.dirs.includes(p)) state.selected.delete(p); });
        state.ctxFiles.forEach(p => { if (!state.files.includes(p)) state.ctxFiles.delete(p); });
        renderTree();
        fillRootSelect();
        renderCtxChips();
    });
}

function buildNested() {
    const root = { dirs: {}, files: [] };
    const node = (path) => {
        let cur = root;
        if (path === '') return cur;
        path.split('/').forEach(part => {
            if (!cur.dirs[part]) cur.dirs[part] = { dirs: {}, files: [] };
            cur = cur.dirs[part];
        });
        return cur;
    };
    const q = state.query;
    let files = state.files, dirs = state.dirs;
    if (q) {
        files = files.filter(f => f.toLowerCase().includes(q));
        const keep = new Set();
        dirs.forEach(d => { if (d && d.toLowerCase().includes(q)) keep.add(d); });
        files.forEach(f => { let p = parentOf(f); while (p) { keep.add(p); p = parentOf(p); } });
        // Cartella che corrisponde alla ricerca: mostra anche i suoi file diretti
        state.files.forEach(f => { if (keep.has(parentOf(f)) && parentOf(f).toLowerCase().includes(q) && !files.includes(f)) files = files.concat(f); });
        [...keep].forEach(d => { let p = parentOf(d); while (p) { keep.add(p); p = parentOf(p); } });
        dirs = dirs.filter(d => d === '' || keep.has(d));
    }
    dirs.forEach(d => node(d));
    files.forEach(f => node(parentOf(f)).files.push(f));
    return root;
}

const FILE_ICONS = {
    php: ['php', '#a074c4'], html: ['<>', '#e44d26'], htm: ['<>', '#e44d26'], css: ['#', '#42a5f5'], scss: ['#', '#cd6799'],
    js: ['JS', '#e8d44d'], mjs: ['JS', '#e8d44d'], jsx: ['JS', '#61dafb'], ts: ['TS', '#3178c6'], tsx: ['TS', '#61dafb'], vue: ['V', '#41b883'],
    json: ['{}', '#cbcb41'], md: ['md', '#519aba'], txt: ['txt', '#9da5b4'], sql: ['db', '#f29111'], xml: ['<>', '#8bc34a'],
    yml: ['Y', '#cb171e'], yaml: ['Y', '#cb171e'], ini: ['ini', '#9da5b4'], env: ['env', '#ecd53f'], sh: ['$', '#89e051'], htaccess: ['ht', '#d22128'],
    svg: ['svg', '#ffb13b'], png: ['img', '#a074c4'], jpg: ['img', '#a074c4'], jpeg: ['img', '#a074c4'], gif: ['img', '#a074c4'], webp: ['img', '#a074c4'],
    ico: ['ico', '#a074c4'], bmp: ['img', '#a074c4'], avif: ['img', '#a074c4'], pdf: ['PDF', '#e53935'], zip: ['zip', '#c09553'],
    woff: ['Aa', '#9da5b4'], woff2: ['Aa', '#9da5b4'], ttf: ['Aa', '#9da5b4'], otf: ['Aa', '#9da5b4'],
    csv: ['csv', '#89e051'], sqlite: ['db', '#f29111'], db: ['db', '#f29111'],
};
// Video, audio e archivi: stessa icona per tutta la famiglia
['mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogv'].forEach(e => { FILE_ICONS[e] = ['▶', '#e06c75']; });
['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'].forEach(e => { FILE_ICONS[e] = ['♪', '#e06c75']; });
['gz', 'tgz', 'tar', 'bz2', 'xz', '7z', 'rar', 'iso', 'dmg'].forEach(e => { FILE_ICONS[e] = ['zip', '#c09553']; });
['tif', 'tiff', 'heic', 'psd'].forEach(e => { FILE_ICONS[e] = ['img', '#a074c4']; });
['doc', 'docx', 'odt'].forEach(e => { FILE_ICONS[e] = ['W', '#2b7cd3']; });
['xls', 'xlsx', 'ods'].forEach(e => { FILE_ICONS[e] = ['X', '#21a366']; });
['ppt', 'pptx'].forEach(e => { FILE_ICONS[e] = ['P', '#d35230']; });
const CHEVRON = '<svg class="chev" viewBox="0 0 16 16"><path d="M6 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';

function fileIcon(path) {
    const b = baseName(path);
    const key = b.startsWith('.') && !b.slice(1).includes('.') ? b.slice(1) : extOf(path);
    const [label, color] = FILE_ICONS[key] || ['···', '#9da5b4'];
    return `<span class="ficon" style="color:${color}">${esc(label)}</span>`;
}

function rowHtml(path, type, depth, open) {
    const name = baseName(path);
    const cls = ['row', type, state.selected.has(path) ? 'selected' : '', path === state.active ? 'active' : '', open ? 'open' : ''].join(' ');
    const lead = type === 'dir' ? CHEVRON : fileIcon(path);
    const dot = type === 'file' && state.ctxFiles.has(path) ? `<span class="ctx-dot" title="${esc(L('http.in_context'))}"></span>` : '';
    return `<div class="${cls}" draggable="true" data-path="${esc(path)}" data-type="${type}" style="--depth:${depth}">`
        + `<span class="lead">${lead}</span><span class="name">${esc(name)}</span>${dot}`
        + `<button type="button" class="row-pen" tabindex="-1" title="${esc(L('common.options'))}">${ic('pen')}</button></div>`;
}

function renderNode(node, depth) {
    let html = '';
    Object.keys(node.dirs).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })).forEach(name => {
        const sub = node.dirs[name];
        const path = sub.__path;
        const open = state.query ? true : state.openFolders.has(path);
        html += `<div class="node">${rowHtml(path, 'dir', depth, open)}<div class="children" style="--guide:${depth * 12}px"${open ? '' : ' hidden'}>${renderNode(sub, depth + 1)}</div></div>`;
    });
    node.files.sort((a, b) => baseName(a).localeCompare(baseName(b), undefined, { numeric: true, sensitivity: 'base' }))
        .forEach(f => { html += rowHtml(f, 'file', depth, false); });
    return html;
}

function renderTree() {
    const nested = buildNested();
    // annota il percorso completo su ogni nodo cartella
    (function tag(n, base) { Object.entries(n.dirs).forEach(([k, s]) => { s.__path = base ? base + '/' + k : k; tag(s, s.__path); }); })(nested, '');
    const html = renderNode(nested, 0);
    $('#tree').innerHTML = html || `<div class="log-empty">${state.query ? L('js.no_results') : L('js.empty_folder')}</div>`;
}

function visibleRows() {
    return $$('#tree .row').filter(r => r.offsetParent !== null);
}

function updateRowClasses() {
    $$('#tree .row').forEach(r => {
        r.classList.toggle('selected', state.selected.has(r.dataset.path));
        r.classList.toggle('active', r.dataset.path === state.active);
    });
}

function rowFor(path) {
    return $$('#tree .row').find(r => r.dataset.path === path);
}

function toggleFolder(path, force) {
    const row = rowFor(path);
    if (!row) return;
    const children = row.nextElementSibling;
    const open = force !== undefined ? force : !state.openFolders.has(path);
    if (open === state.openFolders.has(path) && !state.query) return;
    if (open) {
        state.openFolders.add(path);
        row.classList.add('open');
        children.hidden = false;
        anim.expand(children);
    } else {
        state.openFolders.delete(path);
        row.classList.remove('open');
        anim.collapse(children, () => { children.hidden = true; });
    }
    store('open', [...state.openFolders]);
}

function revealInTree(path) {
    let p = parentOf(path), changed = false;
    while (p) { if (!state.openFolders.has(p)) { state.openFolders.add(p); changed = true; } p = parentOf(p); }
    if (changed) { store('open', [...state.openFolders]); renderTree(); }
    const row = rowFor(path);
    if (row) row.scrollIntoView({ block: 'nearest' });
}

function initExplorer() {
    const tree = $('#tree');
    const container = $('#treeContainer');

    // Ricerca
    const search = $('#treeSearchInput');
    search.addEventListener('input', () => {
        state.query = search.value.trim().toLowerCase();
        $('#treeSearchClear').style.display = state.query ? 'block' : 'none';
        renderTree();
    });
    $('#treeSearchClear').addEventListener('click', () => { search.value = ''; search.dispatchEvent(new Event('input')); search.focus(); });

    $('#explorerRefresh').addEventListener('click', (e) => {
        const b = e.currentTarget;
        anim.press(b);
        if (anim.ok()) gsap.fromTo(b.querySelector('.ic'), { rotate: 0 }, { rotate: 360, duration: 0.6, ease: 'power2.out', clearProps: 'transform' });
        refreshTree().then(() => toast(L('js.explorer_refreshed')));
    });

    // Menu hamburger dell'intestazione: elenco verticale delle azioni
    const menuBtn = $('#explorerMenuBtn'), menu = $('#explorerMenu');
    const closeMenu = () => { menu.hidden = true; menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.classList.remove('on'); };
    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!menu.hidden) { closeMenu(); return; }
        $('#contextMenu').hidden = true;
        menu.hidden = false;
        const r = menuBtn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.left = Math.max(8, Math.min(r.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        menuBtn.setAttribute('aria-expanded', 'true');
        menuBtn.classList.add('on');
        anim.pop(menu);
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !menu.contains(e.target)) closeMenu(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) closeMenu(); });
    window.addEventListener('resize', closeMenu);
    $$('[data-act]', menu).forEach(b => b.addEventListener('click', () => {
        closeMenu();
        const dir = selectedDir();
        switch (b.dataset.act) {
            case 'new-file': newFilePrompt(dir); break;
            case 'new-folder': newFolderPrompt(dir); break;
            case 'upload': openUpload(dir); break;
            case 'zip': state.selected.size ? zipItems([...state.selected]) : toast(L('js.select_to_zip'), 'warn'); break;
            case 'refresh': refreshTree().then(() => toast(L('js.explorer_refreshed'))); break;
            case 'collapse': state.openFolders.clear(); store('open', []); renderTree(); break;
            case 'quick-open': openQuickOpen(); break;
        }
    }));

    // Clic sulle righe
    tree.addEventListener('click', (e) => {
        const row = e.target.closest('.row');
        if (!row) return;
        if (e.target.closest('.row-pen')) {
            e.stopPropagation();
            if (!state.selected.has(row.dataset.path)) { state.selected = new Set([row.dataset.path]); updateRowClasses(); }
            const r = e.target.getBoundingClientRect();
            openContextMenu(row, r.left, r.bottom + 4);
            return;
        }
        const path = row.dataset.path;
        if (e.metaKey || e.ctrlKey) {
            state.selected.has(path) ? state.selected.delete(path) : state.selected.add(path);
            state.anchor = path;
        } else if (e.shiftKey && state.anchor) {
            const rows = visibleRows().map(r => r.dataset.path);
            const a = rows.indexOf(state.anchor), b = rows.indexOf(path);
            if (a >= 0 && b >= 0) state.selected = new Set(rows.slice(Math.min(a, b), Math.max(a, b) + 1));
        } else {
            state.selected = new Set([path]);
            state.anchor = path;
            anim.press(row);
            if (row.dataset.type === 'dir') toggleFolder(path);
            else openFile(path);
        }
        updateRowClasses();
    });

    // Doppio clic sulla riga root o sullo spazio vuoto: deseleziona
    container.addEventListener('dblclick', (e) => { if (!e.target.closest('.row')) { state.selected.clear(); updateRowClasses(); } });

    initMarquee(container);
    initDragDrop(container);
}

/** Cartella di riferimento per "nuovo file" dall'intestazione: la cartella selezionata o il genitore del file selezionato. */
function selectedDir() {
    if (state.selected.size !== 1) return '';
    const p = [...state.selected][0];
    return state.dirs.includes(p) ? p : parentOf(p);
}

// Selezione a rettangolo: si parte trascinando nello spazio vuoto dell'Explorer
function initMarquee(container) {
    const box = $('#marquee');
    let start = null, base = null;

    container.addEventListener('mousedown', (e) => {
        if (e.button !== 0 || e.target.closest('.row')) return;
        const cr = container.getBoundingClientRect();
        start = { x: e.clientX - cr.left, y: e.clientY - cr.top + container.scrollTop };
        base = (e.metaKey || e.ctrlKey || e.shiftKey) ? new Set(state.selected) : new Set();
        e.preventDefault();
        container.focus();
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up, { once: true });
    });

    function move(e) {
        const cr = container.getBoundingClientRect();
        const cur = { x: e.clientX - cr.left, y: e.clientY - cr.top + container.scrollTop };
        const x = Math.min(start.x, cur.x), y = Math.min(start.y, cur.y);
        const w = Math.abs(cur.x - start.x), h = Math.abs(cur.y - start.y);
        if (w < 4 && h < 4) return;
        box.hidden = false;
        Object.assign(box.style, { left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px' });

        // Autoscroll vicino ai bordi
        if (e.clientY > cr.bottom - 20) container.scrollTop += 12;
        if (e.clientY < cr.top + 20) container.scrollTop -= 12;

        const sel = new Set(base);
        visibleRows().forEach(r => {
            const rr = r.getBoundingClientRect();
            const top = rr.top - cr.top + container.scrollTop, bottom = top + rr.height;
            if (bottom > y && top < y + h) sel.add(r.dataset.path);
        });
        state.selected = sel;
        updateRowClasses();
    }

    function up() {
        document.removeEventListener('mousemove', move);
        if (box.hidden) { state.selected = base; updateRowClasses(); }
        box.hidden = true;
        start = null;
    }
}

// Drag & drop: sposta l'intera selezione; accetta anche file trascinati dal computer (upload)
function initDragDrop(container) {
    let hoverTimer = null, hoverRow = null;

    const targetFor = (el) => {
        const row = el.closest('.row');
        if (row) return { dir: row.dataset.type === 'dir' ? row.dataset.path : parentOf(row.dataset.path), el: row.dataset.type === 'dir' ? row : (rowFor(parentOf(row.dataset.path)) || $('#treeRootRow')) };
        if (el.closest('#treeRootRow') || el.closest('#treeContainer')) return { dir: '', el: $('#treeRootRow') };
        return null;
    };
    const clearHover = () => {
        clearTimeout(hoverTimer);
        if (hoverRow) { hoverRow.classList.remove('drop-hover'); anim.hoverOff(hoverRow); }
        hoverRow = null;
    };

    container.addEventListener('dragstart', (e) => {
        const row = e.target.closest('.row');
        if (!row) return;
        if (!state.selected.has(row.dataset.path)) { state.selected = new Set([row.dataset.path]); updateRowClasses(); }
        // Se una cartella e un suo contenuto sono entrambi selezionati si sposta solo la cartella
        const sel = [...state.selected];
        state.dragging = sel.filter(p => !sel.some(o => o !== p && p.startsWith(o + '/')));
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', state.dragging.join('\n'));
        const ghost = document.createElement('div');
        ghost.className = 'drag-ghost';
        ghost.textContent = state.dragging.length > 1 ? L('js.n_items', { n: state.dragging.length }) : baseName(state.dragging[0]);
        document.body.appendChild(ghost);
        e.dataTransfer.setDragImage(ghost, 10, 10);
        setTimeout(() => ghost.remove(), 0);
        state.dragging.forEach(p => { const r = rowFor(p); if (r) r.classList.add('dragging'); });
    });

    container.addEventListener('dragend', () => {
        $$('#tree .dragging').forEach(r => r.classList.remove('dragging'));
        state.dragging = null;
        clearHover();
    });

    const over = (e) => {
        const t = targetFor(e.target);
        if (!t) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = state.dragging ? 'move' : 'copy';
        if (hoverRow !== t.el) {
            clearHover();
            hoverRow = t.el;
            hoverRow.classList.add('drop-hover');
            anim.hoverOn(hoverRow);
            if (t.dir && !state.openFolders.has(t.dir) && hoverRow.classList.contains('dir')) {
                hoverTimer = setTimeout(() => toggleFolder(t.dir, true), 650);
            }
        }
    };
    const drop = (e) => {
        const t = targetFor(e.target);
        if (!t) return;
        e.preventDefault();
        clearHover();
        if (state.dragging) {
            const sources = state.dragging.filter(p => parentOf(p) !== t.dir && p !== t.dir);
            if (sources.length) moveItems(sources, t.dir);
        } else if (e.dataTransfer.files && e.dataTransfer.files.length) {
            collectDropped(e.dataTransfer).then(r => startTransfer(t.dir, r.files, r.dirs));
        }
    };
    [container, $('#treeRootRow')].forEach(el => {
        el.addEventListener('dragover', over);
        el.addEventListener('drop', drop);
    });
    container.addEventListener('dragleave', (e) => { if (!container.contains(e.relatedTarget)) clearHover(); });
}

// ----------------------------------------------------------------------------
// OPERAZIONI SUI FILE
// ----------------------------------------------------------------------------
function moveItems(sources, targetDir) {
    apiPost({ action: 'move_items', sources, target_dir: targetDir }).then(res => {
        (res.moved || []).forEach(m => applyPathChange(m.old_path, m.new_path, m.is_dir));
        if (res.error) toast(res.error, 'error');
        if (targetDir) state.openFolders.add(targetDir);
        state.selected = new Set((res.moved || []).map(m => m.new_path));
        refreshAll().then(() => {
            anim.landed((res.moved || []).map(m => rowFor(m.new_path)).filter(Boolean));
            if (res.moved && res.moved.length) toast(res.moved.length === 1 ? L('js.moved_to', { path: '/' + (targetDir || '') }) : L('js.n_moved', { n: res.moved.length }));
        });
    }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
}

/** Aggiorna schede, selezione, contesto e cartelle aperte dopo rinomina/spostamento. */
function applyPathChange(oldPath, newPath, isDir) {
    const map = (p) => (p === oldPath ? newPath : (isDir && p.startsWith(oldPath + '/') ? newPath + p.substring(oldPath.length) : null));
    state.tabs.forEach(t => {
        const n = map(t.path);
        if (n) {
            t.path = n;
            if (t.session && !isDir) t.session.setMode(aceMode(n));
        }
    });
    const n = map(state.active || '');
    if (n) state.active = n;
    ['selected', 'ctxFiles', 'openFolders'].forEach(k => {
        state[k] = new Set([...state[k]].map(p => map(p) || p));
    });
    store('open', [...state.openFolders]);
    store('ctx', [...state.ctxFiles]);
    renderTabs();
    saveTabsState();
    if (state.active) updateTopbar();
}

function refreshAll() {
    return Promise.all([refreshTree(), refreshSide()]);
}

function newFilePrompt(dir) {
    const name = prompt(L('js.new_file_prompt', { dir: '/' + dir }), 'new.php');
    if (!name || !name.trim()) return;
    apiPost({ action: 'create_file', target_dir: dir, file_name: name.trim() }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        if (dir) state.openFolders.add(dir);
        refreshAll().then(() => { openFile(res.path); anim.landed([rowFor(res.path)].filter(Boolean)); });
    });
}

function newFolderPrompt(dir) {
    const name = prompt(L('js.new_folder_prompt', { dir: '/' + dir }), 'new_folder');
    if (!name || !name.trim()) return;
    apiPost({ action: 'create_folder', parent_dir: dir, folder_name: name.trim() }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        if (dir) state.openFolders.add(dir);
        state.openFolders.add(res.path);
        store('open', [...state.openFolders]);
        refreshAll().then(() => anim.landed([rowFor(res.path)].filter(Boolean)));
    });
}

function renamePrompt(path) {
    const current = baseName(path);
    const isFile = state.files.includes(path) && extOf(path) !== '' && !baseName(path).startsWith('.');
    const name = prompt(L('js.rename_prompt', { name: current }) + (isFile ? '\n' + L('js.rename_ext_hint', { ext: extOf(path) }) : ''), current);
    if (!name || !name.trim() || name.trim() === current) return;
    apiPost({ action: 'rename_item', old_path: path, new_name: name.trim() }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        if (res.unchanged) return;
        applyPathChange(res.old_path, res.new_path, res.is_dir);
        state.selected = new Set([res.new_path]);
        refreshAll().then(() => anim.landed([rowFor(res.new_path)].filter(Boolean)));
    });
}

function deletePaths(paths) {
    if (!paths.length) return;
    const msg = paths.length === 1
        ? L(state.dirs.includes(paths[0]) ? 'js.delete_folder_q' : 'js.delete_one_q', { path: paths[0] })
        : L('js.delete_many_q', { n: paths.length }) + '\n\n' + paths.slice(0, 12).join('\n') + (paths.length > 12 ? '\n…' : '');
    if (!confirm(msg + '\n\n' + L('js.delete_trash_note'))) return;
    apiPost({ action: 'delete_items', paths }).then(res => {
        (res.deleted || []).forEach(d => {
            state.tabs.filter(t => t.path === d.path || (d.is_dir && t.path.startsWith(d.path + '/')))
                .forEach(t => closeTab(t.path, true));
        });
        if (res.error) toast(res.error, 'error');
        else toast(paths.length === 1 ? L('js.deleted') : L('js.n_deleted', { n: paths.length }));
        state.selected.clear();
        refreshAll();
    });
}

// Duplica file e cartelle accanto all'originale ("nome copy.php"); un solo file duplicato si apre subito
function duplicateItems(paths) {
    if (!paths.length) return;
    apiPost({ action: 'duplicate_items', paths }).then(res => {
        if (res.error) toast(res.error, 'error');
        const items = res.items || [];
        if (!items.length) return;
        state.selected = new Set(items.map(x => x.path));
        refreshAll().then(() => {
            anim.landed(items.map(x => rowFor(x.path)).filter(Boolean));
            if (items.length === 1 && !items[0].is_dir) openFile(items[0].path);
            toast(items.length === 1 ? L('dup.done_one', { path: items[0].path }) : L('dup.done_many', { n: items.length }));
        });
    }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
}

// Comprime file e cartelle in uno ZIP accanto al primo elemento (come "Comprimi" del Finder)
function zipItems(paths) {
    if (!paths.length) return;
    toast(L('js.zipping'));
    apiPost({ action: 'zip_items', paths }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        state.selected = new Set([res.path]);
        if (parentOf(res.path)) state.openFolders.add(parentOf(res.path));
        refreshAll().then(() => {
            anim.landed([rowFor(res.path)].filter(Boolean));
            toast(L('js.zip_created', { name: baseName(res.path), n: res.files }));
        });
    }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
}

function unzipItem(path) {
    apiPost({ action: 'unzip_item', path }).then(async res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        if (res.job) {
            // ZIP grande: scompattato a passi con l'avanzamento nella finestra di trasferimento
            if (transfer.running) { toast(L('tr.busy'), 'warn'); return; }
            openTransferDialog(L('tr.unzip_title', { name: baseName(path) }), L('tr.unzip_into', { path: '/' + res.path }));
            const item = { rel: path, size: 0 };
            addTransferRow(item);
            await runUnzipJob(item, res.job, res.total);
            finishTransfer(parentOf(path), [item], () => {
                state.openFolders.add(res.path);
                if (parentOf(res.path)) state.openFolders.add(parentOf(res.path));
                store('open', [...state.openFolders]);
                state.selected = new Set([res.path]);
            });
            return;
        }
        state.openFolders.add(res.path);
        if (parentOf(res.path)) state.openFolders.add(parentOf(res.path));
        store('open', [...state.openFolders]);
        state.selected = new Set([res.path]);
        refreshAll().then(() => {
            anim.landed([rowFor(res.path)].filter(Boolean));
            toast(L('js.unzipped', { path: '/' + res.path, n: res.files }) + (res.skipped.length ? ', ' + L('js.n_skipped_log', { n: res.skipped.length }) : ''), res.skipped.length ? 'warn' : 'ok');
        });
    }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
}

function previewUrl(path) {
    if (!APP.publicUrl) return null;
    return APP.publicUrl + path.split('/').map(encodeURIComponent).join('/');
}

function openPreviewWindow(path) {
    const url = previewUrl(path);
    if (!url) { toast(L('js.no_preview'), 'error'); return; }
    // Stesso nome finestra: l'anteprima riusa sempre la stessa scheda del browser
    const w = window.open(url, 'scaffolder_preview');
    if (w) w.focus();
}

function downloadPath(path) {
    window.location.href = 'api.php?action=download&path=' + encodeURIComponent(path);
}

// ----------------------------------------------------------------------------
// MENU CONTESTUALE (tasto destro su desktop, matita su mobile)
// ----------------------------------------------------------------------------
function initContextMenu() {
    const menu = $('#contextMenu');
    const explorer = $('#explorerCard');

    explorer.addEventListener('contextmenu', (e) => {
        const row = e.target.closest('.row');
        if (!row && !e.target.closest('#treeContainer') && !e.target.closest('#treeRootRow')) return;
        e.preventDefault();
        if (row && !state.selected.has(row.dataset.path)) { state.selected = new Set([row.dataset.path]); updateRowClasses(); }
        openContextMenu(row, e.clientX, e.clientY);
    });
    $('#treeRootRow .row-pen').addEventListener('click', (e) => {
        e.stopPropagation();
        const r = e.target.getBoundingClientRect();
        openContextMenu(null, r.left, r.bottom + 4);
    });

    document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') menu.hidden = true; });
    window.addEventListener('blur', () => { menu.hidden = true; });

    $$('.ctx-item', menu).forEach(btn => btn.addEventListener('click', () => {
        menu.hidden = true;
        const t = ctxTarget;
        switch (btn.dataset.ctx) {
            case 'new-file': newFilePrompt(t.dir); break;
            case 'new-folder': newFolderPrompt(t.dir); break;
            case 'upload': openUpload(t.dir); break;
            case 'download-all':
                toast(L('js.preparing_zip'));
                $('#downloadAllForm').submit();
                setTimeout(refreshSide, 3000);
                break;
            case 'preview': openPreviewWindow(t.path); break;
            case 'download': t.paths.forEach((p, i) => setTimeout(() => downloadPath(p), i * 400)); break;
            case 'zip': zipItems(t.paths); break;
            case 'duplicate': duplicateItems(t.paths); break;
            case 'unzip': unzipItem(t.path); break;
            case 'claude': toggleClaudeContext(t.paths.filter(p => state.files.includes(p))); break;
            case 'copy-path': {
                const full = APP.rootDir + (t.path ? '/' + t.path : '');
                navigator.clipboard.writeText(full).then(() => toast(L('js.path_copied'))).catch(() => prompt(L('menu.copy_path') + ':', full));
                break;
            }
            case 'rename': renamePrompt(t.path); break;
            case 'delete': deletePaths(t.paths); break;
        }
    }));
}

function openContextMenu(row, x, y) {
    const menu = $('#contextMenu');
    if (row) {
        const path = row.dataset.path;
        const type = row.dataset.type;
        const paths = state.selected.size > 1 && state.selected.has(path) ? [...state.selected] : [path];
        ctxTarget = { type, path, dir: type === 'dir' ? path : parentOf(path), paths };
        $('#ctxHeader').textContent = paths.length > 1 ? L('js.n_selected', { n: paths.length }) : path + (type === 'dir' ? '/' : '');
    } else {
        ctxTarget = { type: 'root', path: '', dir: '', paths: [] };
        $('#ctxHeader').textContent = '/ (root) — ' + baseName(APP.rootDir);
    }
    const t = ctxTarget;
    const single = t.paths.length === 1;
    $$('[data-only]', menu).forEach(el => {
        const only = el.dataset.only;
        el.hidden = (only === 'root' && t.type !== 'root')
            || (only === 'item' && !t.paths.length)
            || (only === 'single' && !single)
            || (only === 'file' && !(t.type === 'file' || t.paths.some(p => state.files.includes(p)) && t.paths.length > 1))
            || (only === 'zip' && !(single && t.type === 'file' && extOf(t.path) === 'zip'))
            || (el.dataset.ctx === 'preview' && !(single && t.type === 'file' && APP.publicUrl));
    });
    menu.hidden = false;
    const mw = menu.offsetWidth, mh = menu.offsetHeight;
    menu.style.left = Math.max(4, Math.min(x, window.innerWidth - mw - 8)) + 'px';
    menu.style.top = Math.max(4, Math.min(y, window.innerHeight - mh - 8)) + 'px';
    anim.pop(menu);
}

// ----------------------------------------------------------------------------
// EDITOR A SCHEDE
// ----------------------------------------------------------------------------
function aceMode(path) {
    const b = baseName(path);
    if (b === '.htaccess') return 'ace/mode/apache_conf';
    const m = {
        php: 'php', html: 'html', htm: 'html', css: 'css', scss: 'scss', js: 'javascript', mjs: 'javascript', jsx: 'javascript',
        ts: 'typescript', tsx: 'typescript', vue: 'html', json: 'json', svg: 'xml', xml: 'xml', md: 'markdown', sql: 'sql',
        yml: 'yaml', yaml: 'yaml', ini: 'ini', env: 'ini', sh: 'sh',
    }[extOf(path)];
    return 'ace/mode/' + (m || 'text');
}

function initEditor() {
    if (!window.ace) {
        $('#fileNotice').hidden = false;
        $('#fileNotice').textContent = L('js.ace_missing');
        return;
    }
    ace.config.set('basePath', 'lib/ace/');   // modi, temi e snippet sono nella cartella del progetto
    ace.require('ace/ext/language_tools');
    editor = ace.edit('editor');
    editor.setTheme('ace/theme/tomorrow_night');
    editor.setOptions({
        showPrintMargin: false,
        highlightActiveLine: true,
        highlightSelectedWord: true,
        behavioursEnabled: true,
        enableBasicAutocompletion: true,
        enableLiveAutocompletion: true,
    });
    editor.commands.addCommand({ name: 'save', bindKey: { win: 'Ctrl-S', mac: 'Command-S' }, exec: () => saveActive() });
    editor.commands.addCommand({ name: 'closeTab', bindKey: { win: 'Alt-W', mac: 'Alt-W' }, exec: () => state.active && closeTab(state.active) });
    editor.commands.addCommand({ name: 'searchFiles', bindKey: { win: 'Ctrl-Shift-F', mac: 'Command-Shift-F' }, exec: () => openSearch(true) });
    applyEditorZoom(currentZoom);
    // Spostamenti del cursore: alimentano la cronologia di navigazione (Indietro / Avanti)
    editor.on('changeSelection', () => {
        const tab = tabFor(state.active);
        if (!tab || tab.session !== editor.session) return;
        const p = editor.getCursorPosition();
        navRecord(tab.path, p.row, p.column);
    });

    // Touch: la tastiera si apre solo toccando l'area del testo (.ace_content), non gutter, barre o bordi
    if (IS_TOUCH) {
        const guard = (e) => {
            if (!e.target.closest('.ace_content')) {
                e.stopPropagation();
                blurEditor();
            }
        };
        ['touchstart', 'mousedown', 'pointerdown'].forEach(ev => editor.container.addEventListener(ev, guard, { capture: true, passive: true }));
    }

    window.addEventListener('beforeunload', (e) => {
        if (anyDirty() || transfer.running) { e.preventDefault(); e.returnValue = ''; }
    });
}

/** Mette il focus su un campo solo con mouse e tastiera: su telefono/tablet aprirebbe la tastiera senza che l'utente tocchi il campo. */
function focusSoft(el) {
    if (el && !IS_TOUCH) el.focus();
}

/** Toglie il focus all'editor (su touch chiude la tastiera). */
function blurEditor() {
    if (editor) editor.blur();
    const el = document.activeElement;
    if (el && el.classList && el.classList.contains('ace_text-input')) el.blur();
}

function makeSession(path, content) {
    const s = ace.createEditSession(content, aceMode(path));
    s.setOptions({ tabSize: 4, useSoftTabs: true, useWorker: false });
    s.setUseWrapMode(!!APP.config.editor_wrap);
    return s;
}

function tabFor(path) { return state.tabs.find(t => t.path === path); }
function isDirty(t) { return t && t.session && t.session.getValue() !== t.saved; }
function anyDirty() { return state.tabs.some(isDirty); }

function openFile(path, { activate = true, animate = true, silent = false } = {}) {
    const existing = tabFor(path);
    if (existing) { if (activate) activateTab(path); return Promise.resolve(existing); }
    if (path.startsWith('http:')) return openHttpTab(parseInt(path.slice(5), 10), { activate, animate, silent });
    return apiGet('get_file', { file: path }).then(res => {
        if (!res.ok) { toast(res.error || L('js.cannot_open', { path }), 'error'); return null; }
        if (tabFor(path)) return tabFor(path);
        const tab = {
            path, size: res.size, writable: res.writable, mtime: res.mtime,
            kind: res.too_big ? 'big' : (res.image ? 'image' : (res.binary ? 'binary' : 'code')),
            session: null, saved: res.content,
        };
        if (tab.kind === 'code' && editor) attachSession(tab, res.content);
        state.tabs.push(tab);
        renderTabs(animate ? path : null);
        if (activate) activateTab(path);
        saveTabsState();
        return tab;
    }).catch(err => { toast(L('js.network_error') + ': ' + err, 'error'); return null; });
}

function attachSession(tab, content) {
    tab.session = makeSession(tab.path, content);
    tab.saved = content;
    tab.session.on('change', () => {
        markTabDirty(tab);
        if (tab.path === state.active) updateSaveStatus(tab);
        scheduleLiveLint(tab);
    });
    scheduleLiveLint(tab, 300);
}

// Un SVG si apre come immagine: questo pulsante lo trasforma in scheda di codice
function openAsCode(path) {
    const tab = tabFor(path);
    if (!tab) return;
    apiGet('get_file', { file: path }).then(res => {
        if (!res.ok || res.binary || res.too_big) return;
        tab.kind = 'code';
        tab.mtime = res.mtime;
        attachSession(tab, res.content);
        activateTab(path);
    });
}

function renderTabs(animatePath = null) {
    const bar = $('#tabBar');
    bar.innerHTML = '';
    state.tabs.forEach(t => {
        const el = document.createElement('div');
        el.className = 'tab' + (t.path === state.active ? ' active' : '') + (isDirty(t) ? ' dirty' : '');
        el.title = t.kind === 'http' ? t.req.method + ' ' + (t.req.url || L('js.no_url')) : (t.kind === 'diff' ? L('js.claude_proposal') + ': ' + t.prop.path : t.path);
        el.dataset.path = t.path;
        el.innerHTML = `${tabIcon(t)}<span class="tab-name">${esc(tabName(t))}</span>`
            + `<button type="button" class="tab-close" title="${esc(L('js.close_tab'))}"><span class="x">${ic('x')}</span><span class="dot"></span></button>`;
        el.addEventListener('mousedown', (e) => { if (e.button === 1) { e.preventDefault(); closeTab(t.path); } });
        el.addEventListener('click', (e) => {
            if (Date.now() - tabDrag.endedAt < 250) return;   // rilascio di un trascinamento, non un clic
            if (e.target.closest('.tab-close')) closeTab(t.path);
            else activateTab(t.path);
        });
        t.el = el;
        bar.appendChild(el);
        if (t.path === animatePath) anim.tabIn(el);
    });
    const act = $('.tab.active', bar);
    if (act) act.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

// ----------------------------------------------------------------------------
// RIORDINO DELLE SCHEDE TRASCINANDOLE
// Mouse: si trascina subito. Touch: pressione prolungata (così la barra resta scorrevole col dito).
// ----------------------------------------------------------------------------
const tabDrag = { cur: null, endedAt: 0 };

function initTabDrag() {
    const bar = $('#tabBar');

    const start = (d) => {
        d.active = true;
        d.rects = $$('.tab', bar).map(t => ({ el: t, path: t.dataset.path, r: t.getBoundingClientRect() }));
        d.el.classList.add('tab-dragging');
        bar.classList.add('reordering');
        if (d.touch && navigator.vibrate) navigator.vibrate(15);
        if (anim.ok()) gsap.to(d.el, { scale: 1.06, duration: 0.25, ease: 'back.out(3)' });
    };

    const target = (d) => {
        // Posizione d'inserimento: quante schede (escluso quella trascinata) hanno il centro a sinistra del dito/puntatore
        const others = d.rects.filter(o => o.el !== d.el);
        let idx = others.findIndex(o => d.x < o.r.left + o.r.width / 2);
        if (idx < 0) idx = others.length;
        return { others, idx };
    };

    const move = (d, x) => {
        d.x = x;
        d.el.style.transform = `translateX(${x - d.x0}px) scale(1.06)`;
        const { others, idx } = target(d);
        others.forEach((o, i) => {
            o.el.classList.toggle('drop-before', i === idx);
            o.el.classList.toggle('drop-after', idx === others.length && i === others.length - 1);
        });
        // Scorrimento automatico della barra vicino ai bordi
        const br = bar.getBoundingClientRect();
        if (x > br.right - 30) bar.scrollLeft += 12;
        if (x < br.left + 30) bar.scrollLeft -= 12;
    };

    const finish = (cancelled) => {
        const d = tabDrag.cur;
        if (!d) return;
        clearTimeout(d.timer);
        tabDrag.cur = null;
        if (!d.active) return;
        tabDrag.endedAt = Date.now();
        bar.classList.remove('reordering');
        $$('.tab', bar).forEach(t => t.classList.remove('drop-before', 'drop-after', 'tab-dragging'));
        if (anim.ok()) gsap.killTweensOf(d.el);
        d.el.style.transform = '';
        if (cancelled) return;
        const { others, idx } = target(d);
        const order = others.map(o => o.path);
        order.splice(idx, 0, d.path);
        state.tabs.sort((a, b) => order.indexOf(a.path) - order.indexOf(b.path));
        renderTabs();
        saveTabsState();
        const moved = tabFor(d.path);
        if (moved && moved.el) anim.landed([moved.el]);
        if (d.path !== state.active) activateTab(d.path);
    };

    // --- Mouse: si trascina appena ci si sposta di qualche pixel
    bar.addEventListener('pointerdown', (e) => {
        if (e.pointerType !== 'mouse' || e.button !== 0) return;
        const el = e.target.closest('.tab');
        if (!el || e.target.closest('.tab-close')) return;
        tabDrag.cur = { el, path: el.dataset.path, id: e.pointerId, x0: e.clientX, y0: e.clientY, x: e.clientX, active: false, touch: false };
    });
    window.addEventListener('pointermove', (e) => {
        const d = tabDrag.cur;
        if (!d || d.touch || e.pointerId !== d.id) return;
        if (!d.active) {
            if (Math.hypot(e.clientX - d.x0, e.clientY - d.y0) < 6) return;
            start(d);
        }
        move(d, e.clientX);
    });
    window.addEventListener('pointerup', (e) => { if (tabDrag.cur && !tabDrag.cur.touch) finish(false); });

    // --- Touch (telefono/tablet): eventi touch, più affidabili su iOS e Android.
    // Tieni premuta la scheda ~0,3 s: si "solleva" e la trascini. Uno scorrimento veloce fa scorrere la barra.
    bar.addEventListener('touchstart', (e) => {
        const el = e.target.closest('.tab');
        if (!el || e.target.closest('.tab-close') || e.touches.length > 1) return;
        const t = e.touches[0];
        const d = { el, path: el.dataset.path, x0: t.clientX, y0: t.clientY, x: t.clientX, active: false, touch: true };
        tabDrag.cur = d;
        d.timer = setTimeout(() => { if (tabDrag.cur === d) start(d); }, 300);
    }, { passive: true });
    bar.addEventListener('touchmove', (e) => {
        const d = tabDrag.cur;
        if (!d || !d.touch) return;
        const t = e.touches[0];
        if (!d.active) {
            // Il dito si è mosso prima della pressione prolungata: è uno scorrimento della barra
            if (Math.hypot(t.clientX - d.x0, t.clientY - d.y0) > 8) { clearTimeout(d.timer); tabDrag.cur = null; }
            return;
        }
        e.preventDefault();   // la barra e la pagina non devono scorrere mentre si trascina
        move(d, t.clientX);
    }, { passive: false });
    bar.addEventListener('touchend', (e) => {
        const d = tabDrag.cur;
        if (!d || !d.touch) return;
        if (d.active) e.preventDefault();   // niente "clic" sulla scheda al rilascio
        finish(false);
    }, { passive: false });
    bar.addEventListener('touchcancel', () => { if (tabDrag.cur && tabDrag.cur.touch) finish(true); });
    // Niente menu "copia/condividi" del browser sulla pressione prolungata
    bar.addEventListener('contextmenu', (e) => { if (e.target.closest('.tab')) e.preventDefault(); });
}

function markTabDirty(tab) {
    if (tab.el) tab.el.classList.toggle('dirty', isDirty(tab));
    if (tab.path === state.active) $('#saveBtn').classList.toggle('dirty', isDirty(tab));
}

function activateTab(path) {
    const tab = tabFor(path);
    if (!tab) return;
    if (state.active && state.active !== path) {
        const prev = tabFor(state.active);
        if (prev && prev.session && editor) prev.scroll = editor.session.getScrollTop();
    }
    state.active = path;
    $$('#tabBar .tab').forEach(el => el.classList.toggle('active', el.dataset.path === path));
    const tabEl = $$('#tabBar .tab').find(el => el.dataset.path === path);
    if (tabEl) tabEl.scrollIntoView({ block: 'nearest', inline: 'nearest' });

    $('#welcome').hidden = true;
    $('#editorTopbar').hidden = false;
    const notice = $('#fileNotice');
    notice.hidden = true;
    $('#saveStatus').textContent = '';
    $('#saveStatus').className = 'save-status';

    const isHttp = tab.kind === 'http';
    const isDiff = tab.kind === 'diff';
    $('#httpView').hidden = !isHttp;
    $('#diffView').hidden = !isDiff;
    $('#editorTopbar').hidden = isHttp || isDiff;
    if (isDiff) {
        $('#editorWrap').hidden = true;
        $('#imageView').hidden = true;
        $('#versionsCard').hidden = true;
        renderDiffTab(tab);
        renderTabs();
        renderCtxChips();
        saveTabsState();
        navRecord(path, 0, 0);
        document.title = L('js.claude_proposal') + ': ' + baseName(tab.prop.path) + ' — Scaffolder Pro';
        return;
    }
    if (isHttp) {
        $('#editorWrap').hidden = true;
        $('#imageView').hidden = true;
        $('#versionsCard').hidden = true;
        renderHttp(tab);
        renderTabs();
        renderCtxChips();
        renderHttpList();
        saveTabsState();
        navRecord(path, 0, 0);
        document.title = tab.req.name + ' — Scaffolder Pro';
        return;
    }
    const isCode = tab.kind === 'code' && editor;
    $('#editorWrap').hidden = !isCode;
    $('#imageView').hidden = tab.kind === 'code';
    if (isCode) {
        editor.setSession(tab.session);
        editor.setReadOnly(!tab.writable);
        editor.resize();
        // Su telefono/tablet la tastiera si apre solo toccando il codice, non cambiando scheda
        if (IS_TOUCH) blurEditor(); else editor.focus();
        if (!tab.writable) { notice.hidden = false; notice.innerHTML = L('js.file_not_writable', { user: esc(APP.daemonUser) }); }
        if (tab.saveBlocked) showLintNotice(tab, tab.saveBlocked);
    } else {
        showNonCode(tab);
    }
    updateTopbar();
    $('#saveBtn').classList.toggle('dirty', isDirty(tab));
    updateRowClasses();
    revealInTree(path);
    loadVersions(path);
    renderCtxChips();
    if (state.split) refreshSplit();
    saveTabsState();
    document.title = baseName(path) + ' — Scaffolder Pro';
    const pos = isCode ? editor.getCursorPosition() : { row: 0, column: 0 };
    navRecord(path, pos.row, pos.column);
    scheduleMinimap();
    if (isCode && APP.config.emmet && EMMET_MARKUP.concat(EMMET_CSS).includes(extOf(path))) loadEmmet();
}

function showNonCode(tab) {
    const img = $('#imageEl'), meta = $('#imageMeta');
    meta.innerHTML = '';
    if (tab.kind === 'image') {
        img.hidden = false;
        img.onload = () => {
            meta.innerHTML = `${img.naturalWidth} × ${img.naturalHeight}px · ${humanSize(tab.size)}`
                + (extOf(tab.path) === 'svg' ? ' · <button type="button" class="mini-btn add" id="svgAsCode">' + esc(L('js.edit_svg')) + '</button>' : '');
            const b = $('#svgAsCode');
            if (b) b.onclick = () => openAsCode(tab.path);
        };
        img.src = 'api.php?action=raw&file=' + encodeURIComponent(tab.path) + '&t=' + tab.mtime;
        anim.pop(img);
    } else {
        img.hidden = true;
        img.removeAttribute('src');
        meta.innerHTML = tab.kind === 'big'
            ? esc(L('js.file_too_big', { size: humanSize(tab.size) }))
            : esc(L('js.file_binary', { ext: extOf(tab.path).toUpperCase(), size: humanSize(tab.size) }));
        meta.innerHTML += ` <button type="button" class="mini-btn add" id="binDownload">${ic('download')} ${esc(L('menu.download'))}</button>`;
        $('#binDownload').onclick = () => downloadPath(tab.path);
    }
}

function closeTab(path, force = false) {
    const tab = tabFor(path);
    if (!tab) return;
    if (!force && isDirty(tab) && !confirm(L('js.close_dirty', { name: baseName(path) }))) return;
    // La scheda esce subito dall'elenco (riaprire lo stesso file ne crea una nuova); l'animazione riguarda solo l'elemento
    const idx = state.tabs.indexOf(tab);
    state.tabs.splice(idx, 1);
    const wasActive = state.active === path;
    if (wasActive) state.active = null;
    const finish = () => {
        if (tab.session) tab.session.destroy && tab.session.destroy();
        renderTabs();
        if (wasActive && !state.active) {
            const next = state.tabs[Math.min(idx, state.tabs.length - 1)];
            if (next) activateTab(next.path); else showWelcome();
        }
        saveTabsState();
    };
    if (tab.el) anim.tabOut(tab.el, finish); else finish();
}

function showWelcome() {
    state.active = null;
    $('#welcome').hidden = false;
    $('#editorWrap').hidden = true;
    $('#imageView').hidden = true;
    $('#editorTopbar').hidden = true;
    $('#fileNotice').hidden = true;
    $('#versionsCard').hidden = true;
    $('#httpView').hidden = true;
    $('#diffView').hidden = true;
    renderHttpList();
    $('#previewFrame').hidden = true;
    document.title = 'Scaffolder Pro';
    updateRowClasses();
    renderCtxChips();
    saveTabsState();
}

function saveTabsState() {
    store('tabs', { list: state.tabs.map(t => t.path), active: state.active });
}

function updateTopbar() {
    const tab = tabFor(state.active);
    if (!tab) return;
    $('#fileTitle').textContent = tab.path;
    $('#fileBadge').textContent = (extOf(tab.path) || baseName(tab.path)).toUpperCase() + ' · ' + humanSize(tab.size);
    const isCode = tab.kind === 'code';
    $('#saveBtn').hidden = !isCode;
    $('#saveBtn').disabled = !tab.writable;
    $('#zoomControls').hidden = !isCode;
    $('#previewBtn').disabled = !APP.publicUrl;
    $('#previewBtn').title = APP.publicUrl ? '' : L('js.root_not_docroot');
    $('#splitBtn').hidden = !APP.publicUrl;
    $('#deleteBtn').disabled = !tab.writable;
    $('#formatBtn').disabled = !isCode || !tab.writable || !formatterFor(tab.path);
    $('#symbolsBtn').disabled = !isCode;
    $('#minimapBtn').hidden = !isCode;
    $('#minimapBtn').classList.toggle('on', !!APP.config.minimap);
    $('#minimapBtn').setAttribute('aria-checked', String(!!APP.config.minimap));
    updateSaveStatus(tab);
}

function updateSaveStatus(tab, msg, cls) {
    const st = $('#saveStatus');
    if (msg) { st.textContent = msg; st.className = 'save-status ' + (cls || ''); return; }
    if (isDirty(tab)) { st.textContent = L('js.unsaved'); st.className = 'save-status dirty'; }
    else if (st.classList.contains('dirty')) { st.textContent = ''; st.className = 'save-status'; }
}

/** CRC32 del testo in UTF-8 (uguale a sprintf('%u', crc32()) di PHP): dice al server quale versione aveva caricato l'editor. */
const CRC_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; }
    return t;
})();
function crc32(text) {
    const bytes = new TextEncoder().encode(text);
    let c = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return String((c ^ 0xFFFFFFFF) >>> 0);
}

/** Contenuto appena letto dal server: diventa la versione "salvata" della scheda (con la sua data di modifica). */
function setTabFromDisk(tab, f) {
    tab.session.setValue(f.content);
    tab.saved = f.content;
    tab.size = f.size;
    tab.mtime = f.mtime;
    markTabDirty(tab);
}

function saveActive({ force = false, overwriteDisk = false, formatted = false } = {}) {
    const tab = tabFor(state.active);
    if (!tab || tab.kind !== 'code' || !tab.writable || tab.saving) return;
    // "Formatta al salvataggio": prima Prettier (se il file ha errori di sintassi si salva com'è)
    if (APP.config.format_on_save && !formatted && formatterFor(tab.path)) {
        tab.saving = true;
        formatTab(tab, { quiet: true }).catch(() => {}).then(() => { tab.saving = false; saveActive({ force, overwriteDisk, formatted: true }); });
        return;
    }
    const code = tab.session.getValue();
    tab.saving = true;
    updateSaveStatus(tab, L('js.saving'));
    const fields = { action: 'save_file', filepath: tab.path, code, mtime: tab.mtime || 0, base_crc: crc32(tab.saved || '') };
    if (force) fields.force = '1';
    if (overwriteDisk) fields.force_mtime = '1';
    if (tab.claudePending) fields.reason = 'claude';
    apiPost(fields).then(res => {
        tab.saving = false;
        if (!res.ok && res.conflict) {
            // Il file è stato cambiato sul disco da qualcun altro dopo l'apertura
            updateSaveStatus(tab, L('js.conflict_status'), 'error');
            const msg = res.error + '\n\n' + L('js.conflict_q');
            if (confirm(msg)) saveActive({ force, overwriteDisk: true });
            else if (confirm(L('js.conflict_reload_q'))) apiGet('get_file', { file: tab.path }).then(f => { if (f.ok) { setTabFromDisk(tab, f); updateTopbar(); updateSaveStatus(tab, L('js.reloaded_disk'), 'ok'); } });
            return;
        }
        if (!res.ok && res.lint) {
            // Errore di sintassi PHP: riga segnata, avviso in alto e scelta "Salva comunque" / "Annulla"
            tab.saveBlocked = res.lint;
            setLintMarks(tab, res.lint);
            updateSaveStatus(tab, L('js.unsaved_syntax'), 'error');
            if (tab.path === state.active) showLintNotice(tab, res.lint, res.server_php);
            return;
        }
        if (!res.ok) { updateSaveStatus(tab, res.error, 'error'); toast(res.error, 'error'); return; }
        tab.saveBlocked = null;
        tab.claudePending = false;
        if (force) toast(L('js.saved_despite'), 'warn');
        else setLintMarks(tab, null);
        hideLintNotice();
        tab.saved = code;
        tab.size = new Blob([code]).size;
        if (res.mtime) tab.mtime = res.mtime;
        markTabDirty(tab);
        if (tab.path === state.active) {
            updateTopbar();
            updateSaveStatus(tab, res.unchanged ? L('js.no_changes') : L('js.saved_at', { time: res.saved_at }), 'ok');
            anim.press($('#saveBtn'));
        }
        if (!res.unchanged) {
            loadVersions(tab.path);
            refreshSide();
            if (state.split) refreshSplit();
        }
    }).catch(err => { tab.saving = false; updateSaveStatus(tab, L('js.network_error'), 'error'); console.error(err); });
}

function applyEditorZoom(size) {
    currentZoom = Math.max(10, Math.min(32, size));
    if (editor) {
        editor.setFontSize(currentZoom + 'px');
        editor.container.style.lineHeight = Math.round(currentZoom * 1.55) + 'px';
        editor.renderer.updateFontSize();
    }
    $('#zoomLabel').textContent = currentZoom + 'px';
    localStorage.setItem('scaffolder_editor_zoom', String(currentZoom));
}

function initTopbar() {
    $$('[data-zoom]').forEach(b => b.addEventListener('click', () => applyEditorZoom(currentZoom + parseInt(b.dataset.zoom, 10))));
    $('#zoomLabel').addEventListener('click', () => applyEditorZoom(parseInt(APP.config.editor_font, 10) || 14));
    $('#saveBtn').addEventListener('click', saveActive);
    // Menu ☰ della barra dell'editor: anteprima, anteprima affiancata, scarica, elimina
    const menuBtn = $('#editorMenuBtn'), menu = $('#editorMenu');
    const closeMenu = () => { menu.hidden = true; menuBtn.setAttribute('aria-expanded', 'false'); menuBtn.classList.remove('on'); };
    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!menu.hidden) { closeMenu(); return; }
        menu.hidden = false;
        const r = menuBtn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.left = Math.max(8, Math.min(r.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        menuBtn.setAttribute('aria-expanded', 'true');
        menuBtn.classList.add('on');
        anim.pop(menu);
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !menu.contains(e.target)) closeMenu(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) closeMenu(); });
    window.addEventListener('resize', closeMenu);
    $$('[data-ed]', menu).forEach(b => b.addEventListener('click', () => {
        closeMenu();
        if (!state.active) return;
        switch (b.dataset.ed) {
            case 'format': formatTab(tabFor(state.active)); break;
            case 'symbols': openSymbols(); break;
            case 'minimap':
                APP.config.minimap = !APP.config.minimap;
                apiPost({ action: 'save_settings', settings: { minimap: APP.config.minimap } }).then(r => { if (r.ok) APP.config = r.config; });
                updateTopbar();
                scheduleMinimap();
                break;
            case 'preview': openPreviewWindow(state.active); break;
            case 'split': toggleSplit(); break;
            case 'download': downloadPath(state.active); break;
            case 'delete': deletePaths([state.active]); break;
        }
    }));
}

function toggleSplit() {
    state.split = !state.split;
    $('#splitBtn').classList.toggle('on', state.split);
    $('#splitBtn').setAttribute('aria-checked', String(state.split));
    $('#workspaceGrid').classList.toggle('split', state.split);
    $('#previewFrame').hidden = !state.split;
    if (state.split) refreshSplit();
    if (editor) setTimeout(() => editor.resize(), 50);
}

function refreshSplit() {
    const t = tabFor(state.active);
    const url = t && t.kind !== 'http' && t.kind !== 'diff' && previewUrl(state.active);
    const frame = $('#previewFrame');
    if (url) frame.src = url + '?t=' + Date.now();
}

// ----------------------------------------------------------------------------
// VERSIONI, REGISTRO, CESTINO
// ----------------------------------------------------------------------------
const REASON = { save: 'ver.r_save', delete: 'ver.r_delete', restore: 'ver.r_restore', legacy: 'ver.r_legacy', replace: 'ver.r_replace', claude: 'ver.r_claude' };

function loadVersions(path) {
    const tab = tabFor(path);
    const card = $('#versionsCard');
    if (!tab || tab.kind !== 'code') { card.hidden = true; return; }
    apiGet('get_versions', { file: path }).then(res => {
        if (state.active !== path) return;
        card.hidden = false;
        updateMoveButtons();
        $('#versionsCount').textContent = res.versions.length;
        $('#versionsList').innerHTML = res.versions.length
            ? res.versions.map(v => `<div class="bak-item"><span><a href="#" data-view="${v.id}">${esc(v.created_at)}</a>`
                + `<small class="muted">${esc(REASON[v.reason] ? L(REASON[v.reason]) : v.reason)} · ${humanSize(+v.size)}</small></span>`
                + `<button type="button" class="mini-btn add" data-restore="${v.id}" title="${esc(L('js.restore_version'))}">${ic('undo')}</button></div>`).join('')
            : `<div class="log-empty">${esc(L('js.no_versions'))}</div>`;
    });
}

function restoreVersion(id, fromTrash) {
    const msg = fromTrash ? L('js.restore_file_q') : L('js.restore_version_q');
    if (!confirm(msg)) return;
    apiPost({ action: 'restore_version', id }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        toast(L('js.restored', { path: res.path }));
        const tab = tabFor(res.path);
        const reopen = () => apiGet('get_file', { file: res.path }).then(f => {
            if (!f.ok || !tab) return;
            if (isDirty(tab) && !confirm(L('js.restore_dirty_q'))) return;
            setTabFromDisk(tab, f);
            if (tab.path === state.active) updateTopbar();
        });
        refreshAll().then(() => {
            if (tab && tab.kind === 'code') reopen().then(() => loadVersions(res.path));
            else openFile(res.path);
        });
    });
}

// Finestra della versione: differenza con il testo attuale dell'editor (o con il file sul disco), oppure testo completo
const versionView = { data: null, current: null };

function openVersion(id) {
    apiGet('get_version', { id }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        const tab = tabFor(res.path);
        const fromTab = tab && tab.session ? tab.session.getValue() : null;
        const got = fromTab !== null ? Promise.resolve(fromTab)
            : apiGet('get_file', { file: res.path }).then(f => (f.ok && !f.binary && !f.too_big ? f.content : null)).catch(() => null);
        got.then(current => {
            versionView.data = { id, ...res };
            versionView.current = current;
            versionView.dirty = !!(tab && isDirty(tab));
            $('#versionTitle').textContent = res.path + ' — ' + res.created_at;
            renderVersion();
            showModal($('#versionDialog'));
        });
    });
}

/** Righe della differenza con le parti uguali lunghe ridotte a "… n righe uguali" (restano ctx righe di contesto). */
function collapseDiffRows(rows, ctx = 3) {
    const out = [];
    for (let i = 0; i < rows.length; ) {
        if (rows[i].t !== ' ') { out.push(rows[i++]); continue; }
        let j = i;
        while (j < rows.length && rows[j].t === ' ') j++;
        const run = j - i, head = i === 0 ? 0 : ctx, tail = j === rows.length ? 0 : ctx;
        if (run > head + tail + 2) {
            out.push(...rows.slice(i, i + head));
            out.push({ fold: run - head - tail });
            out.push(...rows.slice(j - tail, j));
        } else {
            out.push(...rows.slice(i, j));
        }
        i = j;
    }
    return out;
}

function renderVersion() {
    const v = versionView.data;
    if (!v) return;
    const mode = versionView.current === null ? 'full' : loadGlobal('vermode', 'diff');
    $$('#versionMode button').forEach(b => { b.classList.toggle('on', b.dataset.vmode === mode); b.disabled = versionView.current === null && b.dataset.vmode === 'diff'; });
    $('#versionContent').hidden = mode !== 'full';
    $('#versionDiff').hidden = mode === 'full';
    $('#versionContent').textContent = v.content;
    if (mode === 'full') { $('#versionLegend').textContent = ''; return; }
    const rows = diffLines(v.content.split('\n'), versionView.current.split('\n'))
        .map(r => ({ t: r.t, s: r.s, ln: [r.a != null ? r.a + 1 : null, r.b != null ? r.b + 1 : null] }));
    const add = rows.filter(r => r.t === '+').length, del = rows.filter(r => r.t === '-').length;
    $('#versionLegend').innerHTML = `<span class="dl-del">− ${esc(L('ver.legend_old', { date: v.created_at }))}</span> · `
        + `<span class="dl-add">+ ${esc(L(versionView.dirty ? 'ver.legend_editor' : 'ver.legend_current'))}</span>`
        + ` · <b class="dl-add">+${add}</b> <b class="dl-del">−${del}</b>`;
    if (!add && !del) { $('#versionDiff').innerHTML = `<div class="log-empty">${esc(L('ver.identical'))}</div>`; return; }
    $('#versionDiff').innerHTML = collapseDiffRows(rows).map(r => r.fold
        ? `<div class="dl more">${esc(L('ver.same_lines', { n: r.fold }))}</div>` : diffRowsHtml([r])).join('');
}

function logItemsHtml(logs) {
    return logs.length
        ? logs.map(l => `<div class="log-item log-${esc(l.type)}" data-id="${+l.id}"><div class="log-meta"><span>${esc(l.type.toUpperCase())}</span><span>${esc(l.created_at.substring(5, 16))}</span></div><div class="log-text">${esc(l.text)}</div></div>`).join('')
        : `<div class="log-empty">${esc(L('js.no_log'))}</div>`;
}

// ----------------------------------------------------------------------------
// REGISTRO A TUTTO SCHERMO: fino a 200 voci, filtro per testo e per tipo
// ----------------------------------------------------------------------------
const logView = { all: [], type: '' };

function initLogDialog() {
    $('#logExpand').addEventListener('click', () => {
        showModal($('#logDialog'));
        loadLogDialog();
        setTimeout(() => focusSoft($('#logFilterText')), 50);
    });
    $('#logFilterText').addEventListener('input', renderLogDialog);
    $$('#logFilterType button').forEach(b => b.addEventListener('click', () => {
        logView.type = b.dataset.type;
        $$('#logFilterType button').forEach(x => x.classList.toggle('on', x === b));
        renderLogDialog();
    }));
}

function loadLogDialog() {
    apiGet('get_side', { limit: 200 }).then(res => {
        if (!res.ok) return;
        logView.all = res.logs;
        renderLogDialog();
    });
}

function renderLogDialog() {
    const q = $('#logFilterText').value.trim().toLowerCase();
    const list = logView.all.filter(l => (!logView.type || l.type === logView.type)
        && (!q || l.text.toLowerCase().includes(q) || l.created_at.includes(q)));
    $('#logDialogBody').innerHTML = `<div class="log-list big">${logItemsHtml(list)}</div>`;
    $('#logFilterCount').textContent = L('js.n_of_m', { n: list.length, m: logView.all.length });
}

// ----------------------------------------------------------------------------
// ORDINE DELLE CARD DELLA COLONNA DESTRA (frecce su/giù, ricordato nel browser)
// ----------------------------------------------------------------------------
// ----------------------------------------------------------------------------
// CARD DELLA COLONNA DESTRA: chiudi/apri (con "+N" per le novità arrivate da chiuse)
// e maniglia in basso per cambiarne l'altezza. Stato ricordato nel browser.
// ----------------------------------------------------------------------------

/** Numero che cresce quando nella card arriva qualcosa di nuovo (null = card senza novità da contare). */
const CARD_COUNTERS = {
    claudeCard: () => $$('#claudeChat .bubble:not(.pending)').length,
    httpCard: () => state.httpList.length,
    versionsCard: () => parseInt($('#versionsCount').textContent, 10) || 0,
    logCard: () => Math.max(0, ...$$('#logList .log-item').map(el => +el.dataset.id || 0)),
    trashCard: () => parseInt($('#trashCount').textContent, 10) || 0,
    errorsCard: () => errView.total,
};

const isCardCollapsed = (card) => card.tagName === 'DETAILS' ? !card.open : card.classList.contains('collapsed');

function initRightCards() {
    const collapsed = new Set(loadGlobal('rightcollapsed', []));
    const heights = loadGlobal('rightheights', {});

    $$('#rightCol > .card').forEach(card => {
        const head = card.querySelector(':scope > .card-head, :scope > summary');
        if (!head) return;
        const h3 = head.querySelector('h3');

        // Tutto ciò che non è l'intestazione va in un contenitore che si può nascondere e ridimensionare
        const body = document.createElement('div');
        body.className = 'card-body';
        Array.from(card.childNodes).filter(n => n !== head).forEach(n => body.appendChild(n));
        card.appendChild(body);

        // Contatore delle novità
        const badge = document.createElement('span');
        badge.className = 'new-badge';
        badge.hidden = true;
        if (h3) h3.appendChild(badge);
        const counter = CARD_COUNTERS[card.id];
        let base = null;
        const update = () => {
            if (!counter) return;
            if (!isCardCollapsed(card)) { badge.hidden = true; base = null; return; }
            if (base === null) base = counter();
            const n = counter() - base;
            const was = badge.textContent;
            badge.hidden = n <= 0;
            badge.textContent = '+' + n;
            if (n > 0 && was !== badge.textContent) anim.pop(badge);
        };
        new MutationObserver(() => { update(); clampCardHeight(card); }).observe(body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden'] });
        if (card.id === 'httpCard') state.onHttpList = (state.onHttpList || []).concat(update);

        // Apri/chiudi
        if (card.tagName === 'DETAILS') {
            const opened = loadGlobal('rightopen', null);
            if (opened) card.open = opened.includes(card.id);
            card.addEventListener('toggle', () => { base = null; update(); saveCollapsed(); });
        } else {
            card.classList.add('collapsible');
            const chev = document.createElement('span');
            chev.className = 'card-chev';
            chev.innerHTML = ic('chevron');
            head.prepend(chev);
            const toggle = (e) => {
                if (e.target.closest('button, a, input, select')) return;
                card.classList.toggle('collapsed');
                base = null;
                update();
                saveCollapsed();
                if (!isCardCollapsed(card)) anim.expand(body);
            };
            head.addEventListener('click', toggle);
            if (collapsed.has(card.id)) card.classList.add('collapsed');
        }
        update();

        // Maniglia per l'altezza
        const grip = document.createElement('div');
        grip.className = 'card-resize';
        grip.title = L('js.card_resize');
        card.appendChild(grip);
        if (heights[card.id]) requestAnimationFrame(() => setCardHeight(card, heights[card.id]));
        grip.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            grip.setPointerCapture(e.pointerId);
            const y0 = e.clientY, h0 = body.getBoundingClientRect().height;
            document.body.classList.add('resizing-v');
            const move = (ev) => setCardHeight(card, Math.max(cardMinHeight(card), Math.round(h0 + ev.clientY - y0)));
            const up = () => {
                grip.removeEventListener('pointermove', move);
                grip.removeEventListener('pointerup', up);
                document.body.classList.remove('resizing-v');
                const all = loadGlobal('rightheights', {});
                all[card.id] = parseInt(body.style.height, 10);
                storeGlobal('rightheights', all);
            };
            grip.addEventListener('pointermove', move);
            grip.addEventListener('pointerup', up);
        });
        grip.addEventListener('dblclick', () => {
            card.classList.remove('sized');
            delete card.dataset.wantHeight;
            body.style.height = '';
            const all = loadGlobal('rightheights', {});
            delete all[card.id];
            storeGlobal('rightheights', all);
        });
    });
}

/**
 * Altezza scelta per la card, mai sotto il minimo: si stringe solo la parte che scorre (.card-scroll:
 * chat, elenchi), mentre campi e pulsanti restano sempre visibili.
 */
function setCardHeight(card, px) {
    const body = card.querySelector(':scope > .card-body');
    card.classList.add('sized');
    card.dataset.wantHeight = px;
    body.style.height = Math.max(px, cardMinHeight(card)) + 'px';
}

const CARD_SCROLL_MIN = 48;   // altezza minima della parte che scorre

/** Altezza minima del corpo della card: tutto ciò che non scorre + CARD_SCROLL_MIN. */
function cardMinHeight(card) {
    const body = card.querySelector(':scope > .card-body');
    const region = body && body.querySelector('.card-scroll');
    if (!body || !region || isCardCollapsed(card)) return 0;
    const fixed = (box) => {
        const cs = getComputedStyle(box);
        let h = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom), shown = 0;
        for (const el of box.children) {
            if (getComputedStyle(el).display === 'none' || el.classList.contains('card-resize')) continue;
            const ecs = getComputedStyle(el);
            const margins = parseFloat(ecs.marginTop) + parseFloat(ecs.marginBottom);
            if (el === region) h += CARD_SCROLL_MIN + margins;
            else if (el.contains(region)) h += fixed(el) + margins;
            else h += el.getBoundingClientRect().height + margins;
            shown++;
        }
        const gap = parseFloat(cs.rowGap) || 0;
        if (shown > 1 && /flex|grid/.test(cs.display)) h += gap * (shown - 1);
        return h;
    };
    return Math.ceil(fixed(body));
}

/** Riapplica il minimo (il contenuto fisso può cambiare: avvisi, campi che compaiono, larghezza della colonna). */
function clampCardHeight(card) {
    if (!card.classList.contains('sized') || !card.dataset.wantHeight) return;
    const body = card.querySelector(':scope > .card-body');
    const h = Math.max(+card.dataset.wantHeight, cardMinHeight(card)) + 'px';
    if (body.style.height !== h) body.style.height = h;
}

window.addEventListener('resize', () => $$('#rightCol > .card.sized').forEach(clampCardHeight));

function saveCollapsed() {
    storeGlobal('rightcollapsed', $$('#rightCol > div.card.collapsed').map(c => c.id));
    storeGlobal('rightopen', $$('#rightCol > details.card').filter(c => c.open).map(c => c.id));
}

function initRightOrder() {
    const col = $('#rightCol');
    const cards = () => $$(':scope > .card', col);
    // Ripristina l'ordine salvato
    const saved = loadGlobal('rightorder', []);
    saved.forEach(id => { const c = document.getElementById(id); if (c && c.parentElement === col) col.appendChild(c); });
    cards().forEach(c => { if (!saved.includes(c.id)) col.appendChild(c); });

    cards().forEach(card => {
        const head = card.querySelector(':scope > .card-head, :scope > summary');
        if (!head) return;
        const box = document.createElement('span');
        box.className = 'move-btns';
        box.innerHTML = `<button type="button" class="icon-btn" data-move="-1" title="${esc(L('js.move_up'))}">${ic('up')}</button>`
            + `<button type="button" class="icon-btn" data-move="1" title="${esc(L('js.move_down'))}">${ic('down')}</button>`;
        if (head.tagName === 'SUMMARY') head.appendChild(box);
        else {
            const actions = head.querySelector('.icon-actions');
            const firstBtn = head.querySelector(':scope > button');
            if (actions) actions.prepend(box);
            else if (firstBtn) head.insertBefore(box, firstBtn);
            else head.appendChild(box);
        }
        box.addEventListener('click', (e) => {
            const b = e.target.closest('[data-move]');
            e.preventDefault();          // nelle card a scomparsa il clic non deve aprire/chiudere
            e.stopPropagation();
            if (b) moveCard(card, parseInt(b.dataset.move, 10));
        });
    });
    updateMoveButtons();
}

function moveCard(card, dir) {
    const col = $('#rightCol');
    const visible = $$(':scope > .card', col).filter(c => !c.hidden);
    const i = visible.indexOf(card), j = i + dir;
    if (i < 0 || j < 0 || j >= visible.length) return;
    const other = visible[j];
    if (dir < 0) col.insertBefore(card, other); else col.insertBefore(card, other.nextSibling);
    storeGlobal('rightorder', $$(':scope > .card', col).map(c => c.id));
    updateMoveButtons();
    anim.landed([card]);
    card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function updateMoveButtons() {
    const visible = $$('#rightCol > .card').filter(c => !c.hidden);
    visible.forEach((c, i) => {
        const up = c.querySelector('[data-move="-1"]'), down = c.querySelector('[data-move="1"]');
        if (up) up.disabled = i === 0;
        if (down) down.disabled = i === visible.length - 1;
    });
}

function refreshSide() {
    return apiGet('get_side').then(res => {
        if (!res.ok) return;
        $('#logList').innerHTML = logItemsHtml(res.logs);
        if ($('#logDialog').open) loadLogDialog();
        $('#trashCount').textContent = res.trash.length;
        $('#emptyTrashBtn').hidden = !res.trash.length;
        $('#clearLogsBtn').hidden = !res.logs.length;
        $('#trashList').innerHTML = res.trash.length
            ? res.trash.map(t => `<div class="bak-item"><span class="trash-name" title="${esc(t.path)} — ${esc(t.created_at)}">${esc(t.path)}</span>`
                + `<button type="button" class="mini-btn add" data-trash-restore="${t.id}" title="${esc(L('js.restore'))}">${ic('undo')}</button></div>`).join('')
            : `<div class="log-empty">${esc(L('js.empty'))}</div>`;
    });
}

function initSide() {
    $('#versionsList').addEventListener('click', (e) => {
        const view = e.target.closest('[data-view]');
        const rest = e.target.closest('[data-restore]');
        if (view) { e.preventDefault(); openVersion(view.dataset.view); }
        if (rest) restoreVersion(rest.dataset.restore, false);
    });
    $$('#versionMode button').forEach(b => b.addEventListener('click', () => {
        storeGlobal('vermode', b.dataset.vmode);
        renderVersion();
    }));
    $('#versionRestore').addEventListener('click', () => {
        if (!versionView.data) return;
        $('#versionDialog').close();
        restoreVersion(versionView.data.id, false);
    });
    $('#trashList').addEventListener('click', (e) => {
        const b = e.target.closest('[data-trash-restore]');
        if (b) restoreVersion(b.dataset.trashRestore, true);
    });
    $('#clearLogsBtn').addEventListener('click', () => {
        if (confirm(L('js.clear_log_q'))) apiPost({ action: 'clear_logs' }).then(refreshSide);
    });
    $('#emptyTrashBtn').addEventListener('click', () => {
        if (confirm(L('js.empty_trash_q'))) apiPost({ action: 'empty_trash' }).then(refreshSide);
    });
}

// ----------------------------------------------------------------------------
// FINESTRE MODALI, IMPOSTAZIONI, UPLOAD
// ----------------------------------------------------------------------------
function initDialogs() {
    $$('dialog').forEach(d => {
        $$('[data-close]', d).forEach(b => b.addEventListener('click', () => d.close()));
        d.addEventListener('click', (e) => { if (e.target === d && !isLocked(d)) d.close(); }); // clic sullo sfondo
    });
    // Esc chiude sempre la finestra in primo piano (anche se il focus è in un campo)
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const open = $$('dialog[open]').pop();
        if (open) { e.preventDefault(); if (!isLocked(open)) open.close(); }
    }, true);
}

// Niente GSAP sul <dialog>: gli stili inline sull'elemento gli fanno perdere lo stato modale.
// L'ingresso è animato in CSS (dialog.modal[open]).
function showModal(d) {
    d.removeAttribute('style');
    d.showModal();
}

function limitsHtml() {
    const l = APP.limits;
    return `<div><span>upload_max_filesize</span><b>${esc(l.upload_max_filesize)}</b></div>`
        + `<div><span>post_max_size</span><b>${esc(l.post_max_size)}</b></div>`
        + `<div><span>max_execution_time</span><b>${l.max_execution_time === '0' ? esc(L('js.unlimited')) : esc(l.max_execution_time) + ' s'}</b></div>`
        + `<div><span>memory_limit</span><b>${esc(l.memory_limit)}</b></div>`
        + `<div><span>max_file_uploads</span><b>${esc(l.max_file_uploads)}</b></div>`;
}

function initSettings() {
    const form = $('#settingsForm');
    form.claude_model.innerHTML = Object.entries(APP.models).map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('');

    $('#settingsBtn').addEventListener('click', () => {
        const c = APP.config;
        ['dev_name', 'environment', 'default_root', 'backup_skip', 'claude_model', 'claude_effort', 'editor_font', 'upload_max_gb'].forEach(k => { form[k].value = c[k] ?? ''; });
        form.editor_wrap.checked = !!c.editor_wrap;
        form.theme.value = c.theme || 'dark';
        form.minimap.checked = !!c.minimap;
        form.format_on_save.checked = !!c.format_on_save;
        form.emmet.checked = !!c.emmet;
        form.show_right.checked = !!c.show_right;
        form.language.value = APP.lang;
        $('#langFlag').innerHTML = APP.flags[APP.lang] || '';
        form.php_lint.checked = !!c.php_lint;
        form.php_lint_live.checked = !!c.php_lint_live;
        form.claude_apply_save.checked = !!c.claude_apply_save;
        form.error_log_path.value = c.error_log_path || '';
        $('#lintInfo').textContent = APP.php.cli
            ? L('js.lint_cli', { v: APP.php.cli })
            : L('js.lint_tokenizer', { v: APP.php.server });
        form.api_key.value = '';
        form.remove_api_key.checked = false;
        $('#apiKeyState').textContent = c.has_api_key ? L('js.key_saved', { hint: c.api_key_hint }) : L('js.no_key');
        $('#settingsMsg').textContent = '';
        $('#limitsBox').innerHTML = `<div class="limits-title">${esc(L('js.php_limits'))}</div>` + limitsHtml()
            + `<div><span>${esc(L('js.apache_user'))}</span><b>${esc(APP.daemonUser)}</b></div>`
            + `<div><span>${esc(L('js.root_writable'))}</span><b>${esc(L($('#permBanner').hidden ? 'js.yes' : 'js.no'))}</b></div>`
            + `<div class="limits-title">${esc(L('set.big_uploads'))}</div>`
            + `<div><span>${esc(L('set.chunk_above'))}</span><b>${humanSize(APP.limits.chunk_above)}</b></div>`
            + `<div><span>${esc(L('set.chunk_size'))}</span><b>${humanSize(APP.limits.chunk_bytes)}</b></div>`
            + `<div><span>${esc(L('set.disk_free'))}</span><b>${APP.limits.disk_free == null ? '—' : humanSize(APP.limits.disk_free)}</b></div>`
            + `<div><span>${esc(L('set.upload_max_now'))}</span><b>${humanSize(APP.limits.upload_max)}</b></div>`;
        showModal($('#settingsDialog'));
    });
    $('#resetLayoutBtn').addEventListener('click', () => { resetColumns(); toast(L('js.columns_reset')); });
    // Resetta tutto: impostazioni e database sul server, poi lo stato salvato nel browser (colonne, card, schede…)
    $('#resetAllBtn').addEventListener('click', () => {
        if (!confirm(L('js.reset_q'))) return;
        apiPost({ action: 'reset_all' }).then(res => {
            if (!res.ok) { $('#settingsMsg').textContent = res.error; toast(res.error, 'error'); return; }
            try {
                Object.keys(localStorage).filter(k => /^(sc_|treeforge_|scaffolder_)/.test(k)).forEach(k => localStorage.removeItem(k));
            } catch (e) { /* storage non disponibile */ }
            state.tabs.forEach(t => { if (t.session) t.saved = t.session.getValue(); });   // niente avviso "modifiche non salvate"
            location.reload();
        }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
    });
    // La bandiera accanto alla lingua segue la scelta (la lingua si applica salvando)
    form.language.addEventListener('change', () => { $('#langFlag').innerHTML = APP.flags[form.language.value] || ''; });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = {
            dev_name: form.dev_name.value, environment: form.environment.value, default_root: form.default_root.value,
            backup_skip: form.backup_skip.value, claude_model: form.claude_model.value, claude_effort: form.claude_effort.value,
            editor_font: form.editor_font.value, editor_wrap: form.editor_wrap.checked, show_right: form.show_right.checked,
            theme: form.theme.value, minimap: form.minimap.checked, format_on_save: form.format_on_save.checked, emmet: form.emmet.checked,
            language: form.language.value,
            api_key: form.api_key.value, remove_api_key: form.remove_api_key.checked,
            php_lint: form.php_lint.checked, php_lint_live: form.php_lint_live.checked,
            claude_apply_save: form.claude_apply_save.checked, error_log_path: form.error_log_path.value,
            upload_max_gb: form.upload_max_gb.value,
        };
        apiPost({ action: 'save_settings', settings: data }).then(res => {
            if (!res.ok) { $('#settingsMsg').textContent = res.error; return; }
            if (res.config.language !== APP.lang) { location.reload(); return; }   // nuova lingua: si ricarica l'interfaccia
            const fontChanged = String(res.config.editor_font) !== String(APP.config.editor_font);
            APP.config = res.config;
            if (res.limits) APP.limits = res.limits;
            if (fontChanged) applyEditorZoom(parseInt(res.config.editor_font, 10));
            state.tabs.forEach(t => t.session && t.session.setUseWrapMode(!!res.config.editor_wrap));
            applyRightVisibility();
            minimap.colors = null;
            applyTheme();
            if (state.active) updateTopbar();
            if (editor) editor.resize();
            $('#claudeModel').value = res.config.claude_model;
            $('#claudeEffort').value = res.config.claude_effort;
            renderClaudeKey();
            if (!res.config.php_lint_live) state.tabs.forEach(t => { if (t.session && !t.saveBlocked) setLintMarks(t, null); });
            loadErrors();
            $('#settingsDialog').close();
            toast(L('js.settings_saved'));
        });
    });
}

// Velo sotto i menu a comparsa: visibile finché uno dei menu è aperto. Ogni menu si chiude già con un clic fuori
// (il clic sul velo arriva al document); il tasto destro sul velo chiude senza aprire il menu del browser.
function initMenuOverlay() {
    const overlay = $('#menuOverlay');
    const menus = $$('.ctx-menu');
    const sync = () => { overlay.hidden = !menus.some(m => !m.hidden); };
    const mo = new MutationObserver(sync);
    menus.forEach(m => mo.observe(m, { attributes: true, attributeFilter: ['hidden'] }));
    overlay.addEventListener('contextmenu', (e) => { e.preventDefault(); overlay.click(); });
    overlay.addEventListener('wheel', (e) => e.preventDefault(), { passive: false });
}

// Esci: il login di questo browser viene cancellato; la pagina torna al modulo di accesso
function initLogout() {
    const btn = $('#logoutBtn');
    if (!btn) return;
    btn.addEventListener('click', () => {
        if (anyDirty() && !confirm(L('auth.logout_dirty'))) return;
        if (transfer.running) { toast(L('tr.busy'), 'warn'); return; }
        apiPost({ action: 'logout' }).then(() => {
            state.tabs.forEach(t => { if (t.session) t.saved = t.session.getValue(); });   // niente avviso "modifiche non salvate"
            location.reload();
        }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
    });
}

// Primo avvio senza password: utente e password creati dall'avviso rosso (scritti in .htpasswd dal server)
function initSetupAuth() {
    const btn = $('#setupAuthBtn');
    if (!btn) return;
    const form = $('#setupAuthForm');
    btn.addEventListener('click', () => {
        form.pass.value = form.pass2.value = '';
        $('#setupAuthMsg').textContent = '';
        showModal($('#setupAuthDialog'));
        focusSoft(form.pass);
    });
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const msg = $('#setupAuthMsg');
        if (anyDirty()) { msg.textContent = L('auth.save_first'); return; }   // dopo si ricarica la pagina
        if (form.pass.value.length < 8) { msg.textContent = L('auth.pass_short', { n: 8 }); return; }
        if (form.pass.value !== form.pass2.value) { msg.textContent = L('auth.pass_mismatch'); return; }
        apiPost({ action: 'setup_auth', user: form.user.value.trim(), pass: form.pass.value }).then(res => {
            if (!res.ok) { msg.textContent = res.error; return; }
            $('#setupAuthDialog').close();
            toast(L('auth.enabled_toast'));
            setTimeout(() => location.reload(), 1200);
        }).catch(err => { msg.textContent = L('js.network_error') + ': ' + err; });
    });
}

let uploadState = { dir: '', files: [], dirs: [] };

function initUpload() {
    const input = $('#uploadInput'), folder = $('#uploadFolderInput'), area = $('#dropArea');
    input.addEventListener('change', () => setUploadFiles(Array.from(input.files).map(f => ({ file: f, rel: f.name })), []));
    // Selezione di una cartella intera: il browser fornisce il percorso relativo di ogni file
    folder.addEventListener('change', () => setUploadFiles(Array.from(folder.files).filter(f => f.name !== '.DS_Store').map(f => ({ file: f, rel: f.webkitRelativePath || f.name })), []));
    $('#pickFolder').addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); folder.click(); });
    area.addEventListener('dragover', (e) => { e.preventDefault(); area.classList.add('over'); });
    area.addEventListener('dragleave', () => area.classList.remove('over'));
    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('over');
        collectDropped(e.dataTransfer).then(r => setUploadFiles(r.files, r.dirs));
    });
    $('#uploadStart').addEventListener('click', () => {
        $('#uploadDialog').close();
        startTransfer(uploadState.dir, uploadState.files, uploadState.dirs);
    });
    initTransfer();
}

function openUpload(dir) {
    uploadState = { dir, files: [], dirs: [] };
    $('#uploadTarget').textContent = '/' + dir;
    $('#uploadLimits').innerHTML = limitsHtml();
    $('#uploadInput').value = '';
    $('#uploadFolderInput').value = '';
    setUploadFiles([], []);
    showModal($('#uploadDialog'));
}

function setUploadFiles(files, dirs) {
    uploadState.files = files;
    uploadState.dirs = dirs;
    const total = files.reduce((s, x) => s + x.file.size, 0);
    $('#uploadChosen').textContent = files.length || dirs.length
        ? L('js.n_files', { n: files.length }) + (dirs.length ? ', ' + L('js.n_empty_dirs', { n: dirs.length }) : '') + ` · ${humanSize(total)}` : '';
    $('#uploadStart').disabled = !files.length && !dirs.length;
}

/**
 * Legge ciò che è stato trascinato: file singoli, ZIP e cartelle intere (con le sottocartelle).
 * Le "entry" vanno prese subito, prima di qualunque await: dopo l'evento drop il browser le invalida.
 */
async function collectDropped(dt) {
    const roots = Array.from(dt.items || []).filter(i => i.kind === 'file').map(i => i.webkitGetAsEntry ? i.webkitGetAsEntry() : null);
    const plain = Array.from(dt.files || []);
    if (!roots.some(Boolean)) return { files: plain.map(f => ({ file: f, rel: f.name })), dirs: [] };

    const files = [], dirs = [];
    const readAll = (reader) => new Promise((resolve, reject) => {
        const out = [];
        const next = () => reader.readEntries(batch => { if (!batch.length) resolve(out); else { out.push(...batch); next(); } }, reject);
        next();
    });
    const walk = async (entry, prefix) => {
        if (entry.isFile) {
            if (entry.name === '.DS_Store') return;
            const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
            files.push({ file, rel: prefix + entry.name });
        } else if (entry.isDirectory) {
            const kids = await readAll(entry.createReader());
            const path = prefix + entry.name;
            if (!kids.some(k => k.name !== '.DS_Store')) dirs.push(path);
            for (const k of kids) await walk(k, path + '/');
        }
    };
    for (const r of roots) if (r) await walk(r, '');
    return { files, dirs };
}

// ----------------------------------------------------------------------------
// TRASFERIMENTO: modale con avanzamento, esito di ogni file, errori da confermare
// ----------------------------------------------------------------------------
const transfer = { xhr: null, cancelled: false, running: false, needsAck: false, paused: false, waiters: [], current: null };

function initTransfer() {
    const d = $('#transferDialog');
    // Durante il caricamento, o finché gli errori non sono stati letti, Esc e clic sullo sfondo non chiudono
    d.addEventListener('cancel', (e) => { if (transfer.running || transfer.needsAck) e.preventDefault(); });
    $('#trCancel').addEventListener('click', () => {
        if (transfer.running) {
            if (!confirm(L('js.stop_upload_q'))) return;
            transfer.cancelled = true;
            if (transfer.xhr) transfer.xhr.abort();
            wakeTransfer();
        } else if (!transfer.needsAck) {
            d.close();
        }
    });
    $('#trAck').addEventListener('click', () => { transfer.needsAck = false; d.close(); });
    $('#trPauseAll').addEventListener('click', () => {
        transfer.paused = !transfer.paused;
        $('#trPauseAll').textContent = L(transfer.paused ? 'tr.resume_all' : 'tr.pause_all');
        if (transfer.paused && transfer.current && transfer.xhr) { transfer.current.pausing = true; transfer.xhr.abort(); }
        if (!transfer.paused) wakeTransfer();
    });
    // Pausa / ripresa di un singolo file caricato a pezzi
    $('#trList').addEventListener('click', (e) => {
        const b = e.target.closest('.tr-act');
        if (!b || !b._item) return;
        const it = b._item;
        it.paused = !it.paused;
        updateItemButton(it);
        if (it.paused && transfer.current === it && transfer.xhr) { it.pausing = true; transfer.xhr.abort(); }
        if (!it.paused) wakeTransfer();
    });
}

function wakeTransfer() {
    const w = transfer.waiters;
    transfer.waiters = [];
    w.forEach(r => r());
}

/** Aspetta finché il file (o tutto il trasferimento) è in pausa. */
async function waitIfPaused(item) {
    while ((transfer.paused || item.paused) && !transfer.cancelled) {
        if (item.status !== 'pause') setRow(item, 'pause', L('tr.paused_at', { pct: item.pct || 0 }));
        await new Promise(r => transfer.waiters.push(r));
    }
}

function isLocked(d) {
    return d.id === 'transferDialog' && (transfer.running || transfer.needsAck);
}

const TR_LABEL = { wait: 'tr.s_wait', run: 'tr.s_run', ok: 'tr.s_ok', skip: 'tr.s_skip', warning: 'tr.s_warning', error: 'tr.s_error', cancel: 'tr.s_cancel', pause: 'tr.s_pause', unzip: 'tr.s_unzip', retry: 'tr.s_retry' };

function setRow(item, status, msg, details) {
    item.status = status;
    item.row.className = 'tr-row ' + status + (item.chunked ? ' chunked' : '');
    $('.tr-state', item.row).textContent = L(TR_LABEL[status]);
    $('.tr-msg', item.row).textContent = msg || '';
    if (details && details.length) {
        $('.tr-details', item.row).innerHTML = details.map(x => `<div>${esc(x)}</div>`).join('');
    }
    updateItemButton(item);
}

function setRowBar(item, pct) {
    item.pct = Math.max(0, Math.min(100, Math.floor(pct)));
    $('.tr-bar i', item.row).style.width = item.pct + '%';
}

function updateItemButton(item) {
    const b = $('.tr-act', item.row);
    if (!b) return;
    const can = item.chunked && ['wait', 'run', 'pause', 'retry'].includes(item.status);
    b.hidden = !can;
    b.innerHTML = ic(item.paused ? 'play' : 'pause');
    b.title = L(item.paused ? 'tr.resume' : 'tr.pause');
}

/** Nuova riga nella finestra di trasferimento. */
function addTransferRow(item) {
    item.row = document.createElement('div');
    item.row.innerHTML = `<span class="tr-name" title="${esc(item.rel)}">${item.dir ? ic('folder') : fileIcon(item.rel)}<span>${esc(item.rel)}</span></span>`
        + `<span class="tr-size">${item.dir ? '' : humanSize(item.size)}</span><span class="tr-state"></span>`
        + `<button type="button" class="icon-btn tr-act" hidden></button>`
        + `<span class="tr-bar"><i></i></span><span class="tr-msg"></span><div class="tr-details"></div>`;
    $('.tr-act', item.row)._item = item;
    $('#trList').appendChild(item.row);
    setRow(item, 'wait');
}

/** Prepara e apre la finestra di trasferimento. */
function openTransferDialog(title, opts) {
    Object.assign(transfer, { xhr: null, cancelled: false, running: true, needsAck: false, paused: false, waiters: [], current: null });
    $('#trTitle').textContent = title;
    $('#trOpts').textContent = opts || '';
    $('#trAlert').hidden = true;
    $('#trConflict').hidden = true;
    $('#trAck').hidden = true;
    $('#trCancel').hidden = false;
    $('#trCancel').textContent = L('tr.stop');
    $('#trPauseAll').hidden = true;
    $('#trPauseAll').textContent = L('tr.pause_all');
    $('#trList').innerHTML = '';
    $('#trProgress').value = 0;
    $('#trCount').textContent = '';
    showModal($('#transferDialog'));
}

/** Riquadro nella finestra di trasferimento: "questi file esistono già, li sovrascrivo?" → 'overwrite' | 'skip' */
function askOverwrite(paths) {
    const box = $('#trConflict');
    $('#trConflictText').innerHTML = L(paths.length === 1 ? 'tr.conflict_one' : 'tr.conflict_many', { n: paths.length });
    $('#trConflictList').innerHTML = paths.slice(0, 50).map(p => `<div>${esc(p)}</div>`).join('') + (paths.length > 50 ? `<div>${esc(L('tr.and_more', { n: paths.length - 50 }))}</div>` : '');
    box.hidden = false;
    $('#trConflictYes').focus();
    return new Promise(resolve => {
        const done = (v) => { box.hidden = true; $('#trConflictYes').onclick = $('#trConflictNo').onclick = null; resolve(v); };
        $('#trConflictYes').onclick = () => done('overwrite');
        $('#trConflictNo').onclick = () => done('skip');
    });
}

/** POST con XMLHttpRequest (serve l'avanzamento dell'invio). Risolve sempre: {ok:false, net:true} per gli errori di rete. */
function xhrPost(fd, onProgress) {
    return new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        transfer.xhr = xhr;
        xhr.open('POST', 'api.php');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', APP.csrf);
        if (onProgress) xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded, e.total); };
        xhr.onload = () => {
            transfer.xhr = null;
            if (xhr.status === 401) { location.reload(); return; }
            try { resolve(JSON.parse(xhr.responseText)); }
            catch (e) { resolve({ ok: false, net: xhr.status >= 500 || xhr.status === 0, error: L('tr.bad_response', { status: xhr.status }) }); }
        };
        xhr.onerror = () => { transfer.xhr = null; resolve({ ok: false, net: true, error: L('js.network_error') }); };
        xhr.onabort = () => { transfer.xhr = null; resolve({ ok: false, error: L('tr.s_cancel'), aborted: true }); };
        fd.append('csrf', APP.csrf);
        xhr.send(fd);
    });
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const fmtDuration = (sec) => sec < 60 ? L('tr.secs', { n: Math.max(1, Math.round(sec)) })
    : sec < 3600 ? L('tr.mins', { n: Math.round(sec / 60) }) : L('tr.hours', { h: Math.floor(sec / 3600), m: Math.round(sec % 3600 / 60) });

/**
 * Carica un file grande a pezzi: upload_start (o ripresa) → upload_chunk… → upload_finish.
 * Pausa/ripresa, fino a 5 nuovi tentativi per pezzo (attesa 1, 2, 4, 8, 16 s), riallineamento con il server.
 * onBytes(n) riceve i byte inviati in più per l'avanzamento totale.
 */
async function uploadChunked(item, dir, opts, onBytes) {
    item.chunked = true;
    setRow(item, 'run', L('tr.starting'));
    let start = await apiPost({ action: 'upload_start', target_dir: dir, rel: item.rel, size: item.size, mtime: item.file.lastModified || 0,
        overwrite: opts.overwrite ? '1' : '0', extract: opts.extract ? '1' : '0' }).catch(err => ({ ok: false, error: L('js.network_error') + ': ' + err }));
    if (!start.ok) { setRow(item, start.exists ? 'skip' : 'error', start.error); return; }
    item.id = start.id;
    let offset = start.received;
    const counted = { n: 0 };   // byte già contati nell'avanzamento totale
    const count = (n) => { const d = n - counted.n; counted.n = n; onBytes(d); };
    count(offset);
    if (start.resumed && offset > 0) setRow(item, 'run', L('tr.resuming', { done: humanSize(offset) }));
    const chunk = Math.max(65536, APP.limits.chunk_bytes || 2097152);
    const samples = [[performance.now(), offset]];
    let tries = 0;
    transfer.current = item;
    try {
        while (offset < item.size) {
            await waitIfPaused(item);
            if (transfer.cancelled) break;
            if (item.status !== 'run') setRow(item, 'run');
            const end = Math.min(item.size, offset + chunk);
            const fd = new FormData();
            fd.append('action', 'upload_chunk');
            fd.append('id', item.id);
            fd.append('offset', String(offset));
            fd.append('chunk', item.file.slice(offset, end), 'chunk');
            const base = offset;
            const res = await xhrPost(fd, (loaded, total) => {
                const sent = base + Math.min(end - base, loaded / total * (end - base));
                count(sent);
                setRowBar(item, sent / item.size * 100);
                // Velocità sugli ultimi ~8 secondi
                const now = performance.now();
                samples.push([now, sent]);
                while (samples.length > 2 && now - samples[0][0] > 8000) samples.shift();
                const dt = (now - samples[0][0]) / 1000, db = sent - samples[0][1];
                const speed = dt > 0.5 ? db / dt : 0;
                $('.tr-msg', item.row).textContent = L('tr.chunk_progress', { pct: item.pct, done: humanSize(sent), total: humanSize(item.size) })
                    + (speed > 0 ? ' · ' + humanSize(speed) + '/s · ' + L('tr.eta', { t: fmtDuration((item.size - sent) / speed) }) : '');
            });
            if (res.ok) { offset = res.received; tries = 0; count(offset); continue; }
            if (res.aborted) {
                count(offset);
                if (transfer.cancelled) break;
                if (item.pausing) { item.pausing = false; continue; }   // pausa: il pezzo si rimanda alla ripresa
                break;
            }
            if (res.resync) { offset = res.received; count(offset); continue; }   // il server è altrove: ci si riallinea
            if (res.gone) { setRow(item, 'error', res.error); return; }
            if (!res.net || ++tries > 5) { setRow(item, 'error', res.error); return; }
            // Errore di rete o del server: si riprova con attesa crescente
            const wait = Math.pow(2, tries - 1);
            for (let k = wait; k > 0 && !transfer.cancelled; k--) { setRow(item, 'retry', L('tr.retry_in', { n: tries, s: k })); await sleep(1000); }
            const st = await apiPost({ action: 'upload_status', id: item.id }).catch(() => null);
            if (st && st.ok) offset = st.received;
            else if (st && st.gone) { setRow(item, 'error', st.error); return; }
            count(offset);
        }
    } finally {
        transfer.current = null;
    }
    if (transfer.cancelled) {
        apiPost({ action: 'upload_cancel', id: item.id }).catch(() => {});
        setRow(item, 'cancel', L('tr.cancelled_cleaned'));
        return;
    }
    setRowBar(item, 100);
    setRow(item, 'run', L('tr.finishing'));
    const fin = await apiPost({ action: 'upload_finish', id: item.id }).catch(err => ({ ok: false, error: L('js.network_error') + ': ' + err }));
    if (!fin.ok) { setRow(item, 'error', fin.error); return; }
    if (fin.job) { await runUnzipJob(item, fin.job, fin.total); return; }
    item.replaced = !!fin.replaced;
    setRow(item, 'ok', fin.msg);
}

/** Scompatta a passi (unzip_step) mostrando l'avanzamento nella riga; alla fine stato ok / con avvisi. */
async function runUnzipJob(item, job, total) {
    item.chunked = false;
    setRowBar(item, 0);
    let res = null, tries = 0;
    while (!transfer.cancelled) {
        setRow(item, 'unzip', res ? L('tr.unzip_progress', { n: res.next, m: total, files: res.files }) : L('tr.unzip_start', { m: total }));
        res = await apiPost({ action: 'unzip_step', job }).catch(err => ({ ok: false, net: true, error: L('js.network_error') + ': ' + err }));
        if (!res.ok) {
            if (res.net && ++tries <= 3) { await sleep(2000 * tries); continue; }
            setRow(item, 'error', res.error);
            return;
        }
        tries = 0;
        setRowBar(item, total ? res.next / total * 100 : 100);
        if (res.done) break;
    }
    if (transfer.cancelled) {
        await apiPost({ action: 'unzip_step', job, cancel: '1' }).catch(() => {});
        setRow(item, 'cancel', L('tr.unzip_stopped', { files: res ? res.files : 0 }));
        return;
    }
    const msg = L('up.extracted', { files: res.files, dirs: res.dirs }) + (res.overwritten ? ', ' + L('up.overwritten_n', { n: res.overwritten }) : '');
    item.unzipPath = res.path;
    setRow(item, res.skipped_n ? (res.files ? 'warning' : 'error') : 'ok', msg + (res.skipped_n ? ' · ' + L('js.n_skipped_log', { n: res.skipped_n }) : ''), res.skipped || []);
}

async function startTransfer(dir, files, dirs = []) {
    if (!files.length && !dirs.length) return;
    if (transfer.running) { toast(L('tr.busy'), 'warn'); return; }
    const d = $('#transferDialog');
    const lim = APP.limits;
    const maxFiles = Math.max(1, Math.min(parseInt(lim.max_file_uploads, 10) || 20, 20));
    const maxBytes = Math.max(1, (lim.post_bytes || Infinity) - 256 * 1024);   // margine per i campi del modulo
    const chunkAbove = lim.chunk_above || 20 * 1048576;
    const extract = $('#uploadExtract').checked, overwrite = $('#uploadOverwrite').checked;

    openTransferDialog(L('tr.uploading_to', { path: '/' + dir }), L(extract ? 'tr.opt_extract' : 'tr.opt_keep_zip')
        + ' · ' + L(overwrite ? 'tr.opt_overwrite' : 'tr.opt_keep') + ' · ' + L('tr.opt_change'));
    const list = $('#trList');

    // Una riga per ogni file (e per le cartelle vuote). I file grandi viaggiano a pezzi
    const items = files.map(x => ({ file: x.file, rel: x.rel, size: x.file.size, chunked: x.file.size > chunkAbove }));
    const all = items.concat(dirs.map(p => ({ rel: p + '/', size: 0, dir: true })));
    all.forEach(addTransferRow);
    items.filter(it => it.size > lim.upload_max).forEach(it => setRow(it, 'error', L('up.too_big_max', { max: humanSize(lim.upload_max) })));
    if (items.some(it => it.chunked)) $('#trPauseAll').hidden = false;

    // File già presenti nella stessa posizione: si sovrascrivono solo con l'ok esplicito
    const destOf = (it) => (dir ? dir + '/' : '') + it.rel;
    const isZipX = (it) => extract && extOf(it.rel) === 'zip';
    let overwritePaths = [];
    if (!overwrite) {
        const conflicts = items.filter(it => it.status === 'wait' && !isZipX(it) && state.files.includes(destOf(it)));
        if (conflicts.length) {
            const choice = await askOverwrite(conflicts.map(destOf));
            if (choice === 'overwrite') overwritePaths = conflicts.map(it => it.rel);
            else conflicts.forEach(it => setRow(it, 'skip', L('tr.kept_by_choice')));
        }
    }

    // Lotti dei file piccoli rispettando max_file_uploads e post_max_size
    const batches = [];
    let cur = [], curBytes = 0;
    items.filter(it => it.status === 'wait' && !it.chunked).forEach(it => {
        if (cur.length && (cur.length >= maxFiles || curBytes + it.size > maxBytes)) { batches.push(cur); cur = []; curBytes = 0; }
        cur.push(it); curBytes += it.size;
    });
    if (cur.length) batches.push(cur);
    const dirItems = all.filter(it => it.dir);
    if (!batches.length && dirItems.length) batches.push([]);
    const bigItems = items.filter(it => it.status === 'wait' && it.chunked);
    bigItems.forEach(it => { it.row.classList.add('chunked'); updateItemButton(it); });

    const totalBytes = items.reduce((s, it) => s + (it.status === 'wait' ? it.size : 0), 0) || 1;
    let doneBytes = 0;
    const updateProgress = (extra = 0) => {
        const pct = Math.min(100, Math.round((doneBytes + extra) / totalBytes * 100));
        $('#trProgress').value = pct;
        const n = all.filter(it => !['wait', 'run', 'pause', 'retry', 'unzip'].includes(it.status)).length;
        $('#trCount').textContent = L('tr.progress', { n, m: all.length, done: humanSize(Math.min(doneBytes + extra, totalBytes)), total: humanSize(totalBytes) });
    };
    updateProgress();

    for (let b = 0; b < batches.length; b++) {
        const batch = batches[b];
        if (transfer.cancelled) break;
        batch.forEach(it => setRow(it, 'run'));
        const batchBytes = batch.reduce((s, it) => s + it.size, 0);
        const fd = new FormData();
        batch.forEach(it => fd.append('files[]', it.file, it.file.name));
        fd.append('paths', JSON.stringify(batch.map(it => it.rel)));
        fd.append('overwrite_paths', JSON.stringify(batch.map(it => it.rel).filter(r => overwritePaths.includes(r))));
        if (b === 0) fd.append('dirs', JSON.stringify(dirs));
        fd.append('action', 'upload');
        fd.append('target_dir', dir);
        fd.append('extract', extract ? '1' : '0');
        fd.append('overwrite', overwrite ? '1' : '0');

        const res = await xhrPost(fd, (loaded, total) => {
            updateProgress(Math.min(batchBytes, loaded / total * batchBytes));
            if (loaded >= total) $('#trCount').textContent += ' · ' + L('tr.processing');
        });
        doneBytes += batchBytes;
        if (!res.ok) {
            batch.forEach(it => setRow(it, res.aborted ? 'cancel' : 'error', res.error));
            if (b === 0) dirItems.forEach(it => setRow(it, res.aborted ? 'cancel' : 'error', res.error));
        } else {
            const byName = new Map((res.results || []).map(r => [r.name, r]));
            batch.concat(b === 0 ? dirItems : []).forEach(it => {
                const r = byName.get(it.rel) || byName.get(it.rel.replace(/\/$/, ''));
                if (r) { setRow(it, r.status, r.msg, r.details); it.replaced = !!r.replaced; }
                else setRow(it, 'ok', it.dir ? L('tr.folder_created') : L('up.uploaded'));
            });
        }
        updateProgress();
    }

    // File grandi, uno alla volta
    for (const it of bigItems) {
        if (transfer.cancelled) break;
        const opts = { extract: isZipX(it), overwrite: overwrite || overwritePaths.includes(it.rel) };
        await uploadChunked(it, dir, opts, (delta) => { doneBytes += delta; updateProgress(); });
        updateProgress();
    }
    all.filter(it => ['wait', 'run', 'pause', 'retry'].includes(it.status)).forEach(it => setRow(it, 'cancel', L('tr.not_sent')));
    finishTransfer(dir, all, () => {
        if (dir) state.openFolders.add(dir);
        files.forEach(x => { let p = parentOf(dir ? dir + '/' + x.rel : x.rel); while (p && p !== dir) { state.openFolders.add(p); p = parentOf(p); } });
        store('open', [...state.openFolders]);
        // Le schede aperte su file appena sovrascritti mostrano il nuovo contenuto (se non hanno modifiche in sospeso)
        items.filter(it => it.status === 'ok' && it.replaced).forEach(it => reloadTabFromDisk(destOf(it)));
    });
}

/** Esito finale nella finestra di trasferimento: si chiude da sola se è andato tutto bene, altrimenti chiede conferma. */
function finishTransfer(dir, all, after) {
    const d = $('#transferDialog');
    transfer.running = false;
    transfer.xhr = null;
    $('#trPauseAll').hidden = true;
    // I file saltati per scelta dell'utente non sono un errore da confermare
    const bad = all.filter(it => it.status !== 'ok' && it.status !== 'skip');
    const okCount = all.filter(it => it.status === 'ok').length;
    const skipped = all.filter(it => it.status === 'skip').length;
    $('#trProgress').value = 100;
    $('#trCount').textContent = L('tr.n_uploaded', { n: okCount, m: all.length });
    if (after) after();
    refreshAll();

    if (!bad.length) {
        $('#trTitle').textContent = L('tr.done_title', { path: '/' + dir });
        $('#trCancel').textContent = L('js.close');
        toast(L('tr.done_toast', { n: okCount, path: '/' + dir }) + (skipped ? ', ' + L('tr.n_kept', { n: skipped }) : ''));
        setTimeout(() => { if (d.open && !transfer.running) d.close(); }, 1400);
        return;
    }
    transfer.needsAck = true;
    const errors = bad.filter(it => it.status === 'error').length;
    const warns = bad.filter(it => it.status === 'warning').length;
    const cancelled = bad.filter(it => it.status === 'cancel').length;
    $('#trTitle').textContent = L('tr.problems_title');
    $('#trAlertText').innerHTML = `<b>${esc(L('js.warning'))}:</b> ${esc([
        errors ? L('tr.n_failed', { n: errors }) : '',
        warns ? L('tr.n_partial', { n: warns }) : '',
        cancelled ? L('tr.n_cancelled', { n: cancelled }) : '',
    ].filter(Boolean).join(', '))}. ${esc(L('tr.problems_note'))}`;
    $('#trAlert').hidden = false;
    $('#trCancel').hidden = true;
    $('#trAck').hidden = false;
    // In cima gli elementi con problemi
    const list = $('#trList');
    bad.forEach(it => list.prepend(it.row));
    list.scrollTop = 0;
    $('#trAck').focus();
}

// ----------------------------------------------------------------------------
// CLAUDE AI
// ----------------------------------------------------------------------------
function initClaude() {
    $('#claudeModel').innerHTML = Object.entries(APP.models).map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('');
    $('#claudeModel').value = APP.config.claude_model;
    $('#claudeEffort').value = APP.config.claude_effort;
    renderClaudeKey();

    $('#claudeSend').addEventListener('click', sendToClaude);
    $('#claudeInput').addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); sendToClaude(); }
    });
    $('#claudeReset').addEventListener('click', () => {
        if (state.chat.length && !confirm(L('js.new_chat_q'))) return;
        state.chat = [];
        $('#claudeChat').innerHTML = '';
    });
    $('#claudeCopy').addEventListener('click', copyClaudePrompt);
    $('#ctxActive').addEventListener('change', renderCtxChips);

    $('#ctxChips').addEventListener('click', (e) => {
        const x = e.target.closest('[data-unctx]');
        if (x) toggleClaudeContext([x.dataset.unctx]);
        const xr = e.target.closest('[data-unreq]');
        if (xr) setRequestContext(parseInt(xr.dataset.unreq, 10), false);
        const open = e.target.closest('[data-open]');
        if (open) openFile(open.dataset.open);
    });

    // "A tutto schermo": il pannello viene spostato dentro una finestra grande e poi rimesso al suo posto
    const dlg = $('#claudeDialog'), panel = $('#claudePanel');
    $('#claudeExpand').addEventListener('click', () => {
        $('#claudeDialogBody').appendChild(panel);
        showModal(dlg);
        focusSoft($('#claudeInput'));
    });
    dlg.addEventListener('close', () => ($('#claudeCard .card-body') || $('#claudeCard')).appendChild(panel));

    $('#claudeChat').addEventListener('click', (e) => {
        const copy = e.target.closest('[data-copy]');
        const ins = e.target.closest('[data-insert]');
        const code = (copy || ins) && (copy || ins).closest('.code-block').querySelector('code').textContent;
        if (copy) navigator.clipboard.writeText(code).then(() => toast(L('js.code_copied')));
        if (ins) {
            const tab = tabFor(state.active);
            if (!tab || tab.kind !== 'code' || !editor) { toast(L('js.open_code_first'), 'error'); return; }
            if (!confirm(L('js.insert_q', { path: tab.path }))) return;
            tab.session.setValue(code);
            toast(L('js.code_inserted', { name: baseName(tab.path) }));
        }
    });
}

function renderClaudeKey() {
    const row = $('#claudeKeyRow');
    row.innerHTML = APP.config.has_api_key ? ''
        : `${ic('alert')}<span>${L('js.no_api_key_html')}</span>`;
    const a = $('#openKeySettings');
    if (a) a.onclick = (e) => { e.preventDefault(); $('#settingsBtn').click(); };
}

function toggleClaudeContext(paths) {
    const allIn = paths.every(p => state.ctxFiles.has(p));
    paths.forEach(p => allIn ? state.ctxFiles.delete(p) : state.ctxFiles.add(p));
    store('ctx', [...state.ctxFiles]);
    renderTree();
    renderCtxChips();
    toast(allIn ? L('js.ctx_removed') : L('js.ctx_added'));
}

function contextPaths() {
    const list = [...state.ctxFiles];
    const tab = tabFor(state.active);
    if ($('#ctxActive').checked && tab && tab.kind === 'code' && !list.includes(tab.path)) list.unshift(tab.path);
    return list;
}

function renderCtxChips() {
    const tab = tabFor(state.active);
    const activeOn = $('#ctxActive').checked && tab && tab.kind === 'code';
    const chips = [];
    if (activeOn) chips.push(`<span class="chip active-chip" title="${esc(L('claude.active_tab'))}">${fileIcon(tab.path)}<span data-open="${esc(tab.path)}">${esc(baseName(tab.path))}</span></span>`);
    state.ctxFiles.forEach(p => {
        if (activeOn && p === tab.path) return;
        chips.push(`<span class="chip" title="${esc(p)}">${fileIcon(p)}<span data-open="${esc(p)}">${esc(baseName(p))}</span><button type="button" data-unctx="${esc(p)}" title="${esc(L('js.remove'))}">${ic('x')}</button></span>`);
    });
    state.ctxRequests.forEach(id => {
        const q = state.httpList.find(x => x.id == id);
        if (!q) return;
        chips.push(`<span class="chip api-chip" title="${esc(L('js.last_response_of', { req: q.method + ' ' + q.url }))}">${ic('api')}<span data-open="http:${q.id}">${esc(q.name)}</span><button type="button" data-unreq="${q.id}" title="${esc(L('js.remove'))}">${ic('x')}</button></span>`);
    });
    $('#ctxChips').innerHTML = chips.join('') || `<span class="muted">${esc(L('js.ctx_none'))}</span>`;
}

// Markdown essenziale per le risposte: blocchi di codice, `inline`, **grassetto**, titoli, elenchi
function renderMarkdown(text) {
    const parts = text.split(/```([\w.+-]*)\n?([\s\S]*?)```/g);
    let html = '';
    for (let i = 0; i < parts.length; i++) {
        if (i % 3 === 0) {
            html += esc(parts[i])
                .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>')
                .replace(/^#{1,6} (.+)$/gm, '<b class="md-h">$1</b>')
                .replace(/^\s*[-*] (.+)$/gm, '• $1')
                .replace(/\n/g, '<br>');
        } else if (i % 3 === 2) {
            html += `<div class="code-block"><div class="code-head"><span>${esc(parts[i - 1] || 'codice')}</span>`
                + `<span><button type="button" class="mini-btn add" data-copy title="${esc(L('js.copy'))}">${ic('copy')}</button> <button type="button" class="mini-btn add" data-insert title="${esc(L('js.insert_title'))}">${ic('file')} ${esc(L('js.insert_btn'))}</button></span></div>`
                + `<pre><code>${esc(parts[i])}</code></pre></div>`;
        }
    }
    return html;
}

function addChatBubble(role, html) {
    const el = document.createElement('div');
    el.className = 'bubble ' + role;
    el.innerHTML = html;
    $('#claudeChat').appendChild(el);
    anim.pop(el);
    el.scrollIntoView({ block: 'end', behavior: 'smooth' });
    return el;
}

function sendToClaude() {
    const input = $('#claudeInput');
    const text = input.value.trim();
    if (!text) return;
    if (!APP.config.has_api_key) { toast(L('api.no_api_key'), 'error'); return; }
    const btn = $('#claudeSend');
    if (btn.disabled) return;

    // Se l'ultimo turno è già dell'utente (risultati di strumenti rimasti a metà) il testo si aggiunge lì
    const snapshot = JSON.stringify(state.chat);
    const last = state.chat[state.chat.length - 1];
    if (last && last.role === 'user' && Array.isArray(last.content)) last.content.push({ type: 'text', text });
    else state.chat.push({ role: 'user', content: text });
    addChatBubble('user', esc(text).replace(/\n/g, '<br>'));
    input.value = '';
    const pending = addChatBubble('assistant pending', '<span class="typing"><i></i><i></i><i></i></span> ' + esc(L('js.claude_working')));
    btn.disabled = true;

    // Le schede non salvate mandano il contenuto attuale dell'editor
    const overrides = {};
    // Tutte le schede con modifiche non salvate: Claude legge e propone sul testo dell'editor, non su quello del disco
    state.tabs.forEach(t => { if (t.kind === 'code' && isDirty(t)) overrides[t.path] = t.session.getValue(); });

    apiPost({
        action: 'claude_chat',
        model: $('#claudeModel').value,
        effort: $('#claudeEffort').value,
        messages: state.chat,
        context: contextPaths(),
        context_requests: [...state.ctxRequests].filter(id => state.httpList.some(q => q.id == id)),
        overrides,
        include_docs: $('#ctxDocs').checked ? '1' : '0',
        active: state.active && !/^(http|diff):/.test(state.active) ? state.active : '',
    }).then(res => {
        btn.disabled = false;
        pending.remove();
        if (!res.ok) {
            state.chat = JSON.parse(snapshot);
            addChatBubble('error', ic('alert') + ' ' + esc(res.error));
            input.value = text;
            return;
        }
        state.chat.push(...(res.messages || []));
        const u = res.usage ? `<div class="bubble-meta">${esc(res.model)} · ${res.usage.input_tokens} in / ${res.usage.output_tokens} out</div>` : '';
        // Cosa ha letto Claude per rispondere (file, ricerche, risposte API)
        const icons = { elenca_file: 'folder', leggi_file: 'file', cerca_nei_file: 'eye', elenca_richieste_api: 'api', leggi_risposta_api: 'api',
            proponi_modifica: 'pen', proponi_nuovo_file: 'file-plus' };
        const steps = (res.steps || []).length
            ? `<div class="tool-steps">${res.steps.map(st => `<span class="tool-step${st.error ? ' err' : ''}">${ic(icons[st.tool] || 'file')}${esc(st.label)}</span>`).join('')}</div>`
            : '';
        addChatBubble('assistant', steps + renderMarkdown(res.text || L('js.no_text')) + proposalsHtml(res.proposals || []) + u);
        refreshSide();
    }).catch(err => {
        btn.disabled = false;
        pending.remove();
        state.chat = JSON.parse(snapshot);
        input.value = text;
        addChatBubble('error', ic('alert') + ' ' + esc(L('js.network_error') + ': ' + String(err)));
    });
}

async function copyClaudePrompt() {
    let out = '# ' + L('cp.context') + '\n\nRoot: ' + APP.rootDir + '\n\n## 1. ' + L('cp.structure') + '\n';
    state.dirs.forEach(d => { if (d) out += d + '/\n'; });
    state.files.forEach(f => { out += f + (f === state.active ? '   <-- [' + L('cp.active') + ']' : '') + '\n'; });
    out += '\n## 2. ' + L('cp.source') + '\n';
    const paths = contextPaths();
    for (const p of paths) {
        const t = tabFor(p);
        let code = t && t.session ? t.session.getValue() : null;
        if (code === null) {
            const res = await apiGet('get_file', { file: p });
            code = res.ok ? res.content : '';
        }
        out += `\n### File: \`${p}\`\n\`\`\`${extOf(p)}\n${code}\n\`\`\`\n`;
    }
    if (!paths.length) out += '*(' + L('cp.no_files') + ')*\n';
    for (const id of state.ctxRequests) {
        const res = await apiGet('http_get', { id });
        if (!res.ok || !res.response) continue;
        const q = res.request, r = res.response;
        out += `\n### ${L('cp.api_response')} "${q.name}": ${q.method} ${q.url} → HTTP ${r.status}\n\`\`\`\n${r.error || prettyBody(r.body)}\n\`\`\`\n`;
    }
    out += '\n## 3. ' + L('cp.request') + '\n' + ($('#claudeInput').value.trim() || '[' + L('cp.write_here') + ']');
    navigator.clipboard.writeText(out).then(() => toast(L('js.prompt_copied', { n: paths.length, size: humanSize(out.length) })));
}

// ----------------------------------------------------------------------------
// RICHIESTE API: elenco a destra, scheda con richiesta e risposta, contesto per Claude
// La chiave resta sul server: il campo "chiave" si compila solo per cambiarla.
// ----------------------------------------------------------------------------
function tabName(t) { return t.kind === 'http' ? t.req.name : (t.kind === 'diff' ? baseName(t.prop.path) : baseName(t.path)); }
function tabIcon(t) {
    if (t.kind === 'http') return `<span class="ficon api-icon">${ic('api')}</span>`;
    if (t.kind === 'diff') return `<span class="ficon diff-icon">${ic('sparkles')}</span>`;
    return fileIcon(t.path);
}

function initHttp() {
    $('#httpNew').addEventListener('click', () => {
        apiPost({ action: 'http_save', request: { name: L('http.new'), method: 'GET', url: '', headers: [], auth: { type: 'header', name: 'X-API-Key' } } }).then(res => {
            if (!res.ok) { toast(res.error, 'error'); return; }
            refreshHttpList().then(() => openFile('http:' + res.request.id).then(() => focusSoft($('#hUrl'))));
        });
    });
    initCurlImport();
    $('#httpList').addEventListener('click', (e) => {
        const it = e.target.closest('[data-req]');
        if (it) openFile('http:' + it.dataset.req);
    });

    const form = $('#httpView');
    // Ogni modifica viene salvata da sola dopo una breve pausa
    form.addEventListener('input', (e) => {
        if (e.target.id === 'hCtx') return;
        const tab = tabFor(state.active);
        if (!tab || tab.kind !== 'http') return;
        if (e.target.id === 'hName') { tab.req.name = e.target.value || L('http.new'); renderTabs(); document.title = tab.req.name + ' — Scaffolder Pro'; }
        if (e.target.id === 'hMethod') $('#hBodySec').hidden = e.target.value === 'GET';
        if (e.target.id === 'hAuthType') updateAuthFields();
        clearTimeout(tab.saveTimer);
        tab.saveTimer = setTimeout(() => saveHttp(tab), 600);
    });
    $('#hUrl').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sendHttp(); } });
    $('#hSend').addEventListener('click', sendHttp);
    $('#hAddHeader').addEventListener('click', () => { addHeaderRow({ name: '', value: '', on: true }); $('#hHeaders .hdr-row:last-child input').focus(); });
    $('#hHeaders').addEventListener('click', (e) => {
        const rm = e.target.closest('[data-rmh]');
        if (!rm) return;
        rm.closest('.hdr-row').remove();
        form.dispatchEvent(new Event('input', { bubbles: true }));
    });
    $('#hAuthClear').addEventListener('click', () => {
        const tab = tabFor(state.active);
        if (!tab || !confirm(L('js.remove_req_key_q'))) return;
        saveHttp(tab, { clear: true });
    });
    $('#hCtx').addEventListener('change', (e) => {
        const tab = tabFor(state.active);
        if (tab) setRequestContext(tab.req.id, e.target.checked);
    });
    $('#hDelete').addEventListener('click', () => {
        const tab = tabFor(state.active);
        if (!tab || !confirm(L('js.delete_req_q', { name: tab.req.name }))) return;
        apiPost({ action: 'http_delete', id: tab.req.id }).then(() => {
            setRequestContext(tab.req.id, false, true);
            closeTab(tab.path, true);
            refreshHttpList();
        });
    });
    $$('[data-hview]').forEach(b => b.addEventListener('click', () => {
        $$('[data-hview]').forEach(x => x.classList.toggle('on', x === b));
        $('#hRespBody').hidden = b.dataset.hview !== 'body';
        $('#hRespHeaders').hidden = b.dataset.hview !== 'headers';
    }));
    $('#hCopy').addEventListener('click', () => {
        const tab = tabFor(state.active);
        if (!tab || !tab.resp) return;
        navigator.clipboard.writeText(prettyBody(tab.resp.body)).then(() => toast(L('js.response_copied')));
    });
    $('#hSaveFile').addEventListener('click', saveResponseAsFile);
    refreshHttpList();
}

// ----------------------------------------------------------------------------
// IMPORTA UNA cURL: comando da terminale oppure codice PHP (curl_init / curl_setopt)
// ----------------------------------------------------------------------------
const KEY_HEADER = /^(x-)?(api[-_]?key|apikey|auth[-_]?token|access[-_]?token)$/i;
const KEY_QUERY = /^(api[-_]?key|apikey|key|token|access[-_]?token|auth)$/i;
const PLACEHOLDER = /^(\{?\$\w+\}?|<[^>]+>|\[[^\]]+\]|la_tua_api_key|your[-_ ]?api[-_ ]?key|api[-_]?key|xxx+|\.\.\.|…|chiave|token|changeme|inserisci.*)$/i;

/** Divide un comando da terminale in parole, rispettando virgolette e "\" a fine riga. */
function shellWords(cmd) {
    const out = [];
    let cur = '', q = null, has = false;
    cmd = cmd.replace(/\\\r?\n/g, ' ');
    for (let i = 0; i < cmd.length; i++) {
        const c = cmd[i];
        if (q) {
            if (c === q) { q = null; continue; }
            if (c === '\\' && q === '"' && i + 1 < cmd.length) { cur += cmd[++i]; continue; }
            cur += c;
        } else if (c === "'" || c === '"') { q = c; has = true; }
        else if (/\s/.test(c)) { if (cur || has) out.push(cur); cur = ''; has = false; }
        else if (c === '\\' && i + 1 < cmd.length) { cur += cmd[++i]; }
        else cur += c;
    }
    if (cur || has) out.push(cur);
    return out;
}

function parseShellCurl(text) {
    const w = shellWords(text.trim().replace(/^\$\s*/, ''));
    const r = { method: '', url: '', headers: [], body: '', insecure: false, getData: false, warnings: [] };
    const data = [];
    for (let i = 0; i < w.length; i++) {
        const a = w[i], next = () => w[++i] ?? '';
        if (i === 0 && a === 'curl') continue;
        if (a === '-X' || a === '--request') r.method = next().toUpperCase();
        else if (a.startsWith('-X') && a.length > 2) r.method = a.slice(2).toUpperCase();
        else if (a === '-H' || a === '--header') r.headers.push(next());
        else if (['-d', '--data', '--data-raw', '--data-binary', '--data-ascii', '--data-urlencode', '--json'].includes(a)) {
            const v = next();
            data.push(v);
            if (a === '--json') { r.headers.push('Content-Type: application/json'); r.headers.push('Accept: application/json'); }
        }
        else if (a === '-u' || a === '--user') r.headers.push('Authorization: Basic ' + btoa(unescape(encodeURIComponent(next()))));
        else if (a === '--url') r.url = next();
        else if (a === '-k' || a === '--insecure') r.insecure = true;
        else if (a === '-G' || a === '--get') r.getData = true;
        else if (['-A', '--user-agent', '-e', '--referer', '-b', '--cookie', '-o', '--output', '-m', '--max-time', '--connect-timeout', '-w', '--write-out'].includes(a)) {
            const v = next();
            if (a === '-A' || a === '--user-agent') r.headers.push('User-Agent: ' + v);
            if (a === '-e' || a === '--referer') r.headers.push('Referer: ' + v);
            if (a === '-b' || a === '--cookie') r.headers.push('Cookie: ' + v);
        }
        else if (/^https?:\/\//i.test(a)) r.url = a;
        else if (a.startsWith('-')) { /* opzioni senza effetto qui: -s, -L, -i, --compressed, -v… */ }
        else if (!r.url && /^[\w.-]+\.[a-z]{2,}(\/|$)/i.test(a)) r.url = 'https://' + a;
    }
    if (data.length) {
        if (r.getData) r.url += (r.url.includes('?') ? '&' : '?') + data.join('&');
        else { r.body = data.join('&'); if (!r.method) r.method = 'POST'; }
    }
    r.method = r.method || 'GET';
    return r;
}

/** Valuta un'espressione PHP semplice: stringhe '…' / "…", variabili $x e concatenazioni con il punto. */
function phpExpr(expr, vars) {
    let out = '', ok = true;
    const re = /'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"|\$(\w+)|(\.)|(\s+)|([^'"$.\s]+)/g;
    let m;
    while ((m = re.exec(expr))) {
        if (m[1] !== undefined) out += m[1].replace(/\\(['\\])/g, '$1');
        else if (m[2] !== undefined) out += m[2].replace(/\{?\$(\w+)\}?/g, (x, v) => vars[v] ?? `{$${v}}`).replace(/\\(["\\$])/g, '$1').replace(/\\n/g, '\n');
        else if (m[3] !== undefined) out += vars[m[3]] ?? `{$${m[3]}}`;
        else if (m[6] !== undefined) ok = false;   // funzioni o costanti: non valutabili qui
    }
    return { value: out, ok };
}

/** Trova l'argomento che segue "OPZIONE =>" (o "OPZIONE,") fino alla virgola o parentesi di chiusura. */
function phpOptionValue(code, opt) {
    const m = new RegExp(opt + "\\s*(=>|,)\\s*", 'i').exec(code);
    if (!m) return null;
    let i = m.index + m[0].length, depth = 0, q = null, start = i;
    for (; i < code.length; i++) {
        const c = code[i];
        if (q) { if (c === '\\') i++; else if (c === q) q = null; continue; }
        if (c === "'" || c === '"') q = c;
        else if (c === '[' || c === '(') depth++;
        else if (c === ']' || c === ')') { if (depth === 0) break; depth--; }
        else if ((c === ',' || c === ';') && depth === 0) break;
    }
    return code.slice(start, i).trim();
}

/** Divide un array PHP [a, b, c] (o array(a, b)) nei suoi elementi di primo livello. */
function phpArrayItems(src) {
    const inner = src.replace(/^array\s*\(/i, '[').replace(/^\[/, '').replace(/[\])]\s*$/, '');
    const items = [];
    let cur = '', depth = 0, q = null;
    for (let i = 0; i < inner.length; i++) {
        const c = inner[i];
        if (q) { cur += c; if (c === '\\') cur += inner[++i] ?? ''; else if (c === q) q = null; continue; }
        if (c === "'" || c === '"') q = c;
        if (c === '[' || c === '(') depth++;
        if (c === ']' || c === ')') depth--;
        if (c === ',' && depth === 0) { if (cur.trim()) items.push(cur.trim()); cur = ''; continue; }
        cur += c;
    }
    if (cur.trim()) items.push(cur.trim());
    return items;
}

function parsePhpCurl(code) {
    const r = { method: '', url: '', headers: [], body: '', insecure: false, warnings: [] };
    const clean = code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"])\/\/[^\n]*/g, '$1').replace(/(^|\n)\s*#[^\n]*/g, '$1');
    const vars = {};
    const assign = /\$(\w+)\s*=\s*([^;]+);/g;
    let m;
    while ((m = assign.exec(clean))) {
        const v = phpExpr(m[2].trim(), vars);
        if (v.ok) vars[m[1]] = v.value;
    }
    const init = /curl_init\s*\(\s*([^)]*?)\s*\)/.exec(clean);
    const urlSrc = phpOptionValue(clean, 'CURLOPT_URL') || (init && init[1]) || '';
    r.url = phpExpr(urlSrc, vars).value;
    const hdr = phpOptionValue(clean, 'CURLOPT_HTTPHEADER');
    if (hdr) {
        const list = /^\$(\w+)$/.test(hdr) ? (/\$\w+\s*=\s*(\[[\s\S]*?\]|array\s*\([\s\S]*?\))\s*;/.exec(clean.slice(clean.indexOf(hdr + ' ='))) || [])[1] || '' : hdr;
        phpArrayItems(list).forEach(it => r.headers.push(phpExpr(it, vars).value));
    }
    const custom = phpOptionValue(clean, 'CURLOPT_CUSTOMREQUEST');
    if (custom) r.method = phpExpr(custom, vars).value.toUpperCase();
    const post = phpOptionValue(clean, 'CURLOPT_POSTFIELDS');
    if (post) {
        const v = phpExpr(post, vars);
        r.body = v.value;
        if (!v.ok) r.warnings.push(L('curl.w_body_php'));
        if (!r.method) r.method = 'POST';
    }
    if (/CURLOPT_POST\s*(=>|,)\s*(true|1)/i.test(clean) && !r.method) r.method = 'POST';
    if (/CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*(false|0)/i.test(clean)) r.insecure = true;
    const userpwd = phpOptionValue(clean, 'CURLOPT_USERPWD');
    if (userpwd) r.headers.push('Authorization: Basic ' + btoa(unescape(encodeURIComponent(phpExpr(userpwd, vars).value))));
    r.method = r.method || 'GET';
    return r;
}

/** Da cURL a richiesta salvabile: la chiave (header, Bearer o parametro dell'URL) va nel campo protetto. */
function curlToRequest(text) {
    const isPhp = /curl_init|curl_setopt|<\?php|CURLOPT_/i.test(text);
    const p = isPhp ? parsePhpCurl(text) : parseShellCurl(text);
    if (!/^https?:\/\//i.test(p.url)) throw new Error(L('curl.no_url'));
    const req = { name: '', method: p.method, url: p.url, headers: [], body: p.body, auth: { type: 'none', name: '', value: '' }, options: { insecure: p.insecure } };
    const warnings = [...p.warnings];
    let keyFound = false;
    const takeKey = (type, name, value) => {
        keyFound = true;
        const v = String(value || '').trim();
        req.auth = { type, name, value: PLACEHOLDER.test(v) || /^\{\$\w+\}$/.test(v) ? '' : v };
    };
    p.headers.forEach(h => {
        const i = h.indexOf(':');
        if (i < 1) return;
        const name = h.slice(0, i).trim(), value = h.slice(i + 1).trim();
        if (!keyFound && KEY_HEADER.test(name)) return takeKey('header', name, value);
        if (!keyFound && /^authorization$/i.test(name) && /^bearer\s+/i.test(value)) return takeKey('bearer', '', value.replace(/^bearer\s+/i, ''));
        if (/^(content-length|host)$/i.test(name)) return;
        req.headers.push({ name, value, on: true });
    });
    try {
        const u = new URL(req.url);
        for (const [k, v] of u.searchParams) {
            if (!KEY_QUERY.test(k)) continue;
            if (!keyFound) { takeKey('query', k, v); u.searchParams.delete(k); req.url = u.toString(); }
            else warnings.push(L('curl.w_key_in_url', { k }));
            break;
        }
        const last = u.pathname.split('/').filter(Boolean).pop() || u.hostname;
        req.name = (u.hostname === '127.0.0.1' || u.hostname === 'localhost' ? 'locale' : u.hostname.replace(/^www\./, '')) + ' · ' + last;
        if (['127.0.0.1', 'localhost', '::1'].includes(u.hostname)) {
            warnings.push(L('curl.w_localhost', { host: u.hostname }));
            if (u.protocol === 'https:') warnings.push(L('curl.w_local_ssl'));
        }
    } catch (e) { req.name = L('curl.imported'); }
    if (keyFound && req.auth.value === '') warnings.push(L('curl.w_placeholder'));
    return { req, warnings, isPhp };
}

function initCurlImport() {
    const d = $('#curlDialog'), input = $('#curlInput'), prev = $('#curlPreview'), btn = $('#curlImport');
    let parsed = null;
    $('#httpPaste').addEventListener('click', () => {
        input.value = '';
        prev.hidden = true;
        btn.disabled = true;
        parsed = null;
        showModal(d);
        setTimeout(() => focusSoft(input), 50);
    });
    input.addEventListener('input', () => {
        const t = input.value.trim();
        parsed = null;
        btn.disabled = true;
        if (!t) { prev.hidden = true; return; }
        try {
            parsed = curlToRequest(t);
            const q = parsed.req;
            const authTxt = { none: L('http.auth_none'), header: `header ${q.auth.name}`, query: `?${q.auth.name}=`, bearer: 'Bearer token' }[q.auth.type];
            prev.innerHTML = `<div><b>${esc(L(parsed.isPhp ? 'curl.php_found' : 'curl.cmd_found'))}</b></div>`
                + `<div class="curl-line"><span class="method m-${esc(q.method.toLowerCase())}">${esc(q.method)}</span><code>${esc(q.url)}</code></div>`
                + `<div>${esc(L('http.auth'))}: ${esc(authTxt)}${q.auth.type !== 'none' ? (q.auth.value ? ' · ' + esc(L('curl.key_found')) + ' (••••' + esc(q.auth.value.slice(-4)) + ')' : ' · <b>' + esc(L('curl.key_missing')) + '</b>') : ''}</div>`
                + (q.headers.length ? `<div>${esc(L('http.headers'))}: ${q.headers.map(h => esc(h.name)).join(', ')}</div>` : '')
                + (q.body ? `<div>${esc(L('http.resp_body'))}: ${esc(q.body.slice(0, 120))}${q.body.length > 120 ? '…' : ''}</div>` : '')
                + (q.options.insecure ? `<div>${esc(L('curl.ssl_unverified'))}</div>` : '')
                + parsed.warnings.map(w => `<div class="curl-warn">${ic('alert')}<span>${esc(w)}</span></div>`).join('');
            prev.hidden = false;
            btn.disabled = false;
        } catch (e) {
            prev.innerHTML = `<div class="curl-warn">${ic('alert')}<span>${esc(e.message)}</span></div>`;
            prev.hidden = false;
        }
    });
    btn.addEventListener('click', () => {
        if (!parsed) return;
        const run = $('#curlRun').checked && (parsed.req.auth.type === 'none' || parsed.req.auth.value !== '');
        btn.disabled = true;
        apiPost({ action: 'http_save', request: parsed.req }).then(res => {
            btn.disabled = false;
            if (!res.ok) { toast(res.error, 'error'); return; }
            d.close();
            toast(L('curl.imported') + (parsed.req.auth.value ? ': ' + L('curl.key_on_server') : ''));
            refreshHttpList().then(() => openFile('http:' + res.request.id)).then(() => {
                if (run) sendHttp();
                else if (parsed.req.auth.type !== 'none') $('#hAuthValue').focus();
            });
        });
    });
}

function refreshHttpList() {
    return apiGet('http_list').then(res => {
        if (!res.ok) return;
        state.httpList = res.requests;
        // Scarta dal contesto le richieste eliminate
        [...state.ctxRequests].forEach(id => { if (!res.requests.some(q => q.id == id)) state.ctxRequests.delete(id); });
        storeGlobal('ctxreq', [...state.ctxRequests]);
        renderHttpList();
        renderCtxChips();
        (state.onHttpList || []).forEach(fn => fn());
    });
}

function statusClass(status, error) {
    if (error || !status) return 'err';
    if (status < 300) return 'ok';
    if (status < 400) return 'redir';
    if (status < 500) return 'warn';
    return 'err';
}

function renderHttpList() {
    const box = $('#httpList');
    if (!box) return;
    box.innerHTML = state.httpList.length ? state.httpList.map(q => {
        const st = q.ran_at ? `<span class="status-pill sm ${statusClass(q.status, q.error)}">${q.error ? esc(L('tr.s_error')) : q.status}</span>` : '';
        const ctx = state.ctxRequests.has(q.id) ? `<span class="ctx-dot" title="${esc(L('http.in_context'))}"></span>` : '';
        return `<div class="http-item${state.active === 'http:' + q.id ? ' active' : ''}" data-req="${q.id}" title="${esc(q.method + ' ' + (q.url || '(senza URL)'))}">`
            + `<span class="method m-${esc(q.method.toLowerCase())}">${esc(q.method)}</span><span class="http-item-name">${esc(q.name)}</span>${ctx}${st}</div>`;
    }).join('') : `<div class="log-empty">${esc(L('js.no_requests'))}</div>`;
}

function openHttpTab(id, { activate = true, animate = true, silent = false } = {}) {
    return apiGet('http_get', { id }).then(res => {
        if (!res.ok) { if (!silent) toast(res.error, 'error'); return null; }
        const path = 'http:' + id;
        if (tabFor(path)) { if (activate) activateTab(path); return tabFor(path); }
        const tab = { path, kind: 'http', req: res.request, resp: res.response, session: null, saved: '' };
        state.tabs.push(tab);
        renderTabs(animate ? path : null);
        if (activate) activateTab(path);
        saveTabsState();
        return tab;
    });
}

function addHeaderRow(h) {
    const row = document.createElement('div');
    row.className = 'hdr-row';
    row.innerHTML = `<input type="checkbox" class="h-on" ${h.on ? 'checked' : ''} title="${esc(L('js.active'))}">`
        + `<input type="text" class="h-name" placeholder="${esc(L('set.name'))}" value="${esc(h.name)}" autocomplete="off">`
        + `<input type="text" class="h-value" placeholder="${esc(L('js.value'))}" value="${esc(h.value)}" autocomplete="off">`
        + `<button type="button" class="icon-btn" data-rmh title="${esc(L('js.remove'))}">${ic('x')}</button>`;
    $('#hHeaders').appendChild(row);
    $('#hHeadCount').textContent = $$('#hHeaders .hdr-row').length;
}

function updateAuthFields() {
    const type = $('#hAuthType').value;
    $('#hAuthName').hidden = type === 'none' || type === 'bearer';
    $('#hAuthValue').hidden = type === 'none';
    $('#hAuthName').placeholder = type === 'query' ? 'api_key' : 'X-API-Key';
    $('#hAuthValue').placeholder = type === 'bearer' ? 'token' : L('http.key');
}

function renderHttp(tab) {
    const q = tab.req;
    $('#hName').value = q.name;
    $('#hMethod').value = q.method;
    $('#hUrl').value = q.url;
    $('#hBody').value = q.body || '';
    $('#hBodySec').hidden = q.method === 'GET';
    $('#hAuthType').value = q.auth.type;
    $('#hAuthName').value = q.auth.name;
    $('#hAuthValue').value = '';
    $('#hAuthHint').textContent = q.auth.has_value
        ? L('js.req_key_saved', { hint: q.auth.hint })
        : (q.auth.type === 'none' ? '' : L('js.no_key'));
    $('#hAuthClear').hidden = !q.auth.has_value;
    updateAuthFields();
    $('#hHeaders').innerHTML = '';
    q.headers.forEach(addHeaderRow);
    $('#hHeadCount').textContent = q.headers.length;
    $('#hCtx').checked = state.ctxRequests.has(q.id);
    $('#hInsecure').checked = !!(q.options && q.options.insecure);
    renderResponse(tab);
}

function collectHttp(tab) {
    return {
        id: tab.req.id,
        name: $('#hName').value,
        method: $('#hMethod').value,
        url: $('#hUrl').value,
        body: $('#hBody').value,
        headers: $$('#hHeaders .hdr-row').map(r => ({ name: $('.h-name', r).value, value: $('.h-value', r).value, on: $('.h-on', r).checked })),
        auth: { type: $('#hAuthType').value, name: $('#hAuthName').value, value: $('#hAuthValue').value },
        options: { insecure: $('#hInsecure').checked },
    };
}

function saveHttp(tab, { clear = false } = {}) {
    clearTimeout(tab.saveTimer);
    const data = collectHttp(tab);
    if (clear) { data.auth.clear = true; data.auth.value = ''; }
    const typedKey = data.auth.value !== '';
    return apiPost({ action: 'http_save', request: data }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return false; }
        tab.req = res.request;
        if (state.active === tab.path) {
            if (typedKey || clear) { $('#hAuthValue').value = ''; renderHttp(tab); if (typedKey) toast(L('js.key_on_server')); }
        }
        const i = state.httpList.findIndex(q => q.id == tab.req.id);
        if (i >= 0) Object.assign(state.httpList[i], { name: tab.req.name, method: tab.req.method, url: tab.req.url });
        renderHttpList();
        renderTabs();
        return true;
    });
}

async function sendHttp() {
    const tab = tabFor(state.active);
    if (!tab || tab.kind !== 'http') return;
    if (!/^https?:\/\//i.test($('#hUrl').value.trim())) { toast(L('http.url_scheme'), 'warn'); $('#hUrl').focus(); return; }
    const btn = $('#hSend');
    if (btn.disabled) return;
    btn.disabled = true;
    $('#hStatus').className = 'status-pill run';
    $('#hStatus').textContent = '…';
    $('#hMeta').textContent = L('js.request_running');
    if (!(await saveHttp(tab))) { btn.disabled = false; renderResponse(tab); return; }
    apiPost({ action: 'http_run', id: tab.req.id }).then(res => {
        btn.disabled = false;
        if (!res.ok) { toast(res.error, 'error'); renderResponse(tab); return; }
        tab.resp = res.response;
        if (state.active === tab.path) renderResponse(tab);
        if (res.response.error) toast(L('js.request_failed') + ': ' + res.response.error, 'error');
        else if (res.response.status >= 400) toast(L('js.server_replied', { status: res.response.status }), 'warn');
        refreshHttpList();
        refreshSide();
    }).catch(err => { btn.disabled = false; toast(L('js.network_error') + ': ' + err, 'error'); renderResponse(tab); });
}

/** Corpo leggibile: JSON indentato se possibile, altrimenti il testo così com'è. */
function prettyBody(body) {
    const t = String(body ?? '');
    try { return JSON.stringify(JSON.parse(t), null, 2); } catch (e) { return t; }
}

function highlightJson(text) {
    return esc(text).replace(/(&quot;(?:\\.|[^\\&]|&(?!quot;))*?&quot;)(\s*:)?|\b(true|false|null)\b|-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/g, (m, str, colon, lit) => {
        if (str) return colon ? `<span class="j-key">${str}</span>${colon}` : `<span class="j-str">${str}</span>`;
        if (lit) return `<span class="j-lit">${m}</span>`;
        return `<span class="j-num">${m}</span>`;
    });
}

function renderResponse(tab) {
    const r = tab.resp;
    const pill = $('#hStatus'), meta = $('#hMeta'), body = $('#hRespBody'), heads = $('#hRespHeaders');
    if (!r) {
        pill.className = 'status-pill';
        pill.textContent = '—';
        meta.textContent = L('http.no_response');
        body.textContent = '';
        heads.innerHTML = '';
        return;
    }
    pill.className = 'status-pill ' + statusClass(r.status, r.error);
    pill.textContent = r.error ? L('tr.s_error') : 'HTTP ' + r.status;
    meta.textContent = [r.duration_ms + ' ms', humanSize(+r.size), r.created_at.substring(11, 19) + ' · ' + r.created_at.substring(0, 10), +r.truncated ? L('js.truncated_2mb') : '']
        .filter(Boolean).join(' · ');
    if (r.error) {
        body.innerHTML = `<span class="j-err">${esc(r.error)}</span>`;
    } else {
        const pretty = prettyBody(r.body);
        const isJson = pretty !== String(r.body ?? '') || /^\s*[\[{]/.test(String(r.body ?? ''));
        body.innerHTML = isJson && pretty.length < 400000 ? highlightJson(pretty) : esc(pretty);
    }
    heads.innerHTML = (r.headers || []).map(([k, v]) => `<div><b>${esc(k)}</b><span>${esc(v)}</span></div>`).join('')
        + (r.final_url ? `<div><b>URL finale</b><span>${esc(r.final_url)}</span></div>` : '');
}

function setRequestContext(id, on, quiet = false) {
    if (on) state.ctxRequests.add(id); else state.ctxRequests.delete(id);
    storeGlobal('ctxreq', [...state.ctxRequests]);
    const tab = tabFor(state.active);
    if (tab && tab.kind === 'http' && tab.req.id === id) $('#hCtx').checked = on;
    renderHttpList();
    renderCtxChips();
    if (!quiet) toast(on ? L('js.req_ctx_on') : L('js.req_ctx_off'));
}

function saveResponseAsFile() {
    const tab = tabFor(state.active);
    if (!tab || !tab.resp || tab.resp.error) { toast(L('js.no_response_to_save'), 'warn'); return; }
    const slug = tab.req.name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'response';
    const path = prompt(L('js.response_path_prompt'), 'data/' + slug + '.json');
    if (!path || !path.trim()) return;
    const rel = path.trim().replace(/^\/+/, '');
    const content = prettyBody(tab.resp.body);
    const write = () => apiPost({ action: 'save_file', filepath: rel, code: content }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        toast(L('js.response_saved', { path: rel }));
        refreshAll().then(() => reloadTabFromDisk(rel));
    });
    if (state.files.includes(rel)) {
        if (confirm(L('js.overwrite_q', { path: rel }))) write();
        return;
    }
    apiPost({ action: 'create_file', target_dir: parentOf(rel), file_name: baseName(rel) }).then(res => {
        if (!res.ok) { toast(res.error, 'error'); return; }
        write();
    });
}

// ----------------------------------------------------------------------------
// NIENTE ZOOM SU MOBILE: pinch e doppio tocco disattivati (Safari iOS ignora user-scalable=no)
// ----------------------------------------------------------------------------
function preventZoom() {
    ['gesturestart', 'gesturechange', 'gestureend'].forEach(ev => document.addEventListener(ev, (e) => e.preventDefault(), { passive: false }));
    document.addEventListener('touchmove', (e) => { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });
    let lastTap = 0;
    document.addEventListener('touchend', (e) => {
        const now = Date.now();
        // Doppio tocco veloce = zoom su iOS: si annulla, tranne nei campi di testo (serve per selezionare parole)
        if (now - lastTap < 300 && !e.target.closest('input, textarea, .ace_editor')) e.preventDefault();
        lastTap = now;
    }, { passive: false });
}

// ----------------------------------------------------------------------------
// TOOLTIP RAPIDI: sostituiscono quelli nativi del browser (che compaiono dopo ~1 s)
// ----------------------------------------------------------------------------
function initTooltips() {
    const tip = document.createElement('div');
    tip.className = 'tooltip';
    tip.hidden = true;
    document.body.appendChild(tip);
    let timer = null, current = null;

    const hide = () => { clearTimeout(timer); tip.hidden = true; tip.classList.remove('show'); current = null; };
    const place = (el) => {
        // Dentro una finestra modale il tooltip deve stare nel suo stesso livello, altrimenti finisce sotto
        const host = el.closest('dialog[open]') || document.body;
        if (tip.parentElement !== host) host.appendChild(tip);
        const r = el.getBoundingClientRect();
        tip.hidden = false;
        const tw = tip.offsetWidth, th = tip.offsetHeight;
        let top = r.bottom + 6;
        if (top + th > window.innerHeight - 4) top = r.top - th - 6;   // non c'è spazio sotto: sopra
        const left = Math.max(4, Math.min(r.left + r.width / 2 - tw / 2, window.innerWidth - tw - 4));
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        void tip.offsetWidth; // forza il reflow così la transizione parte subito
        tip.classList.add('show');
    };

    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest('[title], [data-tip]');
        if (!el || el === current) return;
        // Il title nativo viene spostato in data-tip, così il browser non mostra anche il suo
        if (el.hasAttribute('title')) {
            if (el.title) el.dataset.tip = el.title;
            el.removeAttribute('title');
        }
        hide();
        if (!el.dataset.tip || el.closest('dialog:not([open])')) return;
        current = el;
        // Subito se ci si sposta da un'icona all'altra, altrimenti dopo un attimo
        const delay = tip.dataset.recent === '1' ? 0 : 180;
        timer = setTimeout(() => {
            if (current !== el || !document.contains(el)) return;
            tip.textContent = el.dataset.tip;
            place(el);
            tip.dataset.recent = '1';
        }, delay);
    });
    document.addEventListener('mouseout', (e) => {
        if (current && !current.contains(e.relatedTarget)) {
            hide();
            clearTimeout(tip.recentTimer);
            tip.recentTimer = setTimeout(() => { tip.dataset.recent = ''; }, 400);
        }
    });
    ['mousedown', 'scroll', 'keydown', 'dragstart'].forEach(ev => document.addEventListener(ev, hide, true));
}

// ----------------------------------------------------------------------------
// SCORCIATOIE DA TASTIERA
// ----------------------------------------------------------------------------
function initShortcuts() {
    document.addEventListener('keydown', (e) => {
        const mod = e.metaKey || e.ctrlKey;
        if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); saveActive(); return; }
        if (mod && e.shiftKey && e.key.toLowerCase() === 'f') { e.preventDefault(); openSearch(true); return; }
        if (mod && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'p') { e.preventDefault(); openQuickOpen(); return; }
        if (e.altKey && e.code === 'KeyW') { e.preventDefault(); if (state.active) closeTab(state.active); return; }

        // Scorciatoie dell'Explorer solo con il focus nell'albero
        if (!document.activeElement || !document.activeElement.closest('#treeContainer')) return;
        if (e.key === 'Delete' || (e.key === 'Backspace' && e.metaKey)) { e.preventDefault(); deletePaths([...state.selected]); }
        if (e.key === 'F2' && state.selected.size === 1) { e.preventDefault(); renamePrompt([...state.selected][0]); }
        if (mod && e.key.toLowerCase() === 'd' && state.selected.size) { e.preventDefault(); duplicateItems([...state.selected]); }
        if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); state.selected = new Set(visibleRows().map(r => r.dataset.path)); updateRowClasses(); }
        if (e.key === 'Escape') { state.selected.clear(); updateRowClasses(); }
    });
    // Clic su una riga dà il focus all'albero, così Canc/F2 funzionano
    $('#tree').addEventListener('mousedown', () => $('#treeContainer').focus({ preventScroll: true }));
}

// ----------------------------------------------------------------------------
// CRONOLOGIA DI NAVIGAZIONE (come VS Code: Indietro / Avanti tra schede e punti del codice)
// Mac: ⌃- / ⌃⇧-   Windows/Linux: Alt+← / Alt+→   Mouse: tasti laterali Indietro / Avanti
// Una nuova voce nasce cambiando scheda o saltando più di NAV_LINES righe nello stesso file;
// i piccoli spostamenti aggiornano solo la posizione della voce attuale.
// ----------------------------------------------------------------------------
const nav = { list: [], idx: -1, busy: false };
const NAV_LINES = 10, NAV_MAX = 60;
const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

function navRecord(path, row, col) {
    if (nav.busy || !path) return;
    const cur = nav.list[nav.idx];
    if (cur && cur.path === path && Math.abs(cur.row - row) <= NAV_LINES) { cur.row = row; cur.col = col; return; }
    nav.list = nav.list.slice(0, nav.idx + 1);
    nav.list.push({ path, row, col });
    if (nav.list.length > NAV_MAX) nav.list.shift();
    nav.idx = nav.list.length - 1;
    updateNavButtons();
}

async function navGo(dir) {
    for (let i = nav.idx + dir; i >= 0 && i < nav.list.length; i = nav.idx + dir) {
        const e = nav.list[i];
        // Le schede chiuse si riaprono; i file che non esistono più (e le proposte di Claude chiuse) si saltano
        let tab = tabFor(e.path);
        if (!tab && !e.path.startsWith('diff:') && (e.path.startsWith('http:') || state.files.includes(e.path))) {
            tab = await openFile(e.path, { activate: false, silent: true });
        }
        if (!tab) {
            nav.list.splice(i, 1);
            if (i < nav.idx) nav.idx--;
            continue;
        }
        nav.busy = true;
        nav.idx = i;
        try {
            activateTab(e.path);
            if (tab.kind === 'code' && editor && tab.session === editor.session) {
                editor.clearSelection();
                editor.moveCursorTo(e.row, e.col);
                editor.scrollToLine(e.row, true, true, () => {});
            }
        } finally {
            nav.busy = false;
        }
        break;
    }
    updateNavButtons();
}

function updateNavButtons() {
    const back = $('#navBack'), fwd = $('#navForward');
    if (!back) return;
    back.disabled = nav.idx <= 0;
    fwd.disabled = nav.idx >= nav.list.length - 1;
}

function initNavHistory() {
    const kb = IS_MAC ? ['⌃-', '⌃⇧-'] : ['Alt+←', 'Alt+→'];
    $('#navBack').title = L('nav.back') + ' (' + kb[0] + ')';
    $('#navForward').title = L('nav.forward') + ' (' + kb[1] + ')';
    $('#navBack').addEventListener('click', () => navGo(-1));
    $('#navForward').addEventListener('click', () => navGo(1));
    // In cattura: vale anche con il focus nell'editor (Ace non riceve il tasto)
    document.addEventListener('keydown', (e) => {
        if ($$('dialog[open]').length) return;
        let dir = 0;
        if (e.ctrlKey && !e.metaKey && !e.altKey && (e.code === 'Minus' || e.key === '-' || e.key === '_')) dir = e.shiftKey ? 1 : -1;
        else if (!IS_MAC && e.altKey && !e.ctrlKey && !e.shiftKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) dir = e.key === 'ArrowLeft' ? -1 : 1;
        if (!dir) return;
        e.preventDefault();
        e.stopPropagation();
        navGo(dir);
    }, true);
    // Tasti laterali del mouse: senza preventDefault il browser lascerebbe la pagina
    window.addEventListener('mouseup', (e) => {
        if (e.button !== 3 && e.button !== 4) return;
        e.preventDefault();
        navGo(e.button === 3 ? -1 : 1);
    });
    window.addEventListener('mousedown', (e) => { if (e.button === 3 || e.button === 4) e.preventDefault(); });
}

// ----------------------------------------------------------------------------
// TEMA: scuro / chiaro / come il sistema (<html data-theme>, colori in style.css)
// ----------------------------------------------------------------------------
const themeMedia = window.matchMedia('(prefers-color-scheme: light)');

function isLightTheme() {
    const t = APP.config.theme || 'dark';
    return t === 'light' || (t === 'auto' && themeMedia.matches);
}

function applyTheme() {
    document.documentElement.dataset.theme = APP.config.theme || 'dark';
    if (editor) editor.setTheme(isLightTheme() ? 'ace/theme/tomorrow' : 'ace/theme/tomorrow_night');
    scheduleMinimap();
}

// ----------------------------------------------------------------------------
// FORMATTA DOCUMENTO (Prettier, caricato da lib/prettier solo al primo uso) — ⇧⌥F
// ----------------------------------------------------------------------------
const FORMATTERS = {
    php: { parser: 'php', plugins: ['php'] },
    js: { parser: 'babel', plugins: ['babel', 'estree'] }, mjs: { parser: 'babel', plugins: ['babel', 'estree'] },
    jsx: { parser: 'babel', plugins: ['babel', 'estree'] }, json: { parser: 'json', plugins: ['babel', 'estree'] },
    ts: { parser: 'typescript', plugins: ['typescript', 'estree'] }, tsx: { parser: 'typescript', plugins: ['typescript', 'estree'] },
    css: { parser: 'css', plugins: ['postcss'] }, scss: { parser: 'scss', plugins: ['postcss'] },
    html: { parser: 'html', plugins: ['html', 'postcss', 'babel', 'estree'] }, htm: { parser: 'html', plugins: ['html', 'postcss', 'babel', 'estree'] },
    vue: { parser: 'vue', plugins: ['html', 'postcss', 'babel', 'estree'] },
    md: { parser: 'markdown', plugins: ['markdown'] }, yml: { parser: 'yaml', plugins: ['yaml'] }, yaml: { parser: 'yaml', plugins: ['yaml'] },
};
const formatterFor = (path) => FORMATTERS[extOf(path)] || null;
const scriptCache = new Map();

/** Carica uno script di lib/ una volta sola. */
function loadScript(src) {
    if (!scriptCache.has(src)) {
        scriptCache.set(src, new Promise((resolve, reject) => {
            const el = document.createElement('script');
            el.src = src;
            el.onload = resolve;
            el.onerror = () => { scriptCache.delete(src); reject(new Error(src)); };
            document.head.appendChild(el);
        }));
    }
    return scriptCache.get(src);
}

async function loadPrettier(plugins) {
    await loadScript('lib/prettier/standalone.js');
    await Promise.all(plugins.map(p => loadScript('lib/prettier/plugins/' + p + '.js')));
    return { prettier: window.prettier, plugins: plugins.map(p => window.prettierPlugins[p]) };
}

/**
 * Formatta il testo della scheda. Sostituisce tutto con un solo passo annullabile (⌘Z) e tiene il cursore
 * vicino allo stesso punto. Restituisce true se il testo è cambiato.
 */
async function formatTab(tab, { quiet = false } = {}) {
    const f = tab && tab.kind === 'code' && tab.session && formatterFor(tab.path);
    if (!f) { if (!quiet) toast(L('fmt.not_supported'), 'warn'); return false; }
    if (!tab.writable) { if (!quiet) toast(L('fmt.readonly'), 'warn'); return false; }
    const text = tab.session.getValue();
    const doc = tab.session.getDocument();
    const isActive = editor && editor.session === tab.session;
    const cursor = isActive ? doc.positionToIndex(editor.getCursorPosition()) : 0;
    let res;
    try {
        if (!quiet && !window.prettier) toast(L('fmt.loading'));
        const { prettier, plugins } = await loadPrettier(f.plugins);
        const opts = { parser: f.parser, plugins, cursorOffset: Math.min(cursor, text.length), printWidth: 120, singleQuote: true,
            tabWidth: tab.session.getTabSize(), useTabs: !tab.session.getUseSoftTabs() };
        if (f.parser === 'php') opts.phpVersion = (APP.php.server || '8.0').split('.').slice(0, 2).join('.');
        res = await prettier.formatWithCursor(text, opts);
    } catch (err) {
        if (!quiet) {
            const line = err && err.loc && err.loc.start ? err.loc.start.line : null;
            const msg = String(err && err.message || err).split('\n')[0].replace(/\s*\(\d+:\d+\)\s*$/, '');
            toast(line ? L('fmt.error_line', { line, error: msg }) : L('fmt.error', { error: msg }), 'error');
        }
        return false;
    }
    if (res.formatted === text) { if (!quiet) toast(L('fmt.already')); return false; }
    if (tab.session.getValue() !== text) return false;   // modificato nel frattempo: si lascia stare
    const Range = ace.require('ace/range').Range;
    const last = doc.getLength() - 1;
    tab.session.replace(new Range(0, 0, last, doc.getLine(last).length), res.formatted);
    if (isActive && res.cursorOffset >= 0) {
        const p = doc.indexToPosition(res.cursorOffset);
        editor.clearSelection();
        editor.moveCursorToPosition(p);
        editor.renderer.scrollCursorIntoView(p, 0.5);
    }
    if (!quiet) toast(L('fmt.done'));
    return true;
}

// ----------------------------------------------------------------------------
// EMMET: abbreviazione + Tab in HTML, PHP (fuori da <?php ?>), CSS, SCSS
// es. ul>li.item*3 → lista, a:link, !, p10 (CSS) → padding: 10px;
// ----------------------------------------------------------------------------
const emmet = { mod: null, loading: null };
const EMMET_MARKUP = ['html', 'htm', 'php', 'phtml', 'vue'];
const EMMET_CSS = ['css', 'scss'];
const HTML_TAGS = new Set(('a abbr address area article aside audio b base bdi bdo blockquote body br button canvas caption cite code col colgroup data datalist dd del details dfn dialog div dl dt em embed fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 head header hgroup hr html i iframe img input ins kbd label legend li link main map mark menu meta meter nav noscript object ol optgroup option output p param picture pre progress q rp rt ruby s samp script section select slot small source span strong style sub summary sup table tbody td template textarea tfoot th thead time title tr track u ul var video wbr svg path').split(' '));

function loadEmmet() {
    if (emmet.mod) return Promise.resolve(emmet.mod);
    if (!emmet.loading) {
        emmet.loading = import(new URL('lib/emmet/emmet.js', location.href).href)
            .then(m => { emmet.mod = m; return m; })
            .catch(err => { emmet.loading = null; console.warn('Emmet', err); return null; });
    }
    return emmet.loading;
}

/** Tab: se prima del cursore c'è un'abbreviazione Emmet la espande (con i punti di inserimento), altrimenti false = Tab normale. */
function emmetExpand(ed) {
    const tab = tabFor(state.active);
    if (!APP.config.emmet || !emmet.mod || !tab || tab.kind !== 'code' || !ed.selection.isEmpty() || ed.inMultiSelectMode) return false;
    const ext = extOf(tab.path);
    const markup = EMMET_MARKUP.includes(ext), css = EMMET_CSS.includes(ext);
    if (!markup && !css) return false;
    const pos = ed.getCursorPosition();
    const doc = ed.session.getDocument();
    const before = doc.getTextRange({ start: { row: 0, column: 0 }, end: pos });
    if (ext === 'php' || ext === 'phtml') {
        // Dentro il codice PHP niente Emmet
        if (before.lastIndexOf('<?') > before.lastIndexOf('?>')) return false;
    }
    if (markup) {
        // Dentro <style>/<script> o dentro un tag (attributi) niente Emmet
        const lower = before.toLowerCase();
        if (lower.lastIndexOf('<style') > lower.lastIndexOf('</style') || lower.lastIndexOf('<script') > lower.lastIndexOf('</script')) return false;
    }
    const line = ed.session.getLine(pos.row);
    let found;
    try {
        found = emmet.mod.extract(line, pos.column, { type: css ? 'stylesheet' : 'markup', lookAhead: markup });
    } catch (e) { return false; }
    if (!found || !found.abbreviation) return false;
    const abbr = found.abbreviation;
    if (markup) {
        // Una parola qualunque non diventa un tag: solo tag HTML veri, "!" o abbreviazioni con operatori
        const word = /^[a-z][a-z0-9-]*$/i.test(abbr);
        if (word && !HTML_TAGS.has(abbr.toLowerCase())) return false;
        if (!word && abbr !== '!' && !/[.#>+*^\[\]{}():$@!]/.test(abbr)) return false;
    } else {
        // CSS: solo dentro un blocco { } e all'inizio di una dichiarazione
        const opens = (before.match(/\{/g) || []).length, closes = (before.match(/\}/g) || []).length;
        if (opens <= closes || line.slice(0, found.start).trim() !== '' || abbr.length > 30) return false;
    }
    let out;
    try {
        out = emmet.mod.default(abbr, {
            type: css ? 'stylesheet' : 'markup',
            syntax: css ? ext : 'html',
            options: {
                'output.field': (i, placeholder) => '${' + i + (placeholder ? ':' + placeholder.replace(/[$}\\]/g, '\\$&') : '') + '}',
                'output.text': (text) => text.replace(/[$}\\]/g, '\\$&'),
                'output.indent': ed.session.getTabString(),
            },
        });
    } catch (e) { return false; }
    if (!out || out === abbr) return false;
    const Range = ace.require('ace/range').Range;
    ed.session.remove(new Range(pos.row, found.start, pos.row, found.end));
    ed.moveCursorTo(pos.row, found.start);
    ace.require('ace/snippets').snippetManager.insertSnippet(ed, out);
    return true;
}

// ----------------------------------------------------------------------------
// MINIMAPPA a destra dell'editor: righe in miniatura con i colori della sintassi,
// riquadro della parte visibile; clic = va lì, trascinare il riquadro = scorre
// ----------------------------------------------------------------------------
const minimap = { raf: 0, mmScroll: 0, drag: null, colors: null, hover: false };
const MM_LINE = 2, MM_PAD = 6;

function minimapOn() {
    return !!APP.config.minimap && !IS_TOUCH && window.innerWidth > 760;
}

function scheduleMinimap() {
    if (minimap.raf) return;
    minimap.raf = requestAnimationFrame(() => { minimap.raf = 0; drawMinimap(); });
}

function minimapColors() {
    const cs = getComputedStyle(document.documentElement);
    const v = (n) => cs.getPropertyValue(n).trim();
    return {
        bg: v('--c-1e1e1e'), text: v('--c-d4d4d4'), comment: v('--c-6a9955'), string: v('--c-ce9178'), keyword: v('--c-569cd6'),
        control: v('--c-c586c0'), number: v('--c-b5cea8'), fn: v('--c-dcdcaa'), variable: v('--c-9cdcfe'), type: v('--c-4ec9b0'),
        slider: isLightTheme() ? 'rgba(0, 0, 0, 0.09)' : 'rgba(121, 121, 121, 0.2)',
        sliderHover: isLightTheme() ? 'rgba(0, 0, 0, 0.16)' : 'rgba(121, 121, 121, 0.35)',
    };
}

function tokenColor(type, c) {
    if (type.includes('comment')) return c.comment;
    if (type.includes('string')) return c.string;
    if (type.includes('keyword.control')) return c.control;
    if (type.includes('numeric')) return c.number;
    if (type.includes('entity.name.function') || type.includes('support.function')) return c.fn;
    if (type.includes('support.class') || type.includes('entity.name.type') || type.includes('support.type')) return c.type;
    if (type.includes('keyword') || type.includes('storage') || type.includes('constant.language') || type.includes('meta.tag') || type.includes('entity.name.tag')) return c.keyword;
    if (type.includes('variable') || type.includes('attribute-name')) return c.variable;
    return c.text;
}

/** Geometria: righe visibili, scorrimento della minimappa, posizione del riquadro. */
function minimapGeometry() {
    const canvas = $('#minimap');
    const H = canvas.clientHeight;
    const rows = editor.session.getLength();
    const first = editor.renderer.getFirstVisibleRow();
    const last = Math.min(rows - 1, editor.renderer.getLastVisibleRow());
    const visible = Math.max(1, last - first + 1);
    const contentH = rows * MM_LINE;
    const ratio = rows > visible ? first / (rows - visible) : 0;
    const mmScroll = contentH > H ? ratio * (contentH - H) : 0;
    return { H, rows, first, visible, contentH, mmScroll, sliderTop: first * MM_LINE - mmScroll, sliderH: Math.max(8, visible * MM_LINE) };
}

function drawMinimap() {
    const canvas = $('#minimap');
    const tab = tabFor(state.active);
    const show = minimapOn() && editor && tab && tab.kind === 'code' && editor.session === tab.session;
    if (canvas.hidden === !!show) { canvas.hidden = !show; if (editor) editor.resize(); }
    if (!show) return;
    const dpr = window.devicePixelRatio || 1;
    const W = canvas.clientWidth, H = canvas.clientHeight;
    if (!W || !H) return;
    if (canvas.width !== Math.round(W * dpr) || canvas.height !== Math.round(H * dpr)) { canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr); }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const c = minimap.colors || (minimap.colors = minimapColors());
    ctx.fillStyle = c.bg;
    ctx.fillRect(0, 0, W, H);
    const g = minimapGeometry();
    minimap.mmScroll = g.mmScroll;
    const session = editor.session;
    const tabSize = session.getTabSize();
    const from = Math.floor(g.mmScroll / MM_LINE), to = Math.min(g.rows, from + Math.ceil(H / MM_LINE) + 1);
    ctx.globalAlpha = 0.85;
    for (let row = from; row < to; row++) {
        const y = row * MM_LINE - g.mmScroll;
        let x = MM_PAD;
        for (const tok of session.getTokens(row)) {
            const color = tokenColor(tok.type || '', c);
            for (const part of tok.value.split(/(\s+)/)) {
                if (!part) continue;
                const len = part.replace(/\t/g, ' '.repeat(tabSize)).length;
                if (!/^\s+$/.test(part)) { ctx.fillStyle = color; ctx.fillRect(x, y, Math.min(len, W - x), MM_LINE - 0.6); }
                x += len;
                if (x >= W) break;
            }
            if (x >= W) break;
        }
    }
    ctx.globalAlpha = 1;
    ctx.fillStyle = minimap.hover || minimap.drag ? c.sliderHover : c.slider;
    ctx.fillRect(0, g.sliderTop, W, g.sliderH);
}

function initMinimap() {
    const canvas = $('#minimap');
    if (!editor || !canvas) return;
    editor.on('change', scheduleMinimap);
    editor.on('changeSession', scheduleMinimap);
    editor.renderer.on('afterRender', scheduleMinimap);
    editor.on('changeSession', (e) => { if (e.session) e.session.on('tokenizerUpdate', scheduleMinimap); });
    window.addEventListener('resize', scheduleMinimap);
    themeMedia.addEventListener && themeMedia.addEventListener('change', () => { minimap.colors = null; applyTheme(); });
    const rowAt = (y) => Math.max(0, Math.min(editor.session.getLength() - 1, Math.floor((y + minimap.mmScroll) / MM_LINE)));
    canvas.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const y = e.offsetY;
        const g = minimapGeometry();
        if (y >= g.sliderTop && y <= g.sliderTop + g.sliderH) {
            // Trascinamento del riquadro: come la barra di scorrimento
            minimap.drag = { y: e.clientY, first: g.first, scale: g.contentH > g.H ? (g.rows - g.visible) / Math.max(1, g.H - g.sliderH) : 1 / MM_LINE };
        } else {
            editor.scrollToLine(rowAt(y), true, false, () => {});
            minimap.drag = { y: e.clientY, first: editor.renderer.getFirstVisibleRow(), scale: g.contentH > g.H ? (g.rows - g.visible) / Math.max(1, g.H - g.sliderH) : 1 / MM_LINE };
        }
        const move = (ev) => {
            const d = minimap.drag;
            if (!d) return;
            editor.renderer.scrollToRow(Math.max(0, Math.round(d.first + (ev.clientY - d.y) * d.scale)));
        };
        const up = () => { minimap.drag = null; scheduleMinimap(); window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
        scheduleMinimap();
    });
    canvas.addEventListener('mousemove', (e) => {
        const g = minimapGeometry();
        const on = e.offsetY >= g.sliderTop && e.offsetY <= g.sliderTop + g.sliderH;
        if (on !== minimap.hover) { minimap.hover = on; scheduleMinimap(); }
    });
    canvas.addEventListener('mouseleave', () => { minimap.hover = false; scheduleMinimap(); });
    canvas.addEventListener('wheel', (e) => { e.preventDefault(); editor.session.setScrollTop(editor.session.getScrollTop() + e.deltaY); }, { passive: false });
}

// ----------------------------------------------------------------------------
// VAI AL SIMBOLO (⇧⌘O / Ctrl+Shift+O): funzioni, classi, metodi, costanti, selettori CSS, titoli, id…
// Riconoscimento per righe (veloce, senza analizzatori): basta per orientarsi nel file.
// ----------------------------------------------------------------------------
const symbolView = { items: [], list: [], idx: 0 };

/** Simboli del testo: [{name, kind, row, col}] nell'ordine del file. */
function getSymbols(path, text) {
    const ext = extOf(path);
    const lines = text.split('\n');
    const out = [];
    const add = (name, kind, row, col) => { if (name) out.push({ name: name.trim().replace(/\s+/g, ' ').slice(0, 100), kind, row, col: Math.max(0, col) }); };
    const JS_SKIP = new Set(['if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'else', 'do', 'try', 'with', 'new', 'typeof']);
    const markup = ['html', 'htm', 'vue', 'php', 'phtml'].includes(ext);
    let depth = 0, classDepth = -1;
    lines.forEach((ln, row) => {
        let m;
        if (ext === 'php' || ext === 'phtml') {
            if ((m = ln.match(/^\s*(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_]\w*)/))) { add(m[2], m[1] === 'class' ? 'class' : m[1], row, ln.indexOf(m[2])); classDepth = depth; }
            else if ((m = ln.match(/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+&?\s*([A-Za-z_]\w*)\s*\(/))) add(m[1], classDepth >= 0 && depth > classDepth ? 'method' : 'function', row, ln.indexOf(m[1]));
            else if ((m = ln.match(/^\s*(?:(?:public|protected|private|final)\s+)*const\s+([A-Za-z_]\w*)\s*=/))) add(m[1], 'const', row, ln.indexOf(m[1]));
            else if ((m = ln.match(/\bdefine\(\s*['"]([A-Za-z_]\w*)['"]/))) add(m[1], 'const', row, ln.indexOf(m[1]));
        } else if (['js', 'mjs', 'jsx', 'ts', 'tsx', 'vue'].includes(ext)) {
            if ((m = ln.match(/^\s*(?:export\s+)?(?:default\s+)?(?:abstract\s+)?class\s+([A-Za-z_$][\w$]*)/))) { add(m[1], 'class', row, ln.indexOf(m[1])); classDepth = depth; }
            else if ((m = ln.match(/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)\s*\(/))) add(m[1], 'function', row, ln.indexOf(m[1]));
            else if ((m = ln.match(/^\s*(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/))) add(m[1], 'function', row, ln.indexOf(m[1]));
            else if (classDepth >= 0 && depth === classDepth + 1 && (m = ln.match(/^\s*(?:static\s+|async\s+|get\s+|set\s+|public\s+|private\s+|protected\s+)*([A-Za-z_$#][\w$]*)\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/)) && !JS_SKIP.has(m[1])) add(m[1], 'method', row, ln.indexOf(m[1]));
            else if ((m = ln.match(/^\s*(?:export\s+)?(?:interface|type)\s+([A-Za-z_$][\w$]*)/))) add(m[1], 'type', row, ln.indexOf(m[1]));
        } else if (ext === 'css' || ext === 'scss') {
            if ((m = ln.match(/^\s*(@(?:media|supports|keyframes|font-face|layer|container|mixin|include)[^{;]*)\{/))) add(m[1], '@', row, ln.indexOf('@'));
            else if ((m = ln.match(/^\s*([^{};@\s/][^{};]*?)\s*\{/))) add(m[1], 'selector', row, ln.search(/\S/));
        } else if (ext === 'md') {
            if ((m = ln.match(/^(#{1,6})\s+(.*)$/))) add(m[2], 'h' + m[1].length, row, 0);
        } else if (ext === 'sql') {
            if ((m = ln.match(/create\s+(?:or\s+replace\s+)?(table|view|index|procedure|function|trigger)\s+(?:if\s+not\s+exists\s+)?[`"[]?([\w.]+)/i))) add(m[2], m[1].toLowerCase(), row, ln.indexOf(m[2]));
        } else if (ext === 'yml' || ext === 'yaml') {
            if ((m = ln.match(/^([A-Za-z_][\w.-]*)\s*:/))) add(m[1], 'key', row, 0);
        }
        if (markup) {
            const re = /<h([1-6])\b[^>]*>(.*?)<\/h\1>/gi;
            while ((m = re.exec(ln))) add(m[2].replace(/<[^>]*>/g, '').replace(/<\?.*?\?>/g, '…'), 'h' + m[1], row, m.index);
            const reId = /<([a-z][\w-]*)\b[^>]*?\bid=["']([^"'<>]+)["']/gi;
            while ((m = reId.exec(ln))) add(m[1].toLowerCase() + '#' + m[2], 'id', row, m.index);
        }
        // Profondità delle graffe (approssimata) per distinguere metodi e funzioni
        for (const ch of ln.replace(/(["'`])(?:\\.|(?!\1).)*\1/g, '')) {
            if (ch === '{') depth++;
            else if (ch === '}') { depth = Math.max(0, depth - 1); if (depth <= classDepth) classDepth = -1; }
        }
    });
    return out;
}

const SYMBOL_BADGE = { function: 'ƒ', method: 'm', class: 'C', interface: 'I', trait: 'T', enum: 'E', type: 'T', const: 'K',
    selector: '{}', '@': '@', id: '#', key: 'k', table: 'tbl', view: 'v', index: 'idx', procedure: 'p', trigger: 't' };

function openSymbols() {
    const tab = tabFor(state.active);
    if (!tab || tab.kind !== 'code' || !tab.session) return;
    symbolView.items = getSymbols(tab.path, tab.session.getValue());
    const row = editor ? editor.getCursorPosition().row : 0;
    let near = 0;
    symbolView.items.forEach((s, i) => { if (s.row <= row) near = i; });
    symbolView.idx = near;
    $('#symInput').value = '';
    renderSymbols();
    showModal($('#symbolDialog'));
    focusSoft($('#symInput'));
}

function renderSymbols() {
    const q = $('#symInput').value.trim().toLowerCase().replace(/\s+/g, '');
    let list = symbolView.items.map(s => ({ s, hits: [], score: 0 }));
    if (q) {
        list = [];
        symbolView.items.forEach(s => { const r = fuzzyScore(s.name, q); if (r) list.push({ s, hits: r.hits, score: r.score }); });
        list.sort((a, b) => b.score - a.score || a.s.row - b.s.row);
    }
    symbolView.list = list;
    symbolView.idx = Math.min(symbolView.idx, Math.max(0, list.length - 1));
    const mark = (name, hits) => { const set = new Set(hits); return [...name].map((ch, i) => set.has(i) ? `<mark>${esc(ch)}</mark>` : esc(ch)).join(''); };
    $('#symList').innerHTML = list.length
        ? list.map((it, i) => `<div class="qo-item sym-item${i === symbolView.idx ? ' active' : ''}" data-i="${i}">`
            + `<span class="sym-kind k-${esc(it.s.kind.replace(/[^a-z0-9@]/gi, ''))}">${esc(SYMBOL_BADGE[it.s.kind] || it.s.kind)}</span>`
            + `<span class="qo-name">${mark(it.s.name, it.hits)}</span><span class="qo-dir">${esc(L('sym.line', { n: it.s.row + 1 }))}</span></div>`).join('')
        : `<div class="log-empty">${esc(L(symbolView.items.length ? 'qo.none' : 'sym.none'))}</div>`;
    const act = $('#symList .qo-item.active');
    if (act) act.scrollIntoView({ block: 'nearest' });
}

function pickSymbol(i) {
    const it = symbolView.list[i];
    if (!it) return;
    $('#symbolDialog').close();
    gotoInEditor(state.active, it.s.row + 1, it.s.col, 0);
}

function initSymbols() {
    const input = $('#symInput');
    input.addEventListener('input', () => { symbolView.idx = 0; renderSymbols(); });
    input.addEventListener('keydown', (e) => {
        const n = symbolView.list.length;
        if (e.key === 'ArrowDown' && n) { e.preventDefault(); symbolView.idx = (symbolView.idx + 1) % n; renderSymbols(); }
        else if (e.key === 'ArrowUp' && n) { e.preventDefault(); symbolView.idx = (symbolView.idx - 1 + n) % n; renderSymbols(); }
        else if (e.key === 'Enter') { e.preventDefault(); pickSymbol(symbolView.idx); }
    });
    $('#symList').addEventListener('click', (e) => { const r = e.target.closest('.qo-item'); if (r) pickSymbol(+r.dataset.i); });
}

/** Comandi dell'editor (anche con il focus fuori da Ace), etichette dei tasti per Mac / Windows-Linux. */
function initEditorTools() {
    if (editor) {
        editor.commands.addCommand({ name: 'formatDocument', bindKey: { win: 'Shift-Alt-F', mac: 'Shift-Alt-F' }, exec: () => { formatTab(tabFor(state.active)); } });
        editor.commands.addCommand({ name: 'goToSymbol', bindKey: { win: 'Ctrl-Shift-O', mac: 'Command-Shift-O' }, exec: () => openSymbols() });
        editor.commands.addCommand({ name: 'emmetExpand', bindKey: { win: 'Tab', mac: 'Tab' }, exec: (ed) => emmetExpand(ed) });
        // Con la tendina dei suggerimenti aperta Tab sceglierebbe il suggerimento: un'abbreviazione con operatori
        // (ul>li, .box, p*3…) ha la precedenza, la tendina si chiude
        editor.container.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab' || e.shiftKey || e.altKey || e.metaKey || e.ctrlKey) return;
            const popup = editor.completer && editor.completer.activated;
            if (!popup) return;
            const line = editor.session.getLine(editor.getCursorPosition().row).slice(0, editor.getCursorPosition().column);
            if (!/[.#>+*^\[\]{}():$@!]\S*$/.test(line.trim().split(/\s+/).pop() || '')) return;
            if (emmetExpand(editor)) { e.preventDefault(); e.stopPropagation(); editor.completer.detach(); }
        }, true);
        applyTheme();
        initMinimap();
    }
    $('#formatBtn kbd').textContent = IS_MAC ? '⇧⌥F' : 'Shift+Alt+F';
    $('#symbolsBtn kbd').textContent = IS_MAC ? '⇧⌘O' : 'Ctrl+Shift+O';
    document.addEventListener('keydown', (e) => {
        if ($$('dialog[open]').length) return;
        const mod = e.metaKey || e.ctrlKey;
        if (e.shiftKey && e.altKey && !mod && e.code === 'KeyF') { e.preventDefault(); formatTab(tabFor(state.active)); }
        else if (mod && e.shiftKey && !e.altKey && e.code === 'KeyO') { e.preventDefault(); openSymbols(); }
    });
    initSymbols();
}

// ----------------------------------------------------------------------------
// VAI AL FILE (⌘P / Ctrl+P): ricerca "fuzzy" sui percorsi, frecce + Invio
// ----------------------------------------------------------------------------
const quickOpen = { items: [], idx: 0, recent: loadGlobal('qorecent', []) };

/** Punteggio di q dentro il percorso (lettere in ordine, non per forza vicine), o -1. Premia nome del file, inizio parola e lettere consecutive. */
function fuzzyScore(path, q) {
    const p = path.toLowerCase();
    const nameStart = p.lastIndexOf('/') + 1;
    let score = 0, pi = 0, prev = -2;
    const hits = [];
    for (const ch of q) {
        const at = p.indexOf(ch, pi);
        if (at < 0) return null;
        let pts = 1;
        if (at === prev + 1) pts += 4;
        if (at >= nameStart) pts += 2;
        if (at === 0 || '/._- '.includes(p[at - 1])) pts += 3;
        score += pts;
        hits.push(at);
        prev = at;
        pi = at + 1;
    }
    if (p.slice(nameStart).startsWith(q)) score += 15;
    return { score: score - path.length * 0.01, hits };
}

function openQuickOpen() {
    const dlg = $('#quickOpenDialog');
    if (dlg.open) return;
    $('#qoInput').value = '';
    quickOpen.idx = 0;
    renderQuickOpen();
    showModal(dlg);
    focusSoft($('#qoInput'));
}

function renderQuickOpen() {
    const q = $('#qoInput').value.trim().toLowerCase().replace(/\s+/g, '');
    let list;
    if (!q) {
        // Senza testo: file aperti di recente, poi le schede aperte
        const open = state.tabs.filter(t => t.kind !== 'http' && t.kind !== 'diff').map(t => t.path);
        list = [...new Set(quickOpen.recent.concat(open))].filter(p => state.files.includes(p)).slice(0, 30).map(p => ({ path: p, hits: [] }));
    } else {
        list = [];
        for (const f of state.files) {
            const r = fuzzyScore(f, q);
            if (r) list.push({ path: f, hits: r.hits, score: r.score });
        }
        list.sort((a, b) => b.score - a.score || a.path.localeCompare(b.path));
        list = list.slice(0, 60);
    }
    quickOpen.items = list;
    quickOpen.idx = Math.min(quickOpen.idx, Math.max(0, list.length - 1));
    const mark = (path, hits) => {
        const set = new Set(hits);
        return [...path].map((c, i) => set.has(i) ? `<mark>${esc(c)}</mark>` : esc(c)).join('');
    };
    $('#qoList').innerHTML = list.length
        ? list.map((it, i) => {
            const name = baseName(it.path), dir = parentOf(it.path), off = dir ? dir.length + 1 : 0;
            return `<div class="qo-item${i === quickOpen.idx ? ' active' : ''}" data-i="${i}">${fileIcon(it.path)}`
                + `<span class="qo-name">${mark(name, it.hits.filter(h => h >= off).map(h => h - off))}</span>`
                + `<span class="qo-dir">${mark(dir, it.hits.filter(h => h < dir.length))}</span></div>`;
        }).join('')
        : `<div class="log-empty">${esc(L(q ? 'qo.none' : 'qo.hint'))}</div>`;
    const act = $('#qoList .qo-item.active');
    if (act) act.scrollIntoView({ block: 'nearest' });
}

function quickOpenPick(i) {
    const it = quickOpen.items[i];
    if (!it) return;
    $('#quickOpenDialog').close();
    quickOpen.recent = [it.path].concat(quickOpen.recent.filter(p => p !== it.path)).slice(0, 15);
    storeGlobal('qorecent', quickOpen.recent);
    revealInTree(it.path);
    openFile(it.path);
}

function initQuickOpen() {
    const input = $('#qoInput');
    input.addEventListener('input', () => { quickOpen.idx = 0; renderQuickOpen(); });
    input.addEventListener('keydown', (e) => {
        const n = quickOpen.items.length;
        if (e.key === 'ArrowDown' && n) { e.preventDefault(); quickOpen.idx = (quickOpen.idx + 1) % n; renderQuickOpen(); }
        else if (e.key === 'ArrowUp' && n) { e.preventDefault(); quickOpen.idx = (quickOpen.idx - 1 + n) % n; renderQuickOpen(); }
        else if (e.key === 'Enter') { e.preventDefault(); quickOpenPick(quickOpen.idx); }
    });
    $('#qoList').addEventListener('click', (e) => {
        const row = e.target.closest('.qo-item');
        if (row) quickOpenPick(+row.dataset.i);
    });
}

// ----------------------------------------------------------------------------
// CONTROLLO DELLA SINTASSI PHP: riga segnata in rosso, avviso con "Salva comunque"
// ----------------------------------------------------------------------------
const isPhpPath = (p) => ['php', 'phtml'].includes(extOf(p));

function setLintMarks(tab, lint) {
    if (!tab || !tab.session) return;
    if (tab.lintMarker) { tab.session.removeMarker(tab.lintMarker); tab.lintMarker = null; }
    if (lint && lint.line) {
        const row = Math.max(0, Math.min(lint.line - 1, tab.session.getLength() - 1));
        tab.session.setAnnotations([{ row, column: 0, text: `${lint.message} (PHP ${lint.php}, ${lint.method})`, type: 'error' }]);
        const Range = ace.require('ace/range').Range;
        tab.lintMarker = tab.session.addMarker(new Range(row, 0, row, 1), 'lint-line', 'fullLine');
    } else {
        tab.session.clearAnnotations();
    }
}

function showLintNotice(tab, lint, serverPhp) {
    const n = $('#fileNotice');
    n.hidden = false;
    n.className = 'notice error lint-notice';
    n.dataset.lint = '1';
    const other = serverPhp && serverPhp !== lint.php ? ' ' + esc(L('lint.site_php', { v: serverPhp })) : '';
    n.innerHTML = `<div>${ic('alert')} <b>${esc(L('api.syntax_error_line', { line: +lint.line }))}</b>: ${esc(lint.message)}<br>`
        + `<small>${esc(L('lint.checked_with', { v: lint.php, method: lint.method }))}${other} ${esc(L('lint.consequence'))}</small></div>`
        + `<div class="hrow"><button type="button" class="sbtn" data-lint="goto">${esc(L('lint.goto'))}</button>`
        + `<button type="button" class="sbtn" data-lint="cancel">${esc(L('common.cancel'))}</button>`
        + `<button type="button" class="pbtn warn" data-lint="force">${esc(L('lint.save_anyway'))}</button></div>`;
    n.onclick = (e) => {
        const b = e.target.closest('[data-lint]');
        if (!b) return;
        if (b.dataset.lint === 'goto' && editor) { editor.gotoLine(lint.line, 0, true); editor.scrollToLine(lint.line - 1, true, true, () => {}); focusSoft(editor); }
        if (b.dataset.lint === 'cancel') { tab.saveBlocked = null; hideLintNotice(); updateSaveStatus(tab); }
        if (b.dataset.lint === 'force') saveActive({ force: true });
    };
}

function hideLintNotice() {
    const n = $('#fileNotice');
    if (n.dataset.lint) { n.hidden = true; n.className = 'notice error'; delete n.dataset.lint; n.onclick = null; }
}

/** Controllo a pausa di digitazione: solo annotazioni, non blocca nulla. */
function scheduleLiveLint(tab, delay = 1000) {
    if (!tab.session || !isPhpPath(tab.path) || !APP.config.php_lint || !APP.config.php_lint_live) return;
    clearTimeout(tab.lintTimer);
    tab.lintTimer = setTimeout(() => {
        const code = tab.session.getValue();
        if (code.length > 500000) return;
        apiPost({ action: 'php_lint', path: tab.path, code }).then(res => {
            if (!res.ok || !tab.session || tab.session.getValue() !== code) return;
            setLintMarks(tab, res.lint);
            if (!res.lint && tab.saveBlocked) { tab.saveBlocked = null; if (tab.path === state.active) hideLintNotice(); }
        }).catch(() => {});
    }, delay);
}

/** Porta il cursore su riga/colonna (da 1 e da 0) e seleziona "len" caratteri. */
function gotoInEditor(path, line, col = 0, len = 0) {
    return openFile(path).then(tab => {
        if (!tab || tab.kind !== 'code' || !editor) return tab;
        activateTab(path);
        const Range = ace.require('ace/range').Range;
        const row = Math.max(0, line - 1);
        editor.selection.setRange(new Range(row, col, row, col + len));
        editor.scrollToLine(row, true, true, () => {});
        if (!IS_TOUCH) editor.focus();
        return tab;
    });
}

// ----------------------------------------------------------------------------
// CERCA E SOSTITUISCI NEI FILE (⌘⇧F): pannello al posto dell'Explorer
// ----------------------------------------------------------------------------
const search = {
    opts: Object.assign({ case: false, word: false, regex: false, include: '', exclude: '', repl: false, more: false }, loadGlobal('search', {})),
    data: null,       // ultima risposta di "search"
    seq: 0,
    timer: null,
    collapsed: new Set(),
};

function initSearch() {
    const q = $('#sfQuery'), r = $('#sfReplace');
    $('#searchOpen').addEventListener('click', () => openSearch(false));
    $('#sfClose').addEventListener('click', closeSearch);
    $('#sfRefresh').addEventListener('click', () => runSearch());
    $('#sfCollapse').addEventListener('click', () => {
        const files = (search.data && search.data.files || []).map(f => f.path);
        if (search.collapsed.size < files.length) files.forEach(p => search.collapsed.add(p)); else search.collapsed.clear();
        renderSearch();
    });
    $$('#searchCard [data-opt]').forEach(b => {
        b.classList.toggle('on', !!search.opts[b.dataset.opt]);
        b.addEventListener('click', () => {
            search.opts[b.dataset.opt] = !search.opts[b.dataset.opt];
            b.classList.toggle('on', search.opts[b.dataset.opt]);
            saveSearchOpts();
            runSearch();
        });
    });
    const setRepl = (on) => {
        search.opts.repl = on;
        $('#sfReplRow').hidden = !on;
        $('#sfReplToggle').classList.toggle('open', on);
        saveSearchOpts();
    };
    setRepl(!!search.opts.repl);
    $('#sfReplToggle').addEventListener('click', () => { setRepl(!search.opts.repl); runSearch(); if (search.opts.repl) focusSoft(r); });
    const setMore = (on) => { search.opts.more = on; $('#sfMore').hidden = !on; saveSearchOpts(); };
    setMore(!!search.opts.more);
    $('#sfMoreToggle').addEventListener('click', () => setMore(!search.opts.more));
    $('#sfInclude').value = search.opts.include;
    $('#sfExclude').value = search.opts.exclude;

    const later = () => { clearTimeout(search.timer); search.timer = setTimeout(runSearch, 350); };
    q.addEventListener('input', later);
    r.addEventListener('input', later);
    ['sfInclude', 'sfExclude'].forEach(id => $('#' + id).addEventListener('input', (e) => {
        search.opts[id === 'sfInclude' ? 'include' : 'exclude'] = e.target.value;
        saveSearchOpts();
        later();
    }));
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
    r.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); replaceAllResults(); } });
    $('#sfReplaceAll').addEventListener('click', replaceAllResults);

    $('#sfResults').addEventListener('click', (e) => {
        const fileEl = e.target.closest('[data-sf-file]');
        if (!fileEl) return;
        const path = fileEl.dataset.sfFile;
        const act = e.target.closest('[data-act]');
        const matchEl = e.target.closest('[data-sf-i]');
        const f = search.data.files.find(x => x.path === path);
        if (!f) return;
        if (act) {
            e.stopPropagation();
            const i = matchEl ? +matchEl.dataset.sfI : -1;
            if (act.dataset.act === 'dismiss-file') { search.data.files = search.data.files.filter(x => x !== f); renderSearch(); }
            if (act.dataset.act === 'dismiss-one') { f.matches.splice(i, 1); if (!f.matches.length) search.data.files = search.data.files.filter(x => x !== f); renderSearch(); }
            if (act.dataset.act === 'replace-file') replaceResults([{ path, only: f.matches }], L('sf.in_file', { name: baseName(path) }));
            if (act.dataset.act === 'replace-one') replaceResults([{ path, only: [f.matches[i]] }], null);
            return;
        }
        if (matchEl) {
            const m = f.matches[+matchEl.dataset.sfI];
            $$('#sfResults .sf-match.cur').forEach(x => x.classList.remove('cur'));
            matchEl.classList.add('cur');
            gotoInEditor(path, m.line, m.col, m.len);
            return;
        }
        search.collapsed.has(path) ? search.collapsed.delete(path) : search.collapsed.add(path);
        renderSearch();
    });
}

function saveSearchOpts() { storeGlobal('search', search.opts); }

/** Apre il pannello; con fromKeyboard il testo selezionato nell'editor diventa la ricerca. */
function openSearch(fromKeyboard) {
    const q = $('#sfQuery');
    if (fromKeyboard && editor && state.active && !$('#editorWrap').hidden) {
        const sel = editor.getSelectedText();
        if (sel && !sel.includes('\n') && sel.length < 200) q.value = sel;
    }
    if ($('#searchCard').hidden) {
        $('#explorerCard').hidden = true;
        $('#searchCard').hidden = false;
        anim.pop($('#searchCard'));
    }
    if (window.matchMedia('(max-width: 900px)').matches) $('#leftCol').scrollIntoView({ block: 'start' });
    focusSoft(q);
    q.select();
    if (q.value.trim()) runSearch();
}

function closeSearch() {
    $('#searchCard').hidden = true;
    $('#explorerCard').hidden = false;
}

function searchParams() {
    const p = { q: $('#sfQuery').value, include: search.opts.include, exclude: search.opts.exclude };
    ['case', 'word', 'regex'].forEach(k => { if (search.opts[k]) p[k] = '1'; });
    if (search.opts.repl) p.replace = $('#sfReplace').value;
    return p;
}

function runSearch() {
    clearTimeout(search.timer);
    const params = searchParams();
    const seq = ++search.seq;
    if (!params.q) { search.data = null; renderSearch(); return Promise.resolve(); }
    $('#sfSummary').innerHTML = `<span class="muted">${esc(L('sf.searching'))}</span>`;
    return apiGet('search', params).then(res => {
        if (seq !== search.seq) return;
        if (!res.ok) { search.data = null; $('#sfResults').innerHTML = ''; $('#sfSummary').innerHTML = `<span class="sf-err">${esc(res.error)}</span>`; return; }
        search.data = res;
        renderSearch();
    }).catch(err => { if (seq === search.seq) $('#sfSummary').innerHTML = `<span class="sf-err">${esc(L('js.network_error') + ': ' + String(err))}</span>`; });
}

function renderSearch() {
    const box = $('#sfResults'), sum = $('#sfSummary');
    const d = search.data;
    if (!d) { box.innerHTML = ''; sum.innerHTML = ''; return; }
    const total = d.files.reduce((s, f) => s + f.matches.length, 0);
    const notes = [];
    if (d.truncated) notes.push(`<div class="sf-warn">${ic('alert')} ${esc(L('sf.truncated'))}</div>`);
    if (d.timeout) notes.push(`<div class="sf-warn">${ic('alert')} ${esc(L('sf.timeout'))}</div>`);
    sum.innerHTML = esc(total ? L(total === 1 ? 'sf.one_result' : 'sf.n_results', { n: total, files: d.files.length }) : L('js.no_results'))
        + `<span class="muted"> · ${esc(L('sf.n_read', { n: d.scanned }))}${d.skipped ? ', ' + esc(L('sf.n_skipped', { n: d.skipped })) : ''}</span>` + notes.join('');
    const repl = search.opts.repl;
    box.innerHTML = d.files.map(f => {
        const open = !search.collapsed.has(f.path);
        const dir = parentOf(f.path);
        return `<div class="sf-file${open ? ' open' : ''}" data-sf-file="${esc(f.path)}">`
            + `<div class="sf-file-row" title="${esc(f.path)}">${CHEVRON}${fileIcon(f.path)}<span class="sf-name">${esc(baseName(f.path))}</span>`
            + `<span class="sf-dir">${esc(dir)}</span><span class="sf-count">${f.matches.length}</span><span class="sf-acts">`
            + (repl ? `<button type="button" class="icon-btn" data-act="replace-file" title="${esc(L('sf.replace_file'))}">${ic('replace')}</button>` : '')
            + `<button type="button" class="icon-btn" data-act="dismiss-file" title="${esc(L('sf.dismiss'))}">${ic('x')}</button></span></div>`
            + (open ? f.matches.map((m, i) => `<div class="sf-match" data-sf-i="${i}" title="${esc(L('sf.line_col', { line: m.line, col: m.col + 1 }))}">`
                + `<span class="sf-text">${esc(m.before)}<mark${repl ? ' class="del"' : ''}>${esc(m.match)}</mark>${repl ? `<ins>${esc(m.repl ?? '')}</ins>` : ''}${esc(m.after)}</span>`
                + `<span class="sf-ln">${m.line}</span><span class="sf-acts">`
                + (repl ? `<button type="button" class="icon-btn" data-act="replace-one" title="${esc(L('sf.replace'))}">${ic('replace')}</button>` : '')
                + `<button type="button" class="icon-btn" data-act="dismiss-one" title="${esc(L('sf.dismiss'))}">${ic('x')}</button></span></div>`).join('') : '')
            + '</div>';
    }).join('');
    $('#sfReplaceAll').disabled = !total;
}

function replaceAllResults() {
    const d = search.data;
    if (!search.opts.repl || !d || !d.files.length) return;
    const total = d.files.reduce((s, f) => s + f.matches.length, 0);
    replaceResults(d.files.map(f => ({ path: f.path, only: f.matches })), L('sf.everywhere', { n: total, files: d.files.length }));
}

/** Sostituisce le occorrenze indicate. I file aperti con modifiche non salvate vengono saltati. */
function replaceResults(targets, confirmWhat) {
    const replace = $('#sfReplace').value;
    const dirty = targets.filter(t => isDirty(tabFor(t.path)));
    targets = targets.filter(t => !dirty.includes(t));
    if (dirty.length) toast(L('sf.skip_dirty', { names: dirty.map(t => baseName(t.path)).join(', ') }), 'warn');
    if (!targets.length) return;
    if (confirmWhat && !confirm(L('sf.replace_q', { q: $('#sfQuery').value, r: replace, where: confirmWhat }))) return;
    const opts = { q: $('#sfQuery').value, case: search.opts.case, word: search.opts.word, regex: search.opts.regex };
    const send = (force) => apiPost({
        action: 'replace', opts, replace, force: force ? '1' : '0',
        targets: targets.map(t => ({ path: t.path, only: t.only.map(m => ({ line: m.line, col: m.col })) })),
    }).then(res => {
        (res.files || []).forEach(f => reloadTabFromDisk(f.path));
        if (res.total) toast(L(res.total === 1 ? 'sf.replaced_one' : 'sf.replaced_n', { n: res.total, files: res.files.length }));
        if (res.errors && res.errors.length) toast(res.errors.join('\n'), 'error');
        refreshSide();
        runSearch();
    }).catch(err => toast(L('js.network_error') + ': ' + err, 'error'));
    send(false);
}

/** Aggiorna una scheda aperta dopo una modifica fatta sul server (se non ha modifiche in sospeso). */
function reloadTabFromDisk(path) {
    const tab = tabFor(path);
    if (!tab || tab.kind !== 'code' || isDirty(tab)) return Promise.resolve();
    return apiGet('get_file', { file: path }).then(f => {
        if (!f.ok || isDirty(tab)) return;
        const pos = tab === tabFor(state.active) && editor ? editor.getCursorPosition() : null;
        setTabFromDisk(tab, f);
        if (pos) editor.moveCursorToPosition(pos);
        if (tab.path === state.active) { updateTopbar(); loadVersions(tab.path); }
    });
}

// ----------------------------------------------------------------------------
// ERRORI PHP DEL SITO: card a destra, raggruppati, clic = apre il file alla riga
// ----------------------------------------------------------------------------
const errView = { source: loadGlobal('errsource', null), kind: '', groups: [], sources: [], site: null, total: 0, timer: null, seq: 0 };
const ERR_LABEL = { fatal: 'Fatal', parse: 'Parse', warning: 'Warning', notice: 'Notice', deprecated: 'Deprecated', other: 'Other' };

function initErrors() {
    $('#errRefresh').addEventListener('click', (e) => { anim.press(e.currentTarget); loadErrors(true); });
    $('#errSource').addEventListener('change', (e) => { errView.source = e.target.value; storeGlobal('errsource', errView.source); loadErrors(); });
    $$('#errFilter button').forEach(b => b.addEventListener('click', () => {
        errView.kind = b.dataset.kind;
        $$('#errFilter button').forEach(x => x.classList.toggle('on', x === b));
        renderErrors();
    }));
    const auto = $('#errAuto');
    auto.checked = !!loadGlobal('errauto', false);
    const setAuto = () => {
        clearInterval(errView.timer);
        storeGlobal('errauto', auto.checked);
        if (auto.checked) errView.timer = setInterval(() => { if (!document.hidden) loadErrors(); }, 10000);
    };
    auto.addEventListener('change', setAuto);
    setAuto();

    // Menu della card
    const btn = $('#errMenuBtn'), menu = $('#errMenu');
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!menu.hidden) { menu.hidden = true; return; }
        const src = errView.sources.find(s => s.id === errView.source);
        $('[data-err="clear"]', menu).disabled = !(src && src.in_root && src.writable);
        const site = errView.site || {};
        $('#errSiteLabel').innerHTML = site.on
            ? `${esc(L('err.site_off'))}<small>${esc(L('err.site_off_hint'))}</small>`
            : `${esc(L('err.site_on'))}<small>${esc(L(site.works === false ? 'err.site_modphp' : 'err.site_on_hint'))}</small>`;
        menu.hidden = false;
        const r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.left = Math.max(8, Math.min(r.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        anim.pop(menu);
    });
    document.addEventListener('click', (e) => { if (!menu.hidden && !menu.contains(e.target)) menu.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') menu.hidden = true; });
    $$('[data-err]', menu).forEach(b => b.addEventListener('click', () => {
        menu.hidden = true;
        const src = errView.sources.find(s => s.id === errView.source);
        if (b.dataset.err === 'clear' && src) {
            if (!confirm(L('err.clear_q', { name: src.display }))) return;
            apiPost({ action: 'error_log_clear', source: src.id }).then(res => {
                if (!res.ok) { toast(res.error, 'error'); return; }
                toast(L('err.cleared'));
                loadErrors();
                refreshSide();
            });
        }
        if (b.dataset.err === 'site') {
            const on = !(errView.site && errView.site.on);
            const msg = on
                ? L('err.site_on_q', { ttl: errView.site ? errView.site.ttl : 300 })
                : L('err.site_off_q');
            if (!confirm(msg)) return;
            apiPost({ action: 'error_log_site', on: on ? '1' : '0' }).then(res => {
                if (!res.ok) { toast(res.error, 'error'); return; }
                toast(on ? L('log.site_log_on') : L('log.site_log_off'), on && res.site_log.works === false ? 'warn' : 'ok');
                if (on && res.site_log.works === false) toast(L('err.site_modphp_warn'), 'warn');
                refreshAll();
                loadErrors();
            });
        }
        if (b.dataset.err === 'path') {
            $('#settingsBtn').click();
            setTimeout(() => { const f = $('#settingsForm').error_log_path; f.scrollIntoView({ block: 'center' }); focusSoft(f); }, 80);
        }
    }));

    $('#errList').addEventListener('click', (e) => {
        const item = e.target.closest('[data-err-i]');
        if (!item) return;
        const g = errView.groups[+item.dataset.errI];
        if (e.target.closest('[data-err-open]')) { e.preventDefault(); gotoInEditor(g.rel, g.line || 1, 0, 0); return; }
        if (e.target.closest('[data-err-claude]')) { sendErrorToClaude(g); return; }
        if (e.target.closest('.err-msg')) item.classList.toggle('expanded');
    });
    loadErrors();
}

function loadErrors(manual = false) {
    const seq = ++errView.seq;
    return apiGet('error_logs', errView.source ? { source: errView.source } : {}).then(res => {
        if (seq !== errView.seq || !res.ok) return;
        errView.sources = res.sources;
        errView.source = res.source;
        errView.groups = res.groups;
        errView.site = res.site_log;
        errView.total = res.groups.reduce((s, g) => s + g.count, 0);
        const sel = $('#errSource');
        sel.innerHTML = res.sources.map(s => `<option value="${esc(s.id)}" title="${esc(s.path)}">${esc(s.display)} (${humanSize(s.size)})</option>`).join('');
        sel.hidden = res.sources.length < 2;
        if (res.source) sel.value = res.source;
        renderErrors();
        if (manual) toast(L('err.refreshed'));
    }).catch(() => {});
}

function renderErrors() {
    const box = $('#errList');
    const src = errView.sources.find(s => s.id === errView.source);
    if (!errView.sources.length) {
        box.innerHTML = `<div class="log-empty">${esc(L('err.no_logs'))}</div>`;
        return;
    }
    const list = errView.groups.map((g, i) => [g, i]).filter(([g]) => !errView.kind || g.kind === errView.kind || (errView.kind === 'fatal' && g.kind === 'parse'));
    const root = APP.rootDir + '/';
    const head = src ? `<div class="err-src muted" title="${esc(src.path)}">${esc(src.display)}${src.size ? '' : ' · ' + esc(L('err.empty_file'))}</div>` : '';
    box.innerHTML = head + (list.length ? list.map(([g, i]) => {
        const text = g.text.split(root).join('');
        const where = g.file ? (g.rel
            ? `<a href="#" data-err-open title="${esc(L('err.open_line', { line: g.line }))}">${esc(g.rel)}${g.line ? ':' + g.line : ''}</a>`
            : `<span class="muted" title="${esc(g.file)}">${esc(baseName(g.file))}${g.line ? ':' + g.line : ''}</span>`) : '';
        return `<div class="err-item err-${esc(g.kind)}" data-err-i="${i}">`
            + `<div class="err-meta"><span class="err-kind">${ERR_LABEL[g.kind] || g.kind}</span>${g.count > 1 ? `<span class="err-count">×${g.count}</span>` : ''}`
            + `<span class="err-date" title="${esc(L('err.first_time', { date: g.first }))}">${esc(shortLogDate(g.last))}</span></div>`
            + `<div class="err-msg">${esc(text)}${g.trace.length ? `<pre class="err-trace">${esc(g.trace.join('\n').split(root).join(''))}</pre>` : ''}</div>`
            + `<div class="err-foot">${where}<button type="button" class="mini-btn add" data-err-claude title="${esc(L('err.to_claude_title'))}">${ic('sparkles')} Claude</button></div></div>`;
    }).join('') : `<div class="log-empty">${esc(L(errView.groups.length ? 'err.none_of_type' : 'err.none'))}</div>`);
}

/** "22-Oct-2026 14:05:09 Europe/Rome" → "22 Oct 14:05" (il formato dipende dal server: se non lo si riconosce resta com'è). */
function shortLogDate(d) {
    const m = String(d || '').match(/^(\d{1,2})-(\w{3})-\d{4} (\d{2}:\d{2})/) || String(d || '').match(/^\w{3} (\w{3}) (\d{1,2}) (\d{2}:\d{2})/);
    if (!m) return d || '';
    return /^\d/.test(m[1]) ? `${m[1]} ${m[2]} ${m[3]}` : `${m[2]} ${m[1]} ${m[3]}`;
}

/** Mette nella richiesta per Claude l'errore con le righe di codice intorno. */
async function sendErrorToClaude(g) {
    const root = APP.rootDir + '/';
    let txt = L('err.claude_head', { kind: ERR_LABEL[g.kind] || g.kind, last: g.last }) + (g.count > 1 ? ' ' + L('err.claude_repeated', { n: g.count }) : '') + ':\n' + g.text.split(root).join('') + '\n';
    if (g.trace.length) txt += g.trace.slice(0, 12).join('\n').split(root).join('') + '\n';
    if (g.rel && g.line) {
        const f = await apiGet('get_file', { file: g.rel });
        if (f.ok && !f.binary && !f.too_big) {
            const lines = f.content.split('\n');
            const from = Math.max(1, g.line - 8), to = Math.min(lines.length, g.line + 8);
            txt += '\n' + L('err.claude_code', { path: g.rel, line: g.line }) + `:\n\`\`\`${extOf(g.rel)}\n`
                + lines.slice(from - 1, to).map((l, k) => `${String(from + k).padStart(4)}${from + k === g.line ? ' >' : '  '} ${l}`).join('\n') + '\n```\n';
        }
    }
    txt += '\n' + L('err.claude_ask');
    const input = $('#claudeInput');
    input.value = (input.value.trim() ? input.value.trim() + '\n\n' : '') + txt;
    const card = $('#claudeCard');
    if ($('#layout').classList.contains('no-right') || window.matchMedia('(max-width: 900px)').matches) {
        $('#claudeExpand').click();
    } else {
        if (card.classList.contains('collapsed')) card.querySelector('.card-head').click();
        card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    focusSoft(input);
    toast(L('err.sent_to_claude'));
}

// ----------------------------------------------------------------------------
// PROPOSTE DI MODIFICA DI CLAUDE: differenza, Applica / Scarta / Applica tutte
// ----------------------------------------------------------------------------
const PROP_STATE = { pending: 'prop.s_pending', applied: 'prop.s_applied', discarded: 'prop.s_discarded', error: 'prop.s_error' };

/** Differenza riga per riga (LCS). Restituisce [{t: ' '|'-'|'+', a, b, s}] con a/b = numeri di riga (da 0). */
function diffLines(a, b) {
    const n = a.length, m = b.length;
    if (n * m > 4000000) {   // troppo grande: tutto tolto + tutto aggiunto
        return a.map((s, i) => ({ t: '-', a: i, s })).concat(b.map((s, j) => ({ t: '+', b: j, s })));
    }
    const dp = Array.from({ length: n + 1 }, () => new Uint32Array(m + 1));
    for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) {
        dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
    const out = [];
    let i = 0, j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) { out.push({ t: ' ', a: i, b: j, s: a[i] }); i++; j++; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) { out.push({ t: '-', a: i, s: a[i] }); i++; }
        else { out.push({ t: '+', b: j, s: b[j] }); j++; }
    }
    while (i < n) { out.push({ t: '-', a: i, s: a[i] }); i++; }
    while (j < m) { out.push({ t: '+', b: j, s: b[j] }); j++; }
    return out;
}

/**
 * Righe della differenza di una proposta. Con "content" (file attuale) si aggiungono "ctx" righe di contesto
 * e i numeri di riga veri; senza, solo il pezzo sostituito.
 */
function proposalRows(p, content, ctx) {
    if (p.type === 'new') return p.new.split('\n').map((s, j) => ({ t: '+', s, ln: [null, j + 1] }));
    const idx = content != null ? content.indexOf(p.old) : -1;
    if (idx < 0) {
        const start = (p.line || 1) - 1;
        return diffLines(p.old.split('\n'), p.new.split('\n'))
            .map(r => ({ t: r.t, s: r.s, ln: [r.a != null ? start + r.a + 1 : null, r.b != null ? start + r.b + 1 : null] }));
    }
    // Righe intere toccate dalla sostituzione (la prima e l'ultima possono esserlo solo in parte)
    const lineStart = content.lastIndexOf('\n', idx - 1) + 1;
    const endIdx = idx + p.old.length;
    const lineEnd = content.indexOf('\n', endIdx) < 0 ? content.length : content.indexOf('\n', endIdx);
    const before = content.slice(lineStart, idx), after = content.slice(endIdx, lineEnd);
    const a = (before + p.old + after).split('\n'), b = (before + p.new + after).split('\n');
    const lines = content.split('\n');
    const first = content.slice(0, lineStart).split('\n').length - 1;   // indice (da 0) della prima riga toccata
    const last = first + a.length - 1;
    const delta = b.length - a.length;
    const pre = lines.slice(Math.max(0, first - ctx), first)
        .map((s, k, arr) => ({ t: ' ', s, ln: [first - arr.length + k + 1, first - arr.length + k + 1] }));
    const mid = diffLines(a, b).map(r => ({ t: r.t, s: r.s, ln: [r.a != null ? first + r.a + 1 : null, r.b != null ? first + r.b + 1 : null] }));
    const post = lines.slice(last + 1, last + 1 + ctx).map((s, k) => ({ t: ' ', s, ln: [last + 2 + k, last + 2 + k + delta] }));
    return pre.concat(mid, post);
}

function diffRowsHtml(rows, limit = 0) {
    const shown = limit ? rows.slice(0, limit) : rows;
    return shown.map(r => `<div class="dl ${r.t === '+' ? 'add' : r.t === '-' ? 'del' : 'ctx'}"><span class="ln">${r.ln[0] ?? ''}</span><span class="ln">${r.ln[1] ?? ''}</span>`
        + `<span class="sg">${r.t === ' ' ? '' : r.t}</span><span class="code">${esc(r.s) || ' '}</span></div>`).join('')
        + (limit && rows.length > limit ? `<div class="dl more">${esc(L('prop.more_lines', { n: rows.length - limit }))}</div>` : '');
}

function proposalStats(p) {
    const rows = proposalRows(p, null, 0);
    return { add: rows.filter(r => r.t === '+').length, del: rows.filter(r => r.t === '-').length, rows };
}

/** Blocco delle proposte dentro la risposta di Claude. */
function proposalsHtml(list) {
    if (!list.length) return '';
    list.forEach(p => state.proposals.set(p.id, Object.assign({ status: 'pending' }, p)));
    const items = list.map(p => {
        const st = proposalStats(p);
        return `<div class="prop" data-prop="${esc(p.id)}">`
            + `<div class="prop-top">${fileIcon(p.path)}<a href="#" data-prop-open title="${esc(L('prop.open_diff'))}">${esc(p.path)}</a>`
            + `<span class="prop-kind">${esc(p.type === 'new' ? L('prop.new_file') : L('prop.line', { line: p.line || 1 }))}</span>`
            + `<span class="prop-stat"><b class="a">+${st.add}</b> <b class="d">−${st.del}</b></span></div>`
            + (p.note ? `<div class="prop-note">${esc(p.note)}</div>` : '')
            + `<div class="prop-diff" data-prop-open>${diffRowsHtml(st.rows, 14)}</div>`
            + `<div class="prop-actions"><span class="prop-state"></span>`
            + `<button type="button" class="sbtn" data-prop-discard>${ic('x')} ${esc(L('prop.discard'))}</button>`
            + `<button type="button" class="pbtn" data-prop-apply>${ic('check')} ${esc(L('prop.apply'))}</button></div></div>`;
    }).join('');
    return `<div class="proposals"><div class="prop-head">${ic('pen')} ${esc(L(list.length === 1 ? 'prop.one' : 'prop.many', { n: list.length }))}`
        + (list.length > 1 ? `<button type="button" class="mini-btn add" data-prop-all>${ic('check')} ${esc(L('prop.apply_all'))}</button>` : '') + `</div>${items}</div>`;
}

function updateProposalUI(id) {
    const p = state.proposals.get(id);
    if (!p) return;
    $$(`[data-prop="${CSS.escape(id)}"]`).forEach(el => {
        el.classList.remove('st-applied', 'st-discarded', 'st-error');
        if (p.status !== 'pending') el.classList.add('st-' + p.status);
        const s = $('.prop-state', el);
        if (s) s.textContent = (p.status === 'pending' ? '' : L(PROP_STATE[p.status])) + (p.status === 'error' && p.error ? ': ' + p.error : '');
        $$('[data-prop-apply], [data-prop-discard]', el).forEach(b => { b.disabled = p.status !== 'pending'; });
    });
    $$('.proposals').forEach(box => {
        const all = $('[data-prop-all]', box);
        if (all) all.disabled = !$$('[data-prop]', box).some(el => (state.proposals.get(el.dataset.prop) || {}).status === 'pending');
    });
    const tab = tabFor('diff:' + id);
    if (tab && state.active === tab.path) renderDiffTab(tab);
}

function initDiffView() {
    // Clic dentro le risposte di Claude
    $('#claudeChat').addEventListener('click', (e) => {
        const box = e.target.closest('[data-prop]');
        if (e.target.closest('[data-prop-all]')) { applyAllProposals(e.target.closest('.proposals')); return; }
        if (!box) return;
        const id = box.dataset.prop;
        if (e.target.closest('[data-prop-apply]')) applyProposal(id);
        else if (e.target.closest('[data-prop-discard]')) discardProposal(id);
        else if (e.target.closest('[data-prop-open]')) { e.preventDefault(); openDiffTab(id); }
    });
    $('#diffApply').addEventListener('click', () => { const t = tabFor(state.active); if (t && t.kind === 'diff') applyProposal(t.prop.id); });
    $('#diffDiscard').addEventListener('click', () => { const t = tabFor(state.active); if (t && t.kind === 'diff') discardProposal(t.prop.id); });
}

function openDiffTab(id) {
    const p = state.proposals.get(id);
    if (!p) return;
    const path = 'diff:' + id;
    if (tabFor(path)) { activateTab(path); return; }
    const tab = { path, kind: 'diff', prop: p, session: null, saved: '', content: null };
    state.tabs.push(tab);
    renderTabs(path);
    // Contenuto attuale del file (quello dell'editor se è aperto) per mostrare le righe intorno
    const open = tabFor(p.path);
    const ready = p.type === 'new' ? Promise.resolve(null)
        : (open && open.session ? Promise.resolve(open.session.getValue()) : apiGet('get_file', { file: p.path }).then(f => (f.ok ? f.content : null)));
    ready.then(c => { tab.content = c; activateTab(path); });
}

function renderDiffTab(tab) {
    const p = tab.prop;
    $('#diffTitle').innerHTML = `${fileIcon(p.path)} <b>${esc(p.path)}</b> <span class="muted">${esc(L(p.type === 'new' ? 'prop.new_by_claude' : 'js.claude_proposal'))}</span>`;
    $('#diffNote').textContent = p.note || '';
    $('#diffNote').hidden = !p.note;
    const pill = $('#diffState');
    pill.textContent = L(PROP_STATE[p.status] || 'prop.s_pending');
    pill.className = 'status-pill ' + ({ applied: 'ok', discarded: '', error: 'err' }[p.status] ?? 'run');
    $('#diffApply').disabled = $('#diffDiscard').disabled = p.status !== 'pending';
    const rows = proposalRows(p, tab.content, 6);
    const found = p.type === 'new' || tab.content == null || tab.content.includes(p.old);
    $('#diffBody').innerHTML = (found ? '' : `<div class="notice error">${ic('alert')} ${esc(L('prop.old_changed'))}</div>`)
        + `<div class="diff-table">${diffRowsHtml(rows)}</div>`;
}

function discardProposal(id) {
    const p = state.proposals.get(id);
    if (!p || p.status !== 'pending') return;
    p.status = 'discarded';
    updateProposalUI(id);
}

async function applyAllProposals(box) {
    const ids = $$('[data-prop]', box).map(el => el.dataset.prop).filter(id => (state.proposals.get(id) || {}).status === 'pending');
    for (const id of ids) await applyProposal(id, true);
}

/**
 * Applica una proposta: salvata subito sul server (impostazione "claude_apply_save") oppure messa nell'editor
 * come modifica da salvare. In entrambi i casi passa dal server per i controlli e il Registro movimenti.
 */
async function applyProposal(id, quiet = false) {
    const p = state.proposals.get(id);
    if (!p || p.status !== 'pending') return;
    const fail = (msg) => { p.status = 'error'; p.error = msg; updateProposalUI(id); toast(`${baseName(p.path)}: ${msg}`, 'error'); };
    const open = tabFor(p.path);
    let saveNow = !!APP.config.claude_apply_save;
    if (saveNow && isDirty(open)) {
        if (!confirm(L('prop.dirty_q', { path: p.path }))) return;
        saveNow = false;
    }
    try {
        if (saveNow) {
            let res = await apiPost({ action: 'claude_apply', type: p.type, path: p.path, old: p.old, new: p.new, save: '1' });
            if (!res.ok && res.lint) {
                if (!confirm(L('prop.syntax_q', { path: p.path, line: res.lint.line }) + '\n' + res.lint.message + '\n\n' + L('prop.apply_anyway_q'))) return;
                res = await apiPost({ action: 'claude_apply', type: p.type, path: p.path, old: p.old, new: p.new, save: '1', force: '1' });
            }
            if (!res.ok) { fail(res.error); return; }
            p.status = 'applied';
            updateProposalUI(id);
            await refreshAll();
            if (open) await reloadTabFromDisk(p.path); else await openFile(p.path, { activate: !quiet });
            if (state.active === p.path) loadVersions(p.path);
            if (!quiet) toast(L('prop.saved', { path: p.path }));
            return;
        }
        // Nell'editor, da salvare con ⌘S
        if (p.type === 'new') {
            const res = await apiPost({ action: 'claude_apply', type: 'new', path: p.path, new: p.new, save: '0' });
            if (!res.ok) { fail(res.error); return; }
            await refreshTree();
            const tab = await openFile(p.path, { activate: true });
            if (!tab || !tab.session) { fail(L('prop.cannot_open')); return; }
            tab.session.setValue(p.new);
            tab.claudePending = true;
            markTabDirty(tab);
        } else {
            const tab = await openFile(p.path, { activate: true });
            if (!tab || !tab.session) { fail(L('prop.cannot_open')); return; }
            const text = tab.session.getValue();
            const idx = text.indexOf(p.old);
            if (idx < 0 || text.indexOf(p.old, idx + 1) >= 0) { fail(L(idx < 0 ? 'prop.old_gone' : 'prop.old_twice')); return; }
            const doc = tab.session.getDocument();
            const Range = ace.require('ace/range').Range;
            const a = doc.indexToPosition(idx), b = doc.indexToPosition(idx + p.old.length);
            const end = tab.session.replace(new Range(a.row, a.column, b.row, b.column), p.new);   // resta annullabile con ⌘Z
            tab.claudePending = true;
            markTabDirty(tab);
            activateTab(p.path);
            if (editor) { editor.selection.setRange(new Range(a.row, a.column, end.row, end.column)); editor.scrollToLine(a.row, true, true, () => {}); }
            apiPost({ action: 'claude_apply', type: 'edit', path: p.path, save: '0' }).then(refreshSide).catch(() => {});
        }
        p.status = 'applied';
        updateProposalUI(id);
        if (!quiet) toast(L('prop.applied_editor', { name: baseName(p.path) }));
    } catch (err) {
        fail(L('js.network_error') + ': ' + err);
    }
}
