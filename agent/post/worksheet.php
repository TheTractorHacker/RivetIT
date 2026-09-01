<?php

if (isset($_POST['add_ticket_worksheet'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $template_id = intval($_POST['worksheet_template_id']);
    $is_outtake = isset($_POST['is_outtake']) ? 1 : 0;
    $sign_token = $is_outtake ? bin2hex(random_bytes(32)) : null;
    $token_sql = $sign_token ? "'$sign_token'" : 'NULL';

    $sql_ticket = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_prefix, ticket_number FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
    $t = mysqli_fetch_assoc($sql_ticket);
    $client_id = intval($t['ticket_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli, "INSERT INTO ticket_worksheets SET worksheet_ticket_id = $ticket_id, worksheet_template_id = $template_id, worksheet_is_outtake = $is_outtake, worksheet_sign_token = $token_sql, worksheet_created_by = $session_user_id");

    $worksheet_id = mysqli_insert_id($mysqli);
    $tmpl_name = sanitizeInput(mysqli_fetch_row(mysqli_query($mysqli, "SELECT worksheet_template_name FROM worksheet_templates WHERE worksheet_template_id = $template_id LIMIT 1"))[0]);

    logAction("Worksheet", "Add", "Added worksheet $tmpl_name to ticket {$t['ticket_prefix']}{$t['ticket_number']}", $client_id, $ticket_id);
    flash_alert("Worksheet added. <a href='worksheet.php?worksheet_id=$worksheet_id&ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : '') . "'>Fill it out now</a>");
    redirect();
}

if (isset($_POST['save_worksheet']) || isset($_POST['complete_worksheet'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $worksheet_id = intval($_POST['worksheet_id']);
    $ticket_id = intval($_POST['ticket_id']);

    // Derive client from the ticket itself, not the (client-supplied) form field
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    $sql_ws = mysqli_query($mysqli, "SELECT worksheet_template_id, worksheet_is_outtake FROM ticket_worksheets WHERE worksheet_id = $worksheet_id LIMIT 1");
    $ws = mysqli_fetch_assoc($sql_ws);
    $template_id = intval($ws['worksheet_template_id']);

    $fields = mysqli_query($mysqli, "SELECT field_id FROM worksheet_template_fields WHERE field_template_id = $template_id ORDER BY field_order");
    while ($f = mysqli_fetch_assoc($fields)) {
        $fid = intval($f['field_id']);
        $key = "field_$fid";
        $val = isset($_POST[$key]) ? sanitizeInput($_POST[$key]) : '';

        $existing = mysqli_fetch_row(mysqli_query($mysqli, "SELECT response_id FROM ticket_worksheet_responses WHERE response_worksheet_id = $worksheet_id AND response_field_id = $fid LIMIT 1"));
        if ($existing) {
            mysqli_query($mysqli, "UPDATE ticket_worksheet_responses SET response_value = '$val' WHERE response_id = {$existing[0]}");
        } else {
            mysqli_query($mysqli, "INSERT INTO ticket_worksheet_responses SET response_worksheet_id = $worksheet_id, response_field_id = $fid, response_value = '$val'");
        }
    }

    if (isset($_POST['complete_worksheet'])) {
        mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_completed_at = NOW() WHERE worksheet_id = $worksheet_id");
        flash_alert("Worksheet saved and marked complete.");
    } else {
        flash_alert("Worksheet saved.");
    }
    redirect();
}

if (isset($_GET['unfinalize_worksheet'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $worksheet_id = intval($_GET['unfinalize_worksheet']);
    $ticket_id    = intval($_GET['ticket_id']);

    // Derive client from the ticket itself, not the (client-supplied) URL param
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_completed_at = NULL WHERE worksheet_id = $worksheet_id AND worksheet_signed_at IS NULL");

    logAction("Worksheet", "Unfinalize", "Unfinalized worksheet #$worksheet_id on ticket $ticket_id", $client_id, $ticket_id);
    flash_alert("Worksheet unlocked and set back to editable.");
    header("Location: ticket.php?ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : ''));
    exit;
}

if (isset($_GET['delete_ticket_worksheet'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $worksheet_id = intval($_GET['delete_ticket_worksheet']);
    $ticket_id = intval($_GET['ticket_id']);

    // Derive client from the ticket itself, not the (client-supplied) URL param
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    mysqli_query($mysqli, "DELETE FROM ticket_worksheet_responses WHERE response_worksheet_id = $worksheet_id");
    mysqli_query($mysqli, "DELETE FROM ticket_worksheets WHERE worksheet_id = $worksheet_id");

    flash_alert("Worksheet deleted.");
    header("Location: ticket.php?ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : ''));
    exit;
}
