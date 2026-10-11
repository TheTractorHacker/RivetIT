/*
 * RMM Phase 2 and 3 pages (policies, script library, schedules, approvals, custom fields, agent alerts, maintenance windows, escalation policies).
 * Markup: includes/rmm_automation.php and the page renderers. Linked by includes/footer.php only on a page that set $rmm_auto_scripts, so with the RMM module
 * (or the sub-switch) off no page requests it. Nothing here decides anything: every form posts to agent/post/rmm_automation_*.php, which authorizes through
 * RivetCore. No window.confirm / alert, no inline handlers (the page CSP blocks them); server text is only ever written with textContent.
 */
(function () {
    'use strict';

    // ---- in-page confirmation: a submit button with data-rmm-confirm="Plain words" asks first
    var modalEl = document.getElementById('rmmAutoConfirm');
    var pending = null;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rmm-confirm]');
        if (!btn || !modalEl || !window.bootstrap) { return; }
        if (btn.getAttribute('data-rmm-confirmed') === '1') { btn.removeAttribute('data-rmm-confirmed'); return; }
        e.preventDefault();
        pending = btn;
        document.getElementById('rmmAutoConfirmText').textContent = btn.getAttribute('data-rmm-confirm');
        var extra = btn.getAttribute('data-rmm-confirm-check');
        var holder = document.getElementById('rmmAutoConfirmCheckWrap');
        if (holder) { holder.remove(); }
        var go = document.getElementById('rmmAutoConfirmGo');
        go.disabled = false;
        if (extra) {
            var wrap = document.createElement('div');
            wrap.id = 'rmmAutoConfirmCheckWrap';
            wrap.className = 'form-check mt-3';
            var box = document.createElement('input');
            box.type = 'checkbox'; box.className = 'form-check-input'; box.id = 'rmmAutoConfirmCheck';
            var lab = document.createElement('label');
            lab.className = 'form-check-label'; lab.htmlFor = 'rmmAutoConfirmCheck'; lab.textContent = extra;
            wrap.appendChild(box); wrap.appendChild(lab);
            document.getElementById('rmmAutoConfirmText').parentNode.appendChild(wrap);
            go.disabled = true;
            box.addEventListener('change', function () { go.disabled = !box.checked; });
        }
        go.className = 'btn ' + (btn.getAttribute('data-rmm-confirm-class') || 'btn-primary');
        go.textContent = btn.getAttribute('data-rmm-confirm-label') || 'Confirm';
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
    if (modalEl) {
        document.getElementById('rmmAutoConfirmGo').addEventListener('click', function () {
            var btn = pending;
            pending = null;
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            if (!btn) { return; }
            btn.setAttribute('data-rmm-confirmed', '1');
            if (btn.form && typeof btn.form.requestSubmit === 'function') { btn.form.requestSubmit(btn); } else { btn.click(); }
        });
        modalEl.addEventListener('hidden.bs.modal', function () { if (pending && typeof pending.focus === 'function') { pending.focus(); } pending = null; });
    }

    // ---- repeating rows (check templates, parameters, escalation steps and targets): [data-rmm-repeat] holds rows, <template data-rmm-row> is the blank one,
    //      "__i__" in the template becomes the next index, [data-rmm-add] appends a row, [data-rmm-remove] removes the one it sits in.
    function nextIndex(container) {
        var max = -1;
        container.querySelectorAll('[data-rmm-index]').forEach(function (r) { max = Math.max(max, parseInt(r.getAttribute('data-rmm-index'), 10) || 0); });
        return max + 1;
    }
    function addRow(container) {
        var tpl = container.querySelector('template[data-rmm-row]');
        if (!tpl) { return null; }
        var limit = parseInt(container.getAttribute('data-rmm-max') || '0', 10);
        if (limit > 0 && container.querySelectorAll('[data-rmm-index]').length >= limit) { return null; }
        var i = nextIndex(container);
        var html = tpl.innerHTML.replace(/__i__/g, String(i));
        var holder = document.createElement('div');
        holder.innerHTML = html.trim();
        var row = holder.firstElementChild;
        if (!row) { return null; }
        row.setAttribute('data-rmm-index', String(i));
        var list = container.querySelector('[data-rmm-rows]') || container;
        list.appendChild(row);
        bindScopes(row);
        bindWhen(row);
        var first = row.querySelector('input,select,textarea');
        if (first) { first.focus(); }
        return row;
    }
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-rmm-add]');
        if (add) {
            var c = document.querySelector(add.getAttribute('data-rmm-add'));
            if (c) { e.preventDefault(); addRow(c); }
            return;
        }
        var rm = e.target.closest('[data-rmm-remove]');
        if (rm) {
            var row = rm.closest('[data-rmm-index]');
            if (row) {
                e.preventDefault();
                var cont = row.parentNode;
                var prev = row.previousElementSibling || row.nextElementSibling;
                row.remove();
                var focus = (prev && prev.querySelector('input,select,textarea,button')) || (cont && cont.closest('[data-rmm-repeat]') && cont.closest('[data-rmm-repeat]').querySelector('[data-rmm-add],[data-rmm-add-self]'));
                if (focus) { focus.focus(); }
            }
        }
    });

    // ---- scope pickers: show only the list that matches the chosen type
    function bindScopes(root) {
        (root || document).querySelectorAll('[data-rmm-scope]').forEach(function (box) {
            if (box.getAttribute('data-rmm-bound') === '1') { return; }
            box.setAttribute('data-rmm-bound', '1');
            var sel = box.querySelector('[data-rmm-scope-type]');
            var sync = function () {
                box.querySelectorAll('[data-rmm-scope-for]').forEach(function (d) {
                    var on = d.getAttribute('data-rmm-scope-for') === sel.value;
                    d.classList.toggle('d-none', !on);
                    d.querySelectorAll('select,input').forEach(function (f) { f.disabled = !on; });
                });
            };
            sel.addEventListener('change', sync);
            sync();
        });
    }
    bindScopes(document);

    // ---- conditional fields: data-rmm-when="#selectId=a,b" shows the element only for those values (and disables its fields otherwise so they are not posted)
    function bindWhen(root) {
        (root || document).querySelectorAll('[data-rmm-when]').forEach(function (el) {
            if (el.getAttribute('data-rmm-bound') === '1') { return; }
            el.setAttribute('data-rmm-bound', '1');
            var spec = el.getAttribute('data-rmm-when');
            var eq = spec.indexOf('=');
            var src = el.closest('form') ? el.closest('form').querySelector(spec.slice(0, eq)) : document.querySelector(spec.slice(0, eq));
            if (!src) { return; }
            var wanted = spec.slice(eq + 1).split(',');
            var sync = function () {
                var v = src.type === 'checkbox' ? (src.checked ? '1' : '0') : src.value;
                var on = wanted.indexOf(v) !== -1;
                el.classList.toggle('d-none', !on);
                el.querySelectorAll('input,select,textarea').forEach(function (f) { f.disabled = !on; });
            };
            src.addEventListener('change', sync);
            src.addEventListener('input', sync);
            sync();
        });
    }
    bindWhen(document);

    // ---- a submit that must not be sent twice
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM' || !f.hasAttribute('data-rmm-once')) { return; }
        var b = f.querySelector('button[type=submit]');
        if (b) { window.setTimeout(function () { b.disabled = true; }, 0); }
    });

    // ---- text copy buttons (script hash)
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rmm-copy]');
        if (!b || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(b.getAttribute('data-rmm-copy')).then(function () {
            var old = b.textContent; b.textContent = 'Copied'; window.setTimeout(function () { b.textContent = old; }, 1500);
        });
    });
})();
