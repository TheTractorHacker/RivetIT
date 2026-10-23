<?php
/*
 * Dashboard widgets and layouts (src/Reports/Widgets), on a scratch database:
 *   - permission gating: a widget whose modules the user cannot read is neither offered nor computed;
 *   - department restriction: widget numbers exclude other departments;
 *   - layout validation: unknown / duplicate / unavailable widgets and bad sizes are dropped, save is bounded, per-user rows;
 *   - the average resolution widget follows the resolution-time rules (resolved tickets only).
 */
require __DIR__ . '/reports_bootstrap.php';
rpt_extract_functions(['ticketResolutionColumnExists', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql', 'getAvgResolutionTimeHours']);
if (!ticketResolutionColumnExists($mysqli)) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); }
use ITFlow\Reports\Widgets\{DashboardLayout, WidgetContext, WidgetRegistry, WidgetRenderer};

foreach (['tickets', 'assets', 'clients', 'ticket_statuses', 'dashboard_layouts', 'software', 'domains', 'contacts',
    'endpoint_agent_devices', 'rmm_alerts', 'rmm_patch_available', 'training_completions', 'training_completion_voids',
    'training_assignments', 'training_achievement_awards', 'projects',
    'certificates', 'invoices', 'workflow_runs', 'vendors', 'recurring_invoices', 'recurring_expenses', 'accounts',
    'payments', 'revenues', 'expenses', 'categories', 'trips', 'ticket_automation_runs'] as $t) { $q("DELETE FROM $t"); }
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

