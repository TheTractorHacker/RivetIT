<?php
if (defined('FROM_POST_HANDLER')) return;
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

enforceUserPermission('module_rmm_alerts');

$action   = sanitizeInput($_POST['action'] ?? '');
$alert_id = intval($_POST['alert_id'] ?? 0);

if (!$alert_id) {
    echo json_encode(['success' => false, 'error' => 'Missing alert_id']);
    exit;
}

$alert = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM comet_backup_alerts WHERE alert_id=$alert_id"));
if (!$alert) {
    echo json_encode(['success' => false, 'error' => 'Alert not found']);
    exit;
}

$client_id = intval($alert['alert_client_id']);
if ($client_id) { enforceClientAccess($client_id); }

if ($action === 'acknowledge') {
    enforceUserPermission('module_rmm_alerts_ack');
    mysqli_query($mysqli, "UPDATE comet_backup_alerts SET alert_status='acknowledged', alert_acknowledged_by=$session_user_id, alert_acknowledged_at=NOW() WHERE alert_id=$alert_id");
    logAction('Comet', 'Alert Acknowledged', "$session_name acknowledged backup alert ID $alert_id", $client_id);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'resolve') {
    enforceUserPermission('module_rmm_alerts_ack');
    mysqli_query($mysqli, "UPDATE comet_backup_alerts SET alert_status='resolved', alert_resolved_at=NOW() WHERE alert_id=$alert_id");
    logAction('Comet', 'Alert Resolved', "$session_name resolved backup alert ID $alert_id", $client_id);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
