<?php
// GET /api/v1/reports/expense-summary?year= — wraps agent/reports/expense_summary.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_financial');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

$month_cols = [];
for ($m = 1; $m <= 12; $m++) {
    $month_cols[] = "SUM(CASE WHEN MONTH(e.expense_date) = $m THEN e.expense_amount ELSE 0 END) AS m$m";
}
$month_cols_sql = implode(",\n        ", $month_cols);

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) expense totals - an unrestricted key keeps
// the same company-wide behavior as the classic web report.
$client_join = !empty($api_key_client_id) ? " AND e.expense_client_id = " . intval($api_key_client_id) : '';

$sql = mysqli_query($mysqli,
    "SELECT c.category_id, c.category_name,
        $month_cols_sql,
        SUM(e.expense_amount) AS total
     FROM categories c
     LEFT JOIN expenses e ON e.expense_category_id = c.category_id AND YEAR(e.expense_date) = $year$client_join
     WHERE c.category_type = 'Expense'
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
