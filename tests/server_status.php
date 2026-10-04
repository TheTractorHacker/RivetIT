<?php
/*
 * Server status checks. Needs a DISPOSABLE schema-only database (settings, app_logs, integration_jobs):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/server_status.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
use ITFlow\Ops\ServerStatus;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$db->query("SET SESSION sql_mode=''");
define('LATEST_DATABASE_VERSION', '2.6.500');
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };

$root = sys_get_temp_dir() . '/rivetit-status-test-' . bin2hex(random_bytes(4));
mkdir("$root/includes", 0700, true); mkdir("$root/uploads", 0700); mkdir("$root/backups", 0700);
copy(__DIR__ . '/../includes/cron_jobs.php', "$root/includes/cron_jobs.php");
$db->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.100')");

$find = function (array $groups, string $label) { foreach ($groups as $g) foreach ($g['checks'] as $c) if ($c['label'] === $label) return $c; return ['status' => 'missing', 'detail' => '']; };
$fake = fn(array $mem, bool $throws = false) => new class($mem, $throws) {
    public function __construct(private array $mem, private bool $throws) {}
    public function ping() { if ($this->throws) throw new RuntimeException('boom'); return 'PONG'; }
    public function info() { return ['Server' => ['redis_version' => '7.0.0'], 'Memory' => $this->mem + ['used_memory_human' => '1M']]; }
};
$now = time();
$status = fn(?object $redis = null) => (new ServerStatus($db, $root, $redis, '/nonexistent/helper', $now))->run();
$cronDir = "$root/etc-rivetit"; mkdir($cronDir, 0755);
$helper = "$root/helper.sh"; file_put_contents($helper, '#!/bin/sh'); chmod($helper, 0755);

// ---- database / app
$g = $status();
$ok($find($g, 'Database version')['status'] === 'warn' && str_contains($find($g, 'Database version')['detail'], '2.6.500'), 'pending database update is flagged with the version it needs');
$db->query("UPDATE settings SET config_current_database_version = '2.6.500'");
$ok($find($status(), 'Database version')['status'] === 'ok', 'up-to-date database is ok');
$ok($find($g, 'PHP extensions')['status'] === 'ok', 'PHP extension check passes on this box');

// ---- storage
$ok($find($g, 'uploads/ writable')['status'] === 'ok' && $find($g, 'backups/ writable')['status'] === 'ok', 'writable directories ok');
chmod("$root/backups", 0500);
$ok($find($status(), 'backups/ writable')['status'] === 'fail', 'unwritable backups directory fails');
chmod("$root/backups", 0700);
$ok(in_array($find($g, 'Disk space')['status'], ['ok', 'warn', 'fail'], true), 'disk space reported');

// ---- backups
$ok($find($g, 'In-app backups')['status'] === 'warn' && $find($g, 'Encrypted disaster-recovery backups')['status'] === 'warn', 'no backups at all: both warn');
file_put_contents("$root/backups/itflow_1_auto.zip", 'z'); touch("$root/backups/itflow_1_auto.zip", $now - 600);
$ok($find($status(), 'In-app backups')['status'] === 'ok', 'fresh zip: ok');
touch("$root/backups/itflow_1_auto.zip", $now - 40 * 3600);
$ok($find($status(), 'In-app backups')['status'] === 'warn', 'zip 40h old: warn');
touch("$root/backups/itflow_1_auto.zip", $now - 10 * 86400);
$ok($find($status(), 'In-app backups')['status'] === 'fail', 'zip 10 days old: fail');
file_put_contents("$root/backups/backup-x-1.tar.gz.enc", str_repeat('e', 2048)); touch("$root/backups/backup-x-1.tar.gz.enc", $now - 20 * 86400);
$e = $find($status(), 'Encrypted disaster-recovery backups');
$ok($e['status'] === 'warn' && str_contains($e['detail'], '2 KB'), 'encrypted archive 20 days old: warn, size shown');
touch("$root/backups/backup-x-1.tar.gz.enc", $now - 3600);
$e = $find($status(), 'Encrypted disaster-recovery backups');
$ok($e['status'] === 'ok' && str_contains($e['detail'], 'off-site'), 'fresh encrypted archive: ok, reminds to copy off-site');

// ---- scheduled work
$ok($find($g, 'Scheduled jobs')['status'] === 'warn' && $find($g, 'Schedule editing')['status'] === 'warn', 'no cron entries / no helper: warn');
$db->query("INSERT INTO app_logs (app_log_category, app_log_details, app_log_created_at) VALUES ('Cron', 'Cron executed successfully', NOW() - INTERVAL 2 HOUR)");
$m = $find($status(), 'Main cron');
$ok($m['status'] === 'ok' && str_contains($m['detail'], 'Switched off') && str_contains($m['detail'], '2 h ago'), 'main cron switched off on purpose: ok, last run shown');
$db->query("UPDATE settings SET config_enable_cron = 1");
$ok($find($status(), 'Main cron')['status'] === 'ok', 'main cron on and ran 2 h ago: ok');
$db->query("UPDATE app_logs SET app_log_created_at = NOW() - INTERVAL 30 DAY");
$m = $find($status(), 'Main cron');
$ok($m['status'] === 'warn' && str_contains($m['detail'], '30 days ago'), 'main cron on but silent for 30 days: warn');
$db->query("DELETE FROM app_logs");
$ok($find($status(), 'Main cron')['status'] === 'warn', 'main cron on and never ran: warn');
$db->query("UPDATE settings SET config_enable_cron = 0");

// ---- cron manager registration readable / unreadable
file_put_contents("$cronDir/cron-manager-x.json", json_encode(['app_root' => realpath($root)]));
$withDir = fn() => (new ServerStatus($db, $root, null, $helper, $now, $cronDir));
$f = fn(array $g, string $l) => $find($g, $l);
$ok($f($withDir()->run(), 'Schedule editing')['status'] === 'ok' && count($withDir()->rootTasks()) === 5, 'helper + readable registration: schedule editing ok, no extra task');
chmod($cronDir, 0000);
$g = $withDir()->run(); $tasks = $withDir()->rootTasks();
$ok($f($g, 'Schedule editing')['status'] === 'warn' && str_contains($f($g, 'Schedule editing')['detail'], 'cannot read') && count($tasks) === 6 && str_contains($tasks[0]['command'], 'chmod 755'), 'unreadable registration: specific warning plus the one-line fix task');
chmod($cronDir, 0755);
unlink("$cronDir/cron-manager-x.json");
$db->query('DELETE FROM integration_jobs');
$db->query("INSERT INTO integration_jobs (job_type, status) VALUES ('x', 'pending'), ('x', 'dead_letter')");
$q = $find($status(), 'Integration job queue');
$ok($q['status'] === 'warn' && str_contains($q['detail'], '1 pending') && str_contains($q['detail'], '1 failed'), 'dead-letter jobs raise a warning');

// ---- redis
$ok($find($status(null), 'Redis')['status'] === 'warn', 'Redis unreachable: warn, not fail (it is optional)');
$r = $status($fake(['maxmemory' => 0]));
$ok($find($r, 'Redis')['status'] === 'ok' && $find($r, 'Redis memory limit')['status'] === 'warn', 'Redis with no memory limit: connected ok, limit warns');
$r = $status($fake(['maxmemory' => 268435456, 'maxmemory_policy' => 'allkeys-lru']));
$ok($find($r, 'Redis memory limit')['status'] === 'ok' && str_contains($find($r, 'Redis memory limit')['detail'], '256 MB'), 'Redis with limit: ok, size shown');
$ok($find($status($fake([], true)), 'Redis')['status'] === 'warn', 'Redis query error becomes a warning');

// ---- summary and tasks
$sum = ServerStatus::summarize($status($fake(['maxmemory' => 1])));
$ok($sum['ok'] > 0 && array_sum($sum) === count(array_merge(...array_column($status($fake(['maxmemory' => 1])), 'checks'))), 'summary counts every check');
$tasks = (new ServerStatus($db, $root, null, '/nonexistent/helper'))->rootTasks();
$ok(count($tasks) === 5 && str_contains($tasks[1]['command'], "--app-dir=$root") && str_contains($tasks[4]['caution'], 'REPLACES'), 'root tasks carry this install\'s path and the restore warning');
$weird = sys_get_temp_dir() . "/rivet status test; id " . bin2hex(random_bytes(3));
mkdir($weird);
$t = (new ServerStatus($db, $weird, null, '/nonexistent/helper'))->rootTasks();
$ok(!str_contains($t[1]['command'], "; id ") || str_contains($t[1]['command'], "'"), 'unsafe characters in the install path are shell-quoted in commands');
rmdir($weird);

// cleanup
foreach (glob("$root/backups/*") as $f) unlink($f);
unlink($helper); rmdir($cronDir); unlink("$root/includes/cron_jobs.php"); rmdir("$root/includes"); rmdir("$root/uploads"); rmdir("$root/backups"); rmdir($root);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
