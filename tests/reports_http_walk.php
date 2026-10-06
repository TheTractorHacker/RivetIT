<?php
/*
 * HTTP walk of the reporting and dashboard flows against a throwaway instance (scratch DB + `php -S`) with FORGED session
 * files, no real login and no email. Expects (see the header of this file's commit / docs/REPORTING.md):
 *   RIVETIT_WALK_BASE=http://127.0.0.1:PORT  sessions named user11 (administrator), user12 (department-restricted, Reporting
 *   modify, restricted to client 1), user13 (Reporting read-only) with csrf tokens csrftok11/12/13, and the scratch DB env.
 * Seeds its own rows in the scratch DB. Refuses non-scratch databases.
 */
require __DIR__ . '/reports_bootstrap.php';
$base = rtrim((string) getenv('RIVETIT_WALK_BASE'), '/');
if ($base === '') { echo "set RIVETIT_WALK_BASE\n"; exit(2); }

function http(string $method, string $path, string $user, array $post = []): array {
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => "PHPSESSID=$user", CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $r = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr((string) $raw, 0, $hs), 'body' => substr((string) $raw, $hs)];
    curl_close($ch);
    return $r;
}
$loc = fn(array $r) => preg_match('/^Location: (.*)$/mi', $r['headers'], $m) ? trim($m[1]) : '';
$tok = fn(string $u) => 'csrftok' . substr($u, 4);

