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

foreach (['tickets', 'assets', 'clients', 'ticket_statuses', 'dashboard_layouts', 'software', 'domains', 'contacts',
    'endpoint_agent_devices', 'rmm_alerts', 'rmm_patch_available', 'training_completions', 'training_completion_voids',
    'training_assignments', 'training_achievement_awards', 'projects'] as $t) { $q("DELETE FROM $t"); }
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

// --- RMM / Training / Projects widgets fixture data (A = client 1, B = client 2)
$q("INSERT INTO contacts SET contact_id=1, contact_name='Alice A', contact_client_id=1");
$q("INSERT INTO contacts SET contact_id=2, contact_name='Bob B', contact_client_id=2");

$q("INSERT INTO endpoint_agent_devices SET install_id=UUID(), hostname='a-online', client_id=1, link_state='linked', last_checkin_at=NOW() - INTERVAL 1 MINUTE");
$q("INSERT INTO endpoint_agent_devices SET install_id=UUID(), hostname='a-offline', client_id=1, link_state='linked', last_checkin_at=NOW() - INTERVAL 1 HOUR");
$q("INSERT INTO endpoint_agent_devices SET install_id=UUID(), hostname='b-offline', client_id=2, link_state='linked', last_checkin_at=NULL");
$q("INSERT INTO endpoint_agent_devices SET install_id=UUID(), hostname='b-revoked', client_id=2, link_state='linked', revoked_at=NOW(), last_checkin_at=NOW() - INTERVAL 1 HOUR");
$devA = (int) $one("SELECT device_id FROM endpoint_agent_devices WHERE hostname='a-offline'");
$devB = (int) $one("SELECT device_id FROM endpoint_agent_devices WHERE hostname='b-offline'");

$q("INSERT INTO rmm_alerts SET client_id=1, integration_id=1, severity='critical', status='new', message='m'");
$q("INSERT INTO rmm_alerts SET client_id=1, integration_id=1, severity='warning', status='acknowledged', message='m'");
$q("INSERT INTO rmm_alerts SET client_id=2, integration_id=1, severity='critical', status='resolved', resolved_at=NOW(), message='m'");

$q("INSERT INTO rmm_patch_available SET device_id=$devA, client_id=1, update_id='u1', status='available', first_seen_at=NOW(), last_seen_at=NOW()");
$q("INSERT INTO rmm_patch_available SET device_id=$devA, client_id=1, update_id='u2', status='approved', first_seen_at=NOW(), last_seen_at=NOW()");
$q("INSERT INTO rmm_patch_available SET device_id=$devB, client_id=2, update_id='u3', status='available', first_seen_at=NOW(), last_seen_at=NOW()");

$completionDefaults = "completion_course_kind='training', completion_source_key='k', completion_method='online', completion_proof='self_pin', completion_completed_on=CURDATE()";
$q("INSERT INTO training_completions SET completion_contact_id=1, completion_course_id=1, $completionDefaults, completion_snap_contact_name='Alice A', completion_snap_course_name='Safety', completion_snap_client_id=1, completion_expires_on=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO training_completions SET completion_contact_id=2, completion_course_id=1, $completionDefaults, completion_snap_contact_name='Bob B', completion_snap_course_name='Safety', completion_snap_client_id=2, completion_expires_on=CURDATE() + INTERVAL 10 DAY");
// voided completion expiring soon for A must NOT count
$q("INSERT INTO training_completions SET completion_contact_id=1, completion_course_id=2, $completionDefaults, completion_snap_contact_name='Alice A', completion_snap_course_name='Voided', completion_snap_client_id=1, completion_expires_on=CURDATE() + INTERVAL 5 DAY");
$voidedId = (int) $one("SELECT completion_id FROM training_completions WHERE completion_snap_course_name='Voided'");
$q("INSERT INTO training_completion_voids SET cvoid_completion_id=$voidedId, cvoid_reason='r', cvoid_by_user_id=1, cvoid_at_utc=NOW(3), cvoid_row_sha256=SHA2('x',256)");

