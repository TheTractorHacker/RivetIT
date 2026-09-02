<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Integrations\Odoo\OdooClient;

if (isset($_POST['save_microsoft_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $tenant_id = sanitizeInput($_POST['tenant_id'] ?? '');
    $client_id = sanitizeInput($_POST['client_id'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT microsoft_integration_id FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));

    $secret_sql = '';
    if (!empty($_POST['client_secret'])) {
        $secret_enc = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['client_secret'])));
        $secret_sql = ", client_secret_enc = '$secret_enc'";
    }

    if ($existing) {
        $id = intval($existing['microsoft_integration_id']);
        mysqli_query($mysqli, "UPDATE microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled $secret_sql WHERE microsoft_integration_id = $id");
    } else {
        mysqli_query($mysqli, "INSERT INTO microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled $secret_sql");
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
