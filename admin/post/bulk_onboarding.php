<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Workflow\BulkOnboarding;

if (isset($_POST['preview_bulk_onboarding'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $template_id = intval($_POST['workflow_template_id'] ?? 0);
    $text = (string) ($_POST['emails'] ?? '');
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        if (strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'csv' || $_FILES['file']['size'] > 1048576) {
            flash_alert('Upload a .csv file under 1 MB.', 'error');
            redirect('bulk_onboarding.php');
        }
        $text = (string) file_get_contents($_FILES['file']['tmp_name']);
    }
    $parsed = BulkOnboarding::parseCsv($text);
    if ($parsed['error'] !== null) {
        flash_alert(nullable_htmlentities($parsed['error']), 'error');
        redirect('bulk_onboarding.php');
    }
    $result = (new BulkOnboarding($mysqli))->preview($template_id, $parsed['rows']);
    if ($result['error'] !== null) {
        flash_alert(nullable_htmlentities($result['error']), 'error');
        redirect('bulk_onboarding.php');
    }
    $_SESSION['bulk_onboarding_preview'] = ['template_id' => $template_id, 'template' => ['name' => $result['template']['name'], 'type' => $result['template']['type']], 'rows' => $result['rows']];

    logAction("Settings", "Edit", "$session_name previewed a bulk workflow start (" . count($result['rows']) . " row(s))");
    redirect('bulk_onboarding.php');
}

if (isset($_POST['approve_bulk_onboarding'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $pending = $_SESSION['bulk_onboarding_preview'] ?? null;
    if (!$pending) {
        flash_alert('No pending bulk start to approve.', 'error');
        redirect('bulk_onboarding.php');
    }
    unset($_SESSION['bulk_onboarding_preview']);

    // This handler only loads for administrators, who manage every department; BulkOnboarding re-checks every row before starting it.
    $res = (new BulkOnboarding($mysqli))->commit((int) $pending['template_id'], $pending['rows'], (int) $session_user_id);

    \ITFlow\Audit\AuditService::record('workflow.bulk_started', $session_user_id, 'workflow_template', (int) $pending['template_id'], 'started', "$session_name started {$pending['template']['name']} for {$res['started']} people in bulk ({$res['skipped']} skipped, {$res['failed']} failed)", ['run_ids' => $res['run_ids']]);
    logAction("Settings", "Edit", "$session_name started workflow {$pending['template']['name']} for {$res['started']} people in bulk");

    flash_alert("Started {$res['started']} workflow(s); {$res['skipped']} already open, {$res['failed']} problem(s)" . ($res['messages'] ? ': ' . nullable_htmlentities(implode('; ', array_slice($res['messages'], 0, 5))) : ''), $res['failed'] ? 'error' : 'success');
    redirect('bulk_onboarding.php');
}

if (isset($_POST['cancel_bulk_onboarding'])) {
    validateCSRFToken($_POST['csrf_token']);
    unset($_SESSION['bulk_onboarding_preview']);
    redirect('bulk_onboarding.php');
}
