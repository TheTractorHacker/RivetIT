/*
 * RMM asset panel behaviour (markup: includes/rmm_ui_render.php).
 *
 * Linked by includes/footer.php only on a page that rendered the panel, so with the RMM module off this file is never requested.
 * Every action goes to agent/post/rmm_agent.php, which authorizes it server-side through RivetCore's TechnicianActions; hiding a button is
 * cosmetic. Server text is written with textContent. There is no window.confirm / alert: destructive actions use the in-page dialogs.
 */
(function () {
    'use strict';
    var panel = document.getElementById('rmm-panel');
    if (!panel) { return; }
    var deviceId = panel.getAttribute('data-device-id');
    var csrf = panel.getAttribute('data-csrf');
    var postUrl = panel.getAttribute('data-post-url');
    var outputUrl = panel.getAttribute('data-output-url');
    var msg = document.getElementById('rmm-msg');

    function say(text, ok, extra) {
        if (!msg) { return; }
        msg.className = 'alert mb-0 alert-' + (ok ? 'success' : 'danger');
        msg.textContent = text;
        if (extra) { msg.appendChild(document.createTextNode(' ')); msg.appendChild(extra); }
    }

    function post(action, data) {
        var body = new URLSearchParams(data || {});
        body.set('action', action);
        body.set('device_id', deviceId);
        body.set('csrf_token', csrf);
        return fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (r) { return r.json(); });
    }

    function afterQueued(d, what) {
        if (d && d.success) {
            say(what + ' queued. It is delivered the next time the device checks in.', true);
            window.setTimeout(function () { window.location.hash = '#rmm-jobs'; window.location.reload(); }, 900);
        } else {
            say((d && d.error) || 'The job was not queued.', false);
        }
    }

    function hideModal(id) {
        var el = document.getElementById(id);
        if (el && window.bootstrap) { var inst = window.bootstrap.Modal.getInstance(el); if (inst) { inst.hide(); } }
    }

    // ---- dialogs return focus to the button that opened them (keyboard users keep their place)
    ['rmmRebootModal', 'rmmRunModal'].forEach(function (id) {
        var m = document.getElementById(id);
        if (!m) { return; }
        var opener = null;
        m.addEventListener('show.bs.modal', function (e) { opener = e.relatedTarget || document.activeElement; });
        m.addEventListener('hidden.bs.modal', function () { if (opener && typeof opener.focus === 'function') { opener.focus(); } });
    });

    // ---- reboot dialog
    var rebootGo = document.getElementById('rmm-reboot-go');
    var rebootBox = document.getElementById('rmm-reboot-confirm');
    if (rebootGo && rebootBox) {
        rebootBox.addEventListener('change', function () { rebootGo.disabled = !rebootBox.checked; });
        var rebootModal = document.getElementById('rmmRebootModal');
        if (rebootModal) { rebootModal.addEventListener('hidden.bs.modal', function () { rebootBox.checked = false; rebootGo.disabled = true; }); }
        rebootGo.addEventListener('click', function () {
            if (!rebootBox.checked) { return; }
            rebootGo.disabled = true;
            post('submit_job', { type: 'reboot', destructive: '1', confirm: '1' }).then(function (d) { hideModal('rmmRebootModal'); afterQueued(d, 'Reboot'); })
                .catch(function () { hideModal('rmmRebootModal'); say('Network error.', false); });
        });
    }

    // ---- collect inventory
    function collect() {
        post('submit_job', { type: 'collect' }).then(function (d) { afterQueued(d, 'Inventory collection'); }).catch(function () { say('Network error.', false); });
    }
    ['rmm-act-collect', 'rmm-collect-empty'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) { b.addEventListener('click', function (e) { e.preventDefault(); collect(); }); }
    });

    // ---- run a script: one dialog, three sources ("What to run": the script library, a saved script, free-form text). The library pane is a plain form post to
    // agent/post/rmm_automation_scr.php (the Jobs-tab card's handler); this code only switches panes, filters the list and swaps the chosen script's parameter block.
    var type = document.getElementById('rmm-run-type');
    var libForm = document.getElementById('rmm-run-lib-form');
    var runForm = document.getElementById('rmm-run-form');
    var platform = panel.getAttribute('data-platform');
    function syncRunPane() {
        var kind = type ? type.value : '';
        if (libForm) { libForm.hidden = kind !== 'library'; }
        if (runForm) {
            runForm.hidden = kind === 'library';
            var sw = document.getElementById('rmm-run-saved-wrap');
            var tw = document.getElementById('rmm-run-text-wrap');
            if (sw) { sw.classList.toggle('d-none', kind !== 'saved'); }
            if (tw) { tw.classList.toggle('d-none', kind !== 'text'); }
        }
    }
    if (type) {
        type.addEventListener('change', function () {
            syncRunPane();
            // a pane that held the focus was just hidden: keep the focus inside the dialog (Esc and Tab must still reach it)
            var act = document.activeElement;
            if (type.tagName === 'SELECT' && (!act || act === document.body || (act.closest && act.closest('[hidden]')))) { type.focus(); }
        });
        syncRunPane();
    }

    if (libForm) {
        var search = document.getElementById('rmm-run-lib-q');
        var pick = document.getElementById('rmm-run-lib-script');
        var count = document.getElementById('rmm-run-lib-count');
        var goLib = document.getElementById('rmm-run-lib-go');
        var snapshot = Array.prototype.map.call(pick.querySelectorAll('optgroup'), function (g) {
            return { label: g.getAttribute('label'), risk: g.getAttribute('data-risk'), options: Array.prototype.map.call(g.querySelectorAll('option'), function (o) {
                return { value: o.value, text: o.textContent, search: o.getAttribute('data-search') || '' };
            }) };
        });
        var showBlock = function () {
            Array.prototype.forEach.call(libForm.querySelectorAll('.rmm-run-lib-block'), function (b) {
                var on = b.getAttribute('data-script-id') === pick.value;
                b.hidden = !on;
                Array.prototype.forEach.call(b.querySelectorAll('input, select, textarea'), function (i) { i.disabled = !on; });
            });
            if (goLib) { goLib.disabled = pick.value === ''; }
        };
        var render = function () {
            var q = (search.value || '').toLowerCase().trim();
            var keep = pick.value;
            var n = 0;
            while (pick.firstChild) { pick.removeChild(pick.firstChild); }
            snapshot.forEach(function (g) {
                var g2 = null;
                g.options.forEach(function (o) {
                    if (q !== '' && o.search.indexOf(q) === -1) { return; }
                    if (!g2) { g2 = document.createElement('optgroup'); g2.label = g.label; g2.setAttribute('data-risk', g.risk); pick.appendChild(g2); }
                    var el = document.createElement('option');
                    el.value = o.value; el.textContent = o.text; el.setAttribute('data-search', o.search);
                    if (o.value === keep) { el.selected = true; }
                    g2.appendChild(el); n++;
                });
            });
            if (n > 0 && pick.selectedIndex < 0) { pick.selectedIndex = 0; }
            count.textContent = n === 0 ? 'No script matches.' : n + (n === 1 ? ' script matches.' : ' scripts match.');
            showBlock();
        };
        search.addEventListener('input', render);
        pick.addEventListener('change', showBlock);
        libForm.addEventListener('submit', function (e) {
            if (pick.value === '') { e.preventDefault(); say('Pick a script first.', false); return; }
            if (goLib) { window.setTimeout(function () { goLib.disabled = true; }, 0); }   // one submit only (the page reloads on the answer)
        });
        var libModal = document.getElementById('rmmRunModal');
        if (libModal) { libModal.addEventListener('shown.bs.modal', function () { if (type && type.value === 'library') { search.focus(); } }); }
        showBlock();
    }

    if (runForm) {
        var destr = document.getElementById('rmm-run-destructive');
        var cwrap = document.getElementById('rmm-run-confirm-wrap');
        var cbox = document.getElementById('rmm-run-confirm');
        var syncDestr = function () { cwrap.classList.toggle('d-none', !destr.checked); };
        destr.addEventListener('change', syncDestr); syncDestr();
        runForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!type || type.value === 'library') { return; }
            if (destr.checked && !cbox.checked) { say('Confirm the destructive script first.', false); return; }
            // free-form text is the Core job type of the device's platform: powershell (Windows) or shell (Linux)
            var data = { type: platform === 'linux' ? 'shell' : 'powershell', timeout_s: document.getElementById('rmm-run-timeout').value, destructive: destr.checked ? '1' : '', confirm: destr.checked && cbox.checked ? '1' : '' };
            if (type.value === 'saved') { data.script_id = document.getElementById('rmm-run-saved').value; } else { data.script = document.getElementById('rmm-run-text').value; }
            var goBtn = document.getElementById('rmm-run-go');
            goBtn.disabled = true;
            post('submit_job', data).then(function (d) { hideModal('rmmRunModal'); afterQueued(d, 'Script job'); goBtn.disabled = false; })
                .catch(function () { hideModal('rmmRunModal'); say('Network error.', false); goBtn.disabled = false; });
        });
    }

    // ---- remote session (an offline device asks for an in-page "launch anyway", never window.confirm)
    // The tab is opened inside the click (a browser blocks one opened after the asynchronous launch call), then sent to the session when the launch answers.
    function openRemote(force) {
        var L = window.RivetRmmLaunch;
        var tab = L.begin();
        post('remote', force ? { force: '1' } : {}).then(function (d) {
            if (d.success && d.url) {
                if (tab.go(d.url)) { say('Remote session opened.', true); return; }
                say('Your browser blocked the new tab.', false, L.link(d.url, 'Open remote session'));
                return;
            }
            tab.close();
            if (d.code === 'device_offline' && !force) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'btn btn-sm btn-outline-danger ms-2'; b.textContent = 'Launch anyway';
                b.addEventListener('click', function () { openRemote(true); });
                say(d.error || 'The device is offline.', false, b);
                return;
            }
            say(d.error || 'Could not start the session.', false);
        }).catch(function () { tab.close(); say('Network error.', false); });
    }
    ['rmm-act-remote', 'rmm-remote-btn'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) { b.addEventListener('click', function () { openRemote(false); }); }
    });

    // ---- MeshCentral node mapping (administrators)
    var mf = document.getElementById('rmm-mesh-form');
    if (mf) {
        mf.addEventListener('submit', function (e) {
            e.preventDefault();
            post('set_mesh_node', { mesh_node_id: document.getElementById('rmm-mesh-node').value }).then(function (d) {
                say(d.success ? 'Mapping saved.' : (d.error || 'Not saved.'), !!d.success);
                if (d.success) { window.setTimeout(function () { window.location.reload(); }, 700); }
            }).catch(function () { say('Network error.', false); });
        });
    }

    // ---- jobs: cancel, lazy output
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-cancel-btn'), function (b) {
        b.addEventListener('click', function () {
            post('cancel_job', { job_id: b.getAttribute('data-job') }).then(function (d) {
                if (d.success) { window.location.reload(); } else { say(d.error || 'Not cancelled.', false); }
            }).catch(function () { say('Network error.', false); });
        });
    });
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-output-btn'), function (b) {
        b.addEventListener('click', function () {
            var row = document.getElementById('rmm-out-' + b.getAttribute('data-job'));
            if (!row) { return; }
            var open = !row.hidden;
            if (open) { row.hidden = true; b.setAttribute('aria-expanded', 'false'); return; }
            var pre = row.querySelector('pre');
            var meta = row.querySelector('.rmm-out-meta');
            if (row.getAttribute('data-loaded') !== '1') {
                pre.textContent = 'Loading...';
                var q = new URLSearchParams({ device_id: deviceId, job_id: b.getAttribute('data-job') });
                fetch(outputUrl + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) { pre.textContent = d.error || 'Output is not available.'; return; }
                        pre.textContent = d.output === null || d.output === '' ? '(no output)' : d.output;
                        meta.textContent = 'Exit code ' + (d.exit_code === null ? 'none' : d.exit_code) + (d.truncated ? ' - output was truncated to its size cap' : '');
                        row.setAttribute('data-loaded', '1');
                    })
                    .catch(function () { pre.textContent = 'Network error.'; });
            }
            row.hidden = false;
            b.setAttribute('aria-expanded', 'true');
        });
    });
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-copy-btn'), function (b) {
        b.addEventListener('click', function () {
            var pre = b.parentNode.querySelector('pre');
            if (pre && navigator.clipboard) { navigator.clipboard.writeText(pre.textContent).then(function () { b.textContent = 'Copied'; }); }
        });
    });

    // ---- tags (RivetCore 1.0.0-rc.9): add with the browser's own autocomplete (a datalist of the tags that exist), remove with the x on the chip
    var tagForm = document.getElementById('rmm-tag-form');
    if (tagForm) {
        tagForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = document.getElementById('rmm-tag-input');
            var name = (input.value || '').trim();
            if (name === '') { say('Type a tag name first.', false); input.focus(); return; }
            post('tag_add', { tag: name }).then(function (d) {
                if (d.success) { window.location.reload(); } else { say(d.error || 'The tag was not added.', false); input.focus(); }
            }).catch(function () { say('Network error.', false); });
        });
    }
    Array.prototype.forEach.call(panel.ownerDocument.querySelectorAll('#rmm-tags .rmm-tag-x'), function (b) {
        b.addEventListener('click', function () {
            b.disabled = true;
            post('tag_remove', { tag_id: b.getAttribute('data-tag-id') }).then(function (d) {
                if (d.success) { window.location.reload(); } else { b.disabled = false; say(d.error || 'The tag was not removed.', false); }
            }).catch(function () { b.disabled = false; say('Network error.', false); });
        });
    });

    // ---- software: ask the device for a full list at its next check-in
    ['rmm-act-sw-refresh', 'rmm-sw-refresh'].forEach(function (id) {
        var b = document.getElementById(id);
        if (!b) { return; }
        b.addEventListener('click', function (e) {
            e.preventDefault();
            post('software_refresh', {}).then(function (d) { say(d.success ? d.message : (d.error || 'The request was not sent.'), !!d.success); }).catch(function () { say('Network error.', false); });
        });
    });

    // ---- tab deep links (#rmm-overview, #rmm-inventory, #rmm-software, #rmm-jobs)
    function showHash() {
        var h = (window.location.hash || '').replace('#', '');
        var m = /^rmm-(overview|inventory|software|jobs|policy|alerting|remote|logs)$/.exec(h);
        if (!m || !window.bootstrap) { return; }
        var btn = document.getElementById('rmm-tab-' + m[1]);
        if (btn) { window.bootstrap.Tab.getOrCreateInstance(btn).show(); }
    }
    showHash();
    window.addEventListener('hashchange', showHash);
    Array.prototype.forEach.call(panel.querySelectorAll('[data-bs-toggle="tab"]'), function (t) {
        t.addEventListener('shown.bs.tab', function () {
            var id = t.id.replace('rmm-tab-', '');
            if (window.history && window.history.replaceState) { window.history.replaceState(null, '', '#rmm-' + id); }
        });
    });
}());
