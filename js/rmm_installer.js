/*
 * "Add device" / "Download installer" modal (markup: includes/rmm_installer.php, endpoint: agent/post/rmm_installer.php).
 *
 * Linked by includes/footer.php only on a page that rendered the modal, so with the RMM module off (or for a user who may not issue
 * installers) this file is never requested. Every decision is the server's; this script only fills the form in, posts it with the CSRF token,
 * and shows the answer. Server text is written with textContent. Without JavaScript the form is a plain POST that downloads the file.
 *
 * Windows: Format .exe (default) or .msi (the same endpoint with format=msi; offered only when a template is published for the architecture). The file is fetched (so a refusal is shown inside the dialog) and saved through a temporary link; nothing is kept in the page.
 * Linux: the endpoint returns the install snippet with the freshly created token (shown once); there is no binary to download.
 */
(function () {
    'use strict';
    var modal = document.getElementById('rmmInstallerModal');
    var form = document.getElementById('rmm-inst-form');
    if (!modal || !form) { return; }

    var postUrl = modal.getAttribute('data-post-url');
    var csrf = modal.getAttribute('data-csrf');
    var fixed = modal.getAttribute('data-fixed') === '1';
    var $ = function (id) { return document.getElementById(id); };
    var os = 'windows';
    var busy = false;

    var client = $('rmm-inst-client'), location_ = $('rmm-inst-location'), multi = $('rmm-inst-multi'), uses = $('rmm-inst-uses'), ttl = $('rmm-inst-ttl');
    var err = $('rmm-inst-error'), done = $('rmm-inst-done'), go = $('rmm-inst-go'), goLabel = $('rmm-inst-go-label');
    var meshBox = $('rmm-inst-mesh');   // the optional "Also install the MeshCentral agent" tick-box (absent unless MeshCentral is on and has an address)
    var appsList = $('rmm-inst-apps-list'), appsCol = $('rmm-inst-apps-col');   // RivetCore 1.0.0-rc.26: the "install with the agent" tick-boxes
    var winNext = $('rmm-inst-windows-next'), linOut = $('rmm-inst-linux-out'), cmdBox = $('rmm-inst-linux-cmd');
    var MULTI_USES = 25;
    form.noValidate = true;   // the script reports a missing department inside the dialog; without script the browser's own check applies (the attribute is not in the markup)

    function archValue() { var c = form.querySelector('input[name="arch"]:checked'); return c ? c.value : 'amd64'; }
    function have(arch) { return modal.getAttribute('data-have-' + arch) === '1'; }
    function haveMsi(arch) { return modal.getAttribute('data-have-msi-' + arch) === '1'; }   // an MSI template is published for this architecture (RivetCore 1.0.0-rc.16)
    function formatValue() {   // 'msi' only on the Windows tab, with a template for the chosen architecture, and when the person picked it; everything else is the .exe
        var m = $('rmm-inst-format-msi');
        return os === 'windows' && m && m.checked && !m.disabled ? 'msi' : 'exe';
    }

    function showError(text) {
        if (!text) { err.classList.add('d-none'); err.textContent = ''; return; }
        err.textContent = text;
        err.classList.remove('d-none');
    }

    // The first CPU architecture hint: an ARM Windows/Linux browser defaults the choice to ARM64, everything else to x64.
    function guessArch() {
        var s = ((navigator.userAgentData && navigator.userAgentData.platform) || '') + ' ' + (navigator.userAgent || '');
        return /arm64|aarch64/i.test(s) && !/x86|x64|Win64; x64/i.test(s) ? 'arm64' : 'amd64';
    }

    function refresh() {
        var arch = archValue();
        // rc.16: the Format selector (Windows only). MSI is offered only for an architecture that has a published template; ARM64 normally has none (the build makes x64 only).
        var msiRadio = $('rmm-inst-format-msi'), exeRadio = $('rmm-inst-format-exe'), fmtHelp = $('rmm-inst-format-help'), fmtLink = $('rmm-inst-format-link');
        var msiOk = haveMsi(arch);
        msiRadio.disabled = !msiOk;
        if (!msiOk && msiRadio.checked) { exeRadio.checked = true; }
        $('rmm-inst-format-col').classList.toggle('d-none', os !== 'windows');
        fmtHelp.textContent = msiOk ? 'The .exe is the default. Use the MSI for Intune, Group Policy or any tool that installs .msi packages.'
            : (arch === 'arm64' ? 'MSI is not available for ARM64: no ARM64 MSI template is published (the build makes x64 only). Use the .exe installer for ARM64 PCs.'
                : 'MSI is not available: no MSI template is published for x64.' + (modal.getAttribute('data-admin') === '1' ? '' : ' Ask an administrator to upload one.'));
        if (fmtLink) { fmtLink.classList.toggle('d-none', msiOk); }
        var fmt = formatValue();
        $('rmm-inst-msi-warn').classList.toggle('d-none', fmt !== 'msi');
        $('rmm-inst-silent-exe').classList.toggle('d-none', fmt === 'msi');
        $('rmm-inst-silent-msi').classList.toggle('d-none', fmt !== 'msi');
        var missing = fmt === 'msi' ? false : !have(arch);   // an MSI installer needs no agent program, only its template
        var otherArch = arch === 'amd64' ? 'arm64' : 'amd64';
        var box = $('rmm-inst-nobinary'), text = $('rmm-inst-nobinary-text');
        if (missing) {
            text.textContent = have(otherArch)
                ? 'No ' + (arch === 'amd64' ? 'x64' : 'ARM64') + ' agent program is published yet. Choose ' + (otherArch === 'amd64' ? 'x64' : 'ARM64') + ', or publish this one.'
                : 'No agent program is published yet, so an installer cannot be built.';
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
        }
        // "busy" must not set the disabled attribute: a disabled button drops keyboard focus to the page, and Esc would stop closing the dialog
        var blocked = missing || modal.getAttribute('data-service-ok') !== '1';
        go.disabled = blocked;
        go.classList.toggle('disabled', busy);
        go.setAttribute('aria-disabled', (blocked || busy) ? 'true' : 'false');
        go.setAttribute('aria-busy', busy ? 'true' : 'false');
        $('rmm-inst-linux-arch').textContent = arch;
        winNext.classList.toggle('d-none', os !== 'windows');
        if (os !== 'linux') { linOut.classList.add('d-none'); }
        goLabel.textContent = busy ? 'Working...' : (os === 'windows' ? 'Download installer' : 'Create install command');
        $('rmm-inst-action').value = os === 'windows' ? 'download' : 'linux';
        var slug = client && client.tagName === 'SELECT' && client.selectedIndex > 0 ? client.options[client.selectedIndex].text : '';
        slug = String(slug).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'department';
        $('rmm-inst-silent-name').textContent = 'RivetIT-Agent-Setup-' + slug + '-' + (arch === 'arm64' ? 'arm64' : 'x64') + '.exe';
        $('rmm-inst-silent-name-msi').textContent = 'RivetIT-Agent-' + slug + '-' + (arch === 'arm64' ? 'arm64' : 'x64') + '.msi';
    }

    function setOs(next) {
        os = next;
        ['windows', 'linux'].forEach(function (n) {
            var t = $('rmm-inst-tab-' + n), on = n === os;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        $('rmm-inst-panel').setAttribute('aria-labelledby', 'rmm-inst-tab-' + os);
        done.classList.add('d-none');
        showError('');
        loadApps();
        refresh();
    }

    function selectClient(id) {
        if (fixed || !client || !id) { return; }
        for (var i = 0; i < client.options.length; i++) {
            if (client.options[i].value === String(id)) { client.selectedIndex = i; loadLocations(); break; }
        }
    }

    function loadLocations() {
        if (!client || !location_) { return; }
        var id = client.value;
        while (location_.options.length > 1) { location_.remove(1); }
        location_.value = '0';
        $('rmm-inst-location-col').classList.add('d-none');
        if (!id) { return; }
        var body = new URLSearchParams({ action: 'locations', client_id: id, csrf_token: csrf });
        fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success || client.value !== id) { return; }
                d.locations.forEach(function (l) { var o = document.createElement('option'); o.value = String(l.id); o.textContent = l.name; location_.appendChild(o); });
                $('rmm-inst-location-col').classList.toggle('d-none', d.locations.length === 0);
            })
            .catch(function () { /* the location is optional */ })
            .then(loadApps);
    }

    // RivetCore 1.0.0-rc.26: refill the "install with the agent" tick-boxes whenever the department, location or OS changes. Built with the DOM API (not innerHTML)
    // because the catalog's app and version names are attacker-influenced-free but still untrusted text from the server; textContent is always safe either way.
    function loadApps() {
        if (!appsList) { return; }
        var id = client ? client.value : '';
        if (!id) { appsList.textContent = ''; if (appsCol) { appsCol.classList.add('d-none'); } return; }
        var body = new URLSearchParams({ action: 'selectable_apps', client_id: id, location_id: (location_ && location_.value) || '0', os: os, csrf_token: csrf });
        fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success || !client || client.value !== id) { return; }
                appsList.textContent = '';
                if (appsCol) { appsCol.classList.toggle('d-none', !d.enabled); }
                if (!d.enabled) { return; }
                if (!d.apps || !d.apps.length) {
                    var p = document.createElement('p'); p.className = 'small text-muted mb-0'; p.textContent = 'The catalog has no app for this department and operating system yet.'; appsList.appendChild(p); return;
                }
                d.apps.forEach(function (a) {
                    var row = document.createElement('div'); row.className = 'form-check mb-1';
                    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.name = 'app_ids[]'; cb.value = String(a.package_id); cb.id = 'rmm-inst-app-' + a.package_id;
                    var blocked = a.missing_params && a.missing_params.length > 0;
                    cb.checked = !!a.auto && !blocked; cb.disabled = !!blocked;
                    var label = document.createElement('label'); label.className = 'form-check-label small'; label.htmlFor = cb.id;
                    label.textContent = a.name + ' ' + a.version + (a.required ? ' (required)' : '') + (a.two_person ? ' - needs a second person' : '');
                    row.appendChild(cb); row.appendChild(label);
                    if (blocked) {
                        var help = document.createElement('div'); help.className = 'form-text text-danger';
                        help.textContent = 'Missing vendor parameter(s) for this department: ' + a.missing_params.join(', ') + '. Set them in the software catalog first.';
                        row.appendChild(help);
                    }
                    appsList.appendChild(row);
                });
            })
            .catch(function () { /* the tick-boxes are optional; the plain form still posts without them */ });
    }

    function fileNameFrom(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = /filename="([A-Za-z0-9._-]+)"/.exec(cd);
        return m ? m[1] : fallback;
    }

    function human(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

    function fields() {
        var fd = new URLSearchParams(new FormData(form));
        fd.set('csrf_token', csrf);
        return fd.toString();
    }

    function request() {
        return fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/octet-stream, application/json' }, body: fields() });
    }

    function failure(res) {
        return res.json().then(function (d) { return (d && d.error) || 'The installer could not be created.'; }, function () { return 'The installer could not be created (HTTP ' + res.status + ').'; });
    }

    function downloadWindows() {
        return request().then(function (res) {
            if (!res.ok) { return failure(res).then(function (m) { throw new Error(m); }); }
            var isMsi = formatValue() === 'msi';
            var name = fileNameFrom(res, isMsi ? 'RivetIT-Agent.msi' : 'RivetIT-Agent-Setup.exe');
            return res.blob().then(function (blob) {
                var a = document.createElement('a');
                var url = URL.createObjectURL(blob);
                a.href = url; a.download = name; a.rel = 'noopener'; a.style.display = 'none';
                document.body.appendChild(a); a.click();
                window.setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
                $(isMsi ? 'rmm-inst-silent-name-msi' : 'rmm-inst-silent-name').textContent = name;
                var n = parseInt(uses.value, 10) || 1;
                var appsNote = res.headers.get('X-Apps-Note') || '';
                done.textContent = 'Downloaded ' + name + ' (' + human(blob.size) + '). Copy it to ' + (n > 1 ? 'the PCs' : 'the PC') + ', double-click it and accept the administrator prompt. This file works for ' + n + (n > 1 ? ' PCs' : ' PC') + ' for ' + (parseInt(ttl.value, 10) || 24) + ' hours; making another one creates a new audited token.'
                    + (isMsi ? ' It is an MSI: double-click it, or run msiexec /i "' + name + '" /qn /norestart. It is unsigned: SmartScreen or an application-control policy may warn.' : '')
                    + (meshBox && meshBox.checked ? ' It also installs the MeshCentral agent from your Mesh server after the RivetIT agent; if that part fails the RivetIT agent is still installed and the device page says why.' : '')
                    + (appsNote ? ' ' + appsNote : '');
                done.classList.remove('d-none');
                modal.setAttribute('data-state', 'downloaded');
            });
        });
    }

    function createLinux() {
        return request().then(function (res) {
            return res.json().then(function (d) {
                if (!res.ok || !d || !d.success) { throw new Error((d && d.error) || 'The command could not be created.'); }
                cmdBox.textContent = d.command;
                $('rmm-inst-linux-intro').classList.toggle('d-none', !!d.self_contained);   // 26.10.52: with the Linux files recorded the command downloads and checks everything itself
                $('rmm-inst-linux-self').classList.toggle('d-none', !d.self_contained);
                $('rmm-inst-linux-selfver').textContent = d.agent_version ? '(' + d.agent_version + ')' : '';
                $('rmm-inst-linux-meta').textContent = 'For ' + d.department + '. Works for ' + d.max_uses + (d.max_uses === 1 ? ' machine' : ' machines') + ' until ' + (d.expires_at_text || d.expires_at) + '.'
                    + (meshBox && meshBox.checked ? ' It also installs the MeshCentral agent (the command holds your Mesh device group id: keep it secret).' : '')
                    + (d.apps_note ? ' ' + d.apps_note : '');
                linOut.classList.remove('d-none');
                modal.setAttribute('data-state', 'linux-command');
            });
        });
    }

    form.addEventListener('submit', function (ev) {
        if (!window.fetch) { return; }   // no script support for fetch: the plain form post downloads the file
        ev.preventDefault();
        if (busy || go.disabled) { return; }
        if (client && !client.value) { showError('Choose the department the PC belongs to.'); client.focus(); return; }
        showError(''); done.classList.add('d-none');
        busy = true; refresh();
        (os === 'windows' ? downloadWindows() : createLinux())
            .catch(function (e) { showError(e && e.message ? e.message : 'Something went wrong.'); })
            .then(function () { busy = false; refresh(); });
    });

    // tabs (arrow keys move between them, like any tablist)
    ['windows', 'linux'].forEach(function (n) {
        var t = $('rmm-inst-tab-' + n);
        t.addEventListener('click', function () { setOs(n); });
        t.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { var other = n === 'windows' ? 'linux' : 'windows'; setOs(other); $('rmm-inst-tab-' + other).focus(); e.preventDefault(); }
        });
    });
    form.querySelectorAll('input[name="arch"]').forEach(function (r) { r.addEventListener('change', refresh); });
    form.querySelectorAll('input[name="format"]').forEach(function (r) { r.addEventListener('change', function () { done.classList.add('d-none'); showError(''); refresh(); }); });
    if (client) { client.addEventListener('change', function () { loadLocations(); refresh(); }); }
    if (location_) { location_.addEventListener('change', loadApps); }
    // a command made with the other choice must not stay on screen: it would not match the box any more
    if (meshBox) { meshBox.addEventListener('change', function () { linOut.classList.add('d-none'); cmdBox.textContent = ''; done.classList.add('d-none'); showError(''); }); }
    multi.addEventListener('change', function () { uses.value = multi.checked ? MULTI_USES : 1; });
    uses.addEventListener('input', function () { multi.checked = (parseInt(uses.value, 10) || 1) > 1; });

    $('rmm-inst-copy').addEventListener('click', function () {
        var text = cmdBox.textContent, label = this.querySelector('span');
        var ok = function () { label.textContent = 'Copied'; window.setTimeout(function () { label.textContent = 'Copy command'; }, 2000); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(ok, function () { fallbackCopy(text) && ok(); }); }
        else if (fallbackCopy(text)) { ok(); }
    });
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }

    // opening: preselect the department of the control that was clicked (the department page), reset the transient state
    modal.addEventListener('show.bs.modal', function (ev) {
        var trigger = ev.relatedTarget, want = trigger && trigger.getAttribute ? trigger.getAttribute('data-rmm-installer-client') : null;
        if (want) { selectClient(want); }
        cmdBox.textContent = ''; linOut.classList.add('d-none'); done.classList.add('d-none'); showError('');
        if (meshBox) { meshBox.checked = false; }   // off by default, every time the dialog opens
        $('rmm-inst-format-exe').checked = true;    // the .exe is the default, every time the dialog opens
        modal.removeAttribute('data-state');
        var g = guessArch(), radio = $('rmm-inst-arch-' + g);
        if (radio && have(g)) { radio.checked = true; }
        loadApps();
        refresh();
    });
    modal.addEventListener('shown.bs.modal', function () {
        var first = (client && client.tagName === 'SELECT' && !client.value) ? client : go;
        if (first && !first.disabled) { first.focus(); }
    });
    // the dialog returns focus to the control that opened it (Bootstrap does this; the menu link has no stable element, so remember it)
    var opener = null;
    document.addEventListener('click', function (e) { var t = e.target.closest && e.target.closest('[data-rmm-installer-open]'); if (t) { opener = t; } });
    modal.addEventListener('hidden.bs.modal', function () { if (opener && document.body.contains(opener) && opener.focus) { try { opener.focus(); } catch (e) { /* ignore */ } } });

    // /agent/rmm_fleet.php?installer=1 (the Endpoints menu entry) opens the dialog by itself
    if (/(^|[?&])installer=1(&|$)/.test(window.location.search) && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
    refresh();
})();
