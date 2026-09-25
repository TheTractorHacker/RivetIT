<?php

/*
 * Training › Courses (spec §5.2). Level 1 sees published courses (link to preview); level 2
 * sees drafts too, can create (New ▾ / templates) and duplicate; level 3 archives, restores,
 * deletes never-published drafts and manages categories.
 *
 * The first page of results is computed here from the URL's filters (no flash of an empty
 * grid); agent/js/training_list.js renders it and every later refetch (course_list) with DOM
 * nodes only, and keeps the URL in step with history.replaceState.
 */

$page_extra_css = ['/css/itflow_training.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

use ITFlow\Training\Authoring\CategoryService;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\TemplateCatalog;
use ITFlow\Training\Core\Icons;

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = $tr_ctx->level;
$tr_can_author = lookupUserPermission('module_training') >= 2;
$tr_can_full = lookupUserPermission('module_training') >= 3;

// ---- filters from the URL (same whitelists as the course_list action) --------------------------
$tr_str = static function (string $k, int $max): ?string {
    $v = $_GET[$k] ?? null;
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    if ($v === '' || !mb_check_encoding($v, 'UTF-8')) {
        return null;
    }
    return mb_substr($v, 0, $max, 'UTF-8');
};
$tr_filters = [
    'q' => $tr_str('q', 100),
    'kind' => in_array($_GET['kind'] ?? null, ['training', 'document'], true) ? $_GET['kind'] : null,
    'status' => $tr_can_author && in_array($_GET['status'] ?? null, CourseService::LIST_STATUSES, true) ? $_GET['status'] : null,
    'category_id' => isset($_GET['category_id']) && ctype_digit((string) $_GET['category_id']) && (int) $_GET['category_id'] > 0 ? (int) $_GET['category_id'] : null,
    'tag_ids' => array_values(array_unique(array_filter(array_map('intval', is_array($_GET['tag_ids'] ?? null) ? array_slice($_GET['tag_ids'], 0, 20) : []), static fn($i) => $i > 0))),
    'mine' => $tr_can_author && ($_GET['mine'] ?? '') === '1',
    'sort' => in_array($_GET['sort'] ?? null, CourseService::LIST_SORTS, true) ? $_GET['sort'] : null,
];

$tr_list_error = null;
try {
    $tr_list = (new CourseService($tr_ctx))->list($tr_filters);
} catch (\Throwable $e) {
    error_log('Training courses page: ' . get_class($e) . ': ' . $e->getMessage());
    $tr_list = ['courses' => [], 'facets' => ['stats' => [], 'categories' => [], 'tags' => []]];
    $tr_list_error = 'The course list could not be loaded. Try again.';
}
$tr_stats = $tr_list['facets']['stats'] ?? [];

$tr_categories = [];
try {
    $tr_categories = (new CategoryService($tr_ctx))->list($tr_can_full);
} catch (\Throwable $e) {
    error_log('Training courses page categories: ' . $e->getMessage());
}

$tr_templates = [];
if ($tr_can_author) {
    foreach (TemplateCatalog::all() as $tr_t) {
        $tr_templates[] = $tr_t;
    }
}

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'can_author' => $tr_can_author,
    'can_full' => $tr_can_full,
    'limits' => $tr_ctx->settings->clientLimits(),
    'languages' => $tr_ctx->settings->languages,
    'language_names' => \ITFlow\Training\Core\TrainingSettings::KNOWN_LANGUAGES,
    'list' => $tr_list,
    'list_error' => $tr_list_error,
    'filters' => $tr_filters,
    'categories' => $tr_categories,
    'templates' => $tr_templates,
    'icons' => $tr_can_full ? Icons::ALLOWED : [],
    'preview_available' => is_file(__DIR__ . '/training_preview.php'),
    'banks_available' => is_file(__DIR__ . '/training_banks.php'),
];

// ---- header ----------------------------------------------------------------------------------
$tr_trainings = (int) ($tr_stats['trainings'] ?? 0);
$tr_documents = (int) ($tr_stats['documents'] ?? 0);
$tr_subtitle = ($tr_trainings + $tr_documents) === 0
    ? 'Courses and required documents'
    : $tr_trainings . ' ' . ($tr_trainings === 1 ? 'training' : 'trainings') . ' and ' . $tr_documents . ' required ' . ($tr_documents === 1 ? 'document' : 'documents');

