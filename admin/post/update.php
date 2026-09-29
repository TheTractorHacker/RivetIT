<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_GET['update']) || isset($_GET['update_db'])) {
    // Backup helpers (build_backup, backup_upload_to_s3, $BACKUP_DIR) live in the backup handler, which admin/post.php
    // only loads for the Backup page.
    require_once __DIR__ . '/backup.php';
}

// Full backup (database + uploads, same as Admin > Backup > Save to Server) taken before an update runs.
// Skipped only when the Update page's "Back up first" switch was turned off (no_backup=1). Returns an error
// message when the backup failed - the caller must then abort - or null when it is safe to go on.
// $reuseRecent: a backup made in the last 10 minutes counts (the DB update that follows an app update).
function update_pre_backup(bool $reuseRecent = false): ?string {
    global $mysqli, $BACKUP_DIR, $session_name;

    if (isset($_GET['no_backup'])) {
        return null;
    }

    if (!is_dir($BACKUP_DIR) && !@mkdir($BACKUP_DIR, 0750, true) && !is_dir($BACKUP_DIR)) {
        return 'Backup folder could not be created, so the update was not run. Check file permissions on the backups folder, or turn off "Back up first" to skip the backup.';
    }
    if (!is_writable($BACKUP_DIR)) {
        return 'Backup folder is not writable by the web server, so the update was not run. Fix the permissions on the backups folder, or turn off "Back up first" to skip the backup.';
    }

    if ($reuseRecent) {
        foreach (glob($BACKUP_DIR . '/itflow_*.zip') ?: [] as $existing) {
            if (filemtime($existing) > time() - 600) {
                return null;
            }
        }
    }

    try {
        $result = build_backup($mysqli, 'manual', $BACKUP_DIR);
        logAction('System', 'Backup Save', "$session_name saved backup {$result['name']} to server before an update");
        backup_upload_to_s3($result['path'], $result['name']);
    } catch (\Throwable $e) {
        logApp('Backup', 'error', 'Pre-update backup failed: ' . $e->getMessage());
        return 'The backup failed (' . htmlspecialchars(strtok($e->getMessage(), "\n"), ENT_QUOTES) . '), so the update was not run. Fix the problem, or turn off "Back up first" to update without a backup.';
    }

    return null;
}

if (isset($_GET['update'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    if (($backup_error = update_pre_backup()) !== null) {
        flash_alert($backup_error, 'error');
        redirect();
    }

    //git fetch downloads the latest from remote without trying to merge or rebase anything. Then the git reset resets the branch to what you just fetched. The --hard option changes all the files in your working tree to match the files in origin/main

    if (isset($_GET['force_update']) == 1) {
        exec("git fetch --all");
        exec("git reset --hard origin/main");
    } else {
        exec("git pull");
    }
    //header("Location: post.php?update_db");


    // Telemetry was removed: this used to POST company name/site/city/state/country and usage
    // counts to the upstream ITFlow project on every "Update App" click. The old condition was
    // `$config_telemetry > 0 OR $config_telemetry = 2` -- a stray "=" instead of "==", so it was
    // ALWAYS true and always sent, regardless of the Telemetry setting (found while removing this
    // feature). RivetIT sends nothing anywhere.

    logAction("App", "Update", "$session_name ran updates");

    flash_alert("Update successful");

    sleep(1);

    redirect();

}

if (isset($_GET['update_db'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    //validateAdminRole(); // Old function

    if (($backup_error = update_pre_backup(true)) !== null) {
        flash_alert($backup_error, 'error');
        redirect();
    }

    // Get the current version
    require_once ('../includes/database_version.php');

    // Perform upgrades, if required
    require_once ('database_updates.php');

    logAction("Database", "Update", "$session_name updated the database structure");

    flash_alert("Database structure update successful");

    sleep(1);

    redirect();

}
