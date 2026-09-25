/*
 * Kiosk course overview + player (kiosk/course.php, P3 spec §5.4). Mounts TrainingPlayer in kiosk
 * mode with the §5.4 adapter: every learner action is a POST to /kiosk/api.php (K3 routes), the
 * server's gate decides when a lesson can be completed, and quiz answers are saved as they go.
 * The run state (RunState) is kept here and refreshed from each response.
 */
(function () {
    'use strict';
    var K = window.Kiosk;
    if (!K) { return; }
    var el = K.ui.el;
    var icon = K.ui.icon;
    var t = K.t;
    var root = document.getElementById('kl-player');
    if (!root) { return; }
    var P = K.data().page || {};
    var S = K.data().session || {};
    var view = P.view;
    var run = P.run && typeof P.run === 'object' ? P.run : null;

    function message(title, body, ic) {
        root.appendChild(el('section', { class: 'kl-msg' }, [
            el('span', { class: 'kl-msg__icon', 'aria-hidden': 'true' }, icon(ic || 'fa-info-circle')),
            el('h1', { class: 'kl-msg__title', text: title }),
            body ? el('p', { class: 'kl-msg__body', text: body }) : null,
            el('a', { class: 'kx-btn kx-btn--primary kx-btn--xl', href: '/kiosk/me.php' }, [icon('fa-arrow-left'), el('span', { text: t('course.back_home') })])
        ]));
    }
    if (!view || P.not_ready || !window.TrainingPlayer) {
        message(t('course.unavailable_title'), t('course.not_ready'), 'fa-tools');
        return;
    }

    // ---- API with the learner wording for codes whose message carries values -----------------
    var E = {
        pin_wrong: 'course.e_pin_wrong', pin_locked: 'course.e_pin_locked', pin_locked_hard: 'course.e_pin_locked_hard',
        gate_not_met: 'course.e_gate', lesson_locked: 'course.e_lesson_locked', exam_locked: 'course.e_exam_locked',
        run_locked: 'course.e_run_locked', run_blocked: 'course.e_run_blocked', attempts_exhausted: 'course.e_attempts',
        already_passed: 'course.e_already_passed', session_too_short: 'course.e_session_too_short', time_up: 'course.e_time_up',
        video_changed: 'course.e_video_changed', no_online_part: 'course.e_no_online', prereq_missing: 'course.e_prereq',
        signature_invalid: 'course.e_signature', signature_empty: 'course.e_signature', records_unavailable: 'course.e_records'
    };
    function friendly(e) {
        if (!e || !e.code) { return e; }
        var d = (e.data && typeof e.data === 'object') ? e.data : {};
        var f = (e.fields && typeof e.fields === 'object') ? e.fields : {};
        var v = function (k) { return d[k] !== undefined ? d[k] : f[k]; };
        if (e.code === 'pin_wrong' && typeof v('tries_left') === 'number') { e.message = t('course.e_pin_wrong_n', { n: v('tries_left') }); return e; }
        if (e.code === 'pin_locked' && v('minutes')) { e.message = t('course.e_pin_locked', { minutes: v('minutes') }); return e; }
        if (e.code === 'prereq_missing' && Array.isArray(v('names'))) { e.message = t('course.e_prereq', { names: v('names').map(String).join(', ') }); return e; }
        if (!K.has('err.' + e.code) && E[e.code]) { e.message = t(E[e.code], { minutes: v('minutes') || '', names: '' }); }
        return e;
    }
    function post(action, body) {
        return K.api.post(action, body).then(null, function (e) { throw friendly(e); });
    }
    function runId() { return run ? run.run_id : 0; }
    function absorb(res) {
        // lesson_complete / ack_sign: {progress_pct, run_status, next_uid?, done?}
        if (!run || !res || typeof res !== 'object') { return; }
        if (typeof res.progress_pct === 'number') { run.progress_pct = res.progress_pct; }
        if (typeof res.run_status === 'string') { run.status = res.run_status; }
        if (res.done && typeof res.done === 'object') { run.done = res.done; }
    }

    // ---- page facts for the overview hero ---------------------------------------------------
    function ymdLabel(ymd) {
        if (!ymd || !/^\d{4}-\d{2}-\d{2}/.test(ymd)) { return ''; }
        var p = ymd.slice(0, 10).split('-');
        try { return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).toLocaleDateString(K.lang() === 'es' ? 'es-MX' : 'en-US', { month: 'short', day: 'numeric' }); } catch (e) { return ymd; }
    }
    function status() { return run ? String(run.status || '') : ''; }
    function frozen() {
        if (!run) { return P.needs_online === false; }
        return !!run.locked || !!run.blocked || (status() !== '' && status() !== 'in_progress');
    }
    function chips() {
        var out = [];
        var a = P.assignment;
        if (a && a.due_on) {
            out.push(a.overdue ? { icon: 'fa-exclamation-triangle', text: t('course.chip_overdue', { date: ymdLabel(a.due_on) }), tone: 'bad' }
                : { icon: 'fa-calendar-alt', text: t('course.chip_due', { date: ymdLabel(a.due_on) }), tone: 'warn' });
        } else if (a) {
            out.push({ icon: 'fa-clipboard-check', text: t('course.chip_required'), tone: 'info' });
        }
        var m = Number(P.validity_months || 0);
        if (m > 0) { out.push({ icon: 'fa-sync-alt', text: m === 1 ? t('course.chip_good_for_1') : t('course.chip_good_for', { n: m }), tone: '' }); }
        return out;
    }
    function notice() {
        if (P.needs_online === false) { return { tone: 'info', icon: 'fa-users', title: t('course.session_title'), text: t('course.e_no_online') }; }
        if (!run) {
            return P.completed ? { tone: 'ok', icon: 'fa-check-circle', title: t('course.completed_title'), text: t('course.completed_body') } : null;
        }
        if (run.locked) { return { tone: 'bad', icon: 'fa-lock', title: t('course.locked_title'), text: t('course.locked_body') }; }
        if (run.blocked) {
            var r = run.blocked.reason;
            return { tone: 'bad', icon: 'fa-exclamation-circle', title: t('course.blocked_title'),
                text: r === 'video_changed' ? t('course.blocked_video_changed') : (r === 'video_unavailable' ? t('course.blocked_video_unavailable') : t('course.blocked_other')) };
        }
        if (status() === 'awaiting_signature') { return { tone: 'info', icon: 'fa-pen-nib', title: t('course.sign_title'), text: t('course.sign_body') }; }
        if (status() === 'awaiting_session') { return { tone: 'info', icon: 'fa-users', title: t('course.session_title'), text: t('course.session_body') }; }
        if (status() === 'awaiting_evaluation') { return { tone: 'info', icon: 'fa-hard-hat', title: t('course.evaluation_title'), text: t('course.evaluation_body') }; }
        return null;
    }
    function cta() {
        if (P.needs_online === false) { return { hidden: true }; }
        if (!run) { return null; }
        if (run.locked || run.blocked) { return { label: t('course.see_trainer'), icon: 'fa-lock', iconFirst: true, disabled: true }; }
        if (status() === 'awaiting_signature') {
            return { label: t('course.sign_to_finish'), icon: 'fa-pen-nib', iconFirst: true, onClick: function (b) { K.ui.busy(b, true); location.assign('/kiosk/sign.php?run=' + runId()); } };
        }
        if (status() === 'awaiting_session' || status() === 'awaiting_evaluation') {
            return { label: t('course.back_home'), icon: 'fa-arrow-left', iconFirst: true, onClick: function () { location.assign('/kiosk/me.php'); } };
        }
        return null;
    }

    // ---- the player --------------------------------------------------------------------------
    var done = run && run.done && typeof run.done === 'object' ? run.done : {};
    var adapter = {
        mode: 'kiosk', canGrade: true, chrome: false,
        brand: K.data().brand || '', learnerName: S.name || '', learnerFirst: S.first || '',
        validityMonths: P.validity_months,
        initialProgress: { done: done, current: run ? run.current_uid : null, pages: {}, watch: {} },
        initialLesson: P.lesson && !frozen() ? P.lesson : null,
        signaturePad: function (container, o) { return K.ui.signaturePad(container, o); },
        ensureRun: function () {
            if (run && status() === 'in_progress') { return Promise.resolve(run); }
            return post('run_start', { course_id: P.course_id }).then(function (st) {
                run = st;
                if (status() !== 'in_progress') {
                    // A resumed run that is waiting (sign/session/evaluation) or stuck: show why.
                    location.replace('/kiosk/course.php?c=' + encodeURIComponent(String(P.course_id)));
                    return new Promise(function () { /* navigating */ });
                }
                return run;
            });
        },
        onLessonOpen: function (uid) { return post('lesson_open', { run_id: runId(), lesson_uid: uid }); },
        onTick: function (uid, s) {
            var body = { run_id: runId(), lesson_uid: uid, playing: !!s.playing, visible: !!s.visible, active: !!s.active };
            if (typeof s.position_s === 'number') { body.position_s = Math.max(0, Math.floor(s.position_s)); }
            if (Array.isArray(s.pages_seen) && s.pages_seen.length) { body.pages_seen = s.pages_seen; }
            if (s.video_id) { body.video_id = s.video_id; }
            return post('lesson_tick', body);
        },
        onLessonComplete: function (uid, ev) {
            var evidence = {};
            if (ev && typeof ev.position_s === 'number') { evidence.position_s = Math.max(0, Math.floor(ev.position_s)); }
            if (ev && Array.isArray(ev.pages_seen)) { evidence.pages_seen = ev.pages_seen; }
            return post('lesson_complete', { run_id: runId(), lesson_uid: uid, evidence: evidence }).then(function (res) { absorb(res); return res; });
        },
        signAck: function (uid, p) {
            return post('ack_sign', { run_id: runId(), lesson_uid: uid, signature_png: p.signature_png || null, pin: p.pin || null }).then(function (res) { absorb(res); return res; });
        },
        externalVideoUrl: function (uid) { return (P.video_page || '/kiosk/lesson_video.php') + '?run=' + runId() + '&l=' + encodeURIComponent(uid); },
        startQuiz: function (uid) { return post('exam_start', { run_id: runId(), lesson_uid: uid }); },
        onAnswer: function (token, q, opts) { return post('answer_save', { attempt_id: +String(token).slice(1), question_uid: q, option_uids: opts }); },
        submitQuiz: function (token, answers) {
            return post('exam_submit', { attempt_id: +String(token).slice(1), answers: answers }).then(function (res) {
                if (run && res && typeof res === 'object') {
                    if (typeof res.run_status === 'string') { run.status = res.run_status; }
                    if (res.locked) { run.locked = true; }
                }
                return res;
            });
        },
        quizInfo: function (uid) { return run && run.quizzes && run.quizzes[uid] ? run.quizzes[uid] : null; },
        onQuizResult: function (uid, res) {
            if (run && res && run.quizzes) {
                var q = run.quizzes[uid] || {};
                q.used = res.attempt_number || q.used;
                q.max = res.attempts_max || q.max;
                q.left = typeof res.attempts_left === 'number' ? res.attempts_left : q.left;
                q.locked = !!res.locked;
                q.passed = !!res.passed || !!q.passed;
                run.quizzes[uid] = q;
            }
            return res && res.next === 'sign' ? { signLabel: t('course.sign_to_finish') } : null;
        },
        onSign: function () { location.assign('/kiosk/sign.php?run=' + runId()); },
        onCourseComplete: function () { location.assign('/kiosk/sign.php?run=' + runId() + '&receipt=1'); },
        onLanguage: function (lg) { K.setLang(lg); },
        courseChips: chips,
        homeNotice: notice,
        homeCta: cta,
        runFrozen: frozen
    };

    var player = window.TrainingPlayer.mount(root, view, adapter);

    // ---- "Still there?": pause and hide any video first (§5.4 Behaviour) --------------------
    function mediaHidden(on) {
        var vids = root.querySelectorAll('video');
        for (var i = 0; i < vids.length; i++) {
            if (on) { try { vids[i].pause(); } catch (e) { /* ignore */ } }
            vids[i].classList.toggle('kl-hidden', !!on);
        }
    }
    K.idle.start({
        onWarn: function (left) {
            mediaHidden(true);
            K.ui.confirm(t('shell.idle_body', { seconds: left }), t('shell.idle_stay'), t('shell.idle_leave'), { title: t('shell.idle_title') }).then(function (stay) {
                mediaHidden(false);
                if (stay) { K.idle.touch(); K.session.beat(); } else { K.session.end('done'); }
            });
        },
        onIdle: function () { mediaHidden(true); K.session.end('idle'); }
    });

    // The shell's EN|ES toggle re-renders nothing here: a run keeps its language (§1 L-9); the reload shows the rest.
    window.addEventListener('pagehide', function () { try { player.destroy(); } catch (e) { /* ignore */ } });
}());
