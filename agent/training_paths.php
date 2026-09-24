<?php

/*
 * Training › Learning paths (spec §5.7). Level 1 views; level 2 edits.
 *
 * Path cards, an off-canvas editor (explicit Save, 409 on a version mismatch) and the
 * prerequisite map with an in-card edit panel. Everything the page needs arrives in the
 * #tr-page-data JSON block; agent/js/training_paths.js renders it with DOM nodes only.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_catalog.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = $tr_ctx->level;
$tr_can_edit = lookupUserPermission('module_training') >= 2;
$tr_languages = $tr_ctx->settings->languages;

$tr_courses = [];
if ($tr_can_edit) {
    foreach ((new \ITFlow\Training\Authoring\CourseService($tr_ctx))->list(['status' => 'active', 'sort' => 'name'])['courses'] as $tr_c) {
        $tr_courses[] = [
            'id' => $tr_c['id'], 'name' => $tr_c['name'], 'code' => $tr_c['code'], 'kind' => $tr_c['kind'], 'status' => $tr_c['status'],
            'est_minutes' => $tr_c['est_minutes'], 'lessons_count' => $tr_c['lessons_count'],
        ];
    }
}
$tr_achievements = [];
if ($tr_can_edit) {
    foreach ((new \ITFlow\Training\Catalog\AchievementService($tr_ctx))->list() as $tr_a) {
        $tr_achievements[] = ['id' => $tr_a['id'], 'name' => $tr_a['name'], 'icon' => $tr_a['icon'], 'color' => $tr_a['color'], 'active' => $tr_a['active']];
    }
}

$tr_data = [
    'level' => $tr_level,
    'can_edit' => $tr_can_edit,
    'user_id' => $tr_ctx->userId,
    'languages' => $tr_languages,
    'base_language' => $tr_languages[0],
    'limits' => $tr_ctx->settings->clientLimits(),
    'paths' => (new \ITFlow\Training\Catalog\PathService($tr_ctx))->list($tr_can_edit),
    'prereqs' => (new \ITFlow\Training\Catalog\PrereqService($tr_ctx))->map(),
    'courses' => $tr_courses,
    'achievements' => $tr_achievements,
    'swatches' => ['#0D9488', '#2563EB', '#7C3AED', '#DB2777', '#DC2626', '#D97706', '#16A34A', '#334155'],
    'max_courses' => \ITFlow\Training\Catalog\PathService::MAX_COURSES,
    'max_prereqs' => \ITFlow\Training\Catalog\PrereqService::MAX_PREREQS,
];

render_page_header(
    'Learning paths',
    'Courses in a set order, like a curriculum. Finishing a path can earn an achievement.',
    $tr_can_edit ? '<button type="button" class="btn btn-primary" id="tr-path-new"><i class="fas fa-plus me-2"></i>New path</button>' : '',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Learning paths']]
);
?>

<div class="tr-catalog" id="tr-paths">
    <div class="tr-cat-toolbar">
        <span class="text-muted small" id="tr-path-count" aria-live="polite"></span>
        <?php if ($tr_can_edit) { ?>
        <div class="form-check form-switch mb-0 ms-auto">
            <input class="form-check-input" type="checkbox" role="switch" id="tr-show-archived">
            <label class="form-check-label small" for="tr-show-archived">Show archived</label>
        </div>
        <?php } ?>
    </div>

    <div class="tr-path-grid" id="tr-path-grid" aria-busy="true">
        <?php for ($tr_i = 0; $tr_i < 3; $tr_i++) { ?>
        <div class="tr-path-card tr-skeleton tr-skeleton__card" aria-hidden="true">
            <div class="tr-path-card__cover"></div>
            <div class="tr-path-card__body"><div class="tr-skeleton__line"></div><div class="tr-skeleton__line tr-skeleton__line--short"></div></div>
        </div>
        <?php } ?>
    </div>

    <section class="card tr-prereq-card" aria-labelledby="tr-prereq-title">
        <div class="card-header">
            <div>
                <h3 class="card-title" id="tr-prereq-title"><i class="fas fa-sitemap me-2 text-muted"></i>Prerequisites</h3>
                <div class="text-muted small">A course can require others first. Loops are not allowed.</div>
            </div>
            <div class="card-actions ms-auto d-flex flex-wrap align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="tr-prereq-all">
                    <label class="form-check-label small" for="tr-prereq-all">All courses</label>
                </div>
                <input type="search" class="form-control form-control-sm tr-prereq-search" id="tr-prereq-search" placeholder="Find a course" aria-label="Find a course">
            </div>
        </div>
        <div class="tr-prereq-wrap">
            <div class="table-responsive tr-prereq-table">
                <table class="table table-vcenter card-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Course</th>
                            <th scope="col">Requires</th>
                            <th scope="col">Required by</th>
                            <?php if ($tr_can_edit) { ?><th scope="col" class="w-1"><span class="visually-hidden">Actions</span></th><?php } ?>
                        </tr>
                    </thead>
                    <tbody id="tr-prereq-body"></tbody>
                </table>
            </div>
            <?php if ($tr_can_edit) { ?>
            <aside class="tr-panel tr-prereq-panel" id="tr-prereq-panel" hidden aria-labelledby="tr-prereq-panel-title">
                <div class="tr-panel__header">
                    <div>
                        <div class="text-muted small">Prerequisites for</div>
                        <h4 class="mb-0" id="tr-prereq-panel-title"></h4>
                    </div>
                    <button type="button" class="btn-close" id="tr-prereq-close" aria-label="Close"></button>
                </div>
                <div class="tr-panel__body">
                    <p class="text-muted small mb-2">People must complete these courses first.</p>
                    <ul class="tr-prereq-chosen list-unstyled" id="tr-prereq-chosen"></ul>
                    <label class="form-label" for="tr-prereq-add">Add a course</label>
                    <div class="d-flex gap-2">
                        <select class="form-select" id="tr-prereq-add"></select>
                        <button type="button" class="btn btn-outline-primary" id="tr-prereq-add-btn">Add</button>
                    </div>
                    <div class="invalid-feedback d-block" id="tr-prereq-error" role="alert"></div>
                </div>
                <div class="tr-panel__footer">
                    <button type="button" class="btn btn-link" id="tr-prereq-cancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="tr-prereq-save">Save</button>
                </div>
            </aside>
            <?php } ?>
        </div>
    </section>
</div>

<?php if ($tr_can_edit) { ?>
<div class="offcanvas offcanvas-end tr-path-editor" tabindex="-1" id="tr-path-editor" aria-labelledby="tr-path-editor-title" data-bs-backdrop="static">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title h3" id="tr-path-editor-title">New learning path</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form id="tr-path-form" novalidate autocomplete="off">
            <div class="alert alert-warning d-none" id="tr-path-conflict" role="alert">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="me-auto">Someone else changed this path while you were editing.</span>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="tr-path-reload">Load their version</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label required" for="tr-path-name">Name</label>
                <input type="text" class="form-control" id="tr-path-name" maxlength="200" required placeholder="New-Hire Safety Orientation">
                <div class="invalid-feedback" data-field="name"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="tr-path-description">Description</label>
                <textarea class="form-control" id="tr-path-description" rows="2" maxlength="1000"></textarea>
                <div class="invalid-feedback" data-field="description"></div>
            </div>
            <?php if (count($tr_languages) > 1) { ?>
            <details class="tr-cat-lang mb-3" id="tr-path-i18n">
                <summary>Other languages</summary>
                <?php foreach (array_slice($tr_languages, 1) as $tr_lang) {
                    $tr_lang_name = \ITFlow\Training\Core\TrainingSettings::KNOWN_LANGUAGES[$tr_lang] ?? strtoupper($tr_lang); ?>
                <div class="tr-cat-lang__row" data-lang="<?= nullable_htmlentities($tr_lang) ?>">
                    <label class="form-label" for="tr-path-name-<?= nullable_htmlentities($tr_lang) ?>">Name (<?= nullable_htmlentities($tr_lang_name) ?>)</label>
                    <input type="text" class="form-control mb-2" id="tr-path-name-<?= nullable_htmlentities($tr_lang) ?>" data-i18n="name" maxlength="200">
                    <label class="form-label" for="tr-path-description-<?= nullable_htmlentities($tr_lang) ?>">Description (<?= nullable_htmlentities($tr_lang_name) ?>)</label>
                    <textarea class="form-control" id="tr-path-description-<?= nullable_htmlentities($tr_lang) ?>" data-i18n="description" rows="2" maxlength="1000"></textarea>
                </div>
                <?php } ?>
            </details>
            <?php } ?>

            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <span class="form-label d-block" id="tr-path-color-label">Color</span>
                    <div class="tr-cat-swatches" id="tr-path-colors" role="radiogroup" aria-labelledby="tr-path-color-label"></div>
                </div>
                <div class="col-sm-6">
                    <span class="form-label d-block">Cover</span>
                    <div class="tr-cat-cover tr-drop" id="tr-path-cover">
                        <img class="tr-cat-cover__img" id="tr-path-cover-img" alt="" hidden>
                        <div class="tr-drop__hint" id="tr-path-cover-hint"><i class="fas fa-image me-1"></i>Drop an image</div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="tr-path-cover-upload"><i class="fas fa-upload me-1"></i>Upload…</button>
                        <button type="button" class="btn btn-sm btn-link text-danger" id="tr-path-cover-remove" hidden>Remove</button>
                        <input type="file" class="d-none" id="tr-path-cover-file" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                    <div class="small text-muted" id="tr-path-cover-status" aria-live="polite"></div>
                    <div class="invalid-feedback" data-field="cover_media_id"></div>
                </div>
            </div>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="tr-path-sequential">
                <label class="form-check-label" for="tr-path-sequential">Take the courses in order</label>
                <div class="form-hint">Each course opens when the one before it is complete.</div>
            </div>

            <div class="mb-3">
                <div class="d-flex align-items-baseline mb-1">
                    <span class="form-label mb-0" id="tr-path-courses-label">Courses</span>
                    <span class="ms-auto small text-muted" id="tr-path-courses-meta"></span>
                </div>
                <ol class="tr-path-courses list-unstyled" id="tr-path-courses" aria-labelledby="tr-path-courses-label"></ol>
                <div class="tr-empty tr-path-courses-empty text-muted small" id="tr-path-courses-empty">No courses yet. Add the first one below.</div>
                <div class="invalid-feedback d-block" data-field="courses"></div>
                <label class="form-label mt-2" for="tr-path-course-add">Add courses</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="tr-path-course-add" multiple data-placeholder="Search courses"></select>
                    <button type="button" class="btn btn-outline-primary" id="tr-path-course-add-btn">Add</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="tr-path-achievement">Completion achievement</label>
                <select class="form-select" id="tr-path-achievement"></select>
                <div class="form-hint">Awarded automatically once the Learning Center launches.</div>
                <div class="invalid-feedback" data-field="achievement_id"></div>
            </div>
        </form>
    </div>
    <div class="tr-path-editor__footer" id="tr-path-footer">
        <button type="button" class="btn btn-outline-danger" id="tr-path-archive" hidden>Archive</button>
        <button type="button" class="btn btn-outline-secondary" id="tr-path-restore" hidden>Restore</button>
        <button type="button" class="btn btn-link ms-auto" data-bs-dismiss="offcanvas">Cancel</button>
        <button type="button" class="btn btn-primary" id="tr-path-save">Save</button>
    </div>
</div>
<?php } ?>

<div class="offcanvas offcanvas-end tr-path-editor" tabindex="-1" id="tr-path-view" aria-labelledby="tr-path-view-title">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title h3" id="tr-path-view-title"></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="tr-path-view-body"></div>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php if ($tr_can_edit) { ?>
<script src="/plugins/SortableJS/Sortable.min.js" defer></script>
<?php } ?>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<?php if ($tr_can_edit) { ?>
<script src="/agent/js/training_uploader.js?v=<?= filemtime(__DIR__ . '/js/training_uploader.js') ?>" defer></script>
<?php } ?>
<script src="/agent/js/training_paths.js?v=<?= filemtime(__DIR__ . '/js/training_paths.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
