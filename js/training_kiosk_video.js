/*
 * YouTube / Vimeo lesson (kiosk/lesson_video.php, P3 spec §5.5, mockup Kiosk-LessonVideo).
 * lesson_open on load; the first PLAYING reports the live duration (video_duration; a 409 means
 * the video changed and completion stays off); a tick on every play/pause and every 10 s while
 * playing; a seek past the furthest point + 3 s snaps back (UX only - the server credits time);
 * "Mark lesson complete" follows the server gate -> lesson_complete -> back to the course.
 * Before "Still there?" the video pauses and the iframe hides. Every request carries the page's
 * restricted video token (Kiosk.api adds X-Kiosk-Video from k-page-data.video).
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
    var endedBox = el('div', { class: 'kl-vended', hidden: true }, [
        el('span', { class: 'kl-vended__icon', 'aria-hidden': 'true' }, icon('fa-check-circle')),
        el('strong', { text: t('video.ended') })
    ]);
    var replayBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--ghost kx-btn--xl' }, [icon('fa-redo'), el('span', { text: t('video.replay') })]);
    endedBox.appendChild(replayBtn);
    var playBtn = el('button', { type: 'button', class: 'kl-vbtn kl-vbtn--play', 'aria-label': t('video.play') }, icon('fa-play'));
    var backBtn = el('button', { type: 'button', class: 'kl-vbtn kl-vbtn--wide' }, [icon('fa-undo'), el('span', { text: t('video.back_10') })]);
    var barMax = el('span', { class: 'kl-vbar__max' });
    var barCur = el('span', { class: 'kl-vbar__cur' });
    var bar = el('div', { class: 'kl-vbar', 'aria-hidden': 'true' }, [barMax, barCur]);
    var timeEl = el('span', { class: 'kl-vtime kl-mono', text: '0:00 / ' + (duration ? fmt(duration) : '–:––') });
    var vstatus = el('div', { class: 'kl-vstatus', role: 'status', 'aria-live': 'polite', hidden: true });
    var stage = el('section', { class: 'kl-vstage' }, [
        el('div', { class: 'kl-vstage__top' }, [providerChip, tapNote]),
        holder, endedBox,
        el('div', { class: 'kl-vcontrols' }, [playBtn, backBtn, el('div', { class: 'kl-vbar__wrap' }, [bar, el('span', { class: 'kl-vbar__cap', text: t('video.furthest') })]), timeEl])
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
    var doneBtn = el('button', { type: 'button', class: 'kx-btn kx-btn--primary kx-btn--xl', disabled: true }, [icon('fa-check'), el('span', { text: t('video.mark_complete') })]);
    root.appendChild(el('footer', { class: 'kl-vfoot' }, [el('div', { class: 'kl-gate', role: 'status', 'aria-live': 'polite' }, [gateIcon, gateText]), el('div', { class: 'kl-vfoot__act' }, [nextBtn, doneBtn])]));

    // ---------------------------------------------------------------- state -> UI
    function paint() {
        var d = duration || (server && server.duration_s) || 0;
        var pctW = d > 0 ? Math.min(100, Math.round(maxWatched * 100 / d)) : 0;
        ring.style.setProperty('--p', String(pctW));
        ringText.textContent = pctW + '%';
        ring.setAttribute('aria-label', pctW + '%');
        ring.classList.toggle('is-ok', pctW >= minPct);
        watchTitle.textContent = pctW >= minPct ? t('video.watched_enough', { pct: pctW }) : t('video.watched_keep', { pct: pctW });
        watchLeft.textContent = d > 0 && pctW < 100 ? t('video.about_left', { t: fmt(Math.max(0, d - maxWatched)) }) : '';
        barMax.style.width = (d > 0 ? Math.min(100, maxWatched * 100 / d) : 0) + '%';
        barCur.style.width = (d > 0 ? Math.min(100, lastTime * 100 / d) : 0) + '%';
        timeEl.textContent = fmt(lastTime) + ' / ' + (d ? fmt(d) : '–:––');
        var isDone = server && server.done;
        var can = !changed && server && (server.done || server.can_complete);
        doneBtn.disabled = !can || completing;
        doneBtn.hidden = !!isDone;
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
        } else if (server.can_complete) {
            gateIcon.appendChild(icon('fa-check'));
            gateText.textContent = t('video.gate_ready');
        } else {
            gateIcon.appendChild(icon('fa-lock'));
            var left = Math.max(0, Number(server.required_s || 0) - Number(server.credit_s || 0));
            gateText.textContent = left > 0 && maxWatched > 0 ? t('video.gate_more', { t: fmt(left) }) : t('video.gate_locked');
        }
        gateIcon.parentNode.classList.toggle('is-ok', !!(server && (server.can_complete || isDone)) && !changed);
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

    K.api.post('lesson_open', body({})).then(function (g) {
        applyGate(g);
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
                if (playing) { K.idle.playing(); endedBox.hidden = true; holder.classList.remove('kl-hidden'); }
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
            onError: function (code, message) {
                showError((message || t('video.error')) + ' ' + t('video.error_detail', { code: code }));
                K.api.post('lesson_error', body({ provider: P.provider, code: String(code).slice(0, 40) })).then(null, function () { /* [S] best effort */ });
            }
        });
        startTicks();
    }

    playBtn.addEventListener('click', function () { if (controller) { controller.toggle(); } });
    backBtn.addEventListener('click', function () { if (controller) { controller.seekBy(-10); } });
    replayBtn.addEventListener('click', function () {
        endedBox.hidden = true;
        holder.classList.remove('kl-hidden');
        if (controller) { controller.seekTo(0); controller.play(); }
    });
    doneBtn.addEventListener('click', function () {
        if (doneBtn.disabled || completing) { return; }
        completing = true;
        K.ui.busy(doneBtn, true);
        paint();
        var vid = controller && typeof controller.getVideoId === 'function' ? controller.getVideoId() : null;
        K.api.post('lesson_complete', body({ evidence: { position_s: Math.floor(Math.max(lastTime, 0)), video_id: vid || P.video_id || undefined } })).then(function () {
            location.replace(P.return_url || P.course_url || '/kiosk/me.php');
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
