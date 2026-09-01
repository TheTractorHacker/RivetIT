<?php

/*
 * ITFlow - GET/POST request handler for Contract Templates
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_contract_template'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Sanitize text inputs
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $type = sanitizeInput($_POST['type']);
    $renewal_frequency = sanitizeInput($_POST['renewal_frequency']);
    $support_hours = sanitizeInput($_POST['support_hours']);
    $details = mysqli_escape_string($mysqli, $_POST['details']);

    // Numeric fields cast to integer
    $sla_low_resp = intval($_POST['sla_low_response_time']);
    $sla_med_resp = intval($_POST['sla_medium_response_time']);
    $sla_high_resp = intval($_POST['sla_high_response_time']);
    $sla_low_res = intval($_POST['sla_low_resolution_time']);
    $sla_med_res = intval($_POST['sla_medium_resolution_time']);
    $sla_high_res = intval($_POST['sla_high_resolution_time']);
    $rate_standard = is_numeric($_POST['rate_standard'] ?? '') ? floatval($_POST['rate_standard']) : 'NULL';
    $rate_after_hours = is_numeric($_POST['rate_after_hours'] ?? '') ? floatval($_POST['rate_after_hours']) : 'NULL';
    $net_terms = sanitizeInput($_POST['net_terms']);

    // Insert into database (numbers not quoted)
    mysqli_query($mysqli, "
        INSERT INTO contract_templates SET
        contract_template_name = '$name',
        contract_template_description = '$description',
        contract_template_details = '$details',
        contract_template_type = '$type',
        contract_template_renewal_frequency = '$renewal_frequency',
        contract_template_sla_low_response_time = $sla_low_resp,
        contract_template_sla_medium_response_time = $sla_med_resp,
        contract_template_sla_high_response_time = $sla_high_resp,
        contract_template_sla_low_resolution_time = $sla_low_res,
        contract_template_sla_medium_resolution_time = $sla_med_res,
        contract_template_sla_high_resolution_time = $sla_high_res,
        contract_template_rate_standard = $rate_standard,
        contract_template_rate_after_hours = $rate_after_hours,
        contract_template_support_hours = '$support_hours',
        contract_template_net_terms = '$net_terms'
    ");

    $contract_template_id = mysqli_insert_id($mysqli);

    // Log action
    logAction("Contract Template", "Create", "$session_name created contract template $name", 0, $contract_template_id);

    // Flash message
    flash_alert("Contract Template <strong>$name</strong> created");

    // Redirect back
    redirect();
}

if (isset($_POST['edit_contract_template'])) {

    validateCSRFToken($_POST['csrf_token']);

    $contract_template_id = intval($_POST['contract_template_id']);
    $name            = sanitizeInput($_POST['name']);
    $description     = sanitizeInput($_POST['description']);
    $type            = sanitizeInput($_POST['type']);
    $renewal_frequency= sanitizeInput($_POST['renewal_frequency']);
    $support_hours   = sanitizeInput($_POST['support_hours']);
    $details         = mysqli_escape_string($mysqli, $_POST['details']);
    $sla_low_resp  = intval($_POST['sla_low_response_time']);
    $sla_med_resp  = intval($_POST['sla_medium_response_time']);
    $sla_high_resp = intval($_POST['sla_high_response_time']);
    $sla_low_res   = intval($_POST['sla_low_resolution_time']);
    $sla_med_res   = intval($_POST['sla_medium_resolution_time']);
    $sla_high_res  = intval($_POST['sla_high_resolution_time']);
    $rate_standard   = is_numeric($_POST['rate_standard'] ?? '') ? floatval($_POST['rate_standard']) : 'NULL';
    $rate_after_hours = is_numeric($_POST['rate_after_hours'] ?? '') ? floatval($_POST['rate_after_hours']) : 'NULL';
    $net_terms     = sanitizeInput($_POST['net_terms']);

    mysqli_query($mysqli, "
        UPDATE contract_templates SET
            contract_template_name = '$name',
            contract_template_description = '$description',
            contract_template_details = '$details',
            contract_template_type = '$type',
            contract_template_renewal_frequency = '$renewal_frequency',
            contract_template_sla_low_response_time = $sla_low_resp,
            contract_template_sla_medium_response_time = $sla_med_resp,
            contract_template_sla_high_response_time = $sla_high_resp,
            contract_template_sla_low_resolution_time = $sla_low_res,
            contract_template_sla_medium_resolution_time = $sla_med_res,
            contract_template_sla_high_resolution_time = $sla_high_res,
            contract_template_rate_standard = $rate_standard,
            contract_template_rate_after_hours = $rate_after_hours,
            contract_template_support_hours = '$support_hours',
            contract_template_net_terms = '$net_terms'
        WHERE contract_template_id = $contract_template_id
    ");

    // Log action
    logAction("Contract Template", "Update", "$session_name updated contract template $name", 0, $contract_template_id);

    // Flash + redirect
    flash_alert("Contract Template <strong>$name</strong> updated");
    redirect();
}

if (isset($_GET['archive_contract_template'])) {
    validateCSRFToken($_GET['csrf_token']);

    $contract_template_id = intval($_GET['archive_contract_template']);

    $name = getFieldById('contract_templates', $contract_template_id, 'contract_template_name');

    mysqli_query($mysqli, "
        UPDATE contract_templates SET contract_template_archived_at = NOW()
        WHERE contract_template_id = $contract_template_id
        LIMIT 1
    ");

    logAction("Contract Template", "Archive", "$session_name archived contract template $name", 0, $contract_template_id);
    flash_alert("Contract Template <strong>$name</strong> archived", "danger");
    redirect();
}

if (isset($_GET['restore_contract_template'])) {
    validateCSRFToken($_GET['csrf_token']);

    $contract_template_id = intval($_GET['restore_contract_template']);

    $name = getFieldById('contract_templates', $contract_template_id, 'contract_template_name');

    mysqli_query($mysqli, "
        UPDATE contract_templates SET contract_template_archived_at = NULL
        WHERE contract_template_id = $contract_template_id
        LIMIT 1
    ");

    logAction("Contract Template", "Restore", "$session_name restored contract template $name", 0, $contract_template_id);
    flash_alert("Contract Template <strong>$name</strong> restored");
    redirect();
}

if (isset($_GET['delete_contract_template'])) {
    validateCSRFToken($_GET['csrf_token']);

    $contract_template_id = intval($_GET['delete_contract_template']);

    $name = getFieldById('contract_templates', $contract_template_id, 'contract_template_name');

    mysqli_query($mysqli, "
        DELETE FROM contract_templates
        WHERE contract_template_id = $contract_template_id
        LIMIT 1
    ");

    logAction("Contract Template", "Delete", "$session_name deleted contract template $name", 0, $contract_template_id);
    flash_alert("Contract Template <strong>$name</strong> deleted", "danger");
    redirect();
}

if (isset($_POST['apply_contract_template'])) {
    validateCSRFToken($_POST['csrf_token']);

    $contract_template_id = intval($_POST['contract_template_id']);
    $client_ids = array_map('intval', $_POST['client_ids'] ?? []);
    $contract_status = sanitizeInput($_POST['contract_status'] ?? 'Active');
    $start_date = sanitizeInput($_POST['contract_start_date'] ?? '');
    $start_sql = $start_date ? "'$start_date'" : 'NULL';

    $template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contract_templates WHERE contract_template_id = $contract_template_id LIMIT 1"));

    if (!$template) {
        flash_alert("Contract template not found", "danger");
        redirect();
    }

    $name = mysqli_real_escape_string($mysqli, $template['contract_template_name']);
    $type = mysqli_real_escape_string($mysqli, $template['contract_template_type']);
    $freq = $template['contract_template_renewal_frequency'] !== null && $template['contract_template_renewal_frequency'] !== ''
        ? "'" . mysqli_real_escape_string($mysqli, $template['contract_template_renewal_frequency']) . "'" : 'NULL';
    $rate_standard = $template['contract_template_rate_standard'] !== null ? floatval($template['contract_template_rate_standard']) : 'NULL';
    $rate_after_hours = $template['contract_template_rate_after_hours'] !== null ? floatval($template['contract_template_rate_after_hours']) : 'NULL';
    $net_terms = mysqli_real_escape_string($mysqli, $template['contract_template_net_terms']);
    $support_hours = mysqli_real_escape_string($mysqli, $template['contract_template_support_hours']);
    $details = mysqli_real_escape_string($mysqli, $template['contract_template_details']);
    $sla_lr = intval($template['contract_template_sla_low_response_time']);
    $sla_lres = intval($template['contract_template_sla_low_resolution_time']);
    $sla_mr = intval($template['contract_template_sla_medium_response_time']);
    $sla_mres = intval($template['contract_template_sla_medium_resolution_time']);
    $sla_hr = intval($template['contract_template_sla_high_response_time']);
    $sla_hres = intval($template['contract_template_sla_high_resolution_time']);

    $applied = 0;
    foreach ($client_ids as $cid) {
        if ($cid <= 0) continue;

        mysqli_query($mysqli, "INSERT INTO contracts SET
            contract_client_id = $cid,
            contract_name = '$name',
            contract_type = '$type',
            contract_status = '$contract_status',
            contract_renewal_frequency = $freq,
            contract_start_date = $start_sql,
            contract_rate_standard = $rate_standard,
            contract_rate_after_hours = $rate_after_hours,
            contract_net_terms = '$net_terms',
            contract_support_hours = '$support_hours',
            contract_details = '$details',
            contract_sla_low_response_time = $sla_lr,
            contract_sla_low_resolution_time = $sla_lres,
            contract_sla_medium_response_time = $sla_mr,
            contract_sla_medium_resolution_time = $sla_mres,
            contract_sla_high_response_time = $sla_hr,
            contract_sla_high_resolution_time = $sla_hres,
            contract_created_by = $session_user_id
        ");
        $applied++;
    }

    if ($applied > 0) {
        logAction("Contract Template", "Apply", "$session_name applied contract template $name to $applied client(s)", 0, $contract_template_id);
        flash_alert("Contract template <strong>$name</strong> applied to <strong>$applied</strong> client(s).");
    } else {
        flash_alert("No clients selected.", "danger");
    }
    redirect();
}

?>
