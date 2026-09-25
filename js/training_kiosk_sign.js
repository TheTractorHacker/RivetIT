/*
 * Sign to finish + receipt (kiosk/sign.php, P3 spec §5.6, mockup Kiosk-SignOff).
 * Form: steps strip, attestation, the kiosk signature pad (when the course requires it; the PNG is
 * checked client-side with isInked() before the PIN keypad is enabled), PIN keypad (buttons and
 * dots only), a greyed receipt preview and "Sign & finish" -> POST attest. The PIN lives only in
 * the keypad closure and is cleared after every call.
 * Receipt: Recorded / pending texts, certificate number, score, expiry, achievements; back to
 * me.php after 8 s or on "Back to my training".
 */
(function () {
    'use strict';
    var K = window.Kiosk;
    if (!K) { return; }
    var el = K.ui.el;
    var icon = K.ui.icon;
    var t = K.t;
    var root = document.getElementById('kl-sign');
    if (!root) { return; }
    var P = K.data().page || {};
    var S = K.data().session || {};
    var lang = K.lang();

    function date(ymd, long) {
        if (!ymd || !/^\d{4}-\d{2}-\d{2}/.test(ymd)) { return ''; }
        var p = ymd.slice(0, 10).split('-');
        try {
            return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).toLocaleDateString(lang === 'es' ? 'es-MX' : 'en-US',
                long ? { month: 'long', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) { return ymd.slice(0, 10); }
    }
    function pct(v) { return v === null || v === undefined || v === '' ? '' : Math.round(Number(v)) + '%'; }
    function medal(a) {
        var ic = /^[a-z0-9-]+$/.test(String(a.icon || '')) ? 'fa-' + String(a.icon).replace(/^fa-/, '') : 'fa-medal';
        var m = el('span', { class: 'kl-ach__medal', 'aria-hidden': 'true' }, icon(ic));
        if (/^#[0-9a-fA-F]{6}$/.test(String(a.color || ''))) { m.style.setProperty('--kl-ach', a.color); }
        return m;
    }

    if (P.mode === 'receipt') { renderReceipt(P.receipt || {}); return; }
    if (P.mode !== 'form') { location.replace('/kiosk/me.php'); return; }

    // ---------------------------------------------------------------- form
    var needSig = !!P.requires_signature;
    var steps = el('ol', { class: 'kl-steps', 'aria-label': t('sign.title') }, [
        el('li', { class: 'kl-steps__item is-done' }, [el('span', { class: 'kl-steps__dot', 'aria-hidden': 'true' }, icon('fa-check')), el('span', { text: t('sign.step_lessons') })]),
        P.has_exam ? el('li', { class: 'kl-steps__item is-done' }, [el('span', { class: 'kl-steps__dot', 'aria-hidden': 'true' }, icon('fa-check')), el('span', { text: t('sign.step_exam') })]) : null,
        el('li', { class: 'kl-steps__item is-current', 'aria-current': 'step' }, [el('span', { class: 'kl-steps__dot', text: P.has_exam ? '3' : '2' }), el('span', { text: t('sign.step_sign') })])
    ]);
    root.appendChild(el('header', { class: 'kl-signhead' }, [
        el('div', null, [el('h1', { class: 'kl-h1', text: t('sign.title') }), el('p', { class: 'kl-sub', text: needSig ? t('sign.sub') : t('sign.sub_nosig') })]),
        steps
    ]));

    var left = el('div', { class: 'kl-signmain' });
    var right = el('aside', { class: 'kl-signpin' });
    left.appendChild(el('section', { class: 'kl-attest' }, [
        el('div', { class: 'kl-kicker' }, [icon('fa-shield-alt'), el('span', { text: t('sign.attestation') })]),
        el('p', { class: 'kl-attest__text', text: String(P.attestation || '') })
    ]));
    var pad = null;
    var inked = !needSig;
    if (needSig) {
        var padSec = el('section', { class: 'kl-signpad' }, [el('h2', { class: 'kl-h3', text: t('sign.sign_with_finger') })]);
        left.appendChild(padSec);
        pad = K.ui.signaturePad(padSec, {
            name: S.name || '', date: date(P.today),
            onChange: function (ok) { inked = !!ok; sync(); }
        });
    }
    var status = el('p', { class: 'kl-signstatus', role: 'status', 'aria-live': 'polite' });
    var pinBox = el('div', { class: 'kl-signpin__pad' });
    right.appendChild(el('h2', { class: 'kl-h3 kl-center', text: t('sign.pin_title') }));
    right.appendChild(el('p', { class: 'kl-muted kl-center', text: t('sign.pin_sub') }));
    right.appendChild(pinBox);
    right.appendChild(el('p', { class: 'kl-muted kl-center kl-small', text: t('sign.forgot') }));
    var pinLen = 0;
    var busy = false;
    var keypad = K.ui.keypad(pinBox, {
        minLen: 4, maxLen: 12,
        onChange: function (v) { pinLen = typeof v === 'string' ? v.length : (typeof v === 'number' ? v : keypad.value().length); sync(); },
        onSubmit: function () { submit(); }
    });
    root.appendChild(el('div', { class: 'kl-signgrid' }, [left, right]));

    var finishBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl kl-finish' }, [icon('fa-pen-nib'), el('span', { text: t('sign.finish') })]);
    finishBtn.addEventListener('click', submit);
    var preview = el('section', { class: 'kl-preview', 'aria-label': t('sign.receipt_preview') }, [
        el('span', { class: 'kl-preview__icon', 'aria-hidden': 'true' }, icon('fa-receipt')),
        el('div', { class: 'kl-preview__body' }, [
            el('div', { class: 'kl-kicker' }, [el('span', { text: t('sign.receipt_preview') }), el('span', { class: 'kl-muted', text: ' · ' + t('sign.receipt_preview_note') })]),
            el('div', { class: 'kl-preview__row' }, [
                el('span', { class: 'kl-chip kl-chip--ok' }, [icon('fa-check'), el('span', { text: t('sign.recorded') })]),
                P.kind === 'document' ? null : el('span', null, [el('span', { class: 'kl-muted', text: t('sign.certificate') + ' ' }),
                    el('strong', { text: t(P.pending === 'evaluation' ? 'sign.cert_after_eval' : (P.pending === 'session' ? 'sign.cert_after_session' : 'sign.cert_after')) })]),
                P.score_pct !== null && P.score_pct !== undefined ? el('span', null, [el('span', { class: 'kl-muted', text: t('sign.score') + ' ' }), el('strong', { text: pct(P.score_pct) })]) : null,
                // the expiry runs from the day the course is complete, which for a blended course is not today
                P.pending ? null : (P.validity_months ? el('span', null, [el('span', { class: 'kl-muted', text: t('sign.expires') + ' ' }), el('strong', { text: addMonths(P.today, P.validity_months) })])
                    : el('span', { class: 'kl-muted', text: t('sign.no_expiry') }))
            ])
        ])
    ]);
    root.appendChild(el('div', { class: 'kl-signfoot' }, [preview, el('div', { class: 'kl-signfoot__act' }, [status, finishBtn])]));

    function addMonths(ymd, m) {
        if (!ymd) { return ''; }
        var p = ymd.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1 + Number(m), Number(p[2]));
        var s = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        return date(s);
    }
    function sync() {
        var len = keypad && typeof keypad.value === 'function' ? keypad.value().length : pinLen;
        var ok = inked && len >= 4 && !busy;
        finishBtn.disabled = !ok;
        pinBox.classList.toggle('is-waiting', !inked);
        if (typeof keypad.setBusy === 'function') { keypad.setBusy(busy || !inked); }
        if (busy) { status.textContent = t('sign.sending'); }
        else if (!inked) { status.textContent = t('sign.need_signature'); }
        else if (len < 4) { status.textContent = t('sign.need_pin'); }
        else { status.textContent = t('sign.ready'); }
    }
    function fail(e) {
        busy = false;
        K.ui.busy(finishBtn, false);
        keypad.clear();
        var msg = (e && e.message) ? e.message : t('err.server');
        var d = (e && e.data && typeof e.data === 'object') ? e.data : {};
        if (e && e.code === 'pin_wrong' && typeof d.tries_left === 'number') { msg = t('course.e_pin_wrong_n', { n: d.tries_left }); }
        else if (e && e.code === 'pin_wrong' && !K.has('err.pin_wrong')) { msg = t('course.e_pin_wrong'); }
        else if (e && e.code === 'pin_locked' && d.minutes) { msg = t('course.e_pin_locked', { minutes: d.minutes }); }
        else if (e && e.code === 'pin_locked_hard' && !K.has('err.pin_locked_hard')) { msg = t('course.e_pin_locked_hard'); }
        else if (e && (e.code === 'signature_invalid' || e.code === 'signature_empty')) {
            msg = K.has('err.' + e.code) ? msg : t('course.e_signature');
            if (pad) { pad.clear(); inked = false; }
        } else if (e && e.code === 'records_unavailable' && !K.has('err.records_unavailable')) { msg = t('course.e_records'); }
        K.ui.toast(msg, 'bad');
        sync();
        status.textContent = msg;
    }
    function submit() {
        if (busy || finishBtn.disabled) { return; }
        var png = null;
        if (needSig) {
            if (!pad || !pad.isInked()) { inked = false; sync(); return; }
            png = pad.toPng();
        }
        var pin = keypad.value();
        keypad.clear();   // never kept after the call
        busy = true;
        K.ui.busy(finishBtn, true);
        sync();
        K.api.post('attest', { run_id: P.run_id, signature_png: png, pin: pin }, { timeoutMs: 30000 }).then(function (r) {
            pin = '';
            location.replace('/kiosk/sign.php?run=' + encodeURIComponent(String(P.run_id)) + '&receipt=1');
            // The receipt reloads from the DB; show it right away too in case the reload is slow.
            renderReceipt(r || {});
        }, function (e) { pin = ''; fail(e); });
    }
    sync();

    // ---------------------------------------------------------------- receipt
    function renderReceipt(r) {
        while (root.firstChild) { root.removeChild(root.firstChild); }
        var st = r.status || 'recorded';
        var doc = r.kind === 'document';
        var name = r.course_name || P.course_name || '';
        var title = st === 'pending_session' ? t('sign.pending_session_title') : (st === 'pending_evaluation' ? t('sign.pending_eval_title')
            : (doc ? t('sign.done_ack_title') : t('sign.done_title', { first: S.first || '' })));
        var body = st === 'pending_session' ? t('sign.pending_session', { course: name }) : (st === 'pending_evaluation' ? t('sign.pending_eval', { course: name })
            : (doc ? t('sign.done_ack', { course: name }) : t('sign.done_recorded', { course: name })));
        var ok = st === 'recorded';
        var facts = [];
        facts.push(el('span', { class: 'kl-chip ' + (ok ? 'kl-chip--ok' : 'kl-chip--info') }, [icon(ok ? 'fa-check' : 'fa-hourglass-half'), el('span', { text: ok ? t('sign.chip_recorded') : t('sign.chip_pending') })]));
        if (ok && !doc) { facts.push(fact(t('sign.certificate'), r.cert_number || '—', true)); }
        if (r.score_pct !== null && r.score_pct !== undefined && r.score_pct !== '') { facts.push(fact(t('sign.score'), pct(r.score_pct))); }
        if (ok && r.completed_on) { facts.push(fact(doc ? t('sign.acknowledged_on') : t('sign.completed_on'), date(r.completed_on))); }
        if (ok && !doc) { facts.push(fact(t('sign.expires'), r.expires_on ? date(r.expires_on) : t('sign.no_expiry'))); }
        var card = el('section', { class: 'kl-receipt' + (ok ? ' is-ok' : ' is-pending') }, [
            el('span', { class: 'kl-receipt__badge', 'aria-hidden': 'true' }, icon(ok ? 'fa-check' : 'fa-user-clock')),
            el('h1', { class: 'kl-receipt__title', tabindex: '-1', text: title }),
            el('p', { class: 'kl-receipt__body', text: body }),
            el('div', { class: 'kl-receipt__facts' }, facts)
        ]);
        var awards = Array.isArray(r.achievements) ? r.achievements.filter(function (a) { return a && typeof a === 'object'; }) : [];
        if (awards.length) {
            card.appendChild(el('div', { class: 'kl-receipt__ach' }, [
                el('div', { class: 'kl-kicker' }, [icon('fa-medal'), el('span', { text: t('sign.achievements') })]),
                el('ul', { class: 'kl-ach kl-ach--row' }, awards.map(function (a) {
                    return el('li', { class: 'kl-ach__tile' }, [medal(a), el('strong', { class: 'kl-ach__name', text: String(a.name || '') }), el('span', { class: 'kl-muted', text: t('sign.ach_added') })]);
                }))
            ]));
        }
        var left = 8;
        var countdown = el('p', { class: 'kl-muted kl-center', role: 'status', text: t('sign.auto_return', { s: left }) });
        var back = el('a', { class: 'kx-btn kx-btn--primary kx-btn--xl', href: '/kiosk/me.php' }, [icon('fa-arrow-left'), el('span', { text: t('sign.back') })]);
        card.appendChild(el('div', { class: 'kl-receipt__actions' }, [back, countdown]));
        root.appendChild(card);
        var h = card.querySelector('.kl-receipt__title');
        if (h) { h.focus({ preventScroll: true }); }
        var timer = setInterval(function () {
            left -= 1;
            countdown.textContent = t('sign.auto_return', { s: Math.max(0, left) });
            if (left <= 0) { clearInterval(timer); location.replace('/kiosk/me.php'); }
        }, 1000);
        document.addEventListener('pointerdown', function () { /* a tap keeps the timer; the button leaves at once */ }, { once: true });
    }
    function fact(label, value, mono) {
        return el('span', { class: 'kl-fact' }, [el('span', { class: 'kl-muted', text: label + ' ' }), el('strong', { class: mono ? 'kl-mono' : null, text: String(value) })]);
    }
}());
