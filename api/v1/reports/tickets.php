<?php
// GET /api/v1/reports/tickets?year= — ticket volume by month (wraps agent/reports/ticket_summary.php)
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) ticket volume - an unrestricted key keeps
// the same company-wide behavior as before.
$client_where = !empty($api_key_client_id) ? " AND ticket_client_id = " . intval($api_key_client_id) : '';

$counts = array_fill(1, 12, 0);
$sql = mysqli_query($mysqli,
    "SELECT MONTH(ticket_created_at) AS m, COUNT(ticket_id) AS c
     FROM tickets
     WHERE YEAR(ticket_created_at) = $year$client_where
     GROUP BY MONTH(ticket_created_at)"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $counts[intval($row['m'])] = intval($row['c']);
}

$months = [];
for ($m = 1; $m <= 12; $m++) {
    $months[] = ['month' => $m, 'count' => $counts[$m]];
}

api_response(200, ['year' => $year, 'months' => $months]);
