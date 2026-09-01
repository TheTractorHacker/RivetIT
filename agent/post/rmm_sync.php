<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * RMM Sync + Test Connection handler
 * Called from:
 *   - admin/settings_rmm.php (AJAX test)
 *   - agent/rmm_assets.php (manual sync trigger)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_client_factory.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/class_rmm_asset_mapper.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!lookupUserPermission('module_rmm')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action         = sanitizeInput($_POST['action'] ?? 'sync');
$integration_id = intval($_POST['integration_id'] ?? $config_rmm_default_integration_id);

if ($integration_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No integration configured']);
    exit;
}

// ---- Test connection ----
if ($action === 'test') {
    try {
        $client = getRmmClient($integration_id);
        $ok     = $client->testConnection();
        echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Could not reach Tactical RMM API']);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---- Full sync ----
if ($action === 'sync') {
    if (!lookupUserPermission('module_rmm_sync')) {
        echo json_encode(['success' => false, 'error' => 'No sync permission']);
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
        $client  = getRmmClient($integration_id);
        $mapper  = new RmmAssetMapper($mysqli, $integration_id, $session_user_id, $client);
        $log_id  = $mapper->startSyncLog();

        $agents = $client->getAgents();
        $stats  = $mapper->syncAgents($agents);
        $mapper->finishSyncLog($log_id, $stats);

        $alert_stats = $mapper->syncAlerts();

        logAction('RMM', 'Import',
            "$session_name synced RMM assets: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched" .
            "; alerts: {$alert_stats['created']} new, {$alert_stats['resolved']} resolved"
        );

        echo json_encode([
            'success'         => true,
            'created'         => $stats['created'],
            'updated'         => $stats['updated'],
            'matched'         => $stats['matched'],
            'skipped'         => $stats['skipped'],
            'errors'          => $stats['errors'],
            'alerts_created'  => $alert_stats['created'],
            'alerts_resolved' => $alert_stats['resolved'],
        ]);
    } catch (RuntimeException $e) {
        if (isset($log_id)) {
            mysqli_query($mysqli, "UPDATE rmm_sync_log SET finished_at=NOW(), status='failed', errors='" .
                mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---- Assign an RMM-synced asset to an ITFlow client ----
if ($action === 'assign_client') {
    if (!lookupUserPermission('module_rmm_sync')) {
        echo json_encode(['success' => false, 'error' => 'No sync permission']);
        exit;
    }

    $asset_id          = intval($_POST['asset_id'] ?? 0);
    $target_client_id  = intval($_POST['client_id'] ?? 0);

    if (!$asset_id || !$target_client_id) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    enforceClientAccess($target_client_id);

    $asset_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_name FROM assets WHERE asset_id=$asset_id"));
    if (!$asset_row) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    $client_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id=$target_client_id AND client_archived_at IS NULL"));
    if (!$client_row) {
        echo json_encode(['success' => false, 'error' => 'Client not found']);
        exit;
    }

    mysqli_query($mysqli, "UPDATE assets SET asset_client_id=$target_client_id WHERE asset_id=$asset_id");

    logAction('RMM', 'Asset Assigned',
        "$session_name assigned RMM asset {$asset_row['asset_name']} to client {$client_row['client_name']}",
        $target_client_id, $asset_id);

    echo json_encode(['success' => true]);
    exit;
}

// ---- Sync scripts from Tactical ----
if ($action === 'sync_scripts') {
    if (!lookupUserPermission('module_rmm_scripts')) {
        echo json_encode(['success' => false, 'error' => 'No scripts permission']);
        exit;
    }
    try {
        $client  = getRmmClient($integration_id);
        $scripts = $client->getScripts();

        // Script payloads differ per provider: Tactical exposes shell/code,
        // Level exposes language/content. Map by the integration's type.
        $intg_row  = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT type FROM rmm_integrations WHERE id=$integration_id LIMIT 1"
        ));
        $intg_type = $intg_row['type'] ?? 'tactical_rmm';

        $imported = 0;
        $updated  = 0;
        foreach ($scripts as $s) {
            $tac_id = intval($s['id'] ?? 0);
            if (!$tac_id) continue;
            $name    = mysqli_real_escape_string($mysqli, substr($s['name'] ?? 'Untitled', 0, 200));
            if ($intg_type === 'level') {
                $stype_raw = $s['language'] ?? $s['shell'] ?? $s['script_type'] ?? 'powershell';
                $body_raw  = $s['content'] ?? $s['script'] ?? $s['body'] ?? $s['code'] ?? '';
            } else {
                $stype_raw = $s['shell'] ?? $s['script_type'] ?? 'powershell';
                $body_raw  = $s['code'] ?? $s['script_body'] ?? '';
            }
            $stype   = mysqli_real_escape_string($mysqli, $stype_raw);
            $desc    = mysqli_real_escape_string($mysqli, substr($s['description'] ?? '', 0, 500));
            $body    = mysqli_real_escape_string($mysqli, $body_raw);
            $cat     = mysqli_real_escape_string($mysqli, substr($s['category'] ?? 'Uncategorized', 0, 100));

            // Scoped by integration_id, not just tactical_script_id - Tactical and
            // Level.io each hand out their own numeric script IDs, so an unscoped
            // lookup can match (and overwrite) an unrelated script from the other
            // provider whose ID happens to collide.
            $existing = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT id FROM rmm_scripts WHERE tactical_script_id=$tac_id AND rmm_integration_id=$integration_id LIMIT 1"
            ));
            if ($existing) {
                mysqli_query($mysqli, "UPDATE rmm_scripts SET name='$name', script_type='$stype', description='$desc', script_body='$body', category='$cat' WHERE tactical_script_id=$tac_id AND rmm_integration_id=$integration_id");
                $updated++;
            } else {
                mysqli_query($mysqli, "INSERT INTO rmm_scripts SET name='$name', script_type='$stype', description='$desc', script_body='$body', category='$cat', tactical_script_id=$tac_id, rmm_integration_id=$integration_id, enabled=1, created_by=$session_user_id");
                $imported++;
            }
        }
        logAction('RMM', 'Scripts Sync', "$session_name synced scripts: $imported imported, $updated updated");
        echo json_encode(['success' => true, 'imported' => $imported, 'updated' => $updated, 'total' => count($scripts)]);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
