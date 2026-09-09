<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Directory\FieldMapping;
use ITFlow\Integrations\Google\GoogleDirectoryClient;
use ITFlow\Integrations\Google\GoogleDirectoryMapper;
use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Integrations\Microsoft\IntuneAssetMapper;
use ITFlow\Integrations\Microsoft\MicrosoftDirectoryMapper;
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
    // New field (DB update 2.6.79) - additive, so any pre-existing caller of this
    // same POST key that doesn't send it just gets 0, same as any other boolean
    // field on this form it doesn't send; both the Directory Sync tab's full
    // credential form and the Device Sync tab's slim Intune-only form (this
    // handler's two real callers now) always pass it explicitly (live value or
    // a hidden passthrough), so neither ever clobbers the other's setting.
    $directory_sync_enabled = isset($_POST['directory_sync_enabled']) ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT microsoft_integration_id FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));

    $secret_sql = '';
    if (!empty($_POST['client_secret'])) {
        $secret_enc = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['client_secret'])));
        $secret_sql = ", client_secret_enc = '$secret_enc'";
    }

    if ($existing) {
        $id = intval($existing['microsoft_integration_id']);
        mysqli_query($mysqli, "UPDATE microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled, directory_sync_enabled = $directory_sync_enabled $secret_sql WHERE microsoft_integration_id = $id");
    } else {
        mysqli_query($mysqli, "INSERT INTO microsoft_integrations SET tenant_id = '$tenant_id', client_id = '$client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled, directory_sync_enabled = $directory_sync_enabled $secret_sql");
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

// New (this batch) - Microsoft USER/contact directory sync, the counterpart to
// sync_intune_devices below but for people rather than devices. Reuses the same
// GraphClient/tenant/client/secret sync_intune_devices already uses - Directory
// Sync and Device Sync are two independent on/off switches (directory_sync_enabled
// vs intune_sync_enabled) against the one connection.
if (isset($_POST['sync_microsoft_directory'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['microsoft_integration_id']) : null;

    if (!$row) {
        flash_alert('No Microsoft integration is configured yet.', 'error');
        redirect();
    }
    if (empty($row['enabled'])) {
        flash_alert('Enable the Microsoft integration before syncing the directory.', 'error');
        redirect();
    }
    if (empty($row['client_secret_enc'])) {
        flash_alert('Save a tenant ID, client ID, and client secret before syncing the directory.', 'error');
        redirect();
    }
    if (empty($row['directory_sync_enabled'])) {
        flash_alert('Enable "Sync users from Entra ID" before syncing.', 'error');
        redirect();
    }

    // Guard against overlapping runs - same 60-second running-lock pattern
    // sync_intune_devices/sync_odoo_directory already use.
    $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT id FROM microsoft_directory_sync_log WHERE microsoft_integration_id=$id
         AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND status='running' LIMIT 1"
    ));
    if ($recent) {
        flash_alert('A sync is already running. Please wait 60 seconds.', 'error');
        redirect();
    }

    $client = new GraphClient($row['tenant_id'], $row['client_id'], decryptSetting($row['client_secret_enc']));
    $mapper = new MicrosoftDirectoryMapper($mysqli, $id, $session_user_id);
    $log_id = $mapper->startSyncLog();

    try {
        // One Graph call serves both passes - MicrosoftDirectoryMapper::syncDepartments()
        // reads each user's own `department` string field (see its class docblock
        // "DEPARTMENTS" - v1 has no separate Entra group-membership call).
        $users = $client->getUsers();
        $deptStats = $mapper->syncDepartments($users);
        $empStats = $mapper->syncEmployees($users, $deptStats['idMap']);

        $mapper->finishSyncLog($log_id, $deptStats, $empStats);

        logAction("Settings", "Edit", "$session_name synced Microsoft directory: departments {$deptStats['created']} created/{$deptStats['updated']} updated/{$deptStats['matched']} matched, employees {$empStats['created']} created/{$empStats['updated']} updated/{$empStats['matched']} matched");
        flash_alert("Microsoft directory sync complete: departments {$deptStats['created']} created, {$deptStats['updated']} updated; employees {$empStats['created']} created, {$empStats['updated']} updated");
    } catch (\RuntimeException $e) {
        mysqli_query($mysqli, "UPDATE microsoft_directory_sync_log SET finished_at=NOW(), status='failed', errors='" .
            mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        flash_alert($e->getMessage(), 'error');
    }

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

// ═══════════════════════════════════════════════════════════════════════════
// Google Workspace - net new (this batch). Mirrors the Odoo/Microsoft
// save/test/sync trio above exactly, except there's no client_secret - Google's
// service account auth signs its own JWT from the pasted key file
// (src/Integrations/Google/GoogleDirectoryClient.php), so "the secret" here is
// the whole JSON key file's contents, stored the same encrypted, blank-means-
// keep-current way client_secret_enc/api_key_enc already work above.
// ═══════════════════════════════════════════════════════════════════════════

if (isset($_POST['save_google_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $delegated_admin_email = sanitizeInput($_POST['delegated_admin_email'] ?? '');
    $workspace_domain = sanitizeInput($_POST['workspace_domain'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT google_integration_id FROM google_integrations ORDER BY google_integration_id DESC LIMIT 1"));

    $json_sql = '';
    if (!empty($_POST['service_account_json'])) {
        $raw_json = trim($_POST['service_account_json']);
        // Fail fast with a clear message rather than saving something
        // GoogleDirectoryClient will only reject later, at test/sync time -
        // same two fields it itself requires (parseServiceAccount()).
        $decoded_json = json_decode($raw_json, true);
        if (!is_array($decoded_json) || empty($decoded_json['client_email']) || empty($decoded_json['private_key'])) {
            flash_alert('That does not look like a valid Google service account JSON key file - it must include "client_email" and "private_key". Paste the full, unmodified contents of the downloaded key file.', 'error');
            redirect();
        }
        $json_enc = mysqli_real_escape_string($mysqli, encryptSetting($raw_json));
        $json_sql = ", service_account_json_enc = '$json_enc'";
    }

    if ($existing) {
        $id = intval($existing['google_integration_id']);
        mysqli_query($mysqli, "UPDATE google_integrations SET delegated_admin_email = '$delegated_admin_email', workspace_domain = '$workspace_domain', enabled = $enabled $json_sql WHERE google_integration_id = $id");
    } else {
        mysqli_query($mysqli, "INSERT INTO google_integrations SET delegated_admin_email = '$delegated_admin_email', workspace_domain = '$workspace_domain', enabled = $enabled $json_sql");
    }

    logAction("Settings", "Edit", "$session_name updated the Google Workspace integration settings");
    flash_alert("Google Workspace integration settings saved");
    redirect();
}

if (isset($_POST['test_google_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM google_integrations ORDER BY google_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['google_integration_id']) : null;

    if (!$row || empty($row['service_account_json_enc']) || empty($row['delegated_admin_email'])) {
        flash_alert('Save a service account JSON key and delegated admin email before testing.', 'error');
        redirect();
    }

    $client = new GoogleDirectoryClient(decryptSetting($row['service_account_json_enc']), $row['delegated_admin_email'], $row['workspace_domain'] ?: null);
    $result = $client->testConnection();

    $success = $result->success ? 1 : 0;
    $error_sql = $result->error ? "'" . mysqli_real_escape_string($mysqli, substr($result->error, 0, 500)) . "'" : 'NULL';
    mysqli_query($mysqli, "UPDATE google_integrations SET last_test_at = NOW(), last_test_success = $success, last_test_error = $error_sql WHERE google_integration_id = $id");

    \ITFlow\Audit\AuditService::record('integration.google.test', $session_user_id, 'google_integration', $id, $result->success ? 'success' : 'failed', $result->error);

    flash_alert($result->success ? 'Connection successful' : 'Connection failed: ' . $result->error, $result->success ? 'success' : 'error');
    redirect();
}

if (isset($_POST['sync_google_directory'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM google_integrations ORDER BY google_integration_id DESC LIMIT 1"));
    $id = $row ? intval($row['google_integration_id']) : null;

    if (!$row) {
        flash_alert('No Google Workspace integration is configured yet.', 'error');
        redirect();
    }
    if (empty($row['enabled'])) {
        flash_alert('Enable the Google Workspace integration before syncing the directory.', 'error');
        redirect();
    }
    if (empty($row['service_account_json_enc']) || empty($row['delegated_admin_email'])) {
        flash_alert('Save a service account JSON key and delegated admin email before syncing.', 'error');
        redirect();
    }

    // Guard against overlapping runs - same 60-second running-lock pattern
    // sync_odoo_directory/sync_intune_devices already use.
    $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT id FROM google_sync_log WHERE google_integration_id=$id
         AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND status='running' LIMIT 1"
    ));
    if ($recent) {
        flash_alert('A sync is already running. Please wait 60 seconds.', 'error');
        redirect();
    }

    $client = new GoogleDirectoryClient(decryptSetting($row['service_account_json_enc']), $row['delegated_admin_email'], $row['workspace_domain'] ?: null);
    $mapper = new GoogleDirectoryMapper($mysqli, $id, $session_user_id);
    $log_id = $mapper->startSyncLog();

    try {
        $orgUnits = $client->listOrgUnits();
        $deptStats = $mapper->syncDepartments($orgUnits);

        $users = $client->listUsers();
        $empStats = $mapper->syncEmployees($users, $deptStats['idMap']);

        $mapper->finishSyncLog($log_id, $deptStats, $empStats);

        logAction("Settings", "Edit", "$session_name synced Google Workspace directory: departments {$deptStats['created']} created/{$deptStats['updated']} updated/{$deptStats['matched']} matched, employees {$empStats['created']} created/{$empStats['updated']} updated/{$empStats['matched']} matched");
        flash_alert("Google Workspace sync complete: departments {$deptStats['created']} created, {$deptStats['updated']} updated; employees {$empStats['created']} created, {$empStats['updated']} updated");
    } catch (\RuntimeException $e) {
        mysqli_query($mysqli, "UPDATE google_sync_log SET finished_at=NOW(), status='failed', errors='" .
            mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        flash_alert($e->getMessage(), 'error');
    }

    redirect();
}

// ═══════════════════════════════════════════════════════════════════════════
// Field Mapping (directory_field_mappings) - net new (this batch). One form on
// the Directory Sync tab posts every provider's rows together (see
// admin/settings_integrations.php's "mapping[<key>][provider|source_field|
// target_field|enabled]" fields, <key> a plain per-row index, never the raw
// source_field string itself - some source fields, e.g. Google's
// "organizations[0].title", contain '[' ']' '.' characters that would corrupt
// PHP's own array-in-form-field-name parsing if used as the bracket key
// directly, so source_field always travels as a hidden field's VALUE instead).
// ═══════════════════════════════════════════════════════════════════════════

if (isset($_POST['save_field_mapping'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $mapping_rows = $_POST['mapping'] ?? [];
    $saved_count = 0;
    $skipped_count = 0;

    if (is_array($mapping_rows)) {
        foreach ($mapping_rows as $mapping_row) {
            if (!is_array($mapping_row)) {
                continue;
            }

            $provider = sanitizeInput($mapping_row['provider'] ?? '');
            $source_field = trim((string) ($mapping_row['source_field'] ?? ''));
            $target_field = trim((string) ($mapping_row['target_field'] ?? ''));
            $enabled = isset($mapping_row['enabled']) ? 1 : 0;

            // Never trust provider or target_field as bare values here either -
            // FieldMapping::forProvider() re-checks target_field again at every
            // READ (see its own docblock on why), but this is the point they're
            // actually WRITTEN, so reject anything invalid before it ever
            // reaches SQL rather than relying solely on that later filter.
            if ($provider === '' || !in_array($provider, FieldMapping::VALID_PROVIDERS, true) || $source_field === '') {
                $skipped_count++;
                continue;
            }

            $provider_esc = mysqli_real_escape_string($mysqli, $provider);
            $source_esc = mysqli_real_escape_string($mysqli, $source_field);

            if ($target_field === '') {
                // "— Not mapped —" selected: remove any existing row outright
                // rather than leaving a disabled/blank-target row behind - this
                // is the "turn a field off" action the settings UI offers.
                mysqli_query($mysqli, "DELETE FROM directory_field_mappings WHERE provider='$provider_esc' AND source_field='$source_esc'");
                continue;
            }

            if (!FieldMapping::isValidTargetField($target_field)) {
                $skipped_count++;
                continue;
            }

            $target_esc = mysqli_real_escape_string($mysqli, $target_field);

            mysqli_query($mysqli, "INSERT INTO directory_field_mappings (provider, source_field, target_field, enabled)
                VALUES ('$provider_esc', '$source_esc', '$target_esc', $enabled)
                ON DUPLICATE KEY UPDATE target_field = '$target_esc', enabled = $enabled");
            $saved_count++;
        }
    }

    logAction("Settings", "Edit", "$session_name updated directory sync field mappings ($saved_count saved)");
    flash_alert("Field mappings saved" . ($skipped_count > 0 ? " ($skipped_count row(s) skipped - invalid provider or target field)" : ""), $skipped_count > 0 ? 'error' : 'success');
    redirect();
}
