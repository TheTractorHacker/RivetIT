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

    // ---- run a script
    var runForm = document.getElementById('rmm-run-form');
    if (runForm) {
        var type = document.getElementById('rmm-run-type');
        var destr = document.getElementById('rmm-run-destructive');
        var cwrap = document.getElementById('rmm-run-confirm-wrap');
        var cbox = document.getElementById('rmm-run-confirm');
        var sync = function () {
            if (!type) { return; }
            document.getElementById('rmm-run-saved-wrap').classList.toggle('d-none', type.value !== 'saved');
            document.getElementById('rmm-run-text-wrap').classList.toggle('d-none', type.value !== 'powershell');
            cwrap.classList.toggle('d-none', !destr.checked);
        };
        if (type) { type.addEventListener('change', sync); destr.addEventListener('change', sync); sync(); }
        runForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!type) { return; }
            if (destr.checked && !cbox.checked) { say('Confirm the destructive script first.', false); return; }
            var data = { type: 'powershell', timeout_s: document.getElementById('rmm-run-timeout').value, destructive: destr.checked ? '1' : '', confirm: destr.checked && cbox.checked ? '1' : '' };
            if (type.value === 'saved') { data.script_id = document.getElementById('rmm-run-saved').value; } else { data.script = document.getElementById('rmm-run-text').value; }
            document.getElementById('rmm-run-go').disabled = true;
            post('submit_job', data).then(function (d) { hideModal('rmmRunModal'); afterQueued(d, 'Script job'); document.getElementById('rmm-run-go').disabled = false; })
                .catch(function () { hideModal('rmmRunModal'); say('Network error.', false); document.getElementById('rmm-run-go').disabled = false; });
        });
    }

    // ---- remote session (an offline device asks for an in-page "launch anyway", never window.confirm)
    function openRemote(force) {
        post('remote', force ? { force: '1' } : {}).then(function (d) {
            if (d.success) { window.open(d.url, '_blank', 'noopener'); say('Remote session opened.', true); return; }
            if (d.code === 'device_offline' && !force) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'btn btn-sm btn-outline-danger ms-2'; b.textContent = 'Launch anyway';
                b.addEventListener('click', function () { openRemote(true); });
                say(d.error || 'The device is offline.', false, b);
                return;
            }
            say(d.error || 'Could not start the session.', false);
        }).catch(function () { say('Network error.', false); });
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

    // ---- tab deep links (#rmm-overview, #rmm-inventory, #rmm-jobs)
    function showHash() {
        var h = (window.location.hash || '').replace('#', '');
        var m = /^rmm-(overview|inventory|jobs)$/.exec(h);
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
