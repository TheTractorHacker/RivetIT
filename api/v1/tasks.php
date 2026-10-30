<?php
// GET    /api/v1/tasks/{id}           one task (scope api:tasks:read)
// POST   /api/v1/tasks                create a project task (api:tasks:write); body: project_id, name + any field below
// PATCH  /api/v1/tasks/{id}           change fields of a task (api:tasks:write); PUT is the same
// DELETE /api/v1/tasks/{id}           delete a task (api:tasks:delete)
// POST   /api/v1/tasks/{id}/toggle    toggle a task's completed state (api:tasks:write)
//
// Writable fields: name, status (To Do | In Progress | Blocked | Done), progress (0-100), start_at, due_at (YYYY-MM-DD or null),
// milestone_id, assigned_user_id, estimate_minutes, and, from DB 2.6.207: priority (0 none .. 4 urgent), description, color (#rrggbb or
// null), labels (list of label names, or {name, color}; new names are created in the task's project; replaces the task's labels)
// and label_ids (list of existing label ids that are global or belong to the task's project).
// The scope check (api:tasks:read / write / delete by HTTP verb) runs in index.php before this file; the role check below mirrors the
// web handlers (module_support level 1 read, 2 write, 3 delete).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once dirname(__DIR__, 2) . '/includes/project_tasks.php';

$uid = $api_user_id;
// Attribute the audit log rows (logAction) to the API caller
$session_user_id = $uid;

/** Task row + owner, in the shape the endpoints return. */
function api_task_payload(mysqli $mysqli, int $task_id): ?array
{
    $t = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT tasks.*, tt.ticket_prefix, tt.ticket_number, u.user_name AS assigned_to_name
         FROM tasks
         LEFT JOIN tickets tt ON tt.ticket_id = tasks.task_ticket_id
         LEFT JOIN users u ON u.user_id = tasks.task_assigned_to
         WHERE tasks.task_id = $task_id LIMIT 1"
    ));
    if (!$t) return null;

    return [
        'id'           => intval($t['task_id']),
        'name'         => $t['task_name'],
        'status'       => $t['task_status'],
        'progress'     => intval($t['task_progress']),
        'due_at'       => $t['task_due'],
        'start_at'     => $t['task_start'],
        'milestone_id' => $t['task_milestone_id'] !== null ? intval($t['task_milestone_id']) : null,
        'completed_at' => $t['task_completed_at'],
        'assigned_to'  => $t['assigned_to_name'],
        'ticket_number'=> $t['ticket_number'] !== null ? $t['ticket_prefix'] . $t['ticket_number'] : null,
    ] + projectTaskApiExtras($t, projectTaskLabelsByTask($mysqli, [$task_id]));
}

/** Client that owns a task (via its project, else its ticket), checked against the caller's department scope. Returns the task context. */
function api_task_context(mysqli $mysqli, int $task_id): array
{
    $ctx = projectTaskContext($mysqli, $task_id);
    if (!$ctx) api_error(404, 'Task not found');
    if ($ctx['client_id'] !== 0 && !api_client_scope_ok($ctx['client_id'])) api_error(403, 'Access denied');

    return $ctx;
}

/** Date field: null/'' clears, YYYY-MM-DD sets, anything else is a 400. Returns the SQL fragment. */
function api_task_date(mysqli $mysqli, $value, string $field): string
{
    if ($value === null || $value === '') return 'NULL';
    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
    if (!$d || $d->format('Y-m-d') !== (string) $value) api_error(400, "$field must be YYYY-MM-DD or null");

    return "'" . $d->format('Y-m-d') . "'";
}