$assignDefaults = "tassign_reason='requirement', tassign_anchor='a', tassign_original_due_on=CURDATE() - INTERVAL 30 DAY, tassign_created_at_utc=NOW(3)";
$q("INSERT INTO training_assignments SET tassign_contact_id=1, tassign_course_id=1, $assignDefaults, tassign_required=1, tassign_status='open', tassign_due_on=CURDATE() - INTERVAL 1 DAY");
$q("INSERT INTO training_assignments SET tassign_contact_id=2, tassign_course_id=1, $assignDefaults, tassign_required=1, tassign_status='open', tassign_due_on=CURDATE() - INTERVAL 1 DAY");
$q("INSERT INTO training_assignments SET tassign_contact_id=1, tassign_course_id=2, $assignDefaults, tassign_required=0, tassign_status='open', tassign_due_on=CURDATE() - INTERVAL 1 DAY"); // not required - must not count

$awardDefaults = "taward_achievement_id=1, taward_achievement_uid='uid1', taward_rule_type='r', taward_source='rule', taward_snap_icon='fa-star', taward_snap_color='#000000', taward_row_sha256=SHA2('y',256)";
$q("INSERT INTO training_achievement_awards SET taward_contact_id=1, $awardDefaults, taward_snap_name='First Aid', taward_awarded_at_utc=NOW(3)");
$q("INSERT INTO training_achievement_awards SET taward_contact_id=2, $awardDefaults, taward_snap_name='First Aid', taward_awarded_at_utc=NOW(3)");
$q("INSERT INTO training_achievement_awards SET taward_contact_id=1, $awardDefaults, taward_snap_name='Old', taward_awarded_at_utc=NOW(3) - INTERVAL 2 MONTH"); // not this month - must not count

$q("INSERT INTO projects SET project_name='Open A', project_client_id=1");
$q("INSERT INTO projects SET project_name='Open B', project_client_id=2");
$q("INSERT INTO projects SET project_name='Done A', project_client_id=1, project_completed_at=NOW()"); // completed - must not count

// created 60 days ago so they fall outside the createdVsClosed 30-day window and don't disturb that fixture
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=30, ticket_subject='task due', ticket_status=1, ticket_client_id=1, ticket_project_id=1, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() + INTERVAL 3 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=31, ticket_subject='task overdue', ticket_status=1, ticket_client_id=1, ticket_project_id=1, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=32, ticket_subject='task other dept', ticket_status=1, ticket_client_id=2, ticket_project_id=2, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=33, ticket_subject='not a project task', ticket_status=1, ticket_client_id=1, ticket_project_id=0, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");

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
$ok($st($ctx($all)) === 9 && $st($ctx($all, [1])) === 5, 'open tickets by status: restricted user counts only their department (incl. the project-task fixture tickets)');
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

// --- RMM / Training / Projects widgets
$off = WidgetRegistry::data('rmm_devices_offline', $mysqli, $ctx($all));
$ok($off['value'] === '2', "rmm_devices_offline: a-offline and b-offline count, a-online and revoked b do not (got {$off['value']})");
$offA = WidgetRegistry::data('rmm_devices_offline', $mysqli, $ctx($all, [1]));
$ok($offA['value'] === '1', 'rmm_devices_offline is department-restricted');

$al = WidgetRegistry::data('rmm_alerts_open', $mysqli, $ctx($all, [1]));
$ok(array_sum(array_column($al['items'], 'value')) === 2, 'rmm_alerts_open: only unresolved, department-restricted');

$pp = WidgetRegistry::data('rmm_patches_pending', $mysqli, $ctx($all, [1]));
$items = array_column($pp['items'], 'value', 'label');
$ok($items['Pending approval'] === 1 && $items['Approved, not yet installed'] === 1, 'rmm_patches_pending: department-restricted available/approved counts');

