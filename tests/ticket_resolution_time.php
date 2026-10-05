<?php
/*
 * Resolution-time rules, against the REAL functions in functions.php (their source is extracted and run) on a scratch database:
 *   1. only resolved tickets count: an open ticket, one in an "Unresolved" status, or one reopened and not yet resolved again is left out;
 *   2. reopening restarts the clock (ticket_resolution_started_at), and only when the ticket really was resolved or closed.
 * Needs a THROWAWAY database that holds this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/ticket_resolution_time.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$GLOBALS['mysqli'] = $mysqli;
$config_avg_resolution_exclude_projects = 0;

// Pull the real helper functions out of functions.php.
$src = file_get_contents(__DIR__ . '/../functions.php');
foreach (['ticketResolutionColumnExists', 'ticketReopenSql', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql', 'getAvgResolutionTimeHours'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $m)) { echo "FAIL  could not find $fn in functions.php\n"; exit(1); }
    eval($m[0]);
}
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

if (!ticketResolutionColumnExists($mysqli)) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); echo "note: scratch schema lacked the column; added it\n"; }
// ticketResolutionColumnExists() caches; run the rest in a child so the cache is fresh.
if (getenv('RESOLUTION_CHILD') !== '1') {
    mysqli_close($mysqli);
    putenv('RESOLUTION_CHILD=1');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__), $code); exit($code);
}

$q("SET SESSION sql_mode=''");
$q("DELETE FROM tickets"); $q("DELETE FROM ticket_statuses WHERE ticket_status_name = 'Unresolved'");
$q("INSERT INTO ticket_statuses SET ticket_status_name = 'Unresolved', ticket_status_color = '#fd7e14'");
$unres = (int) $one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'Unresolved'");
$year = (int) date('Y');
function mk($q, $created, $resolved, $closed, $status, $started = 'NULL') {
    $f = fn($v) => $v === 'NULL' ? 'NULL' : "'$v'";
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=" . rand(1, 999999) . ", ticket_subject='x', ticket_status=$status, ticket_created_at=" . $f($created) . ", ticket_resolved_at=" . $f($resolved) . ", ticket_closed_at=" . $f($closed) . ", ticket_resolution_started_at=" . $f($started));
    return mysqli_insert_id($GLOBALS['mysqli']);
}
$y = $year;
// A: resolved after 10h. B: resolved after 20h. => average 15.
$a = mk($q, "$y-03-01 00:00:00", "$y-03-01 10:00:00", "$y-03-01 10:00:00", 5);
$b = mk($q, "$y-03-02 00:00:00", "$y-03-02 20:00:00", "$y-03-02 20:00:00", 5);
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - 15.0) < 0.05, 'average of two resolved tickets (10h and 20h) is 15h');

// C: still open for 100h (no resolved/closed) => not counted.
mk($q, "$y-03-03 00:00:00", 'NULL', 'NULL', 2);
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - 15.0) < 0.05, 'an open ticket does not change the average');

// D: an "Unresolved"-status ticket that still carries a stale resolved date => not counted.
mk($q, "$y-03-04 00:00:00", "$y-03-09 00:00:00", 'NULL', $unres);
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - 15.0) < 0.05, 'a ticket in the Unresolved status is excluded even with a stale resolved date');

// E: reopened ticket. Created 0h, first resolved at 10h, reopened at 50h, resolved again at 54h => 4h, not 54h.
$e = mk($q, "$y-03-05 00:00:00", "$y-03-05 10:00:00", "$y-03-05 10:00:00", 5);
$q("UPDATE tickets SET ticket_resolution_started_at = '$y-03-07 02:00:00', ticket_resolved_at = '$y-03-07 06:00:00', ticket_closed_at = '$y-03-07 06:00:00' WHERE ticket_id = $e");
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - 11.3) < 0.1, 'a reopened ticket is measured from its reopen: (10 + 20 + 4) / 3 = 11.3h');

// The real reopen SQL: stamps the timer for a resolved ticket...
$f = mk($q, "$y-04-01 00:00:00", "$y-04-01 08:00:00", "$y-04-01 08:00:00", 5);
$q("UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 2, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $f");
$age = $one("SELECT TIMESTAMPDIFF(SECOND, ticket_resolution_started_at, NOW()) FROM tickets WHERE ticket_id = $f");
$ok($age !== null && $age >= 0 && $age < 5, 'reopening a resolved/closed ticket stamps ticket_resolution_started_at = now');
$ok($one("SELECT ticket_resolved_at FROM tickets WHERE ticket_id = $f") === null && $one("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $f") === null, 'and still clears resolved/closed as before');
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - 11.3) < 0.1, 'a reopened-and-open ticket is not in the average');

// ...but does nothing for a ticket that was already open (kanban drags, repeated status saves).
$g = mk($q, "$y-04-02 00:00:00", 'NULL', 'NULL', 2, "$y-04-02 03:00:00");
$q("UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 3, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $g");
$ok($one("SELECT ticket_resolution_started_at FROM tickets WHERE ticket_id = $g") === "$y-04-02 03:00:00", 'moving an already-open ticket does NOT reset the timer');
$h = mk($q, "$y-04-03 00:00:00", 'NULL', 'NULL', 2);
$q("UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 3, ticket_resolved_at = NULL, ticket_closed_at = NULL WHERE ticket_id = $h");
$ok($one("SELECT ticket_resolution_started_at FROM tickets WHERE ticket_id = $h") === null, 'a never-resolved ticket keeps a NULL timer start (clock runs from creation)');

// Second reopen resets again.
$q("UPDATE tickets SET ticket_resolved_at = ticket_resolution_started_at + INTERVAL 2 HOUR, ticket_closed_at = ticket_resolution_started_at + INTERVAL 2 HOUR WHERE ticket_id = $f");
sleep(1);
$before = $one("SELECT ticket_resolution_started_at FROM tickets WHERE ticket_id = $f");
$q("UPDATE tickets SET ticket_resolved_at = NOW(), ticket_closed_at = NOW() WHERE ticket_id = $f");
$q("UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 2, ticket_resolved_at = NULL, ticket_closed_at = NULL WHERE ticket_id = $f");
$ok($one("SELECT ticket_resolution_started_at FROM tickets WHERE ticket_id = $f") >= $before, 'a second reopen restarts the timer again');

// Older tickets closed without a resolved date still count (end = closed date).
$i = mk($q, "$y-05-01 00:00:00", 'NULL', "$y-05-01 06:00:00", 5);
$ok(abs(getAvgResolutionTimeHours($mysqli, $y) - (10 + 20 + 4 + 6) / 4) < 0.1, 'a ticket closed without a resolved date still counts, using its close time');

// Priority report averages use the same rules.
$rs = ticketResolutionStartSql(); $re = ticketResolutionEndSql(); $ro = ticketResolvedOnlySql();
$q("UPDATE tickets SET ticket_priority = 'High'");
$avg = (float) $one("SELECT AVG(CASE WHEN $ro THEN TIMESTAMPDIFF(SECOND, $rs, $re) END) FROM tickets WHERE ticket_priority = 'High'") / 3600;
$ok(abs($avg - (10 + 20 + 4 + 6) / 4) < 0.1, 'the shared SQL fragments give the same average for the report queries');
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
