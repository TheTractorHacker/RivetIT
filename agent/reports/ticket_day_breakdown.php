<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// inc_all_reports.php loads includes/filter_header.php, which resolves the
// canned-date / custom-date filter into $dtf and $dtt.
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    // No filter submitted: default to this month, which is what the dropdown shows.
    $report_from = ($_GET['canned_date'] ?? 'custom') === 'custom' ? date('Y-m-01') : date('Y-m-d', strtotime('-29 days'));
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$report = getTicketDayBreakdownReport($mysqli, $report_from, $report_to);

// CSV export: the same per-day series that feeds the table and chart.
if (!empty($report_export_csv)) {
    $csv_header = ['Date', 'Created', 'Closed', 'Net'];
    $csv_rows = [];
    foreach ($report['days'] as $d) {
        $csv_rows[] = [$d['date'], $d['created'], $d['closed'], $d['net']];
    }
    report_send_csv('ticket_day_breakdown_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
}

$canned_options = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'thisweek'  => 'This week',
    'lastweek'  => 'Last week',
    'thismonth' => 'This month',
    'lastmonth' => 'Last month',
    'thisyear'  => 'This year',
    'lastyear'  => 'Last year',
    'alltime'   => 'All time',
    'custom'    => 'Custom range',
];
// filter_header.php forces canned_date to 'custom' when nothing was submitted; treat that
// no-filter default as "this month" so a day-granularity report doesn't try to render
// several years of rows on first load.
$selected_canned = $_GET['canned_date'] ?? 'thismonth';
if ($selected_canned === 'custom' && !isset($_GET['dtf'])) {
    $selected_canned = 'thismonth';
}

?>

<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-calendar-day me-2"></i>Tickets: Day by Day</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <!-- Date range filter (uses includes/filter_header.php) -->
        <form class="p-3 d-print-none form-row align-items-end">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Date range</label>
                <select class="form-control auto-submit-select" id="tdbCanned" name="canned_date">
                    <?php foreach ($canned_options as $val => $label) { ?>
                        <option value="<?php echo $val; ?>" <?php if ($selected_canned === $val) { echo 'selected'; } ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">From</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="tdbCanned" name="dtf" value="<?php echo nullable_htmlentities($report_from); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">To</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="tdbCanned" name="dtt" value="<?php echo nullable_htmlentities($report_to); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <button type="submit" class="btn btn-secondary btn-block">
                    <i class="fas fa-fw fa-filter me-1"></i>Apply (custom)
                </button>
            </div>
        </form>

        <div class="px-3 pb-2">
            <small class="text-muted">
                Showing <strong><?php echo nullable_htmlentities($report['date_from']); ?></strong> to <strong><?php echo nullable_htmlentities($report['date_to']); ?></strong>
                &mdash; <?php echo count($report['days']); ?> day<?php echo count($report['days']) === 1 ? '' : 's'; ?>.
                <?php if (!empty($report['truncated'])) { ?>
                    <span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Range capped at 400 days &mdash; narrow the date range to see the rest.</span>
                <?php } ?>
            </small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-4">
                <div class="info-box bg-danger mb-3">
                    <span class="info-box-icon"><i class="fas fa-inbox"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Created</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['created']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Closed</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['closed']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['net']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="px-3">
            <div class="card card-outline card-secondary">
                <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Created vs. Closed, by Day</h6></div>
                <div class="card-body">
                    <div class="chart-h-320"><canvas id="dayBreakdownChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Per-day table -->
        <div class="px-3 pb-3">
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Created</th>
                            <th class="text-end">Closed</th>
                            <th class="text-end">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['days'])) { ?>
                            <tr><td colspan="4" class="text-center text-muted">No days in range.</td></tr>
                        <?php } else {
                            foreach (array_reverse($report['days']) as $d) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($d['date']); ?></td>
                                <td class="text-end"><?php echo intval($d['created']); ?></td>
                                <td class="text-end"><?php echo intval($d['closed']); ?></td>
                                <td class="text-end<?php echo $d['net'] > 0 ? ' text-danger' : ($d['net'] < 0 ? ' text-success' : ''); ?>"><?php echo intval($d['net']); ?></td>
                            </tr>
                        <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    var ctx = document.getElementById("dayBreakdownChart");
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($report['days'], 'label')); ?>,
            datasets: [
                {
                    label: 'Created',
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220,53,69,0.08)',
                    pointBackgroundColor: '#dc3545',
                    fill: true,
                    tension: 0.3,
                    data: <?php echo json_encode(array_column($report['days'], 'created')); ?>
                },
                {
                    label: 'Closed',
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40,167,69,0.08)',
                    pointBackgroundColor: '#28a745',
                    fill: true,
                    tension: 0.3,
                    data: <?php echo json_encode(array_column($report['days'], 'closed')); ?>
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 14 } },
                y: { beginAtZero: true, ticks: { maxTicksLimit: 6, precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } }
            },
            plugins: { legend: { display: true } }
        }
    });
});
</script>
