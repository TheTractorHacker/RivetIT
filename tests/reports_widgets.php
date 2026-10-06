<?php
/*
 * Dashboard widgets and layouts (src/Reports/Widgets), on a scratch database:
 *   - permission gating: a widget whose modules the user cannot read is neither offered nor computed;
 *   - department restriction: widget numbers exclude other departments;
 *   - layout validation: unknown / duplicate / unavailable widgets and bad sizes are dropped, save is bounded, per-user rows;
 *   - the average resolution widget follows the resolution-time rules (resolved tickets only).
 */
require __DIR__ . '/reports_bootstrap.php';
rpt_extract_functions(['ticketResolutionColumnExists', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql']);
if (!ticketResolutionColumnExists($mysqli)) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); }
use ITFlow\Reports\Widgets\{DashboardLayout, WidgetContext, WidgetRegistry, WidgetRenderer};

foreach (['tickets', 'assets', 'clients', 'ticket_statuses', 'dashboard_layouts', 'software', 'domains'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients (client_id, client_name) VALUES (1,'Dept A'),(2,'Dept B')");
$q("INSERT INTO ticket_statuses SET ticket_status_id=1, ticket_status_name='Open', ticket_status_color='#000'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=2, ticket_status_name='Unresolved', ticket_status_color='#000'");
foreach ([[1, 1], [1, 2], [2, 3], [2, 4], [2, 5]] as [$c, $n]) {
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='s$n', ticket_status=1, ticket_priority='High', ticket_client_id=$c, ticket_assigned_to=7, ticket_created_at=NOW() - INTERVAL 3 DAY,
        ticket_sla_resolution_due=NOW() + INTERVAL 1 HOUR");
}
// resolved tickets: A resolved after 2h, B after 10h; plus one in the Unresolved status that must not count
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=20, ticket_subject='r', ticket_status=1, ticket_client_id=1, ticket_created_at=NOW() - INTERVAL 5 HOUR, ticket_resolved_at=NOW() - INTERVAL 3 HOUR, ticket_closed_at=NOW() - INTERVAL 3 HOUR");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=21, ticket_subject='r', ticket_status=1, ticket_client_id=2, ticket_created_at=NOW() - INTERVAL 20 HOUR, ticket_resolved_at=NOW() - INTERVAL 10 HOUR, ticket_closed_at=NOW() - INTERVAL 10 HOUR");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=22, ticket_subject='r', ticket_status=2, ticket_client_id=1, ticket_created_at=NOW() - INTERVAL 100 HOUR, ticket_resolved_at=NOW() - INTERVAL 1 HOUR, ticket_closed_at=NOW() - INTERVAL 1 HOUR");
$q("INSERT INTO assets SET asset_name='a1', asset_type='Laptop', asset_client_id=1, asset_warranty_expire=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO assets SET asset_name='b1', asset_type='Laptop', asset_client_id=2, asset_warranty_expire=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO assets SET asset_name='b2', asset_type='Server', asset_client_id=2");
$q("INSERT INTO domains SET domain_name='b.example', domain_client_id=2, domain_expire=CURDATE() + INTERVAL 5 DAY");

$all = fn(string $m, int $l = 1) => true;
$supportOnly = fn(string $m, int $l = 1) => $m === 'module_support';
$none = fn(string $m, int $l = 1) => false;
$ctx = fn($can, ?array $scope = null) => new WidgetContext(7, $scope, $can);

// --- permission gating
$ok(count(WidgetRegistry::available($ctx($all))) === count(WidgetRegistry::definitions()), 'a user with every module sees every widget');
$ok(WidgetRegistry::available($ctx($none)) === [], 'a user with no modules sees no widgets');
$av = WidgetRegistry::available($ctx($supportOnly));
$ok(in_array('tickets_by_status', $av, true) && in_array('assets_by_type', $av, true) && !in_array('workflow_tasks_due', $av, true), 'support-only user: ticket and asset widgets (assets are reachable via Tickets/assets/docs), no workflow widget (needs Departments)');
$ok(WidgetRegistry::data('workflow_tasks_due', $mysqli, $ctx($supportOnly)) === null, 'data() refuses a widget the user cannot read');
$ok(WidgetRegistry::data('tickets_by_status', $mysqli, $ctx($none)) === null, 'data() returns nothing without the module');
$ok(WidgetRegistry::data('nope', $mysqli, $ctx($all)) === null, 'unknown widget id returns nothing');
$assetsOnly = fn(string $m, int $l = 1) => $m === 'module_assets';
$ex = WidgetRegistry::data('expiring', $mysqli, $ctx($assetsOnly));
$ok(count(array_filter($ex['rows'], fn($r) => $r['cells'][0] === 'Domain')) === 0 && count(array_filter($ex['rows'], fn($r) => $r['cells'][0] === 'Warranty')) === 2, 'expiring widget: assets-only user gets warranties but no domains (a Departments item)');

// --- department restriction
$st = fn($c) => array_sum(array_column(WidgetRegistry::data('tickets_by_status', $mysqli, $c)['items'], 'value'));
$ok($st($ctx($all)) === 5 && $st($ctx($all, [1])) === 2, 'open tickets by status: restricted user counts only their department');
$ab = WidgetRegistry::data('assets_by_type', $mysqli, $ctx($all, [1]));
$ok(array_sum(array_column($ab['items'], 'value')) === 1, 'assets by type is department-restricted');
$sla = WidgetRegistry::data('sla_at_risk', $mysqli, $ctx($all, [1]));
$ok(count($sla['rows']) === 2, 'SLA at risk is department-restricted');
$ex = WidgetRegistry::data('expiring', $mysqli, $ctx($all, [1]));
$ok(count($ex['rows']) === 1 && $ex['rows'][0]['cells'][1] === 'a1', 'expiring items are department-restricted');
$cv = WidgetRegistry::data('created_vs_closed', $mysqli, $ctx($all, [2]));
$ok(count($cv['labels']) === 30 && array_sum($cv['a']) === 4, 'created vs closed: 30 days, restricted counts');
$my = WidgetRegistry::data('my_tickets', $mysqli, $ctx($all, [1]));
$ok(count($my['rows']) === 2, 'my tickets: assigned to me AND in my departments');

// --- resolution time rules
$r = WidgetRegistry::data('avg_resolution', $mysqli, $ctx($all));
$ok($r['value'] === '6 h', "avg resolution counts only resolved tickets outside 'Unresolved' (2h and 10h = 6h; got {$r['value']})");
$rA = WidgetRegistry::data('avg_resolution', $mysqli, $ctx($all, [1]));
$ok($rA['value'] === '2 h', 'avg resolution is department-restricted');
$open = WidgetRegistry::data('avg_resolution', $mysqli, $ctx($all, [99]));
$ok($open['value'] === '-', 'no resolved tickets in scope: dash, not 0');

// --- rendering escapes
$html = WidgetRenderer::render(['type' => 'table', 'columns' => ['<b>'], 'rows' => [['cells' => ['<script>alert(1)</script>'], 'href' => 'https://evil.example/']]]);
$ok(strpos($html, '<script>') === false && strpos($html, 'evil.example') === false, 'renderer escapes cells and drops off-site links');
$html = WidgetRenderer::render(['type' => 'bars', 'items' => [['label' => '"><img src=x>', 'value' => 3]]]);
$ok(strpos($html, '<img') === false, 'renderer escapes bar labels');

// --- layout validation
$allowed = WidgetRegistry::available($ctx($supportOnly));
$n = DashboardLayout::normalize([
    ['id' => 'tickets_by_status', 'size' => 'lg'], ['id' => 'tickets_by_status', 'size' => 'sm'], ['id' => 'workflow_tasks_due'], ['id' => 'bogus'],
    ['id' => 'my_tickets', 'size' => 'huge', 'hidden' => 1], 'junk', ['id' => ['x']], ['no' => 'id'],
], $allowed);
$ok(count($n) === 2 && $n[0] === ['id' => 'tickets_by_status', 'size' => 'lg', 'hidden' => false] && $n[1]['id'] === 'my_tickets' && $n[1]['size'] === 'md' && $n[1]['hidden'] === true, 'normalize: dedupes, drops unknown/unavailable, defaults a bad size, keeps order');
$ok(DashboardLayout::normalize('nonsense', $allowed) === [] && DashboardLayout::normalize(null, $allowed) === [], 'normalize: non-array input');
$many = array_map(fn($i) => ['id' => 'tickets_by_status'], range(1, 100));
$ok(count(DashboardLayout::normalize($many, $allowed)) === 1, 'normalize: duplicates cannot bloat the layout');
$def = DashboardLayout::defaultLayout($allowed);
$ok(array_column($def, 'id') === $allowed, 'default layout = every available widget');
$ok(DashboardLayout::load($mysqli, 7, $allowed) === $def, 'user without a row gets the default');
$saved = DashboardLayout::save($mysqli, 7, [['id' => 'csat', 'size' => 'sm'], ['id' => 'workflow_tasks_due'], ['id' => 'my_tickets']], $allowed);
$ok(array_column($saved, 'id') === ['csat', 'my_tickets'], 'save stores only widgets the user may use');
$ok(array_column(DashboardLayout::load($mysqli, 7, $allowed), 'id') === ['csat', 'my_tickets'], 'saved layout loads back in order');
$ok(DashboardLayout::load($mysqli, 8, $allowed) === $def && (int) $one("SELECT COUNT(*) FROM dashboard_layouts") === 1, 'layouts are per user');
// a stored widget the user later loses access to disappears
$ok(array_column(DashboardLayout::load($mysqli, 7, ['csat']), 'id') === ['csat'], 'a widget the user lost access to is dropped when loading');
$q("UPDATE dashboard_layouts SET layout_widgets = '{broken' WHERE layout_user_id = 7");
$ok(DashboardLayout::load($mysqli, 7, $allowed) === [], 'corrupt stored JSON loads as empty, not an error');
// apply()
$l = [['id' => 'csat', 'size' => 'sm', 'hidden' => false], ['id' => 'my_tickets', 'size' => 'md', 'hidden' => false]];
$ok(array_column(DashboardLayout::apply($l, 'down', 'csat', '', $allowed), 'id') === ['my_tickets', 'csat'], 'apply: move down');
$ok(array_column(DashboardLayout::apply($l, 'up', 'csat', '', $allowed), 'id') === ['csat', 'my_tickets'], 'apply: top item cannot move up');
$ok(count(DashboardLayout::apply($l, 'remove', 'csat', '', $allowed)) === 1, 'apply: remove');
$ok(DashboardLayout::apply($l, 'add', 'workflow_tasks_due', '', $allowed) === $l, 'apply: cannot add a widget the user may not use');
$ok(DashboardLayout::apply($l, 'size', 'csat', 'lg', $allowed)[0]['size'] === 'lg' && DashboardLayout::apply($l, 'size', 'csat', 'xl', $allowed)[0]['size'] === 'sm', 'apply: size validated');
$ok(DashboardLayout::apply($l, 'toggle', 'csat', '', $allowed)[0]['hidden'] === true, 'apply: hide');
$ok(count(DashboardLayout::apply([], 'add', 'tickets_by_status', '', $allowed)) === 1, 'apply: add');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
