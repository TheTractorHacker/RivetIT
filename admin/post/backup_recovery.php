<?php

/*
 * RivetIT - Backup page: recovery settings and "Run drill now".
 * Included by admin/post/backup.php (admin/post.php picks the handler from the Referer: admin/backup.php).
 * Actions: save_recovery_settings, run_restore_drill
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Recovery\RecoverySettings;
use ITFlow\Recovery\RestoreDrill;

if (isset($_POST['save_recovery_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (!RecoverySettings::ready($mysqli)) {
        flash_alert('Run the database update first (Admin > Update): the recovery tables are not there yet.', 'error');
        redirect();
    }

    $clamp = fn (string $k, int $min, int $max) => (string) max($min, min($max, intval($_POST[$k] ?? RecoverySettings::DEFAULTS[$k] ?? 0)));
    $interval = function (string $k): string {
        $v = trim((string) ($_POST[$k] ?? ''));
        return $v === 'auto' && $k === 'sync_interval_unifi' ? 'auto' : (string) max(0, min(1440, intval($v)));
    };

    RecoverySettings::set($mysqli, 'drill_enabled', isset($_POST['drill_enabled']) ? '1' : '0');
    RecoverySettings::set($mysqli, 'drill_db_host', trim(preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) ($_POST['drill_db_host'] ?? ''))));
    RecoverySettings::set($mysqli, 'drill_db_user', trim(preg_replace('/[^A-Za-z0-9_.@\-]/', '', (string) ($_POST['drill_db_user'] ?? ''))));
    // Blank = keep the saved password (same convention as the S3 secret key and the backup passphrase on this page).
    if (trim((string) ($_POST['drill_db_pass'] ?? '')) !== '') {
        RecoverySettings::set($mysqli, 'drill_db_pass', encryptSetting(trim((string) $_POST['drill_db_pass'])));
    }
    RecoverySettings::set($mysqli, 'drill_tolerance_pct', $clamp('drill_tolerance_pct', 1, 50));
    RecoverySettings::set($mysqli, 'backup_stale_hours', $clamp('backup_stale_hours', 2, 720));
    RecoverySettings::set($mysqli, 'sync_error_threshold', $clamp('sync_error_threshold', 1, 20));
    foreach (['sync_interval_rmm', 'sync_interval_intune', 'sync_interval_unifi'] as $k) {
        RecoverySettings::set($mysqli, $k, $interval($k));
    }
    $emails = array_filter(preg_split('/[,;\s]+/', (string) ($_POST['alert_email'] ?? '')) ?: [], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    RecoverySettings::set($mysqli, 'alert_email', implode(', ', $emails));

    logAction('Settings', 'Edit', "$session_name updated recovery settings (restore drill, backup and sync alerts)");
    flash_alert('Recovery settings saved');
    redirect();
}

if (isset($_POST['run_restore_drill'])) {
    validateCSRFToken($_POST['csrf_token']);

    $drill = new RestoreDrill($mysqli, dirname(__DIR__, 2), ['live_user' => $dbusername ?? '']);
    $cfg = $drill->configuration();
    if (!$cfg['configured']) {
        flash_alert('The restore drill is not set up: ' . nullable_htmlentities(implode(' ', $cfg['problems'])) . ' See the setup steps below.', 'error');
        redirect();
    }
    if (RestoreDrill::spawn(dirname(__DIR__, 2), 'manual')) {
        logAction('Backup', 'Restore Drill', "$session_name started a restore drill");
        flash_alert('Restore drill started. It restores the newest backup into a scratch database and removes it again; refresh this page in a minute for the result.');
    } else {
        flash_alert('Could not start the drill from the web server. Run it from a shell instead: <code>sudo -u www-data php cron/restore_drill.php --force</code>', 'error');
    }
    redirect();
}
