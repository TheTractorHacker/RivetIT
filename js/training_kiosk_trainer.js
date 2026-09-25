/*
 * Training kiosk - trainer mode (P3 spec §5.7, lane K5).
 *   kiosk/trainer.php   view 'home'      tiles, open sessions, run-a-session form
 *   kiosk/session.php   view 'session'   trainer: roster, expected, marks, hand around, finish
 *                       view 'checkin'   pass-the-iPad: tap/search name -> sign -> PIN
 *   kiosk/evaluate.php  view 'evaluate'  trainer: course -> person -> checklist -> hand-off
 *                       view 'handoff'   employee signs + PIN, then the evaluator signs + PIN
 * Everything reaches the DOM through Kiosk.ui.el / textContent. No inline handlers (CSP).
 * PINs live only in the keypad closure and the request body; the keypad is cleared after every call.
 */
(function () {
    'use strict';
    var K = window.Kiosk;
    var root = document.getElementById('kt-root');
    if (!K || !root) { return; }
    var el = K.ui.el;
    var t = K.t;
    var icon = K.ui.icon;
    var D = K.data();
    var P = D.page || {};
    var SESSION = D.session || {};
    var EVAL_KEY = 'kx_eval';
    var EVAL_RESULT_KEY = 'kx_eval_result';

    // ------------------------------------------------------------------ helpers
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    function show(children) {
        clear(root);
        (Array.isArray(children) ? children : [children]).forEach(function (c) { if (c) { root.appendChild(c); } });
        window.scrollTo(0, 0);
    }
    function spinner() { return el('div', { class: 'kx-center kt-loading' }, [el('span', { class: 'kx-spin', 'aria-hidden': 'true' }), el('span', { class: 'kx-sr', text: t('shell.loading') })]); }
    function ss(k, v) {
        try {
            if (v === undefined) { return window.sessionStorage.getItem(k); }
            if (v === null) { window.sessionStorage.removeItem(k); } else { window.sessionStorage.setItem(k, v); }
        } catch (e) { /* private mode: the flow still works in this page */ }
        return null;
    }
    var PIN_CODES = { pin_wrong: 'trn.pin_wrong', pin_locked: 'trn.pin_locked', pin_locked_hard: 'trn.pin_locked_hard', setup_needed: 'trn.pin_setup_needed',
        signin_unavailable: 'trn.pin_unavailable', pin_format: 'trn.pin_format', signature_empty: 'trn.sign_first', signature_invalid: 'trn.signature_bad', not_trainer: 'trn.not_trainer' };
    function errText(err) {
        if (!err) { return t('err.server'); }
        var d = err.data || {};
        if (err.code === 'pin_wrong' && typeof d.tries_left === 'number') { return t('trn.pin_wrong_n', { n: d.tries_left }); }
        if (err.code === 'pin_locked') { return t('trn.pin_locked', { minutes: d.minutes || 1 }); }
        if (err.code === 'not_trainer') { return t('trn.not_trainer'); }   // err.not_trainer (lane K2) is worded for sign-in
        if (err.code && K.has('err.' + err.code)) { return t('err.' + err.code); }
        if (PIN_CODES[err.code]) { return t(PIN_CODES[err.code]); }
        return err.message || t('err.server');
    }
    function alertBox(kind, text, title) {
        var ic = { info: 'fa-info-circle', warn: 'fa-exclamation-triangle', bad: 'fa-times-circle', ok: 'fa-check-circle' }[kind] || 'fa-info-circle';
        return el('div', { class: 'kx-alert kx-alert--' + kind, role: kind === 'bad' ? 'alert' : 'status' }, [icon(ic),
            el('div', null, [title ? el('span', { class: 'kx-alert__title', text: title }) : null, el('span', { text: text })])]);
    }
    function btn(label, cls, ic, onClick, attrs) {
        var a = attrs || {};
        a.type = 'button';
        a.class = 'kx-btn ' + (cls || '');
        a.on = { click: onClick };
        return el('button', a, [ic ? icon(ic) : null, el('span', { text: label })]);
    }
    function avatar(initials, size) { return el('span', { class: 'kx-avatar' + (size ? ' kx-avatar--' + size : ''), 'aria-hidden': 'true', text: initials || '?' }); }
    function chip(text, kind, ic) { return el('span', { class: 'kt-chip kt-chip--' + (kind || 'muted') }, [ic ? icon(ic) : null, el('span', { text: text })]); }
    function heading(kicker, title, sub) {
        return el('header', { class: 'kt-head' }, [
            kicker ? el('div', { class: 'kx-kicker', text: kicker }) : null,
            el('h1', { text: title }),
            sub ? el('p', { class: 'kx-lead', text: sub }) : null
        ]);
    }
    function field(label, input, hint) {
        var id = 'kt-f-' + Math.random().toString(36).slice(2, 8);
        input.id = id;
        return el('div', { class: 'kt-field' }, [el('label', { class: 'kt-label', for: id, text: label }), input, hint ? el('p', { class: 'kx-note', text: hint }) : null]);
    }
    function input(attrs) {
        var a = attrs || {};
        a.class = 'kt-input' + (a.class ? ' ' + a.class : '');
        a.autocomplete = 'off';
        a.spellcheck = 'false';
        return el('input', a);
    }
    function today() {
        try { return new Date().toLocaleDateString(K.lang() === 'es' ? 'es-US' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return ''; }
    }
    /** A YYYY-MM-DD day as "Sep 25, 2026" (parsed as a local date, so it never shifts a day). */
    function dayDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) { return iso || ''; }
        try { return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(K.lang() === 'es' ? 'es-US' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return iso; }
    }
    function fmtDate(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return String(iso); }
        try { return d.toLocaleString(K.lang() === 'es' ? 'es-US' : 'en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return String(iso); }
    }
    function home() { location.assign('/kiosk/trainer.php'); }

    /**
     * A PIN dialog: title, optional subtitle, keypad (4-12 digits). submit(pin) returns a promise;
     * a rejection shows its message in the dialog and clears the keypad; a resolution closes it.
     * Resolves with submit's value, or null when cancelled.
     */
    function pinDialog(o) {
        return new Promise(function (resolve) {
            var msg = el('p', { class: 'kt-pin-msg', role: 'alert', hidden: true });
            var padBox = el('div', { class: 'kt-pin-pad' });
            var cancel = btn(t('shell.cancel'), 'kx-btn--ghost kx-btn--xl', null, function () { finish(null); });
            var card = el('div', { class: 'kx-dialog__card kt-pin-card', role: 'dialog', 'aria-modal': 'true', 'aria-label': o.title }, [
                el('h2', { class: 'kx-dialog__title', text: o.title }),
                o.sub ? el('p', { class: 'kx-note', text: o.sub }) : null,
                o.extra || null,
                msg, padBox,
                el('div', { class: 'kx-dialog__actions' }, [cancel])
            ]);
            var overlay = el('div', { class: 'kx-dialog kt-pin-dialog' }, [card]);
            var closed = false;
            var pad = K.ui.keypad(padBox, {
                minLen: 4, maxLen: o.maxLen || 12,
                onSubmit: function (pin) {
                    pad.setBusy(true);
                    msg.hidden = true;
                    Promise.resolve().then(function () { return o.submit(pin); }).then(function (res) {
                        pad.clear();
                        finish(res === undefined ? true : res);
                    }, function (err) {
                        pad.clear();
                        pad.setBusy(false);
                        if (err && err.keepOpen === false) { finish(null); return; }
                        msg.textContent = errText(err);
                        msg.hidden = false;
                        if (err && (err.code === 'pin_locked_hard' || err.code === 'session_closed')) { pad.setBusy(true); }
                    });
                }
            });
            function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); finish(null); } }
            function finish(v) {
                if (closed) { return; }
                closed = true;
                pad.destroy();
                document.removeEventListener('keydown', onKey, true);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                document.body.classList.remove('kt-pin-open');
                resolve(v);
            }
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(overlay);
            document.body.classList.add('kt-pin-open');
        });
    }

    /** Name search through lane K2's `search` action (device route; a live ksess token is accepted). */
    function searchBox(scope, placeholder, onPick, excludeIds) {
        var box = input({ type: 'search', placeholder: placeholder, autocapitalize: 'words', 'aria-label': placeholder, enterkeyhint: 'search' });
        var list = el('div', { class: 'kt-results', role: 'list' });
        var note = el('p', { class: 'kx-note', hidden: true });
        var timer = null;
        var seq = 0;
        function run() {
            var q = box.value.trim();
            clear(list);
            note.hidden = true;
            if (q.length < 2) { return; }
            var mine = ++seq;
            K.api.post('search', { q: q, scope: scope }).then(function (res) {
                if (mine !== seq) { return; }
                var rows = (res && res.results) || [];
                rows = rows.filter(function (r) { return !excludeIds || excludeIds.indexOf(r.contact_id) === -1; });
                rows.forEach(function (r) {
                    list.appendChild(el('button', { type: 'button', class: 'kx-row', role: 'listitem', on: { click: function () { onPick(r); } } }, [
                        avatar(r.initials, 'md'),
                        el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: r.name }),
                            el('span', { class: 'kx-row__sub', text: [r.dept, r.title].filter(Boolean).join(' · ') })]),
                        el('span', { class: 'kx-row__end' }, [icon('fa-chevron-right')])
                    ]));
                });
                if (!rows.length) { note.textContent = t('trn.checkin_no_match'); note.hidden = false; }
                else if (res.more) { note.textContent = t('trn.checkin_more'); note.hidden = false; }
            }, function (err) {
                if (mine !== seq) { return; }
                note.textContent = errText(err);
                note.hidden = false;
            });
        }
        box.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
        return { el: el('div', { class: 'kt-search' }, [box, note, list]), input: box, reset: function () { box.value = ''; clear(list); note.hidden = true; } };
    }

    function signBlock(label, name, onChange) {
        var holder = el('div', { class: 'kt-sign' });
        var wrap = el('section', { class: 'kt-block' }, [holder]);
        // the heading sits on one row with "Clear signature", as in the mockup
        var pad = K.ui.signaturePad(holder, { title: label, name: name || '', date: today(), onChange: onChange || null });
        return { el: wrap, pad: pad };
    }

    // ================================================================== HOME
    function viewHome() {
        var h = P.home;
        if (!h) {
            show([heading(null, t('trn.title')), alertBox('warn', t('trn.not_trainer'))]);
            return;
        }
        var tiles = el('div', { class: 'kt-tiles' });
        function tile(ic, label, sub, on, enabled) {
            return el('button', { type: 'button', class: 'kx-tile kt-tile', disabled: !enabled, on: { click: on } }, [
                el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon(ic)]),
                el('span', { class: 'kt-tile__label', text: label }),
                el('span', { class: 'kx-row__sub', text: enabled ? sub : t('trn.tile_unavailable') })
            ]);
        }
        if (h.can_train) { tiles.appendChild(tile('fa-users', t('trn.tile_session'), t('trn.tile_session_sub'), pickSessionCourse, h.sessions)); }
        if (h.can_evaluate) { tiles.appendChild(tile('fa-clipboard-check', t('trn.tile_evaluate'), t('trn.tile_evaluate_sub'), function () { location.assign('/kiosk/evaluate.php'); }, h.evaluate)); }
        if (h.award) { tiles.appendChild(tile('fa-award', t('trn.tile_award'), t('trn.tile_award_sub'), viewAward, true)); }
        var parts = [heading(null, t('trn.hello', { first: SESSION.first || '' }), t('trn.hello_sub')), tiles];
        if (!h.sessions && !h.evaluate && !h.award) { parts.push(alertBox('info', t('trn.nothing_to_do'))); }
        if (h.sessions) {
            var list = el('div', { class: 'kx-stack' });
            (h.open_sessions || []).forEach(function (s) {
                list.appendChild(el('a', { class: 'kx-row', href: '/kiosk/session.php?s=' + encodeURIComponent(s.tsession_id) }, [
                    el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon('fa-users')]),
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: s.course }),
                        el('span', { class: 'kx-row__sub', text: [fmtDate(s.started_at), s.location, s.topic].filter(Boolean).join(' · ') })]),
                    el('span', { class: 'kx-row__end' }, [el('span', { text: t('trn.resume') }), icon('fa-chevron-right')])
                ]));
            });
            if (!(h.open_sessions || []).length) { list.appendChild(el('p', { class: 'kx-note', text: t('trn.no_open_sessions') })); }
            parts.push(el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.open_sessions') }), list]));
        }
        show(parts);
    }

    // ------------------------------------------------------------------ [S] A-5 give a badge
    function backBtn(onClick) { return el('div', { class: 'kx-actions' }, [btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', onClick)]); }
    function viewAward() {
        show(spinner());
        K.api.get('trainer_badges').then(function (res) {
            var badges = (res && res.badges) || [];
            if (!badges.length) { show([heading(t('trn.tile_award'), t('trn.award_pick_badge')), alertBox('info', t('trn.award_none')), backBtn(viewHome)]); return; }
            var list = el('div', { class: 'kx-stack' });
            badges.forEach(function (b) {
                var medal = el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon('fa-' + b.icon)]);
                if (/^#[0-9a-fA-F]{6}$/.test(b.color || '')) { medal.style.color = b.color; }
                list.appendChild(el('button', { type: 'button', class: 'kx-row', on: { click: function () { awardPerson(b); } } }, [
                    medal,
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: b.name }), b.description ? el('span', { class: 'kx-row__sub', text: b.description }) : null]),
                    el('span', { class: 'kx-row__end' }, [icon('fa-chevron-right')])
                ]));
            });
            show([heading(t('trn.tile_award'), t('trn.award_pick_badge')), list, backBtn(viewHome)]);
        }, function (err) { show([alertBox('bad', errText(err)), backBtn(viewHome)]); });
    }
    function awardPerson(b) {
        var sb = searchBox('learner', t('trn.award_search'), function (p) { awardReason(b, p); });
        show([heading(b.name, t('trn.award_pick_person')), sb.el, backBtn(viewAward)]);
        setTimeout(function () { sb.input.focus(); }, 50);
    }
    function awardReason(b, p) {
        var text = el('textarea', { class: 'kt-input kt-textarea', rows: '3', maxlength: '500' });
        var go = btn(t('trn.award_give'), 'kx-btn--primary kx-btn--xl', 'fa-award', function () {
            var v = text.value.trim();
            if (v.length < 5) { K.ui.toast(t('trn.reason_short'), 'warn'); text.focus(); return; }
            pinAction(t('trn.award_confirm', { badge: b.name, first: p.first }), 'trainer_award',
                { achievement_id: b.id, contact_id: p.contact_id, sig: p.sig, reason: v }).then(function (res) {
                if (!res) { return; }
                show(el('div', { class: 'kx-center kt-done' }, [
                    el('span', { class: 'kt-done__icon', 'aria-hidden': 'true' }, [icon('fa-award')]),
                    el('h1', { text: t('trn.award_done', { first: res.first || p.first }) }),
                    el('p', { class: 'kx-lead', text: t('trn.award_done_sub', { badge: res.badge || b.name }) }),
                    el('div', { class: 'kx-actions kx-actions--center' }, [
                        btn(t('trn.award_another'), 'kx-btn--ghost kx-btn--xl', 'fa-award', viewAward),
                        btn(t('trn.back_home'), 'kx-btn--primary kx-btn--xl', 'fa-home', viewHome)
                    ])
                ]));
            });
        });
        show([heading(b.name, t('trn.award_for', { name: p.name })), field(t('trn.award_reason'), text, t('trn.award_reason_hint')),
            el('div', { class: 'kx-actions' }, [btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', function () { awardPerson(b); }), go])]);
        setTimeout(function () { text.focus(); }, 50);
    }

    var trainerCourses = null;
    function loadCourses() {
        if (trainerCourses) { return Promise.resolve(trainerCourses); }
        return K.api.get('trainer_courses').then(function (res) { trainerCourses = res; return res; });
    }

    function pickSessionCourse() {
        show(spinner());
        loadCourses().then(function (res) {
            var list = el('div', { class: 'kx-stack' });
            var courses = (res.courses || []).filter(function (c) { return c.needs_session; });
            courses.forEach(function (c) {
                list.appendChild(el('button', { type: 'button', class: 'kx-row', on: { click: function () { sessionForm(c, res); } } }, [
                    el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon('fa-hard-hat')]),
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: c.name })]),
                    el('span', { class: 'kx-row__end' }, [icon('fa-chevron-right')])
                ]));
            });
            if (!courses.length) { list.appendChild(alertBox('info', t('trn.no_courses'))); }
            show([heading(t('trn.tile_session'), t('trn.pick_course')), list, el('div', { class: 'kx-actions' }, [btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', viewHome)])]);
        }, function (err) { show([alertBox('bad', errText(err)), btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', viewHome)]); });
    }

    function sessionForm(course, res) {
        var topic = input({ type: 'text', maxlength: '200' });
        var loc = input({ type: 'text', maxlength: '200', value: P.device_label || '' });
        var dept = el('select', { class: 'kt-input' });
        (res.departments || []).forEach(function (d) {
            var o = el('option', { value: String(d.id), text: d.name });
            if (d.id === res.default_id) { o.selected = true; }
            dept.appendChild(o);
        });
        var msg = el('div', { hidden: true });
        var go = btn(t('trn.start'), 'kx-btn--primary kx-btn--xl', 'fa-play', function () {
            K.ui.busy(go, true);
            msg.hidden = true;
            K.api.post('session_start', { course_id: course.id, topic: topic.value, location: loc.value, client_id: parseInt(dept.value, 10) || 0 }).then(function (r) {
                location.assign('/kiosk/session.php?s=' + encodeURIComponent(r.tsession_id));
            }, function (err) {
                K.ui.busy(go, false);
                clear(msg); msg.appendChild(alertBox('bad', errText(err))); msg.hidden = false;
            });
        });
        show([heading(course.name, t('trn.form_title')),
            el('div', { class: 'kx-card kt-form' }, [field(t('trn.topic'), topic), field(t('trn.location'), loc), field(t('trn.department'), dept), msg,
                el('div', { class: 'kx-actions' }, [go, btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', pickSessionCourse)])])]);
    }

    // ================================================================== SESSION (trainer)
    var S = null;
    function loadSession() {
        show(spinner());
        return K.api.get('session_get', { tsession_id: P.tsession_id }).then(function (res) { S = res; renderSession(); }, function (err) {
            show([alertBox('bad', errText(err)), btn(t('trn.back_home'), 'kx-btn--ghost', 'fa-arrow-left', home)]);
        });
    }

    function pinAction(title, action, body) {
        return pinDialog({ title: title, sub: t('trn.enter_pin'), submit: function (pin) {
            var b = {};
            Object.keys(body).forEach(function (k) { b[k] = body[k]; });
            b.pin = pin;
            return K.api.post(action, b);
        } });
    }

    function renderSession() {
        var s = S.session;
        var present = S.attendees.filter(function (a) { return !a.removed && a.attendance === 'present'; });
        var active = S.attendees.filter(function (a) { return !a.removed; });
        var roster = el('div', { class: 'kx-stack' });
        active.forEach(function (a) { roster.appendChild(attendeeRow(a, s)); });
        if (!active.length) { roster.appendChild(el('p', { class: 'kx-note', text: t('trn.roster_empty') })); }
        var sugg = el('div', { class: 'kx-stack' });
        (S.suggested || []).forEach(function (p) {
            sugg.appendChild(el('div', { class: 'kx-row kt-row--static' }, [
                avatar(p.initials, 'md'),
                el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: p.name }), el('span', { class: 'kx-row__sub', text: p.dept })]),
                s.allow_trainer_attest ? el('span', { class: 'kx-row__end' }, [btn(t('trn.mark_present'), 'kx-btn--ghost', 'fa-user-check', function () { attestFlow(p); })]) : null
            ]));
        });
        if (!(S.suggested || []).length) { sugg.appendChild(el('p', { class: 'kx-note', text: t('trn.suggested_empty') })); }
        var hand = btn(t('trn.hand_around'), 'kx-btn--primary kx-btn--xl', 'fa-hand-holding', function () {
            K.ui.busy(hand, true);
            K.api.post('checkin_enter', { tsession_id: s.tsession_id }).then(function (r) { location.replace(r.next || '/kiosk/session.php'); },
                function (err) { K.ui.busy(hand, false); K.ui.toast(errText(err), 'bad'); });
        });
        var finish = btn(t('trn.finish'), 'kx-btn--xl', 'fa-flag-checkered', function () {
            if (!present.length) { K.ui.toast(t('trn.need_one_present'), 'warn'); return; }
            finalizeView(present);
        });
        var cancel = btn(t('trn.cancel_session'), 'kx-btn--ghost', 'fa-times', cancelFlow);
        show([
            heading(t('trn.tile_session'), s.course, [dayDate(s.held_on), s.location, s.dept, s.topic].filter(Boolean).join(' · ')),
            el('div', { class: 'kt-bar' }, [hand, el('p', { class: 'kx-note', text: t('trn.hand_around_sub') })]),
            el('div', { class: 'kt-cols' }, [
                el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.roster', { n: active.length }) }), roster]),
                el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.suggested', { n: (S.suggested || []).length }) }), sugg])
            ]),
            el('div', { class: 'kx-actions kt-foot' }, [finish, cancel, btn(t('trn.back_home'), 'kx-btn--ghost', 'fa-arrow-left', home)])
        ]);
    }

    function attendeeRow(a, s) {
        var chips = el('span', { class: 'kt-chips' }, [
            chip(t('trn.proof_' + a.proof), a.proof === 'trainer_attested' ? 'warn' : 'ok', a.proof === 'trainer_attested' ? 'fa-user-shield' : 'fa-check'),
            a.attendance !== 'present' ? chip(t('trn.att_' + a.attendance), 'warn', 'fa-exclamation-triangle') : null,
            s.needs_practical ? chip(t('trn.practical') + ': ' + t('trn.pr_' + a.practical), a.practical === 'pass' ? 'ok' : (a.practical === 'fail' ? 'bad' : 'muted'),
                a.practical === 'pass' ? 'fa-check-circle' : (a.practical === 'fail' ? 'fa-times-circle' : 'fa-minus-circle')) : null
        ]);
        var actions = el('span', { class: 'kx-row__end kt-row-actions' });
        function mark(body, title) {
            pinAction(title, 'attendee_mark', Object.assign({ attendee_id: a.attendee_id }, body)).then(function (res) { if (res) { S = res; renderSession(); } });
        }
        if (s.needs_practical && a.can_mark_practical) {   // the server says whether this trainer may record a hands-on result for this person
            actions.appendChild(btn(t('trn.pr_pass'), 'kx-btn--ghost', 'fa-check', function () { mark({ practical: 'pass' }, a.first + ': ' + t('trn.pr_pass')); }));
            actions.appendChild(btn(t('trn.pr_fail'), 'kx-btn--ghost', 'fa-times', function () { mark({ practical: 'fail' }, a.first + ': ' + t('trn.pr_fail')); }));
        }
        if (a.attendance === 'present') {
            actions.appendChild(btn(t('trn.att_partial'), 'kx-btn--ghost', 'fa-door-open', function () { mark({ attendance: 'partial' }, a.first + ': ' + t('trn.att_partial')); }));
        } else {
            actions.appendChild(btn(t('trn.att_present'), 'kx-btn--ghost', 'fa-user-check', function () { mark({ attendance: 'present' }, a.first + ': ' + t('trn.att_present')); }));
        }
        actions.appendChild(btn(t('trn.remove'), 'kx-btn--ghost', 'fa-user-minus', function () { removeFlow(a); }));
        return el('div', { class: 'kx-row kt-row--static' }, [avatar(a.initials, 'md'),
            el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: a.name }), el('span', { class: 'kx-row__sub', text: a.dept }), chips]), actions]);
    }

    function reasonPrompt(title, withCodes) {
        return new Promise(function (resolve) {
            var codes = null;
            var chosen = withCodes ? 'no_pin' : null;
            var text = el('textarea', { class: 'kt-input kt-textarea', rows: '2', maxlength: '255' });
            var err = el('p', { class: 'kt-pin-msg', role: 'alert', hidden: true });
            if (withCodes) {
                codes = el('div', { class: 'kt-seg', role: 'radiogroup' });
                ['no_pin', 'forgot_pin', 'other'].forEach(function (c) {
                    var b = el('button', { type: 'button', class: 'kt-seg__btn', role: 'radio', 'aria-checked': c === chosen ? 'true' : 'false', text: t('trn.reason_' + c) });
                    b.addEventListener('click', function () {
                        chosen = c;
                        Array.prototype.forEach.call(codes.children, function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
                        textField.hidden = c !== 'other';
                    });
                    codes.appendChild(b);
                });
            }
            var textField = field(t('trn.reason_text'), text);
            textField.hidden = !!withCodes;
            var extra = el('div', { class: 'kx-stack' }, [codes, textField, err]);
            var p = K.ui.confirm(withCodes ? t('trn.attest_reason') : title, t('trn.next'), t('shell.cancel'), { title: withCodes ? title : null, extra: extra });
            p.then(function (ok) {
                if (!ok) { resolve(null); return; }
                var v = text.value.trim();
                if ((!withCodes || chosen === 'other') && v.length < 5) { K.ui.toast(t('trn.reason_short'), 'warn'); resolve(null); return; }
                resolve({ code: chosen, text: v });
            });
            setTimeout(function () { if (!withCodes) { text.focus(); } }, 50);
        });
    }

    function attestFlow(p) {
        reasonPrompt(t('trn.attest_title', { first: p.first }), true).then(function (r) {
            if (!r) { return; }
            pinAction(t('trn.attest_title', { first: p.first }), 'attendee_attest',
                { tsession_id: S.session.tsession_id, contact_id: p.contact_id, sig: p.sig, reason: r.code, reason_text: r.code === 'other' ? r.text : null })
                .then(function (res) { if (res) { S = res; renderSession(); K.ui.toast(t('trn.checkin_ok', { first: p.first }), 'ok'); } });
        });
    }

    function removeFlow(a) {
        reasonPrompt(t('trn.remove_reason', { first: a.first }), false).then(function (r) {
            if (!r) { return; }
            pinAction(t('trn.remove') + ': ' + a.name, 'attendee_remove', { attendee_id: a.attendee_id, reason: r.text }).then(function (res) { if (res) { S = res; renderSession(); } });
        });
    }

    function cancelFlow() {
        reasonPrompt(t('trn.cancel_reason'), false).then(function (r) {
            if (!r) { return; }
            pinAction(t('trn.cancel_session'), 'session_cancel', { tsession_id: S.session.tsession_id, reason: r.text }).then(function (res) { if (res) { home(); } });
        });
    }

    function finalizeView(present) {
        var s = S.session;
        var list = el('ul', { class: 'kt-plain' });
        present.forEach(function (a) { list.appendChild(el('li', null, [icon('fa-user-check'), el('span', { text: a.name + (a.dept ? ' · ' + a.dept : '') })])); });
        var box = el('input', { type: 'checkbox', class: 'kt-check__box' });
        var check = el('label', { class: 'kt-check' }, [box, el('span', { text: t('trn.finalize_attest') })]);
        var sign = signBlock(t('trn.finalize_sign'), SESSION.name);
        var msg = el('div', { hidden: true });
        var go = btn(t('trn.finalize_btn'), 'kx-btn--primary kx-btn--xl', 'fa-signature', function () {
            msg.hidden = true;
            if (!box.checked) { K.ui.toast(t('trn.finalize_attest'), 'warn'); box.focus(); return; }
            if (!sign.pad.isInked()) { K.ui.toast(t('trn.sign_first'), 'warn'); return; }
            var png = sign.pad.toPng();
            pinDialog({ title: t('trn.finalize_pin'), submit: function (pin) {
                return K.api.post('session_finalize', { tsession_id: s.tsession_id, pin: pin, signature_png: png, confirm: true }, { timeoutMs: 60000 });
            } }).then(function (res) { if (res) { finalizeDone(res); } });
        });
        show([heading(t('trn.finish'), t('trn.finalize_title', { course: s.course }), t('trn.finalize_review', { n: present.length })),
            el('div', { class: 'kx-card' }, [list]), check, sign.el, msg,
            el('div', { class: 'kx-actions' }, [go, btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', renderSession)])]);
    }

    function finalizeDone(res) {
        var list = el('div', { class: 'kx-stack' });
        (res.list || []).forEach(function (r) {
            var pend = r.status !== 'recorded';
            list.appendChild(el('div', { class: 'kx-row kt-row--static' }, [
                el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: r.name }),
                    el('span', { class: 'kx-row__sub', text: pend ? t(K.has('trn.pending_' + r.reason) ? 'trn.pending_' + r.reason : 'trn.pending_other') : (r.cert_number || '') })]),
                chip(pend ? t('trn.st_pending') : t('trn.st_recorded'), pend ? 'warn' : 'ok', pend ? 'fa-hourglass-half' : 'fa-check-circle')
            ]));
        });
        show([heading(res.course || '', t('trn.finalize_done'), t('trn.finalize_summary', { done: res.completions, pending: res.pending })),
            list, el('div', { class: 'kx-actions' }, [btn(t('trn.back_home'), 'kx-btn--primary kx-btn--xl', 'fa-home', home)])]);
    }

    // ================================================================== CHECK-IN (pass the iPad)
    var C = null;
    function loadCheckin() {
        return K.api.get('session_get', {}).then(function (res) { C = res; renderCheckin(); }, function (err) {
            show([alertBox('bad', errText(err)), exitLink()]);
        });
    }

    function exitLink() {
        return el('div', { class: 'kx-foot kt-exit' }, [
            el('span', { text: C && C.session ? t('trn.session_meta', { date: today(), location: C.session.location || '' }) : '' }),
            btn(t('trn.exit'), 'kx-btn--link', 'fa-lock', function () {
                pinDialog({ title: t('trn.exit_title'), submit: function (pin) { return K.api.post('checkin_exit', { pin: pin }); } })
                    .then(function (res) { if (res) { location.replace(res.next || '/kiosk/trainer.php'); } });
            })
        ]);
    }

    function renderCheckin() {
        var s = C.session;
        var grid = el('div', { class: 'kt-names', role: 'list' });
        (C.suggested || []).forEach(function (p) {
            grid.appendChild(el('button', { type: 'button', class: 'kt-name', role: 'listitem', on: { click: function () { checkinPerson(p); } } }, [
                avatar(p.initials, 'md'), el('span', { class: 'kt-name__text' }, [el('span', { class: 'kt-name__name', text: p.name }), el('span', { class: 'kx-row__sub', text: p.dept })])
            ]));
        });
        var search = searchBox('checkin', t('trn.checkin_placeholder'), checkinPerson, null);
        var here = el('div', { class: 'kt-here' });
        var listed = (C.suggested || []).length > 0;   // "Not on the list?" only when a list is shown
        (C.attendees || []).forEach(function (a) { here.appendChild(chip(a.name, 'ok', 'fa-check')); });
        show([
            heading(t('trn.hand_around'), t('trn.checkin_title', { course: s.course }), t('trn.checkin_sub')),
            listed ? grid : null,
            el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: listed ? t('trn.checkin_search') : t('trn.checkin_type') }), search.el,
                el('p', { class: 'kx-note', text: t('trn.checkin_search_hint') })]),
            el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.checkin_in', { n: (C.attendees || []).length }) }), here]),
            exitLink()
        ]);
    }

    function checkinPerson(p, opts) {
        opts = opts || {};
        var s = opts.session || C.session;
        var back = opts.back || renderCheckin;
        var action = opts.action || 'checkin_attendee';
        var extra = opts.extra || {};
        var msg = el('p', { class: 'kt-pin-msg', role: 'alert', hidden: true });
        var sign = s.requires_signature ? signBlock(t('trn.checkin_sign'), p.name, function () { msg.hidden = true; }) : null;
        var padBox = el('div', { class: 'kt-pin-pad' });
        var pad = K.ui.keypad(padBox, {
            minLen: 4, maxLen: 12,
            onSubmit: function (pin) {
                msg.hidden = true;
                var png = null;
                if (sign) {
                    if (!sign.pad.isInked()) { pad.clear(); msg.textContent = t('trn.sign_first'); msg.hidden = false; return; }
                    png = sign.pad.toPng();
                }
                pad.setBusy(true);
                var body = { contact_id: p.contact_id, sig: p.sig, pin: pin, signature_png: png };
                Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
                K.api.post(action, body).then(function (res) {
                    pad.destroy();
                    checkedIn(res, opts.after);
                }, function (err) {
                    pad.clear();
                    pad.setBusy(false);
                    msg.textContent = errText(err) + (err && err.code === 'setup_needed' ? ' ' + t('trn.checkin_no_pin') : '');
                    msg.hidden = false;
                });
            }
        });
        show([
            el('div', { class: 'kt-person' }, [avatar(p.initials, 'lg'), el('div', null, [el('h1', { text: t('trn.checkin_hi', { first: p.first }) }), el('p', { class: 'kx-lead', text: s.course })])]),
            el('div', { class: sign ? 'kt-split' : 'kt-single' }, [
                sign ? sign.el : null,
                el('section', { class: 'kt-block kt-pinbox' }, [el('h2', { class: 'kx-h3', text: t('trn.checkin_pin') }), msg, padBox])
            ]),
            el('div', { class: 'kx-actions' }, [btn(t('trn.checkin_not_you'), 'kx-btn--ghost', 'fa-arrow-left', function () { pad.destroy(); back(); })])
        ]);
    }

    function checkedIn(res, after) {
        show(el('div', { class: 'kx-center kt-done' }, [
            el('span', { class: 'kt-done__icon' + (res.already ? ' is-already' : ''), 'aria-hidden': 'true' }, [icon(res.already ? 'fa-info' : 'fa-check')]),
            el('h1', { text: t(res.already ? 'trn.checkin_already' : 'trn.checkin_ok', { first: res.first }) })
        ]));
        setTimeout(function () { show(spinner()); (after || loadCheckin)(); }, 3000);
    }

    // ================================================================== [S] T-6 parallel check-in (pre-auth device)
    function viewSelf() {
        show(spinner());
        K.api.get('checkin_sessions').then(function (res) {
            var list = el('div', { class: 'kx-stack' });
            (res.sessions || []).forEach(function (s) {
                list.appendChild(el('button', { type: 'button', class: 'kx-row', on: { click: function () { selfSession(s); } } }, [
                    el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon('fa-users')]),
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: s.course }),
                        el('span', { class: 'kx-row__sub', text: [s.trainer, s.location, fmtDate(s.started_at)].filter(Boolean).join(' · ') })]),
                    el('span', { class: 'kx-row__end' }, [icon('fa-chevron-right')])
                ]));
            });
            if (!(res.sessions || []).length) { list.appendChild(alertBox('info', t('trn.self_none'))); }
            show([heading(null, t('trn.self_title'), t('trn.self_sub')), list,
                el('div', { class: 'kx-actions' }, [el('a', { class: 'kx-btn kx-btn--ghost', href: '/kiosk/' }, [icon('fa-arrow-left'), el('span', { text: t('shell.back') })])])]);
        }, function (err) { show([alertBox('bad', errText(err)), el('a', { class: 'kx-btn kx-btn--ghost', href: '/kiosk/' }, [el('span', { text: t('shell.back') })])]); });
    }

    function selfSession(s) {
        var search = searchBox('checkin', t('trn.checkin_placeholder'), function (p) {
            checkinPerson(p, { session: s, action: 'checkin_self', extra: { tsession_id: s.tsession_id }, back: function () { selfSession(s); }, after: viewSelf });
        }, null);
        show([heading(s.trainer, t('trn.checkin_title', { course: s.course }), t('trn.checkin_search_hint')), search.el,
            el('div', { class: 'kx-actions' }, [btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', viewSelf)])]);
        setTimeout(function () { search.input.focus(); }, 50);
    }

    // ================================================================== EVALUATE (trainer)
    function viewEvaluate() {
        var last = ss(EVAL_RESULT_KEY);
        var banner = null;
        if (last) {
            ss(EVAL_RESULT_KEY, null);
            try {
                var r = JSON.parse(last);
                if (r.status === 'recorded') {
                    banner = alertBox('ok', r.cert_number ? t('trn.eval_recorded_cert', { cert: r.cert_number }) : '', t('trn.eval_recorded', { first: r.first }));
                } else if (r.result === 'fail') {
                    banner = alertBox('warn', t('trn.eval_failed', { first: r.first }));
                } else {
                    banner = alertBox('info', t('trn.eval_pending', { first: r.first }));
                }
            } catch (e) { banner = null; }
        }
        ss(EVAL_KEY, null);
        if (P.course_id) { evalCandidates(P.course_id, banner); return; }
        show(spinner());
        loadCourses().then(function (res) {
            var list = el('div', { class: 'kx-stack' });
            var courses = (res.courses || []).filter(function (c) { return c.needs_practical; });
            courses.forEach(function (c) {
                list.appendChild(el('button', { type: 'button', class: 'kx-row', on: { click: function () { evalCandidates(c.id, null); } } }, [
                    el('span', { class: 'kx-tile__icon', 'aria-hidden': 'true' }, [icon('fa-clipboard-check')]),
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: c.name })]),
                    el('span', { class: 'kx-row__end' }, [icon('fa-chevron-right')])
                ]));
            });
            if (!courses.length) { list.appendChild(alertBox('info', t('trn.no_eval_courses'))); }
            show([banner, heading(t('trn.tile_evaluate'), t('trn.pick_course')), list,
                el('div', { class: 'kx-actions' }, [btn(t('trn.back_home'), 'kx-btn--ghost', 'fa-arrow-left', home)])]);
        }, function (err) { show([banner, alertBox('bad', errText(err)), btn(t('trn.back_home'), 'kx-btn--ghost', 'fa-arrow-left', home)]); });
    }

    function evalCandidates(courseId, banner) {
        show(spinner());
        K.api.get('evaluate_candidates', { course_id: courseId }).then(function (res) {
            var list = el('div', { class: 'kx-stack' });
            (res.candidates || []).forEach(function (p) {
                list.appendChild(el('button', { type: 'button', class: 'kx-row', on: { click: function () { evalChecklist(res, p); } } }, [
                    avatar(p.initials, 'md'),
                    el('span', { class: 'kx-row__main' }, [el('span', { class: 'kx-row__title', text: p.name }),
                        el('span', { class: 'kx-row__sub', text: [p.dept, p.since ? fmtDate(p.since) : ''].filter(Boolean).join(' · ') })]),
                    el('span', { class: 'kx-row__end' }, [chip(t('trn.eval_waiting'), 'warn', 'fa-hourglass-half'), icon('fa-chevron-right')])
                ]));
            });
            if (!(res.candidates || []).length) { list.appendChild(el('p', { class: 'kx-note', text: t('trn.eval_none_waiting') })); }
            var search = searchBox('learner', t('trn.eval_search'), function (p) { evalChecklist(res, p); }, null);
            show([banner, heading(res.course.name, t('trn.eval_pick_person')),
                !res.checklist.length ? alertBox('warn', t('trn.eval_no_checklist')) : null,
                el('section', { class: 'kt-block' }, [list]),
                el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.eval_search') }), search.el]),
                el('div', { class: 'kx-actions' }, [btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', function () { P.course_id = 0; viewEvaluate(); })])]);
        }, function (err) { show([alertBox('bad', errText(err)), btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', function () { P.course_id = 0; viewEvaluate(); })]); });
    }

    function evalChecklist(res, person) {
        if (!res.checklist.length) { K.ui.toast(t('trn.eval_no_checklist'), 'warn'); return; }
        var results = res.checklist.map(function () { return null; });
        var rows = el('ol', { class: 'kt-checklist' });
        var resultLine = el('p', { class: 'kt-result', role: 'status' });
        var equipment = input({ type: 'text', maxlength: '200' });
        var notes = el('textarea', { class: 'kt-input kt-textarea', rows: '3', maxlength: '2000' });
        var go = btn(t('trn.eval_handoff', { first: person.first }), 'kx-btn--primary kx-btn--xl', 'fa-hand-holding', submit, { disabled: true });
        function refresh() {
            var all = results.every(function (r) { return r !== null; });
            var fail = results.some(function (r) { return r === 'fail'; });
            go.disabled = !all;
            resultLine.textContent = all ? t(fail ? 'trn.eval_result_fail' : 'trn.eval_result_pass') : t('trn.eval_mark_all');
            resultLine.className = 'kt-result' + (all ? (fail ? ' is-fail' : ' is-pass') : '');
        }
        res.checklist.forEach(function (item, i) {
            var seg = el('div', { class: 'kt-seg', role: 'radiogroup', 'aria-label': item.item });
            ['pass', 'fail'].forEach(function (v) {
                var b = el('button', { type: 'button', class: 'kt-seg__btn kt-seg__btn--' + v, role: 'radio', 'aria-checked': 'false' },
                    [icon(v === 'pass' ? 'fa-check' : 'fa-times'), el('span', { text: t('trn.pr_' + v) })]);
                b.addEventListener('click', function () {
                    results[i] = v;
                    Array.prototype.forEach.call(seg.children, function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
                    refresh();
                });
                seg.appendChild(b);
            });
            rows.appendChild(el('li', { class: 'kt-check-item' }, [
                el('div', { class: 'kt-check-item__text' }, [el('span', { text: item.item }), item.critical ? chip(t('trn.eval_critical'), 'bad', 'fa-exclamation-circle') : null]),
                seg
            ]));
        });
        function submit() {
            K.ui.busy(go, true);
            K.api.post('evaluate_handoff', { course_id: res.course.id, contact_id: person.contact_id, sig: person.sig, results: results,
                equipment: equipment.value, notes: notes.value }).then(function (r) {
                ss(EVAL_KEY, r.eval_token);
                location.replace(r.next || '/kiosk/evaluate.php');
            }, function (err) { K.ui.busy(go, false); K.ui.toast(errText(err), 'bad'); });
        }
        refresh();
        show([
            el('div', { class: 'kt-person' }, [avatar(person.initials, 'lg'), el('div', null, [el('div', { class: 'kx-kicker', text: t('trn.eval_title', { course: res.course.name }) }),
                el('h1', { text: person.name }), el('p', { class: 'kx-lead', text: person.dept || '' })])]),
            el('section', { class: 'kt-block' }, [el('h2', { class: 'kx-h3', text: t('trn.eval_checklist') }), rows]),
            el('div', { class: 'kx-card kt-form' }, [field(t('trn.eval_equipment'), equipment), field(t('trn.eval_notes'), notes)]),
            resultLine,
            el('div', { class: 'kx-actions' }, [go, btn(t('shell.back'), 'kx-btn--ghost', 'fa-arrow-left', function () { evalCandidates(res.course.id, null); })])
        ]);
    }

    // ================================================================== HAND-OFF (restricted role)
    function viewHandoff() {
        var token = ss(EVAL_KEY);
        if (!token || !/^[0-9a-f]{32}$/.test(token)) { handoffLost(); return; }
        show(spinner());
        K.api.get('evaluate_state', { eval_token: token }).then(function (st) {
            if (st.step === 'evaluator') { handbackView(st, token); } else { employeeView(st, token); }
        }, function () { handoffLost(); });
    }

    function cancelBtn(token) {
        return btn(t('trn.eval_cancel'), 'kx-btn--ghost', 'fa-times', function () {
            pinDialog({ title: t('trn.eval_cancel_title'), submit: function (pin) { return K.api.post('handoff_cancel', { pin: pin, eval_token: token || null }); } })
                .then(function (res) { if (res) { ss(EVAL_KEY, null); location.replace(res.next || '/kiosk/evaluate.php'); } });
        });
    }

    function handoffLost() {
        show([alertBox('warn', t('trn.eval_expired')), el('div', { class: 'kx-actions' }, [cancelBtn(null)])]);
    }

    function summary(st) {
        var rows = el('ol', { class: 'kt-checklist kt-checklist--ro' });
        st.checklist.forEach(function (c) {
            rows.appendChild(el('li', { class: 'kt-check-item' }, [
                el('div', { class: 'kt-check-item__text' }, [el('span', { text: c.item }), c.critical ? chip(t('trn.eval_critical'), 'bad', 'fa-exclamation-circle') : null]),
                chip(t('trn.pr_' + c.result), c.result === 'pass' ? 'ok' : 'bad', c.result === 'pass' ? 'fa-check-circle' : 'fa-times-circle')
            ]));
        });
        var fail = st.result === 'fail';
        return el('div', { class: 'kx-card kt-summary' }, [
            rows,
            st.equipment ? el('p', { class: 'kx-note', text: t('trn.eval_equipment_label') + ': ' + st.equipment }) : null,
            st.notes ? el('p', { class: 'kx-note', text: t('trn.eval_notes_label') + ': ' + st.notes }) : null,
            el('p', { class: 'kt-result ' + (fail ? 'is-fail' : 'is-pass'), text: t(fail ? 'trn.eval_result_fail' : 'trn.eval_result_pass') })
        ]);
    }

    function signAndPin(signLabel, signName, pinLabel, onPin) {
        var msg = el('p', { class: 'kt-pin-msg', role: 'alert', hidden: true });
        var sign = signBlock(signLabel, signName, function () { msg.hidden = true; });
        var padBox = el('div', { class: 'kt-pin-pad' });
        var pad = K.ui.keypad(padBox, {
            minLen: 4, maxLen: 12,
            onSubmit: function (pin) {
                msg.hidden = true;
                if (!sign.pad.isInked()) { pad.clear(); msg.textContent = t('trn.sign_first'); msg.hidden = false; return; }
                pad.setBusy(true);
                onPin(pin, sign.pad.toPng()).then(null, function (err) {
                    pad.clear();
                    pad.setBusy(false);
                    msg.textContent = errText(err);
                    msg.hidden = false;
                });
            }
        });
        return el('div', { class: 'kt-split' }, [sign.el, el('section', { class: 'kt-block kt-pinbox' }, [el('h2', { class: 'kx-h3', text: pinLabel }), msg, padBox])]);
    }

    function employeeView(st, token) {
        show([
            el('div', { class: 'kt-person' }, [el('div', null, [el('div', { class: 'kx-kicker', text: st.course }),
                el('h1', { text: t('trn.eval_employee_title', { first: st.employee.first }) }),
                el('p', { class: 'kx-lead', text: t('trn.eval_employee_sub', { trainer: st.trainer.name, course: st.course }) })])]),
            summary(st),
            signAndPin(t('trn.eval_employee_sign'), st.employee.name, t('trn.eval_employee_pin'), function (pin, png) {
                return K.api.post('evaluate_evaluatee', { eval_token: token, pin: pin, signature_png: png }).then(function () {
                    handbackPrompt(st, token);
                });
            }),
            el('div', { class: 'kx-actions kt-foot' }, [cancelBtn(token)])
        ]);
    }

    function handbackPrompt(st, token) {
        show(el('div', { class: 'kx-center kt-done' }, [
            el('span', { class: 'kt-done__icon', 'aria-hidden': 'true' }, [icon('fa-hand-holding')]),
            el('h1', { text: t('trn.eval_handback', { first: st.trainer.first }) }),
            btn(t('trn.next'), 'kx-btn--primary kx-btn--xl', 'fa-arrow-right', function () { st.step = 'evaluator'; handbackView(st, token); })
        ]));
    }

    function handbackView(st, token) {
        show([
            el('div', { class: 'kt-person' }, [el('div', null, [el('div', { class: 'kx-kicker', text: st.course + ' · ' + st.employee.name }),
                el('h1', { text: t('trn.eval_trainer_title', { first: st.trainer.first }) })])]),
            summary(st),
            signAndPin(t('trn.eval_trainer_sign'), st.trainer.name, t('trn.eval_trainer_pin'), function (pin, png) {
                return K.api.post('evaluate_submit', { eval_token: token, pin: pin, signature_png: png }, { timeoutMs: 60000 }).then(function (r) {
                    ss(EVAL_KEY, null);
                    ss(EVAL_RESULT_KEY, JSON.stringify({ status: r.status, result: r.result, cert_number: r.cert_number || null, first: r.first || '' }));
                    location.replace(r.next || '/kiosk/evaluate.php');
                });
            }),
            el('div', { class: 'kx-actions kt-foot' }, [cancelBtn(token)])
        ]);
    }

    // ------------------------------------------------------------------ boot
    switch (P.view) {
        case 'home': viewHome(); break;
        case 'session': loadSession(); break;
        case 'checkin': loadCheckin(); break;
        case 'evaluate': viewEvaluate(); break;
        case 'handoff': viewHandoff(); break;
        case 'selfcheckin': viewSelf(); break;
        default: show(alertBox('bad', t('err.server')));
    }
}());
