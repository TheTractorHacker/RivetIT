<?php

/*
 * Download of a project task's attachment (DB 2.6.209): GET ?task_id=N&id=A.
 * Logged in, Service Desk level 1, and the task's department must be one the caller may access (the same rule as opening the task).
 * The attachment must belong to the task named in the URL; the stored name is a random reference that only this script maps to a file,
 * and the file is always sent as a download (octet-stream, nosniff), never rendered.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
require_once "../includes/project_tasks.php";

function ptDownloadFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (lookupUserPermission('module_support') < 1) {
    ptDownloadFail(403, 'Your role does not have access to tasks.');
}
$task_id = intval($_GET['task_id'] ?? 0);
$attachment_id = intval($_GET['id'] ?? 0);

$ctx = projectTaskContext($mysqli, $task_id);
if (!$ctx || !projectTaskIsProjectTask($ctx)) {
    ptDownloadFail(404, 'Not found.');
}
if (!projectTaskClientAccessOk($mysqli, intval($session_user_id), !empty($session_is_admin), intval($ctx['client_id']))) {
    ptDownloadFail(403, 'You do not have access to that department.');
}
$row = projectTaskAttachmentRow($mysqli, $task_id, $attachment_id);
if (!$row) {
    ptDownloadFail(404, 'Not found.');
}

projectTaskAttachmentServe($row);
