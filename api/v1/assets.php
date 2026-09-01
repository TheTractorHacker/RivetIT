<?php
// GET /api/v1/assets         list
// GET /api/v1/assets/{id}    detail
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_support');

// Client-scope restriction, mirroring tickets.php/client_tabs.php.
$asset_client_scope_clause = api_client_scope_sql('a.asset_client_id');

if ($id === null && $sub === 'types') {
    $types = [];
    $sql = mysqli_query($mysqli,
        "SELECT DISTINCT asset_type FROM assets
         WHERE asset_archived_at IS NULL AND asset_type IS NOT NULL AND asset_type != ''
         ORDER BY asset_type ASC"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $types[] = $row['asset_type'];
    }
    api_response(200, $types);
}

if ($id === null && $sub === null) {
    $page      = max(1, intval($_GET['page'] ?? 1));
    $limit     = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    $type      = mysqli_real_escape_string($mysqli, trim($_GET['type'] ?? ''));

    $where = ['a.asset_archived_at IS NULL', $asset_client_scope_clause];
    if ($client_id) $where[] = "a.asset_client_id = $client_id";
    if ($search)    $where[] = "(a.asset_name LIKE '%$search%' OR a.asset_tag LIKE '%$search%' OR a.asset_serial LIKE '%$search%' OR a.asset_make LIKE '%$search%' OR a.asset_model LIKE '%$search%')";
    if ($type)      $where[] = "a.asset_type = '$type'";
    $w = implode(' AND ', $where);

    $total  = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM assets a WHERE $w"))['c']);
    $assets = [];
    $sql    = mysqli_query($mysqli,
        "SELECT a.asset_id, a.asset_name, a.asset_tag, a.asset_type, a.asset_make, a.asset_model, a.asset_serial,
                a.asset_os, a.asset_status, c.client_name
         FROM assets a LEFT JOIN clients c ON a.asset_client_id = c.client_id
         WHERE $w ORDER BY a.asset_name ASC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $assets[] = [
            'id'     => intval($row['asset_id']),
            'name'   => $row['asset_name'],
            'tag'    => $row['asset_tag'],
            'type'   => $row['asset_type'],
            'make'   => $row['asset_make'],
            'model'  => $row['asset_model'],
            'serial' => $row['asset_serial'],
            'os'     => $row['asset_os'],
            'status' => $row['asset_status'],
            'client' => $row['client_name'],
        ];
    }
    api_response(200, ['data' => $assets, 'total' => $total]);
}

// Detail
if ($id === null) api_error(404, 'Not found');
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT a.*, c.client_name, l.location_name, l.location_address,
            l.location_city, l.location_state,
            ct.contact_name, ct.contact_phone
     FROM assets a
     LEFT JOIN clients c ON a.asset_client_id = c.client_id
     LEFT JOIN locations l ON a.asset_location_id = l.location_id
     LEFT JOIN contacts ct ON a.asset_contact_id = ct.contact_id
     WHERE a.asset_id = $id AND a.asset_archived_at IS NULL AND $asset_client_scope_clause LIMIT 1"
));
if (!$row) api_error(404, 'Asset not found');

api_response(200, [
    'id'          => intval($row['asset_id']),
    'name'        => $row['asset_name'],
    'tag'         => $row['asset_tag'] ?? '',
    'type'        => $row['asset_type'],
    'make'        => $row['asset_make'],
    'model'       => $row['asset_model'],
    'serial'      => $row['asset_serial'],
    'os'          => $row['asset_os'],
    'status'      => $row['asset_status'],
    'description'    => $row['asset_description'] ?? '',
    'physical_location' => $row['asset_physical_location'] ?? '',
    'location_name'  => $row['location_name'] ?? '',
    'location_city'  => $row['location_city'] ?? '',
    'location_state' => $row['location_state'] ?? '',
    'contact_name'   => $row['contact_name'] ?? '',
    'contact_phone'  => $row['contact_phone'] ?? '',
    'client'         => $row['client_name'],
    'created_at'     => $row['asset_created_at'],
    'purchase_date'  => $row['asset_purchase_date'] ?? '',
    'warranty_expire'=> $row['asset_warranty_expire'] ?? '',
    'notes'          => $row['asset_notes'] ?? '',
]);
