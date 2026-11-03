<?php
// GET  /api/v1/hr_requests.php   HR requests from the employee portal that the caller may decide (status=pending|approved|rejected|cancelled, default pending)
// POST /api/v1/hr_requests.php   {"id":N,"decision":"approve"|"reject","template_id":N,"comment":"..."}
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';
require_once $DOCUMENT_ROOT . '/src/Portal/EmployeeHome.php';
require_once $DOCUMENT_ROOT . '/src/Portal/HrRequests.php';

api_mobile_require_user_token();
$uid = intval($api_user_id);

// Same gate as agent/hr_requests.php: Departments, edit level. HrRequests::decide() re-checks the user's department access per request.
api_require_module_permission($mysqli, $uid, 'module_client', 2);

$hr = new \ITFlow\Portal\HrRequests($mysqli);
if (!\ITFlow\Portal\HrRequests::available($mysqli)) {
    api_error(503, 'Run the database update first');
}

if ($method === 'GET') {
    $status = (string) ($_GET['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        api_error(422, 'status must be pending, approved, rejected or cancelled');
    }
    $items = [];
    foreach ($hr->queue($hr->allowedClients($uid), $status, 100) as $r) {
        $p = json_decode((string) $r['hr_payload'], true) ?: [];
        $items[] = [
            'id'           => intval($r['hr_request_id']),
            'type'         => (string) $r['hr_type'],
            'status'       => (string) $r['hr_status'],
            'client_id'    => intval($r['hr_client_id']),
            'department'   => (string) $r['client_name'],
            'requested_by' => (string) ($r['requester_name'] ?? ''),
            'subject'      => (string) $r['hr_subject_name'],
            'subject_contact_id' => $r['hr_subject_contact_id'] === null ? null : intval($r['hr_subject_contact_id']),
            'effective_date' => $r['hr_effective_date'],
            'details'      => array_intersect_key($p, array_flip(['email', 'title', 'new_title', 'notes', 'manager_id'])),
            'requested_at' => (string) $r['hr_created_at'],
            'decided_at'   => $r['hr_decided_at'],
            'decision_comment' => $r['hr_decision_comment'],
            'run_id'       => $r['hr_run_id'] === null ? null : intval($r['hr_run_id']),
            'templates'    => $r['hr_status'] === 'pending' ? array_map(fn($t) => ['id' => intval($t['workflow_template_id']), 'name' => (string) $t['name']], $hr->templatesFor((string) $r['hr_type'])) : [],
        ];
    }
    api_response(200, ['items' => $items, 'counts' => ['total' => count($items)]]);
}

if ($method === 'POST') {
    $body = api_mobile_json_body();
    $id = $body['id'] ?? null;
    $decision = $body['decision'] ?? '';
    $template = $body['template_id'] ?? 0;
    $comment = $body['comment'] ?? '';
    if (!is_int($id) || $id < 1) {
        api_error(422, 'id must be a positive integer');
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        api_error(422, 'decision must be approve or reject');
    }
    if (!is_int($template) || $template < 0) {
        api_error(422, 'template_id must be a positive integer');
    }
    if (!is_string($comment) || mb_strlen($comment) > 500) {
        api_error(422, 'comment must be a string of 500 characters at most');
    }
    api_mobile_audit_context();
    $req = $hr->get($id);
    // Unknown, outside the caller's departments and not decidable look the same.
    if (!$req || !$hr->canDecide($uid, intval($req['hr_client_id']))) {
        api_error(404, 'Request not found');
    }
    try {
        $res = $hr->decide($id, $uid, $decision === 'approve', $template, $comment);
    } catch (\DomainException $e) {
        api_error(str_contains($e->getMessage(), 'already') || str_contains($e->getMessage(), 'Someone else') ? 409 : 422, $e->getMessage());
    }
    logAction('HR Request', $decision === 'approve' ? 'Approve' : 'Reject', "$session_name " . ($decision === 'approve' ? 'approved' : 'rejected') . " HR request $id via the mobile API", 0, $id);
    api_response(200, ['ok' => true, 'status' => $res['status'], 'run_id' => $res['run_id'] ?: null]);
}

api_error(405, 'Method not allowed');
