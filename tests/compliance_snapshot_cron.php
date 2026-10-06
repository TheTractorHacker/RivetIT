<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Runs the EXACT monthly-snapshot block from cron/cron.php against a scratch database: the first run saves a 'scheduled' snapshot,
 * a second run in the same month saves nothing, and a snapshot from last month does not stop this month's. Needs a THROWAWAY
 * database with the full schema (it never reads config.php):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/compliance_snapshot_cron.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
define('LATEST_DATABASE_VERSION', '0'); define('CURRENT_DATABASE_VERSION', '0');
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$src = file_get_contents(__DIR__ . '/../cron/cron.php');
$a = strpos($src, '// Compliance status: one saved snapshot'); $b = strpos($src, '// CLeanup old domain history');
if ($a === false || $b === false || $b < $a) { echo "FAIL  could not find the snapshot block\n"; exit(1); }
$block = substr($src, $a, $b - $a);
$n = fn(string $w = '1') => (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM compliance_snapshots WHERE $w"))[0];
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$run = function () use ($block, $mysqli) { eval($block); };
mysqli_query($mysqli, 'DELETE FROM compliance_snapshots');
$run();
$ok($n() === 1 && $n("trigger_type='scheduled' AND taken_by IS NULL") === 1, 'first run saves one scheduled snapshot');
$run();
$ok($n() === 1, 'a second run in the same month saves nothing');
mysqli_query($mysqli, "UPDATE compliance_snapshots SET taken_at = taken_at - INTERVAL 40 DAY");
$run();
$ok($n() === 2, 'a snapshot from an earlier month does not block this month');
mysqli_query($mysqli, 'DROP TABLE compliance_snapshots');
$run();
$ok(true, 'a missing table is not fatal');
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
