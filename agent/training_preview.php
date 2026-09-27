<?php

/*
 * Training › Preview as learner:
 *   /agent/training_preview.php?course_id=&[revision_id=]&[source=revision]&[lang=]&[lesson=<uid>]   (spec §5.9)
 *
 * Levels: a draft needs level 2; a published version needs level 1 and is graded only at level 2
 * (LearnerView.can_grade). Without revision_id, level 2+ previews the draft (source=revision
 * previews the current version) and level 1 always previews the current version.
 *
 * Server side: LearnerView::forPreview() builds the draft (RevisionBuilder, strict=false) or loads
 * the revision, then projects it into LearnerView v1 (§3.7), which never contains the answer key
 * and whose HTML is purified again at projection. The quiz is drawn and graded on the server
 * (preview_quiz_start / preview_quiz_submit); nothing here or in the page data can grade it.
 *
 * This is the one agent page that opts into the external-video CSP (YouTube / Vimeo player APIs
 * and frames, path-scoped in includes/header.php). js/training_player.js renders the A17 learner
 * look inside a device frame; agent/js/training_preview.js is the adapter (API calls, progress
 * kept per viewer in localStorage - lesson uids and page numbers only, never question data).
 */

$page_csp_external_video = true;   // BEFORE inc_all: header.php reads it
$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_player.css', '/css/itflow_training_article.css'];
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = $tr_ctx->level;
$tr_course_id = intval($_GET['course_id'] ?? 0);
$tr_revision_id = (is_string($_GET['revision_id'] ?? null) && ctype_digit($_GET['revision_id']) && intval($_GET['revision_id']) > 0)
    ? intval($_GET['revision_id']) : null;
$tr_lang = (is_string($_GET['lang'] ?? null) && in_array($_GET['lang'], $tr_ctx->settings->languages, true)) ? $_GET['lang'] : null;
$tr_lesson = (is_string($_GET['lesson'] ?? null) && \ITFlow\Training\Core\Uid::valid($_GET['lesson'], 'l')) ? $_GET['lesson'] : null;
$tr_source = ($tr_revision_id !== null || ($_GET['source'] ?? '') === 'revision' || $tr_level < 2) ? 'revision' : 'draft';

