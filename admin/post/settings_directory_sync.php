<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Integrations\Microsoft\IntuneAssetMapper;
use ITFlow\Integrations\Odoo\OdooClient;
use ITFlow\Integrations\Odoo\OdooDirectoryMapper;

// Save module on/off toggle (nav visibility only - independent of
// microsoft_integrations' own enabled/intune_sync_enabled flags below)
if (isset($_POST['save_intune_module_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);
    $enabled = isset($_POST['config_module_enable_intune']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_module_enable_intune=$enabled WHERE company_id=1");
    logAction('Settings', 'Edit', "$session_name " . ($enabled ? 'enabled' : 'disabled') . " the Intune Devices module");
    flash_alert($enabled ? 'Intune Devices module enabled' : 'Intune Devices module disabled');
    redirect();
}

if (isset($_POST['save_microsoft_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $tenant_id = sanitizeInput($_POST['tenant_id'] ?? '');
    $client_id = sanitizeInput($_POST['client_id'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $intune_sync_enabled = isset($_POST['intune_sync_enabled']) ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT microsoft_integration_id FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));

    $secret_sql = '';
    if (!empty($_POST['client_secret'])) {
        $secret_enc = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['client_secret'])));
        $secret_sql = ", client_secret_enc = '$secret_enc'";
    }

    if ($existing) {
        $id = intval($existing['microsoft_integration_id']);
        mysqli_query($mysqli, "UPDATE microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled $secret_sql WHERE microsoft_integration_id = $id");
    } else {
        mysqli_query($mysqli, "INSERT INTO microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled $secret_sql");
    }

    logAction("Settings", "Edit", "$session_name updated the Microsoft/Entra integration settings");
    flash_alert("Microsoft integration settings saved");
    redirect();
}

if (isset($_POST['test_microsoft_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['microsoft_integration_id']) : null;

    if (!$row || empty($row['tenant_id']) || empty($row['client_id']) || empty($row['client_secret_enc'])) {
        flash_alert('Save a tenant ID, client ID, and client secret before testing.', 'error');
        redirect();
    }

    $client = new GraphClient($row['tenant_id'], $row['client_id'], decryptSetting($row['client_secret_enc']));
    $result = $client->testConnection();

    $success = $result->success ? 1 : 0;
    $error_sql = $result->error ? "'" . mysqli_real_escape_string($mysqli, substr($result->error, 0, 500)) . "'" : 'NULL';
    mysqli_query($mysqli, "UPDATE microsoft_integrations SET last_test_at = NOW(), last_test_success = $success, last_test_error = $error_sql WHERE microsoft_integration_id = $id");

    \ITFlow\Audit\AuditService::record('integration.microsoft.test', $session_user_id, 'microsoft_integration', $id, $result->success ? 'success' : 'failed', $result->error);

    flash_alert($result->success ? 'Connection successful' : 'Connection failed: ' . $result->error, $result->success ? 'success' : 'error');
    redirect();
}

if (isset($_POST['sync_intune_devices'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['microsoft_integration_id']) : null;

    if (!$row) {
        flash_alert('No Microsoft integration is configured yet.', 'error');
        redirect();
    }
    if (empty($row['enabled'])) {
        flash_alert('Enable the Microsoft integration before syncing Intune devices.', 'error');
        redirect();
    }
    if (empty($row['client_secret_enc'])) {
        flash_alert('Save a tenant ID, client ID, and client secret before syncing Intune devices.', 'error');
        redirect();
    }
    if (empty($row['intune_sync_enabled'])) {
        flash_alert('Enable "Sync devices from Intune" before syncing.', 'error');
        redirect();
    }

    // Guard against overlapping runs (e.g. this button clicked while the cron
    // job is mid-sync) - same 60-second running-lock pattern rmm_sync.php uses.
    $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT id FROM intune_sync_log WHERE microsoft_integration_id=$id
         AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND status='running' LIMIT 1"
    ));
    if ($recent) {
        flash_alert('A sync is already running. Please wait 60 seconds.', 'error');
        redirect();
    }

    $client = new GraphClient($row['tenant_id'], $row['client_id'], decryptSetting($row['client_secret_enc']));
    $mapper = new IntuneAssetMapper($mysqli, $id, $session_user_id);
    $log_id = $mapper->startSyncLog();

    try {
        $devices = $client->listAllManagedDevices();
        $stats = $mapper->syncDevices($devices);
        $mapper->finishSyncLog($log_id, $stats);

        logAction("Settings", "Edit", "$session_name synced Intune devices: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched, {$stats['skipped']} skipped");
        flash_alert("Intune sync complete: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched, {$stats['skipped']} skipped");
    } catch (\RuntimeException $e) {
        mysqli_query($mysqli, "UPDATE intune_sync_log SET finished_at=NOW(), status='failed', errors='" .
            mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        flash_alert($e->getMessage(), 'error');
    }

    redirect();
}

if (isset($_POST['save_odoo_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $base_url = sanitizeInput($_POST['base_url'] ?? '');
    $database_name = sanitizeInput($_POST['database_name'] ?? '');
    $username = sanitizeInput($_POST['username'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT odoo_integration_id FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1"));

    $key_sql = '';
    if (!empty($_POST['api_key'])) {
        $key_enc = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['api_key'])));
        $key_sql = ", api_key_enc = '$key_enc'";
    }

    if ($existing) {
        $id = intval($existing['odoo_integration_id']);
        mysqli_query($mysqli, "UPDATE odoo_integrations SET base_url = '$base_url', database_name = '$database_name', username = '$username', enabled = $enabled $key_sql WHERE odoo_integration_id = $id");
    } else {
        mysqli_query($mysqli, "INSERT INTO odoo_integrations SET base_url = '$base_url', database_name = '$database_name', username = '$username', enabled = $enabled $key_sql");
    }

    logAction("Settings", "Edit", "$session_name updated the Odoo integration settings");
    flash_alert("Odoo integration settings saved");
    redirect();
}

if (isset($_POST['test_odoo_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['odoo_integration_id']) : null;

    if (!$row || empty($row['base_url']) || empty($row['database_name']) || empty($row['username']) || empty($row['api_key_enc'])) {
        flash_alert('Save a base URL, database name, username, and API key before testing.', 'error');
        redirect();
    }

    $client = new OdooClient($row['base_url'], $row['database_name'], $row['username'], decryptSetting($row['api_key_enc']));
    $result = $client->testConnection();

    $success = $result->success ? 1 : 0;
    $error_sql = $result->error ? "'" . mysqli_real_escape_string($mysqli, substr($result->error, 0, 500)) . "'" : 'NULL';
    mysqli_query($mysqli, "UPDATE odoo_integrations SET last_test_at = NOW(), last_test_success = $success, last_test_error = $error_sql WHERE odoo_integration_id = $id");

    \ITFlow\Audit\AuditService::record('integration.odoo.test', $session_user_id, 'odoo_integration', $id, $result->success ? 'success' : 'failed', $result->error);

    flash_alert($result->success ? 'Connection successful' : 'Connection failed: ' . $result->error, $result->success ? 'success' : 'error');
    redirect();
}

if (isset($_POST['sync_odoo_directory'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['odoo_integration_id']) : null;

    if (!$row) {
        flash_alert('No Odoo integration is configured yet.', 'error');
        redirect();
    }
    if (empty($row['enabled'])) {
        flash_alert('Enable the Odoo integration before syncing the directory.', 'error');
        redirect();
    }
    if (empty($row['base_url']) || empty($row['database_name']) || empty($row['username']) || empty($row['api_key_enc'])) {
        flash_alert('Save a base URL, database name, username, and API key before syncing.', 'error');
        redirect();
    }

    // Guard against overlapping runs (e.g. this button clicked while the cron
    // job is mid-sync) - same 60-second running-lock pattern sync_intune_devices uses.
    $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT id FROM odoo_sync_log WHERE odoo_integration_id=$id
         AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND status='running' LIMIT 1"
    ));
    if ($recent) {
        flash_alert('A sync is already running. Please wait 60 seconds.', 'error');
        redirect();
    }

    $client = new OdooClient($row['base_url'], $row['database_name'], $row['username'], decryptSetting($row['api_key_enc']));
    $mapper = new OdooDirectoryMapper($mysqli, $id, $session_user_id);
    $log_id = $mapper->startSyncLog();

    try {
        $departments = $client->listDepartments();
        $deptStats = $mapper->syncDepartments($departments);

        $employees = $client->listEmployees();
        $empStats = $mapper->syncEmployees($employees, $deptStats['idMap']);

        $mapper->finishSyncLog($log_id, $deptStats, $empStats);

        logAction("Settings", "Edit", "$session_name synced Odoo directory: departments {$deptStats['created']} created/{$deptStats['updated']} updated/{$deptStats['matched']} matched, employees {$empStats['created']} created/{$empStats['updated']} updated/{$empStats['matched']} matched");
        flash_alert("Odoo sync complete: departments {$deptStats['created']} created, {$deptStats['updated']} updated; employees {$empStats['created']} created, {$empStats['updated']} updated");
    } catch (\RuntimeException $e) {
        mysqli_query($mysqli, "UPDATE odoo_sync_log SET finished_at=NOW(), status='failed', errors='" .
            mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        flash_alert($e->getMessage(), 'error');
    }

    redirect();
}
