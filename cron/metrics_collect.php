<?php

/*
 * ###############################################################################################################
 *  DEVICE METRICS COLLECTOR  (cron/metrics_collect.php)
 * ###############################################################################################################
 *
 *  Standalone CLI entry point for the device metrics subsystem. One run does three things, in order:
 *
 *    1. COLLECT  every enabled rmm_integrations row is turned into a metrics provider, that provider is asked
 *                for samples for the devices linked to it, and the samples are ingested.
 *    2. ROLLUP   raw samples are aggregated into hour buckets, then hour buckets into day buckets.
 *    3. PRUNE    raw samples and hour rollups past their retention horizon are deleted in chunks.
 *
 *  WHY THIS IS NOT PART OF cron/cron.php: that file is a 1,900-line straight-line script with no lock file and
 *  no per-block scheduling - every block runs on every invocation. Metrics wants a 5-minute cadence, a mutex,
 *  and its own failure isolation, none of which cron.php can provide without rewriting it. This script is
 *  wired into cron directly instead.
 *
 *  USAGE
 *      php cron/metrics_collect.php [--collect-only|--rollup-only|--prune-only] [--dry-run] [--help]
 *
 *      --collect-only   pull and ingest samples; skip rollups and retention
 *      --rollup-only    roll existing samples up; collect nothing, delete nothing
 *      --prune-only     apply retention only
 *      --dry-run        report what every phase WOULD do; write and delete nothing
 *
 *  CADENCE: built for a five-minute crontab entry. The collect interval is config_metrics_collect_interval_seconds
 *  (default 300) and is used only to size the stale-lock threshold - moving to a 1-minute cadence is a crontab
 *  change plus that setting, not a rewrite.
 *
 *  GATE: config_enable_device_metrics. Nothing runs when it is 0.
 *
 *  TIME: device_metric_samples.sampled_at, device_metric_rollups.period_start and the collection/rollup state
 *  timestamps are all UTC - a deliberate divergence from the rest of the application, which stores local time.
 *  The log lines this script prints use LOCAL time, matching /var/log/itflow_rmm_sync.log, and are the only
 *  local-time values here.
 *
 *  OWNERSHIP: this script never creates, updates or deletes an `assets` or `asset_rmm_links` row. Those belong
 *  exclusively to includes/class_rmm_asset_mapper.php, which runs from the RMM sync. Metrics reads them.
 */

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Composer autoloading is required EXPLICITLY: only includes/redis_functions.php pulls vendor/autoload.php in
// today, so nothing in the app's include graph can be relied on to have loaded the ITFlow\ namespace.
require_once dirname(__DIR__) . '/vendor/autoload.php';

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

use ITFlow\Metrics\MetricIngestService;
use ITFlow\Metrics\MetricRegistry;
use ITFlow\Metrics\MetricRetentionService;
use ITFlow\Metrics\MetricRollupService;
use ITFlow\Metrics\MetricSample;
use ITFlow\Metrics\MetricsProviderFactory;

/** =======================================================================
 *  Helpers
 * ======================================================================= */

/** One line to stdout, in the style of /var/log/itflow_rmm_sync.log. Local time, deliberately. */
function metricsCronOut(string $line): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
}

/**
 * Devices linked to one integration, in the shape MetricsProviderInterface::collect() expects.
 *
 * READ ONLY. asset_rmm_links is owned by RmmAssetMapper; this is a join, never a write.
 *
 * @return array{devices:array<int,array{asset_id:int,agent_id:string,hostname:string}>,unlinked:int}
 */
function metricsCronDevices(mysqli $mysqli, int $integration_id): array
{
    $devices  = [];
    $unlinked = 0;

    $result = mysqli_query(
        $mysqli,
        "SELECT arl.asset_id AS asset_id, arl.tactical_agent_id AS agent_id, arl.hostname AS hostname
           FROM asset_rmm_links arl
           JOIN assets a ON a.asset_id = arl.asset_id
          WHERE arl.integration_id = $integration_id
            AND a.asset_archived_at IS NULL
          ORDER BY arl.asset_id ASC"
    );

    if ($result === false) {
        return ['devices' => $devices, 'unlinked' => 0];
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $asset_id = intval($row['asset_id']);
        $agent_id = trim((string) ($row['agent_id'] ?? ''));

        // An agent id we do not have is not an error worth logging 26 times a run - the RMM sync's own
        // skipped= counter is where unmatched agents are reported. Count them and move on.
        if ($asset_id <= 0 || $agent_id === '') {
            $unlinked++;
            continue;
        }

        $devices[] = [
            'asset_id' => $asset_id,
            'agent_id' => $agent_id,
            'hostname' => trim((string) ($row['hostname'] ?? '')),
        ];
    }

    return ['devices' => $devices, 'unlinked' => $unlinked];
}

