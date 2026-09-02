@php
    // @json splits its argument on commas, so the bootstrap has to be one variable.
    $bootstrap = [
        'channel' => $channel,
        'mount' => $mount,
        'apiRoot' => $apiRoot,
        'catalogUrl' => $catalogUrl,
    ];
@endphp
<script>
    // Every fetch is derived from this, never from window.location: the app is mounted
    // in a subdirectory on the host, so a literal "/api/..." reaches the wrong server.
    window.IKAIKA = @json($bootstrap);
</script>
<script>
@verbatim
(function () {
    'use strict';

    var CFG = window.IKAIKA;
    var SESSION_KEY = 'ikaika.playground.session';
    var PAGE_SIZE = 50;

    var el = function (id) { return document.getElementById(id); };

    var basesEl = el('bases');
    var railEl = el('rail');
    var railFilter = el('rail-filter');
    var tableLabel = el('table-label');
    var tableSub = el('table-sub');
    var searchEl = el('search');
    var presetEl = el('preset');
    var showSqlBtn = el('show-sql');
    var filtersEl = el('filters');
    var gridWrap = el('grid-wrap');
    var countEl = el('count');
    var clearBtn = el('clear');
    var prevBtn = el('prev');
    var pageEl = el('page');
    var nextBtn = el('next');
    var peekEl = el('peek');
    var paletteEl = el('palette');
    var paletteOpen = el('palette-open');
    var loginForm = el('login-form');
    var idNoInput = el('id_no');
    var whoEl = el('who');
    var signOutBtn = el('sign-out');

    var consoleEl = el('console');
    var consoleToggle = el('console-toggle');
    var consoleClose = el('console-close');
    var navEl = el('nav');
    var formEl = el('form');
    var methodEl = el('method');
    var pathInput = el('path');
    var bodyEl = el('body');
    var statusEl = el('status');
    var outEl = el('out');

    var catalog = null;
    var navCache = {};
    var schemaCache = {};
    var lastPayload = null;
    var lastBlueprint = null;
    var loadToken = 0;

    var state = {
        base: null,
        table: null,
        page: 1,
        q: '',
        sort: '',
        cols: 'key',
        row: null,
        filters: {}
    };

    // ---------------------------------------------------------------- urls

    function apiUrl() {
        var parts = [CFG.apiRoot, CFG.channel];
        for (var i = 0; i < arguments.length; i++) {
            var piece = String(arguments[i]);
            if (piece !== '') parts.push(piece);
        }
        return parts.join('/');
    }

    // A catalog url already carries the mount; a hand-typed /api/... does not.
    function toRequestUrl(path) {
        var trimmed = String(path).trim();
        if (/^https?:\/\//i.test(trimmed)) return trimmed;
        if (CFG.mount !== '' && (trimmed === '/api' || trimmed.indexOf('/api/') === 0)) {
            return CFG.mount + trimmed;
        }
        return trimmed;
    }

    function readUrl() {
        var p = new URLSearchParams(window.location.search);
        state.base = p.get('base');
        state.table = p.get('table');
        state.page = Math.max(1, parseInt(p.get('page') || '1', 10) || 1);
        state.q = p.get('q') || '';
        state.sort = p.get('sort') || '';
        state.cols = p.get('cols') === 'all' ? 'all' : 'key';
        state.row = p.get('row');
        state.filters = {};
        p.forEach(function (value, key) {
            var hit = /^filter\[(.+)\]$/.exec(key);
            if (hit) state.filters[hit[1]] = value;
        });
    }

    // State lives in the query string only. A deep path would 404 behind the
    // subdirectory mount, because the server only routes / and /api.
    function writeUrl(replace) {
        var p = new URLSearchParams();
        if (state.base) p.set('base', state.base);
        if (state.table) p.set('table', state.table);
        if (state.page > 1) p.set('page', String(state.page));
        if (state.q) p.set('q', state.q);
        if (state.sort) p.set('sort', state.sort);
        if (state.cols === 'all') p.set('cols', 'all');
        Object.keys(state.filters).forEach(function (key) {
            p.set('filter[' + key + ']', state.filters[key]);
        });
        if (state.row) p.set('row', state.row);
        var query = p.toString();
        var url = window.location.pathname + (query === '' ? '' : '?' + query);
        if (replace) window.history.replaceState(null, '', url);
        else window.history.pushState(null, '', url);
    }

    // ------------------------------------------------------------- session

    function readSession() {
        try { return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null'); } catch (e) { return null; }
    }

    function writeSession(session) {
        if (session === null) sessionStorage.removeItem(SESSION_KEY);
        else sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
        renderAuth();
    }

    function token() {
        var session = readSession();
        return session && session.token ? session.token : null;
    }

    function renderAuth() {
        var session = readSession();
        var employee = session && session.employee;
        var submit = loginForm.querySelector('button[type="submit"]');
        if (employee) {
            var name = [employee.first_name, employee.last_name].filter(Boolean).join(' ') || employee.id_no;
            var rank = employee.role_level === 'Executive' ? 'Executive' : employee.role;
            whoEl.textContent = name + ' · ' + rank;
            idNoInput.hidden = true;
            submit.hidden = true;
            signOutBtn.hidden = false;
        } else {
            whoEl.textContent = '';
            idNoInput.hidden = false;
            submit.hidden = false;
            signOutBtn.hidden = true;
        }
    }

    function signedIn() {
        return token() !== null;
    }

    // ---------------------------------------------------------------- http

    async function getJson(url) {
        var headers = { Accept: 'application/json' };
        var bearer = token();
        if (bearer) headers.Authorization = 'Bearer ' + bearer;
        var response = await fetch(url, { headers: headers });
        var payload = null;
        try { payload = await response.json(); } catch (e) { payload = null; }
        if (!response.ok) {
            var error = new Error((payload && payload.message) || (response.status + ' ' + response.statusText));
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    // ------------------------------------------------------------ elements

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function stateMessage(node, heading, detail) {
        clear(node);
        var box = make('div', 'state');
        box.appendChild(make('strong', null, heading));
        box.appendChild(document.createTextNode(detail || ''));
        node.appendChild(box);
    }

    // A stable tint per option value, so the same status reads the same everywhere.
    var TINTS = [
        ['rgba(79,142,247,0.18)', '#9dc0fb'],
        ['rgba(78,161,114,0.18)', '#8fd0ab'],
        ['rgba(217,155,60,0.18)', '#e3bb7d'],
        ['rgba(212,99,107,0.18)', '#e39aa0'],
        ['rgba(156,124,224,0.18)', '#c0aaf0'],
        ['rgba(90,178,196,0.18)', '#9ad2dd'],
        ['rgba(199,132,96,0.18)', '#dfae91'],
        ['rgba(140,150,166,0.18)', '#bcc3ce']
    ];

    function tintFor(value) {
        var text = String(value);
        var sum = 0;
        for (var i = 0; i < text.length; i++) sum = (sum * 31 + text.charCodeAt(i)) % 100003;
        return TINTS[sum % TINTS.length];
    }

    // ------------------------------------------------------------- catalog

    function productByKey(key) {
        var products = (catalog && catalog.products) || [];
        for (var i = 0; i < products.length; i++) {
            if (products[i].key === key) return products[i];
        }
        return null;
    }

    function initials(name) {
        var words = String(name).replace(/[^A-Za-z0-9 ]/g, ' ').split(/\s+/).filter(Boolean);
        if (words.length === 0) return '??';
        if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
        return (words[0][0] + words[1][0]).toUpperCase();
    }

    function renderBases() {
        clear(basesEl);
        ((catalog && catalog.products) || []).forEach(function (product) {
            var button = make('button', state.base === product.key ? 'active' : null);
            button.type = 'button';
            button.dataset.product = product.key;
            button.title = product.database ? product.key + ' · ' + product.database : product.key;
            var ok = product.enabled && product.health && product.health.ok;
            button.appendChild(make('span', 'dot ' + (product.enabled ? (ok ? 'ok' : 'bad') : '')));
            button.appendChild(make('span', null, product.name || initials(product.key)));
            basesEl.appendChild(button);
        });
    }

    // ---------------------------------------------------------------- rail

    async function loadNav(base) {
        if (navCache[base]) return navCache[base];
        var payload = await getJson(apiUrl(base, '_schema'));
        navCache[base] = payload;
        return payload;
    }

    async function loadSchema(base, table) {
        var key = base + '/' + table;
        if (schemaCache[key]) return schemaCache[key];
        var payload = await getJson(apiUrl(base, '_schema', table));
        schemaCache[key] = payload;
        return payload;
    }

    function renderRail() {
        clear(railEl);
        var nav = navCache[state.base];
        if (!nav) {
            railEl.appendChild(make('div', 'state', signedIn() ? 'Loading tables…' : 'Sign in to list tables.'));
            return;
        }

        var needle = railFilter.value.trim().toLowerCase();
        var group = make('div', 'rail-group', nav.database);
        railEl.appendChild(group);

        var shown = 0;
        nav.tables.forEach(function (table) {
            if (needle !== '' && table.name.indexOf(needle) < 0 && table.label.toLowerCase().indexOf(needle) < 0) return;
            shown++;
            var item = make('button', 'rail-item' + (state.table === table.name ? ' active' : ''));
            item.type = 'button';
            item.dataset.table = table.name;
            item.title = table.name;
            item.appendChild(make('span', 'name', table.label));
            item.appendChild(make('span', 'count', table.rows.toLocaleString()));
            railEl.appendChild(item);
        });

        if (shown === 0) railEl.appendChild(make('div', 'state', 'No table matches that.'));
    }

    // ---------------------------------------------------------------- grid

    function fieldsByName(blueprint) {
        var map = {};
        (blueprint.fields || []).forEach(function (field) { map[field.name] = field; });
        return map;
    }

    function nilCell() { return make('span', 'nil', '—'); }

    function emptyCell() { return make('span', 'empty', '""'); }

    function renderChips(td, field, cell) {
        if (!cell || !cell.chips) { td.appendChild(nilCell()); return; }
        if (cell.chips.length === 0) { td.appendChild(nilCell()); return; }
        cell.chips.forEach(function (chip) {
            var node = make('span', 'chip', chip.label);
            node.dataset.to = cell.to;
            node.dataset.rid = chip.id;
            node.title = cell.to + ' #' + chip.id;
            td.appendChild(node);
        });
        if (cell.more > 0) td.appendChild(make('span', 'chip more', '+' + cell.more));
    }

    function renderValue(td, field, value) {
        switch (field.type) {
            case 'boolean':
                var on = Number(value) === 1 || value === true || value === 'true';
                td.appendChild(make('span', on ? 'check' : 'uncheck', on ? '✓' : '·'));
                return;
            case 'select':
                var pill = make('span', 'pill', value);
                var tint = tintFor(value);
                pill.style.background = tint[0];
                pill.style.color = tint[1];
                pill.dataset.filter = field.name;
                pill.dataset.value = String(value);
                pill.title = 'Filter ' + field.label + ' to this';
                td.appendChild(pill);
                return;
            case 'url':
                if (!/^https?:\/\//i.test(String(value))) { td.appendChild(document.createTextNode(String(value))); return; }
                var link = make('a', null, value);
                link.href = String(value);
                link.target = '_blank';
                link.rel = 'noreferrer noopener';
                td.appendChild(link);
                return;
            case 'email':
                var mail = make('a', null, value);
                mail.href = 'mailto:' + String(value);
                td.appendChild(mail);
                return;
            case 'json':
                td.classList.add('mono');
                td.appendChild(document.createTextNode(String(value)));
                return;
            case 'id':
            case 'external':
                td.classList.add('mono');
                td.appendChild(document.createTextNode(String(value)));
                return;
            default:
                td.appendChild(document.createTextNode(String(value)));
        }
    }

    function renderCell(td, field, cell) {
        if (field.type === 'link') { renderChips(td, field, cell); return; }

        var value = cell ? cell.v : null;
        if (value === null || value === undefined) { td.appendChild(nilCell()); return; }
        if (value === '') { td.appendChild(emptyCell()); return; }
        if (field.numeric) td.classList.add('num');
        td.title = field.type === 'longtext' || field.type === 'json' ? String(value) : '';
        renderValue(td, field, value);
    }

    function headCell(field, width) {
        var th = make('th');
        th.style.minWidth = width + 'px';
        th.style.width = width + 'px';
        var head = make('div', 'head');
        head.appendChild(make('span', null, field.label));
        head.appendChild(make('span', 'type', field.type));
        var current = state.sort.split(':');
        if (current[0] === field.name) head.appendChild(make('span', 'arrow', current[1] === 'desc' ? '↓' : '↑'));
        th.appendChild(head);
        if (field.sortable) th.dataset.sort = field.name;
        else th.style.cursor = 'default';
        th.title = field.name + (field.filled === null || field.filled === undefined ? '' : ' · ' + field.filled + ' filled');
        return th;
    }

    function renderGrid(payload, blueprint) {
        var fields = fieldsByName(blueprint);
        var title = payload.title;
        var columns = payload.columns.slice();
        if (!fields[title] || columns.indexOf(title) < 0) title = columns[0];
        var rest = columns.filter(function (name) { return name !== title; });

        var table = make('table', 'grid');
        var thead = make('thead');
        var headRow = make('tr');

        var gutterHead = make('th', 'freeze-1');
        gutterHead.style.minWidth = '64px';
        gutterHead.style.width = '64px';
        headRow.appendChild(gutterHead);

        var titleHead = headCell(fields[title] || { name: title, label: title, type: 'text', sortable: true }, 240);
        titleHead.className = 'freeze-2';
        headRow.appendChild(titleHead);

        rest.forEach(function (name) {
            var field = fields[name] || { name: name, label: name, type: 'text', sortable: true };
            headRow.appendChild(headCell(field, field.width || 180));
        });

        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = make('tbody');
        var offset = (payload.meta.current_page - 1) * payload.meta.per_page;

        payload.data.forEach(function (row, index) {
            var tr = make('tr');
            var idCell = row.id ? row.id.v : null;
            if (idCell !== null && idCell !== undefined) tr.dataset.rid = idCell;
            if (state.row !== null && String(idCell) === String(state.row)) tr.className = 'on';

            var gutter = make('td', 'freeze-1 gutter', offset + index + 1);
            tr.appendChild(gutter);

            var titleCell = make('td', 'freeze-2');
            renderCell(titleCell, fields[title] || { name: title, type: 'text' }, row[title]);
            tr.appendChild(titleCell);

            rest.forEach(function (name) {
                var td = make('td');
                renderCell(td, fields[name] || { name: name, type: 'text' }, row[name]);
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        clear(gridWrap);
        if (payload.data.length === 0) {
            stateMessage(gridWrap, 'No rows match', 'Clear the search or the filters to see the whole table.');
            return;
        }
        gridWrap.appendChild(table);
        gridWrap.scrollTop = 0;
    }

    function renderFilters() {
        clear(filtersEl);
        Object.keys(state.filters).forEach(function (column) {
            var tag = make('span', 'tag');
            tag.appendChild(make('span', null, column + ' ' + state.filters[column].replace(':', ' ')));
            var drop = make('button', null, '×');
            drop.type = 'button';
            drop.dataset.drop = column;
            drop.title = 'Remove this filter';
            tag.appendChild(drop);
            filtersEl.appendChild(tag);
        });
    }

    function renderFooter(payload) {
        var meta = payload.meta;
        var noun = (payload.label || payload.table).toLowerCase();
        if (meta.filtered) {
            countEl.textContent = meta.total.toLocaleString() + ' of ' + meta.unfiltered_total.toLocaleString() +
                ' ' + noun + ' · filtered';
        } else {
            countEl.textContent = meta.total.toLocaleString() + ' ' + noun;
        }
        clearBtn.hidden = !meta.filtered;
        pageEl.textContent = meta.current_page + ' / ' + meta.last_page;
        prevBtn.disabled = meta.current_page <= 1;
        nextBtn.disabled = meta.current_page >= meta.last_page;
    }

    function gridUrl() {
        var p = new URLSearchParams();
        p.set('per_page', String(PAGE_SIZE));
        p.set('page', String(state.page));
        p.set('cols', state.cols);
        if (state.q) p.set('q', state.q);
        if (state.sort) p.set('sort', state.sort);
        Object.keys(state.filters).forEach(function (key) {
            p.set('filter[' + key + ']', state.filters[key]);
        });
        return apiUrl(state.base, '_grid', state.table) + '?' + p.toString();
    }

    async function load() {
        if (!signedIn()) {
            stateMessage(gridWrap, 'Not signed in', 'Enter an employee ID number in the top right to browse the databases.');
            tableSub.textContent = 'sign in to browse';
            return;
        }
        if (!state.base || !state.table) {
            stateMessage(gridWrap, 'Pick a table', 'Choose one from the rail, or press Ctrl K to jump across bases.');
            return;
        }

        var ticket = ++loadToken;
        tableSub.textContent = 'loading…';

        try {
            var blueprint = await loadSchema(state.base, state.table);
            var payload = await getJson(gridUrl());
            if (ticket !== loadToken) return;

            lastPayload = payload;
            lastBlueprint = blueprint;
            tableLabel.textContent = payload.label;
            tableSub.textContent = state.base + ' · ' + payload.table + ' · ' + payload.columns.length + ' columns';
            renderFilters();
            renderGrid(payload, blueprint);
            renderFooter(payload);
            renderRail();
        } catch (error) {
            if (ticket !== loadToken) return;
            if (error.status === 401) {
                writeSession(null);
                stateMessage(gridWrap, 'Session expired', 'Sign in again to keep browsing.');
            } else {
                stateMessage(gridWrap, 'Could not load ' + state.table, error.message);
            }
            tableSub.textContent = 'error';
        }
    }

    // ---------------------------------------------------------------- peek

    async function renderPeek() {
        clear(peekEl);
        if (!state.row || !state.table || !signedIn()) return;

        var panel = make('div', 'peek');
        var header = make('header');
        header.appendChild(make('strong', null, 'Loading record…'));
        var close = make('button', 'ghost', 'Close');
        close.type = 'button';
        close.dataset.peekClose = '1';
        header.appendChild(close);
        panel.appendChild(header);
        var body = make('div', 'body');
        panel.appendChild(body);
        peekEl.appendChild(panel);

        try {
            // The title comes from this record's own table, not from whatever the grid
            // last showed -- a chip can open a record in a table the grid has not loaded.
            var blueprint = await loadSchema(state.base, state.table);
            var record = await getJson(apiUrl(state.base, '_grid', state.table, state.row));
            var titleCell = record.data[blueprint.title];
            header.firstChild.textContent = (titleCell && titleCell.v) ? String(titleCell.v) : (blueprint.label + ' #' + record.id);

            record.fields.forEach(function (field) {
                var row = make('div', 'row');
                row.appendChild(make('div', 'k', field.label));
                var value = make('div', 'v');
                renderCell(value, field, record.data[field.name]);
                row.appendChild(value);
                body.appendChild(row);
            });
        } catch (error) {
            header.firstChild.textContent = 'Could not open that record';
            body.appendChild(make('div', 'state', error.message));
        }
    }

    function openRow(id) {
        state.row = id === null ? null : String(id);
        writeUrl(true);
        gridWrap.querySelectorAll('tr.on').forEach(function (tr) { tr.classList.remove('on'); });
        if (state.row !== null) {
            var target = gridWrap.querySelector('tr[data-rid="' + CSS.escape(state.row) + '"]');
            if (target) target.classList.add('on');
        }
        renderPeek();
    }

    // ------------------------------------------------------------- palette

    var paletteHits = [];
    var paletteIndex = 0;

    async function showPalette() {
        clear(paletteEl);
        if (!signedIn()) return;

        var scrim = make('div', 'scrim');
        scrim.dataset.paletteClose = '1';
        var box = make('div', 'palette');
        var input = make('input');
        input.placeholder = 'Jump to a table in any base';
        input.spellcheck = false;
        box.appendChild(input);
        var hits = make('div', 'hits');
        box.appendChild(hits);
        peekSafeAppend(scrim, box);
        input.focus();

        var bases = ((catalog && catalog.products) || []).filter(function (p) { return p.enabled; });
        await Promise.all(bases.map(function (product) {
            return loadNav(product.key).catch(function () { return null; });
        }));

        var everything = [];
        bases.forEach(function (product) {
            var nav = navCache[product.key];
            if (!nav) return;
            nav.tables.forEach(function (table) {
                everything.push({ base: product.key, baseName: product.name, table: table });
            });
        });

        function draw() {
            var needle = input.value.trim().toLowerCase();
            paletteHits = everything.filter(function (entry) {
                if (needle === '') return true;
                return entry.table.name.indexOf(needle) >= 0 ||
                    entry.table.label.toLowerCase().indexOf(needle) >= 0 ||
                    entry.baseName.toLowerCase().indexOf(needle) >= 0;
            }).slice(0, 40);
            paletteIndex = 0;
            clear(hits);
            paletteHits.forEach(function (entry, index) {
                var hit = make('button', 'hit' + (index === 0 ? ' on' : ''));
                hit.type = 'button';
                hit.dataset.jump = index;
                hit.appendChild(make('span', 'base', entry.baseName));
                hit.appendChild(make('span', 'name', entry.table.label));
                hit.appendChild(make('span', 'count', entry.table.rows.toLocaleString()));
                hits.appendChild(hit);
            });
            if (paletteHits.length === 0) hits.appendChild(make('div', 'state', 'Nothing matches that.'));
        }

        function move(step) {
            if (paletteHits.length === 0) return;
            paletteIndex = (paletteIndex + step + paletteHits.length) % paletteHits.length;
            hits.querySelectorAll('.hit').forEach(function (node, index) {
                node.classList.toggle('on', index === paletteIndex);
            });
            var active = hits.querySelectorAll('.hit')[paletteIndex];
            if (active) active.scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('input', draw);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); move(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1); }
            else if (event.key === 'Enter') { event.preventDefault(); jump(paletteIndex); }
            else if (event.key === 'Escape') { clear(paletteEl); }
        });

        draw();
    }

    function peekSafeAppend(scrim, box) {
        paletteEl.appendChild(scrim);
        paletteEl.appendChild(box);
    }

    function jump(index) {
        var entry = paletteHits[index];
        if (!entry) return;
        clear(paletteEl);
        selectTable(entry.base, entry.table.name);
    }

    // -------------------------------------------------------------- moving

    function selectTable(base, table) {
        var changedBase = base !== state.base;
        state.base = base;
        state.table = table;
        state.page = 1;
        state.q = '';
        state.sort = '';
        state.row = null;
        state.filters = {};
        searchEl.value = '';
        writeUrl(false);
        renderBases();
        clear(peekEl);
        if (changedBase && !navCache[base]) {
            loadNav(base).then(renderRail).catch(function () {});
        }
        renderRail();
        load();
    }

    function reload(pushHistory) {
        writeUrl(!pushHistory);
        load();
    }

    // ------------------------------------------------------------- console

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }

    function syncBodyVisibility() {
        var method = methodEl.value;
        consoleEl.classList.toggle('has-body', method !== 'GET' && method !== 'DELETE');
    }

    function renderTree() {
        var product = productByKey(state.base) || (catalog && catalog.products && catalog.products[0]);
        if (!product) { navEl.innerHTML = '<p class="empty">Choose a base.</p>'; return; }

        var bits = [];
        bits.push('<button class="route top" type="button" data-method="GET" data-path="' + escapeHtml(product.base_url) + '">Overview<span class="meta">GET ' + escapeHtml(product.base_url) + '</span></button>');

        (product.utilities || []).forEach(function (op) {
            bits.push('<button class="route top" type="button" data-method="' + escapeHtml(op.method) + '" data-path="' + escapeHtml(op.url) + '">' + escapeHtml(op.label) + '<span class="meta">' + escapeHtml(op.method) + '</span></button>');
        });

        if ((product.auth || []).length) {
            bits.push('<div class="group">Auth</div>');
            product.auth.forEach(function (op) {
                var body = op.body ? ' data-body="' + escapeHtml(JSON.stringify(op.body)) + '"' : '';
                bits.push('<button class="route top" type="button" data-method="' + escapeHtml(op.method) + '" data-path="' + escapeHtml(op.url) + '"' + body + '>' + escapeHtml(op.label) + '<span class="meta">' + escapeHtml(op.method) + '</span></button>');
            });
        }

        bits.push('<div class="group">Separated</div>');
        if ((product.sections || []).length) {
            product.sections.forEach(function (section) {
                bits.push('<details class="branch"><summary>' + escapeHtml(section.label) + '</summary>');
                (section.resources || []).forEach(function (resource) {
                    (resource.operations || [{ method: 'GET', url: resource.url, label: resource.label }]).forEach(function (op) {
                        var body = op.body ? ' data-body="' + escapeHtml(JSON.stringify(op.body)) + '"' : '';
                        bits.push('<button class="route" type="button" data-method="' + escapeHtml(op.method) + '" data-path="' + escapeHtml(op.url) + '"' + body + '>' + escapeHtml(op.label) + '<span class="meta">' + escapeHtml(op.method) + ' · ' + escapeHtml(resource.label) + '</span></button>');
                    });
                });
                bits.push('</details>');
            });
        } else {
            bits.push('<div class="note">No sectioned API on this product yet.</div>');
        }

        bits.push('<div class="group">Practice</div>');
        if ((product.resources || []).length) {
            bits.push('<details class="branch"><summary>Generic dumps</summary>');
            product.resources.forEach(function (resource) {
                var count = resource.count == null ? '' : ' · ' + resource.count;
                bits.push('<button class="route" type="button" data-method="GET" data-path="' + escapeHtml(resource.url) + '">' + escapeHtml(resource.label) + '<span class="meta">GET' + count + '</span></button>');
            });
            bits.push('</details>');
        } else {
            bits.push('<div class="note">No practice resources. Enable the product when its database exists.</div>');
        }

        navEl.innerHTML = bits.join('');
    }

    async function runConsole() {
        var method = methodEl.value;
        var url = toRequestUrl(pathInput.value);
        pathInput.value = url;
        var started = performance.now();
        statusEl.textContent = 'Requesting…';

        var headers = { Accept: 'application/json' };
        var bearer = token();
        if (bearer) headers.Authorization = 'Bearer ' + bearer;
        var options = { method: method, headers: headers };
        if (method !== 'GET' && method !== 'DELETE') {
            headers['Content-Type'] = 'application/json';
            var raw = bodyEl.value.trim();
            if (raw !== '') options.body = raw;
        }

        try {
            var response = await fetch(url, options);
            var text = await response.text();
            var parsed = text;
            try { parsed = JSON.stringify(JSON.parse(text), null, 2); } catch (e) {}
            statusEl.textContent = method + ' ' + response.status + ' ' + response.statusText +
                ' · ' + Math.round(performance.now() - started) + ' ms';
            outEl.textContent = parsed;
        } catch (error) {
            statusEl.textContent = 'Request failed';
            outEl.textContent = String(error);
        }
    }

    function openConsole() {
        consoleEl.hidden = false;
        renderTree();
        syncBodyVisibility();
    }

    // -------------------------------------------------------------- events

    basesEl.addEventListener('click', function (event) {
        var button = event.target.closest('[data-product]');
        if (!button) return;
        var base = button.dataset.product;
        if (base === state.base) return;
        state.base = base;
        state.table = null;
        state.row = null;
        state.filters = {};
        state.q = '';
        state.sort = '';
        state.page = 1;
        searchEl.value = '';
        clear(peekEl);
        renderBases();
        writeUrl(false);
        stateMessage(gridWrap, 'Pick a table', 'Choose one from the rail on the left.');
        tableLabel.textContent = productByKey(base) ? productByKey(base).name : base;
        tableSub.textContent = base;
        railEl.textContent = '';
        loadNav(base).then(renderRail).catch(function (error) {
            stateMessage(railEl, 'Could not list tables', error.message);
        });
    });

    railEl.addEventListener('click', function (event) {
        var item = event.target.closest('[data-table]');
        if (!item) return;
        selectTable(state.base, item.dataset.table);
    });

    railFilter.addEventListener('input', renderRail);

    gridWrap.addEventListener('click', function (event) {
        var chip = event.target.closest('.chip[data-to]');
        if (chip) {
            event.stopPropagation();
            selectTable(state.base, chip.dataset.to);
            state.row = chip.dataset.rid;
            writeUrl(true);
            renderPeek();
            return;
        }

        var pill = event.target.closest('.pill[data-filter]');
        if (pill) {
            event.stopPropagation();
            state.filters[pill.dataset.filter] = 'eq:' + pill.dataset.value;
            state.page = 1;
            reload(true);
            return;
        }

        var head = event.target.closest('th[data-sort]');
        if (head) {
            var column = head.dataset.sort;
            var current = state.sort.split(':');
            state.sort = current[0] === column && current[1] !== 'desc' ? column + ':desc' : column + ':asc';
            state.page = 1;
            reload(false);
            return;
        }

        var row = event.target.closest('tr[data-rid]');
        if (row) openRow(row.dataset.rid);
    });

    filtersEl.addEventListener('click', function (event) {
        var drop = event.target.closest('[data-drop]');
        if (!drop) return;
        delete state.filters[drop.dataset.drop];
        state.page = 1;
        reload(true);
    });

    peekEl.addEventListener('click', function (event) {
        if (event.target.closest('[data-peek-close]')) openRow(null);
    });

    paletteEl.addEventListener('click', function (event) {
        if (event.target.closest('[data-palette-close]')) { clear(paletteEl); return; }
        var hit = event.target.closest('[data-jump]');
        if (hit) jump(Number(hit.dataset.jump));
    });

    var searchTimer = null;
    searchEl.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            state.q = searchEl.value.trim();
            state.page = 1;
            reload(true);
        }, 250);
    });

    presetEl.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cols]');
        if (!button) return;
        state.cols = button.dataset.cols;
        presetEl.querySelectorAll('button').forEach(function (node) {
            node.classList.toggle('active', node.dataset.cols === state.cols);
        });
        reload(false);
    });

    showSqlBtn.addEventListener('click', function () {
        if (!lastPayload) return;
        openConsole();
        statusEl.textContent = 'The statement behind this grid.';
        outEl.textContent = lastPayload.sql;
    });

    clearBtn.addEventListener('click', function () {
        state.filters = {};
        state.q = '';
        searchEl.value = '';
        state.page = 1;
        reload(true);
    });

    prevBtn.addEventListener('click', function () {
        if (state.page <= 1) return;
        state.page--;
        reload(true);
    });

    nextBtn.addEventListener('click', function () {
        state.page++;
        reload(true);
    });

    paletteOpen.addEventListener('click', showPalette);

    consoleToggle.addEventListener('click', function () {
        if (consoleEl.hidden) openConsole();
        else consoleEl.hidden = true;
    });

    consoleClose.addEventListener('click', function () { consoleEl.hidden = true; });
    methodEl.addEventListener('change', syncBodyVisibility);

    formEl.addEventListener('submit', function (event) {
        event.preventDefault();
        runConsole();
    });

    navEl.addEventListener('click', function (event) {
        var button = event.target.closest('button.route');
        if (!button) return;
        methodEl.value = button.dataset.method || 'GET';
        pathInput.value = button.dataset.path;
        if (button.dataset.body) bodyEl.value = JSON.stringify(JSON.parse(button.dataset.body), null, 2);
        syncBodyVisibility();
        runConsole();
    });

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            showPalette();
            return;
        }
        if (event.key !== 'Escape') return;
        if (paletteEl.firstChild) { clear(paletteEl); return; }
        if (peekEl.firstChild) { openRow(null); return; }
        if (!consoleEl.hidden) consoleEl.hidden = true;
    });

    window.addEventListener('popstate', function () {
        readUrl();
        searchEl.value = state.q;
        presetEl.querySelectorAll('button').forEach(function (node) {
            node.classList.toggle('active', node.dataset.cols === state.cols);
        });
        renderBases();
        renderRail();
        clear(peekEl);
        load().then(renderPeek);
    });

    // ---------------------------------------------------------------- boot

    loginForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        var portal = productByKey('portal');
        var op = ((portal && portal.auth) || []).filter(function (item) { return item.method === 'POST'; })[0];
        var url = op ? toRequestUrl(op.url) : apiUrl('portal', 'auth', 'login');
        var id = idNoInput.value.trim();
        if (id === '') return;

        try {
            var response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_no: id })
            });
            var payload = await response.json();
            if (response.ok && payload.token && payload.employee) {
                writeSession({ token: payload.token, employee: payload.employee });
                idNoInput.value = '';
                navCache = {};
                schemaCache = {};
                await start();
            } else {
                stateMessage(gridWrap, 'Sign in failed', payload.message || 'That employee ID number was not accepted.');
            }
        } catch (error) {
            stateMessage(gridWrap, 'Sign in failed', String(error));
        }
    });

    signOutBtn.addEventListener('click', function () {
        writeSession(null);
        navCache = {};
        schemaCache = {};
        clear(railEl);
        clear(peekEl);
        stateMessage(gridWrap, 'Signed out', 'Sign in again to browse the databases.');
        countEl.textContent = '--';
    });

    async function start() {
        if (!signedIn()) {
            stateMessage(gridWrap, 'Not signed in', 'Enter an employee ID number in the top right to browse the databases.');
            renderRail();
            return;
        }

        if (!state.base) {
            state.base = (productByKey('portal') && 'portal') ||
                (catalog && catalog.products && catalog.products[0] && catalog.products[0].key) || null;
            writeUrl(true);
        }

        renderBases();
        if (!state.base) return;

        try {
            await loadNav(state.base);
        } catch (error) {
            if (error.status === 401) { writeSession(null); return start(); }
            stateMessage(railEl, 'Could not list tables', error.message);
            return;
        }

        if (!state.table) {
            var nav = navCache[state.base];
            var first = nav && nav.tables[0];
            if (first) { state.table = first.name; writeUrl(true); }
        }

        renderRail();
        await load();
        await renderPeek();
    }

    async function boot() {
        readUrl();
        searchEl.value = state.q;
        presetEl.querySelectorAll('button').forEach(function (node) {
            node.classList.toggle('active', node.dataset.cols === state.cols);
        });
        renderAuth();

        try {
            catalog = await getJson(toRequestUrl(CFG.catalogUrl));
        } catch (error) {
            stateMessage(gridWrap, 'Could not reach the API', error.message);
            return;
        }

        renderBases();
        await start();
    }

    boot();
})();
@endverbatim
</script>
