<?php
// GET /api/v1/search?q=   global search across tickets, clients, assets
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
$q_raw = trim($_GET['q'] ?? '');
$q = mysqli_real_escape_string($mysqli, $q_raw);
if (strlen($q) < 2) api_error(400, 'Query must be at least 2 characters');

// LIKE pattern bound as a prepared-statement parameter below. Inner %/_ keep
// acting as wildcards, exactly as the previous "LIKE '%$q%'" interpolation did.
$like = '%' . $q_raw . '%';

// Client-scope restriction, mirroring tickets.php/appointments.php, applied per-table below.
$scope_clause_for = fn(string $client_id_col) => api_client_scope_sql($client_id_col);

// Tickets
$tickets = [];
$rows = api_q(
    "SELECT t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
            ts.ticket_status_name, c.client_name
     FROM tickets t
     LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     WHERE t.ticket_archived_at IS NULL
       AND (t.ticket_subject LIKE ? OR t.ticket_number LIKE ?)
       AND " . $scope_clause_for('t.ticket_client_id') . "
     ORDER BY t.ticket_created_at DESC LIMIT 8",
    'ss',
    [$like, $like]
);
foreach ($rows as $row) {
    $tickets[] = [
        'id'      => intval($row['ticket_id']),
        'number'  => intval($row['ticket_number']),
        'subject' => $row['ticket_subject'],
        'status'  => $row['ticket_status_name'],
        'client'  => $row['client_name'],
        'priority'=> $row['ticket_priority'],
    ];
}

// Clients
$clients = [];
$rows = api_q(
    "SELECT c.client_id, c.client_name, l.location_phone
     FROM clients c
     LEFT JOIN locations l ON l.location_client_id = c.client_id AND l.location_primary = 1
     WHERE c.client_archived_at IS NULL AND c.client_name LIKE ?
       AND " . $scope_clause_for('c.client_id') . "
     ORDER BY c.client_name ASC LIMIT 5",
    's',
    [$like]
);
foreach ($rows as $row) {
    $clients[] = [
        'id'    => intval($row['client_id']),
        'name'  => $row['client_name'],
        'phone' => $row['location_phone'],
    ];
}

// Assets
$assets = [];
$rows = api_q(
    "SELECT a.asset_id, a.asset_name, a.asset_tag, a.asset_serial, a.asset_make, a.asset_model, c.client_name
     FROM assets a LEFT JOIN clients c ON a.asset_client_id = c.client_id
     WHERE a.asset_archived_at IS NULL
       AND (a.asset_name LIKE ? OR a.asset_tag LIKE ? OR a.asset_serial LIKE ?
            OR a.asset_make LIKE ? OR a.asset_model LIKE ?)
       AND " . $scope_clause_for('a.asset_client_id') . "
     ORDER BY a.asset_name ASC LIMIT 5",
    'sssss',
    [$like, $like, $like, $like, $like]
);
foreach ($rows as $row) {
    $assets[] = [
        'id'     => intval($row['asset_id']),
        'name'   => $row['asset_name'],
        'tag'    => $row['asset_tag'],
        'serial' => $row['asset_serial'],
        'make'   => $row['asset_make'],
        'model'  => $row['asset_model'],
        'client' => $row['client_name'],
    ];
}

api_response(200, ['tickets' => $tickets, 'clients' => $clients, 'assets' => $assets]);
