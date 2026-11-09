<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_headline.php";

enforceUserPermission('module_support');

// inc_all_reports.php loads includes/filter_header.php, which resolves the
// canned-date / custom-date filter into $dtf and $dtt.
if (!$date_range_explicit) {
    // No filter submitted: default to the last 30 days (a fresh month starts out as an empty chart).
    $date_range = dateRangeFromRequest(['canned_date' => 'last30']);
    $dtf = $date_range->from();
    $dtt = $date_range->to();
}
$report_from = $dtf;
$report_to   = $dtt;
if ($report_from === '1970-01-01') {
    // An explicit "all time" would span every year; show the last 30 days instead.
    $report_from = date('Y-m-d', strtotime('-29 days'));
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


?>

<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<?php
$hl_days = $report['days'];
if ($hl_days) {
    $c = intval($report['totals']['created']); $cl = intval($report['totals']['closed']); $n = intval($report['totals']['net']);
    $busy = $hl_days[0];
    foreach ($hl_days as $d) { if ($d['created'] > $busy['created']) { $busy = $d; } }
    $nd = count($hl_days);
    if ($c + $cl === 0) {
        reportHeadline('fa-info-circle', 'No tickets were created or closed in this range', 'Widen the date range to see activity.', 'secondary');
    } else {
        reportHeadline($n > 0 ? 'fa-arrow-up' : 'fa-arrow-down',
            $n > 0 ? "The backlog grew by $n: $c opened vs $cl closed" : ($n < 0 ? 'The backlog shrank by ' . abs($n) . ": $cl closed vs $c opened" : "Closing kept pace with opening: $c each"),
            "Busiest day: {$busy['date']} with {$busy['created']} new tickets. Average " . round($c / $nd, 1) . ' opened and ' . round($cl / $nd, 1) . " closed per day over $nd days.",
            $n > 0 ? 'warning' : 'success', ['Opened' => $c, 'Closed' => $cl]);
    }
}
?>
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-calendar-day me-2"></i>Tickets: Day by Day</h3>
    </div>
    <div class="card-body p-0">

        <!-- Date range filter (uses includes/filter_header.php) -->
        <form class="p-3 d-print-none">
            <label class="mb-1 d-block">Date range</label>
            <?php echo dateRangePickerField($date_range, 'canned_date', ['default' => 'last30', 'dates' => [$report_from, $report_to]]); ?>
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
                            $quiet = 0;
                            foreach (array_reverse($report['days']) as $d) { if (!$d['created'] && !$d['closed']) { $quiet++; continue; } ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($d['date']); ?></td>
                                <td class="text-end"><?php echo intval($d['created']); ?></td>
                                <td class="text-end"><?php echo intval($d['closed']); ?></td>
                                <td class="text-end<?php echo $d['net'] > 0 ? ' text-danger' : ($d['net'] < 0 ? ' text-success' : ''); ?>"><?php echo intval($d['net']); ?></td>
                            </tr>
                        <?php }
                            if ($quiet) { ?>
                            <tr><td colspan="4" class="text-center text-secondary small"><?php echo $quiet; ?> quiet day<?php echo $quiet === 1 ? '' : 's'; ?> with no tickets created or closed not listed (they still appear in the chart and CSV).</td></tr>
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
            plugins: { legend: { display: true, labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } } }
        }
    });
});
</script>
