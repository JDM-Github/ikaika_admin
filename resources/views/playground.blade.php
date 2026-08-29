<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IKAIKA Platform — API playground</title>
    <style>
        :root {
            --ink: #1c1917;
            --muted: #78716c;
            --line: #e7e5e4;
            --paper: #fafaf9;
            --surface: #ffffff;
            --fill: #1c1917;
            --ok: #166534;
            --bad: #b91c1c;
            --warn: #a16207;
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
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }
        header strong { font-size: 15px; letter-spacing: 0.02em; }
        header span { color: var(--muted); font-size: 12px; }
        .layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: calc(100% - 49px);
        }
        aside {
            border-right: 1px solid var(--line);
            background: var(--surface);
            overflow: auto;
            padding: 12px 0 24px;
        }
        .group { padding: 10px 16px 4px; color: var(--muted); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; }
        button.route, a.route {
            display: block;
            width: 100%;
            text-align: left;
            border: 0;
            background: transparent;
            padding: 8px 16px;
            font: inherit;
            color: inherit;
            cursor: pointer;
            border-left: 2px solid transparent;
        }
        button.route:hover, a.route:hover { background: var(--paper); }
        button.route.active { border-left-color: var(--ink); background: var(--paper); }
        .meta { display: block; color: var(--muted); font-size: 12px; }
        .pill {
            display: inline-block;
            font-size: 11px;
            padding: 1px 6px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted);
        }
        .pill.ok { color: var(--ok); border-color: #bbf7d0; }
        .pill.bad { color: var(--bad); border-color: #fecaca; }
        .pill.off { color: var(--warn); border-color: #fde68a; }
        main { display: flex; flex-direction: column; min-width: 0; }
        .bar {
            display: flex;
            gap: 8px;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }
        .bar input {
            flex: 1;
            min-width: 0;
            border: 1px solid var(--line);
            background: var(--paper);
            padding: 8px 10px;
            font: 13px/1.4 ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .bar button {
            border: 0;
            background: var(--fill);
            color: white;
            padding: 8px 14px;
            font: inherit;
            cursor: pointer;
        }
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
        .empty { color: var(--muted); padding: 40px 20px; }
    </style>
</head>
<body>
    <header>
        <div>
            <strong>IKAIKA</strong>
            <span>central API playground · {{ $channel }}</span>
        </div>
        <span id="hint">Click a route, or edit the path and run.</span>
    </header>
    <div class="layout">
        <aside id="nav">
            <p class="empty">Loading catalog…</p>
        </aside>
        <main>
            <form class="bar" id="form">
                <input id="path" name="path" value="/api/{{ $channel }}/portal" spellcheck="false" autocomplete="off">
                <button type="submit">Run</button>
            </form>
            <div class="status" id="status">Ready.</div>
            <pre id="out">{}</pre>
        </main>
    </div>
    <script>
        const catalogUrl = @json($catalogUrl);
        const nav = document.getElementById('nav');
        const pathInput = document.getElementById('path');
        const statusEl = document.getElementById('status');
        const outEl = document.getElementById('out');
        const form = document.getElementById('form');

        function pill(ok, enabled) {
            if (!enabled) return '<span class="pill off">off</span>';
            return ok
                ? '<span class="pill ok">connected</span>'
                : '<span class="pill bad">error</span>';
        }

        function setActive(path) {
            nav.querySelectorAll('button.route').forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.path === path);
            });
        }

        async function run(path) {
            const url = path.startsWith('http') ? path : path;
            pathInput.value = url;
            setActive(url);
            const started = performance.now();
            statusEl.textContent = 'Requesting…';
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const text = await response.text();
                let body = text;
                try { body = JSON.stringify(JSON.parse(text), null, 2); } catch {}
                const ms = Math.round(performance.now() - started);
                statusEl.textContent = response.status + ' ' + response.statusText + ' · ' + ms + ' ms · ' + url;
                outEl.textContent = body;
            } catch (error) {
                statusEl.textContent = 'Request failed';
                outEl.textContent = String(error);
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            run(pathInput.value.trim());
        });

        async function boot() {
            try {
                const response = await fetch(catalogUrl, { headers: { Accept: 'application/json' } });
                const catalog = await response.json();
                const bits = [];
                bits.push('<div class="group">Platform</div>');
                bits.push(`<button class="route" data-path="/api/${catalog.channel}" type="button"><strong>GET /api/${catalog.channel}</strong><span class="meta">product catalog</span></button>`);

                for (const product of catalog.products) {
                    bits.push(`<div class="group">${product.name} ${pill(product.health?.ok, product.enabled)}</div>`);
                    bits.push(`<button class="route" data-path="${product.base_url}" type="button"><strong>GET ${product.base_url}</strong><span class="meta">${product.database || 'no database'} · ${product.connection}</span></button>`);
                    bits.push(`<button class="route" data-path="${product.base_url}/health" type="button">GET ${product.base_url}/health</button>`);
                    for (const resource of product.resources) {
                        bits.push(`<button class="route" data-path="${resource.url}" type="button">GET ${resource.url}<span class="meta">${resource.label}</span></button>`);
                    }
                    if (!product.enabled) {
                        bits.push('<div class="meta" style="padding:4px 16px 10px">Enable in .env when this database exists.</div>');
                    }
                }

                nav.innerHTML = bits.join('');
                nav.addEventListener('click', (event) => {
                    const btn = event.target.closest('button.route');
                    if (!btn) return;
                    run(btn.dataset.path);
                });
                run(`/api/${catalog.channel}/portal`);
            } catch (error) {
                nav.innerHTML = '<p class="empty">Could not load catalog. Is the API running?</p>';
                outEl.textContent = String(error);
            }
        }

        boot();
    </script>
</body>
</html>
