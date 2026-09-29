<?php

/*
 * Learning Center (P3 spec §5.3, mockup Kiosk-LearningCenter) - what every employee sees after the
 * PIN. Learner session only. The page is assembled from the frozen interfaces (§3.7 RecordsBridge
 * learnerSummary, K6 AwardRepository, K1 RevisionCache, K2 PinService::noticesForKsess, RunReset::notices) plus
 * read-only P1/P3 rows (courses, runs); no P2 table is named here. Housekeeping first (§0.4 exception): RunService::settleAwaiting and
 * AttemptFinalizer::finalizeExpired for this learner's open runs - both idempotent and
 * system-derived. Every value reaches the DOM through k-page-data and textContent.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Achievements\AwardRepository;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\AttemptFinalizer;
use ITFlow\Training\Kiosk\Learn\LessonCredit;
use ITFlow\Training\Kiosk\Learn\ResumePoint;
use ITFlow\Training\Kiosk\Learn\RunRepo;
use ITFlow\Training\Kiosk\Learn\RunService;
use ITFlow\Training\Kiosk\Pin\PinService;

$k_session = kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();

// ---- housekeeping (idempotent; a failure never blocks the page) ------------------------------
$k_open_runs = static fn(): array => Db::all($db, 'SELECT trun_id, trun_course_id, trun_revision_id, trun_status, trun_progress_pct,
        trun_language, trun_current_lesson_uid, trun_lesson_max_position, trun_lesson_resume_at, trun_locked_at_utc, trun_blocked_reason,
        trun_last_activity_at_utc
    FROM training_runs WHERE trun_contact_id = ? AND trun_open_guard = 1 ORDER BY trun_last_activity_at_utc DESC', 'i', [$cid]);
try {
    foreach ($k_open_runs() as $r) {
        AttemptFinalizer::finalizeExpired($db, (int) $r['trun_id'], 50, $kctx->eventBase());
    }
    (new RunService($kctx))->settleAwaiting($cid);
} catch (\Throwable $e) {
    error_log('Kiosk me.php housekeeping: ' . get_class($e));
}

// ---- records (P2 through the bridge) ----------------------------------------------------------
$summary = ['available' => false];
try {
    $summary = (new RecordsBridge($kctx->core))->learnerSummary($cid);
} catch (\Throwable $e) {
    error_log('Kiosk me.php summary: ' . get_class($e));
    $summary = ['available' => false];
}
$records = !empty($summary['available']);

// ---- course facts -----------------------------------------------------------------------------
$runs = [];
foreach ($k_open_runs() as $r) {
    $runs[(int) $r['trun_course_id']] = $r;
}
$courseIds = array_keys($runs);
foreach (['required', 'optional'] as $list) {
    foreach (($summary[$list] ?? []) as $it) {
        $courseIds[] = (int) ($it['course_id'] ?? 0);
    }
}
$courseIds = array_values(array_unique(array_filter($courseIds)));
$courses = [];
if ($courseIds !== []) {
    $in = implode(',', array_fill(0, count($courseIds), '?'));
    foreach (Db::all($db, "SELECT course_id, course_kind, course_name, course_cover_media_id, course_color, course_est_minutes,
            course_current_revision_id, course_needs_online, course_needs_session, course_needs_practical, course_archived_at
        FROM training_courses WHERE course_id IN ($in)", str_repeat('i', count($courseIds)), $courseIds) as $c) {
        $courses[(int) $c['course_id']] = $c;
    }
}

/**
 * The language a revision is shown in: the open run's language (the run keeps it), else the
 * screen language when the course has it, else the course default - so a Spanish screen shows
 * Spanish course names and lesson titles wherever the author wrote them.
 */
