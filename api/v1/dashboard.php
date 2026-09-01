<?php
// GET /api/v1/dashboard
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;

$my_open = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM tickets
     WHERE ticket_assigned_to = $uid AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL"))['c'];

$all_open = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM tickets
     WHERE ticket_resolved_at IS NULL AND ticket_archived_at IS NULL
     AND " . api_client_scope_sql('ticket_client_id')))['c'];

$unread = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM notifications
     WHERE (notification_user_id = $uid OR notification_user_id = 0)
     AND notification_dismissed_at IS NULL"))['c'];

$overdue = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM tickets
     WHERE ticket_due_at < NOW() AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL
     AND " . api_client_scope_sql('ticket_client_id')))['c'];

$due_today = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM tickets
     WHERE ticket_due_at IS NOT NULL AND DATE(ticket_due_at) = CURDATE()
     AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL
     AND " . api_client_scope_sql('ticket_client_id')))['c'];

$onsite_open = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS c FROM tickets
     WHERE ticket_onsite = 1 AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL
     AND " . api_client_scope_sql('ticket_client_id')))['c'];

// My queue - recent open tickets assigned to me
$queue = [];
$sql = mysqli_query($mysqli,
    "SELECT t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
            t.ticket_status, t.ticket_created_at, t.ticket_due_at,
            c.client_name, ts.ticket_status_name, ts.ticket_status_color
     FROM tickets t
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
     WHERE t.ticket_assigned_to = $uid AND t.ticket_resolved_at IS NULL AND t.ticket_archived_at IS NULL
     ORDER BY t.ticket_due_at ASC, t.ticket_created_at DESC
     LIMIT 10"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $queue[] = [
        'id'          => intval($row['ticket_id']),
        'number'      => intval($row['ticket_number']),
        'subject'     => $row['ticket_subject'],
        'priority'    => $row['ticket_priority'],
        'status'      => $row['ticket_status_name'],
        'status_color'=> $row['ticket_status_color'],
        'client'      => $row['client_name'],
        'created_at'  => $row['ticket_created_at'],
        'due_at'      => $row['ticket_due_at'],
    ];
}

api_response(200, [
    'my_open'     => intval($my_open),
    'all_open'    => intval($all_open),
    'unread'      => intval($unread),
    'overdue'     => intval($overdue),
    'due_today'   => intval($due_today),
    'onsite_open' => intval($onsite_open),
    'queue'       => $queue,
]);
