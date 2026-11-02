<?php
/*
 * Issue #34 scheduled delivery on a scratch database, with the renderer and the mail queue REPLACED BY RECORDERS (nothing is rendered by a
 * child process, no email is queued or sent): PDF attachments, quarterly cadence, recipient groups, per-group saved views, send-time
 * recipient policy, partial failure handling. Plus addToMailQueue() attachment storage (real function, scratch tables).
 */
require __DIR__ . '/i34_bootstrap.php';
require_once __DIR__ . '/../includes/report_schedule_policy.php';
use ITFlow\Reports\RecipientGroups;
use ITFlow\Reports\ReportPdf;
use ITFlow\Reports\ReportScheduler;
use ITFlow\Reports\SavedReports;

foreach (['report_schedules', 'report_exports', 'saved_reports', 'report_recipient_groups', 'email_queue'] as $t) { $q("DELETE FROM $t"); }
$realPdf = ReportPdf::render('Fixture', [['T'], ['a', 'b'], ['1', '2']], []);
$sent = []; $renders = [];
$deps = function (callable $render, array $extra = []) use (&$sent, &$renders) {
    return $extra + [
        'render' => function ($s, $key, $params, $format) use ($render, &$renders) { $renders[] = [$key, $params, $format]; return $render($key, $params, $format); },
        'mail' => function (array $m) use (&$sent) { foreach ($m as $x) { $sent[] = $x; } },
        'from_email' => 'noreply@example.com', 'from_name' => 'RivetIT', 'base_url' => 'scratch.local', 'brand' => 'RivetIT', 'now' => date('Y-m-d H:i:s'),
    ];
};
$okPdf = fn() => ['ok' => true, 'content' => $realPdf];
$ins = fn(string $cols) => $q("INSERT INTO report_schedules SET $cols");
$reset = function () use ($q, &$sent, &$renders) { $q("DELETE FROM report_schedules"); $sent = []; $renders = []; };

// ---- PDF attachment
$ins("schedule_report='service_desk', schedule_frequency='quarterly', schedule_recipients='a@example.com, b@example.com', schedule_format='pdf', schedule_owner_user_id=5");
$sum = ReportScheduler::runDue($mysqli, $deps($okPdf));
$ok($sum['processed'] === 1 && $sum['ok'] === 1 && $sum['emails'] === 2, 'PDF schedule: processed, two recipients mailed');
$ok($renders === [['service_desk', [], 'pdf']], 'PDF schedule: renderer asked for format pdf');
$att = $sent[0]['attachments'] ?? [];
$ok(count($att) === 1 && $att[0]['mime'] === 'application/pdf' && strncmp($att[0]['content'], '%PDF-', 5) === 0 && preg_match('/^service_desk_\d{4}-\d{2}-\d{2}\.pdf$/', $att[0]['filename']), 'PDF schedule: each message carries the PDF as an attachment with a .pdf name');
$ok(strpos($sent[0]['body'], 'attached as a PDF') !== false && strpos($sent[0]['body'], 'download') === false, 'PDF schedule: body says attached, has no download link');
$ok((int) $one("SELECT COUNT(*) FROM report_exports") === 0, 'PDF schedule: nothing stored in report_exports');
$row = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($row['schedule_last_status'] === 'ok: queued 2 email(s)' && $row['schedule_last_sent'] !== null, 'PDF schedule: status recorded');
$sent = []; $renders = [];
$ok(ReportScheduler::runDue($mysqli, $deps($okPdf))['processed'] === 0, 'quarterly schedule is not due again straight away');
$q("UPDATE report_schedules SET schedule_last_sent = DATE_SUB(NOW(), INTERVAL 89 DAY)");
$ok(ReportScheduler::runDue($mysqli, $deps($okPdf))['processed'] === 0, 'quarterly: not due after 89 days');
$q("UPDATE report_schedules SET schedule_last_sent = DATE_SUB(NOW(), INTERVAL 93 DAY)");
$ok(ReportScheduler::runDue($mysqli, $deps($okPdf))['processed'] === 1, 'quarterly: due after 93 days');

