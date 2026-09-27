<?php

/*
 * YouTube / Vimeo lesson (P3 spec §5.5, plan A2, mockup Kiosk-LessonVideo). The ONLY kiosk page
 * that loads third-party script, so it runs on the 'external_video' CSP profile, requires fetch
 * metadata (the bootstrap sets $kiosk_video_unsupported and falls back to the strict profile when
 * the browser sends none) and carries ONLY the restricted video CSRF token (bound to this run and
 * lesson, POST-only). No language toggle: the run's language is frozen.
 */

$KIOSK_CSP_PROFILE = 'external_video';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\KioskLearnerView;

$k_session = kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();
$lang = $kctx->lang;

$runId = isset($_GET['run']) && is_string($_GET['run']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['run']) === 1 ? (int) $_GET['run'] : 0;
$uid = isset($_GET['l']) && is_string($_GET['l']) && preg_match('/^[0-9a-z]{12}$/D', $_GET['l']) === 1 ? $_GET['l'] : '';

/** A plain message page (strict profile content: no third-party script, only the shell). */
$k_message = static function (string $titleKey, string $bodyKey, ?string $back) use ($lang): array {
    return [
        'title' => KioskStrings::t($lang, $titleKey),
        'css' => ['/css/itflow_training_kiosk_learn.css'],
        'js' => ['/js/training_kiosk_video.js'],
        'lang_toggle' => false,
        'body_class' => 'kx-learn kx-video-page',
        'data' => ['mode' => 'message', 'title_key' => $titleKey, 'body_key' => $bodyKey, 'back' => $back ?? '/kiosk/me.php'],
    ];
};

$run = $runId > 0 ? Db::one($db, 'SELECT trun_id, trun_course_id, trun_revision_id, trun_language, trun_status, trun_locked_at_utc, trun_blocked_reason
    FROM training_runs WHERE trun_id = ? AND trun_contact_id = ?', 'ii', [$runId, $cid]) : null;
$courseUrl = $run !== null ? '/kiosk/course.php?c=' . (int) $run['trun_course_id'] : '/kiosk/me.php';

$lesson = null;
$view = null;
if ($kiosk_video_unsupported) {
    $k_page = $k_message('shell.update_device_title', 'shell.update_device_body', $courseUrl);
} elseif ($run === null || $uid === '' || $run['trun_status'] !== 'in_progress' || $run['trun_locked_at_utc'] !== null || $run['trun_blocked_reason'] !== null) {
    $k_page = $k_message('course.unavailable_title', 'video.unavailable', $courseUrl);
} else {
    try {
        $rev = RevisionCache::get($db, (int) $run['trun_revision_id']);
        $view = KioskLearnerView::build($kctx, $rev, (string) $run['trun_language']);
        foreach ((array) ($view['lessons'] ?? []) as $l) {
            if (is_array($l) && ($l['uid'] ?? '') === $uid) {
                $lesson = $l;
            }
        }
    } catch (\Throwable $e) {
        error_log('Kiosk lesson_video.php view: ' . get_class($e));
        $view = null;
    }
    $v = is_array($lesson['video'] ?? null) ? $lesson['video'] : null;
    if ($view === null || $lesson === null || ($lesson['type'] ?? '') !== 'video' || $v === null
        || !in_array($v['provider'] ?? '', ['youtube', 'vimeo'], true) || !is_string($v['embed_url'] ?? null)
        || preg_match('#^https://(www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)#', (string) $v['embed_url']) !== 1) {
        $k_page = $k_message('course.unavailable_title', 'video.unavailable', $courseUrl);
    } else {
        $order = array_values(array_filter((array) ($view['lesson_order'] ?? []), 'is_string'));
        $byUid = [];
        foreach ((array) $view['lessons'] as $l) {
            if (is_array($l) && isset($l['uid'])) {
                $byUid[(string) $l['uid']] = $l;
            }
        }
        if ($order === []) {
            $order = array_keys($byUid);
        }
        $idx = array_search($uid, $order, true);
        $next = ($idx !== false && isset($order[$idx + 1], $byUid[$order[$idx + 1]])) ? $byUid[$order[$idx + 1]] : null;
        $section = null;
        foreach ((array) ($view['sections'] ?? []) as $s) {
            if (is_array($s) && in_array($uid, (array) ($s['lesson_uids'] ?? []), true)) {
                $section = (string) ($s['title'] ?? '');
            }
        }
        $k_page = [
            'title' => (string) ($lesson['title'] ?? ''),
            'css' => ['/css/itflow_training_kiosk_learn.css'],
            'js' => ['/js/training_video_embed.js', '/js/training_media_controls.js', '/js/training_kiosk_video.js'],
            'lang_toggle' => false,
            'body_class' => 'kx-learn kx-video-page',
            'csrf' => $kctx->keys->videoCsrf((string) $k_session['ksess_token_hash'], $runId, $uid),
            'video' => ['run_id' => $runId, 'lesson_uid' => $uid],
            'data' => [
                'mode' => 'video',
                'run_id' => $runId,
                'lesson_uid' => $uid,
                'provider' => (string) $v['provider'],
                'lang' => (string) ($view['lang'] ?? $run['trun_language']),   // the run's language: captions default to it
                'embed_url' => (string) $v['embed_url'],
                'video_id' => (string) ($v['video_id'] ?? ''),
                'duration_s' => (int) ($v['duration_s'] ?? 0),
                'min_watch_pct' => (int) ($v['min_watch_pct'] ?? 90),
                'title' => (string) ($lesson['title'] ?? ''),
                'crumbs' => ['course' => (string) ($view['course']['name'] ?? ''), 'section' => $section,
                    'n' => $idx === false ? null : $idx + 1, 'total' => count($order)],
                'resources' => array_values(array_map(static fn(array $r): array => [
                    'title' => (string) ($r['title'] ?? $r['name'] ?? ''), 'url' => (string) ($r['url'] ?? ''), 'kind' => (string) ($r['kind'] ?? 'file'),
                ], array_filter((array) ($lesson['resources'] ?? []), static fn($r) => is_array($r) && is_string($r['url'] ?? null)
                    && str_starts_with((string) $r['url'], '/kiosk/media.php?')))),
                'up_next' => $next === null ? null : ['n' => $idx + 2, 'title' => (string) ($next['title'] ?? ''), 'type' => (string) ($next['type'] ?? '')],
                'return_url' => $courseUrl . ($next !== null ? '&l=' . rawurlencode((string) $next['uid']) : ''),
                // A lesson with a quick check goes back to the course page on its check once the video is credited.
                'check_url' => (($lesson['quiz']['role'] ?? null) === 'check') ? $courseUrl . '&l=' . rawurlencode($uid) . '&check=1' : null,
                // what the finish button and the status line say about that check (its summary only: count, must-pass)
                'check' => (($lesson['quiz']['role'] ?? null) === 'check') ? ['must_pass' => !empty($lesson['quiz']['must_pass']),
                    'question_count' => (int) ($lesson['quiz']['question_count'] ?? 0)] : null,
                'course_url' => $courseUrl,
            ],
        ];
    }
}
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kl-page kl-video" id="kl-video"></div>
<?php require __DIR__ . '/includes/layout_bottom.php';
