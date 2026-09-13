<?php
// GET /api/v1/contracts         list
// GET /api/v1/contracts/{id}    detail (includes SLA, hours-allowance usage, documents)
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
// Billing-adjacent client data, not support-ticket data - same gate as the existing
// /clients/{id}/contracts and /clients/{id}/allowance sub-resources in client_tabs.php.
api_require_module_permission($mysqli, $uid, 'module_client');

$contract_client_scope_clause = api_client_scope_sql('c.contract_client_id');

if ($id !== null) {
    $contract_access_check = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT c.contract_id FROM contracts c WHERE c.contract_id = $id AND $contract_client_scope_clause LIMIT 1"
    ));
    if (!$contract_access_check) api_error(403, 'Access denied');
}

if ($id === null) {
    $page      = max(1, intval($_GET['page'] ?? 1));
    $limit     = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
    $status    = mysqli_real_escape_string($mysqli, $_GET['status'] ?? '');
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
    $expiring  = isset($_GET['expiring']) ? intval($_GET['expiring']) : 0;
    $today     = date('Y-m-d');
    $warn_date = date('Y-m-d', strtotime('+45 days'));

    $where = ['c.contract_archived_at IS NULL', $contract_client_scope_clause];
    if ($client_id) $where[] = "c.contract_client_id = $client_id";
    if ($status)    $where[] = "c.contract_status = '$status'";
    if ($expiring)  $where[] = "c.contract_renewal_date IS NOT NULL AND c.contract_renewal_date <= '$warn_date'";
    if ($search)    $where[] = "(c.contract_name LIKE '%$search%' OR cl.client_name LIKE '%$search%' OR c.contract_type LIKE '%$search%')";
    $where_sql = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM contracts c LEFT JOIN clients cl ON cl.client_id = c.contract_client_id WHERE $where_sql"))['c']);

    $contracts = [];
    $sql = mysqli_query($mysqli,
        "SELECT c.contract_id, c.contract_name, c.contract_type, c.contract_status, c.contract_value,
                c.contract_renewal_frequency, c.contract_start_date, c.contract_end_date, c.contract_renewal_date,
                c.contract_sla_high_response_time, c.contract_sla_medium_response_time, c.contract_sla_low_response_time,
                c.contract_support_hours_included_remote, c.contract_support_hours_included_onsite,
                cl.client_name
         FROM contracts c
         LEFT JOIN clients cl ON cl.client_id = c.contract_client_id
         WHERE $where_sql
         ORDER BY c.contract_renewal_date IS NULL, c.contract_renewal_date ASC
         LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $renewal = $row['contract_renewal_date'];
        $contracts[] = [
            'id'                => intval($row['contract_id']),
            'name'              => $row['contract_name'],
            'type'              => $row['contract_type'],
            'status'            => $row['contract_status'],
            'client'            => $row['client_name'],
            'value'             => $row['contract_value'] !== null ? floatval($row['contract_value']) : null,
            'renewal_frequency' => $row['contract_renewal_frequency'],
            'start_date'        => $row['contract_start_date'],
            'end_date'          => $row['contract_end_date'],
            'renewal_date'      => $renewal,
            'is_expired'        => $renewal !== null && $renewal < $today,
            'is_due_soon'       => $renewal !== null && $renewal >= $today && $renewal <= $warn_date,
            'has_sla'           => !empty($row['contract_sla_high_response_time']) || !empty($row['contract_sla_medium_response_time']) || !empty($row['contract_sla_low_response_time']),
            'has_allowance'     => $row['contract_support_hours_included_remote'] !== null || $row['contract_support_hours_included_onsite'] !== null,
        ];
    }
    api_response(200, ['data' => $contracts, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

// Detail
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT c.*, cl.client_name
     FROM contracts c
     LEFT JOIN clients cl ON cl.client_id = c.contract_client_id
     WHERE c.contract_id = $id LIMIT 1"
));
if (!$row) api_error(404, 'Contract not found');

$today     = date('Y-m-d');
$warn_date = date('Y-m-d', strtotime('+45 days'));
$renewal   = $row['contract_renewal_date'];

$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$year  = isset($_GET['year']) ? intval($_GET['year']) : null;
$allowance = getContractIncludedIssuesUsage($mysqli, $id, $month, $year);

$documents = [];
$sql = mysqli_query($mysqli,
    "SELECT doc_id, doc_original_name, doc_filename, doc_mime_type, doc_size, doc_uploaded_at
     FROM contract_documents WHERE doc_contract_id = $id ORDER BY doc_uploaded_at DESC"
);
while ($d = mysqli_fetch_assoc($sql)) {
    $documents[] = [
        'id'          => intval($d['doc_id']),
        'name'        => $d['doc_original_name'],
        'mime_type'   => $d['doc_mime_type'],
        'size'        => intval($d['doc_size']),
        'uploaded_at' => $d['doc_uploaded_at'],
        'url'         => "/uploads/contracts/$id/{$d['doc_filename']}",
    ];
}

api_response(200, [
    'id'                => intval($row['contract_id']),
    'name'              => $row['contract_name'],
    'type'              => $row['contract_type'],
    'status'            => $row['contract_status'],
    'client'            => $row['client_name'],
    'value'             => $row['contract_value'] !== null ? floatval($row['contract_value']) : null,
    'renewal_frequency' => $row['contract_renewal_frequency'],
    'start_date'        => $row['contract_start_date'],
    'end_date'          => $row['contract_end_date'],
    'renewal_date'      => $renewal,
    'is_expired'        => $renewal !== null && $renewal < $today,
    'is_due_soon'       => $renewal !== null && $renewal >= $today && $renewal <= $warn_date,
    'details'           => $row['contract_details'] ?? '',
    'sla'               => [
        'high'   => ['response_time' => intval($row['contract_sla_high_response_time'] ?? 0) ?: null, 'resolution_time' => intval($row['contract_sla_high_resolution_time'] ?? 0) ?: null],
        'medium' => ['response_time' => intval($row['contract_sla_medium_response_time'] ?? 0) ?: null, 'resolution_time' => intval($row['contract_sla_medium_resolution_time'] ?? 0) ?: null],
        'low'    => ['response_time' => intval($row['contract_sla_low_response_time'] ?? 0) ?: null, 'resolution_time' => intval($row['contract_sla_low_resolution_time'] ?? 0) ?: null],
    ],
    'allowance'         => $allowance,
    'documents'         => $documents,
    'created_at'        => $row['contract_created_at'],
    'updated_at'        => $row['contract_updated_at'],
]);
