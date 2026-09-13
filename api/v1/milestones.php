<?php
// POST /api/v1/milestones/{id}/toggle    toggle a project milestone's completed state
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

if ($method !== 'POST' || $id === null || $sub !== 'toggle') api_error(404, 'Not found');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_support', 2);

$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT m.milestone_id, m.milestone_status, p.project_client_id
     FROM project_milestones m
     LEFT JOIN projects p ON p.project_id = m.milestone_project_id
     WHERE m.milestone_id = $id LIMIT 1"
));
if (!$row) api_error(404, 'Milestone not found');

$owner_client_id = $row['project_client_id'] !== null ? intval($row['project_client_id']) : 0;
if ($owner_client_id !== 0 && !api_client_scope_ok($owner_client_id)) api_error(403, 'Access denied');

$now_completed = $row['milestone_status'] !== 'completed';
if ($now_completed) {
    mysqli_query($mysqli, "UPDATE project_milestones SET milestone_status = 'completed', milestone_completed_at = NOW() WHERE milestone_id = $id");
} else {
    mysqli_query($mysqli, "UPDATE project_milestones SET milestone_status = 'open', milestone_completed_at = NULL WHERE milestone_id = $id");
}

$updated = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT milestone_id, milestone_name, milestone_description, milestone_due, milestone_order, milestone_status, milestone_completed_at
     FROM project_milestones WHERE milestone_id = $id LIMIT 1"
));

api_response(200, [
    'id'           => intval($updated['milestone_id']),
    'name'         => $updated['milestone_name'],
    'description'  => $updated['milestone_description'],
    'due_at'       => $updated['milestone_due'],
    'order'        => intval($updated['milestone_order']),
    'status'       => $updated['milestone_status'],
    'completed_at' => $updated['milestone_completed_at'],
]);
