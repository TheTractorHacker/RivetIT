<?php

require_once "includes/inc_all_reports.php";

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

?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-life-ring me-2"></i>Ticket Summary</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
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
            <table class="table table-striped">
                <thead>
                <tr>
                    <th class="text-end">January</th>
                    <th class="text-end">February</th>
                    <th class="text-end">March</th>
                    <th class="text-end">April</th>
                    <th class="text-end">May</th>
                    <th class="text-end">June</th>
                    <th class="text-end">July</th>
                    <th class="text-end">August</th>
                    <th class="text-end">September</th>
                    <th class="text-end">October</th>
                    <th class="text-end">November</th>
                    <th class="text-end">December</th>
                    <th class="text-end">Total</th>
                </tr>
                </thead>
                <tbody>

                <?php
                $total_tickets_for_all_months = 0;

                for ($month = 1; $month <= 12; $month++) {

                    $sql_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS tickets_for_month FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month" . \ITFlow\Reports\ReportScope::clause('ticket_client_id'));
                    $row = mysqli_fetch_assoc($sql_tickets);
                    $tickets_for_month = intval($row['tickets_for_month']);

                    if ($tickets_for_month > 0 && $tickets_for_month > $largest_ticket_month) {
                        $largest_ticket_month = $tickets_for_month;
                    }

                    $total_tickets_for_all_months += $tickets_for_month; ?>
                    <td class="text-end"><?php echo $tickets_for_month; ?></td>
                <?php } ?>

                <td class="text-end"><b><?php echo $total_tickets_for_all_months; ?></b></td>

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

        var dataPoints = [
            <?php
            // Recompute for the chart dataset (values already gathered above, but we echo directly again)
            for ($month = 1; $month <= 12; $month++) {
                $sql_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS tickets_for_month FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month" . \ITFlow\Reports\ReportScope::clause('ticket_client_id'));
                $row = mysqli_fetch_assoc($sql_tickets);
                $tickets_for_month = intval($row['tickets_for_month']);
                echo "$tickets_for_month,";
            }
            ?>
        ];

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
