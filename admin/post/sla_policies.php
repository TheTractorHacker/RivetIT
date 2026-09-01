<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * SLA Policies - admin POST/GET handler.
 * Loaded by admin/post.php when the referring page is sla_policies.php.
 * Targets are stored in MINUTES of business time; blank => NULL (legacy fallback).
 */

// SQL literal for a nullable-int target: '' => NULL, else non-negative int.
function slaNullableInt($value): string {
    if ($value === null || $value === '' || !is_numeric($value)) return 'NULL';
    $i = intval($value);
    if ($i < 0) $i = 0;
    return (string) $i;
}

// SQL literal for the calendar id: 0 or blank => NULL (24/7).
function slaCalendarIdSql($value): string {
    $i = intval($value);
    return $i > 0 ? (string) $i : 'NULL';
}

// Build a sanitized, comma-joined list of pause status ids, or NULL.
function slaPauseIdsSql($mysqli, $ids): string {
    if (!is_array($ids)) return 'NULL';
    $clean = [];
    foreach ($ids as $id) {
        $i = intval($id);
        if ($i > 0) $clean[$i] = $i; // dedupe
    }
    if (empty($clean)) return 'NULL';
    $joined = implode(',', array_values($clean));
    return "'" . mysqli_real_escape_string($mysqli, $joined) . "'";
}

if (isset($_POST['add_sla_policy'])) {

    validateCSRFToken($_POST['csrf_token']);

    $policy_name = sanitizeInput($_POST['policy_name']);
    $calendar_sql = slaCalendarIdSql($_POST['policy_calendar_id'] ?? 0);
    $pause_sql = slaPauseIdsSql($mysqli, $_POST['policy_pause_status_ids'] ?? []);
    $low_resp  = slaNullableInt($_POST['policy_low_response'] ?? '');
    $low_reso  = slaNullableInt($_POST['policy_low_resolution'] ?? '');
    $med_resp  = slaNullableInt($_POST['policy_medium_response'] ?? '');
    $med_reso  = slaNullableInt($_POST['policy_medium_resolution'] ?? '');
    $high_resp = slaNullableInt($_POST['policy_high_response'] ?? '');
    $high_reso = slaNullableInt($_POST['policy_high_resolution'] ?? '');
    $is_default = isset($_POST['policy_is_default']) ? 1 : 0;

    if ($is_default) {
        mysqli_query($mysqli, "UPDATE sla_policies SET policy_is_default = 0");
    }

    mysqli_query($mysqli, "INSERT INTO sla_policies SET
        policy_name = '$policy_name',
        policy_calendar_id = $calendar_sql,
        policy_pause_status_ids = $pause_sql,
        policy_low_response = $low_resp,
        policy_low_resolution = $low_reso,
        policy_medium_response = $med_resp,
        policy_medium_resolution = $med_reso,
        policy_high_response = $high_resp,
        policy_high_resolution = $high_reso,
        policy_is_default = $is_default");
    $policy_id = mysqli_insert_id($mysqli);

    logAction("SLA Policy", "Create", "$session_name created SLA policy $policy_name", 0, $policy_id);
    flash_alert("SLA Policy <strong>$policy_name</strong> created");
    redirect();
}

if (isset($_POST['edit_sla_policy'])) {

    validateCSRFToken($_POST['csrf_token']);

    $policy_id = intval($_POST['policy_id']);
    $policy_name = sanitizeInput($_POST['policy_name']);
    $calendar_sql = slaCalendarIdSql($_POST['policy_calendar_id'] ?? 0);
    $pause_sql = slaPauseIdsSql($mysqli, $_POST['policy_pause_status_ids'] ?? []);
    $low_resp  = slaNullableInt($_POST['policy_low_response'] ?? '');
    $low_reso  = slaNullableInt($_POST['policy_low_resolution'] ?? '');
    $med_resp  = slaNullableInt($_POST['policy_medium_response'] ?? '');
    $med_reso  = slaNullableInt($_POST['policy_medium_resolution'] ?? '');
    $high_resp = slaNullableInt($_POST['policy_high_response'] ?? '');
    $high_reso = slaNullableInt($_POST['policy_high_resolution'] ?? '');
    $is_default = isset($_POST['policy_is_default']) ? 1 : 0;

    if ($is_default) {
        mysqli_query($mysqli, "UPDATE sla_policies SET policy_is_default = 0");
    }

    mysqli_query($mysqli, "UPDATE sla_policies SET
        policy_name = '$policy_name',
        policy_calendar_id = $calendar_sql,
        policy_pause_status_ids = $pause_sql,
        policy_low_response = $low_resp,
        policy_low_resolution = $low_reso,
        policy_medium_response = $med_resp,
        policy_medium_resolution = $med_reso,
        policy_high_response = $high_resp,
        policy_high_resolution = $high_reso,
        policy_is_default = $is_default
        WHERE policy_id = $policy_id");

    logAction("SLA Policy", "Edit", "$session_name edited SLA policy $policy_name", 0, $policy_id);
    flash_alert("SLA Policy <strong>$policy_name</strong> saved");
    redirect();
}

if (isset($_GET['set_default_sla_policy'])) {

    validateCSRFToken($_GET['csrf_token']);

    $policy_id = intval($_GET['set_default_sla_policy']);
    mysqli_query($mysqli, "UPDATE sla_policies SET policy_is_default = 0");
    mysqli_query($mysqli, "UPDATE sla_policies SET policy_is_default = 1 WHERE policy_id = $policy_id AND policy_archived_at IS NULL");

    $policy_name = sanitizeInput(getFieldById('sla_policies', $policy_id, 'policy_name'));
    logAction("SLA Policy", "Edit", "$session_name set default SLA policy to $policy_name", 0, $policy_id);
    flash_alert("Default SLA Policy set to <strong>$policy_name</strong>");
    redirect();
}

if (isset($_GET['archive_sla_policy'])) {

    validateCSRFToken($_GET['csrf_token']);

    $policy_id = intval($_GET['archive_sla_policy']);
    mysqli_query($mysqli, "UPDATE sla_policies SET policy_archived_at = NOW(), policy_is_default = 0 WHERE policy_id = $policy_id");

    $policy_name = sanitizeInput(getFieldById('sla_policies', $policy_id, 'policy_name'));
    logAction("SLA Policy", "Archive", "$session_name archived SLA policy $policy_name", 0, $policy_id);
    flash_alert("SLA Policy <strong>$policy_name</strong> archived", 'error');
    redirect();
}

if (isset($_GET['unarchive_sla_policy'])) {

    validateCSRFToken($_GET['csrf_token']);

    $policy_id = intval($_GET['unarchive_sla_policy']);
    mysqli_query($mysqli, "UPDATE sla_policies SET policy_archived_at = NULL WHERE policy_id = $policy_id");

    $policy_name = sanitizeInput(getFieldById('sla_policies', $policy_id, 'policy_name'));
    logAction("SLA Policy", "Edit", "$session_name unarchived SLA policy $policy_name", 0, $policy_id);
    flash_alert("SLA Policy <strong>$policy_name</strong> restored");
    redirect();
}
