<?php

/*
 * Training › Reports › Course analytics › Item analysis (Phase 5 spec §5.5, S7; Lane E).
 *
 * GET /agent/training_item_analysis.php?course_id=N[&revision_id=R][&lang=en|es][&kind=exam|check|all][&period=all|30|90|365]
 *
 * Level 2 (the table reveals the answer key, which authors already see in the builder). How each
 * quiz question performed on the kiosk: % correct, upper/lower discrimination, the option spread,
 * flags and the versions the answers came from, over the caller's departments only (the
 * fail-closed training people scope). Without course_id: a published-course picker.
 * The filters and course are rendered here; the numbers come from insight_items and are built by
 * agent/js/training_insight.js with textContent only. Export CSV = insight_items_csv.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_reports.css', '/css/itflow_training_insight.css'];   // BEFORE inc_all
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // nothing below writes the session

use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Insight\ItemAnalysis;
use ITFlow\Training\Publish\RevisionRepository;

$tia_ctx = Access::ctx($mysqli);
$tia_h = static fn($s): string => nullable_htmlentities($s === null ? '' : (string) $s);
$tia_int = static fn(string $k): ?int => isset($_GET[$k]) && is_string($_GET[$k]) && ctype_digit($_GET[$k]) && strlen($_GET[$k]) < 10 ? (int) $_GET[$k] : null;

$tia_courses = [];
$tia_course = null;
$tia_revisions = [];
$tia_error = null;
try {
    $tia_courses = ItemAnalysis::courses($mysqli);
    $tia_course_id = $tia_int('course_id');
    if ($tia_course_id !== null) {
        $tia_course = ItemAnalysis::course($mysqli, $tia_course_id);
        if ($tia_course === null) {
            $tia_error = 'That course was not found. Pick one below.';
        } else {
            foreach ((new RevisionRepository($tia_ctx))->list($tia_course['id']) as $tia_r) {
                $tia_revisions[] = ['id' => (int) $tia_r['id'], 'number' => (int) $tia_r['number']];
            }
        }
    }
} catch (\Throwable $e) {
    error_log('Training item analysis page: ' . get_class($e) . ': ' . $e->getMessage());
    $tia_error = 'Item analysis could not be loaded. Try again in a moment.';
    $tia_course = null;
}

$tia_crumbs = [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Reports', 'url' => '/agent/training_reports.php']];

if ($tia_course === null) {
    // ---- Course picker ----------------------------------------------------------------------------------
    render_page_header('Item analysis', 'Pick a course to see how each of its quiz questions performed on the kiosk.', '',
        array_merge($tia_crumbs, [['label' => 'Course analytics', 'url' => '/agent/training_reports.php?tab=course'], ['label' => 'Item analysis']]));
    ?>
<div class="trr-page tri-page">
    <?php if ($tia_error !== null) { ?>
    <div class="tr-banner alert alert-warning" role="status"><?= $tia_h($tia_error) ?></div>
    <?php } ?>
    <section class="card trr-card" aria-labelledby="tri-pick-title">
        <div class="card-body">
            <h2 class="trr-card__title" id="tri-pick-title">Published courses</h2>
            <p class="trr-card__sub">Question statistics come from kiosk quiz attempts in your departments.</p>
        </div>
        <?php if ($tia_courses === []) { ?>
        <div class="card-body pt-0">
            <?php render_empty_state('fas fa-chart-bar', 'No published courses yet', 'Item analysis appears once a course with a quiz is published and taken on the kiosk.'); ?>
        </div>
        <?php } else { ?>
        <ul class="tri-picker" role="list">
            <?php foreach ($tia_courses as $tia_c) { ?>
            <li>
                <a class="tri-picker__item" href="/agent/training_item_analysis.php?course_id=<?= (int) $tia_c['id'] ?>">
                    <span class="tri-picker__name"><?= $tia_h($tia_c['name']) ?></span>
                    <span class="tri-picker__meta">
                        <?php if ($tia_c['code'] !== null) { ?><span class="trr-mono"><?= $tia_h($tia_c['code']) ?></span><?php } ?>
                        <span class="trr-chip trr-chip--outline">Version <?= (int) $tia_c['revision_number'] ?></span>
                        <?php if ($tia_c['archived']) { ?><span class="trr-chip trr-chip--neutral">Archived</span><?php } ?>
                    </span>
                    <i class="fas fa-chevron-right tri-picker__go" aria-hidden="true"></i>
                </a>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
    </section>
</div>
    <?php
    require_once "../includes/footer.php";
    exit;
}

// ---- One course -------------------------------------------------------------------------------------------
$tia_languages = [];
foreach ($tia_ctx->settings->languages as $tia_l) {
    $tia_languages[] = ['code' => $tia_l, 'label' => TrainingSettings::KNOWN_LANGUAGES[$tia_l] ?? strtoupper($tia_l)];
}
$tia_rev_ids = array_column($tia_revisions, 'id');
$tia_revision = $tia_int('revision_id');
$tia_revision = in_array($tia_revision, $tia_rev_ids, true) ? $tia_revision : null;
$tia_lang = isset($_GET['lang']) && is_string($_GET['lang']) && in_array($_GET['lang'], $tia_ctx->settings->languages, true) ? $_GET['lang'] : null;
$tia_kind = isset($_GET['kind']) && is_string($_GET['kind']) && in_array($_GET['kind'], ItemAnalysis::KINDS, true) ? $_GET['kind'] : 'exam';
$tia_periods = ['all' => 'All time', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months'];
$tia_period = isset($_GET['period']) && is_string($_GET['period']) && isset($tia_periods[$_GET['period']]) ? $_GET['period'] : 'all';
$tia_today = Clock::todayLocal();
$tia_since = $tia_period === 'all' ? null : Clock::addDays($tia_today, -((int) $tia_period - 1));
$tia_kinds = ['exam' => 'Exams and quizzes', 'check' => 'Quick checks', 'all' => 'All'];

$tia_csv_url = '/agent/training_ajax.php?' . http_build_query(array_filter([
    'action' => 'insight_items_csv', 'course_id' => $tia_course['id'], 'revision_id' => $tia_revision, 'lang' => $tia_lang,
    'kind' => $tia_kind, 'since' => $tia_since,
], static fn($v) => $v !== null));
$tia_analytics_url = '/agent/training_reports.php?' . http_build_query(['tab' => 'course', 'course_id' => $tia_course['id']]);
$tia_actions = '<a class="btn btn-outline-secondary" id="tri-csv" href="' . $tia_h($tia_csv_url) . '" download><i class="fas fa-download me-2" aria-hidden="true"></i>Export CSV</a>'
    . '<button type="button" class="btn btn-outline-secondary js-print-page"><i class="fas fa-print me-2" aria-hidden="true"></i>Print</button>';

/** One filter pill (the Course analytics toolbar style). */
$tia_select = static function (string $id, string $name, string $label, string $icon, array $options, ?string $selected) use ($tia_h): string {
    $html = '<label class="trr-filter" for="' . $tia_h($id) . '"><i class="' . $tia_h($icon) . '" aria-hidden="true"></i>'
        . '<span class="trr-filter__label">' . $tia_h($label) . ':</span>'
        . '<select class="trr-filter__select" id="' . $tia_h($id) . '" name="' . $tia_h($name) . '">';
    foreach ($options as $value => $text) {
        $v = (string) $value;
        $html .= '<option value="' . $tia_h($v) . '"' . ($v === (string) ($selected ?? '') ? ' selected' : '') . '>' . $tia_h($text) . '</option>';
    }
    return $html . '</select></label>';
};
$tia_course_opts = [];
foreach ($tia_courses as $tia_c) {
    $tia_course_opts[(string) $tia_c['id']] = $tia_c['name'] . ($tia_c['archived'] ? ' (archived)' : '');
}
if (!isset($tia_course_opts[(string) $tia_course['id']])) {
    $tia_course_opts = [(string) $tia_course['id'] => $tia_course['name']] + $tia_course_opts;   // unpublished: keep it selectable
}
$tia_rev_opts = ['' => 'All versions'];
foreach ($tia_revisions as $tia_r) {
    $tia_rev_opts[(string) $tia_r['id']] = 'Version ' . $tia_r['number'];
}
$tia_lang_opts = ['' => 'All'];
foreach ($tia_languages as $tia_l) {
    $tia_lang_opts[$tia_l['code']] = $tia_l['label'];
}

