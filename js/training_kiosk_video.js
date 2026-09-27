/*
 * YouTube / Vimeo lesson (kiosk/lesson_video.php, P3 spec §5.5, mockup Kiosk-LessonVideo).
 * lesson_open on load; the first PLAYING reports the live duration (video_duration; a 409 means
 * the video changed and completion stays off); a tick on every play/pause and every 10 s while
 * playing; a seek past the furthest point + 3 s snaps back (UX only - the server credits time);
 * "Mark lesson complete" follows the server gate -> lesson_complete -> back to the course (a lesson with a
 * quick check: "Continue to quick check" -> back to the course on that check).
 * Before "Still there?" the video pauses and the iframe hides. Every request carries the page's
 * restricted video token (Kiosk.api adds X-Kiosk-Video from k-page-data.video).
 * Video options (js/training_media_controls.js): Mute / volume (Mute only on iPhone / iPad, with a "Louder: use
 * the iPad's volume buttons" hint; Up / Down arrows on a PC), remembered per device (sign-out undoes Mute); CC
 * through the player's own captions (the run's language first; off with a note when the video has none),
 * remembered for this signed-in session - until chosen, ON when the page says cc_default (a run in another
 * language than the course default, on the default language's video) and the player lists a track in the run's
 * language; and "Resuming at 3:42 · Start over" in the bar above the player when lesson_open says this lesson
 * was started before (the run's furthest point) - the jump happens on the first play, which is always a tap
 * inside the player. The time sits under the progress bar so the buttons share one row, and the player is
 * made shorter when the card would otherwise run under the bottom bar (MC.fitStage).
 * Honest watch progress (owner report 2026-09-27): the ring and its words show the time the SERVER counted
 * (gate credit_s) against what is required (required_s) - "2:18 of 5:59 watched", 100 % only once the gate is met;
 * the bar's lighter shading is only how far the learner may move. The video pauses whenever the page is hidden
 * (time never counts there) with "Paused while this screen was in the background…" on return, no auto-resume.
 * When the furthest point is at the end but time is short, the bottom bar says so and offers "Watch from the
 * start"; a resume point in the last few seconds is not offered (the video starts at 0 with that message).
 */