$k_lang_for = static function (array $doc, ?string $runLang) use ($kctx): string {
    $c = is_array($doc['course'] ?? null) ? $doc['course'] : [];
    $default = (string) ($c['default_language'] ?? 'en');
    $langs = is_array($c['languages'] ?? null) ? $c['languages'] : [$default];
    foreach ([$runLang, $kctx->lang] as $l) {
        if (is_string($l) && in_array($l, $langs, true)) {
            return $l;
        }
    }
    return $default;
};
/** The course name of a revision document in $lang (falls back to the default language). */
$k_doc_name = static function (array $doc, string $lang): string {
    $text = is_array($doc['course']['text'] ?? null) ? $doc['course']['text'] : [];
    $default = (string) ($doc['course']['default_language'] ?? 'en');
    return trim((string) ($text[$lang]['name'] ?? $text[$default]['name'] ?? ''));
};

/** Lesson count, page count (documents), version number, the course name, the lesson list and the video lessons' lengths of a revision. */
$revFacts = static function (?int $revId, ?string $runLang = null) use ($db, $k_lang_for, $k_doc_name): array {
    if ($revId === null || $revId <= 0) {
        return ['lessons' => 0, 'pages' => null, 'number' => null, 'name' => '', 'titles' => [], 'order' => [], 'videos' => []];
    }
    try {
        $rev = RevisionCache::get($db, $revId);
    } catch (\Throwable) {
        return ['lessons' => 0, 'pages' => null, 'number' => null, 'name' => '', 'titles' => [], 'order' => [], 'videos' => []];
    }
    $doc = is_array($rev['doc'] ?? null) ? $rev['doc'] : [];
    $lessons = is_array($doc['lessons'] ?? null) ? $doc['lessons'] : [];
    $titles = [];
    $videos = [];
    $pages = null;
    $lang = $k_lang_for($doc, $runLang);
    foreach ($lessons as $uid => $l) {
        $u = is_string($uid) ? $uid : (string) ($l['uid'] ?? '');
        $v = $l['variants'][$lang] ?? (is_array($l['variants'] ?? null) ? reset($l['variants']) : []);
        $titles[$u] = (string) ($v['title'] ?? $l['title'] ?? '');
        if (($l['type'] ?? '') === 'video' && is_array($l)) {
            // the length the run's gate uses (RunService::lessonFacts): the variant in this language, else the default
            $videos[$u] = LessonCredit::videoDuration(RunRepo::variant($l, $lang, (string) ($doc['course']['default_language'] ?? 'en')));
        }
        if (($l['type'] ?? '') === 'document' && isset($v['page_count'])) {
            $pages = (int) $v['page_count'];
        }
    }
    $order = is_array($doc['lesson_order'] ?? null) ? array_values(array_map('strval', $doc['lesson_order'])) : array_keys($titles);
    return ['lessons' => count($lessons), 'pages' => $pages, 'number' => isset($rev['number']) ? (int) $rev['number'] : null,
        'name' => $k_doc_name($doc, $lang), 'titles' => $titles, 'order' => $order, 'videos' => $videos];
};

$today = new \DateTimeImmutable('today');
/**
 * The first lesson of an in-progress run whose content is done while its MUST-PASS quick check is still to
 * pass (the lesson is not done yet, so progress may still read 0%), or null.
 */
$k_pending_check = static function (array $run) use ($db): ?string {
    try {
        $doc = RevisionCache::get($db, (int) $run['trun_revision_id'])['doc'];
        $credited = RunRepo::credited($db, (int) $run['trun_id']);
        return RunRepo::pendingChecks($doc, $credited, RunRepo::done($db, (int) $run['trun_id'], $doc, $credited))[0] ?? null;
    } catch (\Throwable $e) {
        error_log('Kiosk me.php pending check: ' . get_class($e));
        return null;
    }
};

