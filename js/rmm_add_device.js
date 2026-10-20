/*
 * Add device / Build installer (markup: includes/rmm_add_device_ui.php, endpoint: agent/post/rmm_installer.php, the same one the Add device dialog uses).
 *
 * Every decision is the server's (who may, which department, the token, the audit entry, the refusals); this script chooses what to ask for, posts it with the CSRF token and shows the
 * answer. Server text is written with textContent / .value only. Without JavaScript nothing on this page works except the platform choice: the buttons need it.
 */
(function () {
    'use strict';
    var root = document.getElementById('ad-root');
    var form = document.getElementById('ad-form');
    if (!root || !form) { return; }
    var postUrl = root.getAttribute('data-post-url');
    var csrf = root.getAttribute('data-csrf');
    var fixed = root.getAttribute('data-fixed') === '1';
    var $ = function (id) { return document.getElementById(id); };
    var busy = false;
    var client = $('ad-client'), location_ = $('ad-location'), err = $('ad-error'), done = $('ad-done');
    var MULTI_USES = 25;

    function platform() { var c = form.querySelector('input[name="platform"]:checked'); return c ? c.value : 'windows-amd64'; }
    function os() { return platform().split('-')[0]; }
    function arch() { return platform().split('-')[1] === 'arm64' ? 'arm64' : 'amd64'; }
    function have(kind) { return root.getAttribute('data-have-' + kind + '-' + arch()) === '1'; }
    function serviceOk() { return root.getAttribute('data-service-ok') === '1'; }

    function showError(t) { if (!t) { err.classList.add('d-none'); err.textContent = ''; return; } err.textContent = t; err.classList.remove('d-none'); }
    function say(t) { if (!t) { done.classList.add('d-none'); done.textContent = ''; return; } done.textContent = t; done.classList.remove('d-none'); }
    function setBusy(on) { busy = on; ['ad-dl-exe', 'ad-dl-msi', 'ad-w-cmds', 'ad-l-go'].forEach(function (id) { var b = $(id); if (b) { b.setAttribute('aria-busy', on ? 'true' : 'false'); b.classList.toggle('disabled', on); } }); }

    function refresh() {
        var w = os() === 'windows';
        $('ad-windows').classList.toggle('d-none', !w);
        $('ad-linux').classList.toggle('d-none', w);
        var a = arch();
        if (w) {
            var exeOk = have('exe'), msiOk = have('msi');
            $('ad-w-missing').classList.toggle('d-none', exeOk);
            $('ad-w-missing-text').textContent = 'No ' + (a === 'arm64' ? 'ARM64' : 'x64') + ' agent program is published yet, so the .exe installer cannot be built.' + (msiOk ? ' The MSI does not need it.' : '');
            $('ad-dl-exe').disabled = !exeOk || !serviceOk();
            $('ad-dl-msi').disabled = !msiOk || !serviceOk();
            $('ad-msi-missing').classList.toggle('d-none', msiOk);
            $('ad-w-cmds').disabled = !(exeOk || msiOk) || !serviceOk();
        } else {
            var lin = root.getAttribute('data-have-linux-' + a) === '1';
            $('ad-l-missing').classList.toggle('d-none', lin);
            $('ad-l-go').disabled = !serviceOk() || !lin;
            document.querySelectorAll('[data-ad-pkgs]').forEach(function (n) { n.classList.toggle('d-none', n.getAttribute('data-ad-pkgs') !== a); });
        }
        say(''); showError('');
    }

    function fields(extra) {
        var fd = new URLSearchParams(new FormData(form));
        fd.delete('platform');
        fd.set('csrf_token', csrf);
        fd.set('arch', arch());
        Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); });
        return fd.toString();
    }
    function send(extra, accept) {
        return fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': accept || 'application/json' }, body: fields(extra) });
    }
    function problem(res) { return res.json().then(function (d) { return (d && d.error) || 'The request was refused.'; }, function () { return 'The request failed (HTTP ' + res.status + ').'; }); }
    function needClient() { if (client && !client.value) { showError('Choose the department the machine belongs to.'); client.focus(); return false; } return true; }
    function guard(fn) {
        return function () {
            if (busy || !needClient()) { return; }
            showError(''); say(''); setBusy(true);
            fn().catch(function (e) { showError(e && e.message ? e.message : 'Something went wrong.'); }).then(function () { setBusy(false); });
        };
    }
    function human(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

    function download(format) {
        return send({ action: 'download', format: format }, 'application/octet-stream, application/json').then(function (res) {
            if (!res.ok) { return problem(res).then(function (m) { throw new Error(m); }); }
            var cd = res.headers.get('Content-Disposition') || '';
            var m = /filename="([A-Za-z0-9._-]+)"/.exec(cd);
            var name = m ? m[1] : (format === 'msi' ? 'RivetIT-Agent.msi' : 'RivetIT-Agent-Setup.exe');
            return res.blob().then(function (blob) {
                var a = document.createElement('a'), url = URL.createObjectURL(blob);
                a.href = url; a.download = name; a.rel = 'noopener'; a.style.display = 'none';
                document.body.appendChild(a); a.click();
                window.setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
                var n = parseInt($('ad-uses').value, 10) || 1;
                say('Downloaded ' + name + ' (' + human(blob.size) + '). It works for ' + n + (n === 1 ? ' machine' : ' machines') + '. ' + (format === 'msi'
                    ? 'Install it with msiexec /i "' + name + '" /qn /norestart, or double-click it. It is unsigned and not yet verified on Windows: try it on one PC first.'
                    : 'Copy it to the PC, double-click it and accept the administrator prompt.'));
            });
        });
    }

    function windowsCommands() {
        var format = have('msi') ? 'msi' : 'exe';
        return send({ action: 'windows_commands', format: format }).then(function (res) {
            return res.json().then(function (d) {
                if (!res.ok || !d || !d.success) { throw new Error((d && d.error) || 'The commands could not be created.'); }
                $('ad-c-ps').value = d.powershell;
                var msi = d.msi;
                $('ad-w-msi-out').classList.toggle('d-none', !msi);
                if (msi) { $('ad-c-msiexec').value = msi.msiexec; $('ad-c-msips').value = msi.powershell; $('ad-c-props').value = msi.properties; $('ad-c-uninst').value = msi.uninstall; }
                $('ad-w-meta').textContent = 'For ' + d.department + '. The token works for ' + d.max_uses + (d.max_uses === 1 ? ' machine' : ' machines') + ' until ' + (d.expires_at_text || d.expires_at) + ' and is shown only once: do not paste it into tickets or chat.';
                $('ad-w-out').classList.remove('d-none');
            });
        });
    }

    function linuxCommand() {
        return send({ action: 'linux' }).then(function (res) {
            return res.json().then(function (d) {
                if (!res.ok || !d || !d.success) { throw new Error((d && d.error) || 'The command could not be created.'); }
                $('ad-l-cmd').value = d.command;
                $('ad-l-meta').textContent = 'For ' + d.department + '. Works for ' + d.max_uses + (d.max_uses === 1 ? ' machine' : ' machines') + ' until ' + (d.expires_at_text || d.expires_at) + '. The token is shown only once: do not paste it into tickets or chat.'
                    + ' Downloads agent ' + d.agent_version + (d.source === 'github' ? ' from GitHub' : ' from this server') + ' and checks it against the SHA-256 printed in the command before installing.';
                $('ad-l-ver').textContent = d.agent_version ? '(' + d.agent_version + ')' : '';
                $('ad-l-out').classList.remove('d-none');
            });
        });
    }

    function loadLocations() {
        if (!client || !location_ || fixed) { return; }
        var id = client.value;
        while (location_.options.length > 1) { location_.remove(1); }
        location_.value = '0';
        $('ad-location-col').classList.add('d-none');
        if (!id) { return; }
        fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: new URLSearchParams({ action: 'locations', client_id: id, csrf_token: csrf }).toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success || client.value !== id) { return; }
                d.locations.forEach(function (l) { var o = document.createElement('option'); o.value = String(l.id); o.textContent = l.name; location_.appendChild(o); });
                $('ad-location-col').classList.toggle('d-none', d.locations.length === 0);
            }).catch(function () { /* optional */ });
    }

    // copy buttons (delegated: the text blocks and the package rows)
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }
    root.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-ad-copy],[data-ad-copy-value]');
        if (!b) { return; }
        var src = b.getAttribute('data-ad-copy');
        var text = src ? ($(src) ? $(src).value : '') : b.getAttribute('data-ad-copy-value');
        var label = b.querySelector('span');
        var okf = function () { if (label) { var old = label.textContent; label.textContent = 'Copied'; window.setTimeout(function () { label.textContent = old === 'Copied' ? 'Copy' : old; }, 2000); } };
        if (!text) { return; }
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(okf, function () { if (fallbackCopy(text)) { okf(); } }); }
        else if (fallbackCopy(text)) { okf(); }
    });

    form.addEventListener('submit', function (e) { e.preventDefault(); });
    form.querySelectorAll('input[name="platform"]').forEach(function (r) { r.addEventListener('change', refresh); });
    if (client) { client.addEventListener('change', function () { loadLocations(); refresh(); }); }
    var mesh = $('ad-mesh');
    if (mesh) { mesh.addEventListener('change', function () { $('ad-l-out').classList.add('d-none'); $('ad-w-out').classList.add('d-none'); $('ad-l-cmd').value = ''; }); }
    $('ad-uses').addEventListener('input', function () { /* the number is the single source of truth */ });
    $('ad-dl-exe').addEventListener('click', guard(function () { return download('exe'); }));
    $('ad-dl-msi').addEventListener('click', guard(function () { return download('msi'); }));
    $('ad-w-cmds').addEventListener('click', guard(windowsCommands));
    $('ad-l-go').addEventListener('click', guard(linuxCommand));
    if (client) { loadLocations(); }
    refresh();
})();
