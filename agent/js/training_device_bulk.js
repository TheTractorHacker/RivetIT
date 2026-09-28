/*
 * Training › Devices & PINs › Get setup codes (2.6.101). Multi-select asset search (checkboxes,
 * DeviceEnrollment::assetOptions via kiosk_asset_options - same list the single-device page uses)
 * plus a one-name-per-line textarea for unlisted devices (with a "prefix + count" quick-fill that
 * just appends lines), one shared department + duration (training_device_time.js, same presets as
 * training_device_setup.js), then kiosk_enroll_codes -> redirect to the print page. On a 409
 * asset_enrolled conflict the reveal-then-retry pattern mirrors training_device_setup.js: show the
 * conflicting assets, reveal "Replace already-enrolled assets", and let the admin press the button
 * again with replace on.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var root = document.getElementById('trb-bulk');
        if (!root) { return; }
        var data = ui.readJson('tr-page-data');
        var maxDays = data.max_days || 30;
        var maxDevices = data.max_devices || 40;
        var T = window.TrainingDeviceTime.create(data.timezone || undefined, maxDays);
        function $(id) { return document.getElementById(id); }

        (data.departments || []).forEach(function (d) {
            $('trb-dept').appendChild(el('option', { value: String(d.id), text: d.name }));
        });

        // ---- picked assets (checkbox multi-select) -------------------------------------------
        var assetList = $('trb-assets');
        var pickedAssets = {};   // asset_id -> {asset_id, name}
        var seq = 0;

        function typeIcon(t) {
            return { 'Tablet': 'fa-tablet-alt', 'Phone': 'fa-mobile-alt', 'Mobile Phone': 'fa-mobile-alt', 'Laptop': 'fa-laptop', 'Desktop': 'fa-desktop' }[t] || 'fa-tablet-alt';
        }

        function updateCount() {
            var n = Object.keys(pickedAssets).length + linesOf($('trb-names').value).length;
            var badge = $('trb-count-badge');
            badge.textContent = String(n);
            badge.className = 'badge ms-1 ' + (n > maxDevices ? 'bg-danger text-white' : 'bg-white text-primary');
            $('trb-go').disabled = n === 0 || n > maxDevices;
        }

        function loadAssets() {
            if (!assetList) { return; }
            var my = ++seq;
            var q = $('trb-q').value.trim();
            assetList.setAttribute('aria-busy', 'true');
            api.get('kiosk_asset_options', { q: q }).then(function (res) {
                if (my !== seq) { return; }
                assetList.removeAttribute('aria-busy');
                while (assetList.firstChild) { assetList.removeChild(assetList.firstChild); }
                var assets = (res && res.assets) || [];
                if (!assets.length) {
                    assetList.appendChild(el('div', { class: 'list-group-item text-secondary small', text: 'No matching device assets.' }));
                    return;
                }
                assets.forEach(function (a) {
                    var sub = [a.type, a.serial ? 'SN ' + a.serial : '', a.client_name].filter(Boolean).join(' · ');
                    var cb = el('input', { type: 'checkbox', class: 'form-check-input flex-shrink-0' });
                    cb.checked = !!pickedAssets[a.asset_id];
                    var badge = a.active_kiosk ? el('span', { class: 'badge bg-warning-lt ms-auto', text: 'Enrolled' }) : null;
                    var row = el('label', { class: 'list-group-item d-flex align-items-center gap-2' }, [
                        cb, el('i', { class: 'fas ' + typeIcon(a.type) + ' text-secondary', 'aria-hidden': 'true' }),
                        el('span', { class: 'd-flex flex-column' }, [el('strong', { text: a.name }), el('small', { class: 'text-secondary', text: sub })]),
                        badge
                    ]);
                    cb.addEventListener('change', function () {
                        if (cb.checked) { pickedAssets[a.asset_id] = { asset_id: a.asset_id, name: a.name }; } else { delete pickedAssets[a.asset_id]; }
                        updateCount();
                    });
                    assetList.appendChild(row);
                });
            }, function (err) {
                if (my !== seq) { return; }
                assetList.removeAttribute('aria-busy');
                ui.toast((err && err.message) || 'Could not load assets.', { type: 'error' });
            });
        }
        if (assetList) {
            $('trb-q').addEventListener('input', ui.debounce(loadAssets, 250));
            loadAssets();
        }

        // ---- unlisted device names (textarea) -------------------------------------------------
        function linesOf(text) {
            return String(text || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
        }
        $('trb-names').addEventListener('input', function () { this.classList.remove('is-invalid'); updateCount(); });

        $('trb-prefix-add').addEventListener('click', function () {
            var prefix = $('trb-prefix').value.trim() || 'Device';
            var n = Math.max(1, Math.min(maxDevices, parseInt($('trb-count').value, 10) || 1));
            var names = $('trb-names');
            var lines = linesOf(names.value);
            for (var i = 1; i <= n; i++) { lines.push(prefix + ' ' + i); }
            names.value = lines.join('\n');
            updateCount();
            names.focus();
        });

        // ---- how long (mirrors training_device_setup.js exactly) ------------------------------
        function preview() {
            var out = $('trb-expiry-preview');
            var preset = $('trb-expires').value;
            var r = T.endFor(preset, $('trb-until').value);
            var t = '';
            out.classList.toggle('text-danger', !!r.error);
            if (r.error) {
                out.textContent = preset === 'until' && !$('trb-until').value ? 'Pick when devices stop working (up to ' + maxDays + ' days from now).' : r.error;
                return;
            }
            if (preset === '4h' || preset === '8h' || preset === '24h') {
                t = 'Stops working about ' + T.fmt(r.date, true) + '.';
            } else if (preset === 'today') {
                t = 'Stops working tonight at ' + T.time(r.date) + '.';
            } else if (preset === 'until') {
                t = 'Stops working ' + T.fmt(r.date, true) + '.';
            }
            out.textContent = t + ' Applies to every device in this batch; change one later on Devices & PINs.';
        }
        function syncLife() {
            var temp = $('trb-life-temp').checked;
            $('trb-temp').hidden = !temp;
            var until = $('trb-expires').value === 'until';
            $('trb-until-wrap').hidden = !(temp && until);
            if (temp && until) {
                var u = $('trb-until');
                T.untilBounds(u);
                if (!u.value) { u.value = T.wall(new Date(Date.now() + 2 * 3600000)); }
            }
            if (temp) { preview(); }
        }
        $('trb-life-keep').addEventListener('change', syncLife);
        $('trb-life-temp').addEventListener('change', syncLife);
        $('trb-expires').addEventListener('change', syncLife);
        $('trb-until').addEventListener('input', function () { this.classList.remove('is-invalid'); preview(); });
        syncLife();

        // ---- submit -----------------------------------------------------------------------------
        function showFieldError(field, msg) {
            var input = field === 'items' ? $('trb-names') : (field === 'expires_until' ? $('trb-until') : null);
            if (!input) { return false; }
            input.classList.add('is-invalid');
            var fb = document.querySelector('[data-field="' + field + '"]');
            if (fb) { fb.textContent = msg; }
            input.focus();
            return true;
        }

        $('trb-go').addEventListener('click', function () {
            var btn = this;
            $('trb-names').classList.remove('is-invalid');
            $('trb-until').classList.remove('is-invalid');
            $('trb-conflict').classList.add('d-none');
            var items = Object.keys(pickedAssets).map(function (k) { var a = pickedAssets[k]; return { asset_id: a.asset_id, label: a.name }; })
                .concat(linesOf($('trb-names').value).map(function (name) { return { label: name }; }));
            if (!items.length) { return; }
            if (items.length > maxDevices) {
                ui.toast('At most ' + maxDevices + ' devices at a time.', { type: 'error' });
                return;
            }
            if ($('trb-life-temp').checked && $('trb-expires').value === 'today' && T.endFor('today').error) {
                ui.toast(T.endFor('today').error, { type: 'error' });
                return;
            }
            var body = {
                items: items,
                default_client_id: parseInt($('trb-dept').value, 10) || 0,
                expires: $('trb-life-temp').checked ? $('trb-expires').value : 'keep',
                replace: !!$('trb-replace').checked
            };
            if (body.expires === 'until') { body.expires_until = $('trb-until').value; }
            btn.disabled = true;
            api.post('kiosk_enroll_codes', body).then(function (res) {
                window.location.assign(res.print_url);
            }, function (err) {
                btn.disabled = false;
                updateCount();
                var f = err.fields || {};
                if (f.items && showFieldError('items', f.items)) { return; }
                if (f.expires_until && showFieldError('expires_until', f.expires_until)) { return; }
                if (err.code === 'assets_enrolled') {
                    var conflicts = (err.data && err.data.conflicts) || [];
                    var box = $('trb-conflict');
                    while (box.firstChild) { box.removeChild(box.firstChild); }
                    box.appendChild(el('div', { class: 'mb-1', text: err.message || 'Some of these assets are already training devices.' }));
                    box.appendChild(el('ul', { class: 'mb-0 ps-3 small' }, conflicts.map(function (c) { return el('li', { text: c.label + ' (already enrolled)' }); })));
                    box.classList.remove('d-none');
                    $('trb-replace-wrap').classList.remove('d-none');
                    $('trb-replace').focus();
                    return;
                }
                ui.toast(f.expires || err.message || 'Could not issue setup codes.', { type: 'error' });
            });
        });

        updateCount();
    });
}());
