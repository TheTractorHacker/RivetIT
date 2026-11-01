<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * In-app backup and the RMM PostgreSQL store (RivetCore BackupHook, edition prompt R3a/#92 step 3), on a SCRATCH database:
 *   - MariaDB data store: the zip has no rmm-postgres.dump and the run records stores = control_db;
 *   - PostgreSQL data store (needs a throwaway PostgreSQL, see below): the zip carries rmm-postgres.dump, a pg_restore --list of it names the RMM tables,
 *     the run records stores = control_db,rmm_postgres, the password is in no zip member, no error text and no later page;
 *   - a dump that cannot run (wrong password) fails the whole run: BackupStoreFailed, a FAILED backup_runs row, no zip left, no secret in the message.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *   [RIVETIT_TEST_PG_PORT=55432 RIVETIT_TEST_PG_PASSWORD=... RIVETIT_TEST_PG_DB=rmm_scratch RIVETIT_TEST_PG_USER=postgres]  php tests/backup_rmm_postgres.php
 * The PostgreSQL part is skipped (reported) without RIVETIT_TEST_PG_PORT. Use a throwaway container with a random password, 127.0.0.1 only.
 */
require __DIR__ . '/support/security_lib.php';
define('FROM_POST_HANDLER', true);
chdir("$root/admin");
require_once "$root/admin/post/backup.php";
require_once "$root/includes/event_bus.php";
require_once "$root/includes/rmm_bootstrap.php";

$tmp = sys_get_temp_dir() . '/bk_pg_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) { sec_sh('rm -rf ' . escapeshellarg($tmp)); });
$GLOBALS['config_backup_passphrase'] = 'correct horse battery staple 99';
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$config_settings_enc_key = $GLOBALS['config_settings_enc_key'];
$q('DELETE FROM backup_runs');

$members = function (string $zipPath): array {
    $z = new ZipArchive();
    $z->open($zipPath);
    $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
    $z->close();
    return $names;
};
$lastRun = fn() => $rows('SELECT * FROM backup_runs ORDER BY run_id DESC LIMIT 1')[0] ?? null;

// ------------------------------------------------------------------ MariaDB data store
$rmm = rivetRmmModule($db);
$ok($rmm->settings()->dataStore() === \RivetCore\Rmm\Store\RmmDataStoreKind::MARIADB, 'the scratch install starts on the MariaDB data store');
$err = 'unset';
$ok(backup_rmm_postgres_dump($db, $tmp . '/none.dump', $err) === null && $err === null, 'MariaDB store: nothing to dump (null)');
$r = build_backup($db, 'manual', $tmp);
$m = $members($r['path']);
$ok(in_array('db.sql', $m, true) && !in_array('rmm-postgres.dump', $m, true), 'MariaDB store: the zip has db.sql and no rmm-postgres.dump');
$run = $lastRun();
$ok($run && (int) $run['run_ok'] === 1 && $run['run_stores'] === 'control_db', 'MariaDB store: the run records stores = control_db');
@unlink($r['path']);

// ------------------------------------------------------------------ PostgreSQL data store
$port = getenv('RIVETIT_TEST_PG_PORT');
$pgpw = (string) getenv('RIVETIT_TEST_PG_PASSWORD');
if (!$port || $pgpw === '') {
    echo "SKIP  PostgreSQL part: set RIVETIT_TEST_PG_PORT / RIVETIT_TEST_PG_PASSWORD (throwaway container)\n";
    exit(0);
}
$sw = $rmm->dataStoreSwitch();
$errs = $sw->saveConnection(['host' => '127.0.0.1', 'port' => (int) $port, 'database' => getenv('RIVETIT_TEST_PG_DB') ?: 'rmm_scratch', 'user' => getenv('RIVETIT_TEST_PG_USER') ?: 'postgres',
    'password' => $pgpw, 'sslmode' => 'prefer', 'schema' => 'public']);
$ok($errs === [], 'PostgreSQL connection saved: ' . implode('; ', $errs));
$sw = rivetRmmModule($db)->dataStoreSwitch();
$res = $sw->switchTo('postgres', ['acknowledge_no_data_move' => true, 'actor' => 'backup-test']);
$ok(!empty($res['ok']), 'switched to the PostgreSQL data store');

$r = build_backup($db, 'manual', $tmp);
$m = $members($r['path']);
$ok(in_array('rmm-postgres.dump', $m, true), 'PostgreSQL store: the zip carries rmm-postgres.dump');
$run = $lastRun();
$ok($run && (int) $run['run_ok'] === 1 && $run['run_stores'] === 'control_db,rmm_postgres', 'PostgreSQL store: the run records stores = control_db,rmm_postgres');
$z = new ZipArchive();
$z->open($r['path']);
file_put_contents("$tmp/x.dump", $z->getFromName('rmm-postgres.dump'));
$leak = false;
for ($i = 0; $i < $z->numFiles; $i++) {
    $name = $z->getNameIndex($i);
    if ($name !== 'uploads.zip' && str_contains((string) $z->getFromIndex($i), $pgpw)) { $leak = true; }
}
$z->close();
[$c, $list] = sec_sh('pg_restore --list ' . escapeshellarg("$tmp/x.dump"));
$ok($c === 0 && str_contains($list, 'rmm_'), 'pg_restore --list reads the dump and names RMM tables');
$ok(!$leak, 'the PostgreSQL password is in no zip member');
$ok(!glob(sys_get_temp_dir() . '/rivetit-pgpass-*'), 'no PGPASSFILE is left in the temp directory');
@unlink($r['path']);

// A dump that cannot run: the stored password no longer works. The whole run must fail and say so without the password.
$q('DELETE FROM backup_runs');
$sw->saveConnection(['host' => '127.0.0.1', 'port' => (int) $port, 'database' => getenv('RIVETIT_TEST_PG_DB') ?: 'rmm_scratch', 'user' => getenv('RIVETIT_TEST_PG_USER') ?: 'postgres',
    'password' => $pgpw . 'WRONG', 'sslmode' => 'prefer', 'schema' => 'public']);
$before = glob("$tmp/*.zip") ?: [];
$thrown = null;
try { build_backup($db, 'manual', $tmp); } catch (BackupStoreFailed $e) { $thrown = $e; }
$ok($thrown !== null, 'an expected PostgreSQL dump that fails makes build_backup throw BackupStoreFailed');
$ok($thrown !== null && !str_contains($thrown->getMessage(), $pgpw) && !str_contains($thrown->getMessage(), 'WRONG'), 'the failure message carries no password');
$run = $lastRun();
$ok($run && (int) $run['run_ok'] === 0 && str_contains((string) $run['run_error'], 'PostgreSQL'), 'the run is recorded as FAILED and names the PostgreSQL store');
$ok(!str_contains((string) ($run['run_error'] ?? ''), $pgpw), 'the recorded error carries no password');
$ok(($glob = glob("$tmp/*.zip") ?: []) === $before, 'no zip is left behind by the failed run');
$sw->switchTo('mariadb', ['actor' => 'backup-test']);
