<?php
// POST /api/v1/tasks/{id}/toggle    toggle a task's completed state
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

if ($method !== 'POST' || $id === null || $sub !== 'toggle') api_error(404, 'Not found');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_support', 2);

// A task belongs either to a ticket (via task_ticket_id) or directly to a project
// (task_project_id) - resolve whichever client owns it for scope checking, mirroring
// projects.php's project_client_id = 0 ("internal project") carve-out.
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT tasks.task_id, tasks.task_completed_at,
            COALESCE(t.ticket_client_id, p.project_client_id) AS owner_client_id
     FROM tasks
     LEFT JOIN tickets t ON t.ticket_id = tasks.task_ticket_id
     LEFT JOIN projects p ON p.project_id = tasks.task_project_id
     WHERE tasks.task_id = $id LIMIT 1"
));
if (!$row) api_error(404, 'Task not found');

$owner_client_id = $row['owner_client_id'] !== null ? intval($row['owner_client_id']) : 0;
if ($owner_client_id !== 0 && !api_client_scope_ok($owner_client_id)) api_error(403, 'Access denied');

$now_completed = $row['task_completed_at'] === null;
if ($now_completed) {
    mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NOW(), task_completed_by = $uid, task_progress = 100 WHERE task_id = $id");
} else {
    mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NULL, task_completed_by = NULL WHERE task_id = $id");
}

$updated = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT tasks.task_id, tasks.task_name, tasks.task_status, tasks.task_progress, tasks.task_due, tasks.task_start,
            tasks.task_milestone_id, tasks.task_completed_at,
            tt.ticket_prefix, tt.ticket_number, u.user_name AS assigned_to_name
     FROM tasks
     LEFT JOIN tickets tt ON tt.ticket_id = tasks.task_ticket_id
     LEFT JOIN users u ON u.user_id = tasks.task_assigned_to
     WHERE tasks.task_id = $id LIMIT 1"
));

api_response(200, [
    'id'           => intval($updated['task_id']),
    'name'         => $updated['task_name'],
    'status'       => $updated['task_status'],
    'progress'     => intval($updated['task_progress']),
    'due_at'       => $updated['task_due'],
    'start_at'     => $updated['task_start'],
    'milestone_id' => $updated['task_milestone_id'] !== null ? intval($updated['task_milestone_id']) : null,
    'completed_at' => $updated['task_completed_at'],
    'assigned_to'  => $updated['assigned_to_name'],
    'ticket_number'=> $updated['ticket_number'] !== null ? $updated['ticket_prefix'] . $updated['ticket_number'] : null,
]);
