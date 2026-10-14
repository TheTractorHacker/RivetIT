<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * ManageEngine Mobile Device Manager Plus (cloud) - connection settings, Test connection and Sync now.
 * Loaded through admin/post/settings_integrations.php (the form is on Integrations > Device Sync), admins only. Same permission as the Intune/Microsoft
 * settings (module_client level 3). Secrets are encrypted with encryptSetting() and never put in a flash message, log line or audit row.
 */

use ITFlow\Integrations\ManageEngine\DataCenters;
use ITFlow\Integrations\ManageEngine\ManageEngineMdmClient;
use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmException;
use ITFlow\Integrations\ManageEngine\MdmSyncService;

if (isset($_POST['save_mdm_connection'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $existing = MdmClientFactory::row($mysqli);
    $edition = ($_POST['edition'] ?? '') === 'onprem' ? 'onprem' : 'cloud';
    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;
    $interval = max(5, min(1440, intval($_POST['sync_interval_minutes'] ?? 60)));
    $enrich = (isset($_POST['enrich_details']) && $_POST['enrich_details'] === '1') ? 1 : 0;
    $stale_days = max(1, min(365, intval($_POST['stale_days'] ?? 7)));
    $msp = (isset($_POST['msp_mode']) && $_POST['msp_mode'] === '1') ? 1 : 0;
    $auto_create = (isset($_POST['auto_create_assets']) && $_POST['auto_create_assets'] === '1') ? 1 : 0;
    $retire = (isset($_POST['stale_retire_enabled']) && $_POST['stale_retire_enabled'] === '1') ? 1 : 0;
    $retire_days = max(7, min(3650, intval($_POST['stale_retire_days'] ?? 30)));
    $default_client = intval($_POST['default_client_id'] ?? 0);
    if ($default_client > 0 && !mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = $default_client AND client_archived_at IS NULL"))) {
        flash_alert('Choose a department that exists, or none.', 'error');
        redirect();
    }
    $cols = ['edition' => $edition, 'enabled' => $enabled, 'sync_interval_minutes' => $interval, 'enrich_details' => $enrich, 'stale_days' => $stale_days, 'msp_mode' => $msp,
        'default_client_id' => $default_client, 'auto_create_assets' => $auto_create, 'stale_retire_enabled' => $retire, 'stale_retire_days' => $retire_days];
    $changed = [];
    $clear_token = false;

    if ($edition === 'onprem') {
        $vet = MdmClientFactory::vetServerUrl($mysqli, (string) ($_POST['server_url'] ?? ''));
        if (!$vet['ok']) {
            flash_alert(nullable_htmlentities($vet['error']), 'error');
            redirect();
        }
        $api_key = trim((string) ($_POST['api_key'] ?? ''));
        if ($api_key !== '' && (strlen($api_key) > 500 || preg_match('/[\x00-\x1f\x7f]/', $api_key))) {
            flash_alert('That does not look like an API key.', 'error');
            redirect();
        }
        $expires = trim((string) ($_POST['api_key_expires_on'] ?? ''));
        if ($expires !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) === false)) {
            flash_alert('Enter the API key expiry as a date.', 'error');
            redirect();
        }
        $has_key = $api_key !== '' || ($existing && !empty($existing['api_key_enc']));
        if ($enabled && !$has_key) {
            flash_alert('Enter the API key before switching the integration on.', 'error');
            redirect();
        }
        $cols['server_url'] = $vet['url'];
        $cols['tls_verify'] = (isset($_POST['tls_verify']) && $_POST['tls_verify'] === '1') ? 1 : 0;
        $cols['api_key_expires_on'] = $expires === '' ? null : $expires;
        if ($api_key !== '') {
            $cols['api_key_enc'] = encryptSetting($api_key);
            $changed[] = 'API key';
        }
        if (($existing['api_key_expires_on'] ?? null) !== $cols['api_key_expires_on'] || $api_key !== '') {
            $cols['api_key_warned_on'] = null;   // a new key or date starts the 30-day reminder afresh
        }
    } else {
        $data_center = strtolower(trim((string) ($_POST['data_center'] ?? '')));
        if (!DataCenters::isValid($data_center)) {
            flash_alert('Choose one of the listed data centers.', 'error');
            redirect();
        }
        $client_id = trim((string) ($_POST['client_id'] ?? ''));
        if (strlen($client_id) > 200 || !preg_match('/^[A-Za-z0-9._-]*$/', $client_id)) {
            flash_alert('The client id contains characters a Zoho client id never has.', 'error');
            redirect();
        }
        $secret = trim((string) ($_POST['client_secret'] ?? ''));
        $refresh = trim((string) ($_POST['refresh_token'] ?? ''));

        // A one-time code from the Zoho API console can be pasted instead of a refresh token: exchange it here and keep only the token.
        $auth_code = trim((string) ($_POST['auth_code'] ?? ''));
        if ($auth_code !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{10,300}$/', $auth_code)) {
                flash_alert('That does not look like a Zoho code. Paste the code from the Generate Code tab, nothing else.', 'error');
                redirect();
            }
            $code_secret = $secret !== '' ? $secret : ($existing && !empty($existing['client_secret_enc']) ? decryptSetting((string) $existing['client_secret_enc']) : '');
            if ($client_id === '' || $code_secret === '') {
                flash_alert('Enter the client id and client secret as well, then paste the code.', 'error');
                redirect();
            }
            try {
                $options = [];
                if (defined('RIVETIT_MDM_ACCOUNTS_URL')) {
                    $options['accounts_base'] = RIVETIT_MDM_ACCOUNTS_URL;
                }
                $refresh = (new ManageEngineMdmClient($data_center, $client_id, $code_secret, '', null, $options))->exchangeAuthorizationCode($auth_code);
            } catch (MdmException $e) {
                \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.code_exchange', $session_user_id, 'manageengine_mdm_integration', intval($existing['id'] ?? 0), 'failed', null);
                flash_alert(nullable_htmlentities($e->getMessage()), 'error');
                redirect();
            }
            \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.code_exchange', $session_user_id, 'manageengine_mdm_integration', intval($existing['id'] ?? 0), 'success', null);
        }

        $has_secret = $secret !== '' || ($existing && !empty($existing['client_secret_enc']));
        $has_refresh = $refresh !== '' || ($existing && !empty($existing['refresh_token_enc']));
        if ($enabled && ($client_id === '' || !$has_secret || !$has_refresh)) {
            flash_alert('Enter a client id, client secret and refresh token before switching the integration on.', 'error');
            redirect();
        }
        $cols += ['data_center' => $data_center, 'client_id' => $client_id];
        if ($secret !== '') {
            $cols['client_secret_enc'] = encryptSetting($secret);
            $changed[] = 'client secret';
        }
        if ($refresh !== '') {
            $cols['refresh_token_enc'] = encryptSetting($refresh);
            $changed[] = 'refresh token';
        }
        // New credentials or another data center / client must never reuse the old cached access token.
        $clear_token = $existing && ($secret !== '' || $refresh !== '' || $existing['data_center'] !== $data_center || $existing['client_id'] !== $client_id || MdmClientFactory::edition($existing) !== 'cloud');
        $data_center_log = $data_center;
    }

    // Prepared statement throughout: the column list is fixed code, every value is a bound parameter.
    $bind = static function (array $values): string {
        $types = '';
        foreach ($values as $v) {
            $types .= is_int($v) ? 'i' : 's';
        }

        return $types;
    };
    if ($existing) {
        $id = intval($existing['id']);
        $sql = 'UPDATE manageengine_mdm_integrations SET ' . implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($cols)))
            . ($clear_token ? ', token_cache_enc = NULL, token_expires_at = NULL' : '') . ' WHERE id = ?';
        $stmt = mysqli_prepare($mysqli, $sql);
        $values = array_values($cols);
        $values[] = $id;
        mysqli_stmt_bind_param($stmt, $bind($values), ...$values);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $sql = 'INSERT INTO manageengine_mdm_integrations (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $stmt = mysqli_prepare($mysqli, $sql);
        $values = array_values($cols);
        mysqli_stmt_bind_param($stmt, $bind($values), ...$values);
        mysqli_stmt_execute($stmt);
        $id = intval(mysqli_insert_id($mysqli));
        mysqli_stmt_close($stmt);
    }

    // Disabling the integration switches the master "Allow MDM to write asset fields" switch off again (it must be turned on deliberately).
    if (!$enabled) {
        \ITFlow\Integrations\ManageEngine\MdmMappingSettings::switchWritesOff($mysqli, $id);
    }

    // Only fact names go in the audit trail: which fields changed, never their values.
    logAction("Settings", "Edit", "$session_name saved the ManageEngine MDM integration (" . ($enabled ? 'enabled' : 'disabled') . ", $edition edition" . ($msp ? ', MSP' : '') . ($edition === 'cloud' ? ", data center $data_center_log" : '') . ($changed ? '; replaced ' . implode(' and ', $changed) : '') . ")");
    \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.save', $session_user_id, 'manageengine_mdm_integration', $id, 'success', ($enabled ? 'enabled' : 'disabled') . ', ' . $edition . ($changed ? '; replaced ' . implode(' and ', $changed) : ''));
    flash_alert('ManageEngine MDM settings saved');
    redirect();
}

