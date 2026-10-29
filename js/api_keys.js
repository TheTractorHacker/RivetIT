// Administration > API Keys. Delegated handlers only (the add / edit forms are loaded into pop-ups after the page is ready).
(function () {
    'use strict';

    // ---- access presets: a preset only ticks real boxes; no wildcard is ever sent ------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ak-preset]');
        if (!btn) { return; }
        var form = btn.closest('form') || document;
        var preset = btn.getAttribute('data-ak-preset');
        form.querySelectorAll('.ak-scope-table input[data-ak-action]').forEach(function (box) {
            var action = box.getAttribute('data-ak-action');
            box.checked = preset === 'read' ? action === 'read' : (preset === 'readwrite' ? action !== 'delete' : false);
        });
    });

    // ---- department boxes are only live while "Only these departments" is chosen (the server refuses both together) --------
    document.addEventListener('change', function (e) {
        var radio = e.target.closest('input[name="dept_mode"]');
        if (!radio) { return; }
        var scope = radio.closest('.ak-dept-fieldset') || document;
        var selected = radio.value === 'selected' && radio.checked;
        scope.querySelectorAll('input[name="departments[]"]').forEach(function (b) { b.disabled = !selected; });
    });

    // ---- expandable details row ---------------------------------------------------------------------------------------------
    function toggleRow(btn, open) {
        var row = document.getElementById(btn.getAttribute('aria-controls'));
        if (!row) { return; }
        var show = typeof open === 'boolean' ? open : btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        row.hidden = !show;
        var icon = btn.querySelector('i');
        if (icon) { icon.className = show ? 'fas fa-fw fa-chevron-down' : 'fas fa-fw fa-chevron-right'; }
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ak-toggle]');
        if (btn) { toggleRow(btn); }
    });
    function openFromHash() {
        var m = /^#client-(\d+)$/.exec(location.hash);
        if (!m) { return; }
        var btn = document.querySelector('[data-ak-toggle][aria-controls="ak-details-' + m[1] + '"]');
        if (btn) { toggleRow(btn, true); btn.scrollIntoView({ block: 'center' }); }
    }

    // ---- copy: Clipboard API where it exists, else select + execCommand (plain HTTP, old browsers) -------------------------
    function fallbackCopy(text, near) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.opacity = '0';
        // inside the open modal when there is one: Bootstrap's focus trap would otherwise pull focus back and cancel the selection
        (near && near.closest('.modal') || document.body).appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, text.length);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        ta.parentNode.removeChild(ta);
        if (near && near.focus) { near.focus(); }
        return ok;
    }
    function copyText(text, near) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text, near); });
        }
        return Promise.resolve(fallbackCopy(text, near));
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ak-copy]');
        if (!btn) { return; }
        e.preventDefault();
        var src = document.getElementById(btn.getAttribute('data-ak-copy'));
        if (!src) { return; }
        var text = 'value' in src ? src.value : src.textContent.trim();
        var label = btn.getAttribute('data-ak-label') || btn.textContent.trim() || 'Copy';
        if (!btn.hasAttribute('data-ak-label')) { btn.setAttribute('data-ak-label', label); }
        copyText(text, btn).then(function (ok) {
            if (!ok && typeof src.select === 'function') { src.select(); }   // last resort: leave it selected for Ctrl+C
            var live = document.getElementById('ak-live');
            var msg = ok ? 'Copied' : 'Press Ctrl+C to copy (text selected)';
            btn.classList.toggle('btn-success', ok);
            var span = btn.querySelector('.ak-copy-text');
            if (span) { span.textContent = ok ? 'Copied' : 'Selected'; } else { btn.textContent = ok ? 'Copied' : 'Selected'; }
            if (live) { live.textContent = msg; }
            clearTimeout(btn._akT);
            btn._akT = setTimeout(function () {
                btn.classList.remove('btn-success');
                if (span) { span.textContent = label; } else { btn.textContent = label; }
            }, 2000);
        });
    });
    // click or focus in a read-only value selects all of it
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.ak-select-all');
        if (el && typeof el.select === 'function') { el.select(); }
    });

    // ---- confirm pop-up (revoke / delete / revoke a secret): no browser confirm() --------------------------------------------
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ak-confirm]');
        if (!b) { return; }
        e.preventDefault();
        var modalEl = document.getElementById('akConfirm');
        if (!modalEl) { return; }
        var kind = b.getAttribute('data-ak-confirm');
        var name = b.getAttribute('data-name') || '';
        var cfg = {
            revoke: { action: 'revoke_integration_client', title: 'Revoke API key', btn: 'Revoke key', cls: 'btn-warning',
                body: 'Revoking stops the key working now: its secrets and access tokens are rejected. The key stays in the list as Revoked and its history is kept. This cannot be undone, but you can add a new key.' },
            delete: { action: 'delete_integration_client', title: 'Delete API key', btn: 'Delete key', cls: 'btn-danger',
                body: 'Deleting removes the key for good: its secrets, tokens, department list and single sign-on settings. Anything still using it stops working. If it was converted from an old API key, that old key string stays blocked. This cannot be undone.' },
            secret: { action: 'revoke_integration_secret', title: 'Revoke secret', btn: 'Revoke secret', cls: 'btn-warning',
                body: 'The secret stops working at once. Tokens issued with it stop at their next request. The key and its other secret are not affected.' }
        }[kind];
        if (!cfg) { return; }
        modalEl.querySelector('.ak-confirm-title').textContent = cfg.title;
        modalEl.querySelector('.ak-confirm-name').textContent = name;
        modalEl.querySelector('.ak-confirm-body').textContent = cfg.body;
        modalEl.querySelector('[name="client_id"]').value = b.getAttribute('data-id') || '';
        modalEl.querySelector('[name="secret_id"]').value = b.getAttribute('data-secret') || '';
        var go = modalEl.querySelector('.ak-confirm-go');
        go.textContent = cfg.btn;
        go.className = 'btn ak-confirm-go ' + cfg.cls;
        go.name = cfg.action;
        if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modalEl).show(); }
    });
    // a secret/id field that does not apply must not be posted
    document.addEventListener('submit', function (e) {
        var f = e.target.closest ? e.target.closest('#akConfirm form') : null;
        if (!f) { return; }
        var s = f.querySelector('[name="secret_id"]');
        if (s && s.value === '') { s.disabled = true; }
    });

    // ---- one-time secret: show it the moment the page loads --------------------------------------------------------------------
    function showSecret() {
        var el = document.getElementById('akSecretModal');
        if (!el) { return; }
        window.scrollTo(0, 0);
        if (window.bootstrap && bootstrap.Modal) {
            el.addEventListener('shown.bs.modal', function () {
                var f = document.getElementById('akSecretValue');
                if (f) { f.focus(); f.select(); }
            });
            bootstrap.Modal.getOrCreateInstance(el).show();
        } else {   // Bootstrap missing: still show it, as a plain block
            el.classList.add('show');
            el.style.display = 'block';
        }
    }
    document.addEventListener('DOMContentLoaded', function () { showSecret(); openFromHash(); });
    window.addEventListener('hashchange', openFromHash);
})();
