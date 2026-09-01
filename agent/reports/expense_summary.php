<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_financial');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

$sql_expense_years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(expense_date) AS expense_year FROM expenses WHERE expense_category_id > 0 ORDER BY expense_year DESC");

$sql_categories = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Expense' ORDER BY category_name ASC");

// For chart Y-axis max
$largest_expense_month = 0;

// CSV export: category x month expense matrix (uses the same SQL as the on-page table).
if (!empty($report_export_csv)) {
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $csv_header = array_merge(['Category'], $months, ['Total']);
    $csv_rows = [];

    $sql_cat_export = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Expense' ORDER BY category_name ASC");
    while ($crow = mysqli_fetch_assoc($sql_cat_export)) {
        $cid = intval($crow['category_id']);
        $line = [$crow['category_name']];
        $cat_total = 0;
        for ($m = 1; $m <= 12; $m++) {
            $e = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(expense_amount) AS v FROM expenses WHERE expense_category_id = $cid AND YEAR(expense_date) = $year AND MONTH(expense_date) = $m"));
            $val = round(floatval($e['v']), 2);
            $line[] = $val;
            $cat_total += $val;
        }
        $line[] = round($cat_total, 2);
        $csv_rows[] = $line;
    }

    // Grand total row (all vendor-attributed expenses).
    $total_line = ['Total'];
    $grand = 0;
    for ($m = 1; $m <= 12; $m++) {
        $e = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(expense_amount) AS v FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $m AND expense_vendor_id > 0"));
        $val = round(floatval($e['v']), 2);
        $total_line[] = $val;
        $grand += $val;
    }
    $total_line[] = round($grand, 2);
    $csv_rows[] = $total_line;

    report_send_csv('expense_summary_' . $year . '.csv', $csv_header, $csv_rows);
}

?>

<!-- Responsive chart helpers -->
<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-coins me-2"></i>Expense Summary</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body">
        <form class="mb-3">
            <select class="form-control auto-submit-select" name="year">
                <?php while ($row = mysqli_fetch_assoc($sql_expense_years)) {
                    $expense_year = intval($row['expense_year']); ?>
                    <option <?php if ($year == $expense_year) { ?> selected <?php } ?>><?php echo $expense_year; ?></option>
                <?php } ?>
            </select>
        </form>

        <div class="chart-h-320 mb-3">
            <canvas id="cashFlow"></canvas>
        </div>

        <div class="table-responsive-sm">
            <table class="table table-striped">
                <thead class="text-dark">
                <tr>
                    <th>Category</th>
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
                <?php while ($row = mysqli_fetch_assoc($sql_categories)) {
                    $category_id = intval($row['category_id']);
                    $category_name = nullable_htmlentities($row['category_name']); ?>
                    <tr>
                        <td><?php echo $category_name; ?></td>
                        <?php
                        $total_expense_for_all_months = 0;
                        for ($month = 1; $month <= 12; $month++) {
                            $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE expense_category_id = $category_id AND YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                            $rowm = mysqli_fetch_assoc($sql_expenses);
                            $expense_amount_for_month = floatval($rowm['expense_amount_for_month']);
                            $total_expense_for_all_months += $expense_amount_for_month;
                            ?>
                            <td class="text-end">
                                <a class="text-dark" href="expenses.php?q=<?php echo $category_name; ?>&dtf=<?php echo "$year-$month"; ?>-01&dtt=<?php echo "$year-$month"; ?>-31">
                                    <?php echo numfmt_format_currency($currency_format, $expense_amount_for_month, $session_company_currency); ?>
                                </a>
                            </td>
                        <?php } ?>
                        <th class="text-end">
                            <a class="text-dark" href="expenses.php?q=<?php echo $category_name; ?>&dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                                <?php echo numfmt_format_currency($currency_format, $total_expense_for_all_months, $session_company_currency); ?>
                            </a>
                        </th>
                    </tr>
                <?php } ?>

                <tr>
                    <th>Total</th>
                    <?php
                    $grand_total_all_months = 0;
                    for ($month = 1; $month <= 12; $month++) {
                        $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_total_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month AND expense_vendor_id > 0");
                        $rowt = mysqli_fetch_assoc($sql_expenses);
                        $expense_total_amount_for_month = floatval($rowt['expense_total_amount_for_month']);
                        $grand_total_all_months += $expense_total_amount_for_month;
                        ?>
                        <th class="text-end">
                            <a class="text-dark" href="expenses.php?dtf=<?php echo "$year-$month"; ?>-01&dtt=<?php echo "$year-$month"; ?>-31">
                                <?php echo numfmt_format_currency($currency_format, $expense_total_amount_for_month, $session_company_currency); ?>
                            </a>
                        </th>
                    <?php } ?>
                    <th class="text-end">
                        <a class="text-dark" href="expenses.php?dtf=<?php echo $year; ?>-01-01&dtt=<?php echo $year; ?>-12-31">
                            <?php echo numfmt_format_currency($currency_format, $grand_total_all_months, $session_company_currency); ?>
                        </a>
                    </th>
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
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // EXPENSES LINE CHART
    (function () {
        var ctx = document.getElementById("cashFlow");
        if (!ctx) return;

        var dataPoints = [
            <?php
            // Build series and track the largest month for axis max
            for ($month = 1; $month <= 12; $month++) {
                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month AND expense_vendor_id > 0");
                $rowm = mysqli_fetch_assoc($sql_expenses);
                $expenses_for_month = floatval($rowm['expense_amount_for_month']);

                if ($expenses_for_month > 0 && $expenses_for_month > $largest_expense_month) {
                    $largest_expense_month = $expenses_for_month;
                }
                echo "$expenses_for_month,";
            }
            ?>
        ];

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
                datasets: [{
                    label: "Expense",
                    tension: 0.3, // v4 name (v2: lineTension)
                    fill: false,
                    borderColor: "#dc3545",
                    pointBackgroundColor: "#dc3545",
                    pointBorderColor: "#dc3545",
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: "#dc3545",
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
                            $max = max(1000, $largest_expense_month);
                            echo roundUpToNearestMultiple($max);
                        ?>,
                        ticks: { maxTicksLimit: 5 },
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
