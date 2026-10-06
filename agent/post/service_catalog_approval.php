<?php

/*
 * RivetIT - POST handler for service catalog approvals (agent/service_catalog_approvals.php)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['decide_service_catalog_request'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support');

    require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
    $catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);

    $request_id = intval($_POST['request_id'] ?? 0);
    $approve = ($_POST['decide_service_catalog_request'] ?? '') === 'approve';
    $comment = trim((string) ($_POST['comment'] ?? ''));
    // Only an administrator may decide on behalf of the approvers; everyone else can only act on a row addressed to them.
    $override = !empty($_POST['override']) && $session_is_admin;

    if (!$approve && $comment === '') {
        flash_alert('Please give a reason when rejecting a request', 'danger');
        redirect();
    }

    $result = $catalog_service->decide($request_id, intval($session_user_id), null, $approve, $comment, $override);

    if ($result['ok']) {
        logAction("Service Catalog", $approve ? "Approve" : "Reject", "$session_name " . ($approve ? 'approved' : 'rejected') . " catalog request $request_id" . ($override ? ' (administrator override)' : ''), 0, $request_id);
        flash_alert($approve ? 'Request approved' : 'Request rejected');
    } else {
        flash_alert(nullable_htmlentities($result['error']), 'danger');
    }

    redirect();

}
