<?php
/*
 * Migration 2.6.138 (reporting and dashboards), on a scratch database: the block is extracted from
 * admin/database_updates.php and run against (a) an old schema (tables and columns absent), (b) a database that
 * already has everything (db.sql), and (c) twice in a row. All must end at 2.6.138 with the same columns.
 */
require __DIR__ . '/reports_bootstrap.php';
$src = file_get_contents(__DIR__ . '/../admin/database_updates.php');
if (!preg_match("/    if \(\\\$rivetit_db_version\(\) == '2\.6\.137'\) \{.*?\n    \}\n/s", $src, $m)) { echo "FAIL  migration block not found\n"; exit(1); }
$ver = fn() => (string) $one("SELECT config_current_database_version FROM settings WHERE company_id = 1");
$rivetit_db_version = $ver;
preg_match('/LATEST_DATABASE_VERSION", "([0-9.]+)"/', (string) file_get_contents(__DIR__ . '/../includes/database_version.php'), $_lv);
$ok(isset($_lv[1]) && version_compare($_lv[1], '2.6.138', '>='), 'LATEST_DATABASE_VERSION is 2.6.138 or later');
$cols = fn() => $one("SELECT GROUP_CONCAT(CONCAT(TABLE_NAME,'.',COLUMN_NAME,':',COLUMN_TYPE) ORDER BY TABLE_NAME, COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('saved_reports','report_exports','dashboard_layouts','report_schedules')");
$run = function () use ($m, $mysqli, &$rivetit_db_version) { eval($m[0]); };

$full = $cols();
$q("UPDATE settings SET config_current_database_version = '2.6.137'"); $run();
$ok($ver() === '2.6.138' && $cols() === $full, 'on a database that already has the schema: advances, nothing changes');
$q("UPDATE settings SET config_current_database_version = '2.6.137'"); $run();
$ok($ver() === '2.6.138' && $cols() === $full, 'run twice: still identical');
$q("DROP TABLE saved_reports"); $q("DROP TABLE report_exports"); $q("DROP TABLE dashboard_layouts");
foreach (['schedule_saved_report_id', 'schedule_format', 'schedule_owner_user_id', 'schedule_last_run_at', 'schedule_last_status'] as $c) { $q("ALTER TABLE report_schedules DROP COLUMN $c"); }
$q("UPDATE settings SET config_current_database_version = '2.6.137'"); $run();
$ok($ver() === '2.6.138' && $cols() === $full, 'on an old schema: creates the tables and columns, identical to db.sql');
$q("UPDATE settings SET config_current_database_version = '2.6.136'"); $run();
$ok($ver() === '2.6.136', 'the block is gated on 2.6.137: it does not run from 2.6.136');
$q("UPDATE settings SET config_current_database_version = '2.6.138'");

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
