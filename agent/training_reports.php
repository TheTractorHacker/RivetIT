<?php

/*
 * Training › Reports (Phase 2 spec §5.1, S8; mockup Admin-CourseAnalytics for the course tab).
 *
 * GET /agent/training_reports.php?tab=matrix|overdue|expiring|course|documents + filters
 *
 * Level 1; every number is computed over the caller's fail-closed training scope (a department
 * outside it is ignored as a filter, never an error page). Server-rendered from the Reports
 * services - the same ones behind report_matrix / report_overdue / report_expiring /
 * report_course / report_doc_acks - with a CSV of each through report_csv.
 * agent/js/training_reports.js: filter autosubmit, the "who is missing" drill-down and the
 * score-distribution chart.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_reports.css'];   // BEFORE inc_all
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock before the reports compute
require_once __DIR__ . '/includes/training_records/report_ui.php';

use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\CourseAnalytics;
use ITFlow\Training\Reports\DocAckReport;
use ITFlow\Training\Reports\ExpiringReport;
use ITFlow\Training\Reports\Labels;
use ITFlow\Training\Reports\Lookup;
use ITFlow\Training\Reports\MatrixService;
use ITFlow\Training\Reports\OverdueReport;
use ITFlow\Training\Reports\PairSource;

$trr_ctx = Access::ctx($mysqli);
$trr_scope = Scope::forCtx($trr_ctx);
$trr_settings = RecordsSettings::fromDb($mysqli);
$trr_today = Clock::todayLocal();

$trr_tabs = [
    'matrix' => ['Compliance matrix', 'fas fa-th'],
    'overdue' => ['Overdue', 'fas fa-exclamation-circle'],
    'expiring' => ['Expiring', 'far fa-clock'],
    'course' => ['Course analytics', 'fas fa-chart-bar'],
    'documents' => ['Document acknowledgments', 'fas fa-signature'],
];
$trr_tab = isset($_GET['tab']) && is_string($_GET['tab']) && isset($trr_tabs[$_GET['tab']]) ? $_GET['tab'] : 'matrix';

// ---- shared GET filters (validated against what the caller may see) ------------------------------
$trr_int = static fn(string $k): ?int => isset($_GET[$k]) && is_string($_GET[$k]) && ctype_digit($_GET[$k]) ? (int) $_GET[$k] : null;
$trr_depts = Lookup::departments($mysqli, $trr_scope);
$trr_courses = Lookup::publishedCourses($mysqli);
$trr_client = $trr_int('client_id');
if ($trr_client !== null && !$trr_scope->allows($trr_client)) {
    $trr_client = null;
}
$trr_course = $trr_int('course_id');
if ($trr_course !== null && !in_array($trr_course, array_column($trr_courses, 'id'), true)) {
    $trr_course = null;
}
$trr_job = $trr_int('job_id');
$trr_location = $trr_int('location_id');
$trr_days = in_array($trr_int('days'), ExpiringReport::WINDOWS, true) ? (int) $trr_int('days') : 30;
$trr_months = in_array($trr_int('months'), [3, 6, 12], true) ? (int) $trr_int('months') : 12;

$trr_dept_opts = [[null, 'All']];
foreach ($trr_depts as $trr_d) {
    $trr_dept_opts[] = [$trr_d['id'], $trr_d['name']];
}
$trr_course_opts = static function (array $courses, bool $withAll = true): array {
    $opts = $withAll ? [[null, 'All']] : [];
    foreach ($courses as $c) {
        $opts[] = [$c['id'], $c['name'] . ($c['kind'] === 'document' ? ' (document)' : '')];
    }
    return $opts;
};

$trr_error = null;
$trr_data = null;
$trr_page_json = ['tab' => $trr_tab];
$trr_csv = null;
$trr_csv_base = ['action' => 'report_csv', 'client_id' => $trr_client, 'course_id' => $trr_course];

try {
    switch ($trr_tab) {
        case 'matrix':
            $trr_f = ['client_id' => $trr_client, 'course_id' => $trr_course, 'job_id' => $trr_job, 'location_id' => $trr_location];
            $trr_ms = new MatrixService($trr_ctx, $trr_scope, $trr_settings);
            $trr_data = $trr_ms->matrix($trr_f);
            // Job / location options from the in-scope roster (Odoo attributes).
            $trr_jobs = [];
            $trr_locs = [];
            foreach ((new PairSource($trr_ctx, $trr_scope, $trr_settings))->people(['client_id' => $trr_client]) as $trr_pp) {
                if ($trr_pp['job_id'] !== null) {
                    $trr_jobs[$trr_pp['job_id']] = $trr_pp['job_name'] ?? ('Job #' . $trr_pp['job_id']);
                }
                if ($trr_pp['location_id'] !== null) {
                    $trr_locs[$trr_pp['location_id']] = $trr_pp['location_name'] ?? ('Location #' . $trr_pp['location_id']);
                }
            }
            asort($trr_jobs, SORT_NATURAL | SORT_FLAG_CASE);
            asort($trr_locs, SORT_NATURAL | SORT_FLAG_CASE);
            $trr_csv = $trr_csv_base + ['report' => 'matrix', 'job_id' => $trr_job, 'location_id' => $trr_location];
            break;
        case 'overdue':
            $trr_data = (new OverdueReport($trr_ctx, $trr_scope, $trr_settings))->rows(['client_id' => $trr_client, 'course_id' => $trr_course]);
            $trr_csv = $trr_csv_base + ['report' => 'overdue'];
            break;
        case 'expiring':
            $trr_data = (new ExpiringReport($trr_ctx, $trr_scope))->rows($trr_days, ['client_id' => $trr_client, 'course_id' => $trr_course]);
            $trr_csv = $trr_csv_base + ['report' => 'expiring', 'days' => $trr_days];
            break;
        case 'course':
            $trr_train_courses = array_values(array_filter($trr_courses, static fn($c) => $c['kind'] === 'training'));
            if ($trr_course === null || !in_array($trr_course, array_column($trr_train_courses, 'id'), true)) {
                $trr_course = $trr_train_courses[0]['id'] ?? null;
            }
            if ($trr_course !== null) {
                $trr_to = $trr_today;
                $trr_from = Clock::addDays(Clock::addMonths($trr_to, -$trr_months), 1);
                $trr_data = (new CourseAnalytics($trr_ctx, $trr_settings))->build($trr_course, $trr_from, $trr_to, $trr_scope);
                $trr_page_json['scores'] = $trr_data['scores'];
                $trr_csv = ['action' => 'report_csv', 'report' => 'course', 'course_id' => $trr_course, 'from' => $trr_from, 'to' => $trr_to];
            }
            break;
        case 'documents':
            $trr_data = (new DocAckReport($trr_ctx, $trr_settings))->build($trr_course, ['client_id' => $trr_client], $trr_scope);
            $trr_csv = $trr_csv_base + ['report' => 'doc_acks'];
            break;
    }
} catch (\Throwable $e) {
    error_log('Training reports (' . $trr_tab . '): ' . get_class($e) . ': ' . $e->getMessage());
    $trr_error = 'This report could not be calculated. Try again in a moment.';
    $trr_data = null;
}

$trr_title = $trr_tab === 'course' && is_array($trr_data) ? 'Course analytics · ' . $trr_data['course']['name'] : 'Training reports';
$trr_actions = ($trr_csv !== null
        ? '<a class="btn btn-outline-secondary" href="' . trr_h(trr_url('/agent/training_ajax.php', $trr_csv)) . '" download><i class="fas fa-download me-2" aria-hidden="true"></i>Export CSV</a>' : '')
    . '<button type="button" class="btn btn-outline-secondary js-print-page"><i class="fas fa-print me-2" aria-hidden="true"></i>Print</button>';
$trr_keep = ['client_id' => $trr_client, 'course_id' => $trr_course];

/** Chip for days until expiry. */
$trr_days_chip = static function (int $d): string {
    $cls = $d <= 14 ? 'err' : ($d <= 30 ? 'warn' : 'info');
    return '<span class="trr-chip trr-chip--' . $cls . '"><i class="far fa-clock" aria-hidden="true"></i>' . ($d <= 0 ? 'today' : $d . ' ' . ($d === 1 ? 'day' : 'days')) . '</span>';
};
$trr_delta = static function (?int $d, string $unit, string $versus, bool $lowerIsBetter = false): string {
    if ($d === null) {
        return '<span class="trr-muted">No earlier period to compare</span>';
    }
    $good = $lowerIsBetter ? $d < 0 : $d > 0;
    $cls = $d === 0 ? 'flat' : ($good ? 'up' : 'down');
    $icon = $d === 0 ? 'fas fa-minus' : ($d > 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down');
    return '<span class="trr-delta trr-delta--' . $cls . '"><i class="' . $icon . '" aria-hidden="true"></i>' . ($d > 0 ? '+' : '') . $d . ' ' . trr_h($unit) . '</span> <span class="trr-muted">' . trr_h($versus) . '</span>';
};
?>

<div class="trr-page trr-reports" id="trr-reports">
    <?php render_page_header($trr_title, null, $trr_actions, [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Reports', 'url' => '/agent/training_reports.php'], ['label' => $trr_tabs[$trr_tab][0]]]); ?>

    <ul class="nav nav-tabs trr-tabs" role="list">
        <?php foreach ($trr_tabs as $trr_k => [$trr_label, $trr_icon]) { ?>
        <li class="nav-item">
            <a class="nav-link<?= $trr_k === $trr_tab ? ' active' : '' ?>"<?= $trr_k === $trr_tab ? ' aria-current="page"' : '' ?> href="<?= trr_h(trr_url('training_reports.php', ['tab' => $trr_k] + ($trr_k === 'course' || $trr_k === 'documents' ? [] : $trr_keep))) ?>">
                <i class="<?= $trr_icon ?> me-2" aria-hidden="true"></i><?= trr_h($trr_label) ?>
            </a>
        </li>
        <?php } ?>
    </ul>

    <form class="trr-filterbar" method="get" action="training_reports.php" role="search" aria-label="Filter the report">
        <input type="hidden" name="tab" value="<?= trr_h($trr_tab) ?>">
        <?php
        if ($trr_tab === 'course') {
            echo trr_filter_select('course_id', 'Course', $trr_course_opts(array_values(array_filter($trr_courses, static fn($c) => $c['kind'] === 'training')), false), $trr_course, 'fas fa-graduation-cap');
            echo trr_filter_select('months', 'Period', [[3, 'Last 3 months'], [6, 'Last 6 months'], [12, 'Last 12 months']], $trr_months, 'far fa-calendar');
        } else {
            echo trr_filter_select('client_id', 'Department', $trr_dept_opts, $trr_client);
            $trr_list = $trr_tab === 'documents' ? array_values(array_filter($trr_courses, static fn($c) => $c['kind'] === 'document')) : $trr_courses;
            echo trr_filter_select('course_id', $trr_tab === 'documents' ? 'Document' : 'Course', $trr_course_opts($trr_list), $trr_course);
            if ($trr_tab === 'matrix') {
                if ($trr_jobs !== [] || $trr_job !== null) {
                    $trr_o = [[null, 'All']];
                    foreach ($trr_jobs as $trr_id => $trr_n) {
                        $trr_o[] = [$trr_id, $trr_n];
                    }
                    echo trr_filter_select('job_id', 'Job', $trr_o, $trr_job);
                }
                if ($trr_locs !== [] || $trr_location !== null) {
                    $trr_o = [[null, 'All']];
                    foreach ($trr_locs as $trr_id => $trr_n) {
                        $trr_o[] = [$trr_id, $trr_n];
                    }
                    echo trr_filter_select('location_id', 'Location', $trr_o, $trr_location);
                }
            }
        }
        if ($trr_tab === 'expiring') { ?>
        <input type="hidden" name="days" value="<?= (int) $trr_days ?>">
        <?php } ?>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Apply</button></noscript>
        <?php if ($trr_tab === 'course' && is_array($trr_data)) {
            $trr_cd = $trr_data['course'];
            $trr_bits = [Labels::shortDate($trr_data['period']['from']) . ' – ' . Labels::shortDate($trr_data['period']['to'])];
            if ($trr_cd['revision_number'] !== null) {
                $trr_bits[] = 'Version ' . $trr_cd['revision_number'];
            }
            if ($trr_cd['validity_label'] !== null) {
                $trr_bits[] = $trr_cd['validity_label'] . ' validity';
            }
            if ($trr_cd['pass_pct'] !== null) {
                $trr_bits[] = 'pass mark ' . $trr_cd['pass_pct'] . '%';
            }
            ?>
        <p class="trr-meta"><?= trr_h(implode(' · ', $trr_bits)) ?></p>
        <?php } elseif ($trr_tab === 'matrix' && is_array($trr_data)) { ?>
        <p class="trr-meta"><?= (int) $trr_data['people'] ?> <?= $trr_data['people'] === 1 ? 'person' : 'people' ?> in view · target <?= (int) $trr_data['target'] ?>%</p>
        <?php } ?>
    </form>

    <?php if ($trr_scope->isNone()) { trr_scope_banner(); } ?>
    <?php if ($trr_error !== null) { ?>
    <div class="tr-banner alert alert-danger" role="alert"><?= trr_h($trr_error) ?></div>
    <?php } ?>

<?php if ($trr_data !== null && $trr_tab === 'matrix') {
    $trr_m = $trr_data;
    $trr_hm_totals = true;
    $trr_hm_job = $trr_job;
    $trr_hm_location = $trr_location;
    ?>
    <section class="card trr-card" aria-labelledby="trr-mx-title">
        <div class="card-body">
            <div class="trr-card__head">
                <div>
                    <h2 class="trr-card__title" id="trr-mx-title">Department × course</h2>
                    <p class="trr-card__sub">Share of required people who are current, for every required course. Select a cell to see who is missing.</p>
                </div>
                <?php if ($trr_m['totals']['overall_pct'] !== null) { ?>
                <div class="trr-bignum"><span><?= (int) $trr_m['totals']['overall_pct'] ?>%</span> overall · <?= (int) $trr_m['totals']['current'] ?> of <?= (int) $trr_m['totals']['required'] ?> current</div>
                <?php } ?>
            </div>
            <?php require __DIR__ . '/includes/training_records/heatmap.php'; ?>
        </div>
    </section>

<?php } elseif ($trr_data !== null && $trr_tab === 'overdue') { ?>
    <section class="card trr-card" aria-labelledby="trr-od-title">
        <div class="card-body">
            <div class="trr-card__head">
                <div>
                    <h2 class="trr-card__title" id="trr-od-title">Overdue required training</h2>
                    <p class="trr-card__sub"><?= (int) $trr_data['total'] ?> overdue <?= $trr_data['total'] === 1 ? 'assignment' : 'assignments' ?> for <?= (int) $trr_data['people'] ?> <?= $trr_data['people'] === 1 ? 'person' : 'people' ?>, by department, most overdue first. Printing puts each department on its own page with a “Scheduled for” column.</p>
                </div>
                <ul class="trr-agechips" aria-label="By days past due">
                    <?php foreach ($trr_data['ageing'] as $trr_a) { ?>
                    <li><span class="trr-muted"><?= trr_h(str_replace('-', '–', $trr_a['bucket'])) ?> days</span> <strong><?= (int) $trr_a['count'] ?></strong></li>
                    <?php } ?>
                </ul>
            </div>
            <?php if ($trr_data['groups'] === [] && empty($trr_data['lapsed'])) { ?>
            <div class="tr-empty"><?php render_empty_state('fas fa-check-circle', 'Nothing is overdue', 'Everyone in view is on time with their required training.', ''); ?></div>
            <?php } elseif ($trr_data['groups'] === []) { ?>
            <p class="trr-muted mb-0">Nothing is overdue. Some people below are not qualified because their certificate already expired.</p>
            <?php } ?>
        </div>
        <?php foreach ($trr_data['groups'] as $trr_gi => $trr_g) { ?>
        <div class="trr-print-group<?= $trr_gi === 0 ? ' trr-print-group--first' : '' ?>">
            <h3 class="trr-group-title px-3"><?= trr_h($trr_g['name']) ?> <span class="trr-muted"><?= (int) $trr_g['count'] ?> overdue · <?= (int) $trr_g['people'] ?> <?= $trr_g['people'] === 1 ? 'person' : 'people' ?></span></h3>
            <div class="trr-table-wrap">
                <table class="table table-vcenter card-table trr-table trr-table--overdue">
                    <colgroup><col class="trr-col-person"><col class="trr-col-course"><col class="trr-col-why"><col class="trr-col-due"><col class="trr-col-late"><col class="trr-col-sched"></colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Person</th>
                            <th scope="col">Course</th>
                            <th scope="col">Why</th>
                            <th scope="col">Due</th>
                            <th scope="col">Days late</th>
                            <th scope="col" class="d-none d-print-table-cell">Scheduled for</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trr_g['rows'] as $trr_r) { ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?= trr_avatar($trr_r['person']['initials']) ?>
                                    <div class="min-w-0">
                                        <a class="fw-semibold trr-link-ink" href="/agent/training_transcript.php?contact_id=<?= (int) $trr_r['person']['contact_id'] ?>"><?= trr_h($trr_r['person']['name']) ?></a>
                                        <?php if ($trr_r['person']['title'] !== null) { ?><span class="trr-sub"><?= trr_h($trr_r['person']['title']) ?></span><?php } ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= trr_h($trr_r['course']['name']) ?><?php if ($trr_r['label'] !== '' && $trr_r['label'] !== 'Overdue') { ?><span class="trr-sub trr-bad"><?= trr_h($trr_r['label']) ?></span><?php } ?></td>
                            <td><?= trr_h($trr_r['reason_label']) ?></td>
                            <td class="text-nowrap"<?= $trr_r['original_due_on'] !== null && $trr_r['original_due_on'] !== $trr_r['due_on'] ? ' title="Originally due ' . trr_h(trr_date($trr_r['original_due_on'])) . '"' : '' ?>><?= trr_h(trr_date($trr_r['due_on'])) ?></td>
                            <td><span class="trr-chip trr-chip--err"><?= (int) $trr_r['days_overdue'] ?> <?= $trr_r['days_overdue'] === 1 ? 'day' : 'days' ?></span></td>
                            <td class="d-none d-print-table-cell trr-blank"></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
        <?php if (!empty($trr_data['lapsed'])) { ?>
        <div class="trr-print-group">
            <h3 class="trr-group-title px-3">Expired — not qualified <span class="trr-muted">renewal open, not due yet · <?= (int) $trr_data['lapsed_total'] ?> for <?= (int) $trr_data['lapsed_people'] ?> <?= $trr_data['lapsed_people'] === 1 ? 'person' : 'people' ?></span></h3>
            <p class="trr-card__sub px-3">Their certificate has already run out, so they are not qualified now. The renewal is not overdue yet because it was opened after the expiry date.</p>
            <div class="trr-table-wrap">
                <table class="table table-vcenter card-table trr-table trr-table--overdue">
                    <thead>
                        <tr>
                            <th scope="col">Person</th>
                            <th scope="col">Department</th>
                            <th scope="col">Course</th>
                            <th scope="col">Expired</th>
                            <th scope="col">Renewal due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trr_data['lapsed'] as $trr_r) { ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?= trr_avatar($trr_r['person']['initials']) ?>
                                    <div class="min-w-0">
                                        <a class="fw-semibold trr-link-ink" href="/agent/training_transcript.php?contact_id=<?= (int) $trr_r['person']['contact_id'] ?>"><?= trr_h($trr_r['person']['name']) ?></a>
                                        <?php if ($trr_r['person']['title'] !== null) { ?><span class="trr-sub"><?= trr_h($trr_r['person']['title']) ?></span><?php } ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= $trr_r['department'] !== null ? trr_h($trr_r['department']) : '<span class="trr-muted">No department</span>' ?></td>
                            <td><?= trr_h($trr_r['course']['name']) ?><span class="trr-sub"><?= trr_h($trr_r['reason_label']) ?></span></td>
                            <td class="text-nowrap"><span class="trr-chip trr-chip--err"><?= trr_h(trr_date($trr_r['expires_on'])) ?></span></td>
                            <td class="text-nowrap"><?= trr_h(trr_date($trr_r['due_on'])) ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    </section>

<?php } elseif ($trr_data !== null && $trr_tab === 'expiring') { ?>
    <section class="card trr-card" aria-labelledby="trr-ex-title">
        <div class="card-body">
            <div class="trr-card__head">
                <div>
                    <h2 class="trr-card__title" id="trr-ex-title">Qualifications expiring in <?= (int) $trr_days ?> days</h2>
                    <p class="trr-card__sub">The latest record of each person and course. A renewal is assigned automatically when the course's renewal window opens.</p>
                </div>
                <nav class="trr-seg" aria-label="Window">
                    <?php foreach (ExpiringReport::WINDOWS as $trr_w) { ?>
                    <a class="trr-seg__btn<?= $trr_w === $trr_days ? ' is-active' : '' ?>"<?= $trr_w === $trr_days ? ' aria-current="true"' : '' ?> href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'expiring', 'days' => $trr_w] + $trr_keep)) ?>"><?= $trr_w ?> days <span class="trr-seg__n"><?= (int) $trr_data['counts']['d' . $trr_w] ?></span></a>
                    <?php } ?>
                </nav>
            </div>
            <?php if ($trr_data['rows'] === []) { ?>
            <div class="tr-empty"><?php render_empty_state('far fa-calendar-check', 'Nothing expires in this window', 'Records that expire in the next ' . $trr_days . ' days appear here.', ''); ?></div>
            <?php } ?>
        </div>
        <?php if ($trr_data['rows'] !== []) { ?>
        <div class="trr-table-wrap">
            <table class="table table-vcenter card-table trr-table">
                <thead>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Department</th>
                        <th scope="col">Course</th>
                        <th scope="col">Record #</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Left</th>
                        <th scope="col">Renewal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trr_data['rows'] as $trr_r) { ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?= trr_avatar($trr_r['person']['initials']) ?>
                                <div class="min-w-0">
                                    <a class="fw-semibold trr-link-ink" href="/agent/training_transcript.php?contact_id=<?= (int) $trr_r['person']['contact_id'] ?>"><?= trr_h($trr_r['person']['name']) ?></a>
                                    <?php if ($trr_r['person']['title'] !== null) { ?><span class="trr-sub"><?= trr_h($trr_r['person']['title']) ?></span><?php } ?>
                                </div>
                            </div>
                        </td>
                        <td><?= trr_h($trr_r['person']['department']['name']) ?></td>
                        <td><?= trr_h($trr_r['course']['name']) ?></td>
                        <td><a class="trr-mono" href="/agent/training_certificate.php?id=<?= (int) $trr_r['completion_id'] ?>" target="_blank" rel="noopener"><?= trr_h($trr_r['cert_number'] ?? ('#' . $trr_r['completion_id'])) ?></a></td>
                        <td class="text-nowrap trr-mono"><?= trr_h(trr_date($trr_r['expires_on'], true)) ?></td>
                        <td><?= $trr_days_chip((int) $trr_r['days_left']) ?></td>
                        <td><?= $trr_r['renewal'] !== null
                            ? '<span class="trr-chip trr-chip--ok"><i class="fas fa-check" aria-hidden="true"></i>Assigned · due ' . trr_h(trr_date($trr_r['renewal']['due_on'], true)) . '</span>'
                            : '<span class="trr-chip trr-chip--neutral">Not yet assigned</span>' ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </section>

<?php } elseif ($trr_tab === 'course' && $trr_data === null && $trr_error === null) { ?>
    <div class="card"><div class="card-body">
        <?php render_empty_state('fas fa-chart-bar', 'No published courses yet', 'Course analytics appear once a training course is published.', ''); ?>
    </div></div>

<?php } elseif ($trr_data !== null && $trr_tab === 'course') {
    $trr_k = $trr_data['kpis'];
    $trr_sc = $trr_data['scores'];
    $trr_prev = 'vs prior ' . $trr_months . ' months';
    $trr_assigned = (int) $trr_data['funnel'][0]['count'];
    ?>
    <div class="trr-grid trr-grid--7-5">
        <!-- Funnel -->
        <section class="card trr-card" aria-labelledby="trr-fn-title">
            <div class="card-body">
                <div class="trr-card__head">
                    <div>
                        <h2 class="trr-card__title" id="trr-fn-title">Completion funnel</h2>
                        <p class="trr-card__sub">People assigned this course in the period, and how far they got</p>
                    </div>
                    <span class="trr-muted small">% of assigned</span>
                </div>
                <?php if ($trr_assigned === 0) { ?>
                <p class="trr-empty-line">Nobody was assigned this course in the period.</p>
                <?php } else { ?>
                <div class="trr-funnel">
                    <?php
                    $trr_drops = [];
                    foreach ($trr_data['drops'] as $trr_dr) {
                        $trr_drops[$trr_dr['to']] = $trr_dr;
                    }
                    $trr_drop_text = ['completed' => ['completed', 'have not finished'], 'passed' => ['passed', 'below the pass mark'], 'started' => ['started', 'never opened it']];
                    foreach ($trr_data['funnel'] as $trr_i => $trr_st) {
                        if ($trr_i > 0 && isset($trr_drops[$trr_st['key']]) && $trr_drops[$trr_st['key']]['kept_pct'] !== null) {
                            $trr_dr = $trr_drops[$trr_st['key']];
                            [$trr_w1, $trr_w2] = $trr_drop_text[$trr_st['key']];
                            ?>
                    <p class="trr-funnel__drop"><i class="fas fa-arrow-down" aria-hidden="true"></i><strong><?= $trr_dr['kept_pct'] === null ? '—' : (int) $trr_dr['kept_pct'] . '%' ?></strong> <?= trr_h($trr_w1) ?> · <?= (int) $trr_dr['lost'] ?> <?= trr_h($trr_w2) ?></p>
                        <?php } elseif ($trr_i > 0 && $trr_st['count'] === null) { ?>
                    <p class="trr-funnel__drop"><i class="fas fa-info-circle" aria-hidden="true"></i>“Started” appears once employees take courses on the kiosk.</p>
                        <?php } ?>
                    <div class="trr-funnel__row">
                        <span class="trr-funnel__label"><?= trr_h($trr_st['label']) ?></span>
                        <span class="trr-funnel__track"><span class="trr-funnel__fill trr-funnel__fill--<?= $trr_i + 1 ?>" style="width: <?= $trr_st['pct'] === null ? 0 : (int) $trr_st['pct'] ?>%"></span></span>
                        <span class="trr-funnel__count"><?= $trr_st['count'] === null ? '—' : (int) $trr_st['count'] ?></span>
                        <span class="trr-funnel__pct"><?= $trr_st['pct'] === null ? '' : (int) $trr_st['pct'] . '%' ?></span>
                    </div>
                    <?php } ?>
                </div>
                <?php
                $trr_big = null;
                foreach ($trr_data['drops'] as $trr_dr) {
                    if ($trr_big === null || $trr_dr['lost'] > $trr_big['lost']) {
                        $trr_big = $trr_dr;
                    }
                }
                if ($trr_big !== null && $trr_big['lost'] > 0) { ?>
                <div class="trr-note">
                    <i class="fas fa-info-circle trr-muted" aria-hidden="true"></i>
                    <span class="flex-grow-1"><?= $trr_big['to'] === 'passed'
                        ? 'Biggest drop is at the pass mark. ' . (int) $trr_big['lost'] . ' ' . ($trr_big['lost'] === 1 ? 'person' : 'people') . ' finished below it.'
                        : 'Biggest drop is before completion. ' . (int) $trr_big['lost'] . ' ' . ($trr_big['lost'] === 1 ? 'person has' : 'people have') . ' not finished the course.' ?></span>
                    <a class="trr-card__link" href="<?= trr_h(trr_url('training_reports.php', ['tab' => 'overdue', 'course_id' => $trr_course])) ?>">See who</a>
                </div>
                <?php } ?>
                <?php } ?>
            </div>
        </section>

        <!-- KPIs -->
        <div class="trr-kpi-grid">
            <div class="trr-kpi">
                <div class="trr-kpi__label">Pass rate <span class="trr-kpi__icon trr-kpi__icon--ok"><i class="far fa-check-circle" aria-hidden="true"></i></span></div>
                <div class="trr-kpi__value"><?= $trr_k['pass_rate'] === null ? '—' : (int) $trr_k['pass_rate'] . '%' ?></div>
                <div class="trr-kpi__sub"><?= $trr_k['scored'] > 0 ? (int) $trr_k['passed'] . ' of ' . (int) $trr_k['scored'] . ' scored records' : 'No scored records in the period' ?></div>
                <div class="trr-kpi__foot"><?= $trr_delta($trr_k['pass_rate_delta'], 'pts', $trr_prev) ?></div>
            </div>
            <div class="trr-kpi">
                <div class="trr-kpi__label">Avg score <span class="trr-kpi__icon trr-kpi__icon--info"><i class="fas fa-bullseye" aria-hidden="true"></i></span></div>
                <div class="trr-kpi__value"><?= $trr_k['avg_score'] === null ? '—' : (int) $trr_k['avg_score'] . '%' ?></div>
                <div class="trr-kpi__sub"><?= $trr_data['attempts_available'] ? 'Best attempt per person' : 'Score on the training record' ?></div>
                <div class="trr-kpi__foot"><?= $trr_delta($trr_k['avg_score_delta'], 'pt' . (abs((int) $trr_k['avg_score_delta']) === 1 ? '' : 's'), $trr_prev) ?></div>
            </div>
            <div class="trr-kpi">
                <div class="trr-kpi__label">First-try pass <span class="trr-kpi__icon trr-kpi__icon--warn"><i class="far fa-flag" aria-hidden="true"></i></span></div>
                <div class="trr-kpi__value"><?= $trr_k['first_try'] === null ? '—' : (int) $trr_k['first_try'] . '%' ?></div>
                <div class="trr-kpi__sub"><?= $trr_k['with_attempts'] > 0 ? (int) $trr_k['first_try_count'] . ' of ' . (int) $trr_k['with_attempts'] . ' on attempt 1' : 'Attempt counts arrive with kiosk results' ?></div>
                <div class="trr-kpi__foot"><?= $trr_delta($trr_k['first_try_delta'], 'pts', $trr_prev) ?></div>
            </div>
            <div class="trr-kpi">
                <div class="trr-kpi__label">Median time <span class="trr-kpi__icon trr-kpi__icon--neutral"><i class="far fa-clock" aria-hidden="true"></i></span></div>
                <div class="trr-kpi__value"><?= $trr_k['median_minutes'] === null ? '—' : (int) $trr_k['median_minutes'] . ' <small class="trr-kpi__unit">min</small>' ?></div>
                <div class="trr-kpi__sub"><?= $trr_data['course']['est_minutes'] !== null ? 'Course estimate ' . (int) $trr_data['course']['est_minutes'] . ' min' : 'Time on the training record' ?></div>
                <div class="trr-kpi__foot"><?= $trr_delta($trr_k['median_minutes_delta'], 'min', $trr_prev, true) ?></div>
            </div>
        </div>
    </div>

    <div class="trr-grid trr-grid--6-6">
        <!-- Score distribution -->
        <section class="card trr-card" aria-labelledby="trr-sd-title">
            <div class="card-body">
                <div class="trr-card__head">
                    <div>
                        <h2 class="trr-card__title" id="trr-sd-title">Score distribution</h2>
                        <p class="trr-card__sub"><?= $trr_sc['basis'] === 'attempts'
                            ? 'All ' . (int) $trr_sc['total'] . ' quiz attempts by ' . (int) $trr_sc['people'] . ' people'
                            : (int) $trr_sc['total'] . ' scored ' . ((int) $trr_sc['total'] === 1 ? 'record' : 'records') . ' in the period (quiz attempts appear with the kiosk)' ?></p>
                    </div>
                    <?php if ($trr_sc['pass_mark'] !== null && $trr_sc['total'] > 0) { ?>
                    <div class="trr-legend-inline"><span class="trr-legend-inline__sw trr-legend-inline__sw--below"></span>Below pass mark · <?= (int) $trr_sc['below_pass'] ?> <span class="trr-legend-inline__sw ms-2"></span>Passed · <?= (int) $trr_sc['total'] - (int) $trr_sc['below_pass'] ?></div>
                    <?php } ?>
                </div>
                <?php if ((int) $trr_sc['total'] === 0) { ?>
                <div class="trr-placeholder"><i class="fas fa-chart-bar" aria-hidden="true"></i><p>No scores in this period yet.</p></div>
                <?php } else { ?>
                <div class="trr-scores"><canvas id="trr-score-chart" role="img" aria-label="Score distribution, <?= (int) $trr_sc['total'] ?> scores in ten-point buckets"></canvas></div>
                <?php } ?>
                <dl class="trr-stats4">
                    <div><dt>Median score</dt><dd><?= $trr_sc['median'] !== null ? trr_h($trr_sc['median']) . '%' : '—' ?></dd></div>
                    <div><dt>Below pass mark</dt><dd><?= $trr_sc['below_pass'] !== null ? (int) $trr_sc['below_pass'] . ' ' . ($trr_sc['basis'] === 'attempts' ? 'attempts' : 'records') : '—' ?></dd></div>
                    <div><dt>Retakes</dt><dd><?= (int) $trr_sc['retakes'] ?> <?= (int) $trr_sc['retakes'] === 1 ? 'person' : 'people' ?></dd></div>
                    <div><dt>Locked after max tries</dt><dd><?= $trr_sc['locked'] !== null ? (int) $trr_sc['locked'] . ' ' . ((int) $trr_sc['locked'] === 1 ? 'person' : 'people') : '—' ?></dd></div>
                </dl>
            </div>
        </section>

        <!-- By department -->
        <section class="card trr-card" aria-labelledby="trr-bd-title">
            <div class="card-body">
                <h2 class="trr-card__title" id="trr-bd-title">By department</h2>
                <p class="trr-card__sub">People who must hold this course</p>
            </div>
            <?php if ($trr_data['by_department'] === []) { ?>
            <div class="card-body pt-0"><p class="trr-empty-line">Nobody in view is required to take this course.</p></div>
            <?php } else { ?>
            <div class="trr-table-wrap">
                <table class="table table-vcenter card-table trr-table trr-bydept">
                    <thead><tr><th scope="col">Department</th><th scope="col">Current / required</th><th scope="col" class="text-end">Avg score</th></tr></thead>
                    <tbody>
                        <?php foreach ($trr_data['by_department'] as $trr_r) { ?>
                        <tr>
                            <th scope="row" class="fw-semibold"><?= trr_h($trr_r['name']) ?></th>
                            <td>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="trr-bar-inline" aria-hidden="true"><span style="width: <?= (int) ($trr_r['pct'] ?? 0) ?>%"></span></span>
                                    <span class="trr-num"><?= (int) $trr_r['current'] ?> / <?= (int) $trr_r['required'] ?></span>
                                    <?php if ($trr_r['behind']) { ?><span class="trr-chip trr-chip--warn"><i class="fas fa-exclamation-circle" aria-hidden="true"></i>Behind</span><?php } ?>
                                </div>
                            </td>
                            <td class="text-end trr-num"><?= $trr_r['avg_score'] !== null ? (int) $trr_r['avg_score'] . '%' : '<span class="trr-muted">—</span>' ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">All</th>
                            <td class="trr-num fw-bold"><?= (int) $trr_data['by_department_total']['current'] ?> / <?= (int) $trr_data['by_department_total']['required'] ?><?= $trr_data['by_department_total']['pct'] !== null ? ' <span class="trr-muted fw-normal">· ' . (int) $trr_data['by_department_total']['pct'] . '%</span>' : '' ?></td>
                            <td class="text-end trr-num fw-bold"><?= $trr_data['by_department_total']['avg_score'] !== null ? (int) $trr_data['by_department_total']['avg_score'] . '%' : '—' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php } ?>
        </section>
    </div>

    <!-- Hardest questions -->
    <section class="card trr-card" aria-labelledby="trr-hq-title">
        <div class="card-body">
            <h2 class="trr-card__title" id="trr-hq-title">Hardest questions</h2>
            <p class="trr-card__sub">Lowest share answered correctly across all attempts. A low score often means the wording or the lesson needs work.</p>
            <?php if ($trr_data['hardest'] === []) { ?>
            <div class="trr-placeholder trr-placeholder--sm mt-3"><i class="far fa-question-circle" aria-hidden="true"></i><p>Question statistics appear once employees take quizzes on the kiosk.</p></div>
            <?php } ?>
        </div>
        <?php if ($trr_data['hardest'] !== []) { ?>
        <div class="trr-table-wrap">
            <table class="table table-vcenter card-table trr-table">
                <thead><tr><th scope="col">Question</th><th scope="col">Answered correctly</th><th scope="col">Most-chosen wrong answer</th><th scope="col">Revision</th></tr></thead>
                <tbody>
                    <?php foreach ($trr_data['hardest'] as $trr_q) { ?>
                    <tr>
                        <td><span class="fw-semibold"><?= $trr_q['text'] !== null ? trr_h($trr_q['text']) : '<span class="trr-muted">Question ' . trr_h($trr_q['question_uid']) . '</span>' ?></span><span class="trr-sub trr-mono"><?= trr_h($trr_q['question_uid']) ?> · <?= (int) $trr_q['answered'] ?> answers</span></td>
                        <td><div class="d-flex align-items-center gap-2"><strong class="trr-num"><?= (int) $trr_q['correct_pct'] ?>%</strong><span class="trr-bar-inline" aria-hidden="true"><span style="width: <?= (int) $trr_q['correct_pct'] ?>%"></span></span></div></td>
                        <td><?= $trr_q['top_wrong_label'] !== null ? '“' . trr_h($trr_q['top_wrong_label']) . '” <span class="trr-muted small">' . (int) $trr_q['top_wrong_pct'] . '%</span>' : '<span class="trr-muted">—</span>' ?></td>
                        <td><span class="trr-chip trr-chip--neutral">Rev <?= (int) $trr_q['revision_min'] ?><?= (int) $trr_q['revision_max'] !== (int) $trr_q['revision_min'] ? ' → ' . (int) $trr_q['revision_max'] : '' ?></span></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="card-body pt-2"><p class="trr-muted small mb-0"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>Revision is the course version the answers came from. “Rev 2 → 3” means the results cover both wordings.</p></div>
        <?php } ?>
    </section>

<?php } elseif ($trr_data !== null && $trr_tab === 'documents') { ?>
    <?php if ($trr_data['courses'] === []) { ?>
    <div class="card"><div class="card-body">
        <?php render_empty_state('fas fa-signature', 'No published documents', 'Policies and handbooks that people sign appear here once they are published.', ''); ?>
    </div></div>
    <?php } ?>
    <?php foreach ($trr_data['courses'] as $trr_dc) {
        $trr_c = $trr_dc['course'];
        ?>
    <section class="card trr-card" aria-labelledby="trr-doc-<?= (int) $trr_c['id'] ?>">
        <div class="card-body">
            <div class="trr-card__head">
                <div>
                    <h2 class="trr-card__title" id="trr-doc-<?= (int) $trr_c['id'] ?>"><?= trr_h($trr_c['name']) ?><?php if ($trr_c['revision_number'] !== null) { ?> <span class="trr-chip trr-chip--outline ms-1">Version <?= (int) $trr_c['revision_number'] ?></span><?php } ?></h2>
                    <p class="trr-card__sub"><?= (int) $trr_dc['required'] ?> required to acknowledge<?= $trr_dc['waived'] > 0 ? ' · ' . (int) $trr_dc['waived'] . ' waived' : '' ?></p>
                </div>
                <div class="trr-bignum"><span><?= $trr_dc['pct'] === null ? '—' : (int) $trr_dc['pct'] . '%' ?></span> acknowledged</div>
            </div>
            <?php
            $trr_tot = max(1, (int) $trr_dc['required']);
            $trr_seg = [
                ['current_version', 'Current version', 'ok'],
                ['older_version', 'Older version, still counts', 'info'],
                ['not_acknowledged', 'Not acknowledged', 'err'],
            ];
            ?>
            <div class="trr-stack" role="img" aria-label="<?= (int) $trr_dc['current_version'] ?> current version, <?= (int) $trr_dc['older_version'] ?> older version, <?= (int) $trr_dc['not_acknowledged'] ?> not acknowledged">
                <?php foreach ($trr_seg as [$trr_key, $trr_lbl, $trr_cls]) {
                    if ((int) $trr_dc[$trr_key] > 0) { ?><span class="trr-stack__seg trr-stack__seg--<?= $trr_cls ?>" style="width: <?= round(100 * (int) $trr_dc[$trr_key] / $trr_tot, 2) ?>%"></span><?php }
                } ?>
            </div>
            <ul class="trr-stack-legend">
                <?php foreach ($trr_seg as [$trr_key, $trr_lbl, $trr_cls]) { ?>
                <li><i class="trr-stack-legend__sw trr-stack__seg--<?= $trr_cls ?>" aria-hidden="true"></i><?= trr_h($trr_lbl) ?> <strong><?= (int) $trr_dc[$trr_key] ?></strong></li>
                <?php } ?>
            </ul>
        </div>
        <?php if ($trr_dc['departments'] !== []) { ?>
        <div class="trr-table-wrap">
            <table class="table table-vcenter card-table trr-table">
                <thead><tr><th scope="col">Department</th><th scope="col" class="text-end">Required</th><th scope="col" class="text-end">Current version</th><th scope="col" class="text-end">Older version</th><th scope="col">Not acknowledged</th><th scope="col" class="text-end">Acknowledged</th></tr></thead>
                <tbody>
                    <?php foreach ($trr_dc['departments'] as $trr_r) { ?>
                    <tr>
                        <th scope="row" class="fw-semibold"><?= trr_h($trr_r['name']) ?></th>
                        <td class="text-end trr-num"><?= (int) $trr_r['required'] ?></td>
                        <td class="text-end trr-num"><?= (int) $trr_r['current_version'] ?></td>
                        <td class="text-end trr-num"><?= (int) $trr_r['older_version'] ?></td>
                        <td>
                            <?php if ((int) $trr_r['not_acknowledged'] > 0) { ?>
                            <button type="button" class="btn btn-sm btn-ghost-danger trr-link-btn" data-trr-cell data-client="<?= (int) $trr_r['client_id'] ?>" data-course="<?= (int) $trr_c['id'] ?>"
                                data-dept-name="<?= trr_h($trr_r['name']) ?>" data-course-name="<?= trr_h($trr_c['name']) ?>"><?= (int) $trr_r['not_acknowledged'] ?> <?= (int) $trr_r['not_acknowledged'] === 1 ? 'person' : 'people' ?> <i class="fas fa-chevron-right ms-1" aria-hidden="true"></i></button>
                            <?php } else { ?>
                            <span class="trr-muted">None</span>
                            <?php } ?>
                        </td>
                        <td class="text-end trr-num fw-semibold"><?= $trr_r['pct'] === null ? '—' : (int) $trr_r['pct'] . '%' ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </section>
    <?php } ?>
<?php } ?>
</div>

<?php require __DIR__ . '/includes/training_records/cell_offcanvas.php'; ?>

<?php trr_json_block('tr-page-data', $trr_page_json + ['user_id' => $trr_ctx->userId, 'level' => $trr_ctx->level]); ?>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_reports.js?v=<?= filemtime(__DIR__ . '/js/training_reports.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
