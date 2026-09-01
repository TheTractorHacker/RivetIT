<?php
// GET /api/v1/reports/time-by-tech?year= — wraps agent/reports/time_by_tech.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) time totals - an unrestricted key keeps
// the same company-wide behavior as before. ticket_replies carries no client column of its
// own, so it's joined to tickets only when scoping is active.
$client_where        = !empty($api_key_client_id) ? " AND ticket_client_id = " . intval($api_key_client_id) : '';
$client_join_replies = !empty($api_key_client_id) ? " JOIN tickets trt ON trt.ticket_id = ticket_replies.ticket_reply_ticket_id AND trt.ticket_client_id = " . intval($api_key_client_id) : '';

$users = [];
$sql_users = mysqli_query($mysqli,
    "SELECT user_id, user_name FROM users
     WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
     ORDER BY user_name ASC"
);
while ($row = mysqli_fetch_assoc($sql_users)) {
    $users[intval($row['user_id'])] = [
        'user_id'          => intval($row['user_id']),
        'name'             => $row['user_name'],
        'tickets_assigned' => 0,
        'tickets_touched'  => 0,
        'seconds_worked'   => 0,
    ];
}

$sql_assigned = mysqli_query($mysqli,
    "SELECT ticket_assigned_to AS uid, COUNT(ticket_id) AS c
     FROM tickets
     WHERE YEAR(ticket_created_at) = $year AND ticket_assigned_to > 0$client_where
     GROUP BY ticket_assigned_to"
);
while ($row = mysqli_fetch_assoc($sql_assigned)) {
    $uid = intval($row['uid']);
    if (isset($users[$uid])) $users[$uid]['tickets_assigned'] = intval($row['c']);
}

$sql_touched = mysqli_query($mysqli,
    "SELECT uid, COUNT(DISTINCT ticket_id) AS c FROM (
        SELECT ticket_reply_by AS uid, ticket_reply_ticket_id AS ticket_id
        FROM ticket_replies$client_join_replies
        WHERE YEAR(ticket_reply_created_at) = $year
        UNION
        SELECT ticket_created_by AS uid, ticket_id FROM tickets WHERE YEAR(ticket_created_at) = $year$client_where
        UNION
        SELECT ticket_closed_by AS uid, ticket_id FROM tickets
        WHERE YEAR(ticket_created_at) = $year AND ticket_closed_by IS NOT NULL$client_where
     ) touched
     GROUP BY uid"
);
while ($row = mysqli_fetch_assoc($sql_touched)) {
    $uid = intval($row['uid']);
    if (isset($users[$uid])) $users[$uid]['tickets_touched'] = intval($row['c']);
}

$sql_time = mysqli_query($mysqli,
    "SELECT tr.ticket_reply_by AS uid, SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) AS secs
     FROM ticket_replies tr
     JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
     WHERE YEAR(t.ticket_created_at) = $year AND tr.ticket_reply_time_worked IS NOT NULL$client_where
     GROUP BY tr.ticket_reply_by"
);
while ($row = mysqli_fetch_assoc($sql_time)) {
    $uid = intval($row['uid']);
    if (isset($users[$uid])) $users[$uid]['seconds_worked'] = intval($row['secs']);
}

api_response(200, ['year' => $year, 'technicians' => array_values($users)]);
