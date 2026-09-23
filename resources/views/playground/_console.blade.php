{{-- The original API playground, demoted to a drawer: it is the raw-request escape
     hatch the grid deliberately does not try to replace. --}}
<section class="console" id="console" hidden>
    <nav id="nav">
        <p class="empty">Loading catalog...</p>
    </nav>
    <div class="pane">
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
            <button type="button" id="console-close">Close</button>
        </form>
        <textarea id="body" spellcheck="false" placeholder='{"role":"Admin"}'></textarea>
        <div class="status" id="status">Ready.</div>
        <pre id="out">{}</pre>
    </div>
</section>