if (isset($_POST['test_mdm_connection'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = MdmClientFactory::row($mysqli);
    if (!$row || !MdmClientFactory::hasCredentials($row)) {
        flash_alert('Save the connection details before testing.', 'error');
        redirect();
    }
    $id = intval($row['id']);

    try {
        $result = MdmClientFactory::forRow($mysqli, $row, time() + 60)->testConnection();
    } catch (MdmException $e) {
        $result = new \ITFlow\Integrations\ConnectionResult(false, $e->getMessage(), ['error_code' => $e->errorCode]);
    }
    $devices_found = isset($result->details['total']) && is_numeric($result->details['total']) ? (int) $result->details['total'] : null;

    $success = $result->success ? 1 : 0;
    $error_sql = $result->error ? "'" . mysqli_real_escape_string($mysqli, substr($result->error, 0, 500)) . "'" : 'NULL';
    $code_sql = !empty($result->details['error_code']) ? "'" . mysqli_real_escape_string($mysqli, (string) $result->details['error_code']) . "'" : 'NULL';
    mysqli_query($mysqli, "UPDATE manageengine_mdm_integrations SET last_test_at = NOW(), last_test_success = $success, last_test_error = $error_sql, last_test_error_code = $code_sql, last_test_devices = " . ($devices_found === null ? 'NULL' : $devices_found) . " WHERE id = $id");

    \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.test', $session_user_id, 'manageengine_mdm_integration', $id, $result->success ? 'success' : 'failed', $result->error);
    flash_alert($result->success ? 'Connection successful' . ($devices_found !== null ? ": $devices_found device" . ($devices_found === 1 ? '' : 's') . ' reported by ManageEngine' : '') : 'Connection failed: ' . nullable_htmlentities($result->error), $result->success ? 'success' : 'error');
    redirect();
}

if (isset($_POST['sync_mdm_now'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);

    $row = MdmClientFactory::row($mysqli);
    if (!$row) {
        flash_alert('ManageEngine MDM is not configured yet.', 'error');
        redirect();
    }
    if (empty($row['enabled'])) {
        flash_alert('Switch the integration on before syncing.', 'error');
        redirect();
    }

    // Same service as cron: overlapping-run lock, sync log, 120 s time limit, classified errors, shared token store.
    $run = MdmSyncService::run($mysqli, $row, $session_user_id, 120);

    if ($run['stats'] !== null) {
        $stats = $run['stats'];
        logAction("Settings", "Edit", "$session_name synced ManageEngine MDM devices: {$stats['total']} devices, {$stats['matched']} newly linked, {$stats['created']} assets created, {$stats['updated']} updated, {$stats['unmatched']} unmatched");
        flash_alert(nullable_htmlentities($run['message']), $run['ok'] ? 'success' : 'error');
    } else {
        logAction("Settings", "Edit", "$session_name ran a ManageEngine MDM sync that failed" . ($run['error_code'] ? " ({$run['error_code']})" : ""));
        flash_alert(nullable_htmlentities($run['message']), 'error');
    }
    redirect();
}

/*
 * Mapping options (Integrations > Device Sync > "Mapping options"): assign contact from the MDM user, the master "Allow MDM to write asset
 * fields" switch and the per-field map. ADMINS ONLY (the master switch lets a sync change asset fields). Every option is off until saved on.
 * "Preview" uses the values in the form (saved or not), writes nothing, and only stores the result in the admin's own session for display.
 */
if (isset($_POST['save_mdm_mapping']) || isset($_POST['preview_mdm_field_writes'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 3);
    if (!$session_is_admin) {
        flash_alert('Only an administrator can change the MDM mapping options.', 'error');
        redirect();
    }

    $row = MdmClientFactory::row($mysqli);
    if (!$row || !\ITFlow\Integrations\ManageEngine\MdmMappingSettings::schemaReady($mysqli)) {
        flash_alert('Save the ManageEngine MDM connection first (and run the database update if it is pending).', 'error');
        redirect();
    }
    $iid = intval($row['id']);

    $raw_map = [];
    foreach (\ITFlow\Integrations\ManageEngine\MdmMappingSettings::FIELDS as $field_key => $field_def) {
        $raw_map[$field_key] = [
            'target' => (string) ($_POST['fm_target'][$field_key] ?? 'panel'),
            'mode' => (string) ($_POST['fm_mode'][$field_key] ?? ''),
        ];
    }
    $field_map = \ITFlow\Integrations\ManageEngine\MdmMappingSettings::cleanFieldMap($raw_map);
    $assign = isset($_POST['assign_contact']) && $_POST['assign_contact'] === '1';
    $overwrite = isset($_POST['assign_contact_overwrite']) && $_POST['assign_contact_overwrite'] === '1';
    $allow_writes = isset($_POST['allow_asset_writes']) && $_POST['allow_asset_writes'] === '1';

    if (isset($_POST['preview_mdm_field_writes'])) {
        $plan = (new \ITFlow\Integrations\ManageEngine\MdmFieldWriter($mysqli, $iid))->plan(['field_map' => $field_map] + \ITFlow\Integrations\ManageEngine\MdmMappingSettings::defaults());
        $_SESSION['mdm_field_preview'] = [
            'at' => date('Y-m-d H:i:s'), 'master_on' => $allow_writes, 'enabled' => (int) $row['enabled'] === 1, 'examined' => $plan['examined'],
            'total' => count($plan['changes']), 'changes' => array_slice($plan['changes'], 0, 200),
            'skipped_total' => count($plan['skipped']), 'skipped' => array_slice($plan['skipped'], 0, 50),
        ];
        logAction("Settings", "View", "$session_name previewed ManageEngine MDM asset field writes (" . count($plan['changes']) . " change(s), nothing written)");
        flash_alert('Preview ready below. Nothing was written.');
        redirect();
    }

    // The master switch stays off while the integration is disabled.
    if ((int) $row['enabled'] !== 1) {
        $allow_writes = false;
    }
    \ITFlow\Integrations\ManageEngine\MdmMappingSettings::save($mysqli, $iid, $assign, $overwrite, $allow_writes, $field_map, intval($session_user_id));
    $written_fields = implode(', ', array_keys($field_map));
    logAction("Settings", "Edit", "$session_name saved the ManageEngine MDM mapping options (assign contact " . ($assign ? ($overwrite ? 'on, overwrite on' : 'on') : 'off') . ", asset writes " . ($allow_writes ? 'ON' : 'off') . ($written_fields !== '' ? ", fields to write: $written_fields" : '') . ")");
    \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.mapping_save', $session_user_id, 'manageengine_mdm_integration', $iid, 'success', ($allow_writes ? 'asset writes on' : 'asset writes off') . ($assign ? ', assign contact on' : ''));
    unset($_SESSION['mdm_field_preview']);
    flash_alert('Mapping options saved' . (((int) $row['enabled'] !== 1 && isset($_POST['allow_asset_writes'])) ? '. Asset writes stay off while the integration is disabled.' : ''));
    redirect();
}