render_page_header('Item analysis · ' . $tia_course['name'], null, $tia_actions,
    array_merge($tia_crumbs, [['label' => 'Course analytics', 'url' => $tia_analytics_url], ['label' => 'Item analysis']]));
?>

<div class="trr-page tri-page" id="tri-root">
    <form class="trr-filterbar tri-filters" id="tri-filters" method="get" action="/agent/training_item_analysis.php" role="search" aria-label="Filter the item analysis">
        <?= $tia_select('tri-f-course', 'course_id', 'Course', 'fas fa-graduation-cap', $tia_course_opts, (string) $tia_course['id']) ?>
        <?= $tia_select('tri-f-revision', 'revision_id', 'Version', 'fas fa-code-branch', $tia_rev_opts, $tia_revision === null ? '' : (string) $tia_revision) ?>
        <?= $tia_select('tri-f-lang', 'lang', 'Language', 'fas fa-language', $tia_lang_opts, $tia_lang ?? '') ?>
        <?= $tia_select('tri-f-kind', 'kind', 'Quiz', 'far fa-question-circle', $tia_kinds, $tia_kind) ?>
        <?= $tia_select('tri-f-period', 'period', 'Period', 'far fa-calendar', $tia_periods, $tia_period) ?>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Apply</button></noscript>
    </form>

    <div class="trr-kpis tri-kpis" id="tri-kpis" aria-live="polite" aria-busy="true">
        <?php foreach (['Attempts', 'People', 'First-try pass', 'Mean score', 'Median time'] as $tia_k) { ?>
        <div class="trr-kpi tr-skeleton"><div class="trr-kpi__label"><?= $tia_h($tia_k) ?></div><div class="tr-skeleton__line tr-skeleton__line--title"></div><div class="tr-skeleton__line tr-skeleton__line--short"></div></div>
        <?php } ?>
    </div>

    <section class="card trr-card tri-card" aria-labelledby="tri-q-title">
        <div class="card-body tri-card__head">
            <div class="trr-card__head mb-0">
                <div>
                    <h2 class="trr-card__title" id="tri-q-title">Questions</h2>
                    <p class="trr-card__sub" id="tri-q-sub">How often each question was answered correctly, and whether it separates people who know the material from those who don't.</p>
                </div>
                <label class="trr-filter tri-sort" for="tri-sort" hidden>
                    <i class="fas fa-sort-amount-down" aria-hidden="true"></i><span class="trr-filter__label">Sort:</span>
                    <select class="trr-filter__select" id="tri-sort">
                        <option value="number">Question order</option>
                        <option value="hardest">Hardest first</option>
                        <option value="discrimination">Weakest discrimination first</option>
                        <option value="answered">Most answered first</option>
                    </select>
                </label>
            </div>
        </div>
        <div id="tri-body" aria-live="polite" aria-busy="true">
            <div class="card-body pt-0 tr-skeleton" aria-hidden="true">
                <div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div><div class="tr-skeleton__row"></div>
            </div>
        </div>
        <div class="card-body pt-2 tri-notes">
            <p class="trr-muted small mb-1"><i class="fas fa-info-circle me-1" aria-hidden="true"></i><strong>Discrimination</strong> compares the top and bottom 27 % of attempts by score: how much more often the stronger group got the question right (from −1 to 1). 0.3 or more is good; under 0.1 the question barely tells them apart; below 0, check the answer key.</p>
            <p class="trr-muted small mb-0"><i class="fas fa-eye-slash me-1" aria-hidden="true"></i>Discrimination and flags are hidden for questions answered fewer than <?= (int) ItemAnalysis::FEW_N ?> times. “Rev 2 → 3” means the answers came from both versions.</p>
        </div>
    </section>
</div>

<script type="application/json" id="tr-page-data"><?= json_encode([
    'user_id' => $tia_ctx->userId,
    'course_id' => $tia_course['id'],
    'course_name' => $tia_course['name'],
    'revision_id' => $tia_revision,
    'lang' => $tia_lang,
    'kind' => $tia_kind,
    'period' => $tia_period,
    'today' => $tia_today,
    'few_n' => ItemAnalysis::FEW_N,
    'endpoints' => ['items' => 'insight_items', 'csv' => '/agent/training_ajax.php?action=insight_items_csv', 'self' => '/agent/training_item_analysis.php'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_insight.js?v=<?= filemtime(__DIR__ . '/js/training_insight.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
