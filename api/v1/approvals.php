<?php
// GET  /api/v1/approvals.php   approvals the caller may decide right now (catalog requests + workflow approval tasks)
// POST /api/v1/approvals.php   {"kind":"catalog_request"|"workflow_task","id":N,"decision":"approve"|"reject","comment":"..."}
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';
require_once $DOCUMENT_ROOT . '/src/ITSM/ServiceCatalogService.php';

api_mobile_require_user_token();
$uid = intval($api_user_id);

// Same module gates as the web pages: catalog approvals = Support (agent/service_catalog_approvals.php), workflow
// approvals = Departments (agent/workflow_run.php). Holding neither means there is nothing this user can ever decide.
$can_catalog  = api_has_module_permission($mysqli, $uid, 'module_support', 1);
$can_workflow = api_has_module_permission($mysqli, $uid, 'module_client', 1);
if (!$can_catalog && !$can_workflow) {
    api_error(403, 'Insufficient permissions');
}

$catalog_service  = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$workflow_service = new \ITFlow\Workflow\WorkflowService($mysqli);

/**
 * Catalog requests this user may decide now: the ones addressed to them, plus (administrators only) every other pending
 * request as an override - exactly the two lists agent/service_catalog_approvals.php shows.
 *
 * @return array<int,array{row:array,override:bool}> keyed by request_id
 */
function api_approvals_decidable_catalog($catalog_service, int $uid): array {
    $out = [];
    foreach ($catalog_service->pendingApprovals($uid) as $r) {
        $out[intval($r['request_id'])] = ['row' => $r, 'override' => false];
    }
    if (api_mobile_is_admin()) {
        foreach ($catalog_service->pendingApprovals(null, null, 0, true) as $r) {
            $rid = intval($r['request_id']);
            if (!isset($out[$rid])) {
                $out[$rid] = ['row' => $r, 'override' => true];
            }
        }
    }
    return $out;
}

