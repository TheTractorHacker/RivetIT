<?php
// GET /api/v1/reports/profit-loss?year= — wraps agent/reports/profit_loss.php (monthly, not quarterly)
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_financial');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) P&L - an unrestricted key keeps the same
// company-wide behavior as the classic web report.
$invoices_client_where = !empty($api_key_client_id) ? " AND invoices.invoice_client_id = " . intval($api_key_client_id) : '';
$revenues_client_where = !empty($api_key_client_id) ? " AND revenue_client_id = " . intval($api_key_client_id) : '';
$expense_client_where  = !empty($api_key_client_id) ? " AND expense_client_id = " . intval($api_key_client_id) : '';

$income = array_fill(1, 12, 0.0);
$sql_income = mysqli_query($mysqli,
    "SELECT MONTH(x.dt) AS m, SUM(x.amt) AS amt FROM (
        SELECT payments.payment_date AS dt, payments.payment_amount AS amt
        FROM payments JOIN invoices ON payments.payment_invoice_id = invoices.invoice_id
        WHERE 1=1$invoices_client_where
        UNION ALL
        SELECT revenue_date AS dt, revenue_amount AS amt FROM revenues WHERE revenue_category_id > 0$revenues_client_where
     ) x
     WHERE YEAR(x.dt) = $year
     GROUP BY MONTH(x.dt)"
);
while ($row = mysqli_fetch_assoc($sql_income)) {
    $income[intval($row['m'])] = round(floatval($row['amt']), 2);
}

$expense = array_fill(1, 12, 0.0);
$sql_expense = mysqli_query($mysqli,
    "SELECT MONTH(expense_date) AS m, SUM(expense_amount) AS amt
     FROM expenses
     WHERE YEAR(expense_date) = $year AND expense_vendor_id > 0$expense_client_where
     GROUP BY MONTH(expense_date)"
);
while ($row = mysqli_fetch_assoc($sql_expense)) {
    $expense[intval($row['m'])] = round(floatval($row['amt']), 2);
}

$months = [];
for ($m = 1; $m <= 12; $m++) {
    $months[] = [
        'month'   => $m,
        'income'  => $income[$m],
        'expense' => $expense[$m],
        'profit'  => round($income[$m] - $expense[$m], 2),
    ];
}

api_response(200, ['year' => $year, 'months' => $months]);
