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

// Save which ticket category (and optional priority) tickets made from RMM alerts get: one default plus optional routes.
// Everything is checked here, never trusted from the form: ids are ints, a category must be a live Ticket category, a route type/value
// must come from the fixed lists in includes/rmm_alert_routes.php, and one bad row rejects the whole save (nothing half-written).
if (isset($_POST['save_rmm_alert_ticket_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    require_once dirname(__DIR__, 2) . '/includes/rmm_alert_routes.php';

    $valid_cats = rmmAlertTicketCategoryIds($mysqli);
    $default_cat = intval($_POST['alert_ticket_category_id'] ?? 0);
    if ($default_cat !== 0 && !in_array($default_cat, $valid_cats, true)) {
        flash_alert('That category does not exist or is not a ticket category. Nothing was saved.', 'warning');
        redirect();
    }

    $allowed_values = [
        'severity'     => array_keys(RMM_ALERT_ROUTE_SEVERITIES),
        'source'       => array_keys(RMM_ALERT_ROUTE_SOURCES),
        'check_prefix' => array_keys(RMM_ALERT_ROUTE_FAMILIES),
    ];
    // each route's "when" arrives as one choice, "type:value" (severity:warning, check_prefix:disk, source:tactical_rmm)
    $whens  = is_array($_POST['route_when'] ?? null) ? array_values($_POST['route_when']) : [];
    $types  = [];
    $values = [];
    foreach ($whens as $w) {
        $parts = is_string($w) ? explode(':', $w, 2) : [];
        $types[]  = count($parts) === 2 ? $parts[0] : (is_string($w) ? $w : []);
        $values[] = count($parts) === 2 ? $parts[1] : '';
    }
    $cats   = is_array($_POST['route_category'] ?? null) ? array_values($_POST['route_category']) : [];
    $prios  = is_array($_POST['route_priority'] ?? null) ? array_values($_POST['route_priority']) : [];
    if (count($types) > 50) {
        flash_alert('At most 50 routes are allowed. Nothing was saved.', 'warning');
        redirect();
    }

    $rows = [];
    $seen = [];
    foreach ($types as $i => $t) {
        $v = $values[$i] ?? '';
        $c = $cats[$i] ?? '';
        $p = $prios[$i] ?? '';
        if (!is_string($t) || !is_string($v) || !is_scalar($c) || !is_string($p)) {
            flash_alert('A route has an invalid value. Nothing was saved.', 'warning');
            redirect();
        }
        if ($t === '' && $v === '' && $c === '') {
            continue;   // an untouched blank row
        }
        $c = intval($c);
        if (!isset($allowed_values[$t]) || !in_array($v, $allowed_values[$t], true)) {
            flash_alert('A route has an unknown "when" choice. Nothing was saved.', 'warning');
            redirect();
        }
        if (!in_array($c, $valid_cats, true)) {
            flash_alert('A route points at a category that does not exist or is not a ticket category. Nothing was saved.', 'warning');
            redirect();
        }
        if ($p !== '' && !in_array($p, RMM_ALERT_ROUTE_PRIORITIES, true)) {
            flash_alert('A route has an unknown priority. Nothing was saved.', 'warning');
            redirect();
        }
        if (isset($seen["$t|$v"])) {
            flash_alert('Two routes have the same "when" choice; keep one. Nothing was saved.', 'warning');
            redirect();
        }
        $seen["$t|$v"] = true;
        $rows[] = [$t, $v, $c, $p];
    }

    $default_sql = $default_cat > 0 ? $default_cat : 'NULL';
    mysqli_begin_transaction($mysqli);
    $ok = mysqli_query($mysqli, "UPDATE settings SET config_rmm_alert_ticket_category_id = $default_sql WHERE company_id = 1")
        && mysqli_query($mysqli, "DELETE FROM rmm_alert_ticket_routes");
    foreach ($rows as $n => [$t, $v, $c, $p]) {
        $ok = $ok && mysqli_query($mysqli, "INSERT INTO rmm_alert_ticket_routes SET match_type = '" . mysqli_real_escape_string($mysqli, $t) . "',
            match_value = '" . mysqli_real_escape_string($mysqli, $v) . "', category_id = $c,
            priority = " . ($p !== '' ? "'" . mysqli_real_escape_string($mysqli, $p) . "'" : 'NULL') . ", sort = " . (($n + 1) * 10));
    }
    if (!$ok) {
        mysqli_rollback($mysqli);
        flash_alert('Could not save the alert ticket settings.', 'danger');
        redirect();
    }
    mysqli_commit($mysqli);

    logAction('Settings', 'Edit', "$session_name updated RMM alert ticket category (default " . ($default_cat ?: 'none') . ', ' . count($rows) . ' route(s))');
    rivetAudit('settings.rmm_alert_ticket_category_changed', (int) $session_user_id, 'settings', 'config_rmm_alert_ticket_category_id', 'update',
        'RMM alert ticket category changed', ['default_category_id' => $default_cat, 'routes' => count($rows)]);
    flash_alert('Alert ticket settings saved');
    redirect();
}

// One click: make a "Maintenance" Ticket category (if none exists yet) and use it as the default for alert tickets.
if (isset($_POST['create_rmm_alert_maintenance_category'])) {
    validateCSRFToken($_POST['csrf_token']);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL AND LOWER(category_name) = 'maintenance' LIMIT 1"));
    if ($existing) {
        $cat_id = intval($existing['category_id']);
        $msg = 'A Maintenance ticket category already exists; it is now the default for alert tickets';
    } else {
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Maintenance', category_description = 'Tickets made from RMM alerts', category_type = 'Ticket', category_color = 'orange', category_parent = 0");
        $cat_id = intval(mysqli_insert_id($mysqli));
        logAction('Category', 'Create', "$session_name created category Ticket Maintenance", 0, $cat_id);
        $msg = 'Maintenance category created and set as the default for alert tickets';
    }
    if ($cat_id > 0) {
        mysqli_query($mysqli, "UPDATE settings SET config_rmm_alert_ticket_category_id = $cat_id WHERE company_id = 1");
        logAction('Settings', 'Edit', "$session_name set the RMM alert ticket category to Maintenance (category $cat_id)");
        rivetAudit('settings.rmm_alert_ticket_category_changed', (int) $session_user_id, 'settings', 'config_rmm_alert_ticket_category_id', 'update',
            'RMM alert ticket category set to Maintenance', ['default_category_id' => $cat_id]);
        flash_alert($msg);
    }
    redirect();
}

// Save device metrics settings.
//
// The three retention/interval numbers are clamped rather than rejected: they come from
// number inputs whose min/max the browser already enforces, so a value outside the range
// means the form was bypassed, and silently pinning it to the nearest sane bound keeps the
// collector's arithmetic safe without bouncing the admin back with an error they cannot act on.
// Raw retention is additionally capped at the hourly retention - keeping full-resolution
// samples for LONGER than the rollups they feed would make the prune step delete rollups that
// still have live source rows, which reads as data loss on the charts.
if (isset($_POST['save_device_metrics_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $metrics_enabled  = isset($_POST['config_enable_device_metrics']) ? 1 : 0;
    $metrics_interval = max(60, min(3600, intval($_POST['config_metrics_collect_interval_seconds'] ?? 300)));
    $metrics_hour_days = max(1, min(3650, intval($_POST['config_metrics_hour_retention_days'] ?? 90)));
    $metrics_raw_days  = max(1, min(365,  intval($_POST['config_metrics_raw_retention_days'] ?? 14)));
    $metrics_raw_days  = min($metrics_raw_days, $metrics_hour_days);

    mysqli_query($mysqli, "UPDATE settings SET
        config_enable_device_metrics = $metrics_enabled,
        config_metrics_collect_interval_seconds = $metrics_interval,
        config_metrics_raw_retention_days = $metrics_raw_days,
        config_metrics_hour_retention_days = $metrics_hour_days
        WHERE company_id = 1");

    logAction('Settings', 'Edit', "$session_name " . ($metrics_enabled ? 'enabled' : 'disabled') .
        " device metrics collection (interval {$metrics_interval}s, raw {$metrics_raw_days}d, hourly {$metrics_hour_days}d)");
    flash_alert('Device metrics settings saved');
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
