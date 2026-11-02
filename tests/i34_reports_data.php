<?php
/*
 * Issue #34 report queries on seeded fixture data (scratch database): SLA breach and aging, ticket backlog, asset lifecycle and the hub KPIs,
 * with and without the universal filters and the department restriction. "Now" is fixed (2026-10-15 12:00:00) so the numbers are exact.
 */
require __DIR__ . '/i34_bootstrap.php';
require_once __DIR__ . '/../includes/sla_functions.php';
rpt_extract_functions(['ticketResolutionColumnExists', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql']);
use ITFlow\Reports\Queries\AssetLifecycleReport;
use ITFlow\Reports\Queries\SlaBreachReport;
use ITFlow\Reports\Queries\TicketBacklogReport;
use ITFlow\Reports\ReportFilters;
use ITFlow\Reports\ReportKpis;

foreach (['tickets', 'clients', 'users', 'ticket_statuses', 'assets', 'locations', 'categories'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients SET client_id=1, client_name='Acme'"); $q("INSERT INTO clients SET client_id=2, client_name='Globex'");
$q("INSERT INTO users SET user_id=11, user_name='Tech A', user_email='a@example.com', user_password='x', user_type=1, user_status=1");
$q("INSERT INTO users SET user_id=12, user_name='Tech B', user_email='b@example.com', user_password='x', user_type=1, user_status=1");
$q("INSERT INTO ticket_statuses SET ticket_status_id=1, ticket_status_name='New', ticket_status_color='x'"); $q("INSERT INTO ticket_statuses SET ticket_status_id=2, ticket_status_name='Open', ticket_status_color='x'");
$q("INSERT INTO locations SET location_id=1, location_name='HQ', location_client_id=1");
$NOW = '2026-10-15 12:00:00';
$n = 0;
$tk = function (array $c) use ($q, &$n) {
    $n++;
    $c += ['ticket_prefix' => 'T', 'ticket_number' => $n, 'ticket_subject' => "Ticket $n", 'ticket_status' => 2, 'ticket_client_id' => 1, 'ticket_assigned_to' => 11, 'ticket_priority' => 'High', 'ticket_created_by' => 11, 'ticket_location_id' => 0, 'ticket_asset_id' => 0, 'ticket_category' => 0];
    $set = [];
    foreach ($c as $k => $v) { $set[] = "$k = " . ($v === null ? 'NULL' : "'" . mysqli_real_escape_string($GLOBALS['mysqli'], (string) $v) . "'"); }
    $q("INSERT INTO tickets SET " . implode(', ', $set));
    return mysqli_insert_id($GLOBALS['mysqli']);
};
// A: all met (High, tech 11, Acme)
$tk(['ticket_created_at' => '2026-10-02 08:00:00', 'ticket_sla_response_due' => '2026-10-02 10:00:00', 'ticket_first_response_at' => '2026-10-02 09:00:00', 'ticket_sla_resolution_due' => '2026-10-05 08:00:00', 'ticket_resolved_at' => '2026-10-04 08:00:00', 'ticket_closed_at' => '2026-10-04 09:00:00']);
// B: response late (High, tech 11, Acme)
$tk(['ticket_created_at' => '2026-10-03 04:00:00', 'ticket_sla_response_due' => '2026-10-03 05:00:00', 'ticket_first_response_at' => '2026-10-03 07:00:00', 'ticket_sla_resolution_due' => '2026-10-06 04:00:00', 'ticket_resolved_at' => '2026-10-05 04:00:00', 'ticket_closed_at' => '2026-10-05 05:00:00']);
// C: resolved late (Low, tech 12, Globex)
$tk(['ticket_created_at' => '2026-10-04 08:00:00', 'ticket_priority' => 'Low', 'ticket_assigned_to' => 12, 'ticket_client_id' => 2, 'ticket_sla_response_due' => '2026-10-04 12:00:00', 'ticket_first_response_at' => '2026-10-04 09:00:00', 'ticket_sla_resolution_due' => '2026-10-10 08:00:00', 'ticket_resolved_at' => '2026-10-12 08:00:00', 'ticket_closed_at' => '2026-10-12 09:00:00']);
// D: open, no response, long overdue (Critical, unassigned, Globex)
$tk(['ticket_created_at' => '2026-10-05 08:00:00', 'ticket_priority' => 'Critical', 'ticket_assigned_to' => 0, 'ticket_client_id' => 2, 'ticket_sla_response_due' => '2026-10-05 12:00:00', 'ticket_sla_resolution_due' => '2026-10-14 12:00:00']);
// E: open, resolution overdue by 2 hours (Medium, tech 12, Acme), location 1
$tk(['ticket_created_at' => '2026-10-10 08:00:00', 'ticket_priority' => 'Medium', 'ticket_assigned_to' => 12, 'ticket_location_id' => 1, 'ticket_sla_response_due' => '2026-10-10 12:00:00', 'ticket_first_response_at' => '2026-10-10 11:00:00', 'ticket_sla_resolution_due' => '2026-10-15 10:00:00']);
// F: open, overdue but SLA clock paused (not counted as overdue)
$tk(['ticket_created_at' => '2026-10-11 08:00:00', 'ticket_priority' => 'Low', 'ticket_sla_resolution_due' => '2026-10-12 08:00:00', 'ticket_sla_paused_at' => '2026-10-11 20:00:00']);
// G: before the range (excluded from the SLA report, still open for the backlog)
$tk(['ticket_created_at' => '2026-09-30 08:00:00', 'ticket_sla_resolution_due' => '2026-10-01 08:00:00', 'ticket_priority' => 'Low']);
// H: no SLA at all, open, unassigned
$tk(['ticket_created_at' => '2026-10-14 08:00:00', 'ticket_assigned_to' => 0, 'ticket_priority' => 'Low']);
// I: archived (excluded everywhere)
$tk(['ticket_created_at' => '2026-10-06 08:00:00', 'ticket_archived_at' => '2026-10-07 08:00:00', 'ticket_sla_resolution_due' => '2026-10-06 09:00:00']);

$s = SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW);
$t = $s['totals'];
$ok($t['tickets'] === 6, 'SLA: six in-range tickets carry an SLA (archived, out-of-range and SLA-less excluded): ' . $t['tickets']);
$ok($t['response_breached'] === 2, 'SLA: response breaches = B (late) + D (open, none yet): ' . $t['response_breached']);
$ok($t['resolution_breached'] === 3, 'SLA: resolution breaches = C (late) + D (open overdue) + E (open overdue); F is paused: ' . $t['resolution_breached']);
$ok($t['any_breached'] === 4 && $t['compliance_pct'] === 33.3, 'SLA: four tickets breached, compliance 33.3%: ' . $t['any_breached'] . ' / ' . var_export($t['compliance_pct'], true));
$aging = array_column($s['aging'], 'count', 'label');
$ok($aging['Under 4 hours'] === 1 && $aging['Over 7 days'] === 1 && array_sum($aging) === 2, 'SLA aging: E is 2h overdue, D is over 7 days; closed-late C is not "aging": ' . json_encode($aging));
$byPrio = array_column($s['by_priority'], null, 'label');
$ok($byPrio['High']['tickets'] === 2 && $byPrio['High']['breached'] === 1 && $byPrio['Critical']['breached'] === 1, 'SLA by priority');
$byTech = array_column($s['by_tech'], null, 'label');
$ok(isset($byTech['Unassigned'], $byTech['Tech A'], $byTech['Tech B']) && $byTech['Tech A']['breached'] === 1, 'SLA by technician incl. Unassigned');
$ok(count($s['tickets']) === 4 && $s['tickets'][0]['open'] === true, 'SLA list: four breached tickets, open ones first');
$ok(array_column($s['tickets'], 'ticket_number') !== [], 'SLA list carries ticket numbers');

ReportFilters::activate('sla_breach_aging', ['f_priority' => 'High']);
$f = SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals'];
$ok($f['tickets'] === 2 && $f['any_breached'] === 1 && $f['compliance_pct'] === 50.0, 'SLA + priority filter: only A and B');
ReportFilters::activate('sla_breach_aging', ['f_tech' => '12']);
$f = SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals'];
$ok($f['tickets'] === 2 && $f['any_breached'] === 2, 'SLA + technician filter: C and E');
ReportFilters::activate('sla_breach_aging', ['f_client' => '2', 'f_priority' => 'Critical']);
$f = SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals'];
$ok($f['tickets'] === 1 && $f['any_breached'] === 1, 'SLA + department + priority filters combine');
ReportFilters::activate('sla_breach_aging', ['f_location' => '1']);
$ok(SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals']['tickets'] === 1, 'SLA + location filter: only E');
ReportFilters::reset();
$GLOBALS['client_access_string'] = '2'; $GLOBALS['session_is_admin'] = false;
$f = SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals'];
$ok($f['tickets'] === 2 && $f['any_breached'] === 2, 'SLA + department restriction (Globex only): C and D');
unset($GLOBALS['client_access_string'], $GLOBALS['session_is_admin']);
$q("UPDATE tickets SET ticket_counts_sla = 0 WHERE ticket_prefix='T' AND ticket_number = 4");
$ok(SlaBreachReport::data($mysqli, '2026-10-01', '2026-10-31', $NOW)['totals']['tickets'] === 5, 'SLA-exempt tickets (ticket_counts_sla = 0) are left out');
$q("UPDATE tickets SET ticket_counts_sla = 1");

// ---- backlog
$b = TicketBacklogReport::data($mysqli, 4, $NOW);
$ok($b['totals']['open'] === 5 && $b['totals']['unassigned'] === 2, 'Backlog: open = D, E, F, G, H; unassigned = D and H: ' . json_encode($b['totals']));
$ok($b['totals']['older_7d'] === 2 && $b['totals']['older_30d'] === 0, 'Backlog: older than 7 days = D, G: ' . $b['totals']['older_7d']);
$age = array_column($b['age'], 'count', 'label');
$ok($age['Under 1 day'] === 0 && $age['1 to 3 days'] === 1 && $age['3 to 7 days'] === 2 && $age['7 to 30 days'] === 2 && array_sum($age) === 5, 'Backlog age buckets: ' . json_encode($age));
$ok(count($b['trend']) === 4 && $b['trend'][3]['backlog'] === 5 && $b['trend'][3]['week_ending'] === '2026-10-15', 'Backlog trend: last week ends now with a backlog of 5');
$ok($b['trend'][3]['opened'] === 3 && $b['trend'][3]['closed'] === 1, 'Backlog trend: week of 10-08..10-15 opened E, F, H and closed C: ' . json_encode($b['trend'][3]));
$ok($b['trend'][2]['opened'] === 4 && $b['trend'][2]['closed'] === 2 && $b['trend'][2]['backlog'] === 3, 'Backlog trend: previous week opened A-D, closed A and B, backlog C, D, G: ' . json_encode($b['trend'][2]));
$ok($b['oldest'][0]['ticket_number'] == 7 && count($b['oldest']) === 5, 'Backlog: oldest first (G)');
$st = array_column($b['by_status'], 'count', 'label');
$ok($st === ['Open' => 5], 'Backlog by status');
ReportFilters::activate('ticket_backlog', ['f_priority' => 'Low']);
$ok(TicketBacklogReport::data($mysqli, 4, $NOW)['totals']['open'] === 3, 'Backlog + priority filter (Low: F, G, H)');
ReportFilters::activate('ticket_backlog', ['f_tech' => '11']);
$ok(TicketBacklogReport::data($mysqli, 4, $NOW)['totals']['open'] === 2, 'Backlog + technician filter (F, G)');
ReportFilters::reset();
$ok(TicketBacklogReport::data($mysqli, 999, $NOW)['weeks'] === 52 && TicketBacklogReport::data($mysqli, 0, $NOW)['weeks'] === 2, 'Backlog: weeks clamped to 2..52');

// ---- KPIs
$k = ReportKpis::headline($mysqli, $NOW);
$ok($k['open'] === 5, 'KPI open tickets = 5: ' . $k['open']);
$ok($k['tickets_this_month'] === 7, 'KPI tickets this month = 7 (A-F, H): ' . $k['tickets_this_month']);
$ok($k['mean_resolution_hours'] === 96.0, 'KPI mean resolution = (48 + 48 + 192) / 3 = 96h: ' . var_export($k['mean_resolution_hours'], true));
$ok($k['sla_compliance_pct'] === 28.6, 'KPI SLA compliance is the 30-day window (adds G, created 09-30, open and overdue): 2 of 7 met = 28.6%: ' . var_export($k['sla_compliance_pct'], true));
$ok($k['csat_avg'] === null, 'KPI CSAT is null with no ratings');
$q("UPDATE tickets SET ticket_csat_rating = 5, ticket_csat_rated_at = '2026-10-10 00:00:00' WHERE ticket_number = 1");
$q("UPDATE tickets SET ticket_csat_rating = 3, ticket_csat_rated_at = '2026-10-11 00:00:00' WHERE ticket_number = 2");
$ok(ReportKpis::headline($mysqli, $NOW)['csat_avg'] === 4.0, 'KPI CSAT averages ratings from the last 30 days');
$ok(ReportKpis::durationText(96.0) === '4 d' && ReportKpis::durationText(5.5) === '5.5 h' && ReportKpis::durationText(null) === '-', 'durationText()');
$GLOBALS['client_access_string'] = '2'; $GLOBALS['session_is_admin'] = false;
$ok(ReportKpis::headline($mysqli, $NOW)['open'] === 1, 'KPIs honour the department restriction');
unset($GLOBALS['client_access_string'], $GLOBALS['session_is_admin']);

// ---- assets
$ast = fn(array $c) => $q("INSERT INTO assets SET asset_make='M', " . implode(', ', array_map(fn($k, $v) => "$k = " . ($v === null ? 'NULL' : "'" . mysqli_real_escape_string($GLOBALS['mysqli'], (string) $v) . "'"), array_keys($c), $c)));
$ast(['asset_name' => 'old laptop', 'asset_type' => 'Laptop', 'asset_client_id' => 1, 'asset_purchase_date' => '2019-01-01', 'asset_warranty_expire' => '2022-01-01']);
$ast(['asset_name' => 'new laptop', 'asset_type' => 'Laptop', 'asset_client_id' => 1, 'asset_location_id' => 1, 'asset_purchase_date' => '2025-06-01', 'asset_warranty_expire' => '2026-11-01']);
$ast(['asset_name' => 'server', 'asset_type' => 'Server', 'asset_client_id' => 2, 'asset_purchase_date' => '2024-01-01', 'asset_warranty_expire' => '2030-01-01']);
$ast(['asset_name' => 'printer', 'asset_type' => 'Printer', 'asset_client_id' => 2]);
$ast(['asset_name' => 'gone', 'asset_type' => 'Laptop', 'asset_client_id' => 1, 'asset_warranty_expire' => '2020-01-01', 'asset_archived_at' => '2026-01-01 00:00:00']);
$a = AssetLifecycleReport::data($mysqli, 90, 5, '2026-10-15');
$ok($a['totals'] === ['assets' => 4, 'warranty_expired' => 1, 'warranty_expiring' => 1, 'no_warranty_date' => 1, 'no_purchase_date' => 1, 'aged' => 1], 'Assets totals (archived excluded): ' . json_encode($a['totals']));
$ok(array_column($a['assets'], 'asset_name') === ['old laptop', 'new laptop'], 'Assets list: expired first, then expiring: ' . json_encode(array_column($a['assets'], 'asset_name')));
$ok((int) $a['assets'][0]['days_left'] < 0 && (int) $a['assets'][1]['days_left'] === 17, 'Assets: days left computed (expired negative, 17 for 2026-11-01)');
$tp = array_column($a['by_type'], null, 'label');
$ok($tp['Laptop']['count'] === 2 && $tp['Laptop']['expired'] === 1 && $tp['Server']['aged'] === 0, 'Assets by type');
$a30 = AssetLifecycleReport::data($mysqli, 10, 5, '2026-10-15');
$ok($a30['totals']['warranty_expiring'] === 0, 'Assets: a 10 day window does not include a warranty ending in 17 days');
ReportFilters::activate('asset_lifecycle', ['f_client' => '2']);
$ok(AssetLifecycleReport::data($mysqli, 90, 5, '2026-10-15')['totals']['assets'] === 2, 'Assets + department filter');
ReportFilters::activate('asset_lifecycle', ['f_location' => '1']);
$ok(AssetLifecycleReport::data($mysqli, 90, 5, '2026-10-15')['totals']['assets'] === 1, 'Assets + location filter');
ReportFilters::reset();
$GLOBALS['client_access_string'] = '1'; $GLOBALS['session_is_admin'] = false;
$ok(AssetLifecycleReport::data($mysqli, 90, 5, '2026-10-15')['totals']['assets'] === 2, 'Assets honour the department restriction');
unset($GLOBALS['client_access_string'], $GLOBALS['session_is_admin']);

foreach (['tickets', 'clients', 'users', 'ticket_statuses', 'assets', 'locations'] as $t) { $q("DELETE FROM $t"); }
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
