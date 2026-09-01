<?php
// GET /api/v1/clients       list
// GET /api/v1/clients/{id}  detail + contacts + primary location
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_client');

// Client-scope restriction, mirroring tickets.php/appointments.php: agents with rows in
// user_client_permissions may only see/access clients in their permitted set.
$client_scope_clause = api_client_scope_sql('c.client_id');

if ($id === null) {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(100, max(1, intval($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;
    $search = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');

    $where = ['c.client_archived_at IS NULL', $client_scope_clause];
    if ($search) $where[] = "c.client_name LIKE '%$search%'";
    $w = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS cnt FROM clients c WHERE $w"))['cnt']);

    $clients = [];
    $sql = mysqli_query($mysqli,
        "SELECT c.client_id, c.client_name, c.client_website,
                l.location_phone, l.location_city, l.location_state
         FROM clients c
         LEFT JOIN locations l ON l.location_client_id = c.client_id AND l.location_primary = 1
         WHERE $w ORDER BY c.client_name ASC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $clients[] = [
            'id'      => intval($row['client_id']),
            'name'    => $row['client_name'],
            'phone'   => $row['location_phone'],
            'city'    => $row['location_city'],
            'state'   => $row['location_state'],
            'website' => $row['client_website'],
        ];
    }
    api_response(200, ['data' => $clients, 'total' => $total]);
}

// Detail
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT c.*, l.location_address, l.location_city, l.location_state,
             l.location_zip, l.location_phone
     FROM clients c
     LEFT JOIN locations l ON l.location_client_id = c.client_id AND l.location_primary = 1
     WHERE c.client_id = $id AND c.client_archived_at IS NULL AND $client_scope_clause LIMIT 1"
));
if (!$row) api_error(404, 'Client not found');

$contacts = [];
$csql = mysqli_query($mysqli,
    "SELECT contact_id, contact_name, contact_title, contact_email,
            contact_phone, contact_extension, contact_mobile
     FROM contacts
     WHERE contact_client_id = $id AND contact_archived_at IS NULL
     ORDER BY contact_primary DESC, contact_name ASC"
);
while ($c = mysqli_fetch_assoc($csql)) {
    $contacts[] = [
        'id'        => intval($c['contact_id']),
        'name'      => $c['contact_name'],
        'title'     => $c['contact_title'],
        'email'     => $c['contact_email'],
        'phone'     => $c['contact_phone'] ?: $c['contact_mobile'],
        'extension' => $c['contact_extension'],
    ];
}

$open_tickets = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) AS cnt FROM tickets
     WHERE ticket_client_id = $id AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL"))['cnt']);

api_response(200, [
    'id'           => intval($row['client_id']),
    'name'         => $row['client_name'],
    'phone'        => $row['location_phone'],
    'address'      => $row['location_address'],
    'city'         => $row['location_city'],
    'state'        => $row['location_state'],
    'zip'          => $row['location_zip'],
    'website'      => $row['client_website'],
    'notes'        => $row['client_notes'] ?? '',
    'open_tickets' => $open_tickets,
    'contacts'     => $contacts,
]);