/** The workflow approval task row (with run + contact) when it is waiting for a decision and this user may decide it, else null. */
function api_approvals_decidable_task($workflow_service, int $task_id, int $uid): ?array {
    $t = mysqli_fetch_assoc(mysqli_query($GLOBALS['mysqli'],
        "SELECT t.*, r.status AS run_status, r.contact_id AS run_contact_id, c.contact_name, c.contact_client_id
         FROM workflow_run_tasks t
         JOIN workflow_runs r ON r.run_id = t.run_id
         LEFT JOIN contacts c ON c.contact_id = r.contact_id
         WHERE t.run_task_id = $task_id LIMIT 1"));
    if (!$t || $t['task_type'] !== 'approval' || $t['status'] !== 'pending' || $t['approval_status'] !== 'pending') {
        return null;
    }
    if (in_array($t['run_status'], ['cancelled', 'paused'], true)) {
        return null;
    }
    if (!api_mobile_client_ok($t['contact_client_id'] ?? 0)) {
        return null;
    }
    if (!$workflow_service->canDecide($task_id, $uid)) {
        return null;
    }
    return $t;
}

if ($method === 'GET') {
    $items = [];

    if ($can_catalog) {
        $decidable = api_approvals_decidable_catalog($catalog_service, $uid);
        // Who asked: the agent when an agent raised it (mobile / agent UI), else the portal contact.
        $requesters = [];
        if ($decidable) {
            $ids = implode(',', array_map('intval', array_keys($decidable)));
            $rs = mysqli_query($mysqli,
                "SELECT r.request_id, u.user_name FROM service_catalog_requests r
                 LEFT JOIN users u ON u.user_id = r.requested_by_user_id AND r.requested_by_user_id > 0
                 WHERE r.request_id IN ($ids)");
            while ($rr = mysqli_fetch_assoc($rs)) {
                $requesters[intval($rr['request_id'])] = $rr['user_name'];
            }
        }
        foreach ($decidable as $rid => $d) {
            $a = $d['row'];
            $values = json_decode((string) $a['field_values'], true);
            $fields = [];
            foreach (is_array($values) ? $values : [] as $v) {
                $fields[] = ['label' => (string) ($v['label'] ?? ''), 'value' => (string) ($v['value'] ?? '')];
            }
            $items[] = [
                'kind'         => 'catalog_request',
                'id'           => $rid,
                'title'        => (string) $a['item_name'],
                'requester'    => (string) ($requesters[$rid] ?? ($a['requester_name'] ?: 'an agent')),
                'summary'      => trim($a['ticket_prefix'] . intval($a['ticket_number']) . ' - ' . $a['ticket_subject']
                                  . ($a['client_name'] ? ' (' . $a['client_name'] . ')' : '')),
                'risk_score'   => intval($a['risk_score']),
                'step'         => 'Step ' . intval($a['step_order']),
                'ticket_id'    => intval($a['ticket_id']),
                'requested_at' => (string) $a['created_at'],
                'due_at'       => null,
                'fields'       => $fields,
            ];
        }
    }

    if ($can_workflow) {
        $rs = mysqli_query($mysqli,
            "SELECT t.run_task_id FROM workflow_run_tasks t
             JOIN workflow_runs r ON r.run_id = t.run_id
             LEFT JOIN contacts c ON c.contact_id = r.contact_id
             WHERE t.task_type = 'approval' AND t.status = 'pending' AND t.approval_status = 'pending'
               AND r.status NOT IN ('cancelled', 'paused')
               AND " . api_mobile_client_sql('c.contact_client_id') . "
             ORDER BY r.started_at ASC, t.run_task_id ASC LIMIT 300");
        while ($rs && ($row = mysqli_fetch_assoc($rs))) {
            $tid = intval($row['run_task_id']);
            $t = api_approvals_decidable_task($workflow_service, $tid, $uid);
            if (!$t) {
                continue;
            }
            $run = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT r.type, r.started_at, u.user_name FROM workflow_runs r LEFT JOIN users u ON u.user_id = r.started_by WHERE r.run_id = " . intval($t['run_id'])));
            $contact = \ITFlow\Workflow\TaskActionRunner::loadContact($mysqli, intval($t['run_contact_id'])) ?? [];
            $fields = [
                ['label' => 'Person', 'value' => (string) ($t['contact_name'] ?? '')],
                ['label' => 'Workflow', 'value' => ucfirst((string) $run['type'])],
                ['label' => 'Approver', 'value' => $workflow_service->approverLabel($t, $contact)],
            ];
            if ((string) $t['instructions'] !== '') {
                $fields[] = ['label' => 'Instructions', 'value' => (string) $t['instructions']];
            }
            $items[] = [
                'kind'         => 'workflow_task',
                'id'           => $tid,
                'title'        => (string) $t['title'],
                'requester'    => (string) ($run['user_name'] ?? 'Automation'),
                'summary'      => ucfirst((string) $run['type']) . ' workflow for ' . ($t['contact_name'] ?? 'an employee'),
                'risk_score'   => null,
                'step'         => null,
                'ticket_id'    => null,
                'requested_at' => (string) ($t['approval_notified_at'] ?: $run['started_at']),
                'due_at'       => $t['due_at'],
                'fields'       => $fields,
            ];
        }
    }

    usort($items, fn($x, $y) => strcmp($x['requested_at'], $y['requested_at']));
    api_response(200, ['items' => $items, 'counts' => ['total' => count($items)]]);
}

if ($method === 'POST') {
    $body     = api_mobile_json_body();
    $kind     = $body['kind'] ?? '';
    $item_id  = $body['id'] ?? null;
    $decision = $body['decision'] ?? '';
    $comment  = $body['comment'] ?? '';

    if (!in_array($kind, ['catalog_request', 'workflow_task'], true)) {
        api_error(422, 'kind must be catalog_request or workflow_task');
    }
    if (!is_int($item_id) || $item_id < 1) {
        api_error(422, 'id must be a positive integer');
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        api_error(422, 'decision must be approve or reject');
    }
    if (!is_string($comment)) {
        api_error(422, 'comment must be a string');
    }
    $comment = trim($comment);
    if ($decision === 'reject' && $comment === '') {
        api_error(422, 'comment is required when rejecting');
    }
    if (mb_strlen($comment) > 2000) {
        api_error(422, 'comment is too long (2000 characters at most)');
    }
    $approve = $decision === 'approve';
    api_mobile_audit_context();

    if ($kind === 'catalog_request') {
        if (!$can_catalog) {
            api_error(403, 'Insufficient permissions');
        }
        $decidable = api_approvals_decidable_catalog($catalog_service, $uid);
        if (!isset($decidable[$item_id])) {
            // Unknown, already decided, or not addressed to this user: one answer for all three.
            api_error(404, 'Approval not found');
        }
        $override = $decidable[$item_id]['override'];
        $result = $catalog_service->decide($item_id, $uid, null, $approve, $comment, $override);
        if (!$result['ok']) {
            api_error(409, (string) $result['error']);
        }
        logAction('Service Catalog', $approve ? 'Approve' : 'Reject',
            "$session_name " . ($approve ? 'approved' : 'rejected') . " catalog request $item_id" . ($override ? ' (administrator override)' : '') . ' via the mobile API',
            0, $item_id);
        api_response(200, ['ok' => true, 'status' => (string) $result['status']]);
    }

    // workflow_task
    if (!$can_workflow) {
        api_error(403, 'Insufficient permissions');
    }
    $t = api_approvals_decidable_task($workflow_service, $item_id, $uid);
    if (!$t) {
        api_error(404, 'Approval not found');
    }
    try {
        if ($approve) {
            $workflow_service->approveTask($item_id, $uid, $comment);
        } else {
            $workflow_service->rejectTask($item_id, $uid, $comment);
        }
    } catch (\DomainException $e) {
        api_error(409, $e->getMessage());
    }
    logAction('Contact', 'Edit', "$session_name " . ($approve ? 'approved' : 'rejected') . " a workflow approval for {$t['contact_name']} via the mobile API",
        intval($t['contact_client_id'] ?? 0), intval($t['run_contact_id']));
    api_response(200, ['ok' => true, 'status' => $approve ? 'approved' : 'rejected']);
}

api_error(405, 'Method not allowed');
