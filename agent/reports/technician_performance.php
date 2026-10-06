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
// Note: getTechnicianPerformanceReport() still returns a billable/non-billable split
// (also consumed by api/v1/reports/technician_performance.php - an existing API
// contract, not something to change here) - this page just displays the sum of the two
// as one "hours logged" figure, since this edition has no billing concept to split on.
if (!empty($report_export_csv)) {
    $csv_header = ['Technician', 'Hours logged', 'Utilization %', 'Tickets closed', 'Avg handle (seconds)', 'CSAT avg rating', 'CSAT rated count', 'CSAT satisfied %'];
    $csv_rows = [];
    foreach ($report['technicians'] as $t) {
        $csv_rows[] = [
            $t['name'],
            round(($t['billable_seconds'] + $t['nonbillable_seconds']) / 3600, 2),
            $t['utilization_pct'] !== null ? $t['utilization_pct'] : '',
            intval($t['tickets_closed']),
            $t['avg_handle_seconds'] !== null ? intval($t['avg_handle_seconds']) : '',
            $t['csat_avg_rating'] !== null ? $t['csat_avg_rating'] : '',
            intval($t['csat_rated_count']),
            $t['csat_satisfied_pct'] !== null ? $t['csat_satisfied_pct'] : '',
        ];
    }
    report_send_csv('technician_performance_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
}


// Build chart series (hours).
$chart_labels = array_column($report['technicians'], 'name');
$chart_hours = array_map(function ($t) { return round(($t['billable_seconds'] + $t['nonbillable_seconds']) / 3600, 2); }, $report['technicians']);

// The chart is one horizontal bar per technician, so its height follows the number of
// technicians instead of a fixed 320px box: a one-tech install used to get a single bar
// stranded in a mostly empty plot, and a ten-tech install would have squeezed them.
$tech_count = count($report['technicians']);
$chart_height = max(180, min(440, $tech_count * 46 + 64));

// A range with no technicians at all - or one where nobody logged a minute - used to
// draw as bare, unexplained axes. Say so instead; the table below still lists everyone.
$chart_has_data = ($tech_count > 0 && array_sum($chart_hours) > 0);

$cap_hours_per_tech = $report['capacity']['capacity_hours_per_tech'];
$total_hours = ($report['totals']['billable_seconds'] + $report['totals']['nonbillable_seconds']) / 3600;

// CSAT tile: fold the rated/satisfied counts into the tile label rather than a third
// line of text inside the tile, so every tile on the row has the same two-line shape.
$csat_label = 'Team CSAT';
if ($report['totals']['csat_rated_count'] > 0) {
    $csat_label .= ' · ' . intval($report['totals']['csat_rated_count']) . ' rated, ' . $report['totals']['csat_satisfied_pct'] . '% satisfied';
}

?>

<style nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
  /* Chart box. Height is data-driven (see $chart_height) and set inline; this gives
     Chart.js the positioned parent it sizes against, and caps the width - a bar chart
     of a handful of names does not need the full 1270px content column, and the sibling
     report pages put their charts in col-lg-8-ish columns for the same reason. */
  .tp-chart { position: relative; max-width: 52rem; }

  /* Tabler pins .btn at min-height:40px while .form-control lands at 36.375px, so
     the filter row's submit button overhung the three fields it sits beside by ~3.6px.
     Match the input metrics exactly (7.2px block padding + 20px line-box + 2px border)
     for the buttons in THIS form only. */
  .tp-filter .btn { min-height: 0; padding-block: .45rem; }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-clock me-2"></i>Technician Performance &amp; Utilization</h3>
        <!-- Export/Print are secondary to the report itself, so they use the neutral
             button (white + border) rather than btn-success/btn-primary: Tabler's
             success green (#2fb344) is not one of this app's greens, and on a red-accent
             install btn-primary made "Print" wear the destructive colour. -->
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-secondary d-print-none me-2"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-secondary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <!-- Date range filter -->
        <form class="p-3 d-print-none">
            <label class="mb-1 d-block">Date range</label>
            <?php echo dateRangePickerField($date_range, 'canned_date', ['dates' => [$report_from, $report_to]]); ?>
        </form>

        <div class="px-3 pb-3">
            <small class="text-muted">
                Showing <strong><?php echo nullable_htmlentities($report['date_from']); ?></strong> to <strong><?php echo nullable_htmlentities($report['date_to']); ?></strong>.
                Utilization baseline: <strong><?php echo intval($report['capacity']['hours_per_day']); ?>h/day &times; <?php echo intval($report['capacity']['business_days']); ?> business days = <?php echo intval($cap_hours_per_tech); ?>h capacity per tech</strong>.
                <!-- TODO: capacity is a hard-coded default (REPORT_CAPACITY_HOURS_PER_DAY); make configurable per company/user. -->
            </small>
        </div>

        <!-- Headline stats. Uses the app's own stat tile (includes/ui/stat_card.php),
             the same component the reports hub uses, so a figure looks identical
             wherever it appears: value first, muted label under it. -->
        <div class="px-3">
            <div class="it-stat-grid">
                <?php render_stat_card('Hours Logged', number_format($total_hours, 1), 'fas fa-hourglass-half', 'info'); ?>
                <?php render_stat_card('Team Utilization', $report['totals']['team_utilization_pct'] !== null ? $report['totals']['team_utilization_pct'] . '%' : '—', 'fas fa-percentage', 'primary'); ?>
                <?php render_stat_card($csat_label, $report['totals']['csat_avg_rating'] !== null ? $report['totals']['csat_avg_rating'] . ' / 5' : '—', 'fas fa-smile', 'warning'); ?>
            </div>
        </div>

        <!-- Chart -->
        <div class="px-3">
            <h6 class="mb-2"><i class="fas fa-chart-bar me-2 text-muted"></i>Hours Logged by Technician</h6>
            <?php if (!$chart_has_data) { ?>
                <?php render_empty_state(
                    'fas fa-chart-bar',
                    'No time logged in this range',
                    'Nobody recorded ticket time between these dates, so there is nothing to plot. Widen the date range above to see earlier work.'
                ); ?>
            <?php } else { ?>
                <div class="tp-chart" style="height: <?php echo intval($chart_height); ?>px"><canvas id="tpHoursChart"></canvas></div>
            <?php } ?>
        </div>

        <!-- Per-technician detail -->
        <div class="px-3 pb-3">
            <h6 class="mt-3 mb-2"><i class="fas fa-user-cog me-2 text-muted"></i>Per-Technician Detail</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th class="text-end">Hours Logged</th>
                            <th class="text-end">Utilization</th>
                            <th class="text-end">Tickets Closed</th>
                            <th class="text-end">Avg Handle</th>
                            <th class="text-end">CSAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['technicians'])) { ?>
                            <tr><td colspan="6" class="text-center text-muted">No technicians found.</td></tr>
                        <?php } else {
                            foreach ($report['technicians'] as $t) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($t['name']); ?></td>
                                <td class="text-end"><?php echo number_format(($t['billable_seconds'] + $t['nonbillable_seconds']) / 3600, 1); ?></td>
                                <td class="text-end"><?php echo $t['utilization_pct'] !== null ? $t['utilization_pct'] . '%' : '—'; ?></td>
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

    // HOURS LOGGED (one horizontal bar per technician)
    (function () {
        var ctx = document.getElementById("tpHoursChart");
        if (!ctx) return;

        // Colour comes from the live accent token via js/chart_theme.js. The old
        // hard-coded '#0d9488' was only the upstream default fallback, so on any
        // install with its own accent the largest coloured area on the page belonged
        // to no token at all.
        var accent = (window.itflowChartTheme && window.itflowChartTheme.semantic().primary) || '#0d9488';

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'Hours logged',
                        data: <?php echo json_encode($chart_hours); ?>,
                        backgroundColor: accent,
                        // Without a cap, one technician = one bar filling ~70% of the
                        // plot, which reads as a rendering fault rather than a value.
                        maxBarThickness: 24,
                        borderRadius: 3
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { right: 8 } },
                scales: {
                    x: { beginAtZero: true, ticks: { maxTicksLimit: 6 } },
                    y: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return ' ' + c.parsed.x + ' h'; } } }
                }
            }
        });
    })();
});
</script>