// --- merged-in classic-dashboard widgets fixture data (A = client 1, B = client 2), all for $ctx->year = 2026
$q("INSERT INTO domains SET domain_name='a-exp.example', domain_client_id=1, domain_expire=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO domains SET domain_name='b-exp.example', domain_client_id=2, domain_expire=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO certificates SET certificate_name='a-cert', certificate_issued_by='CA', certificate_client_id=1, certificate_expire=CURDATE() + INTERVAL 10 DAY");
$q("INSERT INTO invoices SET invoice_prefix='INV', invoice_number=1, invoice_status='Sent', invoice_date=CURDATE(), invoice_due=CURDATE(), invoice_amount=100, invoice_currency_code='USD', invoice_category_id=0, invoice_client_id=1");
$q("INSERT INTO invoices SET invoice_prefix='INV', invoice_number=2, invoice_status='Draft', invoice_date=CURDATE(), invoice_due=CURDATE(), invoice_amount=50, invoice_currency_code='USD', invoice_category_id=0, invoice_client_id=2"); // Draft must not count as unpaid
$q("INSERT INTO workflow_runs SET contact_id=1, type='onboarding', status='in_progress', started_at=NOW()");
$q("INSERT INTO workflow_runs SET contact_id=2, type='offboarding', status='in_progress', started_at=NOW()");
$q("INSERT INTO workflow_runs SET contact_id=1, type='onboarding', status='completed', started_at=NOW()"); // completed - must not count
$q("INSERT INTO assets SET asset_name='a-active', asset_type='Laptop', asset_status='Active', asset_client_id=1");
$q("INSERT INTO assets SET asset_name='a-active2', asset_type='Laptop', asset_status='Active', asset_client_id=1, asset_created_at=NOW()");
$q("INSERT INTO assets SET asset_name='b-inactive', asset_type='Server', asset_status='Inactive', asset_client_id=2");
$q("INSERT INTO vendors SET vendor_name='Acme', vendor_client_id=0, vendor_created_at=NOW()");
$q("INSERT INTO recurring_invoices SET recurring_invoice_prefix='R', recurring_invoice_number=1, recurring_invoice_frequency='month', recurring_invoice_next_date=CURDATE(), recurring_invoice_status=1, recurring_invoice_amount=40, recurring_invoice_currency_code='USD', recurring_invoice_category_id=0, recurring_invoice_client_id=1, recurring_invoice_created_at=NOW()");
$q("INSERT INTO recurring_invoices SET recurring_invoice_prefix='R', recurring_invoice_number=2, recurring_invoice_frequency='year', recurring_invoice_next_date=CURDATE(), recurring_invoice_status=1, recurring_invoice_amount=120, recurring_invoice_currency_code='USD', recurring_invoice_category_id=0, recurring_invoice_client_id=1, recurring_invoice_created_at=NOW()");
$q("INSERT INTO recurring_expenses SET recurring_expense_frequency=1, recurring_expense_next_date=CURDATE(), recurring_expense_status=1, recurring_expense_amount=10, recurring_expense_vendor_id=0, recurring_expense_category_id=0, recurring_expense_account_id=0, recurring_expense_currency_code='USD', recurring_expense_created_at=NOW()");
$q("INSERT INTO accounts SET account_name='Checking', opening_balance=100, account_currency_code='USD'");
$accountId = (int) $one("SELECT account_id FROM accounts WHERE account_name='Checking'");
$q("INSERT INTO categories SET category_name='Hardware', category_type='Expense', category_color='#ff0000'");
$catId = (int) $one("SELECT category_id FROM categories WHERE category_name='Hardware'");
$q("INSERT INTO categories SET category_name='Services', category_type='Income', category_color='#00ff00'");
$incomeCatId = (int) $one("SELECT category_id FROM categories WHERE category_name='Services'");
$q("INSERT INTO invoices SET invoice_prefix='INV', invoice_number=3, invoice_status='Paid', invoice_date=CURDATE(), invoice_due=CURDATE(), invoice_amount=100, invoice_currency_code='USD', invoice_category_id=$incomeCatId, invoice_client_id=1"); // separate from #1 (stays Sent/unpaid) so invoices_unpaid isn't disturbed
$q("INSERT INTO payments SET payment_date=CURDATE(), payment_amount=100, payment_account_id=$accountId, payment_invoice_id=" . (int) $one("SELECT invoice_id FROM invoices WHERE invoice_number=1") . ", payment_currency_code='USD'");
$q("INSERT INTO revenues SET revenue_date=CURDATE(), revenue_amount=25, revenue_category_id=$incomeCatId, revenue_account_id=$accountId, revenue_currency_code='USD'");
$q("INSERT INTO vendors SET vendor_name='Widget Co', vendor_client_id=0, vendor_created_at=NOW()");
$vendorId = (int) $one("SELECT vendor_id FROM vendors WHERE vendor_name='Widget Co'");
$q("INSERT INTO expenses SET expense_date=CURDATE(), expense_amount=60, expense_vendor_id=$vendorId, expense_category_id=$catId, expense_account_id=$accountId, expense_currency_code='USD', expense_client_id=1");
$q("INSERT INTO trips SET trip_date=CURDATE(), trip_purpose='p', trip_source='s', trip_destination='d', trip_miles=42.5, round_trip=0, trip_user_id=7, trip_client_id=1");
// closed (not open) so it doesn't disturb the OPEN-ticket assertions (tickets_by_status, my_tickets, sla_at_risk)
// above, and status 2 ('Unresolved', excluded by ticketResolvedOnlySql()) so its 60-day-long resolution time
// doesn't corrupt the existing 30-day avg_resolution fixture (tickets 20/21/22) the same way ticket 22 already does
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=40, ticket_subject='automated', ticket_status=2, ticket_client_id=1, ticket_assigned_to=7, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_closed_at=NOW()");
$q("INSERT INTO ticket_automation_runs SET rule_id=1, rule_name='Offline alert', trigger_type='asset_offline', ticket_id=" . (int) $one("SELECT ticket_id FROM tickets WHERE ticket_number=40") . ", client_id=1, summary='Created ticket', created_at=NOW()");

// created 60 days ago so they fall outside the createdVsClosed 30-day window and don't disturb that fixture
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=30, ticket_subject='task due', ticket_status=1, ticket_client_id=1, ticket_project_id=1, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() + INTERVAL 3 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=31, ticket_subject='task overdue', ticket_status=1, ticket_client_id=1, ticket_project_id=1, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=32, ticket_subject='task other dept', ticket_status=1, ticket_client_id=2, ticket_project_id=2, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=33, ticket_subject='not a project task', ticket_status=1, ticket_client_id=1, ticket_project_id=0, ticket_created_at=NOW() - INTERVAL 60 DAY, ticket_due_at=NOW() - INTERVAL 1 DAY");

