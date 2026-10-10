<?php
/*
 * Shared harness for tests/security_*.php (Wave 1 security): CLI only, SCRATCH database only, no HTTP.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_backup.php
 *
 * config.php in the repo root must point at the same scratch database (scripts/setup_cli.php --config-only). The scratch database must be
 * fully migrated. Refuses anything else, so a test can never touch a live database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__, 2);
$cfgText = @file_get_contents("$root/config.php");
if (!$cfgText || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$db->query("SET SESSION sql_mode=''");
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'security-test';
ob_start();
require_once "$root/config.php";
require_once "$root/functions.php";
ob_end_clean();
$GLOBALS['mysqli'] = $db;
$mysqli = $db;
$session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test'; $session_name = 'Test Admin';
require_once "$root/vendor/autoload.php";
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$rows = function (string $sql) use ($db): array { $o = []; $r = $db->query($sql); while ($r && ($x = $r->fetch_assoc())) { $o[] = $x; } return $o; };
$esc = fn(string $s) => $db->real_escape_string($s);
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; if ($fails > 0) { exit(1); } });

/** Run a shell command, return [exit code, combined output]. */
function sec_sh(string $cmd): array { exec($cmd . ' 2>&1', $out, $code); return [$code, implode("\n", $out)]; }
