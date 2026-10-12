/*
 * Push the MeshCentral agent to enrolled devices (RivetCore 1.0.0-rc.16). Markup: includes/rmm_mesh_push.php (escaped on the server). Endpoint: agent/post/rmm_mesh_push.php.
 *
 * Linked by includes/footer.php only on a page that drew one of the controls (the Install / Remove buttons of a device, a mesh job's "View result" button, or the Agent Fleet
 * bulk dialog), so with the module, the remote-tools switch or the viewer's ability off this file is never requested.
 *
 * Nothing here decides anything: hiding or disabling a control is cosmetic, and every request is authorized again by RivetCore. Server text that is not a ready-made fragment
 * is only ever written with textContent; the fragments (a job's result) are escaped on the server. No window.confirm / alert / prompt and no inline handlers (the page CSP
 * blocks them): destructive and bulk actions use the in-page dialogs #rmmMeshPushConfirm and #rmmMeshBulk.
 */
(function () {
    'use strict';
    var MAX_WAIT_MS = 20 * 60 * 1000;
    var dlg = document.getElementById('rmmMeshPushConfirm');
    var bulk = document.getElementById('rmmMeshBulk');
    // A modal belongs at the end of the body: the stacking contexts of a tab pane would put it under the backdrop.
    [dlg, bulk].forEach(function (m) { if (m && m.parentNode !== document.body) { document.body.appendChild(m); } });

    function $(id) { return document.getElementById(id); }
    function post(url, csrf, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
        body.set('csrf_token', csrf);
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
            .then(function (r) {
                return r.json().catch(function () { return { success: false, error: 'The server gave an unexpected answer (' + r.status + '). Your session may have expired: reload the page.' }; });
            }, function () { return { success: false, error: 'Network error. Check the connection and try again.' }; });
    }
    function say(el, text, kind) {
        if (!el) { return; }
        if (!el.hasAttribute('data-base')) { el.setAttribute('data-base', el.className); }
        var base = el.getAttribute('data-base');
        el.className = text ? (base + ' alert alert-' + (kind || 'info') + ' py-2').trim() : base;
        el.textContent = text || '';
    }
    function modalOf(el) { return window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(el) : null; }

    // ---------------------------------------------------------------- one device
    var pending = null;      // { action, group, deviceId, host }
    var timers = {};
    function poll(group, deviceId, jobId, started, prefix) {
        var status = group.querySelector('[data-mesh-push-status]');
        var key = deviceId + ':' + jobId;
        if (timers[key]) { clearTimeout(timers[key]); }
        post(dlg.getAttribute('data-post-url'), dlg.getAttribute('data-csrf'), { action: 'job_result', device_id: deviceId, job_id: jobId }).then(function (d) {
            if (!d.success) { say(status, d.error || 'The result could not be read.', 'warning'); return; }
            status.className = status.getAttribute('data-base') || '';
            status.textContent = '';
            if (prefix) {   // what the server said about the click (for example "already queued") stays above the job's state
                var lead = document.createElement('div');
                lead.className = 'mb-1';
                lead.textContent = prefix;
                status.appendChild(lead);
            }
            var body = document.createElement('div');
            body.innerHTML = d.html;   // a fragment escaped by includes/rmm_mesh_push.php
            status.appendChild(body);
            if (d.final) {
                if (d.state === 'succeeded') {
                    var note = document.createElement('div');
                    note.className = 'small text-muted mt-1';
                    note.textContent = 'The Mesh agent state on this page updates when the device next checks in. Reload the page then.';
                    status.appendChild(note);
                }
                return;
            }
            if (Date.now() - started < MAX_WAIT_MS) { timers[key] = setTimeout(function () { poll(group, deviceId, jobId, started, prefix); }, 5000); }
        });
    }

    function openConfirm(action, group) {
        if (!dlg) { return; }
        var host = group.getAttribute('data-hostname') || 'this device';
        var installed = group.getAttribute('data-installed') === '1';
        pending = { action: action, group: group, deviceId: group.getAttribute('data-device-id'), host: host, opener: document.activeElement };
        var go = $('rmmMeshPushGo'), check = $('rmmMeshPushCheck'), force = $('rmmMeshPushForce');
        check.checked = false; force.checked = false;
        $('rmmMeshPushForceWrap').classList.toggle('d-none', !(action === 'install' && installed));
        $('rmmMeshPushCheckWrap').classList.toggle('d-none', action !== 'uninstall');
        if (action === 'install') {
            $('rmmMeshPushTitleText').textContent = 'Install the MeshCentral agent';
            $('rmmMeshPushText').textContent = 'Install the MeshCentral agent on ' + host + '? The device downloads it from your own Mesh server at its next check-in and installs it. The RivetIT agent is not touched.';
            $('rmmMeshPushTarget').textContent = installed ? 'The device already reports the Mesh agent as installed: nothing is queued unless you tick the box above.' : '';
            go.className = 'btn btn-primary'; go.textContent = 'Install'; go.disabled = false;
        } else {
            $('rmmMeshPushTitleText').textContent = 'Remove the MeshCentral agent';
            $('rmmMeshPushText').textContent = 'Remove the MeshCentral agent from ' + host + '? Remote control through MeshCentral stops working on this device until the agent is installed again. The RivetIT agent stays.';
            $('rmmMeshPushTarget').textContent = 'This runs the Mesh agent\'s own uninstaller on the device and is sent at most once.';
            $('rmmMeshPushCheckLabel').textContent = 'I understand and want to remove the MeshCentral agent from ' + host + '.';
            go.className = 'btn btn-danger'; go.textContent = 'Remove'; go.disabled = true;
        }
        var m = modalOf(dlg);
        if (m) { m.show(); }
    }

    function run() {
        if (!pending) { return; }
        var p = pending, status = p.group.querySelector('[data-mesh-push-status]');
        var data = { action: p.action, device_id: p.deviceId };
        if (p.action === 'install' && $('rmmMeshPushForce').checked) { data.force = '1'; }
        if (p.action === 'uninstall') { data.confirm = '1'; }
        var m = modalOf(dlg);
        if (m) { m.hide(); }
        say(status, 'Sending...', 'info');
        post(dlg.getAttribute('data-post-url'), dlg.getAttribute('data-csrf'), data).then(function (d) {
            if (!d.success) { say(status, d.error || 'It could not be queued.', 'danger'); return; }
            if (d.code === 'already_installed' || d.code === 'already_queued') {
                say(status, d.message, 'info');
                if (d.code === 'already_queued' && d.job_id) { poll(p.group, p.deviceId, d.job_id, Date.now(), d.message); }
                return;
            }
            say(status, d.message || 'Queued.', 'success');
            if (d.job_id) { poll(p.group, p.deviceId, d.job_id, Date.now()); }
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-mesh-push-action]') : null;
        if (b && !b.disabled) {
            var g = b.closest('[data-mesh-push]');
            if (g) { openConfirm(b.getAttribute('data-mesh-push-action'), g); }
            return;
        }
        var r = e.target.closest ? e.target.closest('[data-mesh-push-result]') : null;
        if (r) { toggleResult(r); }
    });
    if (dlg) {
        // the dialog is opened from script, so Bootstrap does not know the control to hand focus back to: do it here (Escape, Cancel and Confirm all end up here)
        dlg.addEventListener('hidden.bs.modal', function () {
            var o = pending && pending.opener;
            if (o && o.focus && document.body.contains(o)) { try { o.focus(); } catch (e) { /* ignore */ } }
        });
        $('rmmMeshPushCheck').addEventListener('change', function () { if (pending && pending.action === 'uninstall') { $('rmmMeshPushGo').disabled = !this.checked; } });
        $('rmmMeshPushGo').addEventListener('click', run);
    }

    // ---------------------------------------------------------------- the result of a job, in the job list
    function toggleResult(btn) {
        var row = document.getElementById('rmm-mres-' + btn.getAttribute('data-mesh-push-result'));
        if (!row) { return; }
        var open = !row.hidden;
        if (open) { row.hidden = true; btn.setAttribute('aria-expanded', 'false'); return; }
        var body = row.querySelector('[data-mesh-push-result-body]');
        row.hidden = false; btn.setAttribute('aria-expanded', 'true');
        body.textContent = 'Loading...';
        post(btn.getAttribute('data-post-url') || (dlg && dlg.getAttribute('data-post-url')), btn.getAttribute('data-csrf') || (dlg && dlg.getAttribute('data-csrf')),
            { action: 'job_result', device_id: btn.getAttribute('data-device-id'), job_id: btn.getAttribute('data-mesh-push-result') }).then(function (d) {
            if (d.success) { body.innerHTML = d.html; } else { body.textContent = d.error || 'The result could not be read.'; }   // d.html is escaped on the server
        });
    }

    // ---------------------------------------------------------------- Agent Fleet: many devices
    if (bulk) {
        var target = $('rmm-mb-target'), force = $('rmm-mb-force'), box = $('rmm-mb-confirm'), boxText = $('rmm-mb-confirm-text'), check = $('rmm-mb-check'), result = $('rmm-mb-result'), go = $('rmm-mb-go');
        var step = 1;
        var url = bulk.getAttribute('data-post-url'), csrf = bulk.getAttribute('data-csrf');
        var reset = function () {
            step = 1; box.classList.add('d-none'); check.checked = false; go.disabled = false; go.textContent = 'Count the devices';
        };
        var args = function (confirm) {
            var t = target.value.split(':');
            var d = { action: 'bulk', target_type: t[0], target_id: t[1] || '0' };
            if (force.checked) { d.force = '1'; }
            if (confirm) { d.confirm = '1'; }
            return d;
        };
        var summary = function (d) {
            var parts = [['created', 'queued'], ['skipped_installed', 'already have the Mesh agent'], ['skipped_queued', 'already have an install queued'],
                ['skipped_unsupported', 'run an agent too old for this (update it first)'], ['skipped_denied', 'are in a department you may not manage'], ['skipped_error', 'could not be queued']];
            var lines = [];
            parts.forEach(function (p) { if (d[p[0]] > 0) { lines.push(d[p[0]] + ' ' + p[1]); } });
            return (d.device_count || 0) + ' device(s) reached: ' + (lines.length ? lines.join('; ') : 'nothing to do') + '.';
        };
        target.addEventListener('change', reset);
        force.addEventListener('change', reset);
        check.addEventListener('change', function () { if (step === 2) { go.disabled = !check.checked; } });
        bulk.addEventListener('show.bs.modal', function () { reset(); say(result, '', ''); });
        go.addEventListener('click', function () {
            if (go.disabled) { return; }
            say(result, '', '');
            if (step === 1) {
                go.disabled = true;
                post(url, csrf, args(false)).then(function (d) {
                    go.disabled = false;
                    if (d.code === 'confirmation_required' && d.device_count > 0) {   // Core's first, unconfirmed answer: the number of devices it would reach
                        step = 2;
                        boxText.textContent = 'This installs the MeshCentral agent on up to ' + d.device_count + ' device' + (d.device_count === 1 ? '' : 's') + '. Devices that already have it, run an agent too old for it or already have an install queued are skipped.';
                        box.classList.remove('d-none'); check.checked = false; go.disabled = true;
                        go.textContent = 'Install on ' + d.device_count + ' device' + (d.device_count === 1 ? '' : 's');
                        return;
                    }
                    say(result, d.error || d.message || 'Nothing to do.', d.success ? 'info' : 'danger');   // approval_required, too_many, no device, ... in Core's words
                });
                return;
            }
            go.disabled = true;
            post(url, csrf, args(true)).then(function (d) {
                reset();
                if (!d.success) { say(result, d.error || 'It could not be queued.', 'danger'); return; }
                say(result, summary(d), d.created > 0 ? 'success' : 'info');
                if (d.errors && d.errors.length) {
                    var ul = document.createElement('ul'); ul.className = 'mb-0 mt-1';
                    d.errors.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
                    result.appendChild(ul);
                }
            });
        });
    }
})();
