<?php

/*
 * Sign to finish + receipt (P3 spec §5.6, mockup Kiosk-SignOff). Learner session only.
 *   awaiting_signature                          -> the form (attestation, finger signature when the
 *                                                  course requires it, PIN) -> POST attest (K3)
 *   receipt=1 and the run was completed / is awaiting_* within the last 10 minutes -> the receipt,
 *                                                  read back from the DB (records through the bridge)
 *   anything else                               -> me.php
 * settleAwaiting runs first (§0.4 exception).
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Achievements\AwardRepository;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\AttestService;
use ITFlow\Training\Kiosk\Learn\RunService;

$k_session = kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();
$lang = $kctx->lang;

$runId = isset($_GET['run']) && is_string($_GET['run']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['run']) === 1 ? (int) $_GET['run'] : 0;
$wantReceipt = ($_GET['receipt'] ?? '') === '1';
if ($runId <= 0) {
    kiosk_redirect('/kiosk/me.php');
}
try {
    (new RunService($kctx))->settleAwaiting($cid);
} catch (\Throwable $e) {
    error_log('Kiosk sign.php housekeeping: ' . get_class($e));
}

$run = Db::one($db, 'SELECT trun_id, trun_contact_id, trun_course_id, trun_revision_id, trun_language, trun_status, trun_passed_attempt_id,
        trun_attested_at_utc, trun_ended_at_utc, trun_completion_id, trun_last_activity_at_utc
    FROM training_runs WHERE trun_id = ? AND trun_contact_id = ?', 'ii', [$runId, $cid]);
if ($run === null) {
    kiosk_redirect('/kiosk/me.php');
}
$course = Db::one($db, 'SELECT course_id, course_kind, course_name, course_requires_signature, course_attestation_text, course_validity_months
    FROM training_courses WHERE course_id = ?', 'i', [(int) $run['trun_course_id']]);
if ($course === null) {
    kiosk_redirect('/kiosk/me.php');
}
$status = (string) $run['trun_status'];
$runLang = in_array((string) $run['trun_language'], KioskStrings::LANGS, true) ? (string) $run['trun_language'] : $lang;

$doc = [];
try {
    $rev = RevisionCache::get($db, (int) $run['trun_revision_id']);
    $doc = is_array($rev['doc'] ?? null) ? $rev['doc'] : [];
} catch (\Throwable $e) {
    error_log('Kiosk sign.php revision: ' . get_class($e));
}
$courseName = (string) ($doc['course']['text'][$runLang]['name'] ?? $doc['course']['name'] ?? $course['course_name']);

$score = null;
if ($run['trun_passed_attempt_id'] !== null) {
    $r = Db::one($db, 'SELECT tresult_score_pct FROM training_attempt_results WHERE tresult_attempt_id = ?', 'i', [(int) $run['trun_passed_attempt_id']]);
    $score = $r === null ? null : (string) $r['tresult_score_pct'];
}
$hasExam = false;
foreach ((array) ($doc['lessons'] ?? []) as $l) {
    if (is_array($l) && ($l['type'] ?? '') === 'quiz' && (($l['quiz']['role'] ?? '') === 'exam')) {
        $hasExam = true;
    }
}

$data = ['run_id' => $runId, 'course_id' => (int) $course['course_id'], 'course_name' => $courseName, 'kind' => (string) $course['course_kind'],
    'score_pct' => $score, 'has_exam' => $hasExam, 'validity_months' => $course['course_validity_months'] === null ? null : (int) $course['course_validity_months'],
    'today' => date('Y-m-d'),
    // A class or a hands-on evaluation still finishes a blended course: the preview must not promise a certificate at signing.
    'pending' => !empty($doc['course']['components']['practical']) ? 'evaluation' : (!empty($doc['course']['components']['session']) ? 'session' : null)];

if ($status === 'awaiting_signature') {
    $text = null;
    try {
        $text = (new AttestService($kctx))->attestationText($run, $doc);
    } catch (\Throwable $e) {
        error_log('Kiosk sign.php attestation: ' . get_class($e));
    }
    if (!is_string($text) || trim($text) === '') {
        $text = (string) ($doc['course']['text'][$runLang]['attestation_text'] ?? $course['course_attestation_text'] ?? '');
        if (trim($text) === '') {
            $text = KioskStrings::t($runLang, 'sign.attest_default');
        }
        $text = strtr($text, ['{name}' => (string) ($k_session['contact_name'] ?? ''), '{course}' => $courseName, '{date}' => date('Y-m-d'), '{revision}' => '']);
    }
    $data['mode'] = 'form';
    $data['attestation'] = $text;
    $data['requires_signature'] = (int) $course['course_requires_signature'] === 1;
} else {
    $closedAt = $run['trun_attested_at_utc'] ?? $run['trun_ended_at_utc'] ?? null;
    $recent = false;
    if (is_string($closedAt) && $closedAt !== '') {
        $recent = (strtotime(Clock::nowUtc() . ' UTC') - strtotime($closedAt . ' UTC')) <= 600;
    }
    if (!$wantReceipt || !$recent || !in_array($status, ['completed', 'awaiting_session', 'awaiting_evaluation'], true)) {
        kiosk_redirect('/kiosk/me.php');
    }
    $receipt = ['status' => $status === 'completed' ? 'recorded' : ($status === 'awaiting_session' ? 'pending_session' : 'pending_evaluation'),
        'kind' => (string) $course['course_kind'], 'cert_number' => null, 'score_pct' => $score, 'completed_on' => null, 'expires_on' => null,
        'course_name' => $courseName, 'achievements' => []];
    try {
        if ($status === 'completed' && RecordsBridge::available($db)) {
            $bridge = new RecordsBridge($kctx->core);
            // This run's own record (trun_completion_id); else the pair's newest valid record.
            $vc = $run['trun_completion_id'] !== null ? $bridge->receipt((int) $run['trun_completion_id']) : null;
            if (!is_array($vc) || !empty($vc['voided'])) {
                $vc = $bridge->validCompletion($cid, (int) $course['course_id']);
            }
            if (is_array($vc)) {
                $receipt['cert_number'] = isset($vc['cert_number']) ? (string) $vc['cert_number'] : null;
                $receipt['completed_on'] = isset($vc['completed_on']) ? (string) $vc['completed_on'] : null;
                $receipt['expires_on'] = isset($vc['expires_on']) ? (string) $vc['expires_on'] : null;
            }
        }
        if (is_string($closedAt)) {
            $since = gmdate('Y-m-d H:i:s', strtotime($closedAt . ' UTC') - 900);
            foreach (AwardRepository::since($db, $cid, $since, $runLang) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $receipt['achievements'][] = [
                    'uid' => (string) ($a['uid'] ?? ''), 'name' => (string) ($a['name'] ?? ''), 'description' => (string) ($a['description'] ?? ''),
                    'icon' => preg_match('/^[a-z0-9-]{1,40}$/D', (string) ($a['icon'] ?? '')) === 1 ? (string) $a['icon'] : 'medal',
                    'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) ($a['color'] ?? '')) === 1 ? (string) $a['color'] : null,
                    'awarded_at' => (string) ($a['awarded_at'] ?? ''),
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('Kiosk sign.php receipt: ' . get_class($e));
    }
    $data['mode'] = 'receipt';
    $data['receipt'] = $receipt;
}

$k_page = [
    'title' => KioskStrings::t($lang, 'sign.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css'],
    'js' => ['/js/training_kiosk_sign.js'],
    'body_class' => 'kx-learn kx-sign-page',
    'data' => $data,
];
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kl-signoff" id="kl-sign"></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
