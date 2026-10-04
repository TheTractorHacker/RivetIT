<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

function findTicketIdForLinking($mysqli, string $input): int {
    $digits = preg_replace('/[^0-9]/', '', $input);
    if ($digits === '') {
        return 0;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_number = " . intval($digits) . " ORDER BY ticket_id DESC LIMIT 1"));
    return $row ? intval($row['ticket_id']) : 0;
}

if (isset($_POST['add_problem'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description'] ?? '');

    $problem_id = (new \ITFlow\ITSM\ProblemService($mysqli))->create($title, $description !== '' ? $description : null, $session_user_id);

    logAction("Support", "Create", "$session_name created problem \"$title\"", 0, $problem_id);
    \ITFlow\Audit\AuditService::record('problem.created', $session_user_id, 'problem', $problem_id, 'created', "$session_name created problem \"$title\"");

    flash_alert("Problem <strong>" . nullable_htmlentities($title) . "</strong> created");
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['edit_problem'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);
    $title = sanitizeInput($_POST['title']);
    $description = sanitizeInput($_POST['description'] ?? '');

    $stmt = $mysqli->prepare("UPDATE problems SET title = ?, description = ? WHERE problem_id = ?");
    $stmt->bind_param('ssi', $title, $description, $problem_id);
    $stmt->execute();
    $stmt->close();

    logAction("Support", "Edit", "$session_name edited problem \"$title\"", 0, $problem_id);

    flash_alert("Problem <strong>" . nullable_htmlentities($title) . "</strong> updated");
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['set_problem_status'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);
    $status = sanitizeInput($_POST['status']);

    try {
        (new \ITFlow\ITSM\ProblemService($mysqli))->setStatus($problem_id, $status);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect("problem_details.php?id=$problem_id");
    }

    logAction("Support", "Edit", "$session_name moved problem #$problem_id to $status", 0, $problem_id);
    \ITFlow\Audit\AuditService::record('problem.status_changed', $session_user_id, 'problem', $problem_id, 'status_changed', "$session_name moved problem #$problem_id to $status", ['status' => $status]);

    flash_alert("Problem status updated to <strong>" . nullable_htmlentities($status) . "</strong>");
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['link_problem_ticket'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);
    $ticket_id = findTicketIdForLinking($mysqli, $_POST['ticket_number'] ?? '');

    if (!$ticket_id) {
        flash_alert('Ticket not found', 'error');
        redirect("problem_details.php?id=$problem_id");
    }

    (new \ITFlow\ITSM\ProblemService($mysqli))->linkTicket($problem_id, $ticket_id);

    logAction("Support", "Edit", "$session_name linked ticket #$ticket_id to problem #$problem_id", 0, $problem_id);

    flash_alert('Ticket linked to problem');
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['unlink_problem_ticket'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);
    $ticket_id = intval($_POST['ticket_id']);

    (new \ITFlow\ITSM\ProblemService($mysqli))->unlinkTicket($problem_id, $ticket_id);

    logAction("Support", "Edit", "$session_name unlinked ticket #$ticket_id from problem #$problem_id", 0, $problem_id);

    flash_alert('Ticket unlinked from problem');
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['link_problem_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);
    $change_id = intval($_POST['change_id'] ?? 0);

    if (!$change_id) {
        flash_alert('Select a change to link', 'error');
        redirect("problem_details.php?id=$problem_id");
    }

    (new \ITFlow\ITSM\ProblemService($mysqli))->linkChange($problem_id, $change_id);

    logAction("Support", "Edit", "$session_name linked change #$change_id to problem #$problem_id", 0, $problem_id);

    flash_alert('Change linked to problem');
    redirect("problem_details.php?id=$problem_id");
}

if (isset($_POST['unlink_problem_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $problem_id = intval($_POST['problem_id']);

    (new \ITFlow\ITSM\ProblemService($mysqli))->linkChange($problem_id, null);

    logAction("Support", "Edit", "$session_name unlinked the fixing change from problem #$problem_id", 0, $problem_id);

    flash_alert('Change unlinked from problem');
    redirect("problem_details.php?id=$problem_id");
}
