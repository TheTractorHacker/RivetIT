/*
 * Training › Set up this device (P3 spec §5.8, plan A21). Asset search (kiosk_asset_options),
 * name + default department, kiosk_enroll_here, then the one-time start URL. "Open training on
 * this device" always signs the agent out first: fetch('/agent/post.php?logout') with
 * redirect:'manual', then location.replace(start_url). Everything renders with textContent.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        var data = ui.readJson('tr-page-data');
        function $(id) { return document.getElementById(id); }
        var q = $('tr-setup-q');
        var list = $('tr-setup-assets');
        var details = $('tr-setup-details');
        var done = $('tr-setup-done');
        var chosen = null;
        var startUrl = null;
        var seq = 0;

        (data.departments || []).forEach(function (d) {
            $('tr-setup-dept').appendChild(el('option', { value: String(d.id), text: d.name }));
        });

        function typeIcon(t) {
            return { 'Tablet': 'fa-tablet-alt', 'Phone': 'fa-mobile-alt', 'Mobile Phone': 'fa-mobile-alt', 'Laptop': 'fa-laptop', 'Desktop': 'fa-desktop' }[t] || 'fa-tablet-alt';
        }

        function load() {
            var my = ++seq;
            list.setAttribute('aria-busy', 'true');
            api.get('kiosk_asset_options', { q: q.value.trim() }).then(function (res) {
                if (my !== seq) { return; }
                list.removeAttribute('aria-busy');
                while (list.firstChild) { list.removeChild(list.firstChild); }
                var assets = (res && res.assets) || [];
                if (!assets.length) {
                    list.appendChild(el('div', { class: 'list-group-item text-secondary', text: 'No matching device assets. Add the device under Assets first (type Tablet, Phone, Mobile Phone, Laptop or Desktop).' }));
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

        function choose(a, item) {
            chosen = a;
            Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('active'); c.setAttribute('aria-selected', 'false'); });
            item.classList.add('active');
            item.setAttribute('aria-selected', 'true');
            details.hidden = false;
            var mode = $('tr-setup-mode');
            while (mode.firstChild) { mode.removeChild(mode.firstChild); }
            var personal = a.assigned_contact && a.assigned_contact.eligible;
            mode.appendChild(el('i', { class: 'fas ' + (personal ? 'fa-user' : 'fa-users') + ' mt-1', 'aria-hidden': 'true' }));
            mode.appendChild(el('span', { text: personal
                ? 'Personal device: it opens straight to ' + a.assigned_contact.name + '’s PIN. If the asset is re-assigned later, the device stops working until it gets a new start URL.'
                : 'Shared device: people find their name, then enter their PIN.' }));
            var rep = $('tr-setup-replace');
            if (a.active_kiosk) {
                rep.textContent = 'This asset is already enrolled as “' + a.active_kiosk.label + '”. Continuing moves it here and the old setup stops working.';
                rep.classList.remove('d-none');
            } else {
                rep.textContent = '';
                rep.classList.add('d-none');
            }
            var label = $('tr-setup-label');
            if (!label.value) { label.value = a.name; }
            if (a.client_id) { $('tr-setup-dept').value = String(a.client_id); }
            label.focus();
        }

        var debounced = ui.debounce(load, 250);
        q.addEventListener('input', debounced);

        $('tr-setup-go').addEventListener('click', function () {
            if (!chosen) { return; }
            var btn = this;
            var label = $('tr-setup-label');
            label.classList.remove('is-invalid');
            btn.disabled = true;
            api.post('kiosk_enroll_here', {
                asset_id: chosen.asset_id, label: label.value.trim(),
                default_client_id: parseInt($('tr-setup-dept').value, 10) || 0, replace: !!chosen.active_kiosk
            }).then(function (res) {
                startUrl = res.start_url;
                $('tr-setup-form-card').hidden = true;
                details.hidden = true;
                done.hidden = false;
                $('tr-setup-done-title').textContent = 'Enrolled as ' + res.label;
                $('tr-setup-done-mode').textContent = res.personal ? 'Opens straight to ' + res.personal.name + '.' : 'Shared device: people find their name, then enter their PIN.';
                $('tr-setup-url').value = startUrl;
                $('tr-setup-open').focus();
            }, function (err) {
                btn.disabled = false;
                if (err.fields && err.fields.label) {
                    label.classList.add('is-invalid');
                    var fb = document.querySelector('[data-field="label"]');
                    if (fb) { fb.textContent = err.fields.label; }
                    return;
                }
                if (err.code === 'asset_enrolled') {
                    chosen.active_kiosk = { id: err.data && err.data.kiosk_id, label: (err.data && err.data.label) || '' };
                    choose(chosen, list.querySelector('.active') || list.firstChild);
                    ui.toast('That asset is already enrolled. Press the button again to move it here.', { type: 'warning' });
                    return;
                }
                ui.toast(err.message || 'Could not enroll this device.', { type: 'error' });
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
            if (!startUrl) { return; }
            var btn = this;
            btn.disabled = true;
            var go = function () {
                var u = startUrl;
                startUrl = null;
                $('tr-setup-url').value = '';
                window.location.replace(u);
            };
            // The sign-out is not optional (A21): the device must not keep an ITFlow session.
            fetch('/agent/post.php?logout', { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' }).then(go, go);
        });

        load();
    });
}());
