<?php
/*
 * Scheduled reports with data (src/Reports/ReportScheduler), on a scratch database, with the renderer and the mail
 * queue REPLACED BY RECORDERS: nothing is rendered by a child process and no email is queued or sent.
 *   - a due CSV schedule stores an export (hash only) and "sends" one link per recipient; status/last_run recorded;
 *   - not-due, paused and legacy headline schedules are left alone;
 *   - failures (renderer error, no owner, deleted view, no valid recipient) are recorded and do not stamp last_sent;
 *   - export links expire; an unknown/short token never matches; expired rows are purged.
 */
require __DIR__ . '/reports_bootstrap.php';
use ITFlow\Reports\ReportScheduler;
use ITFlow\Reports\SavedReports;

$q("DELETE FROM report_schedules"); $q("DELETE FROM report_exports"); $q("DELETE FROM saved_reports");
$view = SavedReports::create($mysqli, 5, 'service_desk', 'Monthly SD', ['canned_date' => 'lastmonth'], false);

$sent = []; $renders = [];
$deps = function (array $renderResult) use (&$sent, &$renders) {
    return [
        'render' => function ($s, $key, $params, $format) use ($renderResult, &$renders) { $renders[] = [$key, $params, $format, (int) $s['schedule_owner_user_id']]; return $renderResult; },
        'mail' => function (array $m) use (&$sent) { foreach ($m as $x) { $sent[] = $x; } },
        'from_email' => 'noreply@example.com', 'from_name' => 'RivetIT', 'base_url' => 'scratch.local', 'brand' => 'RivetIT', 'now' => date('Y-m-d H:i:s'),
    ];
};
$ins = fn(string $cols) => $q("INSERT INTO report_schedules SET $cols");

// --- happy path: CSV from a saved view
$ins("schedule_report='service_desk', schedule_frequency='daily', schedule_recipients='a@example.com, bad address, b@example.com', schedule_saved_report_id=$view, schedule_format='csv', schedule_owner_user_id=5");
$sum = ReportScheduler::runDue($mysqli, $deps(['ok' => true, 'content' => "\xEF\xBB\xBFMonth,Opened\nJan,3\n"]));
$ok($sum['processed'] === 1 && $sum['ok'] === 1 && $sum['emails'] === 2, 'one due schedule processed, two valid recipients mailed (bad address skipped)');
$ok($renders === [['service_desk', ['canned_date' => 'lastmonth'], 'csv', 5]], 'rendered as the owner, with the saved view\'s whitelisted params, as csv');
$ok(count($sent) === 2 && $sent[0]['recipient'] === 'a@example.com' && $sent[1]['recipient'] === 'b@example.com', 'mail recorded per recipient (not queued, not sent)');
preg_match('#https://scratch\.local/guest/report_download\.php\?t=([a-f0-9]{64})#', $sent[0]['body'], $m);
$ok(!empty($m[1]) && strpos($sent[0]['body'], 'expires in 7 days') !== false, 'email carries a 64-hex download link and says it expires');
$ok((int) $one("SELECT COUNT(*) FROM report_exports") === 1 && $one("SELECT COUNT(*) FROM report_exports WHERE export_token_hash = '{$m[1]}'") == 0 && $one("SELECT COUNT(*) FROM report_exports WHERE export_token_hash = '" . hash('sha256', $m[1]) . "'") == 1, 'only the SHA-256 of the token is stored');
$exp = ReportScheduler::fetchExport($mysqli, $m[1]);
$ok($exp && strpos($exp['export_content'], 'Jan,3') !== false && substr($exp['export_filename'], -4) === '.csv', 'the link resolves to the CSV');
$row = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($row['schedule_last_status'] === 'ok: queued 2 email(s)' && $row['schedule_last_run_at'] !== null && $row['schedule_last_sent'] !== null, 'status, last_run_at and last_sent recorded');
// not due again straight away
$sent = []; $renders = [];
$sum = ReportScheduler::runDue($mysqli, $deps(['ok' => true, 'content' => 'x']));
$ok($sum['processed'] === 0 && $sent === [] && $renders === [], 'not due again until the frequency has elapsed');
$ok(!ReportScheduler::isDue(['schedule_last_sent' => date('Y-m-d H:i:s', strtotime('-23 hours')), 'schedule_frequency' => 'daily'], date('Y-m-d H:i:s')) && ReportScheduler::isDue(['schedule_last_sent' => date('Y-m-d H:i:s', strtotime('-25 hours')), 'schedule_frequency' => 'daily'], date('Y-m-d H:i:s')) && ReportScheduler::isDue(['schedule_last_sent' => null, 'schedule_frequency' => 'weekly'], date('Y-m-d H:i:s')), 'isDue()');

