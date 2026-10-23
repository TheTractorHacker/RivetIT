// Alerts centre (agent/alerts.php): per-row and bulk Acknowledge / Resolve / Create ticket through each source's own handler.
(function () {
    'use strict';
    var card = document.getElementById('alertsCard');
    if (!card) { return; }
    var CSRF = card.getAttribute('data-csrf');
    var ENDPOINTS = window.ALERT_ENDPOINTS || {};
    var bar = document.getElementById('alertsBar');
    var barCount = document.getElementById('alertsBarCount');

    // The endpoint-agent handler speaks RivetCore's action names and answers JSON only when asked to.
    function request(source, id, action) {
        var agent = source === 'agent';
        var act = agent ? (action === 'acknowledge' ? 'ack_alert' : action === 'resolve' ? 'resolve_alert' : action) : action;
        var body = 'csrf_token=' + encodeURIComponent(CSRF) + '&action=' + encodeURIComponent(act) + '&alert_id=' + encodeURIComponent(id);
        return fetch(ENDPOINTS[source], {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body
        }).then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected answer from the server.' }; }); });
    }

    function done(rowId) {
        var row = document.getElementById('alert-row-' + rowId);
        if (row) { row.classList.add('is-done'); var c = row.querySelector('.alert-chk'); if (c) { c.checked = false; c.disabled = true; } }
        updateBar();
    }

    function selected() { return [].slice.call(document.querySelectorAll('.alert-chk:checked')); }
    function updateBar() {
        if (!bar) { return; }
        var n = selected().length;
        bar.hidden = n === 0;
        if (barCount) { barCount.textContent = n + ' selected'; }
    }

    async function single(el) {
        var action = el.dataset.alertAction, source = el.dataset.source, id = el.dataset.alertId, rowId = el.dataset.rowId;
        if (action === 'create_ticket' && !(await RivetDialog.confirm('Create a ticket from this alert?', { title: 'Create ticket', okText: 'Create ticket' }))) { return; }
        el.disabled = true;
        request(source, id, action).then(function (d) {
            if (d.success) {
                if (d.vendor_warning) { RivetDialog.alert('Updated here, but not synced to the RMM: ' + d.vendor_warning); }
                if (d.redirect) { window.location.href = d.redirect; return; }
                done(rowId);
            } else {
                el.disabled = false;
                RivetDialog.alert('Failed: ' + (d.error || 'Unknown error'));
            }
        }).catch(function () { el.disabled = false; RivetDialog.alert('Failed: the request did not complete.'); });
    }

    async function bulk(action) {
        var boxes = selected();
        if (!boxes.length) { return; }
        var verb = action === 'acknowledge' ? 'Acknowledge' : 'Resolve';
        if (!(await RivetDialog.confirm(verb + ' ' + boxes.length + ' alert' + (boxes.length === 1 ? '' : 's') + '?', { title: verb + ' selected', okText: verb }))) { return; }
        var failed = 0;
        await Promise.all(boxes.map(function (c) {
            return request(c.dataset.source, c.dataset.id, action).then(function (d) { if (d.success) { done(c.dataset.row); } else { failed++; } }).catch(function () { failed++; });
        }));
        if (failed) { RivetDialog.alert(failed + ' alert' + (failed === 1 ? '' : 's') + ' could not be updated; the rest were.'); } else { setTimeout(function () { location.reload(); }, 600); }
    }

    document.addEventListener('click', function (e) {
        var el;
        if ((el = e.target.closest('.js-bulk-alert-action'))) { bulk(el.dataset.bulkAction); return; }
        if ((el = e.target.closest('.js-alert-action'))) { single(el); return; }
        if (e.target.id === 'alertsBarClear') { document.querySelectorAll('.alert-chk').forEach(function (c) { c.checked = false; }); var all = document.getElementById('selectAll'); if (all) { all.checked = false; } updateBar(); }
    });
    document.addEventListener('change', function (e) {
        if (e.target.closest('.js-toggle-select-all')) { document.querySelectorAll('.alert-chk:not(:disabled)').forEach(function (c) { c.checked = e.target.checked; }); }
        if (e.target.closest('.alert-chk, .js-toggle-select-all')) { updateBar(); }
    });
}());
