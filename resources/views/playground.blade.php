<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IKAIKA Platform — API playground</title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --paper: #f8fafc;
            --surface: #ffffff;
            --fill: #0f172a;
            --rail: #0b1220;
            --rail-ink: #e2e8f0;
            --rail-muted: #94a3b8;
            --accent: #2563eb;
            --ok: #16a34a;
            --bad: #dc2626;
            --warn: #ca8a04;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            color: var(--ink);
            background: var(--paper);
            font: 14px/1.45 ui-sans-serif, system-ui, Segoe UI, sans-serif;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }
        .brand { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .brand strong { font-size: 14px; letter-spacing: 0.04em; }
        .brand span, .crumb { color: var(--muted); font-size: 12px; }
        .crumb { letter-spacing: 0.06em; text-transform: uppercase; }
        .auth {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .auth input, .bar input, .bar select, .bar textarea {
            border: 1px solid var(--line);
            background: var(--paper);
            padding: 7px 10px;
            font: 13px/1.4 ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .auth input { width: 160px; }
        .auth button, .bar button {
            border: 0;
            background: var(--fill);
            color: white;
            padding: 7px 12px;
            font: inherit;
            cursor: pointer;
        }
        .auth button.ghost {
            background: transparent;
            color: var(--ink);
            border: 1px solid var(--line);
        }
        .who { font-size: 12px; color: var(--muted); }
        .layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: calc(100% - 57px);
        }
        aside {
            background: var(--rail);
            color: var(--rail-ink);
            overflow: auto;
            padding: 12px 0 28px;
        }
        .products {
            display: grid;
            grid-template-columns: 1fr;
            gap: 4px;
            padding: 0 12px 12px;
        }
        .products button {
            text-align: left;
            border: 1px solid transparent;
            background: transparent;
            color: var(--rail-ink);
            padding: 8px 10px;
            font: inherit;
            cursor: pointer;
            border-radius: 8px;
        }
        .products button:hover { background: rgba(255,255,255,0.04); }
        .products button.active {
            background: rgba(37, 99, 235, 0.18);
            border-color: rgba(37, 99, 235, 0.45);
        }
        .products .meta { color: var(--rail-muted); }
        .group {
            padding: 12px 16px 6px;
            color: var(--rail-muted);
            font-size: 10px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        details.branch { padding: 0; }
        details.branch > summary {
            list-style: none;
            cursor: pointer;
            padding: 8px 16px;
            font-size: 13px;
        }
        details.branch > summary::-webkit-details-marker { display: none; }
        details.branch > summary::after {
            content: '';
            float: right;
            margin-top: 6px;
            border: 4px solid transparent;
            border-top-color: var(--rail-muted);
        }
        details.branch[open] > summary::after {
            margin-top: 2px;
            border-top-color: transparent;
            border-bottom-color: var(--rail-muted);
        }
        button.route {
            display: block;
            width: 100%;
            text-align: left;
            border: 0;
            background: transparent;
            padding: 8px 16px 8px 28px;
            font: inherit;
            color: inherit;
            cursor: pointer;
            border-left: 2px solid transparent;
        }
        button.route.top { padding-left: 16px; }
        button.route:hover { background: rgba(255,255,255,0.04); }
        button.route.active {
            border-left-color: var(--accent);
            background: rgba(37, 99, 235, 0.22);
        }
        .meta { display: block; color: var(--rail-muted); font-size: 11px; }
        .pill {
            display: inline-block;
            font-size: 10px;
            padding: 1px 6px;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 999px;
            color: var(--rail-muted);
        }
        .pill.ok { color: #86efac; border-color: rgba(134,239,172,0.35); }
        .pill.bad { color: #fca5a5; border-color: rgba(252,165,165,0.35); }
        .pill.off { color: #fde68a; border-color: rgba(253,230,138,0.35); }
        main { display: flex; flex-direction: column; min-width: 0; }
        .bar {
            display: grid;
            grid-template-columns: 88px 1fr auto;
            gap: 8px;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }
        .bar textarea {
            grid-column: 1 / -1;
            min-height: 72px;
            resize: vertical;
            display: none;
        }
        .bar.has-body textarea { display: block; }
        .status { padding: 8px 16px; border-bottom: 1px solid var(--line); color: var(--muted); font-size: 12px; }
        pre {
            margin: 0;
            padding: 16px 20px 40px;
            overflow: auto;
            flex: 1;
            font: 12px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .empty { color: var(--rail-muted); padding: 24px 16px; }
        .note { padding: 4px 16px 10px; color: var(--rail-muted); font-size: 11px; }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <strong>IKAIKA</strong>
            <span>API playground · {{ $channel }}</span>
            <div class="crumb" id="crumb">Choose a product</div>
        </div>
        <form class="auth" id="login-form" hidden>
            <input id="id_no" name="id_no" placeholder="Employee ID no." autocomplete="username" spellcheck="false">
            <button type="submit">Sign in</button>
            <span class="who" id="who"></span>
            <button type="button" class="ghost" id="sign-out" hidden>Sign out</button>
        </form>
    </header>
    <div class="layout">
        <aside>
            <div class="products" id="products"></div>
            <nav id="nav">
                <p class="empty">Loading catalog…</p>
            </nav>
        </aside>
        <main>
            <form class="bar" id="form">
                <select id="method" aria-label="HTTP method">
                    <option>GET</option>
                    <option>POST</option>
                    <option>PATCH</option>
                    <option>PUT</option>
                    <option>DELETE</option>
                </select>
                <input id="path" name="path" value="{{ $apiRoot }}/{{ $channel }}/portal" spellcheck="false" autocomplete="off">
                <button type="submit">Run</button>
                <textarea id="body" spellcheck="false" placeholder='{"role":"Admin"}'></textarea>
            </form>
            <div class="status" id="status">Ready. Sign in to call protected portal routes.</div>
            <pre id="out">{}</pre>
        </main>
    </div>
    <script>
        const catalogUrl = @json($catalogUrl);
        const apiRoot = @json($apiRoot);
        const SESSION_KEY = 'ikaika.playground.session';
        const PRODUCT_KEY = 'ikaika.playground.product';

        const nav = document.getElementById('nav');
        const productsEl = document.getElementById('products');
        const pathInput = document.getElementById('path');
        const methodEl = document.getElementById('method');
        const bodyEl = document.getElementById('body');
        const form = document.getElementById('form');
        const statusEl = document.getElementById('status');
        const outEl = document.getElementById('out');
        const crumbEl = document.getElementById('crumb');
        const loginForm = document.getElementById('login-form');
        const idNoInput = document.getElementById('id_no');
        const whoEl = document.getElementById('who');
        const signOutBtn = document.getElementById('sign-out');

        let catalog = null;
        let currentProduct = null;
        let crumb = [];

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[char]));
        }

        function pill(ok, enabled) {
            if (!enabled) return '<span class="pill off">off</span>';
            return ok ? '<span class="pill ok">on</span>' : '<span class="pill bad">error</span>';
        }

        function readSession() {
            try {
                return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
            } catch {
                return null;
            }
        }

        function writeSession(session) {
            if (session === null) sessionStorage.removeItem(SESSION_KEY);
            else sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
            renderAuth();
        }

        function token() {
            return readSession()?.token ?? null;
        }

        function renderAuth() {
            const session = readSession();
            const employee = session?.employee;
            const canLogin = Boolean(currentProduct?.auth?.length);
            loginForm.hidden = !canLogin;
            if (!canLogin) return;
            if (employee) {
                const name = [employee.first_name, employee.last_name].filter(Boolean).join(' ') || employee.id_no;
                const rank = employee.role_level === 'Executive' ? 'Executive' : employee.role;
                whoEl.textContent = name + ' · ' + rank;
                idNoInput.hidden = true;
                loginForm.querySelector('button[type="submit"]').hidden = true;
                signOutBtn.hidden = false;
            } else {
                whoEl.textContent = '';
                idNoInput.hidden = false;
                loginForm.querySelector('button[type="submit"]').hidden = false;
                signOutBtn.hidden = true;
            }
        }

        function setActive(path, method) {
            nav.querySelectorAll('button.route').forEach((btn) => {
                const samePath = btn.dataset.path === path;
                const sameMethod = !btn.dataset.method || btn.dataset.method === method;
                btn.classList.toggle('active', samePath && sameMethod);
            });
        }

        function syncBodyVisibility() {
            const method = methodEl.value;
            form.classList.toggle('has-body', method !== 'GET' && method !== 'DELETE');
        }

        function toRequestUrl(path) {
            const trimmed = path.trim();
            if (/^https?:\/\//i.test(trimmed)) return trimmed;
            const mount = window.location.pathname.replace(/\/+$/, '');
            if (mount !== '' && (trimmed === '/api' || trimmed.startsWith('/api/'))) {
                return mount + trimmed;
            }
            return trimmed;
        }

        function headersFor(method) {
            const headers = { Accept: 'application/json' };
            const bearer = token();
            if (bearer) headers.Authorization = 'Bearer ' + bearer;
            if (method !== 'GET' && method !== 'DELETE') headers['Content-Type'] = 'application/json';
            return headers;
        }

        async function run() {
            const method = methodEl.value;
            const path = pathInput.value.trim();
            const url = toRequestUrl(path);
            pathInput.value = url;
            setActive(url, method);
            const started = performance.now();
            statusEl.textContent = 'Requesting…';
            const options = { method, headers: headersFor(method) };
            if (method !== 'GET' && method !== 'DELETE') {
                const raw = bodyEl.value.trim();
                if (raw !== '') options.body = raw;
            }
            try {
                const response = await fetch(url, options);
                const text = await response.text();
                let parsed = text;
                try { parsed = JSON.stringify(JSON.parse(text), null, 2); } catch {}
                const ms = Math.round(performance.now() - started);
                statusEl.textContent = method + ' ' + response.status + ' ' + response.statusText + ' · ' + ms + ' ms';
                outEl.textContent = parsed;
                if (response.status === 401 && currentProduct?.auth?.length && !token()) {
                    statusEl.textContent += ' · sign in with an employee ID number';
                }
            } catch (error) {
                statusEl.textContent = 'Request failed';
                outEl.textContent = String(error);
            }
        }

        function applyOperation(operation, trail) {
            methodEl.value = operation.method || 'GET';
            pathInput.value = operation.url;
            if (operation.body && typeof operation.body === 'object') {
                bodyEl.value = JSON.stringify(operation.body, null, 2);
            } else if ((operation.method || 'GET') === 'GET') {
                bodyEl.value = '';
            }
            crumb = trail;
            crumbEl.textContent = trail.join(' / ');
            syncBodyVisibility();
            run();
        }

        function productByKey(key) {
            return (catalog?.products ?? []).find((item) => item.key === key) ?? null;
        }

        function renderProducts() {
            productsEl.innerHTML = (catalog.products || []).map((product) => {
                const active = currentProduct?.key === product.key ? ' active' : '';
                return `<button type="button" class="${active.trim()}" data-product="${escapeHtml(product.key)}">
                    <strong>${escapeHtml(product.name)}</strong>
                    ${pill(product.health?.ok, product.enabled)}
                    <span class="meta">${escapeHtml(product.key)}</span>
                </button>`;
            }).join('');
        }

        function renderTree() {
            const product = currentProduct;
            if (!product) {
                nav.innerHTML = '<p class="empty">Choose a product.</p>';
                return;
            }
            const bits = [];
            bits.push(`<button class="route top" type="button" data-method="GET" data-path="${escapeHtml(product.base_url)}" data-trail="${escapeHtml(product.name)}">Overview<span class="meta">GET ${escapeHtml(product.base_url)}</span></button>`);

            (product.utilities || []).forEach((op) => {
                bits.push(`<button class="route top" type="button" data-method="${escapeHtml(op.method)}" data-path="${escapeHtml(op.url)}" data-trail="${escapeHtml(product.name)} / ${escapeHtml(op.label)}">${escapeHtml(op.label)}<span class="meta">${escapeHtml(op.method)}</span></button>`);
            });

            if ((product.auth || []).length) {
                bits.push('<div class="group">Auth</div>');
                product.auth.forEach((op) => {
                    const body = op.body ? ` data-body="${escapeHtml(JSON.stringify(op.body))}"` : '';
                    bits.push(`<button class="route top" type="button" data-method="${escapeHtml(op.method)}" data-path="${escapeHtml(op.url)}" data-trail="${escapeHtml(product.name)} / Auth / ${escapeHtml(op.label)}"${body}>${escapeHtml(op.label)}<span class="meta">${escapeHtml(op.method)}</span></button>`);
                });
            }

            if ((product.sections || []).length) {
                bits.push('<div class="group">Separated</div>');
                product.sections.forEach((section) => {
                    bits.push(`<details class="branch" open><summary>${escapeHtml(section.label)}</summary>`);
                    (section.resources || []).forEach((resource) => {
                        (resource.operations || [{ method: 'GET', url: resource.url, label: resource.label }]).forEach((op) => {
                            const body = op.body ? ` data-body="${escapeHtml(JSON.stringify(op.body))}"` : '';
                            bits.push(`<button class="route" type="button" data-method="${escapeHtml(op.method)}" data-path="${escapeHtml(op.url)}" data-trail="${escapeHtml(product.name)} / ${escapeHtml(section.label)} / ${escapeHtml(op.label)}"${body}>${escapeHtml(op.label)}<span class="meta">${escapeHtml(op.method)} · ${escapeHtml(resource.label)}</span></button>`);
                        });
                    });
                    bits.push('</details>');
                });
            } else {
                bits.push('<div class="group">Separated</div><div class="note">No sectioned API on this product yet.</div>');
            }

            bits.push('<div class="group">Practice</div>');
            if ((product.resources || []).length) {
                bits.push('<details class="branch" open><summary>Generic dumps</summary>');
                product.resources.forEach((resource) => {
                    const count = resource.count == null ? '' : ` · ${resource.count}`;
                    bits.push(`<button class="route" type="button" data-method="GET" data-path="${escapeHtml(resource.url)}" data-trail="${escapeHtml(product.name)} / Practice / ${escapeHtml(resource.label)}">${escapeHtml(resource.label)}<span class="meta">GET${count}</span></button>`);
                });
                bits.push('</details>');
            } else {
                bits.push('<div class="note">No practice resources. Enable the product when its database exists.</div>');
            }

            nav.innerHTML = bits.join('');
        }

        function selectProduct(key, andRun) {
            currentProduct = productByKey(key) || catalog.products[0] || null;
            if (currentProduct) sessionStorage.setItem(PRODUCT_KEY, currentProduct.key);
            renderProducts();
            renderTree();
            renderAuth();
            crumb = currentProduct ? [currentProduct.name] : [];
            crumbEl.textContent = crumb.join(' / ') || 'Choose a product';
            if (andRun && currentProduct) {
                methodEl.value = 'GET';
                pathInput.value = currentProduct.base_url;
                bodyEl.value = '';
                syncBodyVisibility();
                run();
            }
        }

        productsEl.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-product]');
            if (!btn) return;
            selectProduct(btn.dataset.product, true);
        });

        nav.addEventListener('click', (event) => {
            const btn = event.target.closest('button.route');
            if (!btn) return;
            const trail = (btn.dataset.trail || '').split(' / ').filter(Boolean);
            const operation = {
                method: btn.dataset.method || 'GET',
                url: btn.dataset.path,
                body: btn.dataset.body ? JSON.parse(btn.dataset.body) : null,
            };
            applyOperation(operation, trail);
        });

        methodEl.addEventListener('change', syncBodyVisibility);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            run();
        });

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const login = (currentProduct?.auth || []).find((op) => op.method === 'POST');
            if (!login) return;
            methodEl.value = 'POST';
            pathInput.value = login.url;
            bodyEl.value = JSON.stringify({ id_no: idNoInput.value.trim() }, null, 2);
            syncBodyVisibility();
            const url = toRequestUrl(login.url);
            statusEl.textContent = 'Signing in…';
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_no: idNoInput.value.trim() }),
                });
                const payload = await response.json();
                outEl.textContent = JSON.stringify(payload, null, 2);
                statusEl.textContent = 'POST ' + response.status + ' ' + response.statusText;
                if (response.ok && payload.token && payload.employee) {
                    writeSession({ token: payload.token, employee: payload.employee });
                    idNoInput.value = '';
                }
            } catch (error) {
                statusEl.textContent = 'Sign in failed';
                outEl.textContent = String(error);
            }
        });

        signOutBtn.addEventListener('click', () => {
            writeSession(null);
            statusEl.textContent = 'Signed out.';
        });

        async function boot() {
            try {
                const response = await fetch(toRequestUrl(catalogUrl), { headers: { Accept: 'application/json' } });
                catalog = await response.json();
                const portal = productByKey('portal');
                const session = readSession();
                if (session?.token && portal?.auth?.length) {
                    const me = portal.auth.find((op) => op.method === 'GET');
                    if (me) {
                        const check = await fetch(toRequestUrl(me.url), {
                            headers: { Accept: 'application/json', Authorization: 'Bearer ' + session.token },
                        });
                        if (!check.ok) writeSession(null);
                    }
                }
                const saved = sessionStorage.getItem(PRODUCT_KEY);
                const initial = productByKey(saved) || portal || catalog.products[0];
                selectProduct(initial?.key, true);
            } catch (error) {
                nav.innerHTML = '<p class="empty">Could not load catalog. Is the API running?</p>';
                outEl.textContent = String(error);
            }
        }

        syncBodyVisibility();
        boot();
    </script>
</body>
</html>
