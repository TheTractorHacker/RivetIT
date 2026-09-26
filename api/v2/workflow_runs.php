<?php
// GET /api/v2/workflow-runs
defined('FROM_API_V2') || die();

// Roles audit P1g/F8: onboarding/offboarding runs are Departments data - module_client, scoped to the
// caller's departments (no rows = all departments, the app-wide rule).
if (itflow_profile_level($api_v2_profile, 'module_client') < 1) {
    api_v2_error(403, 'Insufficient permissions');
}
$wr_uid = intval($api_user_id);
$wr_scope = empty($api_v2_profile['admin'])
    ? "AND (NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $wr_uid)
            OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = $wr_uid AND ucp.client_id = c.contact_client_id))"
    : '';

$limit = isset($_GET['limit']) ? max(1, min(200, intval($_GET['limit']))) : 50;

$rows = [];
$sql = mysqli_query($mysqli,
    "SELECT r.run_id, r.workflow_template_id, wt.name AS template_name, r.contact_id,
            c.contact_name, r.type, r.status, r.started_by, u.user_name AS started_by_name,
            r.started_at, r.completed_at
     FROM workflow_runs r
     LEFT JOIN workflow_templates wt ON r.workflow_template_id = wt.workflow_template_id
     LEFT JOIN contacts c ON r.contact_id = c.contact_id
     LEFT JOIN users u ON r.started_by = u.user_id
     WHERE 1 = 1 $wr_scope
     ORDER BY r.run_id DESC
     LIMIT $limit"
);

while ($row = mysqli_fetch_assoc($sql)) {
    $rows[] = [
        'run_id'               => intval($row['run_id']),
        'workflow_template_id' => $row['workflow_template_id'] !== null ? intval($row['workflow_template_id']) : null,
        'template_name'        => $row['template_name'],
        'contact_id'           => intval($row['contact_id']),
        'contact_name'         => $row['contact_name'],
        'type'                 => $row['type'],
        'status'               => $row['status'],
        'started_by'           => $row['started_by'] !== null ? intval($row['started_by']) : null,
        'started_by_name'      => $row['started_by_name'],
        'started_at'           => $row['started_at'],
        'completed_at'         => $row['completed_at'],
    ];
}

api_v2_response(200, ['data' => $rows]);
