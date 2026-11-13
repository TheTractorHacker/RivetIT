/*
 * One-time codes (TOTP) of credentials: click "Show code" and the code appears with a countdown ring that runs to the next change, a copy button and a hide button.
 * The server (agent/ajax.php?get_totp_token_via_id&detail=1) answers the code AND how many seconds it still lives, so the ring is right whatever the browser clock says;
 * when it runs out the next code is fetched by itself. A code left on screen hides after two minutes, and when the tab is hidden for half a minute.
 * Fetching a code is audited by the server exactly as before (the "View TOTP" log entry); nothing is cached in the page after it is hidden.
 *
 * Markup (any page): <span class="otp-reveal-trigger" data-credential-id="ID"><span id="otp_ID">...placeholder...</span></span>
 * Public: showOTPViaCredentialID(id, target) shows the widget in `target` (default #otp_ID).
 */
(function () {
    'use strict';
    // Modals load this file again every time they open: bind the listeners once.
    if (window.__rivetTotpBound) { return; }
    window.__rivetTotpBound = true;
    var AUTO_HIDE_MS = 120000;
    var TAB_HIDDEN_MS = 30000;
    var widgets = [];          // live widgets
    var ticker = null;
    var hiddenSince = 0;

    function groupCode(code) {
        return code.length === 6 ? code.slice(0, 3) + ' ' + code.slice(3) : code;
    }

    function el(tag, cls, attrs) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
        return n;
    }

    function placeholder(target, id) {
        target.textContent = '';
        var b = el('button', 'btn btn-sm btn-outline-secondary totp-show', { type: 'button', 'data-totp-show': id, title: 'Show the one-time code' });
        var i = el('i', 'fas fa-shield-alt me-1', { 'aria-hidden': 'true' });
        b.appendChild(i);
        b.appendChild(document.createTextNode('Show code'));
        target.appendChild(b);
    }

    function copyText(text, done) {
        function fallback() {
            var t = el('textarea');
            t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); } catch (e) { /* nothing more to try */ }
            document.body.removeChild(t); done();
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else { fallback(); }
    }

    function build(w) {
        var root = el('span', 'totp totp--ok', { role: 'group', 'aria-label': 'One-time code' });
        w.codeEl = el('span', 'totp-code', { 'aria-live': 'off' });
        var ringWrap = el('span', 'totp-ring', { 'aria-hidden': 'true' });
        ringWrap.innerHTML = '<svg viewBox="0 0 36 36" width="26" height="26"><circle class="totp-ring-bg" cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.2"/>'
            + '<circle class="totp-ring-fg" cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.2" pathLength="100" stroke-dasharray="100" stroke-dashoffset="0" transform="rotate(-90 18 18)"/></svg>';
        w.secEl = el('span', 'totp-sec');
        ringWrap.appendChild(w.secEl);
        w.ringFg = ringWrap.querySelector('.totp-ring-fg');
        var copy = el('button', 'btn btn-sm cred-btn totp-copy', { type: 'button', title: 'Copy the code', 'aria-label': 'Copy the one-time code' });
        copy.innerHTML = '<i class="far fa-copy" aria-hidden="true"></i>';
        copy.addEventListener('click', function () {
            if (!w.code) { return; }
            copyText(w.code, function () {
                copy.innerHTML = '<i class="fas fa-check text-success" aria-hidden="true"></i>';
                copy.title = 'Copied';
                setTimeout(function () { copy.innerHTML = '<i class="far fa-copy" aria-hidden="true"></i>'; copy.title = 'Copy the code'; }, 1400);
            });
        });
        var hide = el('button', 'btn btn-sm cred-btn totp-hide', { type: 'button', title: 'Hide the code', 'aria-label': 'Hide the one-time code' });
        hide.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        hide.addEventListener('click', function () { close(w); });
        root.appendChild(w.codeEl); root.appendChild(ringWrap); root.appendChild(copy); root.appendChild(hide);
        w.root = root;
        return root;
    }

    function close(w) {
        var i = widgets.indexOf(w);
        if (i >= 0) { widgets.splice(i, 1); }
        if (w.target && w.target.isConnected) { placeholder(w.target, w.id); }
        w.code = '';
    }

    function fetchCode(w) {
        if (w.loading) { return; }
        w.loading = true;
        fetch('/agent/ajax.php?get_totp_token_via_id=true&detail=1&credential_id=' + encodeURIComponent(w.id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (d) {
                w.loading = false;
                if (!d || typeof d.code !== 'string') { throw new Error('bad answer'); }
                w.code = d.code;
                w.period = d.period || 30;
                w.expiresAt = performance.now() + Math.max(0, d.remaining) * 1000;
                w.codeEl.textContent = groupCode(d.code);
                w.root.classList.remove('totp--error');
                paint(w);
            })
            .catch(function () {
                w.loading = false;
                w.code = '';
                w.codeEl.textContent = 'Unavailable';
                w.root.classList.add('totp--error');
                w.expiresAt = performance.now() + 5000;   // try again in a few seconds
            });
    }

    function paint(w) {
        var left = Math.max(0, (w.expiresAt - performance.now()) / 1000);
        var frac = Math.min(1, left / (w.period || 30));
        w.ringFg.setAttribute('stroke-dashoffset', String(100 - frac * 100));
        w.secEl.textContent = String(Math.ceil(left));
        w.root.classList.toggle('totp--ok', left > 10);
        w.root.classList.toggle('totp--warn', left <= 10 && left > 5);
        w.root.classList.toggle('totp--low', left <= 5);
        if (left <= 0 && !w.loading) { fetchCode(w); }
    }

    function tick() {
        var now = performance.now();
        for (var i = widgets.length - 1; i >= 0; i--) {
            var w = widgets[i];
            if (!w.target || !w.target.isConnected) { widgets.splice(i, 1); continue; }
            if (now - w.shownAt > AUTO_HIDE_MS) { close(w); continue; }
            paint(w);
        }
        if (!widgets.length && ticker) { clearInterval(ticker); ticker = null; }
    }

    window.showOTPViaCredentialID = function (credentialId, target) {
        target = target || document.getElementById('otp_' + credentialId);
        if (!target) { return; }
        for (var i = 0; i < widgets.length; i++) { if (widgets[i].target === target) { return; } }   // already showing here
        var w = { id: String(credentialId), target: target, shownAt: performance.now(), expiresAt: 0, period: 30, code: '', loading: false };
        target.textContent = '';
        target.appendChild(build(w));
        w.codeEl.textContent = '••• •••';
        widgets.push(w);
        fetchCode(w);
        if (!ticker) { ticker = setInterval(tick, 250); }
    };

    // Click (or Enter/Space on the placeholder) shows the code. It no longer appears just because the pointer passed over the cell.
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-totp-show], .otp-reveal-trigger') : null;
        if (!b || (e.target.closest && e.target.closest('.totp'))) { return; }
        var trigger = b.closest('.otp-reveal-trigger') || b;
        var id = trigger.getAttribute('data-credential-id') || b.getAttribute('data-totp-show');
        if (!id) { return; }
        e.preventDefault();
        window.showOTPViaCredentialID(id, trigger.querySelector('[id^="otp_"]') || document.getElementById('otp_' + id));
    });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-totp-show]')) { e.target.click(); e.preventDefault(); }
    });
    // A code does not stay open in a tab nobody is looking at.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { hiddenSince = performance.now(); return; }
        if (hiddenSince && performance.now() - hiddenSince > TAB_HIDDEN_MS) { widgets.slice().forEach(close); }
        hiddenSince = 0;
    });
})();
