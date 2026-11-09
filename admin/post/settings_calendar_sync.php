<?php

if (isset($_POST['save_outlook_cal_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 3);

    $tenant_id     = sanitizeInput($_POST['outlook_cal_tenant_id']);
    $client_id     = sanitizeInput($_POST['outlook_cal_client_id']);
    $client_secret = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['outlook_cal_client_secret'] ?? '')));

    if ($client_secret) {
        mysqli_query($mysqli, "UPDATE settings SET
            config_outlook_cal_tenant_id     = '$tenant_id',
            config_outlook_cal_client_id     = '$client_id',
            config_outlook_cal_client_secret = '$client_secret'
            WHERE company_id = 1");
    } else {
        // Don't overwrite existing secret if field left blank
        mysqli_query($mysqli, "UPDATE settings SET
            config_outlook_cal_tenant_id = '$tenant_id',
            config_outlook_cal_client_id = '$client_id'
            WHERE company_id = 1");
    }

    logAction("Settings", "Edit", "Updated Outlook Calendar Sync credentials");
    flash_alert("Outlook Calendar Sync credentials saved.");
    redirect();
}

if (isset($_POST['sync_all_outlook'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 3);

    set_time_limit(120);

    $scope = ($_POST['sync_scope'] ?? 'future') === 'all' ? 'all' : 'future';
    $date_clause = ($scope === 'all') ? '1=1' : "ts.schedule_start >= CURDATE()";

    $sql = mysqli_query($mysqli,
        "SELECT ts.schedule_id FROM ticket_schedules ts
         INNER JOIN users u ON ts.schedule_tech_id = u.user_id
         WHERE $date_clause
           AND ts.schedule_archived_at IS NULL
           AND u.user_outlook_refresh_token IS NOT NULL
           AND u.user_outlook_refresh_token != ''
         ORDER BY ts.schedule_start ASC");

    $synced = 0;
    $skipped = 0;
    $failed = 0;

    while ($row = mysqli_fetch_assoc($sql)) {
        $result = syncScheduleEntryToOutlook($row['schedule_id']);
        if ($result === 'synced')       $synced++;
        elseif ($result === 'failed')   $failed++;
        else                            $skipped++;
    }

    $total = $synced + $skipped + $failed;
    $msg = "Outlook sync complete: $synced synced";
    if ($skipped) $msg .= ", $skipped skipped (tech not connected)";
    if ($failed)  $msg .= ", $failed failed (check PHP error log)";

    logAction("Settings", "Edit", "Bulk synced $total appointments to Outlook ($scope)");
    flash_alert($msg, $failed ? 'warning' : 'success');
    redirect();
}

if (isset($_GET['clear_outlook_cal_settings'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('admin', 3);

    mysqli_query($mysqli, "UPDATE settings SET
        config_outlook_cal_tenant_id     = NULL,
        config_outlook_cal_client_id     = NULL,
        config_outlook_cal_client_secret = NULL
        WHERE company_id = 1");

    logAction("Settings", "Edit", "Cleared Outlook Calendar Sync credentials");
    flash_alert("Outlook Calendar Sync credentials cleared.");
    redirect();
}