$te = WidgetRegistry::data('training_expiring', $mysqli, $ctx($all, [1]));
$ok(count($te['rows']) === 1 && $te['rows'][0]['cells'][1] === 'Safety', 'training_expiring: voided completion excluded, department-restricted');

$to = WidgetRegistry::data('training_overdue', $mysqli, $ctx($all, [1]));
$ok($to['value'] === '1', 'training_overdue: only required+open+past-due, department-restricted (non-required excluded)');

$ta = WidgetRegistry::data('training_achievements_month', $mysqli, $ctx($all, [1]));
$ok(array_sum(array_column($ta['items'], 'value')) === 1, 'training_achievements_month: this month only, department-restricted');

$po = WidgetRegistry::data('projects_open', $mysqli, $ctx($all, [1]));
$ok($po['value'] === '1', 'projects_open: completed project excluded, department-restricted');

$ptd = WidgetRegistry::data('project_tasks_due', $mysqli, $ctx($all, [1]));
$ok(count($ptd['rows']) === 2 && $ptd['rows'][0]['cells'][1] === 'task overdue', 'project_tasks_due: next 7 days (incl. overdue), ticket_project_id!=0, department-restricted');

$pto = WidgetRegistry::data('project_tasks_overdue', $mysqli, $ctx($all, [1]));
$ok(count($pto['rows']) === 1 && $pto['rows'][0]['cells'][1] === 'task overdue', 'project_tasks_overdue: only past-due project tasks, department-restricted');

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
$ok(count($n) === 2 && $n[0] === ['id' => 'tickets_by_status', 'size' => 'lg', 'hidden' => false, 'chart' => 'list'] && $n[1]['id'] === 'my_tickets' && $n[1]['size'] === 'md' && $n[1]['hidden'] === true, 'normalize: dedupes, drops unknown/unavailable, defaults a bad size, keeps order');
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

// --- chart type switching (WidgetRegistry::chartOptions/defaultChart, DashboardLayout 'chart' field + apply('chart'), WidgetRenderer's $chart param)
$ok(WidgetRegistry::chartOptions('tickets_by_status') === ['list' => 'List', 'bar' => 'Bar', 'pie' => 'Pie'], 'a "bars"-shaped widget offers list/bar/pie');
$ok(WidgetRegistry::chartOptions('created_vs_closed') === ['bar' => 'Bar', 'line' => 'Line'], 'a "series"-shaped widget offers bar/line');
$ok(WidgetRegistry::chartOptions('avg_resolution') === [], 'a stat widget has no chart type to switch');
$ok(WidgetRegistry::defaultChart('tickets_by_status') === 'list' && WidgetRegistry::defaultChart('created_vs_closed') === 'bar' && WidgetRegistry::defaultChart('avg_resolution') === null, 'defaultChart() matches each shape\'s current rendering');

$l2 = DashboardLayout::apply([], 'add', 'tickets_by_status', '', $allowed);
$ok($l2[0]['chart'] === 'list', 'apply(add): new entry gets the widget\'s default chart type');
$l2 = DashboardLayout::apply($l2, 'chart', 'tickets_by_status', '', $allowed, 'pie');
$ok($l2[0]['chart'] === 'pie', 'apply(chart): switches to an offered chart type');
$l2 = DashboardLayout::apply($l2, 'chart', 'tickets_by_status', '', $allowed, 'bogus');
$ok($l2[0]['chart'] === 'pie', 'apply(chart): an unknown chart type is ignored, keeps the previous one');
$l3 = DashboardLayout::apply([], 'add', 'avg_resolution', '', $allowed);
$l3 = DashboardLayout::apply($l3, 'chart', 'avg_resolution', '', $allowed, 'pie');
$ok($l3[0]['chart'] === null, 'apply(chart): a widget with no chart options cannot be switched');
$ok(DashboardLayout::normalize([['id' => 'tickets_by_status', 'chart' => 'pie']], $allowed)[0]['chart'] === 'pie', 'normalize: a valid stored chart choice is kept');
$ok(DashboardLayout::normalize([['id' => 'tickets_by_status', 'chart' => 'nonsense']], $allowed)[0]['chart'] === 'list', 'normalize: an invalid stored chart choice falls back to the default');

