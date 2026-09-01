<?php

/*
 * crm_ajax.php
 * Async endpoints for CRM Engagement (activity timeline + segment preview).
 * Always returns JSON. Owned by the CRM Engagement feature (do not merge into ajax.php).
 *
 * Actions:
 *   ?log_activity       (POST) - log an activity against a contact/client
 *   ?complete_activity  (POST) - mark a follow-up activity complete
 *   ?segment_preview    (POST) - live count + sample for segment criteria
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
require_once __DIR__ . '/modals/crm/crm_helpers.php';

header('Content-Type: application/json');

// Convert an <input type="datetime-local"> value to a SQL datetime or NULL-literal.
function crm_datetime_or_null($val) {
    $val = trim((string)$val);
    if ($val === '') {
        return 'NULL';
    }
    $ts = strtotime($val);
    if ($ts === false) {
        return 'NULL';
    }
    return "'" . date('Y-m-d H:i:s', $ts) . "'";
}

/*
 * Log an activity against a contact (or its client).
 */
if (isset($_GET['log_activity'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');
    enforceUserPermission('module_client', 2);

    $related_type = ($_POST['related_type'] ?? '') === 'client' ? 'client' : 'contact';
    $related_id   = intval($_POST['related_id'] ?? 0);

    $types = crmActivityTypes();
    $activity_type = strtolower(sanitizeInput($_POST['activity_type'] ?? ''));
    if (!isset($types[$activity_type])) {
        echo json_encode(['success' => false, 'message' => 'Invalid activity type']);
        exit();
    }

    // Resolve the owning client for the access check.
    if ($related_type === 'contact') {
        $lookup = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_client_id FROM contacts WHERE contact_id = $related_id LIMIT 1"));
        if (!$lookup) {
            echo json_encode(['success' => false, 'message' => 'Contact not found']);
            exit();
        }
        $client_id = intval($lookup['contact_client_id']);
    } else {
        $client_id = $related_id;
    }

    enforceClientAccess($client_id);

    $subject      = sanitizeInput($_POST['subject'] ?? '');
    $body         = sanitizeInput($_POST['body'] ?? '');
    $due_sql      = crm_datetime_or_null($_POST['due_at'] ?? '');
    $reminder_sql = crm_datetime_or_null($_POST['reminder_at'] ?? '');
    $owner        = intval($session_user_id);

    mysqli_query($mysqli, "INSERT INTO crm_activities SET
        activity_type = '$activity_type',
        activity_subject = '$subject',
        activity_body = '$body',
        activity_related_type = '$related_type',
        activity_related_id = $related_id,
        activity_due_at = $due_sql,
        activity_reminder_at = $reminder_sql,
        activity_owner = $owner");

    $activity_id = mysqli_insert_id($mysqli);

    logAction("CRM Activity", "Create", "$session_name logged a $activity_type activity", $client_id, $activity_id);

    echo json_encode(['success' => true, 'activity_id' => $activity_id]);
    exit();
}

/*
 * Mark a follow-up activity complete.
 */
if (isset($_GET['complete_activity'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');
    enforceUserPermission('module_client', 2);

    $activity_id = intval($_POST['activity_id'] ?? 0);

    $activity = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT activity_related_type, activity_related_id FROM crm_activities WHERE activity_id = $activity_id LIMIT 1"));
    if (!$activity) {
        echo json_encode(['success' => false, 'message' => 'Activity not found']);
        exit();
    }

    // Resolve owning client for the access check.
    if ($activity['activity_related_type'] === 'contact') {
        $lookup = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_client_id FROM contacts WHERE contact_id = " . intval($activity['activity_related_id']) . " LIMIT 1"));
        $client_id = intval($lookup['contact_client_id'] ?? 0);
    } else {
        $client_id = intval($activity['activity_related_id']);
    }

    enforceClientAccess($client_id);

    mysqli_query($mysqli, "UPDATE crm_activities SET activity_completed = 1 WHERE activity_id = $activity_id");

    logAction("CRM Activity", "Complete", "$session_name completed a follow-up", $client_id, $activity_id);

    echo json_encode(['success' => true]);
    exit();
}

/*
 * Live segment preview: count + a sample of matching contacts.
 * Accepts either a saved segment_id or ad-hoc criteria fields.
 */
if (isset($_GET['segment_preview'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');
    enforceUserPermission('module_sales');

    if (!empty($_POST['segment_id'])) {
        $segment_id = intval($_POST['segment_id']);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT segment_criteria_json FROM crm_segments WHERE segment_id = $segment_id LIMIT 1"));
        $criteria = $row ? crmCriteriaFromJson($row['segment_criteria_json']) : [];
    } else {
        $criteria = crmSanitizeCriteria($_POST);
    }

    $require_email = !empty($_POST['require_email']);

    $count   = crmContactCount($mysqli, $criteria, $client_access_string ?? '', $session_is_admin ?? false, $require_email);
    $samples = crmResolveContacts($mysqli, $criteria, $client_access_string ?? '', $session_is_admin ?? false, $require_email, 8);

    $preview = [];
    foreach ($samples as $s) {
        $preview[] = [
            'name'  => $s['contact_name'],
            'email' => $s['contact_email'],
        ];
    }

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'preview' => $preview,
    ]);
    exit();
}

// No recognised action
echo json_encode(['success' => false, 'message' => 'No action']);