$card = static function (int $courseId, ?array $item) use ($courses, $runs, $revFacts, $today, $k_pending_check): ?array {
    $c = $courses[$courseId] ?? null;
    if ($c === null || $c['course_archived_at'] !== null) {
        return null;
    }
    $run = $runs[$courseId] ?? null;
    $revId = $run !== null ? (int) $run['trun_revision_id'] : ($c['course_current_revision_id'] === null ? null : (int) $c['course_current_revision_id']);
    $f = $revFacts($revId, $run !== null ? (string) ($run['trun_language'] ?? '') : null);
    $pct = $run !== null ? (int) $run['trun_progress_pct'] : 0;
    $state = 'start';
    if ((int) $c['course_needs_online'] !== 1) {
        $state = 'session';
    } elseif ($run !== null) {
        $state = match ((string) $run['trun_status']) {
            'awaiting_signature' => 'sign',
            'awaiting_session' => 'session',
            'awaiting_evaluation' => 'evaluation',
            default => $run['trun_locked_at_utc'] !== null ? 'locked' : ($run['trun_blocked_reason'] !== null ? 'blocked'
                : (($pct > 0 || $run['trun_current_lesson_uid'] !== null) ? 'continue' : 'start')),
        };
    }
    $resume = null;
    $pendingCheck = null;
    if ($run !== null && in_array($state, ['start', 'continue'], true)) {
        $pendingCheck = $k_pending_check($run);
    }
    if ($pendingCheck !== null) {
        // The lesson is watched/read; its quick check is what is left: "Continue" and say so.
        $state = 'continue';
        $n = array_search($pendingCheck, $f['order'], true);
        $resume = ['n' => $n === false ? null : $n + 1, 'title' => $f['titles'][$pendingCheck] ?? '', 'check' => true];
    } elseif ($state === 'continue' && $run['trun_current_lesson_uid'] !== null) {
        $u = (string) $run['trun_current_lesson_uid'];
        $n = array_search($u, $f['order'], true);
        if ($n !== false) {
            $resume = ['n' => $n + 1, 'title' => $f['titles'][$u] ?? ''];
            if (isset($f['videos'][$u])) {
                // A video lesson in progress: where it continues ("Continue at 2:13" / "Sigue en 2:13") - the point the
                // video page resumes at (the last point; an older run's furthest point), null when it starts at 0.
                $resume['at_s'] = ResumePoint::videoOffer($run['trun_lesson_resume_at'] === null ? null : (int) $run['trun_lesson_resume_at'],
                    (int) $run['trun_lesson_max_position'], (int) $f['videos'][$u]);
            }
        }
    }
    $est = $c['course_est_minutes'] === null ? null : (int) $c['course_est_minutes'];
    $due = is_array($item) && isset($item['due_on']) && is_string($item['due_on']) ? $item['due_on'] : null;
    $daysLeft = null;
    if ($due !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) === 1) {
        $daysLeft = (int) $today->diff(new \DateTimeImmutable($due))->format('%r%a');
    }
    return [
        'course_id' => $courseId,
        'name' => $f['name'] !== '' ? $f['name'] : (string) $c['course_name'],
        'kind' => (string) $c['course_kind'],
        'needs_session' => (int) $c['course_needs_session'] === 1,
        'needs_practical' => (int) $c['course_needs_practical'] === 1,
        'cover_url' => $c['course_cover_media_id'] !== null && $revId !== null
            ? '/kiosk/media.php?m=' . (int) $c['course_cover_media_id'] . '&r=' . $revId : null,
        'color' => is_string($c['course_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $c['course_color']) === 1 ? $c['course_color'] : null,
        'lessons' => $f['lessons'],
        'pages' => $f['pages'],
        'version' => $f['number'],
        'minutes' => $est,
        'minutes_left' => $est === null ? null : max(1, (int) ceil($est * (100 - $pct) / 100)),
        'progress_pct' => $pct,
        'state' => $state,
        'resume' => $resume,
        'due_on' => $due,
        'days_left' => $daysLeft,
        'status' => is_array($item) ? (string) ($item['status'] ?? '') : '',
        'reason' => is_array($item) ? (string) ($item['reason_label'] ?? '') : '',
    ];
};

$required = [];
$documents = [];
$seen = [];
foreach (($summary['required'] ?? []) as $it) {
    $x = $card((int) ($it['course_id'] ?? 0), $it);
    if ($x === null) {
        continue;
    }
    $seen[$x['course_id']] = true;
    if ($x['kind'] === 'document') {
        $documents[] = $x;
    } else {
        $required[] = $x;
    }
}
// Started but not (or no longer) assigned: still continuable from here.
$inProgress = [];
foreach ($runs as $courseId => $r) {
    if (isset($seen[$courseId])) {
        continue;
    }
    $x = $card($courseId, null);
    if ($x !== null) {
        $inProgress[] = $x;
    }
}

$awards = [];
$awardProgress = [];
try {
    $awards = AwardRepository::forContact($db, $cid, $kctx->lang);
    $awardProgress = AwardRepository::progress($db, $cid, $kctx->lang);
} catch (\Throwable $e) {
    error_log('Kiosk me.php awards: ' . get_class($e));
}
$pickAward = static fn(array $a): array => [
    'uid' => (string) ($a['uid'] ?? ''), 'name' => (string) ($a['name'] ?? ''), 'description' => (string) ($a['description'] ?? ''),
    'icon' => preg_match('/^[a-z0-9-]{1,40}$/D', (string) ($a['icon'] ?? '')) === 1 ? (string) $a['icon'] : 'medal',
    'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) ($a['color'] ?? '')) === 1 ? (string) $a['color'] : null,
    'awarded_at' => (string) ($a['awarded_at'] ?? ''),
];
$pickProgress = static fn(array $a): array => [
    'uid' => (string) ($a['uid'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
    'icon' => preg_match('/^[a-z0-9-]{1,40}$/D', (string) ($a['icon'] ?? '')) === 1 ? (string) $a['icon'] : 'medal',
    'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) ($a['color'] ?? '')) === 1 ? (string) $a['color'] : null,
    'have' => (int) ($a['have'] ?? 0), 'need' => max(1, (int) ($a['need'] ?? 1)),
];

// PIN notices (K2): a reset by an agent / an Odoo PIN change, shown in the session that signed in after it.
$notices = [];
try {
    foreach (PinService::noticesForKsess($db, (int) $kctx->ksessId(), $cid) as $n) {
        $at = is_string($n['at_utc'] ?? null) && $n['at_utc'] !== '' ? Clock::localDate((string) $n['at_utc']) : null;
        if ($at === null) {
            continue;
        }
        if (($n['type'] ?? '') === 'reset') {
            $notices[] = ['kind' => 'pin_reset', 'date' => $at, 'who' => trim((string) ($n['by'] ?? ''))];
        } elseif (($n['type'] ?? '') === 'fp_changed') {
            $notices[] = ['kind' => 'pin_changed', 'date' => $at, 'who' => ''];
        }
    }
} catch (\Throwable $e) {
    error_log('Kiosk me.php notices: ' . get_class($e));
}
// Reset / take-again notices (Assignments > Reset while they were away from the kiosk), until they start the course again.
try {
    $k_reset = \ITFlow\Training\Kiosk\Learn\RunReset::notices($db, $cid);
    if ($k_reset !== []) {
        $k_ids = array_values(array_unique(array_map(static fn(array $n): int => (int) $n['course_id'], $k_reset)));
        $k_rn = [];
        foreach (Db::all($db, 'SELECT course_id, course_name, course_current_revision_id, course_archived_at FROM training_courses WHERE course_id IN ('
                . implode(',', array_fill(0, count($k_ids), '?')) . ')', str_repeat('i', count($k_ids)), $k_ids) as $r) {
            if ($r['course_archived_at'] === null) {
                $f = $r['course_current_revision_id'] === null ? null : $revFacts((int) $r['course_current_revision_id']);
                $k_rn[(int) $r['course_id']] = $f !== null && $f['name'] !== '' ? $f['name'] : (string) $r['course_name'];
            }
        }
        foreach ($k_reset as $n) {
            if (isset($k_rn[(int) $n['course_id']])) {
                $notices[] = ['kind' => $n['kind'], 'date' => $n['on'], 'who' => '', 'course' => $k_rn[(int) $n['course_id']],
                              'record_on' => $n['record_on'] ?? null, 'due_on' => $n['due_on'] ?? null];
            }
        }
    }
} catch (\Throwable $e) {
    error_log('Kiosk me.php reset notices: ' . get_class($e));
}

// Completed courses and certificates: the course's current name in the screen language (P2 gives the default one).
$k_names = [];
$k_done_ids = array_values(array_unique(array_filter(array_map(static fn($c) => is_array($c) ? (int) ($c['course_id'] ?? 0) : 0,
    array_merge((array) ($summary['completed'] ?? []), (array) ($summary['certificates'] ?? []))))));
if ($k_done_ids !== []) {
    try {
        foreach (Db::all($db, 'SELECT course_id, course_current_revision_id FROM training_courses WHERE course_id IN ('
                . implode(',', array_fill(0, count($k_done_ids), '?')) . ')', str_repeat('i', count($k_done_ids)), $k_done_ids) as $r) {
            if ($r['course_current_revision_id'] !== null) {
                $f = $revFacts((int) $r['course_current_revision_id']);
                if ($f['name'] !== '') {
                    $k_names[(int) $r['course_id']] = $f['name'];
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Kiosk me.php names: ' . get_class($e));
    }
}
$k_name = static fn(array $c): string => $k_names[(int) ($c['course_id'] ?? 0)] ?? (string) ($c['course_name'] ?? '');

$counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
$k_kb = false;
try {
    $k_kb = intval($config_module_enable_kb ?? 0) === 1 && \ITFlow\Training\Kiosk\Learn\KbAccess::clientId($db, $cid) !== null;
} catch (\Throwable $e) {
    error_log('Kiosk me.php kb: ' . get_class($e));
}
$hour = (int) date('G');
$k_page = [
    'title' => KioskStrings::t($kctx->lang, 'home.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css', '/css/itflow_training_kiosk_kb.css'],
    'js' => ['/js/training_kiosk_home.js'],
    'body_class' => 'kx-learn kx-home',
    'data' => [
        'greet' => $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'),
        'today' => $today->format('Y-m-d'),
        'records' => $records,
        'kb' => $k_kb,
        'counts' => [
            'completed' => (int) ($counts['completed'] ?? 0),
            'in_progress' => count($runs),
            'overdue' => (int) ($counts['overdue'] ?? 0),
            'to_sign' => (int) ($counts['documents_to_sign'] ?? count($documents)),
        ],
        'required' => $required,
        'documents' => $documents,
        'in_progress' => $inProgress,
        'completed' => array_map(static fn(array $c): array => [
            'course_id' => (int) ($c['course_id'] ?? 0), 'name' => $k_name($c), 'kind' => (string) ($c['kind'] ?? 'training'),
            'completed_on' => (string) ($c['completed_on'] ?? ''), 'score_pct' => isset($c['score_pct']) ? (string) $c['score_pct'] : null,
        ], array_values((array) ($summary['completed'] ?? []))),
        'completed_total' => (int) ($counts['completed'] ?? count((array) ($summary['completed'] ?? []))),
        'certificates' => array_map(static fn(array $c): array => [
            'completion_id' => (int) ($c['completion_id'] ?? 0),
            'course_name' => $k_name($c), 'cert_number' => (string) ($c['cert_number'] ?? ''),
            'completed_on' => (string) ($c['completed_on'] ?? ''), 'expires_on' => isset($c['expires_on']) ? (string) $c['expires_on'] : null,
            'status' => (string) ($c['status'] ?? 'current'),
        ], array_values((array) ($summary['certificates'] ?? []))),
        'achievements' => array_map($pickAward, array_values(array_filter($awards, 'is_array'))),
        'award_progress' => array_map($pickProgress, array_values(array_filter($awardProgress, 'is_array'))),
        'notices' => $notices,
    ],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kl-home" id="kl-home" aria-live="polite"></div>
<noscript><p class="kx-note"><?= htmlspecialchars(KioskStrings::t($kctx->lang, 'home.load_error'), ENT_QUOTES, 'UTF-8') ?></p></noscript>
<?php require __DIR__ . '/includes/layout_bottom.php';
