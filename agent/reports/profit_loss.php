<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_financial');

if (isset($_GET['year'])) {
    $year = intval($_GET['year']);
} else {
    $year = date('Y');
}

// Wave 2: prior-year comparison + net margin + per-client profitability (set-based helpers).
$prior_year   = $year - 1;
$pl_year      = getProfitLossSummary($mysqli, $year);
$pl_prior     = getProfitLossSummary($mysqli, $prior_year);
$client_profit = getClientProfitability($mysqli, $year);

//GET unique years from expenses, payments and revenues
$sql_all_years = mysqli_query($mysqli, "SELECT YEAR(expense_date) AS all_years FROM expenses
    UNION DISTINCT SELECT YEAR(payment_date) FROM payments
    UNION DISTINCT SELECT YEAR(revenue_date) FROM revenues
    ORDER BY all_years DESC"
);

// Fetched into arrays (rather than looped directly as a mysqli result) so an
// "Uncategorized" (category_id = 0) row can be appended below - without it,
// invoices/expenses never assigned a category would be counted in the Gross
// Revenue / Total Expenses headline figures but never appear in any category
// row, so the breakdown could never sum to the total it's supposed to explain.
$income_categories = [];
$sql_categories_income = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Income' ORDER BY category_name ASC");
while ($row = mysqli_fetch_assoc($sql_categories_income)) {
    $income_categories[] = $row;
}
$income_categories[] = ['category_id' => 0, 'category_name' => 'Uncategorized'];

$expense_categories = [];
$sql_categories_expense = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Expense' ORDER BY category_name ASC");
while ($row = mysqli_fetch_assoc($sql_categories_expense)) {
    $expense_categories[] = $row;
}
$expense_categories[] = ['category_id' => 0, 'category_name' => 'Uncategorized'];


