/*
 * RMM alerting pages (agent alerts, maintenance windows, escalation policies) and the Alerting tab of the asset page. Linked by includes/footer.php after
 * js/rmm_automation.js on those pages only. Nothing here decides anything: every write is a form post or a request the server authorizes again. Server text goes
 * to the DOM only through textContent. No window.confirm / alert, no inline handlers.
 */
(function () {
    'use strict';

    // ---- open the Alerting tab when the page is reached through #rmm-alerting (the handlers send people back there)
    function openAlertingTab() {
        if (location.hash !== '#rmm-alerting') { return; }
        var btn = document.getElementById('rmm-tab-alerting');
        if (btn && window.bootstrap && window.bootstrap.Tab) {
            window.bootstrap.Tab.getOrCreateInstance(btn).show();
            var box = document.getElementById('rmm-alerting');
            if (box && box.scrollIntoView) { box.scrollIntoView(); }
        }
    }
    window.addEventListener('load', openAlertingTab);
    window.addEventListener('hashchange', openAlertingTab);

    // ---- status message (aria-live) shared by the page
    function say(text, kind) {
        var box = document.getElementById('alr-msg');
        if (!box) { return; }
        box.className = 'alert alert-' + (kind || 'info');
        box.textContent = text;
    }

    // ---- Create ticket: posts to the existing agent/post/rmm_alert.php (needs the support module at level 2; the server checks)
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-alr-ticket]');
        if (!b) { return; }
        e.preventDefault();
        b.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', b.getAttribute('data-csrf') || '');
        fd.append('action', 'create_ticket');
        fd.append('alert_id', b.getAttribute('data-alr-ticket'));
        fetch('/agent/post/rmm_alert.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success && j.redirect && /^\/agent\/ticket\.php\?ticket_id=\d+$/.test(j.redirect)) {
                    say(j.existing ? 'This alert already has an open ticket. Opening it.' : 'Ticket created. Opening it.', 'success');
                    window.location.href = j.redirect;
                } else {
                    b.disabled = false;
                    say((j && j.error) ? String(j.error) : 'The ticket could not be created.', 'danger');
                }
            })
            .catch(function () { b.disabled = false; say('The ticket could not be created. Check your connection and try again.', 'danger'); });
    });

    // ---- escalation steps: targets inside a step (the shared script repeats the steps themselves)
    function syncTarget(row) {
        var sel = row.querySelector('[data-alr-type]');
        if (!sel) { return; }
        row.querySelectorAll('[data-alr-ref]').forEach(function (s) {
            var on = s.getAttribute('data-alr-ref') === sel.value;
            s.classList.toggle('d-none', !on);
            s.querySelectorAll('select,input').forEach(function (f) { f.disabled = !on; });
        });
    }
    function nextTargetIndex(list) {
        var max = -1;
        list.querySelectorAll('[data-alr-index]').forEach(function (r) { max = Math.max(max, parseInt(r.getAttribute('data-alr-index'), 10) || 0); });
        return max + 1;
    }
    function addTarget(step) {
        var tpl = step.querySelector('template[data-alr-target-row]');
        var list = step.querySelector('[data-alr-targets]');
        if (!tpl || !list || list.querySelectorAll('[data-alr-index]').length >= 10) { return null; }
        var i = nextTargetIndex(list);
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__j__/g, String(i)).trim();
        var row = holder.firstElementChild;
        row.setAttribute('data-alr-index', String(i));
        list.appendChild(row);
        syncTarget(row);
        return row;
    }
    document.querySelectorAll('.rmm-alr-target').forEach(syncTarget);
    document.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-alr-type]');
        if (sel) { syncTarget(sel.closest('.rmm-alr-target')); }
    });
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-alr-add-target]');
        if (add) {
            e.preventDefault();
            var row = addTarget(add.closest('[data-rmm-index]'));
            if (row) { var f = row.querySelector('select,input'); if (f) { f.focus(); } }
            return;
        }
        var rm = e.target.closest('[data-alr-remove-target]');
        if (rm) {
            e.preventDefault();
            var r = rm.closest('.rmm-alr-target');
            var list = r.parentNode;
            var prev = r.previousElementSibling || r.nextElementSibling;
            r.remove();
            var focus = (prev && prev.querySelector('select,input')) || list.closest('[data-rmm-index]').querySelector('[data-alr-add-target]');
            if (focus) { focus.focus(); }
        }
    });
    // a step added by the shared script starts with one empty person row
    var steps = document.getElementById('alr-steps');
    if (steps && window.MutationObserver) {
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1 && n.hasAttribute && n.hasAttribute('data-rmm-index') && !n.querySelector('[data-alr-index]')) {
                        window.setTimeout(function () { addTarget(n); }, 0);
                    }
                });
            });
        }).observe(steps, { childList: true, subtree: true });
    }

    // ---- maintenance window: show the UTC time next to a local start/end in the chosen zone
    function zoneOffsetMs(utcMs, zone) {
        var f = new Intl.DateTimeFormat('en-US', { timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
        var p = {};
        f.formatToParts(new Date(utcMs)).forEach(function (x) { p[x.type] = x.value; });
        return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - utcMs;
    }
    function localToUtc(value, zone) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
        if (!m) { return null; }
        var guess = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
        try {
            var utc = guess - zoneOffsetMs(guess, zone);
            utc = guess - zoneOffsetMs(utc, zone);
            return new Date(utc);
        } catch (err) { return null; }
    }
    var tz = document.getElementById('w-tz');
    if (tz) {
        var refresh = function () {
            document.querySelectorAll('[data-alr-local]').forEach(function (inp) {
                var out = document.querySelector('[data-alr-utc="' + inp.getAttribute('data-alr-local') + '"]');
                if (!out) { return; }
                var d = localToUtc(inp.value, tz.value);
                out.textContent = d ? 'UTC: ' + d.toISOString().slice(0, 16).replace('T', ' ') : '';
            });
        };
        tz.addEventListener('change', refresh);
        document.querySelectorAll('[data-alr-local]').forEach(function (inp) { inp.addEventListener('input', refresh); });
        refresh();
    }
})();
