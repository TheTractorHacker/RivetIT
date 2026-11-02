<?php
/*
 * The universal filters reach the EXISTING shared report helpers in functions.php (getServiceDeskReport, getTicketDayBreakdownReport,
 * getTechnicianPerformanceReport, getCsatReport) through ReportScope::clause(), without those helpers changing. Scratch database.
 */
require __DIR__ . '/i34_bootstrap.php';
require_once __DIR__ . '/../includes/sla_functions.php';
rpt_extract_functions(['ticketResolutionColumnExists', 'ticketResolutionStartSql', 'ticketResolutionEndSql', 'ticketResolvedOnlySql', 'reportClientIn', 'reportClientClause', 'report_business_days',
    'getServiceDeskReport', 'getTicketDayBreakdownReport', 'getCsatAggregateByGroup', 'getTechnicianPerformanceReport', 'getCsatReport']);
use ITFlow\Reports\ReportFilters;

foreach (['tickets', 'clients', 'users', 'ticket_statuses', 'ticket_replies'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients SET client_id=1, client_name='Acme'");
$q("INSERT INTO users SET user_id=11, user_name='Tech A', user_email='a@example.com', user_password='x', user_type=1, user_status=1");
$q("INSERT INTO users SET user_id=12, user_name='Tech B', user_email='b@example.com', user_password='x', user_type=1, user_status=1");
$q("INSERT INTO ticket_statuses SET ticket_status_id=2, ticket_status_name='Open', ticket_status_color='x'");
$n = 0;
foreach ([['High', 11], ['High', 11], ['Low', 12], ['Low', 12], ['Low', 12]] as [$prio, $tech]) {
    $n++;
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='s$n', ticket_status=2, ticket_client_id=1, ticket_assigned_to=$tech, ticket_priority='$prio', ticket_created_by=11,
        ticket_created_at='2026-10-0$n 08:00:00', ticket_closed_at='2026-10-0$n 15:00:00', ticket_resolved_at='2026-10-0$n 14:00:00', ticket_first_response_at='2026-10-0$n 09:00:00', ticket_csat_rating=" . ($tech === 11 ? 5 : 2) . ", ticket_csat_rated_at='2026-10-0$n 12:00:00'");
}
$all = getServiceDeskReport($mysqli, '2026-10-01', '2026-10-31');
$ok($all['totals']['opened'] === 5, 'Service Desk helper, unfiltered: 5 opened');
ReportFilters::activate('service_desk', ['f_priority' => 'High']);
$ok(getServiceDeskReport($mysqli, '2026-10-01', '2026-10-31')['totals']['opened'] === 2, 'Service Desk helper + priority filter: 2 opened');
ReportFilters::activate('service_desk', ['f_tech' => '12']);
$ok(getServiceDeskReport($mysqli, '2026-10-01', '2026-10-31')['totals']['opened'] === 3, 'Service Desk helper + technician filter: 3 opened');
$ok(getServiceDeskReport($mysqli, '2026-10-01', '2026-10-31', 1)['totals']['opened'] === 5, 'an explicit client id (API keys) bypasses the web filters');
ReportFilters::activate('csat', ['f_tech' => '11']);
$c = getCsatReport($mysqli, '2026-10-01', '2026-10-31');
$ok($c['summary']['rated'] === 2 && $c['summary']['avg_rating'] == 5, 'CSAT helper + technician filter: only Tech A\'s two rated tickets (avg 5)');
ReportFilters::reset();
$ok(getCsatReport($mysqli, '2026-10-01', '2026-10-31')['summary']['rated'] === 5, 'CSAT helper unfiltered: all five rated tickets');
ReportFilters::activate('ticket_day_breakdown', ['f_priority' => 'Low']);
$d = getTicketDayBreakdownReport($mysqli, '2026-10-01', '2026-10-31');
$created = array_sum(array_column($d['days'], 'created'));
$ok($created === 3, 'Day-by-day helper + priority filter: 3 created, got ' . $created);
ReportFilters::reset();
$ok(getTechnicianPerformanceReport($mysqli, '2026-10-01', '2026-10-31')['totals']['tickets_closed'] === 5, 'Technician performance unfiltered: 5 tickets closed');
ReportFilters::activate('technician_performance', ['f_tech' => '11']);
$tp = getTechnicianPerformanceReport($mysqli, '2026-10-01', '2026-10-31');
$byName = array_column($tp['technicians'], 'tickets_closed', 'name');
$ok($tp['totals']['tickets_closed'] === 2 && $byName['Tech A'] === 2 && $byName['Tech B'] === 0, 'Technician performance + technician filter: only Tech A\'s two tickets are counted');
$q("INSERT INTO ticket_replies SET ticket_reply='r', ticket_reply_time_worked='01:00:00', ticket_reply_by=11, ticket_reply_ticket_id=(SELECT ticket_id FROM tickets WHERE ticket_number=1), ticket_reply_created_at='2026-10-02 10:00:00'");
$q("INSERT INTO ticket_replies SET ticket_reply='r', ticket_reply_time_worked='02:00:00', ticket_reply_by=11, ticket_reply_ticket_id=(SELECT ticket_id FROM tickets WHERE ticket_number=3), ticket_reply_created_at='2026-10-03 10:00:00'");
ReportFilters::activate('technician_performance', ['f_priority' => 'High']);
$byTime = array_column(getTechnicianPerformanceReport($mysqli, '2026-10-01', '2026-10-31')['technicians'], 'total_seconds', 'name');
ReportFilters::reset();
$allTime = array_column(getTechnicianPerformanceReport($mysqli, '2026-10-01', '2026-10-31')['technicians'], 'total_seconds', 'name');
$ok($allTime['Tech A'] === 10800 && $byTime['Tech A'] === 3600, 'time worked is filtered too (priority High keeps only the 1h entry, unfiltered 3h)');
ReportFilters::reset();
foreach (['tickets', 'clients', 'users', 'ticket_statuses', 'ticket_replies'] as $t) { $q("DELETE FROM $t"); }
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
