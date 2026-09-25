<?php

/*
 * Training › Awarded badges (P3 spec §5.8 [S], lane K6; level 2, matching the side-nav item).
 *
 * The list of awarded achievements, newest first, within the agent's departments (award_list;
 * AwardScope fails closed), filtered by badge or person, and an "Award manually" panel for
 * manual-type achievements only (award_manual, module_training >= 2, per-person scope check on
 * the server). Everything below is rendered by agent/js/training_awards.js with textContent;
 * the page data block carries only ids, names, icons and colors.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_catalog.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_ready = \ITFlow\Training\Achievements\AwardEngine::ready($mysqli);

$tr_achievements = [];
foreach (\ITFlow\Training\Core\Db::all($mysqli, 'SELECT achievement_id, achievement_uid, achievement_name, achievement_icon, achievement_color,
        achievement_rule_type, achievement_active, achievement_archived_at
    FROM training_achievements ORDER BY achievement_sort, achievement_name, achievement_id') as $tr_a) {
    $tr_achievements[] = [
        'id' => (int) $tr_a['achievement_id'],
        'uid' => (string) $tr_a['achievement_uid'],
        'name' => (string) $tr_a['achievement_name'],
        'icon' => \ITFlow\Training\Core\Icons::valid((string) $tr_a['achievement_icon']) ? (string) $tr_a['achievement_icon'] : \ITFlow\Training\Achievements\AwardEngine::DEFAULT_ICON,
        'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) $tr_a['achievement_color']) === 1 ? (string) $tr_a['achievement_color'] : \ITFlow\Training\Achievements\AwardEngine::DEFAULT_COLOR,
        'rule_type' => (string) $tr_a['achievement_rule_type'],
        'can_award' => $tr_a['achievement_rule_type'] === 'manual' && (int) $tr_a['achievement_active'] === 1 && $tr_a['achievement_archived_at'] === null,
        'archived' => $tr_a['achievement_archived_at'] !== null,
    ];
}
$tr_can_award = $tr_ready && ($tr_ctx->level >= 2 || $tr_ctx->isAdmin) && array_filter($tr_achievements, static fn($a) => $a['can_award']) !== [];

$tr_data = [
    'user_id' => $tr_ctx->userId,
    'level' => $tr_ctx->level,
    'ready' => $tr_ready,
    'achievements' => $tr_achievements,
    'people' => $tr_can_award ? \ITFlow\Training\Achievements\AwardScope::people($tr_ctx) : [],
    'transcript' => is_file(__DIR__ . '/training_transcript.php'),
    'reason_min' => \ITFlow\Training\Achievements\AwardEngine::REASON_MIN,
    'reason_max' => \ITFlow\Training\Achievements\AwardEngine::REASON_MAX,
    'contact_id' => isset($_GET['contact_id']) && ctype_digit((string) $_GET['contact_id']) ? (int) $_GET['contact_id'] : null,
    'achievement_id' => isset($_GET['achievement_id']) && ctype_digit((string) $_GET['achievement_id']) ? (int) $_GET['achievement_id'] : null,
];

render_page_header(
    'Awarded badges',
    'Every badge people have earned, newest first. Automatic badges are given by the rules on the Achievements page.',
    $tr_can_award ? '<button type="button" class="btn btn-primary" id="tr-aw-open"><i class="fas fa-award me-2" aria-hidden="true"></i>Award manually</button>' : '',
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Awarded badges']]
);
?>

<?php if (!$tr_ready) { ?>
<div class="tr-banner alert alert-info d-flex align-items-center gap-2" role="note">
    <i class="fas fa-info-circle" aria-hidden="true"></i>
    <span>Awards start once the kiosk update is installed (Admin › Update › Update Database).</span>
</div>
<?php } elseif (($tr_ctx->level >= 2 || $tr_ctx->isAdmin) && !$tr_can_award) { ?>
<div class="tr-banner alert alert-info d-flex flex-wrap align-items-center gap-2" role="note">
    <i class="fas fa-info-circle" aria-hidden="true"></i>
    <span class="me-auto">To give badges by hand, create an achievement that is "Awarded by a trainer".</span>
    <a class="btn btn-sm btn-outline-primary" href="/agent/training_achievements.php">Open Achievements</a>
</div>
<?php } ?>

<div class="tr-ach-layout" id="tr-awards">
    <div class="tr-ach-main">
        <div class="tr-cat-toolbar">
            <label class="visually-hidden" for="tr-aw-filter">Show badge</label>
            <select class="form-select w-auto" id="tr-aw-filter">
                <option value="">All badges</option>
            </select>
            <label class="visually-hidden" for="tr-aw-search">Find a person</label>
            <div class="input-icon">
                <span class="input-icon-addon"><i class="fas fa-search" aria-hidden="true"></i></span>
                <input type="search" class="form-control" id="tr-aw-search" placeholder="Find a person" autocomplete="off" maxlength="100">
            </div>
            <div class="d-none align-items-center gap-2" id="tr-aw-person-chip">
                <span class="badge bg-secondary-lt" id="tr-aw-person-name"></span>
                <button type="button" class="btn btn-sm btn-ghost-secondary" id="tr-aw-person-clear">Show everyone</button>
            </div>
            <span class="ms-auto small text-muted" id="tr-aw-summary" aria-live="polite"></span>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-vcenter card-table" id="tr-aw-table">
                    <thead>
                        <tr>
                            <th scope="col">Badge</th>
                            <th scope="col">Person</th>
                            <th scope="col">How</th>
                            <th scope="col" class="d-none d-lg-table-cell">Reason</th>
                            <th scope="col" class="text-nowrap">Awarded</th>
                        </tr>
                    </thead>
                    <tbody id="tr-aw-rows" aria-busy="true">
                        <?php for ($tr_i = 0; $tr_i < 4; $tr_i++) { ?>
                        <tr class="tr-skeleton" aria-hidden="true">
                            <td><div class="tr-skeleton__line"></div></td>
                            <td><div class="tr-skeleton__line"></div></td>
                            <td><div class="tr-skeleton__line tr-skeleton__line--short"></div></td>
                            <td class="d-none d-lg-table-cell"><div class="tr-skeleton__line"></div></td>
                            <td><div class="tr-skeleton__line tr-skeleton__line--short"></div></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="tr-empty" id="tr-aw-empty" hidden>
                <div class="tr-empty__icon" aria-hidden="true"><i class="fas fa-medal"></i></div>
                <p class="tr-empty__title" id="tr-aw-empty-title">No badges awarded yet</p>
                <p class="tr-empty__text" id="tr-aw-empty-text">Badges appear here as people finish courses and pass exams on the kiosk.</p>
            </div>
            <div class="card-footer small text-muted" id="tr-aw-more" hidden>Showing the newest 500. Pick a badge or a person to see older ones.</div>
        </div>
    </div>

    <?php if ($tr_can_award) { ?>
    <aside class="tr-ach-panel card" id="tr-aw-panel" hidden aria-labelledby="tr-aw-panel-title">
        <div class="card-header">
            <h2 class="card-title" id="tr-aw-panel-title">Award manually</h2>
            <button type="button" class="btn-close ms-auto" id="tr-aw-close" aria-label="Close"></button>
        </div>
        <form class="card-body" id="tr-aw-form" novalidate autocomplete="off">
            <div class="alert alert-danger d-none" id="tr-aw-error" role="alert"></div>
            <div class="mb-3">
                <label class="form-label required" for="tr-aw-badge">Badge</label>
                <div class="d-flex align-items-center gap-2">
                    <span class="tr-medal tr-medal--sm flex-shrink-0" id="tr-aw-badge-medal" role="img" aria-label="Badge preview"><i class="fas fa-award" aria-hidden="true"></i></span>
                    <select class="form-select" id="tr-aw-badge" required></select>
                </div>
                <div class="form-hint">Only badges that are "Awarded by a trainer" can be given here. The same badge can be given again later.</div>
                <div class="invalid-feedback d-block" data-field="achievement_id"></div>
            </div>
            <div class="mb-3">
                <label class="form-label required" for="tr-aw-person-q">Person</label>
                <input type="search" class="form-control mb-2" id="tr-aw-person-q" placeholder="Type a name or department" maxlength="100" aria-controls="tr-aw-person">
                <select class="form-select" id="tr-aw-person" size="6" required aria-label="Matching people"></select>
                <div class="form-hint" id="tr-aw-person-hint"></div>
                <div class="invalid-feedback d-block" data-field="contact_id"></div>
            </div>
            <div class="mb-1">
                <label class="form-label required" for="tr-aw-reason">Reason</label>
                <textarea class="form-control" id="tr-aw-reason" rows="3" maxlength="<?= (int) \ITFlow\Training\Achievements\AwardEngine::REASON_MAX ?>" required placeholder="Spotted a frayed sling before the lift and stopped the job"></textarea>
                <div class="d-flex"><div class="invalid-feedback d-block me-auto w-auto flex-grow-1" data-field="reason"></div><small class="text-muted text-nowrap ms-2" id="tr-aw-reason-count" aria-live="polite"></small></div>
                <div class="form-hint">Saved with the award and shown on the person's transcript. It cannot be edited later.</div>
            </div>
        </form>
        <div class="card-footer d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary ms-auto" id="tr-aw-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="tr-aw-save"><i class="fas fa-award me-2" aria-hidden="true"></i>Award badge</button>
        </div>
    </aside>
    <?php } ?>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_awards.js?v=<?= filemtime(__DIR__ . '/js/training_awards.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
