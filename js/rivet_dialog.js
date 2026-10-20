/*
 * RivetDialog: the app's replacement for window.alert / confirm / prompt. No native browser dialogs anywhere (tests/no_native_dialogs.php enforces it).
 *
 *   RivetDialog.alert(message, {title, variant})                    -> Promise<true>
 *   RivetDialog.confirm(message, {title, okText, cancelText, variant}) -> Promise<boolean>   (Esc / backdrop / Cancel = false)
 *   RivetDialog.prompt(message, {title, defaultValue, type, placeholder, okText}) -> Promise<string|null> (Esc / Cancel = null)
 *   RivetDialog.gate(event, el, message, opts) -> true when the caller may let the event run natively now (already approved);
 *        false when it cancelled the event and opened the confirm (a yes re-runs the click / form submit once). Used by data-confirm style handlers.
 *
 * message/title/placeholder are always set with textContent / properties, never innerHTML. Dialogs queue (one at a time). The modal DOM is created
 * lazily with Bootstrap/Tabler classes, so it follows the dark theme and works without Bootstrap's JS (kiosk, login, portal, module-only pages).
 * The safe button has the initial focus (Cancel for confirm); Tab is trapped; focus returns to the opener. No inline handlers (CSP-safe).
 */
(function () {
    'use strict';
    if (window.RivetDialog) { return; }

    var queue = Promise.resolve();
    var seq = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function open(kind, message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            queue = queue.then(function () {
                return new Promise(function (done) { show(kind, message, opts, function (v) { resolve(v); done(); }); });
            });
        });
    }

    function show(kind, message, opts, finish) {
        var id = 'rivet-dialog-' + (++seq);
        var opener = document.activeElement;
        var isPrompt = kind === 'prompt';
        var danger = opts.variant === 'danger';
        var okCls = 'btn ' + (danger ? 'btn-danger' : opts.variant === 'warning' ? 'btn-warning' : 'btn-primary');

        var backdrop = el('div', 'modal-backdrop fade show');
        backdrop.style.zIndex = '2000';
        var modal = el('div', 'modal fade show');
        modal.style.display = 'block';
        modal.style.zIndex = '2001';
        modal.tabIndex = -1;
        modal.setAttribute('role', kind === 'confirm' || kind === 'alert' ? 'alertdialog' : 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', id + '-title');
        modal.setAttribute('aria-describedby', id + '-msg');
        modal.setAttribute('data-rivet-dialog', kind);

        var dlg = el('div', 'modal-dialog modal-dialog-centered');
        var content = el('div', 'modal-content');
        var head = el('div', 'modal-header');
        var title = el('h5', 'modal-title', opts.title || (kind === 'alert' ? 'Notice' : kind === 'prompt' ? 'Input needed' : 'Please confirm'));
        title.id = id + '-title';
        head.appendChild(title);
        var body = el('div', 'modal-body');
        var msg = el('div', '', message === undefined || message === null ? '' : message);
        msg.id = id + '-msg';
        msg.style.whiteSpace = 'pre-wrap';
        msg.style.overflowWrap = 'anywhere';
        body.appendChild(msg);
        var input = null;
        if (isPrompt) {
            input = el('input', 'form-control mt-3');
            input.type = opts.type === 'password' ? 'password' : 'text';
            input.value = opts.defaultValue === undefined || opts.defaultValue === null ? '' : String(opts.defaultValue);
            if (opts.placeholder) { input.placeholder = String(opts.placeholder); }
            input.setAttribute('aria-labelledby', id + '-msg');
            input.autocomplete = 'off';
            body.appendChild(input);
        }
        var foot = el('div', 'modal-footer');
        var cancelBtn = null;
        if (kind !== 'alert') {
            cancelBtn = el('button', 'btn btn-secondary', opts.cancelText || 'Cancel');
            cancelBtn.type = 'button';
            foot.appendChild(cancelBtn);
        }
        var okBtn = el('button', okCls, opts.okText || (kind === 'confirm' ? 'Yes' : 'OK'));
        okBtn.type = 'button';
        foot.appendChild(okBtn);
        content.appendChild(head); content.appendChild(body); content.appendChild(foot);
        dlg.appendChild(content); modal.appendChild(dlg);

        var closed = false;
        function close(value) {
            if (closed) { return; }
            closed = true;
            document.removeEventListener('focusin', trap, true);
            document.removeEventListener('keydown', onKey, true);
            modal.remove(); backdrop.remove();
            try { if (opener && opener.focus && document.contains(opener)) { opener.focus(); } } catch (e) { /* opener gone */ }
            finish(value);
        }
        function cancelValue() { return kind === 'alert' ? true : isPrompt ? null : false; }
        function accept() { close(kind === 'alert' ? true : isPrompt ? input.value : true); }

        // Keep Bootstrap's own focus trap (on an underlying modal) from stealing focus back from this dialog.
        function trap(e) {
            if (modal.contains(e.target)) { e.stopPropagation(); return; }
            e.stopPropagation();
            (input || cancelBtn || okBtn).focus();
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(cancelValue()); return; }
            if (e.key === 'Enter' && input && e.target === input) { e.preventDefault(); accept(); return; }
            if (e.key === 'Tab') {
                var f = Array.prototype.slice.call(modal.querySelectorAll('input,button')).filter(function (x) { return !x.disabled; });
                if (!f.length) { return; }
                var first = f[0], last = f[f.length - 1];
                if (!modal.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
                else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        okBtn.addEventListener('click', accept);
        if (cancelBtn) { cancelBtn.addEventListener('click', function () { close(cancelValue()); }); }
        modal.addEventListener('mousedown', function (e) { if (e.target === modal && kind !== 'alert') { close(cancelValue()); } });
        document.addEventListener('focusin', trap, true);
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        // Safe default: Cancel for a confirm, the field for a prompt, OK for an alert.
        (input || cancelBtn || okBtn).focus();
        if (input) { input.select(); }
    }

    var approved = typeof WeakSet === 'function' ? new WeakSet() : null;

    window.RivetDialog = {
        alert: function (message, opts) { return open('alert', message, opts); },
        confirm: function (message, opts) { return open('confirm', message, opts); },
        prompt: function (message, opts) { return open('prompt', message, opts); },
        gate: function (e, target, message, opts) {
            if (approved && approved.has(target)) { approved.delete(target); return true; }
            e.preventDefault();
            e.stopImmediatePropagation();
            var submitter = e.type === 'submit' ? e.submitter : null;
            open('confirm', message, opts).then(function (ok) {
                if (!ok || !approved) { return; }
                approved.add(target);
                if (e.type === 'submit') {
                    if (typeof target.requestSubmit === 'function') { target.requestSubmit(submitter || undefined); } else { target.submit(); }
                } else if (typeof target.click === 'function') {
                    target.click();
                }
            });
            return false;
        }
    };
})();
