/*
 * Training › Devices & PINs (P3 spec §5.8, lane K2) and the setup-slips print page.
 * Devices: kiosk_list / kiosk_revoke / kiosk_reissue / kiosk_clear_cooldown / pin_clear_pause / kiosk_enroll_code /
 * kiosk_set_expiry (2.6.94: "Change end time" on a temporary device - showing the current end and a live preview of
 * the new one, flagged when it is EARLIER - and "Set end time" on a permanent one, both with the setup presets; "End
 * now"; "Remove now" for one whose time is up but that nobody has touched since). Unlisted devices (not in Assets) show
 * "Not in Assets · device #<id>". Every time on the page is in the app's time zone with its zone name (page data
 * `timezone`; training_device_time.js), not the browser's.
 * People & PINs: pin_people / pin_unlock / pin_odoo_unblock / pin_slips_issue / pin_sources_refresh.
 * Slips page: pin_slips_clear. Every string reaches the DOM through TrainingUi.el / textContent.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var ui = window.TrainingUi;
        var api = window.TrainingApi;
        if (!ui || !api) { return; }
        var el = ui.el;
        function $(id) { return document.getElementById(id); }
        function clear(n) { while (n && n.firstChild) { n.removeChild(n.firstChild); } }
        function icon(name, extra) { return el('i', { class: 'fas ' + name + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
        var tz;   // the app's time zone (page data), set below; the slips page never formats a time
        function when(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            try {
                return d.toLocaleString([], { timeZone: tz, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
            } catch (e) {
                return d.toLocaleString();
            }
        }
        function ago(iso) {
            if (!iso) { return 'Never'; }
            var s = (Date.now() - new Date(iso).getTime()) / 1000;
            if (isNaN(s)) { return ''; }
            if (s < 90) { return 'Just now'; }
            if (s < 3600) { return Math.round(s / 60) + ' min ago'; }
            if (s < 86400) { return Math.round(s / 3600) + ' h ago'; }
            return when(iso);
        }

        /** Inline reason form inside `host`: resolves the trimmed reason (>= 5 chars) or null. */
        function askReason(host, message, okLabel, danger) {
            return new Promise(function (resolve) {
                var old = host.querySelector(':scope > .tr-reason');
                if (old) { old.parentNode.removeChild(old); }
                var input = el('input', { type: 'text', class: 'form-control form-control-sm', maxlength: '255', placeholder: 'Reason (required)', 'aria-label': 'Reason' });
                var ok = el('button', { type: 'button', class: 'btn btn-sm ' + (danger ? 'btn-danger' : 'btn-primary'), text: okLabel });
                var cancel = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
                var box = el('div', { class: 'tr-reason alert ' + (danger ? 'alert-danger' : 'alert-warning') + ' mt-2 mb-0 p-2', role: 'group' }, [
                    el('div', { class: 'small mb-1', text: message }),
                    el('div', { class: 'd-flex gap-2' }, [input, cancel, ok])
                ]);
                function done(v) { if (box.parentNode) { box.parentNode.removeChild(box); } resolve(v); }
                ok.addEventListener('click', function () {
                    var v = input.value.trim();
                    if (v.length < 5) { input.classList.add('is-invalid'); input.focus(); return; }
                    done(v);
                });
                cancel.addEventListener('click', function () { done(null); });
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } if (e.key === 'Escape') { done(null); } });
                host.appendChild(box);
                input.focus();
            });
        }

        function fail(err) { ui.toast((err && err.message) || 'Something went wrong.', { type: 'error' }); }

        // ============================================================== slips page
        var slips = $('tr-slips');
        if (slips) {
            var clearBtn = $('tr-slips-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    clearBtn.disabled = true;
                    api.post('pin_slips_clear', { t: slips.getAttribute('data-token') || '' }).then(function () {
                        window.location.replace('/agent/training_pin_slips.php?cleared=1');
                    }, function (err) { clearBtn.disabled = false; fail(err); });
                });
            }
            return;
        }

        var data = ui.readJson('tr-page-data');
        var level = data.kiosk_level || 0;
        tz = data.timezone || undefined;
        if (!$('tr-devices-root')) { return; }
        var T = window.TrainingDeviceTime.create(tz, data.max_days || 30);
        /** A device expiry in the app's time zone, with its zone ("Thu, Sep 25, 11:59 PM CDT"). */
        function whenTz(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return ''; }
            try {
                return d.toLocaleString([], { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
            } catch (e) {
                return when(iso);
            }
        }

        // keep ?tab= in the URL so a reload stays on the tab
        Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
            b.addEventListener('shown.bs.tab', function () {
                try { history.replaceState(null, '', '/agent/training_devices.php?tab=' + b.getAttribute('data-tab')); } catch (e) { /* ignore */ }
            });
        });

        // ============================================================== devices
        var listHost = $('tr-device-list');
        var TYPE_ICON = { 'Tablet': 'fa-tablet-alt', 'Phone': 'fa-mobile-alt', 'Mobile Phone': 'fa-mobile-alt', 'Laptop': 'fa-laptop', 'Desktop': 'fa-desktop' };

        // Revoked devices only: select and remove them from the list (kiosk_hide - not a delete).
        var removePicked = {};
        var bulkBar = $('tr-device-bulk-bar');
        var bulkCount = $('tr-device-bulk-count');
        var bulkRemoveBtn = $('tr-device-bulk-remove');
        function updateBulkBar() {
            var ids = Object.keys(removePicked);
            if (bulkBar) { bulkBar.hidden = ids.length === 0; }
            if (bulkCount) { bulkCount.textContent = ids.length === 1 ? '1 revoked device selected' : ids.length + ' revoked devices selected'; }
        }
        if (bulkRemoveBtn) {
            bulkRemoveBtn.addEventListener('click', function () {
                var ids = Object.keys(removePicked).map(Number);
                if (!ids.length) { return; }
                var label = ids.length === 1 ? 'this device' : ids.length + ' devices';
                ui.confirmBar(bulkBar, { message: 'Remove ' + label + ' from the list? This only removes them here - it does not affect any training records.', confirmLabel: 'Remove', danger: true }).then(function (yes) {
                    if (!yes) { return; }
                    bulkRemoveBtn.disabled = true;
                    api.post('kiosk_hide', { kiosk_ids: ids }).then(function (res) {
                        bulkRemoveBtn.disabled = false;
                        removePicked = {};
                        updateBulkBar();
                        ui.toast(res.hidden === 1 ? '1 device removed.' : res.hidden + ' devices removed.');
                        loadDevices();
                    }, function (err) { bulkRemoveBtn.disabled = false; fail(err); });
                });
            });
        }
        var bulkClearBtn = $('tr-device-bulk-clear');
        if (bulkClearBtn) { bulkClearBtn.addEventListener('click', function () { removePicked = {}; updateBulkBar(); loadDevices(); }); }
        var PROBLEM = {
            asset_missing: 'The asset was deleted', asset_archived: 'The asset is archived', asset_type: 'The asset is no longer a device type',
            assignment_changed: 'Assignment changed — re-enroll', owner_ineligible: 'Owner can no longer train — re-enroll',
            expired: 'Expired'
        };
        /** An unlisted device has no asset type: guess an icon from the browser it last used. */
        function deviceIcon(k) {
            if (k.asset) { return TYPE_ICON[k.asset.type] || 'fa-tablet-alt'; }
            var ua = k.ua || '';
            if (/^(iPad|Android)/.test(ua)) { return 'fa-tablet-alt'; }
            if (/^iPhone/.test(ua)) { return 'fa-mobile-alt'; }
            if (/^(Windows|Mac|Linux|Chromebook)/.test(ua)) { return 'fa-laptop'; }
            return 'fa-tablet-alt';
        }
        var EXPIRY_PRESETS = [['4h', '4 hours from now'], ['8h', '8 hours from now'], ['24h', '24 hours from now'], ['today', 'Until the end of today'],
                              ['until', 'Until a date and time…'], ['keep', 'Keep until I remove it']];

        function statusChip(k) {
            if (k.status === 'revoked') { return el('span', { class: 'badge bg-secondary-lt' }, [icon('fa-ban', 'me-1'), 'Revoked']); }
            if (k.expired || k.problem === 'expired') { return el('span', { class: 'badge bg-danger-lt' }, [icon('fa-hourglass-end', 'me-1'), 'Expired']); }
            if (k.status === 'pending') { return el('span', { class: 'badge bg-info-lt' }, [icon('fa-hourglass-half', 'me-1'), 'Waiting for setup code']); }
            if (k.problem) { return el('span', { class: 'badge bg-danger-lt' }, [icon('fa-exclamation-triangle', 'me-1'), PROBLEM[k.problem] || 'Not working']); }
            return el('span', { class: 'badge bg-success-lt' }, [icon('fa-check-circle', 'me-1'), 'Active']);
        }

        /** "40 min" / "2 h 5 min" until an ISO time. */
        function leftText(ms) {
            var m = Math.max(1, Math.round(ms / 60000));
            return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '');
        }

        function showUrl(host, res, title) {
            var input = el('input', { type: 'text', class: 'form-control form-control-sm font-monospace', readonly: true, value: res.start_url || '' });
            var copy = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary' }, [icon('fa-copy', 'me-1'), 'Copy']);
            copy.addEventListener('click', function () {
                input.select();
                if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(function () { ui.toast('Copied.'); }); } else { try { document.execCommand('copy'); } catch (e) { /* ignore */ } }
            });
            host.appendChild(el('div', { class: 'alert alert-success mt-2 mb-0 p-2' }, [
                el('div', { class: 'small fw-bold mb-1', text: title }),
                el('div', { class: 'input-group input-group-sm' }, [input, copy]),
                el('div', { class: 'small text-secondary mt-1', text: 'Shown only once. Open it on the device (or put it in the Edge kiosk settings). The old start URL no longer works.' })
            ]));
        }

        /**
         * Inline "Change end time" / "Set end time" form inside `host`: resolves {expires, expires_until?} or null.
         * It shows the device's current end ("Now") and a live preview of the new one ("New"), in the app's time zone;
         * an earlier new end is flagged and Save becomes "Shorten it". A permanent device gets no "Keep" choice.
         */
        function askExpiry(host, k) {
            return new Promise(function (resolve) {
                var old = host.querySelector(':scope > .tr-expiry');
                if (old) { old.parentNode.removeChild(old); }
                var cur = k.temporary && k.expires_at ? new Date(k.expires_at) : null;
                var presets = EXPIRY_PRESETS.filter(function (p) { return k.temporary || p[0] !== 'keep'; });
                var sel = el('select', { class: 'form-select form-select-sm', 'aria-label': 'New end time', style: { flex: '1 1 11rem', width: 'auto' } },
                    presets.map(function (p) { return el('option', { value: p[0], text: p[1] }); }));
                var until = el('input', { type: 'datetime-local', class: 'form-control form-control-sm', step: '60', 'aria-label': 'Date and time (' + (tz || 'local') + ')', hidden: true, style: { flex: '1 1 11rem', width: 'auto' } });
                var ok = el('button', { type: 'button', class: 'btn btn-sm btn-primary', text: 'Save' });
                var cancel = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
                var nowLine = el('div', { class: 'small', dataset: { expiryNow: '1' }, text: 'Now: ' + (cur ? 'stops working ' + T.fmt(cur, true) : 'kept until you remove it') });
                var newLine = el('div', { class: 'small fw-semibold', dataset: { expiryNew: '1' }, 'aria-live': 'polite' });
                var box = el('div', { class: 'tr-expiry alert alert-info mt-2 mb-0 p-2', role: 'group', 'aria-label': (cur ? 'Change when ' : 'Set when ') + k.label + ' stops working' }, [
                    el('div', { class: 'small mb-1 fw-semibold', text: (cur ? 'Change when ' : 'Set when ') + k.label + ' stops working (counted from now)' }),
                    nowLine,
                    el('div', { class: 'd-flex flex-wrap gap-2 my-2' }, [sel, until, cancel, ok]),
                    newLine
                ]);
                // Start on the shortest choice that does not cut the current time short.
                var start = 'today';
                if (cur) {
                    start = null;
                    ['4h', '8h', '24h'].forEach(function (p) { if (!start && T.endFor(p).date > cur) { start = p; } });
                    if (!start) {
                        start = 'until';
                        until.value = T.wall(new Date(Math.min(cur.getTime() + 86400000, Date.now() + T.maxDays * 86400000 - 3600000)));
                    }
                } else if (T.endFor('today').error) {
                    start = '4h';
                }
                sel.value = start;
                function refresh() {
                    until.hidden = sel.value !== 'until';
                    if (!until.hidden) {
                        T.untilBounds(until);
                        if (!until.value) { until.value = T.wall(new Date(Date.now() + 2 * 3600000)); }
                    }
                    var r = T.endFor(sel.value, until.value);
                    var earlier = !r.error && cur && (r.date === null ? false : r.date < cur);
                    newLine.className = 'small fw-semibold ' + (r.error ? 'text-danger' : (earlier ? 'text-warning' : ''));
                    newLine.textContent = r.error ? r.error
                        : 'New: ' + (r.date ? 'stops working ' + T.fmt(r.date, true) : 'kept until you remove it') + (earlier ? ' — earlier than now' : '');
                    ok.disabled = !!r.error;
                    ok.textContent = earlier ? 'Shorten it' : 'Save';
                    ok.className = 'btn btn-sm ' + (earlier ? 'btn-warning' : 'btn-primary');
                    until.classList.toggle('is-invalid', sel.value === 'until' && !!r.error);
                }
                function done(v) { if (box.parentNode) { box.parentNode.removeChild(box); } clearInterval(tick); resolve(v); }
                var tick = setInterval(function () { if (!box.parentNode) { clearInterval(tick); return; } refresh(); }, 30000);
                sel.addEventListener('change', function () { refresh(); if (!until.hidden) { until.focus(); } });
                until.addEventListener('input', refresh);
                ok.addEventListener('click', function () {
                    refresh();
                    if (ok.disabled) { return; }
                    done(sel.value === 'until' ? { expires: 'until', expires_until: until.value } : { expires: sel.value });
                });
                cancel.addEventListener('click', function () { done(null); });
                host.appendChild(box);
                refresh();
                sel.focus();
            });
        }

        function deviceCard(k) {
            var body = el('div', { class: 'card-body' });
            var same = function (a, b) { return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase(); };
            var sub = k.asset
                ? [k.asset.type, same(k.asset.name, k.label) ? '' : k.asset.name, k.asset.serial ? 'SN ' + k.asset.serial : ''].filter(Boolean).join(' · ') + (k.asset.archived ? ' (archived)' : '')
                : 'Not in Assets · device #' + k.id;   // two unlisted devices may share a name
            var live = k.status !== 'revoked';
            var chips = el('div', { class: 'd-flex flex-column align-items-end gap-1 flex-shrink-0' }, [
                statusChip(k),
                k.temporary && live && !k.expired ? el('span', { class: 'badge bg-warning-lt', dataset: { kioskTemp: '1' } }, [icon('fa-hourglass-half', 'me-1'), 'Temporary']) : null
            ]);
            var pick = null;
            if (k.status === 'revoked' && level >= 3) {
                var pickCb = el('input', { type: 'checkbox', class: 'form-check-input mt-1', 'aria-label': 'Select ' + k.label });
                pickCb.checked = !!removePicked[k.id];
                pickCb.addEventListener('change', function () { if (pickCb.checked) { removePicked[k.id] = true; } else { delete removePicked[k.id]; } updateBulkBar(); });
                pick = el('div', { class: 'form-check flex-shrink-0' }, [pickCb]);
            }
            var head = el('div', { class: 'd-flex align-items-start gap-3' }, [
                pick,
                el('span', { class: 'avatar avatar-md bg-primary-lt flex-shrink-0' }, [icon(deviceIcon(k), 'fa-lg')]),
                el('div', { class: 'flex-grow-1 min-w-0' }, [
                    el('h3', { class: 'card-title mb-1 text-break', title: k.label, text: k.label }),
                    el('div', { class: 'text-secondary small text-break', text: sub })
                ]),
                chips
            ]);
            body.appendChild(head);
            if (k.temporary && live) {
                var leftMs = k.expires_at ? new Date(k.expires_at).getTime() - Date.now() : 0;
                var soon = !k.expired && leftMs < 3600000;
                body.appendChild(el('div', { class: 'small mt-2 d-flex align-items-center gap-1 ' + (k.expired ? 'text-danger' : (soon ? 'text-warning fw-semibold' : 'text-secondary')), dataset: { kioskExpiry: '1' } }, [
                    icon(k.expired ? 'fa-hourglass-end' : 'fa-hourglass-half', k.expired || soon ? '' : 'text-warning'),
                    el('span', { text: k.expired ? 'Temporary · expired ' + whenTz(k.expires_at)
                        : 'Temporary · expires ' + whenTz(k.expires_at) + (soon ? ' (in ' + leftText(leftMs) + ')' : '') })
                ]));
            }
            if (k.expired && live) {
                body.appendChild(el('div', { class: 'small mt-1 text-secondary', dataset: { kioskExpiredHint: '1' },
                    text: 'Its time is up: it stops at its next tap or within 10 minutes. To use it again, set it up again on the device.' }));
            }
            var facts = el('dl', { class: 'row small mt-3 mb-0' });
            function fact(label, value, extra) {
                facts.appendChild(el('dt', { class: 'col-5 text-secondary fw-normal', text: label }));
                facts.appendChild(el('dd', { class: 'col-7 mb-1' + (extra ? ' ' + extra : '') }, value));
            }
            if (k.personal) { fact('Personal', [icon('fa-user', 'me-1'), k.personal.name]); }
            else if (k.status !== 'revoked') { fact('Mode', k.forced_shared ? 'Shared (set to ignore the asset\'s assignment)' : 'Shared (name search)'); }
            if (k.status === 'active') {
                fact('Last seen', ago(k.last_seen));
                if (k.ua) { fact('Browser', k.ua); }
                fact('Signed in now', k.active_session ? 'Yes (' + (k.session_role || 'learner') + ')' : 'No');
            }
            if (k.status === 'pending' && k.code_expires_at && !k.expired) { fact('Code expires', when(k.code_expires_at)); }
            if (k.enrolled_at) { fact('Enrolled', when(k.enrolled_at) + (k.enrolled_by ? ' by ' + k.enrolled_by : '')); }
            if (k.default_department) { fact('Department', k.default_department); }
            if (k.status === 'revoked') { fact('Revoked', when(k.revoked_at) + (k.revoke_reason ? ' — ' + k.revoke_reason : '')); }
            body.appendChild(facts);
            if (k.cooldown_until) {
                body.appendChild(el('div', { class: 'alert alert-warning mt-2 mb-0 p-2 small d-flex align-items-center gap-2' }, [
                    icon('fa-pause-circle'), el('span', { text: 'Sign-in paused until ' + when(k.cooldown_until) + ' after repeated wrong PINs' + (k.cooldown_reason && k.cooldown_reason !== 'fail_cap' ? ' (24-hour limit)' : '') + '.' })
                ]));
            }
            var actions = el('div', { class: 'd-flex flex-wrap gap-2 mt-3' });
            if (k.cooldown_until && level >= 2) {
                var cc = el('button', { type: 'button', class: 'btn btn-sm btn-warning' }, [icon('fa-play', 'me-1'), 'Clear cooldown']);
                cc.addEventListener('click', function () {
                    askReason(body, 'Why clear the cooldown on ' + k.label + '?', 'Clear cooldown', false).then(function (r) {
                        if (!r) { return; }
                        api.post('kiosk_clear_cooldown', { kiosk_id: k.id, reason: r }).then(function () { ui.toast('Cooldown cleared.'); loadDevices(); }, fail);
                    });
                });
                actions.appendChild(cc);
            }
            if (k.status === 'active' && !k.expired && level >= 3) {
                // Temporary: change its end time; permanent: give it one (a borrowed device kept by mistake).
                var ex = el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary' }, [icon('fa-clock', 'me-1'), k.temporary ? 'Change end time' : 'Set end time']);
                ex.addEventListener('click', function () {
                    askExpiry(body, k).then(function (v) {
                        if (!v) { return; }
                        api.post('kiosk_set_expiry', { kiosk_id: k.id, expires: v.expires, expires_until: v.expires_until }).then(function (res) {
                            ui.toast(res.device_expires_at ? k.label + ' now stops working ' + whenTz(res.device_expires_at) + '.' : k.label + ' is kept until you remove it.');
                            loadDevices();
                        }, function (err) { fail({ message: (err && err.fields && (err.fields.expires_until || err.fields.expires)) || (err && err.message) }); });
                    });
                });
                actions.appendChild(ex);
            }
            if (k.status === 'active' && k.temporary && !k.expired && level >= 3) {
                var en = el('button', { type: 'button', class: 'btn btn-sm btn-outline-warning' }, [icon('fa-stop-circle', 'me-1'), 'End now']);
                en.addEventListener('click', function () {
                    ui.confirmBar(body, { message: 'End ' + k.label + ' now? It stops working at once and anyone signed in is signed out.', confirmLabel: 'End now', danger: true }).then(function (yes) {
                        if (!yes) { return; }
                        api.post('kiosk_set_expiry', { kiosk_id: k.id, expires: 'now' }).then(function () { ui.toast(k.label + ' ended.'); loadDevices(); }, fail);
                    });
                });
                actions.appendChild(en);
            }
            if (k.status === 'active' && !k.expired && level >= 3) {
                var re = el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary' }, [icon('fa-link', 'me-1'), 'New start URL']);
                re.addEventListener('click', function () {
                    ui.confirmBar(body, { message: 'Make a new start URL for ' + k.label + '? The old one stops working and anyone signed in on it is signed out.', confirmLabel: 'Make a new URL' }).then(function (yes) {
                        if (!yes) { return; }
                        api.post('kiosk_reissue', { kiosk_id: k.id }).then(function (res) {
                            loadDevices(function () {
                                var card = listHost.querySelector('[data-kiosk="' + k.id + '"] .card-body');
                                if (card) { showUrl(card, res, 'New start URL for ' + res.label + (res.personal ? ' (opens to ' + res.personal.name + ')' : ' (shared)') + (res.device_expires_at ? ' · stops working ' + whenTz(res.device_expires_at) : '')); }
                            });
                        }, fail);
                    });
                });
                actions.appendChild(re);
            }
            if (k.status === 'active' && !k.expired && !k.unlisted && level >= 3) {
                var modeBtn = k.forced_shared
                    ? el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary' }, [icon('fa-user-check', 'me-1'), 'Follow the asset instead'])
                    : el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary' }, [icon('fa-users', 'me-1'), 'Make shared']);
                modeBtn.addEventListener('click', function () {
                    var toForced = !k.forced_shared;
                    var msg = toForced
                        ? 'Make ' + k.label + ' shared? It will use name search for everyone, no matter who this asset is assigned to.'
                        : 'Set ' + k.label + ' back to following its asset\'s assignment? It may become personal again, or stay shared, depending on who the asset is assigned to right now.';
                    ui.confirmBar(body, { message: msg, confirmLabel: toForced ? 'Make shared' : 'Follow the asset' }).then(function (yes) {
                        if (!yes) { return; }
                        api.post('kiosk_set_mode', { kiosk_id: k.id, forced_shared: toForced }).then(function () {
                            ui.toast(toForced ? k.label + ' is now shared.' : k.label + ' now follows its asset\'s assignment.');
                            loadDevices();
                        }, fail);
                    });
                });
                actions.appendChild(modeBtn);
            }
            if (k.expired && live && level >= 3) {
                // Already dead (nobody has touched it since its time ran out): just clean it up, no reason needed.
                var rm = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary ms-auto' }, [icon('fa-trash-alt', 'me-1'), 'Remove now']);
                rm.addEventListener('click', function () {
                    rm.disabled = true;
                    api.post('kiosk_set_expiry', { kiosk_id: k.id, expires: 'now' }).then(function () { ui.toast(k.label + ' removed.'); loadDevices(); },
                        function (err) { rm.disabled = false; fail(err); loadDevices(); });
                });
                actions.appendChild(rm);
            } else if (live && level >= 3) {
                var rv = el('button', { type: 'button', class: 'btn btn-sm btn-outline-danger ms-auto' }, [icon('fa-ban', 'me-1'), 'Revoke']);
                rv.addEventListener('click', function () {
                    askReason(body, 'Revoke ' + k.label + '? It stops working at once and anyone signed in is signed out.', 'Revoke', true).then(function (r) {
                        if (!r) { return; }
                        api.post('kiosk_revoke', { kiosk_id: k.id, reason: r }).then(function () { ui.toast('Device revoked.'); loadDevices(); }, fail);
                    });
                });
                actions.appendChild(rv);
            }
            if (actions.firstChild) { body.appendChild(actions); }
            return el('div', { class: 'col-md-6 col-xl-4', dataset: { kiosk: String(k.id) } }, [el('div', { class: 'card h-100' + (k.status === 'revoked' ? ' opacity-75' : '') }, [body])]);
        }

        function pauseBanner(res) {
            var host = $('tr-pause-banner');
            clear(host);
            if (!res.pause_until) { return; }
            var b = el('div', { class: 'alert alert-danger d-flex flex-wrap align-items-center gap-2', role: 'status' }, [
                icon('fa-pause-circle'), el('span', { class: 'me-auto', text: 'Sign-in paused on every device until ' + when(res.pause_until) + ' after many wrong PINs.' })
            ]);
            if (level >= 2) {
                var btn = el('button', { type: 'button', class: 'btn btn-sm btn-light', text: 'Clear pause' });
                btn.addEventListener('click', function () {
                    askReason(b, 'Why clear the system-wide pause?', 'Clear pause', false).then(function (r) {
                        if (!r) { return; }
                        api.post('pin_clear_pause', { reason: r }).then(function () { ui.toast('Pause cleared.'); loadDevices(); }, fail);
                    });
                });
                b.appendChild(btn);
            }
            host.appendChild(b);
        }

        function loadDevices(after) {
            listHost.setAttribute('aria-busy', 'true');
            api.get('kiosk_list').then(function (res) {
                listHost.removeAttribute('aria-busy');
                clear(listHost);
                pauseBanner(res);
                var ks = res.kiosks || [];
                if (!ks.length) {
                    listHost.appendChild(el('div', { class: 'col-12' }, [el('div', { class: 'card' }, [el('div', { class: 'card-body text-center py-5' }, [
                        el('i', { class: 'fas fa-tablet-alt fa-2x text-secondary mb-3', 'aria-hidden': 'true' }),
                        el('h2', { class: 'h3', text: 'No training devices yet' }),
                        el('p', { class: 'text-secondary', text: 'Open /kiosk/ on the iPad or PC and tap "Set up this device (admin)", or use "Set up a device" here on that device - or "Get setup codes" above to issue several at once without touching each device.' })
                    ])])]));
                } else {
                    ks.forEach(function (k) { listHost.appendChild(deviceCard(k)); });
                }
                if (typeof after === 'function') { after(); }
            }, function (err) { listHost.removeAttribute('aria-busy'); fail(err); });
        }

        // ============================================================== people & PINs
        var body = $('tr-people-body');
        var picked = {};
        (data.departments || []).forEach(function (d) { $('tr-people-dept').appendChild(el('option', { value: String(d.id), text: d.name })); });

        function yes(v, label) { return v ? el('span', { class: 'badge bg-warning-lt', text: label || 'Yes' }) : el('span', { class: 'text-secondary', text: '—' }); }

        function updatePicked() {
            var n = Object.keys(picked).length;
            var c = $('tr-people-count');
            if (c) { c.textContent = String(n); }
            var b = $('tr-people-slips');
            if (b) { b.disabled = n === 0; }
        }

        function personRow(p) {
            var tr = el('tr');
            if (level >= 2) {
                var cb = el('input', { type: 'checkbox', class: 'form-check-input', 'aria-label': 'Select ' + p.name });
                cb.checked = !!picked[p.contact_id];
                cb.addEventListener('change', function () { if (cb.checked) { picked[p.contact_id] = p; } else { delete picked[p.contact_id]; } updatePicked(); });
                tr.appendChild(el('td', {}, [cb]));
            }
            tr.appendChild(el('td', {}, [el('div', { class: 'fw-semibold', text: p.name }), el('div', { class: 'small text-secondary', text: p.dept })]));
            tr.appendChild(el('td', {}, [el('span', { class: 'badge ' + (p.source === 'odoo' ? 'bg-azure-lt' : 'bg-secondary-lt'), text: p.source === 'odoo' ? 'Odoo' : 'Training' }),
                p.pinned ? el('span', { class: 'badge bg-secondary-lt ms-1', title: 'Kept on a training PIN', text: 'pinned' }) : null]));
            tr.appendChild(el('td', {}, [p.has_local_pin ? el('span', { class: 'text-success' }, [icon('fa-check', 'me-1'), 'Set']) : (p.code_pending ? el('span', { class: 'text-info' }, [icon('fa-receipt', 'me-1'), 'Slip issued']) : el('span', { class: 'text-secondary', text: 'Not set' }))]));
            tr.appendChild(el('td', { text: String(p.failed || 0) }));
            tr.appendChild(el('td', {}, [p.hard_locked ? el('span', { class: 'badge bg-danger-lt' }, [icon('fa-lock', 'me-1'), 'Locked']) : (p.locked_until ? el('span', { class: 'badge bg-warning-lt' }, [icon('fa-clock', 'me-1'), 'Until ' + when(p.locked_until)]) : yes(false))]));
            tr.appendChild(el('td', {}, [yes(p.odoo_blocked, 'Odoo link')]));
            tr.appendChild(el('td', { class: 'small', text: p.last_success ? when(p.last_success) : 'Never' }));
            tr.appendChild(el('td', {}, [p.is_trainer ? el('span', { class: 'badge bg-purple-lt' }, [icon('fa-user-shield', 'me-1'), 'Trainer']) : yes(false)]));
            var act = el('td', { class: 'text-nowrap' });
            var host = el('div');
            if (level >= 2 && (p.hard_locked || p.locked_until || p.failed > 0)) {
                var un = el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary me-1' }, [icon('fa-unlock', 'me-1'), 'Unlock']);
                un.addEventListener('click', function () {
                    askReason(host, 'Why unlock ' + p.name + '?', 'Unlock', false).then(function (r) {
                        if (!r) { return; }
                        api.post('pin_unlock', { contact_id: p.contact_id, reason: r }).then(function () { ui.toast('PIN unlocked.'); loadPeople(); }, fail);
                    });
                });
                act.appendChild(un);
            }
            if (level >= 2 && p.odoo_blocked) {
                var ub = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary me-1' }, [icon('fa-link', 'me-1'), 'Unblock Odoo link']);
                ub.addEventListener('click', function () {
                    askReason(host, 'Confirm ' + p.name + '’s Odoo link is correct now. Reason:', 'Unblock', false).then(function (r) {
                        if (!r) { return; }
                        api.post('pin_odoo_unblock', { contact_id: p.contact_id, reason: r }).then(function () { ui.toast('Odoo link confirmed.'); loadPeople(); }, fail);
                    });
                });
                act.appendChild(ub);
            }
            act.appendChild(host);
            tr.appendChild(act);
            return tr;
        }

        var peopleSeq = 0;
        function loadPeople() {
            var my = ++peopleSeq;
            var cols = level >= 2 ? 10 : 9;
            api.get('pin_people', { q: $('tr-people-q').value.trim(), client_id: $('tr-people-dept').value, filter: $('tr-people-filter').value }).then(function (res) {
                if (my !== peopleSeq) { return; }
                clear(body);
                var ps = res.people || [];
                if (!ps.length) { body.appendChild(el('tr', {}, [el('td', { colspan: String(cols), class: 'text-center text-secondary py-4', text: 'Nobody matches.' })])); }
                ps.forEach(function (p) { body.appendChild(personRow(p)); });
                $('tr-people-foot').textContent = ps.length + (res.truncated ? '+ people (narrow the search to see the rest)' : ' people');
            }, fail);
        }

        var dl = ui.debounce(loadPeople, 250);
        $('tr-people-q').addEventListener('input', dl);
        $('tr-people-dept').addEventListener('change', loadPeople);
        $('tr-people-filter').addEventListener('change', loadPeople);
        var all = $('tr-people-all');
        if (all) {
            all.addEventListener('change', function () {
                Array.prototype.forEach.call(body.querySelectorAll('input[type=checkbox]'), function (cb) {
                    if (cb.checked !== all.checked) { cb.checked = all.checked; cb.dispatchEvent(new Event('change')); }
                });
            });
        }
        var slipsBtn = $('tr-people-slips');
        if (slipsBtn) {
            slipsBtn.addEventListener('click', function () {
                var ids = Object.keys(picked).map(function (k) { return parseInt(k, 10); });
                if (!ids.length) { return; }
                var sw = $('tr-people-switch');
                slipsBtn.disabled = true;
                api.post('pin_slips_issue', { contact_ids: ids, switch_to_local: !!(sw && sw.checked) }).then(function (res) {
                    picked = {};
                    updatePicked();
                    window.location.assign(res.print_url);
                }, function (err) {
                    slipsBtn.disabled = false;
                    if (err.code === 'odoo_source') {
                        ui.toast(err.message + ' ', { type: 'warning', delay: 9000 });
                        if (sw) { sw.closest('.form-check').classList.remove('d-none'); sw.focus(); }
                        return;
                    }
                    fail(err);
                });
            });
        }
        var refresh = $('tr-people-refresh');
        if (refresh) {
            refresh.addEventListener('click', function () {
                refresh.disabled = true;
                api.post('pin_sources_refresh', {}).then(function (res) {
                    refresh.disabled = false;
                    ui.toast(res.skipped ? 'Odoo-PIN sign-in is off; nothing to refresh.' : ('PIN sources refreshed: ' + res.odoo + ' Odoo, ' + res.local + ' training, ' + res.changed + ' changed, ' + (res.needs_slip || []).length + ' need a slip.'));
                    loadPeople();
                }, function (err) { refresh.disabled = false; fail(err); });
            });
        }

        loadDevices();
        loadPeople();
    });
}());
