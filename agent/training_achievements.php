<?php

/*
 * Training › Achievements (spec §5.8, level 2): the badge board. Medallion cards on the left,
 * the "New achievement" / edit panel docked on the right (the approved mockup), with a live
 * medallion preview, icon picker (Core\Icons::ALLOWED), swatches + hex, and the rule editor.
 * Badges are awarded automatically by the award engine when completions meet a rule.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_catalog.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_languages = $tr_ctx->settings->languages;

$tr_courses = [];
foreach ((new \ITFlow\Training\Authoring\CourseService($tr_ctx))->list(['status' => 'active', 'sort' => 'name'])['courses'] as $tr_c) {
    $tr_courses[] = ['id' => $tr_c['id'], 'name' => $tr_c['name'], 'status' => $tr_c['status']];
}
$tr_categories = array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name']], (new \ITFlow\Training\Authoring\CategoryService($tr_ctx))->list());
$tr_paths = array_map(static fn($p) => ['id' => $p['id'], 'name' => $p['name']], (new \ITFlow\Training\Catalog\PathService($tr_ctx))->list());

$tr_data = [
    'level' => $tr_ctx->level,
    'user_id' => $tr_ctx->userId,
    'languages' => $tr_languages,
    'achievements' => (new \ITFlow\Training\Catalog\AchievementService($tr_ctx))->list(),
    'rule_types' => \ITFlow\Training\Catalog\AchievementRules::catalog(),
    'icons' => \ITFlow\Training\Core\Icons::ALLOWED,
    'swatches' => \ITFlow\Training\Catalog\AchievementService::SWATCHES,
    'default_icon' => \ITFlow\Training\Catalog\AchievementService::DEFAULT_ICON,
    'default_color' => \ITFlow\Training\Catalog\AchievementService::DEFAULT_COLOR,
    'courses' => $tr_courses,
    'categories' => $tr_categories,
    'paths' => $tr_paths,
];

render_page_header(
    'Achievements',
    'Badges people earn for training. They show on the kiosk and on each transcript.',
    '<button type="button" class="btn btn-primary" id="tr-ach-new"><i class="fas fa-plus me-2"></i>New achievement</button>',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Achievements']]
);
?>

<div class="tr-banner alert alert-info d-flex align-items-center gap-2" role="note">
    <i class="fas fa-info-circle" aria-hidden="true"></i>
    <span>Achievements are awarded automatically when a learner's completions meet a badge's rule.</span>
</div>

<div class="tr-catalog tr-ach-layout" id="tr-achievements">
    <div class="tr-ach-main">
        <div class="tr-cat-toolbar">
            <div class="tr-cat-segment" role="tablist" aria-label="Filter achievements" id="tr-ach-filter">
                <button type="button" role="tab" class="tr-cat-segment__btn" data-filter="all" aria-selected="true">All <span class="tr-cat-segment__count" data-count="all"></span></button>
                <button type="button" role="tab" class="tr-cat-segment__btn" data-filter="automatic" aria-selected="false">Automatic <span class="tr-cat-segment__count" data-count="automatic"></span></button>
                <button type="button" role="tab" class="tr-cat-segment__btn" data-filter="manual" aria-selected="false">Manual <span class="tr-cat-segment__count" data-count="manual"></span></button>
            </div>
        </div>
        <div class="tr-ach-grid" id="tr-ach-grid" aria-busy="true">
            <?php for ($tr_i = 0; $tr_i < 3; $tr_i++) { ?>
            <div class="tr-ach-card tr-skeleton tr-skeleton__card" aria-hidden="true">
                <div class="tr-ach-card__body"><span class="tr-medal tr-skeleton__line"></span><div class="tr-skeleton__line"></div><div class="tr-skeleton__line tr-skeleton__line--short"></div></div>
            </div>
            <?php } ?>
        </div>
    </div>

    <aside class="tr-ach-panel card" id="tr-ach-panel" hidden aria-labelledby="tr-ach-panel-title">
        <div class="card-header">
            <h2 class="card-title" id="tr-ach-panel-title">New achievement</h2>
            <button type="button" class="btn-close ms-auto" id="tr-ach-close" aria-label="Close"></button>
        </div>
        <div class="tr-ach-panel__top">
            <div class="tr-ach-preview" aria-live="polite">
                <span class="tr-medal" id="tr-ach-preview-medal" role="img" aria-label="Preview"><i class="fas fa-award" aria-hidden="true"></i></span>
                <div class="tr-ach-preview__text">
                    <div class="tr-ach-preview__label">Preview</div>
                    <div class="tr-ach-preview__name" id="tr-ach-preview-name">New achievement</div>
                    <div class="tr-ach-preview__rule" id="tr-ach-preview-rule"></div>
                </div>
            </div>
        </div>
        <form class="card-body" id="tr-ach-form" novalidate autocomplete="off">
            <div class="alert alert-warning d-none" id="tr-ach-conflict" role="alert">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="me-auto">Someone else changed this achievement.</span>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="tr-ach-reload">Load their version</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label required" for="tr-ach-name">Name</label>
                <input type="text" class="form-control" id="tr-ach-name" maxlength="100" required placeholder="Crane Crew Certified">
                <div class="invalid-feedback" data-field="name"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="tr-ach-description">Description</label>
                <textarea class="form-control" id="tr-ach-description" rows="2" maxlength="500" placeholder="Complete the Crane Operator path"></textarea>
                <div class="invalid-feedback" data-field="description"></div>
            </div>

            <div class="mb-3">
                <span class="form-label d-block" id="tr-ach-icon-label">Icon</span>
                <div class="tr-cat-icons" id="tr-ach-icons" role="radiogroup" aria-labelledby="tr-ach-icon-label"></div>
                <div class="invalid-feedback d-block" data-field="icon"></div>
            </div>

            <div class="mb-3">
                <span class="form-label d-block" id="tr-ach-color-label">Color</span>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="tr-cat-swatches" id="tr-ach-colors" role="radiogroup" aria-labelledby="tr-ach-color-label"></div>
                    <input type="text" class="form-control form-control-sm font-monospace tr-cat-hex" id="tr-ach-hex" maxlength="7" aria-label="Color hex code" placeholder="#D97706">
                </div>
                <div class="invalid-feedback d-block" data-field="color"></div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="tr-ach-rule">Earned when</label>
                <select class="form-select" id="tr-ach-rule"></select>
                <div class="tr-cat-rule-params" id="tr-ach-params"></div>
                <div class="invalid-feedback d-block" data-field="rule_type"></div>
            </div>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="tr-ach-active" checked>
                <label class="form-check-label" for="tr-ach-active">Active</label>
                <div class="form-hint">Inactive achievements stay defined but are not awarded.</div>
            </div>

            <?php if (count($tr_languages) > 1) { ?>
            <details class="tr-cat-lang mb-2" id="tr-ach-i18n">
                <summary>Other languages</summary>
                <?php foreach (array_slice($tr_languages, 1) as $tr_lang) {
                    $tr_lang_name = \ITFlow\Training\Core\TrainingSettings::KNOWN_LANGUAGES[$tr_lang] ?? strtoupper($tr_lang); ?>
                <div class="tr-cat-lang__row" data-lang="<?= nullable_htmlentities($tr_lang) ?>">
                    <label class="form-label" for="tr-ach-name-<?= nullable_htmlentities($tr_lang) ?>">Name (<?= nullable_htmlentities($tr_lang_name) ?>)</label>
                    <input type="text" class="form-control mb-2" id="tr-ach-name-<?= nullable_htmlentities($tr_lang) ?>" data-i18n="name" maxlength="100">
                    <label class="form-label" for="tr-ach-description-<?= nullable_htmlentities($tr_lang) ?>">Description (<?= nullable_htmlentities($tr_lang_name) ?>)</label>
                    <textarea class="form-control" id="tr-ach-description-<?= nullable_htmlentities($tr_lang) ?>" data-i18n="description" rows="2" maxlength="500"></textarea>
                </div>
                <?php } ?>
            </details>
            <?php } ?>
        </form>
        <div class="card-footer d-flex gap-2" id="tr-ach-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="tr-ach-archive" hidden>Archive</button>
            <button type="button" class="btn btn-outline-secondary ms-auto" id="tr-ach-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="tr-ach-save">Save</button>
        </div>
    </aside>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_achievements.js?v=<?= filemtime(__DIR__ . '/js/training_achievements.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