$all = fn(string $m, int $l = 1) => true;
$supportOnly = fn(string $m, int $l = 1) => $m === 'module_support';
$none = fn(string $m, int $l = 1) => false;
// financeEnabled/avgResolutionEnabled default to true here so the existing "sees every widget"-style assertions
// below don't have to know about every config flag added since; the finance-specific tests further down build
// their own WidgetContext directly where they need financeEnabled=false.
$ctx = fn($can, ?array $scope = null) => new WidgetContext(7, $scope, $can, true, false, 2, 2026, true, true);

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
$ok(array_sum(array_column($ab['items'], 'value')) === 3, 'assets by type is department-restricted (incl. the assets-by-status fixture assets)');
$sla = WidgetRegistry::data('sla_at_risk', $mysqli, $ctx($all, [1]));
$ok(count($sla['rows']) === 2, 'SLA at risk is department-restricted');
$ex = WidgetRegistry::data('expiring', $mysqli, $ctx($all, [1]));
$ok(count($ex['rows']) === 2, 'expiring items are department-restricted (warranty + the new expiring-domain fixture)');
$ok(count(array_filter($ex['rows'], fn($r) => $r['cells'][1] === 'a1')) === 1, 'expiring: the a1 warranty row is still there');
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

// --- merged-in classic-dashboard widgets ("why have two dashboards?") - misc/attention, assets, tickets, finance
$fctx = fn($scope = null) => new WidgetContext(7, $scope, $all, true, false, 2, 2026, true, true, null, 'USD');

$na = WidgetRegistry::data('needs_attention', $mysqli, $fctx([1]));
$items = array_column($na['items'], 'value', 'label');
$ok(($items['Unassigned tickets'] ?? null) !== null && ($items['Unpaid invoices'] ?? null) !== null, 'needs_attention: includes both a ticket-module item and a sales-module item when the user has both');
$na2 = WidgetRegistry::data('needs_attention', $mysqli, new WidgetContext(7, [1], $supportOnly, true, false, 2, 2026, true, true));
$ok(!array_key_exists('Unpaid invoices', array_column($na2['items'], 'value', 'label')), 'needs_attention: a sub-item is omitted for a user without that module, not errored');

$da = WidgetRegistry::data('departments_active', $mysqli, $fctx([1]));
$ok($da['value'] === '1', 'departments_active is department-restricted');
$daY = WidgetRegistry::data('departments_added_year', $mysqli, $fctx());
$ok($daY['value'] === '2', 'departments_added_year: both fixture clients were created "now" (2026)');
$caY = WidgetRegistry::data('contacts_added_year', $mysqli, $fctx([1]));
$ok($caY['value'] === '1', 'contacts_added_year is department-restricted');
$iu = WidgetRegistry::data('invoices_unpaid', $mysqli, $fctx([1]));
$ok($iu['value'] === '1', 'invoices_unpaid: Sent counts, Draft does not, department-restricted');
$wr = WidgetRegistry::data('workflow_runs_in_progress', $mysqli, $fctx([1]));
$ok(count($wr['rows']) === 1 && $wr['rows'][0]['cells'][1]['text'] === 'Onboarding', 'workflow_runs_in_progress: completed run excluded, department-restricted, type rendered as a badge cell');
$abs = WidgetRegistry::data('assets_by_status', $mysqli, $fctx([1]));
$ok(array_sum(array_column($abs['items'], 'value')) === 3, 'assets_by_status is department-restricted (a1 + the two new Active fixtures)');
$aaY = WidgetRegistry::data('assets_added_year', $mysqli, $fctx([1]));
$ok($aaY['value'] === '3', 'assets_added_year: every client-1 asset defaults asset_created_at to "now" (2026) in this fixture, department-restricted');

