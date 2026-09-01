<?php
// GET /api/v1/reports/tickets-by-client?year=&month= — wraps agent/reports/ticket_by_client.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$year  = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$month_filter = $month ? "AND MONTH(t.ticket_created_at) = $month" : "";

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) ticket breakdown - an unrestricted key
// keeps the same company-wide behavior as before.
$client_filter = !empty($api_key_client_id) ? " AND c.client_id = " . intval($api_key_client_id) : '';

$sql = mysqli_query($mysqli,
    "SELECT
        c.client_id, c.client_name,
        COUNT(t.ticket_id) AS raised,
        SUM(CASE WHEN t.ticket_resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN t.ticket_priority = 'Low' THEN 1 ELSE 0 END) AS priority_low,
        SUM(CASE WHEN t.ticket_priority = 'Medium' THEN 1 ELSE 0 END) AS priority_medium,
        SUM(CASE WHEN t.ticket_priority = 'High' THEN 1 ELSE 0 END) AS priority_high,
        AVG(CASE WHEN t.ticket_first_response_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_first_response_at) END) AS avg_response_seconds,
        AVG(CASE WHEN t.ticket_resolved_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_resolved_at) END) AS avg_resolve_seconds,
        COALESCE(tw.seconds_worked, 0) AS seconds_worked
     FROM clients c
     JOIN tickets t ON t.ticket_client_id = c.client_id
        AND YEAR(t.ticket_created_at) = $year $month_filter
     LEFT JOIN (
        SELECT tt.ticket_client_id AS client_id, SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) AS seconds_worked
        FROM ticket_replies tr
        JOIN tickets tt ON tt.ticket_id = tr.ticket_reply_ticket_id
        WHERE YEAR(tt.ticket_created_at) = $year $month_filter
          AND tr.ticket_reply_time_worked IS NOT NULL
        GROUP BY tt.ticket_client_id
     ) tw ON tw.client_id = c.client_id
     WHERE c.client_archived_at IS NULL$client_filter
     GROUP BY c.client_id, c.client_name, tw.seconds_worked
     HAVING raised > 0
     ORDER BY raised DESC"
);

$clients = [];
while ($row = mysqli_fetch_assoc($sql)) {
    $clients[] = [
        'client_id'            => intval($row['client_id']),
        'client'               => $row['client_name'],
        'raised'               => intval($row['raised']),
        'resolved'             => intval($row['resolved']),
        'priority_low'         => intval($row['priority_low']),
        'priority_medium'      => intval($row['priority_medium']),
        'priority_high'        => intval($row['priority_high']),
        'seconds_worked'       => intval($row['seconds_worked']),
        'avg_response_seconds' => $row['avg_response_seconds'] !== null ? intval($row['avg_response_seconds']) : null,
        'avg_resolve_seconds'  => $row['avg_resolve_seconds'] !== null ? intval($row['avg_resolve_seconds']) : null,
    ];
}

api_response(200, ['year' => $year, 'month' => $month, 'clients' => $clients]);
