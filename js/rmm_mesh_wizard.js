/*
 * Remote access (MeshCentral) setup page (RivetIT 26.10.54). Markup: includes/rmm_mesh_wizard.php; handler: admin/post/settings_mesh_wizard.php.
 * Progressive enhancement only: every button also works as a plain form post. This script
 *   - asks the server for the two live checks after the page has loaded (does MeshCentral answer, is its certificate publicly trusted) and turns the steps green or amber,
 *   - shows/hides the device group box, fills the pin box from "Use this pin", and asks before removing the saved group.
 * Nothing is rendered with innerHTML: every text from the server goes in with textContent.
 */
(function () {
    'use strict';
    var root = document.getElementById('mw-root');
    if (!root) { return; }
    var url = root.getAttribute('data-post-url'), csrf = root.getAttribute('data-csrf');

    function badge(n, ok, label) {
        var b = document.getElementById('mw-badge-' + n), card = document.getElementById('step-' + n);
        if (!b || !card) { return; }
        b.className = 'badge ' + (ok ? 'text-bg-success' : 'text-bg-warning');
        b.setAttribute('data-state', ok ? 'ok' : 'todo');
        card.setAttribute('data-state', ok ? 'ok' : 'todo');
        b.textContent = '';
        var i = document.createElement('i');
        i.className = 'fas me-1 ' + (ok ? 'fa-check-circle' : 'fa-exclamation-circle');
        i.setAttribute('aria-hidden', 'true');
        b.appendChild(i);
        b.appendChild(document.createTextNode(label || (ok ? 'Done' : 'To do')));
    }
    function progress() {
        var n = root.querySelectorAll('.mw-step[data-state="ok"]').length, p = document.getElementById('mw-progress');
        if (p) { p.textContent = n + ' of 5 steps done'; p.className = 'badge fs-6 ' + (n === 5 ? 'text-bg-success' : 'text-bg-warning'); }
    }
    function say(el, state, text) {
        if (!el) { return; }
        el.textContent = '';
        var i = document.createElement('i');
        i.setAttribute('aria-hidden', 'true');
        i.className = 'fas me-1 ' + (state === 'ok' || state === 'public' || state === 'pinned' ? 'fa-check-circle text-success' : (state === 'none' ? 'fa-info-circle text-muted' : 'fa-exclamation-triangle text-warning'));
        el.appendChild(i);
        el.appendChild(document.createTextNode(text));
        el.setAttribute('data-state', state);
    }
    function summary(n, text) { var s = document.getElementById('mw-sum-' + n); if (s) { s.textContent = text; } }

    var running = false;
    function live() {
        var conn = document.getElementById('mw-live-conn'), trust = document.getElementById('mw-live-trust');
        if (running || !conn || conn.getAttribute('data-has-url') !== '1') { return; }
        running = true;
        say(conn, 'none', 'Checking the connection...');
        say(trust, 'none', 'Checking the certificate...');
        var fd = new FormData();
        fd.append('mw_action', 'live'); fd.append('csrf_token', csrf);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                running = false;
                if (!d || !d.success) { say(conn, 'fail', (d && d.message) || 'The check could not be run.'); say(trust, 'none', 'Not checked.'); return; }
                say(conn, d.conn.state, d.conn.text);
                var firstOk = document.getElementById('step-1').getAttribute('data-state') === 'ok';
                if (firstOk) {
                    badge(1, d.conn.state === 'ok', d.conn.state === 'ok' ? 'Done' : 'Not answering');
                    if (d.conn.state !== 'ok') { summary(1, d.conn.text + ' Check the address, or that this server can reach it.'); }
                }
                say(trust, d.trust.state, d.trust.text);
                var trustOk = d.trust.state === 'public' || d.trust.state === 'pinned';
                badge(3, trustOk, trustOk ? 'Done' : 'To do');
                summary(3, d.trust.text);
                var rb = document.getElementById('mw-retest'); if (rb) { rb.classList.remove('d-none'); }
                progress();
            })
            .catch(function () { running = false; say(conn, 'fail', 'The check could not be run. Reload the page to try again.'); });
    }
    var rt = document.getElementById('mw-retest');
    if (rt) { rt.addEventListener('click', live); }
    live();

    var show = document.getElementById('mw_group_show'), g = document.getElementById('mw_group');
    if (show && g) {
        show.addEventListener('click', function () {
            var hidden = g.type === 'password';
            g.type = hidden ? 'text' : 'password';
            show.textContent = hidden ? 'Hide' : 'Show';
        });
    }
    var use = document.getElementById('mw_use_pin'), pin = document.getElementById('mw_pin');
    if (use && pin) { use.addEventListener('click', function () { pin.value = use.getAttribute('data-mw-use-pin') || ''; pin.focus(); }); }
    root.querySelectorAll('button[data-confirm]').forEach(function (b) {
        b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); } });
    });
})();
