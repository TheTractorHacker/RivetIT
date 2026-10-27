// Administration > Update (admin/update.php): feedback around the existing update links. Presentation only: the links keep their
// own href (post.php?update / ?update_db, with the CSRF token) and the shared confirmation modal (js/confirm_modal.js) still asks first.
//
//  1. While the server works: the moment an update starts (straight away for Update Database; after "Yes" in the confirmation modal for
//     Update App / FORCE Update App) the progress panel shows what the server is doing and for how long, and the update links are disabled
//     so a second click cannot queue a second run from this page.
//  2. Afterwards: the server answers with this page again and a short toast. The toast's text is kept on the page as a result banner
//     (success or failure) so it does not vanish after a few seconds. Only for an update started from this browser tab.
(function () {
    'use strict';

    var KEY = 'rivetit.update.started';
    var pending = null;   // an update link waiting for the confirmation modal's "Yes"
    var timer = null;

    function store(value) {
        try {
            if (value === null) { window.sessionStorage.removeItem(KEY); } else { window.sessionStorage.setItem(KEY, JSON.stringify(value)); }
        } catch (e) { /* storage blocked: the progress panel still works, only the result banner is skipped */ }
    }
    function load() {
        try { return JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
    }

    function updateLinks() { return document.querySelectorAll('a[data-upd-action]'); }

    function start(link) {
        var panel = document.getElementById('upd-progress');
        if (!panel) { return; }
        var kind = link.getAttribute('data-upd-action') === 'database' ? 'database' : 'app';
        var page = document.querySelector('.upd-page');
        store({ kind: kind, at: Date.now(), lastId: page ? parseInt(page.getAttribute('data-upd-last-' + kind) || '0', 10) : 0 });

        panel.querySelector('[data-upd-progress-title]').textContent = kind === 'database' ? 'Updating the database…' : 'Updating the app…';
        panel.querySelectorAll('[data-upd-steps]').forEach(function (list) { list.hidden = list.getAttribute('data-upd-steps') !== kind; });
        panel.hidden = false;

        updateLinks().forEach(function (a) {
            a.classList.add('disabled');
            a.setAttribute('aria-disabled', 'true');
            a.setAttribute('tabindex', '-1');
        });

        var started = Date.now();
        var elapsed = panel.querySelector('[data-upd-elapsed]');
        var tick = function () {
            var s = Math.floor((Date.now() - started) / 1000);
            elapsed.textContent = 'running for ' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        };
        tick();
        timer = window.setInterval(tick, 1000);

        var modalEl = document.getElementById('confirmationModal');
        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            var modal = window.bootstrap.Modal.getInstance(modalEl);
            if (modal) { modal.hide(); }
        }
        panel.scrollIntoView({ block: 'center', behavior: 'auto' });
        panel.focus({ preventScroll: true });
    }

    function reset() {
        if (timer) { window.clearInterval(timer); timer = null; }
        var panel = document.getElementById('upd-progress');
        if (panel) { panel.hidden = true; }
        updateLinks().forEach(function (a) {
            a.classList.remove('disabled');
            a.removeAttribute('aria-disabled');
            a.removeAttribute('tabindex');
        });
    }

    // Capture phase: runs before js/confirm_modal.js (bubble phase) and before the modal's own "Yes" handler, never stops either.
    document.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) { return; }
        var link = e.target.closest('a[data-upd-action]');
        if (link) {
            if (link.classList.contains('disabled') || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            if (link.classList.contains('confirm-link')) { pending = link; return; }
            pending = null;
            start(link);
            return;
        }
        if (e.target.closest('a.confirm-link, button.confirm-link')) { pending = null; return; }   // some other confirmation
        if (pending && e.target.closest('#confirmSubmitBtn')) {
            var go = pending;
            pending = null;
            start(go);
        }
    }, true);

    // Back/forward cache: a page restored after navigating away must not still look busy.
    window.addEventListener('pageshow', function (e) { if (e.persisted) { reset(); } });

    function showResult() {
        var started = load();
        if (!started) { return; }
        store(null);
        if (!started.at || Date.now() - started.at > 30 * 60 * 1000) { return; }   // stale marker from a long-gone attempt

        var box = document.getElementById('upd-result');
        if (!box) { return; }
        var toast = document.querySelector('#toast-container .toast');
        var text = toast ? ((toast.querySelector('.toast-message') || toast).textContent || '').trim() : '';
        var ok;
        if (toast) {
            ok = toast.classList.contains('toast-success');
        } else {
            // No message (already dismissed, or toasts unavailable): a new entry in Update history means it finished.
            var page = document.querySelector('.upd-page');
            var now = page ? parseInt(page.getAttribute('data-upd-last-' + started.kind) || '0', 10) : 0;
            ok = now > (started.lastId || 0);
        }
        var what = started.kind === 'database' ? 'database update' : 'app update';
        box.classList.add(ok ? 'alert-success' : 'alert-danger');
        box.querySelector('[data-upd-result-icon]').classList.add(ok ? 'fa-check-circle' : 'fa-times-circle');
        box.querySelector('[data-upd-result-title]').textContent = ok ? 'The ' + what + ' finished.' : 'The ' + what + ' did not finish.';
        box.querySelector('[data-upd-result-text]').textContent = text !== '' ? text
            : (ok ? 'It is listed under Update history below.' : 'Nothing new was recorded. Look at the Checks below, then try again.');
        box.hidden = false;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showResult);
    } else {
        showResult();
    }
}());
