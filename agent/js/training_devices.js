/*
 * Training › Devices & PINs (P3 spec §5.8, lane K2) and the setup-slips print page.
 * Devices: kiosk_list / kiosk_revoke / kiosk_reissue / kiosk_clear_cooldown / pin_clear_pause / kiosk_enroll_code.
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
        function when(iso) {
            if (!iso) { return ''; }
            var d = new Date(iso);
            return isNaN(d.getTime()) ? '' : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
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
        if (!$('tr-devices-root')) { return; }

        // keep ?tab= in the URL so a reload stays on the tab
        Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
            b.addEventListener('shown.bs.tab', function () {
                try { history.replaceState(null, '', '/agent/training_devices.php?tab=' + b.getAttribute('data-tab')); } catch (e) { /* ignore */ }
            });
        });

        // ============================================================== devices
        var listHost = $('tr-device-list');
        var TYPE_ICON = { 'Tablet': 'fa-tablet-alt', 'Phone': 'fa-mobile-alt', 'Mobile Phone': 'fa-mobile-alt', 'Laptop': 'fa-laptop', 'Desktop': 'fa-desktop' };
        var PROBLEM = {
            asset_missing: 'The asset was deleted', asset_archived: 'The asset is archived', asset_type: 'The asset is no longer a device type',
            assignment_changed: 'Assignment changed — re-enroll', owner_ineligible: 'Owner can no longer train — re-enroll'
        };

        function statusChip(k) {
            if (k.status === 'revoked') { return el('span', { class: 'badge bg-secondary-lt' }, [icon('fa-ban', 'me-1'), 'Revoked']); }
            if (k.status === 'pending') { return el('span', { class: 'badge bg-info-lt' }, [icon('fa-hourglass-half', 'me-1'), 'Waiting for setup code']); }
            if (k.problem) { return el('span', { class: 'badge bg-danger-lt' }, [icon('fa-exclamation-triangle', 'me-1'), PROBLEM[k.problem] || 'Not working']); }
            return el('span', { class: 'badge bg-success-lt' }, [icon('fa-check-circle', 'me-1'), 'Active']);
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

        function deviceCard(k) {
            var body = el('div', { class: 'card-body' });
            var head = el('div', { class: 'd-flex align-items-start gap-3' }, [
                el('span', { class: 'avatar avatar-md bg-primary-lt' }, [icon(TYPE_ICON[k.asset.type] || 'fa-tablet-alt', 'fa-lg')]),
                el('div', { class: 'flex-grow-1 min-w-0' }, [
                    el('h3', { class: 'card-title mb-1 text-truncate', text: k.label }),
                    el('div', { class: 'text-secondary small', text: [k.asset.type, k.asset.name, k.asset.serial ? 'SN ' + k.asset.serial : ''].filter(Boolean).join(' · ') + (k.asset.archived ? ' (archived)' : '') })
                ]),
                statusChip(k)
            ]);
            body.appendChild(head);
            var facts = el('dl', { class: 'row small mt-3 mb-0' });
            function fact(label, value, extra) {
                facts.appendChild(el('dt', { class: 'col-5 text-secondary fw-normal', text: label }));
                facts.appendChild(el('dd', { class: 'col-7 mb-1' + (extra ? ' ' + extra : '') }, value));
            }
            if (k.personal) { fact('Personal', [icon('fa-user', 'me-1'), k.personal.name]); } else if (k.status !== 'revoked') { fact('Mode', 'Shared (name search)'); }
            if (k.status === 'active') {
                fact('Last seen', ago(k.last_seen));
                if (k.ua) { fact('Browser', k.ua); }
                fact('Signed in now', k.active_session ? 'Yes (' + (k.session_role || 'learner') + ')' : 'No');
            }
            if (k.status === 'pending' && k.code_expires_at) { fact('Code expires', when(k.code_expires_at)); }
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
            if (k.status === 'active' && level >= 3) {
                var re = el('button', { type: 'button', class: 'btn btn-sm btn-outline-primary' }, [icon('fa-link', 'me-1'), 'New start URL']);
                re.addEventListener('click', function () {
                    ui.confirmBar(body, { message: 'Make a new start URL for ' + k.label + '? The old one stops working and anyone signed in on it is signed out.', confirmLabel: 'Make a new URL' }).then(function (yes) {
                        if (!yes) { return; }
                        api.post('kiosk_reissue', { kiosk_id: k.id }).then(function (res) {
                            loadDevices(function () {
                                var card = listHost.querySelector('[data-kiosk="' + k.id + '"] .card-body');
                                if (card) { showUrl(card, res, 'New start URL for ' + res.label + (res.personal ? ' (opens to ' + res.personal.name + ')' : ' (shared)')); }
                            });
                        }, fail);
                    });
                });
                actions.appendChild(re);
            }
            if (k.status !== 'revoked' && level >= 3) {
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
                        el('p', { class: 'text-secondary', text: 'Open /kiosk/ on the iPad or PC and tap "Set up this device (admin)", or use "Set up a device" here on that device.' })
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
