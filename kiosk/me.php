<?php

/*
 * Learning Center (P3 spec §5.3, mockup Kiosk-LearningCenter) - what every employee sees after the
 * PIN. Learner session only. The page is assembled from the frozen interfaces (§3.7 RecordsBridge
 * learnerSummary, K6 AwardRepository, K1 RevisionCache) plus read-only P1/P3 rows (courses, runs);
 * no P2 table is named here. Housekeeping first (§0.4 exception): RunService::settleAwaiting and
 * AttemptFinalizer::finalizeExpired for this learner's open runs - both idempotent and
 * system-derived. Every value reaches the DOM through k-page-data and textContent.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\RevisionCache;

$k_session = kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();

// ---- housekeeping (idempotent; a failure never blocks the page) ------------------------------
$k_open_runs = static fn(): array => Db::all($db, 'SELECT trun_id, trun_course_id, trun_revision_id, trun_status, trun_progress_pct,
        trun_current_lesson_uid, trun_locked_at_utc, trun_blocked_reason, trun_last_activity_at_utc
    FROM training_runs WHERE trun_contact_id = ? AND trun_open_guard = 1 ORDER BY trun_last_activity_at_utc DESC', 'i', [$cid]);
try {
    if (class_exists(\ITFlow\Training\Kiosk\Learn\AttemptFinalizer::class)) {
        foreach ($k_open_runs() as $r) {
            \ITFlow\Training\Kiosk\Learn\AttemptFinalizer::finalizeExpired($db, (int) $r['trun_id'], 50, $kctx->eventBase());
        }
    }
    if (class_exists(\ITFlow\Training\Kiosk\Learn\RunService::class)) {
        (new \ITFlow\Training\Kiosk\Learn\RunService($kctx))->settleAwaiting($cid);
    }
} catch (\Throwable $e) {
    error_log('Kiosk me.php housekeeping: ' . get_class($e));
}

// ---- records (P2 through the bridge) ----------------------------------------------------------
$summary = ['available' => false];
try {
    if (class_exists(\ITFlow\Training\Kiosk\Bridge\RecordsBridge::class)
        && \ITFlow\Training\Kiosk\Bridge\RecordsBridge::available($db)) {
        $summary = (new \ITFlow\Training\Kiosk\Bridge\RecordsBridge($kctx->core))->learnerSummary($cid);
    }
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

/** Lesson count, page count (documents), version number and the lesson list of a revision. */
$revFacts = static function (?int $revId) use ($db): array {
    if ($revId === null || $revId <= 0) {
        return ['lessons' => 0, 'pages' => null, 'number' => null, 'titles' => [], 'order' => []];
    }
    try {
        $rev = RevisionCache::get($db, $revId);
    } catch (\Throwable) {
        return ['lessons' => 0, 'pages' => null, 'number' => null, 'titles' => [], 'order' => []];
    }
    $doc = is_array($rev['doc'] ?? null) ? $rev['doc'] : [];
    $lessons = is_array($doc['lessons'] ?? null) ? $doc['lessons'] : [];
    $titles = [];
    $pages = null;
    $lang = (string) ($doc['course']['default_language'] ?? 'en');
    foreach ($lessons as $uid => $l) {
        $u = is_string($uid) ? $uid : (string) ($l['uid'] ?? '');
        $v = $l['variants'][$lang] ?? (is_array($l['variants'] ?? null) ? reset($l['variants']) : []);
        $titles[$u] = (string) ($v['title'] ?? $l['title'] ?? '');
        if (($l['type'] ?? '') === 'document' && isset($v['page_count'])) {
            $pages = (int) $v['page_count'];
        }
    }
    $order = is_array($doc['lesson_order'] ?? null) ? array_values(array_map('strval', $doc['lesson_order'])) : array_keys($titles);
    return ['lessons' => count($lessons), 'pages' => $pages, 'number' => isset($rev['number']) ? (int) $rev['number'] : null,
        'titles' => $titles, 'order' => $order];
};

$today = new \DateTimeImmutable('today');
$card = static function (int $courseId, ?array $item) use ($courses, $runs, $revFacts, $today): ?array {
    $c = $courses[$courseId] ?? null;
    if ($c === null || $c['course_archived_at'] !== null) {
        return null;
    }
    $run = $runs[$courseId] ?? null;
    $revId = $run !== null ? (int) $run['trun_revision_id'] : ($c['course_current_revision_id'] === null ? null : (int) $c['course_current_revision_id']);
    $f = $revFacts($revId);
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
    if ($state === 'continue' && $run['trun_current_lesson_uid'] !== null) {
        $u = (string) $run['trun_current_lesson_uid'];
        $n = array_search($u, $f['order'], true);
        if ($n !== false) {
            $resume = ['n' => $n + 1, 'title' => $f['titles'][$u] ?? ''];
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
        'name' => (string) $c['course_name'],
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
    if (class_exists(\ITFlow\Training\Achievements\AwardRepository::class)) {
        $awards = \ITFlow\Training\Achievements\AwardRepository::forContact($db, $cid);
        $awardProgress = \ITFlow\Training\Achievements\AwardRepository::progress($db, $cid);
    }
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

$counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
$hour = (int) date('G');
$k_page = [
    'title' => KioskStrings::t($kctx->lang, 'home.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css'],
    'js' => ['/js/training_kiosk_home.js'],
    'body_class' => 'kx-learn kx-home',
    'data' => [
        'greet' => $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'),
        'today' => $today->format('Y-m-d'),
        'records' => $records,
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
            'course_id' => (int) ($c['course_id'] ?? 0), 'name' => (string) ($c['course_name'] ?? ''), 'kind' => (string) ($c['kind'] ?? 'training'),
            'completed_on' => (string) ($c['completed_on'] ?? ''), 'score_pct' => isset($c['score_pct']) ? (string) $c['score_pct'] : null,
        ], array_values((array) ($summary['completed'] ?? []))),
        'completed_total' => (int) ($counts['completed'] ?? count((array) ($summary['completed'] ?? []))),
        'certificates' => array_map(static fn(array $c): array => [
            'course_name' => (string) ($c['course_name'] ?? ''), 'cert_number' => (string) ($c['cert_number'] ?? ''),
            'completed_on' => (string) ($c['completed_on'] ?? ''), 'expires_on' => isset($c['expires_on']) ? (string) $c['expires_on'] : null,
            'status' => (string) ($c['status'] ?? 'current'),
        ], array_values((array) ($summary['certificates'] ?? []))),
        'achievements' => array_map($pickAward, array_values(array_filter($awards, 'is_array'))),
        'award_progress' => array_map($pickProgress, array_values(array_filter($awardProgress, 'is_array'))),
        'notices' => [],
    ],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kl-home" id="kl-home" aria-live="polite"></div>
<noscript><p class="kx-note"><?= htmlspecialchars(KioskStrings::t($kctx->lang, 'home.load_error'), ENT_QUOTES, 'UTF-8') ?></p></noscript>
<?php require __DIR__ . '/includes/layout_bottom.php';
