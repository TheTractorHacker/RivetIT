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
// Phases 2 and 3 (DB 2.6.208 / 2.6.209), also writable: parent_id (make it a subtask; null/0 detaches), assignee_ids (everyone on the task;
// assigned_user_id stays the primary), reminder_at ('YYYY-MM-DD HH:MM[:SS]' or null), repeat ({interval 1-999, unit day|week|month|year} or null).
// Sub-resources (same scopes and roles as the task itself):
//   GET|POST /tasks/{id}/checklist, PATCH|DELETE /tasks/{id}/checklist/{item}      checklist items {text, done}
//   GET|POST /tasks/{id}/subtasks                                                   list / create a subtask {name}
//   GET|POST /tasks/{id}/comments, PATCH|DELETE /tasks/{id}/comments/{comment}      comments {body}; edit own only, delete own (admin: any)
//   GET|POST /tasks/{id}/attachments, GET|DELETE /tasks/{id}/attachments/{file}     files (multipart field "file"); GET one = the download
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
    ] + projectTaskApiExtras($t, projectTaskLabelsByTask($mysqli, [$task_id])) + (projectTaskApiExtras2($mysqli, [$t])[$task_id] ?? []);
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
        $old_primary = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(task_assigned_to, 0) FROM tasks WHERE task_id = $task_id"))[0] ?? 0);
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

    // DB 2.6.208 / 2.6.209 fields (ignored, like the 2.6.207 ones, until Update Database has run)
    if (array_key_exists('parent_id', $body) && projectTaskPhase2Ready($mysqli)) {
        $pid = $body['parent_id'] === null ? 0 : intval($body['parent_id']);
        $err = projectTaskSetParent($mysqli, $task_id, $pid ?: null);
        if ($err !== '') api_error(400, $err);
    }
    if (projectTaskPhase3Ready($mysqli)) {
        if (array_key_exists('assigned_user_id', $body)) {
            projectTaskSyncAssignees($mysqli, $task_id, intval($body['assigned_user_id']), $old_primary ?? 0, array_key_exists('assignee_ids', $body) ? [] : null);
        }
        if (array_key_exists('assignee_ids', $body)) {
            if (!is_array($body['assignee_ids'])) api_error(400, 'assignee_ids must be a list of user ids');
            $want = array_values(array_unique(array_filter(array_map('intval', $body['assignee_ids']))));
            if (count(projectTaskActiveUserIds($mysqli, $want)) !== count($want)) api_error(400, 'assignee_ids must all be active users');
            $primary_now = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(task_assigned_to, 0) FROM tasks WHERE task_id = $task_id"))[0] ?? 0);
            // The team is exactly this list; the current primary stays primary when it is still in it, otherwise the first one is
            projectTaskSyncAssignees($mysqli, $task_id, in_array($primary_now, $want, true) ? $primary_now : 0, $primary_now, $want);
        }
        if (array_key_exists('reminder_at', $body)) {
            $rem = projectTaskNormalizeReminder($body['reminder_at']);
            if ($rem === false) api_error(400, "reminder_at must be 'YYYY-MM-DD HH:MM[:SS]' or null");
            $r = $rem === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $rem) . "'";
            mysqli_query($mysqli, "UPDATE tasks SET task_reminder_sent_at = IF(task_reminder_at <=> $r, task_reminder_sent_at, NULL), task_reminder_at = $r WHERE task_id = $task_id");
        }
        if (array_key_exists('repeat', $body)) {
            if ($body['repeat'] === null) {
                $n = 0; $u = 'day';
            } else {
                if (!is_array($body['repeat'])) api_error(400, 'repeat must be {interval, unit} or null');
                $n = intval($body['repeat']['interval'] ?? 0);
                $u = (string) ($body['repeat']['unit'] ?? '');
                if ($n < 1 || $n > 999 || !isset(projectTaskRepeatUnits()[$u])) api_error(400, 'repeat needs an interval of 1-999 and a unit of day, week, month or year');
            }
            mysqli_query($mysqli, "UPDATE tasks SET task_repeat_interval = $n, task_repeat_unit = '" . mysqli_real_escape_string($mysqli, $u) . "' WHERE task_id = $task_id");
        }
    }

    if (array_key_exists('status', $body)) {
        $sql = projectTaskStatusSql($mysqli, (string) $body['status'], $uid);
        if ($sql === '') api_error(400, 'status must be one of: ' . implode(', ', projectTaskLanes()));
        $was_done = (bool) mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM tasks WHERE task_id = $task_id AND task_completed_at IS NOT NULL"));
        mysqli_query($mysqli, "UPDATE tasks SET $sql WHERE task_id = $task_id");
        if ((string) $body['status'] === 'Done' && !$was_done) projectTaskCompleted($mysqli, $task_id, $uid);
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
        projectTaskCompleted($mysqli, $id, $uid);
    } else {
        mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NULL, task_completed_by = NULL WHERE task_id = $id");
    }

    api_response(200, api_task_payload($mysqli, $id));
}

