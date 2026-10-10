<?php
/*
 * RivetIT - report a deploy/backup.sh run to the application (table backup_runs), so it shows on Admin > Backup, feeds the
 * "backup is stale" watcher and raises the same failure alert (notification, email, backup.failed event) as an in-app backup.
 *
 * Called by deploy/backup.sh as root; best effort - a missing config, an un-migrated database (no backup_runs table) or an
 * unreachable database exits 0 silently so reporting can never break the backup itself.
 *
 *   php deploy/lib/backup_status.php --app-dir=/var/www/x --ok=1 --started=<epoch> --file=<name> --size=<bytes> --sha256=<hex> [--offsite="ok: ..."]
 *   php deploy/lib/backup_status.php --app-dir=/var/www/x --ok=0 --started=<epoch> --error="mysqldump failed"
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$o = getopt('', ['app-dir:', 'ok:', 'started:', 'file::', 'size::', 'sha256::', 'error::', 'offsite::', 'kind::']);
$app = rtrim((string) ($o['app-dir'] ?? ''), '/');
if ($app === '' || !is_file($app . '/config.php') || !isset($o['ok'])) {
    fwrite(STDERR, "backup_status: --app-dir and --ok are required\n");
    exit(0);
}
try {
    chdir($app . '/cron');
    ob_start();
    require $app . '/config.php';
    require_once $app . '/includes/inc_set_timezone.php';
    require_once $app . '/functions.php';
    require_once $app . '/vendor/autoload.php';
    ob_end_clean();
    $id = \ITFlow\Recovery\BackupStatus::recordExternal($mysqli, (string) ($o['kind'] ?? 'script'), (int) ($o['started'] ?? time()), [
        'ok' => $o['ok'] === '1',
        'file' => $o['file'] ?? null,
        'size' => isset($o['size']) ? (int) $o['size'] : null,
        'sha256' => $o['sha256'] ?? null,
        'error' => $o['error'] ?? null,
        'offsite' => $o['offsite'] ?? null,
    ]);
    echo $id === null ? "backup_status: backup_runs table not present, nothing recorded\n" : "backup_status: recorded run #$id\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'backup_status: ' . $e->getMessage() . "\n");
}
exit(0);
