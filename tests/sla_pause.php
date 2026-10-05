<?php
/*
 * SLA pause on "waiting on someone" statuses, against the REAL includes/sla_functions.php on a scratch database:
 *   - a status flagged "Pauses SLA" (including one created later) stops the clock; the same wait is never shown as a breach;
 *   - a ticket already past due when it was paused stays breached;
 *   - leaving the status accrues the paused time; a status change by ANY path is repaired by slaSyncPause / slaReconcilePauses;
 *   - a status that is not flagged behaves exactly as before.
 * Needs a THROWAWAY database that holds this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/sla_pause.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
date_default_timezone_set('UTC');
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$q("SET SESSION sql_mode=''"); $q("SET SESSION time_zone = '+00:00'");
if (mysqli_num_rows($q("SHOW COLUMNS FROM ticket_statuses LIKE 'ticket_status_pauses_sla'")) === 0) {
    $q("ALTER TABLE ticket_statuses ADD COLUMN ticket_status_pauses_sla tinyint(1) NOT NULL DEFAULT 0");
    echo "note: scratch schema lacked the column; added it\n";
}
if (mysqli_num_rows($q("SHOW COLUMNS FROM tickets LIKE 'ticket_resolution_started_at'")) === 0) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); }
if (getenv('SLA_CHILD') !== '1') {
$q("DELETE FROM tickets"); $q("DELETE FROM ticket_sla_events"); $q("DELETE FROM ticket_statuses");
foreach ([[1, 'New', 0], [2, 'Open', 0], [3, 'On Hold', 1], [4, 'Resolved', 0], [5, 'Closed', 0], [6, 'Waiting on Customer', 1], [7, 'Waiting on Vendor', 0]] as [$id, $n, $f]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_pauses_sla=$f");
}
}
require __DIR__ . '/../includes/sla_functions.php';
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
function mk($q, $status, $createdAgo, $dueAgo, $pausedAgo = null) {
    $d = fn($s) => $s === null ? 'NULL' : "NOW() - INTERVAL $s SECOND";
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=" . rand(1, 9999999) . ", ticket_subject='x', ticket_status=$status, ticket_created_at=" . $d($createdAgo) . ", ticket_sla_resolution_due=" . $d($dueAgo) . ", ticket_sla_paused_at=" . $d($pausedAgo));
    return mysqli_insert_id($GLOBALS['mysqli']);
}
$row = fn(int $id) => mysqli_fetch_assoc($GLOBALS['mysqli']->query("SELECT * FROM tickets WHERE ticket_id = $id"));
$due = fn(int $id) => $row($id)['ticket_sla_resolution_due'];
$H = 3600;

if (getenv('SLA_CHILD') !== '1') {
// --- slaDueState
$t = mk($q, 2, 5 * $H, 3 * $H);                       // open, due 3h ago, running
$s = slaDueState($mysqli, $row($t), $due($t));
$ok($s['breached'] && !$s['paused'], 'an open ticket past its due date is breached');
$t = mk($q, 2, 5 * $H, -1 * $H);                      // due in 1h
$s = slaDueState($mysqli, $row($t), $due($t));
$ok(!$s['breached'] && !$s['paused'] && $s['remaining'] > 3000, 'an open ticket before its due date is running');
$t = mk($q, 6, 5 * $H, 3 * $H, 1 * $H);               // waiting on customer since 1h ago, but ALREADY past due when paused (due 3h ago)
$s = slaDueState($mysqli, $row($t), $due($t));
$ok($s['breached'] && !$s['paused'], 'a ticket already past due when it was paused stays breached');
$t = mk($q, 6, 5 * $H, 1 * $H, 2 * $H);               // due 1h ago, paused 2h ago (due was still ahead then)
$s = slaDueState($mysqli, $row($t), $due($t));
$ok(!$s['breached'] && $s['paused'] && $s['remaining'] > 3000, 'a paused ticket whose due date passes during the wait is NOT breached (clock frozen at the pause)');
$t = mk($q, 6, 5 * $H, 1 * $H);                       // flagged status, not stamped yet (changed by a path that did not sync)
$s = slaDueState($mysqli, $row($t), $due($t));
$ok(!$s['breached'] && $s['paused'], 'a ticket in a flagged status that is not stamped yet is shown as paused, not breached');
$t = mk($q, 7, 5 * $H, 1 * $H);                       // Waiting on Vendor is NOT flagged yet
$s = slaDueState($mysqli, $row($t), $due($t));
$ok($s['breached'], 'a status that is not flagged behaves as before (breached)');

}

// --- a status created later can opt in: flag it and everything follows
$q("UPDATE ticket_statuses SET ticket_status_pauses_sla = 1 WHERE ticket_status_id = 7");
if (getenv('SLA_CHILD') !== '1') {
    $ok(true, 'flag changed (the flagged-id list is cached per request, so the next checks run in a fresh process)');
    mysqli_close($mysqli);
    putenv('SLA_CHILD=1');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__), $code);
    exit($fails || $code ? 1 : 0);
}
echo "--- (fresh process: Waiting on Vendor is now flagged)\n";
$t = mk($q, 7, 5 * $H, 1 * $H);
$s = slaDueState($mysqli, $row($t), $due($t));
$ok(!$s['breached'] && $s['paused'], 'a status flagged later takes effect: Waiting on Vendor now pauses');

// --- accrual across status changes (any path): slaSyncPause
$t = mk($q, 2, 5 * $H, -1 * $H);                      // running, open
$q("UPDATE tickets SET ticket_status = 6 WHERE ticket_id = $t");   // e.g. an agent reply / kanban drag / API that did not tell the SLA code
slaSyncPause($mysqli, $t);
$ok($row($t)['ticket_sla_paused_at'] !== null && (int) $one("SELECT COUNT(*) FROM ticket_sla_events WHERE ticket_id = $t AND event_type = 'pause'") === 1, 'moving a ticket to Waiting on Customer by any path stamps the pause and logs it');
slaSyncPause($mysqli, $t);
$ok((int) $one("SELECT COUNT(*) FROM ticket_sla_events WHERE ticket_id = $t AND event_type = 'pause'") === 1, 'syncing again changes nothing');
$q("UPDATE tickets SET ticket_sla_paused_at = NOW() - INTERVAL 7200 SECOND WHERE ticket_id = $t");
$q("UPDATE tickets SET ticket_status = 2 WHERE ticket_id = $t");   // customer replied
slaSyncPause($mysqli, $t);
$r = $row($t);
$ok($r['ticket_sla_paused_at'] === null && (int) $r['ticket_sla_paused_seconds'] >= 7190 && (int) $r['ticket_sla_paused_seconds'] <= 7260, 'leaving the status accrues the paused time (about 2h) and clears the pause');
$ok((int) $one("SELECT COUNT(*) FROM ticket_sla_events WHERE ticket_id = $t AND event_type = 'resume'") === 1, 'and logs the resume');

// A resolved ticket is never paused.
$t = mk($q, 6, 5 * $H, -1 * $H);
$q("UPDATE tickets SET ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_status = 5 WHERE ticket_id = $t");
slaSyncPause($mysqli, $t);
$ok($row($t)['ticket_sla_paused_at'] === null, 'a closed ticket is never paused');

// --- slaAccruePause (the status-change path agents use) works with no SLA policy, using the flagged statuses
$t = mk($q, 2, 5 * $H, -1 * $H);
$q("UPDATE tickets SET ticket_status = 6 WHERE ticket_id = $t");
slaAccruePause($mysqli, $t, 2, 6);
$ok($row($t)['ticket_sla_paused_at'] !== null, 'slaAccruePause pauses on a flagged status even when no SLA policy is attached');
$q("UPDATE tickets SET ticket_sla_paused_at = NOW() - INTERVAL 3600 SECOND WHERE ticket_id = $t");
$q("UPDATE tickets SET ticket_status = 2 WHERE ticket_id = $t");
slaAccruePause($mysqli, $t, 6, 2);
$ok($row($t)['ticket_sla_paused_at'] === null && (int) $row($t)['ticket_sla_paused_seconds'] >= 3590, 'and resumes with the time accrued');
slaAccruePause($mysqli, $t, 2, 1);
$ok($row($t)['ticket_sla_paused_at'] === null, 'a change between two non-pausing statuses does nothing');

// --- reconcile repairs everything in one pass (cron)
$a = mk($q, 6, 5 * $H, -1 * $H);              // flagged status, not stamped
$b = mk($q, 2, 5 * $H, -1 * $H, 1 * $H);      // not flagged, but still stamped paused
$n = slaReconcilePauses($mysqli);
$ok($n >= 2 && $row($a)['ticket_sla_paused_at'] !== null && $row($b)['ticket_sla_paused_at'] === null, 'reconcile stamps tickets that should be paused and releases ones that should not');
$ok(slaReconcilePauses($mysqli) === 0, 'a second reconcile has nothing to do');

// --- slaStatus (ticket page bars): frozen while paused
$t = mk($q, 6, 5 * $H, 1 * $H, 2 * $H);
$st = slaStatus($row($t), [])['resolution'];
$ok($st['state'] === 'paused', 'the ticket page shows Paused, not Breached, for a ticket waiting on someone');
$t = mk($q, 2, 5 * $H, 1 * $H);
$ok(slaStatus($row($t), [])['resolution']['state'] === 'breached', 'and Breached for the same dates when it is not paused');
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
