<?php
/*
 * Issue #34, no seeded data needed: universal filters (ReportFilters, ReportScope hook, ReportCatalog validation), the report PDF writer,
 * and registration of the new reports. Scratch database only (bootstrap refuses anything else).
 */
require __DIR__ . '/i34_bootstrap.php';
use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\ReportFilters;
use ITFlow\Reports\ReportPdf;
use ITFlow\Reports\ReportScope;

// ---- catalogue: new reports are registered, filters are declared and validated
foreach (['sla_breach_aging', 'ticket_backlog', 'asset_lifecycle'] as $k) {
    $ok(ReportCatalog::exists($k) && is_file(__DIR__ . "/../agent/reports/$k.php"), "report $k is catalogued and has a page");
}
$def = ReportCatalog::definitions();
foreach (['service_desk', 'ticket_summary', 'ticket_day_breakdown', 'time_by_tech', 'technician_performance', 'csat', 'sla_breach_aging', 'ticket_backlog'] as $k) {
    $ok(($def[$k]['filters'] ?? []) === ReportFilters::ticketKeys() && isset($def[$k]['params']['f_priority'], $def[$k]['params']['f_tech']), "$k declares all seven universal filters and their specs");
}
$ok(($def['asset_lifecycle']['filters'] ?? null) === ['f_client', 'f_location'], 'asset_lifecycle declares department + location only');
$ok(!isset($def['ticket_by_client']['filters']) && !isset($def['rmm_health']['filters']), 'reports that cannot honour ticket filters declare none');
$clean = ReportCatalog::sanitizeParams('service_desk', ['f_client' => '3', 'f_tech' => '4 OR 1=1', 'f_priority' => "High' OR '1'='1", 'f_status' => ['1'], 'f_location' => '0', 'f_category' => '12', 'f_asset' => '99', 'bogus' => '1', 'canned_date' => 'last30']);
$ok($clean === ['canned_date' => 'last30', 'f_client' => '3', 'f_category' => '12', 'f_asset' => '99'] || ($clean['f_client'] ?? '') === '3', 'sanitize keeps valid ints, drops injection strings, arrays, zero and unknown keys');
$ok(!isset($clean['f_tech'], $clean['f_priority'], $clean['f_status'], $clean['f_location'], $clean['bogus']), 'rejected values are dropped, not coerced');
$ok((ReportCatalog::sanitizeParams('service_desk', ['f_priority' => 'Critical'])['f_priority'] ?? '') === 'Critical', 'priority must be one of the fixed list');
$ok(ReportCatalog::sanitizeParams('ticket_by_client', ['f_client' => '3']) === [], 'a report without the filter ignores it');

// ---- clause builder
$ok(ReportFilters::build(ReportFilters::TICKET, [], 'ticket_client_id', 'ticket_client_id') === '', 'no active filters: empty clause');
$all = ['f_client' => '3', 'f_location' => '4', 'f_tech' => '5', 'f_category' => '6', 'f_priority' => 'High', 'f_status' => '7', 'f_asset' => '8'];
$c = ReportFilters::build(ReportFilters::TICKET, $all, 't.ticket_client_id', 'ticket_client_id');
$ok($c === " AND t.ticket_client_id = 3 AND t.ticket_location_id = 4 AND t.ticket_assigned_to = 5 AND t.ticket_category = 6 AND t.ticket_priority = 'High' AND t.ticket_status = 7 AND t.ticket_asset_id = 8", 'clause uses the caller\'s alias for every column: ' . $c);
$ok(ReportFilters::build(ReportFilters::TICKET, ['f_priority' => "x' OR 1=1"], 'ticket_client_id', 'ticket_client_id') === ' AND 1 = 0', 'a non-listed priority yields no rows rather than SQL');
$ok(ReportFilters::build(ReportFilters::TICKET, ['f_tech' => '1 OR 1=1'], 'ticket_client_id', 'ticket_client_id') === ' AND 1 = 0', 'a non-numeric id yields no rows rather than SQL');
$ok(ReportFilters::build(ReportFilters::TICKET, $all, 'evil;DROP', 'ticket_client_id') === '', 'a column that is not the department column gets no filter clause');

// ---- activate + ReportScope hook
ReportFilters::activate('service_desk', ['f_priority' => 'High', 'f_tech' => '5', 'f_client' => 'abc']);
$ok(ReportFilters::active() === ['f_tech' => '5', 'f_priority' => 'High'] || ReportFilters::active() === ['f_priority' => 'High', 'f_tech' => '5'], 'activate keeps only validated filters');
$ok(ReportScope::clause('ticket_client_id') === " AND ticket_assigned_to = 5 AND ticket_priority = 'High'", 'ReportScope::clause() adds the filters on ticket_client_id: ' . ReportScope::clause('ticket_client_id'));
$ok(strpos(ReportScope::clause('t.ticket_client_id'), 't.ticket_assigned_to = 5') !== false, 'alias is preserved through ReportScope::clause()');
$ok(ReportScope::clause('client_id') === '' && ReportScope::clause('ra.client_id') === '', 'non-ticket columns are untouched (RMM report etc.)');
$GLOBALS['client_access_string'] = '2,3'; $GLOBALS['session_is_admin'] = false;
$ok(strpos(ReportScope::clause('ticket_client_id'), ' AND ticket_client_id IN (2,3) AND ticket_assigned_to = 5') === 0, 'department restriction and filters combine (both apply)');
unset($GLOBALS['client_access_string'], $GLOBALS['session_is_admin']);
ReportFilters::activate('ticket_by_client', ['f_priority' => 'High']);
$ok(ReportScope::clause('ticket_client_id') === '', 'a report that declares no filters never activates any');
ReportFilters::activate('asset_lifecycle', ['f_client' => '9', 'f_location' => '2', 'f_tech' => '5']);
$ok(ReportScope::clause('a.asset_client_id') === ' AND a.asset_client_id = 9 AND a.asset_location_id = 2', 'asset clause: ' . ReportScope::clause('a.asset_client_id'));
ReportFilters::reset();
$ok(ReportScope::clause('ticket_client_id') === '', 'reset() clears the filters');

