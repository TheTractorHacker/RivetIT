<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Integrations > RMM: the client mapping queue (names an RMM reports that match no department) and the stale-asset
 * review queue. Rules live in ITFlow\Integrations\ClientMap, ITFlow\Assets\StaleAssets and ITFlow\Platform\PlatformSettings.
 */

if (isset($_POST['map_integration_client']) || isset($_POST['ignore_integration_client']) || isset($_POST['reopen_integration_client'])) {
    validateCSRFToken($_POST['csrf_token']);

    $map_id = intval($_POST['map_id'] ?? 0);
    $client_map = new \ITFlow\Integrations\ClientMap($mysqli);
    $map_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT external_name, integration_id FROM integration_client_map WHERE map_id = $map_id"));
    $map_label = $map_row ? sanitizeInput($map_row['external_name']) : '';

    if (!$map_row) {
        flash_alert('That entry no longer exists.', 'error');
    } elseif (isset($_POST['map_integration_client'])) {
        $map_client_id = intval($_POST['client_id'] ?? 0);
        $result = $client_map->assign($map_id, $map_client_id);
        if ($result['ok']) {
            logAction('Integration', 'Edit', "$session_name mapped RMM client name \"$map_label\" to department #$map_client_id", $map_client_id, $map_id);
        }
        flash_alert(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), $result['ok'] ? 'success' : 'error');
    } elseif (isset($_POST['ignore_integration_client'])) {
        $result = $client_map->ignore($map_id);
        logAction('Integration', 'Edit', "$session_name set RMM client name \"$map_label\" to be ignored", 0, $map_id);
        flash_alert(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'));
    } else {
        $client_map->reopen($map_id);
        logAction('Integration', 'Edit', "$session_name moved RMM client name \"$map_label\" back to the needs-mapping queue", 0, $map_id);
        flash_alert('Moved back to the queue.');
    }

    redirect();
}

if (isset($_POST['save_stale_asset_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $retire_enabled = isset($_POST['stale_asset_retire_enabled']) ? '1' : '0';
    $retire_days = max(7, min(3650, intval($_POST['stale_asset_retire_days'] ?? 90)));
    \ITFlow\Platform\PlatformSettings::set($mysqli, 'stale_asset_retire_enabled', $retire_enabled);
    \ITFlow\Platform\PlatformSettings::set($mysqli, 'stale_asset_retire_days', (string) $retire_days);
    logAction('Settings', 'Edit', "$session_name " . ($retire_enabled === '1' ? "turned on auto-retire of stale RMM assets after $retire_days days" : 'turned off auto-retire of stale RMM assets'));
    flash_alert('Stale asset settings saved');

    redirect();
}

if (isset($_POST['restore_retired_asset']) || isset($_POST['confirm_retired_asset'])) {
    validateCSRFToken($_POST['csrf_token']);

    $queue_id = intval($_POST['queue_id'] ?? 0);
    $stale = new \ITFlow\Assets\StaleAssets($mysqli);
    if (isset($_POST['restore_retired_asset'])) {
        $done = $stale->restore($queue_id, intval($session_user_id));
        if ($done) { logAction('Asset', 'Edit', "$session_name restored an auto-retired asset (review queue entry #$queue_id)", 0, $queue_id); }
        flash_alert($done ? 'Asset restored' : 'That entry was already handled', $done ? 'success' : 'error');
    } else {
        $done = $stale->confirm($queue_id, intval($session_user_id));
        if ($done) { logAction('Asset', 'Edit', "$session_name confirmed an auto-retired asset (review queue entry #$queue_id)", 0, $queue_id); }
        flash_alert($done ? 'Retirement confirmed' : 'That entry was already handled', $done ? 'success' : 'error');
    }

    redirect();
}
