/*
 * Administration > Endpoint agent, RivetCore 1.0.0-rc.11 cards (remote tools, file transfer, log collection, scale settings, partitioning, external metric store).
 * Markup: includes/rmm_scale_cards.php. Linked by includes/footer.php only on a page that set $rmm_scale_scripts. Nothing here decides anything: every form posts to
 * admin/post/settings_endpoint_agent.php, which authorizes and validates through RivetCore, so each form also works without this script. No window.confirm / alert,
 * no inline handlers (the page CSP blocks them); server text is only ever written with textContent.
 */
(function () {
    'use strict';

    // ---- a switch that needs an acknowledgement: the verbatim warning and its required box show while the switch is being turned ON (not when it is already on)
    function syncGate(sw) {
        var holder = document.querySelector(sw.getAttribute('data-rmm-scale-gate'));
        if (!holder) { return; }
        var was = sw.getAttribute('data-rmm-scale-was') === '1';
        var ack = holder.querySelector('[data-rmm-scale-ack]');
        var box = ack ? ack.querySelector('input[type=checkbox]') : null;
        var need = sw.checked && !was;
        if (ack) { ack.classList.toggle('d-none', !need); }
        if (box) { box.required = need; if (!need) { box.checked = false; } }
    }
    document.querySelectorAll('[data-rmm-scale-gate]').forEach(function (sw) {
        syncGate(sw);
        sw.addEventListener('change', function () { syncGate(sw); });
    });

    // ---- a switch that enables or disables a group of fields (the fields of a disabled group are not sent; the server turns the feature off)
    function syncToggle(sw) {
        var target = document.querySelector(sw.getAttribute('data-rmm-scale-toggle'));
        if (!target) { return; }
        target.querySelectorAll('input,select,textarea').forEach(function (f) { f.disabled = !sw.checked; });
        target.classList.toggle('opacity-50', !sw.checked);
    }
    document.querySelectorAll('[data-rmm-scale-toggle]').forEach(function (sw) {
        syncToggle(sw);
        sw.addEventListener('change', function () { syncToggle(sw); });
    });

    // ---- the partitioning button waits for its confirmation box
    document.querySelectorAll('[data-rmm-scale-needs]').forEach(function (box) {
        var btn = box.form ? box.form.querySelector('button[name=partition_enable]') : null;
        if (!btn) { return; }
        var sync = function () { btn.disabled = !box.checked; };
        sync();
        box.addEventListener('change', sync);
    });

    // ---- log source rows: <template data-rmm-scale-tpl="win|lin"> is a blank row, "__i__" becomes a fresh index
    var counters = {};
    function rowsOf(kind) { return document.querySelector('[data-rmm-scale-rows="' + kind + '"]'); }
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-rmm-scale-add]');
        if (add) {
            var kind = add.getAttribute('data-rmm-scale-add');
            var tpl = document.querySelector('template[data-rmm-scale-tpl="' + kind + '"]');
            var list = rowsOf(kind);
            if (!tpl || !list) { return; }
            if (list.querySelectorAll('[data-rmm-scale-row]').length >= 20) { return; }
            counters[kind] = (counters[kind] || (100 + list.querySelectorAll('[data-rmm-scale-row]').length)) + 1;
            var holder = document.createElement('div');
            holder.innerHTML = tpl.innerHTML.replace(/__i__/g, String(counters[kind])).trim();
            var row = holder.firstElementChild;
            if (!row) { return; }
            list.appendChild(row);
            var first = row.querySelector('input:not([type=hidden]),select,textarea');
            if (first) { first.focus(); }
            return;
        }
        var rm = e.target.closest('[data-rmm-scale-remove]');
        if (rm) {
            var r = rm.closest('[data-rmm-scale-row]');
            if (!r) { return; }
            var parent = r.parentNode;
            var next = r.nextElementSibling || r.previousElementSibling;
            r.remove();
            var f = next ? next.querySelector('input:not([type=hidden]),select,textarea,button') : parent.parentNode.querySelector('[data-rmm-scale-add]');
            if (f) { f.focus(); }
        }
    });

    // ---- external store: "Test connection" answers in the page (aria-live) without losing what was typed
    document.querySelectorAll('[data-rmm-scale-test]').forEach(function (btn) {
        var form = btn.form;
        var live = form ? form.querySelector('[data-rmm-scale-live]') : null;
        if (!form || !live || !window.fetch || !window.FormData) { return; }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var data = new FormData(form);
            data.delete('save_extstore');
            data.set('test_extstore', '1');
            live.textContent = '';
            var note = document.createElement('div');
            note.className = 'alert alert-info py-2 mb-0';
            note.textContent = 'Testing the connection (up to 5 seconds)...';
            live.appendChild(note);
            btn.disabled = true;
            fetch(form.getAttribute('action') || 'post.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server did not return an answer (HTTP ' + r.status + ').' }; }); })
                .then(function (j) { show(j && typeof j.message === 'string' ? j : { ok: false, message: 'Unexpected answer.' }); })
                .catch(function () { show({ ok: false, message: 'The request could not be sent. Check your connection and try again.' }); });
            function show(j) {
                btn.disabled = false;
                live.textContent = '';
                var box = document.createElement('div');
                box.className = 'alert py-2 mb-0 alert-' + (j.ok ? 'success' : (j.status === 'refused' || j.status === 'auth' ? 'danger' : 'warning'));
                var label = { ok: 'Connected. ', refused: 'Refused. ', unreachable: 'Not reachable. ', auth: 'Login rejected. ', invalid: 'Not tested. ', denied: 'Not allowed. ' }[j.status] || '';
                var b = document.createElement('strong');
                b.textContent = label;
                box.appendChild(b);
                box.appendChild(document.createTextNode(j.message));
                live.appendChild(box);
            }
        });
    });

    // ---- the message a save left in a card: move keyboard focus to it so it is read out, and keep it in view
    var msg = document.querySelector('[data-rmm-scale-msg]');
    if (msg && typeof msg.focus === 'function') {
        window.setTimeout(function () { msg.focus({ preventScroll: false }); }, 50);
    }
})();
