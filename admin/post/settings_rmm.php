<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Save module on/off toggle
if (isset($_POST['save_rmm_module_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $enabled = isset($_POST['config_module_enable_rmm']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_module_enable_rmm=$enabled WHERE company_id=1");
    logAction('Settings', 'Edit', "$session_name " . ($enabled ? 'enabled' : 'disabled') . " RMM module");
    flash_alert($enabled ? 'RMM module enabled' : 'RMM module disabled');
    redirect();
}

// Save Connect/Remote RMM preference (which integration "wins" when an
// asset is tracked by more than one RMM)
if (isset($_POST['save_rmm_prefer_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $prefer_tactical = isset($_POST['config_rmm_prefer_tactical']) ? 1 : 0;
    mysqli_query($mysqli, "UPDATE settings SET config_rmm_prefer_tactical=$prefer_tactical WHERE company_id=1");
    logAction('Settings', 'Edit', "$session_name " . ($prefer_tactical ? 'enabled' : 'disabled') . " RMM preference for Tactical RMM");
    flash_alert('RMM preference settings saved');
    redirect();
}

// Save auto-ticket severity settings
if (isset($_POST['save_rmm_auto_ticket_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $allowed_severities = ['critical', 'error', 'warning', 'info'];
    $selected = array_intersect($_POST['auto_ticket_severities'] ?? [], $allowed_severities);
    $severities = mysqli_real_escape_string($mysqli, implode(',', $selected));
    mysqli_query($mysqli, "UPDATE settings SET config_rmm_auto_ticket_severities='$severities' WHERE company_id=1");
    logAction('Settings', 'Edit', "$session_name updated RMM auto-ticket severities to: " . ($severities ?: 'none'));
    flash_alert('Auto-ticket settings saved');
    redirect();
}

// Save/create integration
if (isset($_POST['save_rmm_integration'])) {
    validateCSRFToken($_POST['csrf_token']);
    $oauth_types = ['action1', 'sophos_central']; // client_id + client_secret, not a single api_key

    $integration_id = intval($_POST['integration_id'] ?? 0);
    $name    = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['integration_name']));
    $type    = in_array($_POST['integration_type'] ?? '', ['tactical_rmm','level','action1','sophos_central']) ? $_POST['integration_type'] : 'tactical_rmm';
    $type    = mysqli_real_escape_string($mysqli, $type);
    $api_url = mysqli_real_escape_string($mysqli, rtrim(sanitizeInput($_POST['integration_api_url']), '/'));
    $web_url = mysqli_real_escape_string($mysqli, rtrim(sanitizeInput($_POST['integration_web_url'] ?? ''), '/'));
    $api_key = trim($_POST['integration_api_key'] ?? '');
    $client_secret = trim($_POST['integration_client_secret'] ?? '');
    $enabled = isset($_POST['integration_enabled']) ? 1 : 0;
    $default_client_id = intval($_POST['integration_default_client_id'] ?? 0);
    $default_client_sql = $default_client_id ?: 'NULL';

    // Validate URL is HTTPS
    $raw_url = rtrim(sanitizeInput($_POST['integration_api_url']), '/');
    if (!filter_var($raw_url, FILTER_VALIDATE_URL) || strtolower(parse_url($raw_url, PHP_URL_SCHEME)) !== 'https') {
        flash_alert('API URL must be a valid HTTPS URL', 'warning');
        redirect();
    }

    if ($integration_id > 0) {
        $set = "name='$name', type='$type', api_url='$api_url', web_url='$web_url', enabled=$enabled";
        if (isset($_POST['integration_default_client_id'])) {
            $set .= ", default_client_id=$default_client_sql";
        }
        if (in_array($type, $oauth_types, true)) {
            if (!empty($api_key) || !empty($client_secret)) {
                $existing = ['client_id' => '', 'client_secret' => ''];
                $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT api_key_enc FROM rmm_integrations WHERE id=$integration_id"));
                if ($row) {
                    $decoded = json_decode(decryptSetting($row['api_key_enc'] ?? ''), true);
                    if (is_array($decoded)) { $existing = array_merge($existing, $decoded); }
                }
                $creds = [
                    'client_id'     => $api_key !== '' ? $api_key : $existing['client_id'],
                    'client_secret' => $client_secret !== '' ? $client_secret : $existing['client_secret'],
                ];
                $enc = mysqli_real_escape_string($mysqli, encryptSetting(json_encode($creds)));
                $set .= ", api_key_enc='$enc'";
            }
        } elseif (!empty($api_key)) {
            $enc = mysqli_real_escape_string($mysqli, encryptSetting($api_key));
            $set .= ", api_key_enc='$enc'";
        }
        mysqli_query($mysqli, "UPDATE rmm_integrations SET $set WHERE id=$integration_id");
        logAction('RMM Settings', 'Edit', "$session_name updated RMM integration $name");
        flash_alert('Integration updated');
    } else {
        if (in_array($type, $oauth_types, true)) {
            if (empty($api_key) || empty($client_secret)) {
                flash_alert('Client ID and Client Secret are required for new integrations of this type', 'warning');
                redirect();
            }
            $enc = mysqli_real_escape_string($mysqli, encryptSetting(json_encode([
                'client_id'     => $api_key,
                'client_secret' => $client_secret,
            ])));
        } else {
            if (empty($api_key)) {
                flash_alert('API key is required for new integrations', 'warning');
                redirect();
            }
            $enc = mysqli_real_escape_string($mysqli, encryptSetting($api_key));
        }
        mysqli_query($mysqli, "INSERT INTO rmm_integrations SET name='$name', type='$type', api_url='$api_url', web_url='$web_url', default_client_id=$default_client_sql, api_key_enc='$enc', enabled=$enabled, created_by=$session_user_id");
        $new_id = intval(mysqli_insert_id($mysqli));
        if (!$config_rmm_default_integration_id) {
            mysqli_query($mysqli, "UPDATE settings SET config_rmm_default_integration_id=$new_id WHERE company_id=1");
        }
        logAction('RMM Settings', 'Create', "$session_name added $type RMM integration $name");
        flash_alert('Integration added');
    }
    redirect();
}

// Delete integration
if (isset($_POST['delete_rmm_integration'])) {
    validateCSRFToken($_POST['csrf_token']);
    $integration_id = intval($_POST['integration_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM rmm_integrations WHERE id=$integration_id"));
    if ($row) {
        mysqli_query($mysqli, "DELETE FROM rmm_integrations WHERE id=$integration_id");
        $log_name = mysqli_real_escape_string($mysqli, $row['name']);
        logAction('RMM Settings', 'Delete', "$session_name deleted RMM integration $log_name");
        flash_alert('Integration deleted');
    }
    redirect();
}

// AJAX test connection
if (isset($_POST['test_rmm_connection'])) {
    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');
    $integration_id = intval($_POST['integration_id'] ?? 0);
    if (!mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT id FROM rmm_integrations WHERE id=$integration_id LIMIT 1"))) {
        echo json_encode(['success' => false, 'error' => 'Integration not found']);
        exit;
    }
    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_client_factory.php';
        $client = getRmmClient($integration_id);
        $ok     = $client->testConnection();
        echo json_encode(['success' => $ok]);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Save per-firewall client assignments (Firewalls tab mapping table)
if (isset($_POST['save_firewall_client_mappings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $mappings = $_POST['fw_client_map'] ?? [];
    foreach ($mappings as $asset_id => $client_id) {
        $asset_id  = intval($asset_id);
        $client_id = intval($client_id);
        if ($asset_id <= 0) continue;
        $cid_sql = 'NULL';
        if ($client_id > 0) {
            $valid_client = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT client_id FROM clients WHERE client_id=$client_id AND client_archived_at IS NULL LIMIT 1"
            ));
            if (!$valid_client) continue;
            $cid_sql = $client_id;
        }
        mysqli_query($mysqli, "UPDATE assets SET asset_client_id=$cid_sql WHERE asset_id=$asset_id AND asset_type='Firewall/Router'");
    }
    logAction('Firewall Settings', 'Edit', "$session_name updated firewall client mappings");
    flash_alert('Firewall mappings saved');
    redirect();
}

// AJAX sync now (assets + alerts) — lets an admin sync any integration
// (e.g. Sophos firewalls) directly from the integration list, without
// needing to find it in the Assets page's integration filter first.
if (isset($_POST['sync_rmm_now'])) {
    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');
    $integration_id = intval($_POST['integration_id'] ?? 0);

    if (!mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT id FROM rmm_integrations WHERE id=$integration_id LIMIT 1"))) {
        echo json_encode(['success' => false, 'error' => 'Integration not found']);
        exit;
    }

    // Rate-limit: one sync per integration per 60 seconds
    $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT id FROM rmm_sync_log WHERE integration_id=$integration_id
         AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) AND status='running' LIMIT 1"
    ));
    if ($recent) {
        echo json_encode(['success' => false, 'error' => 'A sync is already running. Please wait 60 seconds.']);
        exit;
    }

    try {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_client_factory.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/class_rmm_asset_mapper.php';

        $client = getRmmClient($integration_id);
        $mapper = new RmmAssetMapper($mysqli, $integration_id, $session_user_id, $client);
        $log_id = $mapper->startSyncLog();

        $agents = $client->getAgents();
        $stats  = $mapper->syncAgents($agents);
        $mapper->finishSyncLog($log_id, $stats);

        $alert_stats = $mapper->syncAlerts();

        logAction('RMM Settings', 'Import',
            "$session_name synced RMM assets: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched" .
            "; alerts: {$alert_stats['created']} new, {$alert_stats['resolved']} resolved"
        );

        echo json_encode([
            'success'        => true,
            'created'        => $stats['created'],
            'updated'        => $stats['updated'],
            'matched'        => $stats['matched'],
            'skipped'        => $stats['skipped'],
            'alerts_created' => $alert_stats['created'],
        ]);
    } catch (RuntimeException $e) {
        if (isset($log_id)) {
            mysqli_query($mysqli, "UPDATE rmm_sync_log SET finished_at=NOW(), status='failed', errors='" .
                mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        }
        error_log("sync_rmm_now failed for integration $integration_id: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Sync failed — check the sync log for details.']);
    }
    exit;
}
