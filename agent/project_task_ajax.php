<?php

/*
 * JSON endpoint for the task pop-up's panels: checklist, subtasks, comments, files (DB 2.6.208 / 2.6.209).
 * POST only, with csrf_token. Every action: logged in, Service Desk level 2 (writes), the task's department checked against the caller's
 * department access, the task must be a project task. Answers {ok, panels: {name: html}, error?}; the page swaps in the fresh HTML.
 * Same lean require chain as agent/ticket_attachment_upload.php (not includes/inc_all.php, which renders the page shell).
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
require_once "../includes/project_tasks.php";
require_once "../includes/project_task_panels.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ptAjaxFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ptAjaxFail(405, 'POST only.');
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    ptAjaxFail(403, 'Your session token expired. Reload the page and try again.');
}
if (lookupUserPermission('module_support') < 2) {
    ptAjaxFail(403, 'Your role needs Technician access to change tasks.');
}

$user_id = intval($session_user_id);
$is_admin = !empty($session_is_admin);
$task_id = intval($_POST['task_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

$ctx = projectTaskContext($mysqli, $task_id);
if (!$ctx) {
    ptAjaxFail(404, 'That task no longer exists.');
}
if (!projectTaskClientAccessOk($mysqli, $user_id, $is_admin, intval($ctx['client_id']))) {
    logAction('Department', 'Access', "$session_name was denied permission from accessing department", intval($ctx['client_id']), intval($ctx['client_id']));
    ptAjaxFail(403, 'You do not have access to that department.');
}
if (!projectTaskIsProjectTask($ctx)) {
    ptAjaxFail(400, 'This only applies to project tasks.');
}

$caps = ['write' => true, 'user_id' => $user_id, 'is_admin' => $is_admin, 'can_delete_file' => lookupUserPermission('module_support') >= 3 || $is_admin];
$client_id = intval($ctx['client_id']);
$task_name = sanitizeInput($ctx['row']['task_name']);
$need = [];   // panels to re-render
$error = '';

$phase2 = projectTaskPhase2Ready($mysqli);
$phase3 = projectTaskPhase3Ready($mysqli);

switch ($action) {
    case 'checklist_add':
    case 'checklist_toggle':
    case 'checklist_edit':
    case 'checklist_delete':
    case 'checklist_up':
    case 'checklist_down':
        if (!$phase2) { ptAjaxFail(409, 'Run Update Database first.'); }
        $item_id = intval($_POST['item_id'] ?? 0);
        if ($action === 'checklist_add') {
            if (!projectTaskChecklistAdd($mysqli, $task_id, (string) ($_POST['text'] ?? ''))) { $error = 'Type the item first.'; }
        } elseif ($action === 'checklist_toggle') {
            if (!projectTaskChecklistUpdate($mysqli, $task_id, $item_id, null, !empty($_POST['done']))) { $error = 'That item no longer exists.'; }
        } elseif ($action === 'checklist_edit') {
            if (trim((string) ($_POST['text'] ?? '')) === '') { $error = 'An item cannot be empty.'; }
            elseif (!projectTaskChecklistUpdate($mysqli, $task_id, $item_id, (string) $_POST['text'], null)) { $error = 'That item no longer exists.'; }
        } elseif ($action === 'checklist_delete') {
            if (!projectTaskChecklistDelete($mysqli, $task_id, $item_id)) { $error = 'That item no longer exists.'; }
        } else {
            projectTaskChecklistMove($mysqli, $task_id, $item_id, $action === 'checklist_up' ? -1 : 1);
        }
        $need = ['checklist'];
        break;

    case 'subtask_add':
        if (!$phase2) { ptAjaxFail(409, 'Run Update Database first.'); }
        if (!projectTaskAddSubtask($mysqli, $ctx, (string) ($_POST['name'] ?? ''))) { $error = 'Type a name first (a subtask cannot have subtasks).'; }
        else { logAction('Task', 'Create', "$session_name added a subtask to task $task_name", $client_id, $task_id); }
        $need = ['subtasks'];
        break;

    case 'subtask_toggle':
    case 'subtask_detach':
        if (!$phase2) { ptAjaxFail(409, 'Run Update Database first.'); }
        $sub_id = intval($_POST['subtask_id'] ?? 0);
        $sub = projectTaskContext($mysqli, $sub_id);
        // The subtask must really be a child of THIS task (a posted id of any other task is refused, and its department is the parent's)
        if (!$sub || intval($sub['row']['task_parent_id'] ?? 0) !== $task_id) { ptAjaxFail(404, 'That subtask no longer exists.'); }
        if ($action === 'subtask_toggle') {
            projectTaskSetDone($mysqli, $sub_id, !empty($_POST['done']), $user_id);
            logAction('Task', 'Edit', "$session_name " . (!empty($_POST['done']) ? 'completed' : 'reopened') . ' a subtask of task ' . $task_name, $client_id, $sub_id);
        } else {
            projectTaskSetParent($mysqli, $sub_id, null);
        }
        $need = ['subtasks'];
        break;

    case 'subtask_detach_self':
        if (!$phase2) { ptAjaxFail(409, 'Run Update Database first.'); }
        projectTaskSetParent($mysqli, $task_id, null);
        $ctx = projectTaskContext($mysqli, $task_id);
        $need = ['subtasks'];
        break;

    case 'comment_add':
    case 'comment_edit':
    case 'comment_delete':
        if (!$phase2) { ptAjaxFail(409, 'Run Update Database first.'); }
        if ($action === 'comment_add') {
            if (!projectTaskCommentAdd($mysqli, $task_id, $user_id, (string) ($_POST['body'] ?? ''))) { $error = 'Write the comment first.'; }
            else { logAction('Task', 'Edit', "$session_name commented on task $task_name", $client_id, $task_id); }
        } elseif ($action === 'comment_edit') {
            $error = projectTaskCommentEdit($mysqli, $task_id, intval($_POST['comment_id'] ?? 0), $user_id, (string) ($_POST['body'] ?? ''));
        } else {
            $error = projectTaskCommentDelete($mysqli, $task_id, intval($_POST['comment_id'] ?? 0), $user_id, $is_admin);
            if ($error === '') { logAction('Task', 'Edit', "$session_name deleted a comment on task $task_name", $client_id, $task_id); }
        }
        $need = ['comments'];
        break;

    case 'file_upload':
        if (!$phase3) { ptAjaxFail(409, 'Run Update Database first.'); }
        $result = projectTaskAttachmentStore($mysqli, $ctx, $_FILES['file'] ?? [], $user_id);
        if (!$result['ok']) { $error = $result['error']; }
        else { logAction('Task', 'Edit', "$session_name attached a file to task $task_name", $client_id, $task_id); }
        $need = ['files'];
        break;

    case 'file_delete':
        if (!$phase3) { ptAjaxFail(409, 'Run Update Database first.'); }
        $att = projectTaskAttachmentRow($mysqli, $task_id, intval($_POST['attachment_id'] ?? 0));
        if (!$att) { $error = 'That file no longer exists.'; }
        elseif (!$caps['can_delete_file'] && intval($att['attachment_uploaded_by'] ?? 0) !== $user_id) { ptAjaxFail(403, 'Only the person who attached a file, or a lead, can remove it.'); }
        else { projectTaskAttachmentDelete($mysqli, $task_id, intval($att['attachment_id'])); logAction('Task', 'Edit', "$session_name removed a file from task $task_name", $client_id, $task_id); }
        $need = ['files'];
        break;

    default:
        ptAjaxFail(400, 'Unknown action.');
}

$panels = [];
foreach ($need as $name) {
    $panels[$name] = projectTaskRenderPanel($mysqli, $name, $ctx, $caps);
}
echo json_encode(['ok' => $error === '', 'error' => $error, 'panels' => $panels]);
