/*
 * Administration > Endpoint agent > "RMM data store" card (RivetCore 1.0.0-rc.41, issue #89 phase 4). Behaviour only: every form already works
 * without this file (a plain POST and redirect). This adds an in-page "Test connection" answer (same pattern as js/rmm_scale.js's external-store
 * test) and a "Refresh" button for the migration progress box that polls admin/post/settings_endpoint_agent.php without a full page reload.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-ds-test]').forEach(function (btn) {
        var form = btn.form;
        var live = form ? form.querySelector('[data-ds-test-live]') : null;
        if (!form || !live || !window.fetch || !window.FormData) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var data = new FormData(form);
            data.delete('save_datastore_connection');
            data.set('test_datastore', '1');
            live.textContent = '';
            var note = document.createElement('div');
            note.className = 'alert alert-info py-2 mb-0';
            note.textContent = 'Testing the connection (up to a few seconds)...';
            live.appendChild(note);
            btn.disabled = true;
            fetch(form.getAttribute('action') || 'post.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server did not return an answer (HTTP ' + r.status + ').' }; }); })
                .then(function (j) { show(j && typeof j.message === 'string' ? j : { ok: false, message: 'Unexpected answer.' }); })
                .catch(function () { show({ ok: false, message: 'The request could not be sent. Check your connection and try again.' }); });
            function show(j) {
                btn.disabled = false;
                live.textContent = '';
                var box = document.createElement('div');
                box.className = 'alert py-2 mb-0 alert-' + (j.ok ? 'success' : 'warning');
                var b = document.createElement('strong');
                b.textContent = j.ok ? 'Connected. ' : 'Not connected. ';
                box.appendChild(b);
                box.appendChild(document.createTextNode(j.message));
                live.appendChild(box);
            }
        });
    });

    document.querySelectorAll('[data-ds-progress-refresh]').forEach(function (btn) {
        var card = btn.closest('.rmm-scale-card');
        var live = card ? card.querySelector('[data-ds-progress-live]') : null;
        var form = btn.closest('form');
        var csrfInput = card ? card.querySelector('input[name="csrf_token"]') : null;
        if (!live || !csrfInput || !window.fetch || !window.FormData) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var data = new FormData();
            data.set('csrf_token', csrfInput.value);
            data.set('migrate_datastore_status', '1');
            btn.disabled = true;
            fetch('post.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btn.disabled = false;
                    if (!j || !j.ok) {
                        return;
                    }
                    live.textContent = '';
                    (j.log || []).forEach(function (ev) {
                        var row = document.createElement('div');
                        row.textContent = JSON.stringify(ev);
                        live.appendChild(row);
                    });
                    live.scrollTop = live.scrollHeight;
                })
                .catch(function () { btn.disabled = false; });
        });
    });
})();
