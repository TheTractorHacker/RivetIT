<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// inc_all_reports.php loads includes/filter_header.php, which resolves the
// canned-date / custom-date filter into $dtf and $dtt. Translate the "all time"
// sentinels into a sane, bounded reporting window so the monthly volume trend
// doesn't try to render from 1970.
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    $earliest = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT DATE(MIN(ticket_created_at)) AS d FROM tickets WHERE ticket_archived_at IS NULL"));
    $report_from = $earliest['d'] ?? date('Y-01-01');
    if (empty($report_from)) {
        $report_from = date('Y-01-01');
    }
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$report = getServiceDeskReport($mysqli, $report_from, $report_to);

// CSV export: monthly ticket volume trend (same series that feeds the trend chart).
if (!empty($report_export_csv)) {
    $csv_header = ['Month', 'Opened', 'Resolved', 'Cumulative backlog'];
    $csv_rows = [];
    foreach ($report['volume']['labels'] as $i => $label) {
        $csv_rows[] = [
            $label,
            intval($report['volume']['opened'][$i] ?? 0),
            intval($report['volume']['resolved'][$i] ?? 0),
            intval($report['volume']['backlog'][$i] ?? 0),
        ];
    }
    report_send_csv('service_desk_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
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
// filter_header.php forces canned_date to 'custom' when nothing was submitted; treat that
// no-filter default as "all time" for the selector so the UI isn't misleading on first load.
$selected_canned = $_GET['canned_date'] ?? 'alltime';
if ($selected_canned === 'custom' && !isset($_GET['dtf'])) {
    $selected_canned = 'alltime';
}

$priority_colors = [
    'Critical' => '#8b0000',
    'High'     => '#dc3545',
    'Medium'   => '#ffc107',
    'Low'      => '#17a2b8',
];

?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }

  /* KPI stat tiles: Bootstrap's .bg-* utilities only set background-color, so the
     default dark body text sits directly on a saturated fill (unreadable on
     bg-dark/success/danger/primary/secondary), and the undefined .bg-teal utility
     renders as a blank tile next to its colored siblings. Muted surface + colored
     left accent + colored icon instead, matching the app's contextual-row
     treatment (see itflow_design.css "Contextual rows"). */
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
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-headset me-2"></i>Service Desk &amp; SLA Ops</h3>
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
                <select class="form-control auto-submit-select" id="sdCanned" name="canned_date">
                    <?php foreach ($canned_options as $val => $label) { ?>
                        <option value="<?php echo $val; ?>" <?php if ($selected_canned === $val) { echo 'selected'; } ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">From</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="sdCanned" name="dtf" value="<?php echo nullable_htmlentities($report_from); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">To</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="sdCanned" name="dtt" value="<?php echo nullable_htmlentities($report_to); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <button type="submit" class="btn btn-secondary btn-block">
                    <i class="fas fa-fw fa-filter me-1"></i>Apply (custom)
                </button>
            </div>
        </form>

        <div class="px-3 pb-2">
            <small class="text-muted">Showing <strong><?php echo nullable_htmlentities($report['date_from']); ?></strong> to <strong><?php echo nullable_htmlentities($report['date_to']); ?></strong>. Aging &amp; current open workload are live snapshots.</small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-6 col-md-2">
                <div class="info-box bg-danger mb-3">
                    <span class="info-box-icon"><i class="fas fa-inbox"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Opened</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['opened']); ?></span>
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
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-folder-open"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Open Now</span>
                        <span class="info-box-number"><?php echo intval($report['totals']['open_now']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-info mb-3">
                    <span class="info-box-icon"><i class="fas fa-reply"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Response SLA</span>
                        <span class="info-box-number"><?php echo $report['sla']['response_pct'] !== null ? $report['sla']['response_pct'] . '%' : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-teal mb-3">
                    <span class="info-box-icon"><i class="fas fa-flag-checkered"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Resolution SLA</span>
                        <span class="info-box-number"><?php echo $report['sla']['resolution_pct'] !== null ? $report['sla']['resolution_pct'] . '%' : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="info-box bg-warning mb-3">
                    <span class="info-box-icon"><i class="fas fa-smile"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">CSAT</span>
                        <span class="info-box-number"><?php echo $report['csat']['avg_rating'] !== null ? $report['csat']['avg_rating'] . ' / 5' : '—'; ?></span>
                        <?php if ($report['csat']['rated'] > 0) { ?>
                        <span class="info-box-text" style="font-size:10px"><?php echo intval($report['csat']['rated']); ?> rated · <?php echo $report['csat']['satisfied_pct']; ?>% satisfied</span>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts: volume trend + aging -->
        <div class="row px-3">
            <div class="col-lg-8">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Ticket Volume Trend</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="volumeTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-hourglass-half me-2"></i>Open Ticket Aging</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="agingChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SLA compliance detail -->
        <div class="px-3">
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>SLA</th>
                            <th class="text-end">Met</th>
                            <th class="text-end">With SLA target</th>
                            <th class="text-end">Compliance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>First response</td>
                            <td class="text-end"><?php echo intval($report['sla']['response_met']); ?></td>
                            <td class="text-end"><?php echo intval($report['sla']['response_total']); ?></td>
                            <td class="text-end text-bold"><?php echo $report['sla']['response_pct'] !== null ? $report['sla']['response_pct'] . '%' : '—'; ?></td>
                        </tr>
                        <tr>
                            <td>Resolution</td>
                            <td class="text-end"><?php echo intval($report['sla']['resolution_met']); ?></td>
                            <td class="text-end"><?php echo intval($report['sla']['resolution_total']); ?></td>
                            <td class="text-end text-bold"><?php echo $report['sla']['resolution_pct'] !== null ? $report['sla']['resolution_pct'] . '%' : '—'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Avg response / resolution by priority -->
        <div class="px-3">
            <h6 class="mt-2"><i class="fas fa-exclamation-circle me-2"></i>Avg Response &amp; Resolution by Priority</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th class="text-end">Tickets</th>
                            <th class="text-end">Avg First Response</th>
                            <th class="text-end">Avg Resolution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['by_priority'])) { ?>
                            <tr><td colspan="4" class="text-center text-muted">No tickets in range.</td></tr>
                        <?php } else {
                            foreach ($report['by_priority'] as $p) {
                                $pname = nullable_htmlentities($p['priority']);
                                $pcolor = $priority_colors[$p['priority']] ?? '#6c757d'; ?>
                            <tr>
                                <td><span class="badge" style="background-color:<?php echo $pcolor; ?>;color:#fff"><?php echo $pname; ?></span></td>
                                <td class="text-end"><?php echo intval($p['total']); ?></td>
                                <td class="text-end"><?php echo secondsToTime($p['avg_response_seconds']); ?></td>
                                <td class="text-end"><?php echo secondsToTime($p['avg_resolve_seconds']); ?></td>
                            </tr>
                        <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Per-technician workload -->
        <div class="px-3 pb-3">
            <h6 class="mt-2"><i class="fas fa-user-cog me-2"></i>Technician Workload</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th class="text-end">Open (now)</th>
                            <th class="text-end">Resolved (range)</th>
                            <th class="text-end">Avg Resolution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['by_technician'])) { ?>
                            <tr><td colspan="4" class="text-center text-muted">No assigned tickets.</td></tr>
                        <?php } else {
                            foreach ($report['by_technician'] as $t) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($t['name']); ?></td>
                                <td class="text-end"><?php echo intval($t['open']); ?></td>
                                <td class="text-end"><?php echo intval($t['resolved']); ?></td>
                                <td class="text-end"><?php echo secondsToTime($t['avg_resolve_seconds']); ?></td>
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
    // Bootstrap-like defaults for Chart.js v4
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // TICKET VOLUME TREND (opened vs resolved vs cumulative net backlog)
    (function () {
        var ctx = document.getElementById("volumeTrendChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($report['volume']['labels']); ?>,
                datasets: [
                    {
                        label: 'Opened',
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220,53,69,0.08)',
                        pointBackgroundColor: '#dc3545',
                        fill: true,
                        tension: 0.3,
                        data: <?php echo json_encode($report['volume']['opened']); ?>
                    },
                    {
                        label: 'Resolved',
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40,167,69,0.08)',
                        pointBackgroundColor: '#28a745',
                        fill: true,
                        tension: 0.3,
                        data: <?php echo json_encode($report['volume']['resolved']); ?>
                    },
                    {
                        label: 'Backlog (cumulative net)',
                        borderColor: '#6f42c1',
                        backgroundColor: '#6f42c1',
                        pointBackgroundColor: '#6f42c1',
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.3,
                        data: <?php echo json_encode($report['volume']['backlog']); ?>
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                    y: { beginAtZero: true, ticks: { maxTicksLimit: 6 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: true } }
            }
        });
    })();

    // OPEN TICKET AGING BUCKETS
    (function () {
        var ctx = document.getElementById("agingChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($report['aging'], 'label')); ?>,
                datasets: [{
                    label: 'Open tickets',
                    data: <?php echo json_encode(array_column($report['aging'], 'count')); ?>,
                    backgroundColor: ['#28a745', '#a3c644', '#ffc107', '#fd7e14', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { maxTicksLimit: 6, precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();
});
</script>
