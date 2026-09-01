<?php
// GET /api/v1/contacts?client_id=&search=
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_client');

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
$search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$limit     = min(100, max(1, intval($_GET['limit'] ?? 30)));
$offset    = ($page - 1) * $limit;

// Client-scope restriction, mirroring tickets.php/client_tabs.php.
$where = [
    'contact_archived_at IS NULL',
    api_client_scope_sql('contact_client_id'),
];
if ($client_id) $where[] = "contact_client_id = $client_id";
if ($search)    $where[] = "(contact_name LIKE '%$search%' OR contact_email LIKE '%$search%')";
$w = implode(' AND ', $where);

$total    = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM contacts WHERE $w"))['c']);
$contacts = [];
$sql      = mysqli_query($mysqli,
    "SELECT c.contact_id, c.contact_name, c.contact_title, c.contact_email, c.contact_phone,
            c.contact_extension, cl.client_name
     FROM contacts c LEFT JOIN clients cl ON c.contact_client_id = cl.client_id
     WHERE $w ORDER BY c.contact_name ASC LIMIT $limit OFFSET $offset"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $contacts[] = [
        'id'        => intval($row['contact_id']),
        'name'      => $row['contact_name'],
        'title'     => $row['contact_title'],
        'email'     => $row['contact_email'],
        'phone'     => $row['contact_phone'],
        'extension' => $row['contact_extension'],
        'client'    => $row['client_name'],
    ];
}
api_response(200, ['data' => $contacts, 'total' => $total]);