?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-balance-scale me-2"></i>Profit & Loss</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
            </div>
        </div>
        <div class="card-body p-0">
            <form class="p-3">
                <select class="form-control auto-submit-select" name="year">
                    <?php

                    while ($row = mysqli_fetch_assoc($sql_all_years)) {
                        $all_years = intval($row['all_years']);
                        ?>
                        <option <?php if ($year == $all_years) { ?> selected <?php } ?> > <?php echo $all_years; ?></option>

                        <?php
                    }
                    ?>

                </select>
            </form>
            <div class="table-responsive-sm">
                <table class="table table-sm">
                    <thead class="text-dark">
                    <tr>
                        <th></th>
                        <th class="text-end">Jan-Mar</th>
                        <th class="text-end">Apr-Jun</th>
                        <th class="text-end">Jul-Sep</th>
                        <th class="text-end">Oct-Dec</th>
                        <th class="text-end">Total</th>
                    </tr>
                    <tr>
                        <th><br><br>Income</th>
                        <th colspan="5"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($income_categories as $row) {
                        $category_id = intval($row['category_id']);
                        $category_name = nullable_htmlentities($row['category_name']);
                        ?>

                        <tr>
                            <td><?php echo $category_name; ?></td>

                            <?php

                            $payment_amount_for_quarter_one = 0;

                            for($month = 1; $month<=3; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND invoice_category_id = $category_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payment_amount_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id = $category_id AND YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenue_amount_for_month = floatval($row['revenue_amount_for_month']);

                                $payment_amount_for_month = $payment_amount_for_month + $revenue_amount_for_month;

                                $payment_amount_for_quarter_one = $payment_amount_for_quarter_one + $payment_amount_for_month;
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_amount_for_quarter_one, $session_company_currency); ?></td>

                            <?php

                            $payment_amount_for_quarter_two = 0;

                            for($month = 4; $month<=6; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND invoice_category_id = $category_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payment_amount_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id = $category_id AND YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenue_amount_for_month = floatval($row['revenue_amount_for_month']);

                                $payment_amount_for_month = $payment_amount_for_month + $revenue_amount_for_month;

                                $payment_amount_for_quarter_two = $payment_amount_for_quarter_two + $payment_amount_for_month;
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_amount_for_quarter_two, $session_company_currency); ?></td>

                            <?php

                            $payment_amount_for_quarter_three = 0;

                            for($month = 7; $month<=9; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND invoice_category_id = $category_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payment_amount_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id = $category_id AND YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenue_amount_for_month = floatval($row['revenue_amount_for_month']);

                                $payment_amount_for_month = $payment_amount_for_month + $revenue_amount_for_month;
                                $payment_amount_for_quarter_three = $payment_amount_for_quarter_three + $payment_amount_for_month;
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_amount_for_quarter_three, $session_company_currency); ?></td>

                            <?php

                            $payment_amount_for_quarter_four = 0;

                            for($month = 10; $month<=12; $month++) {
                                $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND invoice_category_id = $category_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                                $row = mysqli_fetch_assoc($sql_payments);
                                $payment_amount_for_month = floatval($row['payment_amount_for_month']);

                                $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_amount_for_month FROM revenues WHERE revenue_category_id = $category_id AND YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                                $row = mysqli_fetch_assoc($sql_revenues);
                                $revenue_amount_for_month = floatval($row['revenue_amount_for_month']);

                                $payment_amount_for_month = $payment_amount_for_month + $revenue_amount_for_month;
                                $payment_amount_for_quarter_four = $payment_amount_for_quarter_four + $payment_amount_for_month;
                            }

                            $total_payments_for_all_four_quarters = $payment_amount_for_quarter_one + $payment_amount_for_quarter_two + $payment_amount_for_quarter_three + $payment_amount_for_quarter_four;

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_amount_for_quarter_four, $session_company_currency); ?></td>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $total_payments_for_all_four_quarters, $session_company_currency); ?></td>
                        </tr>

                        <?php

                    }

                    ?>

                    <tr>
                        <th>Gross Revenue</th>
                        <?php

                        $payment_total_amount_for_quarter_one = 0;

                        for($month = 1; $month<=3; $month++) {
                            $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_total_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                            $row = mysqli_fetch_assoc($sql_payments);
                            $payment_total_amount_for_month = floatval($row['payment_total_amount_for_month']);

                            $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_total_amount_for_month FROM revenues WHERE YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                            $row = mysqli_fetch_assoc($sql_revenues);
                            $revenue_total_amount_for_month = floatval($row['revenue_total_amount_for_month']);

                            $payment_total_amount_for_month = $payment_total_amount_for_month + $revenue_total_amount_for_month;

                            $payment_total_amount_for_quarter_one = $payment_total_amount_for_quarter_one + $payment_total_amount_for_month;
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_total_amount_for_quarter_one, $session_company_currency); ?></th>

                        <?php

                        $payment_total_amount_for_quarter_two = 0;

                        for($month = 4; $month<=6; $month++) {
                            $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_total_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                            $row = mysqli_fetch_assoc($sql_payments);
                            $payment_total_amount_for_month = floatval($row['payment_total_amount_for_month']);

                            $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_total_amount_for_month FROM revenues WHERE YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                            $row = mysqli_fetch_assoc($sql_revenues);
                            $revenue_total_amount_for_month = floatval($row['revenue_total_amount_for_month']);

                            $payment_total_amount_for_month = $payment_total_amount_for_month + $revenue_total_amount_for_month;

                            $payment_total_amount_for_quarter_two = $payment_total_amount_for_quarter_two + $payment_total_amount_for_month;
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_total_amount_for_quarter_two, $session_company_currency); ?></th>

                        <?php

                        $payment_total_amount_for_quarter_three = 0;

                        for($month = 7; $month<=9; $month++) {
                            $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_total_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                            $row = mysqli_fetch_assoc($sql_payments);
                            $payment_total_amount_for_month = floatval($row['payment_total_amount_for_month']);

                            $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_total_amount_for_month FROM revenues WHERE YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                            $row = mysqli_fetch_assoc($sql_revenues);
                            $revenue_total_amount_for_month = floatval($row['revenue_total_amount_for_month']);

                            $payment_total_amount_for_month = $payment_total_amount_for_month + $revenue_total_amount_for_month;

                            $payment_total_amount_for_quarter_three = $payment_total_amount_for_quarter_three + $payment_total_amount_for_month;
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_total_amount_for_quarter_three, $session_company_currency); ?></th>

                        <?php

                        $payment_total_amount_for_quarter_four = 0;

                        for($month = 10; $month<=12; $month++) {
                            $sql_payments = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS payment_total_amount_for_month FROM payments, invoices WHERE payment_invoice_id = invoice_id AND YEAR(payment_date) = $year AND MONTH(payment_date) = $month");
                            $row = mysqli_fetch_assoc($sql_payments);
                            $payment_total_amount_for_month = floatval($row['payment_total_amount_for_month']);

                            $sql_revenues = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS revenue_total_amount_for_month FROM revenues WHERE YEAR(revenue_date) = $year AND MONTH(revenue_date) = $month");
                            $row = mysqli_fetch_assoc($sql_revenues);
                            $revenue_total_amount_for_month = floatval($row['revenue_total_amount_for_month']);

                            $payment_total_amount_for_month = $payment_total_amount_for_month + $revenue_total_amount_for_month;

                            $payment_total_amount_for_quarter_four = $payment_total_amount_for_quarter_four + $payment_total_amount_for_month;
                        }

                        $total_payments_for_all_four_quarters = $payment_total_amount_for_quarter_one + $payment_total_amount_for_quarter_two + $payment_total_amount_for_quarter_three + $payment_total_amount_for_quarter_four;

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $payment_total_amount_for_quarter_four, $session_company_currency); ?></th>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $total_payments_for_all_four_quarters, $session_company_currency); ?></th>
                    </tr>

                    <tr>
                        <th><br><br>Expenses</th>
                        <th colspan="5"></th>
                    </tr>
                    <?php
                    foreach ($expense_categories as $row) {
                        $category_id = intval($row['category_id']);
                        $category_name = nullable_htmlentities($row['category_name']);
                        ?>

                        <tr>
                            <td><?php echo $category_name; ?></td>

                            <?php

                            $expense_amount_for_quarter_one = 0;

                            for($month = 1; $month<=3; $month++) {
                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE expense_category_id = $category_id AND YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $expense_amount_for_quarter_one = $expense_amount_for_quarter_one + floatval($row['expense_amount_for_month']);
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_amount_for_quarter_one, $session_company_currency); ?></td>

                            <?php

                            $expense_amount_for_quarter_two = 0;

                            for($month = 4; $month<=6; $month++) {
                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE expense_category_id = $category_id AND YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $expense_amount_for_quarter_two = $expense_amount_for_quarter_two + floatval($row['expense_amount_for_month']);
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_amount_for_quarter_two, $session_company_currency); ?></td>

                            <?php

                            $expense_amount_for_quarter_three = 0;

                            for($month = 7; $month<=9; $month++) {
                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE expense_category_id = $category_id AND YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $expense_amount_for_quarter_three = $expense_amount_for_quarter_three + floatval($row['expense_amount_for_month']);
                            }

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_amount_for_quarter_three, $session_company_currency); ?></td>

                            <?php

                            $expense_amount_for_quarter_four = 0;

                            for($month = 10; $month<=12; $month++) {
                                $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_amount_for_month FROM expenses WHERE expense_category_id = $category_id AND YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                                $row = mysqli_fetch_assoc($sql_expenses);
                                $expense_amount_for_quarter_four = $expense_amount_for_quarter_four + floatval($row['expense_amount_for_month']);
                            }

                            $total_expenses_for_all_four_quarters = $expense_amount_for_quarter_one + $expense_amount_for_quarter_two + $expense_amount_for_quarter_three + $expense_amount_for_quarter_four;

                            ?>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_amount_for_quarter_four, $session_company_currency); ?></td>

                            <td class="text-end"><?php echo numfmt_format_currency($currency_format, $total_expenses_for_all_four_quarters, $session_company_currency); ?></td>
                        </tr>

                        <?php

                    }

                    ?>

                    <tr>
                        <th>Total Expenses<br><br><br></th>
                        <?php

                        $expense_total_amount_for_quarter_one = 0;

                        for($month = 1; $month<=3; $month++) {
                            $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_total_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                            $row = mysqli_fetch_assoc($sql_expenses);
                            $expense_total_amount_for_quarter_one = $expense_total_amount_for_quarter_one + floatval($row['expense_total_amount_for_month']);
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_total_amount_for_quarter_one, $session_company_currency); ?></th>

                        <?php

                        $expense_total_amount_for_quarter_two = 0;

                        for($month = 4; $month<=6; $month++) {
                            $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_total_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                            $row = mysqli_fetch_assoc($sql_expenses);
                            $expense_total_amount_for_quarter_two = $expense_total_amount_for_quarter_two + floatval($row['expense_total_amount_for_month']);
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_total_amount_for_quarter_two, $session_company_currency); ?></th>

                        <?php

                        $expense_total_amount_for_quarter_three = 0;

                        for($month = 7; $month<=9; $month++) {
                            $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_total_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                            $row = mysqli_fetch_assoc($sql_expenses);
                            $expense_total_amount_for_quarter_three = $expense_total_amount_for_quarter_three + floatval($row['expense_total_amount_for_month']);
                        }

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_total_amount_for_quarter_three, $session_company_currency); ?></th>

                        <?php

                        $expense_total_amount_for_quarter_four = 0;

                        for($month = 10; $month<=12; $month++) {
                            $sql_expenses = mysqli_query($mysqli, "SELECT SUM(expense_amount) AS expense_total_amount_for_month FROM expenses WHERE YEAR(expense_date) = $year AND MONTH(expense_date) = $month");
                            $row = mysqli_fetch_assoc($sql_expenses);
                            $expense_total_amount_for_quarter_four = $expense_total_amount_for_quarter_four + floatval($row['expense_total_amount_for_month']);
                        }

                        $total_expenses_for_all_four_quarters = $expense_total_amount_for_quarter_one + $expense_total_amount_for_quarter_two + $expense_total_amount_for_quarter_three + $expense_total_amount_for_quarter_four;

                        ?>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $expense_total_amount_for_quarter_four, $session_company_currency); ?></th>

                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $total_expenses_for_all_four_quarters, $session_company_currency); ?></th>
                    </tr>
                    <tr>
                        <?php
                        $net_profit_quarter_one = $payment_total_amount_for_quarter_one - $expense_total_amount_for_quarter_one;
                        $net_profit_quarter_two = $payment_total_amount_for_quarter_two - $expense_total_amount_for_quarter_two;
                        $net_profit_quarter_three = $payment_total_amount_for_quarter_three - $expense_total_amount_for_quarter_three;
                        $net_profit_quarter_four = $payment_total_amount_for_quarter_four - $expense_total_amount_for_quarter_four;
                        $net_profit_year = $total_payments_for_all_four_quarters - $total_expenses_for_all_four_quarters;
                        ?>

                        <th>Net Profit</th>
                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $net_profit_quarter_one, $session_company_currency); ?></th>
                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $net_profit_quarter_two, $session_company_currency); ?></th>
                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $net_profit_quarter_three, $session_company_currency); ?></th>
                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $net_profit_quarter_four, $session_company_currency); ?></th>
                        <th class="text-end"><?php echo numfmt_format_currency($currency_format, $net_profit_year, $session_company_currency); ?></th>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Wave 2: Net margin + prior-year comparison -->
            <div class="px-3 pb-2">
                <h6 class="mt-2"><i class="fas fa-not-equal me-2"></i>Year-over-Year Summary &amp; Net Margin</h6>
                <div class="table-responsive-sm">
                    <table class="table table-sm table-striped">
                        <thead class="text-dark">
                            <tr>
                                <th></th>
                                <th class="text-end"><?php echo intval($year); ?></th>
                                <th class="text-end"><?php echo intval($prior_year); ?> (prior)</th>
                                <th class="text-end">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $yoy_rows = [
                                ['Gross Revenue', $pl_year['revenue_total'], $pl_prior['revenue_total']],
                                ['Total Expenses', $pl_year['expense_total'], $pl_prior['expense_total']],
                                ['Net Profit', $pl_year['net_total'], $pl_prior['net_total']],
                            ];
                            foreach ($yoy_rows as $r) {
                                $change = round($r[1] - $r[2], 2);
                                $change_class = $change >= 0 ? 'text-success' : 'text-danger';
                                ?>
                                <tr>
                                    <td class="text-bold"><?php echo $r[0]; ?></td>
                                    <td class="text-end"><?php echo numfmt_format_currency($currency_format, $r[1], $session_company_currency); ?></td>
                                    <td class="text-end text-muted"><?php echo numfmt_format_currency($currency_format, $r[2], $session_company_currency); ?></td>
                                    <td class="text-end <?php echo $change_class; ?>"><?php echo ($change >= 0 ? '+' : '') . numfmt_format_currency($currency_format, $change, $session_company_currency); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <td class="text-bold">Net Margin</td>
                                <td class="text-end"><?php echo $pl_year['margin_pct'] !== null ? $pl_year['margin_pct'] . '%' : '—'; ?></td>
                                <td class="text-end text-muted"><?php echo $pl_prior['margin_pct'] !== null ? $pl_prior['margin_pct'] . '%' : '—'; ?></td>
                                <td class="text-end">
                                    <?php
                                    if ($pl_year['margin_pct'] !== null && $pl_prior['margin_pct'] !== null) {
                                        $mchg = round($pl_year['margin_pct'] - $pl_prior['margin_pct'], 1);
                                        echo '<span class="' . ($mchg >= 0 ? 'text-success' : 'text-danger') . '">' . ($mchg >= 0 ? '+' : '') . $mchg . ' pts</span>';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Wave 2: quarterly net profit, this year vs prior year -->
            <div class="px-3 pb-3">
                <div class="card card-outline card-secondary mb-0">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Quarterly Net Profit: <?php echo intval($year); ?> vs <?php echo intval($prior_year); ?></h6></div>
                    <div class="card-body">
                        <div style="position:relative;height:300px"><canvas id="plNetProfitChart"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- Wave 2: per-client profitability (revenue vs labor value) -->
            <div class="px-3 pb-3">
                <h6 class="mt-2"><i class="fas fa-user-tie me-2"></i>Client Profitability (<?php echo intval($year); ?>) &mdash; Revenue vs Labor Value</h6>
                <small class="text-muted d-block mb-2">Revenue = payments received this year. Labor value = time logged &times; labor-type rate (billing rate, not internal wage cost).</small>
                <div class="table-responsive-sm">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Labor Value</th>
                                <th class="text-end">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($client_profit['clients'])) { ?>
                                <tr><td colspan="4" class="text-center text-muted">No client revenue or logged labor for <?php echo intval($year); ?>.</td></tr>
                            <?php } else {
                                foreach ($client_profit['clients'] as $cp) {
                                    $m_class = $cp['margin'] >= 0 ? 'text-success' : 'text-danger'; ?>
                                <tr>
                                    <td><a href="../../agent/client_overview.php?client_id=<?php echo intval($cp['client_id']); ?>"><?php echo nullable_htmlentities($cp['client_name']); ?></a></td>
                                    <td class="text-end"><?php echo numfmt_format_currency($currency_format, $cp['revenue'], $session_company_currency); ?></td>
                                    <td class="text-end"><?php echo numfmt_format_currency($currency_format, $cp['labor_value'], $session_company_currency); ?></td>
                                    <td class="text-end <?php echo $m_class; ?>"><?php echo numfmt_format_currency($currency_format, $cp['margin'], $session_company_currency); ?></td>
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

    (function () {
        var ctx = document.getElementById("plNetProfitChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                datasets: [
                    {
                        label: '<?php echo intval($year); ?>',
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0,123,255,0.10)',
                        pointBackgroundColor: '#007bff',
                        fill: true,
                        tension: 0.3,
                        data: <?php echo json_encode($pl_year['net_q']); ?>
                    },
                    {
                        label: '<?php echo intval($prior_year); ?> (prior)',
                        borderColor: '#6c757d',
                        backgroundColor: '#6c757d',
                        pointBackgroundColor: '#6c757d',
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.3,
                        data: <?php echo json_encode($pl_prior['net_q']); ?>
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { ticks: { maxTicksLimit: 6 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: true } }
            }
        });
    })();
});
</script>
