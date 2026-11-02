<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Migrations 2.6.211 and 2.6.212 (service catalog categories, risk rules and routing, parallel groups, delegation, escalation):
 * run on a database that holds the 2.6.210 schema (the UPGRADED db) and compared, table by table (SHOW CREATE TABLE), with a
 * database made from the new db.sql (the FRESH db). Also proves idempotence (second run) and that existing rows survive.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_UPG_DB=i30_scratch_upg RIVETIT_TEST_FRESH_DB=i30_scratch_fresh RIVETIT_TEST_DB_USER=.. RIVETIT_TEST_DB_PASS=.. php tests/service_catalog_i30_migration.php
 * The upgraded DB must have been imported from the 2.6.210 db.sql (git show origin/beta:db.sql).
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
foreach (['RIVETIT_TEST_UPG_DB', 'RIVETIT_TEST_FRESH_DB'] as $e) {
    if (!preg_match('/scratch|test/i', (string) getenv($e))) { fwrite(STDERR, "Refusing: $e must contain scratch/test\n"); exit(2); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_UPG_DB'));
$fresh = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_FRESH_DB'));
if ($mysqli->connect_errno || $fresh->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn($db, string $sql) => mysqli_query($db, $sql);
$tables = ['service_catalog_items', 'service_catalog_fields', 'service_catalog_approval_steps', 'service_catalog_requests', 'service_catalog_request_approvals', 'service_catalog_categories', 'service_catalog_risk_rules', 'service_catalog_delegations'];
$create = function ($db, string $t): ?string {
    $r = mysqli_query($db, "SHOW CREATE TABLE `$t`");
    return $r ? preg_replace('/ AUTO_INCREMENT=\d+/', '', mysqli_fetch_row($r)[1]) : null; // the row counter is data, not schema
};
$settingsCol = function ($db) {
    $r = mysqli_fetch_assoc(mysqli_query($db, "SHOW COLUMNS FROM settings LIKE 'config_catalog_approval_cron'"));
    return $r ? $r['Type'] . '|' . $r['Null'] . '|' . $r['Default'] : null;
};

// Upgraded DB starts at 2.6.210: no new objects, existing item and request rows present.
$ok($create($mysqli, 'service_catalog_categories') === null, 'start: the upgraded DB does not have the categories table yet');
$ok($settingsCol($mysqli) === null, 'start: no catalog worker flag yet');
$q($mysqli, "DELETE FROM settings"); $q($mysqli, "INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.210'");
$q($mysqli, "DELETE FROM service_catalog_items"); $q($mysqli, "DELETE FROM service_catalog_approval_steps"); $q($mysqli, "DELETE FROM service_catalog_request_approvals");
$q($mysqli, "INSERT INTO service_catalog_items SET catalog_item_id = 7, name = 'Existing', ticket_subject_template = 's', requires_approval = 1, risk_score = 40");
$q($mysqli, "INSERT INTO service_catalog_approval_steps SET catalog_item_id = 7, step_order = 1, approver_type = 'user', approver_id = 1, mode = 'any'");
$q($mysqli, "INSERT INTO service_catalog_request_approvals SET request_id = 9, step_order = 1, approver_user_id = 1, status = 'pending'");

$run = function () use ($mysqli) {
    $mysqli->query("SET SESSION sql_mode=''");
    if (!defined('LATEST_DATABASE_VERSION')) { require __DIR__ . '/../includes/database_version.php'; }
    if (!defined('CURRENT_DATABASE_VERSION')) { define('CURRENT_DATABASE_VERSION', '2.6.210'); }
    ob_start();
    try { require __DIR__ . '/../admin/database_updates.php'; } catch (\Throwable $e) { fwrite(STDERR, 'updater stopped: ' . $e->getMessage() . "\n"); }
    ob_end_clean();
};
$version = fn() => mysqli_fetch_row($q($mysqli, "SELECT config_current_database_version FROM settings WHERE company_id = 1"))[0];
$run();
$ok(version_compare($version(), '2.6.212', '>='), 'run 1: version reached 2.6.212 or later (' . $version() . ')');
foreach ($tables as $t) {
    $ok($create($mysqli, $t) !== null && $create($mysqli, $t) === $create($fresh, $t), "run 1: $t identical (SHOW CREATE TABLE) to a fresh db.sql install");
}
$ok($settingsCol($mysqli) === $settingsCol($fresh) && $settingsCol($mysqli) !== null, 'run 1: settings.config_catalog_approval_cron matches a fresh install (tinyint, default 0)');
$ok(mysqli_fetch_assoc($q($mysqli, "SELECT risk_min, risk_max FROM service_catalog_approval_steps WHERE catalog_item_id = 7")) === ['risk_min' => '0', 'risk_max' => '100'], 'existing approval step keeps the full 0-100 risk band');
$ok(mysqli_fetch_row($q($mysqli, "SELECT reminder_after_hours, escalate_after_hours, expire_after_hours, catalog_category_id IS NULL FROM service_catalog_items WHERE catalog_item_id = 7")) === ['0', '0', '0', '1'], 'existing item: no reminders, escalation, timeout or category');
$ok(mysqli_fetch_row($q($mysqli, "SELECT step_id IS NULL, is_escalation, reminder_count FROM service_catalog_request_approvals WHERE request_id = 9")) === ['1', '0', '0'], 'existing pending approval row keeps working (no branch, not an escalation)');

// Idempotent: rewind the version and run again over a database that already has everything.
$q($mysqli, "UPDATE settings SET config_current_database_version = '2.6.210'");
$run();
$ok(version_compare($version(), '2.6.212', '>='), 'run 2: version reached 2.6.212 or later again');
foreach ($tables as $t) { $ok($create($mysqli, $t) === $create($fresh, $t), "run 2: $t still identical"); }
$ok(mysqli_fetch_row($q($mysqli, "SELECT COUNT(*) FROM service_catalog_items WHERE catalog_item_id = 7"))[0] === '1', 'run 2: existing rows untouched');

$q($mysqli, "DELETE FROM service_catalog_items WHERE catalog_item_id = 7"); $q($mysqli, "DELETE FROM service_catalog_approval_steps WHERE catalog_item_id = 7"); $q($mysqli, "DELETE FROM service_catalog_request_approvals WHERE request_id = 9");
echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
