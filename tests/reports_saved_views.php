<?php
/*
 * Saved report views, the report catalog whitelist and the shared CSV writer (src/Reports), on a scratch database:
 *   - only whitelisted, validated parameters are stored and replayed (unknown keys, bad dates/ranges/enums dropped);
 *   - ownership: only the owner (or an admin) can rename/share/delete; a non-owner cannot read an unshared view;
 *     a shared view is readable but not editable by others; deleting a view un-links its schedules;
 *   - CSV: formula-injection prefix, quoting, BOM, table scraping.
 */
require __DIR__ . '/reports_bootstrap.php';
use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\ReportExport;
use ITFlow\Reports\SavedReports;

$q("DELETE FROM saved_reports"); $q("DELETE FROM report_schedules");

// --- whitelist
$p = ReportCatalog::sanitizeParams('service_desk', ['canned_date' => 'lastmonth', 'dtf' => '2026-01-01', 'dtt' => '2026-02-30', 'evil' => "1' OR 1=1", 'year' => 2026]);
$ok($p === ['canned_date' => 'lastmonth'], 'unknown keys and an impossible date are dropped, a lone dtf without dtt is dropped');
$p = ReportCatalog::sanitizeParams('service_desk', ['canned_date' => 'custom', 'dtf' => '2026-01-01', 'dtt' => '2026-02-28']);
$ok($p === ['canned_date' => 'custom', 'dtf' => '2026-01-01', 'dtt' => '2026-02-28'], 'a valid custom range is kept');
$ok(ReportCatalog::sanitizeParams('service_desk', ['canned_date' => 'drop table']) === [], 'enum value outside the list is dropped');
$ok(ReportCatalog::sanitizeParams('ticket_summary', ['year' => '2026']) === ['year' => '2026'], 'year accepted');
$ok(ReportCatalog::sanitizeParams('ticket_summary', ['year' => '2026 OR 1=1']) === [], 'year with SQL is dropped');
$ok(ReportCatalog::sanitizeParams('ticket_summary', ['year' => 'all']) === [], 'year=all only where the report allows it');
$ok(ReportCatalog::sanitizeParams('income_by_client', ['year' => 'all']) === ['year' => 'all'], 'year=all allowed on income_by_client');
$ok(ReportCatalog::sanitizeParams('credential_rotation', ['days' => '0']) === [] && ReportCatalog::sanitizeParams('credential_rotation', ['days' => '90']) === ['days' => '90'], 'days range enforced');
$ok(ReportCatalog::sanitizeParams('ticket_by_client', ['month' => '13', 'year' => '2026']) === ['year' => '2026'], 'month out of range dropped');
$ok(ReportCatalog::sanitizeParams('ticket_by_client', ['month' => ['1'], 'year' => ['2026']]) === [], 'array values are dropped');
$ok(ReportCatalog::sanitizeParams('ticket_charges', ['uninvoiced' => '1']) === ['uninvoiced' => '1'], 'flag kept');
$ok(ReportCatalog::sanitizeParams('no_such_report', ['year' => '2026']) === [], 'unknown report has no parameters');
$ok(ReportCatalog::url('csat', ['canned_date' => 'thisyear', 'x' => 'y']) === '/agent/reports/csat.php?canned_date=thisyear', 'url() only emits whitelisted params');
$ok(ReportCatalog::keyFromScript('/agent/reports/csat.php') === 'csat' && ReportCatalog::keyFromScript('/agent/reports/schedules.php') === null, 'keyFromScript');
// every catalogued report has a page
$missing = array_filter(ReportCatalog::keys(), fn($k) => !is_file(__DIR__ . "/../agent/reports/$k.php"));
$ok($missing === [], 'every catalogued report has a page' . ($missing ? ': ' . implode(',', $missing) : ''));

