<?php

if (isset($_GET['create_outtake'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['create_outtake']);

    // Derive client from the ticket itself, not the (client-supplied) URL param
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    $sign_token = bin2hex(random_bytes(32));

    mysqli_query($mysqli, "INSERT INTO ticket_outtake_forms SET outtake_ticket_id = $ticket_id, outtake_sign_token = '$sign_token', outtake_created_by = $session_user_id");
    $outtake_id = mysqli_insert_id($mysqli);

    $sql_t = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
    $t = mysqli_fetch_assoc($sql_t);
    logAction("Outtake", "Create", "Created outtake form for ticket {$t['ticket_prefix']}{$t['ticket_number']}", $client_id, $ticket_id);

    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        echo json_encode(['ok' => true, 'outtake_id' => $outtake_id]);
        exit;
    }

    flash_alert("Outtake form created. <a href='outtake_form.php?outtake_id=$outtake_id&ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : '') . "'>Open form</a>");
    redirect();
}

if (isset($_POST['sign_outtake_in_person'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/outtake_functions.php';

    $outtake_id  = intval($_POST['outtake_id']);
    $signed_name = $_POST['signed_name'] ?? '';
    $signature   = $_POST['signature'] ?? '';

    $td = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.ticket_client_id FROM ticket_outtake_forms ot JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id WHERE ot.outtake_id = $outtake_id LIMIT 1"));
    if (!$td) { echo json_encode(['ok' => false, 'error' => 'Outtake form not found.']); exit; }
    if ($td['ticket_client_id']) { enforceClientAccess(intval($td['ticket_client_id'])); }

    $result = signOuttakeForm($mysqli, $outtake_id, $signed_name, $signature);

    echo json_encode($result);
    exit;
}

if (isset($_POST['save_outtake_notes'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $outtake_id = intval($_POST['outtake_id']);
    $notes      = sanitizeInput($_POST['outtake_tech_notes']);

    // Derive client from the outtake form's own ticket join - a separately
    // supplied ticket_id must never be trusted for authorization, since it
    // could reference a ticket the caller has access to while outtake_id
    // references a different one.
    $td = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ot.outtake_ticket_id, t.ticket_client_id FROM ticket_outtake_forms ot JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id WHERE ot.outtake_id = $outtake_id LIMIT 1"));
    if (!$td) { flash_alert("Outtake form not found.", "error"); redirect(); }

    $ticket_id = intval($td['outtake_ticket_id']);
    $client_id = intval($td['ticket_client_id']);
    if ($client_id) { enforceClientAccess($client_id); }

    mysqli_query($mysqli, "UPDATE ticket_outtake_forms SET outtake_tech_notes = '$notes' WHERE outtake_id = $outtake_id");
    flash_alert("Outtake form notes saved.");
    redirect();
}

if (isset($_POST['send_outtake_email'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $outtake_id = intval($_POST['outtake_id']);

    // Fetch by outtake_id (the resource actually being acted on) and derive
    // client_id from its own ticket join - a separately POSTed ticket_id must
    // never be trusted for authorization, since it could reference a ticket
    // the caller has access to while outtake_id references a different one.
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ot.outtake_sign_token, ot.outtake_ticket_id, t.ticket_client_id,
                t.ticket_prefix, t.ticket_number, t.ticket_subject,
                co.contact_email, co.contact_name, c.client_name
         FROM ticket_outtake_forms ot
         JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
         LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         WHERE ot.outtake_id = $outtake_id LIMIT 1"));

    if (!$row) {
        flash_alert("Outtake form not found.", "error");
        redirect();
    }

    $ticket_id = intval($row['outtake_ticket_id']);
    $client_id = intval($row['ticket_client_id']);
    if ($client_id) { enforceClientAccess($client_id); }

    if (empty($row['contact_email'])) {
        flash_alert("No contact email found for this ticket.", "error");
        redirect();
    }

    $sign_url       = "https://$config_base_url/guest/outtake_sign.php?token=" . $row['outtake_sign_token'];
    $ticket_num     = $row['ticket_prefix'] . intval($row['ticket_number']);
    $ticket_subj    = $row['ticket_subject'];
    $recipient      = mysqli_real_escape_string($mysqli, $row['contact_email']);
    $recipient_name = mysqli_real_escape_string($mysqli, $row['contact_name'] ?? $row['client_name']);
    $from           = mysqli_real_escape_string($mysqli, $config_ticket_from_email);
    $from_name      = mysqli_real_escape_string($mysqli, $config_ticket_from_name ?: $session_company_name);
    $subject        = mysqli_real_escape_string($mysqli, "Please sign your outtake form — Ticket $ticket_num");
    $body           = mysqli_real_escape_string($mysqli,
        "Hello " . ($row['contact_name'] ?: $row['client_name']) . ",<br><br>"
        . "Your outtake form for ticket <strong>$ticket_num — $ticket_subj</strong> is ready to sign.<br><br>"
        . "Please click the link below to review and sign. No account or login is required:<br><br>"
        . "<a href=\"$sign_url\">$sign_url</a><br><br>"
        . "This link is unique to your form. Do not share it with others.<br><br>"
        . "Thank you,<br>$session_company_name"
    );

    mysqli_query($mysqli, "INSERT INTO email_queue SET
        email_recipient      = '$recipient',
        email_recipient_name = '$recipient_name',
        email_from           = '$from',
        email_from_name      = '$from_name',
        email_subject        = '$subject',
        email_content        = '$body',
        email_queued_at      = NOW()");

    logAction("Outtake", "Email", "Sent outtake signing link for ticket $ticket_num to {$row['contact_email']}", $client_id, $ticket_id);
    flash_alert("Signing link emailed to <strong>{$row['contact_email']}</strong>.", "success");
    redirect();
}

if (isset($_GET['delete_outtake'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $outtake_id = intval($_GET['delete_outtake']);

    // Derive client from the outtake form's own ticket join - a separately
    // supplied ticket_id must never be trusted for authorization, since it
    // could reference a ticket the caller has access to while outtake_id
    // references a different one.
    $td = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ot.outtake_ticket_id, t.ticket_client_id FROM ticket_outtake_forms ot JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id WHERE ot.outtake_id = $outtake_id LIMIT 1"));
    if (!$td) { flash_alert("Outtake form not found.", "error"); redirect(); }

    $ticket_id = intval($td['outtake_ticket_id']);
    $client_id = intval($td['ticket_client_id']);
    if ($client_id) { enforceClientAccess($client_id); }

    mysqli_query($mysqli, "DELETE FROM ticket_outtake_forms WHERE outtake_id = $outtake_id");
    logAction("Outtake", "Delete", "Deleted outtake form #$outtake_id", $client_id, $ticket_id);
    flash_alert("Outtake form deleted.");
    redirect();
}
