<?php

/*
 * Training › Quiz builder page: /agent/training_quiz.php?lesson_id=   (spec §5.5, level 2)
 *
 * The full-size quiz builder for one lesson's quiz: a Quiz lesson (quiz or final exam) or the
 * quick check attached to a content lesson. Layout (xl): settings and draw sources on the left,
 * question cards in the centre, the live learner-look preview and a "Before you publish"
 * checklist on the right. agent/js/training_quiz_builder.js does all the work
 * (TrainingQuizBuilder.mount with embedded:false, started by that file's page init when it finds
 * #tr-quiz-builder); the page only resolves the lesson, the course and its languages, and renders
 * the chrome.
 *
 * Endpoints (through the component): quiz_get, quiz_attach, quiz_update, quiz_rule_*,
 * quiz_rules_reorder, quiz_pool_stats, question_*, questions_reorder, bank_tree, question_list,
 * question_paste_preview, import_commit, question_csv_template, and training_upload.php
 * (question_image, csv_import).
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_quiz.css', '/css/itflow_training_player.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_lesson_id = intval($_GET['lesson_id'] ?? 0);
$tr_lesson = null;
$tr_course = null;
$tr_error = null;
if ($tr_lesson_id <= 0) {
    $tr_error = 'Open a quiz from its course.';
} else {
    try {
        $tr_lesson = (new \ITFlow\Training\Authoring\LessonService($tr_ctx))->get($tr_lesson_id);
        $tr_course = \ITFlow\Training\Quiz\Guard::course($mysqli, (int) $tr_lesson['course_id']);
    } catch (\ITFlow\Training\Api\ApiException $e) {
        $tr_error = $e->getMessage();
    } catch (\ITFlow\Training\Media\MediaException $e) {
        $tr_error = $e->toApi()->getMessage();
    } catch (\Throwable $e) {
        error_log('Training quiz page: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = 'This quiz could not be opened. Try again.';
    }
}
if ($tr_error === null && $tr_lesson['type'] === 'acknowledgment') {
    $tr_error = "An acknowledgment lesson can't have a quiz.";
}

$tr_crumbs = [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Courses', 'url' => '/agent/training_courses.php']];
if ($tr_error !== null) {
    $tr_crumbs[] = ['label' => 'Quiz'];
    render_page_header('Quiz', null, '', $tr_crumbs);
    echo '<div class="card"><div class="card-body">';
    render_empty_state('fas fa-question-circle', "This quiz isn't available", $tr_error,
        '<a class="btn btn-primary" href="/agent/training_courses.php"><i class="fas fa-arrow-left me-2"></i>Back to courses</a>');
    echo '</div></div>';
    require_once "../includes/footer.php";
    exit;
}

$tr_langs = \ITFlow\Training\Quiz\Guard::languages($tr_course);
$tr_course_id = (int) $tr_course['course_id'];
$tr_title = trim((string) ($tr_lesson['title'] ?? '')) !== '' ? (string) $tr_lesson['title'] : ($tr_lesson['type'] === 'quiz' ? 'Untitled quiz' : 'Untitled lesson');
$tr_is_quiz = $tr_lesson['type'] === 'quiz';
$tr_builder_url = '/agent/training_course.php?course_id=' . $tr_course_id;
$tr_preview_url = '/agent/training_preview.php?course_id=' . $tr_course_id . '&lesson=' . rawurlencode((string) $tr_lesson['uid']);
$tr_company_words = preg_split('/\s+/', trim((string) ($session_company_name ?? '')));

$tr_crumbs[] = ['label' => (string) $tr_course['course_name'], 'url' => $tr_builder_url];
$tr_crumbs[] = ['label' => $tr_title];

$tr_data = [
    'lesson_id' => $tr_lesson_id,
    'lesson_uid' => (string) $tr_lesson['uid'],
    'lesson_type' => (string) $tr_lesson['type'],
    'lesson_title' => $tr_title,
    'course_id' => $tr_course_id,
    'course_name' => (string) $tr_course['course_name'],
    'archived' => $tr_course['course_archived_at'] !== null,
    'languages' => $tr_langs['offered'],
    'default_language' => $tr_langs['default'],
    'level' => $tr_ctx->level,
    'user_id' => $tr_ctx->userId,
    'limits' => $tr_ctx->settings->clientLimits(),
    'brand' => ($tr_company_words[0] ?? '') !== '' ? $tr_company_words[0] : '',
];

render_page_header(
    $tr_title,
    ($tr_is_quiz ? 'Quiz' : 'Quick check after this lesson') . ' · ' . $tr_course['course_name'],
    '<a class="btn btn-outline-secondary" href="' . nullable_htmlentities($tr_builder_url) . '"><i class="fas fa-arrow-left me-2"></i>Course</a>'
    . '<a class="btn btn-outline-primary" href="' . nullable_htmlentities($tr_preview_url) . '" target="_blank" rel="noopener"><i class="fas fa-eye me-2"></i>Preview as learner</a>',
    $tr_crumbs
);
?>

<div class="trq-host" id="tr-quiz-builder" aria-busy="false"></div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/plugins/SortableJS/Sortable.min.js" defer></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_uploader.js?v=<?= filemtime(__DIR__ . '/js/training_uploader.js') ?>" defer></script>
<script src="/js/training_player.js?v=<?= filemtime(__DIR__ . '/../js/training_player.js') ?>" defer></script>
<script src="/agent/js/training_quiz_builder.js?v=<?= filemtime(__DIR__ . '/js/training_quiz_builder.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
