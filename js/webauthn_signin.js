/* Passkey sign-in — discoverable flow */
(function () {
    'use strict';

    var btn = document.getElementById('passkeySignInBtn');
    if (!btn) return;
    btn.addEventListener('click', passkeySignIn);

    // ── helpers ───────────────────────────────────────────────────────────────
    function b64uToBuf(str) {
        var b64 = str.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        return Uint8Array.from(atob(b64), function (c) { return c.charCodeAt(0); }).buffer;
    }
    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), s = '';
        bytes.forEach(function (b) { s += String.fromCharCode(b); });
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }
    function showError(msg) {
        var el = document.getElementById('passkey-error');
        if (!el) {
            el = document.createElement('p');
            el.id        = 'passkey-error';
            el.className = 'text-danger small text-center mt-2 mb-0';
            btn.parentNode.insertBefore(el, btn.nextSibling);
        }
        el.textContent = msg;
    }
    function clearError() {
        var el = document.getElementById('passkey-error');
        if (el) el.textContent = '';
    }

    // ── sign-in ───────────────────────────────────────────────────────────────
    async function passkeySignIn() {
        clearError();
        btn.disabled    = true;
        btn.innerHTML   = '<i class="fas fa-spinner fa-spin mr-2"></i>Waiting…';

        try {
            // 1. Get challenge — discoverable (no email, no allowCredentials)
            var beginResp = await fetch('passkey_auth_begin.php', { method: 'POST' });
            var options   = await beginResp.json();
            if (options.error) { showError(options.error); return; }

            options.challenge = b64uToBuf(options.challenge);
            // empty allowCredentials → browser shows all passkeys for this site

            // 2. OS passkey picker
            var credential;
            try {
                credential = await navigator.credentials.get({ publicKey: options });
            } catch (pickErr) {
                if (pickErr.name === 'NotAllowedError') {
                    showError('No passkey was chosen or found on this device. Try again, or sign in with your email and password below.');
                } else if (pickErr.name !== 'AbortError') {
                    showError('Passkey error: ' + pickErr.message);
                }
                return;
            }

            // 3. Send assertion
            var payload = {
                id:   credential.id,
                type: credential.type,
                response: {
                    clientDataJSON:    bufToB64u(credential.response.clientDataJSON),
                    authenticatorData: bufToB64u(credential.response.authenticatorData),
                    signature:         bufToB64u(credential.response.signature),
                    userHandle:        credential.response.userHandle
                                           ? bufToB64u(credential.response.userHandle)
                                           : null,
                },
            };

            var completeResp = await fetch('passkey_auth_complete.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            var result = await completeResp.json();

            if (result.ok) {
                window.location.href = result.redirect || '/agent/dashboard.php';
            } else {
                showError(result.error || 'Sign-in failed. Try your password instead.');
            }
        } catch (err) {
            showError('Unexpected error: ' + err.message);
        } finally {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-fingerprint mr-2"></i>Sign in with a Passkey';
        }
    }
})();