$barsChart = WidgetRenderer::render(['type' => 'bars', 'items' => [['label' => 'a', 'value' => 3], ['label' => 'b', 'value' => 1]]], 'pie');
$ok(strpos($barsChart, 'class="dash-chartjs"') !== false && strpos($barsChart, '&quot;type&quot;:&quot;pie&quot;') !== false, 'renderer: a "bars" widget switched to pie emits a chartjs canvas, not the progress-bar list');
$barsList = WidgetRenderer::render(['type' => 'bars', 'items' => [['label' => 'a', 'value' => 3]]], 'list');
$ok(strpos($barsList, 'progress') !== false, 'renderer: "list" (the default) keeps the original progress-bar rendering');
$seriesLine = WidgetRenderer::render(['type' => 'series', 'labels' => ['d1'], 'a' => [1], 'b' => [2], 'a_label' => 'Created', 'b_label' => 'Closed'], 'line');
$ok(strpos($seriesLine, 'class="dash-chartjs"') !== false && strpos($seriesLine, '&quot;type&quot;:&quot;line&quot;') !== false, 'renderer: a "series" widget switched to line emits a chartjs canvas, not the SVG bars');
$seriesBar = WidgetRenderer::render(['type' => 'series', 'labels' => ['d1'], 'a' => [1], 'b' => [2], 'a_label' => 'Created', 'b_label' => 'Closed'], 'bar');
$ok(strpos($seriesBar, '<svg') !== false, 'renderer: "bar" (the default) keeps the original SVG rendering');
$pieLabelEscape = WidgetRenderer::render(['type' => 'bars', 'items' => [['label' => '<script>alert(1)</script>', 'value' => 1]]], 'pie');
$ok(strpos($pieLabelEscape, '<script>') === false, 'renderer: chart payload JSON is htmlspecialchars-escaped in the data attribute, not just json_encode-escaped');

// reorder() - the drag-and-drop save (agent/js/my_dashboard.js), replacing the old up/down buttons
$rl = [['id' => 'csat', 'size' => 'sm', 'hidden' => false], ['id' => 'my_tickets', 'size' => 'lg', 'hidden' => true], ['id' => 'workflow_tasks_due', 'size' => 'md', 'hidden' => false]];
$reordered = DashboardLayout::reorder($rl, ['workflow_tasks_due', 'csat', 'my_tickets']);
$ok(array_column($reordered, 'id') === ['workflow_tasks_due', 'csat', 'my_tickets'], 'reorder: matches the dragged order');
$ok($reordered[2]['size'] === 'lg' && $reordered[2]['hidden'] === true, 'reorder: keeps each entry\'s own size/hidden, only the order changes');
$ok(array_column(DashboardLayout::reorder($rl, ['csat', 'bogus_id']), 'id') === ['csat', 'my_tickets', 'workflow_tasks_due'], 'reorder: an id not in the layout is ignored; anything left out of the order keeps its old relative place at the end');
$ok(DashboardLayout::reorder($rl, []) === $rl, 'reorder: an empty order leaves the layout unchanged');
// user 7's stored layout was left corrupt by the earlier "corrupt stored JSON" test - start it fresh here.
DashboardLayout::save($mysqli, 7, [['id' => 'csat'], ['id' => 'my_tickets']], $allowed);
DashboardLayout::save($mysqli, 7, DashboardLayout::reorder(DashboardLayout::load($mysqli, 7, $allowed), ['my_tickets', 'csat']), $allowed);
$ok(array_column(DashboardLayout::load($mysqli, 7, $allowed), 'id') === ['my_tickets', 'csat'], 'reorder: saves and loads back through the normal layout round trip');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