// --- seed
foreach (['tickets', 'saved_reports', 'report_schedules', 'dashboard_layouts', 'report_exports', 'recurring_invoices', 'clients'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients (client_id, client_name) VALUES (1,'Dept A'),(2,'Dept B')");
$q("INSERT INTO ticket_statuses (ticket_status_id, ticket_status_name, ticket_status_color) VALUES (1,'Open','#000') ON DUPLICATE KEY UPDATE ticket_status_name = 'Open'");
foreach ([[1, 1], [1, 2], [2, 3], [2, 4], [2, 5]] as [$c, $n]) {
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='s$n', ticket_status=1, ticket_priority='High', ticket_client_id=$c, ticket_created_at=NOW()");
}
$q("INSERT INTO recurring_invoices SET recurring_invoice_scope='', recurring_invoice_frequency='month', recurring_invoice_status=1, recurring_invoice_amount=50, recurring_invoice_client_id=1, recurring_invoice_next_date=CURDATE()");
$q("INSERT INTO recurring_invoices SET recurring_invoice_scope='', recurring_invoice_frequency='month', recurring_invoice_status=1, recurring_invoice_amount=500, recurring_invoice_client_id=2, recurring_invoice_next_date=CURDATE()");
$year = date('Y');

// --- toolbar, export, print
$r = http('GET', "/agent/reports/ticket_summary.php?year=$year", 'user11');
$ok($r['code'] === 200 && strpos($r['body'], 'Save this view') !== false && strpos($r['body'], 'Export CSV') !== false && strpos($r['body'], 'Print / PDF') !== false, 'report page shows the toolbar (save view, CSV, print/PDF)');
$ok(strpos($r['body'], 'export=csv') !== false && strpos($r['body'], "year=$year") !== false, 'toolbar CSV link carries the current filters');
$r = http('GET', "/agent/reports/ticket_summary.php?year=$year&print=1", 'user11');
$ok($r['code'] === 200 && strpos($r['body'], 'window.print()') !== false && strpos($r['body'], 'id="rpt-save-view"') === false, 'print view auto-prints and drops the toolbar');
$r = http('GET', "/agent/reports/ticket_summary.php?year=$year&export=csv", 'user11');
$ok($r['code'] === 200 && stripos($r['headers'], 'text/csv') !== false && strpos($r['body'], "\xEF\xBB\xBF") === 0 && strpos($r['body'], '<html') === false && strpos($r['body'], 'Tickets raised') !== false, 'native CSV export still works');
$r = http('GET', "/agent/reports/recurring_by_client.php?export=csv", 'user11');
$ok($r['code'] === 200 && stripos($r['headers'], 'text/csv') !== false && strpos($r['body'], 'Dept A') !== false && strpos($r['body'], 'Dept B') !== false && stripos($r['body'], '<div') === false, 'a report with no CSV of its own now exports its table (fallback)');
$r = http('GET', "/agent/reports/ticket_by_client.php?year=$year&export=csv", 'user11');
$ok($r['code'] === 200 && stripos($r['headers'], 'text/csv') !== false && strpos($r['body'], 'Dept A') !== false, 'ticket_by_client exports CSV');
$r = http('GET', "/agent/reports/recurring_by_client.php?export=csv", 'user13');
$ok($r['code'] === 403, 'recurring_by_client CSV still needs the report\'s own module (read-only support user is denied)');

// --- saved views
$name = '<script>alert(1)</script> Q';
$r = http('POST', '/agent/reports/saved_views.php', 'user11', ['csrf_token' => 'wrong', 'action' => 'save', 'report' => 'ticket_summary', 'name' => 'x', 'p' => ['year' => $year]]);
$ok((int) $one("SELECT COUNT(*) FROM saved_reports") === 0, 'save without a valid CSRF token is rejected');
$r = http('POST', '/agent/reports/saved_views.php', 'user11', ['csrf_token' => 'csrftok11', 'action' => 'save', 'report' => 'ticket_summary', 'name' => $name, 'p' => ['year' => $year, 'evil' => "1' OR '1'='1"]]);
$id = (int) $one("SELECT saved_report_id FROM saved_reports LIMIT 1");
$ok($id > 0 && $loc($r) === "/agent/reports/ticket_summary.php?year=$year" && strpos((string) $one("SELECT saved_report_params FROM saved_reports"), 'evil') === false, 'save view: stored, filters whitelisted, redirects back to the report');
$r = http('GET', "/agent/reports/saved_views.php?go=$id", 'user11');
$ok($r['code'] === 302 && $loc($r) === "/agent/reports/ticket_summary.php?year=$year", 'opening a saved view redirects to the report with its filters');
$r = http('GET', "/agent/reports/ticket_summary.php", 'user11');
$ok(strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt; Q') !== false, 'view name is escaped in the saved-view dropdown');
$r = http('GET', "/agent/reports/saved_views.php", 'user11');
$ok(strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;') !== false, 'view name is escaped on the manage page');
// IDOR: another user, unshared
$r = http('GET', "/agent/reports/saved_views.php?go=$id", 'user13');
$ok(strpos($loc($r), 'ticket_summary.php?year') === false, 'another user cannot open an unshared view');
$r = http('POST', '/agent/reports/saved_views.php', 'user13', ['csrf_token' => 'csrftok13', 'action' => 'delete', 'id' => $id]);
$r2 = http('POST', '/agent/reports/saved_views.php', 'user13', ['csrf_token' => 'csrftok13', 'action' => 'update', 'id' => $id, 'name' => 'pwned', 'shared' => 1]);
$ok((int) $one("SELECT COUNT(*) FROM saved_reports WHERE saved_report_id = $id AND saved_report_name NOT LIKE 'pwned' AND saved_report_shared = 0") === 1, 'another user can neither delete, rename nor share it');
$r = http('POST', '/agent/reports/saved_views.php', 'user11', ['csrf_token' => 'csrftok11', 'action' => 'update', 'id' => $id, 'name' => 'Shared year', 'shared' => 1]);
$ok($one("SELECT saved_report_shared FROM saved_reports WHERE saved_report_id = $id") == 1, 'owner shares it');
$r = http('GET', "/agent/reports/saved_views.php?go=$id", 'user13');
$ok($loc($r) === "/agent/reports/ticket_summary.php?year=$year", 'shared view opens for another Reporting user');
$r = http('POST', '/agent/reports/saved_views.php', 'user13', ['csrf_token' => 'csrftok13', 'action' => 'delete', 'id' => $id]);
$ok((int) $one("SELECT COUNT(*) FROM saved_reports") === 1, 'a shared view still cannot be deleted by a non-owner');
// a view of a report the user's role cannot read cannot be saved
$r = http('POST', '/agent/reports/saved_views.php', 'user13', ['csrf_token' => 'csrftok13', 'action' => 'save', 'report' => 'profit_loss', 'name' => 'fin', 'p' => []]);
$ok((int) $one("SELECT COUNT(*) FROM saved_reports WHERE saved_report_key = 'profit_loss'") === 0, 'cannot save a view of a report the role cannot open');
$r = http('POST', '/agent/reports/saved_views.php', 'user13', ['csrf_token' => 'csrftok13', 'action' => 'save', 'report' => 'ticket_summary', 'name' => 'Mine', 'p' => ['year' => $year]]);
$mine = (int) $one("SELECT saved_report_id FROM saved_reports WHERE saved_report_user_id = 13");
$ok($mine > 0, 'a read-only Reporting user can save their own view');
$r = http('POST', '/agent/reports/saved_views.php', 'user11', ['csrf_token' => 'csrftok11', 'action' => 'delete', 'id' => $mine]);
$ok((int) $one("SELECT COUNT(*) FROM saved_reports WHERE saved_report_id = $mine") === 0, 'administrator may delete any view');

// --- department restriction through the pages
$r = http('GET', "/agent/reports/ticket_summary.php?year=$year&export=csv", 'user12');
preg_match('/Tickets raised[^\n]*\n.*?\n/s', $r['body'], $mm);
$tot = function (string $csv) { foreach (preg_split('/\r?\n/', $csv) as $l) { if (stripos($l, 'total') === 0) return $l; } return ''; };
$ra = http('GET', "/agent/reports/ticket_summary.php?year=$year&export=csv", 'user11');
$sumcol = fn(string $csv) => array_sum(array_map(fn($l) => (int) (str_getcsv($l)[1] ?? 0), array_slice(array_filter(preg_split('/\r?\n/', $csv)), 1, 12)));
$ok($sumcol($ra['body']) === 5 && $sumcol($r['body']) === 2, 'ticket_summary CSV: administrator sees 5 tickets, restricted user only their department\'s 2');
$r = http('GET', "/agent/reports/recurring_by_client.php?export=csv", 'user12');
$ok(strpos($r['body'], 'Dept A') !== false && strpos($r['body'], 'Dept B') === false, 'recurring_by_client: restricted user sees only their department');
$r = http('GET', "/agent/reports/ticket_by_client.php?year=$year&export=csv", 'user12');
$ok(strpos($r['body'], 'Dept A') !== false && strpos($r['body'], 'Dept B') === false, 'ticket_by_client: restricted user sees only their department');
$r = http('GET', "/agent/reports/profit_loss.php", 'user12');
$ok($r['code'] === 403, 'company-wide profit & loss is refused for a department-restricted user');
$r = http('GET', "/agent/reports/profit_loss.php", 'user11');
$ok($r['code'] === 200, '...and still opens for the administrator');
$r = http('GET', "/agent/reports/", 'user12');
$ok($r['code'] === 200 && strpos($r['body'], 'profit_loss.php') === false, 'hub hides company-wide reports from a restricted user');

// --- schedules
$post = ['add_schedule' => 1, 'schedule_source' => 'r:service_desk', 'schedule_delivery' => 'csv', 'schedule_frequency' => 'daily', 'schedule_recipients' => 'ops@example.com'];
http('POST', '/agent/reports/schedules.php', 'user13', $post + ['csrf_token' => 'csrftok13']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules") === 0, 'Reporting level 1 cannot add a schedule');
http('POST', '/agent/reports/schedules.php', 'user12', $post + ['csrf_token' => 'nope']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules") === 0, 'adding a schedule without a CSRF token is rejected');
http('POST', '/agent/reports/schedules.php', 'user12', $post + ['csrf_token' => 'csrftok12']);
$s = mysqli_fetch_assoc($q("SELECT * FROM report_schedules"));
$ok($s && $s['schedule_format'] === 'csv' && (int) $s['schedule_owner_user_id'] === 12 && $s['schedule_report'] === 'service_desk', 'Reporting level 2 adds a CSV schedule owned by them');
http('POST', '/agent/reports/schedules.php', 'user12', ['add_schedule' => 1, 'schedule_source' => 'r:profit_loss', 'schedule_delivery' => 'csv', 'schedule_frequency' => 'daily', 'schedule_recipients' => 'ops@example.com', 'csrf_token' => 'csrftok12']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules") === 1 || (int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_report = 'profit_loss'") === 1, 'schedule of a report the role can read (financial level 1) is accepted');
http('POST', '/agent/reports/schedules.php', 'user12', ['add_schedule' => 1, 'schedule_source' => 'r:credential_rotation', 'schedule_delivery' => 'csv', 'schedule_frequency' => 'daily', 'schedule_recipients' => 'ops@example.com', 'csrf_token' => 'csrftok12']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_report = 'credential_rotation'") === 0, 'cannot schedule a report whose module the role cannot read');
http('POST', '/agent/reports/schedules.php', 'user12', ['add_schedule' => 1, 'schedule_source' => 'v:' . $id, 'schedule_delivery' => 'summary', 'schedule_frequency' => 'daily', 'schedule_recipients' => 'ops@example.com', 'csrf_token' => 'csrftok12']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_saved_report_id IS NOT NULL") === 0, 'a saved view cannot be a headline-summary schedule');
http('POST', '/agent/reports/schedules.php', 'user12', ['add_schedule' => 1, 'schedule_source' => 'v:' . $id, 'schedule_delivery' => 'csv', 'schedule_frequency' => 'weekly', 'schedule_recipients' => 'ops@example.com', 'csrf_token' => 'csrftok12']);
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_saved_report_id = $id") === 1, 'a shared saved view can be scheduled as CSV');
$sid = (int) $one("SELECT schedule_id FROM report_schedules WHERE schedule_report = 'service_desk' AND schedule_saved_report_id IS NULL");
$r = http('GET', "/agent/reports/schedules.php?delete=$sid&csrf_token=csrftok13", 'user13');
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_id = $sid") === 1, 'Reporting level 1 cannot delete a schedule');
$r = http('GET', "/agent/reports/schedules.php?toggle=$sid&csrf_token=csrftok13", 'user13');
$ok($one("SELECT schedule_active FROM report_schedules WHERE schedule_id = $sid") == 1, 'Reporting level 1 cannot pause a schedule');
$r = http('GET', "/agent/reports/schedules.php?delete=$sid", 'user12');
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_id = $sid") === 1, 'delete without a CSRF token is rejected');
$r = http('GET', "/agent/reports/schedules.php", 'user13');
$ok($r['code'] === 200 && strpos($r['body'], 'needs Reporting modify access') !== false && strpos($r['body'], 'name="add_schedule"') === false, 'level 1 sees the list but no add form');
$q("INSERT INTO report_schedules SET schedule_report='csat', schedule_frequency='daily', schedule_recipients='x@example.com', schedule_format='csv', schedule_owner_user_id=11");
$other = (int) $one("SELECT schedule_id FROM report_schedules WHERE schedule_owner_user_id = 11");
http('GET', "/agent/reports/schedules.php?delete=$other&csrf_token=csrftok12", 'user12');
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_id = $other") === 1, 'a level-2 user cannot delete a schedule owned by someone else');
http('GET', "/agent/reports/schedules.php?delete=$sid&csrf_token=csrftok12", 'user12');
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_id = $sid") === 0, 'the owner deletes their schedule');
http('GET', "/agent/reports/schedules.php?delete=$other&csrf_token=csrftok11", 'user11');
$ok((int) $one("SELECT COUNT(*) FROM report_schedules WHERE schedule_id = $other") === 0, 'an administrator deletes any schedule');

// --- download link
$t = \ITFlow\Reports\ReportScheduler::storeExport($mysqli, null, 'walk.csv', "a,b\n1,2\n");
$r = http('GET', "/guest/report_download.php?t=$t", 'none');
$ok($r['code'] === 200 && stripos($r['headers'], 'text/csv') !== false && stripos($r['headers'], 'no-store') !== false && trim($r['body']) === "a,b\n1,2", 'emailed link downloads the CSV without a login');
$r = http('GET', "/guest/report_download.php?t=" . str_repeat('0', 64), 'none');
$ok($r['code'] === 404, 'a wrong token is a 404');

// --- headless render as the owner (the scheduler's child process): CSV for a native-export report, and denial
$render = function (int $user, string $report, string $fmt = 'csv', array $params = []): array {
    $cmd = [PHP_BINARY, __DIR__ . '/../scripts/report_render.php', "--user=$user", "--report=$report", "--format=$fmt", '--params=' . base64_encode(json_encode($params))];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
};
$h = $render(12, 'recurring_by_client');
$ok($h['code'] === 0 && strpos($h['out'], 'Dept A') !== false && strpos($h['out'], 'Dept B') === false, 'headless render as the restricted owner: CSV has only their department');
$h = $render(11, 'recurring_by_client');
$ok($h['code'] === 0 && strpos($h['out'], 'Dept A') !== false && strpos($h['out'], 'Dept B') !== false, 'headless render as the administrator: both departments');
$h = $render(12, 'ticket_summary', 'csv', ['year' => $year]);
$ok($h['code'] === 0 && strpos($h['out'], 'Tickets raised') !== false && strpos($h['out'], '<') === false, 'headless render of a report with its own CSV export');
$h = $render(12, 'ticket_by_client', 'html', ['year' => $year]);
$ok($h['code'] === 0 && strpos($h['out'], '<table') !== false && strpos($h['out'], 'Dept A') !== false && strpos($h['out'], 'Dept B') === false && strpos($h['out'], '<script') === false, 'headless html format: tables only, department-restricted, no script');
$h = $render(13, 'recurring_by_client');
$ok($h['code'] === 2 && trim($h['out']) === '', 'headless render as a user without the module is refused (nothing emitted)');
$h = $render(999, 'csat');
$ok($h['code'] === 2 && trim($h['out']) === '', 'headless render as a missing user fails');
$h = $render(12, 'not_a_report');
$ok($h['code'] === 2, 'unknown report key refused');

// --- dashboard customisation
$r = http('GET', '/agent/my_dashboard.php', 'user12');
$ok($r['code'] === 200 && strpos($r['body'], 'Open tickets by status') !== false && strpos($r['body'], 'My open tickets') !== false, 'my dashboard renders widgets');
$r = http('GET', '/agent/my_dashboard.php', 'user13');
$ok($r['code'] === 200 && strpos($r['body'], 'Workflow tasks due') === false, 'a user without Departments access does not get the workflow widget');
$post = fn($u, array $f) => http('POST', '/agent/my_dashboard.php', $u, $f + ['csrf_token' => $tok($u), 'customise' => 1]);
$layout = fn($uid) => json_decode((string) $one("SELECT layout_widgets FROM dashboard_layouts WHERE layout_user_id = $uid"), true);
$post('user12', ['layout_action' => 'remove', 'widget' => 'my_tickets']);
$ids = array_column($layout(12) ?? [], 'id');
$ok($ids && !in_array('my_tickets', $ids, true) && in_array('tickets_by_status', $ids, true), 'remove a widget');
$post('user12', ['layout_action' => 'add', 'widget' => 'my_tickets']);
$ids = array_column($layout(12), 'id');
$ok(end($ids) === 'my_tickets', 'add it back (appended)');
$before = array_column($layout(12), 'id');
$post('user12', ['layout_action' => 'up', 'widget' => 'my_tickets']);
$after = array_column($layout(12), 'id');
$ok($after !== $before && array_search('my_tickets', $after) === array_search('my_tickets', $before) - 1, 'move a widget up');
$post('user12', ['layout_action' => 'size', 'widget' => 'csat', 'size' => 'lg']);
$ok(array_values(array_filter($layout(12), fn($e) => $e['id'] === 'csat'))[0]['size'] === 'lg', 'resize a widget');
$post('user12', ['layout_action' => 'toggle', 'widget' => 'csat']);
$r = http('GET', '/agent/my_dashboard.php', 'user12');
$ok(strpos($r['body'], 'Customer satisfaction') === false, 'a hidden widget is not shown outside customise mode');
$r = http('GET', '/agent/my_dashboard.php?customise=1', 'user12');
$ok(strpos($r['body'], 'Customer satisfaction') !== false && strpos($r['body'], 'Hidden. Choose Show') !== false, 'customise mode shows it dimmed with a Show button');
$n = count($layout(12));
$post('user12', ['layout_action' => 'add', 'widget' => '<script>x']);
$post('user12', ['layout_action' => 'size', 'widget' => 'csat', 'size' => 'galactic']);
$ok(count($layout(12)) === $n && array_values(array_filter($layout(12), fn($e) => $e['id'] === 'csat'))[0]['size'] === 'lg', 'unknown widget id and bad size are ignored');
http('POST', '/agent/my_dashboard.php', 'user12', ['layout_action' => 'remove', 'widget' => 'csat', 'csrf_token' => 'bad']);
$ok(in_array('csat', array_column($layout(12), 'id'), true), 'customising without a CSRF token is rejected');
$post('user13', ['layout_action' => 'add', 'widget' => 'workflow_tasks_due']);
$ok($layout(13) === null || !in_array('workflow_tasks_due', array_column($layout(13), 'id'), true), 'a user cannot add a widget their role cannot read');
$ok($layout(11) === null, 'other users\' layouts are untouched (layouts are per user)');
$post('user12', ['layout_action' => 'reset']);
$ok(count($layout(12)) >= 8, 'reset restores the default layout');
$r = http('GET', '/agent/dashboard.php', 'user11');
$ok($r['code'] === 200 && strpos($r['body'], 'My dashboard') !== false, 'classic dashboard still loads and the nav links to My dashboard');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
