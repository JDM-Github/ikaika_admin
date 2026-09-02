<style>
    :root {
        --bg: #16181d;
        --rail: #1b1e24;
        --surface: #202329;
        --raise: #262a31;
        --line: #2f333b;
        --line-soft: #262a30;
        --ink: #e6e8ec;
        --muted: #9aa1ad;
        --dim: #6b7280;
        --accent: #4f8ef7;
        --accent-soft: rgba(79, 142, 247, 0.16);
        --warn: #d99b3c;
        --ok: #4ea172;
        --bad: #d4636b;
        --chip: #2d323b;
        --row-height: 32px;
        --rail-width: 236px;
        --gutter: 64px;
        --title-width: 240px;
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; }

    /* Several panels set display, which would otherwise beat the hidden attribute. */
    [hidden] { display: none !important; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--ink);
        font: 13px/1.45 ui-sans-serif, system-ui, "Segoe UI", sans-serif;
        overflow: hidden;
    }

    button, input, select { font: inherit; color: inherit; }
    button { cursor: pointer; }

    .mono { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }

    /* Top bar --------------------------------------------------------- */

    .topbar {
        display: flex;
        align-items: center;
        gap: 16px;
        height: 48px;
        padding: 0 14px;
        background: var(--surface);
        border-bottom: 1px solid var(--line);
    }

    .brand {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-weight: 600;
        letter-spacing: 0.04em;
    }

    .brand small { color: var(--dim); font-weight: 400; letter-spacing: 0.08em; text-transform: uppercase; font-size: 10px; }

    .bases { display: flex; gap: 4px; }

    .bases button {
        display: flex;
        align-items: center;
        gap: 7px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--muted);
        padding: 5px 11px;
        border-radius: 7px;
    }

    .bases button:hover { background: var(--raise); color: var(--ink); }

    .bases button.active {
        background: var(--accent-soft);
        border-color: rgba(79, 142, 247, 0.4);
        color: var(--ink);
    }

    .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--dim); flex: none; }
    .dot.ok { background: var(--ok); }
    .dot.bad { background: var(--bad); }

    .spacer { flex: 1; }

    .badge {
        border: 1px solid rgba(217, 155, 60, 0.4);
        color: var(--warn);
        border-radius: 999px;
        padding: 2px 10px;
        font-size: 10px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .keyhint {
        border: 1px solid var(--line);
        border-radius: 6px;
        background: var(--raise);
        color: var(--muted);
        padding: 4px 9px;
        font-size: 11px;
    }

    .auth { display: flex; align-items: center; gap: 8px; }

    .auth input {
        width: 132px;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 5px 9px;
    }

    .auth button {
        border: 1px solid var(--line);
        background: var(--raise);
        border-radius: 6px;
        padding: 5px 11px;
    }

    .auth button:hover { border-color: var(--accent); }
    .who { color: var(--muted); font-size: 12px; white-space: nowrap; }

    /* Layout ---------------------------------------------------------- */

    .layout {
        display: grid;
        grid-template-columns: var(--rail-width) minmax(0, 1fr);
        height: calc(100% - 48px);
    }

    aside {
        background: var(--rail);
        border-right: 1px solid var(--line);
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .rail-search { padding: 10px; border-bottom: 1px solid var(--line-soft); }

    .rail-search input {
        width: 100%;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 6px 9px;
    }

    .rail-list { overflow: auto; padding: 6px 0 24px; flex: 1; }

    .rail-group {
        padding: 12px 14px 5px;
        color: var(--dim);
        font-size: 10px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .rail-item {
        display: flex;
        align-items: baseline;
        gap: 8px;
        width: 100%;
        border: 0;
        background: transparent;
        color: var(--muted);
        text-align: left;
        padding: 6px 14px;
        border-left: 2px solid transparent;
    }

    .rail-item:hover { background: rgba(255, 255, 255, 0.035); color: var(--ink); }

    .rail-item.active {
        background: var(--accent-soft);
        border-left-color: var(--accent);
        color: var(--ink);
    }

    .rail-item span.name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rail-item span.count { color: var(--dim); font-size: 11px; font-variant-numeric: tabular-nums; }

    main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }

    /* Toolbar --------------------------------------------------------- */

    .toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        border-bottom: 1px solid var(--line);
        background: var(--surface);
        flex: none;
    }

    .crumb { display: flex; align-items: baseline; gap: 8px; min-width: 0; }
    .crumb strong { font-size: 14px; }
    .crumb .sub { color: var(--dim); font-size: 11px; }

    .toolbar input.search {
        width: 220px;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 5px 9px;
    }

    .seg { display: flex; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }

    .seg button {
        border: 0;
        background: transparent;
        color: var(--muted);
        padding: 5px 11px;
    }

    .seg button.active { background: var(--raise); color: var(--ink); }
    .seg button:hover { color: var(--ink); }

    .ghost {
        border: 1px solid var(--line);
        background: transparent;
        color: var(--muted);
        border-radius: 6px;
        padding: 5px 10px;
    }

    .ghost:hover { color: var(--ink); border-color: var(--accent); }

    .filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 0 14px; }
    .filters:empty { display: none; }

    .filters .tag {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--raise);
        border: 1px solid var(--line);
        border-radius: 999px;
        padding: 3px 6px 3px 10px;
        font-size: 11px;
        margin-top: 8px;
    }

    .filters .tag button { border: 0; background: transparent; color: var(--dim); padding: 0 3px; }
    .filters .tag button:hover { color: var(--bad); }

    /* Grid ------------------------------------------------------------ */

    .grid-wrap { flex: 1; overflow: auto; min-height: 0; }

    table.grid { border-collapse: separate; border-spacing: 0; width: max-content; min-width: 100%; }

    table.grid th, table.grid td {
        border-right: 1px solid var(--line-soft);
        border-bottom: 1px solid var(--line-soft);
        padding: 0 10px;
        height: var(--row-height);
        max-width: 380px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
        background: var(--bg);
    }

    table.grid thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--surface);
        border-bottom: 1px solid var(--line);
        text-align: left;
        font-weight: 500;
        color: var(--muted);
        cursor: pointer;
        user-select: none;
    }

    table.grid thead th:hover { color: var(--ink); }

    th .head { display: flex; align-items: baseline; gap: 7px; }
    th .head .type { color: var(--dim); font-size: 10px; font-weight: 400; }
    th .head .arrow { color: var(--accent); font-size: 10px; }

    /* The frozen edge: the row id and the title, so a 93-column table stays readable. */
    table.grid th.freeze-1, table.grid td.freeze-1 { position: sticky; left: 0; z-index: 2; }
    table.grid th.freeze-2, table.grid td.freeze-2 { position: sticky; left: var(--gutter); z-index: 2; }
    table.grid thead th.freeze-1, table.grid thead th.freeze-2 { z-index: 4; }
    table.grid td.freeze-1, table.grid td.freeze-2 { background: var(--bg); }
    table.grid td.freeze-2 { border-right: 1px solid var(--line); font-weight: 500; color: var(--ink); }
    table.grid th.freeze-2 { border-right: 1px solid var(--line); }

    tbody tr:hover td { background: #1c1f25; }
    tbody tr.on td { background: #1f2530; }

    td.gutter { color: var(--dim); font-variant-numeric: tabular-nums; text-align: right; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; }
    td.mono, th.mono { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; }

    /* NULL, empty string, false and zero must all look different. */
    .nil { color: #4b525d; }
    .empty { color: #4b525d; font-style: italic; }

    .pill {
        display: inline-block;
        border-radius: 4px;
        padding: 1px 7px;
        font-size: 11px;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }

    .chip {
        display: inline-block;
        background: var(--chip);
        border: 1px solid var(--line);
        border-radius: 4px;
        padding: 1px 7px;
        margin-right: 4px;
        font-size: 11px;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
        cursor: pointer;
    }

    .chip:hover { border-color: var(--accent); color: #fff; }
    .chip.more { background: transparent; color: var(--dim); cursor: default; }
    .chip.more:hover { border-color: var(--line); color: var(--dim); }

    td a { color: var(--accent); text-decoration: none; }
    td a:hover { text-decoration: underline; }

    .check { color: var(--ok); }
    .uncheck { color: #4b525d; }

    /* Footer ---------------------------------------------------------- */

    .footer {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 7px 14px;
        border-top: 1px solid var(--line);
        background: var(--surface);
        color: var(--muted);
        font-size: 12px;
        flex: none;
    }

    .footer .count { font-variant-numeric: tabular-nums; }
    .footer button { border: 1px solid var(--line); background: transparent; color: var(--muted); border-radius: 6px; padding: 3px 9px; }
    .footer button:hover:not(:disabled) { color: var(--ink); border-color: var(--accent); }
    .footer button:disabled { opacity: 0.35; cursor: default; }

    /* Peek panel ------------------------------------------------------ */

    .peek {
        position: fixed;
        top: 48px;
        right: 0;
        bottom: 0;
        width: 460px;
        max-width: 92vw;
        background: var(--surface);
        border-left: 1px solid var(--line);
        display: flex;
        flex-direction: column;
        box-shadow: -18px 0 40px rgba(0, 0, 0, 0.35);
        z-index: 20;
    }

    .peek header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 14px;
        border-bottom: 1px solid var(--line);
    }

    .peek header strong { font-size: 14px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .peek .body { overflow: auto; padding: 4px 0 28px; }

    .peek .row { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 12px; padding: 7px 14px; border-bottom: 1px solid var(--line-soft); }
    .peek .row .k { color: var(--dim); font-size: 11px; overflow: hidden; text-overflow: ellipsis; }
    .peek .row .v { word-break: break-word; white-space: pre-wrap; }

    /* Command palette -------------------------------------------------- */

    .scrim { position: fixed; inset: 0; background: rgba(8, 9, 12, 0.6); z-index: 30; }

    .palette {
        position: fixed;
        top: 14vh;
        left: 50%;
        transform: translateX(-50%);
        width: 560px;
        max-width: 92vw;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 10px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
        z-index: 31;
        overflow: hidden;
    }

    .palette input { width: 100%; border: 0; background: transparent; padding: 13px 15px; font-size: 15px; outline: none; }
    .palette .hits { max-height: 52vh; overflow: auto; border-top: 1px solid var(--line); }

    .palette .hit {
        display: flex;
        align-items: baseline;
        gap: 9px;
        width: 100%;
        border: 0;
        background: transparent;
        color: var(--muted);
        text-align: left;
        padding: 8px 15px;
    }

    .palette .hit.on, .palette .hit:hover { background: var(--accent-soft); color: var(--ink); }
    .palette .hit .base { color: var(--dim); font-size: 11px; }
    .palette .hit .name { flex: 1; }
    .palette .hit .count { color: var(--dim); font-size: 11px; font-variant-numeric: tabular-nums; }

    /* Console drawer --------------------------------------------------- */

    .console {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        height: 62vh;
        background: var(--surface);
        border-top: 1px solid var(--line);
        display: grid;
        grid-template-columns: 300px minmax(0, 1fr);
        z-index: 25;
        box-shadow: 0 -18px 40px rgba(0, 0, 0, 0.4);
    }

    .console nav { border-right: 1px solid var(--line); overflow: auto; padding-bottom: 20px; }
    .console .pane { display: flex; flex-direction: column; min-width: 0; }

    .console .bar { display: grid; grid-template-columns: 92px minmax(0, 1fr) auto auto; gap: 8px; padding: 10px; border-bottom: 1px solid var(--line); }
    .console .bar input, .console .bar select, .console textarea {
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 6px;
        padding: 6px 9px;
        font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
        font-size: 12px;
    }

    .console .bar button { border: 1px solid var(--line); background: var(--raise); border-radius: 6px; padding: 6px 12px; }
    .console textarea { margin: 10px; min-height: 60px; resize: vertical; display: none; }
    .console.has-body textarea { display: block; }
    .console .status { padding: 6px 12px; color: var(--muted); font-size: 11px; border-bottom: 1px solid var(--line-soft); }
    .console pre { margin: 0; padding: 12px; overflow: auto; flex: 1; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word; }

    .console .group { padding: 11px 14px 4px; color: var(--dim); font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; }
    .console details > summary { list-style: none; cursor: pointer; padding: 6px 14px; color: var(--muted); }
    .console details > summary::-webkit-details-marker { display: none; }
    .console button.route { display: block; width: 100%; text-align: left; border: 0; background: transparent; color: var(--muted); padding: 5px 14px 5px 26px; }
    .console button.route.top { padding-left: 14px; }
    .console button.route:hover { background: rgba(255, 255, 255, 0.04); color: var(--ink); }
    .console button.route .meta { display: block; color: var(--dim); font-size: 10px; }
    .console .note, .console .empty { padding: 8px 14px; color: var(--dim); font-size: 11px; }

    .state { padding: 40px 20px; color: var(--dim); text-align: center; }
    .state strong { display: block; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
</style>
