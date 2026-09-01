<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// inc_all_reports.php -> filter_header.php resolves the canned/custom date filter into
// $dtf/$dtt. Translate the "all time" sentinels into a bounded window (mirrors service_desk.php).
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    $earliest = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT DATE(MIN(ticket_reply_created_at)) AS d FROM ticket_replies"));
    $report_from = $earliest['d'] ?? date('Y-01-01');
    if (empty($report_from)) {
        $report_from = date('Y-01-01');
    }
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$report = getTechnicianPerformanceReport($mysqli, $report_from, $report_to);

// CSV export: per-technician detail (same rows as the "Per-Technician Detail" table).
if (!empty($report_export_csv)) {
    $csv_header = ['Technician', 'Billable hours', 'Non-billable hours', 'Utilization %', 'Billable value', 'Tickets closed', 'Avg handle (seconds)', 'CSAT avg rating', 'CSAT rated count', 'CSAT satisfied %'];
    $csv_rows = [];
    foreach ($report['technicians'] as $t) {
        $csv_rows[] = [
            $t['name'],
            round($t['billable_seconds'] / 3600, 2),
            round($t['nonbillable_seconds'] / 3600, 2),
            $t['utilization_pct'] !== null ? $t['utilization_pct'] : '',
            $t['billable_value'],
            intval($t['tickets_closed']),
            $t['avg_handle_seconds'] !== null ? intval($t['avg_handle_seconds']) : '',
            $t['csat_avg_rating'] !== null ? $t['csat_avg_rating'] : '',
            intval($t['csat_rated_count']),
            $t['csat_satisfied_pct'] !== null ? $t['csat_satisfied_pct'] : '',
        ];
    }
    report_send_csv('technician_performance_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
}

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

// Build chart series (hours).
$chart_labels = array_column($report['technicians'], 'name');
$chart_billable = array_map(function ($t) { return round($t['billable_seconds'] / 3600, 2); }, $report['technicians']);
$chart_nonbill  = array_map(function ($t) { return round($t['nonbillable_seconds'] / 3600, 2); }, $report['technicians']);

$cap_hours_per_tech = $report['capacity']['capacity_hours_per_tech'];

?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }

  /* KPI stat tiles: Bootstrap's .bg-* utilities only set background-color, so the
     default dark body text sits directly on a saturated fill (unreadable on
     bg-dark/success/danger/primary/secondary). Muted surface + colored left
     accent + colored icon instead, matching the app's contextual-row treatment
     (see itflow_design.css "Contextual rows"). */
  .info-box.bg-primary, .info-box.bg-secondary, .info-box.bg-success, .info-box.bg-danger,
  .info-box.bg-warning, .info-box.bg-info, .info-box.bg-dark, .info-box.bg-teal {
      background-color: var(--if-surface) !important;
      color: var(--if-ink) !important;
      border: 1px solid var(--if-border);
      border-inline-start-width: 3px;
      box-shadow: var(--if-shadow);
  }
  .info-box.bg-primary   { border-inline-start-color: #0d6efd; }
  .info-box.bg-secondary { border-inline-start-color: #6c757d; }
  .info-box.bg-success   { border-inline-start-color: #059669; }
  .info-box.bg-danger    { border-inline-start-color: #dc2626; }
  .info-box.bg-warning   { border-inline-start-color: #d97706; }
  .info-box.bg-info      { border-inline-start-color: #0891b2; }
  .info-box.bg-dark      { border-inline-start-color: #475569; }
  .info-box.bg-teal      { border-inline-start-color: var(--color-accent, #0d9488); }
  .info-box.bg-primary .info-box-icon   { color: #0d6efd; }
  .info-box.bg-secondary .info-box-icon { color: #6c757d; }
  .info-box.bg-success .info-box-icon   { color: #059669; }
  .info-box.bg-danger .info-box-icon    { color: #dc2626; }
  .info-box.bg-warning .info-box-icon   { color: #b45309; }
  .info-box.bg-info .info-box-icon      { color: #0891b2; }
  .info-box.bg-dark .info-box-icon      { color: #475569; }
  .info-box.bg-teal .info-box-icon      { color: var(--color-accent, #0d9488); }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-clock me-2"></i>Technician Performance &amp; Utilization</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <!-- Date range filter -->
        <form class="p-3 d-print-none form-row align-items-end">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Date range</label>
                <select class="form-control auto-submit-select" id="tpCanned" name="canned_date">
                    <?php foreach ($canned_options as $val => $label) { ?>
                        <option value="<?php echo $val; ?>" <?php if ($selected_canned === $val) { echo 'selected'; } ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">From</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="tpCanned" name="dtf" value="<?php echo nullable_htmlentities($report_from); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">To</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="tpCanned" name="dtt" value="<?php echo nullable_htmlentities($report_to); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <button type="submit" class="btn btn-secondary btn-block">
                    <i class="fas fa-fw fa-filter me-1"></i>Apply (custom)
                </button>
            </div>
        </form>

        <div class="px-3 pb-2">
            <small class="text-muted">
                Showing <strong><?php echo nullable_htmlentities($report['date_from']); ?></strong> to <strong><?php echo nullable_htmlentities($report['date_to']); ?></strong>.
                Utilization baseline: <strong><?php echo intval($report['capacity']['hours_per_day']); ?>h/day &times; <?php echo intval($report['capacity']['business_days']); ?> business days = <?php echo intval($cap_hours_per_tech); ?>h capacity per tech</strong>.
                <!-- TODO: capacity is a hard-coded default (REPORT_CAPACITY_HOURS_PER_DAY); make configurable per company/user. -->
            </small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-6 col-md-3">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Billable Hours</span>
                        <span class="info-box-number"><?php echo number_format($report['totals']['billable_seconds'] / 3600, 1); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-secondary mb-3">
                    <span class="info-box-icon"><i class="fas fa-hourglass-half"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Non-Billable Hours</span>
                        <span class="info-box-number"><?php echo number_format($report['totals']['nonbillable_seconds'] / 3600, 1); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-percentage"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Team Utilization</span>
                        <span class="info-box-number"><?php echo $report['totals']['team_utilization_pct'] !== null ? $report['totals']['team_utilization_pct'] . '%' : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-warning mb-3">
                    <span class="info-box-icon"><i class="fas fa-smile"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Team CSAT</span>
                        <span class="info-box-number"><?php echo $report['totals']['csat_avg_rating'] !== null ? $report['totals']['csat_avg_rating'] . ' / 5' : '—'; ?></span>
                        <?php if ($report['totals']['csat_rated_count'] > 0) { ?>
                        <span class="info-box-text" style="font-size:10px"><?php echo intval($report['totals']['csat_rated_count']); ?> rated · <?php echo $report['totals']['csat_satisfied_pct']; ?>% satisfied</span>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row px-3">
            <div class="col-lg-8">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Billable vs Non-Billable Hours by Technician</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="tpHoursChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Team Billable Mix</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="tpMixChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Per-technician detail -->
        <div class="px-3 pb-3">
            <h6 class="mt-2"><i class="fas fa-user-cog me-2"></i>Per-Technician Detail</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th class="text-end">Billable h</th>
                            <th class="text-end">Non-Billable h</th>
                            <th class="text-end">Utilization</th>
                            <th class="text-end">Billable Value</th>
                            <th class="text-end">Tickets Closed</th>
                            <th class="text-end">Avg Handle</th>
                            <th class="text-end">CSAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['technicians'])) { ?>
                            <tr><td colspan="8" class="text-center text-muted">No technicians found.</td></tr>
                        <?php } else {
                            foreach ($report['technicians'] as $t) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($t['name']); ?></td>
                                <td class="text-end"><?php echo number_format($t['billable_seconds'] / 3600, 1); ?></td>
                                <td class="text-end"><?php echo number_format($t['nonbillable_seconds'] / 3600, 1); ?></td>
                                <td class="text-end"><?php echo $t['utilization_pct'] !== null ? $t['utilization_pct'] . '%' : '—'; ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $t['billable_value'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo intval($t['tickets_closed']); ?></td>
                                <td class="text-end"><?php echo $t['avg_handle_seconds'] !== null ? secondsToTime($t['avg_handle_seconds']) : '—'; ?></td>
                                <td class="text-end">
                                    <?php echo $t['csat_avg_rating'] !== null ? $t['csat_avg_rating'] . ' / 5' : '—'; ?>
                                    <?php if ($t['csat_rated_count'] > 0) { ?>
                                        <small class="text-muted">(<?php echo intval($t['csat_rated_count']); ?> rated)</small>
                                    <?php } ?>
                                </td>
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
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // BILLABLE vs NON-BILLABLE HOURS (stacked bar per technician)
    (function () {
        var ctx = document.getElementById("tpHoursChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'Billable',
                        data: <?php echo json_encode($chart_billable); ?>,
                        backgroundColor: '#28a745'
                    },
                    {
                        label: 'Non-Billable',
                        data: <?php echo json_encode($chart_nonbill); ?>,
                        backgroundColor: '#6c757d'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { maxTicksLimit: 6 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: true } }
            }
        });
    })();

    // TEAM BILLABLE MIX (doughnut)
    (function () {
        var ctx = document.getElementById("tpMixChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Billable', 'Non-Billable'],
                datasets: [{
                    data: [
                        <?php echo round($report['totals']['billable_seconds'] / 3600, 2); ?>,
                        <?php echo round($report['totals']['nonbillable_seconds'] / 3600, 2); ?>
                    ],
                    backgroundColor: ['#28a745', '#6c757d']
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
