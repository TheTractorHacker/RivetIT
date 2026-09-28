<?php

/*
 * RivetIT - Training kiosk housekeeping (P3 spec §6, P-9). Every 10 minutes.
 *
 * Standalone for the same reason as cron/training_cron.php and backup_cron.php: cron/cron.php
 * is not scheduled on this vhost (it shares its SMTP relay and client data with the MSP install).
 *
 * Every run:
 *   0. Revoke temporary devices whose time is up (DeviceLifecycle::sweep, 2.6.94): reason "Temporary
 *      device expired", kiosk.revoked + ksession.end and an audit row, exactly like a manual revoke
 *      but with the system as the actor. (A device that is used first is revoked on that request.)
 *   1. End open kiosk sessions past idle + 30 s or past their absolute cap (ksession.end, actor system).
 *   2. AttemptFinalizer::finalizeExpired($mysqli, null, 200)                 [lane K3, when present]
 *   3. RunService::settleAwaiting for every contact with an awaiting_* run or an open run
 *      on an archived course                                                   [lane K3, when present]
 *   4. Null expired device enroll codes and PIN setup tokens.
 *   5. Delete training_rate_buckets rows older than 2 days.
 * Once per local day after 03:00 (stamp file next to the lock; --force runs them now):
 *   - PinSourceSync::run (one Odoo read; skipped while config_training_odoo_pin_enabled is off) [K2]
 *   - AwardEngine::backfill() and AwardEngine::nightlyStreaks()                                 [K6]
 *
 * Schedule (ops, after live verification):
 *   sudo install -o www-data -g adm -m 0640 /dev/null /var/log/itflow_mw_training_kiosk.log
 *   /etc/cron.d/mw-itflow-training-kiosk:
 *   0-59/10 * * * * www-data /usr/bin/php /var/www/mw-itflow.foleyit.com/cron/training_kiosk_cron.php >> /var/log/itflow_mw_training_kiosk.log 2>&1
 *
 * Exits silently when the Training module is off or the 2.6.94 schema is not there. All UTC
 * comparisons bind literals computed in PHP (§0.11). Errors are logged by class only for the
 * PIN/Odoo step (§0.12). Prints one summary line per run.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Achievements\AwardEngine;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Device\DeviceLifecycle;
use ITFlow\Training\Kiosk\Learn\AttemptFinalizer;
use ITFlow\Training\Kiosk\Learn\RunService;
use ITFlow\Training\Kiosk\Pin\OdooPinVerifier;
use ITFlow\Training\Kiosk\Pin\PinSourceSync;

$force = in_array('--force', $argv ?? [], true);

$tk_ks = KioskSettings::fromDb($mysqli);
if (!$tk_ks->moduleEnabled || !$tk_ks->schemaReady) {
    exit(0);
}

$tk_state_base = sys_get_temp_dir() . '/itflow_training_kiosk_cron_' . md5(__DIR__);
$tk_lock = @fopen($tk_state_base . '.lock', 'c');
if ($tk_lock === false) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_kiosk_cron: ERROR cannot open the lock file (created by another OS user? run as www-data)\n";
    exit(1);
}
if (!flock($tk_lock, LOCK_EX | LOCK_NB)) {
    echo gmdate('Y-m-d\TH:i:s\Z') . " training_kiosk_cron: another run holds the lock, skipping\n";
    exit(0);
}

$tk_system = ['actor_type' => 'system', 'user_agent' => KioskCtx::SYSTEM_UA];
$tk_summary = [];
$tk_errors = 0;

/** Runs one step; a failure is counted and logged (class, plus the message unless $quiet) and the run goes on. */
function tk_step(string $name, callable $fn, bool $quiet = false): mixed
{
    global $tk_errors;
    try {
        return $fn();
    } catch (\Throwable $e) {
        $tk_errors++;
        error_log('training_kiosk_cron ' . $name . ': ' . get_class($e) . ($quiet ? '' : ': ' . $e->getMessage()));
        return null;
    }
}

// ---- 0. temporary devices whose time is up ------------------------------------------------------
$tk_summary[] = 'devices_expired=' . (int) tk_step('devices', static fn() => DeviceLifecycle::sweep($mysqli, 200));