$tr_view = null;
$tr_error = null;
if ($tr_course_id <= 0) {
    $tr_error = 'Choose a course to preview.';
} else {
    try {
        $tr_view = \ITFlow\Training\Preview\LearnerView::forPreview($tr_ctx, $tr_source, $tr_course_id, $tr_revision_id, $tr_lang);
    } catch (\ITFlow\Training\Api\ApiException $e) {
        $tr_error = $e->getMessage();
    } catch (\ITFlow\Training\Media\MediaException $e) {
        $tr_error = $e->toApi()->getMessage();
    } catch (\Throwable $e) {
        error_log('Training preview: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = 'This preview could not be built. Try again, or open the course and check it for problems.';
    }
}

$tr_builder_url = '/agent/training_course.php?course_id=' . $tr_course_id;
$tr_exit_url = $tr_level >= 2 && $tr_course_id > 0 ? $tr_builder_url : '/agent/training_courses.php';
$tr_course_name = $tr_view['course']['name'] ?? 'Preview';
$tr_crumbs = [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Courses', 'url' => '/agent/training_courses.php']];
if ($tr_view !== null) {
    $tr_crumbs[] = $tr_level >= 2 ? ['label' => $tr_course_name, 'url' => $tr_builder_url] : ['label' => $tr_course_name];
}
$tr_crumbs[] = ['label' => 'Preview as learner'];

render_page_header(
    $tr_view !== null ? $tr_course_name : 'Preview as learner',
    $tr_view !== null ? 'Preview as learner: what people see on the iPad.' : null,
    '<a class="btn btn-outline-secondary" href="' . nullable_htmlentities($tr_exit_url) . '"><i class="fas fa-sign-out-alt me-2"></i>Exit</a>',
    $tr_crumbs
);

if ($tr_view === null) {
    echo '<div class="card"><div class="card-body">';
    render_empty_state('fas fa-eye-slash', "This preview isn't available", $tr_error ?? '',
        '<a class="btn btn-primary" href="' . nullable_htmlentities($tr_exit_url) . '"><i class="fas fa-arrow-left me-2"></i>Back</a>');
    echo '</div></div>';
    require_once "../includes/footer.php";
    exit;
}

$tr_company_words = preg_split('/\s+/', trim((string) ($session_company_name ?? '')));
$tr_brand = ($tr_company_words[0] ?? '') !== '' ? $tr_company_words[0] : '';
$tr_is_revision = $tr_view['source']['type'] === 'revision';
$tr_data = [
    'view' => $tr_view,
    'user_id' => $tr_ctx->userId,
    'level' => $tr_level,
    'lesson' => $tr_lesson,
    'brand' => $tr_brand,
    'exit_url' => $tr_exit_url,
];
$tr_lang_names = ['en' => 'English', 'es' => 'Español'];
?>

<div class="trp-preview" id="tr-preview">
    <div class="trp-toolbar card" role="region" aria-label="Preview controls">
        <div class="trp-toolbar__left">
            <span class="trp-toolbar__pill<?= $tr_is_revision ? ' is-version' : '' ?>">
                <i class="fas fa-eye" aria-hidden="true"></i>
                <strong>PREVIEW</strong>
                <span aria-hidden="true">·</span>
                <?php if ($tr_is_revision) { ?>
                    <span>Version <?= intval($tr_view['source']['revision_number']) ?></span>
                    <span aria-hidden="true">·</span>
                    <span>nothing is recorded</span>
                <?php } else { ?>
                    <span>Draft</span>
                    <span aria-hidden="true">·</span>
                    <span>nothing is recorded</span>
                <?php } ?>
            </span>
            <?php if (!$tr_view['can_grade']) { ?>
            <span class="trp-toolbar__note text-muted small"><i class="fas fa-lock me-1" aria-hidden="true"></i>Quizzes are shown but not graded at your access level.</span>
            <?php } ?>
        </div>
        <div class="trp-toolbar__right">
            <label class="trp-toolbar__field">
                <span>Language</span>
                <select class="form-select form-select-sm" id="tr-preview-lang"<?= count($tr_view['languages']) < 2 ? ' disabled' : '' ?>>
                    <?php foreach ($tr_view['languages'] as $tr_l) { ?>
                    <option value="<?= nullable_htmlentities($tr_l) ?>"<?= $tr_l === $tr_view['lang'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_lang_names[$tr_l] ?? strtoupper($tr_l)) ?></option>
                    <?php } ?>
                </select>
            </label>
            <label class="trp-toolbar__field">
                <span>Device</span>
                <select class="form-select form-select-sm" id="tr-preview-device">
                    <option value="landscape">iPad landscape</option>
                    <option value="portrait">iPad portrait</option>
                    <option value="fit">Fit to window</option>
                </select>
            </label>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="tr-preview-reset"><i class="fas fa-undo me-1" aria-hidden="true"></i>Reset progress</button>
        </div>
    </div>

    <div class="trp-stage" id="tr-preview-stage" data-device="landscape">
        <div class="trp-stage__fit" id="tr-preview-fit">
            <div class="trp-device" id="tr-preview-device-frame">
                <div class="trp-device__screen">
                    <div class="trp-host" id="tr-player" aria-busy="true">
                        <div class="trp-loading"><span class="tr-skeleton tr-skeleton__card"></span><span class="tr-skeleton tr-skeleton__line"></span><span class="tr-skeleton tr-skeleton__line"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/js/training_video_embed.js?v=<?= filemtime(__DIR__ . '/../js/training_video_embed.js') ?>" defer></script>
<script src="/js/training_media_controls.js?v=<?= filemtime(__DIR__ . '/../js/training_media_controls.js') ?>" defer></script>
<script src="/js/training_player.js?v=<?= filemtime(__DIR__ . '/../js/training_player.js') ?>" defer></script>
<script src="/agent/js/training_preview.js?v=<?= filemtime(__DIR__ . '/js/training_preview.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
