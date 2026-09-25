/*
 * Kiosk Home / sign-in (P3 spec §5.2, lane K2; mockups Kiosk-SignIn and Kiosk-PIN).
 *
 * Screens (all rendered with Kiosk.ui.el / textContent - never HTML strings):
 *   adopt     /kiosk/#d=<token> (or /kiosk/?d=<token>): replaceState('/kiosk/') at once, POST adopt_device, confirm before
 *             replacing a valid device (409 device_replace_confirm), then location.replace('/kiosk/')
 *   notsetup  server-rendered; [S] "Enter a setup code" (POST enroll_code)
 *   switch    "Sign out {name}?" (POST end)
 *   search    "Who's training today?" - 250 ms debounce, >= 2 letters, rows with the match in bold
 *   pin       Hi {first} + keypad (4..6 local, 4..12 Odoo); pin_login
 *   setup     8-digit setup code (setup_code_verify) -> new PIN (6) -> confirm -> pin_create
 * Trainer sign-in is the same flow with scope/role 'trainer'. 60 s without input on any screen
 * clears it and goes back to Search (a personal device goes back to its own PIN screen).
 * The PIN only ever lives in the keypad closure and the request body; it is cleared after every call.
 */
(function () {
    'use strict';

    var K = window.Kiosk;
    if (!K) { return; }
    var d = K.data();
    var page = d.page || {};
    var root = document.getElementById('kx-signin');
    if (!root) { return; }
    var el = K.ui.el;
    var t = K.t;
    var IDLE_MS = 60000;
    var TOKEN_RE = /^#d=([A-Za-z0-9_-]{43})$/;
    // The same start URL written with a query string (/kiosk/?d=<token>, e.g. typed into an Edge/Chrome
    // kiosk-mode shortcut) is accepted too; it leaves the address bar just as fast. The issued form
    // stays the fragment, which never reaches a server log.
    var QUERY_RE = /^\?d=([A-Za-z0-9_-]{43})$/;

    var mode = 'learner';          // 'learner' | 'trainer'
    var screen = null;             // current screen name
    var keypadObj = null;          // the live keypad (destroyed on every screen change)
    var idleTimer = null;
    var searchSeq = 0;
    var lastQuery = '';

    function icon(name) { return K.ui.icon(name); }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }

    function setScreen(name, nodes) {
        if (keypadObj) { keypadObj.destroy(); keypadObj = null; }
        screen = name;
        root.setAttribute('data-screen', name);
        clear(root);
        (Array.isArray(nodes) ? nodes : [nodes]).forEach(function (n) { if (n) { root.appendChild(n); } });
        armIdle();
    }

    // ------------------------------------------------------------------ idle (pre-auth, 60 s)
    function armIdle() {
        if (idleTimer) { clearTimeout(idleTimer); }
        if (screen === 'adopt' || screen === 'notsetup' || screen === 'switch') { return; }
        idleTimer = setTimeout(function () {
            if (screen === 'search' && lastQuery === '' && mode === 'learner') { return; }
            mode = 'learner';
            home();
        }, IDLE_MS);
    }
    ['pointerdown', 'keydown', 'touchstart', 'input'].forEach(function (ev) {
        document.addEventListener(ev, function () { if (idleTimer) { armIdle(); } }, { capture: true, passive: true });
    });

    function home() {
        if (page.personal) { showPin(page.personal, false); } else { showSearch(''); }
    }

    // ------------------------------------------------------------------ messages
    function alertBox(kind, text, ic) {
        return el('div', { class: 'kx-alert kx-alert--' + kind, role: kind === 'bad' ? 'alert' : 'status' }, [
            icon(ic || (kind === 'bad' ? 'fa-times-circle' : (kind === 'warn' ? 'fa-exclamation-triangle' : 'fa-info-circle'))),
            el('span', { text: text })
        ]);
    }
    function setMsg(host, kind, text, ic) {
        clear(host);
        if (text) { host.appendChild(alertBox(kind, text, ic)); }
    }

    /** Maps a KioskError on a PIN / code step to {kind, text, stop:bool, go?:'setup'|'search'}. */
    function pinError(err, kind) {
        var code = err && err.code;
        var data = (err && err.data) || {};
        var m = data.minutes || 1;
        switch (code) {
            case 'pin_wrong':
                return { kind: 'bad', text: data.tries_left ? t('pin.wrong_left', { n: data.tries_left }) : t('pin.wrong') };
            case 'setup_code_wrong':
                return { kind: 'bad', text: data.tries_left ? t('setup.wrong_left', { n: data.tries_left }) : t('setup.wrong') };
            case 'pin_locked': return { kind: 'warn', text: t('pin.locked', { minutes: m }), stop: true, ic: 'fa-lock' };
            case 'pin_locked_hard': return { kind: 'bad', text: t('pin.locked_hard'), stop: true, ic: 'fa-lock' };
            case 'kiosk_cooldown': return { kind: 'warn', text: t('pin.cooldown', { minutes: m }), stop: true, ic: 'fa-pause-circle' };
            case 'paused': return { kind: 'warn', text: t('pin.paused', { minutes: m }), stop: true, ic: 'fa-pause-circle' };
            case 'signin_unavailable':
                return { kind: 'warn', text: data.reason === 'link_check' ? t('pin.unavailable_link') : t('pin.unavailable'), stop: true, ic: 'fa-plug' };
            case 'setup_needed': return { kind: 'info', text: '', go: 'setup' };
            case 'not_found': return { kind: 'bad', text: err.message, go: 'search' };
            case 'pin_policy':
                return { kind: 'bad', text: K.has('newpin.rule_' + (data.rule || '')) ? t('newpin.rule_' + data.rule) : t('err.pin_policy') };
            case 'pin_mismatch': return { kind: 'bad', text: t('newpin.mismatch') };
            default: return { kind: 'bad', text: (err && err.message) || t('err.server') };
        }
    }

    // ------------------------------------------------------------------ adopt (#d=)
    function adopt(token, replace) {
        setScreen('adopt', el('div', { class: 'kx-center' }, [
            el('span', { class: 'kx-spin', 'aria-hidden': 'true' }),
            el('p', { class: 'kx-lead', role: 'status', text: t('signin.adopting') })
        ]));
        K.api.post('adopt_device', { token: token, replace: !!replace }).then(function () {
            token = null;
            location.replace('/kiosk/');
        }, function (err) {
            if (err && err.code === 'device_replace_confirm') {
                var label = (err.data && err.data.label) || '';
                K.ui.confirm(t('signin.replace_body', { label: label }), t('signin.replace_ok'), t('shell.cancel'), { title: t('signin.replace_title') })
                    .then(function (yes) {
                        if (yes) { adopt(token, true); } else { token = null; location.replace('/kiosk/'); }
                    });
                return;
            }
            token = null;
            setScreen('adopt', el('div', { class: 'kx-center' }, [
                el('div', { class: 'kx-signin__narrow' }, [alertBox('bad', err && err.code === 'rate_limited' ? t('err.rate_limited') : t('signin.adopt_failed'))]),
                el('a', { class: 'kx-btn kx-btn--xl', href: '/kiosk/' }, [icon('fa-redo'), el('span', { text: t('shell.retry') })])
            ]));
        });
    }

    // ------------------------------------------------------------------ not set up: setup code [S]
    function wireNotSetup() {
        var open = document.getElementById('kx-code-open');
        var host = document.getElementById('kx-code');
        if (!open || !host) { return; }
        open.hidden = false;
        open.addEventListener('click', function () {
            open.hidden = true;
            host.hidden = false;
            var input = el('input', { type: 'text', id: 'kx-code-input', class: 'kx-input kx-input--code', maxlength: '11', autocomplete: 'off',
                autocapitalize: 'characters', spellcheck: 'false', placeholder: 'XXXXX-XXXXX', 'aria-describedby': 'kx-code-help' });
            var msg = el('div', { class: 'kx-signin__msg', 'aria-live': 'polite' });
            var btn = el('button', { type: 'submit', class: 'kx-btn kx-btn--primary kx-btn--xl' }, [icon('fa-check'), el('span', { text: t('signin.setup_code_ok') })]);
            var form = el('form', { class: 'kx-code__form', novalidate: true }, [
                el('label', { class: 'kx-label', for: 'kx-code-input', text: t('signin.setup_code_title') }),
                el('p', { class: 'kx-note', id: 'kx-code-help', text: t('signin.setup_code_body') }),
                input, msg, btn
            ]);
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var code = input.value;
                if (!/^[A-Za-z0-9 -]{10,13}$/.test(code)) { setMsg(msg, 'bad', t('err.code_invalid')); return; }
                K.ui.busy(btn, true);
                K.api.post('enroll_code', { code: code }).then(function () {
                    input.value = '';
                    location.replace('/kiosk/');
                }, function (err) {
                    K.ui.busy(btn, false);
                    input.value = '';
                    setMsg(msg, 'bad', err.code === 'enroll_paused' ? t('err.enroll_paused') : (err.code === 'code_invalid' ? t('err.code_invalid') : err.message));
                    input.focus();
                });
            });
            host.appendChild(form);
            input.focus();
        });
    }

    // ------------------------------------------------------------------ switch
    function showSwitch() {
        var sw = page.switch || {};
        var yes = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl' }, [icon('fa-sign-out-alt'), el('span', { text: t('signin.switch_ok') })]);
        var no = el('a', { class: 'kx-btn kx-btn--xl', href: /^\/kiosk\/[a-z_]+\.php/.test(sw.home || '') ? sw.home : '/kiosk/' }, [el('span', { text: t('signin.switch_stay') })]);
        yes.addEventListener('click', function () {
            K.ui.busy(yes, true);
            K.api.post('end', { reason: 'done' }).then(function () { location.replace('/kiosk/'); }, function () { location.replace('/kiosk/'); });
        });
        setScreen('switch', el('div', { class: 'kx-center' }, [el('section', { class: 'kx-hero' }, [
            el('span', { class: 'kx-hero__icon', 'aria-hidden': 'true' }, [icon('fa-user-clock')]),
            el('h1', { text: t('signin.switch_title', { name: sw.name || '' }) }),
            el('p', { class: 'kx-lead', text: t('signin.switch_body') }),
            el('div', { class: 'kx-actions kx-actions--center' }, [no, yes])
        ])]));
    }

    // ------------------------------------------------------------------ search
    function footer() {
        var dev = d.device && d.device.label ? d.device.label : '';
        var toggle = el('button', { type: 'button', class: 'kx-btn kx-btn--ghost kx-signin__mode' }, [
            icon(mode === 'trainer' ? 'fa-user' : 'fa-user-shield'),
            el('span', { text: mode === 'trainer' ? t('signin.learner_link') : t('signin.trainer_link') })
        ]);
        toggle.addEventListener('click', function () {
            if (mode === 'learner' && !page.trainers_available) { K.ui.toast(t('signin.trainer_none'), 'info'); return; }
            mode = mode === 'trainer' ? 'learner' : 'trainer';
            showSearch('');
        });
        // [S] T-6: a trainer's class is open - people can check themselves in from this device too.
        var checkin = page.checkin_open && mode === 'learner' ? el('a', { class: 'kx-btn kx-btn--ghost kx-signin__mode', href: '/kiosk/checkin.php' }, [
            icon('fa-users'), el('span', { text: t('signin.checkin_link') })
        ]) : null;
        return el('footer', { class: 'kx-foot kx-signin__foot' }, [
            el('span', { class: 'kx-note' }, [icon('fa-tablet-alt'), el('span', { text: t('signin.device', { label: dev }) })]),
            checkin,
            toggle
        ]);
    }

    /** The name with the typed words' first match wrapped in <mark> (text nodes only). */
    function highlight(name, q) {
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);
        var norm = function (s) { return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() : s.toLowerCase(); };
        var nameN = norm(name);
        var out = [];
        var ranges = [];
        words.forEach(function (w) {
            var wn = norm(w);
            var re = new RegExp('(^|\\s)' + wn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
            var m = re.exec(nameN);
            if (m && nameN.length === name.length) {
                var start = m.index + m[1].length;
                ranges.push([start, start + wn.length]);
            }
        });
        ranges.sort(function (a, b) { return a[0] - b[0]; });
        var pos = 0;
        ranges.forEach(function (r) {
            if (r[0] < pos) { return; }
            if (r[0] > pos) { out.push(document.createTextNode(name.slice(pos, r[0]))); }
            out.push(el('mark', { class: 'kx-mark', text: name.slice(r[0], r[1]) }));
            pos = r[1];
        });
        if (pos < name.length) { out.push(document.createTextNode(name.slice(pos))); }
        return out;
    }

    function showSearch(initial) {
        lastQuery = '';
        var title = mode === 'trainer' ? t('signin.trainer_title') : t('signin.title');
        var sub = mode === 'trainer' ? t('signin.trainer_subtitle') : t('signin.subtitle');
        var input = el('input', { id: 'kiosk-name', class: 'kx-input kx-search__input', type: 'text', autocomplete: 'off', autocapitalize: 'words',
            autocorrect: 'off', spellcheck: 'false', placeholder: t('signin.placeholder'), maxlength: '60', enterkeyhint: 'search' });
        var clearBtn = el('button', { type: 'button', class: 'kx-search__clear', 'aria-label': t('signin.clear'), hidden: true }, [el('span', {}, [icon('fa-times')])]);
        var results = el('div', { class: 'kx-search__results', id: 'kx-results' });
        var wrap = el('div', { class: 'kx-signin__wrap' }, [
            el('h1', { class: 'kx-signin__title', text: title }),
            el('p', { class: 'kx-lead', text: sub }),
            el('label', { class: 'kx-label kx-search__label', for: 'kiosk-name', text: t('signin.label') }),
            el('div', { class: 'kx-search' }, [el('span', { class: 'kx-search__icon', 'aria-hidden': 'true' }, [icon('fa-search')]), input, clearBtn]),
            results
        ]);
        var main = el('div', { class: 'kx-signin__main' + (mode === 'trainer' ? ' is-trainer' : '') }, [wrap]);
        setScreen('search', [main, footer()]);

        function renderHint() {
            clear(results);
            results.appendChild(el('div', { class: 'kx-search__hint', role: 'status' }, [
                el('span', { class: 'kx-search__hint-icon', 'aria-hidden': 'true' }, [icon('fa-keyboard')]),
                el('span', { class: 'kx-search__hint-text' }, [el('strong', { text: t('signin.hint_title') }), el('span', { text: t('signin.hint_body') })])
            ]));
        }
        function renderResults(q, res) {
            clear(results);
            var list = (res && res.results) || [];
            if (!list.length) {
                results.appendChild(el('div', { class: 'kx-search__hint kx-search__hint--empty', role: 'status' }, [
                    el('span', { class: 'kx-search__hint-icon', 'aria-hidden': 'true' }, [icon('fa-search-minus')]),
                    el('span', { class: 'kx-search__hint-text' }, [el('strong', { text: t('signin.empty_title', { q: q }) }), el('span', { text: t('signin.empty_body') })])
                ]));
                return;
            }
            results.appendChild(el('div', { class: 'kx-search__meta' }, [
                el('span', { class: 'kx-search__count', role: 'status', 'aria-live': 'polite', text: list.length === 1 ? t('signin.count_one') : t('signin.count_many', { n: list.length }) }),
                el('span', { class: 'kx-muted', text: t('signin.tap') })
            ]));
            var grid = el('div', { class: 'kx-search__grid' });
            list.forEach(function (r) {
                var sub = r.dept || '';
                if (r.title) { sub = sub ? sub + ' · ' + r.title : r.title; }
                var row = el('button', { type: 'button', class: 'kx-row kx-person' }, [
                    el('span', { class: 'kx-avatar kx-avatar--soft', 'aria-hidden': 'true', text: r.initials || '?' }),
                    el('span', { class: 'kx-row__main' }, [
                        el('span', { class: 'kx-row__title' }, highlight(String(r.name || ''), q)),
                        el('span', { class: 'kx-row__sub', text: sub })
                    ]),
                    el('span', { class: 'kx-row__end', 'aria-hidden': 'true' }, [icon('fa-chevron-right')])
                ]);
                row.addEventListener('click', function () { showPin(r, true); });
                grid.appendChild(row);
            });
            results.appendChild(grid);
            if (res.more) { results.appendChild(el('p', { class: 'kx-note kx-search__more' }, [icon('fa-filter'), el('span', { text: t('signin.more') })])); }
        }
        var timer = null;
        function run() {
            var q = input.value.replace(/\s+/g, ' ').trim();
            lastQuery = q;
            clearBtn.hidden = input.value === '';
            var letters = q.replace(/[^A-Za-zÀ-ɏ]/g, '');
            if (letters.length < 2) { searchSeq++; renderHint(); return; }
            var seq = ++searchSeq;
            results.setAttribute('aria-busy', 'true');
            K.api.post('search', { q: q, scope: mode }).then(function (res) {
                if (seq !== searchSeq) { return; }
                results.removeAttribute('aria-busy');
                renderResults(q, res);
            }, function (err) {
                if (seq !== searchSeq) { return; }
                results.removeAttribute('aria-busy');
                clear(results);
                results.appendChild(alertBox(err.code === 'rate_limited' ? 'warn' : 'bad', err.message));
            });
        }
        input.addEventListener('input', function () {
            clearBtn.hidden = input.value === '';
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(run, 250);
        });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (timer) { clearTimeout(timer); } run(); } });
        clearBtn.addEventListener('click', function () { input.value = ''; lastQuery = ''; clearBtn.hidden = true; searchSeq++; renderHint(); input.focus(); });
        if (initial) { input.value = initial; run(); } else { renderHint(); }
        setTimeout(function () { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } }, 30);
    }

    // ------------------------------------------------------------------ PIN
    function personHeader(p, promptText, hintText) {
        return [
            el('span', { class: 'kx-avatar kx-avatar--lg', 'aria-hidden': 'true', text: p.initials || '?' }),
            el('h1', { class: 'kx-pin__hello', id: 'kx-pin-hello', text: t('pin.hello', { first: p.first || p.name || '' }) }),
            el('span', { class: 'kx-pin__who', text: p.dept ? t('pin.who', { name: p.name || '', dept: p.dept }) : (p.name || '') }),
            promptText ? el('p', { class: 'kx-pin__prompt', text: promptText }) : null,
            hintText ? el('p', { class: 'kx-note kx-pin__hint' }, [icon('fa-clock'), el('span', { text: hintText })]) : null
        ];
    }

    function backLink(p, personalFirst) {
        var label = personalFirst ? t('pin.not_you', { first: p.first || '' }) : t('pin.back');
        var a = el('button', { type: 'button', class: 'kx-btn kx-btn--link kx-pin__back' }, [icon('fa-arrow-left'), el('span', { text: label })]);
        a.addEventListener('click', function () { showSearch(''); });
        return a;
    }

    /** The split PIN card: left = person + prompt + messages + links, right = keypad. */
    function pinCard(p, opts) {
        var left = el('div', { class: 'kx-pin__left' });
        personHeader(p, opts.prompt, opts.hint).forEach(function (n) { if (n) { left.appendChild(n); } });
        var dotsHost = el('div', { class: 'kx-pin__dots' });
        var msg = el('div', { class: 'kx-signin__msg', 'aria-live': 'polite' });
        left.appendChild(dotsHost);
        left.appendChild(msg);
        left.appendChild(el('div', { class: 'kx-pin__spacer' }));
        var links = el('div', { class: 'kx-pin__links' });
        left.appendChild(links);
        var side = el('div', { class: 'kx-card__side kx-pin__side' });
        var card = el('section', { class: 'kx-card kx-card--split kx-pin', 'aria-labelledby': 'kx-pin-hello' }, [left, side]);
        return { card: card, left: left, side: side, msg: msg, links: links, dotsHost: dotsHost };
    }

    function mountKeypad(c, o) {
        keypadObj = K.ui.keypad(c.side, {
            minLen: o.minLen, maxLen: o.maxLen,
            labels: { group: t('pin.keypad'), okAria: o.okAria || t('pin.ok_aria') },
            onSubmit: o.onSubmit
        });
        var dots = keypadObj.el.querySelector('.kx-dots');
        if (dots) { c.dotsHost.appendChild(dots); }   // the dots sit under the prompt, as in the mockup
        return keypadObj;
    }

    function showPin(p, fromSearch) {
        var personalFirst = !!(page.personal && page.personal.contact_id === p.contact_id);
        var c = pinCard(p, { prompt: '', hint: '' });
        var note = el('p', { class: 'kx-note kx-pin__foot' }, [icon('fa-lock'), el('span', { text: t('pin.lock_note', { n: page.lock_note ? page.lock_note.n : 5, minutes: page.lock_note ? page.lock_note.minutes : 15 }) })]);
        c.links.appendChild(backLink(p, personalFirst && !fromSearch));
        setScreen('pin', el('div', { class: 'kx-center kx-pin__page' }, [c.card, note]));
        c.side.appendChild(el('span', { class: 'kx-spin', 'aria-hidden': 'true' }));
        K.api.post('pick', { contact_id: p.contact_id, sig: p.sig, role: mode }).then(function (info) {
            if (screen !== 'pin') { return; }
            clear(c.side);
            var promptNode = el('p', { class: 'kx-pin__prompt', text: info.prompt === 'odoo' ? t('pin.prompt_odoo') : t('pin.prompt_local') });
            c.left.insertBefore(promptNode, c.dotsHost);
            if (info.prompt === 'odoo') {
                c.left.insertBefore(el('p', { class: 'kx-note kx-pin__hint' }, [icon('fa-clock'), el('span', { text: t('pin.odoo_hint') })]), c.dotsHost);
            }
            if (info.prompt === 'setup_needed') { showSetup(p, mode === 'trainer' ? t('pin.trainer_setup_needed') : t('pin.setup_needed')); return; }
            if (info.prompt === 'unavailable') { setMsg(c.msg, 'warn', t('pin.unavailable'), 'fa-plug'); promptNode.hidden = true; return; }
            if (info.hard_locked) { setMsg(c.msg, 'bad', t('pin.locked_hard'), 'fa-lock'); return; }
            if (info.locked) { setMsg(c.msg, 'warn', t('pin.locked', { minutes: info.locked.minutes }), 'fa-lock'); return; }
            var odoo = info.prompt === 'odoo';
            var have = el('button', { type: 'button', class: 'kx-btn kx-btn--link' }, [icon('fa-ticket-alt'), el('span', { text: t('pin.have_code') })]);
            have.addEventListener('click', function () { showSetup(p, ''); });
            if (!odoo) { c.links.appendChild(have); }
            mountKeypad(c, {
                minLen: 4, maxLen: odoo ? 12 : 6,
                onSubmit: function (pin) {
                    keypadObj.setBusy(true);
                    setMsg(c.msg, 'info', t('pin.checking'), 'fa-circle-notch');
                    var kp = keypadObj;
                    K.api.post('pin_login', { contact_id: p.contact_id, sig: p.sig, pin: pin, role: mode }, { timeoutMs: 30000 }).then(function (res) {
                        pin = null;
                        location.replace(res && /^\/kiosk\/[a-z_]+\.php$/.test(res.next || '') ? res.next : '/kiosk/');
                    }, function (err) {
                        pin = null;
                        if (kp !== keypadObj) { return; }
                        kp.clear();
                        var e = pinError(err, 'pin');
                        if (e.go === 'setup') { showSetup(p, mode === 'trainer' ? t('pin.trainer_setup_needed') : t('pin.setup_needed')); return; }
                        if (e.go === 'search') { showSearch(''); K.ui.toast(e.text, 'bad'); return; }
                        setMsg(c.msg, e.kind, e.text, e.ic);
                        kp.setBusy(!!e.stop);
                    });
                    kp.clear();
                }
            });
        }, function (err) {
            if (screen !== 'pin') { return; }
            clear(c.side);
            var e = pinError(err, 'pin');
            if (e.go === 'search' || err.code === 'not_trainer') { showSearch(''); K.ui.toast(err.message, 'bad'); return; }
            setMsg(c.msg, e.kind, e.text, e.ic);
        });
    }

    // ------------------------------------------------------------------ setup code -> new PIN -> confirm
    function showSetup(p, why) {
        var c = pinCard(p, { prompt: t('setup.title'), hint: '' });
        c.left.insertBefore(el('p', { class: 'kx-note', text: t('setup.body') }), c.dotsHost);
        c.links.appendChild(backLink(p, false));
        setScreen('setup', el('div', { class: 'kx-center kx-pin__page' }, [c.card, el('p', { class: 'kx-note kx-pin__foot' }, [icon('fa-info-circle'), el('span', { text: t('setup.no_slip') })])]));
        if (why) { setMsg(c.msg, 'info', why, 'fa-key'); }
        mountKeypad(c, {
            minLen: 8, maxLen: 8, okAria: t('shell.keypad_ok'),
            onSubmit: function (code) {
                var kp = keypadObj;
                kp.setBusy(true);
                setMsg(c.msg, 'info', t('pin.checking'), 'fa-circle-notch');
                K.api.post('setup_code_verify', { contact_id: p.contact_id, sig: p.sig, code: code }, { timeoutMs: 30000 }).then(function (res) {
                    code = null;
                    showNewPin(p, res.setup_token, '');
                }, function (err) {
                    code = null;
                    if (kp !== keypadObj) { return; }
                    kp.clear();
                    var e = pinError(err, 'setup');
                    if (e.go === 'search') { showSearch(''); K.ui.toast(e.text, 'bad'); return; }
                    setMsg(c.msg, e.kind, e.text, e.ic);
                    kp.setBusy(!!e.stop);
                });
                kp.clear();
            }
        });
    }

    function showNewPin(p, setupToken, why, first) {
        var confirming = typeof first === 'string';
        var c = pinCard(p, { prompt: confirming ? t('newpin.confirm_title') : t('newpin.title') });
        c.left.insertBefore(el('p', { class: 'kx-note', text: confirming ? t('newpin.confirm_body') : t('newpin.body') }), c.dotsHost);
        c.links.appendChild(backLink(p, false));
        setScreen('newpin', el('div', { class: 'kx-center kx-pin__page' }, [c.card]));
        if (why) { setMsg(c.msg, 'bad', why); }
        mountKeypad(c, {
            minLen: 6, maxLen: 6, okAria: t('shell.keypad_ok'),
            onSubmit: function (pin) {
                if (!confirming) { var v = pin; keypadObj.clear(); showNewPin(p, setupToken, '', v); v = null; return; }
                var kp = keypadObj;
                kp.setBusy(true);
                setMsg(c.msg, 'info', t('pin.checking'), 'fa-circle-notch');
                K.api.post('pin_create', { contact_id: p.contact_id, sig: p.sig, setup_token: setupToken, pin: first, pin2: pin, role: mode }, { timeoutMs: 30000 }).then(function (res) {
                    first = null; pin = null;
                    location.replace(res && /^\/kiosk\/[a-z_]+\.php$/.test(res.next || '') ? res.next : '/kiosk/');
                }, function (err) {
                    first = null; pin = null;
                    if (kp !== keypadObj) { return; }
                    var e = pinError(err, 'pin');
                    if (err.code === 'pin_policy' || err.code === 'pin_mismatch' || err.code === 'pin_format') { showNewPin(p, setupToken, e.text); return; }
                    if (err.code === 'validation') { showSetup(p, t('setup.wrong')); return; }
                    if (e.go === 'search') { showSearch(''); K.ui.toast(e.text, 'bad'); return; }
                    kp.clear();
                    setMsg(c.msg, e.kind, e.text, e.ic);
                    kp.setBusy(!!e.stop);
                });
                kp.clear();
            }
        });
    }

    // ------------------------------------------------------------------ boot
    /** Adopts a #d=<token> (or ?d=<token>) start URL: the token leaves the address bar before anything else happens. */
    function adoptFromHash() {
        var hash = TOKEN_RE.exec(location.hash || '') || QUERY_RE.exec(location.search || '');
        if (!hash) { return false; }
        try { history.replaceState(null, '', '/kiosk/'); } catch (e) { /* ignore */ }
        var ns = document.getElementById('kx-ns');
        if (ns) { ns.hidden = true; }
        adopt(hash[1], false);
        hash = null;
        return true;
    }
    // The start URL typed or opened while /kiosk/ is already showing is a same-document fragment change.
    window.addEventListener('hashchange', function () { adoptFromHash(); });
    if (adoptFromHash()) { return; }
    if (location.hash) { try { history.replaceState(null, '', '/kiosk/' + location.search); } catch (e) { /* ignore */ } }
    if (page.state === 'not_setup') { screen = 'notsetup'; wireNotSetup(); return; }
    if (page.state === 'switch') { showSwitch(); return; }
    home();
}());
