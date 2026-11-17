<?php
declare(strict_types=1);
/* Backup dump keeps device_metric_samples' structure but not its rows (it held a PHP worker for minutes: Cloudflare 524).
 * Scratch DB only: DSC_SOCK DSC_DB (name contains 'scratch') [DSC_USER=root]. */
$dbn = getenv('DSC_DB') ?: ''; if (!str_contains($dbn, 'scratch')) { fwrite(STDERR, "Refusing: scratch database required\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = mysqli_connect(null, getenv('DSC_USER') ?: 'root', getenv('DSC_PASS') ?: '', $dbn, 0, getenv('DSC_SOCK') ?: null);
if (!defined("APP_NAME")) define("APP_NAME", "RivetIT");
$file = dirname(__DIR__) . '/admin/post/backup.php';
preg_match("/^const BACKUP_SKIP_DATA_TABLES = .*?;$/m", file_get_contents($file), $c); eval($c[0]);
$src = file_get_contents($file);
foreach (['fwrite_ln', 'dump_database_streaming'] as $fn) { preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $mm); eval($mm[0]); }
foreach (['device_metric_samples', 'bk_other'] as $t) { $m->query("DROP TABLE IF EXISTS $t"); $m->query("CREATE TABLE $t (id INT PRIMARY KEY, v VARCHAR(10))"); $m->query("INSERT INTO $t VALUES (1,'a'),(2,'b')"); }
$f = tempnam(sys_get_temp_dir(), 'bk'); $head = null; dump_database_streaming($m, $f, $head); $d = file_get_contents($f); unlink($f);
$fail = 0; $ok = function (bool $c, string $s) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  ') . $s . "\n"; if (!$c) $fail++; };
$ok(str_contains($d, 'CREATE TABLE `device_metric_samples`'), 'telemetry table structure is in the dump');
$ok(!str_contains($d, 'INSERT INTO `device_metric_samples`'), 'telemetry rows are not');
$ok(str_contains($d, 'INSERT INTO `bk_other`'), 'other tables still dump their rows');
$m->query("DROP TABLE device_metric_samples"); $m->query("DROP TABLE bk_other");
echo $fail ? "FAILED\n" : "ALL PASS\n"; exit($fail ? 1 : 0);
