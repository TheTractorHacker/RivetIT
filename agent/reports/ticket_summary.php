<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_headline.php";

enforceUserPermission('module_support');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

$sql_ticket_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(ticket_created_at) AS ticket_year FROM tickets ORDER BY ticket_year DESC");

$sql_tickets = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE 1 = 1" . \ITFlow\Reports\ReportScope::clause('ticket_client_id'));

// Track largest month for chart y-axis max
$largest_ticket_month = 0;

// CSV export: tickets raised per month for the selected year (same query as the table).
if (!empty($report_export_csv)) {
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $csv_header = ['Month', 'Tickets raised'];
    $csv_rows = [];
    $year_total = 0;
    for ($m = 1; $m <= 12; $m++) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $m" . \ITFlow\Reports\ReportScope::clause('ticket_client_id')));
        $c = intval($r['c']);
        $csv_rows[] = [$months[$m - 1], $c];
        $year_total += $c;
    }
    $csv_rows[] = ['Total', $year_total];
    report_send_csv('ticket_summary_' . $year . '.csv', $csv_header, $csv_rows);
}

// One query for the whole year; months after the current one are "not yet" (null), not zero.
$monthly = array_fill(1, 12, 0);
$rs_m = mysqli_query($mysqli, "SELECT MONTH(ticket_created_at) AS m, COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at) = $year" . \ITFlow\Reports\ReportScope::clause('ticket_client_id') . " GROUP BY MONTH(ticket_created_at)");
while ($rm = mysqli_fetch_assoc($rs_m)) {
    $monthly[intval($rm['m'])] = intval($rm['c']);
}
$cur_year = intval(date('Y'));
$last_month = ($year < $cur_year) ? 12 : (($year == $cur_year) ? intval(date('n')) : 0);
$largest_ticket_month = max(array_slice($monthly, 0, max(1, $last_month), true) ?: [0]);
$total_tickets_for_all_months = array_sum($monthly);
$month_names = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$busiest = 0;
foreach ($monthly as $mm => $cc) { if ($mm <= $last_month && $cc >= ($monthly[$busiest] ?? -1)) { $busiest = $mm; } }

?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<?php
$in_progress = ($year == $cur_year && $last_month > 1) ? $last_month : 0;   // current month is not a fair comparison yet
$ref = $in_progress ? $last_month - 1 : $last_month;                             // last complete month
if ($ref > 0 && $total_tickets_for_all_months > 0) {
    $ref_c = $monthly[$ref]; $prev_c = $ref > 1 ? $monthly[$ref - 1] : null;
    $trend = ($prev_c !== null && $prev_c > 0) ? round((($ref_c - $prev_c) / $prev_c) * 100) : null;
    $detail = ($trend === null ? '' : ($trend >= 0 ? "Up $trend%" : 'Down ' . abs($trend) . '%') . ' on ' . $month_names[$ref - 1] . " ($prev_c). ")
            . 'Busiest month: ' . $month_names[$busiest] . " with {$monthly[$busiest]}."
            . ($in_progress ? ' ' . $month_names[$in_progress] . " is in progress ({$monthly[$in_progress]} so far)." : '');
    reportHeadline('fa-chart-line', "{$month_names[$ref]}: $ref_c tickets raised" . ($trend === null ? '' : ($trend >= 0 ? ", up $trend%" : ', down ' . abs($trend) . '%')), $detail, ($trend !== null && $trend > 25) ? 'warning' : 'primary',
        ["Total $year" => $total_tickets_for_all_months, 'Monthly average' => round(array_sum(array_slice($monthly, 0, $ref, true)) / $ref, 1)]);
} else {
    reportHeadline('fa-info-circle', "No tickets were raised in $year", 'Pick another year, or check the report scope.', 'secondary');
}
?>
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Ticket Summary</h3>
    </div>
    <div class="card-body p-0">
        <!-- The select used to be the form's only child, so it stretched to the full
             1270px card width and read as an empty text field containing "2026" rather
             than as a filter. It also had no <label> and no aria-label anywhere on the
             page, so its purpose was inferable only from its current value. -->
        <form class="p-3 d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-muted small" for="ticket-summary-year">Year</label>
            <select class="form-select form-select-sm auto-submit-select" name="year"
                    id="ticket-summary-year" style="max-width:9rem">
                <?php
                while ($row = mysqli_fetch_assoc($sql_ticket_years)) {
                    $ticket_year = intval($row['ticket_year']); ?>
                    <option <?php if ($year == $ticket_year) { ?> selected <?php } ?>><?php echo $ticket_year; ?></option>
                <?php } ?>
            </select>
        </form>

        <div class="px-3 pb-3">
            <div class="chart-h-320">
                <canvas id="tickets"></canvas>
            </div>
        </div>

        <div class="table-responsive-sm">
            <table class="table table-striped text-center mb-0">
                <thead>
                <tr>
                    <?php foreach ($month_names as $mn) { ?><th class="text-center"><?= substr($mn, 0, 3) ?></th><?php } ?>
                    <th class="text-center">Total</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <?php foreach ($monthly as $mm => $cc) { ?>
                        <td class="<?= $mm > $last_month ? 'text-secondary' : '' ?> <?= ($mm == $busiest && $cc > 0) ? 'fw-bold' : '' ?>"><?= $mm > $last_month ? '&ndash;' : $cc ?></td>
                    <?php } ?>
                    <td><b><?= $total_tickets_for_all_months ?></b></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap-like defaults for Chart.js v4

    (function () {
        var ctx = document.getElementById("tickets");
        if (!ctx) return;

        var dataPoints = <?= json_encode(array_map(function ($m) use ($monthly, $last_month) { return ($m <= $last_month && !($m == $last_month && $last_month == intval(date('n')) && $year == $cur_year && $monthly[$m] == 0)) ? $monthly[$m] : null; }, range(1, 12))) ?>;

        const chartAccent = (getComputedStyle(document.body).getPropertyValue('--if-primary') || '').trim() || '#007bff';
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
                datasets: [{
                    label: "Tickets Raised",
                    fill: false,
                    /* Reads the live accent off the design layer instead of hard-coding the
                       Bootstrap 4 blue this chart used to carry, which matched no token on this
                       install (accent is red here). Falls back to the old blue only if the token
                       is somehow absent, so the chart can never end up with no colour at all. */
                    borderColor: chartAccent,
                    pointBackgroundColor: chartAccent,
                    pointBorderColor: chartAccent,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: chartAccent,
                    pointBorderWidth: 2,
                    data: dataPoints
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        min: 0,
                        max: <?php
                            /* Was roundUpToNearestMultiple($max). That helper defaults to an
                               increment of 1000 - correct for the currency charts that also call
                               it, wrong here: ANY monthly ticket count from 1 to 1000 snapped the
                               axis to 1000, so a real series rendered as a flat line welded to the
                               x-axis (measured: 0.6px of movement across a 281px plot area).
                               Pick a step from the magnitude of the data instead, so the tallest
                               month always fills most of the plot. */
                            $max = max(1, (int) $largest_ticket_month);
                            $step = 1;
                            foreach ([1, 2, 5, 10, 25, 50, 100, 250, 500, 1000] as $candidate) {
                                $step = $candidate;
                                if ((int) ceil($max / $candidate) <= 5) {
                                    break;
                                }
                            }
                            echo (int) ($step * ceil($max / $step));
                        ?>,
                        ticks: { maxTicksLimit: 5, precision: 0 },
                        grid: { color: "rgba(0, 0, 0, .125)" }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    })();
});
</script>
