<?php
// GET /api/v1/reports/income-summary?year= — wraps agent/reports/income_summary.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_financial');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

$month_cols = [];
for ($m = 1; $m <= 12; $m++) {
    $month_cols[] = "SUM(CASE WHEN MONTH(x.dt) = $m THEN x.amt ELSE 0 END) AS m$m";
}
$month_cols_sql = implode(",\n        ", $month_cols);

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) income totals - an unrestricted key keeps
// the same company-wide behavior as the classic web report.
$invoices_client_where = !empty($api_key_client_id) ? " WHERE invoices.invoice_client_id = " . intval($api_key_client_id) : '';
$revenues_client_where = !empty($api_key_client_id) ? " WHERE revenue_client_id = " . intval($api_key_client_id) : '';

$sql = mysqli_query($mysqli,
    "SELECT c.category_id, c.category_name,
        $month_cols_sql,
        SUM(x.amt) AS total
     FROM categories c
     LEFT JOIN (
        SELECT invoices.invoice_category_id AS category_id, payments.payment_date AS dt, payments.payment_amount AS amt
        FROM payments JOIN invoices ON payments.payment_invoice_id = invoices.invoice_id$invoices_client_where
        UNION ALL
        SELECT revenue_category_id AS category_id, revenue_date AS dt, revenue_amount AS amt FROM revenues$revenues_client_where
     ) x ON x.category_id = c.category_id AND YEAR(x.dt) = $year
     WHERE c.category_type = 'Income'
     GROUP BY c.category_id, c.category_name
     ORDER BY c.category_name ASC"
);

$categories = [];
$grand_total = 0.0;
while ($row = mysqli_fetch_assoc($sql)) {
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[] = round(floatval($row["m$m"]), 2);
    }
    $total = round(floatval($row['total']), 2);
    $categories[] = [
        'category_id'   => intval($row['category_id']),
        'category_name' => $row['category_name'],
        'months'        => $months,
        'total'         => $total,
    ];
    $grand_total += $total;
}

api_response(200, ['year' => $year, 'categories' => $categories, 'grand_total' => round($grand_total, 2)]);
