/*
 * Training kiosk runtime (P3 spec §5.1, lane K1) - the frozen window.Kiosk API every kiosk page
 * script builds on. No framework, no inline handlers (CSP), every string reaches the DOM through
 * textContent / DOM nodes, never innerHTML. Written in ES5 + Promise/fetch so an older iPad still
 * runs every page except YouTube/Vimeo lessons.
 *
 *   Kiosk.data()                                   the parsed k-page-data block
 *   Kiosk.api.post(action, body, {timeoutMs})      JSON POST  } X-Kiosk-Token on BOTH; errors reject with
 *   Kiosk.api.get(action, params, {timeoutMs})     JSON GET   } KioskError{status, code, message, data, fields};
 *                                                  401 session_ended => location.replace('/kiosk/'); with data.ended
 *                                                  (the device's temporary time ran out) - also on 403
 *                                                  device_not_enrolled - '/kiosk/?ended=<epoch>'
 *   Kiosk.t(key, vars), Kiosk.lang(), Kiosk.setLang(lang)
 *   Kiosk.idle.start({idleS, warnS, onWarn, onIdle}) / touch() / pause() / resume()
 *        touched ONLY by real input (pointerdown, keydown, wheel, scroll, touchstart) or while a
 *        <video>/embed reports playing (Kiosk.idle.playing()); lesson ticks never touch
 *   Kiosk.session.heartbeatLoop() / end(reason)    heartbeat every 60 s, only if touched since the last one
 *   Kiosk.ui.el / keypad / signaturePad / toast / confirm / busy
 *   Kiosk.guardBfcache()                           pageshow persisted => reload; replaceState on load
 *
 * Auto-start (this file runs before the page scripts): the header's EN|ES toggle and Done button
 * are wired, the bfcache guard is armed, and on a signed-in page the idle timer (default
 * "Still there?" dialog, then Done) and the heartbeat loop start. A page script may call
 * Kiosk.idle.start() again to replace the defaults; page.kiosk_idle === false opts out.
 * A TEMPORARY device (data.device.ends_in_s, counted by the server so a wrong device clock can't
 * loop): from 15 minutes before its end the header shows "Ends 3:13 PM" (plus a toast at 15 and at
 * 5 minutes), and once the time is up the page goes to /kiosk/?ended=<end>, which says the training
 * time ended (a device still working ignores ?ended=).
 */
