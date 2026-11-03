<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * POST handler for agent/hr_requests.php: approve (starts the chosen workflow) or reject (starts nothing) a portal HR request.
 * The department is read from the stored request, never from the form, and HrRequests::decide() re-checks the user's Departments edit access
 * and department access.
 */

if (isset($_POST['decide_hr_request'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/HrRequests.php';
    $hr = new \ITFlow\Portal\HrRequests($mysqli);

    $request_id = intval($_POST['hr_request_id'] ?? 0);
    $approve = ($_POST['decide_hr_request'] ?? '') === 'approve';
    try {
        $res = $hr->decide($request_id, intval($session_user_id), $approve, intval($_POST['template_id'] ?? 0), (string) ($_POST['comment'] ?? ''));
        logAction("HR Request", $approve ? "Approve" : "Reject", "$session_name " . ($approve ? 'approved' : 'rejected') . " HR request $request_id", 0, $request_id);
        flash_alert(nullable_htmlentities($res['message']), $approve ? 'success' : 'info');
    } catch (\DomainException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'danger');
    }

    redirect('hr_requests.php');

}
