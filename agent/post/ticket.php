<?php

/*
 * ITFlow - GET/POST request handler for client tickets
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sla_functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/redis_functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ai_functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ticket_automation_dispatch.php';

if (isset($_POST['add_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    $assigned_to = resolveTicketAssignee(intval($_POST['assigned_to']));
    if ($assigned_to == 0) {
        $ticket_status = 1;
    } else {
        $ticket_status = 2;
    }
    $contact_id = intval($_POST['contact_id']);
    $category_id = intval($_POST['category_id']);
    $subject = sanitizeInput($_POST['subject']);
    $priority = sanitizeInput($_POST['priority']);
    $delivery_method = in_array($_POST['delivery_method'] ?? '', ['Remote', 'Onsite'], true) ? $_POST['delivery_method'] : null;
    $details = mysqli_real_escape_string($mysqli, $_POST['details']);
    $vendor_ticket_number = sanitizeInput($_POST['vendor_ticket_number']);
    $vendor_id = intval($_POST['vendor_id']);
    $asset_id = intval($_POST['asset_id']);
    $location_id = intval($_POST['location_id']);
    $project_id = intval($_POST['project_id']);
    $use_primary_contact = intval($_POST['use_primary_contact'] ?? 0);
    $ticket_template_id = intval($_POST['ticket_template_id']);
    $billable = intval($_POST['billable'] ?? 0);
    // Validate/clean due field
    $dueInput = $_POST['due'] ?? null;
    if ($dueInput === null || trim($dueInput) === '') {
        $due = 'NULL'; // prepare as SQL-safe string
    } else {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $dueInput); // for <input type="datetime-local">
        if ($d !== false) {
            $due = "'" . $d->format('Y-m-d H:i:s') . "'"; // wrap in quotes for SQL
        } else {
            $due = 'NULL'; // fallback if invalid
        }
    }

    enforceClientAccess();

    // Add the primary contact as the ticket contact if "Use primary contact" is checked
    if ($use_primary_contact == 1) {
        $sql = mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_client_id = $client_id AND contact_primary = 1");
        $row = mysqli_fetch_assoc($sql);
        $contact_id = intval($row['contact_id']);
    }

    // Atomically increment and get the new ticket number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");

    $ticket_number = mysqli_insert_id($mysqli);

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_ticket_prefix = sanitizeInput($config_ticket_prefix);
    $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
    $config_base_url = sanitizeInput($config_base_url);

    //Generate a unique URL key for clients to access
    $url_key = randomString(32);

    // SLA: calculate response/resolution deadlines from linked contract
    $contract_id = intval($_POST['contract_id'] ?? 0);
    $sla_response_due = 'NULL';
    $sla_resolution_due = 'NULL';
    if ($contract_id > 0) {
        $sql_ct = mysqli_query($mysqli, "SELECT * FROM contracts WHERE contract_id = $contract_id AND contract_client_id = $client_id LIMIT 1");
        if ($ct = mysqli_fetch_assoc($sql_ct)) {
            $p = strtolower($priority);
            $r_hours  = $p === 'low' ? intval($ct['contract_sla_low_response_time'])    : ($p === 'medium' ? intval($ct['contract_sla_medium_response_time'])    : intval($ct['contract_sla_high_response_time']));
            $res_hours= $p === 'low' ? intval($ct['contract_sla_low_resolution_time'])  : ($p === 'medium' ? intval($ct['contract_sla_medium_resolution_time'])  : intval($ct['contract_sla_high_resolution_time']));
            if ($r_hours > 0)   $sla_response_due   = "'" . date('Y-m-d H:i:s', strtotime("+{$r_hours} hours"))   . "'";
            if ($res_hours > 0) $sla_resolution_due = "'" . date('Y-m-d H:i:s', strtotime("+{$res_hours} hours")) . "'";
        }
    }
    $contract_id_sql = $contract_id > 0 ? $contract_id : 'NULL';
    $delivery_method_sql = $delivery_method !== null ? "'" . mysqli_real_escape_string($mysqli, $delivery_method) . "'" : 'NULL';

    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'Agent', ticket_category = $category_id, ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = '$billable', ticket_status = '$ticket_status', ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_vendor_id = $vendor_id, ticket_location_id = $location_id, ticket_asset_id = $asset_id, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact_id, ticket_url_key = '$url_key', ticket_due_at = $due, ticket_client_id = $client_id, ticket_invoice_id = 0, ticket_project_id = $project_id, ticket_contract_id = $contract_id_sql, ticket_sla_response_due = $sla_response_due, ticket_sla_resolution_due = $sla_resolution_due, ticket_delivery_method = $delivery_method_sql");

    $ticket_id = mysqli_insert_id($mysqli);

    // Apply the SLA engine (policy-aware; falls back to the legacy contract-hour
    // values just inserted when no policy target applies).
    recalculateTicketSla($mysqli, $ticket_id);

    // Run ticket_created automation rules (fire-and-forget; never blocks/fails insert)
    runTicketCreatedAutomation($mysqli, $ticket_id);

    // Notify the assigned agent of the new ticket
    if ($assigned_to != 0 && $assigned_to != $session_user_id) {
        $client_uri = $client_id ? "&client_id=$client_id" : '';
        notifyUser($assigned_to, 'Ticket', "New ticket $config_ticket_prefix$ticket_number - $subject has been assigned to you by $session_name", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id);
    }

    // Add Tasks from Template if Template was selected
    if($ticket_template_id) {
        // Get Associated Tasks from the ticket template
        $sql_task_templates = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id");

        if (mysqli_num_rows($sql_task_templates) > 0) {
            while ($row = mysqli_fetch_assoc($sql_task_templates)) {
                $task_order = intval($row['task_template_order']);
                $task_name = sanitizeInput($row['task_template_name']);
                $task_completion_estimate = intval($row['task_template_completion_estimate']);

                mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_name', task_order = $task_order, task_completion_estimate = $task_completion_estimate, task_ticket_id = $ticket_id");
            }
        }
    }

    // Add Watchers
    if (isset($_POST['watchers'])) {
        foreach ($_POST['watchers'] as $watcher) {
            $watcher_email = sanitizeInput($watcher);
            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$watcher_email', watcher_ticket_id = $ticket_id");
        }
    }

    // Add Additional Assets
    if (isset($_POST['additional_assets'])) {
        foreach ($_POST['additional_assets'] as $additional_asset) {
            $additional_asset_id = intval($additional_asset);
            mysqli_query($mysqli, "INSERT INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $additional_asset_id");
        }
    }

    // E-mail client
    if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get contact/ticket details
        $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_category, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_created_by, ticket_assigned_to, ticket_client_id FROM tickets
              LEFT JOIN clients ON ticket_client_id = client_id
              LEFT JOIN contacts ON ticket_contact_id = contact_id
              WHERE ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($sql);

        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_category = sanitizeInput($row['ticket_category']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
        $ticket_priority = sanitizeInput($row['ticket_priority']);
        $ticket_status = sanitizeInput($row['ticket_status']);
        $ticket_status_name = sanitizeInput(getTicketStatusName($row['ticket_status']));
        $client_id = intval($row['ticket_client_id']);
        $ticket_created_by = intval($row['ticket_created_by']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);

        // Get Company Phone Number
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAILING

        $subject = "Ticket Created [$ticket_prefix$ticket_number] - $ticket_subject";
        $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>A ticket regarding \"$ticket_subject\" has been created for you.<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: Open<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        // Verify contact email is valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {


            // Email Ticket Contact
            // Queue Mail
            $data = [];

            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body
            ];
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
        $body .= "<br><br>----------------------------------------<br>YOU HAVE BEEN ADDED AS A COLLABORATOR FOR THIS TICKET";
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = sanitizeInput($row['watcher_email']);

            // Queue Mail
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => $subject,
                'body' => $body
            ];
        }
        addToMailQueue($data);

        // END EMAILING

    }

    // Custom action/notif handler
    customAction('ticket_create', $ticket_id);

    logAction("Ticket", "Create", "$session_name created ticket $config_ticket_prefix$ticket_number - $ticket_subject", $client_id, $ticket_id);
    queueWebhookEvent('ticket.created', getWebhookTicketPayload($ticket_id));

    flash_alert("Ticket <strong>$config_ticket_prefix$ticket_number</strong> created");

    redirect("ticket.php?client_id=$client_id&ticket_id=$ticket_id");

}

if (isset($_POST['edit_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $contact_id = intval($_POST['contact_id']);
    $assigned_to = intval($_POST['assigned_to']);
    $notify = intval($_POST['contact_notify'] ?? 0);
    $category_id = intval($_POST['category_id']);
    $ticket_subject = sanitizeInput($_POST['subject']);
    $billable = intval($_POST['billable'] ?? 0);
    $ticket_priority = sanitizeInput($_POST['priority']);
    $details = mysqli_real_escape_string($mysqli, $_POST['details']);
    $vendor_ticket_number = sanitizeInput($_POST['vendor_ticket_number']);
    $vendor_id = intval($_POST['vendor_id']);
    $asset_id = intval($_POST['asset_id']);
    $location_id = intval($_POST['location_id']);
    $project_id = intval($_POST['project_id']);
    // Validate/clean due field
    $dueInput = $_POST['due'] ?? null;
    if ($dueInput === null || trim($dueInput) === '') {
        $due = 'NULL'; // prepare as SQL-safe string
    } else {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $dueInput); // for <input type="datetime-local">
        if ($d !== false) {
            $due = "'" . $d->format('Y-m-d H:i:s') . "'"; // wrap in quotes for SQL
        } else {
            $due = 'NULL'; // fallback if invalid
        }
    }

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id, ticket_subject = '$ticket_subject', ticket_priority = '$ticket_priority', ticket_billable = $billable, ticket_details = '$details', ticket_due_at = $due, ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_contact_id = $contact_id, ticket_assigned_to = $assigned_to, ticket_vendor_id = $vendor_id, ticket_location_id = $location_id, ticket_asset_id = $asset_id, ticket_project_id = $project_id WHERE ticket_id = $ticket_id");

    recalculateTicketSla($mysqli, $ticket_id);

    // Add Additional Assets
    if (isset($_POST['additional_assets'])) {
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
        foreach ($_POST['additional_assets'] as $additional_asset) {
            $additional_asset_id = intval($additional_asset);
            mysqli_query($mysqli, "INSERT INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $additional_asset_id");
        }
    } else {
        // If no additional assets are provided, delete them all
        // This handles cases where the assets input might be cleared or not set at all.
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
    }

    // Get contact/ticket details after update for logging / email purposes
    $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_category, ticket_details, ticket_status_name, ticket_created_by, ticket_assigned_to, ticket_url_key, ticket_client_id FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id
        AND ticket_closed_at IS NULL");
    $row = mysqli_fetch_assoc($sql);

    $contact_name = sanitizeInput($row['contact_name']);
    $contact_email = sanitizeInput($row['contact_email']);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = sanitizeInput($row['ticket_category']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_status = sanitizeInput($row['ticket_status_name']);
    $ticket_created_by = intval($row['ticket_created_by']);
    $ticket_assigned_to = intval($row['ticket_assigned_to']);
    $url_key = sanitizeInput($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);

    // Notify new contact if selected
    if ($notify && (!empty($config_smtp_host) || !empty($config_smtp_provider))) {

        // Get Company Name Phone Number and Sanitize for Email Sending
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // Email content
        $data = []; // Queue array

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        $subject = "Ticket Created - [$ticket_prefix$ticket_number] - $ticket_subject";
        $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>A ticket regarding \"$ticket_subject\" has been created for you.<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";


        // Only add contact to email queue if email is valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body
            ];
        }

        addToMailQueue($data);
    }

    // Custom action/notif handler
    customAction('ticket_update', $ticket_id);

    logAction("Ticket", "Edit", "$session_name edited ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Ticket <strong>$ticket_prefix$ticket_number</strong> updated");

    redirect();

}

if (isset($_POST['edit_ticket_priority'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $priority = sanitizeInput($_POST['priority']);
    $client_id = intval($_POST['client_id']);

    // Get ticket details before updating
    $sql = mysqli_query($mysqli, "SELECT
        ticket_prefix, ticket_number, ticket_priority, ticket_status_name, ticket_client_id
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id"
    );
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $original_priority = sanitizeInput($row['ticket_priority']);
    $ticket_status = sanitizeInput($row['ticket_status_name']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$priority' WHERE ticket_id = $ticket_id");

    recalculateTicketSla($mysqli, $ticket_id);

    // Update Ticket History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status', ticket_history_description = '$session_name changed priority from $original_priority to $priority', ticket_history_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name changed priority from $original_priority to $priority for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    customAction('ticket_update', $ticket_id);

}

if (isset($_POST['edit_ticket_delivery_method'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $delivery_method = sanitizeInput($_POST['delivery_method']);
    if (!in_array($delivery_method, ['Remote', 'Onsite'], true)) {
        $delivery_method = null;
    }

    // Look up the ticket's real client - never trust the POSTed client_id for
    // the access check, or a restricted agent could edit another client's
    // ticket by simply lying about which client it belongs to.
    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    if (!$ticket_row) {
        redirect();
    }
    $client_id = intval($ticket_row['ticket_client_id']);

    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $delivery_method_sql = $delivery_method !== null ? "'" . mysqli_real_escape_string($mysqli, $delivery_method) . "'" : 'NULL';
    mysqli_query($mysqli, "UPDATE tickets SET ticket_delivery_method = $delivery_method_sql WHERE ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name set delivery method to " . ($delivery_method ?? 'Not set') . " for ticket $ticket_id", $client_id, $ticket_id);

    redirect();

    flash_alert("Priority updated from <strong>$original_priority</strong> to <strong>$priority</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_status'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $new_status_id = intval($_POST['ticket_status_id']);
    $client_id = intval($_POST['client_id']);
    $is_resolving = ($new_status_id == 4);
    if ($is_resolving) { $new_status_id = 5; }

    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_status, ticket_status_name, ticket_client_id FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $ticket_id");
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $original_status = sanitizeInput($row['ticket_status_name']);
    $old_status_id = intval($row['ticket_status']);
    $client_id = intval($row['ticket_client_id']);

    if ($client_id) {
        enforceClientAccess();
    }

    $sql_new_status = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_id = $new_status_id LIMIT 1");
    $new_status_row = mysqli_fetch_assoc($sql_new_status);
    $new_status_name = sanitizeInput($new_status_row['ticket_status_name']);
    $new_status_color = sanitizeInput($new_status_row['ticket_status_color']);

    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id WHERE ticket_id = $ticket_id");
    if ($new_status_id == 5) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id AND ticket_resolved_at IS NULL");
    } else {
        // Moving a ticket to any non-Closed status must clear these, or the ticket
        // page (which gates the reply form / edit controls on ticket_closed_at, not
        // ticket_status) keeps rendering it as closed even though the status changed.
        mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");
    }

    // SLA pause-on-hold: accrue/clear paused time across this status change.
    slaAccruePause($mysqli, $ticket_id, $old_status_id, $new_status_id);

    if ($is_resolving) {
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");
        logAction("Ticket", "Closed", "$session_name closed ticket $ticket_prefix$ticket_number via Resolve", $client_id, $ticket_id);
        customAction('ticket_close', $ticket_id);
    }

    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$new_status_name', ticket_history_description = '$session_name changed status from $original_status to $new_status_name', ticket_history_ticket_id = $ticket_id");

    publishTicketEvent($ticket_id, 'status', ['status_id' => $new_status_id, 'status_name' => $new_status_name, 'status_color' => $new_status_color, 'by' => $session_name]);

    logAction("Ticket", "Edit", "$session_name changed status from $original_status to $new_status_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
    queueWebhookEvent('ticket.status_changed', getWebhookTicketPayload($ticket_id));

    customAction('ticket_update', $ticket_id);

    flash_alert("Status updated from <strong>$original_status</strong> to <strong>$new_status_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_contact'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $contact_id = intval($_POST['contact']);
    $notify = intval($_POST['contact_notify']) ?? 0;

    // Get Original contact, and ticket details
    $sql = mysqli_query($mysqli, "SELECT
        contact_name, ticket_prefix, ticket_number, ticket_status_name, ticket_subject, ticket_details, ticket_url_key, ticket_client_id
        FROM tickets
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id"
    );
    $row = mysqli_fetch_assoc($sql);

    // Original contact
    $original_contact_name = !empty($row['contact_name']) ? sanitizeInput($row['contact_name']) : 'No one';

    // Ticket details
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status = sanitizeInput($row['ticket_status_name']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $url_key = sanitizeInput($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Update the contact
    mysqli_query($mysqli, "UPDATE tickets SET ticket_contact_id = $contact_id WHERE ticket_id = $ticket_id");

    // Get New contact details
    $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_id = $contact_id");
    $row = mysqli_fetch_assoc($sql);

    $contact_name = !empty($row['contact_name']) ? sanitizeInput($row['contact_name']) : 'No one';
    $contact_email = sanitizeInput($row['contact_email']);

    // Notify new contact (if selected, valid & configured)
    if ($notify && filter_var($contact_email, FILTER_VALIDATE_EMAIL) && (!empty($config_smtp_host) || !empty($config_smtp_provider))) {

        // Get Company Phone Number
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
        $config_ticket_from_name = sanitizeInput($config_ticket_from_name);

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        // Email content
        $data = []; // Queue array

        $subject = "Ticket Created - [$ticket_prefix$ticket_number] - $ticket_subject";
        $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>A ticket regarding \"$ticket_subject\" has been created for you.<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        $data[] = [
            'from' => $ticket_from['email'],
            'from_name' => $ticket_from['name'],
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => $subject,
            'body' => $body
        ];

        addToMailQueue($data);
    }

    // Custom action/notif handler
    customAction('ticket_update', $ticket_id);

    // Update Ticket History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status', ticket_history_description = '$session_name changed the contact from $original_contact_name to $contact_name', ticket_history_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name changed the contact from $original_contact_name to $contact_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Contact changed from <strong>$original_contact_name</strong> to <strong>$contact_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $project_id = intval($_POST['project']);

    $project_name = sanitizeInput(getFieldById('projects', $project_id, 'project_name'));
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    $ticket_prefix = sanitizeInput(getFieldById('tickets', $ticket_id, 'ticket_prefix'));
    $ticket_number = sanitizeInput(getFieldById('tickets', $ticket_id, 'ticket_number'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_project_id = $project_id WHERE ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name set ticket $ticket_prefix$ticket_number project to $project_name", $client_id, $ticket_id);

    flash_alert("Project changed to <strong>$project_name</strong> for Ticket <strong>$ticket_prefix$ticket_number</strong>");

    redirect();

}

if (isset($_POST['update_ticket_tags'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Delete existing tags
    mysqli_query($mysqli, "DELETE FROM ticket_tags WHERE ticket_tag_ticket_id = $ticket_id");

    // Add new tags
    if (isset($_POST['tags'])) {
        foreach ($_POST['tags'] as $tag) {
            $tag = intval($tag);
            mysqli_query($mysqli, "INSERT INTO ticket_tags SET ticket_tag_ticket_id = $ticket_id, ticket_tag_tag_id = $tag");
        }
    }

    flash_alert("Tags updated");

    redirect();

}

if (isset($_POST['add_ticket_watcher'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $watcher_emails = preg_split("/,| |;/", $_POST['watcher_email']); // Split on comma, semicolon or space, we sanitize later
    $notify = intval($_POST['watcher_notify'] ?? 0);

    // Get contact/ticket details
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_category, ticket_subject, ticket_details, ticket_priority, ticket_status_name, ticket_url_key, ticket_created_by, ticket_assigned_to, ticket_client_id FROM tickets
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_id = $ticket_id
    AND ticket_closed_at IS NULL");
    $row = mysqli_fetch_assoc($sql);

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = sanitizeInput($row['ticket_category']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_priority = sanitizeInput($row['ticket_priority']);
    $ticket_status = sanitizeInput($row['ticket_status_name']);
    $url_key = sanitizeInput($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);
    $ticket_created_by = intval($row['ticket_created_by']);
    $ticket_assigned_to = intval($row['ticket_assigned_to']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Get Company Phone Number
    $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_name = sanitizeInput($row['company_name']);
    $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

    $ticket_from = resolveTicketFromIdentity($ticket_id);

    // Process each watcher in list
    foreach ($watcher_emails as $watcher_email) {

        if (filter_var($watcher_email, FILTER_VALIDATE_EMAIL)) {

            $watcher_email = sanitizeInput($watcher_email);

            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$watcher_email', watcher_ticket_id = $ticket_id");

            // Notify watcher
            if ($notify && (!empty($config_smtp_host) || !empty($config_smtp_provider))) {



                // Email content
                $data = []; // Queue array

                $subject = "Ticket Notification - [$ticket_prefix$ticket_number] - $ticket_subject";
                $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello,<br><br>You have been added as a collaborator on this ticket regarding \"$ticket_subject\".<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Guest link: https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

                $data[] = [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $watcher_email,
                    'recipient_name' => $watcher_email,
                    'subject' => $subject,
                    'body' => $body
                ];

                addToMailQueue($data);
            }

            logAction("Ticket", "Edit", "$session_name added $watcher_email as a watcher for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
        }

    }

    flash_alert("Added watcher(s)");

    redirect();

}

if (isset($_GET['delete_ticket_watcher'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $watcher_id = intval($_GET['delete_ticket_watcher']);

    // Get ticket / watcher details for logging
    $sql = mysqli_query($mysqli, "SELECT watcher_email, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id, ticket_id FROM ticket_watchers
        LEFT JOIN tickets ON watcher_ticket_id = ticket_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE watcher_id = $watcher_id"
    );
    $row = mysqli_fetch_assoc($sql);

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = sanitizeInput($row['ticket_status_name']);
    $watcher_email = sanitizeInput($row['watcher_email']);
    $client_id = intval($row['ticket_client_id']);
    $ticket_id = intval($row['ticket_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "DELETE FROM ticket_watchers WHERE watcher_id = $watcher_id");

    // History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status_name', ticket_history_description = '$session_name removed ticket $watcher_email as a watcher', ticket_history_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name removed $watcher_email as a watcher for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Removed ticket watcher <strong>$watcher_email</strong>", 'error');

    redirect();

}

if (isset($_GET['delete_ticket_additional_asset'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $asset_id = intval($_GET['delete_ticket_additional_asset']);
    $ticket_id = intval($_GET['ticket_id']);

    // Get ticket / asset details for logging
    $sql = mysqli_query($mysqli, "SELECT asset_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM assets
        JOIN tickets ON ticket_id = $ticket_id
        JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE asset_id = $asset_id"
    );
    $row = mysqli_fetch_assoc($sql);

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = sanitizeInput($row['ticket_status_name']);
    $asset_name = sanitizeInput($row['asset_name']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id AND asset_id = $asset_id");

    // History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status_name', ticket_history_description = '$session_name removed additional asset $asset_name', ticket_history_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name removed asset $asset_name from ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Removed asset <strong>$asset_name</strong> from ticket.", 'error');

    redirect();

}

if (isset($_POST['edit_ticket_asset'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $asset_id = intval($_POST['asset']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_asset_id = $asset_id WHERE ticket_id = $ticket_id");

    // Add Additional Assets
    if (isset($_POST['additional_assets'])) {
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
        foreach ($_POST['additional_assets'] as $additional_asset) {
            $additional_asset_id = intval($additional_asset);
            mysqli_query($mysqli, "INSERT INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $additional_asset_id");
        }
    } else {
        // If no additional assets are provided, delete them all
        // This handles cases where the assets input might be cleared or not set at all.
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
    }

    // Get ticket / asset details for logging
    $sql = mysqli_query($mysqli, "SELECT asset_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM assets
        LEFT JOIN tickets ON ticket_asset_id = asset_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id"
    );
    $row = mysqli_fetch_assoc($sql);

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = sanitizeInput($row['ticket_status_name']);
    $asset_name = sanitizeInput($row['asset_name']);
    $client_id = intval($row['ticket_client_id']);

    logAction("Ticket", "Edit", "$session_name changed asset to $asset_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Ticket <strong>$ticket_prefix$ticket_number</strong> asset updated to <strong>$asset_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_vendor'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $vendor_id = intval($_POST['vendor']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_vendor_id = $vendor_id WHERE ticket_id = $ticket_id");

    // Get ticket / vendor details for logging
    $sql = mysqli_query($mysqli, "SELECT vendor_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM vendors
        LEFT JOIN tickets ON ticket_vendor_id = $vendor_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id"
    );
    $row = mysqli_fetch_assoc($sql);

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = sanitizeInput($row['ticket_status_name']);
    $vendor_name = sanitizeInput($row['vendor_name']);
    $client_id = intval($row['ticket_client_id']);

    logAction("Ticket", "Edit", "$session_name set vendor to $vendor_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flash_alert("Set vendor to <strong>$vendor_name</strong> for ticket <strong>$ticket_prefix$ticket_number</strong>");

    redirect();

}

if (isset($_POST['quick_categorize_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id   = intval($_POST['ticket_id']);
    $category_id = intval($_POST['category_id']);

    $td = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    if (!$td) { echo json_encode(['ok' => false]); exit; }

    $client_id = intval($td['ticket_client_id']);
    if ($client_id) { enforceClientAccess($client_id); }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id WHERE ticket_id = $ticket_id");

    $cat_name = $category_id ? nullable_htmlentities(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT category_name FROM categories WHERE category_id = $category_id LIMIT 1"))['category_name'] ?? '') : '';
    logAction("Ticket", "Edit", "Category changed on ticket {$td['ticket_prefix']}{$td['ticket_number']}", intval($td['ticket_client_id']), $ticket_id);

    echo json_encode(['ok' => true, 'name' => $cat_name]);
    exit;
}

if (isset($_POST['quick_assign_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id   = intval($_POST['ticket_id']);
    $assigned_to = intval($_POST['assigned_to']);

    $ticket_details_sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_client_id, client_name FROM tickets LEFT JOIN clients ON ticket_client_id = client_id WHERE ticket_id = $ticket_id AND ticket_closed_at IS NULL LIMIT 1");
    $td = mysqli_fetch_assoc($ticket_details_sql);

    if (!$td) { echo json_encode(['ok' => false, 'error' => 'Ticket not found']); exit; }

    $ticket_status = intval($td['ticket_status']);
    $client_id     = intval($td['ticket_client_id']);
    $ticket_prefix = sanitizeInput($td['ticket_prefix']);
    $ticket_number = intval($td['ticket_number']);
    $ticket_subject = sanitizeInput($td['ticket_subject']);
    $client_name   = sanitizeInput($td['client_name']);

    if ($client_id) { enforceClientAccess($client_id); }

    if ($assigned_to == 0) {
        $agent_name  = 'No One';
        $ticket_reply = 'Ticket unassigned.';
    } else {
        $agent_sql  = mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $assigned_to AND user_archived_at IS NULL LIMIT 1");
        $agent      = mysqli_fetch_assoc($agent_sql);
        if (!$agent) { echo json_encode(['ok' => false, 'error' => 'Invalid agent']); exit; }
        $agent_name  = sanitizeInput($agent['user_name']);
        $agent_email = sanitizeInput($agent['user_email']);
        $ticket_reply = "Ticket re-assigned to $agent_name.";
    }

    // New → Open when assigned
    if ($ticket_status == 1 && $assigned_to !== 0) $ticket_status = 2;

    mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $assigned_to, ticket_status = $ticket_status WHERE ticket_id = $ticket_id");
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name reassigned $ticket_prefix$ticket_number to $agent_name", $client_id, $ticket_id);

    if ($session_user_id != $assigned_to && $assigned_to != 0) {
        $client_uri = $client_id ? "&client_id=$client_id" : '';
        notifyUser($assigned_to, 'Ticket', "Ticket $ticket_prefix$ticket_number - $ticket_subject has been assigned to you by $session_name", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id);
    }

    queueWebhookEvent('ticket.assigned', getWebhookTicketPayload($ticket_id));
    echo json_encode(['ok' => true, 'name' => $agent_name, 'agent_id' => $assigned_to]);
    exit;
}

if (isset($_POST['quick_priority_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $priority  = sanitizeInput($_POST['priority']);

    if (!in_array($priority, ['Low', 'Medium', 'High'])) { echo json_encode(['ok' => false]); exit; }

    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_priority, ticket_status_name, ticket_client_id FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $ticket_id AND ticket_closed_at IS NULL LIMIT 1");
    $td = mysqli_fetch_assoc($sql);
    if (!$td) { echo json_encode(['ok' => false]); exit; }

    $client_id = intval($td['ticket_client_id']);
    $original_priority = sanitizeInput($td['ticket_priority']);
    $ticket_status_name = sanitizeInput($td['ticket_status_name']);
    $ticket_prefix = sanitizeInput($td['ticket_prefix']);
    $ticket_number = intval($td['ticket_number']);

    if ($client_id) { enforceClientAccess($client_id); }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$priority' WHERE ticket_id = $ticket_id");
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status_name', ticket_history_description = '$session_name changed priority from $original_priority to $priority', ticket_history_ticket_id = $ticket_id");
    logAction("Ticket", "Edit", "$session_name changed priority from $original_priority to $priority for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
    customAction('ticket_update', $ticket_id);

    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_POST['quick_status_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id     = intval($_POST['ticket_id']);
    $new_status_id = intval($_POST['ticket_status_id']);

    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_status, ticket_status_name, ticket_client_id FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $ticket_id AND ticket_closed_at IS NULL LIMIT 1");
    $td = mysqli_fetch_assoc($sql);
    if (!$td) { echo json_encode(['ok' => false]); exit; }

    $client_id = intval($td['ticket_client_id']);
    $old_status_id = intval($td['ticket_status']);
    $original_status = sanitizeInput($td['ticket_status_name']);
    $ticket_prefix = sanitizeInput($td['ticket_prefix']);
    $ticket_number = intval($td['ticket_number']);

    if ($client_id) { enforceClientAccess($client_id); }

    $sql_ns = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_id = $new_status_id LIMIT 1");
    $ns = mysqli_fetch_assoc($sql_ns);
    if (!$ns) { echo json_encode(['ok' => false]); exit; }

    if ($new_status_id == 4) { $new_status_id = 5; }

    // Re-fetch for the FINAL status id (a "Resolved" selection immediately
    // becomes "Closed" above) - using the pre-remap name/color here previously
    // told the acting user's own UI, the history log, and every other live
    // viewer that the ticket was "Resolved" when it was actually "Closed".
    $sql_ns_final = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_id = $new_status_id LIMIT 1");
    $ns_final = mysqli_fetch_assoc($sql_ns_final);
    $new_status_name  = sanitizeInput($ns_final['ticket_status_name']);
    $new_status_color = sanitizeInput($ns_final['ticket_status_color']);

    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id WHERE ticket_id = $ticket_id");
    if ($new_status_id == 5) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");
        logAction("Ticket", "Closed", "$session_name closed ticket $ticket_prefix$ticket_number via quick status", $client_id, $ticket_id);
        customAction('ticket_close', $ticket_id);
    } else {
        // Moving a ticket to any non-Closed status must clear these, or the ticket
        // page (which gates the reply form / edit controls on ticket_closed_at, not
        // ticket_status) keeps rendering it as closed even though the status changed.
        mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");
    }

    // SLA pause-on-hold: accrue/clear paused time across this status change.
    slaAccruePause($mysqli, $ticket_id, $old_status_id, $new_status_id);

    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$new_status_name', ticket_history_description = '$session_name changed status from $original_status to $new_status_name', ticket_history_ticket_id = $ticket_id");
    logAction("Ticket", "Edit", "$session_name changed status from $original_status to $new_status_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
    customAction('ticket_update', $ticket_id);

    publishTicketEvent($ticket_id, 'status', ['status_id' => $new_status_id, 'status_name' => $new_status_name, 'status_color' => $new_status_color, 'by' => $session_name]);

    echo json_encode(['ok' => true, 'name' => $new_status_name, 'color' => $new_status_color]);
    exit;
}

if (isset($_POST['assign_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $ticket_id = intval($_POST['ticket_id']);
    $assigned_to = intval($_POST['assigned_to']);
    $ticket_status = intval($_POST['ticket_status']);

    // New > Open as assigned
    if ($ticket_status == 1 && $assigned_to !== 0) {
        $ticket_status = 2;
    }

    // Allow for un-assigning tickets
    if ($assigned_to == 0) {
        $ticket_reply = "Ticket unassigned.";
        $agent_name = "No One";
    } else {
        // Get & verify assigned agent details
        $agent_details_sql = mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE users.user_id = $assigned_to");
        $agent_details = mysqli_fetch_assoc($agent_details_sql);

        $agent_name = sanitizeInput($agent_details['user_name']);
        $agent_email = sanitizeInput($agent_details['user_email']);
        $ticket_reply = "Ticket re-assigned to $agent_name.";

        if (!$agent_name) {
            flash_alert("Invalid agent!", 'error');
            redirect();
        }
    }

    // Get & verify ticket details
    $ticket_details_sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name FROM tickets LEFT JOIN clients ON ticket_client_id = client_id WHERE ticket_id = '$ticket_id' AND ticket_status != 5");
    $ticket_details = mysqli_fetch_assoc($ticket_details_sql);

    $ticket_prefix = sanitizeInput($ticket_details['ticket_prefix']);
    $ticket_number = intval($ticket_details['ticket_number']);
    $ticket_subject = sanitizeInput($ticket_details['ticket_subject']);
    $client_id = intval($ticket_details['ticket_client_id']);
    $client_name = sanitizeInput($ticket_details['client_name']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    if (!$ticket_subject) {
        flash_alert("Invalid ticket!", 'error');
        redirect();
    }

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    // Update ticket & insert reply
    mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $assigned_to, ticket_status = '$ticket_status' WHERE ticket_id = $ticket_id");

    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name reassigned $ticket_prefix$ticket_number to $agent_name", $client_id, $ticket_id);

    // Notification
    if ($session_user_id != $assigned_to && $assigned_to != 0) {

        // App Notification
        notifyUser($assigned_to, 'Ticket', "Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject has been assigned to you by $session_name", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id);

        // Email Notification
        if (!empty($config_smtp_host) || !empty($config_smtp_provider)) {

            // Sanitize Config vars from get_settings.php
            $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
            $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
            $company_name = sanitizeInput($session_company_name);

            $ticket_from = resolveTicketFromIdentity($ticket_id);

            $subject = "$config_app_name - Ticket $ticket_prefix$ticket_number assigned to you - $ticket_subject";
            $body = "Hi $agent_name, <br><br>A ticket has been assigned to you!<br><br>Client: $client_name<br>Ticket Number: $ticket_prefix$ticket_number<br> Subject: $ticket_subject<br><br>https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri <br><br>Thanks, <br>$session_name<br>$company_name";

            // Email Ticket Agent
            // Queue Mail
            $data = [
                [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $agent_email,
                    'recipient_name' => $agent_name,
                    'subject' => $subject,
                    'body' => $body,
                ]
            ];
            addToMailQueue($data);
        }
    }

    customAction('ticket_assign', $ticket_id);

    flash_alert("Ticket <strong>$ticket_prefix$ticket_number</strong> assigned to <strong>$agent_name</strong>");

    redirect();

}

if (isset($_GET['delete_ticket'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 3);

    $ticket_id = intval($_GET['delete_ticket']);

    // Get Ticket and Client ID for logging and alert message
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_closed_at, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id");
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = sanitizeInput($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_status = sanitizeInput($row['ticket_status']);
    $ticket_closed_at = sanitizeInput($row['ticket_closed_at']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    if (empty($ticket_closed_at)) {
        mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $ticket_id");

        // Delete all ticket replies
        mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id");

        // Delete all ticket views
        mysqli_query($mysqli, "DELETE FROM ticket_views WHERE view_ticket_id = $ticket_id");

        // Delete ticket watchers
        mysqli_query($mysqli, "DELETE FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");

        // Delete ticket chat messages
        mysqli_query($mysqli, "DELETE FROM ticket_chat_messages WHERE ticket_id = $ticket_id");

        // Delete Ticket Attachements
        mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id");
        removeDirectory("../uploads/tickets/$ticket_id");

        // No Need to delete ticket assets as this is cascadely deleted via the database.

        logAction("Ticket", "Delete", "$session_name deleted $ticket_prefix$ticket_number along with all replies", $client_id);

        flash_alert("Ticket <strong>$ticket_prefix$ticket_number</strong> along with all replies deleted", 'error');

        customAction('ticket_delete', $ticket_id);

        redirect("tickets.php");
    }

}

if (isset($_POST['bulk_delete_tickets'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 3);

    if (isset($_POST['ticket_ids'])) {

        $count = count($_POST['ticket_ids']);

        // Cycle through array and delete each recurring scheduled ticket
        foreach ($_POST['ticket_ids'] as $ticket_id) {

            $ticket_id = intval($ticket_id);

            $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $ticket_id");

            // Delete all ticket replies
            mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id");

            // Delete all ticket views
            mysqli_query($mysqli, "DELETE FROM ticket_views WHERE view_ticket_id = $ticket_id");

            // Delete ticket watchers
            mysqli_query($mysqli, "DELETE FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");

            // Delete ticket chat messages
            mysqli_query($mysqli, "DELETE FROM ticket_chat_messages WHERE ticket_id = $ticket_id");

            // Delete Ticket Attachements
            mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id");
            removeDirectory("../uploads/tickets/$ticket_id");

            // No Need to delete ticket assets as this is cascadely deleted via the database.

            logAction("Ticket", "Delete", "$session_name deleted ticket", 0, $ticket_id);

        }

        logAction("Ticket", "Bulk Delete", "$session_name deleted $count ticket(s)");

        flash_alert("Deleted <strong>$count</strong> ticket(s)", 'error');
    }

    redirect();

}

if (isset($_POST['bulk_assign_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $assign_to = intval($_POST['assign_to']);

    // Get a Ticket Count
    $ticket_count = count($_POST['ticket_ids']);

    // Assign Tech to Selected Tickets
    if (!empty($_POST['ticket_ids'])) {
        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_status = intval($row['ticket_status']);
            $ticket_name = sanitizeInput($row['ticket_name']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            if ($ticket_status == 1 && $assigned_to !== 0) {
                $ticket_status = 2;
            }

            // Allow for un-assigning tickets
            if ($assign_to == 0) {
                $ticket_reply = "Ticket unassigned, pending re-assignment.";
                $agent_name = "No One";
            } else {
                // Get & verify assigned agent details
                $agent_details_sql = mysqli_query($mysqli, "SELECT user_name, user_email FROM users LEFT JOIN user_settings ON users.user_id = user_settings.user_id WHERE users.user_id = $assign_to");
                $agent_details = mysqli_fetch_assoc($agent_details_sql);

                $agent_name = sanitizeInput($agent_details['user_name']);
                $agent_email = sanitizeInput($agent_details['user_email']);
                $ticket_reply = "Ticket re-assigned to $agent_name.";

                if (!$agent_name) {
                    flash_alert("Invalid agent!", 'error');
                    redirect();
                }
            }

            // Update ticket & insert reply
            mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $assign_to, ticket_status = $ticket_status WHERE ticket_id = $ticket_id");

            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

            logAction("Ticket", "Edit", "$session_name reassigned ticket $ticket_prefix$ticket_number to $agent_name", $client_id, $ticket_id);

            customAction('ticket_assign', $ticket_id);

            $tickets_assigned_body .= "$ticket_prefix$ticket_number - $ticket_subject<br>";
        } // End For Each Ticket ID Loop

        // Notification
        if ($session_user_id != $assign_to && $assign_to != 0) {

            // App Notification
            notifyUser($assign_to, 'Ticket', "$ticket_count Tickets have been assigned to you by $session_name", "tickets.php?status=Open&assigned=$assign_to", $client_id);

            // Agent Email Notification
            if (!empty($config_smtp_host) || !empty($config_smtp_provider)) {

                // Sanitize Config vars from get_settings.php
                $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
                $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
                $company_name = sanitizeInput($session_company_name);

                $subject = "$config_app_name - $ticket_count tickets have been assigned to you";
                $body = "Hi $agent_name, <br><br>$session_name assigned $ticket_count tickets to you!<br><br>$tickets_assigned_body<br>Thanks, <br>$session_name<br>$company_name";

                // Email Ticket Agent
                // Queue Mail
                $data = [
                    [
                        'from' => $config_ticket_from_email,
                        'from_name' => $config_ticket_from_name,
                        'recipient' => $agent_email,
                        'recipient_name' => $agent_name,
                        'subject' => $subject,
                        'body' => $body,
                    ]
                ];
                addToMailQueue($data);
            }
        }
    }

    flash_alert("You assigned <b>$ticket_count</b> Tickets to <b>$agent_name</b>");

    redirect();

}

if (isset($_POST['bulk_edit_ticket_priority'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $priority = sanitizeInput($_POST['bulk_priority']);

    // Assign Tech to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Get a Ticket Count
        $ticket_count = count($_POST['ticket_ids']);

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);
            $original_ticket_priority = sanitizeInput($row['ticket_priority']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            // Update ticket & insert reply
            mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$priority' WHERE ticket_id = $ticket_id");

            recalculateTicketSla($mysqli, $ticket_id);

            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$session_name updated the priority from $current_ticket_priority to $priority', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

            logAction("Ticket", "Edit", "$session_name updated the priority on ticket $ticket_prefix$ticket_number - $ticket_subject from $original_ticket_priority to $priority", $client_id, $ticket_id);

            customAction('ticket_update', $ticket_id);
        } // End For Each Ticket ID Loop

        logAction("Ticket", " Bulk Edit", "$session_name updated the priority on $ticket_count");

        flash_alert("You updated the priority for <strong>$ticket_count</strong> Tickets to <strong>$priority</strong>");
    }

    redirect();

}

if (isset($_POST['bulk_edit_ticket_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $category_id = intval($_POST['bulk_category']);

    // Assign Tech to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Get a Ticket Count
        $ticket_count = count($_POST['ticket_ids']);

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, category_name, ticket_client_id FROM tickets LEFT JOIN categories ON ticket_category = category_id WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);
            $previous_ticket_category_name = sanitizeInput($row['category_name']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            // Get Category Name
            $category_name = sanitizeInput(getFieldById('categories', $category_id, 'category_name'));

            // Update ticket
            mysqli_query($mysqli, "UPDATE tickets SET ticket_category = '$category_id' WHERE ticket_id = $ticket_id");

            logAction("Ticket", "Edit", "$session_name updated the category on ticket $ticket_prefix$ticket_number - $ticket_subject from $previous_category_name to $category_name", $client_id, $ticket_id);

            customAction('ticket_update', $ticket_id);
        } // End For Each Ticket ID Loop

        logAction("Ticket", " Bulk Edit", "$session_name updated the category to $category_name on $ticket_count");

        flash_alert("Category set to $category_name for <strong>$ticket_count</strong> Tickets");
    }

    redirect();

}

if (isset($_POST['bulk_merge_tickets'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $merge_into_ticket_id = intval($_POST['merge_into_ticket_id']); // Parent ticket id
    $merge_comment = sanitizeInput($_POST['merge_comment']); // Merge comment
    $ticket_reply_type = 'System'; // Default all auto-generated merge notes to System

    // NEW PARENT ticket details
    // Get merge into ticket id (as it may differ from the number)
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $merge_into_ticket_id");
    if (mysqli_num_rows($sql) == 0) {
        flash_alert("Cannot merge into that ticket.", 'error');
        redirect();
    }
    $merge_row = mysqli_fetch_assoc($sql);
    $merge_into_ticket_number = intval($merge_row['ticket_number']); // Parent ticket Number

    // Confirm access to the destination ticket's client - the loop below
    // only checks each source ticket's client.
    $merge_into_client_id = intval($merge_row['ticket_client_id']);
    if ($merge_into_client_id) {
        enforceClientAccess($merge_into_client_id);
    }

    // Update & Close the selected tickets
    if (isset($_POST['ticket_ids'])) {

        $ticket_count = count($_POST['ticket_ids']); // Get a ticket count

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            if ($ticket_id !== $merge_into_ticket_id) {

                $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
                $row = mysqli_fetch_assoc($sql);

                $ticket_prefix = sanitizeInput($row['ticket_prefix']);
                $ticket_number = intval($row['ticket_number']);
                $ticket_subject = sanitizeInput($row['ticket_subject']);
                $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
                $current_ticket_priority = sanitizeInput($row['ticket_priority']);
                $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);
                $client_id = intval($row['ticket_client_id']);

                // Don't Enforce Client Access if Ticket doesn't have an assigned client
                if ($client_id) {
                    enforceClientAccess();
                }

                // Update current ticket
                if (empty($ticket_first_response_at)) {
                    mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
                }
                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number bulk merged into <a href=\"ticket.php?ticket_id=$merge_into_ticket_id\">$ticket_prefix$merge_into_ticket_number</a>. Comment: $merge_comment', ticket_reply_time_worked = '00:01:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = '5', ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id, ticket_merged_into_id = $merge_into_ticket_id WHERE ticket_id = $ticket_id") or die(mysqli_error($mysqli));

                // Update new parent ticket
                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number was bulk merged into this ticket with comment: $merge_comment.<br><br><b>$ticket_subject</b><br>$ticket_details', ticket_reply_time_worked = '00:01:00', ticket_reply_type = 'System', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $merge_into_ticket_id");

                logAction("Ticket", "Merged", "$session_name Merged ticket $ticket_prefix$ticket_number into $ticket_prefix$merge_into_ticket_number", $client_id, $ticket_id);

                // Custom action/notif handler
                customAction('ticket_merge', $ticket_id);

            }
        } // End For Each Ticket ID Loop

        mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $merge_into_ticket_id");

        flash_alert("<strong>$ticket_count</strong> tickets merged into <strong>$ticket_prefix$merge_into_ticket_number</strong>");

    }

    redirect();

}

if (isset($_POST['bulk_resolve_tickets'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $details = mysqli_escape_string($mysqli, $_POST['bulk_details']);
    $ticket_reply_time_worked = sanitizeInput($_POST['time']);
    $private_note = intval($_POST['bulk_private_note']);
    if ($private_note == 1) {
        $ticket_reply_type = 'Internal';
    } else {
        $ticket_reply_type = 'Public';
    }

    // Resolve Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Intitialze the counts before the loop
        $ticket_count = 0;
        $skipped_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            // Check to make sure Tasks are complete before resolving
            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('task_id') AS num FROM tasks WHERE task_completed_at IS NULL AND task_ticket_id = $ticket_id"));
            $num_of_open_tasks = $row['num'];

            if ($num_of_open_tasks == 0) {
                // Count the Ticket Loop
                $ticket_count++;

                $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
                $row = mysqli_fetch_assoc($sql);

                $ticket_prefix = sanitizeInput($row['ticket_prefix']);
                $ticket_number = intval($row['ticket_number']);
                $ticket_subject = sanitizeInput($row['ticket_subject']);
                $current_ticket_priority = sanitizeInput($row['ticket_priority']);
                $url_key = sanitizeInput($row['ticket_url_key']);
                $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);
                $client_id = intval($row['ticket_client_id']);

                // Don't Enforce Client Access if Ticket doesn't have an assigned client
                if ($client_id) {
                    enforceClientAccess();
                }

                // Mark FR time if required
                if (empty($ticket_first_response_at)) {
                    mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
                }

                // Update ticket & insert reply
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");

                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$details', ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '$ticket_reply_time_worked', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id, ticket_reply_emailed = " . ($private_note == 0 ? 1 : 0));
                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

                logAction("Ticket", "Resolve", "$session_name resolved $ticket_prefix$ticket_number - $ticket_subject", $client_id, $ticket_id);
                logAction("Ticket", "Closed", "$session_name closed $ticket_prefix$ticket_number via bulk resolve", $client_id, $ticket_id);

                customAction('ticket_resolve', $ticket_id);
                customAction('ticket_close', $ticket_id);

                // Client notification email
                if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1 && $private_note == 0) {

                    // Get Contact details
                    $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM tickets
                        LEFT JOIN contacts ON ticket_contact_id = contact_id
                        WHERE ticket_id = $ticket_id
                    ");
                    $row = mysqli_fetch_assoc($ticket_sql);

                    $contact_name = sanitizeInput($row['contact_name']);
                    $contact_email = sanitizeInput($row['contact_email']);

                    // Sanitize Config vars from get_settings.php
                    $ticket_from = resolveTicketFromIdentity($ticket_id);
                    $from_name = sanitizeInput($ticket_from['name']);
                    $from_email = sanitizeInput($ticket_from['email']);
                    $base_url = sanitizeInput($config_base_url);

                    // Get Company Info
                    $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
                    $row = mysqli_fetch_assoc($sql);
                    $company_name = sanitizeInput($row['company_name']);
                    $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

                    // EMAIL
                    $subject = "Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject";
                    $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
                    $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding \"$ticket_subject\" has been resolved and closed.<br><br>$details<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

                    // Check email valid
                    if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                        $data = [];

                        // Email Ticket Contact
                        // Queue Mail

                        $data[] = [
                            'from' => $from_email,
                            'from_name' => $from_name,
                            'recipient' => $contact_email,
                            'recipient_name' => $contact_name,
                            'subject' => $subject,
                            'body' => $body
                        ];
                    }

                    // Also Email all the watchers
                    $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
                    $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
                    while ($row = mysqli_fetch_assoc($sql_watchers)) {
                        $watcher_email = sanitizeInput($row['watcher_email']);

                        // Queue Mail
                        $data[] = [
                            'from' => $from_email,
                            'from_name' => $from_name,
                            'recipient' => $watcher_email,
                            'recipient_name' => $watcher_email,
                            'subject' => $subject,
                            'body' => $body
                        ];
                    }
                    addToMailQueue($data);
                } // End Mail IF
            } else {
                 $skipped_count++;
            } // End Task Check
        } // End Loop
    } // End Array Empty Check

    flash_alert("Resolved <strong>$ticket_count</strong> Tickets");

    if ($skipped_count > 0) {
        flash_alert("Resolved <strong>$ticket_count</strong> Tickets <strong>$skipped_count</strong> ticket(s) could not be resolved because they have open tasks.", 'info');
    }

    redirect();

}

if (isset($_POST['bulk_ticket_reply'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $ticket_reply = mysqli_escape_string($mysqli, $_POST['bulk_reply_details']);
    $ticket_status = intval($_POST['bulk_status']);
    $ticket_reply_time_worked = sanitizeInput($_POST['time']);
    $private_note = intval($_POST['bulk_private_reply']);
    if ($private_note == 1) {
        $ticket_reply_type = 'Internal';
    } else {
        $ticket_reply_type = 'Public';
    }

    // Loop Through Tickets and Add Reply along with Email notifications
    if (isset($_POST['ticket_ids'])) {

        // Get a Ticket Count
        $ticket_count = count($_POST['ticket_ids']);

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);
            $current_ticket_priority = sanitizeInput($row['ticket_priority']);
            $url_key = sanitizeInput($row['ticket_url_key']);
            $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            if ($client_id) {
                $client_uri = "&client_id=$client_id";
            } else {
                $client_uri = '';
            }

            // Mark FR time if required
            if (empty($ticket_first_response_at)) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
            }

            // Add reply
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_time_worked = '$ticket_reply_time_worked', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id, ticket_reply_emailed = " . ($private_note == 0 ? 1 : 0));

            $ticket_reply_id = mysqli_insert_id($mysqli);

            // Update Ticket Status
            mysqli_query($mysqli, "UPDATE tickets SET ticket_status = '$ticket_status' WHERE ticket_id = $ticket_id");

            logAction("Ticket", "Reply", "$session_name replied to ticket $ticket_prefix$ticket_number - $ticket_subject and was a $ticket_reply_type reply", $client_id, $ticket_id);

            // Custom action/notif handler
            if ($ticket_reply_type == 'Internal') {
                customAction('ticket_reply_agent_internal', $ticket_id);
            } else {
                customAction('reply_reply_agent_public', $ticket_id);
            }

            // Resolve the ticket, if set
            if ($ticket_status == 4) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");
                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

                logAction("Ticket", "Resolved", "$session_name resolved Ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
                logAction("Ticket", "Closed", "$session_name closed Ticket $ticket_prefix$ticket_number via resolve", $client_id, $ticket_id);

                customAction('ticket_resolve', $ticket_id);
                customAction('ticket_close', $ticket_id);
            }

            // Get Contact Details
            $sql = mysqli_query(
                $mysqli,
                "SELECT contact_name, contact_email, ticket_created_by, ticket_assigned_to
                FROM tickets
                LEFT JOIN contacts ON ticket_contact_id = contact_id
                WHERE ticket_id = $ticket_id"
            );

            $row = mysqli_fetch_assoc($sql);

            $contact_name = sanitizeInput($row['contact_name']);
            $contact_email = sanitizeInput($row['contact_email']);
            $ticket_created_by = intval($row['ticket_created_by']);
            $ticket_assigned_to = intval($row['ticket_assigned_to']);

            // Sanitize Config vars from get_settings.php
            $ticket_from = resolveTicketFromIdentity($ticket_id);
            $from_name = sanitizeInput($ticket_from['name']);
            $from_email = sanitizeInput($ticket_from['email']);
            $base_url = sanitizeInput($config_base_url);

            $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
            $row = mysqli_fetch_assoc($sql);
            $company_name = sanitizeInput($row['company_name']);
            $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

            // Send e-mail to client if public update & email is set up
            if ($private_note == 0 && (!empty($config_smtp_host) || !empty($config_smtp_provider))) {

                $subject = "Ticket update - [$ticket_prefix$ticket_number] - $ticket_subject";
                $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been updated.<br><br>--------------------------------<br>$ticket_reply<br>--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$from_email<br>$company_phone";

                if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                    $data = [];

                    // Email Ticket Contact
                    // Queue Mail
                    $data[] = [
                        'from' => $from_email,
                        'from_name' => $from_name,
                        'recipient' => $contact_email,
                        'recipient_name' => $contact_name,
                        'subject' => $subject,
                        'body' => $body
                    ];

                }

                // Also Email all the watchers
                $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
                $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
                while ($row = mysqli_fetch_assoc($sql_watchers)) {
                    $watcher_email = sanitizeInput($row['watcher_email']);

                    // Queue Mail
                    $data[] = [
                        'from' => $from_email,
                        'from_name' => $from_name,
                        'recipient' => $watcher_email,
                        'recipient_name' => $watcher_email,
                        'subject' => $subject,
                        'body' => $body
                    ];
                }
                addToMailQueue($data);
            } //End Mail IF

            // Notification for assigned ticket user
            if ($session_user_id != $ticket_assigned_to && $ticket_assigned_to != 0) {

                notifyUser($ticket_assigned_to, 'Ticket', "$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that is assigned to you", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id, false);
            }

            // Notification for user that opened the ticket
            if ($session_user_id != $ticket_created_by && $ticket_created_by != 0) {

                notifyUser($ticket_created_by, 'Ticket', "$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that you opened", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id, false);
            }
        } // End Ticket Lopp

    }

    flash_alert("Updated <strong>$ticket_count</strong> tickets");

    redirect();

}


// Currently not UI Frontend for this
if (isset($_POST['bulk_add_ticket_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // POST variables
    $project_id = intval($_POST['project_id']);

    // Get Project Name
    $sql = mysqli_query($mysqli, "SELECT project_name FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = sanitizeInput($row['project_name']);

    // Assign Project to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Get a Ticket Count
        $ticket_count = count($_POST['ticket_ids']);

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);
            $current_ticket_priority = sanitizeInput($row['ticket_priority']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            // Update ticket & insert reply
            mysqli_query($mysqli, "UPDATE tickets SET ticket_project_id = $project_id WHERE ticket_id = $ticket_id");

            logAction("Ticket", "Reply", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $ticket_id);


        } // End For Each Ticket ID Loop

        flash_alert("<strong>$ticket_count</strong> Tickets added to Project <strong>$project_name</strong>");

    }

    redirect();

}

if (isset($_POST['bulk_add_asset_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $assigned_to = resolveTicketAssignee(intval($_POST['bulk_assigned_to']));
    if ($assigned_to == 0) {
        $ticket_status = 1;
    } else {
        $ticket_status = 2;
    }
    $subject = sanitizeInput($_POST['bulk_subject']);
    $priority = sanitizeInput($_POST['bulk_priority']);
    $category_id = intval($_POST['bulk_category']);
    $details = mysqli_real_escape_string($mysqli, $_POST['bulk_details']);
    $project_id = intval($_POST['bulk_project']);
    $use_primary_contact = intval($_POST['use_primary_contact']);
    $ticket_template_id = intval($_POST['bulk_ticket_template_id']);
    $billable = intval($_POST['bulk_billable'] ?? 0);

    // Check to see if adding a ticket by template
    if($ticket_template_id) {
        $sql = mysqli_query($mysqli, "SELECT * FROM ticket_templates WHERE ticket_template_id = $ticket_template_id");
        $row = mysqli_fetch_assoc($sql);

        // Override Template Subject
        if(empty($subject)) {
            $subject = sanitizeInput($row['ticket_template_subject']);
        }
        $details = mysqli_escape_string($mysqli, $row['ticket_template_details']);

        // Get Associated Tasks from the ticket template
        $sql_task_templates = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id");

    }

    // Create ticket for each selected asset
    if (isset($_POST['asset_ids'])) {

        // Get a Asset Count
        $asset_count = count($_POST['asset_ids']);

        foreach ($_POST['asset_ids'] as $asset_id) {
            $asset_id = intval($asset_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_id = $asset_id");
            $row = mysqli_fetch_assoc($sql);

            $asset_name = sanitizeInput($row['asset_name']);
            $client_id = intval($row['asset_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess();
            }

            $subject_asset_prepended = "$asset_name - $subject";

            // Atomically increment and get the new ticket number
            mysqli_query($mysqli, "
                UPDATE settings
                SET
                    config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                    config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1
            ");

            $ticket_number = mysqli_insert_id($mysqli);

            // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
            $config_ticket_prefix = sanitizeInput($config_ticket_prefix);
            $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
            $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
            $config_base_url = sanitizeInput($config_base_url);

            //Generate a unique URL key for clients to access
            $url_key = randomString(32);

            mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_category = $category_id, ticket_subject = '$subject_asset_prepended', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = $billable, ticket_status = $ticket_status, ticket_asset_id = $asset_id, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned_to, ticket_url_key = '$url_key', ticket_client_id = $client_id, ticket_project_id = $project_id");

            $ticket_id = mysqli_insert_id($mysqli);

            // Add Tasks
            if (!empty($_POST['tasks'])) {
                foreach ($_POST['tasks'] as $task) {
                    $task_name = sanitizeInput($task);
                    // Check that task_name is not-empty (For some reason the !empty on the array doesnt work here like in watchers)
                    if (!empty($task_name)) {
                        mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_name', task_ticket_id = $ticket_id");
                    }
                }
            }

            // Add Tasks from Template if Template was selected
            if($ticket_template_id) {
                if (mysqli_num_rows($sql_task_templates) > 0) {
                    while ($row = mysqli_fetch_assoc($sql_task_templates)) {
                        $task_order = intval($row['task_template_order']);
                        $task_name = sanitizeInput($row['task_template_name']);

                        mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_name', task_order = $task_order, task_ticket_id = $ticket_id");
                    }
                }
            }

            // Custom action/notif handler
            customAction('ticket_create', $ticket_id);
        }

        logAction("Ticket", "Bulk Create", "$session_name created $asset_count tickets for $asset_count");

        flash_alert("You created <b>$asset_count</b> tickets for the selected assets");

    }

    redirect();

}

if (isset($_POST['add_ticket_reply'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $ticket_reply = $_POST['ticket_reply']; // Reply is SQL escaped below
    $ticket_status = intval($_POST['status']);

    // Look up client_id from the ticket record (not from POST) to prevent IDOR bypass
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    // Time tracking, inputs & combine into string
    $hours = intval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    $seconds = intval($_POST['seconds']);
    $ticket_reply_time_worked = sanitizeInput(sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds));
    $reply_labor_type_id = intval($_POST['reply_labor_type_id'] ?? 0);
    $reply_charge_now    = isset($_POST['reply_charge_now']) ? 1 : 0;

    // Derive onsite from the labor type name (matches "onsite" or "on-site")
    $reply_onsite = 0;
    if ($reply_labor_type_id > 0) {
        $lt_name_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT labor_type_name FROM labor_types WHERE labor_type_id = $reply_labor_type_id LIMIT 1"));
        if ($lt_name_row && preg_match('/on.?site/i', $lt_name_row['labor_type_name'])) {
            $reply_onsite = 1;
        }
    }

    // Defaults
    $send_email = 0;
    $ticket_reply_id = 0;
    if ($_POST['public_reply_type'] == 1 ){
        $ticket_reply_type = 'Public';
    } elseif ($_POST['public_reply_type'] == 2 ) {
        $ticket_reply_type = 'Public';
        $send_email = 1;
    } else {
        $ticket_reply_type = 'Internal';
    }
    // Add Signature to the end of the ticket reply if not Internal and if there is reply.
    // Wrapped in an email-safe (inline-styled) block with a divider so it reads as a
    // distinct signature instead of being dumped raw onto the end of the reply text -
    // whether it's hand-typed or built via the "Use Template" button in My Settings.
    if ($ticket_reply !== '' && $ticket_reply_type !== 'Internal' && $send_email == 1) {
        $signature = (string) getFieldById('user_settings', $session_user_id, 'user_config_signature', 'raw');
        $signature_has_text = trim(strip_tags($signature)) !== '';
        $signature_has_image = stripos($signature, '<img') !== false;
        if ($signature_has_text || $signature_has_image) {
            $ticket_reply .= '<div style="margin-top:20px;padding-top:12px;border-top:1px solid #e0e0e0;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#555555;line-height:1.5;">' . $signature . '</div>';
        }
    }

    $ticket_reply = mysqli_escape_string($mysqli, $ticket_reply); // SQL Escape Ticket Reply

    // Capture prior status for SLA pause accounting before it is overwritten.
    $sla_old_status_id = intval(getFieldById('tickets', $ticket_id, 'ticket_status'));

    // Update Ticket Status & updated at (in case status didn't change)
    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $ticket_status, ticket_updated_at = NOW() WHERE ticket_id = $ticket_id");

    // Resolve the ticket, if set
    if ($ticket_status == 4) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

        logAction("Ticket", "Resolved", "$session_name resolved Ticket ticket ID $ticket_id", $client_id, $ticket_id);
        logAction("Ticket", "Closed", "$session_name closed Ticket ID $ticket_id via resolve", $client_id, $ticket_id);

        customAction('ticket_resolve', $ticket_id);
        customAction('ticket_close', $ticket_id);
    }

    // Broadcast the FINAL status (a "Resolved" selection immediately becomes
    // "Closed" above - broadcasting before that remap told other live viewers
    // the wrong status name/color).
    $final_ticket_status_id = ($ticket_status == 4) ? 5 : $ticket_status;
    $reply_status_info = getTicketStatusInfo($mysqli, $final_ticket_status_id);
    publishTicketEvent($ticket_id, 'status', ['status_id' => $reply_status_info['id'], 'status_name' => $reply_status_info['name'], 'status_color' => $reply_status_info['color'], 'by' => $session_name]);

    // SLA pause-on-hold: accrue/clear paused time across this status change.
    slaAccruePause($mysqli, $ticket_id, $sla_old_status_id, $final_ticket_status_id);

    // Time was logged via the timer/manual entry fields
    $ticket_reply_time_logged = ($hours > 0 || $minutes > 0 || $seconds > 0);

    // Process reply actions, if we have a reply to work with (e.g. we're not just editing the status), or if time was logged with no reply text
    if (!empty($ticket_reply) || $ticket_reply_time_logged) {

        // No reply text, but time was logged - record it as a Labor note so the time isn't lost
        if (empty($ticket_reply)) {
            $ticket_reply_type = 'Labor';
            $ticket_reply = 'Time logged';
        }

        // Add reply
        $lt_id_sql = $reply_labor_type_id > 0 ? $reply_labor_type_id : 'NULL';
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_time_worked = '$ticket_reply_time_worked', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id, ticket_reply_onsite = $reply_onsite, ticket_reply_labor_type_id = $lt_id_sql, ticket_reply_emailed = $send_email");

        $ticket_reply_id = mysqli_insert_id($mysqli);

        // New reply invalidates any cached AI summary for this ticket
        markTicketSummaryStale($mysqli, $ticket_id);

        publishTicketEvent($ticket_id, 'reply', ['reply_id' => $ticket_reply_id, 'reply_type' => $ticket_reply_type, 'by' => $session_name, 'by_type' => 'agent']);

        // Auto-create charge if a labor type was selected, time logged, and Charge now checked
        if ($reply_labor_type_id > 0 && $reply_charge_now && ($hours > 0 || $minutes > 0 || $seconds > 0)) {
            $lt_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM labor_types WHERE labor_type_id = $reply_labor_type_id LIMIT 1"));
            if ($lt_row) {
                $lt_name  = mysqli_real_escape_string($mysqli, $lt_row['labor_type_name']);
                $lt_rate  = floatval($lt_row['labor_type_rate']);
                // Round logged time up to the nearest half hour for billing (minimum 0.5)
                $charge_qty   = ceil(($hours + ($minutes / 60) + ($seconds / 3600)) * 2) / 2;
                if ($charge_qty <= 0) $charge_qty = 0.5;
                $charge_total = round($charge_qty * $lt_rate, 2);
                mysqli_query($mysqli, "INSERT INTO ticket_charges SET
                    charge_ticket_id    = $ticket_id,
                    charge_labor_type_id= $reply_labor_type_id,
                    charge_name         = '$lt_name',
                    charge_quantity     = $charge_qty,
                    charge_unit_price   = $lt_rate,
                    charge_total        = $charge_total,
                    charge_created_by   = $session_user_id");
            }
        }

        // Get Ticket Details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_status_name, ticket_url_key, ticket_first_response_at, ticket_created_by, ticket_assigned_to, ticket_client_id
        FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id
        ");

        $row = mysqli_fetch_assoc($ticket_sql);

        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $ticket_status = intval($row['ticket_status']);
        $ticket_status_name = sanitizeInput($row['ticket_status_name']);
        $url_key = sanitizeInput($row['ticket_url_key']);
        $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);
        $ticket_created_by = intval($row['ticket_created_by']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);
        $client_id = intval($row['ticket_client_id']);

        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
        $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
        $config_base_url = sanitizeInput($config_base_url);

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // Send e-mail to client if public update & email is set up
        if ($ticket_reply_type == 'Public' && $send_email == 1 && (!empty($config_smtp_host) || !empty($config_smtp_provider))) {

            // Slightly different email subject/text depending on if this update set auto-close

            if ($ticket_status == 4) {
                // Resolved and closed
                $subject = "Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject";
                $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
                $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been resolved and closed.<br><br>--------------------------------<br>$ticket_reply<br>--------------------------------<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";
            } else {
                // Anything else
                $subject = "Ticket update - [$ticket_prefix$ticket_number] - $ticket_subject";
                $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been updated.<br><br>--------------------------------<br>$ticket_reply<br>--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";
            }

            if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                $data = [];

                // Email Ticket Contact
                // Queue Mail
                $data[] = [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $contact_email,
                    'recipient_name' => $contact_name,
                    'subject' => $subject,
                    'body' => $body
                ];
            }

            // Also Email all the watchers
            $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
            $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
            while ($row = mysqli_fetch_assoc($sql_watchers)) {
                $watcher_email = sanitizeInput($row['watcher_email']);

                // Queue Mail
                $data[] = [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $watcher_email,
                    'recipient_name' => $watcher_email,
                    'subject' => $subject,
                    'body' => $body
                ];
            }
            addToMailQueue($data);

        }
        //End Mail IF

        // Notification for assigned ticket user
        if ($session_user_id != $ticket_assigned_to && $ticket_assigned_to != 0) {
            notifyUser($ticket_assigned_to, 'Ticket', "$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that is assigned to you", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id, false);
        }

        // Notification for user that opened the ticket
        if ($session_user_id != $ticket_created_by && $ticket_created_by != 0) {
            notifyUser($ticket_created_by, 'Ticket', "$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that you opened", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id, false);
        }

        // Handle first response
        if (empty($ticket_first_response_at) && $ticket_reply_type == 'Public') {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
        }

        // Custom action/notif handler
        if ($ticket_reply_type == 'Internal' || $ticket_reply_type == 'Labor') {
            customAction('ticket_reply_agent_internal', $ticket_id);
        } else {
            customAction('reply_reply_agent_public', $ticket_id);
        }

        if ($ticket_reply_type == 'Labor') {
            flash_alert("Logged <strong>" . formatDuration($ticket_reply_time_worked) . "</strong> of time on Ticket <strong>$ticket_prefix$ticket_number</strong>");
        } else {
            flash_alert("Ticket <strong>$ticket_prefix$ticket_number</strong> has been updated with your reply and was <strong>$ticket_reply_type</strong>");
        }

    } else {
        flash_alert("Ticket updated");
    }

    if ($ticket_reply_type == 'Labor') {
        logAction("Ticket", "Time Log", "$session_name logged " . formatDuration($ticket_reply_time_worked) . " on ticket $ticket_prefix$ticket_number - $ticket_subject", $client_id, $ticket_id);
    } else {
        logAction("Ticket", "Reply", "$session_name replied to ticket $ticket_prefix$ticket_number - $ticket_subject and was a $ticket_reply_type reply", $client_id, $ticket_id);
    }
    queueWebhookEvent('ticket.replied', getWebhookTicketPayload($ticket_id));

    redirect();

}

if (isset($_POST['edit_ticket_reply'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_POST['ticket_reply_id']);
    $ticket_reply = mysqli_real_escape_string($mysqli, $_POST['ticket_reply']);
    $ticket_reply_type = sanitizeInput($_POST['ticket_reply_type']);
    $ticket_reply_time_worked = sanitizeInput($_POST['time']);

    // Derive client_id from the reply's ticket (not from POST) to prevent IDOR bypass
    $reply_ticket_id = intval(getFieldById('ticket_replies', $ticket_reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $reply_ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply = '$ticket_reply', ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '$ticket_reply_time_worked' WHERE ticket_reply_id = $ticket_reply_id AND ticket_reply_type != 'Client'") or die(mysqli_error($mysqli));

    logAction("Ticket", "Reply", "$session_name edited ticket_reply", $client_id, $ticket_reply_id);

    flash_alert("Ticket reply updated");

    redirect();

}

if (isset($_POST['redact_ticket_reply'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_POST['ticket_reply_id']);
    $ticket_reply = mysqli_real_escape_string($mysqli, $_POST['ticket_reply']);

    // Derive client_id from the reply's ticket (not from POST) to prevent IDOR bypass
    $reply_ticket_id = intval(getFieldById('ticket_replies', $ticket_reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $reply_ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply = '$ticket_reply' WHERE ticket_reply_id = $ticket_reply_id");

    logAction("Ticket", "Reply", "$session_name redacted ticket_reply", $client_id, $ticket_reply_id);

    flash_alert("Ticket reply redacted");

    redirect();

}

if (isset($_GET['delete_ticket_reply'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_GET['delete_ticket_reply']);
    $ticket_id = intval(getFieldById('ticket_replies', $ticket_reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_reply_id = $ticket_reply_id");
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_id = $ticket_reply_id");

    logAction("Ticket Reply", "Delete", "$session_name deleted a ticket reply", $client_id, $ticket_id);
    flash_alert("Reply deleted.", 'error');
    redirect();
}

if (isset($_GET['archive_ticket_reply'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_GET['archive_ticket_reply']);

    $ticket_id = intval(getFieldById('ticket_replies', $ticket_reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_archived_at = NOW() WHERE ticket_reply_id = $ticket_reply_id");

    logAction("Ticket Reply", "Archive", "$session_name archived ticket_reply", $client_id, $ticket_reply_id);

    flash_alert("Ticket reply archived", 'error');

    redirect();

}

if (isset($_POST['merge_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']); // Child ticket ID to be closed
    $merge_into_ticket_id = intval($_POST['merge_into_ticket_id']); // Parent ticket id
    $merge_comment = sanitizeInput($_POST['merge_comment']); // Merge comment
    $move_replies = intval($_POST['merge_move_replies']); // Whether to move replies to the new parent ticket
    $ticket_reply_type = 'System'; // Default all auto-generated merge notes to System

    // Get current ticket details
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_first_response_at, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id");
    if (mysqli_num_rows($sql) == 0) {
        flash_alert("No ticket with that ID found.", 'error');
        redirect();
    }
    // CURRENT ticket details
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);

    // Confirm access to the SOURCE ticket's client - checking only the
    // destination below let an attacker merge (and force-close) any other
    // client's ticket into one they legitimately have access to.
    $source_client_id = intval($row['ticket_client_id']);
    if ($source_client_id) {
        enforceClientAccess($source_client_id);
    }

    // NEW PARENT ticket details
    // Get merge into ticket id (as it may differ from the number)
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $merge_into_ticket_id");
    if (mysqli_num_rows($sql) == 0) {
        flash_alert("Cannot merge into that ticket.", 'error');
        redirect();
    }
    $merge_row = mysqli_fetch_assoc($sql);
    $client_id = intval($merge_row['ticket_client_id']);
    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }
    $merge_into_ticket_number = intval($merge_row['ticket_number']);
    if ($client_id) {
        $has_client = "&client_id=$client_id";
    } else {
        $has_client = "";
    }
    // Sanity check
    if ($ticket_id == $merge_into_ticket_id) {
        flash_alert("Cannot merge into the same ticket.", 'error');
        redirect();
    }

    // Move ticket replies from child > parent
    if ($move_replies) {
        mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_ticket_id = $merge_into_ticket_id WHERE ticket_reply_ticket_id = $ticket_id");
    }

    // Update current ticket
    if (empty($ticket_first_response_at)) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
    }

    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number merged into <a href=\"ticket.php?ticket_id=$merge_into_ticket_id\">$ticket_prefix$merge_into_ticket_number</a>. Comment: $merge_comment', ticket_reply_time_worked = '00:01:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = '5', ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id, ticket_merged_into_id = $merge_into_ticket_id WHERE ticket_id = $ticket_id") or die(mysqli_error($mysqli));

    //Update new parent ticket
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number was merged into this ticket with comment: $merge_comment.<br><br><b>$ticket_subject</b><br>$ticket_details', ticket_reply_time_worked = '00:01:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $merge_into_ticket_id");

    mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $merge_into_ticket_id");

    logAction("Ticket", "Merged", "$session_name Merged ticket $ticket_prefix$ticket_number into $ticket_prefix$merge_into_ticket_number");

    customAction('ticket_merge', $ticket_id);

    flash_alert("Ticket merged into $ticket_prefix$merge_into_ticket_number");

    redirect("ticket.php?ticket_id=$merge_into_ticket_id$has_client");

}

if (isset($_POST['change_client_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $client_id = intval($_POST['new_client_id']);
    $contact_id = intval($_POST['new_contact_id']);

    // Confirm access to the ticket's CURRENT client before reassigning it - only
    // checking the destination (new_client_id) below let an attacker move any
    // other client's ticket into one they legitimately have access to.
    $current_client_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"));
    if (!$current_client_row) {
        flash_alert("No ticket with that ID found.", 'error');
        redirect();
    }
    $current_client_id = intval($current_client_row['ticket_client_id']);
    if ($current_client_id) {
        enforceClientAccess($current_client_id);
    }

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Set any/all existing replies to internal
    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_type = 'Internal' WHERE ticket_reply_ticket_id = $ticket_id");

    // Update ticket client & contact
    mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $client_id, ticket_contact_id = $contact_id WHERE ticket_id = $ticket_id LIMIT 1");

    logAction("Ticket", "Change", "$session_name changed ticket client", $client_id, $ticket_id);

    customAction('ticket_update', $ticket_id);

    flash_alert("Ticket client updated");

    redirect();

}

if (isset($_GET['resolve_ticket'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['resolve_ticket']);

    $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_first_response_at = sanitizeInput($row['ticket_first_response_at']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Block resolve if unsigned dedicated outtake form exists
    $sql_outtake_check = mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_outtake_forms WHERE outtake_ticket_id = $ticket_id AND outtake_signed_at IS NULL");
    if (intval(mysqli_fetch_row($sql_outtake_check)[0]) > 0) {
        flash_alert("Cannot resolve ticket — there is an unsigned outtake form. Have the customer sign it first.", "error");
        redirect();
    }

    // Block resolve if outtake worksheet not finalized
    $sql_ws_outtake = mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_ticket_id = $ticket_id AND worksheet_is_outtake = 1 AND worksheet_completed_at IS NULL");
    if (intval(mysqli_fetch_row($sql_ws_outtake)[0]) > 0) {
        flash_alert("Cannot resolve ticket — there is an unfinalized outtake worksheet. Finalize it first.", "error");
        redirect();
    }

    // Mark FR
    if (empty($ticket_first_response_at)) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
    }

    // Resolve and immediately close
    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Resolved", "$session_name resolved ticket $ticket_prefix$ticket_number (ID: $ticket_id)", $client_id, $ticket_id);
    logAction("Ticket", "Closed", "$session_name closed ticket $ticket_prefix$ticket_number via resolve", $client_id, $ticket_id);
    queueWebhookEvent('ticket.resolved', getWebhookTicketPayload($ticket_id));

    customAction('ticket_resolve', $ticket_id);
    customAction('ticket_close', $ticket_id);

    // Client notification email
    if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, ticket_assigned_to, ticket_url_key FROM tickets
            LEFT JOIN clients ON ticket_client_id = client_id
            LEFT JOIN contacts ON ticket_contact_id = contact_id
            LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
            WHERE ticket_id = $ticket_id
        ");
        $row = mysqli_fetch_assoc($ticket_sql);

        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);
        $ticket_status = sanitizeInput($row['ticket_status_name']);
        $url_key = sanitizeInput($row['ticket_url_key']);

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
        $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
        $config_base_url = sanitizeInput($config_base_url);

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        // Get Company Info
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAIL
        $subject = "Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject";
        $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
        $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been resolved and closed.<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        // Check email valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $data = [];

            // Email Ticket Contact
            // Queue Mail

            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body
            ];
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
        $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = sanitizeInput($row['watcher_email']);

            // Queue Mail
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => $subject,
                'body' => $body
            ];
        }
        addToMailQueue($data);
    }
    //End Mail IF

    flash_alert("Ticket closed");

    redirect('/agent/tickets.php?status=Closed');

}

if (isset($_GET['close_ticket'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['close_ticket']);
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Block close if unsigned dedicated outtake form exists
    $sql_ot_close = mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_outtake_forms WHERE outtake_ticket_id = $ticket_id AND outtake_signed_at IS NULL");
    if (intval(mysqli_fetch_row($sql_ot_close)[0]) > 0) {
        flash_alert("Cannot close ticket — there is an unsigned outtake form. Have the customer sign it first.", "error");
        redirect();
    }

    // Block close if outtake worksheet not finalized
    $sql_ws_ot_close = mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_ticket_id = $ticket_id AND worksheet_is_outtake = 1 AND worksheet_completed_at IS NULL");
    if (intval(mysqli_fetch_row($sql_ws_ot_close)[0]) > 0) {
        flash_alert("Cannot close ticket — there is an unfinalized outtake worksheet. Finalize it first.", "error");
        redirect();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id") or die(mysqli_error($mysqli));

    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Closed", "$session_name closed ticket ID $ticket_id", $client_id, $ticket_id);

    customAction('ticket_close', $ticket_id);

    // Client notification email
    if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_url_key FROM tickets
            LEFT JOIN clients ON ticket_client_id = client_id
            LEFT JOIN contacts ON ticket_contact_id = contact_id
            WHERE ticket_id = $ticket_id
        ");
        $row = mysqli_fetch_assoc($ticket_sql);

        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $url_key = sanitizeInput($row['ticket_url_key']);

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
        $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
        $config_base_url = sanitizeInput($config_base_url);

        $ticket_from = resolveTicketFromIdentity($ticket_id);

        // Get Company Info
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = sanitizeInput($row['company_name']);
        $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAIL
        $subject = "Ticket closed - [$ticket_prefix$ticket_number] - $ticket_subject | (do not reply)";
        $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
        $body = "Hello $contact_name,<br><br>Your ticket regarding \"$ticket_subject\" has been closed. <br><br> We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>$csat_rating_html<a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>Or click here to leave feedback</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        // Check email valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $data = [];

            // Email Ticket Contact
            // Queue Mail

            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body
            ];
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
        $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = sanitizeInput($row['watcher_email']);

            // Queue Mail
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => $subject,
                'body' => $body
            ];
        }
        addToMailQueue($data);
    }
    //End Mail IF

    flash_alert("Ticket closed");

    redirect();

}

if (isset($_GET['reopen_ticket'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['reopen_ticket']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");

    logAction("Ticket", "Reopened", "$session_name reopened ticket ID $ticket_id", $client_id, $ticket_id);

    customAction('ticket_update', $ticket_id);

    flash_alert("Ticket re-opened");

    redirect();

}

if (isset($_POST['add_invoice_from_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $ticket_id = intval($_POST['ticket_id']);
    $date = sanitizeInput($_POST['date']);
    $category = intval($_POST['category']);
    $scope = sanitizeInput($_POST['scope']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN assets ON ticket_asset_id = asset_id
        LEFT JOIN locations ON ticket_location_id = location_id
        WHERE ticket_id = $ticket_id"
    );

    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['client_id']);
    $client_net_terms = intval($row['client_net_terms']);
    if ($client_net_terms == 0) {
        $client_net_terms = $config_default_net_terms;
    }

    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = sanitizeInput($row['ticket_category']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_created_at = sanitizeInput($row['ticket_created_at']);
    $ticket_updated_at = sanitizeInput($row['ticket_updated_at']);
    $ticket_closed_at = sanitizeInput($row['ticket_closed_at']);

    $contact_id = intval($row['contact_id']);
    $contact_name = sanitizeInput($row['contact_name']);
    $contact_email = sanitizeInput($row['contact_email']);

    $asset_id = intval($row['asset_id']);

    $location_name = sanitizeInput($row['location_name']);

    enforceClientAccess();

    if ($invoice_id == 0) {

        $invoice_prefix = sanitizeInput($config_invoice_prefix);

        // Atomically increment and get the new invoice number
        mysqli_query($mysqli, "
            UPDATE settings
            SET
                config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
                config_invoice_next_number = config_invoice_next_number + 1
            WHERE company_id = 1
        ");

        $invoice_number = mysqli_insert_id($mysqli);

        //Generate a unique URL key for clients to access
        $url_key = randomString(32);

        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = '$config_invoice_prefix', invoice_number = $invoice_number, invoice_scope = '$scope', invoice_date = '$date', invoice_due = DATE_ADD('$date', INTERVAL $client_net_terms day), invoice_currency_code = '$session_company_currency', invoice_category_id = $category, invoice_status = 'Draft', invoice_url_key = '$url_key', invoice_client_id = $client_id");
        $invoice_id = mysqli_insert_id($mysqli);
    } else {
        $sql_invoice = mysqli_query($mysqli, "SELECT invoice_prefix, invoice_number FROM invoices WHERE invoice_id = $invoice_id");
        $row = mysqli_fetch_assoc($sql_invoice);
        $invoice_prefix = sanitizeInput($row['invoice_prefix']);
        $invoice_number = intval($row['invoice_number']);
    }

    //Add Item
    $item_name = sanitizeInput($_POST['item_name']);
    $item_description = sanitizeInput($_POST['item_description']);
    $qty = floatval($_POST['qty']);
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);

    $subtotal = $price * $qty;

    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli, "SELECT * FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    } else {
        $tax_amount = 0;
    }

    $total = $subtotal + $tax_amount;

    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '$item_name', item_description = '$item_description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_order = 1, item_tax_id = $tax_id, item_invoice_id = $invoice_id");

    //Update Invoice Balances

    $sql = mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);

    $new_invoice_amount = floatval($row['invoice_amount']) + $total;

    mysqli_query($mysqli, "UPDATE invoices SET invoice_amount = $new_invoice_amount WHERE invoice_id = $invoice_id");

    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = 'Invoice created from Ticket $ticket_prefix$ticket_number', history_invoice_id = $invoice_id");

    // Add internal note to ticket, and link to invoice in database
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Created invoice <a href=\"invoice.php?invoice_id=$invoice_id\">$config_invoice_prefix$invoice_number</a> for this ticket.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    mysqli_query($mysqli, "UPDATE tickets SET ticket_invoice_id = $invoice_id WHERE ticket_id = $ticket_id");

    logAction("Invoice", "Create", "$session_name created invoice $invoice_prefix$invoice_number from Ticket $ticket_prefix$ticket_number", $client_id, $invoice_id);

    flash_alert("Invoice $invoice_prefix$invoice_number created from ticket");

    redirect("invoice.php?invoice_id=$invoice_id");

}

if (isset($_POST['add_quote_from_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    require_once 'quote_model.php';

    $ticket_id = intval($_POST['ticket_id']);
    $scope = sanitizeInput($_POST['scope']);
    $date = sanitizeInput($_POST['date']);
    $expire = sanitizeInput($_POST['expire']);
    $category = intval($_POST['category']);
    $item_name = sanitizeInput($_POST['item_name']);
    $item_description = sanitizeInput($_POST['item_description']);
    $qty = floatval($_POST['qty']);
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);

    // Totals
    $subtotal = $price * $qty;
    $tax_amount = 0;
    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli, "SELECT * FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    }
    $total = floatval($subtotal + $tax_amount);

    // Ticket info
    $sql = mysqli_query(
        $mysqli,
        "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"
    );
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $client_id = intval($row['ticket_client_id']);

    enforceClientAccess();

    // Atomically increment and get the new quote number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_quote_next_number = LAST_INSERT_ID(config_quote_next_number),
            config_quote_next_number = config_quote_next_number + 1
        WHERE company_id = 1
    ");

    $quote_number = mysqli_insert_id($mysqli);

    //Generate a unique URL key for clients to access
    $quote_url_key = randomString(32);

    mysqli_query($mysqli,"INSERT INTO quotes SET quote_prefix = '$config_quote_prefix', quote_number = $quote_number, quote_scope = '$scope', quote_date = '$date', quote_expire = '$expire', quote_amount = $total, quote_currency_code = '$session_company_currency', quote_category_id = $category, quote_status = 'Draft', quote_url_key = '$quote_url_key', quote_client_id = $client_id");

    $quote_id = mysqli_insert_id($mysqli);

    // Add line item
    mysqli_query($mysqli, "INSERT INTO quote_items SET item_name = '$item_name', item_description = '$item_description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_order = 1, item_tax_id = $tax_id, item_quote_id = $quote_id");

    // Add internal note to ticket, and link to invoice in database
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Created quote <a href=\"quote.php?quote_id=$quote_id\">$config_quote_prefix$quote_number</a> for this ticket.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");
    mysqli_query($mysqli, "UPDATE tickets SET ticket_quote_id = $quote_id WHERE ticket_id = $ticket_id LIMIT 1");

    // Logging + redirects
    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Draft', history_description = 'Quote created from Ticket $ticket_prefix$ticket_number!', history_quote_id = $quote_id");
    logAction("Quote", "Create", "$session_name created quote $config_quote_prefix$quote_number from ticket $ticket_prefix$ticket_number", $client_id, $quote_id);

    customAction('quote_create', $quote_id);

    flash_alert("Quote <strong>$config_quote_prefix$quote_number</strong> created");
    redirect("quote.php?quote_id=$quote_id");

}

if (isset($_POST['export_tickets_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    // Per-agent client access restriction (mirrors the ticket list view)
    $export_access_clause = '';
    if (!empty($client_access_string)) {
        $export_access_clause = "AND ticket_client_id IN (0,$client_access_string)";
    }

    if ($_POST['client_id']) {
        $client_id = intval($_POST['client_id']);
        enforceClientAccess();
        $client_query = "WHERE ticket_client_id = $client_id $export_access_clause";
        $client_name = getFieldById('clients', $client_id, 'client_name');
        $file_name_prepend = "$client_name-";
    } else {
        $client_id = 0;
        $client_name = '';
        $file_name_prepend = "$session_company_name-";
        $client_query = $export_access_clause ? "WHERE 1=1 $export_access_clause" : '';
    }

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        $client_query ORDER BY ticket_number ASC"
    );

    if ($sql->num_rows > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '\\';   // backslash
        $filename = sanitize_filename($file_name_prepend . "Tickets-" . date('Y-m-d_H-i-s') . ".csv");

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Ticket Number', 'Priority', 'Status', 'Subject', 'Date Opened', 'Date Resolved', 'Date Closed');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while ($row = $sql->fetch_assoc()) {
            $lineData = array($config_ticket_prefix . $row['ticket_number'], $row['ticket_priority'], $row['ticket_status_name'], $row['ticket_subject'], $row['ticket_created_at'], $row['ticket_resolved_at'], $row['ticket_closed_at']);
            fputcsv($f, $lineData, $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);
    }
    exit;

}

if (isset($_POST['edit_ticket_billable_status'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $billable_status = intval($_POST['billable_status']);
    if ($billable_status == 0 ) {
        $billable_wording = "Not";
    }

    // Get ticket details for logging
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id");
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli,"UPDATE tickets SET ticket_billable = $billable_status WHERE ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name marked ticket $ticket_prefix$ticket_number as $billable_wording Billable", $client_id, $ticket_id);

    flash_alert("Ticket marked <strong>$billable_wording Billable</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_schedule'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id       = intval($_POST['ticket_id']);
    $onsite          = intval($_POST['onsite']);
    $schedule        = sanitizeInput($_POST['scheduled_date_time']);
    $schedule_end_raw= sanitizeInput($_POST['scheduled_end_time'] ?? '');
    $appt_notes      = sanitizeInput($_POST['appointment_notes'] ?? '');
    $schedule_end    = $schedule_end_raw ?: null;
    $ticket_link     = "client/ticket.php?id=$ticket_id";
    $full_ticket_url = "https://$config_base_url/client/ticket.php?id=$ticket_id";
    $ticket_link_html = "<a href=\"$full_ticket_url\">$ticket_link</a>";

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    $schedule_end_sql = $schedule_end ? "'$schedule_end'" : 'NULL';
    mysqli_query($mysqli,"UPDATE tickets
        SET ticket_schedule = '$schedule',
            ticket_schedule_end = $schedule_end_sql,
            ticket_appointment_notes = '$appt_notes',
            ticket_onsite = $onsite
        WHERE ticket_id = $ticket_id"
    );

    // Sync to Outlook Calendar for the assigned technician
    syncTicketToOutlook($ticket_id);

    // Check for other conflicting scheduled items based on 2 hr window
    //TODO make this configurable
    $start = date('Y-m-d H:i:s', strtotime($schedule) - 7200);
    $end = date('Y-m-d H:i:s', strtotime($schedule) + 7200);
    $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_schedule BETWEEN '$start' AND '$end' AND ticket_id != $ticket_id");
    if (mysqli_num_rows($sql) > 0) {
        $conflicting_tickets = [];
        while ($row = mysqli_fetch_assoc($sql)) {
            $conflicting_tickets[] = $row['ticket_id'] . " - " . $row['ticket_subject'] . " @ " . $row['ticket_schedule'];
        }
    }
    $sql = mysqli_query($mysqli, "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations on contact_location_id = location_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE ticket_id = $ticket_id
    ");

    $row = mysqli_fetch_assoc($sql);

    $client_name = sanitizeInput($row['client_name']);
    $ticket_details = sanitizeInput($row['ticket_details']);
    $contact_name = sanitizeInput($row['contact_name']);
    $contact_email = sanitizeInput($row['contact_email']);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $user_name = sanitizeInput($row['user_name']);
    $user_email = sanitizeInput($row['user_email']);
    $cal_subject = $ticket_number . ": " . $client_name . " - " . $ticket_subject;
    $ticket_details_truncated = substr($ticket_details, 0, 100);
    $cal_description = $ticket_details_truncated . " - " . $full_ticket_url;
    $cal_location = sanitizeInput($row["location_address"]);
    $email_datetime = date('l, F j, Y \a\t g:ia', strtotime($schedule));

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    // Sanitize Config Vars
    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
    $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
    $session_company_name = sanitizeInput($session_company_name);

    $ticket_from = resolveTicketFromIdentity($ticket_id);

    /// Create iCal event
    $cal_str = createiCalStr($schedule, $cal_subject, $cal_description, $cal_location, $schedule_end);

    // Notify the agent of the scheduled work
    $data[] = [
            'from' => $ticket_from['email'],
            'from_name' => $ticket_from['name'],
            'recipient' => $user_email,
            'recipient_name' => $user_name,
            'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => "Hello, " . $user_name . "<br><br>The ticket regarding $ticket_subject has been scheduled for $email_datetime.<br><br>--------------------------------<br><a href=\"https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri\">$ticket_link</a><br>--------------------------------<br><br>Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri<br><br>~<br>$session_company_name<br>Support Department<br>$config_ticket_from_email",
            'cal_str' => $cal_str
        ];

    if ($config_ticket_client_general_notifications) {
        // Notify the ticket contact of the scheduled work
        $data[] = [
            'from' => $ticket_from['email'],
            'from_name' => $ticket_from['name'],
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => mysqli_escape_string($mysqli, "<div class='header'>
                                Hello, $contact_name
                            </div>
                            Your ticket regarding $ticket_subject has been scheduled for $email_datetime.
                            <br><br>
                            <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>Access your ticket here</a>
                            <br><br>
                            Please do not reply to this email.
                            <br><br>
                            <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
                            <strong>Subject:</strong> $ticket_subject<br>
                            <br><br>
                            <div class='footer'>
                                ~<br>
                                $session_company_name<br>
                                Support Department<br>
                                $config_ticket_from_email<br>
                            </div>
                            <div class='no-reply'>
                                This is an automated message. Please do not reply directly to this email.
                            </div>"),
            'cal_str' => $cal_str
        ];

        // Notify the watchers of the scheduled work
        $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");

        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = sanitizeInput($row['watcher_email']);
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
                'body' => mysqli_escape_string($mysqli, nullable_htmlentities("<div class='header'>
            Hello,
        </div>
        The ticket regarding $ticket_subject has been scheduled for $email_datetime.
        <br><br>
        <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>$ticket_link</a>
        <br><br>
        Please do not reply to this email.
        <br><br>
        <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
        <strong>Subject:</strong> $ticket_subject<br>
        <strong>Portal:</strong> <a href='https://$config_base_url/client/ticket.php?id=$ticket_id'>Access the ticket here</a>
        <br><br>
        <div class='footer'>
            ~<br>
            $session_company_name<br>
            Support Department<br>
            $config_ticket_from_email<br>
        </div>
        <div class='no-reply'>
            This is an automated message. Please do not reply directly to this email.
        </div>")),
                'cal_str' => $cal_str
            ];
        }
    }

    // Send
    $response = addToMailQueue($data);

    // Update ticket reply
    $ticket_reply_note = "Ticket scheduled for $email_datetime " . (boolval($onsite) ? '(onsite).' : '(remote).');
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply_note', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name edited ticket schedule", $client_id, $ticket_id);

    customAction('ticket_schedule', $ticket_id);

    if (empty($conflicting_tickets)) {
        flash_alert("Ticket scheduled for $email_datetime");
        redirect();
    } else {
        $_SESSION['alert_type'] = "error";
        flash_alert("Ticket scheduled for $email_datetime. Yet there are conflicting tickets scheduled for the same time: <br>" . implode(", <br>", $conflicting_tickets), 'error');
        redirect("calendar.php");
    }

}

if (isset($_GET['cancel_ticket_schedule'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['cancel_ticket_schedule']);

    $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id");
    $row = mysqli_fetch_assoc($sql);

    $client_id = intval($row['ticket_client_id']);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_schedule = sanitizeInput($row['ticket_schedule']);
    $ticket_cal_str = sanitizeInput($row['ticket_cal_str']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    // Remove Outlook Calendar event before clearing schedule
    deleteOutlookCalendarEvent($ticket_id);

    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = NULL, ticket_schedule_end = NULL, ticket_appointment_notes = NULL WHERE ticket_id = $ticket_id");

    // Sanitize Config Vars
    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
    $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
    $session_company_name = sanitizeInput($session_company_name);

    $ticket_from = resolveTicketFromIdentity($ticket_id);

    //Create iCal event
    $cal_str = createiCalStrCancel($ticket_cal_str);

    //Send emails

    $sql = mysqli_query($mysqli, "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations on contact_location_id = location_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE ticket_id = $ticket_id
    ");
    $row = mysqli_fetch_assoc($sql);

    $client_id = intval($row['ticket_client_id']);
    $client_name = sanitizeInput($row['client_name']);
    $ticket_details = sanitizeInput($row['ticket_details']);
    $contact_name = sanitizeInput($row['contact_name']);
    $contact_email = sanitizeInput($row['contact_email']);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $user_name = sanitizeInput($row['user_name']);
    $user_email = sanitizeInput($row['user_email']);

    // Notify the agent of the cancellation
    $data[] = [
            // User Email
            'from' => $ticket_from['email'],
            'from_name' => $ticket_from['name'],
            'recipient' => $user_email,
            'recipient_name' => $user_name,
            'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => "Hello, " . $user_name . "<br><br>Scheduled work for the ticket regarding $ticket_subject has been cancelled.<br><br>--------------------------------<br><a href=\"https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri\">$ticket_link</a><br>--------------------------------<br><br>Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/agent/ticket.php?id=$ticket_id&client_id=$client_id<br><br>~<br>$session_company_name<br>Support Department<br>$config_ticket_from_email",
            'cal_str' => $cal_str
        ];

    if ($config_ticket_client_general_notifications) {
        // Notify the ticket contact of the cancellation
        $data[] = [
            'from' => $ticket_from['email'],
            'from_name' => $ticket_from['name'],
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => mysqli_escape_string($mysqli, "<div class='header'>
                                Hello, $contact_name
                            </div>
                            Scheduled work for your ticket regarding $ticket_subject has been cancelled.
                            <br><br>
                            <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>Access your ticket here</a>
                            <br><br>
                            Please do not reply to this email.
                            <br><br>
                            <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
                            <strong>Subject:</strong> $ticket_subject<br>
                            <br><br>
                            <div class='footer'>
                                ~<br>
                                $session_company_name<br>
                                Support Department<br>
                                $config_ticket_from_email<br>
                            </div>
                            <div class='no-reply'>
                                This is an automated message. Please do not reply directly to this email.
                            </div>"),
            'cal_str' => $cal_str
        ];

        // Notify the watchers of the cancellation
        $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = sanitizeInput($row['watcher_email']);
            $data[] = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
                'body' => mysqli_escape_string($mysqli, nullable_htmlentities("<div class='header'>
            Hello,
        </div>
        Scheduled work for the ticket regarding $ticket_subject has been cancelled.
        <br><br>
        <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>$ticket_link</a>
        <br><br>
        Please do not reply to this email.
        <br><br>
        <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
        <strong>Subject:</strong> $ticket_subject<br>
        <strong>Portal:</strong> <a href='https://$config_base_url/client/ticket.php?id=$ticket_id'>Access the ticket here</a>
        <br><br>
        <div class='footer'>
            ~<br>
            $session_company_name<br>
            Support Department<br>
            $config_ticket_from_email<br>
        </div>
        <div class='no-reply'>
            This is an automated message. Please do not reply directly to this email.
        </div>")),
                'cal_str' => $cal_str
            ];
        }
    }

    // Send email(s)
    addToMailQueue($data);

    // Update ticket reply
    $ticket_reply_note = "Ticket schedule cancelled.";
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply_note', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name cancelled ticket schedule", $client_id, $ticket_id);

    customAction('ticket_unschedule', $ticket_id);

    flash_alert("Ticket schedule cancelled", 'error');

    redirect();

}

// ── Ticket Charges ──────────────────────────────────────────────────────────

if (isset($_POST['add_ticket_charge'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id        = intval($_POST['ticket_id']);
    $client_id        = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) { enforceClientAccess(); }
    $product_id       = intval($_POST['charge_product_id'] ?? 0);
    $labor_type_id    = intval($_POST['charge_labor_type_id'] ?? 0);
    $charge_name      = sanitizeInput($_POST['charge_name']);
    $charge_desc      = mysqli_real_escape_string($mysqli, $_POST['charge_description'] ?? '');
    $charge_qty       = round(floatval($_POST['charge_quantity']), 2);
    $charge_price     = round(floatval($_POST['charge_unit_price']), 2);
    $charge_total     = round($charge_qty * $charge_price, 2);
    $charge_tax_id    = intval($_POST['charge_tax_id'] ?? 0);

    mysqli_query($mysqli, "INSERT INTO ticket_charges SET
        charge_ticket_id    = $ticket_id,
        charge_product_id   = $product_id,
        charge_labor_type_id= $labor_type_id,
        charge_name         = '$charge_name',
        charge_description  = '$charge_desc',
        charge_quantity     = $charge_qty,
        charge_unit_price   = $charge_price,
        charge_total        = $charge_total,
        charge_tax_id       = $charge_tax_id,
        charge_created_by   = $session_user_id");

    logAction("Ticket", "Edit", "Added charge $charge_name to ticket", $client_id, $ticket_id);

    flash_alert("Charge added", 'success');
    redirect();
}

if (isset($_POST['edit_ticket_charge'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $charge_id      = intval($_POST['charge_id']);
    $charge_name    = sanitizeInput($_POST['charge_name']);
    $charge_desc    = mysqli_real_escape_string($mysqli, $_POST['charge_description'] ?? '');
    $charge_qty     = round(floatval($_POST['charge_quantity']), 2);
    $charge_price   = round(floatval($_POST['charge_unit_price']), 2);
    $charge_total   = round($charge_qty * $charge_price, 2);
    $charge_tax_id  = intval($_POST['charge_tax_id'] ?? 0);

    // Derive ticket_id/client_id FROM the charge row itself (matching the sibling
    // delete_ticket_charge handler below) rather than trusting a separately-POSTed
    // ticket_id - the access check must be validated against the SAME resource the
    // UPDATE actually targets, or an attacker can pass a ticket_id they legitimately
    // own alongside an unrelated charge_id belonging to another client's ticket.
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT charge_ticket_id FROM ticket_charges WHERE charge_id = $charge_id LIMIT 1"));
    $ticket_id = intval($r['charge_ticket_id'] ?? 0);
    if (!$ticket_id) {
        flash_alert("No charge with that ID found.", 'error');
        redirect();
    }

    $tr = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $client_id = intval($tr['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    mysqli_query($mysqli, "UPDATE ticket_charges SET
        charge_name        = '$charge_name',
        charge_description = '$charge_desc',
        charge_quantity    = $charge_qty,
        charge_unit_price  = $charge_price,
        charge_total       = $charge_total,
        charge_tax_id      = $charge_tax_id
        WHERE charge_id = $charge_id AND charge_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "Updated charge $charge_name on ticket", $client_id, $ticket_id);

    flash_alert("Charge updated", 'success');
    redirect();
}

if (isset($_GET['delete_ticket_charge'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $charge_id = intval($_GET['delete_ticket_charge']);
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT charge_ticket_id, charge_name FROM ticket_charges WHERE charge_id = $charge_id LIMIT 1"));
    $ticket_id   = intval($r['charge_ticket_id'] ?? 0);
    $charge_name = sanitizeInput($r['charge_name'] ?? 'charge');

    $tr = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $client_id = intval($tr['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    mysqli_query($mysqli, "UPDATE ticket_charges SET charge_archived_at = NOW() WHERE charge_id = $charge_id");

    logAction("Ticket", "Edit", "Deleted charge $charge_name from ticket", $client_id, $ticket_id);

    flash_alert("Charge deleted", 'error');
    redirect();
}

if (isset($_POST['upload_ticket_attachment'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);

    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $client_id = intval($r['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    $allowed = ['jpg','jpeg','gif','png','webp','pdf','txt','md','doc','docx','odt','csv','xls','xlsx','ods','pptx','odp','zip','tar','gz','xml','msg','json','wav','mp3','ogg','mov','mp4','av1','ovpn'];

    if (!empty($_FILES['attachment_file']['name']) && is_array($_FILES['attachment_file']['name'])) {

        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/tickets/$ticket_id/";
        mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/tickets/");
        mkdirMissing($upload_dir);

        $uploaded_count = 0;
        $rejected_count = 0;

        foreach ($_FILES['attachment_file']['name'] as $index => $original_name) {

            $single_file = [
                'name'     => $_FILES['attachment_file']['name'][$index],
                'type'     => $_FILES['attachment_file']['type'][$index],
                'tmp_name' => $_FILES['attachment_file']['tmp_name'][$index],
                'error'    => $_FILES['attachment_file']['error'][$index],
                'size'     => $_FILES['attachment_file']['size'][$index],
            ];

            if ($single_file['error'] !== UPLOAD_ERR_OK) {
                $rejected_count++;
                continue;
            }

            $ref_name = checkFileUpload($single_file, $allowed);

            if (!is_string($ref_name) || !preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $ref_name)) {
                $rejected_count++;
                continue;
            }

            move_uploaded_file($single_file['tmp_name'], $upload_dir . $ref_name);

            $name = sanitizeInput($original_name);
            $ref  = mysqli_real_escape_string($mysqli, $ref_name);

            mysqli_query($mysqli,
                "INSERT INTO ticket_attachments SET ticket_attachment_name='$name', ticket_attachment_reference_name='$ref', ticket_attachment_reply_id=NULL, ticket_attachment_ticket_id=$ticket_id"
            );

            logAction("Ticket", "Edit", "Uploaded attachment $name to ticket", $client_id, $ticket_id);
            $uploaded_count++;
        }

        if ($uploaded_count && $rejected_count) {
            flash_alert("$uploaded_count file(s) uploaded, $rejected_count skipped (invalid or unsupported type)", 'warning');
        } elseif ($uploaded_count) {
            flash_alert($uploaded_count === 1 ? "Attachment uploaded" : "$uploaded_count attachments uploaded", 'success');
        } else {
            flash_alert("No files uploaded - invalid or unsupported file type", 'error');
        }

    } else {
        flash_alert("No file uploaded", 'error');
    }

    redirect();
}

if (isset($_GET['delete_ticket_attachment'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $attachment_id = intval($_GET['delete_ticket_attachment']);
    $ticket_id     = intval($_GET['ticket_id']);

    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $client_id = intval($r['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    $att = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_attachments WHERE ticket_attachment_id = $attachment_id AND ticket_attachment_ticket_id = $ticket_id LIMIT 1"));

    if ($att) {
        $ref_name = $att['ticket_attachment_reference_name'];
        $file_path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/tickets/$ticket_id/$ref_name";
        if (is_file($file_path)) {
            unlink($file_path);
        }

        mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_id = $attachment_id");

        $name = sanitizeInput($att['ticket_attachment_name']);
        logAction("Ticket", "Edit", "Deleted attachment $name from ticket", $client_id, $ticket_id);
        flash_alert("Attachment deleted", 'error');
    }

    redirect();
}

// ── Multiple Schedule Entries ────────────────────────────────────────────────

if (isset($_POST['add_ticket_schedule'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id    = intval($_POST['ticket_id']);
    $onsite       = intval($_POST['schedule_onsite']);
    $start        = sanitizeInput($_POST['schedule_start']);
    $end_raw      = sanitizeInput($_POST['schedule_end'] ?? '');
    $end_sql      = $end_raw ? "'$end_raw'" : 'NULL';
    $notes        = sanitizeInput($_POST['schedule_notes'] ?? '');

    // Multi-tech: collect selected tech IDs; fall back to unassigned (0) if none checked
    $raw_tech_ids = isset($_POST['schedule_tech_ids']) ? array_map('intval', (array)$_POST['schedule_tech_ids']) : [];
    if (empty($raw_tech_ids)) $raw_tech_ids = [0];

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) enforceClientAccess();

    foreach ($raw_tech_ids as $tech_id) {
        mysqli_query($mysqli, "INSERT INTO ticket_schedules
            (schedule_ticket_id, schedule_start, schedule_end, schedule_onsite, schedule_tech_id, schedule_notes, schedule_created_by)
            VALUES ($ticket_id, '$start', $end_sql, $onsite, $tech_id, '$notes', $session_user_id)");
        syncScheduleEntryToOutlook(mysqli_insert_id($mysqli));
    }

    $tech_count = count($raw_tech_ids);
    logAction("Ticket", "Edit", "Added appointment ($tech_count tech(s)) to ticket #$ticket_id", $client_id, $ticket_id);
    flash_alert("Appointment added", 'success');
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['edit_ticket_schedule_entry'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $schedule_id  = intval($_POST['schedule_id']);
    $ticket_id    = intval($_POST['ticket_id']);
    $onsite       = intval($_POST['schedule_onsite']);
    $tech_id      = intval($_POST['schedule_tech_id'] ?? 0);
    $start        = sanitizeInput($_POST['schedule_start']);
    $end_raw      = sanitizeInput($_POST['schedule_end'] ?? '');
    $end_sql      = $end_raw ? "'$end_raw'" : 'NULL';
    $notes        = sanitizeInput($_POST['schedule_notes'] ?? '');

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) enforceClientAccess();

    mysqli_query($mysqli, "UPDATE ticket_schedules SET
        schedule_start   = '$start',
        schedule_end     = $end_sql,
        schedule_onsite  = $onsite,
        schedule_tech_id = $tech_id,
        schedule_notes   = '$notes'
        WHERE schedule_id = $schedule_id AND schedule_ticket_id = $ticket_id");

    syncScheduleEntryToOutlook($schedule_id);

    logAction("Ticket", "Edit", "Updated appointment #$schedule_id on ticket #$ticket_id", $client_id, $ticket_id);
    flash_alert("Appointment updated", 'success');
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_GET['delete_ticket_schedule'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $schedule_id = intval($_GET['delete_ticket_schedule']);
    $ticket_id   = intval($_GET['ticket_id']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) enforceClientAccess();

    // Verify this schedule entry actually belongs to $ticket_id BEFORE touching
    // the external Outlook event - otherwise a user with access to their own
    // ticket could pass an arbitrary schedule_id belonging to a different
    // client's ticket and delete that client's calendar event as a side
    // effect, even though the DB row itself is safely scoped below.
    $owns_schedule = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT 1 FROM ticket_schedules WHERE schedule_id = $schedule_id AND schedule_ticket_id = $ticket_id AND schedule_archived_at IS NULL LIMIT 1"));
    if (!$owns_schedule) {
        flash_alert("Schedule entry not found", 'error');
        redirect("ticket.php?ticket_id=$ticket_id");
    }

    deleteOutlookScheduleEvent($schedule_id);

    mysqli_query($mysqli, "UPDATE ticket_schedules SET schedule_archived_at = NOW() WHERE schedule_id = $schedule_id AND schedule_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "Removed schedule entry #$schedule_id from ticket #$ticket_id", $client_id, $ticket_id);
    flash_alert("Schedule entry removed", 'error');
    redirect("ticket.php?ticket_id=$ticket_id");
}

// ── Multiple Technicians ─────────────────────────────────────────────────────

if (isset($_POST['add_ticket_tech'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $user_id   = intval($_POST['tech_user_id']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) enforceClientAccess();

    if ($user_id > 0) {
        mysqli_query($mysqli, "INSERT IGNORE INTO ticket_techs
            (tech_ticket_id, tech_user_id, tech_created_by)
            VALUES ($ticket_id, $user_id, $session_user_id)");
        logAction("Ticket", "Edit", "Added technician to ticket #$ticket_id", $client_id, $ticket_id);
        flash_alert("Technician added", 'success');
    }

    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_GET['delete_ticket_tech'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $tech_id   = intval($_GET['delete_ticket_tech']);
    $ticket_id = intval($_GET['ticket_id']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) enforceClientAccess();

    mysqli_query($mysqli, "DELETE FROM ticket_techs WHERE tech_id = $tech_id AND tech_ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "Removed technician from ticket #$ticket_id", $client_id, $ticket_id);
    flash_alert("Technician removed", 'error');
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['toggle_reply_onsite'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $reply_id  = intval($_POST['ticket_reply_id']);
    $onsite    = intval($_POST['onsite']);

    // Derive client_id from the reply's ticket (not from POST) to prevent IDOR bypass
    $reply_ticket_id = intval(getFieldById('ticket_replies', $reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $reply_ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_onsite = $onsite WHERE ticket_reply_id = $reply_id");
    redirect("ticket.php?ticket_id=$reply_ticket_id");
}

if (isset($_POST['update_reply_labor_type'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $reply_id      = intval($_POST['ticket_reply_id']);
    $labor_type_id = intval($_POST['labor_type_id']);

    // Derive client_id from the reply's ticket (not from POST) to prevent IDOR bypass
    $reply_ticket_id = intval(getFieldById('ticket_replies', $reply_id, 'ticket_reply_ticket_id'));
    $client_id = intval(getFieldById('tickets', $reply_ticket_id, 'ticket_client_id'));

    if ($client_id) {
        enforceClientAccess();
    }

    $lt_id_sql = $labor_type_id > 0 ? $labor_type_id : 'NULL';

    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_labor_type_id = $lt_id_sql WHERE ticket_reply_id = $reply_id");

    logAction("Ticket", "Edit", "$session_name changed labor type on a time entry", $client_id, $reply_ticket_id);

    redirect("ticket.php?ticket_id=$reply_ticket_id");
}