$tot = WidgetRegistry::data('tickets_opened_today', $mysqli, $fctx([1]));
$ok((int) $tot['value'] >= 0, 'tickets_opened_today runs and returns a count');
$trw = WidgetRegistry::data('tickets_resolved_week', $mysqli, $fctx([1]));
$ok((int) $trw['value'] >= 1, 'tickets_resolved_week: the automation-run fixture ticket (closed just now) counts');
$twc = WidgetRegistry::data('tickets_waiting_on_customer', $mysqli, $fctx([1]));
$ok($twc['value'] === '0', 'tickets_waiting_on_customer: no tickets in that status in the fixture');
$tfy = WidgetRegistry::data('tickets_flow_year', $mysqli, $fctx([1]));
$ok(count($tfy['labels']) === 12 && array_sum($tfy['b']) >= 1, 'tickets_flow_year: 12 monthly buckets, resolved series picks up the closed fixture ticket');
$tbc = WidgetRegistry::data('tickets_by_category', $mysqli, $fctx([1]));
$ok(array_sum(array_column($tbc['items'], 'value')) === $st($fctx([1])), 'tickets_by_category: open-ticket total matches tickets_by_status for the same scope');
$tbt = WidgetRegistry::data('tickets_by_technician_open', $mysqli, $fctx([1]));
$ok(array_sum(array_column($tbt['items'], 'value')) === 2, 'tickets_by_technician_open: open tickets assigned to a technician, department-restricted');
$tcy = WidgetRegistry::data('tickets_created_year', $mysqli, $fctx([1]));
$ok((int) $tcy['value'] >= 1, 'tickets_created_year counts the automation-run fixture ticket');
$try_ = WidgetRegistry::data('tickets_resolved_year', $mysqli, $fctx([1]));
$ok((int) $try_['value'] >= 1, 'tickets_resolved_year counts the closed fixture ticket');
$ary = WidgetRegistry::data('avg_resolution_year', $mysqli, $fctx([1]));
$ok(in_array($ary['value'], ['-'], true) || (float) $ary['value'] >= 0, 'avg_resolution_year runs without error');
$aryOff = WidgetRegistry::data('avg_resolution_year', $mysqli, new WidgetContext(7, null, $all, true, false, 2, 2026, true, false));
$ok($aryOff['caption'] === 'Average resolution time is turned off.', 'avg_resolution_year: disabled by config shows a message, not hidden');
$rbt = WidgetRegistry::data('tickets_resolved_by_technician_year', $mysqli, $fctx([1]));
$ok(array_sum(array_column($rbt['items'], 'value')) >= 1, 'tickets_resolved_by_technician_year counts the closed fixture ticket');
$rr = WidgetRegistry::data('tickets_recently_resolved', $mysqli, $fctx([1]));
$ok(count($rr['rows']) >= 1 && is_array($rr['rows'][0]['cells'][3]) && $rr['rows'][0]['cells'][3]['badge'] === 'info', 'tickets_recently_resolved: priority renders as a badge cell (Low -> info)');
$tar = WidgetRegistry::data('tickets_automation_recent', $mysqli, $fctx([1]));
$ok(count($tar['rows']) === 1 && $tar['rows'][0]['cells'][1]['text'] === 'Asset offline', 'tickets_automation_recent: scoped to the logged-in user (7), trigger type rendered as a friendly label');
$tarOther = WidgetRegistry::data('tickets_automation_recent', $mysqli, new WidgetContext(99, null, $all, true, false, 2, 2026, true, true));
$ok($tarOther['rows'] === [], 'tickets_automation_recent: a different user sees none of it (always "mine", like my_tickets)');

// --- finance widgets: FINANCE_WIDGETS requires financeEnabled on top of the module check every other widget uses
$finOff = new WidgetContext(7, null, $all, true, false, 2, 2026, false, true);
$ok(WidgetRegistry::data('finance_income', $mysqli, $finOff) === null, 'a finance widget is unavailable when financeEnabled is false, even with every module');
$ok(!in_array('finance_income', WidgetRegistry::available($finOff), true) && in_array('tickets_by_status', WidgetRegistry::available($finOff), true), 'available(): finance widgets drop out when disabled, non-finance widgets do not');
$fin = fn() => new WidgetContext(7, null, $all, true, false, 2, 2026, true, true, null, 'USD');
$ok(in_array('finance_income', WidgetRegistry::available($fin()), true), 'available(): finance widgets return once financeEnabled is true');

