<?php

/*
 * RivetIT - Training automation worker (LMS Phase 5, spec §6.1).
 *
 *   php cron/training_worker.php --task=odoo|daily|discover [--dry-run] [--force] [--limit=N]
 *
 * Its OWN script, deliberately NOT cron/cron.php: this vhost shares the MSP's SMTP relay, and cron.php
 * would send real mail. There is no mail code here or in anything it calls; people are told in-app only
 * (Automation\Notify). Every task is a class_exists-guarded call into the lane that owns it:
 *
 *   odoo      every 10 minutes: Odoo write-back (OdooSync\PushService). Write-back is OFF until an admin
 *             enables it in Admin > Training > Automation; while it is off this prints nothing.
 *   daily     once per local day (a second run the same day exits silently unless --force), after P2's
 *             05:15 training_cron:
 *               0 P2 reconcile when its last run is over 20 h old (only while reminders are on: it exists so
 *                 digests see fresh assignments; skipped on --dry-run)
 *               1 external video watch (Reminders\VideoWatch) when "Video checks" is on (it only alerts)
 *               2 reminder digests + escalation (Reminders\ReminderService; it checks its own switch and weekday)
 *               3 Odoo key-expiry warning (Reminders\KeyExpiryCheck)
 *               4 prune the public verify throttle store (Certificates\VerifyThrottle::prune)
 *               5 stamp the day (not on --dry-run)
 *             Each step runs in its own try/catch: a failure is logged (logApp) and the run carries on.
 *             Prints one summary line when something is enabled (always on --dry-run); with reminders,
 *             video checks and write-back all off it only stamps the day and prints nothing.
 *   discover  read-only Odoo discovery against the configured integration, printed as JSON (ops). Stores nothing.
 *
 * Gates, in order: CLI only; module toggle off => exit 0 silently; Phase 5 tables missing (before DB 2.6.96)
 * => exit 0 silently; one runner per task (flock). Must run as www-data (config.php is www-data 0640 and the
 * lock file must be openable by the next run).
 *
 * Exit codes: 0 ok or silent; 1 bootstrap or lock error; 2 a step failed.
 *
 * Schedule (ops; /etc/cron.d/mw-itflow-training-worker, root 0644):
 *   *\/10 * * * * www-data /usr/bin/php /var/www/mw-itflow.foleyit.com/cron/training_worker.php --task=odoo >> /var/log/itflow_mw_training_worker.log 2>&1
 *   40 5 * * * www-data /usr/bin/php /var/www/mw-itflow.foleyit.com/cron/training_worker.php --task=daily >> /var/log/itflow_mw_training_worker.log 2>&1
 */

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$tw_opts = getopt('', ['task:', 'dry-run', 'force', 'limit:', 'help']);
$tw_task = is_string($tw_opts['task'] ?? null) ? $tw_opts['task'] : '';
if (isset($tw_opts['help']) || !in_array($tw_task, ['odoo', 'daily', 'discover'], true)) {
    fwrite(STDERR, "usage: php training_worker.php --task=odoo|daily|discover [--dry-run] [--force] [--limit=N]\n");
    exit(isset($tw_opts['help']) ? 0 : 1);
}
$tw_dry = isset($tw_opts['dry-run']);
$tw_force = isset($tw_opts['force']);
$tw_limit = null;
if (isset($tw_opts['limit'])) {
    if (!is_string($tw_opts['limit']) || preg_match('/^[1-9]\d{0,3}$/D', $tw_opts['limit']) !== 1) {
        fwrite(STDERR, "--limit must be 1 to 9999\n");
        exit(1);
    }
    $tw_limit = (int) $tw_opts['limit'];
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\WorkerCtx;
use ITFlow\Training\Upstream\ComplianceGateway;
use ITFlow\Training\Upstream\Schema;

/** One log line, UTC-stamped like training_cron.php. */
function tw_say(string $task, string $line): void
{
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_worker $task: " . str_replace(["\r", "\n"], ' ', $line) . "\n";
}

// ---- gates: module on, Phase 5 schema present (both silent) ------------------------------------------------
try {
    $tw_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_training FROM settings WHERE company_id = 1"));
} catch (\Throwable $e) {
    exit(0);
}
if (!$tw_row || intval($tw_row['config_module_enable_training']) !== 1) {
    exit(0);
}
if (!class_exists(Schema::class) || !Schema::has($mysqli, Schema::P5)) {
    exit(0);
}

// ---- one runner per task -------------------------------------------------------------------------------------
$tw_lock_path = sys_get_temp_dir() . '/itflow_training_worker_' . $tw_task . '_' . md5(__DIR__) . '.lock';
$tw_lock = @fopen($tw_lock_path, 'c');
if ($tw_lock === false) {
    // Not contention: the file belongs to another OS user (fs.protected_regular). Say so instead of skipping forever.
    tw_say($tw_task, "ERROR cannot open $tw_lock_path (created by another OS user? run as www-data)");
    exit(1);
}
if (!flock($tw_lock, LOCK_EX | LOCK_NB)) {
    tw_say($tw_task, 'another run holds the lock, skipping');
    exit(0);
}

try {
    $tw_ctx = WorkerCtx::build($mysqli, (string) ($config_base_url ?? ''));
    $tw_notify = new Notify($mysqli);
} catch (\Throwable $e) {
    tw_say($tw_task, 'ERROR could not build the worker context: ' . get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

$tw_exit = 0;
$tw_today = date('Y-m-d');

// ================================================================================================================
// odoo: write-back
// ================================================================================================================
if ($tw_task === 'odoo') {
    $tw_class = 'ITFlow\\Training\\OdooSync\\PushService';
    if (!class_exists($tw_class)) {
        exit(0);   // lane not installed: nothing to do, nothing to say
    }
    try {
        $tw_r = (new $tw_class($tw_ctx, null, null, $tw_notify))->run(['limit' => $tw_limit ?? 25, 'budget_s' => 150, 'dry_run' => $tw_dry]);
        $tw_paused = is_array($tw_r) ? ($tw_r['paused'] ?? null) : null;
        $tw_line = is_array($tw_r) ? trim((string) ($tw_r['line'] ?? '')) : '';
        // A disabled push is silent; anything it did or tried (including a pause) is one line.
        if ($tw_paused !== 'disabled' && $tw_line !== '') {
            $tw_line = (string) preg_replace('/^odoo:\s*/', '', $tw_line);   // tw_say() already names the task
            tw_say('odoo', ($tw_dry && stripos($tw_line, 'dry run') === false ? '[dry-run] ' : '') . $tw_line);
        }
    } catch (\Throwable $e) {
        tw_say('odoo', 'ERROR ' . get_class($e) . ': ' . $e->getMessage());
        logApp('Training', 'error', 'Training worker (odoo) failed: ' . get_class($e) . ': ' . $e->getMessage());
        $tw_exit = 2;
    }
    exit($tw_exit);
}

// ================================================================================================================
// discover: read-only, printed, stores nothing
// ================================================================================================================
if ($tw_task === 'discover') {
    $tw_target_class = 'ITFlow\\Training\\OdooSync\\Target';
    $tw_disc_class = 'ITFlow\\Training\\OdooSync\\Discovery';
    if (!class_exists($tw_target_class) || !class_exists($tw_disc_class)) {
        echo json_encode(['error' => 'odoo_writeback_not_installed'], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    try {
        $tw_target = $tw_target_class::current($mysqli);
        if ($tw_target === null) {
            echo json_encode(['error' => 'no_enabled_odoo_integration'], JSON_PRETTY_PRINT) . "\n";
            exit(0);
        }
        if (!$tw_target->https) {
            // Spec §8: the legacy connector does not enforce https; never send the key in clear.
            echo json_encode(['error' => 'Check Odoo needs an https:// Odoo address.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit(2);
        }
        $tw_result = (new $tw_disc_class($tw_target, $tw_target->connector()))->run();
        echo json_encode($tw_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    } catch (\Throwable $e) {
        echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(2);
    }
    exit(0);
}

// ================================================================================================================
// daily
// ================================================================================================================
$tw_s = AutomationSettings::loadWorker($mysqli);
if (!$tw_s['ready']) {
    exit(0);
}
if (!$tw_force && !$tw_dry && ($tw_s['tauto_daily_last_run_on'] ?? null) === $tw_today) {
    exit(0);   // already ran today
}

$tw_reminders_on = intval($tw_s['tauto_reminders_enabled']) === 1;
$tw_video_on = intval($tw_s['tauto_video_recheck_enabled']) === 1;
$tw_odoo_on = intval($tw_s['tauto_odoo_push_enabled']) === 1;
$tw_parts = [];
$tw_failed = [];

/** Runs one step; a throw is logged and recorded, never fatal. */
$tw_step = static function (string $name, callable $fn) use (&$tw_parts, &$tw_failed): void {
    try {
        $out = $fn();
        if ($out !== null && $out !== '') {
            $tw_parts[] = $out;
        }
    } catch (\Throwable $e) {
        $tw_failed[] = $name;
        $tw_parts[] = "$name ERROR";
        error_log("Training worker daily step $name: " . get_class($e) . ': ' . $e->getMessage());
        try {
            logApp('Training', 'error', "Training worker (daily, $name) failed: " . get_class($e) . ': ' . $e->getMessage());
        } catch (\Throwable) {
        }
    }
};

// 0. P2 reconcile guard (P2's own transactions and `trrec` lock; outside any Phase 5 transaction).
$tw_step('reconcile', static function () use ($tw_dry, $tw_reminders_on, $mysqli, $tw_ctx): ?string {
    if ($tw_dry || !$tw_reminders_on) {
        return null;
    }
    return 'reconcile ' . (new ComplianceGateway($mysqli))->reconcileIfStale($tw_ctx, 20);
});

// 1. External video watch (alerts only; never writes Phase 1's training_video_checks).
$tw_step('video', static function () use ($tw_video_on, $tw_ctx, $tw_notify, $tw_dry): ?string {
    $class = 'ITFlow\\Training\\Reminders\\VideoWatch';
    if (!$tw_video_on || !class_exists($class)) {
        return null;
    }
    $r = (new $class($tw_ctx, $tw_notify))->run(200, 90, $tw_dry);
    return sprintf('video checked %d, changed %d, bad %d', (int) ($r['checked'] ?? 0), (int) ($r['changed'] ?? 0), is_array($r['bad'] ?? null) ? count($r['bad']) : 0);
});

// 2. Reminder digests + escalation (checks its own switch, weekday and P2 availability).
$tw_step('reminders', static function () use ($tw_ctx, $tw_notify, $tw_dry, $tw_today, $tw_reminders_on): ?string {
    $class = 'ITFlow\\Training\\Reminders\\ReminderService';
    if (!class_exists($class)) {
        return null;
    }
    $r = (new $class($tw_ctx, $tw_notify))->run($tw_today, $tw_dry);
    if (!$tw_reminders_on && (int) ($r['sent'] ?? 0) === 0) {
        return null;
    }
    return sprintf('reminders sent %d, skipped %d', (int) ($r['sent'] ?? 0), (int) ($r['skipped'] ?? 0));
});

// 3. Odoo key-expiry warning (admins, once a day while it applies).
$tw_step('key_expiry', static function () use ($mysqli, $tw_notify, $tw_s, $tw_today, $tw_dry): ?string {
    $class = 'ITFlow\\Training\\Reminders\\KeyExpiryCheck';
    if ($tw_dry || !class_exists($class)) {
        return null;
    }
    return $class::run($mysqli, $tw_notify, $tw_s, $tw_today) ? 'key-expiry warned' : null;
});

// 4. Public verify throttle store (the worker is www-data like FPM, so it prunes FPM's store).
$tw_step('verify_prune', static function () use ($database, $tw_dry): ?string {
    $class = 'ITFlow\\Training\\Certificates\\VerifyThrottle';
    if ($tw_dry || !class_exists($class)) {
        return null;
    }
    $n = (int) $class::prune((string) $database);
    return $n > 0 ? "verify-throttle pruned $n" : null;
});

$tw_something_on = $tw_reminders_on || $tw_video_on || $tw_odoo_on;
$tw_summary = $tw_parts === [] ? 'nothing to do' : implode(' · ', $tw_parts);

// 5. Stamp the day (the admin card's "last daily run"); never on a dry run.
if (!$tw_dry) {
    try {
        AutomationSettings::stamp($mysqli, [
            'tauto_daily_last_run_on' => $tw_today,
            'tauto_daily_last_result' => mb_substr(($tw_failed ? 'FAILED ' . implode(',', $tw_failed) . ': ' : 'ok ') . $tw_summary, 0, 255),
        ]);
    } catch (\Throwable $e) {
        $tw_failed[] = 'stamp';
        error_log('Training worker daily stamp: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

if ($tw_dry || $tw_something_on || $tw_failed) {
    tw_say('daily', ($tw_dry ? '[dry-run] ' : '') . ($tw_failed ? 'FAILED ' . implode(',', $tw_failed) . ': ' : 'ok ') . $tw_summary);
}
exit($tw_failed ? 2 : 0);