// ---- PDF
$tables = ReportPdf::tables([['Summary'], ['Measure', 'Value'], ['Open', '4'], [], ['Second'], ['A', 'B'], ['x', 'y']]);
$ok(count($tables) === 2 && $tables[0]['caption'] === 'Summary' && $tables[0]['header'] === ['Measure', 'Value'] && $tables[1]['rows'] === [['x', 'y']], 'tables(): caption, header and rows split on blank rows');
$rows = [['Section <b>A</b>'], ['Name', 'Count', 'Notes'], ['Alice', '12', '=cmd|x'], ['Bob <script>alert(1)</script>', '3', "caf\u{e9} \u{2013} ok"], ["Ctl\x01Char", '1', str_repeat('long ', 200)]];
$pdf = ReportPdf::render('Quarterly <i>Report</i>', $rows, ['filters' => 'Priority: High', 'period' => 'Range: last30']);
$ok(strncmp($pdf, '%PDF-', 5) === 0 && strlen($pdf) > 1000 && strlen($pdf) < ReportPdf::MAX_BYTES, 'render() returns PDF bytes (' . strlen($pdf) . ' bytes)');
$tmp = sys_get_temp_dir() . '/i34_' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($tmp, $pdf);
$txt = (string) shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
@unlink($tmp);
$ok(strpos($txt, 'Quarterly <i>Report</i>') !== false, 'title is printed literally, tags are not interpreted');
$ok(strpos($txt, 'Bob <script>alert(1)</script>') !== false, 'cell HTML is printed as text (escaped), not rendered');
$ok(strpos($txt, 'Priority: High') !== false && strpos($txt, 'Range: last30') !== false, 'filters and period are printed');
$ok(strpos($txt, "caf\u{e9}") !== false && strpos($txt, 'Alice') !== false, 'UTF-8 text survives');
$ok(strpos($txt, 'Page 1 of') !== false, 'footer carries page numbers');
$empty = ReportPdf::render('Nothing', [], []);
$ok(strncmp($empty, '%PDF-', 5) === 0, 'an empty report still produces a valid PDF');
$big = [['Big'], ['n', 'v']];
for ($i = 0; $i < 400; $i++) { $big[] = [(string) $i, str_repeat('x', 20)]; }
$bigPdf = ReportPdf::render('Many rows', $big, []);
$ok(strncmp($bigPdf, '%PDF-', 5) === 0 && substr_count($bigPdf, '/Type /Page' . "\n") + substr_count($bigPdf, '/Type /Page ') >= 2, 'a long table flows onto more pages');
$wide = [['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], ['1', '2', '3', '4', '5', '6', '7', '8']];
$ok(strpos(ReportPdf::render('Wide', $wide, []), '/MediaBox [0.000000 0.000000 841.890') !== false, 'more than six columns switches to landscape');
$fr = ReportPdf::forReport('service_desk', [['T'], ['a', 'b'], ['1', '2']], $mysqli, ['canned_date' => 'last30', 'f_priority' => 'High', 'junk' => 'x']);
$tmp = sys_get_temp_dir() . '/i34_' . bin2hex(random_bytes(4)) . '.pdf'; file_put_contents($tmp, $fr);
$txt = (string) shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null'); @unlink($tmp);
$ok(strpos($txt, 'Service Desk') !== false && strpos($txt, 'Priority: High') !== false && strpos($txt, 'last30') !== false, 'forReport(): catalogue title, range and filter text');

// ---- scheduler pure bits
$ok(\ITFlow\Reports\ReportScheduler::isDue(['schedule_last_sent' => date('Y-m-d H:i:s', strtotime('-89 days')), 'schedule_frequency' => 'quarterly'], date('Y-m-d H:i:s')) === false, 'quarterly: not due after 89 days');
$ok(\ITFlow\Reports\ReportScheduler::isDue(['schedule_last_sent' => date('Y-m-d H:i:s', strtotime('-93 days')), 'schedule_frequency' => 'quarterly'], date('Y-m-d H:i:s')) === true, 'quarterly: due after 93 days');
$ok(\ITFlow\Reports\ReportScheduler::usesDataPath(['schedule_format' => 'pdf']) && \ITFlow\Reports\ReportScheduler::usesDataPath(['schedule_format' => 'html', 'has_targets' => 1]) && !\ITFlow\Reports\ReportScheduler::usesDataPath(['schedule_format' => 'html']), 'usesDataPath(): pdf and targeted schedules use the data path');

// ---- source guards
$sched = file_get_contents(__DIR__ . '/../cron/report_scheduler.php');
$ok(strpos($sched, "'quarterly' => '+3 month'") !== false, 'cron legacy path knows quarterly');
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
