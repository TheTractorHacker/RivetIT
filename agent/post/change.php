<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

function itsmParseDatetimeLocal(?string $value): ?string {
    if (!$value) {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

if (isset($_POST['add_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $title = sanitizeInput($_POST['title']);
    $risk = in_array($_POST['risk'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['risk'] : 'low';
    $reason = sanitizeInput($_POST['reason'] ?? '');
    $impact = sanitizeInput($_POST['impact'] ?? '');
    $implementation_plan = sanitizeInput($_POST['implementation_plan'] ?? '');
    $rollback_plan = sanitizeInput($_POST['rollback_plan'] ?? '');
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);

    $change_id = (new \ITFlow\ITSM\ChangeService($mysqli))->create(
        $title,
        $reason !== '' ? $reason : null,
        $impact !== '' ? $impact : null,
        $risk,
        $implementation_plan !== '' ? $implementation_plan : null,
        $rollback_plan !== '' ? $rollback_plan : null,
        $scheduled_at,
        $session_user_id
    );

    logAction("Support", "Create", "$session_name created change \"$title\"", 0, $change_id);
    \ITFlow\Audit\AuditService::record('change.created', $session_user_id, 'change', $change_id, 'created', "$session_name created change \"$title\"");

    $linked_problem_id = intval($_POST['linked_problem_id'] ?? 0);
    if ($linked_problem_id) {
        (new \ITFlow\ITSM\ProblemService($mysqli))->linkChange($linked_problem_id, $change_id);
        logAction("Support", "Edit", "$session_name linked change #$change_id to problem #$linked_problem_id", 0, $linked_problem_id);
        flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> created and linked");
        redirect("problem_details.php?id=$linked_problem_id");
    }

    flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> created");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['edit_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $title = sanitizeInput($_POST['title']);
    $risk = in_array($_POST['risk'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['risk'] : 'low';
    $reason = sanitizeInput($_POST['reason'] ?? '');
    $impact = sanitizeInput($_POST['impact'] ?? '');
    $implementation_plan = sanitizeInput($_POST['implementation_plan'] ?? '');
    $rollback_plan = sanitizeInput($_POST['rollback_plan'] ?? '');

    $stmt = $mysqli->prepare(
        "UPDATE changes SET title = ?, risk = ?, reason = ?, impact = ?, implementation_plan = ?, rollback_plan = ? WHERE change_id = ?"
    );
    $stmt->bind_param('ssssssi', $title, $risk, $reason, $impact, $implementation_plan, $rollback_plan, $change_id);
    $stmt->execute();
    $stmt->close();

    logAction("Support", "Edit", "$session_name edited change \"$title\"", 0, $change_id);

    flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> updated");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['set_change_status'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $status = sanitizeInput($_POST['status']);
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);

    try {
        (new \ITFlow\ITSM\ChangeService($mysqli))->setStatus($change_id, $status, $scheduled_at);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect("change_details.php?id=$change_id");
    }

    logAction("Support", "Edit", "$session_name moved change #$change_id to $status", 0, $change_id);
    \ITFlow\Audit\AuditService::record('change.status_changed', $session_user_id, 'change', $change_id, 'status_changed', "$session_name moved change #$change_id to $status", ['status' => $status]);

    flash_alert("Change status updated to <strong>" . nullable_htmlentities($status) . "</strong>");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['reschedule_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);

    if (!$scheduled_at) {
        flash_alert('Invalid date/time', 'error');
        redirect("change_details.php?id=$change_id");
    }

    (new \ITFlow\ITSM\ChangeService($mysqli))->reschedule($change_id, $scheduled_at);

    logAction("Support", "Edit", "$session_name rescheduled change #$change_id to $scheduled_at", 0, $change_id);

    flash_alert('Change rescheduled');
    redirect("change_details.php?id=$change_id");
}