(function () {
    'use strict';
    var K = window.Kiosk;
    if (!K) { return; }
    var el = K.ui.el;
    var icon = K.ui.icon;
    var t = K.t;
    var root = document.getElementById('kl-video');
    if (!root) { return; }
    var P = K.data().page || {};

    function fmt(s) {
        s = Math.max(0, Math.floor(Number(s) || 0));
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    if (P.mode !== 'video') {
        root.appendChild(el('section', { class: 'kl-msg' }, [
            el('span', { class: 'kl-msg__icon', 'aria-hidden': 'true' }, icon('fa-video-slash')),
            el('h1', { class: 'kl-msg__title', text: t(P.title_key || 'course.unavailable_title') }),
            el('p', { class: 'kl-msg__body', text: t(P.body_key || 'video.unavailable') }),
            el('a', { class: 'kx-btn kx-btn--primary kx-btn--xl', href: /^\/kiosk\//.test(P.back || '') ? P.back : '/kiosk/me.php' }, [icon('fa-arrow-left'), el('span', { text: t('video.back') })])
        ]));
        return;
    }

    var runId = P.run_id;
    var uid = P.lesson_uid;
    var duration = Number(P.duration_s || 0);
    var minPct = Number(P.min_watch_pct || 90);
    var maxWatched = 0;
    var server = null;         // the last Gate
    var changed = false;       // 409 video_changed
    var completing = false;
    var controller = null;
    var playing = false;
    var lastTime = 0;
    var tickTimer = null;
    var MC = window.TrainingMediaControls || null;
    var owner = K.prefsOwner ? K.prefsOwner() : null;
    var ccChoice = MC ? MC.prefs.cc(owner) : null;               // true / false as chosen this session, null = not chosen
    var ccAuto = ccChoice === null && P.cc_default === true;      // a default ON, taken back if the video has no track in P.lang
    var ccOn = ccChoice === null ? ccAuto : ccChoice;
    var vp = MC ? MC.prefs.volume() : { level: 0.8, muted: false };
    var resumeAt = null;       // seconds to jump to on the first play; -1 once done
    var started = false;       // the video played on this screen (the end-of-video message then waits for the end again)
    var ended = false;

    // ---------------------------------------------------------------- layout
    var back = el('a', { class: 'kx-btn kx-btn--ghost kl-vback', href: P.course_url || '/kiosk/me.php' }, [icon('fa-arrow-left'), el('span', { text: t('video.back') })]);
    var crumbs = P.crumbs || {};
    var total = Number(crumbs.total || 0);
    var segs = el('div', { class: 'kl-vsegs', 'aria-hidden': 'true' });
    for (var i = 1; i <= total && total <= 30; i++) { segs.appendChild(el('span', { class: 'kl-vsegs__s' + (i < crumbs.n ? ' is-done' : (i === crumbs.n ? ' is-current' : '')) })); }
    root.appendChild(el('header', { class: 'kl-vhead' }, [
        back,
        el('div', { class: 'kl-vhead__main' }, [
            el('div', { class: 'kl-crumbs', text: [crumbs.course, crumbs.section].filter(Boolean).join(' · ') }),
            el('h1', { class: 'kl-vhead__title', text: P.title || '' })
        ]),
        crumbs.n ? el('div', { class: 'kl-vhead__pos' }, [el('strong', { text: t('video.lesson_n_of', { n: crumbs.n, total: total }) }), segs]) : null
    ]));

    var providerChip = el('span', { class: 'kl-vchip' }, [el('i', { class: 'fab ' + (P.provider === 'vimeo' ? 'fa-vimeo-v' : 'fa-youtube'), 'aria-hidden': 'true' }),
        el('span', { text: P.provider === 'vimeo' ? t('video.provider_vimeo') : t('video.provider_youtube') })]);
    var tapNote = el('span', { class: 'kl-vtap' }, [icon('fa-hand-pointer'), el('span', { text: t('video.tap_to_start') })]);
    var holder = el('div', { class: 'kl-vframe' });
    var endedIcon = el('span', { class: 'kl-vended__icon', 'aria-hidden': 'true' }, icon('fa-check-circle'));
    var endedBox = el('div', { class: 'kl-vended', hidden: true }, [endedIcon, el('strong', { text: t('video.ended') })]);
    var replayLabel = el('span', { text: t('video.replay') });
    var replayBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--ghost kx-btn--xl' }, [icon('fa-redo'), replayLabel]);
    endedBox.appendChild(replayBtn);
    var playBtn = el('button', { type: 'button', class: 'kl-vbtn kl-vbtn--play', 'aria-label': t('video.play') }, icon('fa-play'));
    var backBtn = el('button', { type: 'button', class: 'kl-vbtn kl-vbtn--wide kl-vback10', 'aria-label': t('video.back_10') }, [icon('fa-undo'),
        el('span', { class: 'kl-vback10__full', 'aria-hidden': 'true', text: t('video.back_10') }), el('span', { class: 'kl-vback10__short', 'aria-hidden': 'true', text: t('vopt.back_10_short') })]);
    var barMax = el('span', { class: 'kl-vbar__max' });
    var barCur = el('span', { class: 'kl-vbar__cur' });
    var bar = el('div', { class: 'kl-vbar', 'aria-hidden': 'true' }, [barMax, barCur]);
    var timeEl = el('span', { class: 'kl-vtime kl-mono', text: '0:00 / ' + (duration ? fmt(duration) : '–:––') });
    var vstatus = el('div', { class: 'kl-vstatus', role: 'status', 'aria-live': 'polite', hidden: true });
    var ccNote = el('div', { class: 'kl-ccnote', hidden: true }, [icon('fa-closed-captioning'), el('span', { text: t('vopt.cc_none') })]);
    var ccBtn = MC ? MC.ccButton({ labels: { cc: t('vopt.cc'), cc_short: t('vopt.cc_short'), none: t('vopt.cc_none') }, on: ccOn,
        onToggle: function (on) { ccAuto = false; ccOn = on; MC.prefs.setCc(on, owner); if (controller) { controller.setCaptions(on, P.lang || K.lang()); } } }) : null;
    var vol = MC ? MC.volume({ labels: { mute: t('vopt.mute'), unmute: t('vopt.unmute'), volume: t('vopt.volume'), hint: t('vopt.louder_ios') }, level: vp.level, muted: vp.muted,
        onChange: function (level, muted) { MC.prefs.setVolume(level, muted); if (controller) { controller.setVolume(level); controller.setMuted(muted); } } }) : null;
    var resume = MC ? MC.resumeBar({ labels: { start_over: t('vopt.start_over') }, onStartOver: function () {
        var was = resumeAt;
        resumeAt = null;
        if (was === -1 && controller) { controller.seekTo(0); }   // it already jumped: back to the start
    } }) : null;
    // "Paused while this screen was in the background…" (the video pauses whenever the page is hidden).
    var bgNote = MC && MC.noticeBar ? MC.noticeBar({ text: t('video.bg_paused'), icon: 'fa-pause-circle' }) : null;
    // Nothing lies over the provider's player (plan A2): "Resuming at 3:42 · Start over" joins the dark bar above it.
    var stage = el('section', { class: 'kl-vstage' }, [
        el('div', { class: 'kl-vstage__top' }, [providerChip, tapNote, resume ? resume.el : null, bgNote ? bgNote.el : null]),
        holder, endedBox,
        el('div', { class: 'kl-vcontrols' }, [playBtn, backBtn,
            el('div', { class: 'kl-vbar__wrap' }, [bar, el('div', { class: 'kl-vbar__meta' }, [timeEl, el('span', { class: 'kl-vbar__cap', text: t('video.furthest') }), vol && vol.hintEl ? vol.hintEl : null])]),
            ccBtn || vol ? el('div', { class: 'kl-vopts' }, [ccBtn ? el('div', { class: 'tmc-ccgroup' }, ccBtn.el) : null, vol ? vol.el : null]) : null]),
        ccNote
    ]);

    // right column: resources, watch progress ring, up next
    var ringFill = el('span', { class: 'kl-ring__fill' });
    var ringText = el('span', { class: 'kl-ring__text', text: '0%' });
    var ring = el('div', { class: 'kl-ring', role: 'img', 'aria-label': '0%' }, [ringFill, ringText]);
    var watchTitle = el('strong', { class: 'kl-watch__title' });
    var watchLeft = el('span', { class: 'kl-muted' });
    var chip = el('span', { class: 'kl-chip kl-chip--info' }, [icon('fa-sync-alt'), el('span', { text: t('video.chip_in_progress') })]);
    var side = [];
    var res = Array.isArray(P.resources) ? P.resources : [];
    if (res.length) {
        side.push(el('section', { class: 'kl-side' }, [
            el('header', { class: 'kl-side__head' }, [el('h2', { class: 'kl-h3', text: t('video.resources') }), el('span', { class: 'kl-muted', text: res.length === 1 ? t('video.files_1') : t('video.files_n', { n: res.length }) })]),
            el('ul', { class: 'kl-res' }, res.map(function (r) {
                return el('li', null, el('a', { class: 'kl-res__row', href: r.url, rel: 'noopener' }, [
                    el('span', { class: 'kl-res__icon', 'aria-hidden': 'true' }, icon(/pdf/i.test(r.title) ? 'fa-file-pdf' : 'fa-file')),
                    el('span', { class: 'kl-res__name', text: r.title || '' }),
                    el('span', { class: 'kl-res__dl', 'aria-hidden': 'true' }, icon('fa-download'))
                ]));
            }))
        ]));
    }
    side.push(el('section', { class: 'kl-side kl-watch' }, [
        el('header', { class: 'kl-side__head' }, [el('h2', { class: 'kl-h3', text: t('video.watch_progress') }), chip]),
        el('div', { class: 'kl-watch__body' }, [ring, el('div', null, [watchTitle, watchLeft])]),
        el('div', { class: 'kl-note' }, [icon('fa-info-circle'), el('div', null, [el('strong', { text: t('video.skip_not_counted') }), el('div', { text: t('video.only_watched') })])])
    ]));
    if (P.up_next) {
        side.push(el('a', { class: 'kl-side kl-upnext', href: P.return_url || P.course_url }, [
            el('span', { class: 'kl-upnext__icon', 'aria-hidden': 'true' }, icon('fa-arrow-right')),
            el('span', null, [el('span', { class: 'kl-kicker', text: t('video.up_next', { n: P.up_next.n }) }), el('strong', { text: P.up_next.title || '' })])
        ]));
    }
    root.appendChild(el('div', { class: 'kl-vgrid' }, [el('div', { class: 'kl-vmain' }, [stage, vstatus]), el('aside', { class: 'kl-vside' }, side)]));

    var gateIcon = el('span', { class: 'kl-gate__icon', 'aria-hidden': 'true' }, icon('fa-lock'));
    var gateText = el('span', { class: 'kl-gate__text', text: t('video.checking') });
    var nextBtn = el('a', { class: 'kx-btn kx-btn--ghost kx-btn--xl', href: P.return_url || P.course_url }, [el('span', { text: t('video.next_lesson') }), icon('fa-arrow-right')]);
    var doneIcon = el('span', { class: 'kl-btnicon', 'aria-hidden': 'true' }, icon('fa-check'));
    var doneLabel = el('span', { text: t('video.mark_complete') });
    var doneBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl', disabled: true }, [doneIcon, doneLabel]);
    // The furthest point is at the end but not enough time counted: the only way on is to watch again (any part counts).
    var restartBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl kl-restart', hidden: true }, [icon('fa-redo'), el('span', { text: t('video.watch_from_start') })]);
    var foot = el('footer', { class: 'kl-vfoot' }, [el('div', { class: 'kl-gate', role: 'status', 'aria-live': 'polite' }, [gateIcon, gateText]), el('div', { class: 'kl-vfoot__act' }, [restartBtn, nextBtn, doneBtn])]);
    root.appendChild(foot);
    // The controls stay above the sticky bottom bar (iPad landscape, a 768 px tall PC screen): a shorter player
    // instead of a scroll (the provider letterboxes the video inside it; nothing is laid over it).
    if (MC && typeof MC.fitStage === 'function') {
        MC.fitStage(holder, stage, { bottomBar: foot, min: 200, apply: function (px) {
            stage.classList.toggle('is-fit', !!px);
            if (px) { stage.style.setProperty('--kl-frame-h', px + 'px'); } else { stage.style.removeProperty('--kl-frame-h'); }
        } });
    }

    /** Whether a provider's caption list [{lang}] has this language (es matches es-419). */
    function hasTrackLang(list, lg) {
        var base = String(lg || '').toLowerCase().split(/[-_]/)[0];
        return (list || []).some(function (x) { return x && String(x.lang || '').toLowerCase().split(/[-_]/)[0] === base; });
    }

    // ---------------------------------------------------------------- state -> UI
    /** The honest numbers: the time the server counted against what is required (MC.watchCredit). */
    function watched() {
        var d = duration || (server && server.duration_s) || 0;
        var o = { credit: server ? server.credit_s : 0, required: server ? server.required_s : 0, met: !!(server && (server.done || server.can_complete)),
            max: maxWatched, duration: d, minPct: minPct, cur: lastTime, started: started, ended: ended };
        if (MC && typeof MC.watchCredit === 'function') { return MC.watchCredit(o); }
        var req = Math.max(0, Number(o.required) || 0);
        var cr = Math.max(0, Number(o.credit) || 0);
        return { counted: o.met ? req : Math.min(cr, req), required: req, left: o.met ? 0 : Math.max(0, req - cr), pct: o.met ? 100 : (req > 0 ? Math.min(99, Math.floor(cr * 100 / req)) : 0),
            met: o.met, short: o.met ? null : (cr < req ? 'time' : null), needPos: 0, atEnd: false };
    }
    function paint() {
        var d = duration || (server && server.duration_s) || 0;
        var w = watched();
        ring.style.setProperty('--p', String(w.pct));
        ringText.textContent = w.pct + '%';
        ring.setAttribute('aria-label', w.pct + '%');
        ring.classList.toggle('is-ok', w.met);
        var isDone = server && server.done;
        // A lesson with a quick check: every finish wording says the check comes next (must-pass: it has to be passed).
        var hasCheck = !!(P.check_url && P.check);
        watchTitle.textContent = !server ? t('video.checking') : (w.met ? (hasCheck && !isDone ? t('video.counted_ok_check') : t('video.counted_ok'))
            : t('video.counted', { done: fmt(w.counted), need: fmt(w.required) }));
        watchLeft.textContent = !server || w.met ? '' : (w.short === 'position' ? t('video.watch_on_to', { t: fmt(w.needPos) })
            : (w.left > 0 ? t('video.about_left', { t: fmt(w.left) }) : ''));
        barMax.style.width = (d > 0 ? Math.min(100, maxWatched * 100 / d) : 0) + '%';
        barCur.style.width = (d > 0 ? Math.min(100, lastTime * 100 / d) : 0) + '%';
        timeEl.textContent = fmt(lastTime) + ' / ' + (d ? fmt(d) : '–:––');
        var can = !changed && server && (server.done || server.can_complete);
        // Watched already, with a must-pass quick check still to pass: the button goes on to the check.
        var toCheck = !!(P.check_url && server && server.credited && !isDone);
        doneBtn.disabled = !can || completing;
        doneBtn.hidden = !!isDone;
        doneLabel.textContent = toCheck ? t('video.to_check') : (hasCheck ? t('video.continue_check') : t('video.mark_complete'));
        while (doneIcon.firstChild) { doneIcon.removeChild(doneIcon.firstChild); }
        doneIcon.appendChild(icon(toCheck || hasCheck ? 'fa-clipboard-check' : 'fa-check'));
        nextBtn.classList.toggle('kx-btn--primary', !!isDone);
        nextBtn.classList.toggle('kx-btn--ghost', !isDone);
        // Before this lesson is done the next one is still locked (it would only bounce back to the course page),
        // and the last lesson has no next one: the finish button (or "← Course") is the way on.
        nextBtn.hidden = !isDone || !P.up_next;
        while (chip.firstChild) { chip.removeChild(chip.firstChild); }
        chip.className = 'kl-chip ' + (isDone ? 'kl-chip--ok' : 'kl-chip--info');
        chip.appendChild(icon(isDone ? 'fa-check' : 'fa-sync-alt'));
        chip.appendChild(el('span', { text: isDone ? t('video.chip_done') : t('video.chip_in_progress') }));
        while (gateIcon.firstChild) { gateIcon.removeChild(gateIcon.firstChild); }
        if (changed) {
            gateIcon.appendChild(icon('fa-exclamation-triangle'));
            gateText.textContent = t('video.changed');
        } else if (!server) {
            gateIcon.appendChild(icon('fa-circle-notch'));
            gateText.textContent = t('video.checking');
        } else if (isDone) {
            gateIcon.appendChild(icon('fa-check'));
            gateText.textContent = t('video.gate_done');
        } else if (toCheck) {
            gateIcon.appendChild(icon('fa-check'));
            gateText.textContent = t('video.gate_check');
        } else if (server.can_complete) {
            gateIcon.appendChild(icon('fa-check'));
            var qn = Number(P.check && P.check.question_count) || 0;
            gateText.textContent = !hasCheck ? t('video.gate_ready')
                : (P.check.must_pass ? (qn === 1 ? t('video.then_must_1') : t('video.then_must_n', { n: qn })) : (qn === 1 ? t('video.then_check_1') : t('video.then_check_n', { n: qn })));
        } else if (w.atEnd) {
            // "You reached the end, but only 2:18 of watching counted. Watch about 3:41 more - any part of the video counts."
            gateIcon.appendChild(icon('fa-redo'));
            gateText.textContent = t('video.gate_end_short', { done: fmt(w.counted), t: fmt(w.left) });
        } else if (w.short === 'position') {
            gateIcon.appendChild(icon('fa-lock'));
            gateText.textContent = t('video.gate_position', { t: fmt(w.needPos) });
        } else {
            gateIcon.appendChild(icon('fa-lock'));
            gateText.textContent = w.left > 0 && (maxWatched > 0 || w.counted > 0) ? t('video.gate_more', { t: fmt(w.left) }) : t('video.gate_locked');
        }
        gateIcon.parentNode.classList.toggle('is-ok', !!(server && (server.can_complete || isDone)) && !changed);
        // "Watch from the start": in the bottom bar, or as the finished video's own button (the one Replay becomes).
        var offerRestart = !!(server && w.atEnd && !changed && !isDone);
        restartBtn.hidden = !offerRestart || !endedBox.hidden;
        foot.classList.toggle('has-restart', !restartBtn.hidden);   // the longer message gets its own row on narrower screens
        replayLabel.textContent = offerRestart ? t('video.watch_from_start') : t('video.replay');
        replayBtn.className = 'kx-btn kx-btn--xl ' + (offerRestart ? 'kx-btn--primary' : 'kx-btn--ghost');
        // A finished video with too little time counted is not "done": no green tick on it.
        endedIcon.className = 'kl-vended__icon' + (offerRestart ? ' is-short' : '');
        while (endedIcon.firstChild) { endedIcon.removeChild(endedIcon.firstChild); }
        endedIcon.appendChild(icon(offerRestart ? 'fa-info-circle' : 'fa-check-circle'));
        while (playBtn.firstChild) { playBtn.removeChild(playBtn.firstChild); }
        playBtn.appendChild(icon(playing ? 'fa-pause' : 'fa-play'));
        playBtn.setAttribute('aria-label', playing ? t('video.pause') : t('video.play'));
    }
    function showError(msg) {
        while (vstatus.firstChild) { vstatus.removeChild(vstatus.firstChild); }
        vstatus.appendChild(icon('fa-exclamation-triangle'));
        vstatus.appendChild(el('span', { text: msg }));
        vstatus.hidden = false;
    }
    function applyGate(g) {
        if (!g || typeof g !== 'object') { return; }
        server = g;
        if (Number(g.max_position_s) > maxWatched) { maxWatched = Number(g.max_position_s); }
        if (!duration && Number(g.duration_s) > 0) { duration = Number(g.duration_s); }
        paint();
    }

    // ---------------------------------------------------------------- server calls
    function body(extra) {
        var b = { run_id: runId, lesson_uid: uid };
        Object.keys(extra || {}).forEach(function (k) { b[k] = extra[k]; });
        return b;
    }
    function tick() {
        if (changed || (server && server.done)) { return; }
        var vid = controller && typeof controller.getVideoId === 'function' ? controller.getVideoId() : null;
        K.api.post('lesson_tick', body({
            position_s: Math.floor(lastTime), playing: playing, visible: document.visibilityState !== 'hidden', active: true,
            video_id: vid || P.video_id || undefined
        })).then(applyGate, function (e) {
            if (e && (e.code === 'video_changed' || e.code === 'run_blocked' || e.code === 'run_locked')) { changed = e.code === 'video_changed'; showError(e.message); paint(); }
        });
    }
    function startTicks() { if (!tickTimer) { tickTimer = setInterval(function () { if (playing) { tick(); } }, 10000); } }

    // The page went to the background (another app, a minimized window, the screen locked): pause - time never counts
    // there, so the furthest point must not run ahead of it. The tick goes at once (visible:false closes the interval
    // the server was counting); back on the page a short notice says why the video stopped; it never resumes by itself.
    var guard = MC && typeof MC.backgroundPause === 'function' ? MC.backgroundPause({
        isPlaying: function () { return playing; },
        pause: function () { if (controller) { controller.pause(); } },
        onHide: function (wasPlaying) { if (wasPlaying) { tick(); } },
        onReturn: function () {
            if (resume) { resume.hide(); }
            if (bgNote) { bgNote.show(); }
            paint();
        }
    }) : null;

    K.api.post('lesson_open', body({})).then(function (g) {
        applyGate(g);
        // Pick up where you left off: this lesson was started before (the run's furthest point for it).
        // Not in the last few seconds: someone whose counted time is short would be dropped at the very end (the video
        // starts at 0 instead, and the bottom bar says why - "You reached the end, but only …").
        var mp = Math.floor(Number(g && g.max_position_s) || 0);
        var d = duration || Number(g && g.duration_s) || 0;
        var atEndNow = MC && typeof MC.nearEnd === 'function' ? MC.nearEnd(mp, d) : (d > 0 && mp >= d - 3);
        if (resume && g && !g.done && !g.credited && mp >= 5 && !atEndNow) {
            resumeAt = mp;
            resume.show(t('vopt.resume_at', { t: fmt(mp) }));
        }
        mount();
    }, function (e) {
        showError(e && e.message ? e.message : t('video.unavailable'));
        stage.classList.add('is-off');
        doneBtn.disabled = true;
    });

    var reportedDuration = false;
    function mount() {
        if (!window.TrainingVideoEmbed) { showError(t('video.error')); return; }
        controller = window.TrainingVideoEmbed.mount(holder, {
            provider: P.provider, embedUrl: P.embed_url, title: P.title || 'Video',
            transport: 'postmessage',   // no YouTube/Vimeo script in the kiosk origin (security review: it could read other kiosk pages)
            onPlaying: function (durationS) {
                tapNote.hidden = true;
                if (resumeAt !== null && resumeAt > 0 && controller) {
                    controller.seekTo(Math.min(resumeAt, maxWatched || resumeAt));
                    lastTime = Math.min(resumeAt, maxWatched || resumeAt);
                    resumeAt = -1;
                    setTimeout(function () { if (resume) { resume.hide(); } }, 8000);
                }
                if (durationS > 0 && !reportedDuration) {
                    reportedDuration = true;
                    if (!duration) { duration = durationS; }
                    K.api.post('video_duration', body({ duration_s: Math.round(durationS) })).then(null, function (e) {
                        if (e && e.code === 'video_changed') {
                            changed = true;
                            try { controller.pause(); } catch (x) { /* ignore */ }
                            showError(t('video.changed'));
                            paint();
                        }
                    });
                }
            },
            onState: function (s) {
                var was = playing;
                playing = s === 'playing';
                if (playing) {
                    K.idle.playing();
                    endedBox.hidden = true;
                    holder.classList.remove('kl-hidden');
                    started = true;
                    ended = false;
                    if (bgNote) { bgNote.hide(); }
                    if (guard) { guard.playing(); }   // a play that raced the page going to the background is paused again
                }
                if (s === 'ended') { ended = true; }
                if (was !== playing && (s === 'playing' || s === 'paused' || s === 'ended')) { tick(); }
                if (s === 'ended') {
                    holder.classList.add('kl-hidden');
                    endedBox.hidden = false;
                }
                paint();
            },
            onTime: function (tm) {
                if (tm.current > maxWatched + 3 && controller) { controller.seekTo(maxWatched); return; }   // UX only
                lastTime = tm.current;
                if (tm.current > maxWatched) { maxWatched = tm.current; }
                if (!duration && tm.duration > 0) { duration = tm.duration; }
                if (playing) { K.idle.playing(); }
                paint();
            },
            onCaptionTracks: function (list) {
                // The player says which captions the video has: none -> CC is off, with a short note.
                var none = !Array.isArray(list) || list.length === 0;
                if (ccBtn) { ccBtn.available(none ? 'no' : 'yes'); }
                ccNote.hidden = !none;
                // A default ON (not this person's choice) needs captions in the run's language: none -> off again.
                if (ccAuto && ccOn && !none && !hasTrackLang(list, P.lang || K.lang())) {
                    ccAuto = false;
                    ccOn = false;
                    if (ccBtn) { ccBtn.set(false); }
                    if (controller) { controller.setCaptions(false, P.lang || K.lang()); }
                }
            },
            onError: function (code, message) {
                showError((message || t('video.error')) + ' ' + t('video.error_detail', { code: code }));
                K.api.post('lesson_error', body({ provider: P.provider, code: String(code).slice(0, 40) })).then(null, function () { /* [S] best effort */ });
            }
        });
        if (vol) { controller.setVolume(vp.level); controller.setMuted(vp.muted); }
        controller.setCaptions(ccOn, P.lang || K.lang());
        startTicks();
    }
    // Windows keyboard: Up / Down = volume +-10 % (nothing on iOS, where only Mute works).
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.altKey || e.ctrlKey || e.metaKey) { return; }
        if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && vol && vol.step(e.key === 'ArrowUp' ? 0.1 : -0.1)) { e.preventDefault(); }
    });

    playBtn.addEventListener('click', function () { if (controller) { controller.toggle(); } });
    backBtn.addEventListener('click', function () { if (controller) { controller.seekBy(-10); } });
    /** Replay / "Watch from the start": back to 0 and play (iOS may still want the first tap inside the player). */
    function fromStart() {
        endedBox.hidden = true;
        holder.classList.remove('kl-hidden');
        if (resume) { resume.hide(); }
        if (resumeAt !== -1) { resumeAt = null; }   // a pending "Resuming at…" jump is dropped: this starts at 0
        lastTime = 0;
        ended = false;
        started = true;   // the end-of-video message waits for the end again
        if (controller) { controller.seekTo(0); controller.play(); }
        paint();
    }
    replayBtn.addEventListener('click', fromStart);
    restartBtn.addEventListener('click', fromStart);
    doneBtn.addEventListener('click', function () {
        if (doneBtn.disabled || completing) { return; }
        completing = true;
        K.ui.busy(doneBtn, true);
        paint();
        var vid = controller && typeof controller.getVideoId === 'function' ? controller.getVideoId() : null;
        K.api.post('lesson_complete', body({ evidence: { position_s: Math.floor(Math.max(lastTime, 0)), video_id: vid || P.video_id || undefined } })).then(function () {
            location.replace(P.check_url || P.return_url || P.course_url || '/kiosk/me.php');   // a quick check comes right after the video
        }, function (e) {
            completing = false;
            K.ui.busy(doneBtn, false);
            if (e && e.data && typeof e.data === 'object' && 'can_complete' in e.data) { applyGate(e.data); }
            showError(e && e.message ? e.message : t('err.server'));
            paint();
        });
    });

    // "Still there?": pause and hide the player first (§5.5).
    K.idle.start({
        onWarn: function (left) {
            if (controller) { try { controller.pause(); } catch (e) { /* ignore */ } }
            holder.classList.add('kl-hidden');
            K.ui.idleDialog(left).then(function (stay) {
                if (endedBox.hidden) { holder.classList.remove('kl-hidden'); }
                if (stay) { K.idle.touch(); K.session.beat(); } else { K.session.end('done'); }
            });
        },
        onIdle: function () {
            if (controller) { try { controller.pause(); } catch (e) { /* ignore */ } }
            holder.classList.add('kl-hidden');
            K.session.end('idle');
        }
    });
    window.addEventListener('pagehide', function () { if (controller) { controller.destroy(); } });
    paint();
}());