$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='a@example.com', schedule_format='pdf', schedule_owner_user_id=5");
$s = ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => true, 'content' => 'not a pdf']));
$r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($s['failed'] === 1 && $sent === [] && $r['schedule_last_sent'] === null && strpos($r['schedule_last_status'], 'did not return a PDF') !== false, 'a renderer that returns something that is not a PDF fails the run, nothing mailed');
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='a@example.com', schedule_format='pdf', schedule_owner_user_id=5");
$s = ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => true, 'content' => '%PDF-1.7' . str_repeat('x', ReportScheduler::MAX_ATTACHMENT_BYTES)]));
$r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($s['failed'] === 1 && $sent === [] && strpos($r['schedule_last_status'], 'larger than') !== false, 'an oversized PDF fails the run instead of being mailed');

// ---- recipient policy at send time
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='staff@example.com, outsider@evil.example', schedule_format='csv', schedule_owner_user_id=5");
$policy = fn(string $list) => report_schedule_filter_recipients($list, ['staff@example.com'], ['@partner.example'])[0];
ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => true, 'content' => 'x'], ['filter_recipients' => $policy]));
$ok(count($sent) === 1 && $sent[0]['recipient'] === 'staff@example.com', 'send-time policy drops an address that is not staff or approved');

// ---- groups and per-group saved views
$staff = ['cio@example.com', 'coo@example.com', 'lead@example.com', 'tech@example.com'];
$g1 = RecipientGroups::create($mysqli, 5, 'Executives', 'cio@example.com, COO@example.com', $staff, []);
$g2 = RecipientGroups::create($mysqli, 5, 'Service desk', 'lead@example.com; tech@example.com', $staff, []);
$ok(is_int($g1) && is_int($g2), 'two groups created');
$ok(RecipientGroups::get($mysqli, $g1)['group_recipients'] === 'cio@example.com, coo@example.com', 'group recipients are normalised (lower case, comma list)');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // the application's mode (PHP 8.1+ default): errors THROW
$dup = RecipientGroups::create($mysqli, 5, 'Executives', 'cio@example.com', $staff, []);
mysqli_report(MYSQLI_REPORT_OFF);
$ok(is_string($dup) && strpos($dup, 'already exists') !== false, 'duplicate group name refused with a message (also when mysqli throws)');
$ok(is_string(RecipientGroups::create($mysqli, 5, 'Bad', 'cio@example.com, stranger@evil.example', $staff, [])) && strpos(RecipientGroups::create($mysqli, 5, 'Bad', 'stranger@evil.example', $staff, []), 'stranger@evil.example') !== false, 'a group with an address outside the recipient policy is refused and names it');
$ok(is_string(RecipientGroups::create($mysqli, 5, '  ', 'cio@example.com', $staff, [])) && is_string(RecipientGroups::create($mysqli, 5, 'Empty', 'not an email', $staff, [])), 'empty name / no valid address refused');
$ok(is_int(RecipientGroups::create($mysqli, 5, 'Partner', 'x@partner.example', $staff, ['@partner.example'])), 'an administrator-approved domain is accepted');
$vExec = SavedReports::create($mysqli, 5, 'service_desk', 'Exec summary', ['canned_date' => 'lastmonth'], false);
$vTech = SavedReports::create($mysqli, 5, 'technician_performance', 'Tech view', ['canned_date' => 'last7', 'f_priority' => 'High'], false);

$reset();
$ins("schedule_report='service_desk', schedule_frequency='monthly', schedule_recipients='', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
$ok(RecipientGroups::setTargets($mysqli, $sid, [$g1 => $vExec, $g2 => $vTech, 99999 => 0]) === 2, 'setTargets stores two targets and skips an unknown group');
$sum = ReportScheduler::runDue($mysqli, $deps($okPdf));
$ok($sum['processed'] === 1 && $sum['ok'] === 1 && $sum['emails'] === 4, 'targeted schedule with no own recipients runs and mails 4 people');
$ok(count($renders) === 2 && in_array(['service_desk', ['canned_date' => 'lastmonth'], 'pdf'], $renders, true) && in_array(['technician_performance', ['canned_date' => 'last7', 'f_priority' => 'High'], 'pdf'], $renders, true), 'each group\'s saved view is rendered with its own report and filters');
$byRcpt = array_column($sent, null, 'recipient');
$ok(strpos($byRcpt['cio@example.com']['subject'], 'Exec summary') !== false && strpos($byRcpt['tech@example.com']['subject'], 'Tech view') !== false, 'each group gets the subject of its own view');
$ok(!empty($byRcpt['coo@example.com']['attachments']) && !empty($byRcpt['lead@example.com']['attachments']), 'all four messages carry their PDF');
$ok(strpos($byRcpt['cio@example.com']['subject'], 'Service Desk') !== false && strpos($byRcpt['lead@example.com']['subject'], 'Technician Performance') !== false, 'subjects carry the right report name');
$ok(count(ReportScheduler::targets($mysqli, $sid)) === 2, 'targets() lists the two targets');

// own recipients + group, plain report for the group (view 0)
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='lead@example.com', schedule_format='tables', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => 0]);
$sum = ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => true, 'content' => '<table><tr><td>t</td></tr></table>']));
$ok($sum['emails'] === 3 && count($renders) === 2 && $renders[1][0] === 'csat', 'own recipients and a group (own report) are both delivered: ' . $sum['emails']);

