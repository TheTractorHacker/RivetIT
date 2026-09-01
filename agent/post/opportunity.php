<?php

/*
 * ITFlow - GET/POST request handler for CRM Sales Opportunities
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_opportunity'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $opportunity_name = sanitizeInput($_POST['name']);
    $client_id = intval($_POST['client_id'] ?? 0);
    $contact_id = intval($_POST['contact_id'] ?? 0);
    $stage = sanitizeInput($_POST['stage'] ?? 'Qualification');
    $amount = floatval($_POST['amount'] ?? 0);
    $probability = intval($_POST['probability'] ?? 0);
    $close_date = sanitizeInput($_POST['close_date'] ?? '');
    $owner = intval($_POST['owner'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');

    // Only enforce client access when the opportunity is tied to a client
    if ($client_id) {
        enforceClientAccess();
    }

    // Validate stage against the known set; fall back to Qualification
    $stages = getOpportunityStages();
    if (!isset($stages[$stage])) {
        $stage = 'Qualification';
    }
    $status = opportunityStatusForStage($stage);

    // Clamp probability to 0-100
    if ($probability < 0) { $probability = 0; }
    if ($probability > 100) { $probability = 100; }

    $client_id_sql = $client_id ? $client_id : "NULL";
    $contact_id_sql = $contact_id ? $contact_id : "NULL";
    $owner_sql = $owner ? $owner : "NULL";
    $close_date_sql = !empty($close_date) ? "'$close_date'" : "NULL";

    mysqli_query(
        $mysqli,
        "INSERT INTO opportunities SET
            opportunity_name = '$opportunity_name',
            opportunity_client_id = $client_id_sql,
            opportunity_contact_id = $contact_id_sql,
            opportunity_stage = '$stage',
            opportunity_amount = $amount,
            opportunity_probability = $probability,
            opportunity_close_date = $close_date_sql,
            opportunity_status = '$status',
            opportunity_owner = $owner_sql,
            opportunity_notes = '$notes'"
    );

    $opportunity_id = mysqli_insert_id($mysqli);

    logAction("Opportunity", "Create", "$session_name created opportunity $opportunity_name", $client_id, $opportunity_id);

    flash_alert("Opportunity <strong>$opportunity_name</strong> created");

    redirect();
}

if (isset($_POST['edit_opportunity'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $opportunity_id = intval($_POST['opportunity_id']);

    // Confirm access to the opportunity's current client before allowing any
    // edit - checking only the newly-submitted client_id (below) can be
    // bypassed by submitting client_id=0/omitting it.
    $existing_opportunity_client_id = intval(getFieldById('opportunities', $opportunity_id, 'opportunity_client_id'));
    if ($existing_opportunity_client_id) {
        enforceClientAccess($existing_opportunity_client_id);
    }

    $opportunity_name = sanitizeInput($_POST['name']);
    $client_id = intval($_POST['client_id'] ?? 0);
    $contact_id = intval($_POST['contact_id'] ?? 0);
    $stage = sanitizeInput($_POST['stage'] ?? 'Qualification');
    $amount = floatval($_POST['amount'] ?? 0);
    $probability = intval($_POST['probability'] ?? 0);
    $close_date = sanitizeInput($_POST['close_date'] ?? '');
    $owner = intval($_POST['owner'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');

    if ($client_id) {
        enforceClientAccess();
    }

    $stages = getOpportunityStages();
    if (!isset($stages[$stage])) {
        $stage = 'Qualification';
    }
    $status = opportunityStatusForStage($stage);

    if ($probability < 0) { $probability = 0; }
    if ($probability > 100) { $probability = 100; }

    $client_id_sql = $client_id ? $client_id : "NULL";
    $contact_id_sql = $contact_id ? $contact_id : "NULL";
    $owner_sql = $owner ? $owner : "NULL";
    $close_date_sql = !empty($close_date) ? "'$close_date'" : "NULL";

    mysqli_query(
        $mysqli,
        "UPDATE opportunities SET
            opportunity_name = '$opportunity_name',
            opportunity_client_id = $client_id_sql,
            opportunity_contact_id = $contact_id_sql,
            opportunity_stage = '$stage',
            opportunity_amount = $amount,
            opportunity_probability = $probability,
            opportunity_close_date = $close_date_sql,
            opportunity_status = '$status',
            opportunity_owner = $owner_sql,
            opportunity_notes = '$notes',
            opportunity_updated_at = NOW()
        WHERE opportunity_id = $opportunity_id"
    );

    logAction("Opportunity", "Edit", "$session_name edited opportunity $opportunity_name", $client_id, $opportunity_id);

    flash_alert("Opportunity <strong>$opportunity_name</strong> updated");

    redirect();
}

if (isset($_GET['delete_opportunity'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_sales', 3);

    $opportunity_id = intval($_GET['delete_opportunity']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT opportunity_name, opportunity_client_id FROM opportunities WHERE opportunity_id = $opportunity_id"));
    $opportunity_name = sanitizeInput($row['opportunity_name'] ?? '');
    $client_id = intval($row['opportunity_client_id'] ?? 0);

    if ($client_id) {
        enforceClientAccess($client_id);
    }

    mysqli_query($mysqli, "DELETE FROM opportunities WHERE opportunity_id = $opportunity_id");

    logAction("Opportunity", "Delete", "$session_name deleted opportunity $opportunity_name", $client_id, $opportunity_id);

    flash_alert("Opportunity <strong>$opportunity_name</strong> deleted");

    redirect();
}
