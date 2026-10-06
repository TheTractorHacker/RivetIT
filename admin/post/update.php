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

// Choose the release channel (Production or Beta). Refuses a channel this server cannot move to without going backwards.
if (isset($_POST['save_release_channel'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    require_once __DIR__ . '/../../includes/release_channel.php';
    $new_channel = (string) ($_POST['release_channel'] ?? '');
    if (!isset(releaseChannels()[$new_channel])) {
        flash_alert('Choose Production or Beta.', 'error');
        redirect();
    }
    $old_channel = releaseChannelConfigured($mysqli, dirname(__DIR__, 2));
    if ($new_channel !== $old_channel) {
        exec("timeout 15 git fetch " . escapeshellarg(RELEASE_REMOTE) . " 2>&1");
        $status = releaseChannelStatus(dirname(__DIR__, 2), $new_channel);
        if (!$status['ref_exists'] || !$status['can_switch']) {
            flash_alert(htmlspecialchars($status['reason'], ENT_QUOTES), 'error');
            redirect();
        }
    }
    // The setting is added by a database update; until it has run, say so instead of failing.
    $channel_column = mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_release_channel'");
    if (!$channel_column || mysqli_num_rows($channel_column) === 0) {
        flash_alert('Run <strong>Update Database</strong> first: the release channel setting is added by that update.', 'error');
        redirect();
    }
    mysqli_query($mysqli, "UPDATE settings SET config_release_channel = '" . mysqli_real_escape_string($mysqli, $new_channel) . "' WHERE company_id = 1");
    logAction('App', 'Update', "$session_name set the release channel to $new_channel (was $old_channel)");
    flash_alert('Release channel set to <strong>' . htmlspecialchars(releaseChannels()[$new_channel]['label'], ENT_QUOTES) . '</strong>.' . ($new_channel !== $old_channel ? ' Run <strong>Update App</strong> to move this server onto it.' : ''));
    redirect();

}

if (isset($_GET['update'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    if (($backup_error = update_pre_backup()) !== null) {
        flash_alert($backup_error, 'error');
        redirect();
    }

    //git fetch downloads the latest from remote without trying to merge or rebase anything. Then the git reset resets the branch to what you just fetched. The --hard option changes all the files in your working tree to match the files in origin/main

    // Capture git's own output and exit code. Before, a failed git step (for example the web user unable to
    // write to the install folder, or hand-edited files in the way of a pull) was ignored and the page still
    // said "Update successful" while nothing had changed.
    require_once __DIR__ . '/../../includes/release_channel.php';
    releaseResetGeneratedFiles(dirname(__DIR__, 2));
    $git_output = [];
    $git_code = 0;
    // composer rewrites tracked files in vendor/composer (installed.php, autoload_*.php) every time it runs. They are
    // generated, never edited by hand, and a pull would refuse to overwrite them, so restore them first;
    // composer regenerates them right after the pull below.
    exec("git checkout -- ':/vendor/composer' 2>&1");

    // Follow the release channel: move onto its branch first (forward only; refused if it would install older code), then update from it.
    require_once __DIR__ . '/../../includes/release_channel.php';
    $release_channel = releaseChannelConfigured($mysqli, dirname(__DIR__, 2));
    $release_branch  = releaseChannelBranch($release_channel);
    exec("timeout 30 git fetch " . escapeshellarg(RELEASE_REMOTE) . " 2>&1");
    $ensure = releaseChannelEnsureBranch(dirname(__DIR__, 2), $release_channel);
    if (!$ensure['ok']) {
        logApp('Update', 'error', 'Update stopped: ' . substr($ensure['message'], 0, 400));
        flash_alert('The update did not run: ' . htmlspecialchars($ensure['message'], ENT_QUOTES), 'error');
        redirect();
    }
    if (isset($_GET['force_update']) == 1) {
        exec("git reset --hard " . escapeshellarg(RELEASE_REMOTE . '/' . $release_branch) . " 2>&1", $git_output, $git_code);
    } else {
        exec("git pull " . escapeshellarg(RELEASE_REMOTE) . " " . escapeshellarg($release_branch) . " 2>&1", $git_output, $git_code);
    }
    if ($git_code !== 0) {
        $git_reason = trim(implode(' ', array_slice(array_filter(array_map('trim', $git_output)), 0, 2)));
        logApp('Update', 'error', 'Update failed: ' . substr($git_reason, 0, 500));
        flash_alert('The update did not run: ' . htmlspecialchars(substr($git_reason, 0, 300), ENT_QUOTES)
            . ' If local files were changed by hand, use Advanced: force update. If it says permission denied, the web server user cannot write to the install folder.', 'error');
        redirect();
    }
    //header("Location: post.php?update_db");


    // Telemetry was removed: this used to POST company name/site/city/state/country and usage
    // counts to the upstream ITFlow project on every "Update App" click. The old condition was
    // `$config_telemetry > 0 OR $config_telemetry = 2` -- a stray "=" instead of "==", so it was
    // ALWAYS true and always sent, regardless of the Telemetry setting (found while removing this
    // feature). RivetIT sends nothing anywhere.

    // The code is updated; now make sure the PHP packages it needs are installed (git does not carry them all).
    require_once __DIR__ . '/../../includes/composer_install.php';
    $composer_result = rivetit_composer_install(dirname(__DIR__, 2));
    if (!$composer_result['ok']) {
        logApp('Update', 'error', 'composer install after update failed: ' . $composer_result['message']);
    }

    logAction("App", "Update", "$session_name ran updates");

    if ($composer_result['ok']) {
        flash_alert("Update successful");
    } else {
        flash_alert('The code was updated, but the PHP packages could not be installed automatically ('
            . htmlspecialchars($composer_result['message'], ENT_QUOTES)
            . '). Run <code>composer install --no-dev</code> in the install folder (or use deploy/update.sh) before running Update Database.', 'warning');
    }

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

    // One database update at a time: a second click or a CLI run while one is going must not run the same steps twice.
    require_once __DIR__ . '/../../includes/redis_guards.php';
    $update_lock = rivetLocks() ? rivetLocks()->acquire('update_db', 900) : null;
    if ($update_lock !== null && !$update_lock->held()) {
        flash_alert('A database update is already running. Wait for it to finish.', 'error');
        redirect();
    }

    // Get the current version
    require_once ('../includes/database_version.php');

    // Perform upgrades, if required
    require_once ('database_updates.php');

    if ($update_lock !== null) {
        $update_lock->release();
    }

    logAction("Database", "Update", "$session_name updated the database structure");

    flash_alert("Database structure update successful");

    sleep(1);

    redirect();

}
