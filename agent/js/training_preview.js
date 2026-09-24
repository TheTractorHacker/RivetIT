/*
 * Training › Preview as learner - page adapter for agent/training_preview.php (spec §5.9, §6.0).
 *
 * Mounts js/training_player.js on the LearnerView in #tr-page-data and supplies the adapter:
 *   startQuiz / submitQuiz   -> preview_quiz_start / preview_quiz_submit (server draw + grading)
 *   onVideoReady / onVideoError -> video_verify (level 2+, only while the video is unverified),
 *                               then the same tr-video-verified / tr-video-error broadcast the
 *                               in-window check frame sends, so an open content window updates.
 *   progress                  -> localStorage "tr-preview:<userId>:<courseId>:<revision|draft>"
 *                               (lesson uids, page numbers, seconds watched; never question data)
 * Page chrome: language select (reloads in that language), device frame (iPad landscape /
 * portrait / fit; remembered), Reset progress.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var data = window.TrainingUi ? TrainingUi.readJson('tr-page-data') : {};
    var view = data && data.view;
    var host = document.getElementById('tr-player');
    if (!view || !host || !window.TrainingPlayer) { return; }

    var src = view.source || {};
    var storeKey = 'tr-preview:' + (data.user_id || 0) + ':' + (src.course_id || 0) + ':' + (src.revision_id || 'draft');
    var channel = TrainingUi.channel();
    var stage = document.getElementById('tr-preview-stage');
    var fit = document.getElementById('tr-preview-fit');
    var frame = document.getElementById('tr-preview-device-frame');
    var deviceSel = document.getElementById('tr-preview-device');
    var langSel = document.getElementById('tr-preview-lang');
    var resetBtn = document.getElementById('tr-preview-reset');
    var DEVICE_KEY = 'tr-preview-device';
    var SIZES = { landscape: [1180, 820], portrait: [820, 1180] };

    function videoBody(v, extra) {
        return Object.assign({ provider: v.provider, ext_id: v.extId, ext_hash: v.extHash || '' }, extra);
    }
    function broadcast(type, payload) {
        try { channel.post(type, payload); } catch (e) { /* ignore */ }
    }

    var player = TrainingPlayer.mount(host, view, {
        mode: 'preview',
        canGrade: !!view.can_grade,
        brand: data.brand || '',
        initialProgress: TrainingUi.local.get(storeKey),
        initialLesson: data.lesson || null,
        onProgress: function (p) { TrainingUi.local.set(storeKey, p); },
        onLanguage: function (lg) { switchLanguage(lg); },
        startQuiz: function (lessonUid, lang) {
            return TrainingApi.post('preview_quiz_start', {
                source: src.type, course_id: src.course_id, revision_id: src.revision_id || null, lesson_uid: lessonUid, lang: lang
            });
        },
        submitQuiz: function (token, answers) {
            return TrainingApi.post('preview_quiz_submit', { attempt_token: token, answers: answers });
        },
        onLessonOpen: function (uid) {
            try {
                var u = new URL(window.location.href);
                u.searchParams.set('lesson', uid);
                window.history.replaceState(null, '', u.toString());
            } catch (e) { /* ignore */ }
        },
        onLessonComplete: function () { return Promise.resolve(); },
        onVideoReady: function (lesson, lang, v) {
            if (!view.can_verify_video) { return undefined; }
            return TrainingApi.post('video_verify', videoBody(v, { duration_s: v.durationS, ok: true })).then(function (d) {
                var check = d && d.check;
                broadcast('tr-video-verified', { provider: v.provider, ext_id: v.extId, ext_hash: v.extHash || null, duration_s: v.durationS, check: check });
                return { verified: !!(check && check.verified_fresh), check: check };
            });
        },
        onVideoError: function (lesson, lang, v) {
            if (!view.can_verify_video) { return; }
            TrainingApi.post('video_verify', videoBody(v, { ok: false, error_code: v.code })).then(function (d) {
                broadcast('tr-video-error', { provider: v.provider, ext_id: v.extId, ext_hash: v.extHash || null, error_code: v.code, check: d && d.check });
            }, function () { /* the player already shows the error */ });
        }
    });

    function switchLanguage(lg) {
        try {
            var u = new URL(window.location.href);
            u.searchParams.set('lang', lg);
            var cur = player.progress().current;
            if (cur) { u.searchParams.set('lesson', cur); } else { u.searchParams.delete('lesson'); }
            window.location.assign(u.toString());
        } catch (e) { /* ignore */ }
    }
    if (langSel) {
        langSel.addEventListener('change', function () { switchLanguage(langSel.value); });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            TrainingUi.confirmBar(document.querySelector('#tr-preview .trp-toolbar'), {
                message: 'Start the preview over? Lessons you marked complete are cleared.',
                confirmLabel: 'Reset progress', cancelLabel: 'Cancel'
            }).then(function (ok) {
                if (!ok) { return; }
                TrainingUi.local.remove(storeKey);
                player.reset();
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.delete('lesson');
                    window.history.replaceState(null, '', u.toString());
                } catch (e) { /* ignore */ }
                TrainingUi.toast('Progress reset.');
            });
        });
    }

    // ---- device frame -------------------------------------------------------------------
    function applyDevice(mode) {
        if (!SIZES[mode] && mode !== 'fit') { mode = 'landscape'; }
        stage.dataset.device = mode;
        if (deviceSel && deviceSel.value !== mode) { deviceSel.value = mode; }
        layout();
    }
    function layout() {
        var mode = stage.dataset.device;
        if (mode === 'fit') {
            fit.style.removeProperty('width');
            fit.style.removeProperty('height');
            frame.style.removeProperty('transform');
            var top = stage.getBoundingClientRect().top + window.scrollY;
            stage.style.setProperty('--trp-fit-h', Math.max(560, window.innerHeight - Math.min(top, 220) - 24) + 'px');
            return;
        }
        var size = SIZES[mode];
        var bezel = 36;   // matches .trp-device padding x2
        var w = size[0] + bezel;
        var hgt = size[1] + bezel;
        var avail = stage.clientWidth;
        var s = Math.min(1, avail / w);
        frame.style.transform = s < 1 ? 'scale(' + s.toFixed(4) + ')' : '';
        fit.style.width = Math.floor(w * s) + 'px';
        fit.style.height = Math.floor(hgt * s) + 'px';
    }
    var saved = TrainingUi.local.get(DEVICE_KEY);
    applyDevice(typeof saved === 'string' ? saved : 'landscape');
    if (deviceSel) {
        deviceSel.addEventListener('change', function () {
            TrainingUi.local.set(DEVICE_KEY, deviceSel.value);
            applyDevice(deviceSel.value);
        });
    }
    window.addEventListener('resize', TrainingUi.debounce(layout, 100));
    host.setAttribute('aria-busy', 'false');
});