// --- ownership / IDOR
$alice = 101; $bob = 102;
$id = SavedReports::create($mysqli, $alice, 'service_desk', "  Q1 \x07 view\n ", ['canned_date' => 'lastmonth', 'junk' => 'x'], false);
$ok(is_int($id), 'create returns an id');
$row = SavedReports::get($mysqli, $id);
$ok($row['saved_report_name'] === 'Q1 view' && $row['params'] === ['canned_date' => 'lastmonth'], 'name cleaned, params whitelisted');
$ok(strpos($one("SELECT saved_report_params FROM saved_reports WHERE saved_report_id = $id"), 'junk') === false, 'junk key never reaches the table');
$ok(!SavedReports::canView($row, $bob), 'another user cannot view an unshared view');
$ok(SavedReports::listFor($mysqli, $bob, 'service_desk') === [], 'it is not in the other user\'s list');
$ok(!SavedReports::update($mysqli, $id, $bob, false, 'hijack', true), 'non-owner cannot rename/share');
$ok(!SavedReports::delete($mysqli, $id, $bob, false) && SavedReports::get($mysqli, $id) !== null, 'non-owner cannot delete');
$ok(SavedReports::update($mysqli, $id, $alice, false, 'Renamed', true), 'owner renames and shares');
$row = SavedReports::get($mysqli, $id);
$ok(SavedReports::canView($row, $bob) && !SavedReports::canEdit($row, $bob, false), 'shared view: readable, not editable by others');
$ok(count(SavedReports::listFor($mysqli, $bob, 'service_desk')) === 1, 'shared view listed for the other user');
$ok(SavedReports::listFor($mysqli, $bob, 'service_desk', fn($m) => $m !== 'module_support') === [], 'listing hides reports whose module the user cannot read');
$ok(is_string(SavedReports::create($mysqli, $alice, 'nope', 'x', [], false)) && is_string(SavedReports::create($mysqli, $alice, 'csat', '   ', [], false)), 'unknown report / empty name rejected');
// tampered stored params are re-validated on read
$q("UPDATE saved_reports SET saved_report_params = '{\"canned_date\":\"x\\\\\"; DROP\",\"year\":\"2020\"}' WHERE saved_report_id = $id");
$ok(SavedReports::get($mysqli, $id)['params'] === [], 'a hand-edited row is re-validated when loaded');
$q("INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients, schedule_saved_report_id, schedule_format) VALUES ('service_desk','daily','a@example.com',$id,'csv')");
$ok(SavedReports::delete($mysqli, $id, $bob, true), 'admin may delete');
$ok($one("SELECT schedule_saved_report_id IS NULL FROM report_schedules LIMIT 1") == 1, 'deleting a view un-links its schedule');

// --- CSV
$ok(ReportExport::cell('=1+1') === "'=1+1" && ReportExport::cell('+SUM(A1)') === "'+SUM(A1)" && ReportExport::cell('@x') === "'@x" && ReportExport::cell('-cmd') === "'-cmd" && ReportExport::cell("\tx") === "'\tx", 'formula prefixes are neutralised');
$ok(ReportExport::cell(-5) === -5 && ReportExport::cell('-5.50') === '-5.50' && ReportExport::cell(12.5) === 12.5 && ReportExport::cell('abc') === 'abc' && ReportExport::cell(null) === '', 'numbers and plain text untouched');
$csv = ReportExport::toCsv(['Name', 'Note'], [['A, "quoted"', "line1\nline2"], ['=HYPERLINK("x")', 'ok']]);
$ok(strpos($csv, "\xEF\xBB\xBF") === 0, 'BOM present');
$parsed = array_map('str_getcsv', explode("\n", trim(substr($csv, 3), "\n")));
$ok(strpos($csv, '"A, ""quoted"""') !== false && strpos($csv, "'=HYPERLINK") !== false, 'quoting and injection prefix in output');
$ok(strpos(ReportExport::toCsv(['a'], [['b']], false), "\xEF\xBB\xBF") === false, 'BOM optional');
$html = '<div><h3 class="card-title">Totals</h3><table><thead><tr><th>Client</th><th>Amt</th></tr></thead><tbody><tr><td>=evil()</td><td> 5 </td></tr><tr><td colspan="2"></td></tr></tbody></table><table><tr><td>x</td></tr></table></div>';
$rows = ReportExport::fromHtml($html);
$ok($rows[0] === ['Totals'] && $rows[1] === ['Client', 'Amt'] && $rows[2] === ['=evil()', '5'] && in_array([], $rows, true), 'tables scraped with caption, header and a blank row between tables');
$ok(strpos(ReportExport::csvFromHtml($html, false), "'=evil()") !== false, 'scraped cells get the injection prefix too');
$ok(ReportExport::fromHtml('<p>none</p>') === [], 'no table, no rows');
$ok(ReportExport::filename('../../etc/passwd') === 'etc_passwd.csv', 'filename sanitised');
$ok(strpos(ReportExport::emailHtmlFromRows('<b>T</b>', [['<script>'], ['h1', 'h2'], ['v1', '<img>']]), '<script>') === false, 'email html escapes cell text');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
