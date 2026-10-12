<?php

/*
 * RivetIT - Backup page: recovery settings and "Run drill now".
 * Included by admin/post/backup.php (admin/post.php picks the handler from the Referer: admin/backup.php).
 * Actions: save_recovery_settings, run_restore_drill, sync_watch_action (mute / unmute / forget / interval)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Recovery\RecoverySettings;
use ITFlow\Recovery\RestoreDrill;
use ITFlow\Recovery\SyncWatch;
use ITFlow\Recovery\WatchOverrides;

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
    RecoverySettings::set($mysqli, 'alert_repeat_hours', $clamp('alert_repeat_hours', 1, 720));
    RecoverySettings::set($mysqli, 'sync_dormant_days', $clamp('sync_dormant_days', 0, 3650));
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

if (isset($_POST['sync_watch_action'])) {
    validateCSRFToken($_POST['csrf_token']);

    $sw_source = (string) ($_POST['sw_source'] ?? '');
    $sw_id = intval($_POST['sw_id'] ?? 0);
    $sw_action = (string) $_POST['sync_watch_action'];
    if (!WatchOverrides::ready($mysqli)) {
        flash_alert('Run the database update first (Admin > Update): the sync-watch table is not there yet.', 'error');
        redirect();
    }
    // Only an integration the watcher really watches can be controlled (never the endpoint agent, which has no vendor API).
    $sw_target = null;
    foreach (SyncWatch::inspect($mysqli, true) as $w) {
        if ($w['source'] === $sw_source && $w['id'] === $sw_id) {
            $sw_target = $w;
        }
    }
    if ($sw_target === null) {
        flash_alert('That integration is not on the sync watch.', 'error');
        redirect();
    }
    $sw_label = $sw_target['label'] . ' ' . $sw_target['name'];
    $sw_uid = intval($session_user_id ?? 0);
    $sw_done = '';
    if ($sw_action === 'mute' || $sw_action === 'unmute') {
        if (WatchOverrides::setMuted($mysqli, $sw_source, $sw_id, $sw_action === 'mute', $sw_uid)) {
            $sw_done = $sw_action === 'mute' ? "muted the sync watch for $sw_label" : "unmuted the sync watch for $sw_label";
        }
    } elseif ($sw_action === 'forget') {
        if (WatchOverrides::forgetHistory($mysqli, $sw_source, $sw_id, $sw_uid)) {
            $sw_done = "cleared the sync history seen by the watch for $sw_label";
        }
    } elseif ($sw_action === 'interval') {
        $sw_val = trim((string) ($_POST['sw_interval'] ?? ''));
        $sw_note = (string) ($_POST['sw_note'] ?? '');
        if ($sw_val === '') {
            if (WatchOverrides::setInterval($mysqli, $sw_source, $sw_id, null, $sw_note, $sw_uid)) {
                $sw_done = "removed the expected-interval override for $sw_label";
            }
        } else {
            $mult = ['m' => 60, 'h' => 3600, 'd' => 86400][(string) ($_POST['sw_unit'] ?? 'm')] ?? 0;
            $secs = $mult * intval($sw_val);
            if ($mult === 0 || intval($sw_val) < 1 || !WatchOverrides::setInterval($mysqli, $sw_source, $sw_id, $secs, $sw_note, $sw_uid)) {
                flash_alert('The expected interval must be between 1 minute and 90 days.', 'error');
                redirect();
            }
            $sw_done = "set the expected sync interval for $sw_label to " . WatchOverrides::describe($secs);
        }
    }
    if ($sw_done === '') {
        flash_alert('Nothing was changed.', 'error');
        redirect();
    }
    logAction('Settings', 'Edit', "$session_name $sw_done");
    if (!function_exists('rivetAudit') && is_file(dirname(__DIR__, 2) . '/includes/event_bus.php')) {
        require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
    }
    if (function_exists('rivetAudit')) {
        rivetAudit('recovery.sync_watch_changed', $sw_uid, 'integration', $sw_id, $sw_action, "$session_name $sw_done", ['source' => $sw_source, 'integration_id' => $sw_id, 'action' => $sw_action]);
    }
    flash_alert(ucfirst($sw_done));
    redirect();
}
