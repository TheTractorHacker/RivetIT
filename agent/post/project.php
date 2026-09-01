<?php

/*
 * ITFlow - GET/POST request handler for tasks
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_name = sanitizeInput($_POST['name']);
    $project_description = sanitizeInput($_POST['description']);
    $due_date = sanitizeInput($_POST['due_date']);
    $project_manager = intval($_POST['project_manager']);
    $client_id = intval($_POST['client_id']);
    $project_template_id = intval($_POST['project_template_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Sanitize Project Prefix
    $config_project_prefix = sanitizeInput($config_project_prefix);

    // Atomically increment and get the new project number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_project_next_number = LAST_INSERT_ID(config_project_next_number),
            config_project_next_number = config_project_next_number + 1
        WHERE company_id = 1
    ");

    $project_number = mysqli_insert_id($mysqli);

    // Optional planning/budget fields
    $project_start = sanitizeInput($_POST['start_date'] ?? '');
    $project_estimated_hours = floatval($_POST['estimated_hours'] ?? 0);
    $project_budget_amount = floatval($_POST['budget_amount'] ?? 0);
    $project_hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
    $project_start_sql = $project_start ? "'$project_start'" : "NULL";
    $project_estimated_hours_sql = $project_estimated_hours > 0 ? $project_estimated_hours : "NULL";
    $project_budget_amount_sql = $project_budget_amount > 0 ? $project_budget_amount : "NULL";
    $project_hourly_rate_sql = $project_hourly_rate > 0 ? $project_hourly_rate : "NULL";

    mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = '$config_project_prefix', project_number = $project_number, project_name = '$project_name', project_description = '$project_description', project_due = '$due_date', project_manager = $project_manager, project_client_id = $client_id, project_start = $project_start_sql, project_estimated_hours = $project_estimated_hours_sql, project_budget_amount = $project_budget_amount_sql, project_hourly_rate = $project_hourly_rate_sql");

    $project_id = mysqli_insert_id($mysqli);

    // Optionally apply a contract template to this client - creates a real contract
    // and surfaces its terms on the onboarding ticket(s) created below
    $contract_template_id = intval($_POST['contract_template_id'] ?? 0);
    $contract_info_html = '';

    if ($contract_template_id && $client_id) {
        $contract_template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contract_templates WHERE contract_template_id = $contract_template_id LIMIT 1"));

        if ($contract_template) {
            $ct_name = mysqli_real_escape_string($mysqli, $contract_template['contract_template_name']);
            $ct_type = mysqli_real_escape_string($mysqli, $contract_template['contract_template_type']);
            $ct_freq = $contract_template['contract_template_renewal_frequency'] !== null && $contract_template['contract_template_renewal_frequency'] !== ''
                ? "'" . mysqli_real_escape_string($mysqli, $contract_template['contract_template_renewal_frequency']) . "'" : 'NULL';
            $ct_rate_standard = $contract_template['contract_template_rate_standard'] !== null ? floatval($contract_template['contract_template_rate_standard']) : 'NULL';
            $ct_rate_after_hours = $contract_template['contract_template_rate_after_hours'] !== null ? floatval($contract_template['contract_template_rate_after_hours']) : 'NULL';
            $ct_net_terms = mysqli_real_escape_string($mysqli, $contract_template['contract_template_net_terms']);
            $ct_support_hours = mysqli_real_escape_string($mysqli, $contract_template['contract_template_support_hours']);
            $ct_details = mysqli_real_escape_string($mysqli, $contract_template['contract_template_details']);
            $ct_sla_lr = intval($contract_template['contract_template_sla_low_response_time']);
            $ct_sla_lres = intval($contract_template['contract_template_sla_low_resolution_time']);
            $ct_sla_mr = intval($contract_template['contract_template_sla_medium_response_time']);
            $ct_sla_mres = intval($contract_template['contract_template_sla_medium_resolution_time']);
            $ct_sla_hr = intval($contract_template['contract_template_sla_high_response_time']);
            $ct_sla_hres = intval($contract_template['contract_template_sla_high_resolution_time']);

            mysqli_query($mysqli, "INSERT INTO contracts SET
                contract_client_id = $client_id,
                contract_name = '$ct_name',
                contract_type = '$ct_type',
                contract_status = 'Active',
                contract_renewal_frequency = $ct_freq,
                contract_start_date = CURDATE(),
                contract_rate_standard = $ct_rate_standard,
                contract_rate_after_hours = $ct_rate_after_hours,
                contract_net_terms = '$ct_net_terms',
                contract_support_hours = '$ct_support_hours',
                contract_details = '$ct_details',
                contract_sla_low_response_time = $ct_sla_lr,
                contract_sla_low_resolution_time = $ct_sla_lres,
                contract_sla_medium_response_time = $ct_sla_mr,
                contract_sla_medium_resolution_time = $ct_sla_mres,
                contract_sla_high_response_time = $ct_sla_hr,
                contract_sla_high_resolution_time = $ct_sla_hres,
                contract_created_by = $session_user_id
            ");

            logAction("Contract", "Add", "$session_name applied contract template " . $contract_template['contract_template_name'] . " during onboarding", $client_id);

            // Build a summary to surface the client's contract terms on the onboarding ticket(s)
            $contract_info_html = '<hr><p><strong>Contract: ' . nullable_htmlentities($contract_template['contract_template_name']) . '</strong>';
            if ($contract_template['contract_template_type']) {
                $contract_info_html .= ' (' . nullable_htmlentities($contract_template['contract_template_type']) . ')';
            }
            $contract_info_html .= '</p><ul>';
            if ($contract_template['contract_template_support_hours']) {
                $contract_info_html .= '<li>Support Hours: ' . nullable_htmlentities($contract_template['contract_template_support_hours']) . '</li>';
            }
            if ($contract_template['contract_template_rate_standard'] !== null) {
                $contract_info_html .= '<li>Standard Rate: $' . number_format($contract_template['contract_template_rate_standard'], 2) . '/hr</li>';
            }
            if ($contract_template['contract_template_rate_after_hours'] !== null) {
                $contract_info_html .= '<li>After Hours Rate: $' . number_format($contract_template['contract_template_rate_after_hours'], 2) . '/hr</li>';
            }
            if ($contract_template['contract_template_net_terms']) {
                $contract_info_html .= '<li>Net Terms: ' . nullable_htmlentities($contract_template['contract_template_net_terms']) . '</li>';
            }
            if ($ct_sla_lr || $ct_sla_mr || $ct_sla_hr) {
                $contract_info_html .= "<li>SLA Response/Resolution (hrs) &mdash; Low: $ct_sla_lr/$ct_sla_lres, Medium: $ct_sla_mr/$ct_sla_mres, High: $ct_sla_hr/$ct_sla_hres</li>";
            }
            $contract_info_html .= '</ul>';
            if ($contract_template['contract_template_details']) {
                $contract_info_html .= '<div>' . $contract_template['contract_template_details'] . '</div>';
            }
        }
    }

    // If project template is selected add Ticket Templates and convert them to real tickets
    if($project_template_id) {
         // Get Associated Ticket Templates
        $sql_ticket_templates = mysqli_query($mysqli, "SELECT * FROM ticket_templates, project_template_ticket_templates
            WHERE ticket_templates.ticket_template_id = project_template_ticket_templates.ticket_template_id
            AND project_template_ticket_templates.project_template_id = $project_template_id");
        $ticket_template_count = mysqli_num_rows($sql_ticket_templates);

        while ($row = mysqli_fetch_assoc($sql_ticket_templates)) {
            $ticket_template_id = intval($row['ticket_template_id']);
            $ticket_template_order = intval($row['ticket_template_order']);
            $ticket_template_subject = sanitizeInput($row['ticket_template_subject']);
            $ticket_template_details = mysqli_escape_string($mysqli, $row['ticket_template_details']);

            if ($contract_info_html) {
                $ticket_template_details .= mysqli_escape_string($mysqli, $contract_info_html);
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

            $resolved_assigned_to = resolveTicketAssignee(0);
            $ticket_status = $resolved_assigned_to > 0 ? 2 : 1;

            mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_subject = '$ticket_template_subject', ticket_details = '$ticket_template_details', ticket_priority = 'Low', ticket_status = $ticket_status, ticket_created_by = $session_user_id, ticket_client_id = $client_id, ticket_project_id = $project_id, ticket_assigned_to = $resolved_assigned_to");

            $ticket_id = mysqli_insert_id($mysqli);

            // Task Templates for Ticket template and add the to the ticket
            $sql_task_templates = mysqli_query($mysqli,
                "SELECT * FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id");
            $task_template_count = mysqli_num_rows($sql_task_templates);

            while ($row = mysqli_fetch_assoc($sql_task_templates)) {
                $task_template_id = intval($row['task_template_id']);
                $task_template_order = intval($row['task_template_order']);
                $task_template_name = sanitizeInput($row['task_template_name']);

                mysqli_query($mysqli,"INSERT INTO tasks SET task_name = '$task_template_name', task_order = $task_template_order, task_ticket_id = $ticket_id");
            } // End task Loop
        } // End Ticket Loop
    } // End If Project Template

    logAction("Project", "Create", "$session_name created project $project_name", $client_id, $project_id);

    flash_alert("You created Project <strong>$project_name</strong>");

    redirect();

}

if (isset($_POST['edit_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);
    $project_name = sanitizeInput($_POST['name']);
    $project_description = sanitizeInput($_POST['description']);
    $due_date = sanitizeInput($_POST['due_date']);
    $project_manager = intval($_POST['project_manager']);
    $client_id = intval($_POST['client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Optional planning/budget fields
    $project_start = sanitizeInput($_POST['start_date'] ?? '');
    $project_estimated_hours = floatval($_POST['estimated_hours'] ?? 0);
    $project_budget_amount = floatval($_POST['budget_amount'] ?? 0);
    $project_hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
    $project_start_sql = $project_start ? "'$project_start'" : "NULL";
    $project_estimated_hours_sql = $project_estimated_hours > 0 ? $project_estimated_hours : "NULL";
    $project_budget_amount_sql = $project_budget_amount > 0 ? $project_budget_amount : "NULL";
    $project_hourly_rate_sql = $project_hourly_rate > 0 ? $project_hourly_rate : "NULL";

    mysqli_query($mysqli, "UPDATE projects SET project_name = '$project_name', project_description = '$project_description', project_due = '$due_date', project_manager = $project_manager, project_client_id = $client_id, project_start = $project_start_sql, project_estimated_hours = $project_estimated_hours_sql, project_budget_amount = $project_budget_amount_sql, project_hourly_rate = $project_hourly_rate_sql WHERE project_id = $project_id");

    logAction("Project", "Edit", "$session_name edited project $project_name", $client_id, $project_id);

    flash_alert("Project <strong>$project_name</strong> edited");

    redirect();

}

if (isset($_GET['close_project'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['close_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = sanitizeInput($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_completed_at = NOW() WHERE project_id = $project_id");

    logAction("Project", "Close", "$session_name closed project $project_name", $client_id, $project_id);

    flash_alert("Project <strong>$project_name</strong> closed");

    redirect();

}

if (isset($_GET['archive_project'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['archive_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = sanitizeInput($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_archived_at = NOW() WHERE project_id = $project_id");

    logAction("Project", "Archive", "$session_name archived project $project_name", $client_id, $project_id);

    flash_alert("Project <strong>$project_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_project'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['restore_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = sanitizeInput($row['project_name']);
    $client_id = sanitizeInput($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_archived_at = NULL WHERE project_id = $project_id");

    logAction("Project", "Restore", "$session_name restored project $project_name", $client_id, $project_id);

    flash_alert("Project <strong>$project_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_project'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 3);

    $project_id = intval($_GET['delete_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = sanitizeInput($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project_id");

    logAction("Project", "Delete", "$session_name deleted project $project_name", $client_id, $project_id);

    flash_alert("Project <strong>$project_name</strong> Deleted", 'error');

    redirect();

}

if (isset($_POST['link_ticket_to_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_client_id, project_name FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['project_client_id']);
    $project_name = sanitizeInput($row['project_name']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Add Tickets
    if (isset($_POST['tickets'])) {

        // Get Selected Count
        $count = count($_POST['tickets']);

        foreach ($_POST['tickets'] as $ticket) {
            $ticket_id = intval($ticket);

            // Get Ticket Info
            $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject FROM tickets WHERE ticket_id = $ticket_id");
            $row = mysqli_fetch_assoc($sql);
            $ticket_prefix = sanitizeInput($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = sanitizeInput($row['ticket_subject']);

            mysqli_query($mysqli, "UPDATE tickets SET ticket_project_id = $project_id WHERE ticket_id = $ticket_id");

            logAction("Project", "Edit", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $project_id);

        }

        logAction("Project", "Bulk Edit", "$session_name added $count ticket(s) to project $project_name", $client_id, $project_id);

        flash_alert("<strong>$count</strong> Ticket(s) added to <strong>$project_name</strong>");
    }

    redirect();

}

if (isset($_POST['link_closed_ticket_to_project'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);
    $ticket_number = intval($_POST['ticket_number']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_client_id, project_name FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['project_client_id']);
    $project_name = sanitizeInput($row['project_name']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Get ticket details
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id, ticket_prefix, ticket_number, ticket_subject, ticket_updated_at FROM tickets WHERE ticket_number = $ticket_number");
    if (mysqli_num_rows($sql) == 0) {
        flash_alert("Cannot merge into that ticket.", 'error');
        redirect();
    }
    $row = mysqli_fetch_assoc($sql);
    $ticket_id = intval($row['ticket_id']);
    $ticket_client_id = intval($row['ticket_client_id']);
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_updated = sanitizeInput($row['ticket_updated_at']); // So we don't mess with the last response

    // ticket_number is a global, non-client-scoped counter - reject if the matched
    // ticket doesn't actually belong to this project's client before re-parenting it.
    if ($ticket_client_id != $client_id) {
        flash_alert("Cannot merge into that ticket.", 'error');
        redirect();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_project_id = $project_id, ticket_updated_at = '$ticket_updated' WHERE ticket_id = $ticket_id");

    logAction("Project", "Edit", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $project_id);

    flash_alert("Ticket added to <strong>$project_name</strong>");

    redirect();

}

if (isset($_POST['add_milestone'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);
    $milestone_name = sanitizeInput($_POST['name']);
    $milestone_description = sanitizeInput($_POST['description']);
    $milestone_due = sanitizeInput($_POST['due']);
    $milestone_order = intval($_POST['order']);

    // Resolve client from the project for access checks/logging
    $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $milestone_due_sql = $milestone_due ? "'$milestone_due'" : "NULL";

    mysqli_query($mysqli, "INSERT INTO project_milestones SET milestone_project_id = $project_id, milestone_name = '$milestone_name', milestone_description = '$milestone_description', milestone_due = $milestone_due_sql, milestone_order = $milestone_order");

    $milestone_id = mysqli_insert_id($mysqli);

    logAction("Project", "Edit", "$session_name added milestone $milestone_name", $client_id, $project_id);

    flash_alert("Milestone <strong>$milestone_name</strong> created");

    redirect();

}

if (isset($_POST['edit_milestone'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $milestone_id = intval($_POST['milestone_id']);
    $project_id = intval($_POST['project_id']);
    $milestone_name = sanitizeInput($_POST['name']);
    $milestone_description = sanitizeInput($_POST['description']);
    $milestone_due = sanitizeInput($_POST['due']);
    $milestone_order = intval($_POST['order']);

    // Resolve client from the milestone's project for access checks/logging
    $project_id = intval(getFieldById('project_milestones', $milestone_id, 'milestone_project_id'));
    $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $milestone_due_sql = $milestone_due ? "'$milestone_due'" : "NULL";

    mysqli_query($mysqli, "UPDATE project_milestones SET milestone_name = '$milestone_name', milestone_description = '$milestone_description', milestone_due = $milestone_due_sql, milestone_order = $milestone_order WHERE milestone_id = $milestone_id");

    logAction("Project", "Edit", "$session_name edited milestone $milestone_name", $client_id, $project_id);

    flash_alert("Milestone <strong>$milestone_name</strong> edited");

    redirect();

}

if (isset($_GET['toggle_milestone'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $milestone_id = intval($_GET['toggle_milestone']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT milestone_project_id, milestone_name, milestone_status FROM project_milestones WHERE milestone_id = $milestone_id LIMIT 1"));
    $project_id = intval($row['milestone_project_id']);
    $milestone_name = sanitizeInput($row['milestone_name']);
    $milestone_status = sanitizeInput($row['milestone_status']);

    $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    if ($milestone_status == 'completed') {
        mysqli_query($mysqli, "UPDATE project_milestones SET milestone_status = 'open', milestone_completed_at = NULL WHERE milestone_id = $milestone_id");
        logAction("Project", "Edit", "$session_name reopened milestone $milestone_name", $client_id, $project_id);
        flash_alert("Milestone <strong>$milestone_name</strong> reopened");
    } else {
        mysqli_query($mysqli, "UPDATE project_milestones SET milestone_status = 'completed', milestone_completed_at = NOW() WHERE milestone_id = $milestone_id");
        logAction("Project", "Edit", "$session_name completed milestone $milestone_name", $client_id, $project_id);
        flash_alert("Milestone <strong>$milestone_name</strong> completed");
    }

    redirect();

}

if (isset($_GET['delete_milestone'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 3);

    $milestone_id = intval($_GET['delete_milestone']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT milestone_project_id, milestone_name FROM project_milestones WHERE milestone_id = $milestone_id LIMIT 1"));
    $project_id = intval($row['milestone_project_id']);
    $milestone_name = sanitizeInput($row['milestone_name']);

    $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    // Detach any tasks from this milestone before removing it (tasks remain on the project)
    mysqli_query($mysqli, "UPDATE tasks SET task_milestone_id = NULL WHERE task_milestone_id = $milestone_id");

    mysqli_query($mysqli, "DELETE FROM project_milestones WHERE milestone_id = $milestone_id");

    logAction("Project", "Delete", "$session_name deleted milestone $milestone_name", $client_id, $project_id);

    flash_alert("Milestone <strong>$milestone_name</strong> deleted", 'error');

    redirect();

}
