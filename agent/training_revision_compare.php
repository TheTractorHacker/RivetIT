<?php

/*
 * Training › Reports › Course analytics › Item analysis › Compare versions (Phase 5 spec §5.6, L1; Lane E).
 *
 * GET /agent/training_revision_compare.php?course_id=N[&a=R1&b=R2][&kind=exam|check|all]
 *
 * Level 2. Two published versions of one course side by side: kiosk results per version (the
 * caller's departments only), each question's % correct in both with the change, and what changed
 * in the content. Without a and b: the previous version against the newest. The numbers come from
 * insight_revisions and are built by agent/js/training_insight.js with textContent only.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_reports.css', '/css/itflow_training_insight.css'];   // BEFORE inc_all
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // nothing below writes the session

use ITFlow\Training\Core\Access;
use ITFlow\Training\Insight\ItemAnalysis;
use ITFlow\Training\Publish\RevisionRepository;

$trc_ctx = Access::ctx($mysqli);
$trc_h = static fn($s): string => nullable_htmlentities($s === null ? '' : (string) $s);
$trc_int = static fn(string $k): ?int => isset($_GET[$k]) && is_string($_GET[$k]) && ctype_digit($_GET[$k]) && strlen($_GET[$k]) < 10 ? (int) $_GET[$k] : null;

$trc_course = null;
$trc_revisions = [];
$trc_error = null;
try {
    $trc_course = ItemAnalysis::course($mysqli, (int) ($trc_int('course_id') ?? 0));
    if ($trc_course !== null) {
        foreach ((new RevisionRepository($trc_ctx))->list($trc_course['id']) as $trc_r) {
            $trc_revisions[] = ['id' => (int) $trc_r['id'], 'number' => (int) $trc_r['number']];   // newest first
        }
    }
} catch (\Throwable $e) {
    error_log('Training revision compare page: ' . get_class($e) . ': ' . $e->getMessage());
    $trc_error = 'The comparison could not be loaded. Try again in a moment.';
    $trc_course = null;
}
$trc_crumbs = [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Reports', 'url' => '/agent/training_reports.php']];

if ($trc_course === null || count($trc_revisions) < 2) {
    $trc_title = $trc_course === null ? 'Compare versions' : 'Compare versions · ' . $trc_course['name'];
    render_page_header($trc_title, null, '', array_merge($trc_crumbs, [
        ['label' => 'Item analysis', 'url' => '/agent/training_item_analysis.php' . ($trc_course !== null ? '?course_id=' . (int) $trc_course['id'] : '')],
        ['label' => 'Compare versions'],
    ]));
    echo '<div class="card"><div class="card-body">';
    if ($trc_course === null) {
        render_empty_state('fas fa-code-branch', $trc_error ?? 'That course was not found.', 'Open a course from Item analysis, then choose Compare versions.',
            '<a class="btn btn-primary" href="/agent/training_item_analysis.php">Open Item analysis</a>');
    } else {
        render_empty_state('fas fa-code-branch', 'Only one version so far', 'Versions can be compared once the course has been published again.',
            '<a class="btn btn-primary" href="/agent/training_item_analysis.php?course_id=' . (int) $trc_course['id'] . '">Back to item analysis</a>');
    }
    echo '</div></div>';
    require_once "../includes/footer.php";
    exit;
}

$trc_ids = array_column($trc_revisions, 'id');
$trc_b = $trc_int('b');
$trc_b = in_array($trc_b, $trc_ids, true) ? $trc_b : $trc_revisions[0]['id'];
$trc_a = $trc_int('a');
if (!in_array($trc_a, $trc_ids, true) || $trc_a === $trc_b) {
    $trc_a = $trc_revisions[0]['id'] === $trc_b ? $trc_revisions[1]['id'] : $trc_revisions[0]['id'];
}
$trc_kind = isset($_GET['kind']) && is_string($_GET['kind']) && in_array($_GET['kind'], ItemAnalysis::KINDS, true) ? $_GET['kind'] : 'exam';
$trc_rev_opts = [];
foreach ($trc_revisions as $trc_r) {
    $trc_rev_opts[(string) $trc_r['id']] = 'Version ' . $trc_r['number'];
}
$trc_select = static function (string $id, string $label, string $icon, array $options, string $selected) use ($trc_h): string {
    $html = '<label class="trr-filter" for="' . $trc_h($id) . '"><i class="' . $trc_h($icon) . '" aria-hidden="true"></i>'
        . '<span class="trr-filter__label">' . $trc_h($label) . ':</span><select class="trr-filter__select" id="' . $trc_h($id) . '">';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . $trc_h((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . $trc_h($text) . '</option>';
    }
    return $html . '</select></label>';
};
$trc_ia_url = '/agent/training_item_analysis.php?course_id=' . (int) $trc_course['id'];

render_page_header('Compare versions · ' . $trc_course['name'], null,
    '<button type="button" class="btn btn-outline-secondary js-print-page"><i class="fas fa-print me-2" aria-hidden="true"></i>Print</button>',
    array_merge($trc_crumbs, [
        ['label' => 'Course analytics', 'url' => '/agent/training_reports.php?' . http_build_query(['tab' => 'course', 'course_id' => $trc_course['id']])],
        ['label' => 'Item analysis', 'url' => $trc_ia_url],
        ['label' => 'Compare versions'],
    ]));
?>

<div class="trr-page tri-page" id="trc-root">
    <form class="trr-filterbar tri-filters" id="trc-filters" role="search" aria-label="Choose the versions to compare">
        <?= $trc_select('trc-f-a', 'Version A', 'fas fa-code-branch', $trc_rev_opts, (string) $trc_a) ?>
        <span class="trc-vs" aria-hidden="true">vs</span>
        <?= $trc_select('trc-f-b', 'Version B', 'fas fa-code-branch', $trc_rev_opts, (string) $trc_b) ?>
        <?= $trc_select('trc-f-kind', 'Quiz', 'far fa-question-circle', ['exam' => 'Exams and quizzes', 'check' => 'Quick checks', 'all' => 'All'], $trc_kind) ?>
    </form>

    <div class="tr-banner alert alert-info tri-note" id="tri-note" role="status" hidden><i class="fas fa-user-lock" aria-hidden="true"></i><span>Ask an administrator to grant department access to see results.</span></div>

    <div class="trr-grid trr-grid--7-5">
        <section class="card trr-card mb-0" aria-labelledby="trc-kpi-title">
            <div class="card-body pb-2">
                <h2 class="trr-card__title" id="trc-kpi-title">Results by version</h2>
                <p class="trr-card__sub">Kiosk quiz attempts in your departments on each version.</p>
            </div>
            <div id="trc-kpis" aria-live="polite" aria-busy="true">
                <div class="card-body pt-0 tr-skeleton" aria-hidden="true"><div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div></div>
            </div>
        </section>
        <section class="card trr-card mb-0" aria-labelledby="trc-diff-title">
            <div class="card-body">
                <h2 class="trr-card__title" id="trc-diff-title">What changed</h2>
                <p class="trr-card__sub" id="trc-diff-sub">From version A to version B.</p>
                <div id="trc-diff" aria-live="polite"><div class="tr-skeleton" aria-hidden="true"><div class="tr-skeleton__line"></div><div class="tr-skeleton__line tr-skeleton__line--short"></div></div></div>
            </div>
        </section>
    </div>

    <section class="card trr-card tri-card" aria-labelledby="trc-q-title">
        <div class="card-body tri-card__head">
            <h2 class="trr-card__title" id="trc-q-title">Questions</h2>
            <p class="trr-card__sub" id="trc-q-sub">% answered correctly on each version.</p>
        </div>
        <div id="trc-body" aria-live="polite" aria-busy="true">
            <div class="card-body pt-0 tr-skeleton" aria-hidden="true"><div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div></div>
        </div>
        <div class="card-body pt-2 tri-notes">
            <p class="trr-muted small mb-0"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>A change measured on fewer than <?= (int) ItemAnalysis::FEW_N ?> answers on either side is shown in grey: treat it as a hint, not a result. Different people take each version, so compare the pattern, not single points.</p>
        </div>
    </section>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode([
    'user_id' => $trc_ctx->userId,
    'course_id' => $trc_course['id'],
    'course_name' => $trc_course['name'],
    'few_n' => ItemAnalysis::FEW_N,
    'endpoints' => ['revisions' => 'insight_revisions', 'self' => '/agent/training_revision_compare.php', 'items_page' => $trc_ia_url],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_insight.js?v=<?= filemtime(__DIR__ . '/js/training_insight.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
