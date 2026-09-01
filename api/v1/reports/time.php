<?php
// GET /api/v1/reports/time?period=week|month|all&mine=1
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$uid    = $api_user_id;
$period = $_GET['period'] ?? 'week';
$mine   = intval($_GET['mine'] ?? 0);

$date_filter = match($period) {
    'week'  => "AND DATE(r.ticket_reply_created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    'month' => "AND DATE(r.ticket_reply_created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    default => "",
};
$mine_filter = $mine ? "AND r.ticket_reply_by = $uid" : "";

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) time entries - an unrestricted key keeps
// the same company-wide behavior as before.
$client_filter = !empty($api_key_client_id) ? " AND t.ticket_client_id = " . intval($api_key_client_id) : '';

$sql = mysqli_query($mysqli,
    "SELECT c.client_name, c.client_id,
            SUM(
                TIME_TO_SEC(IFNULL(r.ticket_reply_time_worked, '0:0:0')) / 3600
            ) AS total_hours,
            COUNT(DISTINCT t.ticket_id) AS ticket_count
     FROM ticket_replies r
     JOIN tickets t ON r.ticket_reply_ticket_id = t.ticket_id
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     WHERE r.ticket_reply_time_worked IS NOT NULL
       AND r.ticket_reply_time_worked != ''
       AND r.ticket_reply_archived_at IS NULL
       $date_filter $mine_filter$client_filter
     GROUP BY c.client_id, c.client_name
     ORDER BY total_hours DESC
     LIMIT 50"
);

$entries = [];
$total   = 0;
while ($row = mysqli_fetch_assoc($sql)) {
    $h = round(floatval($row['total_hours']), 2);
    $entries[] = [
        'client'       => $row['client_name'] ?? 'No Client',
        'client_id'    => intval($row['client_id'] ?? 0),
        'hours'        => $h,
        'ticket_count' => intval($row['ticket_count']),
    ];
    $total += $h;
}

api_response(200, ['period' => $period, 'total_hours' => round($total, 2), 'entries' => $entries]);
