<?php

/*
 * Training › Course builder (spec §5.3, level 2).
 *
 *   Content   training kind: sections + drag-drop lessons + aside (course card, to-do,
 *             languages, versions, quick add); document kind: the three-step builder
 *   Settings  per-card autosave through the page's single course store
 *   Versions  draft diff + published versions timeline (Preview / Compare / Details)
 *
 * The Create Content window (content_modal.php) and the publish modal (publish_modal.php,
 * level 3) are top-level modals on this page. Everything dynamic is rendered by
 * agent/js/training_builder.js from #tr-page-data with DOM nodes only.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_quiz.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\CategoryService;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\TagService;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\TrainingSettings;

$tr_ctx = Access::ctx($mysqli);
$tr_course_id = intval($_GET['course_id'] ?? 0);
$tr_detail = null;
$tr_error = null;
if ($tr_course_id > 0) {
    try {
        $tr_detail = (new CourseService($tr_ctx))->get($tr_course_id);
    } catch (ApiException $e) {
        $tr_error = $e->http === 404 ? 'not_found' : 'error';
    } catch (\Throwable $e) {
        error_log('Training builder: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = 'error';
    }
} else {
    $tr_error = 'not_found';
}

if ($tr_detail === null) {
    render_page_header('Course', null, '', [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Courses', 'url' => '/agent/training_courses.php'], ['label' => 'Course']]);
    echo '<div class="card"><div class="card-body">';
    if ($tr_error === 'not_found') {
        render_empty_state('fas fa-search', 'That course no longer exists', 'It may have been deleted. Go back to the course list.', '<a class="btn btn-primary" href="/agent/training_courses.php">All courses</a>');
    } else {
        render_empty_state('fas fa-exclamation-triangle', 'The course could not be opened', 'Reload the page to try again.', '<a class="btn btn-outline-secondary" href="/agent/training_courses.php">All courses</a>');
    }
    echo '</div></div>';
    require_once "../includes/footer.php";
    exit;
}

$tr_course = $tr_detail['course'];
$tr_level = $tr_ctx->level;
$tr_can_full = lookupUserPermission('module_training') >= 3;
$tr_kind = (string) $tr_course['kind'];
$tr_archived = !empty($tr_course['archived']);

$tr_categories = [];
$tr_tags = [];
$tr_courses = [];
try {
    $tr_categories = (new CategoryService($tr_ctx))->list(false);
    $tr_tags = array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name']], (new TagService($tr_ctx))->list());
    foreach ((new CourseService($tr_ctx))->list(['status' => 'active', 'sort' => 'name'])['courses'] as $tr_c) {
        if ((int) $tr_c['id'] !== $tr_course_id) {
            $tr_courses[] = ['id' => $tr_c['id'], 'name' => $tr_c['name'], 'kind' => $tr_c['kind'], 'status' => $tr_c['status']];
        }
    }
} catch (\Throwable $e) {
    error_log('Training builder lookups: ' . $e->getMessage());
}

$tr_root = dirname(__DIR__);
$tr_flags = [
    'quiz_builder' => is_file(__DIR__ . '/js/training_quiz_builder.js'),
    'quiz_page' => is_file(__DIR__ . '/training_quiz.php'),
    'video_frame' => is_file(__DIR__ . '/training_video_frame.php'),
    'preview' => is_file(__DIR__ . '/training_preview.php'),
    'banks' => is_file(__DIR__ . '/training_banks.php'),
    'article_css' => is_file($tr_root . '/css/itflow_training_article.css') ? '/css/itflow_training_article.css?v=' . filemtime($tr_root . '/css/itflow_training_article.css') : null,
    'kb' => Access::canUseKb(),
];

$tr_data = [
    'course_id' => $tr_course_id,
    'level' => $tr_level,
    'can_full' => $tr_can_full,
    'user_id' => $tr_ctx->userId,
    'limits' => $tr_ctx->settings->clientLimits(),
    'languages' => $tr_ctx->settings->languages,
    'language_names' => TrainingSettings::KNOWN_LANGUAGES,
    'default_language' => (string) $tr_course['default_language'],
    'detail' => $tr_detail,
    'categories' => $tr_categories,
    'tags' => $tr_tags,
    'courses' => $tr_courses,
    'flags' => $tr_flags,
];

$tr_name = (string) $tr_course['name'];
?>

<div class="tr-b<?= $tr_archived ? ' is-readonly' : '' ?>" id="tr-builder" data-kind="<?= nullable_htmlentities($tr_kind) ?>">
    <?php if ($tr_archived) { ?>
    <div class="tr-banner alert alert-warning" role="status" id="tr-archived-banner">
        <i class="fas fa-archive" aria-hidden="true"></i>
        <span class="me-auto"><strong>Archived.</strong> This course is read-only. Published versions and records are kept.</span>
        <?php if ($tr_can_full) { ?><button type="button" class="btn btn-sm btn-outline-dark" id="tr-restore-course">Restore</button><?php } ?>
    </div>
    <?php } ?>

    <header class="tr-b-head">
        <div class="tr-b-head__main">
            <nav aria-label="breadcrumb" class="tr-b-head__crumbs">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/agent/training.php">Training</a></li>
                    <li class="breadcrumb-item"><a href="/agent/training_courses.php">Courses</a></li>
                    <li class="breadcrumb-item active" aria-current="page" id="tr-crumb-name"><?= nullable_htmlentities($tr_name) ?></li>
                </ol>
            </nav>
            <div class="tr-b-head__title-row">
                <span class="tr-b-head__cover" id="tr-head-cover" aria-hidden="true"></span>
                <h1 class="visually-hidden" id="tr-course-h1"><?= nullable_htmlentities($tr_name) ?></h1>
                <input type="text" class="tr-inline-input tr-b-head__name" id="tr-course-name" value="<?= nullable_htmlentities($tr_name) ?>" maxlength="200" aria-label="Course name" aria-describedby="tr-course-name-error"<?= $tr_archived ? ' disabled' : '' ?>>
                <div class="tr-b-head__badges" id="tr-head-badges"></div>
            </div>
            <div class="tr-field-error" id="tr-course-name-error" role="alert"></div>
            <div class="tr-b-head__sub">
                <span class="tr-save-state" id="tr-head-save" data-state="idle" aria-live="polite"></span>
                <span id="tr-head-live"></span>
                <span class="d-inline-flex flex-wrap gap-2" id="tr-head-drift"></span>
            </div>
        </div>
        <div class="tr-b-head__actions">
            <?php if ($tr_flags['preview']) { ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="tr-preview-btn"><i class="fas fa-eye me-2" aria-hidden="true"></i>Preview</button>
                <ul class="dropdown-menu dropdown-menu-end" id="tr-preview-menu"></ul>
            </div>
            <?php } ?>
            <?php if ($tr_can_full && !$tr_archived) { ?>
            <span class="tr-b-publish-wrap" id="tr-publish-wrap" tabindex="-1">
                <button type="button" class="btn btn-primary" id="tr-publish-btn" disabled><i class="fas fa-rocket me-2" aria-hidden="true"></i>Publish</button>
            </span>
            <?php } ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary px-2" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More course actions" id="tr-course-kebab"><i class="fas fa-ellipsis-h" aria-hidden="true"></i></button>
                <ul class="dropdown-menu dropdown-menu-end" id="tr-course-menu">
                    <li><button type="button" class="dropdown-item" data-tr-act="duplicate"><i class="fas fa-copy fa-fw me-2 text-muted" aria-hidden="true"></i>Duplicate</button></li>
                    <?php if ($tr_kind === 'document') { ?>
                    <li><button type="button" class="dropdown-item" data-tr-act="duplicate-training"><i class="fas fa-graduation-cap fa-fw me-2 text-muted" aria-hidden="true"></i>Duplicate as training course</button></li>
                    <?php } ?>
                    <?php if ($tr_can_full) { ?>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($tr_archived) { ?>
                    <li><button type="button" class="dropdown-item" data-tr-act="restore"><i class="fas fa-undo fa-fw me-2 text-muted" aria-hidden="true"></i>Restore</button></li>
                    <?php } else { ?>
                    <li><button type="button" class="dropdown-item" data-tr-act="archive"><i class="fas fa-archive fa-fw me-2 text-muted" aria-hidden="true"></i>Archive…</button></li>
                    <?php } ?>
                    <?php if (empty($tr_course['current_revision'])) { ?>
                    <li><button type="button" class="dropdown-item text-danger" data-tr-act="delete"><i class="fas fa-trash-alt fa-fw me-2" aria-hidden="true"></i>Delete draft…</button></li>
                    <?php } ?>
                    <?php } ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Advanced</h6></li>
                    <li><button type="button" class="dropdown-item" data-tr-act="copy-uid"><i class="fas fa-fingerprint fa-fw me-2 text-muted" aria-hidden="true"></i>Copy course UID</button></li>
                    <?php if (!$tr_archived && count($tr_ctx->settings->languages) > 1) { ?>
                    <li><button type="button" class="dropdown-item" data-tr-act="default-language"><i class="fas fa-language fa-fw me-2 text-muted" aria-hidden="true"></i>Change default language…</button></li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </header>
    <div id="tr-head-confirm"></div>

    <ul class="nav tr-tabs" role="tablist" id="tr-tabs">
        <li class="nav-item" role="presentation"><button type="button" class="nav-link active" id="tr-tab-content" data-bs-toggle="tab" data-bs-target="#tr-pane-content" role="tab" aria-controls="tr-pane-content" aria-selected="true" data-hash="content">Content</button></li>
        <li class="nav-item" role="presentation"><button type="button" class="nav-link" id="tr-tab-settings" data-bs-toggle="tab" data-bs-target="#tr-pane-settings" role="tab" aria-controls="tr-pane-settings" aria-selected="false" tabindex="-1" data-hash="settings">Settings</button></li>
        <li class="nav-item" role="presentation"><button type="button" class="nav-link" id="tr-tab-versions" data-bs-toggle="tab" data-bs-target="#tr-pane-versions" role="tab" aria-controls="tr-pane-versions" aria-selected="false" tabindex="-1" data-hash="versions">Versions <span class="tr-count" id="tr-versions-count"><?= (int) ($tr_course['current_revision']['number'] ?? 0) ?></span></button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tr-pane-content" role="tabpanel" aria-labelledby="tr-tab-content" tabindex="0">
            <?php require __DIR__ . '/includes/training/' . ($tr_kind === 'document' ? 'course_document_tab.php' : 'course_content_tab.php'); ?>
        </div>
        <div class="tab-pane fade" id="tr-pane-settings" role="tabpanel" aria-labelledby="tr-tab-settings" tabindex="0">
            <?php require __DIR__ . '/includes/training/course_settings_tab.php'; ?>
        </div>
        <div class="tab-pane fade" id="tr-pane-versions" role="tabpanel" aria-labelledby="tr-tab-versions" tabindex="0">
            <?php require __DIR__ . '/includes/training/course_revisions_tab.php'; ?>
        </div>
    </div>
</div>

<div class="tr-tray" id="tr-tray" hidden role="region" aria-label="Uploads">
    <div class="tr-tray__header">
        <i class="fas fa-cloud-upload-alt text-muted" aria-hidden="true"></i>
        <span class="me-auto" id="tr-tray-title">Uploads</span>
        <button type="button" class="tr-icon-btn" id="tr-tray-close" aria-label="Close uploads" disabled><i class="fas fa-times" aria-hidden="true"></i></button>
    </div>
    <ul class="tr-tray__list" id="tr-tray-list"></ul>
</div>

<?php
require __DIR__ . '/includes/training/content_modal.php';
if ($tr_can_full) {
    require __DIR__ . '/includes/training/publish_modal.php';
}
?>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/plugins/SortableJS/Sortable.min.js" defer></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_uploader.js?v=<?= filemtime(__DIR__ . '/js/training_uploader.js') ?>" defer></script>
<?php if ($tr_flags['quiz_builder']) { ?>
<script src="/agent/js/training_quiz_builder.js?v=<?= filemtime(__DIR__ . '/js/training_quiz_builder.js') ?>" defer></script>
<?php } ?>
<script src="/agent/js/training_content_modal.js?v=<?= filemtime(__DIR__ . '/js/training_content_modal.js') ?>" defer></script>
<script src="/agent/js/training_publish.js?v=<?= filemtime(__DIR__ . '/js/training_publish.js') ?>" defer></script>
<script src="/agent/js/training_builder.js?v=<?= filemtime(__DIR__ . '/js/training_builder.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
