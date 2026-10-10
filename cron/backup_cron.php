<?php

/*
 * RivetIT - standalone auto-backup runner.
 *
 * Deliberately NOT part of cron/cron.php. This vhost shares its SMTP relay
 * and client data with the live MSP install (see /etc/cron.d/mw-itflow-metrics's
 * own comment on the same subject) - scheduling the FULL cron.php here would
 * send real mail (CSAT surveys, invoice reminders, etc.) from the internal-IT
 * app using that shared relay. This script does exactly one thing: check
 * whether an auto-backup is due (Admin > Backup > Scheduled Backups) and, if
 * so, build one and prune old ones. No email-sending code is required or
 * reachable from here, by construction - not just "not called this run."
 *
 * Mirrors cron/cron.php's own AUTO-BACKUP block (same settings, same
 * once-per-day/once-per-week due check, same build_backup()/prune_backups()
 * calls) so behavior is identical to what that block would do if it ran -
 * this is a narrower runner, not different logic.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('backup_cron', 3600);

require_once "../includes/backup_cron_settings.php";

$sql_companies = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
$settings_row = mysqli_fetch_assoc($sql_companies);
rivetit_load_backup_cron_settings($settings_row);

$config_backup_auto_enabled = intval($settings_row['config_backup_auto_enabled'] ?? 0);
$config_backup_frequency    = $settings_row['config_backup_frequency'] ?? 'daily';
$config_backup_retain_count = max(1, intval($settings_row['config_backup_retain_count'] ?? 7));

// The main dispatcher already builds due auto-backups when it is enabled.
// Avoid a second runner racing it to create the same daily/weekly backup.
if (intval($settings_row['config_enable_cron'] ?? 0) !== 0) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " backup_cron: main cron handles auto-backups, skipping\n";
    exit(0);
}

if (!$config_backup_auto_enabled) {
    // A one-line heartbeat on every exit path (same convention as
    // cron/training_kiosk_cron.php), not just when a backup is actually
    // built below - without this, /var/log/itflow_mw_backup.log stays
    // byte-for-byte empty forever even on a perfectly healthy install,
    // since every prior exit path here was silent. An empty, unchanging
    // logfile and a genuinely-stuck cron job used to look identical from
    // the outside (see project history: a missing logfile silently
    // stopped a different cron job for days before anyone noticed); this
    // makes "the job is alive and just has nothing to do" distinguishable
    // from "the job stopped running" by tailing the log's timestamps.
    echo gmdate('Y-m-d\TH:i:s\Z') . " backup_cron: auto-backup disabled (Admin > Backup > Scheduled Backups), skipping\n";
    exit(0);
}

$backup_dir = dirname(__DIR__) . '/backups';
$should_run = false;
// Backup files keep the pre-RivetIT name itflow_<YmdHis>_<manual|auto>.zip on purpose: the Backup
// page lists, prunes and serves downloads by that exact pattern (admin/post/backup.php).

if ($config_backup_frequency === 'daily') {
    // Run if no auto-backup exists from today
    $today_pattern = $backup_dir . '/itflow_' . date('Ymd') . '*_auto.zip';
    $should_run    = empty(glob($today_pattern));
} elseif ($config_backup_frequency === 'weekly') {
    // Run if no auto-backup exists from this ISO week
    $week_prefix   = date('YW');
    $all_auto      = glob($backup_dir . '/itflow_*_auto.zip') ?: [];
    $has_this_week = false;
    foreach ($all_auto as $f) {
        $fdate = substr(basename($f), 7, 8); // YYYYMMDD portion
        if (date('YW', mktime(0, 0, 0, substr($fdate, 4, 2), substr($fdate, 6, 2), substr($fdate, 0, 4))) === $week_prefix) {
            $has_this_week = true;
            break;
        }
    }
    $should_run = !$has_this_week;
}

if (!$should_run) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " backup_cron: an auto-backup already exists for this {$config_backup_frequency} period, skipping\n";
    exit(0);
}

// backup.php guards against direct HTTP access - mark this as an authorized
// include, same technique cron/cron.php's own (unscheduled, on this vhost)
// AUTO-BACKUP block already used.
if (!defined('FROM_POST_HANDLER')) {
    define('FROM_POST_HANDLER', true);
}
require_once dirname(__DIR__) . '/admin/post/backup.php';

try {
    $result = build_backup($mysqli, 'auto', $backup_dir);
} catch (BackupPassphraseRequired $e) {
    // No usable backup passphrase: refuse loudly instead of writing a backup that cannot protect the settings key.
    logApp('Backup', 'error', 'Auto-backup refused: ' . $e->getMessage());
    appNotify('Backup', 'Automatic backup was NOT taken: set a backup passphrase (16+ characters) in Admin > Backup.', '/admin/backup.php');
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . ' backup_cron: refused - ' . $e->getMessage() . "\n");
    exit(1);
}
prune_backups($backup_dir, $config_backup_retain_count);
logApp('Backup', 'info', "Auto-backup completed: {$result['name']}");
appNotify('Backup', "Auto-backup saved: {$result['name']}", '/admin/backup.php');

// Remote (S3-compatible) upload, if configured - see Admin > Backup > Remote Storage.
$s3_uploaded = null;
if (function_exists('backup_upload_to_s3')) {
    $s3_uploaded = backup_upload_to_s3($result['path'], $result['name']);
}

echo gmdate('Y-m-d\TH:i:s\Z') . " backup_cron: built {$result['name']}"
    . ($s3_uploaded === null ? '' : ($s3_uploaded ? ', uploaded to S3' : ', S3 upload FAILED'))
    . "\n";
