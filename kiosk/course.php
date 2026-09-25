<?php

/*
 * Course overview and player (P3 spec §5.4; mockups Kiosk-CourseOverview, Kiosk-LessonDocument,
 * Kiosk-Quiz, Kiosk-QuizResult). Learner session only. The revision is the open run's revision,
 * else the course's current one; an unpublished or archived course goes back to me.php.
 * Housekeeping (§0.4): finalizeExpired for the run, then settleAwaiting. The learner view comes
 * from K3's KioskLearnerView::build (media re-pointed at /kiosk/media.php, checks and link
 * resources stripped, preview flags off) and the run state from RunService::state.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\AttemptFinalizer;
use ITFlow\Training\Kiosk\Learn\KioskLearnerView;
use ITFlow\Training\Kiosk\Learn\RunService;

$k_session = kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();

$courseId = isset($_GET['c']) && is_string($_GET['c']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['c']) === 1 ? (int) $_GET['c'] : 0;
$lessonUid = isset($_GET['l']) && is_string($_GET['l']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $_GET['l']) === 1 ? $_GET['l'] : null;
if ($courseId <= 0) {
    kiosk_redirect('/kiosk/me.php');
}
$course = Db::one($db, 'SELECT course_id, course_kind, course_name, course_current_revision_id, course_archived_at, course_validity_months,
        course_needs_online, course_needs_session, course_needs_practical
    FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
if ($course === null || $course['course_archived_at'] !== null || $course['course_current_revision_id'] === null) {
    kiosk_redirect('/kiosk/me.php');
}

function kl_run_columns(): string
{
    return 'trun_id, trun_contact_id, trun_course_id, trun_revision_id, trun_revision_sha256, trun_assignment_id, trun_language, trun_status, trun_open_guard, trun_channel, trun_started_at_utc, trun_started_kiosk_id, trun_current_lesson_uid, trun_lesson_opened_at_utc, trun_lesson_last_tick_at_utc, trun_lesson_last_active, trun_lesson_credit_s, trun_lesson_max_position, trun_lesson_pages_hex, trun_lesson_rejected_ticks, trun_progress_pct, trun_extra_attempts, trun_locked_at_utc, trun_locked_lesson_uid, trun_blocked_reason, trun_blocked_lesson_uid, trun_passed_attempt_id, trun_attested_at_utc, trun_attest_tsig_id, trun_attest_proof, trun_attest_pin_source, trun_attest_odoo_employee_id, trun_last_activity_at_utc, trun_ended_at_utc, trun_completion_id, trun_superseded_by_run_id';
}
$loadRun = static fn(): ?array => Db::one($db, 'SELECT '
    . kl_run_columns() . ' FROM training_runs WHERE trun_contact_id = ? AND trun_course_id = ? AND trun_open_guard = 1',
    'ii', [$cid, $courseId]);
$run = $loadRun();
try {
    if ($run !== null) {
        AttemptFinalizer::finalizeExpired($db, (int) $run['trun_id'], 50, $kctx->eventBase());
    }
    (new RunService($kctx))->settleAwaiting($cid);
} catch (\Throwable $e) {
    error_log('Kiosk course.php housekeeping: ' . get_class($e));
}
$run = $loadRun();

$notReady = false;
$view = null;
$state = null;
try {
    $revId = $run !== null ? (int) $run['trun_revision_id'] : (int) $course['course_current_revision_id'];
    $rev = RevisionCache::get($db, $revId);
    $lang = $run !== null ? (string) $run['trun_language'] : $kctx->lang;
    $view = KioskLearnerView::build($kctx, $rev, $lang);
    if ($run !== null) {
        $state = (new RunService($kctx))->state($run);
    }
} catch (\Throwable $e) {
    error_log('Kiosk course.php view: ' . get_class($e));
    $notReady = true;
    $view = null;
}

// Assignment facts and a valid record (P2, through the bridge only).
$assignment = null;
$completed = null;
try {
    if (RecordsBridge::available($db)) {
        $bridge = new RecordsBridge($kctx->core);
        $sum = $bridge->learnerSummary($cid);
        foreach (array_merge((array) ($sum['required'] ?? []), (array) ($sum['optional'] ?? [])) as $it) {
            if ((int) ($it['course_id'] ?? 0) === $courseId) {
                $assignment = ['due_on' => isset($it['due_on']) ? (string) $it['due_on'] : null, 'overdue' => ($it['status'] ?? '') === 'overdue'];
                break;
            }
        }
        $vc = $bridge->validCompletion($cid, $courseId);
        if (is_array($vc)) {
            $completed = ['completed_on' => (string) ($vc['completed_on'] ?? ''), 'expires_on' => isset($vc['expires_on']) ? (string) $vc['expires_on'] : null];
        }
    }
} catch (\Throwable $e) {
    error_log('Kiosk course.php records: ' . get_class($e));
}

$k_page = [
    'title' => $view !== null ? (string) ($view['course']['name'] ?? '') : KioskStrings::t($kctx->lang, 'course.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css'],
    'js' => ['/js/training_video_embed.js', '/js/training_player.js', '/js/training_kiosk_course.js'],
    'body_class' => 'kx-learn kx-course',
    'data' => [
        'course_id' => $courseId,
        'not_ready' => $notReady,
        'view' => $view,
        'run' => $state,
        'assignment' => $assignment,
        'completed' => $completed,
        'validity_months' => $course['course_validity_months'] === null ? null : (int) $course['course_validity_months'],
        'needs_online' => (int) $course['course_needs_online'] === 1,
        'video_page' => '/kiosk/lesson_video.php',
        'lesson' => $lessonUid,
    ],
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-player" id="kl-player"></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
