<?php
// GET  /api/v1/notifications               list unread
// POST /api/v1/notifications/{id}/read     mark read
// POST /api/v1/notifications/read-all      mark all read
defined('FROM_API') || die();

$uid = $api_user_id;

if ($method === 'GET') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM notifications
         WHERE (notification_user_id = $uid OR notification_user_id = 0)
         AND notification_dismissed_at IS NULL"))['c']);

    $notifs = [];
    $sql    = mysqli_query($mysqli,
        "SELECT * FROM notifications
         WHERE (notification_user_id = $uid OR notification_user_id = 0)
         AND notification_dismissed_at IS NULL
         ORDER BY notification_timestamp DESC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $notifs[] = [
            'id'        => intval($row['notification_id']),
            'type'      => $row['notification_type'],
            'message'   => $row['notification'],
            'action'    => $row['notification_action'],
            'timestamp' => $row['notification_timestamp'],
        ];
    }
    api_response(200, ['data' => $notifs, 'total' => $total]);
}

if ($method === 'POST') {
    if ($sub === 'read-all' || (isset($segments[1]) && $segments[1] === 'read-all')) {
        mysqli_query($mysqli,
            "UPDATE notifications SET notification_dismissed_at = NOW(), notification_dismissed_by = $uid
             WHERE (notification_user_id = $uid OR notification_user_id = 0)
             AND notification_dismissed_at IS NULL"
        );
        api_response(200, ['ok' => true]);
    }

    if ($id !== null && $sub === 'read') {
        mysqli_query($mysqli,
            "UPDATE notifications SET notification_dismissed_at = NOW(), notification_dismissed_by = $uid
             WHERE notification_id = $id AND (notification_user_id = $uid OR notification_user_id = 0)"
        );
        api_response(200, ['ok' => true]);
    }
}

api_error(404, 'Not found');
