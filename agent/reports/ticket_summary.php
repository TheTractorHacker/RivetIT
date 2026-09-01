<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

$sql_ticket_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(ticket_created_at) AS ticket_year FROM tickets ORDER BY ticket_year DESC");

$sql_tickets = mysqli_query($mysqli, "SELECT ticket_id FROM tickets");

// Track largest month for chart y-axis max
$largest_ticket_month = 0;

// CSV export: tickets raised per month for the selected year (same query as the table).
if (!empty($report_export_csv)) {
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $csv_header = ['Month', 'Tickets raised'];
    $csv_rows = [];
    $year_total = 0;
    for ($m = 1; $m <= 12; $m++) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS c FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $m"));
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
        <form class="p-3">
            <select class="form-control auto-submit-select" name="year">
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

                    $sql_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS tickets_for_month FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month");
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
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    (function () {
        var ctx = document.getElementById("tickets");
        if (!ctx) return;

        var dataPoints = [
            <?php
            // Recompute for the chart dataset (values already gathered above, but we echo directly again)
            for ($month = 1; $month <= 12; $month++) {
                $sql_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS tickets_for_month FROM tickets WHERE YEAR(ticket_created_at) = $year AND MONTH(ticket_created_at) = $month");
                $row = mysqli_fetch_assoc($sql_tickets);
                $tickets_for_month = intval($row['tickets_for_month']);
                echo "$tickets_for_month,";
            }
            ?>
        ];

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
                datasets: [{
                    label: "Tickets Raised",
                    fill: false,
                    borderColor: "#007bff",
                    pointBackgroundColor: "#007bff",
                    pointBorderColor: "#007bff",
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: "#007bff",
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
                            // use your helper if available, otherwise largest_ticket_month as-is
                            $max = max(5, $largest_ticket_month);
                            echo function_exists('roundUpToNearestMultiple') ? roundUpToNearestMultiple($max) : $max;
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