/**
 * The high-water mark to pass to collect(), as UTC.
 *
 * It is the OLDEST last_sample_at across the integration's devices, because collect() takes one cursor for the
 * whole batch and a cursor that is too new would permanently starve the device that is furthest behind. If any
 * device has never produced a sample, null is returned so the provider reaches back for whatever history it can
 * - that is the expensive first run, and it happens once per device.
 */
function metricsCronSince(mysqli $mysqli, int $integration_id, array $devices): ?DateTimeImmutable
{
    if (!$devices) {
        return null;
    }

    $asset_ids = [];
    foreach ($devices as $device) {
        $asset_ids[] = intval($device['asset_id']);
    }
    $in_list = implode(',', $asset_ids);

    $result = mysqli_query(
        $mysqli,
        "SELECT asset_id, last_sample_at
           FROM device_metric_collection_state
          WHERE integration_id = $integration_id
            AND asset_id IN ($in_list)
            AND last_sample_at IS NOT NULL"
    );

    if ($result === false) {
        return null;
    }

    $known  = 0;
    $oldest = null;
    while ($row = mysqli_fetch_assoc($result)) {
        $known++;
        $value = (string) $row['last_sample_at'];
        if ($oldest === null || $value < $oldest) {
            $oldest = $value;
        }
    }

    if ($oldest === null || $known < count($asset_ids)) {
        return null; // at least one device has no history yet
    }

    try {
        // last_sample_at is UTC, like sampled_at. Pin the zone so the process timezone cannot shift it.
        return new DateTimeImmutable($oldest, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
}

/** =======================================================================
 *  Flags
 * ======================================================================= */

$cli_args = is_array($argv ?? null) ? array_slice($argv, 1) : [];

$flag_collect_only = in_array('--collect-only', $cli_args, true);
$flag_rollup_only  = in_array('--rollup-only', $cli_args, true);
$flag_prune_only   = in_array('--prune-only', $cli_args, true);
$flag_dry_run      = in_array('--dry-run', $cli_args, true);
$flag_help         = in_array('--help', $cli_args, true) || in_array('-h', $cli_args, true);

$known_flags = ['--collect-only', '--rollup-only', '--prune-only', '--dry-run', '--help', '-h'];
foreach ($cli_args as $arg) {
    if (!in_array($arg, $known_flags, true)) {
        fwrite(STDERR, "Unknown option '$arg'. Try --help.\n");
        exit(2);
    }
}

if ($flag_help) {
    echo "Usage: php cron/metrics_collect.php [--collect-only|--rollup-only|--prune-only] [--dry-run]\n\n";
    echo "  --collect-only   pull and ingest samples only\n";
    echo "  --rollup-only    aggregate existing samples only\n";
    echo "  --prune-only     apply retention only\n";
    echo "  --dry-run        report what each phase would do without writing or deleting\n";
    exit(0);
}

if (((int) $flag_collect_only + (int) $flag_rollup_only + (int) $flag_prune_only) > 1) {
    fwrite(STDERR, "--collect-only, --rollup-only and --prune-only are mutually exclusive.\n");
    exit(2);
}

$do_collect = !($flag_rollup_only || $flag_prune_only);
$do_rollup  = !($flag_collect_only || $flag_prune_only);
$do_prune   = !($flag_collect_only || $flag_rollup_only);

/** =======================================================================
 *  Settings gate
 * ======================================================================= */

$sql_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
$settings_row = $sql_settings ? mysqli_fetch_assoc($sql_settings) : null;

if (!is_array($settings_row)) {
    logApp("Cron-Metrics", "error", "Device metrics collector could not read the settings row.");
    exit("Metrics: settings row unavailable -- Quitting..\n");
}

$config_enable_device_metrics = intval($settings_row['config_enable_device_metrics'] ?? 0);
$config_metrics_interval      = intval($settings_row['config_metrics_collect_interval_seconds'] ?? 300);
$config_enable_cron           = intval($settings_row['config_enable_cron'] ?? 0);

if ($config_enable_device_metrics !== 1) {
    exit("Metrics: device metrics are not enabled in admin settings -- Quitting..\n");
}

// Deliberately NOT a hard gate: this script is scheduled independently of the cron.php monolith, so metrics
// keep flowing even when the global cron is paused. Worth one line so an operator is not surprised.
if ($config_enable_cron !== 1) {
    metricsCronOut('Note: global cron is disabled; device metrics run on their own schedule regardless.');
}

if ($config_metrics_interval < 60) {
    $config_metrics_interval = 60;
}

/** =======================================================================
 *  Lock file (age-based mutex, same pattern as cron/mail_queue.php)
 * ======================================================================= */

$metrics_lock_key   = isset($installation_id) && $installation_id !== '' ? $installation_id : 'default';
$metrics_lock_path  = sys_get_temp_dir() . "/itflow_metrics_collect_{$metrics_lock_key}.lock";
// Three cadences' worth. Long enough that a slow vendor never trips it, short enough that a killed run
// self-heals within a quarter of an hour at the default 5-minute interval.
$metrics_lock_stale = max(900, $config_metrics_interval * 3);

if (file_exists($metrics_lock_path)) {
    $file_age = time() - filemtime($metrics_lock_path);
    if ($file_age > $metrics_lock_stale) {
        unlink($metrics_lock_path);
        logApp("Cron-Metrics", "warning", "Device metrics collector removed a stale lock file ({$file_age}s old).");
    } else {
        logApp("Cron-Metrics", "info", "Device metrics collector was already running so this run terminated.");
        exit("Script is already running. Exiting.\n");
    }
}

file_put_contents($metrics_lock_path, "Locked");

// Release the lock on EVERY exit path, including a fatal error or an uncaught exception. mail_queue.php only
// unlinks at the bottom, which is why a crash there wedges the queue for ten minutes.
register_shutdown_function(function () use ($metrics_lock_path) {
    if (is_file($metrics_lock_path)) {
        @unlink($metrics_lock_path);
    }
});

/** =======================================================================
 *  Run
 * ======================================================================= */

$run_started  = microtime(true);
$exit_code    = 0;
$dry_suffix   = $flag_dry_run ? ' [dry-run]' : '';

metricsCronOut(APP_NAME . " device metrics run starting{$dry_suffix}");

$ingest = new MetricIngestService($mysqli);
$rollup = new MetricRollupService($mysqli);
$rollup->dryRun = $flag_dry_run;

// Oldest sample written this run, UTC. Anything older than the rollup watermark means a backfill landed behind
// it, and the watermark has to be rewound or those buckets would never be recomputed.
$oldest_ingested_utc = null;

/* ------------------------------ 1. COLLECT ------------------------------ */

if ($do_collect) {
    // Keep device_metric_defs in step with MetricRegistry before anything tries to resolve a metric_id.
    if (!$flag_dry_run) {
        $defs_synced = MetricRegistry::syncToDatabase($mysqli);
        if ($defs_synced < count(MetricRegistry::keys())) {
            logApp("Cron-Metrics", "warning", "Only $defs_synced of " . count(MetricRegistry::keys()) . " metric definitions synced to device_metric_defs.");
        }
        $ingest->refreshMetricIds();
    }

    $sql_integrations = mysqli_query($mysqli, "SELECT id, name, type FROM rmm_integrations WHERE enabled = 1 ORDER BY id ASC");

    if ($sql_integrations === false) {
        metricsCronOut('Collect — failed to read rmm_integrations: ' . mysqli_error($mysqli));
        logApp("Cron-Metrics", "error", "Device metrics collector could not read rmm_integrations: " . mysqli_error($mysqli));
        $exit_code = 1;
    } else {
        while ($integration = mysqli_fetch_assoc($sql_integrations)) {
            $intg_id   = intval($integration['id']);
            $intg_name = (string) ($integration['name'] ?? "integration $intg_id");
            $intg_type = (string) ($integration['type'] ?? 'unknown');

            $provider = MetricsProviderFactory::forIntegration($integration, $mysqli);
            $caps     = $provider->capabilities();

            if ($caps->isEmpty()) {
                metricsCronOut("[$intg_name] Skipped ($intg_type) — " . ($caps->notes() !== '' ? $caps->notes() : 'no metrics available'));
                continue;
            }

            $linked   = metricsCronDevices($mysqli, $intg_id);
            $devices  = $linked['devices'];
            $unlinked = $linked['unlinked'];

            if (!$devices) {
                metricsCronOut("[$intg_name] Metrics ($intg_type) — no linked devices" . ($unlinked > 0 ? ", $unlinked without an agent id" : ''));
                continue;
            }

            $since    = metricsCronSince($mysqli, $intg_id, $devices);
            $started  = microtime(true);
            $samples  = [];
            $failed   = false;

            try {
                $samples = $provider->collect($devices, $since);
            } catch (Throwable $e) {
                $failed = true;
                $message = 'collect() threw: ' . $e->getMessage();
                metricsCronOut("[$intg_name] Metrics FAILED ($intg_type) — " . $message);
                logApp("Cron-Metrics", "error", "Device metrics collection failed for '$intg_name': " . $e->getMessage());
                $exit_code = 1;

                // A thrown collect() means no device in this integration produced anything, so every one of
                // them gets the failure recorded. A per-device error inside a successful collect() is reported
                // through errors() instead and does not come through here.
                if (!$flag_dry_run) {
                    foreach ($devices as $device) {
                        $ingest->recordFailure(intval($device['asset_id']), $intg_id, $message);
                    }
                }
            }

            $provider_errors = $provider->errors();

            // Per-device failures. A device the vendor could not reach produces no samples, so
            // ingest() never records a success for it, and collect() did not throw, so the
            // recordFailure() branch above never fires either - without this, its collection-state
            // row keeps a stale last_collected_at and a NULL last_error, and the Performance tab
            // says "no samples recorded yet" when the truthful answer is in $provider_errors.
            //
            // A device is only marked failed when it produced NOTHING. A partial error alongside
            // real samples is a warning in the log, not a collection failure - recording it would
            // increment consecutive_failures on a device that is in fact still reporting.
            if (!$failed && !$flag_dry_run) {
                $assets_with_samples = [];
                foreach ($samples as $sample) {
                    if ($sample instanceof MetricSample) {
                        $assets_with_samples[$sample->assetId()] = true;
                    }
                }
                foreach ($provider->deviceErrors() as $failed_asset_id => $failed_message) {
                    $failed_asset_id = (int) $failed_asset_id;
                    if ($failed_asset_id > 0 && !isset($assets_with_samples[$failed_asset_id])) {
                        $ingest->recordFailure($failed_asset_id, $intg_id, $failed_message);
                    }
                }
            }

            if (!$failed) {
                $ingest_result = [
                    'inserted'  => 0,
                    'rejected'  => 0,
                    'duplicate' => 0,
                    'errors'    => [],
                ];

                if ($samples && !$flag_dry_run) {
                    $ingest_result = $ingest->ingest($samples, $intg_id);
                }

                // Track the oldest sample seen so the rollup watermark can be rewound past a backfill.
                foreach ($samples as $sample) {
                    if ($sample instanceof MetricSample) {
                        $sampled_at = $sample->sampledAtSql();
                        if ($oldest_ingested_utc === null || $sampled_at < $oldest_ingested_utc) {
                            $oldest_ingested_utc = $sampled_at;
                        }
                    }
                }

                $elapsed = number_format(microtime(true) - $started, 1);
                metricsCronOut(sprintf(
                    "[%s] Metrics (%s) — devices=%d%s samples=%d inserted=%d dup=%d rejected=%d errors=%d in %ss%s",
                    $intg_name,
                    $intg_type,
                    count($devices),
                    $unlinked > 0 ? " unlinked=$unlinked" : '',
                    count($samples),
                    intval($ingest_result['inserted'] ?? 0),
                    intval($ingest_result['duplicate'] ?? 0),
                    intval($ingest_result['rejected'] ?? 0),
                    count($provider_errors),
                    $elapsed,
                    $dry_suffix
                ));

                logApp("Cron-Metrics", "info", sprintf(
                    "Device metrics for '%s' (%s): %d devices, %d samples, %d inserted, %d duplicate, %d rejected, %d provider error(s)%s",
                    $intg_name,
                    $intg_type,
                    count($devices),
                    count($samples),
                    intval($ingest_result['inserted'] ?? 0),
                    intval($ingest_result['duplicate'] ?? 0),
                    intval($ingest_result['rejected'] ?? 0),
                    count($provider_errors),
                    $flag_dry_run ? ' (dry run: nothing written)' : ''
                ));

                foreach (($ingest_result['errors'] ?? []) as $ingest_error) {
                    metricsCronOut("[$intg_name] Ingest error — $ingest_error");
                    logApp("Cron-Metrics", "error", "Device metrics ingest error for '$intg_name': $ingest_error");
                    $exit_code = 1;
                }
            }

            // Provider errors are per-device and expected to be survivable; log them all, print the first few
            // so a cron mail stays readable.
            $printed = 0;
            foreach ($provider_errors as $provider_error) {
                logApp("Cron-Metrics", "warning", "Device metrics provider '$intg_name': $provider_error");
                if ($printed < 3) {
                    metricsCronOut("[$intg_name] Provider — $provider_error");
                    $printed++;
                }
            }
            if (count($provider_errors) > $printed) {
                metricsCronOut("[$intg_name] Provider — " . (count($provider_errors) - $printed) . ' further error(s) in the app log');
            }
        }
    }
}

/* ------------------------------ 2. ROLLUP ------------------------------- */

if ($do_rollup) {
    // A backfill that landed behind the watermark has to pull it back, or those hours never get recomputed.
    if ($oldest_ingested_utc !== null) {
        $rollup->rewindTo($oldest_ingested_utc);
    }

    $rollup_result = $rollup->run();
    $hour = $rollup_result['hour'];
    $day  = $rollup_result['day'];

    metricsCronOut(sprintf(
        "Rollups — hour: %d chunk(s), %d row(s)%s; day: %d chunk(s), %d row(s)%s; %dms%s",
        intval($hour['chunks']),
        intval($hour['rows_written']),
        $hour['rolled_through'] !== null ? ' through ' . $hour['rolled_through'] . ' UTC' : ($hour['reason'] !== null ? ' (' . $hour['reason'] . ')' : ''),
        intval($day['chunks']),
        intval($day['rows_written']),
        $day['rolled_through'] !== null ? ' through ' . $day['rolled_through'] . ' UTC' : ($day['reason'] !== null ? ' (' . $day['reason'] . ')' : ''),
        intval($rollup_result['duration_ms']),
        $dry_suffix
    ));

    if (!$rollup_result['ok']) {
        $exit_code = 1;
        foreach ($rollup_result['errors'] as $rollup_error) {
            metricsCronOut("Rollups — ERROR: $rollup_error");
            logApp("Cron-Metrics", "error", "Device metrics rollup error: $rollup_error");
        }
    } else {
        logApp("Cron-Metrics", "info", sprintf(
            "Device metrics rollups: hour %d row(s) through %s UTC, day %d row(s) through %s UTC%s",
            intval($hour['rows_written']),
            (string) ($hour['rolled_through'] ?? 'n/a'),
            intval($day['rows_written']),
            (string) ($day['rolled_through'] ?? 'n/a'),
            $flag_dry_run ? ' (dry run: nothing written)' : ''
        ));
    }
}

/* ------------------------------ 3. PRUNE -------------------------------- */

if ($do_prune) {
    $retention = MetricRetentionService::fromSettings($mysqli);
    $retention->dryRun = $flag_dry_run;

    $prune_result = $retention->prune();
    $raw  = $prune_result['raw'];
    $hour_rollups = $prune_result['hour_rollups'];

    metricsCronOut(sprintf(
        "Retention — raw: %d row(s)%s (keep %dd); hour rollups: %d row(s)%s (keep %dd); day rollups: kept; %dms%s",
        intval($raw['deleted']),
        $raw['cutoff'] !== null ? ' before ' . $raw['cutoff'] . ' UTC' : ($raw['reason'] !== null ? ' (' . $raw['reason'] . ')' : ''),
        $retention->rawRetentionDays(),
        intval($hour_rollups['deleted']),
        $hour_rollups['cutoff'] !== null ? ' before ' . $hour_rollups['cutoff'] . ' UTC' : ($hour_rollups['reason'] !== null ? ' (' . $hour_rollups['reason'] . ')' : ''),
        $retention->hourRetentionDays(),
        intval($prune_result['duration_ms']),
        $dry_suffix
    ));

    if (!$raw['complete'] || !$hour_rollups['complete']) {
        metricsCronOut('Retention — did not finish this pass; the next run continues where it stopped.');
    }

    if (!$prune_result['ok']) {
        $exit_code = 1;
        foreach ($prune_result['errors'] as $prune_error) {
            metricsCronOut("Retention — ERROR: $prune_error");
            logApp("Cron-Metrics", "error", "Device metrics retention error: $prune_error");
        }
    } elseif ($prune_result['deleted_total'] > 0) {
        logApp("Cron-Metrics", "info", sprintf(
            "Device metrics retention: %d raw sample(s) and %d hour rollup(s) deleted%s",
            intval($raw['deleted']),
            intval($hour_rollups['deleted']),
            $flag_dry_run ? ' (dry run: nothing deleted)' : ''
        ));
    }
}

/** =======================================================================
 *  Unlock (the shutdown handler covers abnormal exits)
 * ======================================================================= */

metricsCronOut(APP_NAME . ' device metrics run complete in ' . number_format(microtime(true) - $run_started, 1) . 's' . $dry_suffix);

if (is_file($metrics_lock_path)) {
    unlink($metrics_lock_path);
}

exit($exit_code);
