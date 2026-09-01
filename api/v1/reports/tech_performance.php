<?php
// GET /api/v1/reports/tech-performance?year= — combines dashboard's open-workload + resolved-by-tech queries
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) technician workload - an unrestricted key
// keeps the same company-wide behavior as before.
$client_where = !empty($api_key_client_id) ? " AND ticket_client_id = " . intval($api_key_client_id) : '';

$techs = [];

$sql_open = mysqli_query($mysqli,
    "SELECT user_name, COUNT(ticket_id) AS c
     FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id
     WHERE ticket_closed_at IS NULL AND ticket_assigned_to > 0$client_where
     GROUP BY user_name
     ORDER BY c DESC
     LIMIT 8"
);
while ($row = mysqli_fetch_assoc($sql_open)) {
    $name = $row['user_name'];
    if (!isset($techs[$name])) $techs[$name] = ['name' => $name, 'open_tickets' => 0, 'resolved_this_year' => 0];
    $techs[$name]['open_tickets'] = intval($row['c']);
}

$sql_resolved = mysqli_query($mysqli,
    "SELECT user_name, COUNT(ticket_id) AS c
     FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id
     WHERE ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = $year AND ticket_assigned_to > 0$client_where
     GROUP BY user_name
     ORDER BY c DESC
     LIMIT 8"
);
while ($row = mysqli_fetch_assoc($sql_resolved)) {
    $name = $row['user_name'];
    if (!isset($techs[$name])) $techs[$name] = ['name' => $name, 'open_tickets' => 0, 'resolved_this_year' => 0];
    $techs[$name]['resolved_this_year'] = intval($row['c']);
}

api_response(200, ['year' => $year, 'technicians' => array_values($techs)]);
