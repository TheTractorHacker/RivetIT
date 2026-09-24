<?php

/*
 * ITFlow Internal IT - Training (LMS) nightly runner.
 *
 * Deliberately NOT part of cron/cron.php, for the same reason cron/backup_cron.php is not:
 * this vhost shares its SMTP relay and client data with the live MSP install, so the full
 * cron.php is not scheduled here. This script does only training housekeeping:
 *
 *   1. Ledger verify (plan A8/A12): walks the hash chain and every hashed training table.
 *      Shallow on weekdays, --deep (re-hash every media file) on Sundays (UTC).
 *      Writes config_training_ledger_verified_at_utc / config_training_ledger_verify_result
 *      ("ok #<seq>/<hash16>" or "BREAK #<seq> <kind>"). On a NEW break signature it notifies
 *      every admin, writes an app log line and an audit event - once, not every night.
 *   2. Sweeps abandoned upload temp files (uploads/training/<dir>/.<rand>.tmp older than 1 h)
 *      left behind if a request died between copy and rename (spec §4.2).
 *
 * Schedule (ops, once Phase 1 is verified): /etc/cron.d/mw-itflow-training
 *   15 5 * * * www-data /usr/bin/php /var/www/mw-itflow.foleyit.com/cron/training_cron.php >> /var/log/itflow_mw_training.log 2>&1
 * Runs once per UTC day (a second run the same day exits silently) unless --force. "Ran today"
 * is a stamp only THIS runner writes (next to its lock file), never the settings columns:
 * Admin > Training > Verify now writes those too, and a click in the evening (already the next
 * UTC day from 19:00 CDT) must not cancel that night's run or Sunday's deep one. On a Sunday a
 * shallow stamp does not count; only a deep run does. --deep asks for a deep verify on any day
 * (and, like Sunday, is not satisfied by an earlier shallow run the same day).
 * Must run as www-data (config.php and media files are www-data 0640; the lock and stamp are
 * created by whoever runs first).
 * Exits silently when the Training module is off or the 2.6.91 schema is not there yet.
 * Prints one summary line per run.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Core\LedgerVerifier;

$force = in_array('--force', $argv ?? [], true);
$deep_requested = in_array('--deep', $argv ?? [], true);

// Module toggle + schema presence. A missing column (pre-2.6.91) means "off".
try {
    $tr_settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_training FROM settings WHERE company_id = 1"));
} catch (\Throwable $e) {
    exit(0);
}
if (!$tr_settings || intval($tr_settings['config_module_enable_training']) !== 1) {
    exit(0);
}

// One runner at a time (a manual --force run while the nightly one is going).
$tr_state_base = sys_get_temp_dir() . '/itflow_training_cron_' . md5(__DIR__);
$tr_lock_path = $tr_state_base . '.lock';
$tr_lock = @fopen($tr_lock_path, 'c');
if ($tr_lock === false) {
    // Not contention: the file exists but belongs to another OS user (fs.protected_regular
    // refuses O_CREAT on someone else's file in /tmp). Say so instead of "skipping" forever.
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_cron: ERROR cannot open $tr_lock_path (created by another OS user? run as www-data)\n";
    exit(1);
}
if (!flock($tr_lock, LOCK_EX | LOCK_NB)) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_cron: another run holds the lock, skipping\n";
    exit(0);
}

// Once per UTC day unless --force, keyed on this runner's own stamp ("<Y-m-d> deep|shallow").
$tr_today_utc = gmdate('Y-m-d');
$tr_deep = $deep_requested || gmdate('w') === '0';
$tr_stamp_path = $tr_state_base . '.stamp';
if (!$force) {
    $tr_stamp = @file_get_contents($tr_stamp_path);
    if (is_string($tr_stamp) && preg_match('/^(\d{4}-\d{2}-\d{2}) (deep|shallow)$/', trim($tr_stamp), $tr_m)
        && $tr_m[1] === $tr_today_utc && (!$tr_deep || $tr_m[2] === 'deep')) {
        exit(0);
    }
}

$tr_started = microtime(true);

// 1. Ledger verify ---------------------------------------------------------------------------
try {
    $tr_result = LedgerVerifier::verify($mysqli, ['deep' => $tr_deep]);
    $tr_record = LedgerVerifier::recordResult($mysqli, $tr_result);
} catch (\Throwable $e) {
    $tr_msg = 'Training ledger verify failed to run: ' . get_class($e) . ': ' . $e->getMessage();
    logApp('Training', 'error', $tr_msg);
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_cron: ERROR $tr_msg\n";
    exit(1);
}

// The run happened: stamp it (after the result is stored, so a run that died above is retried).
$tr_stamp_note = '';
if (@file_put_contents($tr_stamp_path, $tr_today_utc . ' ' . ($tr_deep ? 'deep' : 'shallow') . "\n", LOCK_EX) === false) {
    $tr_stamp_note = " (WARNING: could not write $tr_stamp_path)";
}

// A NEW break signature alerts once, from whichever path saw it first. Admin > Training >
// Verify now runs the same block (admin/post/settings_training.php), so a break an admin
// found first still reaches every admin.
if ($tr_record['new_break']) {
    $tr_first = $tr_result['breaks'][0];
    $tr_detail = $tr_record['line'] . ' - ' . $tr_first['detail'];
    logApp('Training', 'error', 'Training ledger verification found a break: ' . $tr_detail);
    $tr_admins = mysqli_query($mysqli, "SELECT users.user_id FROM users
        JOIN user_roles ON users.user_role_id = user_roles.role_id
        WHERE user_roles.role_is_admin = 1 AND users.user_type = 1 AND users.user_status = 1 AND users.user_archived_at IS NULL");
    while ($tr_admin = mysqli_fetch_assoc($tr_admins)) {
        notifyUser(intval($tr_admin['user_id']), 'Training', 'Training records integrity check found a problem: ' . $tr_record['line'] . '. Open Admin > Training for details.', '/admin/settings_training.php');
    }
    try {
        \ITFlow\Audit\AuditService::record('training.ledger_break', null, 'training_ledger', $tr_first['seq'], 'verify', $tr_record['line'], [
            'breaks' => array_slice($tr_result['breaks'], 0, 20),
            'head' => $tr_result['head'],
            'deep' => $tr_deep,
        ]);
    } catch (\Throwable $e) {
        logApp('Training', 'error', 'Could not write the ledger-break audit event: ' . $e->getMessage());
    }
}

// 2. Abandoned upload temp files -------------------------------------------------------------
$tr_swept = 0;
$tr_media_root = dirname(__DIR__) . '/uploads/training';
if (is_dir($tr_media_root)) {
    $tr_cutoff = time() - 3600;
    $tr_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tr_media_root, FilesystemIterator::SKIP_DOTS));
    foreach ($tr_it as $tr_file) {
        if ($tr_file->isFile() && !$tr_file->isLink() && preg_match('/^\..+\.tmp$/', $tr_file->getFilename()) && $tr_file->getMTime() < $tr_cutoff) {
            if (@unlink($tr_file->getPathname())) {
                $tr_swept++;
            }
        }
    }
}

printf("%s training_cron: ledger verify (%s) %s; checked %d events in %.1fs; swept %d temp file(s)%s\n",
    gmdate('Y-m-d\TH:i:s\Z'), $tr_deep ? 'deep' : 'shallow', $tr_record['line'], $tr_result['checked'],
    microtime(true) - $tr_started, $tr_swept, $tr_stamp_note);

flock($tr_lock, LOCK_UN);
fclose($tr_lock);
