<?php
/*
 * Issue #34 migrations 2.6.231 (email_queue_attachments) and 2.6.232 (report_recipient_groups, report_schedule_targets), on scratch databases.
 * Needs TWO scratch databases: RIVETIT_TEST_DB_NAME = loaded from the NEW db.sql (this branch), RIVETIT_TEST_DB_OLD = loaded from the db.sql
 * of origin/beta (before these tables). The blocks are extracted from admin/database_updates.php and run on the OLD database; the
 * resulting SHOW CREATE TABLE must equal the one from the fresh db.sql install, a second run changes nothing, and the version gates hold.
 */
require __DIR__ . '/i34_bootstrap.php';
$old = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), (string) getenv('RIVETIT_TEST_DB_OLD'));
if ($old->connect_errno || !preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_OLD'))) { fwrite(STDERR, "need RIVETIT_TEST_DB_OLD (scratch)\n"); exit(2); }
mysqli_query($old, "SET SESSION sql_mode=''");
$src = file_get_contents(__DIR__ . '/../admin/database_updates.php');
foreach (['2.6.210' => '2.6.231', '2.6.231' => '2.6.232'] as $from => $to) {
    if (!preg_match("/    if \(\\\$rivetit_db_version\(\) == '" . preg_quote($from, '/') . "'\) \{.*?\n    \}\n/s", $src, $m)) { echo "FAIL  block $from not found\n"; exit(1); }
    $blocks[$from] = $m[0];
}
$mysqli_saved = $mysqli;
$mysqli = $old;                                  // the extracted blocks use $mysqli
$ver = fn() => (string) mysqli_fetch_row(mysqli_query($old, "SELECT config_current_database_version FROM settings WHERE company_id = 1"))[0];
$rivetit_db_version = $ver;
$set = fn(string $v) => mysqli_query($old, "UPDATE settings SET config_current_database_version = '$v' WHERE company_id = 1");
$create = function (\mysqli $db, string $t) { $res = @mysqli_query($db, "SHOW CREATE TABLE `$t`"); $r = $res ? mysqli_fetch_row($res) : null; return $r ? preg_replace('/ AUTO_INCREMENT=\d+/', '', $r[1]) : null; };
$tables = ['email_queue_attachments', 'report_recipient_groups', 'report_schedule_targets'];

mysqli_query($old, "INSERT IGNORE INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.210')");
foreach ($tables as $t) { mysqli_query($old, "DROP TABLE IF EXISTS `$t`"); }
$set('2.6.210');
$ok($create($old, 'email_queue_attachments') === null, 'old schema: attachments table absent');
$run = function () use ($blocks, $old, $mysqli, &$rivetit_db_version) { foreach ($blocks as $b) { eval($b); } };
$run();
$ok($ver() === '2.6.232', 'one run applies both steps and ends at 2.6.232 (' . $ver() . ')');
foreach ($tables as $t) {
    $ok($create($old, $t) !== null && $create($old, $t) === $create($mysqli_saved, $t), "$t: upgraded SHOW CREATE TABLE identical to fresh db.sql");
}
$snap = array_map(fn($t) => $create($old, $t), $tables);
$set('2.6.210'); $run();
$ok($ver() === '2.6.232' && $snap === array_map(fn($t) => $create($old, $t), $tables), 're-running from 2.6.210 is idempotent');
$set('2.6.209'); $run();
$ok($ver() === '2.6.209', 'gated: does not run from 2.6.209');
$set('2.6.231'); $run();
$ok($ver() === '2.6.232', 'second step runs alone from 2.6.231');
// cascade behaviour
mysqli_query($old, "INSERT INTO email_queue (email_recipient, email_from, email_from_name, email_subject, email_content) VALUES ('a@example.com','b@example.com','x','s','c')");
$eid = mysqli_insert_id($old);
mysqli_query($old, "INSERT INTO email_queue_attachments (attachment_email_id, attachment_filename, attachment_content) VALUES ($eid, 'a.pdf', 'x')");
mysqli_query($old, "DELETE FROM email_queue WHERE email_id = $eid");
$ok((int) mysqli_fetch_row(mysqli_query($old, "SELECT COUNT(*) FROM email_queue_attachments"))[0] === 0, 'deleting the queued email removes its attachments (FK cascade)');
mysqli_query($old, "INSERT INTO report_schedules SET schedule_report='csat', schedule_frequency='daily'"); $sid = mysqli_insert_id($old);
mysqli_query($old, "INSERT INTO report_recipient_groups SET group_name='G', group_recipients='a@example.com'"); $gid = mysqli_insert_id($old);
mysqli_query($old, "INSERT INTO report_schedule_targets SET target_schedule_id=$sid, target_group_id=$gid");
$dup = mysqli_query($old, "INSERT INTO report_schedule_targets SET target_schedule_id=$sid, target_group_id=$gid");
$ok(!$dup, 'one target per schedule and group (unique key)');
mysqli_query($old, "DELETE FROM report_schedules WHERE schedule_id=$sid");
$ok((int) mysqli_fetch_row(mysqli_query($old, "SELECT COUNT(*) FROM report_schedule_targets"))[0] === 0, 'deleting a schedule removes its targets');
mysqli_query($old, "DELETE FROM report_recipient_groups");
$set('2.6.210');
preg_match('/LATEST_DATABASE_VERSION", "([0-9.]+)"/', (string) file_get_contents(__DIR__ . '/../includes/database_version.php'), $lv);
$ok(($lv[1] ?? '') === '2.6.232', 'LATEST_DATABASE_VERSION is 2.6.232');
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