// partial failure: the group whose render fails is reported, the other still goes out, schedule is stamped (no duplicate resend)
$reset();
$ins("schedule_report='service_desk', schedule_frequency='daily', schedule_recipients='', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => $vExec, $g2 => $vTech]);
$sum = ReportScheduler::runDue($mysqli, $deps(fn($key) => $key === 'technician_performance' ? ['ok' => false, 'error' => 'denied: role cannot open this report'] : $okPdf()));
$r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($sum['ok'] === 1 && $sum['emails'] === 2 && $r['schedule_last_sent'] !== null && strpos($r['schedule_last_status'], '1 delivery(ies) failed') !== false && strpos($r['schedule_last_status'], 'Service desk') !== false, 'partial failure: ok with a note naming the failed group: ' . $r['schedule_last_status']);
// all fail
$reset();
$ins("schedule_report='service_desk', schedule_frequency='daily', schedule_recipients='', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => $vExec]);
$sum = ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => false, 'error' => 'boom']));
$r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($sum['failed'] === 1 && $r['schedule_last_sent'] === null && strpos($r['schedule_last_status'], 'boom') !== false, 'every target failing fails the run, not stamped, retried next time');
// send-time policy applies to groups
$reset();
$ins("schedule_report='service_desk', schedule_frequency='daily', schedule_recipients='', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => 0]);
$policy2 = fn(string $list) => report_schedule_filter_recipients($list, ['cio@example.com'], [])[0];   // coo left the company
ReportScheduler::runDue($mysqli, $deps($okPdf, ['filter_recipients' => $policy2]));
$ok(count($sent) === 1 && $sent[0]['recipient'] === 'cio@example.com', 'group addresses are re-checked at send time (coo is no longer staff)');
// group with no valid recipient left
$reset();
$ins("schedule_report='service_desk', schedule_frequency='daily', schedule_recipients='', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => 0]);
$sum = ReportScheduler::runDue($mysqli, $deps($okPdf, ['filter_recipients' => fn($l) => []]));
$r = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($sum['failed'] === 1 && $r['schedule_last_status'] === 'error: no valid recipient', 'no valid recipient anywhere: error');
// no targets, no recipients is unchanged
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='', schedule_format='csv', schedule_owner_user_id=5");
ReportScheduler::runDue($mysqli, $deps($okPdf));
$ok(mysqli_fetch_assoc($q("SELECT schedule_last_status AS s FROM report_schedules"))['s'] === 'error: no valid recipient', 'a schedule with neither recipients nor targets still errors as before');

// ---- deleting views / groups / schedules
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='lead@example.com', schedule_format='pdf', schedule_owner_user_id=5");
$sid = (int) $one("SELECT schedule_id FROM report_schedules");
RecipientGroups::setTargets($mysqli, $sid, [$g1 => $vExec, $g2 => $vTech]);
$ok(SavedReports::delete($mysqli, $vExec, 5, false), 'view deleted by owner');
$ok($one("SELECT target_saved_report_id FROM report_schedule_targets WHERE target_group_id = $g1") === null, 'deleting a view un-links it from targets (the group falls back to the schedule\'s own report)');
$ok(!RecipientGroups::delete($mysqli, $g1, 6, false) && RecipientGroups::get($mysqli, $g1) !== null, 'another non-admin user cannot delete a group');
$ok(RecipientGroups::delete($mysqli, $g1, 6, true) && (int) $one("SELECT COUNT(*) FROM report_schedule_targets WHERE target_group_id = $g1") === 0, 'an administrator can; its targets go with it');
$q("DELETE FROM report_schedules");
$ok((int) $one("SELECT COUNT(*) FROM report_schedule_targets") === 0, 'deleting the schedule removes its targets');

// ---- mail queue attachments (real addToMailQueue from functions.php)
rpt_extract_functions(['addToMailQueue']);
$q("DELETE FROM email_queue");
addToMailQueue([
    ['from' => 'noreply@example.com', 'from_name' => 'RivetIT', 'recipient' => 'a@example.com', 'recipient_name' => 'a', 'subject' => 'with pdf', 'body' => '<p>x</p>',
     'attachments' => [['filename' => '../../etc/passwd<bad>.pdf', 'mime' => 'application/pdf', 'content' => $realPdf], ['filename' => 'empty.pdf', 'mime' => 'application/pdf', 'content' => ''], ['filename' => 'odd.bin', 'mime' => "x\r\nBcc: evil", 'content' => 'abc']]],
    ['from' => 'noreply@example.com', 'from_name' => 'RivetIT', 'recipient' => 'b@example.com', 'recipient_name' => 'b', 'subject' => 'plain', 'body' => '<p>y</p>'],
]);
$mid = (int) $one("SELECT email_id FROM email_queue WHERE email_subject = 'with pdf'");
$atts = []; $res = $q("SELECT * FROM email_queue_attachments WHERE attachment_email_id = $mid ORDER BY attachment_id"); while ($a = mysqli_fetch_assoc($res)) { $atts[] = $a; }
$ok(count($atts) === 2, 'two attachments stored (the empty one skipped)');
$ok($atts[0]['attachment_content'] === $realPdf && $atts[0]['attachment_mime'] === 'application/pdf', 'PDF bytes stored intact (binary safe)');
$ok(strpos($atts[0]['attachment_filename'], '/') === false && strpos($atts[0]['attachment_filename'], '<') === false && strpos($atts[0]['attachment_filename'], '..') !== 0, 'file name is stripped of path and markup: ' . $atts[0]['attachment_filename']);
$ok($atts[1]['attachment_mime'] === 'application/octet-stream', 'a malformed mime type (header injection attempt) falls back to octet-stream');
$ok((int) $one("SELECT COUNT(*) FROM email_queue_attachments WHERE attachment_email_id = " . (int) $one("SELECT email_id FROM email_queue WHERE email_subject = 'plain'")) === 0, 'a message without attachments stores none');
$q("DELETE FROM email_queue WHERE email_id = $mid");
$ok((int) $one("SELECT COUNT(*) FROM email_queue_attachments") === 0, 'removing the queued message removes its attachments');

// ---- code deployed before Update Database: no targets table yet, the cron still runs the schedules it always could
$reset();
$ins("schedule_report='csat', schedule_frequency='daily', schedule_recipients='a@example.com', schedule_format='csv', schedule_owner_user_id=5");
$q("RENAME TABLE report_schedule_targets TO report_schedule_targets_hold");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // PHP 8.1+ default: a missing table THROWS
$sum = ReportScheduler::runDue($mysqli, $deps(fn() => ['ok' => true, 'content' => 'x']));
$vdel = SavedReports::create($mysqli, 5, 'csat', 'tmp', [], false);
$ok(SavedReports::delete($mysqli, $vdel, 5, false), 'deleting a saved view does not fatal without the targets table either');
$ok(ReportScheduler::targets($mysqli, 1) === [] && RecipientGroups::all($mysqli) !== null, 'targets() / groups list degrade to empty');
mysqli_report(MYSQLI_REPORT_OFF);
$q("RENAME TABLE report_schedule_targets_hold TO report_schedule_targets");
$ok($sum['processed'] === 1 && $sum['ok'] === 1 && count($sent) === 1, 'without the 2.6.224 tables the cron path still sends ordinary schedules (no fatal)');

foreach (['report_schedules', 'report_exports', 'saved_reports', 'report_recipient_groups', 'email_queue'] as $t) { $q("DELETE FROM $t"); }
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
