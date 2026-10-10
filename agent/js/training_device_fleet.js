/*
 * Training › Devices & PINs › Fleet links (2.6.151, issue #43). fleet_list / fleet_create / fleet_revoke / fleet_rotate /
 * fleet_mobileconfig / fleet_export / fleet_approve / fleet_reject (+ kiosk_asset_options for the approve form).
 * The plain token exists only in this page's memory after create / rotate (the server keeps sha256); the MDM URL, the
 * Web Clip profile and the per-device CSV are all built from it while the reveal panel is open. Every string reaches the
 * DOM through TrainingUi.el / textContent.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        var root = document.getElementById('trf-root');
        if (!ui || !api || !root) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        function $(id) { return document.getElementById(id); }
        function clear(n) { while (n && n.firstChild) { n.removeChild(n.firstChild); } }
        function fail(err, fallback) { ui.toast((err && err.message) || fallback, { type: 'error' }); }
        function when(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            try { return d.toLocaleString([], { timeZone: data.timezone, year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return d.toLocaleString(); }
        }
        var NOTES = {
            no_serial: 'The link had no serial number',
            no_match: 'No active Asset in this department has that serial',
            ambiguous: 'More than one Asset has that serial',
            other_department: 'That serial belongs to an Asset in another department',
            asset_in_use: 'The matching Asset already has a training device',
            matched: 'Matched an Asset - needs your approval',
            auto: 'Auto-enrolled'
        };

        (data.departments || []).forEach(function (d) { $('trf-dept').appendChild(el('option', { value: String(d.id), text: d.name })); });

        // ---- reveal (token in memory only) -------------------------------------------------
        var shown = null;   // {fleet_id, token, url}
        function macro() { return $('trf-macro').value.trim(); }
        function mdmUrl() { return shown ? shown.url + '&sn=' + macro() : ''; }
        function refreshReveal() { $('trf-url-mdm').value = mdmUrl(); }
        function reveal(name, r) {
            shown = r;
            $('trf-reveal-name').textContent = name;
            $('trf-url-plain').value = r.url;
            $('trf-reveal').hidden = false;
            refreshReveal();
            $('trf-reveal').scrollIntoView({ block: 'nearest' });
        }
        $('trf-mdm').addEventListener('change', function () { if (this.value) { $('trf-macro').value = this.value; } refreshReveal(); });
        $('trf-macro').addEventListener('input', function () { this.classList.remove('is-invalid'); refreshReveal(); });
        root.querySelectorAll('[data-copy]').forEach(function (b) {
            b.addEventListener('click', function () {
                var input = $(b.getAttribute('data-copy'));
                input.select();
                var done = function () { ui.toast('Copied.'); };
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
                else { document.execCommand('copy'); done(); }
            });
        });
        function download(name, mime, text) {
            var url = URL.createObjectURL(new Blob([text], { type: mime }));
            var a = el('a', { href: url, download: name });
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        }
        $('trf-dl-profile').addEventListener('click', function () {
            if (!shown) { return; }
            var btn = this;
            btn.disabled = true;
            api.post('fleet_mobileconfig', { fleet_id: shown.fleet_id, token: shown.token, placeholder: macro(), name: $('trf-clip-name').value.trim() }).then(function (res) {
                download(res.filename, 'application/x-apple-aspen-config', res.content);
            }, function (err) {
                var f = (err && err.fields) || {};
                if (f.placeholder) { $('trf-macro').classList.add('is-invalid'); }
                if (f.name) { $('trf-clip-name').classList.add('is-invalid'); }
                fail(err, 'Could not build the profile.');
            }).then(function () { btn.disabled = false; });
        });
        function csvCell(v) {
            var s = String(v == null ? '' : v);
            if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }   // never let a spreadsheet run an asset name as a formula
            return '"' + s.replace(/"/g, '""') + '"';
        }
        var csvBtn = $('trf-dl-csv');
        if (csvBtn) {
            csvBtn.addEventListener('click', function () {
                if (!shown) { return; }
                csvBtn.disabled = true;
                api.post('fleet_export', { fleet_id: shown.fleet_id, token: shown.token }).then(function (res) {
                    var rows = (res && res.rows) || [];
                    if (!rows.length) { ui.toast('No tablets with a usable serial number in this department.', { type: 'warning' }); return; }
                    var out = ['Device name,Serial,Type,Start URL'];
                    rows.forEach(function (r) { out.push([r.name, r.serial, r.type, r.url].map(csvCell).join(',')); });
                    download('training-kiosk-device-urls.csv', 'text/csv', out.join('\r\n') + '\r\n');
                }, function (err) { fail(err, 'Could not export.'); }).then(function () { csvBtn.disabled = false; });
            });
        }

        // ---- create ------------------------------------------------------------------------
        $('trf-create').addEventListener('click', function () {
            var btn = this;
            root.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
            var approval = root.querySelector('input[name="trf-approval"]:checked');
            var name = $('trf-label').value.trim();
            btn.disabled = true;
            api.post('fleet_create', {
                label: name, client_id: parseInt($('trf-dept').value, 10) || 0, approval: approval ? approval.value : 'require',
                days: parseInt($('trf-days').value, 10), max_uses: parseInt($('trf-max').value, 10) || 0
            }).then(function (res) {
                reveal(name, res);
                $('trf-label').value = '';
                load();
            }, function (err) {
                var f = (err && err.fields) || {};
                Object.keys(f).forEach(function (k) {
                    var fb = root.querySelector('[data-field="' + k + '"]');
                    if (fb) { fb.textContent = f[k]; if (fb.previousElementSibling) { fb.previousElementSibling.classList.add('is-invalid'); } }
                });
                fail(err, 'Could not create the link.');
            }).then(function () { btn.disabled = false; });
        });

        // ---- links -------------------------------------------------------------------------
        var STATE = { active: ['bg-success', 'Active'], expired: ['bg-secondary', 'Expired'], used_up: ['bg-secondary', 'Used up'], revoked: ['bg-danger', 'Revoked'] };
        function renderLinks(links) {
            var body = $('trf-links-body');
            clear(body);
            if (!links.length) { body.appendChild(el('tr', null, el('td', { colspan: '6', class: 'text-secondary', text: 'No fleet links yet.' }))); return; }
            links.forEach(function (l) {
                var st = STATE[l.state] || ['bg-secondary', l.state];
                var actions = el('div', { class: 'd-flex gap-1 justify-content-end' });
                if (l.state !== 'revoked') {
                    actions.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: 'Revoke', on: { click: function () {
                        if (!window.confirm('Revoke "' + l.label + '"? New tablets can no longer use it. Tablets already enrolled keep working.')) { return; }
                        api.post('fleet_revoke', { fleet_id: l.id }).then(function () { ui.toast('Link revoked.'); load(); }, function (e) { fail(e, 'Could not revoke.'); });
                    } } }));
                }
                actions.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Rotate', on: { click: function () {
                    if (!window.confirm('Rotate "' + l.label + '"? The old link stops working and you get a new one to push to your MDM.')) { return; }
                    api.post('fleet_rotate', { fleet_id: l.id }).then(function (res) { reveal(l.label, res); load(); }, function (e) { fail(e, 'Could not rotate.'); });
                } } }));
                body.appendChild(el('tr', null, [
                    el('td', null, [el('div', { class: 'fw-bold', text: l.label }), el('div', { class: 'text-secondary small', text: l.approval === 'auto_match' ? 'Auto-enroll on serial match' : 'Requires approval' })]),
                    el('td', { text: l.department }),
                    el('td', null, [el('span', { text: l.use_count + ' of ' + l.max_uses }), l.pending_count ? el('span', { class: 'badge bg-warning ms-2', text: l.pending_count + ' waiting' }) : null]),
                    el('td', { text: when(l.expires_at) }),
                    el('td', null, el('span', { class: 'badge text-white ' + st[0], text: st[1] })),
                    el('td', null, actions)
                ]));
            });
        }

        // ---- pending -----------------------------------------------------------------------
        function approveForm(p, row) {
            var existing = row.nextElementSibling;
            if (existing && existing.classList.contains('trf-approve')) { existing.parentNode.removeChild(existing); return; }
            var label = el('input', { type: 'text', class: 'form-control form-control-sm', maxlength: '100', placeholder: p.asset ? p.asset.name : 'Device name', 'aria-label': 'Device name' });
            var assetSel = el('select', { class: 'form-select form-select-sm', 'aria-label': 'Asset' }, el('option', { value: '', text: p.asset ? 'Use the matched asset: ' + p.asset.name : 'Not in Assets (unlisted device)' }));
            var kids = [el('div', { class: 'col-md-4' }, [el('label', { class: 'form-label small mb-1', text: 'Name' }), label])];
            if (data.can_assets) {
                var q = el('input', { type: 'search', class: 'form-control form-control-sm', maxlength: '80', placeholder: 'Search Assets by name or serial', 'aria-label': 'Search assets' });
                var search = ui.debounce(function () {
                    api.get('kiosk_asset_options', { q: q.value.trim() }).then(function (res) {
                        clear(assetSel);
                        assetSel.appendChild(el('option', { value: '', text: p.asset ? 'Use the matched asset: ' + p.asset.name : 'Not in Assets (unlisted device)' }));
                        ((res && res.assets) || []).forEach(function (a) {
                            if (a.active_kiosk) { return; }
                            assetSel.appendChild(el('option', { value: String(a.asset_id), text: a.name + (a.serial ? ' - ' + a.serial : '') + (a.client_name ? ' (' + a.client_name + ')' : '') }));
                        });
                    }, function () { /* keep the current options */ });
                }, 300);
                q.addEventListener('input', search);
                kids.push(el('div', { class: 'col-md-4' }, [el('label', { class: 'form-label small mb-1', text: 'Bind to an Asset (this department only)' }), q, assetSel]));
            }
            var ok = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'Approve' });
            ok.addEventListener('click', function () {
                ok.disabled = true;
                api.post('fleet_approve', { kiosk_id: p.id, label: label.value.trim(), asset_id: assetSel.value ? parseInt(assetSel.value, 10) : 0 }).then(function (res) {
                    ui.toast('Approved "' + res.label + '".');
                    load();
                }, function (e) { ok.disabled = false; fail(e, 'Could not approve.'); });
            });
            kids.push(el('div', { class: 'col-md-4 d-flex align-items-end' }, ok));
            var tr = el('tr', { class: 'trf-approve' }, el('td', { colspan: '6' }, el('div', { class: 'row g-2' }, kids)));
            row.parentNode.insertBefore(tr, row.nextSibling);
            label.focus();
        }
        function renderPending(pending) {
            $('trf-pending-card').hidden = pending.length === 0;
            $('trf-pending-count').textContent = String(pending.length);
            var body = $('trf-pending-body');
            clear(body);
            pending.forEach(function (p) {
                var row = el('tr', null, [
                    el('td', null, [el('div', { class: 'fw-bold', text: p.label }), p.asset ? el('div', { class: 'text-secondary small', text: 'Asset: ' + p.asset.name }) : null]),
                    el('td', { class: 'font-monospace', text: p.serial || '(none)' }),
                    el('td', { class: 'small', text: NOTES[p.note] || p.note }),
                    el('td', null, [el('div', { text: p.department }), el('div', { class: 'text-secondary small', text: p.link })]),
                    el('td', { text: when(p.requested_at) }),
                    el('td', null, el('div', { class: 'd-flex gap-1' }, [
                        el('button', { type: 'button', class: 'btn btn-sm btn-success', text: 'Approve', on: { click: function () { approveForm(p, row); } } }),
                        el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: 'Reject', on: { click: function () {
                            if (!window.confirm('Reject "' + p.label + '"? It will not be able to use training.')) { return; }
                            api.post('fleet_reject', { kiosk_id: p.id }).then(function () { ui.toast('Rejected.'); load(); }, function (e) { fail(e, 'Could not reject.'); });
                        } } })
                    ]))
                ]);
                body.appendChild(row);
            });
        }

        function load() {
            return api.get('fleet_list').then(function (res) {
                renderLinks((res && res.links) || []);
                renderPending((res && res.pending) || []);
            }, function (err) { fail(err, 'Could not load fleet links.'); });
        }
        load();
    });
}());