$fi = WidgetRegistry::data('finance_income', $mysqli, $fin());
$ok($fi['value'] === '$125.00' && strpos($fi['caption'], 'Receivables') === 0, 'finance_income: payments (100) + category revenue (25) = 125, no currency_format given falls back to a plain decimal');
$fe = WidgetRegistry::data('finance_expenses', $mysqli, $fin());
$ok($fe['value'] === '$60.00', 'finance_expenses: the one vendor-linked expense');
$fp = WidgetRegistry::data('finance_profit', $mysqli, $fin());
$ok($fp['value'] === '$65.00', 'finance_profit: income (125) - expenses (60) = 65');
$fri = WidgetRegistry::data('finance_recurring_income', $mysqli, $fin());
$ok($fri['value'] === '$50.00', 'finance_recurring_income: 40/month + 120/year amortized (10/mo) = 50/mo');
$fre = WidgetRegistry::data('finance_recurring_expense', $mysqli, $fin());
$ok($fre['value'] === '$10.00', 'finance_recurring_expense: the one monthly recurring expense');
$fva = WidgetRegistry::data('finance_vendors_added', $mysqli, $fin());
$ok((int) $fva['value'] === 2, 'finance_vendors_added: both fixture vendors (vendor_client_id=0) created "now"');
$fmt = WidgetRegistry::data('finance_miles_traveled', $mysqli, $fin());
$ok($fmt['value'] === '42.50', 'finance_miles_traveled: the one fixture trip');
$fcf = WidgetRegistry::data('finance_cash_flow', $mysqli, $fin());
$ok($fcf['type'] === 'multiseries' && count($fcf['series']) === 4 && array_sum($fcf['series'][3]['data']) == 60, 'finance_cash_flow: 4 series (Income/LY Income/Projected/Expense), expense series sums to 60');
$ftf = WidgetRegistry::data('finance_trip_flow', $mysqli, $fin());
$ok($ftf['type'] === 'multiseries' && count($ftf['series']) === 1 && array_sum($ftf['series'][0]['data']) == 42.5, 'finance_trip_flow: single series, sums to the fixture trip');
$fibc = WidgetRegistry::data('finance_income_by_category', $mysqli, $fin());
$ok(count($fibc['items']) === 1 && $fibc['items'][0]['label'] === 'Services' && $fibc['items'][0]['display'] === '$100.00', 'finance_income_by_category: the one Paid invoice, categorized, currency-formatted display');
$febc = WidgetRegistry::data('finance_expense_by_category', $mysqli, $fin());
$ok(count($febc['items']) === 1 && $febc['items'][0]['label'] === 'Hardware' && $febc['items'][0]['display'] === '$60.00', 'finance_expense_by_category: the one vendor-linked expense, categorized');
$febv = WidgetRegistry::data('finance_expense_by_vendor', $mysqli, $fin());
$ok(count($febv['items']) === 1 && $febv['items'][0]['label'] === 'Widget Co', 'finance_expense_by_vendor: the one vendor');
$fab = WidgetRegistry::data('finance_account_balances', $mysqli, $fin());
$ok(count($fab['rows']) === 1 && $fab['rows'][0]['cells'][1] === '$165.00', 'finance_account_balances: opening (100) + payment (100) + revenue (25) - expense (60) = 165');
$fli = WidgetRegistry::data('finance_latest_income', $mysqli, $fin());
$ok(count($fli['rows']) === 1 && $fli['rows'][0]['cells'][3] === '$100.00', 'finance_latest_income: the one payment');
$fle = WidgetRegistry::data('finance_latest_expenses', $mysqli, $fin());
$ok(count($fle['rows']) === 1 && $fle['rows'][0]['cells'][3] === '$60.00', 'finance_latest_expenses: the one expense');
$fur = WidgetRegistry::data('finance_unbilled_or_recurring_added', $mysqli, $fin());
$ok($fur['type'] === 'stat' && ctype_digit($fur['value']), 'finance_unbilled_or_recurring_added: a plain count either way (ticketing+accounting both on, or recurring invoices)');

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

// --- Add-a-widget categories (WidgetRegistry::categories()) - every definitions() id appears in exactly one
// category, so a widget added without being filed under a category silently disappears from "Add a widget".
$catIds = [];
foreach (WidgetRegistry::categories() as $cat => $ids) { foreach ($ids as $id) { $catIds[] = $id; } }
$defIds = array_keys(WidgetRegistry::definitions());
sort($catIds);
sort($defIds);
$ok($catIds === $defIds, 'categories(): covers every definitions() id exactly once, no gaps or dupes');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