(function () {
    'use strict';

    var API = '/kiosk/api.php';
    var DEFAULT_TIMEOUT_MS = 20000;
    var HEARTBEAT_MS = 60000;
    var SIGN_W = 1200;
    var SIGN_H = 400;
    var SIGN_INK = '#16232a';
    var SIGN_MIN_INK = 550;          // server MIN_INK 500 + margin
    var SIGN_ALPHA_MIN = 32;

    var DATA = null;
    var currentLang = 'en';

    // ---------------------------------------------------------------- data + strings
    function data() {
        if (DATA) { return DATA; }
        var node = document.getElementById('k-page-data');
        var parsed = {};
        if (node) {
            try { parsed = JSON.parse(node.textContent || '{}') || {}; } catch (e) { parsed = {}; }
        }
        if (!parsed.strings) { parsed.strings = { en: {}, es: {} }; }
        if (!parsed.idle) { parsed.idle = { idle_s: 180, warn_s: 30, absolute_left_s: null }; }
        if (!parsed.page) { parsed.page = {}; }
        DATA = parsed;
        currentLang = (parsed.lang === 'es') ? 'es' : 'en';
        return DATA;
    }

    function has(key) {
        var s = data().strings;
        return !!((s[currentLang] && Object.prototype.hasOwnProperty.call(s[currentLang], key))
            || (s.en && Object.prototype.hasOwnProperty.call(s.en, key)));
    }

    function t(key, vars) {
        var s = data().strings;
        var text = (s[currentLang] && s[currentLang][key]) || (s.en && s.en[key]) || key;
        if (vars) {
            text = String(text).replace(/\{([a-z_]+)\}/g, function (m, name) {
                return Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : m;
            });
        }
        return String(text);
    }

    function lang() { data(); return currentLang; }

    /** POSTs set_language, then reloads so server-rendered text follows (a page may cancel the 'kiosk:lang' event to re-render itself). */
    function setLang(l) {
        l = (l === 'es') ? 'es' : 'en';
        return api.post('set_language', { lang: l }).then(function (res) {
            currentLang = (res && res.lang === 'es') ? 'es' : 'en';
            data().lang = currentLang;
            document.documentElement.setAttribute('lang', currentLang);
            syncLangToggle();
            var ev;
            try { ev = new CustomEvent('kiosk:lang', { cancelable: true, detail: { lang: currentLang } }); } catch (e) { ev = null; }
            if (!ev || document.dispatchEvent(ev)) { location.reload(); }
            return currentLang;
        });
    }

    // ---------------------------------------------------------------- API
    function KioskError(status, code, message, extra, fields) {
        var err = new Error(message);
        err.name = 'KioskError';
        err.status = status;
        err.code = code;
        err.data = extra || null;
        err.fields = fields || {};
        return err;
    }

    function errorMessage(code, serverMessage) {
        if (code && has('err.' + code)) { return t('err.' + code); }
        if (currentLang === 'en' && serverMessage) { return String(serverMessage); }
        return t('err.server');
    }

    function request(method, action, body, params, opts) {
        opts = opts || {};
        var d = data();
        var url = (d.api || API) + '?action=' + encodeURIComponent(action);
        if (params) {
            Object.keys(params).forEach(function (k) {
                if (params[k] !== null && params[k] !== undefined) {
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k]));
                }
            });
        }
        var headers = { 'Accept': 'application/json', 'X-Kiosk-Token': d.csrf || '' };
        if (method === 'POST') { headers['Content-Type'] = 'application/json'; }
        if (d.video && d.video.run_id && d.video.lesson_uid) {
            headers['X-Kiosk-Video'] = String(d.video.run_id) + ':' + String(d.video.lesson_uid);
        }
        var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
        var timedOut = false;
        var timer = setTimeout(function () { timedOut = true; if (ctrl) { ctrl.abort(); } }, opts.timeoutMs || DEFAULT_TIMEOUT_MS);
        var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error' };
        if (ctrl) { init.signal = ctrl.signal; }
        if (method === 'POST') { init.body = JSON.stringify(body || {}); }
        return fetch(url, init).then(function (res) {
            clearTimeout(timer);
            return res.text().then(function (txt) {
                var json = null;
                try { json = txt ? JSON.parse(txt) : null; } catch (e) { json = null; }
                if (res.ok && json && json.ok === true) { return json.data; }
                var e = (json && json.error) ? json.error : {};
                var code = e.code || (res.status === 503 ? 'server' : 'server');
                var err = KioskError(res.status, code, errorMessage(code, e.message), json ? (json.data || null) : null, e.fields);
                var ended = json && json.data && typeof json.data.ended === 'number' ? '/kiosk/?ended=' + Math.floor(json.data.ended) : null;
                if (res.status === 401 && code === 'session_ended') {
                    stopTimers();
                    location.replace(ended || '/kiosk/');
                } else if (ended && res.status === 403 && code === 'device_not_enrolled') {
                    stopTimers();
                    location.replace(ended);
                }
                throw err;
            });
        }, function (netErr) {
            clearTimeout(timer);
            var code = timedOut ? 'timeout' : 'network';
            throw KioskError(0, code, errorMessage(code, null), null, null);
        });
    }

    var api = {
        post: function (action, body, opts) { return request('POST', action, body, null, opts); },
        get: function (action, params, opts) { return request('GET', action, null, params, opts); }
    };

    // ---------------------------------------------------------------- DOM helper
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) { return; }
                if (k === 'text') { node.textContent = String(v); }
                else if (k === 'class') { node.className = String(v); }
                else if (k === 'on') { Object.keys(v).forEach(function (ev) { node.addEventListener(ev, v[ev]); }); }
                else if (k === 'dataset') { Object.keys(v).forEach(function (dk) { node.dataset[dk] = String(v[dk]); }); }
                else if (k === 'hidden' || k === 'disabled' || k === 'checked') { node[k] = !!v; }
                else if (/^on/i.test(k)) { /* never inline handlers */ }
                else { node.setAttribute(k, v === true ? '' : String(v)); }
            });
        }
        append(node, children);
        return node;
    }

    function append(node, children) {
        if (children === null || children === undefined) { return; }
        if (!Array.isArray(children)) { children = [children]; }
        children.forEach(function (c) {
            if (c === null || c === undefined || c === false) { return; }
            node.appendChild((typeof c === 'string' || typeof c === 'number') ? document.createTextNode(String(c)) : c);
        });
    }

    function icon(name) {
        return el('i', { class: 'fas ' + (/^fa-[a-z0-9-]+$/.test(name) ? name : 'fa-circle'), 'aria-hidden': 'true' });
    }

    function busy(node, on) {
        if (!node) { return; }
        node.classList.toggle('is-busy', !!on);
        if (on) { node.setAttribute('aria-busy', 'true'); } else { node.removeAttribute('aria-busy'); }
        if ('disabled' in node) { node.disabled = !!on; }
    }

    // ---------------------------------------------------------------- toast + confirm
    var toastRegion = null;
    function toast(msg, kind) {
        if (!toastRegion || !document.body.contains(toastRegion)) {
            toastRegion = el('div', { class: 'kx-toasts', role: 'status', 'aria-live': 'polite' });
            document.body.appendChild(toastRegion);
        }
        kind = /^(info|warn|bad|ok)$/.test(kind || '') ? kind : 'info';
        var ic = { info: 'fa-info-circle', warn: 'fa-exclamation-triangle', bad: 'fa-times-circle', ok: 'fa-check-circle' }[kind];
        var node = el('div', { class: 'kx-toast kx-toast--' + kind }, [icon(ic), el('span', { text: String(msg) })]);
        toastRegion.appendChild(node);
        setTimeout(function () {
            node.classList.add('is-leaving');
            setTimeout(function () { if (node.parentNode) { node.parentNode.removeChild(node); } }, 300);
        }, kind === 'bad' ? 6000 : 4000);
        return node;
    }

    /** A modal dialog: resolves true (OK) or false (Cancel / Escape). Returns a Promise<bool>; .close() on it dismisses as false. */
    function confirmDialog(msg, okLabel, cancelLabel, opts) {
        opts = opts || {};
        var prevFocus = document.activeElement;
        var resolveFn;
        var p = new Promise(function (resolve) { resolveFn = resolve; });
        var titleId = 'kx-dlg-' + Math.random().toString(36).slice(2, 8);
        var body = el('p', { class: 'kx-dialog__body', id: titleId + 'b', text: String(msg) });
        var okBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl' }, [el('span', { text: okLabel || t('shell.ok') })]);
        var cancelBtn = cancelLabel === null ? null
            : el('button', { type: 'button', class: 'kx-btn kx-btn--ghost kx-btn--xl' }, [el('span', { text: cancelLabel || t('shell.cancel') })]);
        var card = el('div', { class: 'kx-dialog__card', role: 'alertdialog', 'aria-modal': 'true', 'aria-describedby': titleId + 'b',
            'aria-labelledby': opts.title ? titleId : null }, [
            opts.title ? el('h2', { class: 'kx-dialog__title', id: titleId, text: String(opts.title) }) : null,
            body,
            opts.extra || null,
            el('div', { class: 'kx-dialog__actions' }, [cancelBtn, okBtn])
        ]);
        var overlay = el('div', { class: 'kx-dialog' }, [card]);
        var done = false;
        function finish(val) {
            if (done) { return; }
            done = true;
            document.removeEventListener('keydown', onKey, true);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            document.body.classList.remove('kx-has-dialog');
            if (prevFocus && typeof prevFocus.focus === 'function' && document.body.contains(prevFocus)) {
                try { prevFocus.focus(); } catch (e) { /* ignore */ }
            }
            resolveFn(val);
        }
        function onKey(e) {
            if (e.key === 'Escape' && cancelBtn) { e.preventDefault(); finish(false); return; }
            if (e.key === 'Tab') {   // keep focus inside the dialog
                var f = [cancelBtn, okBtn].filter(Boolean);
                var i = f.indexOf(document.activeElement);
                e.preventDefault();
                f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
            }
        }
        okBtn.addEventListener('click', function () { finish(true); });
        if (cancelBtn) { cancelBtn.addEventListener('click', function () { finish(false); }); }
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(overlay);
        document.body.classList.add('kx-has-dialog');
        setTimeout(function () { okBtn.focus(); }, 0);
        p.close = function () { finish(false); };
        p.body = body;
        return p;
    }

    // ---------------------------------------------------------------- keypad
    /**
     * A PIN keypad: <button>s and dots only - there is never an <input> for a PIN (A9). The value
     * lives in this closure; clear() wipes it. A hardware keyboard (Windows) types digits, Backspace
     * and Enter while the keypad is on screen and nothing else has focus.
     */
    function keypad(container, o) {
        o = o || {};
        var minLen = Math.max(1, o.minLen || 4);
        var maxLen = Math.max(minLen, o.maxLen || 6);
        var labels = o.labels || {};
        var value = '';
        var isBusy = false;
        var typedWhileBusy = '';   // o.bufferWhileBusy: digits typed on a hardware keyboard while "Checking…" are kept for the next try
        var dotCount = maxLen <= 8 ? maxLen : Math.max(6, minLen);
        var dots = el('div', { class: 'kx-dots', 'aria-hidden': 'true' });
        var status = el('span', { class: 'kx-sr', role: 'status', 'aria-live': 'polite' });
        var keys = [];
        var grid = el('div', { class: 'kx-keypad', role: 'group', 'aria-label': labels.group || t('shell.keypad') });

        function key(label, cls, aria, fn) {
            var b = el('button', { type: 'button', class: 'kx-key' + (cls ? ' ' + cls : ''), 'aria-label': aria || null }, label);
            b.addEventListener('click', function (e) { e.preventDefault(); if (!isBusy) { fn(); } });
            keys.push(b);
            return b;
        }
        '123456789'.split('').forEach(function (d) { grid.appendChild(key(d, '', null, function () { press(d); })); });
        var del = key(icon('fa-backspace'), 'kx-key--fn', labels.delete || t('shell.keypad_delete'), back);
        grid.appendChild(del);
        grid.appendChild(key('0', '', null, function () { press('0'); }));
        var ok = key(el('span', { text: labels.ok || t('shell.keypad_ok') }), 'kx-key--ok', labels.okAria || null, submit);
        grid.appendChild(ok);

        var wrap = el('div', { class: 'kx-pad' }, [dots, status, grid]);
        container.appendChild(wrap);

        function render() {
            var n = Math.max(dotCount, Math.min(maxLen, value.length + (value.length < maxLen ? 1 : 0)));
            while (dots.firstChild) { dots.removeChild(dots.firstChild); }
            for (var i = 0; i < n; i++) {
                dots.appendChild(el('span', { class: 'kx-dot' + (i < value.length ? ' is-filled' : (i === value.length ? ' is-next' : '')) }));
            }
            status.textContent = t('shell.keypad_status', { n: value.length, max: maxLen });
            ok.disabled = isBusy || value.length < minLen;
            del.disabled = isBusy || value.length === 0;
        }
        function changed() { render(); if (typeof o.onChange === 'function') { o.onChange(value.length); } }
        function press(d) { if (value.length < maxLen) { value += d; changed(); } }
        function back() { if (value.length) { value = value.slice(0, -1); changed(); } }
        function submit() { if (value.length >= minLen && typeof o.onSubmit === 'function') { o.onSubmit(value); } }
        function onKey(e) {
            if (!document.body.contains(wrap) || document.body.classList.contains('kx-has-dialog')) { return; }
            var tgt = e.target;
            if (isBusy) {
                if (o.bufferWhileBusy && /^[0-9]$/.test(e.key) && !(tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA' || tgt.isContentEditable))) {
                    e.preventDefault();
                    if (typedWhileBusy.length < maxLen) { typedWhileBusy += e.key; }
                }
                return;
            }
            if (tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA' || tgt.isContentEditable)) { return; }
            if (/^[0-9]$/.test(e.key)) { e.preventDefault(); press(e.key); }
            else if (e.key === 'Backspace') { e.preventDefault(); back(); }
            else if (e.key === 'Enter' && (!tgt || tgt.tagName !== 'BUTTON')) { e.preventDefault(); submit(); }
        }
        document.addEventListener('keydown', onKey);
        render();
        return {
            el: wrap,
            clear: function () { value = ''; changed(); },
            value: function () { return value; },
            /** Types digits as if pressed (a hardware keyboard's digits typed before the keypad was ready). */
            feed: function (digits) { String(digits || '').replace(/[^0-9]/g, '').split('').forEach(function (d) { if (!isBusy) { press(d); } }); },
            setBusy: function (b) {
                var wasBusy = isBusy;
                isBusy = !!b;
                if (!isBusy && wasBusy && typedWhileBusy) { var q = typedWhileBusy; typedWhileBusy = ''; q.split('').forEach(function (d) { if (value.length < maxLen) { value += d; } }); }
                keys.forEach(function (k) { k.disabled = isBusy; });
                wrap.classList.toggle('is-busy', isBusy);
                if (isBusy) { wrap.setAttribute('aria-busy', 'true'); } else { wrap.removeAttribute('aria-busy'); }
                render();
            },
            destroy: function () {
                value = '';
                typedWhileBusy = '';
                document.removeEventListener('keydown', onKey);
                if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
            }
        };
    }

    // ---------------------------------------------------------------- signature pad
    /**
     * Finger signature. Strokes are kept as points in CSS pixels of the visible canvas (drawn at
     * the display DPR). toPng() renders them onto an OFFSCREEN 1200x400 canvas - scale to fit,
     * transparent background, ink #16232a - and returns that PNG as a data URL; inkPx() counts
     * the pixels with alpha >= 32 on that same image; isInked() = inkPx() >= 550 (server MIN_INK
     * 500 + margin). The name/date/baseline decorations are DOM, never part of the PNG. A resize
     * or rotation clears the pad and asks again.
     */
    /** A mouse-only screen (a Windows PC): wording says "mouse" instead of "finger". */
    function mouseOnly() {
        try {
            return !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches && !window.matchMedia('(any-pointer: coarse)').matches
                && !(navigator.maxTouchPoints > 0) && !('ontouchstart' in window));
        } catch (e) { return false; }
    }

    /** o.title: a heading shown on the same row as "Clear signature" (the mockup's layout). */
    function signaturePad(container, o) {
        o = o || {};
        var hereText = mouseOnly() ? t('shell.sign_here_mouse') : t('shell.sign_here');
        var canvas = el('canvas', { class: 'kx-sign__canvas', role: 'img', 'aria-label': hereText });
        var clearBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--ghost kx-sign__clear' }, [icon('fa-undo'), el('span', { text: t('shell.sign_clear') })]);
        var hint = el('span', { class: 'kx-sign__hint', 'aria-hidden': 'true', text: hereText });
        var note = el('p', { class: 'kx-note kx-sign__note', role: 'status', 'aria-live': 'polite', hidden: true });
        var meta = el('div', { class: 'kx-sign__meta', 'aria-hidden': 'true' }, [
            el('span', { text: o.name ? String(o.name) : '' }), el('span', { text: o.date ? String(o.date) : '' })
        ]);
        var area = el('div', { class: 'kx-sign__area' }, [
            canvas,
            el('span', { class: 'kx-sign__line', 'aria-hidden': 'true' }),
            el('span', { class: 'kx-sign__x', 'aria-hidden': 'true', text: '×' }),
            hint,
            meta
        ]);
        var wrap = el('div', { class: 'kx-sign' }, [el('div', { class: 'kx-sign__head' + (o.title ? ' has-title' : '') }, [
            o.title ? el('strong', { class: 'kx-sign__title', text: String(o.title) }) : null, clearBtn]), area, note]);
        container.appendChild(wrap);

        var strokes = [];        // [[{x,y}, ...], ...] in CSS px of the visible canvas
        var cur = null;
        var ctx = null;
        var cssW = 0;
        var cssH = 0;
        var cachedInk = null;

        function lineWidth(scale) { return Math.max(2, 3.2 * scale); }

        function size(asked) {
            var r = canvas.getBoundingClientRect();
            if (!r.width || !r.height) { return; }
            var changedSize = cssW && (Math.abs(r.width - cssW) > 2 || Math.abs(r.height - cssH) > 2);
            var dpr = window.devicePixelRatio || 1;
            cssW = r.width;
            cssH = r.height;
            canvas.width = Math.round(r.width * dpr);
            canvas.height = Math.round(r.height * dpr);
            ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = getComputedStyle(canvas).color || SIGN_INK;
            ctx.lineWidth = lineWidth(1);
            if (changedSize && strokes.length) {
                reset();
                note.textContent = t('shell.sign_again');
                note.hidden = false;
            } else if (asked !== 'keep') {
                redraw();
            }
        }
        function redraw() {
            if (!ctx) { return; }
            ctx.clearRect(0, 0, cssW, cssH);
            strokes.forEach(function (s) { drawStroke(ctx, s, 1, 0, 0); });
        }
        function drawStroke(c, s, scale, ox, oy) {
            if (!s.length) { return; }
            c.beginPath();
            c.moveTo(ox + s[0].x * scale, oy + s[0].y * scale);
            if (s.length === 1) {
                c.lineTo(ox + s[0].x * scale + 0.01, oy + s[0].y * scale + 0.01);
            }
            for (var i = 1; i < s.length; i++) { c.lineTo(ox + s[i].x * scale, oy + s[i].y * scale); }
            c.stroke();
        }
        function reset() {
            strokes = [];
            cur = null;
            cachedInk = null;
            if (ctx) { ctx.clearRect(0, 0, cssW, cssH); }
            area.classList.remove('is-inked');
            if (typeof o.onChange === 'function') { o.onChange(false); }
        }
        function pos(e) {
            var r = canvas.getBoundingClientRect();
            return { x: Math.max(0, Math.min(r.width, e.clientX - r.left)), y: Math.max(0, Math.min(r.height, e.clientY - r.top)) };
        }
        canvas.addEventListener('pointerdown', function (e) {
            if (!ctx) { size('keep'); }
            if (!ctx) { return; }
            e.preventDefault();
            note.hidden = true;
            cur = [pos(e)];
            strokes.push(cur);
            cachedInk = null;
            area.classList.add('is-inked');
            try { canvas.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
            drawStroke(ctx, cur, 1, 0, 0);
            touch();
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!cur || !ctx) { return; }
            e.preventDefault();
            var evs = (typeof e.getCoalescedEvents === 'function') ? e.getCoalescedEvents() : [e];
            if (!evs.length) { evs = [e]; }
            evs.forEach(function (ce) {
                var p = pos(ce);
                var last = cur[cur.length - 1];
                if (Math.abs(p.x - last.x) + Math.abs(p.y - last.y) < 0.8) { return; }
                ctx.beginPath();
                ctx.moveTo(last.x, last.y);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                cur.push(p);
            });
        });
        function endStroke() {
            if (!cur) { return; }
            cur = null;
            cachedInk = null;
            touch();
            if (typeof o.onChange === 'function') { o.onChange(isInked()); }
        }
        canvas.addEventListener('pointerup', endStroke);
        canvas.addEventListener('pointercancel', endStroke);
        canvas.addEventListener('pointerleave', function (e) { if (cur && e.pointerType === 'mouse') { endStroke(); } });
        clearBtn.addEventListener('click', function () { note.hidden = true; reset(); });

        function offscreen() {
            var off = document.createElement('canvas');
            off.width = SIGN_W;
            off.height = SIGN_H;
            var c = off.getContext('2d');
            c.clearRect(0, 0, SIGN_W, SIGN_H);
            if (cssW && cssH && strokes.length) {
                var scale = Math.min(SIGN_W / cssW, SIGN_H / cssH);
                var ox = (SIGN_W - cssW * scale) / 2;
                var oy = (SIGN_H - cssH * scale) / 2;
                c.lineCap = 'round';
                c.lineJoin = 'round';
                c.strokeStyle = SIGN_INK;
                c.lineWidth = lineWidth(scale);
                strokes.forEach(function (s) { drawStroke(c, s, scale, ox, oy); });
            }
            return off;
        }
        function inkPx() {
            if (cachedInk !== null) { return cachedInk; }
            if (!strokes.length) { cachedInk = 0; return 0; }
            var px = offscreen().getContext('2d').getImageData(0, 0, SIGN_W, SIGN_H).data;
            var n = 0;
            for (var i = 3; i < px.length; i += 4) { if (px[i] >= SIGN_ALPHA_MIN) { n++; } }
            cachedInk = n;
            return n;
        }
        function isInked() { return inkPx() >= SIGN_MIN_INK; }

        var resizeTimer = null;
        function onResize() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { size(); }, 120);
        }
        window.addEventListener('resize', onResize);
        window.addEventListener('orientationchange', onResize);
        setTimeout(function () { size(); }, 0);

        return {
            el: wrap,
            toPng: function () { return strokes.length ? offscreen().toDataURL('image/png') : null; },
            clear: function () { note.hidden = true; reset(); },
            isInked: isInked,
            inkPx: inkPx,
            destroy: function () {
                window.removeEventListener('resize', onResize);
                window.removeEventListener('orientationchange', onResize);
                if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
            }
        };
    }

    // ---------------------------------------------------------------- idle + session
    var idleOpts = null;
    var lastTouch = Date.now();
    var lastBeat = Date.now();
    var touchedSinceBeat = false;
    var idlePaused = false;
    var warnShown = false;
    var warnDialog = null;
    var idleTimer = null;
    var beatTimer = null;
    var ending = false;
    var absWarned = false;
    var absDeadline = null;

    function touch() {
        if (warnDialog) { return; }   // the default "Still there?" dialog needs a deliberate tap on its button
        lastTouch = Date.now();
        touchedSinceBeat = true;
        warnShown = false;
    }

    /** Check-in and hand-off are left only with the trainer PIN: their screens offer no sign-out. */
    function restrictedRole() {
        var s = data().session;
        return !!(s && (s.role === 'checkin' || s.role === 'handoff'));
    }

    /** "Still there?" for a page's own onWarn: the seconds count down on screen (a page's custom warning is not refreshed by idleCheck). */
    function idleDialog(left) {
        var d = confirmDialog(t('shell.idle_body', { seconds: left }), t('shell.idle_stay'), restrictedRole() ? null : t('shell.idle_leave'), { title: t('shell.idle_title') });
        var end = Date.now() + Math.max(1, Number(left) || 1) * 1000;
        var timer = setInterval(function () {
            if (d.body) { d.body.textContent = t('shell.idle_body', { seconds: Math.max(0, Math.ceil((end - Date.now()) / 1000)) }); }
        }, 1000);
        d.then(function () { clearInterval(timer); });
        return d;
    }

    function defaultWarn(left) {
        var d = confirmDialog(t('shell.idle_body', { seconds: left }), t('shell.idle_stay'), restrictedRole() ? null : t('shell.idle_leave'), { title: t('shell.idle_title') });
        warnDialog = d;
        d.then(function (stay) {
            warnDialog = null;
            if (!warnShown) { return; }
            warnShown = false;
            if (stay) {
                touch();
                beat(true);   // the server's idle clock restarts now too
            } else {
                endSession('done');
            }
        });
    }

    function idleCheck() {
        if (!idleOpts || idlePaused || ending) { return; }
        var now = Date.now();
        var idleMs = idleOpts.idleS * 1000;
        var warnMs = Math.max(0, idleOpts.warnS) * 1000;
        var quiet = now - lastTouch;
        if (quiet >= idleMs) {
            warnShown = false;
            if (warnDialog) { var wd = warnDialog; warnDialog = null; wd.close(); }
            if (typeof idleOpts.onIdle === 'function') { idleOpts.onIdle(); } else { endSession('idle'); }
            lastTouch = now;   // an onIdle that keeps the page does not fire again at once
            return;
        }
        if (warnMs > 0 && quiet >= idleMs - warnMs) {
            var left = Math.max(1, Math.ceil((idleMs - quiet) / 1000));
            if (!warnShown) {
                warnShown = true;
                if (typeof idleOpts.onWarn === 'function') { idleOpts.onWarn(left); } else { defaultWarn(left); }
            } else if (warnDialog && warnDialog.body) {
                warnDialog.body.textContent = t('shell.idle_body', { seconds: left });
            }
        }
        if (absDeadline !== null) {
            var absLeft = absDeadline - now;
            if (absLeft <= 0) { stopTimers(); location.replace('/kiosk/'); return; }
            if (!absWarned && absLeft <= 300000) {
                absWarned = true;
                toast(t('shell.absolute_warn', { minutes: Math.max(1, Math.ceil(absLeft / 60000)) }), 'warn');
            }
        }
    }

    var idle = {
        start: function (o) {
            o = o || {};
            var d = data();
            idleOpts = {
                idleS: Math.max(10, parseInt(o.idleS || (d.idle && d.idle.idle_s) || 180, 10)),
                warnS: (o.warnS === 0) ? 0 : Math.max(0, parseInt(o.warnS || (d.idle && d.idle.warn_s) || 30, 10)),
                onWarn: o.onWarn || null,
                onIdle: o.onIdle || null,
                custom: !!o.idleS
            };
            if (idleOpts.warnS >= idleOpts.idleS) { idleOpts.warnS = Math.floor(idleOpts.idleS / 3); }
            lastTouch = Date.now();
            warnShown = false;
            idlePaused = false;
            if (!idleTimer) { idleTimer = setInterval(idleCheck, 1000); }
        },
        touch: touch,
        /** A <video>/embed that is playing counts as activity (call on each timeupdate while playing). */
        playing: function () { if (!warnDialog) { lastTouch = Date.now(); touchedSinceBeat = true; warnShown = false; } },
        pause: function () { idlePaused = true; },
        resume: function () { idlePaused = false; lastTouch = Date.now(); },
        stop: function () { idleOpts = null; if (idleTimer) { clearInterval(idleTimer); idleTimer = null; } }
    };

    function beat(force) {
        if (ending || !data().session) { return Promise.resolve(null); }
        if (!force && !touchedSinceBeat) { return Promise.resolve(null); }
        touchedSinceBeat = false;
        lastBeat = Date.now();
        return api.post('heartbeat', {}, { timeoutMs: 15000 }).then(function (res) {
            if (res && typeof res.absolute_left_s === 'number') { absDeadline = Date.now() + res.absolute_left_s * 1000; }
            if (res && res.idle_s && idleOpts && !idleOpts.custom) { idleOpts.idleS = Math.max(10, parseInt(res.idle_s, 10)); }
            return res;
        }, function (err) {
            if (err && err.code !== 'session_ended') { touchedSinceBeat = true; }   // try again next round
            return null;
        });
    }

    function endSession(reason) {
        if (ending) { return Promise.resolve(); }
        ending = true;
        stopTimers();
        reason = (reason === 'idle') ? 'idle' : 'done';
        var go = function (next) { location.replace(typeof next === 'string' && /^\/kiosk\//.test(next) ? next : '/kiosk/'); };
        return api.post('end', { reason: reason }, { timeoutMs: 8000 }).then(function (res) { go(res && res.next); }, function () { go('/kiosk/'); });
    }

    function stopTimers() {
        if (idleTimer) { clearInterval(idleTimer); idleTimer = null; }
        if (beatTimer) { clearInterval(beatTimer); beatTimer = null; }
    }

    var session = {
        heartbeatLoop: function () {
            if (beatTimer || !data().session) { return; }
            beatTimer = setInterval(function () { beat(false); }, HEARTBEAT_MS);
        },
        end: endSession,
        beat: function () { return beat(true); }
    };

    // ---------------------------------------------------------------- bfcache + shell wiring
    function guardBfcache() {
        window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload(); } });
        try { history.replaceState({ kx: 1 }, '', location.href); } catch (e) { /* ignore */ }
    }

    function syncLangToggle() {
        var btns = document.querySelectorAll('[data-kx-lang]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('aria-pressed', btns[i].getAttribute('data-kx-lang') === currentLang ? 'true' : 'false');
        }
    }

    function wireShell() {
        var btns = document.querySelectorAll('[data-kx-lang]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function (e) {
                var l = e.currentTarget.getAttribute('data-kx-lang');
                if (l === currentLang) { return; }
                var b = e.currentTarget;
                busy(b, true);
                setLang(l).then(null, function (err) {
                    busy(b, false);
                    toast(err && err.message ? err.message : t('err.server'), 'bad');
                });
            });
        }
        var done = document.querySelectorAll('.kx-done');
        for (var j = 0; j < done.length; j++) {
            done[j].addEventListener('click', function (e) { busy(e.currentTarget, true); endSession('done'); });
        }
        // Real input keeps a session alive; nothing else does.
        ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, touch, { capture: true, passive: true });
        });
        window.addEventListener('scroll', touch, { capture: true, passive: true });
        // A <video> element that is playing counts as activity (media events don't bubble; capture sees them).
        var lastPlayTouch = 0;
        document.addEventListener('timeupdate', function (e) {
            var v = e.target;
            if (v && v.tagName === 'VIDEO' && !v.paused && !v.ended && Date.now() - lastPlayTouch > 5000) {
                lastPlayTouch = Date.now();
                idle.playing();
            }
        }, true);
        // Coming back from sleep: the interval did not run; check at once.
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { idleCheck(); } });
    }

    // ---------------------------------------------------------------- temporary device: its end time
    var DEVICE_WARN_MS = 15 * 60000;
    function deviceEnd() {
        var dv = data().device;
        if (!dv || typeof dv.ends_in_s !== 'number' || !dv.ends_at) { return; }
        var deadline = Date.now() + Math.max(0, dv.ends_in_s) * 1000;
        var chip = null;
        var toasted = { 15: dv.ends_in_s * 1000 <= DEVICE_WARN_MS, 5: false };   // the chip already says it on a page opened late
        var timer = null;
        function check() {
            var left = deadline - Date.now();
            if (left <= -2000) {   // a little after the server's end: Home says the training time ended (even if the cron got there first)
                if (timer) { clearInterval(timer); timer = null; }
                stopTimers();
                location.replace(typeof dv.ends_epoch === 'number' ? '/kiosk/?ended=' + Math.floor(dv.ends_epoch) : '/kiosk/');
                return;
            }
            if (left > DEVICE_WARN_MS) { return; }
            if (!chip) {
                chip = el('span', { class: 'kx-endchip', title: t('shell.device_ends', { time: dv.ends_at }) }, [icon('fa-hourglass-end'),
                    el('span', { text: t('shell.device_ends_chip', { time: dv.ends_at }) })]);
                var host = document.querySelector('.kx-top__right');
                if (host) { host.insertBefore(chip, host.firstChild); }
            }
            // A toast when the 15-minute mark passes on this page, and once in the last 5 minutes.
            var mark = left <= 5 * 60000 ? 5 : 15;
            if (!toasted[mark]) {
                toasted[mark] = true;
                toasted[15] = true;
                toast(t('shell.device_ends', { time: dv.ends_at }), 'warn');
            }
        }
        check();
        timer = setInterval(check, 5000);
    }

    function init() {
        var d = data();
        document.documentElement.setAttribute('lang', currentLang);
        syncLangToggle();
        wireShell();
        guardBfcache();
        deviceEnd();
        if (d.session) {
            if (d.idle && typeof d.idle.absolute_left_s === 'number') { absDeadline = Date.now() + d.idle.absolute_left_s * 1000; }
            if (d.page.kiosk_idle !== false) { idle.start({}); }
            session.heartbeatLoop();
        }
    }

    window.Kiosk = {
        data: data,
        api: api,
        t: t,
        has: has,
        lang: lang,
        setLang: setLang,
        idle: idle,
        session: session,
        ui: { el: el, icon: icon, keypad: keypad, signaturePad: signaturePad, toast: toast, confirm: confirmDialog, busy: busy, idleDialog: idleDialog },
        mouseOnly: mouseOnly,
        guardBfcache: guardBfcache,
        KioskError: KioskError
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
