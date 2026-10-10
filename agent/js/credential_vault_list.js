// Credential vault list: show or copy ONE username / password on demand.
// The page itself carries no secrets. Each click asks credential_reveal.php, which audits it, counts it against the
// per-user limit and may ask for the password again (step-up). Nothing is cached: a revealed value is masked again
// after a short time, and a copy goes straight to the clipboard.
(function () {
    var root = document.getElementById('credential-vault-list');
    if (!root) { return; }
    var csrf = root.getAttribute('data-csrf') || '';
    var url = root.getAttribute('data-reveal-url') || 'credential_reveal.php';
    var SHOW_MS = 20000;
    var MASK = '••••••••';

    var messages = {
        rate_limited: 'You have revealed or copied too many credentials in a short time. This was recorded and the administrators were told. Try again in a few minutes.',
        vault_locked: 'The credential vault is locked for this session. Sign in with your password to view or copy credentials.',
        stepup_locked: 'Too many wrong passwords. Try again in a few minutes.',
        denied: 'You do not have access to this credential.',
        not_found: 'That credential no longer exists.',
        decrypt_failed: 'This credential could not be decrypted with your vault key.',
        csrf: 'Your page is out of date. Reload it and try again.'
    };

    function send(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; },
                function () { return { status: r.status, body: { ok: false, error: 'bad_response' } }; });
        }).catch(function () { return { status: 0, body: { ok: false, error: 'network' } }; });
    }

    // Ask for the value; handles the password step-up by showing the modal and retrying once.
    function fetchValue(btn, mode) {
        var params = {
            credential_id: btn.getAttribute('data-credential-id'),
            field: btn.getAttribute('data-field'),
            mode: mode,
            csrf_token: csrf
        };
        return attempt(params, false);
    }

    function attempt(params, wrongPassword) {
        return send(params).then(function (res) {
            if (res.body && res.body.ok) { return res.body.value; }
            if (res.body && res.body.stepup) {
                return askPassword(wrongPassword).then(function (pw) {
                    if (pw === null) { return null; }
                    var again = Object.assign({}, params, { stepup_password: pw });
                    return attempt(again, true);
                });
            }
            var key = res.body && res.body.error;
            window.alert(messages[key] || 'This credential could not be shown right now.');
            return null;
        });
    }

    var modalEl = document.getElementById('credStepUpModal');
    var formEl = document.getElementById('credStepUpForm');
    var pwEl = document.getElementById('credStepUpPassword');
    var errEl = document.getElementById('credStepUpError');
    var resolveModal = null;

    function askPassword(wrong) {
        return new Promise(function (resolve) {
            if (!modalEl || !window.bootstrap) { resolve(window.prompt('Enter your password to continue') ); return; }
            resolveModal = resolve;
            errEl.hidden = !wrong;
            pwEl.value = '';
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            setTimeout(function () { pwEl.focus(); }, 200);
        });
    }
    if (formEl) {
        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            var pw = pwEl.value;
            var done = resolveModal; resolveModal = null;
            pwEl.value = '';
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            if (done) { done(pw); }
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (resolveModal) { var done = resolveModal; resolveModal = null; done(null); }
        });
    }

    function cellOf(btn) { return btn.closest('.cred-cell'); }

    function mask(cell) {
        var v = cell.querySelector('.js-cred-value');
        if (!v) { return; }
        v.textContent = MASK;
        v.setAttribute('data-masked', '1');
        v.classList.add('cred-mask');
        var eye = cell.querySelector('.js-cred-reveal i');
        if (eye) { eye.className = 'far fa-eye'; }
        if (cell._credTimer) { clearTimeout(cell._credTimer); cell._credTimer = null; }
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
            document.body.removeChild(ta);
        });
    }

    document.addEventListener('click', function (e) {
        var rev = e.target.closest('.js-cred-reveal');
        var cpy = e.target.closest('.js-cred-copy');
        if (!rev && !cpy) { return; }
        e.preventDefault();

        if (rev) {
            var cell = cellOf(rev);
            var v = cell.querySelector('.js-cred-value');
            if (v.getAttribute('data-masked') !== '1') { mask(cell); return; }
            rev.disabled = true;
            fetchValue(rev, 'reveal').then(function (value) {
                rev.disabled = false;
                if (value === null) { return; }
                v.textContent = value === '' ? '(empty)' : value;
                v.setAttribute('data-masked', '0');
                v.classList.remove('cred-mask');
                var eye = rev.querySelector('i');
                if (eye) { eye.className = 'far fa-eye-slash'; }
                cell._credTimer = setTimeout(function () { mask(cell); }, SHOW_MS);
            });
            return;
        }

        cpy.disabled = true;
        fetchValue(cpy, 'copy').then(function (value) {
            cpy.disabled = false;
            if (value === null) { return; }
            copyText(value).then(function () {
                var icon = cpy.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-check text-success';
                    setTimeout(function () { icon.className = 'far fa-copy'; }, 1500);
                }
            }, function () { window.alert('Could not copy to the clipboard in this browser.'); });
        });
    });
})();