// --- html tables format on a plain report, and the data-path switch
$q("DELETE FROM report_schedules");
$ins("schedule_report='csat', schedule_frequency='weekly', schedule_recipients='h@example.com', schedule_format='html', schedule_owner_user_id=5");        // legacy headline summary: untouched here
$ins("schedule_report='csat', schedule_frequency='weekly', schedule_recipients='h@example.com', schedule_format='csv', schedule_owner_user_id=5, schedule_active=0"); // paused
$ins("schedule_report='csat', schedule_frequency='weekly', schedule_recipients='h@example.com', schedule_format='csv', schedule_owner_user_id=5, schedule_last_sent=NOW()"); // not due
$sent = []; $renders = [];
$sum = ReportScheduler::runDue($mysqli, $deps(['ok' => true, 'content' => 'x']));
$ok($sum['processed'] === 0 && $renders === [], 'legacy headline, paused and not-due schedules are not run by the data path');
$ok(ReportScheduler::usesDataPath(['schedule_format' => 'csv']) && ReportScheduler::usesDataPath(['schedule_format' => 'tables']) && ReportScheduler::usesDataPath(['schedule_format' => 'html', 'schedule_saved_report_id' => 3]) && !ReportScheduler::usesDataPath(['schedule_format' => 'html']), 'usesDataPath()');
$q("DELETE FROM report_schedules");
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='h@example.com', schedule_format='tables', schedule_owner_user_id=5");
$sent = [];
ReportScheduler::runDue($mysqli, $deps(['ok' => true, 'content' => '<table><tr><td>Tables in the email</td></tr></table>']));
$ok(count($sent) === 1 && strpos($sent[0]['body'], 'Tables in the email') !== false && (int) $one("SELECT COUNT(*) FROM report_exports") === 1, 'tables format (plain report, no saved view) puts the tables in the body and stores no new export');

// --- failures are recorded, last_sent is not stamped
$fail = function (string $cols, array $result, string $expect, string $label) use ($q, $mysqli, $deps, $ok, $one, &$sent) {
    $q("DELETE FROM report_schedules");
    $q("INSERT INTO report_schedules SET schedule_frequency='daily', schedule_format='csv', $cols");
    $sent = [];
    $s = ReportScheduler::runDue($mysqli, $deps($result));
    $r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
    $ok($s['failed'] === 1 && $sent === [] && $r['schedule_last_sent'] === null && strpos((string) $r['schedule_last_status'], $expect) !== false && $r['schedule_last_run_at'] !== null, $label . ' (' . $r['schedule_last_status'] . ')');
};
$fail("schedule_report='csat', schedule_recipients='h@example.com', schedule_owner_user_id=5", ['ok' => false, 'error' => 'denied: the owner\'s role cannot open this report'], 'denied', 'renderer failure is recorded, nothing mailed, retried next run');
$fail("schedule_report='csat', schedule_recipients='h@example.com'", ['ok' => true, 'content' => 'x'], 'no owner', 'schedule with no owner is refused');
$fail("schedule_report='csat', schedule_recipients='not-an-email', schedule_owner_user_id=5", ['ok' => true, 'content' => 'x'], 'no valid recipient', 'no valid recipient');
$fail("schedule_report='csat', schedule_recipients='h@example.com', schedule_owner_user_id=5, schedule_saved_report_id=99999", ['ok' => true, 'content' => 'x'], 'no longer exists', 'deleted view');
$fail("schedule_report='nonsense', schedule_recipients='h@example.com', schedule_owner_user_id=5", ['ok' => true, 'content' => 'x'], 'unknown report', 'unknown report key');

// --- export expiry / token handling
$tok = ReportScheduler::storeExport($mysqli, null, 'x.csv', 'a,b');
$ok(ReportScheduler::fetchExport($mysqli, $tok) !== null, 'fresh export is served');
$q("UPDATE report_exports SET export_expires_at = NOW() - INTERVAL 1 SECOND WHERE export_token_hash = '" . hash('sha256', $tok) . "'");
$ok(ReportScheduler::fetchExport($mysqli, $tok) === null, 'expired export is not served');
$ok(ReportScheduler::fetchExport($mysqli, 'short') === null && ReportScheduler::fetchExport($mysqli, str_repeat('a', 64)) === null && ReportScheduler::fetchExport($mysqli, "' OR 1=1 -- ") === null, 'unknown / malformed tokens never match');
ReportScheduler::purgeExpired($mysqli);
$ok((int) $one("SELECT COUNT(*) FROM report_exports WHERE export_expires_at <= NOW()") === 0, 'expired exports are purged');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
