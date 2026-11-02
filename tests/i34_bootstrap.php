<?php
/*
 * Shared setup for the reporting tests (tests/reports_*.php). Connects to a THROWAWAY database (name must contain
 * scratch/test), loads the Composer autoloader and pulls the real helper functions out of functions.php (as the other
 * tests do) instead of loading the whole app. Never reads config.php.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/reports_*.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "DB connect failed\n"); exit(2); }
$GLOBALS['mysqli'] = $mysqli;
date_default_timezone_set('UTC');
mysqli_query($mysqli, "SET SESSION sql_mode=''");
require_once __DIR__ . '/../vendor/autoload.php';

$q   = fn(string $sql) => mysqli_query($GLOBALS['mysqli'], $sql);
$one = fn(string $sql) => mysqli_fetch_row(mysqli_query($GLOBALS['mysqli'], $sql))[0] ?? null;
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

/** Extract named top-level functions from a source file and define them. */
function rpt_extract_functions(array $names, string $file = __DIR__ . '/../functions.php'): void {
    $src = file_get_contents($file);
    foreach ($names as $fn) {
        if (function_exists($fn)) continue;
        if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?^}\n/ms', $src, $m)) { echo "FAIL  could not find $fn\n"; exit(1); }
        eval($m[0]);
    }
}
