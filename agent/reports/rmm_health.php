<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// Resolve the canned/custom date filter (filter_header.php) into a bounded window.
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    $earliest = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT DATE(MIN(created_at)) AS d FROM rmm_alerts"));
    $report_from = $earliest['d'] ?? date('Y-01-01');
    if (empty($report_from)) {
        $report_from = date('Y-01-01');
    }
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$report = getRmmHealthReport($mysqli, $report_from, $report_to);

$canned_options = [
    'alltime'   => 'All time',
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'thisweek'  => 'This week',
    'lastweek'  => 'Last week',
    'thismonth' => 'This month',
    'lastmonth' => 'Last month',
    'thisyear'  => 'This year',
    'lastyear'  => 'Last year',
    'custom'    => 'Custom range',
];
$selected_canned = $_GET['canned_date'] ?? 'alltime';
if ($selected_canned === 'custom' && !isset($_GET['dtf'])) {
    $selected_canned = 'alltime';
}

$severity_colors = [
    'critical' => '#8b0000',
    'error'    => '#dc3545',
    'warning'  => '#ffc107',
    'info'     => '#17a2b8',
    'unknown'  => '#6c757d',
];
$status_badge = [
    'new'          => 'danger',
    'acknowledged' => 'warning',
    'resolved'     => 'success',
    'unknown'      => 'secondary',
];

// Chart colours resolved from the severity map (DB-driven values, mapped to the app palette).
$sev_labels = array_column($report['by_severity'], 'severity');
$sev_counts = array_column($report['by_severity'], 'count');
$sev_bg = array_map(function ($s) use ($severity_colors) {
    return $severity_colors[strtolower((string) $s)] ?? '#6c757d';
}, $sev_labels);

?>

<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-heartbeat me-2"></i>RMM Health</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <!-- Date range filter -->
        <form class="p-3 d-print-none form-row align-items-end">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Date range</label>
                <select class="form-control auto-submit-select" id="rmmCanned" name="canned_date">
                    <?php foreach ($canned_options as $val => $label) { ?>
                        <option value="<?php echo $val; ?>" <?php if ($selected_canned === $val) { echo 'selected'; } ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">From</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="rmmCanned" name="dtf" value="<?php echo nullable_htmlentities($report_from); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">To</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="rmmCanned" name="dtt" value="<?php echo nullable_htmlentities($report_to); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <button type="submit" class="btn btn-secondary btn-block">
                    <i class="fas fa-fw fa-filter me-1"></i>Apply (custom)
                </button>
            </div>
        </form>

        <?php if (!$report['have_data']) { ?>
            <div class="px-3 pb-4">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>No RMM alerts have been recorded yet. Connect an RMM integration to populate this report.
                </div>
            </div>
        <?php } else { ?>

        <div class="px-3 pb-2">
            <small class="text-muted">Showing alerts created <strong><?php echo nullable_htmlentities($report['date_from']); ?></strong> to <strong><?php echo nullable_htmlentities($report['date_to']); ?></strong>.</small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-6 col-md-2">
                <div class="info-box bg-danger mb-3">
                    <span class="info-box-icon"><i class="fas fa-bell"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Alerts</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['total']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-warning mb-3">
                    <span class="info-box-icon"><i class="fas fa-folder-open"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Open</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['open']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Resolved</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['resolved']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-info mb-3">
                    <span class="info-box-icon"><i class="fas fa-stopwatch"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">MTTA</span>
                        <span class="info-box-number" style="font-size:1.1rem"><?php echo $report['totals']['mtta_seconds'] !== null ? secondsToTime($report['totals']['mtta_seconds']) : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-flag-checkered"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">MTTR</span>
                        <span class="info-box-number" style="font-size:1.1rem"><?php echo $report['totals']['mttr_seconds'] !== null ? secondsToTime($report['totals']['mttr_seconds']) : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-teal mb-3">
                    <span class="info-box-icon"><i class="fas fa-ticket-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">&rarr; Ticket</span>
                        <span class="info-box-number"><?php echo $report['totals']['conversion_pct'] !== null ? $report['totals']['conversion_pct'] . '%' : '—'; ?></span>
                        <span class="info-box-text" style="font-size:10px"><?php echo intval($report['totals']['with_ticket']); ?> of <?php echo intval($report['totals']['total']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row px-3">
            <div class="col-lg-8">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Alert Volume Trend</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="rmmTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-layer-group me-2"></i>Alerts by Severity</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="rmmSeverityChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status breakdown -->
        <div class="px-3">
            <h6 class="mt-2"><i class="fas fa-tasks me-2"></i>Alerts by Status</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped table-sm">
                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($report['by_status'] as $s) {
                            $badge = $status_badge[strtolower((string) $s['status'])] ?? 'secondary'; ?>
                            <tr>
                                <td><span class="badge badge-<?php echo $badge; ?>"><?php echo nullable_htmlentities(ucfirst((string) $s['status'])); ?></span></td>
                                <td class="text-end"><?php echo intval($s['count']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Noisiest assets + clients -->
        <div class="row px-3 pb-3">
            <div class="col-md-6">
                <h6 class="mt-2"><i class="fas fa-server me-2"></i>Noisiest Assets</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>Asset</th><th class="text-end">Alerts</th><th class="text-end">Avg MTTR</th></tr></thead>
                        <tbody>
                            <?php if (empty($report['noisiest_assets'])) { ?>
                                <tr><td colspan="3" class="text-center text-muted">No asset-linked alerts.</td></tr>
                            <?php } else {
                                foreach ($report['noisiest_assets'] as $a) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($a['asset_name']); ?></td>
                                    <td class="text-end"><?php echo intval($a['alerts']); ?></td>
                                    <td class="text-end"><?php echo $a['mttr_seconds'] !== null ? secondsToTime($a['mttr_seconds']) : '—'; ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="mt-2"><i class="fas fa-building me-2"></i>Noisiest Clients</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>Client</th><th class="text-end">Alerts</th><th class="text-end">&rarr; Ticket</th></tr></thead>
                        <tbody>
                            <?php if (empty($report['noisiest_clients'])) { ?>
                                <tr><td colspan="3" class="text-center text-muted">No client-linked alerts.</td></tr>
                            <?php } else {
                                foreach ($report['noisiest_clients'] as $c) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($c['client_name']); ?></td>
                                    <td class="text-end"><?php echo intval($c['alerts']); ?></td>
                                    <td class="text-end"><?php echo intval($c['with_ticket']); ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php } // end have_data ?>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>

<?php if ($report['have_data']) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // ALERT VOLUME TREND
    (function () {
        var ctx = document.getElementById("rmmTrendChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($report['trend']['labels']); ?>,
                datasets: [{
                    label: 'Alerts',
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220,53,69,0.10)',
                    pointBackgroundColor: '#dc3545',
                    fill: true,
                    tension: 0.3,
                    data: <?php echo json_encode($report['trend']['counts']); ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                    y: { beginAtZero: true, ticks: { maxTicksLimit: 6, precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();

    // ALERTS BY SEVERITY
    (function () {
        var ctx = document.getElementById("rmmSeverityChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($sev_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($sev_counts); ?>,
                    backgroundColor: <?php echo json_encode($sev_bg); ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    })();
});
</script>
<?php } ?>