/** Apply the writable fields of $body to a task (it must exist). $project_id scopes milestone and labels. */
function api_task_apply(mysqli $mysqli, int $task_id, int $project_id, array $body, int $uid, bool $is_create): void
{
    $sets = [];
    if (array_key_exists('name', $body)) {
        $name = sanitizeInput(mb_substr((string) $body['name'], 0, 255));
        if ($name === '') api_error(400, 'name cannot be empty');
        $sets[] = "task_name = '$name'";
    }
    if (array_key_exists('progress', $body)) {
        $sets[] = 'task_progress = ' . max(0, min(100, intval($body['progress'])));
    }
    if (array_key_exists('start_at', $body)) $sets[] = 'task_start = ' . api_task_date($mysqli, $body['start_at'], 'start_at');
    if (array_key_exists('due_at', $body))   $sets[] = 'task_due = ' . api_task_date($mysqli, $body['due_at'], 'due_at');
    if (array_key_exists('estimate_minutes', $body)) $sets[] = 'task_completion_estimate = ' . max(0, intval($body['estimate_minutes']));
    if (array_key_exists('milestone_id', $body)) {
        $mid = intval($body['milestone_id']);
        if ($mid) {
            $m = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT milestone_id FROM project_milestones WHERE milestone_id = $mid AND milestone_project_id = $project_id LIMIT 1"));
            if (!$m) api_error(400, 'milestone_id does not belong to the task\'s project');
        }
        $sets[] = 'task_milestone_id = ' . ($mid ?: 'NULL');
    }
    if (array_key_exists('assigned_user_id', $body)) {
        $aid = intval($body['assigned_user_id']);
        if ($aid) {
            $u = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_id = $aid AND user_status = 1 AND user_archived_at IS NULL LIMIT 1"));
            if (!$u) api_error(400, 'assigned_user_id is not an active user');
        }
        $sets[] = 'task_assigned_to = ' . ($aid ?: 'NULL');
    }

    // DB 2.6.207 fields: silently ignored (with the neutral defaults on read) until Update Database has run
    if (projectTaskColumnsExist($mysqli)) {
        if (array_key_exists('priority', $body)) {
            $p = intval($body['priority']);
            if ($p < 0 || $p > 4) api_error(400, 'priority must be 0 (none) to 4 (urgent)');
            $sets[] = "task_priority = $p";
        }
        if (array_key_exists('description', $body)) {
            $desc = $body['description'] === null ? '' : sanitizeInput(mb_substr((string) $body['description'], 0, 20000));
            $sets[] = $desc === '' ? 'task_description = NULL' : "task_description = '$desc'";
        }
        if (array_key_exists('color', $body)) {
            if ($body['color'] === null || $body['color'] === '') {
                $sets[] = 'task_color = NULL';
            } else {
                $c = projectTaskNormalizeColor($body['color']);
                if (!$c) api_error(400, 'color must be #rrggbb or null');
                $sets[] = "task_color = '$c'";
            }
        }
    }
    if ($sets) {
        mysqli_query($mysqli, 'UPDATE tasks SET ' . implode(', ', $sets) . " WHERE task_id = $task_id");
    }

    if (array_key_exists('status', $body)) {
        $sql = projectTaskStatusSql($mysqli, (string) $body['status'], $uid);
        if ($sql === '') api_error(400, 'status must be one of: ' . implode(', ', projectTaskLanes()));
        mysqli_query($mysqli, "UPDATE tasks SET $sql WHERE task_id = $task_id");
    }

    if (projectTaskLabelTablesExist($mysqli) && (array_key_exists('labels', $body) || array_key_exists('label_ids', $body))) {
        $ids = is_array($body['label_ids'] ?? null) ? $body['label_ids'] : [];
        foreach ((is_array($body['labels'] ?? null) ? $body['labels'] : []) as $lab) {
            $lname = sanitizeInput(mb_substr(is_array($lab) ? (string) ($lab['name'] ?? '') : (string) $lab, 0, 100));
            if ($lname === '') continue;
            $lid = projectTaskCreateLabel($mysqli, $lname, is_array($lab) ? ($lab['color'] ?? null) : null, $project_id ?: null);
            if ($lid) $ids[] = $lid;
        }
        projectTaskSetLabels($mysqli, $task_id, $project_id, $ids);
    }
}

// --- POST /tasks/{id}/toggle -------------------------------------------------------------------------------------------
if ($method === 'POST' && $id !== null && $sub === 'toggle') {
    api_require_module_permission($mysqli, $uid, 'module_support', 2);

    $ctx = api_task_context($mysqli, $id);

    $now_completed = $ctx['row']['task_completed_at'] === null;
    if ($now_completed) {
        mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NOW(), task_completed_by = $uid, task_progress = 100 WHERE task_id = $id");
    } else {
        mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NULL, task_completed_by = NULL WHERE task_id = $id");
    }

    api_response(200, api_task_payload($mysqli, $id));
}

// --- GET /tasks/{id} ---------------------------------------------------------------------------------------------------
if ($method === 'GET' && $id !== null && $sub === null) {
    api_require_module_permission($mysqli, $uid, 'module_support', 1);
    api_task_context($mysqli, $id);

    api_response(200, api_task_payload($mysqli, $id));
}

// --- POST /tasks (create a project task) -------------------------------------------------------------------------------
if ($method === 'POST' && $id === null && $sub === null) {
    api_require_module_permission($mysqli, $uid, 'module_support', 2);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) api_error(400, 'JSON body required');
    $project_id = intval($body['project_id'] ?? 0);
    if (!$project_id) api_error(400, 'project_id is required');
    if (!array_key_exists('name', $body) || trim((string) $body['name']) === '') api_error(400, 'name is required');

    $project = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT project_id, project_client_id FROM projects WHERE project_id = $project_id LIMIT 1"));
    if (!$project) api_error(404, 'Project not found');
    $owner = intval($project['project_client_id']);
    if ($owner !== 0 && !api_client_scope_ok($owner)) api_error(403, 'Access denied');

    $placeholder = sanitizeInput(mb_substr((string) $body['name'], 0, 255));
    mysqli_query($mysqli, "INSERT INTO tasks SET task_name = '$placeholder', task_project_id = $project_id");
    $new_id = intval(mysqli_insert_id($mysqli));
    api_task_apply($mysqli, $new_id, $project_id, $body, $uid, true);

    logAction('Task', 'Create', 'API created task ' . $placeholder, $owner, $new_id);

    api_response(201, api_task_payload($mysqli, $new_id));
}

// --- PATCH|PUT /tasks/{id} ---------------------------------------------------------------------------------------------
if (($method === 'PATCH' || $method === 'PUT') && $id !== null && $sub === null) {
    api_require_module_permission($mysqli, $uid, 'module_support', 2);

    $ctx = api_task_context($mysqli, $id);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) api_error(400, 'JSON body required');

    api_task_apply($mysqli, $id, $ctx['project_id'], $body, $uid, false);

    logAction('Task', 'Edit', 'API edited task ' . sanitizeInput($ctx['row']['task_name']), $ctx['client_id'], $id);

    api_response(200, api_task_payload($mysqli, $id));
}

// --- DELETE /tasks/{id} ------------------------------------------------------------------------------------------------
if ($method === 'DELETE' && $id !== null && $sub === null) {
    api_require_module_permission($mysqli, $uid, 'module_support', 3);

    $ctx = api_task_context($mysqli, $id);
    projectTaskDelete($mysqli, $id);

    logAction('Task', 'Delete', 'API deleted task ' . sanitizeInput($ctx['row']['task_name']), $ctx['client_id'], $id);

    api_response(200, ['deleted' => true, 'id' => $id]);
}

api_error(404, 'Not found');
