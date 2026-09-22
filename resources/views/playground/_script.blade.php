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
    var grantsEl = el('grants');
    var addFilterBtn = el('add-filter');
    var openViewsBtn = el('open-views');
    var syncAirtableBtn = el('sync-airtable');
    var menuEl = el('menu');
    var toastEl = el('toast');
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

    async function send(method, url, body) {
        var headers = { Accept: 'application/json' };
        var bearer = token();
        if (bearer) headers.Authorization = 'Bearer ' + bearer;
        var options = { method: method, headers: headers };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        var response = await fetch(url, options);
        var payload = null;
        try { payload = await response.json(); } catch (e) { payload = null; }
        if (!response.ok) {
            var error = new Error((payload && payload.message) || (response.status + ' ' + response.statusText));
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    function getJson(url) { return send('GET', url); }

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

    // A confirmation of something already on screen clears fast; a refusal has to be
    // read, so it stays for a beat longer.
    function toast(text, kind) {
        var note = make('div', 'note' + (kind ? ' ' + kind : ''), text);
        toastEl.appendChild(note);
        window.setTimeout(function () {
            if (note.parentNode) note.parentNode.removeChild(note);
        }, kind === 'bad' ? 6000 : 2200);
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
        if (navCache[base]) { renderGrants(navCache[base].grants); return navCache[base]; }
        var payload = await getJson(apiUrl(base, '_schema'));
        navCache[base] = payload;
        renderGrants(payload.grants);
        return payload;
    }

    // Say what this account may do before it tries something and is refused.
    function renderGrants(grants) {
        if (!grants) { grantsEl.hidden = true; return; }
        grantsEl.hidden = false;
        grantsEl.textContent = grants.label + ' · ' + (grants.edit ? 'can edit' : 'read only');
        grantsEl.title = grants.private
            ? 'You can read personal columns and correct values. Every change is logged.'
            : 'Personal columns are locked and the grid is read only for you.';
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
        if (!cell || !cell.chips || cell.chips.length === 0) { td.appendChild(nilCell()); return; }
        var single = cell.kind === 'parent';
        cell.chips.forEach(function (chip) {
            var node = make('span', 'chip' + (single ? ' one' : ''), chip.label);
            node.dataset.to = cell.to;
            node.dataset.rid = chip.id;
            node.title = cell.to + ' #' + chip.id + ' · click to open, shift-click to filter by it';
            td.appendChild(node);
        });
        if (cell.more > 0) {
            var more = make('span', 'chip more', '+' + cell.more);
            more.title = cell.count + ' in all';
            td.appendChild(more);
        }
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
        if (cell && cell.locked) {
            var lock = make('span', 'locked', '••••');
            lock.title = 'Only an administrator can read ' + field.label + '.';
            td.appendChild(lock);
            return;
        }
        if (field.type === 'link' || (field.type === 'reference' && cell && cell.chips)) {
            renderChips(td, field, cell);
            return;
        }

        var value = cell ? cell.v : null;
        if (value === null || value === undefined) { td.appendChild(nilCell()); return; }
        if (value === '') { td.appendChild(emptyCell()); return; }
        if (field.numeric) td.classList.add('num');
        td.title = field.type === 'longtext' || field.type === 'json' ? String(value) : '';
        renderValue(td, field, value);
    }

    // A JSON column's own detail row: pretty-printed, not the flat single-line text the
    // grid cell shows -- the whole point of opening a record is to actually read this.
    function renderJsonDetail(container, raw) {
        var pretty = String(raw);
        try { pretty = JSON.stringify(JSON.parse(raw), null, 2); } catch (error) {}
        container.appendChild(make('pre', 'mono json-detail', pretty));
    }

    function headCell(field, width) {
        var th = make('th');
        th.style.minWidth = width + 'px';
        th.style.width = width + 'px';
        var head = make('div', 'head');
        head.appendChild(make('span', null, field.label));
        head.appendChild(make('span', 'type', field.locked ? 'locked' : field.type));
        var current = state.sort.split(':');
        if (current[0] === field.name) head.appendChild(make('span', 'arrow', current[1] === 'desc' ? '↓' : '↑'));
        th.appendChild(head);
        th.dataset.col = field.name;
        if (field.locked) th.classList.add('locked-head');
        if (field.sortable) th.dataset.sort = field.name;
        else th.style.cursor = 'default';
        th.title = field.name + (field.filled === null || field.filled === undefined ? '' : ' · ' + field.filled + ' filled');
        return th;
    }

    function bodyCell(fields, name, row, className) {
        var field = fields[name] || { name: name, label: name, type: 'text' };
        var td = make('td', className);
        td.dataset.col = name;
        if (field.editable) td.classList.add('editable');
        renderCell(td, field, row[name]);
        return td;
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
            tr.dataset.index = String(index);
            if (state.row !== null && String(idCell) === String(state.row)) tr.className = 'on';

            // Never editable, so it is the one cell guaranteed to open the record.
            var gutter = make('td', 'freeze-1 gutter', offset + index + 1);
            gutter.title = 'Open this record';
            tr.appendChild(gutter);

            tr.appendChild(bodyCell(fields, title, row, 'freeze-2'));
            rest.forEach(function (name) { tr.appendChild(bodyCell(fields, name, row, null)); });

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

    function renderFilters(blueprint) {
        clear(filtersEl);
        var fields = blueprint ? fieldsByName(blueprint) : {};
        Object.keys(state.filters).forEach(function (column) {
            var field = fields[column];
            var split = state.filters[column].split(':');
            var operator = split.shift();
            var phrase = (operatorsFor(field || { type: 'text' }).filter(function (pair) {
                return pair[0] === operator;
            })[0] || [operator, operator])[1];

            var tag = make('span', 'tag');
            tag.appendChild(make('span', null, (field ? field.label : column) + ' ' + phrase + ' ' + split.join(':')));
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
            syncAirtableBtn.hidden = !(state.base === 'core' && state.table === 'actions');
            renderFilters(blueprint);
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
                var cell = record.data[field.name];
                var key = make('div', 'k', field.label);
                if (field.type === 'link' && cell && cell.count) key.appendChild(make('span', 'count', ' ' + cell.count));
                row.appendChild(key);
                var value = make('div', 'v');
                if (field.type === 'json' && cell && cell.v !== null && cell.v !== undefined && cell.v !== '') {
                    renderJsonDetail(value, cell.v);
                } else {
                    renderCell(value, field, cell);
                }
                if (field.type === 'link' && cell && cell.count) value.appendChild(reverseLink(field, record));
                row.appendChild(value);
                body.appendChild(row);
            });
        } catch (error) {
            header.firstChild.textContent = 'Could not open that record';
            body.appendChild(make('div', 'state', error.message));
        }
    }

    /**
     * The other end of a link, as a question. The chips say who is linked; this opens
     * the linked table already narrowed to this record, which is the thing the chips
     * cannot answer on their own.
     */
    function reverseLink(field, record) {
        var go = make('button', 'ghost mini', 'Show all');
        go.type = 'button';
        go.title = 'Open ' + field.target + ' narrowed to this record';
        go.addEventListener('click', async function (event) {
            event.stopPropagation();
            try {
                var target = await loadSchema(state.base, field.target);
                var back = (target.fields || []).filter(function (candidate) {
                    return candidate.type === 'link'
                        && candidate.target === record.table
                        && candidate.via === field.via
                        && candidate.variant === field.variant;
                })[0];
                if (!back) { toast('That link has no way back from ' + field.target + '.', 'bad'); return; }

                var narrowed = {};
                narrowed[back.name] = 'eq:' + record.id;
                selectTable(state.base, field.target, narrowed);
            } catch (error) {
                toast(error.message, 'bad');
            }
        });

        return go;
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

    // --------------------------------------------------------------- menus

    function closeMenu() { clear(menuEl); }

    function openMenu(anchor) {
        closeMenu();
        var box = make('div', 'menu');
        menuEl.appendChild(box);
        var edge = anchor.getBoundingClientRect();
        box.style.top = (edge.bottom + 6) + 'px';
        box.style.left = Math.max(8, Math.min(edge.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
        return box;
    }

    // What each type can sensibly be asked. A link is asked about its records, not its
    // value, which is the whole difference between this and a column filter.
    var OPERATORS = {
        link: [['filled', 'has any'], ['empty', 'has none'], ['contains', 'linked name contains'], ['eq', 'linked record id is']],
        reference: [['eq', 'is'], ['empty', 'is empty'], ['filled', 'is set']],
        select: [['eq', 'is'], ['ne', 'is not'], ['in', 'is any of'], ['empty', 'is empty'], ['filled', 'is set']],
        boolean: [['eq', 'is']],
        date: [['eq', 'is'], ['gte', 'on or after'], ['lte', 'on or before'], ['empty', 'is empty'], ['filled', 'is set']],
        number: [['eq', 'is'], ['ne', 'is not'], ['gte', 'at least'], ['lte', 'at most'], ['empty', 'is empty'], ['filled', 'is set']],
        text: [['contains', 'contains'], ['starts', 'starts with'], ['eq', 'is'], ['ne', 'is not'], ['empty', 'is empty'], ['filled', 'is set']]
    };

    function operatorsFor(field) {
        if (field.type === 'link') return OPERATORS.link;
        if (field.type === 'reference') return OPERATORS.reference;
        if (field.type === 'select') return OPERATORS.select;
        if (field.type === 'boolean') return OPERATORS.boolean;
        if (field.type === 'date' || field.type === 'datetime') return OPERATORS.date;
        if (field.numeric) return OPERATORS.number;
        return OPERATORS.text;
    }

    function option(select, value, label) {
        var node = make('option', null, label);
        node.value = value;
        select.appendChild(node);
        return node;
    }

    function showFilterMenu(anchor, preselect) {
        if (!lastBlueprint) return;
        var box = openMenu(anchor);
        box.appendChild(make('h4', null, 'Narrow this table'));

        var columnSelect = make('select');
        var usable = (lastBlueprint.fields || []).filter(function (field) {
            return !field.locked && field.type !== 'id' && field.type !== 'json';
        });
        usable.forEach(function (field) { option(columnSelect, field.name, field.label); });
        if (preselect) columnSelect.value = preselect;
        box.appendChild(columnSelect);

        var operatorSelect = make('select');
        box.appendChild(operatorSelect);

        var valueWrap = make('div');
        box.appendChild(valueWrap);

        function fieldNow() {
            return usable.filter(function (field) { return field.name === columnSelect.value; })[0];
        }

        function drawValue() {
            clear(valueWrap);
            var field = fieldNow();
            var operator = operatorSelect.value;
            if (!field || operator === 'empty' || operator === 'filled') return;

            if (field.type === 'select' && field.options && field.options.length && operator !== 'in') {
                var choices = make('select');
                field.options.forEach(function (value) { option(choices, value, value); });
                valueWrap.appendChild(choices);
                return;
            }

            var input = make('input');
            input.type = field.type === 'date' ? 'date' : 'text';
            if (operator === 'in') input.placeholder = 'one | per | line';
            else if (field.type === 'link') input.placeholder = operator === 'eq' ? 'record id' : 'part of the name';
            valueWrap.appendChild(input);
            input.focus();
        }

        function drawOperators() {
            clear(operatorSelect);
            var field = fieldNow();
            if (!field) return;
            operatorsFor(field).forEach(function (pair) { option(operatorSelect, pair[0], pair[1]); });
            drawValue();
        }

        columnSelect.addEventListener('change', drawOperators);
        operatorSelect.addEventListener('change', drawValue);
        drawOperators();

        var actions = make('div', 'actions');
        var cancel = make('button', null, 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', closeMenu);
        var apply = make('button', 'go', 'Apply');
        apply.type = 'button';
        apply.addEventListener('click', function () {
            var operator = operatorSelect.value;
            var control = valueWrap.firstChild;
            var value = control ? String(control.value).trim() : '';
            if (control && value === '') { control.focus(); return; }
            state.filters[columnSelect.value] = operator + (control ? ':' + value : '');
            state.page = 1;
            closeMenu();
            reload(true);
        });
        actions.appendChild(cancel);
        actions.appendChild(apply);
        box.appendChild(actions);

        columnSelect.focus();
    }

    async function showViewsMenu(anchor) {
        if (!state.base || !state.table) return;
        var box = openMenu(anchor);
        box.appendChild(make('h4', null, 'Saved views'));
        var list = make('div');
        list.appendChild(make('div', 'none', 'Loading…'));
        box.appendChild(list);

        var name = make('input');
        name.type = 'text';
        name.placeholder = 'Name this view';
        box.appendChild(name);

        var grants = (navCache[state.base] || {}).grants;
        var share = null;
        if (grants && grants.share_views) {
            var label = make('label');
            share = make('input');
            share.type = 'checkbox';
            label.appendChild(share);
            label.appendChild(make('span', null, 'Everyone can see it'));
            box.appendChild(label);
        }

        var actions = make('div', 'actions');
        var save = make('button', 'go', 'Save this view');
        save.type = 'button';
        save.addEventListener('click', async function () {
            if (name.value.trim() === '') { name.focus(); return; }
            try {
                await send('POST', apiUrl(state.base, '_views', state.table), {
                    name: name.value.trim(),
                    query: viewQuery(),
                    shared: share ? share.checked : false
                });
                closeMenu();
                toast('Saved "' + name.value.trim() + '"', 'ok');
            } catch (error) {
                toast(error.message, 'bad');
            }
        });
        actions.appendChild(save);
        box.appendChild(actions);

        try {
            var payload = await getJson(apiUrl(state.base, '_views', state.table));
            clear(list);
            if (payload.views.length === 0) {
                list.appendChild(make('div', 'none', 'None yet. The filters and sort on screen are what gets saved.'));
            }
            payload.views.forEach(function (view) {
                var item = make('button', 'item');
                item.type = 'button';
                item.appendChild(make('span', 'name', view.name));
                if (!view.mine) item.appendChild(make('span', 'who', view.owner_id_no || 'shared'));
                else if (view.shared) item.appendChild(make('span', 'who', 'shared'));
                item.addEventListener('click', function () { closeMenu(); applyView(view.query); });
                list.appendChild(item);

                if (!view.mine && !(grants && grants.share_views)) return;
                var drop = make('span', 'drop', '×');
                drop.title = 'Delete this view';
                drop.addEventListener('click', async function (event) {
                    event.stopPropagation();
                    try {
                        await send('DELETE', apiUrl(state.base, '_views', String(view.id)));
                        item.parentNode.removeChild(item);
                    } catch (error) {
                        toast(error.message, 'bad');
                    }
                });
                item.appendChild(drop);
            });
        } catch (error) {
            clear(list);
            list.appendChild(make('div', 'none', error.message));
        }
    }

    // What a view is: the question, without the page it was left on.
    function viewQuery() {
        var p = new URLSearchParams();
        if (state.cols === 'all') p.set('cols', 'all');
        if (state.q) p.set('q', state.q);
        if (state.sort) p.set('sort', state.sort);
        Object.keys(state.filters).forEach(function (key) {
            p.set('filter[' + key + ']', state.filters[key]);
        });
        return p.toString();
    }

    function applyView(query) {
        var p = new URLSearchParams(query);
        state.cols = p.get('cols') === 'all' ? 'all' : 'key';
        state.q = p.get('q') || '';
        state.sort = p.get('sort') || '';
        state.page = 1;
        state.filters = {};
        p.forEach(function (value, key) {
            var hit = /^filter\[(.+)\]$/.exec(key);
            if (hit) state.filters[hit[1]] = value;
        });
        searchEl.value = state.q;
        presetEl.querySelectorAll('button').forEach(function (node) {
            node.classList.toggle('active', node.dataset.cols === state.cols);
        });
        reload(false);
    }

    // ------------------------------------------------------------- editing

    function editorFor(field, value) {
        if (field.type === 'boolean') {
            var flag = make('select');
            option(flag, '', '—');
            option(flag, 'true', 'true');
            option(flag, 'false', 'false');
            flag.value = value === null || value === undefined ? '' : (Number(value) === 1 ? 'true' : 'false');
            return flag;
        }

        if (field.type === 'select' && field.options && field.options.length) {
            var choices = make('select');
            option(choices, '', '—');
            var known = false;
            field.options.forEach(function (each) {
                option(choices, each, each);
                if (String(each) === String(value)) known = true;
            });
            // An existing value outside the sampled palette must not be silently lost.
            if (!known && value !== null && value !== undefined && value !== '') option(choices, String(value), String(value));
            choices.value = value === null || value === undefined ? '' : String(value);
            return choices;
        }

        var input = make('input');
        input.type = field.type === 'date' ? 'date' : 'text';
        input.value = value === null || value === undefined ? '' : String(value);
        return input;
    }

    function beginEdit(td) {
        if (!lastBlueprint || !lastPayload || td.classList.contains('editing')) return;
        var field = fieldsByName(lastBlueprint)[td.dataset.col];
        if (!field || !field.editable) return;

        var tr = td.closest('tr[data-rid]');
        if (!tr) return;
        var index = Number(tr.dataset.index);
        var cell = lastPayload.data[index] ? lastPayload.data[index][field.name] : null;
        var was = cell && cell.v !== undefined ? cell.v : null;

        clear(td);
        td.classList.add('editing');
        var editor = editorFor(field, was);
        td.appendChild(editor);
        editor.focus();
        if (editor.select) editor.select();

        var settled = false;
        function finish(save) {
            if (settled) return;
            settled = true;
            var next = editor.value === '' ? null : editor.value;
            if (!save || String(next) === String(was === null ? '' : was)) {
                repaintRow(tr, index);
                return;
            }
            commitEdit(td, tr, index, field, was, next);
        }

        editor.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); finish(true); }
            if (event.key === 'Escape') { event.preventDefault(); finish(false); }
        });
        editor.addEventListener('blur', function () { finish(true); });
    }

    function repaintRow(tr, index) {
        var fields = fieldsByName(lastBlueprint);
        var row = lastPayload.data[index] || {};
        Array.prototype.forEach.call(tr.querySelectorAll('td[data-col]'), function (node) {
            var field = fields[node.dataset.col];
            if (!field) return;
            clear(node);
            node.classList.remove('editing', 'saving', 'num', 'mono');
            node.title = '';
            renderCell(node, field, row[node.dataset.col]);
        });
    }

    async function commitEdit(td, tr, index, field, was, next) {
        var changes = {};
        var expect = {};
        changes[field.name] = next;
        expect[field.name] = was;

        clear(td);
        td.classList.remove('editing');
        td.classList.add('saving');
        td.appendChild(document.createTextNode(next === null ? '—' : String(next)));

        try {
            var result = await send('PATCH', apiUrl(state.base, '_grid', state.table, tr.dataset.rid), {
                changes: changes,
                expect: expect
            });
            Object.keys(result.data).forEach(function (name) {
                lastPayload.data[index][name] = result.data[name];
            });
            td.classList.remove('saving');
            repaintRow(tr, index);
            if (result.action_id) toast('Saved. Logged as action ' + result.action_id + '.', 'ok');
        } catch (error) {
            td.classList.remove('saving');
            repaintRow(tr, index);
            toast(error.message, 'bad');
            if (error.status === 409) reload(true);
        }
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

    function selectTable(base, table, filters) {
        var changedBase = base !== state.base;
        state.base = base;
        state.table = table;
        state.page = 1;
        state.q = '';
        // A history table reads newest-first by default; every other table keeps the
        // grid's own default (its key, ascending) unless a click or a saved view says otherwise.
        state.sort = (base === 'core' && table === 'actions') ? 'created_at:desc' : '';
        state.row = null;
        state.filters = filters || {};
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
        syncAirtableBtn.hidden = true;
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
        if (event.target.closest('td.editing')) return;

        var chip = event.target.closest('.chip[data-to]');
        if (chip) {
            event.stopPropagation();
            var owner = chip.closest('td[data-col]');
            // Shift asks the opposite question: not "what is this record" but "what
            // else here is linked to it".
            if (event.shiftKey && owner) {
                state.filters[owner.dataset.col] = 'eq:' + chip.dataset.rid;
                state.page = 1;
                reload(true);
                return;
            }
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

        var head = event.target.closest('thead th');
        if (head) {
            if (head.dataset.sort) {
                var column = head.dataset.sort;
                var current = state.sort.split(':');
                state.sort = current[0] === column && current[1] !== 'desc' ? column + ':desc' : column + ':asc';
                state.page = 1;
                reload(false);
                return;
            }
            // A link column cannot be sorted, but it is the one most worth filtering.
            var field = lastBlueprint ? fieldsByName(lastBlueprint)[head.dataset.col] : null;
            if (field && field.type === 'link') showFilterMenu(head, field.name);
            return;
        }

        // An editable cell belongs to the editor, not to the peek: a single click that
        // opened a panel over the cell made the second click of a double land on it.
        if (event.target.closest('td.editable')) return;

        var row = event.target.closest('tr[data-rid]');
        if (row) openRow(row.dataset.rid);
    });

    gridWrap.addEventListener('dblclick', function (event) {
        var td = event.target.closest('td.editable');
        if (td) {
            event.stopPropagation();
            beginEdit(td);
        }
    });

    addFilterBtn.addEventListener('click', function () { showFilterMenu(addFilterBtn, null); });
    openViewsBtn.addEventListener('click', function () { showViewsMenu(openViewsBtn); });

    document.addEventListener('mousedown', function (event) {
        if (!menuEl.firstChild) return;
        if (event.target.closest('#menu, #add-filter, #open-views')) return;
        closeMenu();
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

    // Placeholder: the drain that would actually push these rows to Airtable is not
    // built yet (ADMIN_MISSING.md §3). Says so rather than pretending to do something.
    syncAirtableBtn.addEventListener('click', function () {
        toast('Airtable sync is not built yet. This button does not do anything.', 'bad');
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
        if (menuEl.firstChild) { closeMenu(); return; }
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
        renderGrants(null);
        clear(railEl);
        clear(peekEl);
        closeMenu();
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
            renderGrants(null);
            stateMessage(railEl, 'Could not list tables', error.message);
            stateMessage(
                gridWrap,
                error.status === 403 ? 'No workspace for this account' : 'Could not open ' + state.base,
                error.message,
            );
            countEl.textContent = '--';
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
