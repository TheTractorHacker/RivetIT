<?php
// GET  /api/v1/workflow_tasks.php?scope=mine|all   open onboarding/offboarding checklist tasks
// POST /api/v1/workflow_tasks.php                  {"id":N,"action":"complete"|"skip","reason":"..."}
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';

api_mobile_require_user_token();
$uid = intval($api_user_id);

// Onboarding/offboarding runs are Departments data (agent/workflow_run.php, agent/post/workflow_run.php): viewing needs
// module_client view, working a task needs edit. Every run is also subject to department (client) access.
api_require_module_permission($mysqli, $uid, 'module_client', $method === 'GET' ? 1 : 2);

if ($method === 'GET') {
    $scope = $_GET['scope'] ?? 'mine';
    if (!in_array($scope, ['mine', 'all'], true)) {
        api_error(422, 'scope must be mine or all');
    }
    // "all" shows other people's assigned tasks too, so it needs edit access to Departments (or admin).
    if ($scope === 'all') {
        api_require_module_permission($mysqli, $uid, 'module_client', 2);
    }

    $where = [
        "t.status IN ('pending','blocked','running','action_failed','rejected')",
        "r.status IN ('in_progress','paused')",
        api_mobile_client_sql('c.contact_client_id'),
    ];
    if ($scope === 'mine') {
        // Assigned to me, plus unassigned tasks of runs I may see (the checklist anyone with access may pick up).
        $where[] = "(t.assignee_user_id = $uid OR t.assignee_user_id IS NULL OR t.assignee_user_id = 0)";
    }
    $rs = mysqli_query($mysqli,
        "SELECT t.run_task_id, t.run_id, t.title, t.instructions, t.due_at, t.status, t.task_type, t.depends_on,
                r.type AS run_type, c.contact_name, u.user_name AS assignee_name
         FROM workflow_run_tasks t
         JOIN workflow_runs r ON r.run_id = t.run_id
         LEFT JOIN contacts c ON c.contact_id = r.contact_id
         LEFT JOIN users u ON u.user_id = t.assignee_user_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY (t.due_at IS NULL) ASC, t.due_at ASC, t.run_task_id ASC LIMIT 300");

    $rows = [];
    $run_ids = [];
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $rows[] = $row;
        $run_ids[intval($row['run_id'])] = true;
    }
    // Titles/status of every task in those runs, to name the unresolved dependencies.
    $run_tasks = [];
    if ($run_ids) {
        $ds = mysqli_query($mysqli, "SELECT run_task_id, title, status FROM workflow_run_tasks WHERE run_id IN (" . implode(',', array_keys($run_ids)) . ")");
        while ($d = mysqli_fetch_assoc($ds)) {
            $run_tasks[intval($d['run_task_id'])] = $d;
        }
    }

    $now = time();
    $items = [];
    $overdue = 0;
    foreach ($rows as $row) {
        $blocked_by = [];
        foreach (\ITFlow\Workflow\DependencyGraph::parse($row['depends_on']) as $dep) {
            if (isset($run_tasks[$dep]) && !in_array($run_tasks[$dep]['status'], ['completed', 'skipped'], true)) {
                $blocked_by[] = (string) $run_tasks[$dep]['title'];
            }
        }
        if (\ITFlow\Workflow\DueDateResolver::state($row['due_at'], $now) === 'overdue') {
            $overdue++;
        }
        $items[] = [
            'id'           => intval($row['run_task_id']),
            'run_id'       => intval($row['run_id']),
            'run_title'    => ucfirst((string) $row['run_type']) . ': ' . ($row['contact_name'] ?? 'unknown person'),
            'task_title'   => (string) $row['title'],
            'instructions' => (string) ($row['instructions'] ?? ''),
            'due_at'       => $row['due_at'],
            'status'       => (string) $row['status'],
            'type'         => (string) $row['task_type'],
            'blocked_by'   => $blocked_by,
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'assignee'     => $row['assignee_name'],
        ];
    }
    api_response(200, ['items' => $items, 'counts' => ['open' => count($items), 'overdue' => $overdue]]);
}

if ($method === 'POST') {
    $body    = api_mobile_json_body();
    $task_id = $body['id'] ?? null;
    $action  = $body['action'] ?? '';
    $reason  = $body['reason'] ?? '';

    if (!is_int($task_id) || $task_id < 1) {
        api_error(422, 'id must be a positive integer');
    }
    if (!in_array($action, ['complete', 'skip'], true)) {
        api_error(422, 'action must be complete or skip');
    }
    if (!is_string($reason)) {
        api_error(422, 'reason must be a string');
    }
    $reason = mb_substr(cleanInput($reason), 0, 500);
    if ($action === 'skip' && $reason === '') {
        api_error(422, 'reason is required when skipping');
    }

    // The task must exist AND belong to a run whose department this user may access (the web handler's loadWorkflowRunOrDie +
    // taskInRun). Unknown and inaccessible look the same.
    $t = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.run_task_id, t.run_id, r.contact_id, c.contact_name, c.contact_client_id
         FROM workflow_run_tasks t
         JOIN workflow_runs r ON r.run_id = t.run_id
         LEFT JOIN contacts c ON c.contact_id = r.contact_id
         WHERE t.run_task_id = $task_id LIMIT 1"));
    if (!$t || !api_mobile_client_ok($t['contact_client_id'] ?? 0)) {
        api_error(404, 'Task not found');
    }

    $service = new \ITFlow\Workflow\WorkflowService($mysqli);
    if (!$service->taskInRun($task_id, intval($t['run_id']))) {
        api_error(404, 'Task not found');
    }
    api_mobile_audit_context();
    try {
        if ($action === 'complete') {
            $service->completeTask($task_id, $uid);
        } else {
            $service->skipTask($task_id, $reason, $uid, api_mobile_is_admin());
        }
    } catch (\DomainException $e) {
        api_error(422, $e->getMessage());
    }

    logAction('Contact', 'Edit',
        "$session_name " . ($action === 'complete' ? 'completed' : 'skipped') . " a workflow task for {$t['contact_name']}" . ($action === 'skip' ? ": $reason" : '') . ' via the mobile API',
        intval($t['contact_client_id'] ?? 0), intval($t['contact_id']));

    $now_status = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT status FROM workflow_run_tasks WHERE run_task_id = $task_id"));
    api_response(200, ['ok' => true, 'status' => (string) ($now_status['status'] ?? '')]);
}

api_error(405, 'Method not allowed');
