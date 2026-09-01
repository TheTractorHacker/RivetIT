<?php
// GET /api/v1/ticket-views   list saved ticket views, translated to /api/v1/tickets-compatible params
defined('FROM_API') || die();
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;

$views = [];
$sql = mysqli_query($mysqli,
    "SELECT ticket_saved_view_id, ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query
     FROM ticket_saved_views
     WHERE (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $uid)
     AND ticket_saved_view_archived_at IS NULL
     ORDER BY ticket_saved_view_user_id ASC, ticket_saved_view_order ASC"
);
while ($row = mysqli_fetch_assoc($sql)) {
    parse_str($row['ticket_saved_view_query'], $q);

    // All values are strings so the Android client can deserialize this as Map<String, String>
    $params = [];
    if (isset($q['status'])) {
        $params['status'] = $q['status'] === 'Closed' ? 'closed' : 'open';
    }
    if (isset($q['assigned']) && $q['assigned'] === 'me') {
        $params['mine'] = '1';
    }
    if (isset($q['onsite']) && $q['onsite'] !== '') {
        $params['onsite'] = (string) intval($q['onsite']);
    }
    if (isset($q['priority']) && $q['priority'] !== '') {
        $params['priority'] = strtolower($q['priority']);
    }
    if (isset($q['category']) && $q['category'] !== '') {
        $params['category_id'] = (string) intval($q['category']);
    }
    if (isset($q['search']) && $q['search'] !== '') {
        $params['search'] = (string) $q['search'];
    }
    if (isset($q['overdue'])) $params['overdue'] = '1';
    if (isset($q['due_today'])) $params['due_today'] = '1';

    $views[] = [
        'id'     => intval($row['ticket_saved_view_id']),
        'name'   => $row['ticket_saved_view_name'],
        'icon'   => $row['ticket_saved_view_icon'],
        'params' => $params,
    ];
}

api_response(200, $views);
