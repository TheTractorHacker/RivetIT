<?php

/*
 * deploy/backup.sh and restore.sh (via lib/backup_extras.sh): prints shell `export KEY=VALUE` lines for scripts/rmm-postgres-backup.sh /
 * rmm-postgres-restore.sh, read from the RMM data store setting (RivetCore\Rmm\Store\DataStoreSwitch), NEVER from a command-line argument.
 * Writes the password to a 0600 PGPASSFILE (never PGPASSWORD on a command line or in this output) and prints its path too. Prints NOTHING
 * (exit 0) when the data store is not 'postgres' - the caller's `eval "$(php pg_dump_env.php ...)"` is then a no-op.
 *
 * Usage: php deploy/lib/pg_dump_env.php <app-dir>
 */

$appDir = $argv[1] ?? null;
if (!is_string($appDir) || !is_dir($appDir)) {
    fwrite(STDERR, "usage: pg_dump_env.php <app-dir>\n");
    exit(2);
}

require $appDir . '/config.php';
require $appDir . '/vendor/autoload.php';
require $appDir . '/includes/event_bus.php';   // rivetCoreDb()
require $appDir . '/includes/rmm_bootstrap.php';

/** @var \mysqli $mysqli set by config.php */
$rmm = rivetRmmModule($mysqli);
if ($rmm->settings()->dataStore() !== \RivetCore\Rmm\Store\RmmDataStoreKind::POSTGRES) {
    exit(0);   // MariaDB: nothing to back up here, the control database dump already covers everything
}

try {
    $cfg = $rmm->dataStoreSwitch()->postgresConfig();
} catch (\Throwable $e) {
    fwrite(STDERR, 'pg_dump_env: the stored PostgreSQL connection could not be read: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($cfg === null) {
    fwrite(STDERR, "pg_dump_env: the data store is 'postgres' but no connection is saved. Nothing to dump.\n");
    exit(1);
}

$passFile = tempnam(sys_get_temp_dir(), 'rivetit-pgpass-');
chmod($passFile, 0600);
// One line, libpq .pgpass format: hostname:port:database:username:password. ':' and '\' in a field are escaped per the libpq rule.
$esc = static fn (string $v): string => str_replace([':', '\\'], ['\\:', '\\\\'], $v);
file_put_contents($passFile, $esc($cfg->host) . ':' . $cfg->port . ':' . $esc($cfg->database) . ':' . $esc($cfg->user) . ':' . $esc($cfg->password()) . "\n");

$lines = [
    'RIVETCORE_PG_DATABASE' => $cfg->database,
    'RIVETCORE_PG_USER' => $cfg->user,
    'RIVETCORE_PG_HOST' => $cfg->host,
    'RIVETCORE_PG_PORT' => (string) $cfg->port,
    'RIVETCORE_PG_SSLMODE' => $cfg->sslMode,
    'RIVETCORE_PG_SCHEMA' => $cfg->schema,
    'PGPASSFILE' => $passFile,
];
foreach ($lines as $k => $v) {
    echo 'export ' . $k . '=' . escapeshellarg($v) . "\n";
}
