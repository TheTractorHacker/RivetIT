<?php

/*
 * RivetIT - nightly Odoo directory sync (Training Phase 2, S5).
 *
 * Deliberately NOT part of cron/cron.php, for the same reason cron/backup_cron.php and
 * cron/training_cron.php are not: this vhost shares its SMTP relay and client data with the live
 * MSP install, so the full cron.php is not scheduled here. This script does exactly one thing:
 * the same Odoo directory sync as Admin > Directory Sync > "Sync now", through
 * ITFlow\Training\Directory\DirectorySyncRunner::runOdoo(), which applies the same row selection
 * (latest integration, must be enabled), the Odoo target guard (plan A22), the `trodoo` lock and
 * the 60-second running guard, then the Training extension (link states, attributes) and a
 * reconcile when the Training module is on.
 *
 * INERT BY DEFAULT: it does nothing until an admin turns on "Nightly Odoo directory sync" under
 * Admin > Training > Employee links (Odoo) (settings.config_training_odoo_sync_enabled, default 0).
 *
 * Schedule (ops, Phase 2 spec §6.3): /etc/cron.d/mw-itflow-training
 *   30 4 * * * www-data /usr/bin/php /var/www/mw-itflow.foleyit.com/cron/odoo_sync_cron.php >> /var/log/itflow_mw_odoo_sync.log 2>&1
 * Runs once per LOCAL day (config_training_odoo_sync_last_on) unless --force. Writes
 * config_training_odoo_sync_last_on / _last_result. On a failure (including a changed Odoo
 * target) it writes an app log line and notifies every admin once - only when the result line
 * differs from the previous run's, so a standing problem does not alert every night.
 * Must run as www-data (config.php is www-data 0640). Prints one line per run.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Directory\DirectorySyncRunner;

$force = in_array('--force', $argv ?? [], true);
$os_now = static fn(): string => gmdate('Y-m-d\TH:i:s\Z');

// The toggle (2.6.92). A missing column means the Phase 2 update has not run: nothing to do.
try {
    $os_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_training_odoo_sync_enabled, config_training_odoo_sync_last_on,
        config_training_odoo_sync_last_result FROM settings WHERE company_id = 1"));
} catch (\Throwable $e) {
    echo $os_now() . " odoo_sync_cron: disabled (schema)\n";
    exit(0);
}
if (!$os_row || intval($os_row['config_training_odoo_sync_enabled']) !== 1) {
    echo $os_now() . " odoo_sync_cron: disabled (Admin > Training > Employee links (Odoo))\n";
    exit(0);
}

// One runner at a time (a manual --force run while the nightly one is going).
$os_lock_path = sys_get_temp_dir() . '/itflow_odoo_sync_cron_' . md5(__DIR__) . '.lock';
$os_lock = @fopen($os_lock_path, 'c');
if ($os_lock === false) {
    // Not contention: the file exists but belongs to another OS user (fs.protected_regular).
    echo $os_now() . " odoo_sync_cron: ERROR cannot open $os_lock_path (created by another OS user? run as www-data)\n";
    exit(1);
}
if (!flock($os_lock, LOCK_EX | LOCK_NB)) {
    echo $os_now() . " odoo_sync_cron: another run holds the lock, skipping\n";
    exit(0);
}

// Once per LOCAL day unless --force.
$os_today = Clock::todayLocal();
if (!$force && (string) ($os_row['config_training_odoo_sync_last_on'] ?? '') === $os_today) {
    flock($os_lock, LOCK_UN);
    fclose($os_lock);
    exit(0);
}

try {
    $os_result = DirectorySyncRunner::runOdoo($mysqli, 0);
} catch (\Throwable $e) {
    $os_result = ['ok' => false, 'protocol' => null, 'dept' => null, 'emp' => null, 'training' => null,
        'error' => 'exception', 'message' => get_class($e) . ': ' . $e->getMessage()];
}
$os_line = DirectorySyncRunner::line($os_result);   // plain text, ≤ 255
$os_previous = (string) ($os_row['config_training_odoo_sync_last_result'] ?? '');

try {
    $os_upd = mysqli_prepare($mysqli, 'UPDATE settings SET config_training_odoo_sync_last_on = ?, config_training_odoo_sync_last_result = ? WHERE company_id = 1');
    mysqli_stmt_bind_param($os_upd, 'ss', $os_today, $os_line);
    mysqli_stmt_execute($os_upd);
    mysqli_stmt_close($os_upd);
} catch (\Throwable $e) {
    logApp('Cron', 'error', 'Odoo sync cron could not store its result: ' . $e->getMessage());
}

if (empty($os_result['ok'])) {
    logApp('Cron', 'error', 'Nightly Odoo directory sync failed: ' . $os_line);
    if ($os_line !== $os_previous) {
        // Notifications and toasts render HTML; the line can quote Odoo's own error text.
        $os_text = nullable_htmlentities('Nightly Odoo directory sync failed: ' . $os_line);
        try {
            foreach (\ITFlow\Training\Directory\OdooTrainingSync::adminUserIds($mysqli) as $os_uid) {
                notifyUser($os_uid, 'Directory Sync', $os_text, '/admin/settings_training.php#odoo-sync');
            }
        } catch (\Throwable $e) {
            logApp('Cron', 'error', 'Odoo sync cron could not notify admins: ' . $e->getMessage());
        }
    }
}

echo $os_now() . ' odoo_sync_cron: ' . $os_line . "\n";

flock($os_lock, LOCK_UN);
fclose($os_lock);
exit(empty($os_result['ok']) ? 1 : 0);
