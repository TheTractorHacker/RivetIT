<?php

/*
 * ITFlow Internal IT - standalone auto-backup runner.
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

$sql_companies = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
$settings_row = mysqli_fetch_assoc($sql_companies);

$config_backup_auto_enabled = intval($settings_row['config_backup_auto_enabled'] ?? 0);
$config_backup_frequency    = $settings_row['config_backup_frequency'] ?? 'daily';
$config_backup_retain_count = max(1, intval($settings_row['config_backup_retain_count'] ?? 7));

if (!$config_backup_auto_enabled) {
    exit(0);
}

$backup_dir = dirname(__DIR__) . '/backups';
$should_run = false;

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
    exit(0);
}

// backup.php guards against direct HTTP access - mark this as an authorized
// include, same technique cron/cron.php's own (unscheduled, on this vhost)
// AUTO-BACKUP block already used.
if (!defined('FROM_POST_HANDLER')) {
    define('FROM_POST_HANDLER', true);
}
require_once dirname(__DIR__) . '/admin/post/backup.php';

$result = build_backup($mysqli, 'auto', $backup_dir);
prune_backups($backup_dir, $config_backup_retain_count);
logApp('Backup', 'info', "Auto-backup completed: {$result['name']}");
appNotify('Backup', "Auto-backup saved: {$result['name']}", '/admin/backup.php');

// Remote (S3-compatible) upload, if configured - see Admin > Backup > Remote Storage.
if (function_exists('backup_upload_to_s3')) {
    backup_upload_to_s3($result['path'], $result['name']);
}