// ---- 1. expired kiosk sessions ---------------------------------------------------------------
$tk_summary[] = 'ended=' . (int) tk_step('sessions', static function () use ($mysqli, $tk_system): int {
    $now = KTime::now();
    $n = 0;
    $after = 0;
    do {
        $rows = Db::all($mysqli, 'SELECT ksess_id, ksess_last_seen_at_utc, ksess_idle_limit_s, ksess_absolute_until_utc
            FROM training_kiosk_sessions WHERE ksess_open_guard = 1 AND ksess_id > ? ORDER BY ksess_id LIMIT 200', 'i', [$after]);
        foreach ($rows as $r) {
            $after = (int) $r['ksess_id'];
            $reason = null;
            if ((KTime::epoch($r['ksess_absolute_until_utc']) ?? 0) < (KTime::epoch($now) ?? 0)) {
                $reason = 'absolute';
            } elseif ((KTime::epoch($r['ksess_last_seen_at_utc']) ?? 0) + (int) $r['ksess_idle_limit_s'] + KioskAuth::IDLE_GRACE_S < (KTime::epoch($now) ?? 0)) {
                $reason = 'idle';
            }
            if ($reason !== null && KioskAuth::endSessionAs($mysqli, (int) $r['ksess_id'], $reason, $tk_system)) {
                $n++;
            }
        }
    } while (count($rows) === 200);
    return $n;
});

// ---- 2. + 3. lazy learner housekeeping (lane K3) -----------------------------------------------
$tk_summary[] = 'finalized=' . (int) tk_step('finalize', static fn() => AttemptFinalizer::finalizeExpired($mysqli, null, 200, $tk_system));
$tk_summary[] = 'settled=' . (int) tk_step('settle', static function () use ($mysqli): int {
    $cids = Db::all($mysqli, "SELECT DISTINCT r.trun_contact_id AS cid FROM training_runs r
          JOIN training_courses c ON c.course_id = r.trun_course_id
         WHERE r.trun_open_guard = 1
           AND (r.trun_status IN ('awaiting_signature','awaiting_session','awaiting_evaluation') OR c.course_archived_at IS NOT NULL)
         ORDER BY r.trun_contact_id LIMIT 500");
    if ($cids === []) {
        return 0;
    }
    $svc = new RunService(KioskCtx::system($mysqli, (string) ($GLOBALS['config_settings_enc_key'] ?? '')));
    $n = 0;
    foreach ($cids as $r) {
        tk_step('settle#' . (int) $r['cid'], static function () use ($svc, $r, &$n): void {
            $svc->settleAwaiting((int) $r['cid']);
            $n++;
        });
    }
    return $n;
});

// ---- 4. expired enroll codes and setup tokens --------------------------------------------------
$tk_summary[] = 'expired=' . (int) tk_step('expire', static function () use ($mysqli): int {
    $now = KTime::now();
    $a = Db::exec($mysqli, 'UPDATE training_kiosks SET kiosk_enroll_code_hash = NULL, kiosk_enroll_expires_at_utc = NULL
        WHERE kiosk_enroll_code_hash IS NOT NULL AND kiosk_enroll_expires_at_utc < ?', 's', [$now]);
    $b = Db::exec($mysqli, 'UPDATE training_learner_credentials SET tcred_setup_token_hash = NULL, tcred_setup_token_expires_at_utc = NULL
        WHERE tcred_setup_token_hash IS NOT NULL AND tcred_setup_token_expires_at_utc < ?', 's', [$now]);
    return (int) $a + (int) $b;
});

// ---- 5. old rate buckets ---------------------------------------------------------------------
$tk_summary[] = 'buckets=' . (int) tk_step('buckets', static function () use ($mysqli): int {
    return (int) Db::exec($mysqli, 'DELETE FROM training_rate_buckets WHERE trate_window_start_utc < ?', 's',
        [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
});

// ---- nightly (once per local day after 03:00, or --force) ---------------------------------------
$tk_today = date('Y-m-d');
$tk_stamp_path = $tk_state_base . '.stamp';
$tk_stamp = @file_get_contents($tk_stamp_path);
$tk_nightly = $force || ((int) date('G') >= 3 && trim((string) $tk_stamp) !== $tk_today);
if ($tk_nightly) {
    if ($tk_ks->odooPinEnabled) {
        $tk_r = tk_step('pin_sources', static fn() => PinSourceSync::run($mysqli, new OdooPinVerifier($mysqli, $tk_ks), $tk_system), true);
        $tk_summary[] = 'pin_sources=' . (is_array($tk_r) ? (int) ($tk_r['changed'] ?? 0) . ' changed' : 'skipped');
    } else {
        $tk_summary[] = 'pin_sources=off';
    }
    $tk_summary[] = 'backfill=' . (int) tk_step('backfill', static fn() => AwardEngine::backfill($mysqli));
    $tk_summary[] = 'streaks=' . (int) tk_step('streaks', static fn() => AwardEngine::nightlyStreaks($mysqli));
    if (!$force || (int) date('G') >= 3) {
        @file_put_contents($tk_stamp_path, $tk_today . "\n");
        @chmod($tk_stamp_path, 0600);
    }
}

echo gmdate('Y-m-d\TH:i:s\Z') . ' training_kiosk_cron: ' . implode(' ', $tk_summary) . ($tk_nightly ? ' nightly' : '')
    . ($tk_errors > 0 ? " ERRORS=$tk_errors" : '') . "\n";
flock($tk_lock, LOCK_UN);
exit($tk_errors > 0 ? 1 : 0);
