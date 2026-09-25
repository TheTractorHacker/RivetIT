<?php

/*
 * Training › Overview (Phase 2 spec §5.1, M11 + S7 + S16; mockup Admin-TrainingDashboard).
 *
 * Level 1. Server-rendered from DashboardService::summary() (the same data as the dash_summary
 * action); agent/js/training_dashboard.js only draws the trend chart, switches the expiring
 * window and opens the department × course drill-down (report_cell_people).
 *
 * Before computing, a full reconcile runs when the last one is more than 10 minutes old (S16,
 * system actor, lock wait 0 s, errors logged, never blocks the page).
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_reports.css'];   // BEFORE inc_all
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock before the reports compute
require_once __DIR__ . '/includes/training_records/report_ui.php';

use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\DashboardService;
use ITFlow\Training\Reports\Lookup;

$trr_ctx = Access::ctx($mysqli);
$trr_scope = Scope::forCtx($trr_ctx);
$trr_level = $trr_ctx->level;
$trr_settings = RecordsSettings::fromDb($mysqli);

// ---- filters (GET; a department outside the scope is ignored, never an error page) -----------
$trr_depts = Lookup::departments($mysqli, $trr_scope);
$trr_courses = Lookup::publishedCourses($mysqli);
$trr_client = isset($_GET['client_id']) && ctype_digit((string) $_GET['client_id']) ? (int) $_GET['client_id'] : null;
if ($trr_client !== null && !$trr_scope->allows($trr_client)) {
    $trr_client = null;
}
$trr_course = isset($_GET['course_id']) && ctype_digit((string) $_GET['course_id']) ? (int) $_GET['course_id'] : null;
if ($trr_course !== null && !in_array($trr_course, array_column($trr_courses, 'id'), true)) {
    $trr_course = null;
}
$trr_months = in_array((int) ($_GET['months'] ?? 12), DashboardService::PERIODS, true) ? (int) ($_GET['months'] ?? 12) : 12;

$trr_error = null;
$trr_has_rules = DashboardService::hasRules($mysqli);
$trr = null;
if ($trr_has_rules) {
    if (DashboardService::maybeReconcile($mysqli, $trr_settings) !== null) {
        $trr_settings = RecordsSettings::fromDb($mysqli);
    }
    try {
        $trr = (new DashboardService($trr_ctx, $trr_scope, $trr_settings))
            ->summary(['client_id' => $trr_client, 'course_id' => $trr_course, 'months' => $trr_months]);
    } catch (\Throwable $e) {
        error_log('Training dashboard: ' . get_class($e) . ': ' . $e->getMessage());
        $trr_error = 'The overview could not be calculated. Try again in a moment.';
    }
}
$trr_filters = ['client_id' => $trr_client, 'course_id' => $trr_course, 'months' => $trr_months === 12 ? null : $trr_months];
$trr_csv = trr_url('/agent/training_ajax.php', ['action' => 'report_csv', 'report' => 'matrix', 'client_id' => $trr_client, 'course_id' => $trr_course]);

$trr_actions = '<a class="btn btn-outline-secondary" href="' . trr_h($trr_csv) . '" download><i class="fas fa-download me-2" aria-hidden="true"></i>Export CSV</a>'
    . '<button type="button" class="btn btn-outline-secondary js-print-page"><i class="fas fa-print me-2" aria-hidden="true"></i>Print</button>';
?>

<div class="trr-page trr-dash" id="trr-dash">
    <?php render_page_header('Training overview', null, $trr_actions, [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Overview']]); ?>

    <form class="trr-filterbar" method="get" action="training_dashboard.php" role="search" aria-label="Filter the overview">
        <?php
        $trr_dept_opts = [[null, 'All']];
        foreach ($trr_depts as $trr_d) {
            $trr_dept_opts[] = [$trr_d['id'], $trr_d['name']];
        }
        $trr_course_opts = [[null, 'All']];
        foreach ($trr_courses as $trr_c) {
            $trr_course_opts[] = [$trr_c['id'], $trr_c['name']];
        }
        echo trr_filter_select('client_id', 'Department', $trr_dept_opts, $trr_client);
        echo trr_filter_select('course_id', 'Course', $trr_course_opts, $trr_course);
        echo trr_filter_select('months', 'Period', [[3, 'Last 3 months'], [6, 'Last 6 months'], [12, 'Last 12 months']], $trr_months, 'far fa-calendar');
        ?>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Apply</button></noscript>
        <?php if ($trr !== null) { ?>
        <p class="trr-meta">
            <i class="fas fa-sync-alt" aria-hidden="true"></i>
            <?= (int) $trr['roster_people'] ?> <?= $trr['roster_people'] === 1 ? 'person' : 'people' ?> on the roster<?php if ($trr['reconciled_at'] !== null) { ?> · recalculated <?= trr_h(trr_clock($trr['reconciled_at'])) ?><?php } ?>
        </p>
        <?php } ?>
    </form>

    <?php if ($trr_scope->isNone()) { trr_scope_banner(); } ?>
    <?php if ($trr_error !== null) { ?>
    <div class="tr-banner alert alert-danger" role="alert"><?= trr_h($trr_error) ?></div>
    <?php } ?>

    <?php if (!$trr_has_rules) { ?>
    <div class="card"><div class="card-body">
        <?php render_empty_state('fas fa-clipboard-check', 'No required training yet', 'Create an assignment rule to start tracking compliance.',
            $trr_level >= 3 ? '<a class="btn btn-primary" href="/agent/training_rule.php"><i class="fas fa-plus me-2" aria-hidden="true"></i>New rule</a>' : ''); ?>
    </div></div>
    <?php } elseif ($trr !== null) {
        $trr_k = $trr['kpis'];
        $trr_trend = $trr['trend'];
        ?>

    <!-- KPI row -->
    <section class="trr-kpis" aria-label="Key numbers">
        <div class="trr-kpi">
            <div class="trr-kpi__label">Compliance</div>
            <div class="trr-kpi__row">
                <div class="trr-kpi__value"><?= $trr_k['compliance_pct'] === null ? '—' : (int) $trr_k['compliance_pct'] . '%' ?></div>
                <span class="trr-kpi__spark"><?= trr_sparkline(array_merge($trr_trend['values'])) ?></span>
            </div>
            <div class="trr-kpi__sub">
                <?php if ($trr_k['compliance_delta_pts'] !== null) {
                    $trr_d = (int) $trr_k['compliance_delta_pts'];
                    ?>
                <span class="trr-delta trr-delta--<?= $trr_d > 0 ? 'up' : ($trr_d < 0 ? 'down' : 'flat') ?>"><i class="fas fa-arrow-<?= $trr_d >= 0 ? 'up' : 'down' ?>" aria-hidden="true"></i><?= ($trr_d > 0 ? '+' : '') . $trr_d ?> pts</span> vs 3 months ago
                <?php } else { ?>
                <?= (int) $trr_k['current'] ?> of <?= (int) $trr_k['required'] ?> required are current
                <?php } ?>
            </div>
        </div>
        <a class="trr-kpi trr-kpi--link<?= $trr_k['overdue'] > 0 || (int) ($trr_k['lapsed_open'] ?? 0) > 0 ? ' trr-kpi--alert' : '' ?>" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'overdue', 'client_id' => $trr_client, 'course_id' => $trr_course])) ?>">
            <div class="trr-kpi__label">Overdue <span class="trr-kpi__icon trr-kpi__icon--err"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span></div>
            <div class="trr-kpi__value<?= $trr_k['overdue'] > 0 ? ' trr-kpi__value--err' : '' ?>"><?= (int) $trr_k['overdue'] ?></div>
            <div class="trr-kpi__sub">
                <?php if ($trr_k['overdue'] > 0) { ?><span class="trr-chip trr-chip--err">Needs action</span> <?= (int) $trr_k['overdue_people'] ?> <?= $trr_k['overdue_people'] === 1 ? 'person' : 'people' ?><?php } else { ?>Nothing overdue<?php } ?>
                <?php if ((int) ($trr_k['lapsed_open'] ?? 0) > 0) { ?><div class="trr-bad" title="Renewals whose certificate has already expired, not due yet">+<?= (int) $trr_k['lapsed_open'] ?> expired, not qualified</div><?php } ?>
            </div>
        </a>
        <a class="trr-kpi trr-kpi--link" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'expiring', 'days' => 30, 'client_id' => $trr_client, 'course_id' => $trr_course])) ?>">
            <div class="trr-kpi__label">Expiring in 30 days <span class="trr-kpi__icon trr-kpi__icon--warn"><i class="far fa-clock" aria-hidden="true"></i></span></div>
            <div class="trr-kpi__value"><?= (int) $trr_k['expiring_30'] ?></div>
            <div class="trr-kpi__sub"><?= (int) $trr_k['renewals_assigned'] ?> <?= $trr_k['renewals_assigned'] === 1 ? 'renewal' : 'renewals' ?> already assigned</div>
        </a>
        <div class="trr-kpi">
            <div class="trr-kpi__label">Completions this month <span class="trr-kpi__icon trr-kpi__icon--ok"><i class="far fa-check-circle" aria-hidden="true"></i></span></div>
            <div class="trr-kpi__value"><?= (int) $trr_k['completions_month'] ?></div>
            <div class="trr-kpi__sub">
                <?php $trr_d = (int) $trr_k['completions_month'] - (int) $trr_k['completions_prev_month']; ?>
                <strong class="trr-delta trr-delta--<?= $trr_d > 0 ? 'up' : ($trr_d < 0 ? 'down' : 'flat') ?>"><?= ($trr_d > 0 ? '+' : '') . $trr_d ?></strong> vs <?= trr_h($trr_k['prev_month_label']) ?>
            </div>
        </div>
        <div class="trr-kpi">
            <div class="trr-kpi__label">Average score <span class="trr-kpi__icon trr-kpi__icon--info"><i class="fas fa-bullseye" aria-hidden="true"></i></span></div>
            <div class="trr-kpi__value"><?= $trr_k['avg_score'] === null ? '—' : (int) $trr_k['avg_score'] . '%' ?></div>
            <div class="trr-kpi__sub"><?= $trr_k['pass_mark'] !== null ? 'Pass mark ' . (int) $trr_k['pass_mark'] . '%' : 'No scored records in the period' ?></div>
        </div>
    </section>

    <div class="trr-grid trr-grid--8-4">
        <!-- Compliance trend (S7) -->
        <section class="card trr-card" aria-labelledby="trr-trend-title">
            <div class="card-body">
                <div class="trr-card__head">
                    <div>
                        <h2 class="trr-card__title" id="trr-trend-title">Compliance trend</h2>
                        <p class="trr-card__sub">Share of required training that is current, at month end<?php if (($trr_trend['months'][0] ?? null) !== null) { ?> · <?= trr_h((new DateTimeImmutable($trr_trend['months'][0] . '-01'))->format('M Y')) ?> to <?= trr_h((new DateTimeImmutable(end($trr_trend['months']) . '-01'))->format('M Y')) ?><?php } ?></p>
                    </div>
                    <div class="trr-legend-inline" aria-hidden="true"><span class="trr-legend-inline__line"></span>Compliance <span class="trr-legend-inline__dash"></span>Target</div>
                </div>
                <?php if ($trr_trend['points'] >= 2) { ?>
                <div class="trr-trend"><canvas id="trr-trend-chart" role="img" aria-label="Monthly compliance, <?= (int) $trr_trend['points'] ?> months, target <?= (int) $trr_trend['target'] ?>%"></canvas></div>
                <?php } else { ?>
                <div class="trr-placeholder"><i class="fas fa-chart-line" aria-hidden="true"></i><p>The trend starts after the first nightly snapshot.</p></div>
                <?php } ?>
                <dl class="trr-stats4">
                    <div><dt>Gap to target</dt><dd><?= $trr_trend['gap_pts'] === null ? '—' : (int) $trr_trend['gap_pts'] . ' pts' ?></dd></div>
                    <div><dt>Change in <?= (int) $trr_months ?> months</dt><dd class="<?= ($trr_trend['change_12m'] ?? 0) > 0 ? 'trr-good' : (($trr_trend['change_12m'] ?? 0) < 0 ? 'trr-bad' : '') ?>"><?= $trr_trend['change_12m'] === null ? '—' : (($trr_trend['change_12m'] > 0 ? '+' : '') . (int) $trr_trend['change_12m'] . ' pts') ?></dd></div>
                    <div><dt>Strongest department</dt><dd><?= $trr_trend['strongest'] === null ? '—' : trr_h($trr_trend['strongest']['name']) . ' · ' . (int) $trr_trend['strongest']['pct'] . '%' ?></dd></div>
                    <div><dt>Needs the most help</dt><dd><?= $trr_trend['weakest'] === null ? '—' : trr_h($trr_trend['weakest']['name']) . ' · ' . (int) $trr_trend['weakest']['pct'] . '%' ?></dd></div>
                </dl>
            </div>
        </section>

        <!-- Overdue by age -->
        <section class="card trr-card" aria-labelledby="trr-age-title">
            <div class="card-body d-flex flex-column">
                <h2 class="trr-card__title" id="trr-age-title">Overdue by age</h2>
                <p class="trr-card__sub"><?= (int) $trr_k['overdue'] ?> overdue <?= $trr_k['overdue'] === 1 ? 'assignment' : 'assignments' ?>, by days past due</p>
                <?php $trr_max = max(1, ...array_map(static fn($a) => (int) $a['count'], $trr['ageing'])); ?>
                <ul class="trr-bars">
                    <?php foreach ($trr['ageing'] as $trr_i => $trr_a) { ?>
                    <li class="trr-bars__row">
                        <span class="trr-bars__label"><?= trr_h(str_replace('-', '–', $trr_a['bucket'])) ?> days</span>
                        <span class="trr-bars__track"><span class="trr-bars__fill trr-bars__fill--<?= $trr_i + 1 ?><?= (int) $trr_a['count'] === 0 ? ' is-empty' : '' ?>" style="width: <?= round(100 * (int) $trr_a['count'] / $trr_max, 1) ?>%"></span></span>
                        <span class="trr-bars__count"><?= (int) $trr_a['count'] ?></span>
                    </li>
                    <?php } ?>
                </ul>
                <?php if ($trr['longest'] !== []) { ?>
                <h3 class="trr-eyebrow">Longest overdue</h3>
                <ul class="trr-longest">
                    <?php foreach ($trr['longest'] as $trr_l) { ?>
                    <li>
                        <a href="/agent/training_transcript.php?contact_id=<?= (int) $trr_l['person']['contact_id'] ?>"><strong><?= trr_h($trr_l['person']['name']) ?></strong></a>
                        <span class="trr-muted trr-longest__course" title="<?= trr_h((($trr_l['course']['code'] ?? '') !== '' ? $trr_l['course']['code'] . ' · ' : '') . $trr_l['course']['name']) ?>">· <?= trr_h(trr_course_short_name((string) $trr_l['course']['name'])) ?></span>
                        <span class="trr-chip trr-chip--err ms-auto"><?= (int) $trr_l['days'] ?> days late</span>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
                <a class="trr-card__link mt-auto" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'overdue', 'client_id' => $trr_client, 'course_id' => $trr_course])) ?>">Open overdue report <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </section>
    </div>

    <!-- Department × course -->
    <?php $trr_m = $trr['matrix']; ?>
    <section class="card trr-card" aria-labelledby="trr-heat-title">
        <div class="card-body">
            <div class="trr-card__head">
                <div>
                    <h2 class="trr-card__title" id="trr-heat-title">Department × course</h2>
                    <p class="trr-card__sub">Share of required people who are current. Select a cell to see who is missing.</p>
                </div>
                <a class="trr-card__link" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'matrix', 'client_id' => $trr_client, 'course_id' => $trr_course])) ?>">Open full matrix <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php $trr_hm_skip_empty = true; require __DIR__ . '/includes/training_records/heatmap.php'; ?>
        </div>
    </section>

    <div class="trr-grid trr-grid--6-6">
        <!-- Expiring soon -->
        <section class="card trr-card" aria-labelledby="trr-exp-title" id="trr-expiring" data-total30="<?= (int) $trr['expiring']['d30'] ?>" data-total60="<?= (int) $trr['expiring']['d60'] ?>" data-total90="<?= (int) $trr['expiring']['d90'] ?>">
            <div class="card-body d-flex flex-column">
                <div class="trr-card__head">
                    <h2 class="trr-card__title" id="trr-exp-title">Expiring soon</h2>
                    <div class="trr-seg" role="group" aria-label="Window">
                        <?php foreach ([30, 60, 90] as $trr_w) { ?>
                        <button type="button" class="trr-seg__btn" data-trr-window="<?= $trr_w ?>" aria-pressed="<?= $trr_w === 30 ? 'true' : 'false' ?>"><?= $trr_w ?> days <span class="trr-seg__n"><?= (int) $trr['expiring']['d' . $trr_w] ?></span></button>
                        <?php } ?>
                    </div>
                </div>
                <?php if ($trr['expiring']['top'] === []) { ?>
                <p class="trr-empty-line">Nothing expires in the next 90 days.</p>
                <?php } else { ?>
                <ul class="trr-rows" id="trr-exp-rows">
                    <?php foreach ($trr['expiring']['top'] as $trr_r) { ?>
                    <li class="trr-rows__item" data-days="<?= (int) $trr_r['days_left'] ?>"<?= $trr_r['days_left'] > 30 ? ' hidden' : '' ?>>
                        <?= trr_avatar($trr_r['person']['initials']) ?>
                        <div class="trr-rows__main">
                            <div><a href="/agent/training_transcript.php?contact_id=<?= (int) $trr_r['person']['contact_id'] ?>"><strong><?= trr_h($trr_r['person']['name']) ?></strong></a> <span class="trr-muted">· <?= trr_h($trr_r['person']['department']['name']) ?></span></div>
                            <div class="trr-muted"><?= trr_h($trr_r['course']['name']) ?></div>
                        </div>
                        <span class="trr-mono"><?= trr_h(trr_date($trr_r['expires_on'], true)) ?></span>
                        <span class="trr-chip trr-chip--warn"><i class="far fa-clock" aria-hidden="true"></i><?= (int) $trr_r['days_left'] ?> <?= $trr_r['days_left'] === 1 ? 'day' : 'days' ?></span>
                    </li>
                    <?php } ?>
                </ul>
                <p class="trr-empty-line" id="trr-exp-none"<?= $trr['expiring']['d30'] > 0 ? ' hidden' : '' ?>>Nothing expires in this window.</p>
                <?php } ?>
                <div class="trr-card__foot mt-auto">
                    <span class="trr-muted" id="trr-exp-showing">Showing <?= min(5, (int) $trr['expiring']['d30']) ?> of <?= (int) $trr['expiring']['d30'] ?></span>
                    <a class="trr-card__link" id="trr-exp-all" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'expiring', 'days' => 30, 'client_id' => $trr_client, 'course_id' => $trr_course])) ?>">View all <?= (int) $trr['expiring']['d30'] ?></a>
                </div>
            </div>
        </section>

        <!-- Recent completions -->
        <section class="card trr-card" aria-labelledby="trr-recent-title">
            <div class="card-body d-flex flex-column">
                <div class="trr-card__head">
                    <h2 class="trr-card__title" id="trr-recent-title">Recent completions</h2>
                    <span class="trr-muted">Kiosk and office records</span>
                </div>
                <?php if ($trr['recent'] === []) { ?>
                <p class="trr-empty-line">No completions recorded yet.</p>
                <?php } else { ?>
                <ul class="trr-rows">
                    <?php foreach ($trr['recent'] as $trr_r) { ?>
                    <li class="trr-rows__item">
                        <?= trr_avatar($trr_r['person']['initials']) ?>
                        <div class="trr-rows__main">
                            <div><a href="/agent/training_transcript.php?contact_id=<?= (int) $trr_r['person']['contact_id'] ?>"><strong><?= trr_h($trr_r['person']['name']) ?></strong></a></div>
                            <div class="trr-muted"><?= trr_h($trr_r['course']['name']) ?><?= $trr_r['kind'] === 'document' ? ' · document' : '' ?></div>
                        </div>
                        <span class="trr-num"><?= $trr_r['score_pct'] !== null ? trr_h($trr_r['score_pct']) . '%' : '—' ?></span>
                        <?php if ($trr_r['kind'] === 'document') { ?>
                        <span class="trr-chip trr-chip--ok"><i class="fas fa-signature" aria-hidden="true"></i>Signed</span>
                        <?php } elseif ($trr_r['passed']) { ?>
                        <span class="trr-chip trr-chip--ok"><i class="fas fa-check" aria-hidden="true"></i>Passed</span>
                        <?php } else { ?>
                        <span class="trr-chip trr-chip--neutral">Recorded</span>
                        <?php } ?>
                        <span class="trr-muted trr-rows__when"><?= trr_h(trr_rel_time($trr_r['when'])) ?></span>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
                <div class="trr-card__foot mt-auto">
                    <span class="trr-muted"><?= (int) $trr_k['completions_month'] ?> <?= $trr_k['completions_month'] === 1 ? 'completion' : 'completions' ?> this month</span>
                    <a class="trr-card__link" href="<?= trr_h(trr_url('/agent/training_records.php', ['client_id' => $trr_client, 'course_id' => $trr_course])) ?>">Open records log</a>
                </div>
            </div>
        </section>
    </div>
    <?php } ?>
</div>

<?php require __DIR__ . '/includes/training_records/cell_offcanvas.php'; ?>

<?php
trr_json_block('tr-page-data', [
    'user_id' => $trr_ctx->userId,
    'level' => $trr_level,
    'trend' => $trr !== null ? $trr['trend'] : null,
    'filters' => $trr_filters,
]);
?>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_reports.js?v=<?= filemtime(__DIR__ . '/js/training_reports.js') ?>" defer></script>
<script src="/agent/js/training_dashboard.js?v=<?= filemtime(__DIR__ . '/js/training_dashboard.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
