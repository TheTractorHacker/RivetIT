<?php

/*
 * RivetIT - nightly restore drill.
 *
 * Restores the newest in-app backup zip into a scratch database (drill_<yyyymmdd>) with a dedicated, drill_%-scoped database
 * account, verifies it (checksums, schema version, tables, row counts, training ledger chain, a sample secret, uploads archive),
 * ALWAYS drops the scratch database and temp files, and records the result in restore_drill_log (Admin > Backup shows it).
 *
 * Standalone, like cron/backup_cron.php: it never boots the application against the scratch database, never runs integration
 * syncs or cron code against it, and sends no mail except the failure alert through the normal mail queue.
 *
 * Usage:
 *   php cron/restore_drill.php                      nightly run; does nothing while the drill is switched off
 *   php cron/restore_drill.php --force              run now even if switched off (the "Run drill now" button uses this)
 *   php cron/restore_drill.php --trigger=script --fail="<reason>" --source-name=<file>      record that an archive was unusable (checksum / decrypt)
 *   php cron/restore_drill.php --trigger=script --extracted-dir=<dir> --source-name=<backup-*.tar.gz.enc> [--source-mtime=<epoch>]
 *                                                   verify an already decrypted+unpacked deploy/backup.sh archive
 *                                                   (called by deploy/restore_drill.sh as root)
 *
 * Needs the drill account: see docs/RECOVERY_RUNBOOK.md ("Restore drill setup") or the setup steps on Admin > Backup.
 * Exit codes: 0 pass/warn (or off), 1 fail/error, 2 enabled but not configured.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['force', 'trigger::', 'extracted-dir::', 'source-name::', 'source-mtime::', 'fail::']);
$force = array_key_exists('force', $opts);
$trigger = in_array($opts['trigger'] ?? 'cron', ['cron', 'manual', 'script'], true) ? $opts['trigger'] ?? 'cron' : 'cron';
$stamp = fn () => gmdate('Y-m-d\TH:i:s\Z');

if (!$force && $trigger !== 'script' && \ITFlow\Recovery\RecoverySettings::get($mysqli, 'drill_enabled') !== '1') {
    echo $stamp() . " restore_drill: disabled (Admin > Backup > Restore drill), skipping\n";
    exit(0);
}

$drillOpts = ['trigger' => $trigger, 'live_user' => $dbusername ?? ''];
if (!empty($opts['extracted-dir'])) {
    $drillOpts['extracted_dir'] = (string) $opts['extracted-dir'];
    $drillOpts['source_name'] = (string) ($opts['source-name'] ?? 'backup.enc');
    if (isset($opts['source-mtime'])) {
        $drillOpts['source_mtime'] = (int) $opts['source-mtime'];
    }
}

if (!empty($opts['fail'])) {
    // deploy/restore_drill.sh: the archive was unusable before any restore (see RestoreDrill::run precheck_failure).
    $drillOpts['precheck_failure'] = (string) $opts['fail'];
    $drillOpts['source_name'] = (string) ($opts['source-name'] ?? 'backup.enc');
}

$drill = new \ITFlow\Recovery\RestoreDrill($mysqli, dirname(__DIR__), $drillOpts);
$r = $drill->run();

if (!empty($r['busy'])) {
    echo $stamp() . " restore_drill: another drill is already running, skipping\n";
    exit(0);
}
echo $stamp() . " restore_drill: {$r['status']} - {$r['message']}"
    . ($r['restore_seconds'] !== null ? " (restore {$r['restore_seconds']} s)" : '')
    . ($r['backup_file'] ? " [{$r['backup_file']}]" : '') . "\n";
foreach ($r['checks'] as $c) {
    echo sprintf("  %-4s %-26s %s\n", strtoupper($c['status']), $c['label'], $c['detail']);
}
if ($r['cleanup_ok'] === false) {
    echo $stamp() . " restore_drill: WARNING scratch cleanup incomplete\n";
}
exit($r['status'] === 'not_configured' ? 2 : (in_array($r['status'], ['fail', 'error'], true) ? 1 : 0));
