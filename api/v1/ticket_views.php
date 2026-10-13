<?php
// GET /api/v1/ticket-views   list saved ticket views, translated to /api/v1/tickets-compatible params
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

// Saved ticket views are part of Tickets (roles audit P1g): an Assets-only or Sales-only token gets 403.
api_require_module_permission($mysqli, intval($api_user_id), 'module_support');

$uid = $api_user_id;

require_once __DIR__ . '/../../agent/includes/ticket_view_filters.php';

// Which view opens by default for this user (their own, else the shared one), if the update that adds default views has run.
$default_ids = ticketViewDefaultIds($mysqli, intval($uid));
$effective_default = $default_ids['personal'] ?: $default_ids['shared'];
$effective_scope = $default_ids['personal'] ? 'personal' : ($default_ids['shared'] ? 'shared' : null);

$views = [];
$sql = mysqli_query($mysqli,
    "SELECT ticket_saved_view_id, ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query, ticket_saved_view_user_id
     FROM ticket_saved_views
     WHERE (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $uid)
     AND ticket_saved_view_archived_at IS NULL
     ORDER BY ticket_saved_view_user_id ASC, ticket_saved_view_order ASC"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $q = ticketViewParseQuery((string) $row['ticket_saved_view_query']);
    // The multi-value filters (a view may hold several priorities, agents, boards...), read the same way the web page reads them.
    $sets = ticketFilterSets($q, 0);
    $assigned_tokens = [];
    foreach (ticketFilterTokens($q['assigned'] ?? ($q['assigned_to'] ?? null)) as $t) {
        $low = strtolower($t);
        if ($low === 'me' || $low === 'unassigned') $assigned_tokens[$low] = $low;
        elseif (ctype_digit($t)) $assigned_tokens[$t === '0' ? 'unassigned' : $t] = $t === '0' ? 'unassigned' : (string) (int) $t;
    }

    // All values are strings so the Android client can deserialize this as Map<String, String>; lists are comma-separated.
    $params = [];
    if (isset($q['status'])) {
        $params['status'] = $q['status'] === 'Closed' ? 'closed' : 'open';
    }
    if ($assigned_tokens === ['me' => 'me']) {
        $params['mine'] = '1';   // the original single-value form, which older app versions understand
    } elseif ($assigned_tokens) {
        $params['assigned'] = implode(',', $assigned_tokens);
    }
    if ($sets['onsite']) {
        $params['onsite'] = implode(',', array_map('strval', $sets['onsite']));
    }
    if ($sets['priority']) {
        $params['priority'] = strtolower(implode(',', $sets['priority']));
    }
    if ($sets['category']) {
        $params['category_id'] = implode(',', array_map('strval', $sets['category']));
    }
    if ($sets['board']) {
        $params['board'] = implode(',', array_map('strval', $sets['board']));
    }
    if (isset($q['search']) && is_string($q['search']) && $q['search'] !== '') {
        $params['search'] = $q['search'];
    }
    if ($sets['overdue']) $params['overdue'] = '1';
    if ($sets['due_today']) $params['due_today'] = '1';

    $view_id = intval($row['ticket_saved_view_id']);
    $views[] = [
        'id'      => $view_id,
        'name'    => $row['ticket_saved_view_name'],
        'icon'    => $row['ticket_saved_view_icon'],
        'params'  => $params,
        'shared'  => intval($row['ticket_saved_view_user_id']) === 0,
        'is_default'    => $effective_default === $view_id,
        'default_scope' => $effective_default === $view_id ? $effective_scope : null,
    ];
}

api_response(200, $views);
