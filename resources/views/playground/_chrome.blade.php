<header class="topbar">
    <div class="brand">IKAIKA <small>Workspace · {{ $channel }}</small></div>
    <div class="bases" id="bases"></div>
    <div class="spacer"></div>
    <span class="badge" title="The grid reads the live databases and never writes to them.">Read only</span>
    <button type="button" class="keyhint" id="palette-open">Ctrl K &nbsp;jump to a table</button>
    <button type="button" class="ghost" id="console-toggle">Console</button>
    <form class="auth" id="login-form">
        <input id="id_no" name="id_no" placeholder="Employee ID no." autocomplete="username" spellcheck="false">
        <button type="submit">Sign in</button>
        <span class="who" id="who"></span>
        <button type="button" id="sign-out" hidden>Sign out</button>
    </form>
</header>

<div class="layout">
    <aside>
        <div class="rail-search">
            <input id="rail-filter" placeholder="Filter tables" spellcheck="false" autocomplete="off">
        </div>
        <div class="rail-list" id="rail"></div>
    </aside>

    <main>
        <div class="toolbar">
            <div class="crumb">
                <strong id="table-label">Workspace</strong>
                <span class="sub mono" id="table-sub">sign in to browse</span>
            </div>
            <div class="spacer"></div>
            <input class="search" id="search" placeholder="Search this table" spellcheck="false" autocomplete="off">
            <div class="seg" id="preset">
                <button type="button" data-cols="key" class="active">Key</button>
                <button type="button" data-cols="all">All</button>
            </div>
            <button type="button" class="ghost" id="show-sql" title="The exact statement behind this grid">SQL</button>
        </div>
        <div class="filters" id="filters"></div>
        <div class="grid-wrap" id="grid-wrap">
            <div class="state"><strong>Nothing loaded yet</strong>Sign in with an employee ID number, then pick a table.</div>
        </div>
        <div class="footer">
            <span class="count" id="count">--</span>
            <button type="button" id="clear" hidden>Clear</button>
            <div class="spacer"></div>
            <button type="button" id="prev">Prev</button>
            <span id="page" class="mono">1 / 1</span>
            <button type="button" id="next">Next</button>
        </div>
    </main>
</div>

<div id="peek"></div>
<div id="palette"></div>