$tr_actions = '';
if ($tr_can_author) {
    $tr_actions = '<div class="btn-group">'
        . '<button type="button" class="btn btn-primary" data-tr-new="training"><i class="fas fa-plus me-2" aria-hidden="true"></i>New course</button>'
        . '<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More ways to create a course"></button>'
        . '<ul class="dropdown-menu dropdown-menu-end">'
        . '<li><button type="button" class="dropdown-item" data-tr-new="training"><i class="fas fa-graduation-cap fa-fw me-2 text-muted" aria-hidden="true"></i>Training course</button></li>'
        . '<li><button type="button" class="dropdown-item" data-tr-new="document"><i class="fas fa-file-signature fa-fw me-2 text-muted" aria-hidden="true"></i>Required document</button></li>'
        . '<li><button type="button" class="dropdown-item" data-tr-new="template"><i class="fas fa-shapes fa-fw me-2 text-muted" aria-hidden="true"></i>From template…</button></li>'
        . '</ul></div>';
}
?>

<div class="tr-list" id="tr-list">
    <?php render_page_header('Courses', $tr_subtitle, $tr_actions, [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Courses']]); ?>

    <?php if ($tr_can_author) {
        $tr_featured = ['required_document', 'toolbox_talk', 'safety_course_quiz', 'equipment_certification'];
        ?>
    <section class="tr-tpl-strip" id="tr-tpl-strip" aria-labelledby="tr-tpl-title">
        <div class="tr-tpl-strip__head">
            <h2 class="tr-tpl-strip__title" id="tr-tpl-title">Start from a template<span class="tr-tpl-strip__hint">Comes pre-filled with the right steps. Change anything after.</span></h2>
            <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none" id="tr-tpl-hide">Hide templates</button>
        </div>
        <div class="tr-tpl-grid">
            <?php foreach ($tr_featured as $tr_key) {
                $tr_t = TemplateCatalog::get($tr_key);
                if ($tr_t === null) {
                    continue;
                }
                $tr_color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $tr_t['color']) ? $tr_t['color'] : '#0D9488';
                $tr_icon = in_array($tr_t['icon'], Icons::ALLOWED, true) ? $tr_t['icon'] : 'graduation-cap';
                ?>
            <button type="button" class="tr-tpl-card tr-lift" data-tr-template="<?= nullable_htmlentities($tr_t['key']) ?>">
                <span class="tr-tpl-card__icon" style="--tr-tpl: <?= nullable_htmlentities($tr_color) ?>" aria-hidden="true"><i class="fas fa-<?= nullable_htmlentities($tr_icon) ?>"></i></span>
                <span class="tr-tpl-card__text">
                    <span class="tr-tpl-card__name"><?= nullable_htmlentities($tr_t['name']['en']) ?></span>
                    <span class="tr-tpl-card__desc"><?= nullable_htmlentities($tr_t['description']['en']) ?></span>
                </span>
            </button>
            <?php } ?>
        </div>
    </section>
    <?php } ?>

    <div class="tr-stats" id="tr-stats">
        <div data-tr-stat="published"><?php render_stat_card('Published', (string) (int) ($tr_stats['published'] ?? 0), 'fas fa-check-circle', 'success'); ?></div>
        <?php if ($tr_can_author) { ?>
        <div data-tr-stat="drafts_changes"><?php render_stat_card('Drafts & unpublished changes', (string) (int) ($tr_stats['drafts_changes'] ?? 0), 'fas fa-pen', 'warning'); ?></div>
        <?php } ?>
        <div data-tr-stat="documents"><?php render_stat_card('Required documents', (string) (int) ($tr_stats['documents'] ?? 0), 'fas fa-file-signature', 'info'); ?></div>
        <?php if ($tr_can_author) { ?>
        <div data-tr-stat="questions"><?php render_stat_card('Question Library: questions', (string) (int) ($tr_stats['questions'] ?? 0), 'fas fa-layer-group', 'slate', is_file(__DIR__ . '/training_banks.php') ? '/agent/training_banks.php' : null); ?></div>
        <?php } ?>
    </div>

    <div class="tr-filters" role="search" aria-label="Filter courses">
        <div class="tr-filters__row">
            <label class="tr-search mb-0">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" class="form-control" id="tr-q" placeholder="Search courses" aria-label="Search courses" maxlength="100" autocomplete="off" value="<?= nullable_htmlentities($tr_filters['q'] ?? '') ?>">
            </label>
            <div class="tr-segment" role="radiogroup" aria-label="Kind" id="tr-kind">
                <button type="button" class="tr-segment__btn" role="radio" data-value="" aria-checked="<?= $tr_filters['kind'] === null ? 'true' : 'false' ?>">All</button>
                <button type="button" class="tr-segment__btn" role="radio" data-value="training" aria-checked="<?= $tr_filters['kind'] === 'training' ? 'true' : 'false' ?>">Training</button>
                <button type="button" class="tr-segment__btn" role="radio" data-value="document" aria-checked="<?= $tr_filters['kind'] === 'document' ? 'true' : 'false' ?>">Documents</button>
            </div>
            <?php if ($tr_can_author) { ?>
            <label class="tr-labelled-select mb-0">
                <span>Status</span>
                <select class="form-select" id="tr-status" aria-label="Status">
                    <?php foreach (['active' => 'Active', 'published' => 'Published', 'draft' => 'Never published', 'changes' => 'Unpublished changes', 'archived' => 'Archived', 'all' => 'All'] as $tr_v => $tr_l) { ?>
                    <option value="<?= $tr_v ?>"<?= ($tr_filters['status'] ?? 'active') === $tr_v ? ' selected' : '' ?>><?= $tr_l ?></option>
                    <?php } ?>
                </select>
            </label>
            <?php } ?>
            <label class="tr-labelled-select mb-0">
                <span>Sort</span>
                <select class="form-select" id="tr-sort" aria-label="Sort">
                    <?php foreach (['updated' => 'Recently edited', 'name' => 'Name', 'lessons' => 'Most lessons'] as $tr_v => $tr_l) { ?>
                    <option value="<?= $tr_v ?>"<?= ($tr_filters['sort'] ?? 'updated') === $tr_v ? ' selected' : '' ?>><?= $tr_l ?></option>
                    <?php } ?>
                </select>
            </label>
            <span class="tr-filters__count" id="tr-count" aria-live="polite"></span>
            <div class="tr-segment" role="group" aria-label="View">
                <button type="button" class="tr-segment__btn px-2" id="tr-view-grid" aria-pressed="true" aria-label="Grid view" title="Grid view"><i class="fas fa-th-large" aria-hidden="true"></i></button>
                <button type="button" class="tr-segment__btn px-2" id="tr-view-list" aria-pressed="false" aria-label="List view" title="List view"><i class="fas fa-list" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="tr-filters__row tr-filters__row--chips">
            <div class="tr-filters__chips" id="tr-cat-chips" role="group" aria-label="Category"></div>
            <?php if ($tr_can_full) { ?>
            <button type="button" class="btn btn-link btn-sm text-decoration-none px-1" id="tr-cat-manage"><i class="fas fa-sliders-h me-1" aria-hidden="true"></i>Manage categories…</button>
            <?php } ?>
            <div class="tr-tags-select">
                <select id="tr-tags" multiple aria-label="Tags" placeholder="Filter by tag"></select>
            </div>
            <?php if ($tr_can_author) { ?>
            <div class="form-check form-switch tr-mine">
                <input class="form-check-input" type="checkbox" role="switch" id="tr-mine"<?= $tr_filters['mine'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="tr-mine" title="Courses where you are the Responsible person">Mine</label>
            </div>
            <?php } ?>
        </div>
    </div>

    <div class="tr-results" id="tr-results" aria-live="polite" aria-busy="true">
        <div class="tr-card-grid tr-skeleton" aria-hidden="true">
            <?php for ($tr_i = 0; $tr_i < 4; $tr_i++) { ?>
            <div class="tr-skeleton__card">
                <div class="tr-skeleton__block tr-skeleton__block--cover"></div>
                <div class="p-3"><span class="tr-skeleton__line tr-skeleton__line--short"></span><span class="tr-skeleton__line tr-skeleton__line--title"></span><span class="tr-skeleton__line"></span></div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php
if ($tr_can_author) {
    require __DIR__ . '/includes/training/new_course_modal.php';
}
if ($tr_can_full) {
    require __DIR__ . '/includes/training/category_panel.php';
}
?>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_list.js?v=<?= filemtime(__DIR__ . '/js/training_list.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
