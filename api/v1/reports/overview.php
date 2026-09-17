<?php
// GET /api/v1/reports/overview?year= — combines dashboard's by-priority/status/category + avg resolution time
// New endpoint — no equivalent standalone web report; ports agent/dashboard.php's inline queries.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) ticket overview - an unrestricted key
// keeps the same company-wide behavior as the classic web dashboard.
$client_where = !empty($api_key_client_id) ? " AND ticket_client_id = " . intval($api_key_client_id) : '';

$by_priority = [];
$sql = mysqli_query($mysqli,
    "SELECT ticket_priority, COUNT(ticket_id) AS c FROM tickets
     WHERE ticket_closed_at IS NULL$client_where
     GROUP BY ticket_priority
     ORDER BY FIELD(ticket_priority, 'High', 'Medium', 'Low')"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $by_priority[] = ['priority' => $row['ticket_priority'], 'count' => intval($row['c'])];
}

$by_status = [];
$sql = mysqli_query($mysqli,
    "SELECT ticket_status_name, ticket_status_color, COUNT(ticket_id) AS c
     FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
     WHERE ticket_closed_at IS NULL$client_where
     GROUP BY ticket_status_name, ticket_status_color
     ORDER BY c DESC
     LIMIT 8"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $by_status[] = [
        'status' => $row['ticket_status_name'],
        'color'  => $row['ticket_status_color'] ?: '#6c757d',
        'count'  => intval($row['c']),
    ];
}

$by_category = [];
$sql = mysqli_query($mysqli,
    "SELECT COALESCE(category_name, 'Uncategorized') AS cat, category_color, COUNT(ticket_id) AS c
     FROM tickets LEFT JOIN categories ON ticket_category = category_id
     WHERE ticket_closed_at IS NULL$client_where
     GROUP BY cat, category_color
     ORDER BY c DESC
     LIMIT 8"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $by_category[] = [
        'category' => $row['cat'],
        'color'    => $row['category_color'] ?: '#6c757d',
        'count'    => intval($row['c']),
    ];
}

$avg_resolution_hours_raw = getAvgResolutionTimeHours($mysqli, $year, $client_where);
$avg_resolution_hours = $avg_resolution_hours_raw > 0 ? $avg_resolution_hours_raw : null;

api_response(200, [
    'year'                  => $year,
    'by_priority'           => $by_priority,
    'by_status'             => $by_status,
    'by_category'           => $by_category,
    'avg_resolution_hours'  => $avg_resolution_hours,
]);
