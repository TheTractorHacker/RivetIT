/*
 * Training › Set up this device (P3 spec §5.8, plan A21). Asset search (kiosk_asset_options) - or
 * "This device isn't in Assets" (an unlisted device: just a name, 2.6.94) - name + default
 * department + how long ("Keep until I remove it" or Temporary: end of today / 4 / 8 / 24 hours /
 * a date and time), kiosk_enroll_here, then the one-time start URL. "Open training on this device"
 * always signs the agent out first: fetch('/agent/post.php?logout') with redirect:'manual', then
 * location.replace(open_url) (the #d= form; the ?d= start_url is only shown, for kiosk-mode
 * browsers). Expiry times are shown in the app's time zone. Everything renders with textContent.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        var tz = data.timezone || undefined;
        var maxDays = data.max_days || 30;
        function $(id) { return document.getElementById(id); }
        var q = $('tr-setup-q');
        var list = $('tr-setup-assets');
        var details = $('tr-setup-details');
        var done = $('tr-setup-done');
        var label = $('tr-setup-label');
        var chosen = null;     // the picked asset, or null
        var unlisted = false;  // "This device isn't in Assets"
        var startUrl = null;   // /kiosk/?d=<token>: shown once, for a kiosk-mode browser's start page
        var openUrl = null;    // /kiosk/#d=<token>: "Open training" here (a fragment never reaches a server)
        var seq = 0;

        (data.departments || []).forEach(function (d) {
            $('tr-setup-dept').appendChild(el('option', { value: String(d.id), text: d.name }));
        });

        // ---- time helpers (the app's time zone) ---------------------------------------------------
        function fmt(date, withZone) {
            try {
                return date.toLocaleString([], { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: withZone ? 'short' : undefined });
            } catch (e) {
                return date.toLocaleString();
            }
        }
        /** 'YYYY-MM-DDTHH:MM' wall clock in the app's time zone (datetime-local min/max). */
        function wall(date) {
            var p = {};
            try {
                new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
                    .formatToParts(date).forEach(function (x) { p[x.type] = x.value; });
            } catch (e) {
                return '';
            }
            return p.year + '-' + p.month + '-' + p.day + 'T' + (p.hour === '24' ? '00' : p.hour) + ':' + p.minute;
        }
        /** The app zone's short name right now ("CDT"), or its IANA name. */
        function zoneName() {
            try {
                var z = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'short' }).formatToParts(new Date()).filter(function (x) { return x.type === 'timeZoneName'; });
                return z.length ? z[0].value : (tz || '');
            } catch (e) {
                return tz || '';
            }
        }
        /** A datetime-local value (app zone) as text, without converting it through the browser's zone. */
        function wallText(v) {
            var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || '');
            if (!m) { return ''; }
            var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
            return d.toLocaleString([], { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        }
        function preview() {
            var out = $('tr-setup-expiry-preview');
            var preset = $('tr-setup-expires').value;
            var now = new Date();
            var t = '';
            if (preset === '4h' || preset === '8h' || preset === '24h') {
                t = 'Stops working about ' + fmt(new Date(now.getTime() + parseInt(preset, 10) * 3600000), true) + '.';
            } else if (preset === 'today') {
                t = 'Stops working tonight at 11:59 PM ' + zoneName() + '.';
            } else if (preset === 'until') {
                var v = $('tr-setup-until').value;
                t = v ? 'Stops working ' + wallText(v) + ' ' + zoneName() + '.' : 'Pick when it stops working (up to ' + maxDays + ' days from now).';
            }
            out.textContent = t + ' You can extend it or end it early on Devices & PINs.';
        }
        function syncLife() {
            var temp = $('tr-setup-life-temp').checked;
            $('tr-setup-temp').hidden = !temp;
            var until = $('tr-setup-expires').value === 'until';
            $('tr-setup-until-wrap').hidden = !(temp && until);
            if (temp && until) {
                var u = $('tr-setup-until');
                u.min = wall(new Date(Date.now() + 5 * 60000));
                u.max = wall(new Date(Date.now() + maxDays * 86400000));
                if (!u.value) { u.value = wall(new Date(Date.now() + 2 * 3600000)); }
            }
            if (temp) { preview(); }
        }
        $('tr-setup-life-keep').addEventListener('change', syncLife);
        $('tr-setup-life-temp').addEventListener('change', syncLife);
        $('tr-setup-expires').addEventListener('change', syncLife);
        $('tr-setup-until').addEventListener('input', function () { this.classList.remove('is-invalid'); preview(); });

        function typeIcon(t) {
            return { 'Tablet': 'fa-tablet-alt', 'Phone': 'fa-mobile-alt', 'Mobile Phone': 'fa-mobile-alt', 'Laptop': 'fa-laptop', 'Desktop': 'fa-desktop' }[t] || 'fa-tablet-alt';
        }

        function clearList() { while (list.firstChild) { list.removeChild(list.firstChild); } }

        function load() {
            var my = ++seq;
            list.setAttribute('aria-busy', 'true');
            api.get('kiosk_asset_options', { q: q.value.trim() }).then(function (res) {
                if (my !== seq) { return; }
                list.removeAttribute('aria-busy');
                clearList();
                var assets = (res && res.assets) || [];
                if (!assets.length) {
                    var none = el('div', { class: 'list-group-item text-secondary' }, [
                        el('div', { text: 'No matching device assets (type Tablet, Phone, Mobile Phone, Laptop or Desktop).' }),
                        el('button', { type: 'button', class: 'btn btn-link p-0 mt-1', text: 'Set it up without an asset instead' })
                    ]);
                    none.querySelector('button').addEventListener('click', function () { $('tr-setup-kind-unlisted').click(); });
                    list.appendChild(none);
                    return;
                }
                assets.forEach(function (a) {
                    var sub = [a.type, a.serial ? 'SN ' + a.serial : '', a.client_name].filter(Boolean).join(' · ');
                    var who = a.assigned_contact ? 'Assigned to ' + a.assigned_contact.name + (a.assigned_contact.eligible ? '' : ' (not eligible for training)') : 'Not assigned (shared)';
                    var badge = a.active_kiosk ? el('span', { class: 'badge bg-warning-lt ms-auto', text: 'Enrolled as ' + a.active_kiosk.label }) : null;
                    var item = el('button', { type: 'button', class: 'list-group-item list-group-item-action d-flex align-items-center gap-3', role: 'option', 'aria-selected': 'false' }, [
                        el('i', { class: 'fas ' + typeIcon(a.type) + ' fa-lg text-secondary', 'aria-hidden': 'true' }),
                        el('span', { class: 'd-flex flex-column' }, [el('strong', { text: a.name }), el('small', { class: 'text-secondary', text: sub }), el('small', { text: who })]),
                        badge
                    ]);
                    item.addEventListener('click', function () { choose(a, item); });
                    list.appendChild(item);
                });
            }, function (err) {
                if (my !== seq) { return; }
                list.removeAttribute('aria-busy');
                ui.toast(err.message || 'Could not load assets.', { type: 'error' });
            });
        }

        function setMode(icon, text) {
            var mode = $('tr-setup-mode');
            while (mode.firstChild) { mode.removeChild(mode.firstChild); }
            mode.appendChild(el('i', { class: 'fas ' + icon + ' mt-1', 'aria-hidden': 'true' }));
            mode.appendChild(el('span', { text: text }));
        }

        function choose(a, item) {
            chosen = a;
            Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('active'); c.setAttribute('aria-selected', 'false'); });
            if (item) {
                item.classList.add('active');
                item.setAttribute('aria-selected', 'true');
            }
            details.hidden = false;
            var personal = a.assigned_contact && a.assigned_contact.eligible;
            setMode(personal ? 'fa-user' : 'fa-users', personal
                ? 'Personal device: it opens straight to ' + a.assigned_contact.name + '’s PIN. If the asset is re-assigned later, the device stops working until it gets a new start URL.'
                : 'Shared device: people find their name, then enter their PIN.');
            var rep = $('tr-setup-replace');
            if (a.active_kiosk) {
                rep.textContent = 'This asset is already enrolled as “' + a.active_kiosk.label + '”. Continuing moves it here and the old setup stops working.';
                rep.classList.remove('d-none');
            } else {
                rep.textContent = '';
                rep.classList.add('d-none');
            }
            if (!label.value) { label.value = a.name; }
            if (a.client_id) { $('tr-setup-dept').value = String(a.client_id); }
            label.focus();
        }

        /** "It's in Assets" / "This device isn't in Assets". */
        function setKind(isUnlisted) {
            unlisted = isUnlisted;
            $('tr-setup-asset-pane').hidden = isUnlisted;
            $('tr-setup-unlisted-pane').hidden = !isUnlisted;
            label.classList.remove('is-invalid');
            $('tr-setup-replace').classList.add('d-none');
            if (isUnlisted) {
                chosen = null;
                Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('active'); c.setAttribute('aria-selected', 'false'); });
                details.hidden = false;
                setMode('fa-users', 'Not in Assets: a shared device. People find their name, then enter their PIN.');
                label.placeholder = 'Trainer’s laptop, Borrowed iPad';
                label.value = '';
                // A device you don't track is usually a one-off: start on Temporary (until tonight).
                $('tr-setup-life-temp').checked = true;
                $('tr-setup-expires').value = 'today';
                syncLife();
                label.focus();
            } else {
                details.hidden = chosen === null;
                label.placeholder = 'Fab Shop iPad 2';
                label.value = '';
                $('tr-setup-life-keep').checked = true;
                syncLife();
                q.focus();
            }
        }
        $('tr-setup-kind-asset').addEventListener('change', function () { if (this.checked) { setKind(false); } });
        $('tr-setup-kind-unlisted').addEventListener('change', function () { if (this.checked) { setKind(true); } });

        var debounced = ui.debounce(load, 250);
        q.addEventListener('input', debounced);
        label.addEventListener('input', function () { label.classList.remove('is-invalid'); });

        function showFieldError(field, msg) {
            var input = field === 'label' ? label : (field === 'expires_until' ? $('tr-setup-until') : null);
            if (!input) { return false; }
            input.classList.add('is-invalid');
            var fb = document.querySelector('[data-field="' + field + '"]');
            if (fb) { fb.textContent = msg; }
            input.focus();
            return true;
        }

        $('tr-setup-go').addEventListener('click', function () {
            if (!chosen && !unlisted) { return; }
            var btn = this;
            label.classList.remove('is-invalid');
            $('tr-setup-until').classList.remove('is-invalid');
            if (unlisted && !label.value.trim()) {
                showFieldError('label', 'Give the device a name, like “Trainer’s laptop”.');
                return;
            }
            var body = {
                label: label.value.trim(),
                default_client_id: parseInt($('tr-setup-dept').value, 10) || 0,
                expires: $('tr-setup-life-temp').checked ? $('tr-setup-expires').value : 'keep'
            };
            if (body.expires === 'until') { body.expires_until = $('tr-setup-until').value; }
            if (unlisted) {
                body.unlisted = true;
            } else {
                body.asset_id = chosen.asset_id;
                body.replace = !!chosen.active_kiosk;
            }
            btn.disabled = true;
            api.post('kiosk_enroll_here', body).then(function (res) {
                startUrl = res.start_url;
                openUrl = res.open_url || res.start_url;
                $('tr-setup-form-card').hidden = true;
                details.hidden = true;
                done.hidden = false;
                $('tr-setup-done-title').textContent = 'Enrolled as ' + res.label;
                $('tr-setup-done-mode').textContent = (res.unlisted ? 'Not in Assets. ' : '')
                    + (res.personal ? 'Opens straight to ' + res.personal.name + '.' : 'Shared device: people find their name, then enter their PIN.');
                var exp = $('tr-setup-done-expiry');
                while (exp.firstChild) { exp.removeChild(exp.firstChild); }
                if (res.device_expires_at) {
                    exp.appendChild(el('i', { class: 'fas fa-hourglass-half text-warning mt-1', 'aria-hidden': 'true' }));
                    exp.appendChild(el('span', {}, [el('strong', { text: 'Temporary: ' }), 'stops working ' + fmt(new Date(res.device_expires_at), true) + '. The start URL stops working then too.']));
                } else {
                    exp.appendChild(el('i', { class: 'fas fa-infinity text-secondary mt-1', 'aria-hidden': 'true' }));
                    exp.appendChild(el('span', { text: 'Kept until you remove it.' }));
                }
                $('tr-setup-url').value = startUrl;
                $('tr-setup-open').focus();
            }, function (err) {
                btn.disabled = false;
                var f = err.fields || {};
                if (f.label && showFieldError('label', f.label)) { return; }
                if (f.expires_until && showFieldError('expires_until', f.expires_until)) { return; }
                if (err.code === 'asset_enrolled' && chosen) {
                    chosen.active_kiosk = { id: err.data && err.data.kiosk_id, label: (err.data && err.data.label) || '' };
                    choose(chosen, list.querySelector('.active'));
                    ui.toast('That asset is already enrolled. Press the button again to move it here.', { type: 'warning' });
                    return;
                }
                ui.toast(f.expires || err.message || 'Could not enroll this device.', { type: 'error' });
            });
        });

        $('tr-setup-copy').addEventListener('click', function () {
            var input = $('tr-setup-url');
            input.select();
            var ok = function () { ui.toast('Start URL copied.'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(ok, function () { try { document.execCommand('copy'); ok(); } catch (e) { /* ignore */ } });
            } else {
                try { document.execCommand('copy'); ok(); } catch (e) { /* ignore */ }
            }
        });

        $('tr-setup-open').addEventListener('click', function () {
            if (!openUrl) { return; }
            var btn = this;
            btn.disabled = true;
            var go = function () {
                var u = openUrl;
                startUrl = null;
                openUrl = null;
                $('tr-setup-url').value = '';
                window.location.replace(u);
            };
            // The sign-out is not optional (A21): the device must not keep an ITFlow session.
            fetch('/agent/post.php?logout', { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' }).then(go, go);
        });

        syncLife();
        load();
    });
}());
