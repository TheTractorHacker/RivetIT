/*
 * Remote tab of the asset page (RMM Phase 6): services, processes, files and the MeshCentral launch buttons. Markup and every fragment of HTML this file inserts:
 * includes/rmm_rem_ui.php (escaped on the server). Linked by includes/footer.php only on a page that drew the tab, so with the module or the remote-tools switch
 * off this file is never requested.
 *
 * Nothing here decides anything: every action posts to agent/post/rmm_remote_tools.php, which authorizes through RivetCore. Hiding or disabling a control is cosmetic.
 * Server text that is not a ready-made fragment is only ever written with textContent. No window.confirm / alert / prompt: destructive actions use the in-page
 * dialog (#rmmRemConfirm) and the upload dialog (#rmmRemUpload). No inline handlers (the page CSP blocks them).
 */
(function () {
    'use strict';
    var root = document.getElementById('rmm-remote-tools');
    if (!root) { return; }
    var postUrl = root.getAttribute('data-post-url');
    var csrf = root.getAttribute('data-csrf');
    var deviceId = root.getAttribute('data-device-id');
    var hostname = root.getAttribute('data-hostname') || 'the device';
    var uploadMax = parseInt(root.getAttribute('data-upload-max') || '0', 10);
    var MAX_WAIT_MS = 20 * 60 * 1000;

    // The dialogs sit in the tab pane in the markup; a modal belongs at the end of the body (stacking contexts of the page would put it under the backdrop).
    ['rmmRemConfirm', 'rmmRemUpload'].forEach(function (id) {
        var m = document.getElementById(id);
        if (m && m.parentNode !== document.body) { document.body.appendChild(m); }
    });

    function $(id) { return document.getElementById(id); }

    // ---------------------------------------------------------------- plumbing
    function post(action, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
        body.set('action', action);
        body.set('device_id', deviceId);
        body.set('csrf_token', csrf);
        return send({ method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() });
    }
    function send(init) {
        return fetch(postUrl, init).then(function (r) {
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
    function setHtml(el, html) { if (el) { el.innerHTML = html; } }   // html is a fragment escaped by includes/rmm_rem_ui.php
    function fmtBytes(n) {
        if (!isFinite(n)) { return ''; }
        var u = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? String(Math.round(n)) : (Math.round(n * 10) / 10).toString()) + ' ' + u[i];
    }

    // ---------------------------------------------------------------- polling (one timer per name; a new request replaces an older one)
    var timers = {};
    function stopPoll(name) { if (timers[name]) { clearTimeout(timers[name]); timers[name] = null; } }
    function poll(name, step, onTimeout) {
        stopPoll(name);
        var t0 = Date.now();
        (function tick() {
            timers[name] = setTimeout(function () {
                timers[name] = null;
                step().then(function (done) {
                    if (done) { return; }
                    if (Date.now() - t0 > MAX_WAIT_MS) { if (onTimeout) { onTimeout(); } return; }
                    tick();
                });
            }, Date.now() - t0 < 60000 ? 2500 : 10000);
        })();
    }

    // ---------------------------------------------------------------- the confirmation dialog
    var cEl = $('rmmRemConfirm');
    var cCheck = $('rmmRemConfirmCheck');
    var cGo = $('rmmRemConfirmGo');
    var cForceWrap = $('rmmRemConfirmForceWrap');
    var cForce = $('rmmRemConfirmForce');
    var cur = null, lastDialog = null;
    function confirmDialog(o) {
        if (!cEl || !window.bootstrap) { return; }
        cur = o;
        lastDialog = o;
        o.opener = document.activeElement;
        $('rmmRemConfirmText').textContent = o.text;
        $('rmmRemConfirmTarget').textContent = o.target || '';
        $('rmmRemConfirmCheckLabel').textContent = o.checkLabel || 'I understand and want to do this.';
        cCheck.checked = false;
        cForce.checked = false;
        cForceWrap.classList.toggle('d-none', !o.force);
        cGo.disabled = true;
        cGo.className = 'btn ' + (o.goClass || 'btn-danger');
        cGo.textContent = o.goLabel || 'Confirm';
        window.bootstrap.Modal.getOrCreateInstance(cEl).show();
    }
    if (cEl) {
        cCheck.addEventListener('change', function () { cGo.disabled = !cCheck.checked; });
        cGo.addEventListener('click', function () {
            if (!cCheck.checked || !cur) { return; }
            var o = cur;
            cur = null;
            cGo.disabled = true;
            o.done = true;
            window.bootstrap.Modal.getOrCreateInstance(cEl).hide();
            o.onGo(cForce.checked);
        });
        cEl.addEventListener('shown.bs.modal', function () { cCheck.focus(); });
        cEl.addEventListener('hidden.bs.modal', function () {
            var o = lastDialog;
            cur = null;
            if (o && o.opener && document.contains(o.opener) && typeof o.opener.focus === 'function') { o.opener.focus(); } else if (o && o.fallback && $(o.fallback)) { $(o.fallback).focus(); }
        });
    }

    // ---------------------------------------------------------------- list helpers: filter and sort (client side, on the rows already on the page)
    function applyFilter(inputId, tableId) {
        var inp = $(inputId), tbl = $(tableId);
        if (!inp || !tbl) { return; }
        var q = inp.value.trim().toLowerCase();
        tbl.querySelectorAll('tbody tr').forEach(function (tr) { tr.hidden = q !== '' && (tr.getAttribute('data-rem-filter') || '').indexOf(q) === -1; });
    }
    var procSort = null;
    function applySort() {
        var tbl = $('rem-proc-table');
        if (!tbl) { return; }
        tbl.querySelectorAll('thead th').forEach(function (th) {
            var b = th.querySelector('[data-rem-sort]');
            var on = procSort && b && b.getAttribute('data-rem-sort') === procSort.key;
            th.setAttribute('aria-sort', on ? (procSort.dir > 0 ? 'ascending' : 'descending') : 'none');
            var ic = th.querySelector('i');
            if (ic) { ic.className = 'fas ms-1 fa-' + (on ? (procSort.dir > 0 ? 'sort-up' : 'sort-down') : 'sort'); }
        });
        if (!procSort) { return; }
        var body = tbl.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        var numeric = procSort.key === 'pid' || procSort.key === 'cpu' || procSort.key === 'mem';
        rows.sort(function (a, b) {
            var x = a.getAttribute('data-' + procSort.key) || '', y = b.getAttribute('data-' + procSort.key) || '';
            if (numeric) { x = parseFloat(x); y = parseFloat(y); return (x - y) * procSort.dir; }
            return x < y ? -procSort.dir : (x > y ? procSort.dir : 0);
        });
        rows.forEach(function (r) { body.appendChild(r); });
    }
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-sort]');
        if (!b) { return; }
        var key = b.getAttribute('data-rem-sort');
        procSort = procSort && procSort.key === key ? { key: key, dir: -procSort.dir } : { key: key, dir: key === 'cpu' || key === 'mem' ? -1 : 1 };
        applySort();
        b.focus();
    });
    [['rem-svc-filter', 'rem-svc-table'], ['rem-proc-filter', 'rem-proc-table']].forEach(function (p) {
        var inp = $(p[0]);
        if (inp) { inp.addEventListener('input', function () { applyFilter(p[0], p[1]); }); }
    });

    // ---------------------------------------------------------------- one list (services or processes)
    function listPanel(o) {
        var body = $(o.body), status = $(o.status), summary = $(o.summary), btn = $(o.refresh);
        function load() {
            return post(o.snapshot).then(function (d) {
                if (!d.success) { say(status, d.error || 'Could not read the list.', 'danger'); if (btn) { btn.disabled = false; } return true; }
                setHtml(body, d.html);
                body.setAttribute('data-state', d.state);
                if (summary) { summary.textContent = d.summary || ''; }
                applyFilter(o.filter, o.table);
                if (o.table === 'rem-proc-table') { applySort(); }
                if (d.final) {
                    if (btn) { btn.disabled = false; }
                    say(status, d.state === 'succeeded' ? 'The list is up to date.' : (d.state === 'none' ? '' : 'The device did not deliver the list.'), d.state === 'succeeded' ? 'success' : 'warning');
                    return true;
                }
                say(status, 'Waiting for the device to check in and answer...', 'info');
                return false;
            });
        }
        function waitForList() {
            poll(o.name, load, function () { say(status, 'Still waiting for the device. Reload the page later to see the list when it arrives.', 'warning'); if (btn) { btn.disabled = false; } });
        }
        if (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                say(status, 'Asking the device...', 'info');
                var data = {};
                if (o.params) { data = o.params(); }
                post(o.refreshAction, data).then(function (d) {
                    if (!d.success) { say(status, d.error || 'The request was not queued.', 'danger'); btn.disabled = false; return; }
                    say(status, 'Queued. The device answers at its next check-in; this page keeps checking.', 'info');
                    setHtml(body, '<p class="mb-0" role="status"><span class="badge text-bg-info"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>Queued, waiting for the device</span></p>');
                    waitForList();
                });
            });
        }
        var st = body ? body.getAttribute('data-state') : '';
        if (st === 'queued' || st === 'running') { if (btn) { btn.disabled = true; } say(status, 'A list was asked for earlier. Waiting for the device...', 'info'); waitForList(); }
        return { load: load };
    }
    listPanel({ name: 'svc', body: 'rem-svc-body', status: 'rem-svc-status', summary: 'rem-svc-summary', refresh: 'rem-svc-refresh', refreshAction: 'svc_refresh', snapshot: 'svc_snapshot', filter: 'rem-svc-filter', table: 'rem-svc-table' });
    listPanel({ name: 'proc', body: 'rem-proc-body', status: 'rem-proc-status', summary: 'rem-proc-summary', refresh: 'rem-proc-refresh', refreshAction: 'proc_refresh', snapshot: 'proc_snapshot', filter: 'rem-proc-filter', table: 'rem-proc-table',
        params: function () { return { sort: $('rem-proc-sort').value, limit: $('rem-proc-limit').value }; } });
    applySort();

    // ---------------------------------------------------------------- follow a queued control / kill job to its end
    function trackJob(name, resultEl, jobId, afterText) {
        function step() {
            return post('job_result', { job_id: jobId }).then(function (d) {
                if (!d.success) { say(resultEl, d.error || 'Could not read the result.', 'danger'); return true; }
                if (!resultEl.hasAttribute('data-base')) { resultEl.setAttribute('data-base', resultEl.className); }
                resultEl.className = (resultEl.getAttribute('data-base') + ' alert alert-' + (d.state === 'succeeded' ? 'success' : (d.final ? 'danger' : 'info')) + ' py-2').trim();
                setHtml(resultEl, d.html + (d.final && afterText ? '<div class="small mt-1"></div>' : ''));
                if (d.final && afterText) { var s = resultEl.querySelector('.small.mt-1'); if (s) { s.textContent = afterText; } }
                return !!d.final;
            });
        }
        poll(name, step, function () { say(resultEl, 'Still waiting for the device. Reload the page later to see the result.', 'warning'); });
        step().then(function (done) { if (done) { stopPoll(name); } });
    }
    var verbWords = {
        stop: { text: 'Stopping a service interrupts whatever uses it until someone starts it again.', go: 'Stop service' },
        restart: { text: 'Restarting a service makes it unavailable for a moment and drops the connections that use it.', go: 'Restart service' },
        disable: { text: 'Disabling a service changes how it starts: it will not start by itself, and whatever depends on it can break.', go: 'Disable service' }
    };
    var verbDone = { start: 'Start', stop: 'Stop', restart: 'Restart', enable: 'Enable', disable: 'Disable', manual: 'Set to manual' };

    function runService(name, verb, confirmed) {
        var result = $('rem-svc-result'), status = $('rem-svc-status');
        say(status, verbDone[verb] + ' ' + name + ': asking the device...', 'info');
        post('svc_control', { name: name, verb: verb, confirm: confirmed ? '1' : '' }).then(function (d) {
            if (!d.success) { say(status, d.error || 'The request was refused.', 'danger'); return; }
            say(status, verbDone[verb] + ' ' + name + ' queued. The device carries it out at its next check-in.', 'info');
            trackJob('svcjob', result, d.job_id, 'Refresh the list to see the new state.');
        });
    }
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-svc]');
        if (!b) { return; }
        var verb = b.getAttribute('data-rem-svc'), name = b.getAttribute('data-name'), disp = b.getAttribute('data-display') || name;
        if (verbWords[verb]) {
            confirmDialog({ text: verbWords[verb].text, target: verbDone[verb] + ': ' + disp + ' (' + name + ') on ' + hostname, checkLabel: 'I understand this interrupts the device and want to do it.', goLabel: verbWords[verb].go,
                fallback: 'rem-svc-refresh', onGo: function () { runService(name, verb, true); } });
        } else {
            runService(name, verb, false);
        }
    });

    // ---------------------------------------------------------------- end a process
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-kill]');
        if (!b) { return; }
        var pid = b.getAttribute('data-pid'), name = b.getAttribute('data-name');
        confirmDialog({ text: 'Ending a process can lose unsaved work and stop whatever depends on it. The agent refuses to end itself, system processes and kernel threads.', target: 'End process ' + name + ' (id ' + pid + ') on ' + hostname,
            checkLabel: 'I understand and want to end this process.', goLabel: 'End process', force: true, fallback: 'rem-proc-refresh',
            onGo: function (force) {
                var status = $('rem-proc-status');
                say(status, 'End ' + name + ' (' + pid + '): asking the device...', 'info');
                post('proc_kill', { pid: pid, name: name, force: force ? '1' : '', confirm: '1' }).then(function (d) {
                    if (!d.success) { say(status, d.error || 'The request was refused.', 'danger'); return; }
                    say(status, 'Queued. The device carries it out at its next check-in.', 'info');
                    trackJob('procjob', $('rem-proc-result'), d.job_id, 'Refresh the list to see what is still running.');
                });
            } });
    });

    // ---------------------------------------------------------------- files: allowed folders, download request, ledger
    var xferBody = $('rem-xfer-body'), xferStatus = $('rem-xfer-status');
    var watching = null;
    function xferText(t) {
        if (t.state === 'complete') {
            return (t.direction === 'download' ? 'Done: ' : 'Delivered: ') + t.file_name + ', ' + fmtBytes(t.size_bytes) + ' (' + t.size_bytes + ' bytes). SHA-256 verified by the server: ' + t.sha256 + '.' + (t.direction === 'download' ? ' Use Download in the list below.' : '');
        }
        if (t.state === 'failed') { return 'The transfer failed' + (t.error ? ': ' + t.error : '.'); }
        if (t.state === 'expired') { return 'The transfer expired before it finished.'; }
        if (t.direction === 'upload' && (t.state === 'queued' || t.state === 'delivering')) {
            return 'Accepted: ' + t.file_name + ', ' + t.size_bytes + ' bytes, SHA-256 ' + t.sha256 + '. The device fetches it at its next check-in and checks it again before writing.';
        }
        if (t.state === 'queued') { return 'Waiting for the device to pick up the request at its next check-in...'; }
        if (t.state === 'receiving') { return 'The device is sending the file...'; }
        if (t.state === 'delivering') { return 'The device is fetching the file...'; }
        if (t.state === 'awaiting_content') { return 'Waiting for the file content...'; }
        return t.state;
    }
    function loadXfers() {
        return post('xfer_list').then(function (d) {
            if (!d.success) { say(xferStatus, d.error || 'Could not read the transfers.', 'danger'); return true; }
            setHtml(xferBody, d.html);
            if (watching) {
                var t = (d.items || []).filter(function (i) { return i.transfer_id === watching; })[0];
                if (t) {
                    var final = ['complete', 'failed', 'expired', 'deleted'].indexOf(t.state) !== -1;
                    say(xferStatus, xferText(t), t.state === 'complete' ? 'success' : (final ? 'danger' : 'info'));
                    if (final) { watching = null; }
                }
            }
            return !d.pending;
        });
    }
    function followXfers(id) {
        if (id) { watching = id; }
        poll('xfer', loadXfers, function () { say(xferStatus, 'Still waiting for the device. Reload the page later.', 'warning'); });
    }
    if (xferBody && xferBody.getAttribute('data-pending') === '1') { followXfers(null); }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-root]');
        if (!b) { return; }
        var inp = $('rem-dl-path');
        if (inp) { inp.value = b.getAttribute('data-rem-root'); inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
    });
    var dl = $('rem-dl-form');
    if (dl) {
        dl.addEventListener('submit', function (e) {
            e.preventDefault();
            var path = $('rem-dl-path').value.trim();
            if (!path) { say(xferStatus, 'Give the full path of the file.', 'danger'); return; }
            $('rem-dl-go').disabled = true;
            say(xferStatus, 'Asking the device...', 'info');
            post('xfer_download', { path: path }).then(function (d) {
                $('rem-dl-go').disabled = false;
                if (!d.success) { say(xferStatus, d.error || 'The request was refused.', 'danger'); return; }
                say(xferStatus, 'Queued. The device sends the file at its next check-in; this page keeps checking.', 'info');
                followXfers(d.transfer_id);
                loadXfers();
            });
        });
    }
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-xfer-delete]');
        if (!b) { return; }
        var id = b.getAttribute('data-rem-xfer-delete'), name = b.getAttribute('data-name');
        confirmDialog({ text: 'The stored copy on this server is removed now. The record stays in the audit log. This does not touch the file on the device.', target: 'Delete the stored copy of ' + name, checkLabel: 'I want to delete the stored copy.', goLabel: 'Delete',
            fallback: 'rem-xfer-status', onGo: function () {
                post('xfer_delete', { transfer_id: id }).then(function (d) {
                    say(xferStatus, d.success ? 'The stored copy was deleted.' : (d.error || 'Could not delete it.'), d.success ? 'success' : 'danger');
                    loadXfers();
                });
            } });
    });

    // ---------------------------------------------------------------- upload (administrators; the control is only drawn for them and the server checks again)
    var upEl = $('rmmRemUpload'), upForm = $('rem-up-form');
    if (upEl && upForm) {
        var file = $('rem-up-file'), path = $('rem-up-path'), conf = $('rem-up-confirm'), go = $('rem-up-go'), msg = $('rem-up-msg'), opener = null;
        var sync = function () {
            var f = file.files && file.files[0];
            if (f && uploadMax > 0 && f.size > uploadMax) { say(msg, 'That file is ' + fmtBytes(f.size) + ', more than the ' + fmtBytes(uploadMax) + ' that can be sent. Choose a smaller file.', 'danger'); go.disabled = true; return; }
            if (msg.textContent && !/Sending/.test(msg.textContent)) { say(msg, '', ''); }
            go.disabled = !(f && path.value.trim() !== '' && conf.checked);
        };
        [file, path, conf].forEach(function (el) { el.addEventListener('input', sync); el.addEventListener('change', sync); });
        upEl.addEventListener('show.bs.modal', function (e) { opener = e.relatedTarget || document.activeElement; });
        upEl.addEventListener('shown.bs.modal', function () { file.focus(); });
        upEl.addEventListener('hidden.bs.modal', function () {
            upForm.reset(); say(msg, '', ''); go.disabled = true;
            if (opener && document.contains(opener) && typeof opener.focus === 'function') { opener.focus(); }
        });
        upForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (go.disabled) { return; }
            var f = file.files[0];
            var fd = new FormData();
            fd.append('action', 'xfer_upload'); fd.append('device_id', deviceId); fd.append('csrf_token', csrf);
            fd.append('path', path.value.trim()); fd.append('file', f, f.name);
            if ($('rem-up-overwrite').checked) { fd.append('overwrite', '1'); }
            if (conf.checked) { fd.append('confirm', '1'); }
            go.disabled = true;
            say(msg, 'Sending ' + fmtBytes(f.size) + ' to the server...', 'info');
            send({ method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: fd }).then(function (d) {
                if (!d.success) { say(msg, d.error || 'The file was not accepted.', 'danger'); go.disabled = false; return; }
                window.bootstrap.Modal.getOrCreateInstance(upEl).hide();
                say(xferStatus, 'Accepted: ' + f.name + ', ' + d.size_bytes + ' bytes, SHA-256 ' + d.sha256 + '. The device fetches it at its next check-in and checks it again before writing.', 'info');
                followXfers(d.transfer_id);
                loadXfers();
            });
        });
    }

    // ---------------------------------------------------------------- MeshCentral
    var meshStatus = $('rem-mesh-status');
    function openMesh(view, force) {
        say(meshStatus, 'Opening the ' + view + ' session...', 'info');
        post('mesh', { view: view, force: force ? '1' : '' }).then(function (d) {
            if (d.success && d.url) {
                var w = window.open(d.url, '_blank', 'noopener');
                if (w) { say(meshStatus, 'The session opened in a new tab.', 'success'); return; }
                say(meshStatus, 'Your browser blocked the new tab. ', 'warning');
                var a = document.createElement('a');
                a.href = d.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Open the session';
                meshStatus.appendChild(a);
                return;
            }
            if (d.code === 'device_offline' && !force) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'btn btn-sm btn-outline-danger ms-2'; b.textContent = 'Launch anyway';
                b.addEventListener('click', function () { openMesh(view, true); });
                say(meshStatus, d.error || 'The device is offline.', 'warning');
                meshStatus.appendChild(b);
                return;
            }
            say(meshStatus, d.error || 'Could not start the session.', 'danger');
        });
    }
    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rem-mesh]');
        if (b) { openMesh(b.getAttribute('data-rem-mesh'), false); }
    });
})();
