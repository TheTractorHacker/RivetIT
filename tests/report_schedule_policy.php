<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Scheduled report recipient/module policy (pentest IT-8) plus wiring checks on the page and cron. No DB, no network. */
require __DIR__ . '/../includes/report_schedule_policy.php';
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

foreach (['income_summary', 'expense_summary', 'clients_with_balance', 'mrr'] as $k) {
    $ok(report_schedule_required_module($k) === 'module_financial', "$k requires module_financial");
}
foreach (['service_desk', 'ticket_summary', 'csat', 'technician_performance', 'bogus'] as $k) {
    $ok(report_schedule_required_module($k) === null, "$k needs no extra module");
}

$staff = ['ann@msp.example', 'bob@msp.example'];
$allow = report_schedule_parse_allowlist("Owner@Partner.example; @accounting.example, not-an-email, @bad_domain, @x");
$ok($allow === ['owner@partner.example', '@accounting.example'], 'allowlist keeps addresses and @domains only');

$ok(report_schedule_recipient_allowed('ANN@msp.example', $staff, $allow), 'staff address allowed (case-insensitive)');
$ok(report_schedule_recipient_allowed('owner@partner.example', $staff, $allow), 'approved address allowed');
$ok(report_schedule_recipient_allowed('cfo@accounting.example', $staff, $allow), 'approved domain allowed');
$ok(!report_schedule_recipient_allowed('evil@attacker.example', $staff, $allow), 'unknown external address refused');
$ok(!report_schedule_recipient_allowed('x@sub.accounting.example', $staff, $allow), 'subdomain of approved domain refused');
$ok(!report_schedule_recipient_allowed('x@accounting.example.evil.test', $staff, $allow), 'suffix trick refused');
$ok(!report_schedule_recipient_allowed('evil@attacker.example', [], []), 'nothing approved => refused');
$ok(!report_schedule_recipient_allowed('', $staff, $allow) && !report_schedule_recipient_allowed('nope', $staff, $allow), 'empty/invalid refused');

[$a, $r] = report_schedule_filter_recipients('ann@msp.example, evil@attacker.example; ann@msp.example', $staff, $allow);
$ok($a === ['ann@msp.example'] && $r === ['evil@attacker.example'], 'filter splits allowed/refused and de-duplicates');

$page = file_get_contents(__DIR__ . '/../agent/reports/schedules.php');
$cron = file_get_contents(__DIR__ . '/../cron/report_scheduler.php');
$ok(strpos($page, "enforceUserPermission('module_reporting', 2)") !== false, 'page: adding a schedule needs Reporting edit rights');
$ok(strpos($page, 'report_schedule_filter_recipients') !== false && strpos($page, 'schedule_owner_user_id') !== false, 'page: filters recipients and records the owner');
$ok(strpos($page, 'enforceAdminPermission()') !== false, 'page: only admins edit the approved list');
$ok(strpos($cron, 'report_schedule_owner_may_send') !== false && strpos($cron, 'report_schedule_filter_recipients') !== false, 'cron: re-checks owner and recipients on every send');
exit($fails ? 1 : 0);
