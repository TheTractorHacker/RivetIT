<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// inc_all_reports.php -> filter_header.php resolves the canned/custom date filter into
// $dtf/$dtt. Translate the "all time" sentinels into a bounded window (mirrors
// service_desk.php / technician_performance.php).
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    $earliest = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT DATE(MIN(ticket_csat_rated_at)) AS d FROM tickets WHERE ticket_csat_rated_at IS NOT NULL"));
    $report_from = $earliest['d'] ?? date('Y-01-01');
    if (empty($report_from)) {
        $report_from = date('Y-01-01');
    }
}
if ($report_to === '2099-12-31') {
    $report_to = date('Y-m-d');
}

$report = getCsatReport($mysqli, $report_from, $report_to, null, $config_ticket_csat_low_rating_threshold);

// CSV export: raw feedback feed (same rows as the "Feedback" table below).
if (!empty($report_export_csv)) {
    $csv_header = ['Ticket #', 'Client', 'Technician', 'Rating', 'Comment', 'Rated at'];
    $csv_rows = [];
    foreach ($report['feedback'] as $f) {
        $csv_rows[] = [
            $config_ticket_prefix . $f['ticket_number'],
            $f['client_name'] ?? '',
            $f['tech_name'] ?? '',
            $f['rating'],
            $f['comment'] ?? '',
            $f['rated_at'],
        ];
    }
    report_send_csv('csat_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
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

// Build chart series.
$dist_labels = [
    csatFaceEmoji(1) . ' ' . csatFaceLabel(1),
    csatFaceEmoji(2) . ' ' . csatFaceLabel(2),
    csatFaceEmoji(3) . ' ' . csatFaceLabel(3),
    csatFaceEmoji(4) . ' ' . csatFaceLabel(4),
    csatFaceEmoji(5) . ' ' . csatFaceLabel(5),
];
$dist_data   = [
    $report['distribution'][1],
    $report['distribution'][2],
    $report['distribution'][3],
    $report['distribution'][4],
    $report['distribution'][5],
];
$trend_labels = array_column($report['trend'], 'label');
$trend_avg    = array_map(function ($t) { return $t['avg_rating']; }, $report['trend']);

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
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-star me-2"></i>Customer Satisfaction (CSAT)</h3>
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
                <select class="form-control auto-submit-select" id="csatCanned" name="canned_date">
                    <?php foreach ($canned_options as $val => $label) { ?>
                        <option value="<?php echo $val; ?>" <?php if ($selected_canned === $val) { echo 'selected'; } ?>><?php echo $label; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">From</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="csatCanned" name="dtf" value="<?php echo nullable_htmlentities($report_from); ?>">
            </div>
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">To</label>
                <input type="date" class="form-control js-canned-date-input" data-canned-target="csatCanned" name="dtt" value="<?php echo nullable_htmlentities($report_to); ?>">
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
                KPI tiles and tables are scoped to tickets <em>closed</em> in range; the trend chart is scoped to when each rating was <em>submitted</em>.
            </small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-6 col-md-3">
                <div class="info-box bg-warning mb-3">
                    <span class="info-box-icon"><i class="fas fa-star"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Rating</span>
                        <span class="info-box-number"><?php echo $report['summary']['avg_rating'] !== null ? $report['summary']['avg_rating'] . ' / 5' : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-reply"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Response Rate</span>
                        <span class="info-box-number"><?php echo $report['summary']['response_rate_pct'] !== null ? $report['summary']['response_rate_pct'] . '%' : '—'; ?></span>
                        <span class="info-box-text" style="font-size:10px"><?php echo intval($report['summary']['rated']); ?> of <?php echo intval($report['summary']['total_closed']); ?> closed</span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-smile"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Satisfied (<?= csatFaceEmoji(4) ?><?= csatFaceEmoji(5) ?>)</span>
                        <span class="info-box-number"><?php echo $report['summary']['satisfied_pct'] !== null ? $report['summary']['satisfied_pct'] . '%' : '—'; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-danger mb-3">
                    <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Needs Follow-up</span>
                        <span class="info-box-number"><?php echo intval($report['summary']['needs_followup_count']); ?></span>
                        <span class="info-box-text" style="font-size:10px">rated &le; <?php echo intval($config_ticket_csat_low_rating_threshold); ?>/5, not yet re-closed</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row px-3">
            <div class="col-lg-5">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Rating Distribution</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="csatDistChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Avg Rating Trend</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="csatTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Per-technician / per-client breakdowns -->
        <div class="row px-3">
            <div class="col-lg-6">
                <h6 class="mt-2"><i class="fas fa-user-cog me-2"></i>By Technician</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Technician</th>
                                <th class="text-end">Rated</th>
                                <th class="text-end">Avg Rating</th>
                                <th class="text-end">Satisfied %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['by_technician'])) { ?>
                                <tr><td colspan="4" class="text-center text-muted">No ratings in range.</td></tr>
                            <?php } else { foreach ($report['by_technician'] as $t) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($t['name']); ?></td>
                                    <td class="text-end"><?php echo intval($t['rated_count']); ?></td>
                                    <td class="text-end"><?php echo $t['avg_rating'] !== null ? $t['avg_rating'] . ' / 5' : '—'; ?></td>
                                    <td class="text-end"><?php echo $t['satisfied_pct'] !== null ? $t['satisfied_pct'] . '%' : '—'; ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <h6 class="mt-2"><i class="fas fa-building me-2"></i>By Client</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-end">Rated</th>
                                <th class="text-end">Avg Rating</th>
                                <th class="text-end">Satisfied %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['by_client'])) { ?>
                                <tr><td colspan="4" class="text-center text-muted">No ratings in range.</td></tr>
                            <?php } else { foreach ($report['by_client'] as $c) { ?>
                                <tr>
                                    <td><a href="../client_overview.php?client_id=<?php echo intval($c['client_id']); ?>"><?php echo nullable_htmlentities($c['name']); ?></a></td>
                                    <td class="text-end"><?php echo intval($c['rated_count']); ?></td>
                                    <td class="text-end"><?php echo $c['avg_rating'] !== null ? $c['avg_rating'] . ' / 5' : '—'; ?></td>
                                    <td class="text-end"><?php echo $c['satisfied_pct'] !== null ? $c['satisfied_pct'] . '%' : '—'; ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Raw feedback feed -->
        <div class="px-3 pb-3">
            <h6 class="mt-2"><i class="fas fa-comment-dots me-2"></i>Feedback <small class="text-muted">(most recent first, capped at 1000 rows — full range via CSV)</small></h6>
            <div class="table-responsive-sm">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Client</th>
                            <th>Technician</th>
                            <th>Rating</th>
                            <th>Comment</th>
                            <th>Rated At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['feedback'])) { ?>
                            <tr><td colspan="6" class="text-center text-muted">No ratings in range.</td></tr>
                        <?php } else { foreach ($report['feedback'] as $f) { ?>
                            <tr>
                                <td><a href="../ticket.php?ticket_id=<?php echo intval($f['ticket_id']); ?>"><?php echo nullable_htmlentities($config_ticket_prefix . $f['ticket_number']); ?></a></td>
                                <td><?php echo nullable_htmlentities($f['client_name']); ?></td>
                                <td><?php echo nullable_htmlentities($f['tech_name']); ?></td>
                                <td>
                                    <span class="csat-face-display" title="<?= csatFaceLabel($f['rating']) ?>"><?= csatFaceEmoji($f['rating']) ?></span>
                                </td>
                                <td><?php echo nullable_htmlentities($f['comment']); ?></td>
                                <td><?php echo nullable_htmlentities($f['rated_at']); ?></td>
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

    // RATING DISTRIBUTION (bar - ordinal 1-5 reads better than a donut)
    (function () {
        var ctx = document.getElementById("csatDistChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dist_labels); ?>,
                datasets: [{
                    label: 'Ratings',
                    data: <?php echo json_encode($dist_data); ?>,
                    backgroundColor: ['#dc2626', '#f97316', '#f5a623', '#84cc16', '#059669']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();

    // AVG RATING TREND (line - Y axis pinned to the 1-5 scale so small real
    // differences aren't visually exaggerated by auto-ranging)
    (function () {
        var ctx = document.getElementById("csatTrendChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: 'Avg Rating',
                    data: <?php echo json_encode($trend_avg); ?>,
                    borderColor: '#f5a623',
                    backgroundColor: 'rgba(245,166,35,.15)',
                    spanGaps: true,
                    tension: 0.25,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { min: 1, max: 5, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();
});
</script>
