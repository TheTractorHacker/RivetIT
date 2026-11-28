/*
 * "Move to Department" (RivetCore 1.0.0-rc.60): a small dialog opened by any button with [data-rmm-move] (the asset edit modal and the RMM panel on the asset page).
 * The button carries data-device-id, data-device-name, data-csrf, data-post-url and data-confirm-text (the plain-words confirmation, written by the server).
 * Nothing here decides anything: agent/post/rmm_agent.php re-checks rmm.device.manage and the CSRF token on every call and Core's TechnicianActions::transfer() does the
 * move. Server text is only ever written with textContent; no inline handlers (the page CSP blocks them).
 */
(function () {
    'use strict';
    if (window.__rmmDeviceMoveBound) { return; }
    window.__rmmDeviceMoveBound = true;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function post(url, csrf, fields) {
        var body = new URLSearchParams(fields);
        body.set('csrf_token', csrf);
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected answer from the server.' }; }); });
    }

    function open(btn) {
        var cfg = {
            deviceId: btn.getAttribute('data-device-id') || '',
            name: btn.getAttribute('data-device-name') || 'this computer',
            csrf: btn.getAttribute('data-csrf') || ((btn.closest('[data-csrf]') || {}).getAttribute ? btn.closest('[data-csrf]').getAttribute('data-csrf') : '') || (document.querySelector('input[name="csrf_token"]') || {}).value || '',
            url: btn.getAttribute('data-post-url') || '/agent/post/rmm_agent.php',
            confirmText: btn.getAttribute('data-confirm-text') || ''
        };

        var old = document.getElementById('rmmMoveModal');
        if (old && old.parentNode) { old.parentNode.removeChild(old); }

        var modal = el('div', 'modal fade');
        modal.id = 'rmmMoveModal';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'rmmMoveTitle');
        modal.setAttribute('aria-hidden', 'true');
        var dlg = el('div', 'modal-dialog');
        var content = el('div', 'modal-content');
        var head = el('div', 'modal-header');
        var title = el('h5', 'modal-title', 'Move to Department');
        title.id = 'rmmMoveTitle';
        var x = el('button', 'btn-close');
        x.type = 'button';
        x.setAttribute('data-bs-dismiss', 'modal');
        x.setAttribute('aria-label', 'Close');
        head.appendChild(title);
        head.appendChild(x);

        var bodyEl = el('div', 'modal-body');
        bodyEl.appendChild(el('p', 'mb-3', 'Move ' + cfg.name + ' to another department.'));
        var g1 = el('div', 'mb-3');
        var l1 = el('label', 'form-label', 'Department');
        l1.htmlFor = 'rmmMoveDept';
        var dept = el('select', 'form-select');
        dept.id = 'rmmMoveDept';
        dept.disabled = true;
        var ph = el('option', '', 'Loading...');
        ph.value = '';
        dept.appendChild(ph);
        g1.appendChild(l1);
        g1.appendChild(dept);
        var g2 = el('div', 'mb-3');
        var l2 = el('label', 'form-label', 'Site (optional)');
        l2.htmlFor = 'rmmMoveSite';
        var site = el('select', 'form-select');
        site.id = 'rmmMoveSite';
        site.disabled = true;
        var none = el('option', '', 'No site');
        none.value = '0';
        site.appendChild(none);
        g2.appendChild(l2);
        g2.appendChild(site);
        var warn = el('div', 'alert alert-info mb-3', cfg.confirmText);
        warn.id = 'rmmMoveConfirmText';
        var err = el('div', 'alert alert-danger d-none mb-0');
        err.id = 'rmmMoveError';
        err.setAttribute('role', 'alert');
        bodyEl.appendChild(g1);
        bodyEl.appendChild(g2);
        bodyEl.appendChild(warn);
        bodyEl.appendChild(err);

        var foot = el('div', 'modal-footer');
        var cancel = el('button', 'btn btn-light', 'Cancel');
        cancel.type = 'button';
        cancel.setAttribute('data-bs-dismiss', 'modal');
        var go = el('button', 'btn btn-primary', 'Move computer');
        go.type = 'button';
        go.disabled = true;
        go.setAttribute('aria-describedby', 'rmmMoveConfirmText');
        foot.appendChild(cancel);
        foot.appendChild(go);

        content.appendChild(head);
        content.appendChild(bodyEl);
        content.appendChild(foot);
        dlg.appendChild(content);
        modal.appendChild(dlg);
        document.body.appendChild(modal);

        function showError(msg) {
            err.textContent = msg;
            err.classList.remove('d-none');
        }

        post(cfg.url, cfg.csrf, { action: 'move_options', device_id: cfg.deviceId }).then(function (d) {
            dept.textContent = '';
            var first = el('option', '', d.success ? 'Choose a department...' : 'Not available');
            first.value = '';
            dept.appendChild(first);
            if (!d.success) { showError(d.error || 'Could not load the departments.'); return; }
            (d.departments || []).forEach(function (c) {
                var o = el('option', '', c.name);
                o.value = String(c.id);
                dept.appendChild(o);
            });
            if (!(d.departments || []).length) { showError('There is no other department you may move computers to.'); return; }
            dept.disabled = false;
        }).catch(function () { showError('Network error.'); });

        dept.addEventListener('change', function () {
            site.textContent = '';
            var o0 = el('option', '', 'No site');
            o0.value = '0';
            site.appendChild(o0);
            go.disabled = dept.value === '';
            if (dept.value === '') { site.disabled = true; return; }
            site.disabled = true;
            post(cfg.url, cfg.csrf, { action: 'move_locations', device_id: cfg.deviceId, client_id: dept.value }).then(function (d) {
                (d.locations || []).forEach(function (l) {
                    var o = el('option', '', l.name);
                    o.value = String(l.id);
                    site.appendChild(o);
                });
                site.disabled = false;
            }).catch(function () { site.disabled = false; });
        });

        go.addEventListener('click', function () {
            if (dept.value === '') { return; }
            go.disabled = true;
            err.classList.add('d-none');
            post(cfg.url, cfg.csrf, { action: 'move_department', device_id: cfg.deviceId, client_id: dept.value, location_id: site.value }).then(function (d) {
                if (d.success) {
                    window.location.href = d.redirect || window.location.href;   // reloads at the device in its new department; the server queued the message in the flash area
                } else {
                    showError(d.error || 'The computer was not moved.');
                    go.disabled = false;
                }
            }).catch(function () { showError('Network error.'); go.disabled = false; });
        });

        modal.addEventListener('hidden.bs.modal', function () { if (modal.parentNode) { modal.parentNode.removeChild(modal); } });
        var show = function () { window.bootstrap.Modal.getOrCreateInstance(modal).show(); };
        var parentModal = btn.closest('.modal');
        if (parentModal && window.bootstrap) {
            // Opened from inside the asset edit modal: close that one first (Bootstrap does not stack two focus-trapped modals), then show this one.
            parentModal.addEventListener('hidden.bs.modal', show, { once: true });
            window.bootstrap.Modal.getOrCreateInstance(parentModal).hide();
        } else {
            show();
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rmm-move]');
        if (!btn) { return; }
        e.preventDefault();
        open(btn);
    });
})();
