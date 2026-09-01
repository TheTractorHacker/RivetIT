<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['convert_mail_request'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $mail_request_id = intval($_POST['mail_request_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);

    if ($mail_request_id <= 0) {
        flash_alert("Request not found", 'error');
        redirect("mail_requests.php");
    }

    if ($client_id > 0) {
        $valid_client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1"));
        if (!$valid_client) {
            $client_id = 0;
        }
    }

    // Make sure this agent is actually permitted to act on the client they're converting to
    if ($client_id > 0) {
        enforceClientAccess($client_id);
    }

    $ticket_id = convertMailRequestToTicket($mail_request_id, $client_id);

    if (!$ticket_id) {
        flash_alert("Could not convert this request - it may have already been converted or dismissed.", 'error');
        redirect("mail_requests.php");
    }

    flash_alert("Ticket created from request");
    redirect("/agent/ticket.php?ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : ''));
}

if (isset($_GET['dismiss_mail_request'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $mail_request_id = intval($_GET['dismiss_mail_request']);

    // mail_requests aren't tied to a client directly, but if the mailbox they came in on
    // has a default client set, respect that scoping the same way the convert flow does
    $mr_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT m.mailbox_default_client_id
        FROM mail_requests mr
        LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id
        WHERE mr.mail_request_id = $mail_request_id AND mr.mail_request_archived_at IS NULL LIMIT 1"));

    $mr_default_client_id = intval($mr_row['mailbox_default_client_id'] ?? 0);

    if ($mr_default_client_id > 0) {
        enforceClientAccess($mr_default_client_id);
    }

    if (dismissMailRequest($mail_request_id)) {
        flash_alert("Request dismissed", 'error');
    } else {
        flash_alert("Request not found", 'error');
    }

    redirect("mail_requests.php");
}