// --- sub-resources: checklist, subtasks, comments, attachments (DB 2.6.208 / 2.6.209) ----------------------------------------------
if ($id !== null && in_array($sub, ['checklist', 'subtasks', 'comments', 'attachments'], true)) {
    $level = $method === 'GET' ? 1 : 2;
    api_require_module_permission($mysqli, $uid, 'module_support', $level);
    $ctx = api_task_context($mysqli, $id);
    if (!projectTaskIsProjectTask($ctx)) api_error(400, 'This only applies to project tasks');
    $needs3 = $sub === 'attachments';
    if (($needs3 && !projectTaskPhase3Ready($mysqli)) || (!$needs3 && !projectTaskPhase2Ready($mysqli))) api_error(409, 'Not available until an administrator runs Update Database');
    $sub_id = isset($segments[3]) && ctype_digit((string) $segments[3]) ? intval($segments[3]) : null;
    $is_admin = projectTaskUserIsAdmin($mysqli, $uid);
    $body = in_array($method, ['POST', 'PATCH', 'PUT'], true) && $sub !== 'attachments' ? json_decode(file_get_contents('php://input'), true) : [];
    if (!is_array($body)) api_error(400, 'JSON body required');
    $tname = sanitizeInput($ctx['row']['task_name']);

    if ($sub === 'checklist') {
        if ($method === 'GET' && $sub_id === null) api_response(200, ['items' => array_map(fn($i) => ['id' => $i['item_id'], 'text' => $i['item_text'], 'done' => (bool) $i['item_done']], projectTaskChecklist($mysqli, $id))]);
        if ($method === 'POST' && $sub_id === null) {
            $new = projectTaskChecklistAdd($mysqli, $id, (string) ($body['text'] ?? ''));
            if (!$new) api_error(400, 'text is required');
            api_response(201, ['id' => $new, 'text' => projectTaskPlainText($body['text'], 255), 'done' => false]);
        }
        if (in_array($method, ['PATCH', 'PUT'], true) && $sub_id !== null) {
            $text = array_key_exists('text', $body) ? (string) $body['text'] : null;
            if ($text !== null && projectTaskPlainText($text, 255) === '') api_error(400, 'text cannot be empty');
            if (!projectTaskChecklistUpdate($mysqli, $id, $sub_id, $text, array_key_exists('done', $body) ? (bool) $body['done'] : null)) api_error(404, 'Checklist item not found');
            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_id, item_text, item_done FROM task_checklist_items WHERE item_id = $sub_id"));
            api_response(200, ['id' => intval($row['item_id']), 'text' => $row['item_text'], 'done' => (bool) $row['item_done']]);
        }
        if ($method === 'DELETE' && $sub_id !== null) {
            if (!projectTaskChecklistDelete($mysqli, $id, $sub_id)) api_error(404, 'Checklist item not found');
            api_response(200, ['deleted' => true, 'id' => $sub_id]);
        }
    } elseif ($sub === 'subtasks') {
        if ($method === 'GET' && $sub_id === null) api_response(200, ['subtasks' => array_map(fn($s) => ['id' => $s['task_id'], 'name' => $s['task_name'], 'done' => $s['done']], projectTaskSubtasks($mysqli, $id))]);
        if ($method === 'POST' && $sub_id === null) {
            $new = projectTaskAddSubtask($mysqli, $ctx, (string) ($body['name'] ?? ''));
            if (!$new) api_error(400, 'name is required (and a subtask cannot have subtasks)');
            logAction('Task', 'Create', 'API added a subtask to task ' . $tname, $ctx['client_id'], $new);
            api_response(201, api_task_payload($mysqli, $new));
        }
    } elseif ($sub === 'comments') {
        if ($method === 'GET' && $sub_id === null) api_response(200, ['comments' => array_map(fn($c) => ['id' => $c['comment_id'], 'user_id' => $c['comment_user_id'], 'user' => $c['user_name'], 'body' => $c['comment_body'], 'created_at' => $c['comment_created_at'], 'updated_at' => $c['comment_updated_at']], projectTaskComments($mysqli, $id))]);
        if ($method === 'POST' && $sub_id === null) {
            $new = projectTaskCommentAdd($mysqli, $id, $uid, (string) ($body['body'] ?? ''));
            if (!$new) api_error(400, 'body is required');
            logAction('Task', 'Edit', 'API commented on task ' . $tname, $ctx['client_id'], $id);
            api_response(201, ['id' => $new]);
        }
        if (in_array($method, ['PATCH', 'PUT'], true) && $sub_id !== null) {
            $err = projectTaskCommentEdit($mysqli, $id, $sub_id, $uid, (string) ($body['body'] ?? ''));
            if ($err !== '') api_error(str_contains($err, 'own') ? 403 : (str_contains($err, 'exist') ? 404 : 400), $err);
            api_response(200, ['id' => $sub_id]);
        }
        if ($method === 'DELETE' && $sub_id !== null) {
            $err = projectTaskCommentDelete($mysqli, $id, $sub_id, $uid, $is_admin);
            if ($err !== '') api_error(str_contains($err, 'own') ? 403 : 404, $err);
            api_response(200, ['deleted' => true, 'id' => $sub_id]);
        }
    } else {
        if ($method === 'GET' && $sub_id === null) api_response(200, ['attachments' => array_map(fn($a) => ['id' => $a['attachment_id'], 'name' => $a['attachment_name'], 'size' => $a['attachment_size'], 'mime' => $a['attachment_mime'], 'uploaded_by' => $a['user_name'], 'created_at' => $a['attachment_created_at']], projectTaskAttachments($mysqli, $id))]);
        if ($method === 'GET' && $sub_id !== null) {
            $row = projectTaskAttachmentRow($mysqli, $id, $sub_id);
            if (!$row) api_error(404, 'Attachment not found');
            projectTaskAttachmentServe($row);
        }
        if ($method === 'POST' && $sub_id === null) {
            $res = projectTaskAttachmentStore($mysqli, $ctx, $_FILES['file'] ?? [], $uid);
            if (!$res['ok']) api_error(400, $res['error']);
            logAction('Task', 'Edit', 'API attached a file to task ' . $tname, $ctx['client_id'], $id);
            api_response(201, ['id' => $res['id']]);
        }
        if ($method === 'DELETE' && $sub_id !== null) {
            $row = projectTaskAttachmentRow($mysqli, $id, $sub_id);
            if (!$row) api_error(404, 'Attachment not found');
            if (!$is_admin && intval($row['attachment_uploaded_by'] ?? 0) !== $uid && !api_has_module_permission($mysqli, $uid, 'module_support', 3)) api_error(403, 'Only the uploader or a Service Desk level 3 role can delete this file');
            projectTaskAttachmentDelete($mysqli, $id, $sub_id);
            api_response(200, ['deleted' => true, 'id' => $sub_id]);
        }
    }
    api_error(404, 'Not found');
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

    // A subtask must be valid BEFORE anything is created (same project, a project task, not itself a subtask)
    if (!empty($body['parent_id']) && projectTaskPhase2Ready($mysqli)) {
        $par = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT task_project_id, task_ticket_id, task_parent_id FROM tasks WHERE task_id = ' . intval($body['parent_id']) . ' LIMIT 1'));
        if (!$par) api_error(400, 'parent_id: the parent task does not exist');
        if (intval($par['task_project_id']) !== $project_id || intval($par['task_ticket_id'])) api_error(400, 'parent_id must be a task of the same project');
        if ($par['task_parent_id'] !== null) api_error(400, 'parent_id: subtasks can only go one level deep');
    }
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
